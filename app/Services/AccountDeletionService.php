<?php

namespace App\Services;

use App\Models\AccountDeleteRequest;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AccountDeletionService
{
    /**
     * User-owned tables that carry both `user_id` and `deleted_at`. Soft-deleting these is
     * what "delete all related user data" means here — recoverable, and visible to a syncing
     * client because `updated_at` is bumped alongside (every one of these is delta-synced).
     *
     * Ordered child-first purely for readability; UPDATEs have no FK ordering constraint.
     */
    private const OWNED_SOFT_DELETE_TABLES = [
        'ai_messages',
        'ai_conversations',
        'transactions',
        'transfers',
        'recurring_transactions',
        'budgets',
        'liabilities',
        'accounts',
        'user_categories',
    ];

    /**
     * Record a deletion request for an email address.
     *
     * Deliberately never reports whether the address is registered — the caller shows the
     * same confirmation either way — so an unauthenticated form cannot be used to enumerate
     * accounts. An unmatched address is still stored, with `user_id` null.
     *
     * A pending request for the same address is refreshed rather than duplicated, so someone
     * submitting twice does not produce two rows for the operator to reconcile.
     */
    public function request(string $email, ?string $ip = null, ?string $userAgent = null): AccountDeleteRequest
    {
        $email = mb_strtolower(trim($email));

        // Trashed users are excluded: their data is already deleted, so there is nothing to act on.
        $user = User::where('email', $email)->first();

        return DB::transaction(function () use ($email, $user, $ip, $userAgent): AccountDeleteRequest {
            $pending = AccountDeleteRequest::where('email', $email)
                ->where('status', AccountDeleteRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'user_id' => $user?->id,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ];

            if ($pending) {
                $pending->update($attributes);

                return $pending;
            }

            return AccountDeleteRequest::create([
                'email' => $email,
                'status' => AccountDeleteRequest::STATUS_PENDING,
                ...$attributes,
            ]);
        });
    }

    /**
     * Dismiss a request without touching the account.
     */
    public function ignore(AccountDeleteRequest $request, User $admin): void
    {
        $request->update([
            'status' => AccountDeleteRequest::STATUS_IGNORED,
            'processed_by' => $admin->id,
            'processed_at' => now(),
        ]);
    }

    /**
     * Approve a request: soft-delete the account and everything it owns, then revoke every
     * credential so no live session or token survives the deletion.
     *
     * Returns the number of rows soft-deleted per table, which the panel surfaces as a receipt.
     *
     * @return array<string, int>
     */
    public function approve(AccountDeleteRequest $request, User $admin): array
    {
        $user = null;

        $deleted = DB::transaction(function () use ($request, $admin, &$user): array {
            $deleted = [];

            if ($request->user_id !== null) {
                $user = User::withTrashed()->find($request->user_id);

                if ($user) {
                    $deleted = $this->deleteUserData($user);
                }
            }

            $request->update([
                'status' => AccountDeleteRequest::STATUS_DELETED,
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ]);

            return $deleted;
        });

        // Deliberately outside the transaction above: this is an external Stripe API call,
        // not a DB write, and must not hold the transaction open while it happens. Billing
        // records themselves are left intact (not in OWNED_SOFT_DELETE_TABLES) as an audit
        // trail — only the live subscription is stopped.
        if ($user && $user->subscribed('default')) {
            $user->subscription('default')->cancelNow();
        }

        return $deleted;
    }

    /**
     * Soft-delete a user and all data belonging to them.
     *
     * @return array<string, int> table name => rows soft-deleted
     */
    public function deleteUserData(User $user): array
    {
        $now = Carbon::now();
        $counts = [];

        foreach (self::OWNED_SOFT_DELETE_TABLES as $table) {
            $counts[$table] = $this->softDelete(
                DB::table($table)->where('user_id', $user->id),
                $now,
            );
        }

        // These two tables have no `user_id` of their own (see their migrations) — reach them
        // through the parent the user does own, including parents trashed by the loop above.
        $counts['liability_payments'] = $this->softDelete(
            DB::table('liability_payments')->whereIn(
                'liability_id',
                DB::table('liabilities')->where('user_id', $user->id)->select('id'),
            ),
            $now,
        );

        $counts['transaction_attachments'] = $this->softDelete(
            DB::table('transaction_attachments')->whereIn(
                'transaction_id',
                DB::table('transactions')->where('user_id', $user->id)->select('id'),
            ),
            $now,
        );

        // Credentials and device state: hard-deleted, because a revoked credential must not be
        // recoverable and none of these tables has a `deleted_at` to soft-delete into anyway.
        $counts['refresh_tokens'] = DB::table('refresh_tokens')->where('user_id', $user->id)->delete();
        $counts['device_syncs'] = DB::table('device_syncs')->where('user_id', $user->id)->delete();
        $counts['sessions'] = DB::table('sessions')->where('user_id', $user->id)->delete();
        $counts['password_reset_tokens'] = DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        $counts['personal_access_tokens'] = DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->delete();

        // Left intact on purpose: `user_currencies` has no `deleted_at`, and accounts and
        // liabilities reference it with RESTRICT — keeping it is what makes this reversible.

        if (! $user->trashed()) {
            $user->delete();
            $counts['users'] = 1;
        } else {
            $counts['users'] = 0;
        }

        return $counts;
    }

    /**
     * Stamp `deleted_at` on rows that are not already trashed, preserving the original
     * timestamp on anything the user had deleted themselves.
     */
    private function softDelete(Builder $query, Carbon $now): int
    {
        return $query->whereNull('deleted_at')->update([
            'deleted_at' => $now,
            'updated_at' => $now, // delta-sync: clients poll on updated_at
        ]);
    }
}
