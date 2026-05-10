<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Sample;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * FeatureExtractionApiController
 * ------------------------------
 * Receives status reports from external GPU servers (RunPod) about the
 * feature-extraction stage of a sample.  This is the **only** endpoint
 * the RunPod side calls back into.
 *
 * Endpoints:
 *   POST /api/v1/feature-extraction/jobs/{sample}/start    – mark as processing
 *   POST /api/v1/feature-extraction/jobs/{sample}/complete – mark as completed
 *   POST /api/v1/feature-extraction/jobs/{sample}/fail     – mark as failed
 *   POST /api/v1/feature-extraction/report                 – unified report (status field)
 *   GET  /api/v1/feature-extraction/jobs/{sample}          – read current status
 *
 * All endpoints require the `verify.server.api_key` middleware.
 */
class FeatureExtractionApiController extends Controller
{
    /**
     * Unified report endpoint – RunPod sends a single payload with a `status` field.
     */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sample_id'                 => ['required', 'integer', 'exists:samples,id'],
            'slide_id'                  => ['nullable', 'string', 'max:255'],
            'status'                    => ['required', 'in:processing,completed,failed'],
            'runpod_output_path'        => ['nullable', 'string', 'max:500'],
            'features_gdrive_path'      => ['nullable', 'string', 'max:500'],
            'features_gdrive_folder_id' => ['nullable', 'string', 'max:100'],
            'patch_count'               => ['nullable', 'integer', 'min:0'],
            'failed_patch_count'        => ['nullable', 'integer', 'min:0'],
            'model_name'                => ['nullable', 'string', 'max:100'],
            'model_version'             => ['nullable', 'string', 'max:100'],
            'error_message'             => ['nullable', 'string', 'max:2000'],
        ]);

        $sample = Sample::findOrFail($data['sample_id']);
        $server = $request->attributes->get('server');

        $update = [
            'feature_extraction_status'       => $data['status'],
            'feature_extraction_server_id'    => $server?->id ?? $sample->feature_extraction_server_id,
        ];

        if ($data['status'] === 'completed') {
            $update['feature_extraction_completed_at'] = now();
            $update['features_runpod_path']            = $data['runpod_output_path']        ?? null;
            $update['features_gdrive_path']            = $data['features_gdrive_path']      ?? null;
            $update['features_gdrive_folder_id']       = $data['features_gdrive_folder_id'] ?? null;
            $update['features_patch_count']            = $data['patch_count']               ?? null;
            $update['features_failed_patch_count']     = $data['failed_patch_count']        ?? null;
            $update['features_model_version']          = $data['model_version']             ?? null;
            $update['feature_extraction_error']        = null;
        } elseif ($data['status'] === 'failed') {
            $update['feature_extraction_error'] = $data['error_message'] ?? 'Unknown error reported by RunPod.';
        }

        $sample->update($update);

        Log::info('[API/feature-extraction] Sample #' . $sample->id . ' → ' . $data['status'], [
            'server'  => $server?->name,
            'gdrive'  => $data['features_gdrive_path'] ?? null,
            'patches' => $data['patch_count'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status updated.',
            'sample'  => [
                'id'     => $sample->id,
                'status' => $update['feature_extraction_status'],
            ],
        ]);
    }

    /**
     * GET — read the current feature-extraction state of a sample.
     * Useful for the RunPod side to resume / verify after restarts.
     */
    public function show(Sample $sample): JsonResponse
    {
        return response()->json([
            'success' => true,
            'sample'  => [
                'id'                          => $sample->id,
                'slide_id'                    => $sample->file_name,
                'feature_extraction_status'   => $sample->feature_extraction_status,
                'feature_extraction_completed_at' => $sample->feature_extraction_completed_at,
                'features_gdrive_path'        => $sample->features_gdrive_path,
                'features_gdrive_folder_id'   => $sample->features_gdrive_folder_id,
                'features_runpod_path'        => $sample->features_runpod_path,
                'features_patch_count'        => $sample->features_patch_count,
                'features_failed_patch_count' => $sample->features_failed_patch_count,
                'features_model_version'      => $sample->features_model_version,
                'feature_extraction_error'    => $sample->feature_extraction_error,
            ],
        ]);
    }

    /**
     * Health-check / connectivity test.
     */
    public function health(Request $request): JsonResponse
    {
        $server = $request->attributes->get('server');
        return response()->json([
            'success' => true,
            'server'  => $server?->name,
            'time'    => now()->toIso8601String(),
        ]);
    }

    /**
     * Self-registration — RunPod calls this on boot to update its own api_url.
     * Authenticated via the same Bearer token (verify.server.api_key).
     */
    public function updateUrl(Request $request, int $serverId): JsonResponse
    {
        $data = $request->validate([
            'api_url' => ['required', 'url', 'max:255'],
        ]);

        // Use the authenticated server from middleware (ignores $serverId in URL)
        // This ensures the server can only update its own api_url regardless of ID.
        $server = $request->attributes->get('server');

        $affected = \DB::table('servers_names')
            ->where('id', $server->id)
            ->update(['api_url' => $data['api_url']]);

        if (!$affected) {
            return response()->json(['success' => false, 'message' => 'Server not found.'], 404);
        }

        Log::info('[API/server] Self-registered api_url for server #' . $server->id, [
            'api_url' => $data['api_url'],
        ]);

        return response()->json(['success' => true, 'api_url' => $data['api_url'], 'server_id' => $server->id]);
    }
}
