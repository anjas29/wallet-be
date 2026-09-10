<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountDeleteRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('admin.home', [
            'pendingDeleteRequests' => AccountDeleteRequest::where('status', AccountDeleteRequest::STATUS_PENDING)->count(),
            'activeSubscriptions' => Subscription::where('stripe_status', 'active')->count(),
            'activePlans' => SubscriptionPlan::where('is_active', true)->count(),
        ]);
    }
}
