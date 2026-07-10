<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', function () {
    return view('welcome');
});

Route::redirect('/docs', '/docs/api');

// Rendered Markdown guide for the sync/push endpoint (linked from the Scramble docs).
Route::get('/docs/push-changes', function () {
    $path = base_path('docs/push-changes.md');

    abort_unless(is_file($path), 404);

    return view('docs.page', [
        'title' => 'Push Changes',
        'content' => Str::markdown(file_get_contents($path)),
    ]);
})->name('docs.push-changes');
