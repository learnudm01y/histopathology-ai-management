<?php

namespace App\Observers;

use App\Jobs\DeleteWsiFromDriveJob;
use App\Jobs\RunSlideVerification;
use App\Jobs\UploadWsiToDriveJob;
use App\Models\Sample;
use Illuminate\Support\Facades\Log;

/**
 * SampleObserver — the PERMANENT prevention layer.
 *
 * ════════════════════════════════════════════════════════════════════════
 *  ROOT-CAUSE FIX
 * ════════════════════════════════════════════════════════════════════════
 *
 * The core problem was a DISCONNECT between the GDC download pipeline and
 * the Drive upload pipeline. GDC downloads files to local disk and sets
 * download_completed_at, but nothing automatically triggered the upload.
 *
 * This observer wires them together directly:
 *
 *   GDC sets download_completed_at
 *        │
 *        ▼
 *   SampleObserver.updated()
 *        │
 *        ▼
 *   UploadWsiToDriveJob dispatched (ShouldBeUnique — never duplicated)
 *        │
 *        ▼
 *   wsi_remote_path set on Sample
 *        │
 *        ▼
 *   slide_verifications reset to pending
 *        │
 *        ▼
 *   slides:verify-pending (runs every 2 min) picks up & verifies
 *
 * Belt-and-suspenders: even if this observer somehow misses a sample,
 * wsi:scan-and-upload runs hourly to catch any remaining gaps.
 */
class SampleObserver
{
    /**
     * Fires whenever a Sample row is created.
     * Handles the case where file_id and file_name are set at creation time
     * and download_completed_at is set in the same insert.
     */
    public function created(Sample $sample): void
    {
        if ($this->shouldDispatchUpload($sample)) {
            $this->dispatchUpload($sample, 'created');
        }
    }

    /**
     * Fires whenever a Sample row is updated.
     *
     * Two trigger conditions (either is enough):
     *   A) download_completed_at just changed to a non-null value
     *      → GDC download just finished
     *   B) file_id just set for the first time
     *      → Sample record populated after external download
     */
    public function updated(Sample $sample): void
    {
        $downloadJustCompleted =
            $sample->wasChanged('download_completed_at') &&
            $sample->download_completed_at !== null;

        $fileIdJustSet =
            $sample->wasChanged('file_id') &&
            !empty($sample->file_id);

        if (($downloadJustCompleted || $fileIdJustSet) && $this->shouldDispatchUpload($sample)) {
            // Delay slightly to ensure the GDC client has fully flushed the file to disk
            $delay = $downloadJustCompleted ? 30 : 60;
            $this->dispatchUpload($sample, $downloadJustCompleted ? 'download_completed' : 'file_id_set', $delay);
        }

        $this->reverifyIfCaseInfoChanged($sample);
    }

    /**
     * A slide held in `needs_clinical_info` leaves that state only when its
     * verification is recomputed. Editing the case linkage is exactly the act
     * that is supposed to release it, so re-verify immediately instead of
     * waiting for the scheduler's staleness window.
     */
    private function reverifyIfCaseInfoChanged(Sample $sample): void
    {
        $caseFields = [
            'case_id', 'entity_submitter_id', 'entity_id',
            'data_source_id', 'category_id', 'disease_subtype_id', 'organ_id',
        ];

        if (! $sample->wasChanged($caseFields)) {
            return;
        }

        RunSlideVerification::dispatch($sample->id, false)->delay(now()->addSeconds(5));

        Log::info(sprintf(
            '[SampleObserver] Sample #%d: case linkage changed (%s) — re-verifying.',
            $sample->id,
            implode(', ', array_filter($caseFields, fn ($f) => $sample->wasChanged($f)))
        ));
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * All conditions that must be true before we dispatch an upload job.
     */
    private function shouldDispatchUpload(Sample $sample): bool
    {
        return !empty($sample->file_id)
            && !empty($sample->file_name)
            && empty($sample->wsi_remote_path)   // not already on Drive
            && ($sample->storage_status ?? '') !== 'upload_failed';  // don't re-queue permanently-failed uploads
    }

    private function dispatchUpload(Sample $sample, string $reason, int $delaySecs = 30): void
    {
        UploadWsiToDriveJob::dispatch($sample->id)->delay(now()->addSeconds($delaySecs));

        Log::info(sprintf(
            '[SampleObserver] Sample #%d: dispatched UploadWsiToDriveJob (reason: %s, delay: %ds)',
            $sample->id,
            $reason,
            $delaySecs,
        ));
    }

    /**
     * Fires after a Sample row is deleted.
     *
     * If the sample had a file on Google Drive (wsi_remote_path is set),
     * we queue a job to purge the remote folder so Drive stays clean.
     * The job is fire-and-forget (1 try) — if Drive is unreachable the
     * operator can clean up manually; the DB row is already gone.
     */
    public function deleted(Sample $sample): void
    {
        if (empty($sample->wsi_remote_path)) {
            return;
        }

        DeleteWsiFromDriveJob::dispatch($sample->wsi_remote_path, $sample->id);

        Log::info(sprintf(
            '[SampleObserver] Sample #%d: queued Drive folder deletion for %s',
            $sample->id,
            $sample->wsi_remote_path,
        ));
    }
}
