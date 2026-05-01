<?php

namespace App\Console\Commands;

use App\Models\SlideVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Artisan command to remove stale / orphaned slide_verifications rows.
 *
 * Orphaned rows are those whose sample_id no longer exists in the samples
 * table (the parent sample was deleted or replaced). These stale rows block
 * new imports because the slide_id unique constraint prevents a fresh INSERT
 * for the newly-created sample record.
 *
 * Usage:
 *   php artisan slides:clean-orphaned            # delete orphaned rows
 *   php artisan slides:clean-orphaned --dry-run  # preview only, no changes
 *   php artisan slides:clean-orphaned --force    # skip confirmation prompt
 */
class CleanOrphanedSlideVerifications extends Command
{
    protected $signature = 'slides:clean-orphaned
                            {--dry-run : Preview deletions without making any changes}
                            {--force   : Skip the confirmation prompt}';

    protected $description = 'Delete slide_verifications rows whose parent sample no longer exists (orphaned / stale records)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // ── 1. Find all orphaned rows ────────────────────────────────────────
        // A row is "orphaned" when its sample_id is NOT NULL but the matching
        // samples row has been deleted (or was never created).
        $orphanedQuery = SlideVerification::whereNotNull('sample_id')
            ->whereNotIn('sample_id', function ($sub) {
                $sub->select('id')->from('samples');
            });

        $count = $orphanedQuery->count();

        if ($count === 0) {
            $this->info('No orphaned slide_verifications rows found. Nothing to do.');
            return self::SUCCESS;
        }

        // ── 2. Show a summary table ──────────────────────────────────────────
        $this->warn("Found {$count} orphaned slide_verifications row(s) whose sample no longer exists:");

        $rows = (clone $orphanedQuery)
            ->select('id', 'sample_id', 'slide_id', 'patient_id', 'file_path', 'verified_at')
            ->orderBy('sample_id')
            ->limit(200)   // cap preview at 200 rows for readability
            ->get();

        $this->table(
            ['sv.id', 'sample_id (missing)', 'slide_id', 'patient_id', 'verified_at'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->sample_id,
                $r->slide_id ?? '—',
                $r->patient_id ?? '—',
                $r->verified_at ? $r->verified_at->toDateTimeString() : '—',
            ])->toArray()
        );

        if ($count > 200) {
            $this->line("  … and " . ($count - 200) . " more (not shown).");
        }

        // ── 3. Dry-run exits here ────────────────────────────────────────────
        if ($dryRun) {
            $this->info('[dry-run] No changes were made.');
            return self::SUCCESS;
        }

        // ── 4. Confirm before deleting ───────────────────────────────────────
        if (! $this->option('force')) {
            if (! $this->confirm("Delete these {$count} orphaned rows? This cannot be undone.")) {
                $this->line('Aborted. No changes made.');
                return self::SUCCESS;
            }
        }

        // ── 5. Delete ────────────────────────────────────────────────────────
        $deleted = (clone $orphanedQuery)->delete();

        $this->info("Deleted {$deleted} orphaned slide_verifications row(s).");
        $this->info('The affected slide_ids are now free. Re-run the verification queue to rebuild them:');
        $this->line('  php artisan slides:verify-pending --limit=500');

        return self::SUCCESS;
    }
}
