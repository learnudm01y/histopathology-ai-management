<?php

namespace App\Services;

use App\Models\Operation;
use App\Models\OperationItem;
use App\Models\TrainingRun;
use Illuminate\Support\Collection;

/**
 * Keeps a running operation's items in step with the work they describe.
 *
 * Progress is DERIVED, not reported. The obvious alternative — have each job
 * tick its own item off — cannot work here: feature extraction and training
 * are finished by the remote GPU service writing straight to `samples` and
 * `training_runs`, so no Laravel job is running at the moment they complete.
 * Reading the pipeline column each operation kind already maintains is the one
 * mechanism that covers all of them, and it needs no cooperation from the jobs.
 *
 * A FINISHED operation is never re-derived. Its rows are evidence of what
 * happened at the time, and re-tiling a slide next month must not rewrite the
 * history of the run that tiled it last month — that run's record stays as it
 * settled, and the new dispatch opens a record of its own.
 */
class OperationProgress
{
    /** The column on `samples` that each operation kind advances. */
    private const SOURCE_COLUMN = [
        'patch_extraction'   => 'tiling_status',
        'feature_extraction' => 'feature_extraction_status',
    ];

    /**
     * Per operation kind: pipeline value → item status. Anything not listed
     * means the slide has not been picked up yet, which is 'pending'.
     */
    private const STATUS_MAP = [
        'patch_extraction' => [
            'done'       => 'completed',
            'failed'     => 'failed',
            'processing' => 'processing',
        ],
        'feature_extraction' => [
            'completed'  => 'completed',
            'failed'     => 'failed',
            'processing' => 'processing',
        ],
    ];

    /**
     * Bring every still-running operation in the collection up to date.
     *
     * @param  Collection<int, Operation>  $operations
     */
    public function syncMany(Collection $operations): void
    {
        $operations->filter->is_running->each(fn (Operation $op) => $this->sync($op));
    }

    public function sync(Operation $operation): Operation
    {
        if (! $operation->is_running) {
            return $operation;
        }

        $statuses = $operation->type === 'training'
            ? $this->trainingStatuses($operation)
            : $this->sampleStatuses($operation);

        foreach ($statuses as $itemId => $status) {
            OperationItem::whereKey($itemId)->update(['status' => $status]);
        }

        $operation->recount();

        return $operation->refresh();
    }

    /**
     * Item id → new status, read from the slides themselves.
     *
     * A slide that has since been deleted settles as 'skipped': it can never
     * reach a terminal state now, and without this the operation would sit at
     * "running" for ever waiting on a row that no longer exists.
     *
     * @return array<int, string>
     */
    private function sampleStatuses(Operation $operation): array
    {
        $column = self::SOURCE_COLUMN[$operation->type] ?? null;
        if ($column === null) {
            return [];
        }

        $map = self::STATUS_MAP[$operation->type];

        return $operation->items()
            ->with(['sample:id,' . $column])
            ->get(['id', 'sample_id', 'status'])
            ->mapWithKeys(function (OperationItem $item) use ($column, $map) {
                $status = $item->sample === null
                    ? 'skipped'
                    : ($map[$item->sample->{$column}] ?? 'pending');

                // Only rows that actually move are written back.
                return $status === $item->status ? [] : [$item->id => $status];
            })
            ->all();
    }

    /**
     * Training is one remote run covering every slide at once, so all items
     * share the run's fate rather than settling independently.
     *
     * @return array<int, string>
     */
    private function trainingStatuses(Operation $operation): array
    {
        $runId = $operation->params['training_run_id'] ?? null;
        if ($runId === null) {
            return [];
        }

        $runStatus = TrainingRun::whereKey($runId)->value('status');

        $status = match ($runStatus) {
            'completed'          => 'completed',
            'failed', 'cancelled' => 'failed',
            null                 => 'skipped',   // the run was deleted
            default              => 'processing',
        };

        return $operation->items()
            ->where('status', '!=', $status)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => $status])
            ->all();
    }
}
