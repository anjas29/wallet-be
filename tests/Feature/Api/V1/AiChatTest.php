<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Ai\AnalystToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    private function callFrame(string $name, array $args): array
    {
        return ['candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => $name, 'args' => $args]]]],
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

        // Failures before the stream opens keep the standard JSON envelope.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai/chat', ['message' => ''])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.message.0', 'The message field is required.');
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
}
