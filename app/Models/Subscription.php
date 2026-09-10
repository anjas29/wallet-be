<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * ULID-keyed override of Cashier's subscription model, registered via
 * `Cashier::useSubscriptionModel()` in `AppServiceProvider::boot()` to match this
 * project's primary-key convention (see `database/migrations/*_create_subscriptions_table.php`).
 */
class Subscription extends CashierSubscription
{
    use HasUlids;
}
