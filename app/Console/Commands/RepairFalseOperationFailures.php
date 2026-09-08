<?php

namespace App\Console\Commands;

use App\Models\Operation;
use App\Models\OperationItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Corrects operation items recorded as failed for work that actually succeeded.
 *
 * On 2026-09-07 the stuck-job sweeper marked 119 slides failed because it could
 * not tell a slide waiting in the queue from a slide whose worker had crashed.
 * The operations covering them settled as "failed" and froze, which is normally
 * the right behaviour — a finished record is evidence and must not be rewritten
 * by later status changes. But that evidence was manufactured by a defect: the
 * slides went on to tile successfully, and the audit now permanently reports a
 * hundred failures that never happened.
 *
 * So this corrects them, and only where the slide itself proves the correction:
 * the item is only moved to `completed` when its slide is `done` AND its patches
 * are on Drive. An item whose slide is still unfinished is left alone and
 * reported, because that one may be a real failure.
 *
 * ATTRIBUTION. "The slide is tiled now" is not on its own proof that THIS run
 * tiled it. If a later operation covers the same slide and completed it, that
 * later run did the work — crediting the earlier one would have the audit
 * report a success it never achieved, which is the same dishonesty as the false
 * failure, only in the opposite direction. Those items keep their failure.
 *
 * Every corrected row keeps a message saying it was corrected and why, so the
 * audit shows a correction rather than a silent rewrite.
 *
 * Usage:
 *   php artisan operations:repair-false-failures         # report only
 *   php artisan operations:repair-false-failures --fix   # apply
 */
class RepairFalseOperationFailures extends Command
{
    protected $signature = 'operations:repair-false-failures
                            {--fix : Apply the corrections (default is a dry run)}
                            {--operation= : Restrict to one operation id}';

    protected $description = 'Correct operation items recorded as failed for slides that actually completed';

    private const NOTE = 'Corrected: recorded as failed by the stuck-job sweeper before this slide had started; it went on to tile successfully.';

    public function handle(): int
    {
        $fix = $this->option('fix');

        // The proof of a false failure is the slide itself: tiled, with its
        // patches where the run said it would put them.
        $candidates = OperationItem::query()
            ->join('samples as s', 's.id', '=', 'operation_items.sample_id')
            ->join('operations as o', 'o.id', '=', 'operation_items.operation_id')
            ->whereIn('operation_items.status', ['failed', 'cancelled'])
            ->where('o.type', 'patch_extraction')
            ->where('s.tiling_status', 'done')
            ->whereNotNull('s.tiles_gdrive_path')
            ->when($this->option('operation'), fn ($q, $id) => $q->where('o.id', $id))
            // A later run that completed the same slide is the one that did the
            // work, so this failure stands.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('operation_items as later')
                    ->whereColumn('later.sample_id', 'operation_items.sample_id')
                    ->whereColumn('later.operation_id', '>', 'operation_items.operation_id')
                    ->where('later.status', 'completed');
            })
            ->select('operation_items.id', 'operation_items.operation_id', 'operation_items.status', 's.file_name')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No falsely-failed items found — every failure on record is backed by a slide that really did not finish.');

            return self::SUCCESS;
        }

        $this->info("Found {$candidates->count()} item(s) recorded as failed for slides that DID complete:");

        foreach ($candidates->groupBy('operation_id') as $operationId => $items) {
            $operation = Operation::find($operationId);
            $this->line(sprintf('  operation #%d [%s] — %d item(s)', $operationId, $operation?->status ?? '?', $items->count()));
        }

        if (! $fix) {
            $this->newLine();
            $this->warn('Dry run — nothing written. Re-run with --fix to apply.');
            $this->reportStillFailing();

            return self::SUCCESS;
        }

        DB::transaction(function () use ($candidates) {
            OperationItem::whereIn('id', $candidates->pluck('id'))->update([
                'status'     => 'completed',
                'message'    => self::NOTE,
                'updated_at' => now(),
            ]);

            // Recount rebuilds each operation's status from its items, so a run
            // whose every failure was false goes back to reading "completed".
            foreach ($candidates->pluck('operation_id')->unique() as $operationId) {
                Operation::find($operationId)?->recount();
            }
        });

        foreach ($candidates->pluck('operation_id')->unique() as $operationId) {
            $operation = Operation::find($operationId);
            $this->line(sprintf('  operation #%d is now [%s] — %d/%d completed, %d failed',
                $operationId, $operation->status, $operation->completed_items, $operation->total_items, $operation->failed_items));
        }

        Log::info("[RepairFalseFailures] Corrected {$candidates->count()} operation item(s) recorded as failed for slides that completed.");
        $this->info("Corrected {$candidates->count()} item(s).");

        $this->reportStillFailing();

        return self::SUCCESS;
    }

    /** Whatever is left really did not finish, and needs a retry rather than a correction. */
    private function reportStillFailing(): void
    {
        $remaining = OperationItem::query()
            ->join('samples as s', 's.id', '=', 'operation_items.sample_id')
            ->whereIn('operation_items.status', ['failed', 'cancelled'])
            ->where('s.tiling_status', '!=', 'done')
            ->select('operation_items.operation_id', 's.id as sample_id', 's.file_name', 's.tiling_status')
            ->get();

        $this->newLine();

        if ($remaining->isEmpty()) {
            $this->info('Nothing else is on record as failed.');

            return;
        }

        $this->warn("{$remaining->count()} item(s) genuinely did not finish — retry them from the operation page:");
        foreach ($remaining as $row) {
            $this->line(sprintf('  operation #%d  slide #%d  [%s]  %s',
                $row->operation_id, $row->sample_id, $row->tiling_status, \Illuminate\Support\Str::limit($row->file_name, 60)));
        }
    }
}
