<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Answers "is there still a job in the queue for this slide?".
 *
 * Needed because a slide's status column cannot tell waiting apart from
 * working. Dispatch marks every slide in a batch `processing` at once, but the
 * worker takes them one at a time, so the last slide of a fifty-slide run sits
 * at `processing` for hours before anything touches it. Anything that reasons
 * about "stuck" work has to consult the queue, or it will mistake a queue for
 * a crash.
 *
 * The payload is unserialised rather than string-matched: a substring search
 * for a sample id also hits an unrelated job whose numbers happen to line up,
 * and acting on that would cancel or condemn the wrong slide.
 */
class QueuedJobLookup
{
    /**
     * Sample ids that still have a job row for this class — queued or already
     * in a worker's hands.
     *
     * @return array<int, int>
     */
    public function sampleIds(string $jobClass): array
    {
        $ids = [];

        $this->scan($jobClass, null, false, function ($row, $job) use (&$ids) {
            $ids[] = (int) $job->sampleId;
        });

        return array_values(array_unique($ids));
    }

    /**
     * Row ids in `jobs` carrying this class for the given samples.
     *
     * `$onlyUnreserved` excludes jobs a worker has already picked up: deleting
     * one of those loses the bookkeeping without stopping the work.
     *
     * @param  array<int, int>  $sampleIds
     * @return array<int, int>
     */
    public function jobRowIds(string $jobClass, array $sampleIds, bool $onlyUnreserved = true): array
    {
        if ($sampleIds === []) {
            return [];
        }

        $rowIds = [];

        $this->scan($jobClass, $sampleIds, $onlyUnreserved, function ($row) use (&$rowIds) {
            $rowIds[] = $row->id;
        });

        return $rowIds;
    }

    /**
     * @param  array<int, int>|null  $sampleIds  null = every sample
     */
    private function scan(string $jobClass, ?array $sampleIds, bool $onlyUnreserved, callable $onMatch): void
    {
        DB::table('jobs')
            ->when($onlyUnreserved, fn ($q) => $q->whereNull('reserved_at'))
            ->orderBy('id')
            ->select('id', 'payload')
            ->chunk(200, function ($rows) use ($jobClass, $sampleIds, $onMatch) {
                foreach ($rows as $row) {
                    $command = json_decode($row->payload, true)['data']['command'] ?? null;

                    if (! is_string($command) || ! str_contains($command, $jobClass)) {
                        continue;
                    }

                    try {
                        $job = unserialize($command, ['allowed_classes' => true]);
                    } catch (\Throwable) {
                        continue;
                    }

                    if (! $job instanceof $jobClass || ! isset($job->sampleId)) {
                        continue;
                    }

                    if ($sampleIds !== null && ! in_array((int) $job->sampleId, $sampleIds, true)) {
                        continue;
                    }

                    $onMatch($row, $job);
                }
            });
    }
}
