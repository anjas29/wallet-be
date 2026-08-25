<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AiChatException;
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
     * Accepts JSON, or `multipart/form-data` when attaching an `image` (jpg/png/webp) — a
     * receipt, a statement, a screenshot. With an image, `message` may be omitted. The analyst
     * reads images but never generates them; every answer is text.
     *
     * Attachments are capped at `GEMINI_IMAGE_MAX_UPLOAD_KB` (default 1536 KB) and are
     * **downscaled server-side** to `GEMINI_IMAGE_MAX_EDGE` (default 1024 px on the longest
     * edge) and re-encoded as JPEG before being stored or sent upstream, so there is no benefit
     * to uploading a full-resolution photo — resize on the device and the request is faster for
     * the same result.
     *
     * Two quotas apply per user, per calendar day, and both are refused **before** the stream
     * opens with a normal `429` JSON envelope: `GEMINI_DAILY_TURN_LIMIT` messages (default 20)
     * and `GEMINI_DAILY_IMAGE_LIMIT` attachments (default 10). Hitting the image limit does not
     * block text-only questions.
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
     *
     * ### Record reference tags
     *
     * Answer text may contain inline markers naming a record the analyst is talking about:
     *
     * ```
     * Your biggest expense was the 84.20 supermarket run on 12 Aug [[transaction:01JB…]].
     * ```
     *
     * Types are `transaction`, `transfer` and `liability`, and the id is always a record you own
     * that exists right now — the server verifies each one and deletes any it cannot resolve
     * before the text is streamed. Match them with
     * `\[\[(transaction|transfer|liability):([0-9A-Za-z]{26})\]\]`, replace each with a chip
     * that opens the record, and resolve the label from your **local** copy of the record: these
     * entities all arrive through `/sync/pull`, so no extra request is needed.
     *
     * Parse against the accumulated answer, not a single `delta` — a tag routinely straddles two
     * or three of them. Text without a tag always reads correctly on its own, so a client that
     * ignores this can simply strip the markers.
     */
    public function chat(Request $request)
    {
        $data = $request->validate([
            // Optional only when an image carries the question ("what do you make of this?").
            'message' => ['required_without:image', 'nullable', 'string', 'max:2000'],
            'image' => [
                'nullable', 'image', 'mimes:jpg,jpeg,png,webp',
                'max:'.max(1, (int) config('services.gemini.image.max_upload_kb', 1536)),
            ],
            'conversation_id' => ['nullable', 'string', 'ulid'],
            'message_id' => ['nullable', 'string', 'ulid'],
        ]);

        try {
            $turn = $this->chat->beginTurn(
                $request->user(),
                $data['message'] ?? '',
                $data['conversation_id'] ?? null,
                $data['message_id'] ?? null,
                $request->file('image'),
            );
        } catch (AiChatException $e) {
            // Still the standard envelope: nothing has been streamed yet. Past this point a
            // failure can only be an `error` event.
            return $this->error($e->getMessage(), $e->status);
        }

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
     *
     * A message with an attachment carries `image_url`, a signed URL valid for **60 minutes** —
     * re-read the conversation to refresh it rather than caching the URL itself.
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
