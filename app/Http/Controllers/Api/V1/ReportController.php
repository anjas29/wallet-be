<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private ReportService $reports,
    ) {}

    /**
     * Transaction report
     *
     * Generates a PDF statement of the authenticated user's transactions and transfers
     * for the given period, uploads it to S3, and returns a temporary download URL.
     * `period` accepts `yyyy-mm`, `yyyy`, or is omitted for an all-time report.
     */
    #[Group('Reports', weight: 12)]
    public function transactions(Request $request)
    {
        $request->validate([
            'period' => ['nullable', 'regex:/^\d{4}(-\d{2})?$/'],
        ]);

        $url = $this->reports->generateTransactionReport($request->user(), $request->input('period'));

        return $this->success(['url' => $url], 'Report generated.');
    }
}
