<?php

namespace App\Jobs;

use App\Models\TrainingRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrainingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;  // HTTP dispatch timeout (the actual training is async on RunPod)
    public int $tries   = 2;

    public function __construct(
        private readonly int $trainingRunId
    ) {}

    public function handle(): void
    {
        $run = TrainingRun::with(['server', 'trainingHead', 'featureModel', 'samples'])->find($this->trainingRunId);

        if (! $run) {
            Log::error("[TrainingJob] TrainingRun #{$this->trainingRunId} not found");
            return;
        }

        $server = $run->server;
        if (! $server || ! $server->api_url) {
            Log::error("[TrainingJob] No server configured for run #{$run->id}");
            $run->update(['status' => 'failed', 'error' => 'No server configured']);
            return;
        }

        // ── Build sample payload split into train / val / test ───────────────
        //
        // Labels are NOT derived here. They were resolved once at dispatch time
        // (OperationsController + TrainingLabelResolver) and frozen into the
        // pivot, so a run is reproducible and a sample edited after dispatch
        // cannot silently change what the model was trained on.
        $featureModelName = $run->featureModel?->name ?? 'TITAN';
        $isHierarchical   = $run->isHierarchical();

        $splitBuckets = [1 => [], 2 => [], 3 => []]; // 1=train, 2=val, 3=test
        $unlabelled   = [];

        foreach ($run->samples as $sample) {
            $phase = (int) ($sample->pivot->training_phase ?? 1);
            $label = $sample->pivot->label;

            if ($label === null) {
                // Legacy run created before labels were frozen into the pivot,
                // or a corrupted attach. Fail loudly — never guess a class.
                $unlabelled[] = $sample->id;
                continue;
            }

            $entry = [
                'sample_id'            => $sample->id,
                'label'                => (int) $label,
                'training_phase'       => $phase,
                'gdrive_features_path' => $sample->features_gdrive_path ?? '',
            ];

            if ($isHierarchical) {
                $entry['parent_label'] = $sample->pivot->parent_label !== null
                    ? (int) $sample->pivot->parent_label
                    : -1;
            }

            $splitBuckets[$phase][] = $entry;
        }

        if (! empty($unlabelled)) {
            $ids   = implode(', ', array_slice($unlabelled, 0, 20));
            $error = count($unlabelled) . " sample(s) in this run have no resolved label "
                   . "(sample IDs: {$ids}). Re-create the run from the workflow page so labels are resolved.";
            Log::error("[TrainingJob] Run #{$run->id} — {$error}");
            $run->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
            return;
        }

        $nTrain = count($splitBuckets[1]);
        $nVal   = count($splitBuckets[2]);
        $nTest  = count($splitBuckets[3]);

        Log::info("[TrainingJob] Run #{$run->id} split — Train:{$nTrain} Val:{$nVal} Test:{$nTest}");

        // ── Build training params ─────────────────────────────────────────────
        $trainingParams = [
            'model_type'         => $run->model_type,
            'epochs'             => $run->epochs,
            'learning_rate'      => (float) $run->learning_rate,
            'bag_size'           => $run->bag_size,
            'n_classes'          => $run->n_classes,
            'use_class_weights'  => (bool) $run->use_class_weights,
        ];

        if ($isHierarchical) {
            $trainingParams['n_parent_classes'] = (int) $run->n_parent_classes;
            $trainingParams['hier_weight']      = (float) $run->hier_weight;
            $trainingParams['hierarchy_consistent_inference'] = (bool) $run->hierarchy_consistent_inference;
            // Fine class index → coarse class index, as a dense list so the
            // Python side never has to deal with JSON-object key ordering.
            $trainingParams['child_to_parent'] = $this->denseChildToParent(
                $run->child_to_parent ?? [],
                (int) $run->n_classes
            );
        }

        $payload = [
            'run_id'           => $run->id,
            'feature_model'    => $featureModelName,
            // Explicit three-way split — no random splitting on the server side
            'samples_train'    => $splitBuckets[1],
            'samples_val'      => $splitBuckets[2],
            'samples_test'     => $splitBuckets[3],
            // Legacy flat array kept for backward compatibility (same data, tagged)
            'samples'          => array_merge($splitBuckets[1], $splitBuckets[2], $splitBuckets[3]),
            'training_params'  => $trainingParams,
            'gdrive_output_dir' => $run->gdrive_output_dir ?? "training/CLAM/run_{$run->id}",
            // Sent for logging / checkpoint provenance on the training server
            'label_map'        => array_values($run->label_map ?? []),
            'parent_label_map' => $isHierarchical ? array_values($run->parent_label_map ?? []) : [],
        ];

        // ── Dispatch to RunPod CLAM server ────────────────────────────────────
        $run->update(['status' => 'processing', 'started_at' => now()]);

        try {
            $response = Http::withToken($server->api_key)
                ->timeout(60)
                ->post(rtrim($server->api_url, '/') . '/training/start', $payload);

            if ($response->successful()) {
                Log::info("[TrainingJob] Dispatched run #{$run->id} to CLAM server — response: " . $response->body());
            } else {
                $error = "CLAM server returned HTTP {$response->status()}: " . $response->body();
                Log::error("[TrainingJob] {$error}");
                $run->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
            }
        } catch (\Exception $e) {
            Log::error("[TrainingJob] HTTP error dispatching run #{$run->id}: " . $e->getMessage());
            $run->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
        }
    }

    /**
     * Turn { "0": 1, "2": 0 } into [1, -1, 0, …] of length n_classes.
     * -1 means "no parent known" and is ignored by the coarse loss.
     */
    private function denseChildToParent(array $map, int $nClasses): array
    {
        $dense = array_fill(0, max(0, $nClasses), -1);
        foreach ($map as $child => $parent) {
            $c = (int) $child;
            if ($c >= 0 && $c < $nClasses) {
                $dense[$c] = (int) $parent;
            }
        }
        return $dense;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[TrainingJob] Job failed for run #{$this->trainingRunId}: " . $exception->getMessage());
        TrainingRun::find($this->trainingRunId)?->update([
            'status'      => 'failed',
            'error'       => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
