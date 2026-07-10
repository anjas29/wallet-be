<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\CurrencyResource;
use App\Services\CategoryService;
use App\Services\CurrencyService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

class MiscController extends Controller
{
    public function __construct(
        private CurrencyService $currencies,
        private CategoryService $categories,
    ) {}

    /**
     * List currencies
     *
     * Global reference list of supported currencies.
     */
    #[Group('Currencies', weight: 8)]
    public function currencies(Request $request)
    {
        $items = $this->currencies->list($request->input('since'), $this->limit($request));

        return $this->collection(CurrencyResource::collection($items));
    }

    /**
     * Get currency
     */
    #[Group('Currencies', weight: 8)]
    public function showCurrency(Request $request, string $id)
    {
        $currency = $this->currencies->find($id, $request->input('since'));

        abort_if($currency === null, 404);

        return $this->success(new CurrencyResource($currency));
    }

    /**
     * List categories
     *
     * Global reference list of income/expense categories.
     */
    #[Group('Categories', weight: 9)]
    public function categories(Request $request)
    {
        $items = $this->categories->list($request->input('type'), $request->input('since'), $this->limit($request));

        return $this->collection(CategoryResource::collection($items));
    }

    /**
     * Get category
     */
    #[Group('Categories', weight: 9)]
    public function showCategory(Request $request, string $id)
    {
        $category = $this->categories->find($id, $request->input('since'));

        abort_if($category === null, 404);

        return $this->success(new CategoryResource($category));
    }
}
