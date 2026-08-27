<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Ai\AnalystToolService;
use App\Services\Ai\AnswerTagFilter;
use App\Services\Ai\ImageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.key' => 'test-key']);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function authUser(): array
    {
        $user = User::factory()->create();

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function seedBaseline(User $user, string $currencyCode = 'USD'): array
    {
        $currencyId = (string) Str::ulid();
        $userCurrencyId = (string) Str::ulid();
        $accountId = (string) Str::ulid();
        $categoryId = (string) Str::ulid();

        DB::table('currencies')->insert([
            'id' => $currencyId, 'code' => $currencyCode, 'name' => 'US Dollar', 'symbol' => '$',
            'decimal_places' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_currencies')->insert([
            'id' => $userCurrencyId, 'user_id' => $user->id, 'currency_id' => $currencyId,
            'exchange_rate' => 1, 'is_anchor' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('accounts')->insert([
            'id' => $accountId, 'user_id' => $user->id, 'user_currency_id' => $userCurrencyId,
            'name' => 'Checking', 'type' => 'bank_account', 'initial_balance' => '100.00', 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_categories')->insert([
            'id' => $categoryId, 'user_id' => $user->id, 'name' => 'Groceries',
            'type' => 'expense', 'icon' => 'basket', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('accountId', 'categoryId', 'userCurrencyId');
    }

    private function transaction(User $user, array $ids, string $amount, string $date, string $type = 'expense'): void
    {
        DB::table('transactions')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $user->id, 'account_id' => $ids['accountId'],
            'category_id' => $ids['categoryId'], 'type' => $type, 'amount' => $amount,
            'transaction_date' => $date, 'exchange_rate_to_anchor' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Build a Gemini `alt=sse` body. Frames are ordinary JSON — deliberately NOT the
     * double-encoded form the receipt scanner has to deal with.
     */
    private function sse(array $frames): string
    {
        return implode('', array_map(fn ($frame) => 'data: '.json_encode($frame)."\n\n", $frames));
    }

    private function textFrame(string $text, ?string $finish = null): array
    {
        $candidate = ['content' => ['role' => 'model', 'parts' => [['text' => $text]]]];

        if ($finish !== null) {
            $candidate['finishReason'] = $finish;
        }

        return ['candidates' => [$candidate]];
    }

    private function callFrame(string $name, array $args, ?string $thoughtSignature = null): array
    {
        $part = ['functionCall' => ['name' => $name, 'args' => $args]];

        if ($thoughtSignature !== null) {
            $part['thoughtSignature'] = $thoughtSignature;
        }

        return ['candidates' => [[
            'content' => ['role' => 'model', 'parts' => [$part]],
        ]]];
    }

    /**
     * A single candidate carrying several functionCall parts at once, the way Gemini returns
     * parallel tool calls. Each entry is [name, args, thoughtSignature|null].
     */
    private function parallelCallFrame(array $calls): array
    {
        $parts = array_map(function (array $entry) {
            [$name, $args, $signature] = [$entry[0], $entry[1], $entry[2] ?? null];

            $part = ['functionCall' => ['name' => $name, 'args' => $args]];

            if ($signature !== null) {
                $part['thoughtSignature'] = $signature;
            }

            return $part;
        }, $calls);

        return ['candidates' => [[
            'content' => ['role' => 'model', 'parts' => $parts],
        ]]];
    }

    /**
     * @return array<string, string> event name => data payload, in arrival order
     */
    private function events(string $body): array
    {
        preg_match_all("/event: (\S+)\ndata: (.*)\n/", $body, $matches, PREG_SET_ORDER);

        return $matches;
    }

    public function test_chat_streams_a_plain_text_answer_and_persists_both_messages(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->sse([
            $this->textFrame('You spent '),
            $this->textFrame('less than usual.', 'STOP'),
        ]))]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => 'How am I doing?']);

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));

        $body = $response->streamedContent();
        $names = array_column($this->events($body), 1);

        $this->assertSame(['meta', 'delta', 'delta', 'done', 'update'], $names);
        $this->assertStringContainsString('You spent ', $body);
        $this->assertStringContainsString('</stream>', $body);

        $this->assertDatabaseHas('ai_messages', [
            'user_id' => $user->id, 'role' => 'user', 'content' => 'How am I doing?',
        ]);
        $this->assertDatabaseHas('ai_messages', [
            'user_id' => $user->id, 'role' => 'assistant', 'content' => 'You spent less than usual.', 'error' => null,
        ]);

        $conversation = DB::table('ai_conversations')->where('user_id', $user->id)->first();
        $this->assertNotNull($conversation->last_message_at);
        $this->assertSame('How am I doing?', $conversation->title);
    }

    public function test_chat_runs_a_tool_then_streams_the_answer(): void
    {
        [$user, $token] = $this->authUser();
        $ids = $this->seedBaseline($user);
        $this->transaction($user, $ids, '40.00', now()->toDateString());

        $bodies = [
            $this->sse([$this->callFrame('get_spending_by_category', [
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->toDateString(),
                'type' => 'expense',
            ])]),
            $this->sse([$this->textFrame('Groceries, 40.00 USD.', 'STOP')]),
        ];

        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($bodies[0])
            ->push($bodies[1])]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => 'Where did my money go?']);

        $body = $response->assertStatus(200)->streamedContent();
        $names = array_column($this->events($body), 1);

        $this->assertSame(['meta', 'tool', 'delta', 'done', 'update'], $names);
        $this->assertStringContainsString('get_spending_by_category', $body);

        // The model's own functionCall turn must be echoed back before the functionResponse,
        // and the tool's real result must reach the second request.
        $second = Http::recorded()[1][0]->data();
        $roles = array_column($second['contents'], 'role');

        $this->assertSame(['user', 'model', 'user'], $roles);
        $this->assertSame('get_spending_by_category', $second['contents'][1]['parts'][0]['functionCall']['name']);

        $result = $second['contents'][2]['parts'][0]['functionResponse']['response']['result'];
        $this->assertSame('40.00', $result['total']);
        $this->assertSame('Groceries', $result['categories'][0]['category']);
    }

    /**
     * Gemini 3 attaches a `thoughtSignature` next to a functionCall part and rejects the next
     * request if it isn't echoed back verbatim (400 INVALID_ARGUMENT: "missing a
     * thought_signature"). That upstream 400 previously surfaced to the user as a generic
     * "AI service returned an error" — this pins the round trip that avoids it.
     */
    public function test_chat_echoes_the_thought_signature_back_on_the_next_tool_round_trip(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->sse([$this->callFrame('get_account_balances', [], 'sig-abc123')]))
            ->push($this->sse([$this->textFrame('You hold 100.00 USD.', 'STOP')]))]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => 'What is my balance?']);

        $response->assertStatus(200)->streamedContent();

        $second = Http::recorded()[1][0]->data();

        $this->assertSame('sig-abc123', $second['contents'][1]['parts'][0]['thoughtSignature']);
        $this->assertSame('get_account_balances', $second['contents'][1]['parts'][0]['functionCall']['name']);
    }

    /**
     * Gemini 3 is documented to attach a thought_signature only to the first part of a parallel
     * (multi-tool) call, but has a known upstream bug where it sometimes drops even that one once
     * 3+ tools are requested at once. Without a fallback, the next request comes back 400
     * "missing a thought_signature", surfaced to the user as "The AI service returned an error."
     */
    public function test_chat_falls_back_to_a_placeholder_signature_when_gemini_omits_it_on_a_parallel_call(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->sse([$this->parallelCallFrame([
                ['get_account_balances', []],
                ['get_budget_status', []],
                ['get_income_expense_summary', []],
            ])]))
            ->push($this->sse([$this->textFrame('Here is your overview.', 'STOP')]))]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => 'Give me a full overview.']);

        $body = $response->assertStatus(200)->streamedContent();
        $names = array_column($this->events($body), 1);

        $this->assertSame(['meta', 'tool', 'tool', 'tool', 'delta', 'done', 'update'], $names);

        $second = Http::recorded()[1][0]->data();
        $parts = $second['contents'][1]['parts'];

        $this->assertSame('skip_thought_signature_validator', $parts[0]['thoughtSignature']);
        $this->assertArrayNotHasKey('thoughtSignature', $parts[1]);
        $this->assertArrayNotHasKey('thoughtSignature', $parts[2]);
    }

    public function test_chat_forces_an_answer_when_the_tool_loop_hits_its_cap(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);

        config(['services.gemini.max_tool_iterations' => 3]);

        // A model that only ever asks for more tools, until the final forced round trip.
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->sse([$this->callFrame('get_account_balances', [])]))
            ->push($this->sse([$this->callFrame('get_account_balances', [])]))
            ->push($this->sse([$this->textFrame('You hold 100.00 USD.', 'STOP')]))]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => 'What is my balance?']);

        $body = $response->assertStatus(200)->streamedContent();
        $names = array_column($this->events($body), 1);

        // A `done`, not an `error` — the user gets a real answer at the cap.
        $this->assertSame(['meta', 'tool', 'tool', 'delta', 'done', 'update'], $names);

        $recorded = Http::recorded();
        $this->assertCount(3, $recorded);
        $this->assertSame('AUTO', $recorded[0][0]->data()['toolConfig']['functionCallingConfig']['mode']);
        $this->assertSame('NONE', $recorded[2][0]->data()['toolConfig']['functionCallingConfig']['mode']);

        $this->assertDatabaseHas('ai_messages', [
            'user_id' => $user->id, 'role' => 'assistant', 'content' => 'You hold 100.00 USD.',
        ]);
    }

    public function test_upstream_failure_becomes_an_error_event_and_marks_the_message(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('nope', 500)]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => 'How am I doing?']);

        $body = $response->assertStatus(200)->streamedContent();
        $names = array_column($this->events($body), 1);

        $this->assertSame(['meta', 'error', 'update'], $names);

        $assistant = DB::table('ai_messages')->where('user_id', $user->id)->where('role', 'assistant')->first();
        $this->assertNotNull($assistant->error);
    }

    public function test_chat_requires_authentication_and_uses_the_normal_envelope_for_validation(): void
    {
        $this->postJson('/api/v1/ai/chat', ['message' => 'hi'])->assertStatus(401);

        [, $token] = $this->authUser();

        // Failures before the stream opens keep the standard JSON envelope. `message` is only
        // optional when an image carries the question, so an empty body with neither still 422s.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => ''])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.message.0', 'The message field is required when image is not present.');
    }

    public function test_conversation_endpoints_return_the_transcript_and_are_user_scoped(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            $this->sse([$this->textFrame('Hello.', 'STOP')])
        )]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => 'Hi there'])
            ->streamedContent();

        $conversationId = DB::table('ai_conversations')->where('user_id', $user->id)->value('id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/ai/conversations')
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.id', $conversationId)
            ->assertJsonPath('data.items.0.title', 'Hi there');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/ai/conversations/{$conversationId}")
            ->assertStatus(200)
            ->assertJsonPath('data.messages.0.role', 'user')
            ->assertJsonPath('data.messages.0.content', 'Hi there')
            ->assertJsonPath('data.messages.1.role', 'assistant')
            ->assertJsonPath('data.messages.1.content', 'Hello.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/ai/conversations/{$conversationId}")
            ->assertStatus(200);

        $this->assertNotNull(DB::table('ai_conversations')->where('id', $conversationId)->value('deleted_at'));
    }

    /**
     * Its own test on purpose: the auth guard caches the first user it resolves for the whole
     * test method, so authenticating as two users in one test would silently keep the first.
     */
    public function test_a_conversation_is_not_readable_by_another_user(): void
    {
        [$owner] = $this->authUser();
        [, $intruderToken] = $this->authUser();

        $conversationId = (string) Str::ulid();

        DB::table('ai_conversations')->insert([
            'id' => $conversationId, 'user_id' => $owner->id, 'title' => 'Private',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('ai_messages')->insert([
            'id' => (string) Str::ulid(), 'conversation_id' => $conversationId, 'user_id' => $owner->id,
            'role' => 'user', 'content' => 'Secret question', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$intruderToken)
            ->getJson("/api/v1/ai/conversations/{$conversationId}")
            ->assertStatus(404);

        $this->withHeader('Authorization', 'Bearer '.$intruderToken)
            ->getJson('/api/v1/ai/conversations')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.items');

        // A delete aimed at someone else's conversation must be a no-op, not a silent success
        // that removes it.
        $this->withHeader('Authorization', 'Bearer '.$intruderToken)
            ->deleteJson("/api/v1/ai/conversations/{$conversationId}");

        $this->assertNull(DB::table('ai_conversations')->where('id', $conversationId)->value('deleted_at'));
    }

    public function test_sync_pull_carries_conversations_but_not_messages(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            $this->sse([$this->textFrame('Hello.', 'STOP')])
        )]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => 'Hi there'])
            ->streamedContent();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.ai_conversations');

        $this->assertArrayNotHasKey('ai_messages', $response->json('data'));
    }

    public function test_sync_push_allows_rename_and_delete_but_refuses_create(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            $this->sse([$this->textFrame('Hello.', 'STOP')])
        )]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => 'Hi there'])
            ->streamedContent();

        $conversationId = DB::table('ai_conversations')->where('user_id', $user->id)->value('id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'changes' => [
                    ['client_change_id' => 'c1', 'entity' => 'ai_conversation', 'op' => 'create',
                        'id' => (string) Str::ulid(), 'data' => ['title' => 'Nope']],
                    ['client_change_id' => 'c2', 'entity' => 'ai_conversation', 'op' => 'update',
                        'id' => $conversationId, 'data' => ['title' => 'Renamed']],
                ],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.1.status', 'applied');

        $this->assertDatabaseHas('ai_conversations', ['id' => $conversationId, 'title' => 'Renamed']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'changes' => [[
                    'client_change_id' => 'c3', 'entity' => 'ai_conversation', 'op' => 'delete',
                    'id' => $conversationId,
                ]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'applied');
    }

    public function test_tools_never_leak_another_users_data(): void
    {
        [$userA] = $this->authUser();
        [$userB] = $this->authUser();

        $idsA = $this->seedBaseline($userA);
        $idsB = $this->seedBaseline($userB, 'EUR');

        $this->transaction($userA, $idsA, '40.00', now()->toDateString());
        $this->transaction($userB, $idsB, '999.00', now()->toDateString());

        $tools = app(AnalystToolService::class);
        $range = [
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'type' => 'expense',
        ];

        $spending = $tools->getSpendingByCategory($userA->id, $range);
        $this->assertSame('40.00', $spending['total']);

        $transactions = $tools->listTransactions($userA->id, $range);
        $this->assertCount(1, $transactions['transactions']);
        $this->assertSame('40.00', $transactions['transactions'][0]['amount']);

        $summary = $tools->getIncomeExpenseSummary($userA->id, $range + ['group_by' => 'month']);
        $this->assertSame('40.00', $summary['periods'][0]['expense']);

        // Balances: user A's account only, and never user B's 999.00 spend.
        $balances = $tools->getAccountBalances($userA->id);
        $this->assertCount(1, $balances['accounts']);
        $this->assertSame('60.00', $balances['net_worth']);
        $this->assertSame('USD', $balances['currency']);

        // Declarations must only enumerate the caller's own ids.
        $declarations = collect($tools->declarations($userA->id))->keyBy('name');
        $this->assertSame([$idsA['categoryId']], $declarations['list_transactions']['parameters']['properties']['category_id']['enum']);
        $this->assertSame([$idsA['accountId']], $declarations['list_transactions']['parameters']['properties']['account_id']['enum']);
    }

    public function test_tools_exclude_soft_deleted_transactions(): void
    {
        [$user] = $this->authUser();
        $ids = $this->seedBaseline($user);

        $this->transaction($user, $ids, '40.00', now()->toDateString());

        DB::table('transactions')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $user->id, 'account_id' => $ids['accountId'],
            'category_id' => $ids['categoryId'], 'type' => 'expense', 'amount' => '500.00',
            'transaction_date' => now()->toDateString(), 'exchange_rate_to_anchor' => 1,
            'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $spending = app(AnalystToolService::class)->getSpendingByCategory($user->id, [
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'type' => 'expense',
        ]);

        $this->assertSame('40.00', $spending['total']);
    }

    public function test_spending_converts_to_the_anchor_currency(): void
    {
        [$user] = $this->authUser();
        $ids = $this->seedBaseline($user);

        // A transaction booked in a currency worth 0.5 anchor units at write time.
        DB::table('transactions')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $user->id, 'account_id' => $ids['accountId'],
            'category_id' => $ids['categoryId'], 'type' => 'expense', 'amount' => '100.00',
            'transaction_date' => now()->toDateString(), 'exchange_rate_to_anchor' => '0.5',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $spending = app(AnalystToolService::class)->getSpendingByCategory($user->id, [
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'type' => 'expense',
        ]);

        $this->assertSame('50.00', $spending['total']);
        $this->assertSame('USD', $spending['currency']);
    }

    public function test_unknown_tool_names_are_reported_to_the_model_not_executed(): void
    {
        [$user] = $this->authUser();
        $this->seedBaseline($user);

        $result = app(AnalystToolService::class)->call($user->id, 'drop_everything', []);

        $this->assertSame(['error' => "Unknown tool 'drop_everything'."], $result);
    }

    private function fakeS3(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options = []) => "https://fake-bucket.s3.amazonaws.com/{$path}"
        );
    }

    public function test_chat_accepts_an_image_attachment_and_forwards_it_to_the_model(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);
        $this->fakeS3();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            $this->sse([$this->textFrame('That is a 12.50 coffee receipt.', 'STOP')])
        )]);

        // No `message` at all: the image is the question.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['image' => UploadedFile::fake()->image('receipt.jpg', 64, 64)]);

        $response->assertStatus(200)->streamedContent();

        $message = DB::table('ai_messages')->where('user_id', $user->id)->where('role', 'user')->first();

        $this->assertNotNull($message->image_path);
        $this->assertStringStartsWith("ai-attachments/{$user->id}/", $message->image_path);
        $this->assertSame('image/jpeg', $message->image_mime);
        $this->assertSame('', $message->content);
        Storage::disk('s3')->assertExists($message->image_path);

        // The photo reaches Gemini as an inline_data part on the user turn.
        $parts = Http::recorded()[0][0]->data()['contents'][0]['parts'];

        $this->assertCount(1, $parts);
        $this->assertSame('image/jpeg', $parts[0]['inline_data']['mime_type']);
        $this->assertNotEmpty($parts[0]['inline_data']['data']);

        // An attachment-only turn still needs a readable chat-list entry.
        $this->assertSame('Image', DB::table('ai_conversations')->where('user_id', $user->id)->value('title'));
    }

    public function test_transcript_exposes_a_signed_url_for_an_attachment(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);
        $this->fakeS3();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            $this->sse([$this->textFrame('Noted.', 'STOP')])
        )]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', [
                'message' => 'What is this?',
                // Big enough that the JPEG re-encode is unambiguously smaller than the PNG, so
                // normalisation is what decides the stored mime.
                'image' => UploadedFile::fake()->image('receipt.png', 1200, 900),
            ])
            ->streamedContent();

        $conversationId = DB::table('ai_conversations')->where('user_id', $user->id)->value('id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/ai/conversations/{$conversationId}")
            ->assertStatus(200)
            ->assertJsonPath('data.messages.0.image_mime', 'image/jpeg')
            ->assertJsonPath('data.messages.1.image_url', null);

        $url = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/ai/conversations/{$conversationId}")
            ->json('data.messages.0.image_url');

        $this->assertStringContainsString("ai-attachments/{$user->id}/", $url);
    }

    public function test_only_the_newest_attachments_are_re_sent_on_later_turns(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);
        $this->fakeS3();

        // Budget of one: the older photo must degrade to a note rather than being re-billed.
        config(['services.gemini.max_history_images' => 1]);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->sse([$this->textFrame('First answer.', 'STOP')]))
            ->push($this->sse([$this->textFrame('Second answer.', 'STOP')]))]);

        $first = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', [
                'message' => 'First',
                'image' => UploadedFile::fake()->image('one.jpg', 64, 64),
            ]);

        $first->streamedContent();
        $conversationId = DB::table('ai_conversations')->where('user_id', $user->id)->value('id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', [
                'conversation_id' => $conversationId,
                'message' => 'Second',
                'image' => UploadedFile::fake()->image('two.jpg', 64, 64),
            ])
            ->streamedContent();

        $contents = Http::recorded()[1][0]->data()['contents'];

        $this->assertSame(['user', 'model', 'user'], array_column($contents, 'role'));

        // Turn 1 keeps its text but loses the pixels.
        $this->assertArrayNotHasKey('inline_data', $contents[0]['parts'][0]);
        $this->assertStringContainsString('image was attached', $contents[0]['parts'][0]['text']);
        $this->assertSame('First', $contents[0]['parts'][1]['text']);

        // Turn 3 — the newest — still carries them.
        $this->assertArrayHasKey('inline_data', $contents[2]['parts'][0]);
        $this->assertSame('Second', $contents[2]['parts'][1]['text']);
    }

    public function test_reference_tags_survive_only_for_records_the_user_owns(): void
    {
        [$user, $token] = $this->authUser();
        $ids = $this->seedBaseline($user);

        $realId = (string) Str::ulid();
        $strangerId = (string) Str::ulid();

        DB::table('transactions')->insert([
            'id' => $realId, 'user_id' => $user->id, 'account_id' => $ids['accountId'],
            'category_id' => $ids['categoryId'], 'type' => 'expense', 'amount' => '40.00',
            'transaction_date' => now()->toDateString(), 'exchange_rate_to_anchor' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The real tag is deliberately split across three deltas — that is the normal case, not
        // an edge case, and emitting half of it would be unrecoverable.
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->sse([
            $this->textFrame('Groceries [[transa'),
            $this->textFrame('ction:'.$realId),
            $this->textFrame(']] led, then rent [[transaction:'.$strangerId.']].', 'STOP'),
        ]))]);

        $body = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => 'What did I spend on?'])
            ->assertStatus(200)
            ->streamedContent();

        // No partial tag ever went out on the wire.
        $this->assertStringNotContainsString('[[transa"', $body);

        $content = DB::table('ai_messages')
            ->where('user_id', $user->id)->where('role', 'assistant')->value('content');

        $this->assertSame("Groceries [[transaction:{$realId}]] led, then rent .", $content);
    }

    public function test_reference_tags_are_dropped_for_unknown_types_and_deleted_records(): void
    {
        [$user] = $this->authUser();
        $ids = $this->seedBaseline($user);

        $deletedId = (string) Str::ulid();

        DB::table('transactions')->insert([
            'id' => $deletedId, 'user_id' => $user->id, 'account_id' => $ids['accountId'],
            'category_id' => $ids['categoryId'], 'type' => 'expense', 'amount' => '40.00',
            'transaction_date' => now()->toDateString(), 'exchange_rate_to_anchor' => 1,
            'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $filter = new AnswerTagFilter($user->id);

        // A soft-deleted record has no screen to open, and `account` is not a linkable type.
        $out = $filter->push("a [[transaction:{$deletedId}]] b [[account:{$ids['accountId']}]] c");

        $this->assertSame('a  b  c', $out.$filter->flush());

        // '[[' in ordinary prose is released rather than stalling the stream forever.
        $prose = new AnswerTagFilter($user->id);
        $this->assertSame(
            'see [[the appendix for the full breakdown of every single category you spent on]',
            $prose->push('see [[the appendix for the full breakdown of every single category you spent on]')
        );

        // An opener the model never closed is discarded at flush, not leaked as raw markup.
        $dangling = new AnswerTagFilter($user->id);
        $this->assertSame('done ', $dangling->push('done [[transaction:01J').$dangling->flush());
    }

    public function test_row_tools_return_ids_and_transfers_are_user_scoped(): void
    {
        [$userA] = $this->authUser();
        [$userB] = $this->authUser();

        $idsA = $this->seedBaseline($userA);
        $idsB = $this->seedBaseline($userB, 'EUR');

        $transactionId = (string) Str::ulid();

        DB::table('transactions')->insert([
            'id' => $transactionId, 'user_id' => $userA->id, 'account_id' => $idsA['accountId'],
            'category_id' => $idsA['categoryId'], 'type' => 'expense', 'amount' => '40.00',
            'transaction_date' => now()->toDateString(), 'exchange_rate_to_anchor' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A second account for A, so a transfer has somewhere to go.
        $savingsId = (string) Str::ulid();

        DB::table('accounts')->insert([
            'id' => $savingsId, 'user_id' => $userA->id, 'user_currency_id' => $idsA['userCurrencyId'],
            'name' => 'Savings', 'type' => 'bank_account', 'initial_balance' => '0', 'is_default' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $transferId = (string) Str::ulid();

        DB::table('transfers')->insert([
            'id' => $transferId, 'user_id' => $userA->id, 'from_account_id' => $idsA['accountId'],
            'to_account_id' => $savingsId, 'from_amount' => '25.00', 'to_amount' => '25.00',
            'exchange_rate' => 1, 'fee' => '1.00', 'description' => 'Monthly saving',
            'transfer_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $strangerAccountId = (string) Str::ulid();

        DB::table('accounts')->insert([
            'id' => $strangerAccountId, 'user_id' => $userB->id, 'user_currency_id' => $idsB['userCurrencyId'],
            'name' => 'Their savings', 'type' => 'bank_account', 'initial_balance' => '0', 'is_default' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('transfers')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $userB->id, 'from_account_id' => $idsB['accountId'],
            'to_account_id' => $strangerAccountId, 'from_amount' => '900.00', 'to_amount' => '900.00',
            'exchange_rate' => 1, 'fee' => '0', 'description' => 'Not yours',
            'transfer_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $tools = app(AnalystToolService::class);
        $range = [
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ];

        // Without an id in the tool result the model has nothing legitimate to put in a tag.
        $transactions = $tools->listTransactions($userA->id, $range);
        $this->assertSame($transactionId, $transactions['transactions'][0]['id']);

        $transfers = $tools->listTransfers($userA->id, $range);
        $this->assertCount(1, $transfers['transfers']);
        $this->assertSame($transferId, $transfers['transfers'][0]['id']);
        $this->assertSame('Checking', $transfers['transfers'][0]['from_account']);
        $this->assertSame('Savings', $transfers['transfers'][0]['to_account']);
        $this->assertSame('25.00', $transfers['transfers'][0]['amount']);
        $this->assertSame('1.00', $transfers['transfers'][0]['fee']);
        $this->assertSame('USD', $transfers['currency']);

        // list_transfers is reachable through the same match whitelist as everything else.
        $this->assertSame($transfers, $tools->call($userA->id, 'list_transfers', $range));
    }

    public function test_attachments_are_downscaled_and_re_encoded_before_storage_or_upload(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);
        $this->fakeS3();

        config(['services.gemini.image.max_edge' => 512]);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            $this->sse([$this->textFrame('Noted.', 'STOP')])
        )]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', [
                'message' => 'Read this',
                'image' => UploadedFile::fake()->image('huge.png', 2400, 1200),
            ])
            ->streamedContent();

        $message = DB::table('ai_messages')->where('user_id', $user->id)->where('role', 'user')->first();

        // Only the shrunk copy is kept — the original never reaches the bucket.
        $this->assertStringEndsWith('.jpg', $message->image_path);
        $this->assertSame('image/jpeg', $message->image_mime);

        $stored = Storage::disk('s3')->get($message->image_path);
        [$width, $height, $type] = getimagesizefromstring($stored);

        $this->assertSame(512, $width);
        $this->assertSame(256, $height);
        $this->assertSame(IMAGETYPE_JPEG, $type);

        // And the same shrunk bytes are what Gemini is billed for.
        $sent = Http::recorded()[0][0]->data()['contents'][0]['parts'][0]['inline_data'];

        $this->assertSame('image/jpeg', $sent['mime_type']);
        $this->assertSame(base64_encode($stored), $sent['data']);
    }

    public function test_uploads_over_the_configured_ceiling_are_refused(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);
        $this->fakeS3();

        config(['services.gemini.image.max_upload_kb' => 20]);

        Http::fake();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', [
                'message' => 'Read this',
                'image' => UploadedFile::fake()->create('big.jpg', 64, 'image/jpeg'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_messages', 0);
    }

    public function test_the_daily_turn_ration_is_refused_with_the_normal_envelope(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);

        config(['services.gemini.daily_turn_limit' => 1]);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            $this->sse([$this->textFrame('Hello.', 'STOP')])
        )]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => 'First'])
            ->assertStatus(200)
            ->streamedContent();

        // Refused before the stream opens, so this is a readable JSON envelope and not an
        // `error` event the client would have to parse out of a 200 SSE body.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => 'Second'])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', "You have reached today's limit of 1 analyst messages. Try again tomorrow.");

        // Nothing was written and nothing was spent on the refused turn.
        $this->assertSame(1, DB::table('ai_messages')->where('role', 'user')->count());
        Http::assertSentCount(1);
    }

    public function test_the_daily_image_ration_still_allows_text_questions(): void
    {
        [$user, $token] = $this->authUser();
        $this->seedBaseline($user);
        $this->fakeS3();

        config(['services.gemini.daily_image_limit' => 1]);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            $this->sse([$this->textFrame('Noted.', 'STOP')])
        )]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['image' => UploadedFile::fake()->image('one.jpg', 64, 64)])
            ->assertStatus(200)
            ->streamedContent();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['image' => UploadedFile::fake()->image('two.jpg', 64, 64)])
            ->assertStatus(429)
            ->assertJsonPath('message', "You have reached today's limit of 1 image attachments. You can still ask questions without a photo.");

        // The image ration is separate from the turn ration: text keeps working.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => 'How am I doing?'])
            ->assertStatus(200)
            ->streamedContent();

        $this->assertSame(1, DB::table('ai_messages')->whereNotNull('image_path')->count());
        $this->assertSame(2, DB::table('ai_messages')->where('role', 'user')->count());
    }

    /**
     * Regression: the megapixel backstop was originally set at 12 MP, which reads like it covers
     * every phone — but the standard 4032x3024 main sensor is 12,192,768 pixels, just over it, so
     * resizing was skipped for the single most common upload there is. The guard exists for
     * decode bombs, not for cameras.
     */
    public function test_a_standard_phone_photo_is_not_skipped_by_the_megapixel_backstop(): void
    {
        $file = UploadedFile::fake()->image('iphone.jpg', 4032, 3024);

        $this->assertGreaterThan(12_000_000, 4032 * 3024);

        $normalised = app(ImageAttachment::class)->normalise($file);
        [$width, $height] = getimagesizefromstring($normalised['bytes']);

        $this->assertSame(1024, $width);
        $this->assertSame(768, $height);
        $this->assertSame('image/jpeg', $normalised['mime']);
    }
}
