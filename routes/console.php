<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires `php artisan schedule:run` to be invoked every minute by the deployment (host cron
// or a `schedule:work` sidecar) — this repo/its deployed compose do not wire that up yet.
Schedule::command('recurring-transactions:generate')
    ->dailyAt('00:10')
    ->withoutOverlapping()
    ->onOneServer();
