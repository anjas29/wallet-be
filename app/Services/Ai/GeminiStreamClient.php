<?php

namespace App\Services\Ai;

use App\Exceptions\AiChatException;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin streaming bridge to the Gemini Developer API's :streamGenerateContent?alt=sse endpoint.
 *
 * Deliberately separate from ReceiptScanService, which stays on the blocking generateContent
 * call. One important difference from that service: `alt=sse` frames are ordinary JSON, NOT the
 * double-encoded string that responseMimeType=application/json produces. There is no second
 * json_decode here.
 */
class GeminiStreamClient
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * Yields normalised parts as they arrive:
     *   ['type' => 'text',         'text' => string]
     *   ['type' => 'functionCall', 'call' => ['name' => string, 'args' => array], 'thoughtSignature' => ?string]
     *   ['type' => 'finish',       'reason' => string]
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(array $payload): Generator
    {
        $apiKey = config('services.gemini.key');

        if (! $apiKey) {
            throw new AiChatException('AI chat is not configured.', 500);
        }

        $model = config('services.gemini.model', 'gemini-3.1-flash-lite');
        $url = self::BASE."/{$model}:streamGenerateContent?alt=sse";

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->withOptions(['stream' => true])
                ->timeout(120)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Gemini chat connection failed', ['error' => $e->getMessage()]);

            throw new AiChatException('Could not reach the AI service.', 502);
        }

        if ($response->failed()) {
            Log::warning('Gemini chat returned an error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->status() === 429) {
                throw new AiChatException('The AI assistant is busy right now. Please try again in a few minutes.', 429);
            }

            throw new AiChatException('The AI service returned an error.', 502);
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);

            // A non-eof stream that yields nothing would otherwise spin forever.
            if ($chunk === '') {
                break;
            }

            $buffer .= $chunk;

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $newline), "\r");
                $buffer = substr($buffer, $newline + 1);

                yield from $this->parseLine($line);
            }
        }

        if (trim($buffer) !== '') {
            yield from $this->parseLine(rtrim($buffer, "\r"));
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function parseLine(string $line): Generator
    {
        if (! str_starts_with($line, 'data:')) {
            return;
        }

        $json = trim(substr($line, 5));

        if ($json === '' || $json === '[DONE]') {
            return;
        }

        $frame = json_decode($json, true);

        if (! is_array($frame)) {
            Log::warning('Gemini chat sent an unparseable SSE frame', ['frame' => $json]);

            return;
        }

        foreach (data_get($frame, 'candidates.0.content.parts', []) as $part) {
            if (isset($part['text']) && $part['text'] !== '') {
                yield ['type' => 'text', 'text' => $part['text']];
            }

            if (isset($part['functionCall'])) {
                yield ['type' => 'functionCall', 'call' => $part['functionCall'], 'thoughtSignature' => $part['thoughtSignature'] ?? null];
            }
        }

        $reason = data_get($frame, 'candidates.0.finishReason');

        if ($reason !== null) {
            yield ['type' => 'finish', 'reason' => $reason];
        }
    }
}
