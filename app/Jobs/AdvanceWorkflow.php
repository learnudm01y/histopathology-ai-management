<?php

namespace App\Jobs;

use App\Models\Sample;
use App\Models\ServerName;
use App\Services\DiagnosisWorkflow;
use App\Services\OperationDispatcher;
use App\Services\PodLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Walks one slide from wherever it is to an answer, without a human clicking.
 *
 * This is a supervisor, not a chain. It does not hook into the ends of
 * PatchExtractionJob or FeatureExtractionJob — those run for bulk operations
 * too, and threading per-slide follow-up logic through them would put this
 * page's concerns inside a path that a hundred other slides share. Instead it
 * reads the slide's own state, queues the single step that unblocks it, and
 * re-queues itself to look again a little later.
 *
 * That makes it safe to lose. If the worker restarts mid-chain the slide is
 * left in a valid state and re-running the job picks up exactly where it was,
 * because nothing is remembered between ticks except what the slide itself
 * records.
 */
class AdvanceWorkflow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How long to wait between looks. Patch extraction takes minutes. */
    private const TICK_SECONDS = 20;

    /** servers_names.id of the RunPod TITAN box that produces the features. */
    private const TITAN_SERVER_ID = 3;

    /** Give up after this many ticks (~2h) so a stuck slide cannot loop forever. */
    private const MAX_TICKS = 360;

    public function __construct(
        public readonly int $sampleId,
        public readonly string $modelKey,
        public readonly int $tick = 0,
    ) {
        $this->onQueue('operations');
    }

    public static function stateKey(int $sampleId): string
    {
        return "wf:chain:{$sampleId}";
    }

    public static function resultKey(int $sampleId): string
    {
        return "wf:prediction:{$sampleId}";
    }

    /**
     * Record that a chain is on its way, before any worker has picked it up.
     *
     * Without this the page has no way to tell "queued and about to start" from
     * "nothing is happening", and those two look identical on a slide whose
     * every column still reads pending — which is precisely the moment someone
     * is watching hardest.
     */
    public static function markQueued(int $sampleId): void
    {
        Cache::put(self::stateKey($sampleId), [
            'status'  => 'running',
            'message' => 'Queued — waiting for a worker.',
            'tick'    => -1,
            'at'      => now()->toDateTimeString(),
        ], now()->addDays(7));
    }

    public function handle(DiagnosisWorkflow $workflow, OperationDispatcher $dispatcher): void
    {
        $sample = Sample::find($this->sampleId);
        if (! $sample) {
            return;
        }

        $model = config("diagnosis_models.models.{$this->modelKey}");
        if (! $model) {
            $this->stop('No such model is registered: ' . $this->modelKey);
            return;
        }

        if ($this->tick >= self::MAX_TICKS) {
            $this->stop('Gave up waiting — the slide did not finish within two hours.');
            return;
        }

        // A failed step is terminal. Retrying it automatically would just burn
        // the same compute against the same broken input.
        foreach ([
            'storage_status'            => ['corrupted', 'The slide file could not be stored.'],
            'tiling_status'             => ['failed', 'Patch extraction failed for this slide.'],
            'feature_extraction_status' => ['failed', 'Feature extraction failed for this slide.'],
        ] as $column => [$badValue, $message]) {
            if ($sample->{$column} === $badValue) {
                $this->stop($message);
                return;
            }
        }

        $state = $workflow->inspect($sample, $model);

        // Requirements that no amount of waiting will satisfy.
        if (! empty($state['requirement_failures'])) {
            $this->stop('The slide does not meet what the model needs: '
                . implode('; ', $state['requirement_failures']));
            return;
        }

        if ($state['stage'] === DiagnosisWorkflow::STAGE_READY) {
            $result = $workflow->predict($sample, $model);
            if (isset($result['error'])) {
                $this->stop($result['error']);
                return;
            }
            $result['model_key']   = $this->modelKey;
            $result['model_label'] = $model['label'] ?? $this->modelKey;
            Cache::put(self::resultKey($this->sampleId), $result, now()->addDays(7));
            $this->note('done', 'Finished.');
            $this->releaseTheGpu();
            return;
        }

        // Something is already running: look again rather than queue it twice.
        $running = $sample->storage_status === 'downloading'
                || $sample->tiling_status === 'processing'
                || $sample->feature_extraction_status === 'processing';

        $message = $state['blocking'] ?? 'Working.';

        if (! $running) {
            if ($state['next_action'] === 'features') {
                // Feature extraction needs a GPU that bills by the hour, so the
                // pod is only on when there is something to run on it. Waking it
                // takes a couple of minutes, and dispatching into that window
                // would spend all three of the job's attempts on a 404 — so wait
                // here instead, where waiting is free.
                $server = ServerName::find(self::TITAN_SERVER_ID);
                $pod = $server
                    ? app(PodLifecycle::class)->ensureAwake($server)
                    : ['state' => 'failed', 'message' => 'The TITAN server is not configured.'];

                if ($pod['state'] === 'failed') {
                    $this->stop($pod['message']);
                    return;
                }
                if ($pod['state'] !== 'healthy') {
                    $this->note('running', $pod['message'] . ' Waiting for it before extracting features.');
                    self::dispatch($this->sampleId, $this->modelKey, $this->tick + 1)
                        ->delay(now()->addSeconds(self::TICK_SECONDS));
                    return;
                }
                $dispatcher->featureExtraction([$sample->id], self::TITAN_SERVER_ID, 1);
            } elseif ($state['next_action'] === 'patches') {
                $dispatcher->patchExtraction([$sample->id], 1, 1, 2);
            }
        }

        $this->note('running', $message);

        self::dispatch($this->sampleId, $this->modelKey, $this->tick + 1)
            ->delay(now()->addSeconds(self::TICK_SECONDS));
    }

    private function note(string $status, string $message): void
    {
        Cache::put(self::stateKey($this->sampleId), [
            'status'  => $status,
            'message' => $message,
            'tick'    => $this->tick,
            'at'      => now()->toDateTimeString(),
        ], now()->addDays(7));
    }

    private function stop(string $why): void
    {
        Log::warning("[AdvanceWorkflow] sample #{$this->sampleId} stopped: {$why}");
        $this->note('stopped', $why);
        $this->releaseTheGpu();
    }

    /**
     * Ask, after a grace period, whether the pod is still needed.
     *
     * Queued on both endings — finished and abandoned — because a run that gave
     * up leaves a pod running just as expensively as one that succeeded. The
     * job only stops the pod if the queue is empty when it comes around, so
     * several slides finishing near each other cost one stop, not several
     * stop-start pairs.
     */
    private function releaseTheGpu(): void
    {
        StopIdlePod::dispatch(self::TITAN_SERVER_ID)
            ->delay(now()->addSeconds(StopIdlePod::GRACE_SECONDS));
    }
}
