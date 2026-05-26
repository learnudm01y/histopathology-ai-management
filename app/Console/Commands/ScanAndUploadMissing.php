<?php

namespace App\Console\Commands;

use App\Jobs\UploadWsiToDriveJob;
use App\Models\Sample;
use App\Models\SlideVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * php artisan wsi:scan-and-upload [--dry-run] [--limit=N]
 *
 * ════════════════════════════════════════════════════════════════════════
 *  SELF-HEALING UPLOAD GAP DETECTOR  (runs hourly via scheduler)
 * ════════════════════════════════════════════════════════════════════════
 *
 * Phase 0 — Self-healing:
 *   Samples stuck as storage_status='upload_failed' for > 24 h are
 *   automatically reset to NULL. The system retries them on its own —
 *   no human command required.
 *
 * Phase 1 — DB scan:
 *   Samples with file_id but no wsi_remote_path whose local file exists
 *   → dispatch UploadWsiToDriveJob. Classification comes from the DB
 *   (dataSource + category), NOT from filename guessing.
 *
 * Phase 2 — Filesystem scan:
 *   SVS files in the local download directory with no DB record → logged
 *   as orphans for investigation.
 *
 * Operational limits:
 *   • --limit=200          jobs dispatched per run
 *   • max_upload_queue_depth (config/gdrive.php)  prevents queue flooding
 *   • ShouldBeUnique on the job prevents duplicate queue entries
 */
class ScanAndUploadMissing extends Command
{
    protected $signature = 'wsi:scan-and-upload
                            {--dry-run   : Show what would happen without making any changes}
                            {--limit=200 : Maximum number of upload jobs to dispatch per run}';

    protected $description = 'Self-healing: find locally-downloaded WSI files not yet on Drive and dispatch upload jobs';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $limit     = (int)  $this->option('limit');
        $localBase = rtrim(config('gdrive.local_wsi_base', env('HISTO_AI_LOCAL_BASE', '/var/www/HISTO_AI/ameer')), '/');

        // Hard ceiling: never exceed this many pending upload jobs in the queue.
        // Prevents each hourly run from stacking 200 more on top of the previous 200.
        $maxQueueDepth = (int) config('gdrive.max_upload_queue_depth', 400);

        $this->info('');
        $this->info('╔═══════════════════════════════════════════════════════════╗');
        $this->info('║  wsi:scan-and-upload — self-healing upload gap detector  ║');
        $this->info('╚═══════════════════════════════════════════════════════════╝');

        if ($dryRun) {
            $this->warn('  ⚠  DRY-RUN — no changes will be made.');
        }

        $this->info("  Local base      : {$localBase}");
        $this->info("  Dispatch limit  : {$limit}  |  Max queue depth: {$maxQueueDepth}");
        $this->info('');

        // ── Phase 0a: Self-healing reset ─────────────────────────────────────
        $this->runSelfHealingReset($dryRun);

        // ── Phase 0b: Repair stale "File exists" failures ─────────────────────
        // Samples already on Drive (wsi_remote_path set) whose slide_verifications
        // row still has file_path = NULL from a run that pre-dated the upload.
        // These show "File exists — Failed" in the UI even though the file IS there.
        // Fix: copy wsi_remote_path → slide_verifications.file_path and reset to pending.
        $this->repairStaleFilePaths($dryRun);

        // ── Phase 0c: Repair storage_status='corrupted' for files on Drive ────
        // Samples that were already on Drive when UploadWsiToDriveJob ran, but
        // persistRemotePath() previously didn't set storage_status='uploaded'.
        // These show a red "corrupted" badge in the UI even though the file IS there.
        // Fix: set storage_status='uploaded' for any sample that has wsi_remote_path
        //      but still carries 'corrupted' (or 'upload_failed') storage_status.
        $this->repairCorruptedStatus($dryRun);

        // ── Phase 0d: Rescue stuck pending verifications ──────────────────────
        // Samples whose wsi_remote_path IS NULL but slide_verifications row
        // has verification_status='pending' — this is caused by WsiPreviewJob
        // being dispatched when only file_id was set (GDC UUID, not a Drive path).
        // The job found no remote path, returned silently, and left the DB in
        // 'pending' forever → VerifyPendingSlides re-dispatches it every 2 min.
        // Fix: reset verification_status = 'not_checked' (or NULL) so the row
        // is skipped until wsi_remote_path is populated by UploadWsiToDriveJob.
        $this->repairStuckPendingVerifications($dryRun);

        // ── Operational limit: queue depth guard ──────────────────────────────
        $pendingUploads = DB::table('jobs')->where('queue', 'uploads')->count();
        $this->info("  Queue depth now : {$pendingUploads} / {$maxQueueDepth}");

