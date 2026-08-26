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
            $this->failRun($run, 'No server configured');
            return;
        }

        // ── Pre-dispatch health check ─────────────────────────────────────────
        // The feature-extraction path already gated on /health; the training
        // path did not, so a run dispatched to a stale pod URL was reported as
        // failed with no way to tell "wrong URL" from "training crashed".
        // /health is unauthenticated on all RunPod services.
        try {
            $health = Http::timeout(10)->connectTimeout(5)
                ->get(rtrim($server->api_url, '/') . '/health');

            if (! $health->successful()) {
                Log::warning("[TrainingJob] Server not healthy — releasing run #{$run->id} for retry", [
                    'server' => $server->name,
                    'status' => $health->status(),
                ]);
                $this->release(60);
                return;
            }

            // Guard against pointing a training run at a feature-extraction
            // worker: every service answers /health, but only CLAM can train.
            $service = (string) ($health->json('service') ?? '');
            if ($service !== 'clam_training') {
                $error = "Server '{$server->name}' at {$server->api_url} is not a CLAM training server "
                       . "(/health reports service='{$service}', expected 'clam_training').";
                Log::error("[TrainingJob] {$error}");
                $this->failRun($run, $error);
                return;
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning("[TrainingJob] Server unreachable — releasing run #{$run->id} for retry", [
                'server' => $server->name,
                'error'  => $e->getMessage(),
            ]);
            $this->release(90);
            return;
        }

        // ── Validate the label map before touching any sample ─────────────────
        $labelMap = $run->label_map ?? [];   // int → label string

        if (! is_array($labelMap) || count($labelMap) < 2) {
            $this->failRun($run, 'label_map must contain at least 2 classes.');
            return;
        }

        // Label indices must be contiguous 0..n-1: they are used directly as
        // cross-entropy targets, so a gap (e.g. {0, 2} after a UI row was
        // removed) makes the worker raise "Target 2 is out of bounds".
        $indices = array_map('intval', array_keys($labelMap));
        sort($indices);
        if ($indices !== range(0, count($labelMap) - 1)) {
            $this->failRun($run, sprintf(
                'label_map indices must be contiguous 0..%d, got [%s].',
                count($labelMap) - 1,
                implode(', ', $indices)
            ));
            return;
        }

        if ((int) $run->n_classes !== count($labelMap)) {
            $this->failRun($run, sprintf(
                'n_classes (%d) does not match label_map size (%d).',
                (int) $run->n_classes,
                count($labelMap)
            ));
            return;
        }

        // ── Build sample payload split into train / val / test ───────────────
        $featureModelName = $run->featureModel?->name ?? 'TITAN';
        $labelType        = $run->label_type;

        $splitBuckets = [1 => [], 2 => [], 3 => []]; // 1=train, 2=val, 3=test
        $unmatched    = [];                          // raw label => count

        foreach ($run->samples as $sample) {
            $phase = (int) ($sample->pivot->training_phase ?? 1);

            $rawLabel = match ($labelType) {
                'disease_type' => $sample->patientCase?->disease_type ?? 'unknown',
                default        => $sample->category?->label_en ?? 'Unknown',
            };

            $numericLabel = array_search($rawLabel, $labelMap, true);

            // Previously an unmatched label silently became class 0, which turned
            // any casing/wording mismatch into a dataset where every slide
            // carried the first class — training then reported ~100% accuracy on
            // a constant label. Collect and abort instead of guessing.
            if ($numericLabel === false) {
                $key = $rawLabel === '' ? '<empty>' : (string) $rawLabel;
                $unmatched[$key] = ($unmatched[$key] ?? 0) + 1;
                continue;
            }

            $splitBuckets[$phase][] = [
                'sample_id'            => $sample->id,
                'label'                => (int) $numericLabel,
                'training_phase'       => $phase,
                'gdrive_features_path' => $sample->features_gdrive_path ?? '',
            ];
        }

        if (! empty($unmatched)) {
            arsort($unmatched);
            $detail = implode(', ', array_map(
                fn ($lbl, $n) => "\"{$lbl}\" ×{$n}",
                array_keys($unmatched),
                $unmatched
            ));
            $error = sprintf(
                'Aborted: %d sample(s) have a %s value that is not present in label_map [%s]. Unmatched: %s. '
                . 'Fix label_map to use the exact stored values (they are case-sensitive) or exclude those samples.',
                array_sum($unmatched),
                $labelType === 'disease_type' ? 'disease_type' : 'category',
                implode(', ', array_values($labelMap)),
                $detail
            );
            Log::error("[TrainingJob] Run #{$run->id} {$error}");
            $this->failRun($run, $error);
            return;
        }

        $nTrain = count($splitBuckets[1]);
        $nVal   = count($splitBuckets[2]);
        $nTest  = count($splitBuckets[3]);

        // A validation split with a single class makes roc_auc_score undefined,
        // which the worker reports as val_auc = 0.0 — and that silently disables
        // best-checkpoint selection for the whole run.
        $valClasses = count(array_unique(array_column($splitBuckets[2], 'label')));
        if ($nVal < 2 || $valClasses < 2) {
            $error = sprintf(
                'Validation split must contain at least 2 samples covering at least 2 classes '
                . '(got %d sample(s), %d class(es)). Without both classes the AUC cannot be computed '
                . 'and model selection is disabled.',
                $nVal,
                $valClasses
            );
            Log::error("[TrainingJob] Run #{$run->id} {$error}");
            $this->failRun($run, $error);
            return;
        }

        $trainClasses = count(array_unique(array_column($splitBuckets[1], 'label')));
        if ($nTrain < 2 || $trainClasses < 2) {
            $error = sprintf(
                'Train split must contain at least 2 classes (got %d sample(s), %d class(es)).',
                $nTrain,
                $trainClasses
            );
            Log::error("[TrainingJob] Run #{$run->id} {$error}");
            $this->failRun($run, $error);
            return;
        }

        Log::info("[TrainingJob] Run #{$run->id} split — Train:{$nTrain} Val:{$nVal} Test:{$nTest}");

        // ── Build training params ─────────────────────────────────────────────
        // Everything the worker's TrainingConfig accepts is sent explicitly.
        // Previously only 5 of these were sent and the rest fell back to
        // hard-coded defaults inside the worker, so a finished run could not be
        // reproduced from its database row.
        $trainingParams = [
            'model_type'          => $run->model_type,
            'epochs'              => $run->epochs,
            'learning_rate'       => (float) $run->learning_rate,
            'bag_size'            => $run->bag_size,
            'n_classes'           => $run->n_classes,
            'seed'                => 42,
            'bag_weight'          => 0.7,
            'use_instance_loss'   => true,
            'dropout'             => 0.25,
            'hidden_dim'          => 256,
            'weight_decay'        => 1.0e-5,
            'early_stop_patience' => 20,
            'class_weighting'     => true,
        ];

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
        ];

        // ── Dispatch to RunPod CLAM server ────────────────────────────────────
        // Record what this run was actually dispatched with, so the numbers it
        // reports later can be traced back to an exact configuration.
        $metrics = $run->metrics ?? [];
        $metrics['provenance'] = [
            'dispatched_at'    => now()->toIso8601String(),
            'server'           => $server->name,
            'server_api_url'   => $server->api_url,
            'feature_model'    => $featureModelName,
            'label_type'       => $labelType,
            'label_map'        => $labelMap,
            'split'            => ['train' => $nTrain, 'val' => $nVal, 'test' => $nTest],
            'training_params'  => $trainingParams,
        ];

        $run->update([
            'status'     => 'processing',
            'started_at' => now(),
            'metrics'    => $metrics,
            'error'      => null,
        ]);

        try {
            $response = Http::withToken($server->api_key)
                ->timeout(60)
                ->post(rtrim($server->api_url, '/') . '/training/start', $payload);

            if ($response->successful()) {
                Log::info("[TrainingJob] Dispatched run #{$run->id} to CLAM server — response: " . $response->body());
            } else {
                $error = "CLAM server returned HTTP {$response->status()}: " . $response->body();
                Log::error("[TrainingJob] {$error}");
                $this->failRun($run, $error);
            }
        } catch (\Exception $e) {
            Log::error("[TrainingJob] HTTP error dispatching run #{$run->id}: " . $e->getMessage());
            $this->failRun($run, $e->getMessage());
        }
    }

    /**
     * Mark a run failed with a message, preserving whatever metrics it already has.
     */
    private function failRun(TrainingRun $run, string $error): void
    {
        $run->update([
            'status'      => 'failed',
            'error'       => $error,
            'finished_at' => now(),
        ]);
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
