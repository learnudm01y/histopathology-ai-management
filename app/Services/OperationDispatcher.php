<?php

namespace App\Services;

use App\Jobs\FeatureExtractionJob;
use App\Jobs\PatchExtractionJob;
use App\Models\AiModel;
use App\Models\Magnification;
use App\Models\Operation;
use App\Models\PatchSize;
use App\Models\Sample;
use App\Models\ServerName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queues pipeline work and opens the operation record that accounts for it.
 *
 * This lives outside the controllers because the same dispatch is now reachable
 * from two places: the Operations page, where slides are picked by filter, and
 * an operation's own review page, where the next stage is run over the slides
 * the previous stage finished. Both must produce an identical record — same
 * eligibility rules, same naming, same audit — so there is one implementation
 * rather than a copy that will drift.
 */
class OperationDispatcher
{
    /**
     * Re-run slides INSIDE the operation that already owns them.
     *
     * A batch dispatched together is one group, and it keeps its slides until
     * they are done. Opening a separate record for a retry split that group in
     * two and filed the eventual success under a run nobody asked for, so the
     * group is reopened instead and the outcome lands where the slide has
     * always belonged.
     *
     * The failed attempt is not erased by this. Each item counts its attempts
     * and stamps when it last ran, so "finished on the second try" survives the
     * status moving on — which is the part of the failure worth keeping.
     *
     * @param  array<int, int>  $sampleIds
     * @return array{queued: int, skipped: int}
     */
    public function retryWithin(Operation $operation, array $sampleIds): array
    {
        $params = $operation->params ?? [];

        foreach (['server_id', 'patch_size_id', 'magnification_id'] as $required) {
            if (empty($params[$required])) {
                return ['queued' => 0, 'skipped' => count($sampleIds)];
            }
        }

        $serverId        = (int) $params['server_id'];
        $patchSizeId     = (int) $params['patch_size_id'];
        $magnificationId = (int) $params['magnification_id'];

        $samples = Sample::whereIn('id', $sampleIds)->get(['id']);

        if ($samples->isEmpty()) {
            return ['queued' => 0, 'skipped' => count($sampleIds)];
        }

        DB::transaction(function () use ($operation, $samples, $serverId, $patchSizeId, $magnificationId) {
            $operation->items()
                ->whereIn('sample_id', $samples->pluck('id'))
                ->update([
                    'status'          => 'pending',
                    'message'         => null,
                    'attempts'        => DB::raw('attempts + 1'),
                    'last_attempt_at' => now(),
                    'updated_at'      => now(),
                ]);

            // Reopening is what lets the group record the outcome: a finished
            // operation is never re-derived, so it would otherwise keep
            // reporting the failure no matter how the retry went.
            $operation->forceFill([
                'status'      => 'running',
                'finished_at' => null,
            ])->save();
        });

        foreach ($samples as $sample) {
            Sample::whereKey($sample->id)->update([
                'tiling_status'    => 'processing',
                'patch_server_id'  => $serverId,
                'patch_size_id'    => $patchSizeId,
                'magnification_id' => $magnificationId,
            ]);

            PatchExtractionJob::dispatch((int) $sample->id, $serverId, $patchSizeId, $magnificationId);
        }

        Log::info(sprintf(
            '[OperationRetry] Operation #%d reopened; %d slide(s) re-queued inside it.',
            $operation->id, $samples->count()
        ));

        return ['queued' => $samples->count(), 'skipped' => count($sampleIds) - $samples->count()];
    }

