<?php

namespace App\Jobs;

use App\Models\AiModel;
use App\Models\Magnification;
use App\Models\OperationItem;
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
 *   {root}/features/{ai_model_slug}/{magnification}/{data_source}/{organ}/{group}/{case_id}/sample_{id}_{size}px/
 */
class FeatureExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Plenty of time for HTTP dispatch + a wide network jitter window. */
    public int $timeout = 300;

    /**
     * 3 attempts total:
     *   attempt 1 → immediate
     *   attempt 2 → 90 s later  (server may still be booting)
     *   attempt 3 → 180 s later (last chance)
     */
    public int $tries = 3;

    public function backoff(): array
    {
        return [90, 180];
    }

    /**
     * The pod this job must talk to, when its operation is bound to one.
     *
     * `servers_names.api_url` is a single mutable field shared by every job, so
     * two operations could never run on two pods: whichever was selected last
     * would capture both. Carrying the endpoint on the job instead lets each
     * operation hold its own pod, which is the whole point of running more than
     * one — the work is submitted and processed remotely, so the pod, not the
     * queue, is what limits throughput.
     *
     * Not promoted and given a default so a job serialised before this existed
     * still unserialises cleanly and falls back to the server's own URL.
     */
    public ?string $endpoint = null;

    /**
     * The operation this slide belongs to, when it was dispatched as part of
     * one. Declared with a default rather than promoted so that a payload
     * serialised before this property existed still unserialises cleanly.
     */
    public ?int $operationId = null;

    public function __construct(
        public readonly int $sampleId,
        public readonly int $serverId,
        public readonly int $aiModelId,
        ?string $endpoint = null,
        ?int $operationId = null,
    ) {
        $this->endpoint    = $endpoint;
        $this->operationId = $operationId;
        $this->onQueue('operations');
    }

    /** Where this job should send its work: its own pod, else the server's URL. */
    private function endpointFor(ServerName $server): ?string
    {
        return $this->endpoint ?: $server->api_url;
    }

    public function handle(): void
    {
        /** @var Sample $sample */
        $sample = Sample::with(['dataSource', 'category', 'organ', 'patientCase'])->findOrFail($this->sampleId);
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

        // The operation's own pod when it has one, otherwise the server's URL.
        $endpoint = $this->endpointFor($server);

        if ($server->type !== 'external' || !$endpoint || !$server->api_key) {
            $this->fail($sample, "Server '{$server->name}' is not reachable: no pod endpoint for this operation, and no api_url / api_key configured.");
            return;
        }

        // ── Pre-dispatch health check ─────────────────────────────────────────
        // Avoids burning a tries-slot on a server that is still booting.
        // /health is unauthenticated on all RunPod services.
        try {
            $health = Http::timeout(10)->connectTimeout(5)
                ->get(rtrim($endpoint, '/') . '/health');

            if (!$health->successful()) {
                // Server exists but unhealthy — release back to queue for retry.
                $this->release(60);
                Log::warning('[FeatureExtractionJob] Server not healthy — releasing for retry', [
                    'sample_id' => $sample->id,
                    'server'    => $server->name,
                    'status'    => $health->status(),
                ]);
                return;
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Cannot reach the server at all — could be booting, release for retry.
            $this->release(90);
            Log::warning('[FeatureExtractionJob] Server unreachable — releasing for retry', [
                'sample_id' => $sample->id,
                'server'    => $server->name,
                'error'     => $e->getMessage(),
            ]);
            return;
        }

        $payload = $this->buildPayload($sample, $server, $model, $patchSize, $magnification);

        Log::info('[FeatureExtractionJob] Dispatching', [
            'sample_id'   => $sample->id,
            'server'      => $server->name,
            'endpoint'    => $endpoint,
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
                ->post(rtrim($endpoint, '/') . '/jobs/start', $payload);

            if (!$response->successful()) {
                $status = $response->status();
                $body   = substr($response->body(), 0, 500);

                if ($status === 403 || $status === 401) {
                    // Wrong API key — retrying is pointless, fail immediately.
                    $this->fail($sample, "HTTP {$status} (API key mismatch on server '{$server->name}'). Update the key in Settings → Servers. Response: {$body}");
                    return;
                }

                if ($status === 404 || $status >= 500) {
                    // Server not ready / internal error — re-throw so the job retries.
                    throw new \RuntimeException("RunPod returned HTTP {$status}: {$body}");
                }

                $this->fail($sample, "RunPod returned HTTP {$status}: {$body}");
                return;
            }

            $body = $response->json() ?? [];
            Log::info('[FeatureExtractionJob] Accepted by RunPod', [
                'sample_id'    => $sample->id,
                'remote_jobid' => $body['job_id'] ?? null,
            ]);

            // The worker's queue lives in memory, so this id is the only way to
            // ask later whether it still knows about this slide. Resuming reads
            // it to tell a slide still in flight from one a restart dropped.
            if ($this->operationId && ($body['job_id'] ?? null)) {
                OperationItem::where('operation_id', $this->operationId)
                    ->where('sample_id', $sample->id)
                    ->update(['remote_job_id' => $body['job_id']]);
            }

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
        // Organ-rooted: the same clinical-group name exists under several organs,
        // so the organ has to be in the path to keep the trees disjoint.
        $organSlug = Str::slug($sample->organ?->name ?? 'unknown_organ');
        $groupSlug = Str::slug($sample->category?->label_en ?? 'unknown_group');
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
            $organSlug,
            $groupSlug,
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
