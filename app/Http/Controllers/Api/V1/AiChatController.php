<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiConversationResource;
use App\Services\Ai\AiChatService;
use App\Services\AiConversationService;
use App\Services\AiMessageService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('AI Analyst', weight: 12)]
class AiChatController extends Controller
{
    public function __construct(
        private AiChatService $chat,
        private AiConversationService $conversations,
        private AiMessageService $messages,
    ) {}

    /**
     * Chat with the analyst
     *
     * Ask a free-form question about your own finances. The analyst is **read-only** — it queries
     * your accounts, transactions, budgets and liabilities to answer, and never writes anything.
     *
     * Unlike every other endpoint, this returns a **Server-Sent Event stream**
     * (`text/event-stream`), not the usual JSON envelope. Failures *before* the stream opens
     * (validation, auth, unconfigured service) still use the standard envelope; failures after it
     * opens arrive as an `error` event.
     *
     * Events, in order:
     *
     * | Event   | Data | When |
     * |---------|------|------|
     * | `meta`  | `{conversation_id, user_message_id, assistant_message_id}` | immediately, before the model runs |
     * | `tool`  | `{name}` | each data lookup the analyst performs |
     * | `delta` | `{text}` | each chunk of the answer, in order |
     * | `done`  | `{assistant_message_id, finish_reason}` | after the answer is persisted |
     * | `error` | `{message}` | the turn failed; any partial answer is kept |
     *
     * The stream terminates with a literal `</stream>` sentinel.
     *
     * Supply `conversation_id` to continue an existing chat, or omit it to start a new one. Both
     * ULIDs may be client-generated, matching the offline-first convention used by `/sync/push`.
     */
    public function chat(Request $request)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string', 'ulid'],
            'message_id' => ['nullable', 'string', 'ulid'],
        ]);

        $turn = $this->chat->beginTurn(
            $request->user(),
            $data['message'],
            $data['conversation_id'] ?? null,
            $data['message_id'] ?? null,
        );

        return response()->eventStream(fn () => $this->chat->stream($turn));
    }

    /**
     * List conversations
     *
     * Newest first. Transcripts are not included — read one conversation to get its messages.
     */
    public function index(Request $request)
    {
        $items = $this->conversations->listRecent($request->user()->id, $this->limit($request));

        return $this->collection(AiConversationResource::collection($items));
    }

    /**
     * Get conversation
     *
     * Returns the conversation with its full transcript under `messages`, oldest first. This is
     * the message-history path: `/sync/pull` carries conversations but deliberately not their
     * messages.
     */
    public function show(Request $request, string $id)
    {
        $userId = $request->user()->id;

        $conversation = $this->conversations->find($userId, $id, null);

        abort_if($conversation === null, 404);

        $conversation->setRelation(
            'messages',
            $this->messages->forConversation($userId, $id, $this->limit($request))
        );

        return $this->success(new AiConversationResource($conversation));
    }

    /**
     * Delete conversation
     *
     * Soft-deletes the conversation and stops it appearing in `/sync/pull` as anything but a
     * tombstone.
     */
    public function destroy(Request $request, string $id)
    {
        $this->conversations->delete($request->user(), $id);

        return $this->success(null, 'Conversation deleted.');
    }
}