    /**
     * Queue patch extraction over the given slides.
     *
     * Used both by the Operations page and by "retry the failed slides" on a
     * run's own review page, so a retry is dispatched under exactly the
     * settings and rules of the original.
     *
     * @param  array<int, int>  $sampleIds
     * @return array{queued: int, skipped: int, operation: Operation|null}
     */
    public function patchExtraction(
        array $sampleIds,
        int $serverId,
        int $patchSizeId,
        int $magnificationId,
        ?Operation $parent = null,
    ): array {
        $samples = Sample::with('patientCase:id,submitter_id')->whereIn('id', $sampleIds)->get();

        if ($samples->isEmpty()) {
            return ['queued' => 0, 'skipped' => count($sampleIds), 'operation' => null];
        }

        $server        = ServerName::find($serverId);
        $patchSize     = PatchSize::find($patchSizeId);
        $magnification = Magnification::find($magnificationId);

        // Opened before anything is queued: a dispatch that dies half way is
        // still the case worth reviewing.
        $operation = Operation::start(
            'patch_extraction',
            Operation::buildName('patch_extraction', [
                $patchSize ? $patchSize->size_px . 'px' : null,
                $magnification?->label,
                $server?->name,
            ], $samples->count()),
            $samples,
            array_filter([
                'server_id'           => $serverId,
                'server'              => $server?->name,
                'patch_size_id'       => $patchSizeId,
                'patch_size'          => $patchSize ? $patchSize->size_px . 'px' : null,
                'magnification_id'    => $magnificationId,
                'magnification'       => $magnification?->label,
                'source_operation_id' => $parent?->id,
            ], fn ($v) => $v !== null),
        );

        foreach ($samples as $sample) {
            Sample::whereKey($sample->id)->update([
                'tiling_status'    => 'processing',
                'patch_server_id'  => $serverId,
                'patch_size_id'    => $patchSizeId,
                'magnification_id' => $magnificationId,
            ]);

            PatchExtractionJob::dispatch((int) $sample->id, $serverId, $patchSizeId, $magnificationId);
        }

        return [
            'queued'    => $samples->count(),
            'skipped'   => count($sampleIds) - $samples->count(),
            'operation' => $operation,
        ];
    }

    /**
     * Queue feature extraction over the given slides.
     *
     * A slide is only admitted when its patches actually exist. Feature
     * extraction reads the patch archive from Drive, so dispatching one without
     * it produces a job that can only fail — and, worse, an operation record
     * claiming work that was never possible.
     *
     * @param  array<int, int>  $sampleIds
     * @return array{queued: int, skipped: int, operation: Operation|null}
     */
    public function featureExtraction(
        array $sampleIds,
        int $serverId,
        int $aiModelId,
        ?Operation $parent = null,
    ): array {
        $accepted = collect();
        $skipped  = 0;

        foreach ($sampleIds as $sampleId) {
            /** @var Sample|null $sample */
            $sample = Sample::with('patientCase:id,submitter_id')->find($sampleId);

            if (! $sample || $sample->tiling_status !== 'done' || ! $sample->tiles_gdrive_path) {
                $skipped++;
                continue;
            }

            $accepted->push($sample);

            $sample->update([
                'feature_extraction_status'      => 'processing',
                'feature_extraction_ai_model_id' => $aiModelId,
                'feature_extraction_server_id'   => $serverId,
                'feature_extraction_error'       => null,
            ]);

            FeatureExtractionJob::dispatch((int) $sample->id, $serverId, $aiModelId);
        }

        if ($accepted->isEmpty()) {
            return ['queued' => 0, 'skipped' => $skipped, 'operation' => null];
        }

        $server  = ServerName::find($serverId);
        $aiModel = AiModel::find($aiModelId);

        // Only the slides actually queued are recorded. A slide skipped for
        // missing patches was never part of this run, and putting it in the
        // audit would misreport what the operation touched.
        $operation = Operation::start(
            'feature_extraction',
            Operation::buildName('feature_extraction', [$aiModel?->name, $server?->name], $accepted->count()),
            $accepted,
            array_filter([
                'server_id'           => $serverId,
                'server'              => $server?->name,
                'ai_model_id'         => $aiModelId,
                'ai_model'            => $aiModel?->name,
                'source_operation_id' => $parent?->id,
            ], fn ($v) => $v !== null),
        );

        return ['queued' => $accepted->count(), 'skipped' => $skipped, 'operation' => $operation];
    }
}
