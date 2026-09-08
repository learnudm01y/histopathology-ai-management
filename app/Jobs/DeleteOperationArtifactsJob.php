<?php

namespace App\Jobs;

use App\Models\Operation;
use App\Models\OperationItem;
use App\Models\Sample;
use App\Services\GoogleDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Deletes the files an operation produced, then optionally the record itself.
 *
 * WHAT THIS DELETES: the derived artefacts — the patch archive a tiling run
 * uploaded, the feature file a feature-extraction run produced — and the
 * columns pointing at them, so the system stops claiming to hold output it no
 * longer has.
 *
 * WHAT THIS MUST NEVER DELETE: the whole-slide images themselves.
 * `wsi_remote_path` and `storage_path` are the irreplaceable originals; patches
 * can be regenerated from them in an afternoon, the slides cannot be recovered
 * at all. Nothing here reads those columns, and the paths purged come only from
 * `tiles_gdrive_path` / `features_gdrive_path`.
 *
 * WHAT THIS MUST NOT DELETE EITHER: output another operation still accounts
 * for. Two runs dispatched over overlapping selections tile the same slide to
 * the same place, so the patches are one artefact with two owners. Purging on
 * behalf of one owner destroyed the other's output while leaving its record
 * saying "completed" — which is exactly what happened when operation #3 was
 * deleted on 2026-09-07 and took 23 of operation #2's slides with it.
 *
 * Queued because purging dozens of Drive folders is minutes of rclone calls,
 * and on the `operations` queue so it lines up behind the work it cleans up
 * after rather than racing it.
 */
class DeleteOperationArtifactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** No retries: a half-repeated purge is worse than a reported failure. */
    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $operationId,
        public readonly bool $deleteRecord = false,
    ) {
        $this->onQueue('operations');
    }

    public function handle(GoogleDriveService $drive): void
    {
        $operation = Operation::with('items')->find($this->operationId);

        if (! $operation) {
            Log::warning("[DeleteOperationArtifacts] Operation #{$this->operationId} is already gone.");

            return;
        }

        $purged = 0;
        $failed = 0;
        $cleared = 0;
        $shared  = 0;

        // Slides whose output another operation of this kind also records as
        // completed. That run still needs them, so they are left alone.
        $claimedElsewhere = OperationItem::query()
            ->join('operations as o', 'o.id', '=', 'operation_items.operation_id')
            ->where('o.type', $operation->type)
            ->where('operation_items.operation_id', '!=', $operation->id)
            ->where('operation_items.status', 'completed')
            ->whereIn('operation_items.sample_id', $operation->items->pluck('sample_id')->filter())
            ->pluck('operation_items.sample_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($operation->items as $item) {
            if (in_array((int) $item->sample_id, $claimedElsewhere, true)) {
                $shared++;
                continue;
            }

            /** @var Sample|null $sample */
            $sample = $item->sample_id ? Sample::find($item->sample_id) : null;

            if (! $sample) {
                continue;   // the slide is gone; its artefacts went with it
            }

            [$pathColumn, $columnsToClear] = match ($operation->type) {
                'patch_extraction' => ['tiles_gdrive_path', [
                    'tiling_status'          => 'pending',
                    'tile_count'             => null,
                    'tiles_path'             => null,
                    'tiles_gdrive_path'      => null,
                    'tiles_gdrive_folder_id' => null,
                    'tiling_completed_at'    => null,
                ]],
                'feature_extraction' => ['features_gdrive_path', [
                    'feature_extraction_status'       => 'pending',
                    'features_gdrive_path'            => null,
                    'features_gdrive_folder_id'       => null,
                    'features_patch_count'            => null,
                    'features_failed_patch_count'     => null,
                    'feature_extraction_completed_at' => null,
                    'feature_extraction_error'        => null,
                ]],
                default => [null, []],
            };

            if ($pathColumn === null) {
                continue;   // training produces no per-slide file to remove
            }

            $remotePath = $sample->{$pathColumn};

            if (filled($remotePath)) {
                // The stored value is the FOLDER the run wrote into, so it is
                // purged as a directory rather than as a single file.
                if ($drive->deleteRemotePath(ltrim($remotePath, '/'), true)) {
                    $purged++;
                } else {
                    $failed++;
                    Log::error("[DeleteOperationArtifacts] Sample #{$sample->id}: could not purge '{$remotePath}' — manual cleanup needed.");
                }
            }

            // The columns are cleared whether or not the purge succeeded: they
            // describe output this operation is disowning either way, and a
            // path left behind would let a later run believe patches exist.
            $sample->forceFill($columnsToClear)->save();
            $cleared++;
        }

        Log::info(sprintf(
            '[DeleteOperationArtifacts] Operation #%d (%s): %d folder(s) purged, %d failed, %d slide(s) reset, '
            . '%d slide(s) kept because another operation still accounts for their output.',
            $operation->id, $operation->type, $purged, $failed, $cleared, $shared
        ));

        if ($this->deleteRecord) {
            // Items go with it through the cascade on operation_id.
            $operation->delete();
            Log::info("[DeleteOperationArtifacts] Operation #{$this->operationId} record deleted.");
        }
    }
}
