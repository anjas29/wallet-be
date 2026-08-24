<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecurringTransactionResource;
use App\Services\RecurringTransactionService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

class RecurringTransactionController extends Controller
{
    public function __construct(private RecurringTransactionService $recurringTransactions) {}

    /**
     * List recurring transactions
     */
    #[Group('Recurring Transactions', weight: 8)]
    public function index(Request $request)
    {
        $items = $this->recurringTransactions->list($request->user()->id, $request->input('since'), $this->limit($request));

        return $this->collection(RecurringTransactionResource::collection($items));
    }

    /**
     * Get recurring transaction
     */
    #[Group('Recurring Transactions', weight: 8)]
    public function show(Request $request, string $id)
    {
        $recurringTransaction = $this->recurringTransactions->find($request->user()->id, $id, $request->input('since'));

        abort_if($recurringTransaction === null, 404);

        return $this->success(new RecurringTransactionResource($recurringTransaction));
    }
}
