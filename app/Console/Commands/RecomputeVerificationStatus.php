<?php

namespace App\Console\Commands;

use App\Models\SlideVerification;
use App\Services\SlideVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan slides:recompute-status [--dry-run] [--only=failed,pending,passed]
 *
 * Re-derives verification_status (and the sample's quality_status) from the
 * values ALREADY stored on each slide_verifications row.
 *
 * No file is opened, nothing is downloaded and no job is queued — this only
 * re-applies the ranking in SlideVerificationService::finalize(), which is what
 * has to happen after that ranking changes. It is how the existing rows moved
 * onto `needs_clinical_info`: a slide rejected for a missing patient_id is a
 * slide whose stored columns already say everything needed to reclassify it.
 *
 * Safe to re-run at any time.
 */
class RecomputeVerificationStatus extends Command
{
    protected $signature = 'slides:recompute-status
                            {--dry-run : Report the transitions without writing them}
                            {--only=   : Comma-separated current statuses to limit the pass to}
                            {--chunk=500 : Rows per chunk}';

    protected $description = 'Re-derive verification_status / quality_status from the stored check values';

    public function handle(SlideVerificationService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only   = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $chunk  = max(50, (int) $this->option('chunk'));

        $query = SlideVerification::query()->orderBy('id');
        if ($only) {
            $query->whereIn('verification_status', $only);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Nothing to recompute.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Recomputing %d verification row(s)%s.', $total, $dryRun ? ' (dry run)' : ''));
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $transitions = [];
        $changed     = 0;

        $query->chunkById($chunk, function ($rows) use ($service, $dryRun, &$transitions, &$changed, $bar) {
            foreach ($rows as $row) {
                $before = $row->verification_status;

                if ($dryRun) {
                    // Mirror finalize()'s ranking without writing anything.
                    $states = array_column($row->evaluateChecks(), 'state');
                    $after  = match (true) {
                        in_array(SlideVerification::STATE_FAILED, $states, true)      => SlideVerification::STATUS_FAILED,
                        in_array(SlideVerification::STATE_NEEDS_INFO, $states, true)  => SlideVerification::STATUS_NEEDS_CASE,
                        in_array(SlideVerification::STATE_NOT_CHECKED, $states, true) => SlideVerification::STATUS_PENDING,
                        default                                                       => SlideVerification::STATUS_PASSED,
                    };
                } else {
                    $service->recomputeStatus($row);
                    $after = $row->fresh()?->verification_status ?? $before;
                }

                if ($after !== $before) {
                    $changed++;
                    $key = "{$before} → {$after}";
                    $transitions[$key] = ($transitions[$key] ?? 0) + 1;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        if (! $transitions) {
            $this->info('Every row already carries the status its stored values imply.');
            return self::SUCCESS;
        }

        arsort($transitions);
        $this->table(
            ['Transition', 'Rows'],
            array_map(fn ($k, $v) => [$k, $v], array_keys($transitions), $transitions)
        );

        $summary = sprintf(
            '[RecomputeVerificationStatus] %d/%d row(s) reclassified%s — %s',
            $changed,
            $total,
            $dryRun ? ' (dry run, nothing written)' : '',
            json_encode($transitions)
        );

        $this->info($summary);
        if (! $dryRun) {
            Log::info($summary);
        }

        return self::SUCCESS;
    }
}
