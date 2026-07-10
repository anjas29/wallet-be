<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserCurrencyResource;
use App\Services\UserCurrencyService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

/**
 * The authenticated user's currency holdings (user_currencies).
 */
#[Group('User Currencies', weight: 7)]
class CurrencyController extends Controller
{
    public function __construct(private UserCurrencyService $userCurrencies) {}

    /**
     * List user currencies
     */
    public function index(Request $request)
    {
        $items = $this->userCurrencies->list($request->user()->id, $request->input('since'), $this->limit($request));

        return $this->collection(UserCurrencyResource::collection($items));
    }

    /**
     * Get user currency
     */
    public function show(Request $request, string $id)
    {
        $userCurrency = $this->userCurrencies->find($request->user()->id, $id, $request->input('since'));

        abort_if($userCurrency === null, 404);

        return $this->success(new UserCurrencyResource($userCurrency));
    }
}
