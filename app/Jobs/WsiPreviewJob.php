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

        // ── Attempt rclone FUSE mount path (on-demand, no full download) ─────
        // When an rclone FUSE mount is active at WSI_GDRIVE_MOUNT, OpenSlide
        // reads only the exact byte ranges it needs for each tile request.
        // rclone VFS caches those ranges on-disk automatically — no full file
        // download is ever triggered by this code path.
        $wsiPath   = null;
        $usingFuse = false;
        $mountRoot = env('WSI_GDRIVE_MOUNT', '');

        if ($mountRoot && $remotePath) {
            // $remotePath is a relative path on the remote (e.g.
            // "samples/TCGA-BRCA/tumor/uuid/file.svs"). Strip any
            // "remote:" prefix that may have been stored.
            $relPath   = ltrim(preg_replace('/^[a-zA-Z0-9_\-]+:/', '', $remotePath), '/');
            $candidate = rtrim($mountRoot, '/') . '/' . $relPath;

            if (is_file($candidate)) {
                $wsiPath   = $candidate;
                $usingFuse = true;
                Log::info("[WsiPreviewJob] Sample #{$this->sampleId}: using FUSE mount at {$candidate} — no download needed");
            } else {
                Log::info("[WsiPreviewJob] Sample #{$this->sampleId}: FUSE path {$candidate} not found, falling back to download");
            }
        }

        // ── Fall back: download from Google Drive ────────────────────────────
        // Used when no FUSE mount is configured or the path is not yet visible
        // on the mount (e.g. file was just uploaded and VFS hasn't seen it yet).
        if ($wsiPath === null) {
            $tempDir = storage_path("app/wsi_previews/{$this->sampleId}");
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // Reuse a fully-downloaded local copy when its size matches remote.
            $expectedName = $fileName ?: ($remotePath ? basename($remotePath) : null);
            $expectedPath = $expectedName ? ($tempDir . DIRECTORY_SEPARATOR . $expectedName) : null;

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
        }

        // ── Run Python inspection script ─────────────────────────────────────
        // BOTH modes start with openslide_inspect.py (fast: ~5-15 s).
        // In 'preview' mode we additionally run wsi_preview_generate.py for
        // the thumbnail and deep metrics — but ONLY AFTER we've already set the
        // cache to 'ready' so the DZI viewer can open without waiting.
        $pythonBin  = config('app.python_binary', PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
        $inspectScript = base_path('scripts/openslide_inspect.py');

        $fastProcess = new Process([$pythonBin, $inspectScript, $wsiPath]);
        $fastProcess->setTimeout(180);
        $fastProcess->run();

        $fastStdout = trim($fastProcess->getOutput());
        $fastStderr = trim($fastProcess->getErrorOutput());

        if ($fastStderr !== '') {
            Log::warning("[WsiPreviewJob] Python stderr for sample #{$this->sampleId}: {$fastStderr}");
        }

        $fastData = json_decode($fastStdout, true);

        // Retry with python3 when 'python' is not found on this system.
        if (!is_array($fastData) && $fastStdout === '' && $pythonBin === 'python') {
            Log::warning("[WsiPreviewJob] Retrying with python3 binary for sample #{$this->sampleId}");
            $retry = new Process(['python3', $inspectScript, $wsiPath]);
            $retry->setTimeout(180);
            $retry->run();
            $fastStdout = trim($retry->getOutput());
            if (($s = trim($retry->getErrorOutput())) !== '') {
                Log::warning("[WsiPreviewJob] python3 stderr for sample #{$this->sampleId}: {$s}");
            }
            $fastData = json_decode($fastStdout, true);
            $pythonBin = 'python3'; // use python3 for subsequent calls too
        }

        if (!is_array($fastData)) {
            Log::error("[WsiPreviewJob] Could not parse openslide_inspect output for sample #{$this->sampleId}. stdout=[{$fastStdout}]");
            $this->_cacheError($cacheKey, 'WSI inspection script returned unexpected output.');
            return;
        }

        if (!empty($fastData['error']) && ($fastData['open_slide_status'] ?? '') === 'failed') {
            Log::error("[WsiPreviewJob] openslide_inspect error for sample #{$this->sampleId}: {$fastData['error']}");
            $this->_cacheError($cacheKey, $fastData['error']);
            return;
        }

        // ── Phase 1: persist fast metadata & mark cache ready ────────────────
        // The DZI viewer can now open. Thumbnail + deep metrics follow below.
        $hasDimensions = isset($fastData['slide_width'], $fastData['slide_height'])
            && (int) $fastData['slide_width']  > 0
            && (int) $fastData['slide_height'] > 0;

        // Keep any existing thumbnail from a previous preview run.
        $existingCache = Cache::get($cacheKey);
        $thumbRelPath  = $existingCache['thumb_rel'] ?? null;

        $buildMeta = fn(array $d) => [
            'level_count'         => $d['level_count']         ?? null,
            'slide_width'         => $d['slide_width']         ?? null,
            'slide_height'        => $d['slide_height']        ?? null,
            'mpp_x'               => $d['mpp_x']               ?? null,
            'mpp_y'               => $d['mpp_y']               ?? null,
            'magnification_power' => $d['magnification_power'] ?? null,
            'tissue_area_percent' => $d['tissue_area_percent'] ?? null,
            'tissue_patch_count'  => $d['tissue_patch_count']  ?? null,
            'artifact_score'      => $d['artifact_score']      ?? null,
            'blur_score'          => $d['blur_score']           ?? null,
            'background_ratio'    => $d['background_ratio']    ?? null,
        ];

        $buildChecks = fn(array $d) => [
            'open_slide_status'     => $d['open_slide_status']     ?? 'not_checked',
            'file_integrity_status' => $d['file_integrity_status'] ?? 'not_checked',
            'read_test_status'      => $d['read_test_status']      ?? 'not_checked',
        ];

        Cache::put($cacheKey, [
            'status'               => 'ready',
            'error'                => $fastData['error'] ?? null,
            'checks'               => $buildChecks($fastData),
            'wsi_meta'             => $buildMeta($fastData),
            'wsi_path'             => $wsiPath,
            'thumb_rel'            => $thumbRelPath,
            'dzi_available'        => $hasDimensions,
            'thumbnail_generating' => ($this->mode === 'preview'), // hint for UI
        ], self::CACHE_TTL);

        // Persist fast check results to DB immediately.
        $this->_persistVerification($fastData, $this->sampleId);

        Log::info("[WsiPreviewJob] Sample #{$this->sampleId} ({$this->mode}): inspection complete → "
            . "open={$fastData['open_slide_status']} "
            . "integrity={$fastData['file_integrity_status']} "
            . "read={$fastData['read_test_status']}");

        // ── Phase 2 (preview mode only): thumbnail + deep metrics ────────────
        // Runs AFTER cache is already 'ready' — the DZI viewer is already open
        // on the user's browser while this runs in the background.
        if ($this->mode === 'preview') {
            $outputDir = storage_path("app/wsi_previews/{$this->sampleId}/preview_output");
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $deepProcess = new Process(
                [$pythonBin, base_path('scripts/wsi_preview_generate.py'), $wsiPath, $outputDir]
            );
            $deepProcess->setTimeout(3600);
            $deepProcess->run();

            $deepStdout = trim($deepProcess->getOutput());
            if (($s = trim($deepProcess->getErrorOutput())) !== '') {
                Log::warning("[WsiPreviewJob] wsi_preview_generate stderr for sample #{$this->sampleId}: {$s}");
            }

            $deepData = json_decode($deepStdout, true);

            if (is_array($deepData)) {
                // Update DB with deep metrics (they supersede the fast values).
                $this->_persistVerification($deepData, $this->sampleId);

                // Determine thumbnail path.
                $thumbAbsPath = $deepData['thumbnail_path'] ?? null;
                $thumbRelPath = ($thumbAbsPath && is_file($thumbAbsPath))
                    ? 'wsi_previews/' . $this->sampleId . '/preview_output/thumbnail.jpg'
                    : ($existingCache['thumb_rel'] ?? null);

                // Merge deep data into the existing 'ready' cache entry.
                $current = Cache::get($cacheKey) ?? [];
                Cache::put($cacheKey, array_merge($current, [
                    'checks'               => $buildChecks($deepData),
                    'wsi_meta'             => $buildMeta($deepData),
                    'thumb_rel'            => $thumbRelPath,
                    'thumbnail_generating' => false,
                ]), self::CACHE_TTL);

                Log::info("[WsiPreviewJob] Sample #{$this->sampleId}: deep inspection complete, thumbnail=" . ($thumbRelPath ? 'yes' : 'no'));
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function failed(\Throwable $exception): void
    {
        $cacheKey = "wsi_preview:{$this->sampleId}";
        $this->_cacheError($cacheKey, $exception->getMessage());
        Log::error("[WsiPreviewJob] Job failed for sample #{$this->sampleId}: " . $exception->getMessage());
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function _persistVerification(array $pyData, int $sampleId): void
    {
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

        // Force status columns (must overwrite 'not_checked').
        foreach (['open_slide_status', 'file_integrity_status', 'read_test_status'] as $col) {
            if (isset($pyData[$col])) {
                $verificationData[$col] = $pyData[$col];
            }
        }

        $verification = SlideVerification::updateOrCreate(
            ['sample_id' => $sampleId],
            $verificationData,
        );

        app(SlideVerificationService::class)->recomputeStatus($verification);
    }

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
