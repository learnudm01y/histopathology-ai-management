<?php

namespace App\Jobs;

use App\Models\Sample;
use App\Services\V2DiagnoseRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Prepare a slide for V2 Diagnose before anyone asks for its analysis: copy
 * it off Drive, tile it, build the view images, contact sheets and density
 * (V2DiagnoseRunner::buildPrepared). A run on it then starts at the analysis.
 *
 * Dispatched when a slide is uploaded for analysis (prepare_on_upload) and by
 * `php artisan v2:prepare`. A slide still on its way in is waited for, like a
 * run waits for it; one a run is already preparing is left to that run.
 */
class PrepareV2Slide implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    /** How long a slide that is still arriving is waited for (~3 h at 2 min). */
    private const MAX_WAITS = 90;

    public function __construct(public readonly int $sampleId, public readonly int $wait = 0)
    {
        $this->onConnection(config('v2_diagnose.queue_connection'));
        $this->onQueue(config('v2_diagnose.queue'));
    }

    /** After an upload meant for analysis, when preparing on upload is on. */
    public static function afterUpload(int $sampleId): void
    {
        if (config('v2_diagnose.prepared.on_upload') && (float) config('v2_diagnose.prepared.max_gb') > 0) {
            self::dispatch($sampleId)->delay(now()->addMinute());
        }
    }

    public function handle(V2DiagnoseRunner $runner): void
    {
        $sample = Sample::find($this->sampleId);
        if (! $sample) {
            return;
        }
        $key = (string) $sample->id;
        if ($runner->prepared($key)) {
            return;
        }
        $wsi = $runner->resolveSampleWsi($sample);
        if (! $wsi) {
            if ($this->wait < self::MAX_WAITS && $sample->storage_status !== 'corrupted') {
                self::dispatch($this->sampleId, $this->wait + 1)->delay(now()->addMinutes(2));
            }
            return;
        }
        $lock = $runner->preparedLock($key);
        if (! $lock->get()) {
            return;                                 // a run or another job is preparing it now
        }
        try {
            if (! $runner->prepared($key)) {
                $t0 = microtime(true);
                $runner->buildPrepared($key, $wsi);
                Log::info("[V2Diagnose] slide #{$sample->id} prepared in advance in " . round(microtime(true) - $t0) . ' s');
            }
        } catch (\Throwable $e) {
            Log::error("[V2Diagnose] preparing slide #{$sample->id} failed: {$e->getMessage()}");
        } finally {
            $lock->release();
        }
        $runner->evictPrepared();
    }
}
