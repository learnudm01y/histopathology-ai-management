<?php

namespace App\Jobs;

use App\Services\GoogleDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Deletes the Google Drive folder for a WSI file when its Sample record
 * is deleted from the database.
 *
 * Drive folder path is derived from wsi_remote_path:
 *   e.g.  samples/TCGA-BRCA/tumor/8191fa1b-.../TCGA-xxx.svs
 *    →    purge samples/TCGA-BRCA/tumor/8191fa1b-.../
 *
 * Uses rclone purge so the entire per-slide folder is removed cleanly.
 * Only 1 retry — if Drive is unreachable the operator can clean up manually.
 */
class DeleteWsiFromDriveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param  string $remotePath   The full remote FILE path (wsi_remote_path).
     *                              The parent folder is what gets purged.
     * @param  int    $sampleId     For logging only.
     */
    public function __construct(
        public readonly string $remotePath,
        public readonly int    $sampleId,
    ) {
        $this->onQueue('operations');
    }

    public function handle(GoogleDriveService $drive): void
    {
        // Purge the whole per-slide folder (parent of the WSI file).
        // dirname() strips the filename; we get e.g. samples/TCGA-BRCA/tumor/8191fa1b-...
        $folderPath = ltrim(dirname($this->remotePath), '/');

        if (empty($folderPath) || $folderPath === '.') {
            Log::warning("[DeleteWsiFromDriveJob] Sample #{$this->sampleId}: could not derive folder from '{$this->remotePath}' — skipping.");
            return;
        }

        Log::info("[DeleteWsiFromDriveJob] Sample #{$this->sampleId}: purging Drive folder '{$folderPath}'…");

        $success = $drive->deleteRemotePath($folderPath, true);

        if ($success) {
            Log::info("[DeleteWsiFromDriveJob] Sample #{$this->sampleId}: Drive folder deleted successfully.");
        } else {
            Log::error("[DeleteWsiFromDriveJob] Sample #{$this->sampleId}: Drive folder deletion failed — manual cleanup may be needed for '{$folderPath}'.");
        }
    }
}
