<?php

namespace App\Jobs;

use App\Models\InferenceRun;
use App\Models\ServerName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InferenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;  // HTTP dispatch timeout (actual inference is async on RunPod)
    public int $tries   = 2;

    public function __construct(
        private readonly int $inferenceRunId
    ) {}

    public function handle(): void
    {
        $run = InferenceRun::with(['trainingRun.server', 'trainingRun.trainingHead', 'trainingRun.featureModel', 'server'])
                           ->find($this->inferenceRunId);

        if (! $run) {
            Log::error("[InferenceJob] InferenceRun #{$this->inferenceRunId} not found");
            return;
        }

        // Resolve server: inference run's own server, or fall back to training run's server
        $server = $run->server ?? $run->trainingRun?->server;

        if (! $server || ! $server->api_url) {
            Log::error("[InferenceJob] No server configured for inference run #{$run->id}");
            $run->update(['status' => 'failed', 'error' => 'No server configured']);
            return;
        }

        $trainingRun  = $run->trainingRun;
        $featureModel = $trainingRun?->featureModel?->name ?? 'TITAN';

        // GDrive output directory for this inference run
        $gdriveOutputDir = $run->gdrive_output_dir ?? "inference/results/run_{$run->id}";

        // Build payload for RunPod CLAM server
        $payload = [
            'inference_run_id'        => $run->id,
            'model_checkpoint_path'   => $trainingRun?->model_gdrive_path ?? '',
            'feature_model'           => $featureModel,
            'slide_features_path'     => $run->slide_features_gdrive_path ?? '',
            'slide_name'              => $run->slide_name ?? "slide_{$run->id}",
            'n_classes'               => $trainingRun?->n_classes ?? 2,
            'model_type'              => $trainingRun?->model_type ?? 'clam_sb',
            'label_map'               => $trainingRun?->label_map ?? [],
            'gdrive_output_dir'       => $gdriveOutputDir,
        ];

        // Mark as processing
        $run->update([
            'status'           => 'processing',
            'started_at'       => now(),
            'gdrive_output_dir' => $gdriveOutputDir,
        ]);

        try {
            $response = Http::withToken($server->api_key)
                ->timeout(120)
                ->post(rtrim($server->api_url, '/') . '/inference/start', $payload);

            if ($response->successful()) {
                Log::info("[InferenceJob] Dispatched inference run #{$run->id} to server — response: " . $response->body());
            } else {
                $error = "Server returned HTTP {$response->status()}: " . $response->body();
                Log::error("[InferenceJob] {$error}");
                $run->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
            }
        } catch (\Exception $e) {
            Log::error("[InferenceJob] HTTP error for run #{$run->id}: " . $e->getMessage());
            $run->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
        }
    }
}
