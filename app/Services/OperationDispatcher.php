<?php

namespace App\Services;

use App\Jobs\FeatureExtractionJob;
use App\Jobs\PatchExtractionJob;
use App\Models\AiModel;
use App\Models\Magnification;
use App\Models\Operation;
use App\Models\OperationItem;
use App\Models\PatchSize;
use App\Models\Sample;
use App\Models\ServerName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
     * Re-dispatch the slides of a run that the GPU worker has forgotten.
     *
     * The worker holds its queue in memory. Restarting it — to change settings,
     * or because the pod stopped — drops everything waiting there, while
     * Laravel goes on showing those slides as in flight for ever.
     *
     * Resuming asks the worker about each unfinished slide before touching it:
     *
     *   • the worker still knows it (queued or running) → LEFT ALONE. This is
     *     what makes the button safe to press twice, and safe to press while
     *     the run is genuinely working.
     *   • the worker has forgotten it, or reports it failed → re-dispatched
     *     inside this same operation.
     *   • the worker cannot be reached at all → nothing is dispatched, because
     *     "no answer" is not evidence the work was lost, and guessing wrong
     *     doubles every slide in the run.
     *
     * @return array{requeued: int, still_running: int, unreachable: bool}
     */
    public function resumeStalled(Operation $operation): array
    {
        $params  = $operation->params ?? [];
        $server  = ServerName::find($params['server_id'] ?? 0);
        $aiModel = (int) ($params['ai_model_id'] ?? 0);

        if ($operation->type !== 'feature_extraction' || ! $server || ! $server->api_url || $aiModel === 0) {
            return ['requeued' => 0, 'still_running' => 0, 'unreachable' => true];
        }

        // Anything not finished is a candidate; the worker decides which are real.
        $candidates = $operation->items()
            ->whereNotIn('status', ['completed', 'skipped'])
            ->get(['id', 'sample_id', 'remote_job_id', 'attempts']);

        if ($candidates->isEmpty()) {
            return ['requeued' => 0, 'still_running' => 0, 'unreachable' => false];
        }

        $base = rtrim($server->api_url, '/');

        try {
            Http::withToken($server->api_key)->timeout(10)->get($base . '/health')->throw();
        } catch (\Throwable $e) {
            Log::warning("[OperationResume] Worker for operation #{$operation->id} is unreachable: {$e->getMessage()}");

            return ['requeued' => 0, 'still_running' => 0, 'unreachable' => true];
        }

        $requeue = collect();
        $alive   = 0;

        foreach ($candidates as $item) {
            if ($item->remote_job_id) {
                try {
                    $r = Http::withToken($server->api_key)->acceptJson()->timeout(10)
                        ->get("{$base}/jobs/{$item->remote_job_id}");

                    $state = $r->successful() ? ($r->json()['status'] ?? null) : null;

                    // Known and not finished failing: the worker still owns it.
                    if (in_array($state, ['queued', 'running'], true)) {
                        $alive++;
                        continue;
                    }
                } catch (\Throwable) {
                    // Fall through: an unanswered question about ONE job is not
                    // grounds to assume it survived.
                }
            }

            $requeue->push($item);
        }

        if ($requeue->isEmpty()) {
            return ['requeued' => 0, 'still_running' => $alive, 'unreachable' => false];
        }

        $samples = Sample::whereIn('id', $requeue->pluck('sample_id')->filter())->get(['id']);
        $now     = now();

        DB::transaction(function () use ($operation, $requeue, $now) {
            OperationItem::whereIn('id', $requeue->pluck('id'))->update([
                'status'          => 'pending',
                'message'         => null,
                'remote_job_id'   => null,
                'attempts'        => DB::raw('attempts + 1'),
                'last_attempt_at' => $now,
                'updated_at'      => $now,
            ]);

            $operation->forceFill(['status' => 'running', 'finished_at' => null])->save();
        });

        foreach ($samples as $sample) {
            $sample->update([
                'feature_extraction_status' => 'processing',
                'feature_extraction_error'  => null,
            ]);

            FeatureExtractionJob::dispatch((int) $sample->id, (int) $server->id, $aiModel, $operation->id);
        }

        Log::info(sprintf(
            '[OperationResume] Operation #%d: re-queued %d slide(s); %d were still live on the worker.',
            $operation->id, $samples->count(), $alive
        ));

        return ['requeued' => $samples->count(), 'still_running' => $alive, 'unreachable' => false];
    }

    /**
     * Add slides to a feature-extraction run that is already open.
     *
     * A run started from a tiling operation covers whatever was ready at the
     * moment it was launched. Slides that were not ready then — patches still
     * being made, or missing — belong to the same piece of work, so when they
     * become available they join that run rather than starting a second one
     * covering the same intent.
     *
     * Only slides with features to extract are admitted, on the same terms as
     * the original dispatch, and one already in the run is never added twice.
     *
     * @param  array<int, int>  $sampleIds
     * @return array{queued: int, skipped: int}
     */
    public function addToFeatureRun(Operation $operation, array $sampleIds): array
    {
        $params  = $operation->params ?? [];
        $server  = (int) ($params['server_id'] ?? 0);
        $aiModel = (int) ($params['ai_model_id'] ?? 0);

        if ($operation->type !== 'feature_extraction' || $server === 0 || $aiModel === 0) {
            return ['queued' => 0, 'skipped' => count($sampleIds)];
        }

        $already  = $operation->items()->pluck('sample_id')->filter()->map(fn ($id) => (int) $id)->all();
        $accepted = collect();
        $skipped  = 0;

        foreach (array_diff($sampleIds, $already) as $sampleId) {
            /** @var Sample|null $sample */
            $sample = Sample::with('patientCase:id,submitter_id')->find($sampleId);

            if (! $sample || $sample->tiling_status !== 'done' || ! $sample->tiles_gdrive_path) {
                $skipped++;
                continue;
            }

            $accepted->push($sample);
        }

        if ($accepted->isEmpty()) {
            return ['queued' => 0, 'skipped' => $skipped + count(array_intersect($sampleIds, $already))];
        }

        $now = now();

        DB::transaction(function () use ($operation, $accepted, $now) {
            OperationItem::insert($accepted->map(fn (Sample $sample) => [
                'operation_id'      => $operation->id,
                'sample_id'         => $sample->id,
                'case_id'           => $sample->case_id,
                'sample_file_name'  => $sample->file_name,
                'case_submitter_id' => $sample->patientCase?->submitter_id,
                'status'            => 'pending',
                'attempts'          => 1,
                'last_attempt_at'   => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ])->all());

            // A run that had already settled has more to do again.
            $operation->forceFill(['status' => 'running', 'finished_at' => null])->save();
            $operation->recount();
        });

        foreach ($accepted as $sample) {
            $sample->update([
                'feature_extraction_status'      => 'processing',
                'feature_extraction_ai_model_id' => $aiModel,
                'feature_extraction_server_id'   => $server,
                'feature_extraction_error'       => null,
            ]);

            FeatureExtractionJob::dispatch((int) $sample->id, $server, $aiModel);
        }

        Log::info(sprintf(
            '[OperationTopUp] Operation #%d took on %d more slide(s); %d were not eligible.',
            $operation->id, $accepted->count(), $skipped
        ));

        return ['queued' => $accepted->count(), 'skipped' => $skipped];
    }

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
