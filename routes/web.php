<?php

use App\Http\Controllers\AccountDeleteRequestController;
use App\Http\Controllers\Admin;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
| Admin panel. The site root is the sign-in page (it replaced the "Coming Soon" splash);
| `welcome.blade.php` is kept in the repo so the splash can be restored if needed.
*/
Route::get('/', [Admin\SessionController::class, 'create'])->name('login');
Route::post('/', [Admin\SessionController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('login.store');
Route::post('/logout', [Admin\SessionController::class, 'destroy'])->name('logout');

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [Admin\HomeController::class, 'index'])->name('home');

    Route::get('/account-delete-requests', [Admin\AccountDeleteRequestController::class, 'index'])
        ->name('account-delete-requests.index');
    Route::post('/account-delete-requests/{accountDeleteRequest}/ignore', [Admin\AccountDeleteRequestController::class, 'ignore'])
        ->name('account-delete-requests.ignore');
    Route::post('/account-delete-requests/{accountDeleteRequest}/delete', [Admin\AccountDeleteRequestController::class, 'destroy'])
        ->name('account-delete-requests.destroy');

    Route::get('/subscriptions', [Admin\SubscriptionController::class, 'index'])
        ->name('subscriptions.index');

    Route::get('/plans', [Admin\PlanController::class, 'index'])->name('plans.index');
    Route::get('/plans/create', [Admin\PlanController::class, 'create'])->name('plans.create');
    Route::post('/plans', [Admin\PlanController::class, 'store'])->name('plans.store');
    Route::get('/plans/{plan}/edit', [Admin\PlanController::class, 'edit'])->name('plans.edit');
    Route::post('/plans/{plan}', [Admin\PlanController::class, 'update'])->name('plans.update');
    Route::post('/plans/{plan}/archive', [Admin\PlanController::class, 'archive'])->name('plans.archive');
});

/*
| Default Stripe Checkout redirect targets (config('subscriptions.checkout.*')). These are
| fallback web pages only — coordinate real Android deep links with the mobile team and
| override STRIPE_CHECKOUT_SUCCESS_URL/STRIPE_CHECKOUT_CANCEL_URL once those exist. The
| actual subscription activation happens via Stripe webhook, not this page.
*/
Route::get('/subscriptions/checkout/success', fn () => view('subscriptions.checkout-success'))
    ->name('subscriptions.checkout.success');
Route::get('/subscriptions/checkout/cancelled', fn () => view('subscriptions.checkout-cancelled'))
    ->name('subscriptions.checkout.cancelled');

/*
| Public account-deletion request form — unauthenticated on purpose: a user who has already
| uninstalled the app still has to be able to ask. Throttled because it writes on POST.
*/
Route::get('/account-delete-request', [AccountDeleteRequestController::class, 'create'])
    ->name('account-delete-request.create');
Route::post('/account-delete-request', [AccountDeleteRequestController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('account-delete-request.store');
Route::get('/account-delete-request/submitted', [AccountDeleteRequestController::class, 'submitted'])
    ->name('account-delete-request.submitted');

// Render a Markdown file from docs/ through the shared docs.page view.
$renderDoc = function (string $file, string $title) {
    $path = base_path('docs/'.$file);

    abort_unless(is_file($path), 404);

    return view('docs.page', [
        'title' => $title,
        'content' => Str::markdown(file_get_contents($path)),
    ]);
};

// Legal pages.
Route::get('/terms-conditions', fn () => $renderDoc('terms-conditions.md', 'Terms & Conditions'))->name('terms-conditions');
Route::get('/privacy-policy', fn () => $renderDoc('privacy-policy.md', 'Privacy Policy'))->name('privacy-policy');

/*
| Documentation hub + Markdown guides. `/docs` is the landing page that links out to
| everything; Scramble's interactive API explorer lives at `/docs/api` (registered by
| Scramble itself, not here). The endpoint reference is Scramble — these are the guides.
*/
$docPages = [
    '' => ['index.md', 'Documentation'],
    'database-schema' => ['database-schema.md', 'Server Database Schema'],
    'android-room-schema' => ['android-room-schema.md', 'Android Room Schema'],
    'push-changes' => ['push-changes.md', 'Push Changes'],
    'sync-changes-1.2.0' => ['sync-changes-1.2.0.md', 'Sync Changes — v1.2.0'],
    'onboarding-and-sync-client-flow' => ['onboarding-and-sync-client-flow.md', 'Onboarding & Sync — Client Flow'],
    'changelog' => ['changelog.md', 'Changelog'],
];

foreach ($docPages as $slug => [$file, $title]) {
    Route::get('/docs'.($slug === '' ? '' : '/'.$slug), fn () => $renderDoc($file, $title))
        ->name('docs.'.($slug === '' ? 'home' : $slug));
}
