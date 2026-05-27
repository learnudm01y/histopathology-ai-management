<?php

namespace App\Jobs;

use App\Models\Sample;
use App\Models\ServerName;
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

        // ── Build sample payload ──────────────────────────────────────────────
        $featureModelName = $run->featureModel?->name ?? 'TITAN';
        $labelType        = $run->label_type;
        $labelMap         = $run->label_map ?? [];   // int → label string

        $samplesPayload = $run->samples->map(function (Sample $sample) use ($featureModelName, $labelMap, $labelType) {
            // Determine numeric label
            $rawLabel = match ($labelType) {
                'disease_type' => $sample->patientCase?->disease_type ?? 'unknown',
                default        => $sample->category?->label_en ?? 'Unknown',
            };
            $numericLabel = array_search($rawLabel, $labelMap, true);
            if ($numericLabel === false) {
                $numericLabel = 0;
            }

            return [
                'sample_id'            => $sample->id,
                'label'                => (int) $numericLabel,
                'gdrive_features_path' => $sample->features_gdrive_path ?? '',
            ];
        })->values()->all();

        // ── Build training params ─────────────────────────────────────────────
        $trainingParams = [
            'model_type'         => $run->model_type,
            'epochs'             => $run->epochs,
            'learning_rate'      => (float) $run->learning_rate,
            'bag_size'           => $run->bag_size,
            'n_classes'          => $run->n_classes,
        ];

        $payload = [
            'run_id'           => $run->id,
            'feature_model'    => $featureModelName,
            'samples'          => $samplesPayload,
            'training_params'  => $trainingParams,
            'gdrive_output_dir' => $run->gdrive_output_dir ?? "training/CLAM/run_{$run->id}",
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
