<?php

namespace App\Jobs;

use App\Models\Magnification;
use App\Models\PatchSize;
use App\Models\Sample;
use App\Models\ServerName;
use App\Services\GoogleDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Downloads a WSI from Google Drive, runs the patch_extract.py script
 * to tile the slide, then uploads the resulting patches folder back to
 * Google Drive under the "sliced_slides" root — preserving the same
 * data-source / category / case hierarchy.
 *
 * Pipeline (all on the local / Hostinger server):
 *   1. Download WSI from Google Drive via rclone (GoogleDriveService)
 *   2. Run  scripts/patch_extract.py  →  local temp patches folder
 *   3. rclone copy patches folder → gdrive:sliced_slides/{source}/{cat}/{case}/…
 *   4. Update sample:  tiling_status=done, tile_count, tiles_gdrive_path, etc.
 *   5. Delete local temp directory
 */
class PatchExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Allow 24 hours — large SVS files can take a very long time to download + process. */
    public int $timeout = 86_400;

    /** No automatic retries; the user can re-trigger from the Operations page. */
    public int $tries = 1;

    public function __construct(
        public readonly int $sampleId,
        public readonly int $serverId,
        public readonly int $patchSizeId,
        public readonly int $magnificationId,
    ) {
        $this->onQueue('operations');
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function handle(GoogleDriveService $drive): void
    {
        /** @var Sample $sample */
        $sample       = Sample::with(['dataSource', 'category', 'organ', 'patientCase'])->findOrFail($this->sampleId);
        $server       = ServerName::findOrFail($this->serverId);
        $patchSize    = PatchSize::findOrFail($this->patchSizeId);
        $magnification = Magnification::findOrFail($this->magnificationId);

        Log::info("[PatchExtraction] Starting — Sample #{$this->sampleId} | Server: {$server->name} | Size: {$patchSize->size_px}px | Mag: {$magnification->label}");

        // Mark as processing
        $sample->update([
            'tiling_status'   => 'processing',
            'patch_size_id'   => $this->patchSizeId,
            'patch_server_id' => $this->serverId,
        ]);

        $tempDir = storage_path('app/patch_extraction/' . $this->sampleId . '_' . time());

        try {
            // ── 1. Download WSI ──────────────────────────────────────────────
            $wsiDir      = $tempDir . DIRECTORY_SEPARATOR . 'wsi';
            $localWsi    = $this->downloadWsi($sample, $drive, $wsiDir);
            Log::info("[PatchExtraction] Sample #{$this->sampleId}: WSI ready at {$localWsi}");

            // ── 2. Extract patches ───────────────────────────────────────────
            $patchesDir = $tempDir . DIRECTORY_SEPARATOR . 'patches';
            $result     = $this->runExtraction($localWsi, $patchesDir, $patchSize);
            Log::info("[PatchExtraction] Sample #{$this->sampleId}: {$result['patches_extracted']} patches extracted, {$result['patches_skipped']} skipped");

            // ── 3. Upload patches to Google Drive ────────────────────────────
            $gdrivePath = $this->buildGdrivePath($sample, $patchSize, $magnification);
            $drive->uploadBulkFolder($patchesDir, $gdrivePath);

            // Retrieve the Google Drive folder ID from rclone metadata
            $folderMeta     = $drive->fetchFileMeta($gdrivePath);
            $gdriveFolderId = $folderMeta['ID'] ?? null;

            Log::info("[PatchExtraction] Sample #{$this->sampleId}: uploaded to gdrive:{$gdrivePath} (id={$gdriveFolderId})");

            // ── 4. Persist results ───────────────────────────────────────────
            $sample->update([
                'tiling_status'          => 'done',
                'tile_size_px'           => $patchSize->size_px,
                'tile_count'             => $result['patches_extracted'],
                'tiles_path'             => null,          // temp dir will be deleted below
                'tiles_gdrive_path'      => $gdrivePath,
                'tiles_gdrive_folder_id' => $gdriveFolderId,
                'tiling_completed_at'    => now(),
                'magnification_id'       => $this->magnificationId,
                'patch_size_id'          => $this->patchSizeId,
            ]);

            Log::info("[PatchExtraction] Sample #{$this->sampleId}: completed successfully.");

        } catch (\Throwable $e) {
            $sample->update(['tiling_status' => 'failed']);
            Log::error("[PatchExtraction] Sample #{$this->sampleId} FAILED: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;

        } finally {
            // Always clean up temp files regardless of success/failure
            if (is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Download the WSI file from Google Drive to a local directory.
     *
     * Priority:
     *   1. wsi_remote_path — direct path to the .svs file  (most reliable)
     *   2. storage_path    — only used if it points to the file itself,
     *                        NOT when it is the parent folder
     *   3. file_id         — Drive file-ID fallback
     */
    private function downloadWsi(Sample $sample, GoogleDriveService $drive, string $destDir): string
    {
        $fileName = $sample->file_name ?? 'slide.svs';

        // wsi_remote_path is the actual .svs path — always prefer it
        if ($sample->wsi_remote_path) {
            return $drive->downloadToLocal(
                localDir:   $destDir,
                remotePath: $sample->wsi_remote_path,
                fileName:   $fileName,
            );
        }

        // storage_path may be the parent folder — only use it when its basename
        // looks like a file (has an extension like .svs / .tif / .ndpi)
        if ($sample->storage_path && pathinfo($sample->storage_path, PATHINFO_EXTENSION) !== '') {
            return $drive->downloadToLocal(
                localDir:   $destDir,
                remotePath: $sample->storage_path,
                fileName:   $fileName,
            );
        }

        if ($sample->file_id) {
            return $drive->downloadToLocal(
                localDir: $destDir,
                fileId:   $sample->file_id,
                fileName: $fileName,
            );
        }

        throw new \RuntimeException(
            "Sample #{$sample->id} has no Google Drive source (storage_path / wsi_remote_path / file_id)."
        );
    }

    /**
     * Run  scripts/patch_extract.py  and return the decoded JSON result.
     */
    private function runExtraction(string $wsiPath, string $outputDir, PatchSize $patchSize): array
    {
        $scriptPath = base_path('scripts/patch_extract.py');
        $pythonPath = (string) env('PYTHON_PATH', 'python3');

        $cmd = [
            $pythonPath, $scriptPath,
            '--input',            $wsiPath,
            '--output_dir',       $outputDir,
            '--patch_size',       (string) $patchSize->size_px,
            '--level',            (string) $patchSize->wsi_level,
            '--overlap',          (string) $patchSize->overlap_px,
            '--format',           'png',
            '--tissue_threshold', '0.5',
            '--workers',          (string) max(1, (int) env('PATCH_WORKERS', 2)),
            '--save_coords',                // always write patch_coords.csv
            '--overview',                   // always write overview.png
        ];

        $process = new Process($cmd, base_path(), null, null, 86_400);
        $process->run();

        if (!$process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            throw new \RuntimeException("patch_extract.py failed (exit {$process->getExitCode()}): {$stderr}");
        }

        $json   = trim($process->getOutput());
        $result = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("patch_extract.py returned invalid JSON: {$json}");
        }

        if (isset($result['error'])) {
            throw new \RuntimeException("patch_extract.py error: {$result['error']}");
        }

        return $result;
    }

    /**
     * Build the Google Drive destination path for the patches folder.
     *
     * Pattern:
     *   {root}/sliced_slides/{magnification}/{data_source}/{category}/{case_id}/sample_{id}_{size}px/
     */
    private function buildGdrivePath(Sample $sample, PatchSize $patchSize, Magnification $magnification): string
    {
        $root       = rtrim((string) config('gdrive.root_folder'), '/');
        $magFolder  = $magnification->folder_name;          // e.g. "20x"
        $source     = Str::slug($sample->dataSource?->name ?? 'unknown_source');
        $category   = Str::slug($sample->category?->label_en  ?? 'unknown_category');
        $caseId     = $sample->patientCase?->case_id ?? 'no_case';
        $folderName = "sample_{$sample->id}_{$patchSize->size_px}px";

        return implode('/', [$root, 'sliced_slides', $magFolder, $source, $category, $caseId, $folderName]);
    }

    /** Recursively delete a directory. */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
