<?php

namespace App\Services;

use App\Models\Sample;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class GoogleDriveService
{
    private readonly string  $rclonePath;
    private readonly string  $rcloneConfig;
    private readonly string  $remoteName;
    private readonly string  $rootFolder;

    public function __construct()
    {
        $this->rclonePath   = config('gdrive.rclone_path');
        $this->rcloneConfig = config('gdrive.rclone_config');
        $this->remoteName   = config('gdrive.remote_name');
        $this->rootFolder   = config('gdrive.root_folder');
    }

    // ── Path Building ────────────────────────────────────────────────────────

    /**
     * Build the remote folder path for a sample (SINGLE file upload).
     *
     * Pattern (WITHOUT USERNAME):
     *   {root}/{data_source}/{category}/{sample_folder}/
     *
     * {sample_folder} = {data_source}-{category}-{sampleName}-{UUID}
     *
     * If entity_submitter_id is provided, it is used as the "sampleName" part.
     * A UUID is always appended to guarantee uniqueness.
     */
    public function buildSampleFolderPath(Sample $sample): string
    {
        $uuid         = (string) Str::uuid();
        $categorySlug = $this->sanitize($sample->category?->label_en ?? 'uncategorized');
        $sampleName   = $sample->entity_submitter_id
            ? $this->sanitize($sample->entity_submitter_id)
            : 'sample';

        // Folder name: {data_source}-{category}-{sampleName}-{UUID}
        // NO USERNAME in folder name anymore
        $dataSourceSlug = $this->sanitize($sample->dataSource?->name ?? 'unknown_source');
        $sampleFolder = implode('-', [
            $dataSourceSlug,
            $categorySlug,
            $sampleName,
            $uuid,
        ]);

        return implode('/', [
            $this->rootFolder,
            $dataSourceSlug,
            $categorySlug,
            $sampleFolder,
        ]);
    }

    /**
     * Build the remote folder path for BULK uploads (TCGA folders).
     * Preserves the original folder structure from the source.
     *
     * Pattern:
     *   {root}/{data_source}/{category}/{original_folder_name}/
     *
     * The original folder structure inside is NOT modified.
     */
    public function buildBulkFolderPath(Sample $sample, string $originalFolderName): string
    {
        $dataSourceSlug = $this->sanitize($sample->dataSource?->name ?? 'unknown_source');
        $categorySlug = $this->sanitize($sample->category?->label_en ?? 'uncategorized');

        return implode('/', [
            $this->rootFolder,
            $dataSourceSlug,
            $categorySlug,
            $this->sanitize($originalFolderName),
        ]);
    }

    // ── Transfer Operations ──────────────────────────────────────────────────

    /**
     * Upload a local file to Google Drive via rclone.
     * The remote folder is created automatically.
     * Returns rclone lsjson metadata for the uploaded file.
     */
    public function uploadLocalFile(string $localPath, string $remoteFolderPath): array
    {
        $this->rclone(['mkdir', "{$this->remoteName}:{$remoteFolderPath}"], timeout: 300);

        $this->rclone([
            'copy',
            $localPath,
            "{$this->remoteName}:{$remoteFolderPath}",
        ], timeout: 7200);

        return $this->fetchFileMeta($remoteFolderPath . '/' . basename($localPath));
    }

    /**
     * Copy a file from a shared Google Drive link (by file ID) to our drive.
     * Uses --drive-root-folder-id so rclone treats the shared file as a root.
     */
    public function copyFromSharedFileId(
        string $fileId,
        string $remoteFolderPath,
        string $fileName
    ): array {
        $this->rclone(['mkdir', "{$this->remoteName}:{$remoteFolderPath}"], timeout: 300);

        $this->rclone([
            'copy',
            "{$this->remoteName}:",                        // root = the shared file ID
            "{$this->remoteName}:{$remoteFolderPath}",
            "--drive-root-folder-id={$fileId}",
        ], timeout: 7200);

        return $this->fetchFileMeta($remoteFolderPath . '/' . $fileName);
    }

    // ── Metadata & Links ─────────────────────────────────────────────────────

    /**
     * Fetch file metadata (Name, Size, MimeType, ID) from a remote path.
     */
    public function fetchFileMeta(string $remoteFilePath): array
    {
        try {
            $out   = $this->rclone(['lsjson', "{$this->remoteName}:{$remoteFilePath}", '--no-traverse']);
            $items = json_decode($out, true);
            return $items[0] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get a shareable link for a file/folder on Google Drive.
     */
    public function getShareableLink(string $remotePath): ?string
    {
        try {
            $out = $this->rclone(['link', "{$this->remoteName}:{$remotePath}"]);
            return trim($out) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Try to determine the file name from a shared Google Drive file ID.
     * Returns null if the metadata cannot be retrieved.
     */
    public function getFileNameFromDriveId(string $fileId): ?string
    {
        return $this->getFileInfoFromDriveId($fileId)['name'];
    }

    /**
     * Return [name, size] for a shared Google Drive file ID.
     * Both values are null if the metadata cannot be retrieved.
     */
    public function getFileInfoFromDriveId(string $fileId): array
    {
        try {
            $out   = $this->rclone([
                'lsjson', "{$this->remoteName}:",
                "--drive-root-folder-id={$fileId}",
                '--no-traverse',
            ]);
            $items = json_decode($out, true);
            return [
                'name' => $items[0]['Name'] ?? null,
                'size' => $items[0]['Size'] ?? null,
            ];
        } catch (\Throwable) {
            return ['name' => null, 'size' => null];
        }
    }

    /**
     * Upload a directory structure (TCGA folder).
     * Preserves the original folder structure without modification.
     * Returns metadata of any WSI files found (.svs, .tiff, .tif).
     */
    public function uploadBulkFolder(string $localFolderPath, string $remoteFolderPath): array
    {
        $this->rclone(['mkdir', "{$this->remoteName}:{$remoteFolderPath}"], timeout: 300);

        $this->rclone([
            'copy',
            $localFolderPath,
            "{$this->remoteName}:{$remoteFolderPath}",
        ], timeout: 7200);

        // Find all WSI files in the uploaded directory
        $wsiFiles = $this->findWsiFilesInFolder($remoteFolderPath);

        return [
            'remote_path' => $remoteFolderPath,
            'wsi_files'   => $wsiFiles,
        ];
    }

    /**
     * Find all WSI files (.svs, .tiff, .tif) recursively in a remote folder.
     *
     * IMPORTANT: Only returns WSI files from the ROOT level of each subfolder,
     * excluding files in 'logs', 'metadata', or similar directories.
     * This ensures we don't pick up .parcel files or other metadata files.
     */
    public function findWsiFilesInFolder(string $remoteFolderPath): array
    {
        try {
            $out = $this->rclone([
                'lsjson',
                "{$this->remoteName}:{$remoteFolderPath}",
                '--recursive',
                '--files-only',
            ]);

            $allFiles = json_decode($out, true) ?? [];
            $wsiFiles = [];
            $wsiExtensions = ['svs', 'tiff', 'tif'];

            foreach ($allFiles as $file) {
                $fileName = $file['Name'] ?? '';
                $filePath = $file['Path'] ?? '';
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                // Only include actual WSI files
                if (!in_array($ext, $wsiExtensions)) {
                    continue;
                }

                // Exclude files nested inside logs/, metadata/, or hidden dirs
                $pathLower = strtolower($filePath);
                if (str_contains($pathLower, '/logs/') ||
                    str_contains($pathLower, '/log/') ||
                    str_contains($pathLower, '/metadata/') ||
                    str_contains($pathLower, '/.') ||
                    str_contains($pathLower, '__pycache__')) {
                    continue;
                }

                // Accept WSI files at depth 1 (file directly in this folder — new per-slide mode)
                // OR depth 2 (uuid-subfolder/file.svs — legacy parent-folder mode)
                $pathParts = explode('/', trim($filePath, '/'));
                if (count($pathParts) === 1 || count($pathParts) === 2) {
                    $wsiFiles[] = [
                        'name' => $fileName,
                        'path' => $filePath,
                        'size' => $file['Size'] ?? null,
                        'id'   => $file['ID'] ?? null,
                    ];
                }
            }

            return $wsiFiles;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[GoogleDriveService] findWsiFilesInFolder error: ' . $e->getMessage());
            return [];
        }
    }

    // ── URL Utilities ────────────────────────────────────────────────────────

    /**
     * Extract Google Drive file ID from a sharing URL.
     *
     * Supports:
     *   https://drive.google.com/file/d/{ID}/view
     *   https://drive.google.com/open?id={ID}
     *   https://docs.google.com/…/d/{ID}/…
     */
    public function extractFileIdFromUrl(string $url): ?string
    {
        if (preg_match('#/(?:file|document|presentation|spreadsheets)/d/([a-zA-Z0-9_-]{10,})#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]{10,})#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    /**
     * Run an rclone command and return stdout.
     *
     * Resilience strategy (cross-platform):
     *   - GODEBUG=netdns=go  : forces rclone (a Go binary) to use Go's built-in
     *     pure DNS resolver instead of the OS resolver. Works on Windows, Linux,
     *     and macOS. Prevents OS-level DNS freezes that produce "getaddrinfow"
     *     or "lookup" errors on Windows and "cgo" errors on Linux.
     *   - --retries 5          : rclone retries on API / HTTP errors.
     *   - --low-level-retries 10 : rclone retries low-level network ops (token fetch, etc.).
     *   - PHP-level retry loop : catches any remaining transient network errors
     *     with a 10-second pause between attempts.
     */
    private function rclone(array $args, int $timeout = 60): string
    {
        $cmd = array_merge(
            [
                $this->rclonePath, '--config', $this->rcloneConfig,
                '--retries', '5',
                '--low-level-retries', '10',
            ],
            $args
        );

        Log::debug('[GoogleDriveService] rclone ' . implode(' ', array_slice($args, 0, 2)));

        $maxAttempts = 3;
        $stderr      = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $process = new Process($cmd);
            $process->setTimeout($timeout);
            $process->run();

            if ($process->isSuccessful()) {
                return $process->getOutput();
            }

            $stderr = trim($process->getErrorOutput());

            $isNetworkError = str_contains($stderr, 'dial tcp')
                || str_contains($stderr, 'lookup ')
                || str_contains($stderr, 'no such host')
                || str_contains($stderr, 'connection reset by peer')
                || str_contains($stderr, 'i/o timeout')
                || str_contains($stderr, 'couldn\'t fetch token')
                || str_contains($stderr, 'TLS handshake timeout')
                || str_contains($stderr, 'EOF');

            if ($isNetworkError && $attempt < $maxAttempts) {
                Log::warning("[GoogleDriveService] Network error on attempt {$attempt}/{$maxAttempts} — retrying in 10 s…");
                sleep(10);
                continue;
            }

            break;
        }

        throw new \RuntimeException('rclone error: ' . $stderr);
    }

    /**
     * Remove characters invalid in Google Drive folder names.
     */
    private function sanitize(string $name): string
    {
        return trim(preg_replace('/[\/\\\\:*?"<>|]+/', '_', $name));
    }
}
