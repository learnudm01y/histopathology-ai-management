<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\WsiPreviewJob;
use App\Models\Sample;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

        WsiPreviewJob::dispatch($sample->id, 'preview');

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
     * Generated on-the-fly from cached slide dimensions (no pre-generation needed).
     * OSD fetches individual tiles via dziTileStandard() which proxies to the
     * wsi_tile_server.py Flask process running on 127.0.0.1:8001.
     */
    public function dzi(Sample $sample): Response
    {
        $cacheKey = "wsi_preview:{$sample->id}";
        $data     = Cache::get($cacheKey);

        if (!$data || ($data['status'] ?? '') !== 'ready') {
            abort(404, 'Preview not ready.');
        }

        $w = (int) ($data['wsi_meta']['slide_width']  ?? 0);
        $h = (int) ($data['wsi_meta']['slide_height'] ?? 0);

        if ($w <= 0 || $h <= 0) {
            abort(404, 'Slide dimensions not available in cache.');
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
             . "<Image xmlns=\"http://schemas.microsoft.com/deepzoom/2008\" "
             . "Format=\"jpeg\" Overlap=\"1\" TileSize=\"512\">\n"
             . "  <Size Width=\"{$w}\" Height=\"{$h}\"/>\n"
             . "</Image>\n";

        return response($xml, 200, [
            'Content-Type'  => 'application/xml',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Serve an individual DeepZoom tile on-demand via the wsi_tile_server.py
     * Flask process (127.0.0.1:8001). No pre-generation required — tiles are
     * read directly from the WSI file by OpenSlide, exactly like QuPath does.
     *
     * URL scheme used by OSD's native DZI parser:
     *   slide_files/{level}/{col}_{row}.jpeg
     */
    public function dziTileStandard(Sample $sample, int $level, string $tileFile): Response
    {
        $level = max(0, min($level, 64));

        if (!preg_match('/^(\d+)_(\d+)\.(jpe?g|png)$/i', $tileFile, $m)) {
            abort(404);
        }
        $col = max(0, min((int) $m[1], 100_000));
        $row = max(0, min((int) $m[2], 100_000));

        $data    = Cache::get("wsi_preview:{$sample->id}");
        $wsiPath = $data['wsi_path'] ?? null;

        if (!$wsiPath || !is_file($wsiPath)) {
            abort(404, 'WSI file not available.');
        }

        $this->ensureTileServerRunning();

        $tileUrl = "http://127.0.0.1:8001/tile/{$sample->id}/{$level}/{$col}/{$row}"
                 . '?wsi_path=' . urlencode($wsiPath);

        try {
            $response = Http::timeout(15)->get($tileUrl);
        } catch (\Throwable $e) {
            Log::warning("[WsiPreviewController] Tile server unavailable for sample #{$sample->id}: " . $e->getMessage());
            return $this->_blankTile();
        }

        if (!$response->successful()) {
            return $this->_blankTile();
        }

        return response($response->body(), 200, [
            'Content-Type'   => 'image/jpeg',
            'Content-Length' => (string) strlen($response->body()),
            'Cache-Control'  => 'public, max-age=86400, immutable',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /admin/samples/{sample}/wsi-preview/dzi-tile/{level}/{col}_{row}.{ext}
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Serve an individual DeepZoom tile on-demand (custom URL scheme).
     * Proxies to wsi_tile_server.py exactly like dziTileStandard().
     */
    public function dziTile(Sample $sample, int $level, int $col, int $row, string $ext): Response
    {
        $level = max(0, min($level, 64));
        $col   = max(0, min($col, 100_000));
        $row   = max(0, min($row, 100_000));

        $data    = Cache::get("wsi_preview:{$sample->id}");
        $wsiPath = $data['wsi_path'] ?? null;

        if (!$wsiPath || !is_file($wsiPath)) {
            abort(404, 'WSI file not available.');
        }

        $this->ensureTileServerRunning();

        $tileUrl = "http://127.0.0.1:8001/tile/{$sample->id}/{$level}/{$col}/{$row}"
                 . '?wsi_path=' . urlencode($wsiPath);

        try {
            $response = Http::timeout(15)->get($tileUrl);
        } catch (\Throwable $e) {
            Log::warning("[WsiPreviewController] Tile server unavailable for sample #{$sample->id}: " . $e->getMessage());
            return $this->_blankTile();
        }

        if (!$response->successful()) {
            return $this->_blankTile();
        }

        return response($response->body(), 200, [
            'Content-Type'   => 'image/jpeg',
            'Content-Length' => (string) strlen($response->body()),
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

        // Only delete the temp directory when the preview used a locally
        // downloaded copy (not a FUSE mount path). FUSE paths live outside
        // the app storage directory and must never be deleted.
        $mountRoot   = env('WSI_GDRIVE_MOUNT', '');
        $cachedPath  = $data['wsi_path'] ?? '';
        $isFusePath  = $mountRoot && str_starts_with($cachedPath, rtrim($mountRoot, '/'));

        $tempDir = storage_path("app/wsi_previews/{$sample->id}");
        if (!$isFusePath && is_dir($tempDir)) {
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

    /**
     * Return a 1×1 transparent/grey JPEG so the viewer shows a blank tile
     * instead of crashing when the tile server is temporarily unavailable.
     */
    private function _blankTile(): Response
    {
        // Minimal 1×1 grey JPEG (hard-coded bytes, no PIL/GD dependency).
        static $jpeg = null;
        if ($jpeg === null) {
            // 1×1 white JPEG generated once via base64.
            $jpeg = base64_decode(
                '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFgABAQEAAAAAAAAAAAAAAAAABgUEA//EABwQAAICAwEBAAAAAAAAAAAAAAECAwQREiExBf/aAAgBAQAAPwCwej6tIdoqe5rHqm5pCLuGm8K4JkL1QAAA'
            );
        }
        return response($jpeg, 200, [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Check if the WSI tile server is running on port 8001.
     * If not, attempt to start it automatically and wait up to 8 s.
     */
    private function ensureTileServerRunning(): void
    {
        // Fast path: port is already open.
        if ($this->isTileServerUp()) {
            return;
        }

        $script = base_path('scripts/wsi_tile_server.py');
        if (!is_file($script)) {
            Log::warning('[WsiPreviewController] wsi_tile_server.py not found — tiles will fail.');
            return;
        }

        // Prefer the venv Python (matches the server environment).
        $venvPython = base_path('venv/bin/python3');
        if (PHP_OS_FAMILY === 'Windows') {
            $python = 'python';
            // On Windows, start detached so PHP does not wait for the process.
            $cmd = "start /B \"wsi-tile-server\" python " . escapeshellarg($script);
            pclose(popen($cmd, 'r'));
        } else {
            $python = is_file($venvPython) ? $venvPython : 'python3';
            shell_exec('nohup ' . escapeshellarg($python) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
        }

        Log::info('[WsiPreviewController] Started wsi_tile_server.py — waiting for port 8001…');

        // Poll for up to 8 seconds.
        for ($i = 0; $i < 16; $i++) {
            usleep(500_000); // 0.5 s
            if ($this->isTileServerUp()) {
                Log::info('[WsiPreviewController] Tile server is now up.');
                return;
            }
        }

        Log::warning('[WsiPreviewController] Tile server did not start within 8 s.');
    }

    private function isTileServerUp(): bool
    {
        $sock = @fsockopen('127.0.0.1', 8001, $errno, $errstr, 1);
        if ($sock) {
            fclose($sock);
            return true;
        }
        return false;
    }

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
