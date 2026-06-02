<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\InferenceJob;
use App\Models\AiModel;
use App\Models\InferenceRun;
use App\Models\Sample;
use App\Models\ServerName;
use App\Models\TrainingRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InferenceController extends Controller
{
    /**
     * GET /admin/ai-diagnosis-test
     * Show the AI Diagnosis Test page.
     */
    public function index(Request $request): View
    {
        // Completed training runs available for inference
        $trainingRuns = TrainingRun::with(['trainingHead:id,name', 'featureModel:id,name'])
            ->where('status', 'completed')
            ->whereNotNull('model_gdrive_path')
            ->orderByDesc('id')
            ->get();

        // Servers active and suitable for inference (same servers used for CLAM)
        $servers = ServerName::where('is_active', true)->orderBy('name')->get();

        // Samples eligibility depends on the selected training_run (loaded via AJAX)
        // but we preload all completed-feature samples for the initial state
        $eligibleSamples = Sample::with(['category:id,label_en', 'organ:id,name'])
            ->where('feature_extraction_status', 'completed')
            ->whereNotNull('features_gdrive_path')
            ->orderByDesc('id')
            ->get(['id', 'file_name', 'category_id', 'organ_id', 'features_gdrive_path',
                   'feature_extraction_ai_model_id']);

        // Selected training run (if passed as query param for quick re-selection)
        $selectedRunId = $request->integer('training_run_id');

        // Recent inference history
        $history = InferenceRun::with([
                'trainingRun:id,training_head_id,feature_model_id,model_type,n_classes',
                'trainingRun.trainingHead:id,name',
                'trainingRun.featureModel:id,name',
                'sample:id,file_name',
            ])
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.ai-diagnosis-test', compact(
            'trainingRuns',
            'servers',
            'eligibleSamples',
            'selectedRunId',
            'history',
        ));
    }

    /**
     * POST /admin/ai-diagnosis-test/dispatch
     * Validate and dispatch an inference job.
     */
    public function dispatch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'training_run_id'           => ['required', 'integer', 'exists:training_runs,id'],
            'server_id'                 => ['required', 'integer', 'exists:servers_names,id'],
            'slide_source'              => ['required', 'in:sample,gdrive'],
            'sample_id'                 => ['required_if:slide_source,sample', 'nullable', 'integer', 'exists:samples,id'],
            'slide_name'                => ['required_if:slide_source,gdrive', 'nullable', 'string', 'max:500'],
            'slide_features_gdrive_path'=> ['required_if:slide_source,gdrive', 'nullable', 'string', 'max:1000'],
        ], [
            'training_run_id.required' => 'Please select a trained model / training run.',
            'server_id.required'       => 'Please select a server.',
            'sample_id.required_if'    => 'Please select a sample from the table.',
            'slide_name.required_if'   => 'Please provide a slide name.',
            'slide_features_gdrive_path.required_if' => 'Please provide the GDrive features path.',
        ]);

        /** @var TrainingRun $trainingRun */
        $trainingRun = TrainingRun::find($validated['training_run_id']);

        if ($trainingRun->status !== 'completed' || ! $trainingRun->model_gdrive_path) {
            return back()->withErrors(['training_run_id' => 'The selected training run is not completed or has no saved checkpoint.']);
        }

        // Resolve slide details
        $slideName              = null;
        $slideFeaturesPath      = null;
        $sampleId               = null;

        if ($validated['slide_source'] === 'sample') {
            /** @var Sample $sample */
            $sample = Sample::find($validated['sample_id']);

            if (! $sample || $sample->feature_extraction_status !== 'completed' || ! $sample->features_gdrive_path) {
                return back()->withErrors(['sample_id' => 'Selected sample does not have completed feature extraction.']);
            }

            // Ensure the feature model matches
            if ($sample->feature_extraction_ai_model_id !== $trainingRun->feature_model_id) {
                $trainingFeatureModel = $trainingRun->featureModel?->name ?? 'unknown';
                return back()->withErrors([
                    'sample_id' => "Feature model mismatch: sample was extracted with a different model than the training run ({$trainingFeatureModel}).",
                ]);
            }

            $slideName         = $sample->file_name;
            $slideFeaturesPath = $sample->features_gdrive_path;
            $sampleId          = $sample->id;

        } else {
            // gdrive mode — user provided path manually
            $slideName         = trim($validated['slide_name']);
            $slideFeaturesPath = trim($validated['slide_features_gdrive_path']);
        }

        // Create inference run record
        $inferenceRun = InferenceRun::create([
            'training_run_id'            => $validated['training_run_id'],
            'server_id'                  => $validated['server_id'],
            'slide_source'               => $validated['slide_source'],
            'sample_id'                  => $sampleId,
            'slide_name'                 => $slideName,
            'slide_features_gdrive_path' => $slideFeaturesPath,
            'status'                     => 'pending',
        ]);

        // Fix GDrive output dir with the real ID
        $inferenceRun->update([
            'gdrive_output_dir' => "inference/results/run_{$inferenceRun->id}",
        ]);

        // Dispatch background job
        InferenceJob::dispatch($inferenceRun->id);

        return redirect()->route('admin.ai-diagnosis-test')
            ->with('success', "Inference run #{$inferenceRun->id} dispatched for \"{$slideName}\". Results will appear in the history table once complete.");
    }

    /**
     * GET /admin/ai-diagnosis-test/samples-for-run/{trainingRun}
     * AJAX: return eligible samples for a given training run (matching feature model).
     */
    public function samplesForRun(TrainingRun $trainingRun): JsonResponse
    {
        $samples = Sample::where('feature_extraction_status', 'completed')
            ->whereNotNull('features_gdrive_path')
            ->where('feature_extraction_ai_model_id', $trainingRun->feature_model_id)
            ->with(['category:id,label_en', 'organ:id,name'])
            ->orderByDesc('id')
            ->get(['id', 'file_name', 'category_id', 'organ_id', 'features_gdrive_path',
                   'feature_extraction_ai_model_id']);

        return response()->json([
            'samples'       => $samples,
            'feature_model' => $trainingRun->featureModel?->name,
            'label_map'     => $trainingRun->label_map,
            'n_classes'     => $trainingRun->n_classes,
            'model_type'    => $trainingRun->model_type,
        ]);
    }

    /**
     * GET /admin/ai-diagnosis-test/{inferenceRun}/status
     * AJAX polling: return current status + result of an inference run.
     */
    public function status(InferenceRun $inferenceRun): JsonResponse
    {
        return response()->json([
            'id'          => $inferenceRun->id,
            'status'      => $inferenceRun->status,
            'prediction'  => $inferenceRun->prediction,
            'error'       => $inferenceRun->error,
            'finished_at' => $inferenceRun->finished_at?->toDateTimeString(),
        ]);
    }
}
