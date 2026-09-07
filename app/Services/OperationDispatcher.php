<?php

namespace App\Services;

use App\Jobs\FeatureExtractionJob;
use App\Models\AiModel;
use App\Models\Operation;
use App\Models\Sample;
use App\Models\ServerName;

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
