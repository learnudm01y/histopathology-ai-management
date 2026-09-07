<?php

namespace App\Services;

use App\Jobs\FeatureExtractionJob;
use App\Jobs\PatchExtractionJob;
use App\Models\Operation;
use App\Models\Sample;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stops a running operation.
 *
 * Stopping has to reach three places, and missing any one of them leaves the
 * system lying about itself:
 *
 *   1. the QUEUE — jobs that have not started yet are removed, otherwise a
 *      worker picks them up minutes later and the "stopped" run carries on;
 *   2. the RECORDS — unfinished items become `cancelled` and the operation with
 *      them, so the audit says the run was stopped rather than that it failed;
 *   3. the SLIDES — a slide left at `processing` shows as busy for ever and is
 *      refused by every later dispatch, so it goes back to `pending`, the state
 *      it was in before this run claimed it.
 *
 * A job already executing cannot be killed from here: it is a separate process
 * mid-download. Resetting its slide to `pending` is what stops it — the job
 * re-reads that column at each checkpoint and aborts when it is no longer the
 * `processing` it set, which is why (3) is a cancellation signal and not just
 * bookkeeping.
 */
class OperationCanceller
{
    /** Which job class carries the work for each operation kind. */
    private const JOB_CLASS = [
        'patch_extraction'   => PatchExtractionJob::class,
        'feature_extraction' => FeatureExtractionJob::class,
    ];

    /** The pipeline column each kind occupies while it runs. */
    private const STATUS_COLUMN = [
        'patch_extraction'   => 'tiling_status',
        'feature_extraction' => 'feature_extraction_status',
    ];

    /**
     * @return array{cancelled_items: int, dequeued_jobs: int, slides_reset: int}
     */
    public function cancel(Operation $operation): array
    {
        $unfinished = $operation->items()
            ->whereIn('status', ['pending', 'processing'])
            ->get(['id', 'sample_id']);

        $sampleIds = $unfinished->pluck('sample_id')->filter()->map(fn ($id) => (int) $id)->all();

        $dequeued = $this->dequeue($operation->type, $sampleIds);
        $reset    = $this->resetSlides($operation->type, $sampleIds);

        DB::transaction(function () use ($operation, $unfinished) {
            $operation->items()->whereIn('id', $unfinished->pluck('id'))->update([
                'status'     => 'cancelled',
                'message'    => 'Stopped by operator',
                'updated_at' => now(),
            ]);

            $operation->forceFill([
                'status'      => 'cancelled',
                'finished_at' => $operation->finished_at ?? now(),
            ])->save();

            $operation->recount();
        });

        Log::info(sprintf(
            '[OperationCancel] Operation #%d (%s) stopped: %d item(s) cancelled, %d queued job(s) removed, %d slide(s) reset.',
            $operation->id, $operation->type, $unfinished->count(), $dequeued, $reset
        ));

        return [
            'cancelled_items' => $unfinished->count(),
            'dequeued_jobs'   => $dequeued,
            'slides_reset'    => $reset,
        ];
    }

    /**
     * Remove this operation's not-yet-started jobs from the queue.
     *
     * Rows with `reserved_at` set are already in a worker's hands and deleting
     * them would only lose the bookkeeping, not stop the work — those are left
     * to notice the reset slide and abort themselves.
     */
    private function dequeue(string $type, array $sampleIds): int
    {
        $jobClass = self::JOB_CLASS[$type] ?? null;
        if ($jobClass === null || $sampleIds === []) {
            return 0;
        }

        $removed = 0;

        DB::table('jobs')->whereNull('reserved_at')->orderBy('id')
            ->select('id', 'payload')->chunk(200, function ($rows) use ($jobClass, $sampleIds, &$removed) {
                $doomed = [];

                foreach ($rows as $row) {
                    $command = json_decode($row->payload, true)['data']['command'] ?? null;
                    if (! is_string($command) || ! str_contains($command, $jobClass)) {
                        continue;
                    }

                    // Unserialising is what makes this exact: a substring match on
                    // the id would also hit an unrelated job whose numbers happen
                    // to line up.
                    try {
                        $job = unserialize($command, ['allowed_classes' => true]);
                    } catch (\Throwable) {
                        continue;
                    }

                    if ($job instanceof $jobClass && in_array((int) $job->sampleId, $sampleIds, true)) {
                        $doomed[] = $row->id;
                    }
                }

                if ($doomed !== []) {
                    $removed += DB::table('jobs')->whereIn('id', $doomed)->delete();
                }
            });

        return $removed;
    }

    /** Put the slides back to the state they were in before this run claimed them. */
    private function resetSlides(string $type, array $sampleIds): int
    {
        $column = self::STATUS_COLUMN[$type] ?? null;
        if ($column === null || $sampleIds === []) {
            return 0;
        }

        return Sample::whereIn('id', $sampleIds)
            ->where($column, 'processing')
            ->update([$column => 'pending']);
    }
}
