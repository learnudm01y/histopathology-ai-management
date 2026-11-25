<?php

namespace App\Console\Commands;

use App\Jobs\PrepareV2Slide;
use App\Models\Sample;
use App\Services\V2DiagnoseRunner;
use Illuminate\Console\Command;

/**
 * Prepare slides for V2 Diagnose ahead of their analysis, so a run on them
 * starts at the analysis instead of the tiling.
 *
 *   php artisan v2:prepare 1569 1618          queue these slides
 *   php artisan v2:prepare 1569 --now         prepare here and print the time of each stage
 *   php artisan v2:prepare --list             what is prepared, its size and age
 */
class V2Prepare extends Command
{
    protected $signature = 'v2:prepare
        {samples?* : slide (sample) ids}
        {--now : prepare in this process instead of queueing, and report the stage times}
        {--list : list the prepared slides}';

    protected $description = 'Prepare slides for V2 Diagnose in advance (tiles, view images, density)';

    public function handle(V2DiagnoseRunner $runner): int
    {
        if ($this->option('list')) {
            $rows = [];
            foreach (glob(rtrim((string) config('v2_diagnose.prepared.dir'), '/\\') . '/*/meta.json') ?: [] as $m) {
                $meta = json_decode((string) file_get_contents($m), true) ?: [];
                $rows[] = [$meta['key'] ?? basename(dirname($m)), $meta['tiles'] ?? '?',
                           round(($meta['bytes'] ?? 0) / 1048576) . ' MB',
                           json_encode($meta['seconds'] ?? []),
                           isset($meta['used_at']) ? date('Y-m-d H:i', $meta['used_at']) : '-'];
            }
            $this->table(['slide', 'tiles', 'size', 'seconds', 'last used'], $rows);
            return self::SUCCESS;
        }

        foreach ((array) $this->argument('samples') as $id) {
            $sample = Sample::find((int) $id);
            if (! $sample) {
                $this->warn("#{$id}: no such slide");
                continue;
            }
            if (! $this->option('now')) {
                PrepareV2Slide::dispatch($sample->id);
                $this->line("#{$sample->id}: queued");
                continue;
            }
            $key = (string) $sample->id;
            if ($dir = $runner->prepared($key)) {
                $this->line("#{$sample->id}: already prepared");
                continue;
            }
            $wsi = $runner->resolveSampleWsi($sample);
            if (! $wsi) {
                $this->warn("#{$sample->id}: the slide is not readable on this server");
                continue;
            }
            $lock = $runner->preparedLock($key);
            if (! $lock->get()) {
                $this->warn("#{$sample->id}: being prepared by another job");
                continue;
            }
            try {
                $t0 = microtime(true);
                $dir = $runner->buildPrepared($key, $wsi);
                $meta = json_decode((string) file_get_contents("{$dir}/meta.json"), true);
                $this->line(sprintf('#%d: %d tiles in %.0f s %s, %d MB', $sample->id, $meta['tiles'],
                    microtime(true) - $t0, json_encode($meta['seconds']), $meta['bytes'] / 1048576));
            } finally {
                $lock->release();
            }
        }
        $runner->evictPrepared();
        return self::SUCCESS;
    }
}
