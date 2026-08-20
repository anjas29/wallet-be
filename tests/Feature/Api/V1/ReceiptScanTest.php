<?php

namespace Tests\Feature\Api\V1;

use App\Models\Currency;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReceiptScanTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        return [$user, $token];
    }

    private function geminiPayload(string $categoryId, string $currencyCode = ''): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'is_valid' => true,
                                    'merchant_name' => 'Corner Store',
                                    'transaction_date' => '2026-08-19',
                                    'total_amount' => 12.5,
                                    'currency_code' => $currencyCode,
                                    'items' => [
                                        [
                                            'description' => 'Milk',
                                            'amount' => 12.5,
                                            'category_id' => $categoryId,
                                        ],
                                    ],
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_user_can_scan_a_receipt_and_items_are_mapped_to_their_category_name(): void
    {
        config(['services.gemini.key' => 'test-key']);
        [$user, $token] = $this->authUser();
        $category = UserCategory::create(['user_id' => $user->id, 'name' => 'Groceries', 'type' => 'expense', 'icon' => 'cart']);
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiPayload($category->id, 'USD'), 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/receipts/scan', [
                'receipt' => UploadedFile::fake()->image('receipt.jpg', 800, 600),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_valid', true)
            ->assertJsonPath('data.merchant_name', 'Corner Store')
            ->assertJsonPath('data.currency_code', 'USD')
            ->assertJsonPath('data.items.0.category_id', $category->id)
            ->assertJsonPath('data.items.0.category_name', 'Groceries');

        Http::assertSent(function ($request) use ($category) {
            return $request->hasHeader('x-goog-api-key', 'test-key')
                && $request['generationConfig']['responseSchema']['properties']['items']['items']['properties']['category_id']['enum'] === [$category->id];
        });
    }

    public function test_unrecognized_currency_code_is_left_empty(): void
    {
        config(['services.gemini.key' => 'test-key']);
        [$user, $token] = $this->authUser();
        $category = UserCategory::create(['user_id' => $user->id, 'name' => 'Groceries', 'type' => 'expense', 'icon' => 'cart']);
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2]);

        Http::fake([
            // Gemini returned something outside the enum it was given — defense in depth.
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiPayload($category->id, 'XXX'), 200),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/receipts/scan', [
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.currency_code', null);
    }

    public function test_scan_requires_an_uploaded_image(): void
    {
        [, $token] = $this->authUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/receipts/scan', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.receipt.0', 'The receipt field is required.');
    }

    public function test_scan_rejects_images_larger_than_5mb(): void
    {
        [, $token] = $this->authUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/receipts/scan', [
                'receipt' => UploadedFile::fake()->create('receipt.jpg', 5121, 'image/jpeg'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_scan_rejects_non_image_uploads(): void
    {
        [, $token] = $this->authUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/receipts/scan', [
                'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_scan_rejects_file_types_outside_jpg_png_webp(): void
    {
        [, $token] = $this->authUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/receipts/scan', [
                'receipt' => UploadedFile::fake()->image('receipt.gif'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.receipt.0', 'The receipt field must be a file of type: jpg, png, webp.');
    }

    public function test_scan_requires_the_user_to_have_at_least_one_category(): void
    {
        [, $token] = $this->authUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/receipts/scan', [
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You have no categories yet. Create a category before scanning receipts.');
    }

    public function test_scan_requires_authentication(): void
    {
        $this->postJson('/api/v1/receipts/scan', [
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertStatus(401);
    }

    public function test_upstream_gemini_failure_is_reported_as_an_error(): void
    {
        config(['services.gemini.key' => 'test-key']);
        [$user, $token] = $this->authUser();
        UserCategory::create(['user_id' => $user->id, 'name' => 'Groceries', 'type' => 'expense', 'icon' => 'cart']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/receipts/scan', [
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertStatus(502)
            ->assertJsonPath('success', false);
    }
}
