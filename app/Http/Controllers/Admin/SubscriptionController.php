<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(): View
    {
        $subscriptions = Subscription::with('user')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.subscriptions', [
            'subscriptions' => $subscriptions,
            'counts' => Subscription::selectRaw('stripe_status, count(*) as total')
                ->groupBy('stripe_status')
                ->pluck('total', 'stripe_status'),
        ]);
    }
}
