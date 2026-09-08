<?php

namespace App\Console\Commands;

use App\Models\Sample;
use App\Services\GoogleDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Automatically recovers samples stuck in tiling_status = 'processing'.
 *
 * A sample gets stuck when the PHP queue worker process is killed (e.g.
 * by the OS or supervisor timeout) while rclone is uploading patches.
 * The rclone subprocess often finishes as an orphan — patches land on
 * Drive — but PHP never gets to update the DB.
 *
 * This command runs every 10 minutes via the scheduler and:
 *   1. Finds samples stuck in 'processing' for more than 30 minutes.
 *   2. Skips any whose job is still in the queue — see below.
 *   3. Checks if patches actually exist on Google Drive.
 *   4. If yes → marks as 'done' with the known gdrive path.
 *   5. If no  → marks as 'failed' so the user can retry.
 *
 * STEP 2 IS NOT OPTIONAL. Dispatch marks every slide of a batch 'processing'
 * at once, while the worker takes them one at a time — so in a fifty-slide run
 * the last slide waits well over an hour before anything touches it, with an
 * `updated_at` from dispatch and no gdrive path, which is indistinguishable
 * from a crash by age alone. On 2026-09-07 that mistake condemned 119 slides
 * in a single sweep: none had failed, none had even started. A slide that
 * still has a job in the queue has not crashed, whatever its timestamp says.
 *
 * Usage:
 *   php artisan patch:recover-stuck          # dry-run (no changes)
 *   php artisan patch:recover-stuck --fix    # apply changes
 */
class RecoverStuckPatchJobs extends Command
{
    protected $signature   = 'patch:recover-stuck
                              {--fix : Actually update the database (default is dry-run)}
                              {--max=15 : Refuse the sweep if more slides than this look stuck at once}
                              {--force : Sweep even past --max}';
    protected $description = 'Recover samples whose tiling_status is stuck at "processing" after a worker crash.';

    public function handle(GoogleDriveService $drive, \App\Services\QueuedJobLookup $queue): int
    {
        $fix         = $this->option('fix');
        $staleAfter  = now()->subMinutes(30);

        $candidates = Sample::where('tiling_status', 'processing')
            ->where('updated_at', '<', $staleAfter)
            ->get();

        // Slides whose job is still queued are waiting their turn, not stuck.
        $stillQueued = $queue->sampleIds(\App\Jobs\PatchExtractionJob::class);
        $stuck       = $candidates->reject(fn (Sample $s) => in_array($s->id, $stillQueued, true))->values();
        $waiting     = $candidates->count() - $stuck->count();

        if ($waiting > 0) {
            $this->line("Skipping {$waiting} sample(s) that are still queued — waiting is not a crash.");
        }

        if ($stuck->isEmpty()) {
            $this->info('No stuck samples found.');
            return self::SUCCESS;
        }

        // Defence in depth. The queue check above fixes the cause we know about;
        // this catches the ones we do not. Slides do not crash in their dozens
        // simultaneously — a sweep that wants to condemn that many is far more
        // likely to be misreading the system than to be right, and in 2026-09 a
        // sweep exactly like that destroyed 119 records. Refusing and shouting
        // is recoverable; marking them all failed is not.
        $max = (int) $this->option('max');

        if ($fix && ! $this->option('force') && $stuck->count() > $max) {
            $message = sprintf(
                '[RecoverStuck] REFUSED: %d slides look stuck at once (limit %d). That is far more likely to be a '
                . 'misjudgement than %d simultaneous crashes, so nothing was changed. Investigate, then re-run with '
                . '--force if the slides really did fail.',
                $stuck->count(), $max, $stuck->count()
            );

            Log::error($message);
            $this->error($message);

            return self::FAILURE;
        }

        $this->info("Found {$stuck->count()} stuck sample(s) (processing > 30 min, nothing left in the queue):");

        foreach ($stuck as $sample) {
            $this->line("  Sample #{$sample->id}: {$sample->file_name}");

            $gdrivePath = $sample->tiles_gdrive_path;

            if (empty($gdrivePath)) {
                $this->warn("    → No tiles_gdrive_path saved. Cannot verify Drive. Marking as FAILED.");
                if ($fix) {
                    DB::reconnect();
                    $sample->update(['tiling_status' => 'failed']);
                    Log::warning("[RecoverStuck] Sample #{$sample->id}: no gdrive path, marked failed.");
                } else {
                    $this->line("    → [dry-run] Would mark as FAILED.");
                }
                continue;
            }

            $this->line("    → Checking gdrive:{$gdrivePath}");

            $count = $this->countDriveFiles($drive, $gdrivePath);

            if ($count > 0) {
                $this->info("    → {$count} file(s) found on Drive → marking as DONE.");
                if ($fix) {
                    DB::reconnect();
                    $sample->update([
                        'tiling_status'       => 'done',
                        'tiling_completed_at' => $sample->tiling_completed_at ?? now(),
                    ]);
                    Log::info("[RecoverStuck] Sample #{$sample->id}: found {$count} patches on Drive, marked done.");
                } else {
                    $this->line("    → [dry-run] Would mark as DONE with tiling_completed_at = now().");
                }
            } else {
                $this->warn("    → 0 files on Drive → marking as FAILED.");
                if ($fix) {
                    DB::reconnect();
                    $sample->update(['tiling_status' => 'failed']);
                    Log::warning("[RecoverStuck] Sample #{$sample->id}: no patches on Drive, marked failed.");
                } else {
                    $this->line("    → [dry-run] Would mark as FAILED.");
                }
            }
        }

        if (!$fix) {
            $this->newLine();
            $this->comment('Dry-run complete. Re-run with --fix to apply changes.');
        }

        return self::SUCCESS;
    }

    private function countDriveFiles(GoogleDriveService $drive, string $remotePath): int
    {
        try {
            $rclonePath   = config('gdrive.rclone_path');
            $rcloneConfig = config('gdrive.rclone_config');
            $remoteName   = config('gdrive.remote_name');

            $process = new Process([
                $rclonePath,
                '--config', $rcloneConfig,
                'ls',
                "{$remoteName}:{$remotePath}",
            ]);
            $process->setTimeout(60);
            $process->run();

            if (!$process->isSuccessful()) {
                return 0;
            }

            $lines = array_filter(explode("\n", trim($process->getOutput())));
            return count($lines);
        } catch (\Throwable $e) {
            $this->warn("    → rclone check failed: {$e->getMessage()}");
            return 0;
        }
    }
}
