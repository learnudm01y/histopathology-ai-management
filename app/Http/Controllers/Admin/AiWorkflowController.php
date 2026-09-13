<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSampleUpload;
use App\Models\Category;
use App\Models\DataSource;
use App\Models\Organ;
use App\Models\Sample;
use App\Services\DiagnosisWorkflow;
use App\Services\GoogleDriveService;
use App\Services\OperationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * One page that takes a slide from wherever it is to an answer.
 *
 * A slide can arrive three ways — already in the database, uploaded here, or
 * pulled from a Drive link — and can be at any point in the pipeline when it
 * does. Rather than assume, the page reads the slide's actual state and offers
 * only the step that unblocks it: patch extraction if it has no patches,
 * feature extraction if it has no features, the model if it has both.
 *
 * The model is chosen from a registry rather than hardcoded, so a second model
 * is a config entry and not a rewrite of this controller.
 */
class AiWorkflowController extends Controller
{
    public function __construct(
        private readonly DiagnosisWorkflow $workflow,
        private readonly OperationDispatcher $dispatcher,
    ) {}

    public function index(Request $request)
    {
        $models = config('diagnosis_models.models', []);
        $modelKey = $request->get('model', config('diagnosis_models.default'));
        $model = $models[$modelKey] ?? reset($models) ?: null;

        $sample = null;
        $state = null;
        $prediction = session('prediction');

        if ($id = $request->integer('sample_id')) {
            $sample = Sample::with(['patientCase:id,submitter_id', 'diseaseSubtype:id,name'])->find($id);
            if ($sample) {
                $state = $this->workflow->inspect($sample, $model);
            }
        }

        // Slides that could be scored today, so the picker is not a list of
        // things that will only turn out to be unusable.
        $ready = Sample::where('feature_extraction_status', 'completed')
            ->whereNotNull('features_gdrive_path')
            ->with(['diseaseSubtype:id,name', 'patientCase:id,submitter_id'])
            ->orderByDesc('id')
            ->limit(400)
            ->get(['id', 'entity_submitter_id', 'file_name', 'disease_subtype_id',
                   'case_id', 'features_patch_count', 'features_model_version',
                   'tile_size_px', 'magnification', 'tiling_status',
                   'feature_extraction_status']);

        $pending = Sample::where(function ($q) {
                $q->where('tiling_status', '!=', 'done')
                  ->orWhere('feature_extraction_status', '!=', 'completed');
            })
            ->where('storage_status', 'available')
            ->orderByDesc('id')->limit(200)
            ->get(['id', 'entity_submitter_id', 'file_name', 'tiling_status',
                   'feature_extraction_status']);

        return view('admin.ai-workflow', compact(
            'models', 'modelKey', 'model', 'sample', 'state', 'prediction', 'ready', 'pending'
        ));
    }

    /** Re-read a slide's stage without reloading the page. */
    public function state(Request $request, Sample $sample): JsonResponse
    {
        $models = config('diagnosis_models.models', []);
        $model = $models[$request->get('model', config('diagnosis_models.default'))] ?? null;
        return response()->json($this->workflow->inspect($sample, $model));
    }

