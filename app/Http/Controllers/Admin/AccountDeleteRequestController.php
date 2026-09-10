<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountDeleteRequest;
use App\Services\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountDeleteRequestController extends Controller
{
    public function __construct(private AccountDeletionService $deletions) {}

    public function index(Request $request): View
    {
        $status = $request->query('status', AccountDeleteRequest::STATUS_PENDING);

        if (! in_array($status, ['all', AccountDeleteRequest::STATUS_PENDING, AccountDeleteRequest::STATUS_IGNORED, AccountDeleteRequest::STATUS_DELETED], true)) {
            $status = AccountDeleteRequest::STATUS_PENDING;
        }

        $requests = AccountDeleteRequest::with(['user', 'processedBy'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.account-delete-requests', [
            'requests' => $requests,
            'status' => $status,
            'counts' => AccountDeleteRequest::selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
        ]);
    }

    public function ignore(Request $request, AccountDeleteRequest $accountDeleteRequest)
    {
        abort_unless($accountDeleteRequest->isPending(), 409, 'This request has already been processed.');

        $this->deletions->ignore($accountDeleteRequest, $request->user());

        return back()->with('status', "Request from {$accountDeleteRequest->email} was ignored.");
    }

    public function destroy(Request $request, AccountDeleteRequest $accountDeleteRequest)
    {
        abort_unless($accountDeleteRequest->isPending(), 409, 'This request has already been processed.');

        // An operator deleting their own account would lock themselves out mid-request.
        abort_if(
            $accountDeleteRequest->user_id === $request->user()->id,
            403,
            'You cannot delete the account you are signed in with.',
        );

        $counts = $this->deletions->approve($accountDeleteRequest, $request->user());

        $rows = array_sum($counts);
        $message = $accountDeleteRequest->user_id === null
            ? "No account matched {$accountDeleteRequest->email}; the request was closed."
            : "Deleted {$accountDeleteRequest->email} and {$rows} related record(s).";

        return back()->with('status', $message);
    }
}
