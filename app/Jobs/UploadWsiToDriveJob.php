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
 * Trigger points:
 *   • ScanAndUploadMissing command (scheduled hourly) — bulk discovery
 *   • Anywhere else in the codebase when a new GDC download completes
 *
 * ShouldBeUnique ensures only ONE upload job per sampleId is ever in
 * the queue at a time, preventing race conditions and duplicate uploads.
 */
class UploadWsiToDriveJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 2 hours — enough for even the largest 4-GB WSI files on a slow link. */
    public int $timeout = 7200;

    /** Retry once after a transient network failure. */
    public int $tries = 2;

    /** Wait 60 s before the retry (token refresh, network recovery). */
    public int $backoff = 60;

    /** ShouldBeUnique lock lives for 3 hours (covers upload + retry window). */
    public int $uniqueFor = 10800;

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
        $sample = Sample::find($this->sampleId);

        if (!$sample) {
            Log::warning("[UploadWsiToDriveJob] Sample #{$this->sampleId} not found — skipping.");
            return;
        }

        // ── Already uploaded? ─────────────────────────────────────────────────
        if (!empty($sample->wsi_remote_path)) {
            Log::info("[UploadWsiToDriveJob] Sample #{$this->sampleId} already has wsi_remote_path — nothing to do.");
            return;
        }

        if (empty($sample->file_id) || empty($sample->file_name)) {
            Log::warning("[UploadWsiToDriveJob] Sample #{$this->sampleId}: missing file_id or file_name — cannot locate local file.");
            return;
        }

        // ── Locate local file ─────────────────────────────────────────────────
        // GDC download client stores files as:
        //   {LOCAL_BASE}/{file_id}/{file_name}
        $localBase = rtrim(config('gdrive.local_wsi_base', env('HISTO_AI_LOCAL_BASE', '/var/www/HISTO_AI/ameer')), '/');
        $localDir  = $localBase . '/' . $sample->file_id;
        $localPath = $localDir  . '/' . $sample->file_name;

        if (!is_file($localPath)) {
            Log::warning("[UploadWsiToDriveJob] Sample #{$this->sampleId}: local file not found at {$localPath}.");
            return;
        }

        // Skip files that are still being downloaded (.parcel companion = incomplete)
        $parcelFile = $localDir . '/logs/' . $sample->file_name . '.parcel';
        if (is_file($parcelFile)) {
            $parcelAge = time() - filemtime($parcelFile);
            if ($parcelAge < 3600) {
                // Modified within the last hour — download still in progress
                Log::info("[UploadWsiToDriveJob] Sample #{$this->sampleId}: .parcel file is fresh ({$parcelAge}s) — download still in progress, will retry later.");
                $this->release(600); // re-queue in 10 minutes
                return;
            }
        }

        // ── Resolve Drive destination ─────────────────────────────────────────
        // Pattern: {root_folder}/TCGA-BRCA/{tumor|normal}/{file_id}/{file_name}
        // The TCGA-BRCA and tumor/normal parts are derived from the barcode.
        $driveType    = $this->resolveDriveType($sample->file_name, $sample->entity_submitter_id ?? '');
        $driveProject = $this->resolveProjectSlug($sample);
        $remoteFolderPath = implode('/', [
            rtrim(config('gdrive.root_folder', 'samples'), '/'),
            $driveProject,
            $driveType,
            $sample->file_id,
        ]);
        $remotePath = $remoteFolderPath . '/' . $sample->file_name;

        // ── Check if already on Drive (idempotent) ────────────────────────────
        $meta = $drive->fetchFileMeta($remotePath);
        if (!empty($meta['Name'])) {
            Log::info("[UploadWsiToDriveJob] Sample #{$this->sampleId}: file already exists on Drive at {$remotePath} — updating DB only.");
            $this->persistRemotePath($sample, $remotePath, $remoteFolderPath . '/');
            return;
        }

        // ── Upload ────────────────────────────────────────────────────────────
        Log::info("[UploadWsiToDriveJob] Sample #{$this->sampleId}: uploading {$sample->file_name} → {$remotePath}");

        $drive->uploadLocalFile($localPath, $remoteFolderPath);

        Log::info("[UploadWsiToDriveJob] Sample #{$this->sampleId}: upload complete.");

        $this->persistRemotePath($sample, $remotePath, $remoteFolderPath . '/');
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Determine whether the WSI belongs to the tumor or normal Drive subfolder.
     *
     * TCGA sample-type code is the 4th dash-delimited segment of the barcode:
     *   TCGA-XX-XXXX-[01A]-01-BSA.uuid.svs   → 01x = Primary Tumor
     *   TCGA-XX-XXXX-[11B]-02-TSB.uuid.svs   → 11x = Solid Normal Tissue
     *   01–09  = tumour variants
     *   10–19  = normal variants
     */
    private function resolveDriveType(string $fileName, string $submitterId): string
    {
        // Prefer entity_submitter_id (the TCGA slide barcode) if available
        $barcode = $submitterId ?: (strstr($fileName, '.', true) ?: $fileName);
        $parts   = explode('-', $barcode);
        $typeCode = $parts[3] ?? '';

        if ($typeCode !== '') {
            $numeric = (int) substr($typeCode, 0, 2);
            return $numeric >= 10 ? 'normal' : 'tumor';
        }

        // Fallback: look for common TCGA normal indicators in filename
        if (preg_match('/-1[0-9][A-Z]-/', $fileName)) {
            return 'normal';
        }

        return 'tumor';
    }

    /**
     * Resolve the project slug for the Drive path (e.g. "TCGA-BRCA").
     * Uses the sample's project_id when available; falls back to "TCGA-BRCA".
     */
    private function resolveProjectSlug(Sample $sample): string
    {
        // project_id on Sample (e.g. "TCGA-BRCA")
        if (!empty($sample->project_id)) {
            return preg_replace('/[^a-zA-Z0-9\-_]/', '_', $sample->project_id);
        }

        // Derive from entity_submitter_id / file_name: "TCGA-XX-..." → "TCGA-XX"
        $barcode = $sample->entity_submitter_id ?: strstr($sample->file_name, '.', true) ?: '';
        $parts   = explode('-', $barcode);
        if (count($parts) >= 2 && strtoupper($parts[0]) === 'TCGA') {
            // Map TCGA project code from BRCA barcode prefix
            // For now return the configured default — extend this map as needed
            return config('gdrive.tcga_project', 'TCGA-BRCA');
        }

        return 'unknown_project';
    }

    /**
     * Set wsi_remote_path on the Sample and reset its verification record
     * so the verification scheduler will pick it up for a full WSI check.
     */
    private function persistRemotePath(Sample $sample, string $remotePath, string $storageDir): void
    {
        $sample->wsi_remote_path = $remotePath;
        $sample->storage_path    = $storageDir;
        $sample->save();

        // Reset verification so WsiPreviewJob (verify mode) can now run the
        // full OpenSlide check that was previously skipped due to 404.
        SlideVerification::where('sample_id', $sample->id)
            ->update([
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
