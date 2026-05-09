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
use Illuminate\Support\Facades\DB;
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

            // ── 3. Compress patches into a single archive ─────────────────────
            // Uploading 1 archive file = 1 rclone API call instead of N calls
            // (one per patch file). This is dramatically faster for large slides.
            $archivePath = $tempDir . DIRECTORY_SEPARATOR . 'patches.tar.gz';
            $this->compressPatches($patchesDir, $archivePath);

            // ── 4. Upload archive to Google Drive ────────────────────────────
            $gdrivePath = $this->buildGdrivePath($sample, $patchSize, $magnification);

            // Save the path BEFORE uploading so recovery command can find it
            // even if the PHP process is killed during the upload.
            Sample::where('id', $this->sampleId)->update(['tiles_gdrive_path' => $gdrivePath]);

            Log::info("[PatchExtraction] Sample #{$this->sampleId}: starting upload → gdrive:{$gdrivePath}/patches.tar.gz");

            $fileMeta = $drive->uploadFile($archivePath, $gdrivePath);
            $gdriveFolderId = $fileMeta['ID'] ?? null;

            Log::info("[PatchExtraction] Sample #{$this->sampleId}: upload complete → gdrive:{$gdrivePath}/patches.tar.gz (id={$gdriveFolderId})");

            // ── 4. Persist results ───────────────────────────────────────────
            // Reconnect the DB in case the connection went stale during the
            // long-running upload (large slides can take 10+ minutes).
            DB::reconnect();

            Sample::where('id', $this->sampleId)->update([
                'tiling_status'          => 'done',
                'tile_size_px'           => $patchSize->size_px,
                'tile_count'             => $result['patches_extracted'],
                'tiles_path'             => null,
                'tiles_gdrive_path'      => $gdrivePath,
                'tiles_gdrive_folder_id' => $gdriveFolderId,
                'tiling_completed_at'    => now(),
                'magnification_id'       => $this->magnificationId,
                'patch_size_id'          => $this->patchSizeId,
            ]);

            Log::info("[PatchExtraction] Sample #{$this->sampleId}: completed successfully.");

        } catch (\Throwable $e) {
            try {
                DB::reconnect();
                Sample::where('id', $this->sampleId)->update(['tiling_status' => 'failed']);
            } catch (\Throwable $dbErr) {
                Log::error("[PatchExtraction] Sample #{$this->sampleId}: could not mark as failed in DB: {$dbErr->getMessage()}");
            }
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

        // Pre-flight: make sure the Python interpreter actually runs.
        // Cross-platform: just try `<python> --version` and check exit code.
        // On Hostinger / shared hosts, `python3` may not be on PATH and
        // PYTHON_PATH must point to a virtualenv (e.g. /home/.../venv/bin/python).
        try {
            $verCheck = new Process([$pythonPath, '--version']);
            $verCheck->setTimeout(15);
            $verCheck->run();
            $verOk = $verCheck->isSuccessful();
        } catch (\Throwable $e) {
            $verOk = false;
        }
        if (!$verOk) {
            throw new \RuntimeException(
                "Python interpreter not runnable: '{$pythonPath}'. " .
                "Set PYTHON_PATH in .env to the absolute path of a Python with " .
                "openslide-python, opencv-python-headless, numpy and Pillow installed."
            );
        }

        // Pre-flight: make sure the script file is readable.
        if (!is_file($scriptPath) || !is_readable($scriptPath)) {
            throw new \RuntimeException("patch_extract.py not found or not readable at: {$scriptPath}");
        }

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

        // Force unbuffered Python so we get logs immediately and JSON gets flushed
        // before exit even when the process is killed (OOM etc.).
        $env = ['PYTHONUNBUFFERED' => '1', 'PYTHONIOENCODING' => 'UTF-8'];

        $process = new Process($cmd, base_path(), $env, null, 86_400);
        $process->run();

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());

        // The Python script ALWAYS prints a JSON line to stdout — both on
        // success (the result dict) AND on failure (`{"error": "..."}`).
        // Try to parse stdout first regardless of exit code, because that
        // contains the most useful diagnostic.
        $parsed = null;
        if ($stdout !== '') {
            // Stdout may have multiple lines (e.g. progress noise) — only the
            // last non-empty line is guaranteed to be the JSON summary.
            $lines = preg_split('/\r?\n/', $stdout);
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = trim($lines[$i]);
                if ($line === '') {
                    continue;
                }
                $tryDecode = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($tryDecode)) {
                    $parsed = $tryDecode;
                    break;
                }
            }
        }

        if (!$process->isSuccessful()) {
            // Build the most informative error message we can:
            //   1. error JSON from stdout (script-reported reason — best)
            //   2. stderr tail (Python traceback / log output)
            //   3. exit code
            $reason = $parsed['error']
                ?? ($stderr !== '' ? $stderr : 'No output captured from script');

            // Trim very long traces
            if (strlen($reason) > 2000) {
                $reason = substr($reason, 0, 2000) . '… [truncated]';
            }

            Log::error('[PatchExtraction] Script failure diagnostics', [
                'exit_code' => $process->getExitCode(),
                'stdout'    => $stdout,
                'stderr'    => $stderr,
                'cmd'       => $cmd,
            ]);

            throw new \RuntimeException(
                "patch_extract.py failed (exit {$process->getExitCode()}): {$reason}"
            );
        }

        if (!is_array($parsed)) {
            throw new \RuntimeException(
                "patch_extract.py returned invalid JSON. stdout='{$stdout}' stderr='{$stderr}'"
            );
        }

        if (isset($parsed['error'])) {
            throw new \RuntimeException("patch_extract.py error: {$parsed['error']}");
        }

        return $parsed;
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

    /**
     * Compress the patches directory into a single tar.gz archive.
     * Uses system tar on Linux (fast), PharData fallback on Windows.
     */
    private function compressPatches(string $patchesDir, string $archivePath): void
    {
        Log::info("[PatchExtraction] Sample #{$this->sampleId}: compressing patches…");

        if (PHP_OS_FAMILY !== 'Windows') {
            $process = new Process(['tar', '-czf', $archivePath, '-C', $patchesDir, '.']);
            $process->setTimeout(600);
            $process->mustRun();
        } else {
            // Windows fallback (local dev only)
            $tarPath = str_replace('.tar.gz', '.tar', $archivePath);
            $phar = new \PharData($tarPath);
            $phar->buildFromDirectory($patchesDir);
            $phar->compress(\Phar::GZ);
            @unlink($tarPath);
        }

        $sizeMb = round(filesize($archivePath) / 1024 / 1024, 1);
        Log::info("[PatchExtraction] Sample #{$this->sampleId}: archive ready ({$sizeMb} MB)");
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
