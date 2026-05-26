<?php

namespace App\Console\Commands;

use App\Jobs\UploadWsiToDriveJob;
use App\Models\Sample;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan wsi:scan-and-upload [--dry-run] [--limit=N] [--force-requeue]
 *
 * ════════════════════════════════════════════════════════════════════════
 *  PERMANENT PREVENTION COMMAND
 * ════════════════════════════════════════════════════════════════════════
 *
 * Scans for samples whose WSI file:
 *   A. Exists locally in {HISTO_AI_LOCAL_BASE}/{file_id}/{file_name}
 *   B. Has NOT yet been uploaded to Google Drive (wsi_remote_path IS NULL)
 *
 * For every such sample, dispatches UploadWsiToDriveJob onto the 'uploads'
 * queue. The job is ShouldBeUnique, so re-running this command is safe —
 * it will not double-queue the same sample.
 *
 * SCHEDULED: every hour (see routes/console.php).
 * MANUAL:    php artisan wsi:scan-and-upload --dry-run   (preview)
 *            php artisan wsi:scan-and-upload             (dispatch jobs)
 *            php artisan wsi:scan-and-upload --limit=100 (batch of 100)
 *
 * Additionally scans the filesystem for SVS files that exist locally but
 * are NOT in the database at all — these are logged as orphan files for
 * manual investigation.
 */
class ScanAndUploadMissing extends Command
{
    protected $signature = 'wsi:scan-and-upload
                            {--dry-run   : Show what would be dispatched without queuing anything}
                            {--limit=500 : Maximum number of upload jobs to dispatch per run}
                            {--force-requeue : Re-queue even samples already in pending upload state}';

    protected $description = 'Find WSI files downloaded locally but not yet uploaded to Drive, and dispatch upload jobs';

    public function handle(): int
    {
        $dryRun       = (bool) $this->option('dry-run');
        $limit        = (int)  $this->option('limit');

        $localBase = rtrim(config('gdrive.local_wsi_base', env('HISTO_AI_LOCAL_BASE', '/var/www/HISTO_AI/ameer')), '/');

        $this->info('');
        $this->info('╔═══════════════════════════════════════════════════════════╗');
        $this->info('║  wsi:scan-and-upload — Drive upload gap detector         ║');
        $this->info('╚═══════════════════════════════════════════════════════════╝');

        if ($dryRun) {
            $this->warn('  ⚠  DRY-RUN — no jobs will be queued.');
        }

        $this->info("  Local base: {$localBase}");
        $this->info('');

        // ── Phase 1: DB-driven scan ───────────────────────────────────────────
        // Find samples that have file_id + file_name but no wsi_remote_path.
        // These are samples the GDC client downloaded but the upload job
        // either never ran or failed silently.
        $this->info('Phase 1: DB scan — samples with file_id but no wsi_remote_path...');

        $candidates = Sample::query()
            ->whereNotNull('file_id')
            ->whereNotNull('file_name')
            ->whereNull('wsi_remote_path')
            ->select(['id', 'file_id', 'file_name'])
            ->orderBy('id')
            ->limit($limit * 2) // fetch extra; we filter by local-file-exists below
            ->get();

        $this->info("  Found {$candidates->count()} DB candidates (limit fetch: " . ($limit * 2) . ").");

        $dispatched   = 0;
        $notFound     = 0;
        $stillParceling = 0;

        foreach ($candidates as $sample) {
            if ($dispatched >= $limit) {
                break;
            }

            $localPath  = $localBase . '/' . $sample->file_id . '/' . $sample->file_name;
            $parcelPath = $localBase . '/' . $sample->file_id . '/logs/' . $sample->file_name . '.parcel';

            if (!is_file($localPath)) {
                $notFound++;
                continue;
            }

            // Skip files still downloading (fresh .parcel = in-progress)
            if (is_file($parcelPath) && (time() - filemtime($parcelPath)) < 3600) {
                $stillParceling++;
                $this->line("  <comment>SKIP #{$sample->id}</comment> — download still in progress (.parcel)");
                continue;
            }

            if (!$dryRun) {
                UploadWsiToDriveJob::dispatch($sample->id);
                $dispatched++;
                Log::info("[ScanAndUploadMissing] Dispatched upload job for sample #{$sample->id}");
            } else {
                $dispatched++;
                $this->line("  <info>WOULD DISPATCH</info> sample #{$sample->id} — {$sample->file_name}");
            }
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['DB candidates (no wsi_remote_path)',       $candidates->count()],
                [$dryRun ? 'Would dispatch' : 'Dispatched', $dispatched],
                ['Local file not found (not downloaded yet)', $notFound],
                ['Still downloading (.parcel active)',        $stillParceling],
            ]
        );

        // ── Phase 2: Filesystem scan ──────────────────────────────────────────
        // Walk {LOCAL_BASE}/ and look for .svs files whose parent directory
        // (= file_id) does not match any sample in the DB, or matches a sample
        // that has wsi_remote_path already set (just double-checking).
        $this->info('');
        $this->info('Phase 2: Filesystem scan — SVS files with no DB record...');

        if (!is_dir($localBase)) {
            $this->warn("  Local base directory {$localBase} does not exist — skipping filesystem scan.");
        } else {
            $this->runFilesystemScan($localBase, $dryRun);
        }

        if (!$dryRun && $dispatched > 0) {
            $this->info('');
            $this->info("✅  {$dispatched} upload job(s) queued on the 'uploads' worker.");
            Log::info("[ScanAndUploadMissing] Queued {$dispatched} upload jobs.");
        }

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function runFilesystemScan(string $localBase, bool $dryRun): void
    {
        $orphanCount    = 0;
        $knownCount     = 0;
        $dirs           = glob($localBase . '/*', GLOB_ONLYDIR);

        if ($dirs === false || count($dirs) === 0) {
            $this->info('  No subdirectories found.');
            return;
        }

        foreach ($dirs as $dir) {
            $fileId = basename($dir);
            $svs    = glob($dir . '/*.svs');

            if (empty($svs)) {
                continue;
            }

            foreach ($svs as $svsPath) {
                $fileName = basename($svsPath);
                $sample   = Sample::where('file_id', $fileId)
                    ->where('file_name', $fileName)
                    ->first();

                if (!$sample) {
                    $orphanCount++;
                    if ($orphanCount <= 10) {
                        // Only show first 10 to avoid flooding the output
                        $this->warn("  ORPHAN (not in DB): {$svsPath}");
                    }
                    Log::warning("[ScanAndUploadMissing] Orphan SVS (no DB record): {$svsPath}");
                } else {
                    $knownCount++;
                }
            }
        }

        if ($orphanCount > 10) {
            $this->warn("  ... and " . ($orphanCount - 10) . " more orphan files (see laravel.log for full list).");
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['SVS files with matching DB record', $knownCount],
                ['Orphan SVS files (no DB record)',   $orphanCount],
            ]
        );
    }
}
