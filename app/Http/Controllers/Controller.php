<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

abstract class Controller
{
    use ApiResponses;

    /**
     * Wrap a resource collection in the standard read envelope: data.items + data.server_time.
     */
    protected function collection(ResourceCollection $items): JsonResponse
    {
        return $this->success([
            'items' => $items,
            'server_time' => now()->toISOString(),
        ]);
    }

    /**
     * Optional `limit` query param for delta-sync reads (null when absent).
     */
    protected function limit(Request $request): ?int
    {
        return $request->filled('limit') ? (int) $request->input('limit') : null;
    }
}
