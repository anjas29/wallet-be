<?php

namespace App\Services\Ai;

/**
 * Streaming filter that strips Markdown formatting from the analyst's prose, so the client only
 * ever receives plain text plus the `[[type:id]]` reference tags AnswerTagFilter resolves — this
 * class never touches `[` or `]`, so the two compose in either order.
 *
 * Same problem as AnswerTagFilter, same shape of fix: deltas arrive mid-token, so a `**bold**`
 * pair or a `# heading` marker routinely straddles two or three chunks. Text after an opening
 * delimiter is held back until its closer arrives — or the run is abandoned as ordinary prose
 * after MAX_HOLD characters — so a stray `*` is never shipped.
 *
 * State is per turn: construct one, push() every text chunk, flush() once at the end.
 */
class MarkdownStripFilter
{
    /**
     * How long to hold an unclosed delimiter before giving up and releasing it as plain text —
     * long enough for a bolded sentence, short enough not to stall the stream.
     */
    private const MAX_HOLD = 240;

    private string $held = '';

    private bool $lineStart = true;

    public function push(string $chunk): string
    {
        $this->held .= $chunk;
        $out = '';

        while ($this->held !== '') {
            if ($this->lineStart) {
                $resolved = $this->consumeLineMarker();

                if ($resolved === null) {
                    break; // Not enough of the line yet to tell if it opens with a marker.
                }

                $this->lineStart = false;

                continue;
            }

            $span = strcspn($this->held, "\n*_`");

            if ($span > 0) {
                $out .= substr($this->held, 0, $span);
                $this->held = substr($this->held, $span);
            }

            if ($this->held === '') {
                break;
            }

            if ($this->held[0] === "\n") {
                $out .= "\n";
                $this->held = substr($this->held, 1);
                $this->lineStart = true;

                continue;
            }

            $emphasis = $this->consumeEmphasis();

            if ($emphasis === null) {
                break; // Delimiter run not resolved yet — wait for more of the stream.
            }

            $out .= $emphasis;
        }

        return $out;
    }

    /**
     * Whatever the filter is still holding once the turn ends. Unlike a reference tag, an
     * unresolved delimiter is still meaningful prose that never found its pair, so it is released
     * verbatim rather than dropped.
     */
    public function flush(): string
    {
        $held = $this->held;
        $this->held = '';

        return $held;
    }

    /**
     * At the start of a line, `#`, `-`/`*`/`+` and `>` may introduce a heading, bullet or
     * blockquote marker. Returns null when there is not yet enough buffered to decide; otherwise
     * a real marker is stripped (consuming it from $held), and anything else is left in place for
     * the normal scan below to emit.
     */
    private function consumeLineMarker(): ?string
    {
        $ch = $this->held[0];

        if ($ch === '#') {
            $run = strspn($this->held, '#');

            if ($run === strlen($this->held) && $run < 7 && strlen($this->held) < self::MAX_HOLD) {
                return null;
            }

            if ($run <= 6 && ($this->held[$run] ?? '') === ' ') {
                $this->held = substr($this->held, $run + 1);
            }

            return '';
        }

        if ($ch === '-' || $ch === '*' || $ch === '+') {
            if (strlen($this->held) < 2 && strlen($this->held) < self::MAX_HOLD) {
                return null; // Need the next character to know if this is "X ".
            }

            if (($this->held[1] ?? '') === ' ') {
                $this->held = substr($this->held, 2);
            }

            return '';
        }

        if ($ch === '>') {
            $run = strspn($this->held, '>');

            if ($run === strlen($this->held) && strlen($this->held) < self::MAX_HOLD) {
                return null;
            }

            if (($this->held[$run] ?? '') === ' ') {
                $this->held = substr($this->held, $run + 1);
            }

            return '';
        }

        return '';
    }

    /**
     * Resolve a `*`, `_` or `` ` `` run starting at $held[0]: find the matching run of the same
     * character and length, strip both and keep the text between them, or give up after
     * MAX_HOLD characters and release the run as plain text.
     */
    private function consumeEmphasis(): ?string
    {
        $ch = $this->held[0];
        $run = strspn($this->held, $ch);

        if ($run === strlen($this->held)) {
            if (strlen($this->held) < self::MAX_HOLD) {
                return null; // The run might still be growing.
            }

            $out = $this->held;
            $this->held = '';

            return $out;
        }

        if ($run > 3) {
            // Not valid emphasis syntax (e.g. a literal "----"); release as-is.
            $out = substr($this->held, 0, $run);
            $this->held = substr($this->held, $run);

            return $out;
        }

        $marker = substr($this->held, 0, $run);
        $rest = substr($this->held, $run);
        $close = strpos($rest, $marker);

        if ($close === false) {
            if (strlen($this->held) > self::MAX_HOLD) {
                $this->held = substr($this->held, $run);

                return $marker;
            }

            return null; // Wait for the rest of the stream.
        }

        $inner = substr($rest, 0, $close);
        $this->held = substr($rest, $close + $run);

        return $inner;
    }
}
