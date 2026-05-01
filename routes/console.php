<?php

use App\Models\SlideVerification;
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

// ─── Clean orphaned slide_verifications rows ──────────────────────────
// Removes rows whose parent sample no longer exists in the samples table.
// These stale rows block re-import because of the UNIQUE constraint on
// slide_id. Run with --dry-run first to preview, then without to delete.
//
//   php artisan slides:clean-orphaned --dry-run
//   php artisan slides:clean-orphaned --force
//
Artisan::command('slides:clean-orphaned {--dry-run : Preview only, no changes} {--force : Skip confirmation}', function () {
    $dryRun = (bool) $this->option('dry-run');

    // Find all slide_verifications rows whose sample_id points to a
    // sample that no longer exists.
    $orphanedQuery = SlideVerification::whereNotNull('sample_id')
        ->whereNotIn('sample_id', function ($sub) {
            $sub->select('id')->from('samples');
        });

    $count = $orphanedQuery->count();

    if ($count === 0) {
        $this->info('No orphaned slide_verifications rows found. Nothing to do.');
        return;
    }

    $this->warn("Found {$count} orphaned slide_verifications row(s) whose sample no longer exists:");

    $rows = (clone $orphanedQuery)
        ->select('id', 'sample_id', 'slide_id', 'patient_id', 'verified_at')
        ->orderBy('sample_id')
        ->limit(200)
        ->get();

    $this->table(
        ['sv.id', 'sample_id (missing)', 'slide_id', 'patient_id', 'verified_at'],
        $rows->map(fn ($r) => [
            $r->id,
            $r->sample_id,
            $r->slide_id ?? '—',
            $r->patient_id ?? '—',
            $r->verified_at?->toDateTimeString() ?? '—',
        ])->toArray()
    );

    if ($count > 200) {
        $this->line('  … and ' . ($count - 200) . ' more (not shown).');
    }

    if ($dryRun) {
        $this->info('[dry-run] No changes were made.');
        return;
    }

    if (! $this->option('force')) {
        if (! $this->confirm("Delete these {$count} orphaned rows? This cannot be undone.")) {
            $this->line('Aborted. No changes made.');
            return;
        }
    }

    $deleted = (clone $orphanedQuery)->delete();

    $this->info("Deleted {$deleted} orphaned slide_verifications row(s).");
    $this->info('The slide_ids are now free. Re-queue verification with:');
    $this->line('  php artisan slides:verify-pending --limit=500');
})->purpose('Delete slide_verifications rows whose parent sample no longer exists');

