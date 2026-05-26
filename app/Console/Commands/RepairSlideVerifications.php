<?php

namespace App\Console\Commands;

use App\Models\Sample;
use App\Models\SlideVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * php artisan slides:repair-orphans [--dry-run] [--force]
 *
 * Scans the slide_verifications table for integrity problems and fixes them.
 * Safe to run at any time — production-safe with --dry-run for preview.
 *
 * Problems detected and fixed:
 *   1. Orphan rows  — sample_id IS NULL (cannot be owned by any sample)
 *   2. Ghost rows   — sample_id points to a non-existent sample
 *   3. Duplicate rows — multiple verification rows for the same sample_id
 *   4. Slide ID conflicts — two rows share a slide_id (UNIQUE violation risk)
 */
class RepairSlideVerifications extends Command
{
    protected $signature = 'slides:repair-orphans
                            {--dry-run : Show what would be changed without writing anything}
                            {--force  : Skip the confirmation prompt}';

    protected $description = 'Detect and repair integrity problems in slide_verifications (orphans, duplicates, conflicts)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║  slides:repair-orphans — slide_verifications integrity  ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        if ($dryRun) {
            $this->warn('  ⚠  DRY-RUN mode — no changes will be written.');
        }
        $this->info('');

        // ── Gather statistics ─────────────────────────────────────────────────
        $total       = SlideVerification::count();
        $nullSample  = SlideVerification::whereNull('sample_id')->count();

        $ghostCount = DB::table('slide_verifications as sv')
            ->whereNotNull('sv.sample_id')
            ->whereNotExists(fn ($q) =>
                $q->from('samples')->whereColumn('samples.id', 'sv.sample_id')
            )
            ->count();

        $dupSampleIds = DB::table('slide_verifications')
            ->select('sample_id', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('sample_id')
            ->groupBy('sample_id')
            ->having('cnt', '>', 1)
            ->count();

        $dupSlideIds = DB::table('slide_verifications')
            ->select('slide_id', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('slide_id')
            ->groupBy('slide_id')
            ->having('cnt', '>', 1)
            ->count();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total rows',                  $total],
                ['Orphans (sample_id = NULL)',   $nullSample],
                ['Ghosts (sample deleted)',      $ghostCount],
                ['Duplicate sample_id rows',     $dupSampleIds],
                ['Duplicate slide_id values',    $dupSlideIds],
            ]
        );

        $issuesFound = $nullSample + $ghostCount + $dupSampleIds + $dupSlideIds;

        if ($issuesFound === 0) {
            $this->info('✅  No integrity issues found. The table is clean.');
            return self::SUCCESS;
        }

        $this->warn("⚠  {$issuesFound} integrity issues detected.");

        if (!$dryRun) {
            if (!$this->option('force') && !$this->confirm('Proceed with repairs?')) {
                $this->info('Aborted — no changes made.');
                return self::SUCCESS;
            }
        }

        $rescued = $deleted = $ghostDeleted = $dupDeleted = $slideConflicts = 0;

        if (!$dryRun) {
            DB::transaction(function () use (
                &$rescued, &$deleted, &$ghostDeleted, &$dupDeleted, &$slideConflicts
            ) {

                // ── Step 1: Rescue orphans that can be linked to a sample ─────
                // An orphan with a slide_id matching a sample's entity_submitter_id
                // and no competing row for that sample can be safely claimed.
                // MySQL error 1093 workaround: wrap the self-referencing subquery
                // in a derived table so MySQL allows the UPDATE.
                DB::statement("
                    UPDATE slide_verifications sv
                    INNER JOIN samples s
                        ON s.entity_submitter_id = sv.slide_id
                    SET sv.sample_id = s.id
                    WHERE sv.sample_id IS NULL
                      AND sv.slide_id  IS NOT NULL
                      AND s.id NOT IN (
                          SELECT sample_id FROM (
                              SELECT sample_id
                              FROM   slide_verifications
                              WHERE  sample_id IS NOT NULL
                          ) AS existing_samples
                      )
                ");
                $rescued = DB::affectedRows() ?: 0;

                // ── Step 2: Delete remaining orphans (sample_id still NULL) ──
                $deleted = DB::table('slide_verifications')
                    ->whereNull('sample_id')
                    ->delete();

                // ── Step 3: Delete ghost rows (sample was hard-deleted) ───────
                $ghostDeleted = DB::table('slide_verifications as sv')
                    ->whereNotNull('sv.sample_id')
                    ->whereNotExists(fn ($q) =>
                        $q->from('samples')->whereColumn('samples.id', 'sv.sample_id')
                    )
                    ->delete();

                // ── Step 4: Deduplicate sample_id — keep newest row per sample
                DB::statement("
                    DELETE sv1
                    FROM   slide_verifications sv1
                    INNER  JOIN slide_verifications sv2
                        ON  sv1.sample_id = sv2.sample_id
                        AND sv1.id        < sv2.id
                    WHERE sv1.sample_id IS NOT NULL
                ");
                $dupDeleted = DB::affectedRows() ?: 0;

                // ── Step 5: Deduplicate slide_id — keep newest row per slide ─
                DB::statement("
                    DELETE sv1
                    FROM   slide_verifications sv1
                    INNER  JOIN slide_verifications sv2
                        ON  sv1.slide_id = sv2.slide_id
                        AND sv1.id       < sv2.id
                    WHERE sv1.slide_id IS NOT NULL
                ");
                $slideConflicts = DB::affectedRows() ?: 0;
            });
        }

        // ── Report ────────────────────────────────────────────────────────────
        $verb = $dryRun ? 'Would rescue' : 'Rescued';
        $this->table(
            ['Action', 'Rows'],
            [
                ["{$verb}: orphans linked to their sample",  $dryRun ? '?' : $rescued],
                ["{$verb}: unresolvable orphans deleted",    $dryRun ? '?' : $deleted],
                ["{$verb}: ghost rows deleted",              $dryRun ? '?' : $ghostDeleted],
                ["{$verb}: duplicate sample_id rows removed",$dryRun ? '?' : $dupDeleted],
                ["{$verb}: duplicate slide_id rows removed", $dryRun ? '?' : $slideConflicts],
            ]
        );

        if (!$dryRun) {
            $remaining = SlideVerification::whereNull('sample_id')->count();
            if ($remaining === 0) {
                $this->info('✅  All issues resolved. The table is clean.');
            } else {
                $this->warn("⚠  {$remaining} orphan rows could not be resolved — manual review needed.");
            }

            Log::info('[RepairSlideVerifications] Repair complete', [
                'rescued'         => $rescued,
                'deleted'         => $deleted,
                'ghost_deleted'   => $ghostDeleted,
                'dup_deleted'     => $dupDeleted,
                'slide_conflicts' => $slideConflicts,
            ]);
        }

        return self::SUCCESS;
    }
}
