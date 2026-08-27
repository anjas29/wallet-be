<?php

namespace App\Services\Ai;

use App\Exceptions\AiChatException;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\AiConversationService;
use App\Services\AiMessageService;
use Generator;
use Illuminate\Http\StreamedEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates one chat turn: history -> Gemini tool loop -> streamed prose -> persistence.
 *
 * Split in two on purpose. beginTurn() runs BEFORE any SSE header is flushed, so anything it
 * throws still renders through the normal ApiResponse envelope in bootstrap/app.php. Once
 * stream() starts yielding, the status code is already sent and the only way to report a
 * failure is an `error` event.
 */
class AiChatService
{
    /**
     * Gemini 3 requires a `thought_signature` on the first functionCall part of a parallel
     * (multi-tool) turn, but has a known upstream inconsistency where it sometimes omits it once
     * 3+ tools are called at once — see https://github.com/googleapis/js-genai/issues/1275. That
     * omission previously made the *next* request come back 400 "missing a thought_signature",
     * which this service surfaced as the generic "AI service returned an error." Google documents
     * this exact string as the escape hatch for a missing signature.
     */
    private const FALLBACK_THOUGHT_SIGNATURE = 'skip_thought_signature_validator';

    private const SYSTEM_PROMPT = <<<'TXT'
        You are a personal finance analyst embedded in a wallet app. Answer the user's questions
        about their own finances.

        Rules:
        - Never invent figures. Every number you state must come from a tool result. If the tools
          do not cover something, say so plainly.
        - Call tools as needed before answering. Prefer one call with a wide date range over many
          narrow ones.
        - All tool amounts are already converted to the user's anchor currency and labelled with
          `currency`. Never sum values across different `currency` labels.
        - Be concise and concrete. Lead with the answer, then the supporting numbers. Format money
          with thousands separators and the currency code.
        - You are read-only. If asked to create, edit or delete anything, explain that you cannot
          and describe where in the app to do it.
        - The user may attach an image (a receipt, a statement, a screenshot). Read it and answer
          from it, but remember it is not in their books until they enter it — say so rather than
          implying it has been recorded.

        Linking records:
        - When you name a specific transaction, transfer or liability that came back from a tool,
          put a reference tag straight after it, using the `id` from that tool result:
          "your biggest expense was the 84.20 supermarket run on 12 Aug [[transaction:01J...]]".
        - Valid types are exactly `transaction`, `transfer` and `liability`. Nothing else.
        - Copy ids verbatim from tool results. Never invent or reconstruct one — a tag whose id is
          not a real record of this user's is deleted before the user sees it.
        - A tag is a marker, not a word: write the sentence so it still reads correctly with every
          tag removed. One tag per record, and never inside a heading or a table cell.
        TXT;

    public function __construct(
        private GeminiStreamClient $gemini,
        private AnalystToolService $tools,
        private AiConversationService $conversations,
        private AiMessageService $messages,
        private ImageAttachment $attachments,
    ) {}

    /**
     * Persist the user's turn (and its attachment) and reserve the assistant row. Runs before the
     * stream opens so validation, ownership and upload errors still get the standard JSON
     * envelope.
     *
     * @return array{user: User, conversation: AiConversation, userMessage: AiMessage, assistantMessage: AiMessage, contents: array}
     */
    public function beginTurn(User $user, string $message, ?string $conversationId, ?string $messageId, ?UploadedFile $image = null): array
    {
        // Before anything is written or uploaded, so a user over quota costs nothing at all.
        $this->enforceDailyLimits($user, $image !== null);

        $conversation = $this->conversations->resolveForTurn($user, $conversationId, $message, $image !== null);

        $attachment = $image !== null ? $this->storeAttachment($user, $image) : null;

        $userMessage = new AiMessage;
        $userMessage->id = $messageId ?? (string) Str::ulid();
        $userMessage->fill([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $message,
            'image_path' => $attachment['path'] ?? null,
            'image_mime' => $attachment['mime'] ?? null,
        ])->save();

        // Read after the user's row is written, so the new question is already in it.
        $contents = $this->messages->contentsForPrompt($user->id, $conversation->id);

        // Reserved empty so its id can go out in `meta` and the client can render a placeholder
        // bubble immediately. contentsForPrompt() skips empty rows, so a stream that dies here
        // cannot poison the next turn's context.
        $assistantMessage = new AiMessage;
        $assistantMessage->id = (string) Str::ulid();
        $assistantMessage->fill([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => '',
        ])->save();

        $conversation->forceFill(['last_message_at' => now()])->save();

        return [
            'user' => $user,
            'conversation' => $conversation,
            'userMessage' => $userMessage,
            'assistantMessage' => $assistantMessage,
            'contents' => $contents,
        ];
    }

