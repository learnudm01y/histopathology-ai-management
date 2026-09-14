<?php

namespace App\Services;

use App\Models\Sample;
use App\Models\ServerName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns a GPU pod on when there is work for it, and off when there is not.
 *
 * A pod left running bills by the hour whether or not anything is using it, and
 * a pod left stopped turns every feature-extraction job into a 404. Neither is
 * a state a person should have to remember to fix, so this decides from the
 * work in front of it: wake before dispatching, and sleep once the queue has
 * nothing left that needs a GPU.
 *
 * Stopping is deliberately conservative. Waking a pod costs a minute; stopping
 * one that still had work costs a failed run and the compute already spent on
 * it, so every check here has to come back empty before the pod goes down.
 */
class PodLifecycle
{
    /** A pod that just started needs roughly this long before /health answers. */
    public const BOOT_SECONDS = 180;

    /**
     * The pod id hiding in the server's proxy URL.
     *
     * RunPod's proxy host is "{podId}-{port}.proxy.runpod.net", and podId itself
     * contains a dash, so the port is stripped from the right rather than the
     * host split on dashes. Reading it from api_url keeps the id in one place
     * instead of adding a column that could disagree with the URL beside it.
     */
    public function podIdFor(ServerName $server): ?string
    {
        $host = parse_url((string) $server->api_url, PHP_URL_HOST);
        if (! $host || ! str_ends_with($host, '.proxy.runpod.net')) {
            return null;
        }

        $name = substr($host, 0, -strlen('.proxy.runpod.net'));
        $cut  = strrpos($name, '-');

        return $cut === false ? null : substr($name, 0, $cut);
    }

    /** Is the service behind this server answering right now? */
    public function isHealthy(ServerName $server): bool
    {
        if (! $server->api_url) {
            return false;
        }
        try {
            return Http::timeout(8)->connectTimeout(5)
                ->get(rtrim($server->api_url, '/') . '/health')
                ->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Make sure the pod is on. Returns one of:
     *   healthy  — answering now, dispatch away
     *   starting — a start was issued, or one is still booting; come back later
     *   failed   — with a reason a person can act on
     */
    public function ensureAwake(ServerName $server): array
    {
        if ($this->isHealthy($server)) {
            return ['state' => 'healthy', 'message' => 'The pod is answering.'];
        }

        $podId = $this->podIdFor($server);
        if (! $podId || ! $server->runpod_api_key) {
            return ['state' => 'failed', 'message' =>
                "Server '{$server->name}' has no RunPod pod id or api key, so it cannot be started automatically."];
        }

        try {
            $runpod = new RunPodService($server->runpod_api_key);
            $pod = $runpod->getPod($podId);

            if (! $pod) {
                return ['state' => 'failed', 'message' =>
                    "RunPod does not know a pod called {$podId}. It may have been deleted."];
            }

            if (($pod['desiredStatus'] ?? null) === 'RUNNING') {
                // Running but not answering yet: still booting the service.
                return ['state' => 'starting', 'message' => 'The pod is up; its service is still starting.'];
            }

            $runpod->startPod($podId);
            Log::info("[PodLifecycle] started pod {$podId} for server '{$server->name}'");

            return ['state' => 'starting', 'message' => 'The pod was off — starting it now.'];
        } catch (\Throwable $e) {
            Log::error("[PodLifecycle] could not start {$podId}: {$e->getMessage()}");
            return ['state' => 'failed', 'message' => 'Could not start the pod: ' . $e->getMessage()];
        }
    }

    /**
     * Anything that would need this GPU if it woke up in a minute.
     *
     * Deliberately broader than "a job is running now": a slide waiting in the
     * queue, or one whose extraction is mid-flight, both mean the pod is still
     * earning its keep.
     */
    public function hasPendingWork(): bool
    {
        // Anything the queue will actually run. This is the authoritative check:
        // a stale OperationItem left at 'pending' by a crashed run has no job
        // behind it and will never execute, so counting it would pin the pod up
        // forever over work that is never coming.
        $queued = DB::table('jobs')->where(function ($q) {
            $q->where('payload', 'like', '%FeatureExtractionJob%')
              ->orWhere('payload', 'like', '%AdvanceWorkflow%');
        })->exists();

        if ($queued) {
            return true;
        }

        // A slide mid-extraction, but only if it has been touched recently —
        // otherwise one crash that left a row on 'processing' would keep a GPU
        // billing indefinitely.
        return Sample::where('feature_extraction_status', 'processing')
            ->where('updated_at', '>=', now()->subHours(3))
            ->exists();
    }

    /** Stop the pod, but only if nothing is left that would want it. */
    public function stopIfIdle(ServerName $server): array
    {
        if ($this->hasPendingWork()) {
            return ['state' => 'kept', 'message' => 'Work is still queued — leaving the pod up.'];
        }

        $podId = $this->podIdFor($server);
        if (! $podId || ! $server->runpod_api_key) {
            return ['state' => 'skipped', 'message' => 'No pod id or api key for this server.'];
        }

        try {
            $runpod = new RunPodService($server->runpod_api_key);
            $pod = $runpod->getPod($podId);

            if (! $pod || ($pod['desiredStatus'] ?? null) !== 'RUNNING') {
                return ['state' => 'skipped', 'message' => 'The pod is already down.'];
            }

            $runpod->stopPod($podId);
            Log::info("[PodLifecycle] stopped idle pod {$podId} for server '{$server->name}'");

            return ['state' => 'stopped', 'message' => "Pod {$podId} stopped — nothing left in the queue."];
        } catch (\Throwable $e) {
            Log::error("[PodLifecycle] could not stop {$podId}: {$e->getMessage()}");
            return ['state' => 'failed', 'message' => 'Could not stop the pod: ' . $e->getMessage()];
        }
    }
}
