<?php

namespace App\Services;

use App\Models\AiConversation;
use App\Models\User;
use App\Services\Concerns\DeltaSyncQuery;
use App\Services\Concerns\PersistsEntities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiConversationService
{
    use DeltaSyncQuery;
    use PersistsEntities;

    /**
     * Delta-sync read (used by /sync/pull). Transcripts are deliberately excluded — see
     * AiMessageService::forConversation() for those.
     */
    public function list(string $userId, ?string $since, ?int $limit): Collection
    {
        return $this->listDelta(AiConversation::where('user_id', $userId), $since, $limit);
    }

    public function find(string $userId, string $id, ?string $since): ?AiConversation
    {
        return $this->findDelta(AiConversation::where('user_id', $userId), $id, $since);
    }

    /**
     * Chat-list read: newest conversation first, which is what the UI wants. Distinct from
     * list(), whose (updated_at, id) ordering is the sync cursor contract.
     */
    public function listRecent(string $userId, ?int $limit): Collection
    {
        return AiConversation::where('user_id', $userId)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(min($limit ?? 50, 500))
            ->get();
    }

    /**
     * Resolve the conversation a chat turn belongs to, creating it when the client supplied no
     * id (or supplied one we have not seen yet — clients own ULID generation here, matching
     * PersistsEntities).
     */
    public function resolveForTurn(User $user, ?string $conversationId, string $firstMessage): AiConversation
    {
        if ($conversationId !== null) {
            $existing = AiConversation::where('user_id', $user->id)->find($conversationId);

            if ($existing !== null) {
                return $existing;
            }
        }

        $conversation = new AiConversation;
        $conversation->id = $conversationId ?? (string) Str::ulid();
        $conversation->fill([
            'user_id' => $user->id,
            'title' => $this->titleFrom($firstMessage),
        ]);
        $conversation->save();

        return $conversation;
    }

    /**
     * Sync push. `create` is refused: a conversation only means anything once it holds a
     * generated turn, so it must originate from POST /ai/chat.
     */
    public function createOrUpdate(User $user, string $id, string $op, array $data): AiConversation
    {
        if ($op === 'create') {
            throw ValidationException::withMessages([
                'changes' => ['Conversations are created by POST /ai/chat, not by sync.'],
            ]);
        }

        $payload = [];

        // Title is the only client-writable field; everything else is server-owned.
        if (array_key_exists('title', $data)) {
            $payload['title'] = $data['title'];
        }

        return $this->upsertEntity(AiConversation::class, $id, $op, $payload, $user->id);
    }

    public function delete(User $user, string $id): void
    {
        $this->softDeleteEntity(AiConversation::class, $id, $user->id);
    }

    /**
     * Cheap derived title so the chat list is readable without a second model call.
     */
    private function titleFrom(string $message): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', $message)), 60);
    }
}
