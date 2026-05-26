<?php

namespace App\Jobs;

use App\Models\Sample;
use App\Models\SlideVerification;
use App\Services\GoogleDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Uploads a locally-downloaded WSI file to Google Drive and updates
 * the sample record with the resulting wsi_remote_path.
 *
 * ════════════════════════════════════════════════════════════════════════
 *  CLASSIFICATION GUARANTEE
 * ════════════════════════════════════════════════════════════════════════
 * Path is ALWAYS derived from the database relationships:
 *
 *   GoogleDriveService::buildBulkFolderPath($sample, $sample->file_id)
 *       → {root}/{dataSource->name}/{category->label_en}/{file_id}/
 *       → e.g. samples/TCGA-BRCA/tumor/8191fa1b-.../
 *
 * If dataSource or category is not set in the DB, the job REFUSES to upload
 * and marks storage_status='classification_error' — it will NOT place files
 * into unknown_source/ or uncategorized/ folders that corrupt the structure.
 *
 * ════════════════════════════════════════════════════════════════════════
 *  OPERATIONAL LIMITS
 * ════════════════════════════════════════════════════════════════════════
 * • timeout   = 7200 s  (2 h per file — enough for 4 GB on a slow link)
 * • tries     = 3       (3 total attempts)
 * • backoff   = [60, 300, 900]  (1 min → 5 min → 15 min between retries)
 * • uniqueFor = 10800 s (unique lock for 3 h — prevents duplicate jobs)
 * • ShouldBeUnique — only one job per sampleId in the queue at any time
 */
class UploadWsiToDriveJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $timeout  = 7200;
    public int   $tries    = 3;
    public array $backoff  = [60, 300, 900];
    public int   $uniqueFor = 10800;

    /**
     * Placeholder values that buildBulkFolderPath emits when a relationship is null.
     * If the resolved path contains any of these, the upload is aborted.
     */
    private const INVALID_SLUGS = ['unknown_source', 'uncategorized'];

    public function uniqueId(): string
    {
        return 'wsi-upload-' . $this->sampleId;
    }

    public function __construct(
        public readonly int $sampleId,
    ) {
        $this->onQueue('uploads');
    }

    public function handle(GoogleDriveService $drive): void
    {
        // ── 1. Load sample WITH classification relationships ───────────────────
        // Eager-load dataSource and category to avoid N+1 and to ensure the
        // path builder has accurate data before we decide anything.
        $sample = Sample::with(['dataSource', 'category'])->find($this->sampleId);

        if (!$sample) {
            Log::warning("[UploadWsiToDriveJob] Sample #{$this->sampleId}: not found in DB — discarding.");
            return;
        }

        if (!empty($sample->wsi_remote_path)) {
            Log::info("[UploadWsiToDriveJob] Sample #{$this->sampleId}: already uploaded — skipping.");
            return;
        }

        if (empty($sample->file_id) || empty($sample->file_name)) {
            Log::warning("[UploadWsiToDriveJob] Sample #{$this->sampleId}: missing file_id or file_name.");
            return;
        }

        // ── 2. Resolve & validate the Drive path (DB-driven) ──────────────────
        //
        // buildBulkFolderPath uses:
        //   $sample->dataSource->name       → e.g. "TCGA-BRCA"
        //   $sample->category->label_en     → e.g. "tumor" | "normal"
        //   $sample->file_id                → e.g. "8191fa1b-..."
        //
        // This produces: samples/TCGA-BRCA/tumor/8191fa1b-.../
        // which is the SAME structure used by all other uploads in the system.
        $remoteFolderPath = $drive->buildBulkFolderPath($sample, $sample->file_id);
        $remotePath       = $remoteFolderPath . '/' . $sample->file_name;

        // Guard: refuse to upload if the path contains unresolved placeholders.
        // A path like "samples/unknown_source/uncategorized/..." means the DB
        // record is incomplete. Uploading there would corrupt the Drive structure.
        foreach (self::INVALID_SLUGS as $invalid) {
            if (str_contains($remoteFolderPath, $invalid)) {
                $error = sprintf(
                    'Classification incomplete: path "%s" contains "%s". ' .
                    'dataSource=%s, category=%s — fix the sample record first.',
                    $remoteFolderPath,
                    $invalid,
                    $sample->dataSource?->name ?? 'NULL',
                    $sample->category?->label_en ?? 'NULL',
                );
                Log::error("[UploadWsiToDriveJob] Sample #{$this->sampleId}: {$error}");
                // Call fail() directly — retrying won't fix a DB data problem
                $this->fail(new \RuntimeException($error));
                return;
            }
        }

        // ── 3. Locate local file ──────────────────────────────────────────────
        $localBase = rtrim(config('gdrive.local_wsi_base', env('HISTO_AI_LOCAL_BASE', '/var/www/HISTO_AI/ameer')), '/');
        $localDir  = $localBase . '/' . $sample->file_id;
        $localPath = $localDir  . '/' . $sample->file_name;

        if (!is_file($localPath)) {
            Log::warning("[UploadWsiToDriveJob] Sample #{$this->sampleId}: local file not found at {$localPath}.");
            return;
        }

        // Skip files still being downloaded (.parcel companion = GDC download in progress)
        $parcelPath = $localDir . '/logs/' . $sample->file_name . '.parcel';
        if (is_file($parcelPath) && (time() - filemtime($parcelPath)) < 3600) {
            Log::info("[UploadWsiToDriveJob] Sample #{$this->sampleId}: download in progress (.parcel fresh) — re-queuing in 10 min.");
            $this->release(600);
            return;
        }

        // ── 4. Idempotent guard — check if already on Drive ───────────────────
        // If the file is already there (e.g. from a previous partial run),
        // skip the rclone copy and just update the DB.
        $meta = $drive->fetchFileMeta($remotePath);
        if (!empty($meta['Name'])) {
            Log::info("[UploadWsiToDriveJob] Sample #{$this->sampleId}: already on Drive at {$remotePath} — updating DB only.");
            $this->persistRemotePath($sample, $remotePath, $remoteFolderPath . '/');
            return;
        }

        // ── 5. Upload ─────────────────────────────────────────────────────────
        Log::info(sprintf(
            '[UploadWsiToDriveJob] Sample #%d: uploading %s → %s',
            $this->sampleId,
            $sample->file_name,
            $remotePath,
        ));

        $drive->uploadLocalFile($localPath, $remoteFolderPath);

        Log::info("[UploadWsiToDriveJob] Sample #{$this->sampleId}: upload complete.");

        $this->persistRemotePath($sample, $remotePath, $remoteFolderPath . '/');
    }

    /**
     * Set wsi_remote_path on the Sample and reset its verification record
     * so the verification scheduler will pick it up for a full WSI check.
     */
    private function persistRemotePath(Sample $sample, string $remotePath, string $storageDir): void
    {
        $sample->wsi_remote_path = $remotePath;
        $sample->storage_path    = $storageDir;
        $sample->storage_status  = 'available';  // ← clear any prior 'corrupted' / 'upload_failed'
        $sample->save();

        // Reset verification AND immediately fix file_path so the "File exists"
        // check passes right away — without waiting for the next full re-run.
        // Previously, file_path stayed NULL (set to NULL when wsi_remote_path was
        // not yet known), causing the check to show "Failed" in the UI even after
        // a successful upload.
        SlideVerification::where('sample_id', $sample->id)
            ->update([
                'file_path'             => $remotePath,   // ← fixes "File exists" instantly
                'open_slide_status'     => 'not_checked',
                'file_integrity_status' => 'not_checked',
                'read_test_status'      => 'not_checked',
                'verification_status'   => 'pending',
                'notes'                 => null,
                'verified_at'           => null,
            ]);

        Log::info("[UploadWsiToDriveJob] Sample #{$sample->id}: wsi_remote_path → {$remotePath}");
    }

    /**
     * Called automatically by Laravel after all $tries are exhausted.
     *
     * Marks storage_status = 'upload_failed' on the sample so:
     *   • SampleObserver won't re-queue it in a loop
     *   • wsi:scan-and-upload skips it (prevents hammering a broken upload)
     *   • The monitoring query  SELECT * FROM samples WHERE storage_status = 'upload_failed'
     *     surfaces it immediately for manual investigation
     */
    public function failed(\Throwable $exception): void
    {
        Log::error(sprintf(
            '[UploadWsiToDriveJob] Sample #%d: ALL retries exhausted — marking storage_status=upload_failed. Error: %s',
            $this->sampleId,
            $exception->getMessage(),
        ));

        Sample::where('id', $this->sampleId)->update(['storage_status' => 'upload_failed']);
    }
}