    /**
     * Downscale, then store on the private disk — no public URL, because chat attachments are
     * receipts and statements, not avatars. Only the shrunk copy is ever kept: the original is
     * of no further use once the model and the transcript both read the normalised one.
     *
     * @return array{path: string, mime: string}
     */
    private function storeAttachment(User $user, UploadedFile $image): array
    {
        $normalised = $this->attachments->normalise($image);

        $path = "ai-attachments/{$user->id}/".Str::ulid().'.'.$normalised['extension'];

        Storage::disk('s3')->put($path, $normalised['bytes']);

        return ['path' => $path, 'mime' => $normalised['mime']];
    }

    /**
     * Free-tier ration, counted per calendar day off the messages themselves rather than a cache
     * key, so it survives a restart and cannot be reset by clearing the cache.
     *
     * Throws before the stream opens, which is what makes the 429 a normal JSON envelope the
     * client can read rather than an `error` event it has to special-case.
     */
    private function enforceDailyLimits(User $user, bool $hasImage): void
    {
        $turnLimit = (int) config('services.gemini.daily_turn_limit', 20);
        $imageLimit = (int) config('services.gemini.daily_image_limit', 10);

        $today = AiMessage::query()
            ->where('user_id', $user->id)
            ->where('role', 'user')
            ->where('created_at', '>=', now()->startOfDay());

        if ($turnLimit > 0 && (clone $today)->count() >= $turnLimit) {
            throw new AiChatException(
                "You have reached today's limit of {$turnLimit} analyst messages. Try again tomorrow.",
                429,
            );
        }

        if ($hasImage && $imageLimit > 0 && (clone $today)->whereNotNull('image_path')->count() >= $imageLimit) {
            throw new AiChatException(
                "You have reached today's limit of {$imageLimit} image attachments. You can still ask questions without a photo.",
                429,
            );
        }
    }

