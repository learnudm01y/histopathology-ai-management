<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSampleUpload;
use App\Jobs\RunV2Diagnosis;
use App\Models\Organ;
use App\Models\Sample;
use App\Models\Stain;
use App\Models\V2Diagnosis;
use App\Services\V2DiagnoseRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * V2 Diagnose: clinical context + a slide in, Claude's reading drawn on the
 * live slide out. No chat — one request, one answer of at most five lines,
 * and the coordinates behind it.
 */
class V2DiagnoseController extends Controller
{
    /** Same confinement as the AI workflow intake: the path is read by a worker. */
    private const PATH_ROOTS = ['/var/www/HISTO_AI/'];

    public function __construct(private readonly V2DiagnoseRunner $runner) {}

    public function index(Request $request)
    {
        $withTruth = ['sample:id,entity_submitter_id,file_name,category_id,disease_subtype_id',
                      'sample.category:id,label_en', 'sample.diseaseSubtype:id,name'];
        $runs = V2Diagnosis::with($withTruth)->latest('id')->paginate(25);

        // The tally across every finished run whose slide has a recorded
        // diagnosis — not only the page shown.
        $tally = ['correct' => 0, 'partial' => 0, 'wrong' => 0];
        V2Diagnosis::with($withTruth)->where('status', 'completed')
            ->get(['id', 'sample_id', 'status', 'diagnosis_code'])
            ->each(function ($r) use (&$tally) {
                if ($v = $r->verdict()) {
                    $tally[$v['result']]++;
                }
            });

        $samples = Sample::where('storage_status', 'available')
            ->whereNotNull('wsi_remote_path')
            ->orderByDesc('id')->limit(1000)
            ->get(['id', 'entity_submitter_id', 'file_name']);

        return view('admin.v2-diagnose.index', [
            'runs'    => $runs,
            'tally'   => $tally,
            'samples' => $samples,
            'organs'  => Organ::orderBy('name')->pluck('name'),
            'stains'  => Stain::orderBy('name')->pluck('name'),
            'picked'  => $request->integer('sample_id') ?: null,
        ]);
    }

