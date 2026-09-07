<?php

namespace App\Jobs;

use App\Models\Operation;
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

        foreach ($operation->items as $item) {
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
            '[DeleteOperationArtifacts] Operation #%d (%s): %d folder(s) purged, %d failed, %d slide(s) reset.',
            $operation->id, $operation->type, $purged, $failed, $cleared
        ));

        if ($this->deleteRecord) {
            // Items go with it through the cascade on operation_id.
            $operation->delete();
            Log::info("[DeleteOperationArtifacts] Operation #{$this->operationId} record deleted.");
        }
    }
}
