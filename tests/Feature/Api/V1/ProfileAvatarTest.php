<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        return [$user, $token];
    }

    public function test_user_can_upload_avatar_to_s3(): void
    {
        Storage::fake('s3');
        [$user, $token] = $this->authUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/profile/avatar', [
                'avatar' => UploadedFile::fake()->image('me.jpg', 256, 256),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.avatar_path', fn ($v) => is_string($v) && str_starts_with($v, "avatars/{$user->id}/"))
            ->assertJsonPath('data.user.avatar_url', fn ($v) => is_string($v) && $v !== '');

        $path = $response->json('data.user.avatar_path');
        Storage::disk('s3')->assertExists($path);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar_path' => $path]);
    }

    public function test_uploading_a_new_avatar_replaces_and_deletes_the_previous_one(): void
    {
        Storage::fake('s3');
        [$user, $token] = $this->authUser();

        $first = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.png', 128, 128)])
            ->json('data.user.avatar_path');

        $second = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/profile/avatar', ['avatar' => UploadedFile::fake()->image('b.png', 128, 128)])
            ->assertStatus(200)
            ->json('data.user.avatar_path');

        $this->assertNotSame($first, $second);
        Storage::disk('s3')->assertMissing($first);
        Storage::disk('s3')->assertExists($second);
    }

    public function test_user_can_delete_avatar(): void
    {
        Storage::fake('s3');
        [$user, $token] = $this->authUser();

        $path = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 128, 128)])
            ->json('data.user.avatar_path');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/auth/profile/avatar')
            ->assertStatus(200)
            ->assertJsonPath('data.user.avatar_path', null)
            ->assertJsonPath('data.user.avatar_url', null);

        Storage::disk('s3')->assertMissing($path);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar_path' => null]);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('s3');
        [, $token] = $this->authUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/profile/avatar', [
                'avatar' => UploadedFile::fake()->create('malware.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_avatar_upload_requires_authentication(): void
    {
        Storage::fake('s3');

        $this->postJson('/api/v1/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('a.jpg', 64, 64),
        ])->assertStatus(401);
    }
}
