<?php

namespace App\Services\Ai;

use App\Models\Liability;
use App\Models\Transaction;
use App\Models\Transfer;
use Illuminate\Database\Eloquent\Model;

/**
 * Streaming filter for the record-reference tags the analyst embeds in its prose.
 *
 * The model is told to mark records it names with `[[transaction:<ulid>]]` (or `transfer` /
 * `liability`), so the app can render a tappable chip that opens the record. The client already
 * holds every transaction, transfer and liability locally via /sync/pull, so a bare id is enough
 * — it resolves the label itself and no extra round trip is needed.
 *
 * Two problems this class exists to solve:
 *
 * 1. **Hallucinated ids.** A tag pointing at a record that does not exist (or belongs to someone
 *    else) sends the app to a dead screen. Every id is checked against the database, scoped to
 *    the asking user, before it is allowed through. Failures are dropped silently — the prompt
 *    requires the sentence to read correctly without its tags.
 * 2. **Chunk boundaries.** Deltas arrive mid-token, so a tag routinely straddles two or three of
 *    them. Emitting eagerly would ship half a tag and there would be no way to retract it, so
 *    text from an opening `[[` is held back until the tag closes and is judged.
 *
 * State is per turn: construct one, push() every text chunk, flush() once at the end.
 */
class AnswerTagFilter
{
    /**
     * Tag type => the model whose ownership is checked. Adding `account` or `budget` here is all
     * it takes to make those linkable too — the prompt lists the same set.
     *
     * @var array<string, class-string<Model>>
     */
    private const TYPES = [
        'transaction' => Transaction::class,
        'transfer' => Transfer::class,
        'liability' => Liability::class,
    ];

    /**
     * A real tag is `[[liability:` + 26 + `]]` = 41 characters. Past this, the `[[` was ordinary
     * prose and holding text back would stall the stream, so we give up and release it.
     */
    private const MAX_TAG_LENGTH = 64;

    private string $held = '';

    /** @var array<string, bool> */
    private array $seen = [];

    public function __construct(private string $userId) {}

    /**
     * Feed one delta; returns the text that is safe to emit now (possibly empty).
     */
    public function push(string $chunk): string
    {
        $this->held .= $chunk;
        $out = '';

        while ($this->held !== '') {
            $open = strpos($this->held, '[[');

            if ($open === false) {
                // A trailing single '[' may be the first half of an opener split across chunks,
                // so it stays held until the next one arrives.
                $keep = str_ends_with($this->held, '[') ? 1 : 0;
                $out .= substr($this->held, 0, strlen($this->held) - $keep);
                $this->held = $keep === 1 ? '[' : '';

                break;
            }

            $out .= substr($this->held, 0, $open);
            $this->held = substr($this->held, $open);

            $close = strpos($this->held, ']]');

            if ($close === false) {
                if (strlen($this->held) > self::MAX_TAG_LENGTH) {
                    $out .= substr($this->held, 0, 2);
                    $this->held = substr($this->held, 2);

                    continue;
                }

                break; // Incomplete tag — wait for the rest.
            }

            $tag = substr($this->held, 0, $close + 2);
            $this->held = substr($this->held, $close + 2);

            $out .= $this->resolve($tag);
        }

        return $out;
    }

    /**
     * Release whatever is still held once the turn is over. Anything from a `[[` onwards was a
     * tag the model never closed, so it is discarded rather than leaked as raw markup.
     */
    public function flush(): string
    {
        $held = $this->held;
        $this->held = '';

        $open = strpos($held, '[[');

        return $open === false ? $held : substr($held, 0, $open);
    }

    /**
     * A tag survives only if it is well-formed, of a known type, and names a live record owned
     * by this user. Soft-deleted rows fail the check by default scope, which is what we want:
     * the client has no screen for a deleted transaction.
     */
    private function resolve(string $tag): string
    {
        if (! preg_match('/^\[\[([a-z_]+):([0-9A-Za-z]{26})\]\]$/', $tag, $matches)) {
            return '';
        }

        [, $type, $id] = $matches;

        $model = self::TYPES[$type] ?? null;

        if ($model === null) {
            return '';
        }

        // Memoised: the model often cites the same record two or three times in one answer.
        $key = $type.':'.$id;

        $this->seen[$key] ??= $model::query()
            ->where('user_id', $this->userId)
            ->whereKey($id)
            ->exists();

        return $this->seen[$key] ? "[[{$key}]]" : '';
    }
}
