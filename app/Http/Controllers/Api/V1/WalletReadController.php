<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletReadController extends Controller
{
    public function currencies(Request $request)
    {
        return $this->index($request, 'currencies', false, true);
    }

    public function showCurrency(Request $request, string $id)
    {
        return $this->show($request, 'currencies', $id, false, true);
    }

    public function categories(Request $request)
    {
        $query = DB::table('categories');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return $this->indexQuery($request, $query, true);
    }

    public function showCategory(Request $request, string $id)
    {
        return $this->show($request, 'categories', $id, false, true);
    }

    public function userCurrencies(Request $request)
    {
        return $this->index($request, 'user_currencies', true, false);
    }

    public function showUserCurrency(Request $request, string $id)
    {
        return $this->show($request, 'user_currencies', $id, true, false);
    }

    public function accounts(Request $request)
    {
        return $this->index($request, 'accounts', true, true);
    }

    public function showAccount(Request $request, string $id)
    {
        return $this->show($request, 'accounts', $id, true, true);
    }

    public function transactions(Request $request)
    {
        return $this->index($request, 'transactions', true, true);
    }

    public function showTransaction(Request $request, string $id)
    {
        return $this->show($request, 'transactions', $id, true, true);
    }

    public function transfers(Request $request)
    {
        return $this->index($request, 'transfers', true, true);
    }

    public function showTransfer(Request $request, string $id)
    {
        return $this->show($request, 'transfers', $id, true, true);
    }

    public function liabilities(Request $request)
    {
        return $this->index($request, 'liabilities', true, true);
    }

    public function showLiability(Request $request, string $id)
    {
        return $this->show($request, 'liabilities', $id, true, true);
    }

    public function liabilityPayments(Request $request)
    {
        return $this->index($request, 'liability_payments', true, true);
    }

    public function showLiabilityPayment(Request $request, string $id)
    {
        return $this->show($request, 'liability_payments', $id, true, true);
    }

    private function index(Request $request, string $table, bool $userScoped, bool $softDeletes)
    {
        $query = DB::table($table);

        if ($userScoped) {
            $query->where('user_id', $request->user()->id);
        }

        return $this->indexQuery($request, $query, $softDeletes);
    }

    private function indexQuery(Request $request, $query, bool $softDeletes)
    {
        if ($request->filled('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        } elseif ($softDeletes) {
            $query->whereNull('deleted_at');
        }

        if ($request->filled('limit')) {
            $query->limit(min((int) $request->input('limit'), 500));
        }

        $query->orderBy('updated_at')->orderBy('id');

        return response()->json([
            'data' => $query->get(),
            'meta' => [
                'server_time' => now()->toISOString(),
            ],
        ]);
    }

    private function show(Request $request, string $table, string $id, bool $userScoped, bool $softDeletes)
    {
        $query = DB::table($table)->where('id', $id);

        if ($userScoped) {
            $query->where('user_id', $request->user()->id);
        }

        if ($request->filled('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        } elseif ($softDeletes) {
            $query->whereNull('deleted_at');
        }

        $record = $query->first();

        if (! $record) {
            abort(404);
        }

        return response()->json(['data' => $record]);
    }
}
