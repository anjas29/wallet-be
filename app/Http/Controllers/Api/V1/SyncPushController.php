<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SyncService;
use Illuminate\Http\Request;

class SyncPushController extends Controller
{
    public function __construct(private SyncService $sync) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'changes' => ['required', 'array'],
        ]);

        $results = $this->sync->apply($request->user(), $data['changes']);

        return $this->success([
            'results' => $results,
            'server_time' => now()->toISOString(),
        ]);
    }
}
