<?php

namespace App\Jobs;

use App\Models\ServerName;
use App\Services\PodLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Puts the GPU pod back to sleep once nothing needs it.
 *
 * Dispatched with a delay rather than fired the moment a run ends, because
 * slides usually arrive in groups: stopping after the first and starting again
 * for the second would pay the boot cost over and over for no gain. The delay
 * is the grace period, and the idle check at the end of it is what actually
 * decides — if anything queued up meanwhile, the pod stays.
 */
class StopIdlePod implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Wait this long after the last piece of work before considering a stop. */
    public const GRACE_SECONDS = 600;

    public function __construct(public readonly int $serverId)
    {
        $this->onQueue('operations');
    }

    public function handle(PodLifecycle $pods): void
    {
        $server = ServerName::find($this->serverId);
        if (! $server) {
            return;
        }

        $result = $pods->stopIfIdle($server);
        Log::info("[StopIdlePod] server '{$server->name}': {$result['state']} — {$result['message']}");
    }
}
