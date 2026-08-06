<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): array
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);
        $token = $user->createToken('test')->plainTextToken;

        return [$user, $token];
    }

    public function test_user_can_update_their_name(): void
    {
        [$user, $token] = $this->authUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/auth/profile', ['name' => 'Grace Hopper']);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.name', 'Grace Hopper');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Grace Hopper']);
    }

    public function test_name_is_required_to_update_profile(): void
    {
        [, $token] = $this->authUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/auth/profile', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_unauthenticated_request_cannot_update_profile(): void
    {
        $this->putJson('/api/v1/auth/profile', ['name' => 'Grace Hopper'])
            ->assertStatus(401);
    }
}
