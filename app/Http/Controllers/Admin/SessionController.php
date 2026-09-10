<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session login for the admin panel. Served at `/` — the site root is the panel's front door.
 */
class SessionController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()?->isAdmin()) {
            return redirect()->route('admin.home');
        }

        return view('admin.login');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password']], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        // Authenticated, but not an operator. Drop the session again rather than leaving a
        // logged-in non-admin holding a session that only ever renders 403s.
        if (! $request->user()->isAdmin()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'This account does not have admin access.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('admin.home'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
