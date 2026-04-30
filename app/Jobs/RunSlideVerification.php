<?php

namespace App\Jobs;

use App\Models\Sample;
use App\Services\SlideVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Run the slide-verification pipeline for a single sample.
 *
 * ShouldBeUnique ensures only ONE job per sampleId can exist in the queue
 * at a time — re-dispatching the same sampleId is a no-op until the first
 * job completes. This prevents the scheduler from accumulating thousands of
 * duplicate jobs when samples stay in a non-passing state.
 */
class RunSlideVerification implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;

    /**
     * The unique key that prevents duplicate jobs in the queue.
     * Laravel's ShouldBeUnique uses this as the cache lock key.
     */
    public function uniqueId(): string
    {
        return (string) $this->sampleId;
    }

    /**
     * Lock expires after 10 minutes — covers normal verification time.
     * If the job is still stuck after 10 min, allow a new one to be dispatched.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $sampleId)
    {
    }

    public function handle(SlideVerificationService $service): void
    {
        $sample = Sample::find($this->sampleId);

        if (!$sample) {
            Log::warning("[RunSlideVerification] Sample #{$this->sampleId} not found — skipping.");
            return;
        }

        try {
            $service->verify($sample);
        } catch (\Throwable $e) {
            Log::error("[RunSlideVerification] Sample #{$this->sampleId} failed: " . $e->getMessage());
            throw $e;
        }

        // Phase 1 (metadata-only) almost always results in 'pending' because
        // WSI-derived checks (open_slide, level_count, mpp, tissue %) require
        // OpenSlide. Dispatch WsiPreviewJob (mode='verify') so those checks run
        // and quality_status can advance to 'passed' or 'rejected'.
        $hasSource = $sample->file_id
            || $sample->wsi_remote_path
            || $sample->storage_path;

        // Skip if deep checks already exist (open_slide_status was set by a
        // previous run) — avoids re-downloading a file we already inspected.
        $alreadyInspected = \App\Models\SlideVerification::where('sample_id', $sample->id)
            ->whereNotNull('open_slide_status')
            ->where('open_slide_status', '!=', 'not_checked')
            ->exists();

        if ($hasSource && !$alreadyInspected) {
            WsiPreviewJob::dispatch($this->sampleId, 'verify');
            Log::info("[RunSlideVerification] Sample #{$this->sampleId}: dispatched WsiPreviewJob (verify mode) for deep inspection.");
        }
    }
}
