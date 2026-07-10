<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserCurrencyResource;
use App\Services\UserCurrencyService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The authenticated user's currency holdings (user_currencies).
 */
#[Group('User Currencies', weight: 7)]
class CurrencyController extends Controller
{
    public function __construct(private UserCurrencyService $userCurrencies) {}

    /**
     * Add user currency
     *
     * Add a currency to the caller's holdings. Marking `is_anchor` unsets the anchor on
     * the user's other currencies.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'currency_id' => [
                'required',
                'string',
                'exists:currencies,id',
                Rule::unique('user_currencies', 'currency_id')->where('user_id', $request->user()->id),
            ],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'is_anchor' => ['boolean'],
        ]);

        $userCurrency = $this->userCurrencies->create($request->user(), $data);

        return $this->success(new UserCurrencyResource($userCurrency), 'Currency added.', 201);
    }

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
