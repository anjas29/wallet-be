<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ReceiptScanException;
use App\Http\Controllers\Controller;
use App\Services\ReceiptScanService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    public function __construct(
        private ReceiptScanService $receiptScan,
    ) {}

    /**
     * Scan a receipt
     *
     * Extracts line items, merchant, date, and total from a receipt photo,
     * mapping each item to the closest matching category owned by the
     * authenticated user.
     */
    #[Group('Receipts', weight: 11)]
    public function scan(Request $request)
    {
        $request->validate([
            'receipt' => ['required', 'image', 'mimes:jpg,png,webp', 'max:5120'],
        ]);

        try {
            $result = $this->receiptScan->scan($request->user(), $request->file('receipt'));
        } catch (ReceiptScanException $e) {
            return $this->error($e->getMessage(), $e->status);
        }

        return $this->success($result, 'Receipt scanned.');
    }
}
