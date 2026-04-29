<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Slide-verification scheduler ────────────────────────────────────
// Development: every 2 minutes with a 5-minute overlap lock.
// Production: change to ->cron('0 0,12 * * *') (every 12 hours).
// ShouldBeUnique on RunSlideVerification prevents duplicate jobs from
// accumulating in the queue between scheduler ticks.
Schedule::command('slides:verify-pending')
    ->everyTwoMinutes()
    ->withoutOverlapping(5)
    ->runInBackground();

