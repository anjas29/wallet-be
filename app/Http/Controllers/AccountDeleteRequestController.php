<?php

namespace App\Http\Controllers;

use App\Services\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public, unauthenticated account-deletion request form. Required as a reachable web page by
 * the app stores for any app that holds user accounts.
 */
class AccountDeleteRequestController extends Controller
{
    public function __construct(private AccountDeletionService $deletions) {}

    public function create(): View
    {
        return view('account-delete-request.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'email_confirmation' => ['required', 'same:email'],
            'confirm' => ['accepted'],
        ], [
            'email_confirmation.same' => 'The two email addresses do not match.',
            'confirm.accepted' => 'Please confirm that you understand your data will be deleted.',
        ]);

        $this->deletions->request(
            $data['email'],
            $request->ip(),
            $request->userAgent(),
        );

        // Always the same outcome, whether or not the address is registered — see
        // AccountDeletionService::request().
        return redirect()
            ->route('account-delete-request.submitted')
            ->with('deletion_requested_email', mb_strtolower(trim($data['email'])));
    }

    public function submitted(Request $request): View
    {
        // Direct hits with no flashed email are fine; the page just omits the address.
        return view('account-delete-request.submitted', [
            'email' => $request->session()->get('deletion_requested_email'),
        ]);
    }
}
