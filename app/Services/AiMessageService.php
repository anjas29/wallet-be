<?php

namespace App\Services;

use App\Models\AiMessage;
use Illuminate\Database\Eloquent\Collection;

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
     * Prior turns rendered as Gemini `contents`. Capped to the most recent $turns messages so a
     * long conversation cannot grow the request without bound; tool call/response parts are not
     * replayed, only the resulting prose.
     */
    public function historyForPrompt(string $userId, string $conversationId, int $turns = 20): array
    {
        $messages = AiMessage::query()
            ->where('user_id', $userId)
            ->where('conversation_id', $conversationId)
            ->whereNull('error')
            ->where('content', '!=', '')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($turns)
            ->get()
            ->reverse();

        return $messages->map(fn (AiMessage $message) => [
            'role' => $message->role === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $message->content]],
        ])->values()->all();
    }
}