    /** What the database already knows about a slide, to pre-fill the form. */
    public function sampleContext(Sample $sample): JsonResponse
    {
        $sample->loadMissing(['organ:id,name', 'stain:id,name', 'patientCase.clinicalInfo']);
        $c = $sample->patientCase?->clinicalInfo;
        $age = $c?->age_at_index;
        if ($age === null && $c?->days_to_birth) {
            $age = (int) floor(abs($c->days_to_birth) / 365.25);
        }

        return response()->json([
            'organ' => $sample->organ?->name,
            'stain' => $sample->stain?->name,
            'age'   => $age,
            'sex'   => $c?->gender ?: $sample->patientCase?->gender,
            'race'  => $c?->race && ! in_array(strtolower($c->race), ['not reported', 'unknown'], true) ? $c->race : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'source'         => ['required', 'in:existing,server_path,upload'],
            'sample_id'      => ['required_if:source,existing', 'nullable', 'integer', 'exists:samples,id'],
            'server_path'    => ['required_if:source,server_path', 'nullable', 'string', 'max:1000'],
            'wsi'            => ['required_if:source,upload', 'nullable', 'file', 'mimes:svs,tiff,tif,ndpi,scn'],
            'label'          => ['nullable', 'string', 'max:150'],
            'organ'          => ['required', 'string', 'max:100'],
            'stain'          => ['nullable', 'string', 'max:100'],
            'age'            => ['nullable', 'integer', 'min:0', 'max:120'],
            'sex'            => ['nullable', 'in:female,male,other'],
            'race'           => ['nullable', 'string', 'max:100'],
            'clinical_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $wsiPath = null;
        if ($v['source'] === 'existing') {
            $sample = Sample::findOrFail($v['sample_id']);
        } else {
            [$sample, $wsiPath, $error] = $this->intake($request, $v);
            if ($error) {
                return back()->withInput()->withErrors(['source' => $error]);
            }
        }

        $run = V2Diagnosis::create([
            'sample_id'      => $sample->id,
            'user_id'        => $request->user()?->id,
            'organ'          => $v['organ'],
            'stain'          => $v['stain'] ?? null,
            'age'            => $v['age'] ?? null,
            'sex'            => $v['sex'] ?? null,
            'race'           => $v['race'] ?? null,
            'clinical_notes' => $v['clinical_notes'] ?? null,
            'wsi_path'       => $wsiPath,
            'status'         => 'queued',
        ]);
        $run->stage('queued', 'Queued — waiting for a V2 worker.');
        RunV2Diagnosis::dispatch($run->id);

        return redirect()->route('admin.v2-diagnose.show', $run)
            ->with('success', "Run #{$run->id} queued for slide #{$sample->id}.");
    }

    public function show(V2Diagnosis $run)
    {
        $run->load('sample:id,entity_submitter_id,file_name,wsi_remote_path,category_id,disease_subtype_id',
            'sample.category:id,label_en', 'sample.diseaseSubtype:id,name', 'user:id,name');
        $wsi = $this->runner->resolveWsi($run);
        $tiles = null;
        if ($wsi && config('v2_diagnose.tile_source') === 'direct') {
            // Development: the browser asks wsi_tile_server.py itself. Needs the
            // slide's size, which is known once tiling has run.
            if ($run->slide_width && $run->slide_height) {
                $tiles = ['direct' => true, 'url' => rtrim(config('v2_diagnose.tile_server_url'), '/')
                    . "/tile/{$run->sample_id}/", 'wsi' => $wsi];
            }
        } elseif ($wsi && $run->sample_id && $this->registerWithTileService($run->sample_id, $wsi)) {
            $tiles = ['direct' => false, 'url' => "/wsi/{$run->sample_id}.dzi"];
        }

        $done = $run->status === 'completed' && $run->run_dir && is_file($run->run_dir . '/final.json');
        if ($done) {
            $this->ensureViewerFiles($run);
        }

        return view('admin.v2-diagnose.show', [
            'run'    => $run,
            'viewer' => (bool) $tiles,
            'tiles'  => $tiles,
            // The small drawing file only: final.json runs to megabytes and is
            // never needed to render the page.
            'final'  => $done ? $run->viewData() : null,
            'progress' => $run->status === 'completed' ? null : $this->runner->progress($run),
        ]);
    }

    public function status(V2Diagnosis $run): JsonResponse
    {
        return response()->json($run->only([
            'id', 'status', 'stage_message', 'events', 'summary', 'diagnosis', 'confidence',
            'regions_count', 'error',
        ]) + ['progress' => $this->runner->progress($run)]);
    }

    /**
     * What the viewer draws first: tiles, regions, SAM prompts and the tumour
     * mask as vectors (view.json).
     *
     * Served gzipped from a file made once. The whole final.json used to be
     * sent instead — up to 8 MB of uncompressed JSON, which took close to a
     * minute to reach the browser, and nothing was drawn until it arrived.
     */
    public function result(Request $request, V2Diagnosis $run)
    {
        return $this->viewerFile($request, $run, 'view.json');
    }

    /** The two heat layers as byte grids (heat.json), loaded after the vectors. */
    public function heat(Request $request, V2Diagnosis $run)
    {
        return $this->viewerFile($request, $run, 'heat.json');
    }

    /**
     * Images from a run — what the model was shown. Whitelisted by pattern, so
     * a file name from the URL can never reach outside the run directory.
     */
    public function asset(V2Diagnosis $run, string $path)
    {
        abort_unless(preg_match(
            '#^(view/P\d{3,4}\.jpg|sheets/sheet_\d{2,3}\.jpg|overview\.(png|jpg)|coverage\.(png|jpg))$#', $path), 404);
        $file = $run->run_dir . '/' . $path;
        abort_unless($run->run_dir && is_file($file), 404);
        // A run's images never change once written.
        return response()->file($file, ['Cache-Control' => 'private, max-age=604800']);
    }

    /**
     * A viewer file, gzipped once and kept beside it.
     *
     * Compressed here rather than by nginx, whose gzip covers HTML only on
     * this host — and so that the fix does not live in server config that a
     * rebuild would lose.
     */
    private function viewerFile(Request $request, V2Diagnosis $run, string $name)
    {
        abort_unless($run->run_dir && is_file($run->run_dir . '/final.json'), 404);
        $this->ensureViewerFiles($run);
        $file = $run->run_dir . '/' . $name;
        abort_unless(is_file($file), 404);

        $headers = ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-cache', 'Vary' => 'Accept-Encoding'];
        if (! str_contains((string) $request->header('Accept-Encoding'), 'gzip')) {
            return response()->file($file, $headers);
        }
        $gz = $file . '.gz';
        if (! is_file($gz) || filemtime($gz) < filemtime($file)) {
            file_put_contents($gz, gzencode((string) file_get_contents($file), 6));
        }
        return response()->file($gz, $headers + ['Content-Encoding' => 'gzip']);
    }

    /**
     * Runs finished before view.json/heat.json existed get them — and the
     * display check — built from their final.json on first view.
     */
    private function ensureViewerFiles(V2Diagnosis $run): void
    {
        $dir = $run->run_dir;
        if (is_file("{$dir}/view.json") && is_file("{$dir}/heat.json")
            && filemtime("{$dir}/view.json") >= filemtime("{$dir}/final.json")) {
            return;
        }
        try {
            $r = $this->runner->export($run);
            $checks = collect($r['problems'] ?? [])->map(fn ($p) => "Display check: {$p}")->all();
            $run->update(['warnings' => array_values(array_unique(array_merge(
                array_filter($run->warnings ?? [], fn ($w) => ! str_starts_with($w, 'Display check:')), $checks)))]);
        } catch (\Throwable $e) {
            Log::error("[V2Diagnose] viewer files for run #{$run->id} could not be built: {$e->getMessage()}");
        }
    }

    /** Every record of a run, downloadable as it was written. */
    public function download(V2Diagnosis $run, string $kind)
    {
        $files = [
            'final'   => ['final.json', 'application/json'],
            'result'  => ['result.json', 'application/json'],
            'prompt'  => ['prompt.md', 'text/markdown'],
            'tiling'  => ['tiling.json', 'application/json'],
            'density' => ['density.json', 'application/json'],
        ];
        abort_unless(isset($files[$kind]), 404);
        [$name, $type] = $files[$kind];
        $file = $run->run_dir . '/' . $name;
        abort_unless($run->run_dir && is_file($file), 404);
        return response()->download($file, "v2_run{$run->id}_{$name}", ['Content-Type' => $type]);
    }

    /** A fresh run with the same inputs. The old one stays as it was. */
    public function rerun(Request $request, V2Diagnosis $run): RedirectResponse
    {
        $new = V2Diagnosis::create([
            ...$run->only(['sample_id', 'organ', 'stain', 'age', 'sex', 'race', 'clinical_notes', 'wsi_path']),
            'user_id' => $request->user()?->id,
            'status'  => 'queued',
        ]);
        $new->stage('queued', "Queued — re-run of #{$run->id}.");
        RunV2Diagnosis::dispatch($new->id);

        return redirect()->route('admin.v2-diagnose.show', $new)
            ->with('success', "Run #{$new->id} queued with the inputs of #{$run->id}.");
    }

    /**
     * Take a new slide in the way the AI workflow does: a sample row first,
     * then the transfer to Drive on the queue. A server path is also analysed
     * in place, so the run need not wait for the copy to land.
     *
     * @return array{0: ?Sample, 1: ?string, 2: ?string} sample, local path, error
     */
    private function intake(Request $request, array $v): array
    {
        $local = null;
        if ($v['source'] === 'server_path') {
            $local = realpath(trim($v['server_path'])) ?: null;
            $inRoot = $local && collect(self::PATH_ROOTS)->contains(fn ($r) => str_starts_with($local, $r));
            if (! $local || ! is_file($local) || ! $inRoot || ! preg_match('/\.(svs|tiff?|ndpi|scn)$/i', $local)) {
                return [null, null, 'No readable slide at that path. It must sit under '
                    . implode(' or ', self::PATH_ROOTS)];
            }
        }

        $organ = Organ::where('name', $v['organ'])->first();
        $sample = Sample::create([
            'organ_id'            => $organ?->id,
            'entity_type'         => 'slide',
            'data_format'         => 'SVS',
            'storage_status'      => 'not_downloaded',
            'entity_submitter_id' => $v['label'] ?? null,
            'is_usable'           => true,
        ]);

        try {
            if ($local) {
                $sample->update(['file_name' => basename($local)]);
                ProcessSampleUpload::dispatch($sample->id, $local, null, null, null, null, false);
            } else {
                $stored = $request->file('wsi')->store('wsi_intake');
                $sample->update(['file_name' => $request->file('wsi')->getClientOriginalName()]);
                ProcessSampleUpload::dispatch($sample->id, storage_path("app/{$stored}"));
            }
        } catch (\Throwable $e) {
            Log::error("[V2Diagnose] intake failed: {$e->getMessage()}");
            $sample->delete();
            return [null, null, 'Could not take the slide in: ' . $e->getMessage()];
        }

        return [$sample, $local, null];
    }

    /**
     * Name the slide to the tile service behind /wsi/{sample}.dzi — the same
     * registry the AI workflow viewer writes to, so both viewers share tiles.
     */
    private function registerWithTileService(int $sampleId, string $wsi): bool
    {
        $dir = '/var/www/HISTO_AI/viewer';
        $ok = is_dir($dir) && @file_put_contents("{$dir}/{$sampleId}.json", json_encode([
            'wsi' => $wsi, 'registered_at' => now()->toDateTimeString(),
        ])) !== false;
        if (! $ok) {
            Log::warning("[V2Diagnose] could not register slide #{$sampleId} with the tile service");
        }
        return $ok;
    }
}
