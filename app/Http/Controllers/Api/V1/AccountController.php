<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Services\AccountService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Accounts', weight: 2)]
class AccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    /**
     * Create account
     *
     * The server generates the account id. `user_currency_id` must be one of the caller's
     * currency holdings. Marking `is_default` unsets the flag on the user's other accounts.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_currency_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'type' => ['required', 'in:bank_account,cash,credit_card,savings'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'initial_balance' => ['nullable', 'numeric', 'min:0'],
            'is_default' => ['boolean'],
        ]);

        $account = $this->accounts->create($request->user(), $data);

        return $this->success(new AccountResource($account), 'Account created.', 201);
    }

    /**
     * List accounts
     *
     * Each account includes a derived `balance` in its own currency.
     */
    public function index(Request $request)
    {
        $items = $this->accounts->list($request->user()->id, $request->input('since'), $this->limit($request));

        return $this->collection(AccountResource::collection($items));
    }

    /**
     * Get account
     */
    public function show(Request $request, string $id)
    {
        $account = $this->accounts->find($request->user()->id, $id, $request->input('since'));

        abort_if($account === null, 404);

        return $this->success(new AccountResource($account));
    }
}
