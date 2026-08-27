<?php

namespace App\Services;

use App\Exceptions\ReceiptScanException;
use App\Models\Currency;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Extracts structured line items from a receipt photo via the Gemini Developer
 * API (generateContent), constrained to the authenticated user's categories.
 * Mirrors the experiment in plan/receipt-scanner.md — no SDK, just the API.
 */
class ReceiptScanService
{
    private const PROMPT = 'Extract every line item from this receipt photo. For each item give a '
        .'short description, its price, and pick the closest category_id from the '
        .'provided list below (match by the category name, not the id string itself). '
        .'Also return the merchant name, purchase date (yyyy-MM-dd), the printed total, '
        .'and the ISO 4217 currency_code (e.g. USD, IDR, EUR) implied by the receipt '
        .'(currency symbol, language, or store location) — choose only from the currency '
        .'codes listed below, or an empty string if you are not confident. '
        .'If the image is not a readable receipt, set '
        .'is_valid to false and leave other fields empty.'
        ."\n\nAvailable categories (id: name):\n";

    public function scan(User $user, UploadedFile $file): array
    {
        $categories = UserCategory::where('user_id', $user->id)->get(['id', 'name']);

        if ($categories->isEmpty()) {
            throw new ReceiptScanException('You have no categories yet. Create a category before scanning receipts.', 422);
        }

        $apiKey = config('services.gemini.key');

        if (! $apiKey) {
            throw new ReceiptScanException('Receipt scanning is not configured.', 500);
        }

        $currencyCodes = Currency::pluck('code');

        $extracted = $this->callGemini($apiKey, $file, $categories, $currencyCodes);

        $categoryNames = $categories->pluck('name', 'id');

        $items = collect($extracted['items'] ?? [])
            ->map(function (array $item) use ($categoryNames) {
                $item['category_name'] = $categoryNames->get($item['category_id'] ?? null);

                return $item;
            })
            ->all();

        $currencyCode = strtoupper(trim((string) ($extracted['currency_code'] ?? '')));

        if ($currencyCode === '' || ! $currencyCodes->contains($currencyCode)) {
            $currencyCode = null;
        }

        return [
            'is_valid' => $extracted['is_valid'] ?? false,
            'merchant_name' => $extracted['merchant_name'] ?? null,
            'transaction_date' => $extracted['transaction_date'] ?? null,
            'total_amount' => $extracted['total_amount'] ?? null,
            'currency_code' => $currencyCode,
            'items' => $items,
        ];
    }

    private function callGemini(string $apiKey, UploadedFile $file, Collection $categories, Collection $currencyCodes): array
    {
        $model = config('services.gemini.model', 'gemini-3.1-flash-lite');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $this->buildPrompt($categories, $currencyCodes)],
                        [
                            'inline_data' => [
                                'mime_type' => $file->getMimeType() ?: 'image/jpeg',
                                'data' => base64_encode(file_get_contents($file->getRealPath())),
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->buildSchema($categories, $currencyCodes),
            ],
        ];

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout(60)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Gemini receipt scan connection failed', ['error' => $e->getMessage()]);

            throw new ReceiptScanException('Could not reach the receipt scanning service.', 502);
        }

        if ($response->failed()) {
            Log::warning('Gemini receipt scan returned an error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new ReceiptScanException('Receipt scanning service returned an error.', 502);
        }

        // responseMimeType=application/json still nests the structured JSON as a
        // STRING inside candidates[0].content.parts[0].text — needs a second decode.
        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (! is_string($text) || $text === '') {
            throw new ReceiptScanException('Receipt scanning service returned an unexpected response.', 502);
        }

        $extracted = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($extracted)) {
            throw new ReceiptScanException('Could not parse the receipt scanning result.', 502);
        }

        return $extracted;
    }

    private function buildPrompt(Collection $categories, Collection $currencyCodes): string
    {
        $categoryList = $categories->map(fn ($c) => "{$c->id}: {$c->name}")->implode("\n");
        $currencyList = $currencyCodes->implode(', ');

        return self::PROMPT.$categoryList."\n\nAvailable currency codes:\n".$currencyList;
    }

    private function buildSchema(Collection $categories, Collection $currencyCodes): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'is_valid' => ['type' => 'BOOLEAN'],
                'merchant_name' => ['type' => 'STRING'],
                'transaction_date' => ['type' => 'STRING'],
                'total_amount' => ['type' => 'NUMBER'],
                // No enum here — Gemini rejects an empty string as an enum value, and an
                // empty result is exactly how "not confident" is expressed. Anything it
                // returns is re-validated against $currencyCodes in scan() regardless.
                'currency_code' => ['type' => 'STRING'],
                'items' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'description' => ['type' => 'STRING'],
                            'amount' => ['type' => 'NUMBER'],
                            'category_id' => [
                                'type' => 'STRING',
                                'enum' => $categories->pluck('id')->all(),
                            ],
                        ],
                        'required' => ['description', 'amount', 'category_id'],
                    ],
                ],
            ],
            'required' => ['is_valid', 'merchant_name', 'transaction_date', 'total_amount', 'currency_code', 'items'],
        ];
    }
}
