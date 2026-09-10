<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'avatar_path'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasApiTokens, HasFactory, HasUlids, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function deleteRequests(): HasMany
    {
        return $this->hasMany(AccountDeleteRequest::class);
    }

    /**
     * The plan behind the user's active Stripe subscription, if any. The one place
     * feature-gating code should call — keeps callers off Cashier/Stripe directly, so a
     * second billing provider can plug in here later without touching call sites.
     */
    public function activeSubscriptionPlan(): ?SubscriptionPlan
    {
        $subscription = $this->subscription('default');

        if ($subscription === null || ! $subscription->valid()) {
            return null;
        }

        return SubscriptionPlan::where('stripe_price_id', $subscription->stripe_price)->first();
    }

    /**
     * Gate for the admin panel. `role` is a database enum with only these two values.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * Onboarding is a one-time, account-level step: creating the first account. Checked
     * with `withTrashed()` so deleting the last account never re-triggers onboarding UI.
     */
    public function needsOnboarding(): bool
    {
        return ! $this->accounts()->withTrashed()->exists();
    }
}
