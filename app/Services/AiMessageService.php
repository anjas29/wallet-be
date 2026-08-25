<?php

namespace App\Services;

use App\Models\AiMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AiMessageService
{
    /**
     * Transcript for one conversation, oldest first. Scoped by user_id as well as
     * conversation_id: the denormalised column means no join is needed to prove ownership.
     */
    public function forConversation(string $userId, string $conversationId, ?int $limit = null): Collection
    {
        return AiMessage::query()
            ->where('user_id', $userId)
            ->where('conversation_id', $conversationId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->when($limit !== null, fn ($query) => $query->limit(min($limit, 500)))
            ->get();
    }

    /**
     * The conversation rendered as Gemini `contents`, oldest first — including the turn that was
     * just written, so call this after persisting the user's message.
     *
     * Capped to the most recent $turns messages so a long chat cannot grow the request without
     * bound. Tool call/response parts are not replayed, only the resulting prose.
     *
     * Attached images are re-sent as `inline_data` so follow-up questions about a photo still
     * work, but only for the newest `gemini.max_history_images` of them: every image is re-billed
     * and re-uploaded on every round trip of every turn, so an old receipt would otherwise be
     * paid for indefinitely. Older ones degrade to a note rather than vanishing, which stops the
     * model claiming it never saw an image the user can plainly see in the transcript.
     */
    public function contentsForPrompt(string $userId, string $conversationId, int $turns = 20): array
    {
        $messages = AiMessage::query()
            ->where('user_id', $userId)
            ->where('conversation_id', $conversationId)
            ->whereNull('error')
            // An image-only turn has empty content but is still a real turn.
            ->where(fn ($query) => $query->where('content', '!=', '')->orWhereNotNull('image_path'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($turns)
            ->get();

        $budget = max(0, (int) config('services.gemini.max_history_images', 1));

        // $messages is newest-first here, so take() keeps the most recent images.
        $inlined = $messages
            ->filter(fn (AiMessage $message) => $message->image_path !== null)
            ->take($budget)
            ->pluck('id')
            ->flip();

        $contents = [];

        foreach ($messages->reverse() as $message) {
            $parts = [];

            if ($message->image_path !== null) {
                $data = $inlined->has($message->id) ? $this->inlineData($message) : null;

                $parts[] = $data ?? ['text' => '[An image was attached to this message but is no longer available to you.]'];
            }

            if ($message->content !== '') {
                $parts[] = ['text' => $message->content];
            }

            if ($parts === []) {
                continue;
            }

            $contents[] = [
                'role' => $message->role === 'assistant' ? 'model' : 'user',
                'parts' => $parts,
            ];
        }

        return $contents;
    }

    /**
     * One attachment as a Gemini `inline_data` part, or null if the object has gone from S3 — a
     * missing file must degrade the prompt, never fail the turn.
     */
    private function inlineData(AiMessage $message): ?array
    {
        try {
            $bytes = Storage::disk('s3')->get($message->image_path);
        } catch (Throwable) {
            $bytes = null;
        }

        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        return ['inline_data' => [
            'mime_type' => $message->image_mime ?: 'image/jpeg',
            'data' => base64_encode($bytes),
        ]];
    }
}
