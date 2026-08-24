<?php

namespace App\Console\Commands;

use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateRecurringTransactions extends Command
{
    protected $signature = 'recurring-transactions:generate';

    protected $description = 'Generate due Transaction rows from active recurring transaction templates.';

    // Safety valve: caps how many missed occurrences one template can catch up on in a single
    // run (e.g. the server was down for months) — the remainder is picked up on the next run.
    private const MAX_CATCH_UP_OCCURRENCES = 366;

    public function handle(): int
    {
        $today = Carbon::today();

        // whereHas excludes soft-deleted account/category by default, so a template whose
        // account or category was retired stops generating without needing extra bookkeeping.
        $dueIds = RecurringTransaction::query()
            ->where('is_active', true)
            ->where('next_run_date', '<=', $today->toDateString())
            ->whereHas('account')
            ->whereHas('category')
            ->pluck('id');

        $generated = 0;

        foreach ($dueIds as $id) {
            try {
                $generated += $this->processTemplate($id, $today);
            } catch (\Throwable $e) {
                // Isolate per-template failures — one bad row must not abort the whole run.
                Log::error('recurring-transactions:generate failed for template', [
                    'recurring_transaction_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Generated {$generated} transaction(s) from {$dueIds->count()} due template(s).");

        return self::SUCCESS;
    }

    private function processTemplate(string $id, Carbon $today): int
    {
        return DB::transaction(function () use ($id, $today) {
            // Re-fetch + row-lock: serializes against any concurrent/overlapping run touching
            // this same template, making a double invocation a safe no-op.
            $template = RecurringTransaction::where('id', $id)
                ->with(['category', 'account.userCurrency'])
                ->lockForUpdate()
                ->first();

            if ($template === null || ! $template->is_active) {
                return 0; // paused/deleted since the outer query ran
            }

            $count = 0;
            $dueDate = $template->next_run_date;

            while ($dueDate !== null && $dueDate->lte($today) && $count < self::MAX_CATCH_UP_OCCURRENCES) {
                if ($template->end_date !== null && $dueDate->gt($template->end_date)) {
                    $template->is_active = false;
                    $dueDate = null;
                    break;
                }

                Transaction::create([
                    'id' => (string) Str::ulid(),
                    'user_id' => $template->user_id,
                    'account_id' => $template->account_id,
                    'category_id' => $template->category_id,
                    'type' => $template->category->type, // derived — no client input to trust here
                    'exchange_rate_to_anchor' => $template->account->userCurrency->exchange_rate ?? '1',
                    'amount' => $template->amount,
                    'description' => $template->description,
                    'transaction_date' => $dueDate->toDateString(),
                ]);

                $count++;
                $dueDate = $template->nextOccurrenceAfter($dueDate);
            }

            $template->next_run_date = $dueDate;
            $template->save();

            return $count;
        });
    }
}
