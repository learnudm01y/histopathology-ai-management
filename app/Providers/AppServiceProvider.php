<?php

namespace App\Providers;

use App\Models\Sample;
use App\Observers\SampleObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin template uses Bootstrap 4 — use the matching pagination view.
        Paginator::useBootstrap();

        // ── Automatic Drive upload on download completion ─────────────────
        // SampleObserver watches for download_completed_at / file_id changes
        // and immediately dispatches UploadWsiToDriveJob.
        // This permanently closes the gap between local download and Drive upload.
        Sample::observe(SampleObserver::class);
    }
}
