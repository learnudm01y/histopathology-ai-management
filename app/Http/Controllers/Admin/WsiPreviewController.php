<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\WsiPreviewJob;
use App\Models\Sample;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Handles on-demand WSI preview: download → inspect → thumbnail → cleanup.
 *
 * Routes (all inside auth middleware):
 *   POST   admin/samples/{sample}/wsi-preview/start      start download + inspection job
 *   GET    admin/samples/{sample}/wsi-preview/status     poll for job completion
 *   GET    admin/samples/{sample}/wsi-preview/thumbnail  serve the thumbnail image
 *   POST   admin/samples/{sample}/wsi-preview/cleanup    delete temp files + confirm statuses
 */
class WsiPreviewController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // POST  /admin/samples/{sample}/wsi-preview/start
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Validate the sample has a Drive source, set cache to "pending",
     * dispatch the background job and return immediately.
     */
    public function start(Sample $sample): JsonResponse
    {
        // Require that the sample has a reachable Drive source
        if (!$sample->file_id && !$sample->wsi_remote_path && !$sample->storage_path) {
            return response()->json([
                'success' => false,
                'error'   => 'This sample has no Google Drive path or file ID. Upload the slide first.',
            ], 422);
        }

        $cacheKey = "wsi_preview:{$sample->id}";

        // If a previous result is still cached and ready, return it immediately
        // so the user doesn't have to wait for a full re-download.
        $existing = Cache::get($cacheKey);
        if (is_array($existing) && ($existing['status'] ?? '') === 'ready') {
            return response()->json([
                'success'   => true,
                'status'    => 'ready',
                'from_cache'=> true,
            ]);
        }

        // Mark as pending
        Cache::put($cacheKey, ['status' => 'pending'], 7200);

        WsiPreviewJob::dispatch($sample->id);

        Log::info("[WsiPreviewController] Preview job dispatched for sample #{$sample->id}");

        return response()->json([
            'success' => true,
            'status'  => 'pending',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /admin/samples/{sample}/wsi-preview/status
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return current job status from cache.
     *
     * Response shape:
     * {
     *   "status": "pending" | "ready" | "error" | "not_started",
     *   "checks": { open_slide_status, file_integrity_status,
     *               read_test_status },
     *   "wsi_meta": { ... },
     *   "thumbnail_url": string | null,
     *   "error": string | null
     * }
     */
    public function status(Request $request, Sample $sample): JsonResponse
    {
        $cacheKey = "wsi_preview:{$sample->id}";
        $data     = Cache::get($cacheKey);

        if (!$data) {
            return response()->json(['status' => 'not_started']);
        }

        $payload = ['status' => $data['status'] ?? 'pending'];

        if (($data['status'] ?? '') === 'ready') {
            $payload['checks']        = $data['checks']       ?? [];
            $payload['wsi_meta']      = $data['wsi_meta']     ?? [];
            $payload['duplicate_of']  = $data['duplicate_of'] ?? null;
            $payload['error']         = $data['error']        ?? null;
            $payload['thumbnail_url'] = $data['thumb_rel']
                ? route('admin.samples.wsi-preview.thumbnail', $sample, false)
                : null;
            // Use the standard slide.dzi URL so OSD's native DZI parser handles
            // tile URL generation automatically (most reliable approach).
            $payload['dzi_url']       = !empty($data['dzi_available'])
                ? "/admin/samples/{$sample->id}/wsi-preview/slide.dzi"
                : null;
        }

        if (($data['status'] ?? '') === 'error') {
            $payload['error'] = $data['error'] ?? 'Unknown error';
        }

        return response()->json($payload);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /admin/samples/{sample}/wsi-preview/thumbnail
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Serve the thumbnail JPEG from temporary local storage.
     * Uses file_get_contents() for maximum compatibility (PHP built-in server,
     * Windows paths, no X-Sendfile dependency).
     */
    public function thumbnail(Sample $sample): Response
    {
        $cacheKey = "wsi_preview:{$sample->id}";
        $data     = Cache::get($cacheKey);

        $relPath      = $data['thumb_rel'] ?? null;
        $absolutePath = $relPath ? storage_path('app/' . ltrim(str_replace('\\', '/', $relPath), '/')) : null;

        if (!$absolutePath || !file_exists($absolutePath)) {
            abort(404, 'Thumbnail not yet available.');
        }

        $jpeg = file_get_contents($absolutePath);

        return response($jpeg, 200, [
            'Content-Type'   => 'image/jpeg',
            'Content-Length' => (string) strlen($jpeg),
            'Cache-Control'  => 'no-store, no-cache, must-revalidate',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /admin/samples/{sample}/wsi-preview/dzi
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Serve the DeepZoom (DZI) descriptor — the OpenSeadragon entry point.
     * The descriptor declares tile size / overlap / image dimensions; OSD then
     * fetches individual tiles via the dziTile() endpoint.
     */
    public function dzi(Sample $sample): Response
    {
        $absolutePath = storage_path("app/wsi_previews/{$sample->id}/preview_output/dzi/slide.dzi");

        if (!file_exists($absolutePath)) {
            abort(404, 'DeepZoom descriptor not available. Re-run the preview.');
        }

        return response(file_get_contents($absolutePath), 200, [
            'Content-Type'  => 'application/xml',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Serve an individual DeepZoom tile using OSD's standard URL scheme:
     *   slide_files/{level}/{col}_{row}.jpeg
     * This route is used by OSD's native DZI parser (tileSources: 'slide.dzi').
     */
    public function dziTileStandard(Sample $sample, int $level, string $tileFile): Response
    {
        $level = max(0, min($level, 32));

        // tileFile = "0_0.jpeg" or "0_0.jpg" — extract col/row safely
        if (!preg_match('/^(\d+)_(\d+)\.(jpe?g|png)$/i', $tileFile, $m)) {
            abort(404);
        }
        $col = (int) $m[1];
        $row = (int) $m[2];

        $absolutePath = storage_path(
            "app/wsi_previews/{$sample->id}/preview_output/dzi/slide_files/{$level}/{$col}_{$row}.jpg"
        );

        if (!file_exists($absolutePath)) {
            abort(404);
        }

        $bytes = file_get_contents($absolutePath);

        return response($bytes, 200, [
            'Content-Type'   => 'image/jpeg',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control'  => 'public, max-age=86400, immutable',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /admin/samples/{sample}/wsi-preview/dzi-tile/{level}/{col}_{row}.{ext}
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Serve an individual DeepZoom tile (JPEG) at a given pyramid level.
     * Tiles live under preview_output/dzi/slide_files/<level>/<col>_<row>.jpg
     */
    public function dziTile(Sample $sample, int $level, int $col, int $row, string $ext): Response
    {
        // Hard-clamp values to safe ranges (defensive against path traversal)
        $level = max(0, min($level, 32));
        $col   = max(0, min($col, 100_000));
        $row   = max(0, min($row, 100_000));
        $ext   = in_array($ext, ['jpg', 'jpeg', 'png'], true) ? $ext : 'jpg';

        $absolutePath = storage_path(
            "app/wsi_previews/{$sample->id}/preview_output/dzi/slide_files/{$level}/{$col}_{$row}.jpg"
        );

        if (!file_exists($absolutePath)) {
            // Returning 404 lets OpenSeadragon mark the tile as missing without breaking the viewer
            abort(404);
        }

        $bytes = file_get_contents($absolutePath);

        return response($bytes, 200, [
            'Content-Type'   => 'image/jpeg',
            'Content-Length' => (string) strlen($bytes),
            // Tiles never change for a given preview run → aggressive caching is safe.
            'Cache-Control'  => 'public, max-age=86400, immutable',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /admin/samples/{sample}/wsi-preview/cleanup
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Delete temporary WSI + thumbnail files from local storage.
     * The verification record (already updated by the job) is preserved.
     * Cache entry is cleared so a fresh download can be triggered later.
     */
    public function cleanup(Sample $sample): JsonResponse
    {
        $cacheKey = "wsi_preview:{$sample->id}";
        $data     = Cache::get($cacheKey);

        // Delete the entire temp directory for this sample
        $tempDir = storage_path("app/wsi_previews/{$sample->id}");
        if (is_dir($tempDir)) {
            $this->deleteDirectory($tempDir);
            Log::info("[WsiPreviewController] Deleted temp dir for sample #{$sample->id}: {$tempDir}");
        }

        Cache::forget($cacheKey);

        // Return the current verification summary so the UI can refresh badges
        $verification = $sample->slideVerification()->first();
        $verStatus    = $verification?->verification_status ?? 'pending';

        return response()->json([
            'success'             => true,
            'verification_status' => $verStatus,
            'checks'              => $data['checks'] ?? [],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Recursively delete a directory and all its contents. */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
