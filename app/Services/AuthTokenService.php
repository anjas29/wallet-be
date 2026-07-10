<?php

namespace App\Services;

use App\Exceptions\RefreshTokenException;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AuthTokenService
{
    /** Access token lifetime. Will be shortened in a later phase. */
    public const ACCESS_TTL_DAYS = 30;

    public const REFRESH_TTL_DAYS = 30;

    /**
     * Issue a fresh access token plus a brand-new refresh-token family (login/register).
     *
     * @return array{token: string, refresh_token: string, refresh_expires_at: string}
     */
    public function issue(User $user, string $deviceId, ?string $deviceName, ?string $ip): array
    {
        $accessToken = $user->createToken($deviceId, ['*'], now()->addDays(self::ACCESS_TTL_DAYS))->plainTextToken;

        $refresh = $this->mintRefreshToken($user, (string) Str::ulid(), $deviceId, $deviceName, $ip);

        return [
            'token' => $accessToken,
            'refresh_token' => $refresh['plain'],
            'refresh_expires_at' => $refresh['expires_at']->toIso8601String(),
        ];
    }

    /**
     * Exchange a refresh token for a new access token, rotating the refresh token within its
     * family. Replaying an already-rotated (revoked) token revokes the whole family.
     *
     * @return array{user: User, token: string, refresh_token: string, refresh_expires_at: string}
     */
    public function refresh(string $plainRefreshToken, string $deviceId, ?string $deviceName, ?string $ip): array
    {
        $record = RefreshToken::where('token_hash', $this->hash($plainRefreshToken))->first();

        if ($record === null) {
            throw new RefreshTokenException('Invalid refresh token.');
        }

        // Reuse detection: a revoked token being replayed means the lineage may be compromised.
        if ($record->revoked_at !== null) {
            RefreshToken::where('family_id', $record->family_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            throw new RefreshTokenException('Refresh token has been revoked.');
        }

        if ($record->expires_at->isPast()) {
            throw new RefreshTokenException('Refresh token has expired.');
        }

        $user = $record->user;

        // Rotate: revoke the presented token and mint a successor in the same family.
        $record->update(['revoked_at' => now()]);

        $refresh = $this->mintRefreshToken($user, $record->family_id, $deviceId ?: $record->device_id, $deviceName, $ip);
        $accessToken = $user->createToken($deviceId ?: $record->device_id, ['*'], now()->addDays(self::ACCESS_TTL_DAYS))->plainTextToken;

        return [
            'user' => $user,
            'token' => $accessToken,
            'refresh_token' => $refresh['plain'],
            'refresh_expires_at' => $refresh['expires_at']->toIso8601String(),
        ];
    }

    /**
     * @return array{plain: string, expires_at: Carbon}
     */
    private function mintRefreshToken(User $user, string $familyId, string $deviceId, ?string $deviceName, ?string $ip): array
    {
        $plain = Str::random(64);
        $expiresAt = now()->addDays(self::REFRESH_TTL_DAYS);

        RefreshToken::create([
            'user_id' => $user->id,
            'family_id' => $familyId,
            'token_hash' => $this->hash($plain),
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'ip_address' => $ip,
            'expires_at' => $expiresAt,
        ]);

        return ['plain' => $plain, 'expires_at' => $expiresAt];
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
