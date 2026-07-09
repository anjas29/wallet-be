<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\CurrencyResource;
use App\Http\Resources\LiabilityPaymentResource;
use App\Http\Resources\LiabilityResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\TransferResource;
use App\Http\Resources\UserCurrencyResource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Liability;
use App\Models\LiabilityPayment;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\UserCurrency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;

class WalletReadController extends Controller
{
    public function currencies(Request $request)
    {
        return $this->index($request, Currency::query(), CurrencyResource::class, false);
    }

    public function showCurrency(Request $request, string $id)
    {
        return $this->show($request, Currency::query(), CurrencyResource::class, $id, false);
    }

    public function categories(Request $request)
    {
        $query = Category::query();

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return $this->index($request, $query, CategoryResource::class, false);
    }

    public function showCategory(Request $request, string $id)
    {
        return $this->show($request, Category::query(), CategoryResource::class, $id, false);
    }

    public function userCurrencies(Request $request)
    {
        return $this->index($request, UserCurrency::query(), UserCurrencyResource::class, true);
    }

    public function showUserCurrency(Request $request, string $id)
    {
        return $this->show($request, UserCurrency::query(), UserCurrencyResource::class, $id, true);
    }

    public function accounts(Request $request)
    {
        return $this->index($request, Account::query(), AccountResource::class, true);
    }

    public function showAccount(Request $request, string $id)
    {
        return $this->show($request, Account::query(), AccountResource::class, $id, true);
    }

    public function transactions(Request $request)
    {
        return $this->index($request, Transaction::query(), TransactionResource::class, true);
    }

    public function showTransaction(Request $request, string $id)
    {
        return $this->show($request, Transaction::query(), TransactionResource::class, $id, true);
    }

    public function transfers(Request $request)
    {
        return $this->index($request, Transfer::query(), TransferResource::class, true);
    }

    public function showTransfer(Request $request, string $id)
    {
        return $this->show($request, Transfer::query(), TransferResource::class, $id, true);
    }

    public function liabilities(Request $request)
    {
        return $this->index($request, Liability::query(), LiabilityResource::class, true);
    }

    public function showLiability(Request $request, string $id)
    {
        return $this->show($request, Liability::query(), LiabilityResource::class, $id, true);
    }

    public function liabilityPayments(Request $request)
    {
        return $this->index($request, LiabilityPayment::query(), LiabilityPaymentResource::class, true);
    }

    public function showLiabilityPayment(Request $request, string $id)
    {
        return $this->show($request, LiabilityPayment::query(), LiabilityPaymentResource::class, $id, true);
    }

    private function index(Request $request, Builder $query, string $resource, bool $userScoped)
    {
        if ($userScoped) {
            $query->where('user_id', $request->user()->id);
        }

        $this->applyDeltaScope($request, $query);

        if ($request->filled('limit')) {
            $query->limit(min((int) $request->input('limit'), 500));
        }

        $query->orderBy('updated_at')->orderBy('id');

        return $this->success([
            'items' => $resource::collection($query->get()),
            'server_time' => now()->toISOString(),
        ]);
    }

    private function show(Request $request, Builder $query, string $resource, string $id, bool $userScoped)
    {
        $query->whereKey($id);

        if ($userScoped) {
            $query->where('user_id', $request->user()->id);
        }

        $this->applyDeltaScope($request, $query);

        $record = $query->first();

        if (! $record) {
            abort(404);
        }

        return $this->success(new $resource($record));
    }

    /**
     * Delta-sync: when `since` is provided, return everything changed after it — including
     * soft-deleted rows so clients learn about deletions. Otherwise exclude trashed rows.
     */
    private function applyDeltaScope(Request $request, Builder $query): void
    {
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true);

        if ($request->filled('since')) {
            if ($softDeletes) {
                $query->withTrashed();
            }

            $query->where('updated_at', '>', $request->input('since'));
        }
    }
}
