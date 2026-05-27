<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\FeatureExtractionJob;
use App\Jobs\PatchExtractionJob;
use App\Jobs\TrainingJob;
use App\Models\AiModel;
use App\Models\Sample;
use App\Models\ServerName;
use App\Models\TrainingRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Create and dispatch a CLAM training run.
     *
     * Expects POST body:
     *   sample_ids[]       — array of sample IDs (must have feature_extraction_status = "completed")
     *   server_id          — ID of the CLAM training server (servers_names)
     *   training_head_id   — ID of the CLAM model in ai_models
     *   feature_model_id   — ID of the feature extraction model used (TITAN/Virchow2)
     *   label_type         — 'category' | 'disease_type'
     *   label_map          — JSON string: { "0": "Normal", "1": "Malignant" }
     *   model_type         — 'clam_sb' | 'clam_mb'
     *   epochs             — int
     *   learning_rate      — float
     *   bag_size           — int (-1 = no limit)
     *   gdrive_output_dir  — GDrive output path (optional)
     */
    public function dispatchTraining(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sample_ids'        => ['required', 'array', 'min:2'],
            'sample_ids.*'      => ['integer', 'exists:samples,id'],
            'server_id'         => ['required', 'integer', 'exists:servers_names,id'],
            'training_head_id'  => ['required', 'integer', 'exists:ai_models,id'],
            'feature_model_id'  => ['required', 'integer', 'exists:ai_models,id'],
            'label_type'        => ['required', 'in:category,disease_type'],
            'label_map'         => ['required', 'string'],  // JSON
            'model_type'        => ['required', 'in:clam_sb,clam_mb'],
            'epochs'            => ['required', 'integer', 'min:1', 'max:200'],
            'learning_rate'     => ['required', 'numeric', 'min:0.000001', 'max:0.1'],
            'bag_size'          => ['required', 'integer', 'min:-1'],
            'n_classes'         => ['required', 'integer', 'min:2', 'max:10'],
            'gdrive_output_dir' => ['nullable', 'string', 'max:255'],
        ]);

        // Decode and validate label_map
        $labelMap = json_decode($validated['label_map'], true);
        if (! is_array($labelMap) || count($labelMap) < 2) {
            return redirect()->back()->withErrors(['label_map' => 'Label map must have at least 2 classes.']);
        }

        // Only include samples with completed feature extraction
        $eligibleSampleIds = Sample::whereIn('id', $validated['sample_ids'])
            ->where('feature_extraction_status', 'completed')
            ->whereNotNull('features_gdrive_path')
            ->pluck('id')
            ->toArray();

        $skipped = count($validated['sample_ids']) - count($eligibleSampleIds);

        if (count($eligibleSampleIds) < 2) {
            return redirect()->back()->withErrors([
                'sample_ids' => 'Not enough samples with completed feature extraction. At least 2 required.',
            ]);
        }

        // Create the training run record
        $run = TrainingRun::create([
            'training_head_id'  => $validated['training_head_id'],
            'feature_model_id'  => $validated['feature_model_id'],
            'server_id'         => $validated['server_id'],
            'status'            => 'pending',
            'sample_count'      => count($eligibleSampleIds),
            'label_type'        => $validated['label_type'],
            'label_map'         => $labelMap,
            'model_type'        => $validated['model_type'],
            'epochs'            => (int) $validated['epochs'],
            'learning_rate'     => (float) $validated['learning_rate'],
            'bag_size'          => (int) $validated['bag_size'],
            'n_classes'         => (int) $validated['n_classes'],
            'gdrive_output_dir' => $validated['gdrive_output_dir'] ?? "training/CLAM/run_",
        ]);

        // Fix gdrive_output_dir with the auto-generated run ID
        if (! $validated['gdrive_output_dir']) {
            $run->update(['gdrive_output_dir' => "training/CLAM/run_{$run->id}"]);
        }

        // Attach samples to the run
        $run->samples()->sync($eligibleSampleIds);

        // Dispatch the job
        TrainingJob::dispatch($run->id);

        $msg = "Training run #{$run->id} dispatched with {$run->sample_count} samples.";
        if ($skipped > 0) {
            $msg .= " {$skipped} sample(s) skipped (features not ready).";
        }

        return redirect()->back()->with('success', $msg);
    }
}