        if ($pendingUploads >= $maxQueueDepth) {
            $this->warn("  Queue is at capacity — skipping dispatch this run. Worker will drain before next scan.");
            Log::info("[ScanAndUploadMissing] Queue at capacity ({$pendingUploads}/{$maxQueueDepth}) — skipping dispatch.");
            return self::SUCCESS;
        }

        // Dispatch only as many jobs as there are free slots.
        $available = min($limit, $maxQueueDepth - $pendingUploads);
        $this->info("  Available slots : {$available}");
        $this->info('');

        // ── Phase 1: DB-driven scan ───────────────────────────────────────────
        $this->info('Phase 1: DB scan — samples with file_id but no wsi_remote_path...');

        // Exclude upload_failed here — Phase 0 handles resetting those.
        // If they were reset this run, they will appear here with storage_status=NULL.
        $candidates = Sample::query()
            ->whereNotNull('file_id')
            ->whereNotNull('file_name')
            ->whereNull('wsi_remote_path')
            ->where(fn ($q) => $q->whereNull('storage_status')
                ->orWhere('storage_status', '!=', 'upload_failed'))
            ->select(['id', 'file_id', 'file_name'])
            ->orderBy('id')
            ->limit($available * 3) // over-fetch; filter by local-file-exists below
            ->get();

        $this->info("  DB candidates : {$candidates->count()}");

        $dispatched       = 0;
        $notFound         = 0;
        $stillDownloading = 0;

        foreach ($candidates as $sample) {
            if ($dispatched >= $available) {
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
                $stillDownloading++;
                continue;
            }

            if (!$dryRun) {
                UploadWsiToDriveJob::dispatch($sample->id);
                $dispatched++;
                Log::info("[ScanAndUploadMissing] Dispatched UploadWsiToDriveJob for sample #{$sample->id}");
            } else {
                $dispatched++;
                $this->line("  <info>WOULD DISPATCH</info> #{$sample->id} — {$sample->file_name}");
            }
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['DB candidates (no wsi_remote_path)',        $candidates->count()],
                [$dryRun ? 'Would dispatch' : 'Dispatched',  $dispatched],
                ['Local file not found (not yet downloaded)', $notFound],
                ['Still downloading (.parcel active)',         $stillDownloading],
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

    /**
     * Phase 0: reset samples stuck as upload_failed for > 24 h.
     *
     * This makes the system self-healing: a Drive API outage causes failures,
     * but 24 h later the system automatically retries without human intervention.
     * The 24-h gate prevents hammering a persistently broken connection.
     */
    private function runSelfHealingReset(bool $dryRun): void
    {
        $staleCount = Sample::where('storage_status', 'upload_failed')
            ->where('updated_at', '<', now()->subHours(24))
            ->count();

        if ($staleCount === 0) {
            $this->info('  Phase 0 (self-healing): no stale upload_failed samples.');
            $this->info('');
            return;
        }

        $this->warn("  Phase 0 (self-healing): {$staleCount} sample(s) stuck as upload_failed for > 24 h — resetting for retry.");

        if (!$dryRun) {
            Sample::where('storage_status', 'upload_failed')
                ->where('updated_at', '<', now()->subHours(24))
                ->update(['storage_status' => null]);

            Log::warning("[ScanAndUploadMissing] Self-healing: reset {$staleCount} upload_failed sample(s) for retry.");
        }

        $this->info('');
    }

    private function repairCorruptedStatus(bool $dryRun): void
    {
        // Samples that have wsi_remote_path (file IS on Drive) but still carry
        // a 'corrupted' or 'upload_failed' storage_status — a symptom of the
        // old persistRemotePath() that forgot to set storage_status='uploaded'.
        $affected = Sample::whereNotNull('wsi_remote_path')
            ->whereIn('storage_status', ['corrupted', 'upload_failed'])
            ->get(['id', 'wsi_remote_path', 'storage_status']);

        if ($affected->isEmpty()) {
            $this->info('  Phase 0c (repair corrupted status): nothing to fix.');
            $this->info('');
            return;
        }

        $this->warn("  Phase 0c (repair corrupted status): {$affected->count()} sample(s) have wsi_remote_path set but storage_status=corrupted/upload_failed — fixing.");

        if (!$dryRun) {
            Sample::whereNotNull('wsi_remote_path')
                ->whereIn('storage_status', ['corrupted', 'upload_failed'])
                ->update(['storage_status' => 'available']);

            Log::info("[ScanAndUploadMissing] Phase 0c: reset storage_status='available' on {$affected->count()} sample(s).");
        }

        $this->info('');
    }

    private function repairStuckPendingVerifications(bool $dryRun): void
    {
        // Find slide_verifications rows where:
        //   - verification_status = 'pending'  (scheduler keeps re-dispatching)
        //   - sample has NO wsi_remote_path    (WsiPreviewJob will always bail early)
        //
        // Root cause: the old "has drive source" guard included file_id (GDC UUID)
        // so WsiPreviewJob was dispatched for every TCGA sample regardless of
        // whether the file was on Google Drive.  The job found no remote path,
        // returned without updating the DB, and left verification_status='pending'
        // forever.  Fix: reset to NULL so the scheduler ignores these rows until
        // UploadWsiToDriveJob later sets wsi_remote_path.
        $stuck = DB::table('slide_verifications as sv')
            ->join('samples as s', 's.id', '=', 'sv.sample_id')
            ->whereNull('s.wsi_remote_path')
            ->whereNull('s.storage_path')
            ->where('sv.verification_status', 'pending')
            ->select('sv.id', 'sv.sample_id')
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('  Phase 0d (repair stuck verifications): nothing to fix.');
            $this->info('');
            return;
        }

        $this->warn("  Phase 0d (repair stuck verifications): {$stuck->count()} verification row(s) stuck at 'pending' with no Drive path — resetting to NULL.");

        if (!$dryRun) {
            $ids = $stuck->pluck('id')->toArray();
            DB::table('slide_verifications')
                ->whereIn('id', $ids)
                ->update([
                    'verification_status' => null,
                    'notes'               => 'Reset by ScanAndUploadMissing: no wsi_remote_path available at dispatch time.',
                ]);

            Log::info("[ScanAndUploadMissing] Phase 0d: reset verification_status on {$stuck->count()} stuck row(s).");
        }

        $this->info('');
    }

