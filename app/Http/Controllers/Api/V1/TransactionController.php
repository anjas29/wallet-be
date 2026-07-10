<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\TransferResource;
use App\Services\TransactionService;
use App\Services\TransferService;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactions,
        private TransferService $transfers,
    ) {}

    public function index(Request $request)
    {
        $items = $this->transactions->list($request->user()->id, $request->input('since'), $this->limit($request));

        return $this->collection(TransactionResource::collection($items));
    }

    public function show(Request $request, string $id)
    {
        $transaction = $this->transactions->find($request->user()->id, $id, $request->input('since'));

        abort_if($transaction === null, 404);

        return $this->success(new TransactionResource($transaction));
    }

    public function transfers(Request $request)
    {
        $items = $this->transfers->list($request->user()->id, $request->input('since'), $this->limit($request));

        return $this->collection(TransferResource::collection($items));
    }

    public function showTransfer(Request $request, string $id)
    {
        $transfer = $this->transfers->find($request->user()->id, $id, $request->input('since'));

        abort_if($transfer === null, 404);

        return $this->success(new TransferResource($transfer));
    }
}
