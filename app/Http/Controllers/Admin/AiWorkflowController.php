<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AdvanceWorkflow;
use App\Jobs\FetchSlideFromGdc;
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
use Illuminate\Support\Facades\Cache;
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
    /**
     * Directories the server-path intake may read from.
     *
     * The field names a file for a background worker to open, so leaving it
     * unconfined would let anyone with this form read anything the worker can.
     */
    private const PATH_ROOTS = ['/var/www/HISTO_AI/'];

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
        $chain = null;
        $watching = false;
        $evidence = null;
        $prediction = session('prediction');

        if ($id = $request->integer('sample_id')) {
            $sample = Sample::with(['patientCase:id,submitter_id', 'diseaseSubtype:id,name'])->find($id);
            if ($sample) {
                $state = $this->workflow->inspect($sample, $model);
                $chain = Cache::get(AdvanceWorkflow::stateKey($sample->id));
                // A prediction the queue produced outlives the one-shot session
                // flash, so an unattended run is still here when you come back.
                $prediction = $prediction ?: Cache::get(AdvanceWorkflow::resultKey($sample->id));

                // Evidence, if it has ever been built for this slide. It is
                // kept on disk rather than rebuilt per view because producing
                // it takes minutes.
                $dir = $this->workflow->evidenceDir($sample);
                $evidence = is_file("{$dir}/evidence.json")
                    ? json_decode((string) file_get_contents("{$dir}/evidence.json"), true)
                    : null;

                // Whether the page should keep reloading. A queued chain counts
                // even though every column still reads pending: the wait for a
                // worker is part of the run, and a page that goes quiet there
                // looks broken at the exact moment it is working.
                $watching = ($chain['status'] ?? null) === 'running'
                    || $sample->storage_status === 'downloading'
                    || $sample->tiling_status === 'processing'
                    || $sample->feature_extraction_status === 'processing';
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
            'models', 'modelKey', 'model', 'sample', 'state', 'prediction', 'ready', 'pending', 'chain', 'watching', 'evidence'
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
     * Bring a slide in, by whichever route moves the bytes best.
     *
     * A whole-slide image is one to three gigabytes, so the browser is the
     * worst possible carrier for it: no resume, a request held open for the
     * length of the copy, and a size ceiling to raise at every hop — nginx,
     * PHP, the request handler. The three routes that matter here all hand the
     * transfer to the queue and let the browser carry nothing but an
     * identifier: a path already on this server, a GDC file UUID, or a Drive
     * link. Direct upload stays for the occasional small file and nothing else.
     *
     * The record is created first and the transfer queued after, so a slide
     * that fails mid-copy is visible as a failed row rather than vanishing.
     */
    public function intake(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source'      => ['required', 'in:upload,gdrive,server_path,gdc'],
            'wsi'         => ['required_if:source,upload', 'file', 'mimes:svs,tiff,tif,ndpi,scn'],
            'gdrive_link' => ['required_if:source,gdrive', 'string', 'max:500'],
            'server_path' => ['required_if:source,server_path', 'string', 'max:1000'],
            'gdc_file_id' => ['required_if:source,gdc', 'string', 'max:64'],
            'gdc_md5'     => ['nullable', 'string', 'size:32'],
            'label'       => ['nullable', 'string', 'max:150'],
            'model'       => ['nullable', 'string'],
            'auto'        => ['nullable'],
        ]);

        // Resolve the server path before creating anything, so a typo leaves no
        // orphan row behind. It is confined to the slide directories: this field
        // names a file for a background job to read, and an unconfined one would
        // read any file on the box that the worker can reach.
        $localPath = null;
        if ($validated['source'] === 'server_path') {
            $localPath = realpath(trim($validated['server_path']));
            $allowed = false;
            foreach (self::PATH_ROOTS as $root) {
                if ($localPath && str_starts_with($localPath, $root)) {
                    $allowed = true;
                    break;
                }
            }
            if (! $localPath || ! is_file($localPath) || ! $allowed) {
                return back()->withErrors(['server_path' =>
                    'No readable slide at that path. It must sit under ' . implode(' or ', self::PATH_ROOTS)]);
            }
            if (! preg_match('/\.(svs|tiff?|ndpi|scn)$/i', $localPath)) {
                return back()->withErrors(['server_path' => 'That file is not a slide image.']);
            }
        }

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
            if ($validated['source'] === 'server_path') {
                // deleteSource stays false: this file belongs to whoever put it
                // there, and the intake form is not the place to destroy it.
                $sample->update(['file_name' => basename($localPath)]);
                ProcessSampleUpload::dispatch($sample->id, $localPath, null, null, null, null, false);
            } elseif ($validated['source'] === 'gdc') {
                $fileId = trim($validated['gdc_file_id']);
                $name = $this->gdcFileName($fileId);
                if (! $name) {
                    $sample->delete();
                    return back()->withErrors(['gdc_file_id' =>
                        'GDC does not know that file id, or it is not an open-access file.']);
                }
                $sample->update(['file_name' => $name, 'file_id' => $fileId]);
                FetchSlideFromGdc::dispatch($sample->id, $fileId, $name, $validated['gdc_md5'] ?? null);
            } elseif ($validated['source'] === 'upload') {
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

        $modelKey = $validated['model'] ?: config('diagnosis_models.default');
        $auto = (bool) ($validated['auto'] ?? false);
        if ($auto) {
            AdvanceWorkflow::markQueued($sample->id);
            AdvanceWorkflow::dispatch($sample->id, $modelKey)->delay(now()->addSeconds(10));
        }

        return redirect()->route('admin.ai-workflow', [
            'sample_id' => $sample->id, 'model' => $modelKey,
        ])->with('success', $auto
            ? "Slide accepted as #{$sample->id}. The queue will carry it the whole way — "
              . 'transfer, patches, features, then the model. This page follows along.'
            : "Slide accepted as #{$sample->id}. The transfer is queued; patch extraction "
              . 'becomes available once it lands.');
    }

    /** Hand a slide already in the system to the queue and let it finish. */
    public function autorun(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sample_id' => ['required', 'integer', 'exists:samples,id'],
            'model'     => ['required', 'string'],
        ]);

        Cache::forget(AdvanceWorkflow::resultKey($validated['sample_id']));
        AdvanceWorkflow::markQueued($validated["sample_id"]);
        AdvanceWorkflow::dispatch($validated["sample_id"], $validated["model"]);

        return redirect()->route('admin.ai-workflow', [
            'sample_id' => $validated['sample_id'], 'model' => $validated['model'],
        ])->with('success', 'The queue has it. Every remaining step runs without you.');
    }

    /**
     * The gate nginx asks before serving a slide tile.
     *
     * The tile service knows nothing about sessions — it only knows how to cut
     * JPEGs out of an SVS — so this is the only thing standing between patient
     * tissue and anyone who guesses a sample id. 204 means the caller is logged
     * in here; anything else and nginx refuses the tile.
     */
    public function tileAuth(Request $request)
    {
        return $request->user() ? response()->noContent() : abort(401);
    }

    /**
     * Register a slide with the tile service and open the viewer.
     *
     * The service will only serve slides named in this registry, so a request
     * for a sample nobody opened resolves to nothing rather than to a file
     * path assembled from a URL.
     */
    public function viewer(Request $request, Sample $sample)
    {
        $modelKey = $request->get('model', config('diagnosis_models.default'));
        $model = config("diagnosis_models.models.{$modelKey}");

        $mount = rtrim((string) config('services.gdrive_mount', '/mnt/gdrive'), '/');
        $wsi = $mount . '/' . ltrim((string) $sample->wsi_remote_path, '/');

        if (! $sample->wsi_remote_path || ! is_file($wsi)) {
            return redirect()->route('admin.ai-workflow', ['sample_id' => $sample->id])
                ->withErrors(['viewer' => 'The slide image is not readable on this server.']);
        }

        $dir = '/var/www/HISTO_AI/viewer';
        if (is_dir($dir) && is_writable($dir)) {
            file_put_contents("{$dir}/{$sample->id}.json", json_encode([
                'wsi' => $wsi, 'registered_at' => now()->toDateTimeString(),
            ]));
        }

        $evDir = $this->workflow->evidenceDir($sample);
        $evidence = is_file("{$evDir}/evidence.json")
            ? json_decode((string) file_get_contents("{$evDir}/evidence.json"), true)
            : null;

        return view('admin.ai-viewer', [
            'sample' => $sample,
            'model' => $model,
            'modelKey' => $modelKey,
            'evidence' => $evidence,
            'prediction' => Cache::get(AdvanceWorkflow::resultKey($sample->id)),
        ]);
    }

    /** Build the evidence view for a slide that has already been scored. */
    public function evidence(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sample_id' => ['required', 'integer', 'exists:samples,id'],
            'model'     => ['required', 'string'],
        ]);

        $model = config("diagnosis_models.models.{$validated['model']}");
        $sample = Sample::findOrFail($validated['sample_id']);
        $back = redirect()->route('admin.ai-workflow', [
            'sample_id' => $sample->id, 'model' => $validated['model'],
        ]);

        if (! $model) {
            return $back->withErrors(['model' => 'No such model is registered.']);
        }

        $result = $this->workflow->evidence($sample, $model);
        if (isset($result['error'])) {
            return $back->withErrors(['model' => $result['error']]);
        }

        return $back->with('success', 'Evidence built — the map and the patches are below.');
    }

    /**
     * Serve one evidence image.
     *
     * Through a route rather than a public symlink: these are pictures of
     * patient tissue, and the admin middleware that guards every other page
     * here should guard them too. The filename is whitelisted rather than
     * sanitised, because a whitelist cannot be walked out of.
     */
    public function evidenceImage(Sample $sample, string $file)
    {
        if (! in_array($file, ['evidence_map.png', 'top_patches.png', 'heatmap.png'], true)) {
            abort(404);
        }

        $path = $this->workflow->evidenceDir($sample) . '/' . $file;
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'image/png']);
    }

    /**
     * Ask GDC what a file id is called.
     *
     * Only the name is needed — the bytes are the queue's problem — but asking
     * first means a mistyped UUID fails here, in front of the person who typed
     * it, instead of an hour later inside a worker.
     */
    private function gdcFileName(string $fileId): ?string
    {
        try {
            $ch = curl_init("https://api.gdc.cancer.gov/files/{$fileId}?fields=file_name,access");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
            $body = curl_exec($ch);
            curl_close($ch);

            $data = json_decode((string) $body, true)['data'] ?? null;
            if (! is_array($data) || ($data['access'] ?? '') !== 'open') {
                return null;
            }
            return $data['file_name'] ?? null;
        } catch (\Throwable $e) {
            Log::error("[AiWorkflow] GDC lookup failed for {$fileId}: {$e->getMessage()}");
            return null;
        }
    }
}
