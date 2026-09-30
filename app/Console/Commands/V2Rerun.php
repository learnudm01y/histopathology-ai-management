<?php

namespace App\Console\Commands;

use App\Models\V2Diagnosis;
use App\Services\V2DiagnoseRunner;
use Illuminate\Console\Command;

/**
 * Run finished V2 runs again under their own ids.
 *
 *   php artisan v2:rerun 17 48 50
 *
 * Same slide and case details, a fresh analysis; each previous attempt's
 * folder is kept as <id>.attemptN (see V2DiagnoseRunner::rerunInPlace).
 */
class V2Rerun extends Command
{
    protected $signature = 'v2:rerun {ids* : run ids}';

    protected $description = 'Re-run V2 Diagnose runs in place, keeping their ids';

    public function handle(V2DiagnoseRunner $runner): int
    {
        $failed = 0;
        foreach ($this->argument('ids') as $id) {
            $run = V2Diagnosis::find((int) $id);
            if (! $run) {
                $this->error("#{$id}: no such run");
                $failed++;
                continue;
            }
            try {
                $runner->rerunInPlace($run);
                $this->info("#{$id}: queued again — " . $run->fresh()->stage_message);
            } catch (\Throwable $e) {
                $this->error("#{$id}: {$e->getMessage()}");
                $failed++;
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
