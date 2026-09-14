<?php

namespace App\Jobs;

use App\Models\Sample;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Pulls one slide straight from GDC into staging, then hands it to the normal
 * upload path.
 *
 * A whole-slide image is between one and three gigabytes. Sending that through
 * a browser, a TLS termination, nginx's body buffer and PHP's upload handler is
 * the wrong shape of transfer: it has no resume, it holds a request open for
 * the length of the copy, and every hop needs its own raised limit. GDC is
 * already a file server that does this well, so the queue asks it directly and
 * the browser only ever carries a UUID.
 */
class FetchSlideFromGdc implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200;

    /** Anything smaller than this is a truncated download, not a slide. */
    private const MIN_FILE_BYTES = 50 * 1024 * 1024;

    public function __construct(
        public readonly int     $sampleId,
        public readonly string  $fileId,
        public readonly string  $fileName,
        public readonly ?string $expectedMd5 = null,
        public readonly string  $staging = '/var/www/HISTO_AI/tcga_staging',
    ) {
        $this->onQueue('uploads');
    }

    public function handle(): void
    {
        $sample = Sample::find($this->sampleId);
        if (! $sample) {
            return;
        }

        $sample->update(['storage_status' => 'downloading']);
        $target = "{$this->staging}/{$this->fileId}/{$this->fileName}";

        try {
            if (! is_file($target) || filesize($target) < self::MIN_FILE_BYTES) {
                $proc = new Process(
                    ['gdc-client', 'download', $this->fileId, '-d', $this->staging, '--retry-amount', '3'],
                    timeout: 7200,
                );
                $proc->run();

                if (! is_file($target)) {
                    throw new \RuntimeException(
                        'gdc-client did not produce the file: '
                        . trim($proc->getErrorOutput() ?: $proc->getOutput())
                    );
                }
            }

            // The checksum is the only thing proving these bytes are GDC's bytes.
            // A slide that fails here must never reach Drive, because past that
            // point nothing downstream questions it again.
            if ($this->expectedMd5) {
                $actual = md5_file($target);
                if (! hash_equals(strtolower($this->expectedMd5), strtolower((string) $actual))) {
                    @unlink($target);
                    throw new \RuntimeException("checksum mismatch: expected {$this->expectedMd5}, got {$actual}");
                }
            }
        } catch (\Throwable $e) {
            Log::error("[FetchSlideFromGdc] sample #{$this->sampleId}: {$e->getMessage()}");
            $sample->update(['storage_status' => 'corrupted']);
            return;
        }

        $sample->update(['file_name' => $this->fileName, 'storage_status' => 'not_downloaded']);
        ProcessSampleUpload::dispatch($this->sampleId, $target);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[FetchSlideFromGdc] sample #{$this->sampleId} failed: {$e->getMessage()}");
        Sample::where('id', $this->sampleId)->update(['storage_status' => 'corrupted']);
    }
}