    /** Queue whichever step the slide is missing, as a tracked operation. */
    public function advance(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sample_id' => ['required', 'integer', 'exists:samples,id'],
            'step'      => ['required', 'in:patches,features'],
            'model'     => ['nullable', 'string'],
        ]);

        $sample = Sample::findOrFail($validated['sample_id']);
        $back = redirect()->route('admin.ai-workflow', [
            'sample_id' => $sample->id, 'model' => $validated['model'] ?? null,
        ]);

        if ($validated['step'] === 'patches') {
            // The settings the deployed models expect: 224px at 20x on the
            // local server. Anything else produces features no model can read.
            $r = $this->dispatcher->patchExtraction([$sample->id], 1, 1, 2);
            return $back->with('success', $r['operation']
                ? "Patch extraction queued as \"{$r['operation']->name}\". Follow it under Operations."
                : 'Nothing was queued — the slide may already be processing.');
        }

        $r = $this->dispatcher->featureExtraction([$sample->id], 3, 1);
        if (! $r['operation']) {
            return $back->withErrors(['step' =>
                'Feature extraction was refused: the slide has no patches on Drive yet.']);
        }
        return $back->with('success',
            "Feature extraction queued as \"{$r['operation']->name}\". "
            . 'It runs on the GPU pod and takes a few minutes per slide.');
    }

    /** Score a slide that is ready. */
    public function predict(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sample_id' => ['required', 'integer', 'exists:samples,id'],
            'model'     => ['required', 'string'],
        ]);

        $models = config('diagnosis_models.models', []);
        $model = $models[$validated['model']] ?? null;
        $sample = Sample::findOrFail($validated['sample_id']);
        $back = redirect()->route('admin.ai-workflow', [
            'sample_id' => $sample->id, 'model' => $validated['model'],
        ]);

        if (! $model) {
            return $back->withErrors(['model' => 'No such model is registered.']);
        }

        $state = $this->workflow->inspect($sample, $model);
        if ($state['stage'] !== DiagnosisWorkflow::STAGE_READY) {
            return $back->withErrors(['model' =>
                $state['blocking'] ?? 'This slide is not ready to be scored.']);
        }

        $result = $this->workflow->predict($sample, $model);
        if (isset($result['error'])) {
            return $back->withErrors(['model' => $result['error']]);
        }

        $result['model_key'] = $validated['model'];
        $result['model_label'] = $model['label'];
        return $back->with('prediction', $result);
    }

    /**
     * Bring a slide in from a Drive link, or from an uploaded file.
     *
     * The record is created first and the transfer queued after, so a slide
     * that fails mid-copy is visible as a failed row rather than vanishing.
     */
    public function intake(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source'      => ['required', 'in:upload,gdrive'],
            'wsi'         => ['required_if:source,upload', 'file', 'mimes:svs,tiff,tif,ndpi,scn'],
            'gdrive_link' => ['required_if:source,gdrive', 'string', 'max:500'],
            'label'       => ['nullable', 'string', 'max:150'],
            'model'       => ['nullable', 'string'],
        ]);

        $organ = Organ::where('name', 'Breast')->first();
        $category = $organ
            ? Category::where('organ_id', $organ->id)->where('label_en', 'tumor')->first()
            : null;
        $source = DataSource::where('name', 'TCGA-BRCA')->first();

        $sample = Sample::create([
            'organ_id'       => $organ?->id,
            'category_id'    => $category?->id,
            'data_source_id' => $source?->id,
            'entity_type'    => 'slide',
            'data_format'    => 'SVS',
            'tile_size_px'   => 224,
            'magnification'  => '20x',
            'storage_status' => 'not_downloaded',
            'entity_submitter_id' => $validated['label'] ?: null,
            'is_usable'      => true,
        ]);

        try {
            if ($validated['source'] === 'upload') {
                $path = $request->file('wsi')->store('wsi_intake');
                $sample->update(['file_name' => $request->file('wsi')->getClientOriginalName()]);
                ProcessSampleUpload::dispatch($sample->id, storage_path("app/{$path}"));
            } else {
                $drive = app(GoogleDriveService::class);
                $fileId = $drive->extractFileIdFromUrl($validated['gdrive_link'])
                          ?: trim($validated['gdrive_link']);
                $name = $drive->getFileNameFromDriveId($fileId);
                if (! $name) {
                    $sample->delete();
                    return back()->withErrors(['gdrive_link' =>
                        'That Drive link could not be read. Check it is shared with this account.']);
                }
                $sample->update(['file_name' => $name, 'gdrive_source_id' => $fileId]);
                ProcessSampleUpload::dispatch($sample->id, null, $fileId, $name);
            }
        } catch (\Throwable $e) {
            Log::error("[AiWorkflow] intake failed for sample #{$sample->id}: {$e->getMessage()}");
            $sample->delete();
            return back()->withErrors(['source' => 'Could not take the slide in: ' . $e->getMessage()]);
        }

        return redirect()->route('admin.ai-workflow', [
            'sample_id' => $sample->id, 'model' => $validated['model'] ?? null,
        ])->with('success',
            "Slide accepted as #{$sample->id}. The transfer is queued; patch extraction "
            . 'becomes available once it lands.');
    }
}
