<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function __construct(private SubscriptionPlanService $plans) {}

    public function index(): View
    {
        return view('admin.plans.index', [
            'plans' => SubscriptionPlan::orderBy('sort_order')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.plans.form', ['plan' => new SubscriptionPlan, 'mode' => 'create']);
    }

    public function store(Request $request): RedirectResponse
    {
        $plan = $this->plans->create($this->validated($request));

        return redirect()->route('admin.plans.index')->with('status', "Plan '{$plan->name}' created.");
    }

    public function edit(SubscriptionPlan $plan): View
    {
        return view('admin.plans.form', ['plan' => $plan, 'mode' => 'edit']);
    }

    public function update(Request $request, SubscriptionPlan $plan): RedirectResponse
    {
        $this->plans->update($plan, $this->validated($request, $plan));

        return redirect()->route('admin.plans.index')->with('status', "Plan '{$plan->name}' updated.");
    }

    /**
     * Deactivates the plan and archives its Stripe Price rather than deleting the row — see
     * SubscriptionPlanService::archive().
     */
    public function archive(SubscriptionPlan $plan): RedirectResponse
    {
        $this->plans->archive($plan);

        return redirect()->route('admin.plans.index')->with('status', "Plan '{$plan->name}' archived.");
    }

    /**
     * @return array{slug: string, name: string, description: ?string, price_amount: int, interval: string, features: array<int, string>, is_active: bool, sort_order: int, trial_days: ?int}
     */
    private function validated(Request $request, ?SubscriptionPlan $plan = null): array
    {
        $data = $request->validate([
            'slug' => [
                'required', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('subscription_plans', 'slug')->ignore($plan?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price_amount' => ['required', 'integer', 'min:1'],
            'interval' => ['required', 'in:month,year'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'features' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $data['features'] = collect(preg_split('/\r?\n/', $data['features'] ?? ''))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();

        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }
}
