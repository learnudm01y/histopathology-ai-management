<?php

namespace App\Jobs;

use App\Models\Sample;
use App\Models\SlideVerification;
use App\Services\GoogleDriveService;
use App\Services\SlideVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Downloads a WSI from Google Drive to temporary local storage, runs the
 * wsi_preview_generate.py inspection pipeline, updates the verification
 * record, and stores the result in cache so the frontend can poll it.
 *
 * Cache key: "wsi_preview:{sample_id}"
 * Cache TTL: 2 hours (covers download + processing time for a large slide).
 *
 * Cache value shape:
 * {
 *   "status": "pending" | "ready" | "error",
 *   "error":  null | string,
 *   "checks": {
 *       "open_slide_status": "passed"|"failed"|"not_checked",
 *       "file_integrity_status": ...,
 *       "read_test_status": ...
 *   },
 *   "thumbnail_url": string|null,   // route to WsiPreviewController@thumbnail
 *   "wsi_meta": { level_count, slide_width, slide_height, mpp_x, mpp_y,
 *                 magnification_power, tissue_area_percent, background_ratio }
 * }
 */
class WsiPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Allow up to 4 hours for very large slides (download + processing). */
    public int $timeout = 14_400;

    /** No automatic retries — user can re-click the button. */
    public int $tries = 1;

    private const CACHE_TTL = 7200; // seconds

    /**
     * 'verify'  — runs openslide_inspect.py only (fast, ~10-30 s).
     *             Triggered by the "Verify Slide" button.
     * 'preview' — runs wsi_preview_generate.py (full: thumbnail + quality
     *             metrics). Triggered by the WSI Preview panel.
     */
    public function __construct(
        public readonly int    $sampleId,
        public readonly string $mode = 'preview',
    ) {
        $this->onQueue('previews');
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function handle(GoogleDriveService $drive): void
    {
        $cacheKey = "wsi_preview:{$this->sampleId}";

        /** @var Sample|null $sample */
        $sample = Sample::find($this->sampleId);
        if (!$sample) {
            $this->_cacheError($cacheKey, 'Sample not found.');
            return;
        }

        // ── Guard: reject if sample is still being uploaded ──────────────────
        // storage_status = 'downloading' means rclone is actively transferring
        // this file to Google Drive. Attempting a preview download at the same
        // time would (a) race against an incomplete remote file and (b) saturate
        // bandwidth, stalling the upload. Fail fast with a clear message.
        if ($sample->storage_status === 'downloading') {
            $this->_cacheError(
                $cacheKey,
                'Upload is still in progress for this sample. Please wait until the upload completes before running a preview.'
            );
            Log::warning("[WsiPreviewJob] Sample #{$this->sampleId} is still uploading — preview aborted.");
            return;
        }

        // ── Resolve remote path ──────────────────────────────────────────────
        $remotePath = $this->resolveRemotePath($sample);
        $fileId     = $sample->file_id ?: null;
        $fileName   = $sample->file_name;

        if (!$remotePath && !$fileId) {
            $this->_cacheError($cacheKey, 'No Google Drive path or file ID available for this sample.');
            return;
        }

        // ── Local temp directory ─────────────────────────────────────────────
        $tempDir = storage_path("app/wsi_previews/{$this->sampleId}");
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // ── Skip download if file already exists locally ─────────────────────
        // Massive speed-up for repeat previews: a fully-downloaded WSI
        // (matching the remote size) is reused instead of re-downloaded.
        $expectedName = $fileName ?: ($remotePath ? basename($remotePath) : null);
        $expectedPath = $expectedName ? ($tempDir . DIRECTORY_SEPARATOR . $expectedName) : null;
        $wsiPath      = null;

        if ($expectedPath && is_file($expectedPath) && filesize($expectedPath) > 0) {
            $localSize  = filesize($expectedPath);
            $remoteSize = (int) ($sample->file_size_bytes ?? 0);
            if ($remoteSize <= 0 || $localSize === $remoteSize) {
                Log::info("[WsiPreviewJob] Sample #{$this->sampleId}: reusing cached local file {$expectedPath} ({$localSize} bytes)");
                $wsiPath = $expectedPath;
            } else {
                Log::info("[WsiPreviewJob] Sample #{$this->sampleId}: local size {$localSize} ≠ remote {$remoteSize}, re-downloading");
            }
        }

        if ($wsiPath === null) {
            Log::info("[WsiPreviewJob] Sample #{$this->sampleId}: downloading WSI to {$tempDir}");
            try {
                $wsiPath = $drive->downloadToLocal(
                    localDir:   $tempDir,
                    remotePath: $remotePath,
                    fileId:     $remotePath ? null : $fileId,
                    fileName:   $fileName,
                    timeout:    12_000,
                );
            } catch (\Throwable $e) {
                Log::error("[WsiPreviewJob] Download failed: " . $e->getMessage());
                $this->_cacheError($cacheKey, 'Download failed: ' . $e->getMessage());
                return;
            }
            Log::info("[WsiPreviewJob] Sample #{$this->sampleId}: download complete → {$wsiPath}");
        }

        // ── Run Python inspection script ─────────────────────────────────────
        // 'verify' mode  → openslide_inspect.py  (fast: checks + metadata only, ~10-30 s)
        // 'preview' mode → wsi_preview_generate.py (full: thumbnail + quality metrics, ~1-2 min)
        $pythonBin = config('app.python_binary', 'python');

        if ($this->mode === 'verify') {
            $scriptPath = base_path('scripts/openslide_inspect.py');
            $process    = new Process([$pythonBin, $scriptPath, $wsiPath]);
            $process->setTimeout(180);
        } else {
            $scriptPath = base_path('scripts/wsi_preview_generate.py');
            $outputDir  = $tempDir . DIRECTORY_SEPARATOR . 'preview_output';
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }
            $process = new Process([$pythonBin, $scriptPath, $wsiPath, $outputDir]);
            $process->setTimeout(3600);
        }
        $process->run();

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());
        $pyData = json_decode($stdout, true);

        if ($stderr !== '') {
            Log::warning("[WsiPreviewJob] Python stderr for sample #{$this->sampleId}: {$stderr}");
        }

        if (!is_array($pyData)) {
            // If 'python' binary failed (not found), retry with 'python3'
            if ($stdout === '' && $pythonBin === 'python') {
                Log::warning("[WsiPreviewJob] Retrying with python3 binary for sample #{$this->sampleId}");
                if ($this->mode === 'verify') {
                    $process2 = new Process(['python3', $scriptPath, $wsiPath]);
                    $process2->setTimeout(180);
                } else {
                    $process2 = new Process(['python3', $scriptPath, $wsiPath, $outputDir ?? '']);
                    $process2->setTimeout(3600);
                }
                $process2->run();
                $stdout = trim($process2->getOutput());
                $stderr2 = trim($process2->getErrorOutput());
                if ($stderr2 !== '') {
                    Log::warning("[WsiPreviewJob] python3 stderr for sample #{$this->sampleId}: {$stderr2}");
                }
                $pyData = json_decode($stdout, true);
            }

            if (!is_array($pyData)) {
                Log::error("[WsiPreviewJob] Could not parse Python output for sample #{$this->sampleId}. stdout=[{$stdout}] stderr=[{$stderr}]");
                $this->_cacheError($cacheKey, 'WSI inspection script returned unexpected output.');
                return;
            }
        }

        // Detect silent Python failures (e.g. missing openslide library)
        if (!empty($pyData['error'])) {
            Log::error("[WsiPreviewJob] Python script error for sample #{$this->sampleId}: {$pyData['error']}");
            $this->_cacheError($cacheKey, $pyData['error']);
            return;
        }

        // ── Update verification record ────────────────────────────────────────
        $verificationData = array_filter([
            'open_slide_status'     => $pyData['open_slide_status']     ?? null,
            'file_integrity_status' => $pyData['file_integrity_status'] ?? null,
            'read_test_status'      => $pyData['read_test_status']      ?? null,
            'level_count'           => $pyData['level_count']           ?? null,
            'slide_width'           => $pyData['slide_width']           ?? null,
            'slide_height'          => $pyData['slide_height']          ?? null,
            'mpp_x'                 => $pyData['mpp_x']                 ?? null,
            'mpp_y'                 => $pyData['mpp_y']                 ?? null,
            'magnification_power'   => $pyData['magnification_power']   ?? null,
            'tissue_area_percent'   => $pyData['tissue_area_percent']   ?? null,
            'tissue_patch_count'    => $pyData['tissue_patch_count']    ?? null,
            'artifact_score'        => $pyData['artifact_score']        ?? null,
            'blur_score'            => $pyData['blur_score']            ?? null,
            'background_ratio'      => $pyData['background_ratio']      ?? null,
            'verified_at'           => now()->toDateTimeString(),
        ], fn($v) => $v !== null);

        // Force the status columns even if null (we must overwrite 'not_checked')
        foreach (['open_slide_status', 'file_integrity_status', 'read_test_status'] as $col) {
            if (isset($pyData[$col])) {
                $verificationData[$col] = $pyData[$col];
            }
        }
        $verification = SlideVerification::updateOrCreate(
            ['sample_id' => $this->sampleId],
            $verificationData,
        );

        // Recompute aggregate verification_status (passed / failed / pending)
        app(SlideVerificationService::class)->recomputeStatus($verification);

        // ── Determine thumbnail relative path for serving ────────────────────
        // Only wsi_preview_generate.py produces a thumbnail (preview mode).
        $thumbAbsPath = $pyData['thumbnail_path'] ?? null;
        $thumbRelPath = null;

        if ($thumbAbsPath && is_file($thumbAbsPath)) {
            $thumbRelPath = 'wsi_previews/' . $this->sampleId . '/preview_output/thumbnail.jpg';
        }

        // In verify mode, keep any existing thumbnail that was previously generated.
        if ($this->mode === 'verify') {
            $existing     = Cache::get($cacheKey);
            $thumbRelPath = $existing['thumb_rel'] ?? $thumbRelPath;
        }

        $hasDimensions = isset($pyData['slide_width'], $pyData['slide_height'])
            && (int) $pyData['slide_width']  > 0
            && (int) $pyData['slide_height'] > 0;

        // ── Cache result for frontend polling ────────────────────────────────
        Cache::put($cacheKey, [
            'status'   => 'ready',
            'error'    => $pyData['error'] ?? null,
            'checks'   => [
                'open_slide_status'     => $pyData['open_slide_status']     ?? 'not_checked',
                'file_integrity_status' => $pyData['file_integrity_status'] ?? 'not_checked',
                'read_test_status'      => $pyData['read_test_status']      ?? 'not_checked',
            ],
            'wsi_meta' => [
                'level_count'           => $pyData['level_count']           ?? null,
                'slide_width'           => $pyData['slide_width']           ?? null,
                'slide_height'          => $pyData['slide_height']          ?? null,
                'mpp_x'                 => $pyData['mpp_x']                 ?? null,
                'mpp_y'                 => $pyData['mpp_y']                 ?? null,
                'magnification_power'   => $pyData['magnification_power']   ?? null,
                'tissue_area_percent'   => $pyData['tissue_area_percent']   ?? null,
                'tissue_patch_count'    => $pyData['tissue_patch_count']    ?? null,
                'artifact_score'        => $pyData['artifact_score']        ?? null,
                'blur_score'            => $pyData['blur_score']            ?? null,
                'background_ratio'      => $pyData['background_ratio']      ?? null,
            ],
            'wsi_path'      => $wsiPath,
            'thumb_rel'     => $thumbRelPath,
            'dzi_available' => $hasDimensions,
        ], self::CACHE_TTL);

        Log::info("[WsiPreviewJob] Sample #{$this->sampleId} ({$this->mode}): inspection complete → "
            . "open={$verificationData['open_slide_status']} "
            . "integrity={$verificationData['file_integrity_status']} "
            . "read={$verificationData['read_test_status']}");
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function failed(\Throwable $exception): void
    {
        $cacheKey = "wsi_preview:{$this->sampleId}";
        $this->_cacheError($cacheKey, $exception->getMessage());
        Log::error("[WsiPreviewJob] Job failed for sample #{$this->sampleId}: " . $exception->getMessage());
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function resolveRemotePath(Sample $sample): ?string
    {
        // Prefer the stored WSI remote path (most specific)
        if ($sample->wsi_remote_path) {
            return $sample->wsi_remote_path;
        }

        // Fall back to storage_path + file_name
        if ($sample->storage_path && $sample->file_name) {
            return rtrim($sample->storage_path, '/') . '/' . $sample->file_name;
        }

        return null;
    }

    private function _cacheError(string $key, string $message): void
    {
        Cache::put($key, [
            'status' => 'error',
            'error'  => $message,
        ], self::CACHE_TTL);
    }
}
