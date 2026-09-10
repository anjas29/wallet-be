<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;

/**
 * ULID-keyed override of Cashier's subscription-item model, registered via
 * `Cashier::useSubscriptionItemModel()` in `AppServiceProvider::boot()`.
 */
class SubscriptionItem extends CashierSubscriptionItem
{
    use HasUlids;
}
