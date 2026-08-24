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
use Illuminate\Support\Facades\Log;
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
        TXT;

    public function __construct(
        private GeminiStreamClient $gemini,
        private AnalystToolService $tools,
        private AiConversationService $conversations,
        private AiMessageService $messages,
    ) {}

    /**
     * Persist the user's turn and reserve the assistant row. Runs before the stream opens so
     * validation and ownership errors still get the standard JSON envelope.
     *
     * @return array{user: User, conversation: AiConversation, userMessage: AiMessage, assistantMessage: AiMessage}
     */
    public function beginTurn(User $user, string $message, ?string $conversationId, ?string $messageId): array
    {
        $conversation = $this->conversations->resolveForTurn($user, $conversationId, $message);

        // History must be read before the new message is written, or the prompt ends with the
        // question duplicated.
        $history = $this->messages->historyForPrompt($user->id, $conversation->id);

        $userMessage = new AiMessage;
        $userMessage->id = $messageId ?? (string) Str::ulid();
        $userMessage->fill([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $message,
        ])->save();

        // Reserved empty so its id can go out in `meta` and the client can render a placeholder
        // bubble immediately. historyForPrompt() skips empty rows, so a stream that dies here
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
            'history' => $history,
        ];
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

        $contents = array_merge($turn['history'], [
            ['role' => 'user', 'parts' => [['text' => $turn['userMessage']->content]]],
        ]);

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
                        $calls[] = $part['call'];

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
                        $answer .= $part['text'];

                        yield new StreamedEvent('delta', ['text' => $part['text']]);
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
                    fn (array $call) => ['functionCall' => $call],
                    $calls
                )];

                $responses = [];

                foreach ($calls as $call) {
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