    /**
     * The SSE body. Yields StreamedEvent instances; eventStream() appends its `</stream>`
     * sentinel after the last one.
     *
     * @return Generator<int, StreamedEvent>
     */
    public function stream(array $turn): Generator
    {
        /** @var User $user */
        $user = $turn['user'];
        /** @var AiMessage $assistant */
        $assistant = $turn['assistantMessage'];

        yield new StreamedEvent('meta', [
            'conversation_id' => $turn['conversation']->id,
            'user_message_id' => $turn['userMessage']->id,
            'assistant_message_id' => $assistant->id,
        ]);

        $contents = $turn['contents'];

        // Strips reference tags the model made up before they reach the client. Holds text back
        // across delta boundaries, so nothing is emitted until its enclosing tag is judged.
        $tags = new AnswerTagFilter($user->id);

        $answer = '';
        $finishReason = null;

        try {
            $maxIterations = max(1, (int) config('services.gemini.max_tool_iterations', 5));

            for ($iteration = 1; $iteration <= $maxIterations; $iteration++) {
                // On the last permitted round trip, forbid further tool calls so the model must
                // answer from what it already gathered instead of us failing the turn.
                $forceAnswer = $iteration === $maxIterations;

                $calls = [];
                $text = '';

                foreach ($this->gemini->stream($this->payload($user->id, $contents, $forceAnswer)) as $part) {
                    if ($part['type'] === 'functionCall') {
                        $calls[] = ['call' => $part['call'], 'thoughtSignature' => $part['thoughtSignature']];

                        continue;
                    }

                    if ($part['type'] === 'finish') {
                        $finishReason = $part['reason'];

                        continue;
                    }

                    // Text arriving in a turn that also calls tools is preamble ("let me check
                    // that..."), so hold it back until we know the turn's shape.
                    $text .= $part['text'];

                    if ($calls === []) {
                        $safe = $tags->push($part['text']);

                        if ($safe !== '') {
                            $answer .= $safe;

                            yield new StreamedEvent('delta', ['text' => $safe]);
                        }
                    }
                }

                if ($calls === []) {
                    break;
                }

                // Discard preamble that preceded a tool call; it was never streamed.
                if ($answer === '' && $text !== '') {
                    $text = '';
                }

                $contents[] = ['role' => 'model', 'parts' => array_map(
                    fn (array $entry, int $index) => array_filter([
                        'functionCall' => $entry['call'],
                        // Only the first part is required to carry a signature; the rest are
                        // sent as Gemini returned them (usually none at all).
                        'thoughtSignature' => $index === 0
                            ? ($entry['thoughtSignature'] ?? self::FALLBACK_THOUGHT_SIGNATURE)
                            : $entry['thoughtSignature'],
                    ], fn ($value) => $value !== null),
                    $calls,
                    array_keys($calls)
                )];

                $responses = [];

                foreach ($calls as $entry) {
                    $call = $entry['call'];
                    $name = (string) ($call['name'] ?? '');

                    yield new StreamedEvent('tool', ['name' => $name]);

                    $responses[] = ['functionResponse' => [
                        'name' => $name,
                        'response' => ['result' => $this->runTool($user->id, $name, (array) ($call['args'] ?? []))],
                    ]];
                }

                // Gemini requires the model's own functionCall turn to be echoed back before the
                // matching functionResponse parts, or the next request is rejected.
                $contents[] = ['role' => 'user', 'parts' => $responses];
            }

            // Whatever the filter is still holding: a closing tag that never arrived is dropped.
            $tail = $tags->flush();

            if ($tail !== '') {
                $answer .= $tail;

                yield new StreamedEvent('delta', ['text' => $tail]);
            }

            $assistant->forceFill(['content' => $answer])->save();

            yield new StreamedEvent('done', [
                'assistant_message_id' => $assistant->id,
                'finish_reason' => $finishReason,
            ]);
        } catch (AiChatException $e) {
            yield from $this->fail($assistant, $answer, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('AI chat turn failed', ['error' => $e->getMessage(), 'message_id' => $assistant->id]);

            yield from $this->fail($assistant, $answer, 'Something went wrong generating the answer.');
        }
    }

    /**
     * @return Generator<int, StreamedEvent>
     */
    private function fail(AiMessage $assistant, string $partial, string $message): Generator
    {
        $assistant->forceFill(['content' => $partial, 'error' => $message])->save();

        yield new StreamedEvent('error', ['message' => $message]);
    }

    /**
     * A tool that throws must not kill the stream — hand the model the error so it can explain
     * or try a different angle.
     */
    private function runTool(string $userId, string $name, array $args): array
    {
        try {
            return $this->tools->call($userId, $name, $args);
        } catch (Throwable $e) {
            Log::warning('AI analyst tool failed', ['tool' => $name, 'error' => $e->getMessage()]);

            return ['error' => 'That lookup failed.'];
        }
    }

    private function payload(string $userId, array $contents, bool $forceAnswer): array
    {
        return [
            'systemInstruction' => ['parts' => [['text' => $this->systemInstruction($userId)]]],
            'contents' => $contents,
            'tools' => [['functionDeclarations' => $this->tools->declarations($userId)]],
            'toolConfig' => ['functionCallingConfig' => ['mode' => $forceAnswer ? 'NONE' : 'AUTO']],
        ];
    }

    /**
     * Carries what the tool schemas cannot: without today's date the model cannot resolve
     * relative ranges like "last month".
     */
    private function systemInstruction(string $userId): string
    {
        $anchor = $this->tools->anchorCode($userId);

        return self::SYSTEM_PROMPT
            ."\n\nToday's date is ".now()->toDateString().'.'
            .($anchor ? "\nThe user's anchor currency is {$anchor}." : '');
    }
}
