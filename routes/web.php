<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', function () {
    return view('welcome');
});

Route::redirect('/docs', '/docs/api');

// Legal pages.
Route::get('/terms-conditions', function () {
    $path = base_path('docs/terms-conditions.md');

    abort_unless(is_file($path), 404);

    return view('docs.page', [
        'title' => 'Terms & Conditions',
        'content' => Str::markdown(file_get_contents($path)),
    ]);
})->name('terms-conditions');

Route::get('/privacy-policy', function () {
    $path = base_path('docs/privacy-policy.md');

    abort_unless(is_file($path), 404);

    return view('docs.page', [
        'title' => 'Privacy Policy',
        'content' => Str::markdown(file_get_contents($path)),
    ]);
})->name('privacy-policy');

// Rendered Markdown guide for the sync/push endpoint (linked from the Scramble docs).
Route::get('/docs/push-changes', function () {
    $path = base_path('docs/push-changes.md');

    abort_unless(is_file($path), 404);

    return view('docs.page', [
        'title' => 'Push Changes',
        'content' => Str::markdown(file_get_contents($path)),
    ]);
})->name('docs.push-changes');

// Server-side PostgreSQL schema, generated from the migrations (source of truth).
Route::get('/docs/database-schema', function () {
    $path = base_path('docs/database-schema.md');

    abort_unless(is_file($path), 404);

    return view('docs.page', [
        'title' => 'Server Database Schema',
        'content' => Str::markdown(file_get_contents($path)),
    ]);
})->name('docs.database-schema');

// Android Room mirror of the server schema, for offline-first sync.
Route::get('/docs/android-room-schema', function () {
    $path = base_path('docs/android-room-schema.md');

    abort_unless(is_file($path), 404);

    return view('docs.page', [
        'title' => 'Android Room Schema',
        'content' => Str::markdown(file_get_contents($path)),
    ]);
})->name('docs.android-room-schema');
