<?php

namespace App\Console\Commands;

use App\Jobs\RunSlideVerification;
use App\Models\Sample;
use App\Services\SlideVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic worker that picks samples needing (re-)verification and
 * dispatches a RunSlideVerification job for each.
 *
 * Selection criteria — a sample needs verification when:
 *   • it has no slide_verifications row yet, OR
 *   • its verification_status is 'pending' or 'failed', OR
 *   • its verified_at is older than REVERIFY_AFTER_HOURS hours.
 *
 * Schedule: every 30 seconds in development. In production this is
 * intended to run every 12 hours — change the schedule registration
 * in routes/console.php to ->cron('0 *\/12 * * *') (or similar).
 */
class VerifyPendingSlides extends Command
{
    protected $signature = 'slides:verify-pending
                            {--limit=50 : Max samples to process per run}
                            {--sync : Run verifications inline instead of dispatching jobs}';

    protected $description = 'Run slide verification for samples that are pending or due for re-verification';

    public function handle(SlideVerificationService $service): int
    {
        $limit = (int) $this->option('limit');
        $sync  = (bool) $this->option('sync');

        $cutoff = now()->subHours(SlideVerificationService::REVERIFY_AFTER_HOURS);

        // Pick samples that need (first-time) verification or are stuck in
        // 'pending'. Failed samples are intentionally excluded from automatic
        // re-runs — they must be re-triggered manually via the "Verify Slide"
        // button. Including 'failed' here caused an infinite loop: every run
        // marks them failed → next run picks them up again → repeat forever.
        //
        // Re-verification of stale results is restricted to 'passed' records
        // only, to avoid re-processing failures on every scheduler tick.
        $samples = Sample::query()
            ->leftJoin('slide_verifications as sv', 'sv.sample_id', '=', 'samples.id')
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('sv.id')                            // never verified yet
                  ->orWhere('sv.verification_status', 'pending')  // stuck in pending
                  ->orWhere(function ($q2) use ($cutoff) {        // passed but now stale
                      $q2->where('sv.verification_status', 'passed')
                         ->where('sv.verified_at', '<', $cutoff);
                  });
            })
            ->select('samples.id')
            ->orderBy('samples.id')
            ->limit($limit)
            ->pluck('samples.id');

        if ($samples->isEmpty()) {
            $this->info('No samples need verification right now.');
            return self::SUCCESS;
        }

        $this->info("Queueing verification for {$samples->count()} sample(s).");
        Log::info("[VerifyPendingSlides] Queueing verification for {$samples->count()} sample(s).");

        $dispatched = 0;
        foreach ($samples as $sampleId) {
            if ($sync) {
                $sample = Sample::find($sampleId);
                if ($sample) {
                    $service->verify($sample);
                    $dispatched++;
                }
            } else {
                // ShouldBeUnique on RunSlideVerification ensures this is a
                // no-op if a job for this sampleId is already in the queue.
                RunSlideVerification::dispatch($sampleId);
                $dispatched++;
            }
        }

        if ($dispatched > 0) {
            $this->info("Dispatched {$dispatched} job(s).");
            Log::info("[VerifyPendingSlides] Dispatched {$dispatched} job(s).");
        }

        return self::SUCCESS;
    }
}
