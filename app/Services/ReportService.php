<?php

namespace App\Services;

use App\Models\Account;
use App\Models\LiabilityPayment;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserCurrency;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReportService
{
    public function __construct(
        private AccountService $accounts,
    ) {}

    public function generateTransactionReport(User $user, ?string $period): string
    {
        ['start' => $start, 'end' => $end, 'label' => $label] = $this->parsePeriod($period);

        $accounts = Account::where('user_id', $user->id)
            ->with('userCurrency.currency')
            ->get();

        $anchor = UserCurrency::where('user_id', $user->id)->where('is_anchor', true)->with('currency')->first();

        $statements = $accounts->map(fn (Account $account) => $this->buildAccountStatement($account, $start, $end));

        $summary = $this->buildSummary($statements, $anchor);

        $pdf = Pdf::loadView('reports.transactions', [
            'user' => $user,
            'label' => $label,
            'generatedAt' => now(),
            'statements' => $statements,
            'summary' => $summary,
            'anchor' => $anchor,
        ])->setPaper('a4');

        $filename = 'transactions-'.($period ?? 'all-time').'.pdf';
        $path = "reports/{$user->id}/{$filename}";

        Storage::disk('s3')->put($path, $pdf->output());

        return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(60));
    }

    /**
     * @return array{start: ?Carbon, end: ?Carbon, label: string}
     */
    private function parsePeriod(?string $period): array
    {
        if ($period === null) {
            return ['start' => null, 'end' => null, 'label' => 'All Time'];
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $period, $matches)) {
            if (! checkdate((int) $matches[2], 1, (int) $matches[1])) {
                throw ValidationException::withMessages(['period' => ['The period is not a valid month.']]);
            }

            $start = Carbon::createFromDate((int) $matches[1], (int) $matches[2], 1)->startOfMonth();

            return ['start' => $start->copy(), 'end' => $start->copy()->endOfMonth(), 'label' => $start->format('F Y')];
        }

        if (preg_match('/^\d{4}$/', $period)) {
            $start = Carbon::createFromDate((int) $period, 1, 1)->startOfYear();

            return ['start' => $start->copy(), 'end' => $start->copy()->endOfYear(), 'label' => $period];
        }

        throw ValidationException::withMessages(['period' => ['The period must be formatted as yyyy-mm or yyyy.']]);
    }

    /**
     * @return array{account: Account, opening: string, closing: string, entries: Collection, totalIncome: float, totalExpense: float, totalTransferIn: float, totalTransferOut: float, totalLiabilityPayment: float}
     */
    private function buildAccountStatement(Account $account, ?Carbon $start, ?Carbon $end): array
    {
        $opening = $start
            ? $this->accounts->balancesFor($account->user_id, [$account->id], $start)[$account->id]
            : $account->initial_balance;

        $transactions = Transaction::where('account_id', $account->id)
            ->with('category')
            ->when($start && $end, fn ($query) => $query->whereBetween('transaction_date', [$start, $end]))
            ->get()
            ->map(fn (Transaction $transaction) => [
                'date' => $transaction->transaction_date,
                'description' => $transaction->description ?: $transaction->category?->name ?: ucfirst($transaction->type),
                'type' => $transaction->type,
                'signedAmount' => $transaction->type === 'income' ? (float) $transaction->amount : -(float) $transaction->amount,
            ]);

        $transfersOut = Transfer::where('from_account_id', $account->id)
            ->when($start && $end, fn ($query) => $query->whereBetween('transfer_date', [$start, $end]))
            ->get()
            ->map(fn (Transfer $transfer) => [
                'date' => $transfer->transfer_date,
                'description' => $transfer->description ?: 'Transfer out',
                'type' => 'transfer_out',
                'signedAmount' => -((float) $transfer->from_amount + (float) $transfer->fee),
            ]);

        $transfersIn = Transfer::where('to_account_id', $account->id)
            ->when($start && $end, fn ($query) => $query->whereBetween('transfer_date', [$start, $end]))
            ->get()
            ->map(fn (Transfer $transfer) => [
                'date' => $transfer->transfer_date,
                'description' => $transfer->description ?: 'Transfer in',
                'type' => 'transfer_in',
                'signedAmount' => (float) $transfer->to_amount,
            ]);

        $liabilityPayments = LiabilityPayment::where('account_id', $account->id)
            ->with('liability')
            ->when($start && $end, fn ($query) => $query->whereBetween('payment_date', [$start, $end]))
            ->get()
            ->map(fn (LiabilityPayment $payment) => [
                'date' => $payment->payment_date,
                'description' => $payment->note ?: ('Payment: '.($payment->liability?->name ?? 'Liability')),
                'type' => 'liability_payment',
                'signedAmount' => -(float) $payment->amount,
            ]);

        $entries = $transactions->concat($transfersOut)->concat($transfersIn)->concat($liabilityPayments)
            ->sortBy('date')
            ->values();

        $running = (float) $opening;
        $entries = $entries->map(function (array $entry) use (&$running) {
            $running += $entry['signedAmount'];
            $entry['balance'] = number_format($running, 2, '.', '');
            $entry['amount'] = number_format($entry['signedAmount'], 2, '.', '');

            return $entry;
        });

        return [
            'account' => $account,
            'opening' => number_format((float) $opening, 2, '.', ''),
            'closing' => number_format($running, 2, '.', ''),
            'entries' => $entries,
            'totalIncome' => $entries->where('type', 'income')->sum('signedAmount'),
            'totalExpense' => abs($entries->where('type', 'expense')->sum('signedAmount')),
            'totalTransferIn' => $entries->where('type', 'transfer_in')->sum('signedAmount'),
            'totalTransferOut' => abs($entries->where('type', 'transfer_out')->sum('signedAmount')),
            'totalLiabilityPayment' => abs($entries->where('type', 'liability_payment')->sum('signedAmount')),
        ];
    }

    /**
     * @param  Collection  $statements
     * @return array{net: string, currencyCode: ?string}
     */
    private function buildSummary($statements, ?UserCurrency $anchor): array
    {
        $net = $statements->sum(function (array $statement) {
            $rate = (float) ($statement['account']->userCurrency?->exchange_rate ?? 1);

            return ((float) $statement['closing'] - (float) $statement['opening']) * $rate;
        });

        return [
            'net' => number_format($net, 2, '.', ''),
            'currencyCode' => $anchor?->currency?->code,
        ];
    }
}
