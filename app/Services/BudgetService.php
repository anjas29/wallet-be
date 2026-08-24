<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Concerns\DeltaSyncQuery;
use App\Services\Concerns\PersistsEntities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class BudgetService
{
    use DeltaSyncQuery;
    use PersistsEntities;

    public function list(string $userId, ?string $since, ?int $limit): Collection
    {
        $budgets = $this->listDelta(Budget::where('user_id', $userId), $since, $limit);

        return $this->attachSpent($budgets);
    }

    public function find(string $userId, string $id, ?string $since): ?Budget
    {
        $budget = $this->findDelta(Budget::where('user_id', $userId), $id, $since);

        if ($budget !== null) {
            $this->attachSpent(new Collection([$budget]));
        }

        return $budget;
    }

    public function createOrUpdate(User $user, string $id, string $op, array $data): Budget
    {
        $category = UserCategory::where('id', $data['category_id'] ?? null)
            ->where('user_id', $user->id)
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'data.category_id' => ['The selected category is invalid.'],
            ]);
        }

        if ($category->type !== 'expense') {
            throw ValidationException::withMessages([
                'data.category_id' => ['A budget can only be set on an expense category.'],
            ]);
        }

        $periodType = $data['period_type'] ?? null;

        if (! in_array($periodType, ['monthly', 'custom'], true)) {
            throw ValidationException::withMessages([
                'data.period_type' => ['The period type must be monthly or custom.'],
            ]);
        }

        $periodStart = $data['period_start'] ?? null;
        $periodEnd = $data['period_end'] ?? null;

        if ($periodType === 'custom') {
            if (! $periodStart || ! $periodEnd) {
                throw ValidationException::withMessages([
                    'data.period_start' => ['A custom budget requires period_start and period_end.'],
                ]);
            }

            if (Carbon::parse($periodEnd)->lt(Carbon::parse($periodStart))) {
                throw ValidationException::withMessages([
                    'data.period_end' => ['period_end must not be before period_start.'],
                ]);
            }
        } else {
            // monthly: always relative to "now" — never persist stray dates from a client.
            $periodStart = null;
            $periodEnd = null;
        }

        $budget = $this->upsertEntity(Budget::class, $id, $op, [
            'user_id' => $user->id,
            'category_id' => $data['category_id'],
            'amount' => $data['amount'] ?? null,
            'period_type' => $periodType,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ], $user->id);

        $this->attachSpent(new Collection([$budget]));

        return $budget;
    }

    public function delete(User $user, string $id): void
    {
        $this->softDeleteEntity(Budget::class, $id, $user->id);
    }

    /**
     * Attach derived `spent`/`remaining` attributes to each model. Groups budgets by their
     * resolved period window and issues one aggregate query per distinct window (not N+1) —
     * mirrors AccountService::attachBalances()/balancesFor().
     */
    public function attachSpent(Collection $budgets): Collection
    {
        if ($budgets->isEmpty()) {
            return $budgets;
        }

        $groups = $budgets->groupBy(function (Budget $budget) {
            $period = $this->resolvePeriod($budget);

            return $period['start']->toDateString().'_'.$period['end']->toDateString();
        });

        foreach ($groups as $group) {
            $period = $this->resolvePeriod($group->first());
            $categoryIds = $group->pluck('category_id')->unique()->all();

            $spent = Transaction::query()
                ->whereIn('category_id', $categoryIds)
                ->where('type', 'expense')
                ->whereBetween('transaction_date', [$period['start'], $period['end']])
                ->groupBy('category_id')
                ->selectRaw('category_id, SUM(amount) as total')
                ->pluck('total', 'category_id');

            foreach ($group as $budget) {
                $spentAmount = (float) ($spent[$budget->category_id] ?? 0);

                $budget->setAttribute('spent', number_format($spentAmount, 2, '.', ''));
                $budget->setAttribute('remaining', number_format((float) $budget->amount - $spentAmount, 2, '.', ''));
            }
        }

        return $budgets;
    }

    /**
     * Resolve a budget's evaluation window: 'monthly' is always "the current calendar month"
     * (recomputed every read, per requirements); 'custom' uses its own stored dates.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    private function resolvePeriod(Budget $budget): array
    {
        if ($budget->period_type === 'custom') {
            return ['start' => $budget->period_start->copy(), 'end' => $budget->period_end->copy()];
        }

        return ['start' => Carbon::now()->startOfMonth(), 'end' => Carbon::now()->endOfMonth()];
    }
}
