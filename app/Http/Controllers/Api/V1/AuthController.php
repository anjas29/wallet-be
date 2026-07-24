<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthTokenService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

#[Group('Authentication', weight: 1)]
class AuthController extends Controller
{
    public function __construct(private AuthTokenService $tokens) {}

    /**
     * Register
     *
     * Create a new user account and issue access + refresh tokens.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique(User::class)],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // Reload so database-assigned defaults (e.g. role) are reflected in the response.
        $user->refresh();

        $tokens = $this->tokens->issue($user, ...$this->deviceContext($request));

        return $this->success([
            'user' => new UserResource($user),
            ...$tokens,
        ], 'Registration successful.', 201);
    }

    /**
     * Log in
     *
     * Authenticate with email and password and issue access + refresh tokens.
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->error('Invalid credentials.', 401);
        }

        $tokens = $this->tokens->issue($user, ...$this->deviceContext($request));

        return $this->success([
            'user' => new UserResource($user),
            ...$tokens,
        ], 'Login successful.');
    }

    /**
     * Refresh
     *
     * Exchange a valid refresh token for a new access token. The refresh token is rotated:
     * the old one is revoked and a new one returned.
     */
    public function refresh(Request $request)
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $result = $this->tokens->refresh($data['refresh_token'], ...$this->deviceContext($request));

        $user = $result['user'];
        unset($result['user']);

        return $this->success([
            'user' => new UserResource($user),
            ...$result,
        ], 'Token refreshed.');
    }

    /**
     * Log out
     *
     * Revoke the access token for the current device.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->success(null, 'Logged out.');
    }

    /**
     * Log out everywhere
     *
     * Revoke all of the user's access tokens.
     */
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return $this->success(null, 'Logged out from all devices.');
    }

    /**
     * Profile
     *
     * Get the authenticated user.
     */
    public function profile(Request $request)
    {
        return $this->success([
            'user' => new UserResource($request->user()),
        ]);
    }

    /**
     * Upload profile picture
     *
     * Store or replace the authenticated user's avatar on the S3 disk and return the updated user.
     * Send as `multipart/form-data` with an `avatar` file field.
     */
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();
        $previous = $user->avatar_path;

        // Store under a per-user prefix; storage generates a random name. Public read is granted
        // by the bucket policy on `avatars/*` (no per-object ACL, so this works with ACLs disabled).
        $path = $request->file('avatar')->store("avatars/{$user->id}", 's3');

        $user->update(['avatar_path' => $path]);

        // Remove the old object only after the new one is committed.
        if ($previous && $previous !== $path) {
            Storage::disk('s3')->delete($previous);
        }

        return $this->success([
            'user' => new UserResource($user),
        ], 'Avatar updated.');
    }

    /**
     * Delete profile picture
     *
     * Remove the authenticated user's avatar from S3 and clear `avatar_path`.
     */
    public function deleteAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('s3')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return $this->success([
            'user' => new UserResource($user),
        ], 'Avatar removed.');
    }

    /**
     * Device context for token issuance: [deviceId, deviceName, ip].
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function deviceContext(Request $request): array
    {
        return [
            $request->header('X-Device-Id', 'default'),
            $request->header('X-Device-Name'),
            $request->ip(),
        ];
    }
}
