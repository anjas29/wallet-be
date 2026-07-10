<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LiabilityPaymentResource;
use App\Http\Resources\LiabilityResource;
use App\Services\LiabilityPaymentService;
use App\Services\LiabilityService;
use Illuminate\Http\Request;

class LiabilityController extends Controller
{
    public function __construct(
        private LiabilityService $liabilities,
        private LiabilityPaymentService $payments,
    ) {}

    public function index(Request $request)
    {
        $items = $this->liabilities->list($request->user()->id, $request->input('since'), $this->limit($request));

        return $this->collection(LiabilityResource::collection($items));
    }

    public function show(Request $request, string $id)
    {
        $liability = $this->liabilities->find($request->user()->id, $id, $request->input('since'));

        abort_if($liability === null, 404);

        return $this->success(new LiabilityResource($liability));
    }

    public function payments(Request $request)
    {
        $items = $this->payments->list($request->user()->id, $request->input('since'), $this->limit($request));

        return $this->collection(LiabilityPaymentResource::collection($items));
    }

    public function showPayment(Request $request, string $id)
    {
        $payment = $this->payments->find($request->user()->id, $id, $request->input('since'));

        abort_if($payment === null, 404);

        return $this->success(new LiabilityPaymentResource($payment));
    }
}
