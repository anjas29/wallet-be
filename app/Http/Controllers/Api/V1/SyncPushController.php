<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SyncService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Sync', weight: 10)]
class SyncPushController extends Controller
{
    public function __construct(private SyncService $sync) {}

    /**
     * Push changes
     *
     * Apply a batch of offline client changes; returns a per-item result list.
     */
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
