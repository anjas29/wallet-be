<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BudgetResource;
use App\Services\BudgetService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    public function __construct(private BudgetService $budgets) {}

    /**
     * List budgets
     *
     * Each budget includes derived `spent`/`remaining` for its resolved period.
     */
    #[Group('Budgets', weight: 7)]
    public function index(Request $request)
    {
        $items = $this->budgets->list($request->user()->id, $request->input('since'), $this->limit($request));

        return $this->collection(BudgetResource::collection($items));
    }

    /**
     * Get budget
     */
    #[Group('Budgets', weight: 7)]
    public function show(Request $request, string $id)
    {
        $budget = $this->budgets->find($request->user()->id, $id, $request->input('since'));

        abort_if($budget === null, 404);

        return $this->success(new BudgetResource($budget));
    }
}
