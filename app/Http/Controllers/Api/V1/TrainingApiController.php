<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TrainingRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives training callbacks from the RunPod CLAM server.
 *
 * Authentication: Bearer token matching the server's api_key in servers_names.
 */
class TrainingApiController extends Controller
{
    /**
     * POST /api/v1/training/progress
     * Incremental epoch update from the CLAM server.
     */
    public function progress(Request $request): JsonResponse
    {
        $data = $request->validate([
            'run_id'       => ['required', 'integer'],
            'epoch'        => ['required', 'integer'],
            'total_epochs' => ['required', 'integer'],
            'metrics'      => ['required', 'array'],
        ]);

        $run = TrainingRun::find($data['run_id']);
        if (! $run) {
            return response()->json(['error' => 'Run not found'], 404);
        }

        // Append epoch to history in metrics
        $metrics = $run->metrics ?? [];
        $metrics['history'][] = $data['metrics'];
        $run->update(['metrics' => $metrics, 'status' => 'processing']);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/training/report
     * Final result from CLAM server (success or failure).
     */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'run_id'     => ['required', 'integer'],
            'status'     => ['required', 'in:completed,failed,cancelled'],
            'metrics'    => ['nullable', 'array'],
            'model_path' => ['nullable', 'string'],
            'error'      => ['nullable', 'string'],
        ]);

        $run = TrainingRun::find($data['run_id']);
        if (! $run) {
            return response()->json(['error' => 'Run not found'], 404);
        }

        $run->update([
            'status'           => $data['status'],
            'metrics'          => $data['metrics'] ?? $run->metrics,
            'model_gdrive_path'=> $data['model_path'],
            'error'            => $data['error'],
            'finished_at'      => now(),
        ]);

        Log::info("[TrainingApiController] Run #{$run->id} finished with status={$data['status']}");

        return response()->json(['ok' => true]);
    }
}