    /**
     * Phase 0b: Fix slide_verifications rows that show "File exists — Failed"
     * even though the sample is already on Drive.
     *
     * Root cause: verification ran when wsi_remote_path was NULL → file_path was
     * stored as NULL.  After a successful upload, wsi_remote_path gets set on
     * samples but the old NULL in slide_verifications is never corrected unless
     * a full re-verify fires.  This method copies wsi_remote_path → file_path
     * directly so the check passes immediately.
     */
    private function repairStaleFilePaths(bool $dryRun): void
    {
        // Find samples that are on Drive but whose verification still has a NULL file_path.
        $staleIds = Sample::whereNotNull('wsi_remote_path')
            ->whereHas('slideVerification', function ($q) {
                $q->whereNull('file_path');
            })
            ->pluck('id', 'wsi_remote_path');  // [sample_id => wsi_remote_path]

        if ($staleIds->isEmpty()) {
            $this->info('  Phase 0b (repair file_path): nothing to fix.');
            $this->info('');
            return;
        }

        $this->warn("  Phase 0b (repair file_path): {$staleIds->count()} verification(s) have file_path=NULL despite wsi_remote_path being set — fixing.");

        if (!$dryRun) {
            foreach ($staleIds as $remotePath => $sampleId) {
                SlideVerification::where('sample_id', $sampleId)
                    ->whereNull('file_path')
                    ->update([
                        'file_path'           => $remotePath,
                        'verification_status' => 'pending',
                        'verified_at'         => null,
                    ]);
            }

            Log::info("[ScanAndUploadMissing] Phase 0b: patched file_path on {$staleIds->count()} slide_verifications row(s).");
        }

        $this->info('');
    }

    private function runFilesystemScan(string $localBase): void
    {
        $orphanCount = 0;
        $knownCount  = 0;
        $dirs        = glob($localBase . '/*', GLOB_ONLYDIR) ?: [];

        foreach ($dirs as $dir) {
            $fileId = basename($dir);
            $svs    = glob($dir . '/*.svs') ?: [];

            foreach ($svs as $svsPath) {
                $fileName = basename($svsPath);
                $exists   = Sample::where('file_id', $fileId)
                    ->where('file_name', $fileName)
                    ->exists();

                if (!$exists) {
                    $orphanCount++;
                    if ($orphanCount <= 10) {
                        $this->warn("  ORPHAN (not in DB): {$svsPath}");
                    }
                    Log::warning("[ScanAndUploadMissing] Orphan SVS file (no DB record): {$svsPath}");
                } else {
                    $knownCount++;
                }
            }
        }

        if ($orphanCount > 10) {
            $this->warn("  ... and " . ($orphanCount - 10) . " more orphan files — see laravel.log.");
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
