<?php

namespace App\Jobs;

use App\Models\AiModel;
use App\Models\Magnification;
use App\Models\PatchSize;
use App\Models\Sample;
use App\Models\ServerName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FeatureExtractionJob
 * --------------------
 * Dispatches a feature-extraction job to a remote GPU server (RunPod) by
 * POSTing the full job specification to the server's /jobs/start endpoint.
 *
 * The remote server then:
 *   1. Syncs the patches archive from Google Drive (using `tiles_gdrive_path`)
 *   2. Loads the requested AI model
 *   3. Extracts features into HDF5
 *   4. Syncs the HDF5 + metadata back to Google Drive at `features_gdrive_path`
 *   5. POSTs status reports to /api/v1/feature-extraction/report
 *
 * Output GDrive path convention (mirrors sliced_slides hierarchy):
 *   {root}/features/{ai_model_slug}/{magnification}/{data_source}/{category}/{case_id}/sample_{id}_{size}px/
 */
class FeatureExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Plenty of time for HTTP dispatch + a wide network jitter window. */
    public int $timeout = 300;

    /** A single dispatch attempt — retry from the UI if it fails. */
    public int $tries = 1;

    public function __construct(
        public readonly int $sampleId,
        public readonly int $serverId,
        public readonly int $aiModelId,
    ) {
        $this->onQueue('operations');
    }

    public function handle(): void
    {
        /** @var Sample $sample */
        $sample = Sample::with(['dataSource', 'category', 'patientCase'])->findOrFail($this->sampleId);
        /** @var ServerName $server */
        $server = ServerName::findOrFail($this->serverId);
        /** @var AiModel $model */
        $model  = AiModel::findOrFail($this->aiModelId);

        $patchSize = $sample->patch_size_id
            ? PatchSize::find($sample->patch_size_id)
            : null;
        $magnification = $sample->magnification_id
            ? Magnification::find($sample->magnification_id)
            : null;

        if (!$patchSize || !$magnification) {
            $this->fail($sample, 'Sample is missing patch_size_id / magnification_id.');
            return;
        }

        if (!$sample->tiles_gdrive_path) {
            $this->fail($sample, 'Sample has no tiles_gdrive_path — patch extraction must run first.');
            return;
        }

        if ($server->type !== 'external' || !$server->api_url || !$server->api_key) {
            $this->fail($sample, "Server '{$server->name}' is not configured as a reachable external server (api_url / api_key missing).");
            return;
        }

        $payload = $this->buildPayload($sample, $server, $model, $patchSize, $magnification);

        Log::info('[FeatureExtractionJob] Dispatching', [
            'sample_id'   => $sample->id,
            'server'      => $server->name,
            'api_url'     => $server->api_url,
            'model'       => $model->name,
            'output_path' => $payload['gdrive_output_path'],
        ]);

        try {
            $response = Http::withToken($server->api_key)
                ->acceptJson()
                ->asJson()
                ->timeout(60)
                ->connectTimeout(15)
                ->retry(3, 5_000, function ($exception) {
                    return $exception instanceof \Illuminate\Http\Client\ConnectionException;
                }, throw: false)
                ->post(rtrim($server->api_url, '/') . '/jobs/start', $payload);

            if (!$response->successful()) {
                $this->fail(
                    $sample,
                    "RunPod returned HTTP {$response->status()}: " . substr($response->body(), 0, 500)
                );
                return;
            }

            $body = $response->json() ?? [];
            Log::info('[FeatureExtractionJob] Accepted by RunPod', [
                'sample_id'    => $sample->id,
                'remote_jobid' => $body['job_id'] ?? null,
            ]);

            // Persist target output path eagerly so admins can find it before
            // the RunPod side reports back.
            $sample->update([
                'feature_extraction_status'      => 'processing',
                'feature_extraction_ai_model_id' => $model->id,
                'feature_extraction_server_id'   => $server->id,
                'features_gdrive_path'           => $payload['gdrive_output_path'],
                'feature_extraction_error'       => null,
            ]);
        } catch (\Throwable $e) {
            $this->fail($sample, 'Dispatch error: ' . $e->getMessage());
        }
    }

    private function buildPayload(
        Sample $sample,
        ServerName $server,
        AiModel $model,
        PatchSize $patchSize,
        Magnification $magnification,
    ): array {
        $modelSlug = Str::slug($model->name);              // e.g. "TITAN" → "titan"
        $modelFolder = $model->name;                        // keep original case in folder name
        $magFolder = $magnification->folder_name;           // e.g. "20x"
        $sourceSlug = Str::slug($sample->dataSource?->name ?? 'unknown_source');
        $categorySlug = Str::slug($sample->category?->label_en ?? 'unknown_category');
        $caseId = $sample->patientCase?->case_id ?? 'no_case';
        $sampleFolder = "sample_{$sample->id}_{$patchSize->size_px}px";

        $gdriveRoot = rtrim((string) config('gdrive.root_folder', 'samples'), '/');

        // Output features GDrive path — same hierarchy as sliced_slides but
        // rooted at the AI model name so each model has its own tree.
        $outputPath = implode('/', [
            $gdriveRoot,
            'features',
            $modelFolder,
            $magFolder,
            $sourceSlug,
            $categorySlug,
            $caseId,
            $sampleFolder,
        ]);

        return [
            'sample_id'           => $sample->id,
            'slide_id'            => $sample->file_name ?: ('sample_' . $sample->id),
            'patch_size_px'       => $patchSize->size_px,
            'magnification'       => $magnification->label,
            'magnification_folder'=> $magFolder,

            // Input on Google Drive — produced by the patch-extraction stage
            'gdrive_input_path'   => $sample->tiles_gdrive_path,
            'gdrive_input_archive'=> 'patches.tar.gz',           // produced by PatchExtractionJob

            // Where the RunPod side must place the resulting features
            'gdrive_output_path'  => $outputPath,

            // AI model selection
            'ai_model' => [
                'id'             => $model->id,
                'name'           => $model->name,
                'slug'           => $modelSlug,
                'huggingface'    => $model->huggingface_url,
                'version'        => $model->version,
                'embedding_dim'  => $model->embedding_dim,
                'input_resolution' => $model->input_resolution,
            ],

            // Where to report progress / final status
            'callback' => [
                'url'    => rtrim((string) config('app.url'), '/') . '/api/v1/feature-extraction/report',
                // The RunPod side authenticates back to us with the same shared key.
                'token'  => $server->api_key,
                'method' => 'POST',
            ],

            // Tracing
            'dispatched_at' => now()->toIso8601String(),
        ];
    }

    private function fail(Sample $sample, string $message): void
    {
        Log::error('[FeatureExtractionJob] FAILED — sample #' . $sample->id . ': ' . $message);
        $sample->update([
            'feature_extraction_status' => 'failed',
            'feature_extraction_error'  => $message,
        ]);
    }
}
