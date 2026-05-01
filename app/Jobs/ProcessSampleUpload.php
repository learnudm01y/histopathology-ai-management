<?php

namespace App\Jobs;

use App\Models\Sample;
use App\Services\CaseLinker;
use App\Services\GoogleDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSampleUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Dedicated queue — runs on the 'uploads' worker, kept separate from
     * 'previews' so preview downloads never compete for the same worker
     * (and therefore never saturate the same network connection).
     */


    /**
     * Max time (seconds) allowed for a single upload — 4 hours for large WSI files.
     */
    public int $timeout = 14400;

    /**
     * Retry up to 3 times on transient failures (network, DNS, etc.).
     */
    public int $tries = 3;

    /**
     * Seconds to wait before each retry: 1 min, 5 min, 15 min.
     */
    public array $backoff = [60, 300, 900];

    /**
     * @param int         $sampleId         ID of the Sample record to update.
     * @param string|null $tempFilePath      Absolute path of the temp file (Method 1: single upload).
     * @param string|null $gdriveFileId      Shared Drive file ID (Method 2: Google Drive link).
     * @param string|null $gdriveFileName    Original file name resolved from Drive (Method 2).
     * @param string|null $bulkFolderPath    Local folder path for bulk upload (Method 3: TCGA folders).
     * @param string|null $bulkFolderName    Original folder name for bulk upload.
     */
    public function __construct(
        public readonly int     $sampleId,
        public readonly ?string $tempFilePath    = null,
        public readonly ?string $gdriveFileId    = null,
        public readonly ?string $gdriveFileName  = null,
        public readonly ?string $bulkFolderPath  = null,
        public readonly ?string $bulkFolderName  = null,
        public readonly bool    $deleteSource    = true,
    ) {
        $this->onQueue('uploads');
    }

    public function handle(GoogleDriveService $drive): void
    {
        /** @var Sample $sample */
        $sample = Sample::with(['dataSource', 'category'])->findOrFail($this->sampleId);

        // Mark as transferring
        $sample->update(['storage_status' => 'downloading']);
        Log::info("[ProcessSampleUpload] Starting upload for sample #{$this->sampleId}");

        // ── Pre-flight connectivity check ────────────────────────────────────
        if (!$this->isInternetReachable()) {
            $sample->update(['storage_status' => 'corrupted']);
            $msg = 'No internet connectivity — cannot reach googleapis.com. Check your network and use Retry Upload.';
            Log::error("[ProcessSampleUpload] ❌ Sample #{$this->sampleId}: {$msg}");
            $this->fail(new \RuntimeException($msg));
            return;
        }

        try {
            // ── Method 1: Single file upload ─────────────────────────────────
            if ($this->tempFilePath && file_exists($this->tempFilePath)) {
                Log::info("[ProcessSampleUpload] ⬆️ Method 1: Uploading single file from temp storage");

                $folderPath = $drive->buildSampleFolderPath($sample);
                $meta       = $drive->uploadLocalFile($this->tempFilePath, $folderPath);
                $fileName   = $meta['Name'] ?? basename($this->tempFilePath);

                if ($this->deleteSource) {
                    @unlink($this->tempFilePath);
                }

                $wsiPath   = $folderPath . '/' . $fileName;

                $sample->update([
                    'file_id'         => $meta['ID'] ?? null,
                    'file_name'       => $fileName,
                    'data_format'     => strtoupper(pathinfo($fileName, PATHINFO_EXTENSION)) ?: 'UNKNOWN',
                    'file_size_bytes' => $meta['Size'] ?? null,
                    'file_size_gb'    => isset($meta['Size']) ? round($meta['Size'] / 1_073_741_824, 3) : null,
                    'storage_path'    => $folderPath,
                    'wsi_remote_path' => $wsiPath,
                    'upload_type'     => 'single',
                    'storage_link'    => $meta['URL'] ?? null,
                    'storage_status'  => 'available',
                    'download_completed_at' => now(),
                ]);

                Log::info("[ProcessSampleUpload] ✅ Sample #{$this->sampleId} (single) uploaded → {$wsiPath}");

            // ── Method 2: Copy from shared Drive link ────────────────────────
            } elseif ($this->gdriveFileId) {
                Log::info("[ProcessSampleUpload] ⬆️ Method 2: Copying from Google Drive shared link (ID: {$this->gdriveFileId})");

                $folderPath = $drive->buildSampleFolderPath($sample);
                $fileName   = $this->gdriveFileName ?? 'unknown_file';
                $meta       = $drive->copyFromSharedFileId($this->gdriveFileId, $folderPath, $fileName);
                $fileName   = $meta['Name'] ?? $fileName;

                $shareLink = $drive->getShareableLink($folderPath . '/' . $fileName);
                $wsiPath   = $folderPath . '/' . $fileName;

                $sample->update([
                    'file_id'         => $meta['ID'] ?? null,
                    'file_name'       => $fileName,
                    'data_format'     => strtoupper(pathinfo($fileName, PATHINFO_EXTENSION)) ?: 'UNKNOWN',
                    'file_size_bytes' => $meta['Size'] ?? null,
                    'file_size_gb'    => isset($meta['Size']) ? round($meta['Size'] / 1_073_741_824, 3) : null,
                    'storage_path'    => $folderPath,
                    'wsi_remote_path' => $wsiPath,
                    'upload_type'     => 'single',
                    'storage_link'    => $shareLink,
                    'storage_status'  => 'available',
                    'download_completed_at' => now(),
                ]);

                Log::info("[ProcessSampleUpload] ✅ Sample #{$this->sampleId} (Google Drive) uploaded → {$wsiPath}");

            // ── Method 3: Bulk folder upload (TCGA) ──────────────────────────
            } elseif ($this->bulkFolderPath && $this->bulkFolderName) {
                Log::info("[ProcessSampleUpload] ⬆️ Method 3: Bulk folder upload → {$this->bulkFolderName}");

                if (!is_dir($this->bulkFolderPath)) {
                    throw new \RuntimeException("Bulk folder path does not exist: {$this->bulkFolderPath}");
                }

                $bulkRemotePath = $drive->buildBulkFolderPath($sample, $this->bulkFolderName);
                Log::info("[ProcessSampleUpload] 📁 Uploading to: {$bulkRemotePath}");

                // Upload the entire folder structure
                try {
                    $uploadResult = $drive->uploadBulkFolder($this->bulkFolderPath, $bulkRemotePath);
                } catch (\Throwable $e) {
                    throw new \RuntimeException("Failed to upload bulk folder to Google Drive: " . $e->getMessage());
                }

                $wsiFiles = $uploadResult['wsi_files'] ?? [];
                Log::info("[ProcessSampleUpload] 🔍 Found " . count($wsiFiles) . " WSI file(s) in uploaded folder");

                if (empty($wsiFiles)) {
                    throw new \RuntimeException("No WSI files (.svs, .tiff, .tif) found in uploaded folder {$this->bulkFolderName}. Please ensure files are in the root directory of each UUID subfolder, not in 'logs' or other directories.");
                }

                // Use the first (primary) WSI file for this sample
                $primaryFile = reset($wsiFiles);

                if (!$primaryFile) {
                    throw new \RuntimeException("Could not select primary WSI file from bulk upload.");
                }

                $wsiFileName = $primaryFile['name'] ?? 'Unknown WSI File';
                $wsiPath     = $bulkRemotePath . '/' . ($primaryFile['path'] ?? '');
                $driveFileId = $primaryFile['id'] ?? null;

                Log::info("[ProcessSampleUpload] 📄 Primary WSI: {$wsiFileName}");

                try {
                    $shareLink = $drive->getShareableLink($wsiPath);
                } catch (\Throwable $e) {
                    Log::warning("[ProcessSampleUpload] ⚠️ Could not generate share link for {$wsiPath}: " . $e->getMessage());
                    $shareLink = null;
                }

                // Preserve the GDC file UUID in file_id if already set by manifest/bulk-upload init.
                // Store the actual Google Drive file ID in gdrive_source_id.
                $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
                $hasGdcUuid  = $sample->file_id && preg_match($uuidPattern, $sample->file_id);

                $updates = [
                    'gdrive_source_id'          => $driveFileId,
                    'file_name'                 => $wsiFileName,
                    'data_format'               => strtoupper(pathinfo($wsiFileName, PATHINFO_EXTENSION)) ?: 'SVS',
                    'file_size_bytes'            => $primaryFile['size'] ?? null,
                    'file_size_gb'              => isset($primaryFile['size']) ? round($primaryFile['size'] / 1_073_741_824, 3) : null,
                    'storage_path'              => $bulkRemotePath,
                    'wsi_remote_path'           => $wsiPath,
                    'upload_type'               => 'bulk',
                    'bulk_folder_original_path' => $this->bulkFolderName,
                    'storage_link'              => $shareLink,
                    'storage_status'            => 'available',
                    'download_completed_at'     => now(),
                ];

                // Only write Google Drive ID to file_id if we do not already have a GDC UUID there.
                // This ensures manifest/metadata-linked samples keep their GDC file_id for querying.
                if (!$hasGdcUuid) {
                    $updates['file_id'] = $driveFileId;
                }

                $sample->update($updates);

                // Do NOT delete the bulk folder — it's the user's original local folder
                Log::info("[ProcessSampleUpload] ✅ Sample #{$this->sampleId} (bulk) uploaded → {$wsiPath}");
            }

            // ── Force-link slide → clinical case (TCGA submitter matching) ─────────
            // file_name may have just changed for single/gdrive uploads, so do this
            // AFTER the metadata writes above. Idempotent — noop if already linked.
            app(CaseLinker::class)->linkSampleToCase($sample->fresh());

        } catch (\Throwable $e) {
            // Clean up only temp files (Method 1 single upload temp)
            if ($this->deleteSource && $this->tempFilePath && file_exists($this->tempFilePath)) {
                @unlink($this->tempFilePath);
            }
            // NOTE: do NOT delete bulkFolderPath — it's the user's original local folder

            $sample->update(['storage_status' => 'corrupted']);

            $errorMsg = $e->getMessage();
            Log::error("[ProcessSampleUpload] Failed for sample #{$this->sampleId}: {$errorMsg}");

            $this->fail($e);
        }
    }

    /**
     * Quick connectivity check before running rclone.
     * Uses a direct TCP connection to bypass the OS DNS resolver entirely.
     * Works on all platforms (Windows, Linux, macOS).
     */
    private function isInternetReachable(): bool
    {
        // TCP connection to Google's public DNS — no OS DNS resolver involved
        $sock = @fsockopen('8.8.8.8', 53, $errno, $errstr, 5);
        if ($sock) {
            fclose($sock);
            return true;
        }

        // Fallback: try HTTPS port on a known stable IP
        $sock = @fsockopen('142.250.185.78', 443, $errno, $errstr, 5);
        if ($sock) {
            fclose($sock);
            return true;
        }

        return false;
    }

    /**
     * Recursively delete a directory and all its contents.
     */
    private function recursiveDelete(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }

        $files = @scandir($path);
        if ($files) {
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $this->recursiveDelete($path . '/' . $file);
                }
            }
        }

        @rmdir($path);
    }
}
