<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', function () {
    return view('welcome');
});

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
    'changelog' => ['changelog.md', 'Changelog'],
];

foreach ($docPages as $slug => [$file, $title]) {
    Route::get('/docs'.($slug === '' ? '' : '/'.$slug), fn () => $renderDoc($file, $title))
        ->name('docs.'.($slug === '' ? 'home' : $slug));
}
