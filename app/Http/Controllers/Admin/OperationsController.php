<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\FeatureExtractionJob;
use App\Jobs\PatchExtractionJob;
use App\Models\Sample;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OperationsController extends Controller
{
    /**
     * Dispatch patch extraction for the selected samples.
     *
     * Expects POST body:
     *   sample_ids[]   — array of sample IDs to process
     *   server_id      — ID from servers_names
     *   patch_size_id  — ID from patch_sizes
     *   magnification_id — ID from magnifications
     */
    public function dispatchPatchExtraction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sample_ids'       => ['required', 'array', 'min:1'],
            'sample_ids.*'     => ['integer', 'exists:samples,id'],
            'server_id'        => ['required', 'integer', 'exists:servers_names,id'],
            'patch_size_id'    => ['required', 'integer', 'exists:patch_sizes,id'],
            'magnification_id' => ['required', 'integer', 'exists:magnifications,id'],
        ]);

        $count = 0;
        foreach ($validated['sample_ids'] as $sampleId) {
            // Mark as processing immediately so the UI reflects the queued state
            Sample::where('id', $sampleId)
                  ->whereNotIn('tiling_status', ['processing']) // avoid double-dispatch
                  ->update([
                      'tiling_status'    => 'processing',
                      'patch_server_id'  => $validated['server_id'],
                      'patch_size_id'    => $validated['patch_size_id'],
                      'magnification_id' => $validated['magnification_id'],
                  ]);

            PatchExtractionJob::dispatch(
                (int) $sampleId,
                (int) $validated['server_id'],
                (int) $validated['patch_size_id'],
                (int) $validated['magnification_id'],
            );

            $count++;
        }

        return redirect()
            ->back()
            ->with('success', "{$count} sample(s) queued for patch extraction. You can monitor progress via the Tiling Status column.");
    }

    /**
     * Dispatch feature extraction for the selected samples.
     *
     * Expects POST body:
     *   sample_ids[]     — array of sample IDs (must already have tiling_status = "done")
     *   server_id        — ID from servers_names (must be type=external for RunPod)
     *   ai_model_id      — ID from ai_models (selects which model to use, e.g. TITAN)
     */
    public function dispatchFeatureExtraction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sample_ids'   => ['required', 'array', 'min:1'],
            'sample_ids.*' => ['integer', 'exists:samples,id'],
            'server_id'    => ['required', 'integer', 'exists:servers_names,id'],
            'ai_model_id'  => ['required', 'integer', 'exists:ai_models,id'],
        ]);

        $count = 0;
        $skipped = 0;
        foreach ($validated['sample_ids'] as $sampleId) {
            /** @var Sample|null $sample */
            $sample = Sample::find($sampleId);

            // Only allow samples whose patches are ready
            if (!$sample || $sample->tiling_status !== 'done' || !$sample->tiles_gdrive_path) {
                $skipped++;
                continue;
            }

            $sample->update([
                'feature_extraction_status'      => 'processing',
                'feature_extraction_ai_model_id' => $validated['ai_model_id'],
                'feature_extraction_server_id'   => $validated['server_id'],
                'feature_extraction_error'       => null,
            ]);

            FeatureExtractionJob::dispatch(
                (int) $sampleId,
                (int) $validated['server_id'],
                (int) $validated['ai_model_id'],
            );

            $count++;
        }

        $msg = "{$count} sample(s) queued for feature extraction.";
        if ($skipped > 0) {
            $msg .= " {$skipped} skipped (patches not ready).";
        }

        return redirect()->back()->with('success', $msg);
    }
}
