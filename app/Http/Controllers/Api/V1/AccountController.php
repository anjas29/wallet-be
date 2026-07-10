<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Services\AccountService;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function index(Request $request)
    {
        $items = $this->accounts->list($request->user()->id, $request->input('since'), $this->limit($request));

        return $this->collection(AccountResource::collection($items));
    }

    public function show(Request $request, string $id)
    {
        $account = $this->accounts->find($request->user()->id, $id, $request->input('since'));

        abort_if($account === null, 404);

        return $this->success(new AccountResource($account));
    }
}
