<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InferenceRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives inference callbacks from the RunPod CLAM server.
 *
 * Authentication: Bearer token matching the server's api_key in servers_names.
 */
class InferenceApiController extends Controller
{
    /**
     * POST /api/v1/inference/report
     *
     * Final result posted by RunPod after inference completes.
     *
     * Expected body:
     * {
     *   "inference_run_id": 1,
     *   "status": "completed",
     *   "prediction": {
     *     "class_label": "Normal",
     *     "class_index": 0,
     *     "confidence": 0.923,
     *     "probabilities": { "0": 0.923, "1": 0.077 }
     *   },
     *   "attention_map_path": "inference/results/run_1/attention.png",
     *   "error": null
     * }
     */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inference_run_id'    => ['required', 'integer'],
            'status'              => ['required', 'in:completed,failed'],
            'prediction'          => ['nullable', 'array'],
            'prediction.class_label'    => ['nullable', 'string', 'max:255'],
            'prediction.class_index'    => ['nullable', 'integer'],
            'prediction.confidence'     => ['nullable', 'numeric', 'min:0', 'max:1'],
            'prediction.probabilities'  => ['nullable', 'array'],
            'attention_map_path'  => ['nullable', 'string', 'max:1000'],
            'error'               => ['nullable', 'string'],
        ]);

        $run = InferenceRun::find($data['inference_run_id']);
        if (! $run) {
            return response()->json(['error' => 'Inference run not found'], 404);
        }

        $run->update([
            'status'                   => $data['status'],
            'prediction'               => $data['prediction'] ?? null,
            'attention_map_gdrive_path'=> $data['attention_map_path'] ?? null,
            'error'                    => $data['error'] ?? null,
            'finished_at'              => now(),
        ]);

        Log::info("[InferenceApiController] Run #{$run->id} finished with status={$data['status']}");

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/inference/progress
     *
     * Optional intermediate progress update (e.g. loading model, loading features, running).
     *
     * Expected body:
     * {
     *   "inference_run_id": 1,
     *   "stage": "loading_features",
     *   "message": "Loading .h5 features file from GDrive..."
     * }
     */
    public function progress(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inference_run_id' => ['required', 'integer'],
            'stage'            => ['nullable', 'string', 'max:100'],
            'message'          => ['nullable', 'string', 'max:500'],
        ]);

        $run = InferenceRun::find($data['inference_run_id']);
        if (! $run) {
            return response()->json(['error' => 'Inference run not found'], 404);
        }

        // Mark as processing when we receive the first progress ping
        if ($run->status === 'pending') {
            $run->update(['status' => 'processing']);
        }

        Log::info("[InferenceApiController] Run #{$run->id} progress: stage={$data['stage']} — {$data['message']}");

        return response()->json(['ok' => true]);
    }
}
