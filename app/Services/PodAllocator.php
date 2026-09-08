<?php

namespace App\Services;

use App\Models\Operation;
use App\Models\OperationItem;
use App\Models\Sample;
use App\Models\ServerName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Binds an operation to one RunPod pod, and says what that will cost.
 *
 * Feature extraction is submitted and then processed remotely, so the pod — not
 * the local queue — is what limits throughput: fifty slides handed to one pod
 * are processed one after another however fast the queue drains. Giving each
 * operation its own pod is therefore the only thing that actually makes two
 * operations run at once.
 *
 * That was impossible while the endpoint lived in `servers_names.api_url`, a
 * single mutable field every job read at execution time: selecting a second pod
 * silently redirected the first operation's remaining work. The pod is recorded
 * on the operation instead, and carried on each job.
 */
class PodAllocator
{
    /**
     * How long one slide has historically taken on a pod, in seconds.
     *
     * Measured from this system's own completed work rather than assumed, and
     * only used to say how long something will take and what it will cost. When
     * there is no history yet the estimate is refused rather than guessed —
     * a made-up number on a page about money is worse than no number.
     */
    public function secondsPerSlide(?int $serverId = null): ?float
    {
        $window = Sample::whereNotNull('feature_extraction_completed_at')
            ->where('feature_extraction_status', 'completed')
            ->when($serverId, fn ($q) => $q->where('feature_extraction_server_id', $serverId))
            ->selectRaw('MIN(feature_extraction_completed_at) AS first_done')
            ->selectRaw('MAX(feature_extraction_completed_at) AS last_done')
            ->selectRaw('COUNT(*) AS slides')
            ->first();

        // Two completions are the minimum that can describe a rate at all.
        if (! $window || (int) $window->slides < 2 || ! $window->first_done || ! $window->last_done) {
            return null;
        }

        $seconds = strtotime($window->last_done) - strtotime($window->first_done);

        return $seconds > 0 ? $seconds / ((int) $window->slides - 1) : null;
    }

    /**
     * What finishing this operation on a given pod would cost and take.
     *
     * @return array{slides:int, seconds:float|null, hours:float|null, cost:float|null, rate:float|null}
     */
    public function estimate(Operation $operation, ?float $costPerHour, ?int $serverId = null): array
    {
        $remaining = $operation->items()
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        $perSlide = $this->secondsPerSlide($serverId);

        if ($perSlide === null || $costPerHour === null) {
            return ['slides' => $remaining, 'seconds' => null, 'hours' => null, 'cost' => null, 'rate' => $costPerHour];
        }

        $seconds = $perSlide * $remaining;
        $hours   = $seconds / 3600;

        return [
            'slides'  => $remaining,
            'seconds' => $seconds,
            'hours'   => $hours,
            'cost'    => round($hours * $costPerHour, 2),
            'rate'    => $costPerHour,
        ];
    }

    /**
     * Point this operation at a pod, and send its outstanding work there.
     *
     * Only the slides that have not been submitted yet are moved. Work already
     * accepted by another pod is left with it: re-submitting it would have two
     * pods extracting the same slide to the same place, and pay for both.
     *
     * @return array{queued:int, endpoint:string}
     */
    public function assign(Operation $operation, ServerName $server, array $pod, RunPodService $runpod): array
    {
        $port     = $server->runpod_port ?: 8000;
        $endpoint = $runpod->proxyUrlFor($pod, $port);

        if ($endpoint === null) {
            throw new \RuntimeException('That pod is not running, so it has no endpoint yet. Start it first.');
        }

        $pending = $operation->items()
            ->where('status', 'pending')
            ->pluck('sample_id')
            ->filter()
            ->map(fn ($id) => (int) $id);

        DB::transaction(function () use ($operation, $pod, $endpoint) {
            $operation->forceFill([
                'params' => array_merge($operation->params ?? [], [
                    'pod_id'          => $pod['id'],
                    'pod_name'        => $pod['name'] ?? $pod['id'],
                    'pod_gpu'         => $pod['machine']['gpuDisplayName'] ?? null,
                    'pod_cost_per_hr' => isset($pod['costPerHr']) ? (float) $pod['costPerHr'] : null,
                    'pod_endpoint'    => $endpoint,
                ]),
            ])->save();
        });

        $aiModelId = (int) ($operation->params['ai_model_id'] ?? 0);

        foreach ($pending as $sampleId) {
            Sample::whereKey($sampleId)->update([
                'feature_extraction_status'    => 'processing',
                'feature_extraction_server_id' => $server->id,
                'feature_extraction_error'     => null,
            ]);

            \App\Jobs\FeatureExtractionJob::dispatch($sampleId, $server->id, $aiModelId, $endpoint);
        }

        if ($pending->isNotEmpty()) {
            OperationItem::where('operation_id', $operation->id)
                ->whereIn('sample_id', $pending)
                ->update(['last_attempt_at' => now(), 'updated_at' => now()]);
        }

        Log::info(sprintf(
            '[PodAllocator] Operation #%d bound to pod %s (%s) at %s; %d slide(s) sent there.',
            $operation->id, $pod['id'], $pod['machine']['gpuDisplayName'] ?? '?', $endpoint, $pending->count()
        ));

        return ['queued' => $pending->count(), 'endpoint' => $endpoint];
    }
}
