<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSampleUpload;
use App\Jobs\RunV2Diagnosis;
use App\Models\Category;
use App\Models\DiseaseSubtype;
use App\Models\Organ;
use App\Models\Sample;
use App\Models\Stain;
use App\Models\V2Diagnosis;
use App\Services\V2DiagnoseRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
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

        // The results, filtered by the slide's organ and its place in the
        // taxonomy: classification group, then disease (with every finer
        // disease under it).
        $f = [
            'organ' => $request->integer('r_organ') ?: null,
            'cat'   => $request->input('r_cat') === 'none' ? 'none' : ($request->integer('r_cat') ?: null),
            'dx'    => $request->integer('r_dx') ?: null,
        ];
        $filtered = function ($q) use ($f) {
            $q->whereHas('sample', function ($s) use ($f) {
                if ($f['organ']) {
                    $s->where('organ_id', $f['organ']);
                }
                if ($f['cat'] === 'none') {
                    $s->whereNull('category_id')->whereNull('disease_subtype_id');
                } elseif ($f['cat']) {
                    $s->where(fn ($w) => $w->where('category_id', $f['cat'])
                        ->orWhereIn('disease_subtype_id', DiseaseSubtype::where('category_id', $f['cat'])->select('id')));
                }
                if ($f['dx']) {
                    $s->whereIn('disease_subtype_id', DiseaseSubtype::subtreeIds($f['dx']));
                }
            });
        };
        $any = array_filter($f);
        $runs = V2Diagnosis::with($withTruth)->when($any, $filtered)->latest('id')->paginate(25)->withQueryString()->fragment('results');

        // The tally across every finished run in the filter whose slide has a
        // recorded diagnosis — not only the page shown.
        $tally = ['correct' => 0, 'partial' => 0, 'wrong' => 0, 'review' => 0];
        V2Diagnosis::with($withTruth)->when($any, $filtered)->where('status', 'completed')
            ->get(['id', 'sample_id', 'status', 'diagnosis_code'])
            ->each(function ($r) use (&$tally) {
                if ($v = $r->verdict()) {
                    $tally[$v['result']]++;
                }
            });

        // The archive, counted by organ and stain, so the two filters only
        // offer what holds a slide — and say how many.
        $counts = $this->archive()->selectRaw('organ_id, stain_id, count(*) as n')
            ->groupBy('organ_id', 'stain_id')->get();
        $organNames = Organ::whereIn('id', $counts->pluck('organ_id')->filter())->pluck('name', 'id');
        $stainNames = Stain::whereIn('id', $counts->pluck('stain_id')->filter())->pluck('name', 'id');
        $archive = $counts->groupBy(fn ($c) => (int) $c->organ_id)
            ->map(fn ($rows, $organId) => [
                'id'     => $organId,
                'name'   => $organNames[$organId] ?? 'Organ not recorded',
                'n'      => $rows->sum('n'),
                'stains' => $rows->map(fn ($c) => [
                    'id'   => $c->stain_id ? (string) $c->stain_id : 'none',
                    'name' => $c->stain_id ? ($stainNames[$c->stain_id] ?? "Stain #{$c->stain_id}") : 'Stain not recorded',
                    'n'    => (int) $c->n,
                ])->sortBy(fn ($s) => [$s['id'] === 'none', $s['name']])->values(),
            ])->sortBy('name')->values();

        // A slide asked for by id (a link from elsewhere, or the form coming
        // back with errors) opens with its organ and stain already filtered.
        $pickedId = (int) old('sample_id', $request->integer('sample_id')) ?: null;
        $picked = $pickedId ? Sample::find($pickedId, ['id', 'organ_id', 'stain_id']) : null;

        return view('admin.v2-diagnose.index', [
            'runs'    => $runs,
            'tally'   => $tally,
            'rf'      => $f,
            'rfOptions' => $this->resultFilterOptions($f),
            'archive' => $archive,
            'organs'  => Organ::orderBy('name')->pluck('name'),
            'stains'  => Stain::orderBy('name')->pluck('name'),
            'picked'  => $picked ? [
                'id'    => $picked->id,
                'organ' => (int) $picked->organ_id,
                'stain' => $picked->stain_id ? (string) $picked->stain_id : 'none',
            ] : null,
        ]);
    }

    /**
     * What the results filter offers: organs, then the chosen organ's
     * classification groups, then the chosen group's diseases as an indented
     * tree — each with how many results it holds, and only those that hold
     * any. A disease counts every result filed under it or any finer disease.
     *
     * @return array{organs: list<array>, cats: list<array>, dxs: list<array>}
     */
    private function resultFilterOptions(array $f): array
    {
        $rows = V2Diagnosis::join('samples', 'samples.id', '=', 'v2_diagnoses.sample_id')
            ->selectRaw('samples.organ_id, samples.category_id, samples.disease_subtype_id, count(*) as n')
            ->groupBy('samples.organ_id', 'samples.category_id', 'samples.disease_subtype_id')->get();

        $organNames = Organ::whereIn('id', $rows->pluck('organ_id')->filter())->pluck('name', 'id');
        $organs = $rows->filter(fn ($r) => $r->organ_id)->groupBy('organ_id')
            ->map(fn ($g, $id) => ['id' => (int) $id, 'name' => $organNames[$id] ?? "Organ #{$id}", 'n' => (int) $g->sum('n')])
            ->sortBy('name')->values()->all();

        $cats = $dxs = [];
        if ($f['organ']) {
            $mine = $rows->where('organ_id', $f['organ']);
            $subtypes = DiseaseSubtype::where('organ_id', $f['organ'])->get(['id', 'parent_id', 'category_id', 'name'])->keyBy('id');

            // Each result counts for its disease and every disease above it.
            $perDx = [];
            $perCat = [];
            $unfiled = 0;
            foreach ($mine as $r) {
                $cat = $r->category_id ?: ($r->disease_subtype_id ? $subtypes->get($r->disease_subtype_id)?->category_id : null);
                $cat ? $perCat[$cat] = ($perCat[$cat] ?? 0) + $r->n : $unfiled += $r->n;
                for ($id = $r->disease_subtype_id, $hops = 0; $id && $hops < DiseaseSubtype::MAX_DEPTH; $hops++) {
                    $perDx[$id] = ($perDx[$id] ?? 0) + $r->n;
                    $id = $subtypes->get($id)?->parent_id;
                }
            }
            $catNames = Category::whereIn('id', array_keys($perCat))->pluck('label_en', 'id');
            $cats = collect($perCat)->map(fn ($n, $id) => ['id' => (int) $id, 'name' => $catNames[$id] ?? "Group #{$id}", 'n' => $n])
                ->sortBy('name')->values()->all();
            if ($unfiled) {
                $cats[] = ['id' => 'none', 'name' => 'Not classified', 'n' => $unfiled];
            }

            if ($f['cat'] && $f['cat'] !== 'none') {
                $walk = function ($parentId, $depth) use (&$walk, &$dxs, $subtypes, $perDx, $f) {
                    $level = $subtypes->filter(fn ($d) => $parentId === null
                        ? $d->parent_id === null && (int) $d->category_id === (int) $f['cat']
                        : (int) $d->parent_id === $parentId)->sortBy('name');
                    foreach ($level as $d) {
                        if (! empty($perDx[$d->id]) && $depth <= DiseaseSubtype::MAX_DEPTH) {
                            $dxs[] = ['id' => $d->id, 'name' => $d->name, 'n' => $perDx[$d->id], 'depth' => $depth];
                            $walk($d->id, $depth + 1);
                        }
                    }
                };
                $walk(null, 0);
            }
        }

        return ['organs' => $organs, 'cats' => $cats, 'dxs' => $dxs];
    }

    /**
     * One page of an organ's archive slides, each with what the database
     * knows about it and every earlier V2 run on it. Stain, search and
     * "not run yet" are applied here, so they reach every slide, not only
     * the page on screen.
     */
    public function archiveSamples(Request $request): JsonResponse
    {
        $v = $request->validate([
            'organ_id' => ['required', 'integer'],
            'stain'    => ['nullable', 'string', 'max:10'],
            'q'        => ['nullable', 'string', 'max:100'],
            'unused'   => ['nullable', 'boolean'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);

        $q = $this->archive();
        $v['organ_id'] ? $q->where('organ_id', $v['organ_id']) : $q->whereNull('organ_id');
        match ($v['stain'] ?? '') {
            '', 'all' => null,
            'none'    => $q->whereNull('stain_id'),
            default   => $q->where('stain_id', (int) $v['stain']),
        };
        if ($term = trim((string) ($v['q'] ?? ''))) {
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $q->where(function ($w) use ($term, $like) {
                $w->where('entity_submitter_id', 'like', $like)
                  ->orWhere('file_name', 'like', $like)
                  ->orWhereHas('patientCase', fn ($c) => $c->where('submitter_id', 'like', $like))
                  ->orWhereHas('diseaseSubtype', fn ($d) => $d->where('name', 'like', $like))
                  ->orWhereHas('category', fn ($d) => $d->where('label_en', 'like', $like))
                  ->orWhereHas('patientCase.clinicalInfo', fn ($c) => $c->where('primary_diagnosis', 'like', $like)
                      ->orWhere('site_of_resection_or_biopsy', 'like', $like));
                if (ctype_digit(ltrim($term, '#'))) {
                    $w->orWhere('id', (int) ltrim($term, '#'));
                }
            });
        }
        $ran = V2Diagnosis::whereNotNull('sample_id')->select('sample_id');
        $usedTotal = (clone $q)->whereIn('id', $ran)->count();
        if (! empty($v['unused'])) {
            $q->whereNotIn('id', $ran);
        }

        $page = $q->with(self::PROFILE_WITH)->orderByDesc('id')
            ->paginate($v['per_page'] ?? 25, ['*'], 'page', $v['page'] ?? 1);
        $runs = $this->runsFor($page->getCollection());

        return response()->json([
            'samples'    => $page->getCollection()->map(fn ($s) => $this->profile($s, $runs->get($s->id, collect())))->values(),
            'total'      => $page->total(),
            'page'       => $page->currentPage(),
            'pages'      => $page->lastPage(),
            'per_page'   => $page->perPage(),
            'used_total' => $usedTotal,
        ]);
    }

    /** What the database already knows about one slide, to pre-fill the form. */
    public function sampleContext(Sample $sample): JsonResponse
    {
        $sample->loadMissing(self::PROFILE_WITH);
        return response()->json($this->profile($sample, $this->runsFor(collect([$sample]))->get($sample->id, collect())));
    }

    /** Relations a profile reads — the clinical row without its large JSON copies. */
    private const PROFILE_WITH = [
        'organ:id,name', 'stain:id,name', 'category:id,label_en', 'diseaseSubtype:id,name',
        'patientCase:id,case_id,submitter_id,project_id',
        'patientCase.clinicalInfo:id,case_id,project_id,gender,race,age_at_index,days_to_birth,'
            . 'primary_diagnosis,tissue_or_organ_of_origin,site_of_resection_or_biopsy,sites_of_involvement,'
            . 'laterality,method_of_diagnosis,classification_of_tumor,metastasis_at_diagnosis,'
            . 'ajcc_pathologic_stage,ajcc_pathologic_t,ajcc_pathologic_n,ajcc_pathologic_m,'
            . 'lymph_nodes_positive,lymph_nodes_tested,molecular_tests,vital_status',
    ];

    /** Slides a run can read: the image is on Drive. */
    private function archive()
    {
        return Sample::where('storage_status', 'available')->whereNotNull('wsi_remote_path');
    }

    /** Earlier V2 runs, by slide, newest first. */
    private function runsFor($samples)
    {
        $bySample = $samples->keyBy('id');
        return V2Diagnosis::whereIn('sample_id', $bySample->keys())->latest('id')
            ->get(['id', 'sample_id', 'status', 'diagnosis', 'diagnosis_code', 'confidence', 'run_dir', 'created_at'])
            ->each(fn ($r) => $r->setRelation('sample', $bySample[$r->sample_id]))
            ->groupBy('sample_id');
    }

    /**
     * One slide as the archive table shows it.
     *
     * `form` holds what is sent to the model with the run. The recorded
     * diagnosis, stage and receptor status are shown to the user but never
     * put there: they are the answer the run is judged against.
     */
    private function profile(Sample $s, $runs): array
    {
        $c = $s->patientCase?->clinicalInfo;
        $known = fn ($x) => $x !== null && $x !== '' && ! in_array(strtolower((string) $x),
            ['not reported', 'unknown', 'not allowed to collect', "'--", '--'], true) ? $x : null;

        $age = $c?->age_at_index;
        if ($age === null && $c?->days_to_birth) {
            $age = (int) floor(abs($c->days_to_birth) / 365.25);
        }
        $sex = strtolower((string) $known($c?->gender));
        $sex = in_array($sex, ['female', 'male'], true) ? $sex : null;

        // "Breast, NOS" says nothing once a quadrant is named.
        $sites = collect($c?->sites_of_involvement ?? [])->filter()->unique();
        $specific = $sites->reject(fn ($x) => str_ends_with($x, ', NOS'));
        $site = ($specific->isNotEmpty() ? $specific : $sites)->implode('; ')
            ?: $known($c?->site_of_resection_or_biopsy) ?: $known($c?->tissue_or_organ_of_origin);
        $laterality = $known($c?->laterality);
        $method = $known($c?->method_of_diagnosis);

        $receptors = collect($c?->molecular_tests ?? [])
            ->filter(fn ($t) => in_array($t['gene_symbol'] ?? null, ['ESR1', 'PGR', 'ERBB2'], true)
                && in_array($t['test_result'] ?? null, ['Positive', 'Negative', 'Equivocal'], true))
            ->unique('gene_symbol')
            ->map(fn ($t) => ['ESR1' => 'ER', 'PGR' => 'PR', 'ERBB2' => 'HER2'][$t['gene_symbol']]
                . ['Positive' => '+', 'Negative' => '−', 'Equivocal' => '±'][$t['test_result']])
            ->sortBy(fn ($r) => array_search(rtrim($r, '+−±'), ['ER', 'PR', 'HER2']))
            ->implode(' ');
        $tnm = collect([$c?->ajcc_pathologic_t, $c?->ajcc_pathologic_n, $c?->ajcc_pathologic_m])
            ->map($known)->filter()->implode(' ');
        $nodes = $c && $c->lymph_nodes_tested !== null
            ? ($c->lymph_nodes_positive ?? '?') . '/' . $c->lymph_nodes_tested . ' nodes' : null;

        $notes = collect([
            $site ? 'Site: ' . $site . ($laterality && ! str_contains(strtolower($site), strtolower($laterality)) ? " ({$laterality})" : '') : null,
            $method ? 'Obtained by ' . strtolower($method) : null,
        ])->filter()->implode('. ');

        return [
            'id'         => $s->id,
            'label'      => $s->entity_submitter_id ?: ($s->file_name ?: "Sample #{$s->id}"),
            'file'       => $s->file_name,
            'case'       => $s->patientCase?->submitter_id,
            'project'    => $c?->project_id ?: $s->patientCase?->project_id,
            'organ'      => $s->organ?->name,
            'stain'      => $s->stain?->name,
            'disease'    => $s->diseaseSubtype?->name,
            'category'   => $s->category?->label_en,
            'primary_dx' => $known($c?->primary_diagnosis),
            'age'        => $age,
            'sex'        => $sex,
            'race'       => $known($c?->race),
            'site'       => $site ?: null,
            'laterality' => $laterality,
            'method'     => $method,
            'tumour'     => $known($c?->classification_of_tumor),
            'metastasis' => $known($c?->metastasis_at_diagnosis),
            'stage'      => $known($c?->ajcc_pathologic_stage),
            'tnm'        => $tnm ?: null,
            'nodes'      => $nodes,
            'receptors'  => $receptors ?: null,
            'vital'      => $known($c?->vital_status),
            'has_clinical' => (bool) $c,
            'url'        => route('admin.samples.show', $s->id),
            'form'       => array_filter([
                'organ' => $s->organ?->name,
                'stain' => $s->stain?->name,
                'age'   => $age,
                'sex'   => $sex,
                'race'  => $known($c?->race),
                'clinical_notes' => $notes ?: null,
            ], fn ($x) => $x !== null && $x !== ''),
            'runs' => $runs->map(function ($r) {
                $v = $r->verdict();
                $overview = collect(['overview.jpg', 'overview.png'])
                    ->first(fn ($f) => $r->run_dir && is_file("{$r->run_dir}/{$f}"));
                return [
                    'id'        => $r->id,
                    'status'    => $r->status,
                    'diagnosis' => $r->diagnosis,
                    'verdict'   => $v['result'] ?? null,
                    'truth'     => $v['truth'] ?? $r->recordedClass(),
                    'confidence' => $r->confidence !== null ? (int) round($r->confidence * 100) : null,
                    'reason'    => $v['reason'] ?? null,
                    'when'      => $r->created_at?->format('Y-m-d'),
                    'url'       => route('admin.v2-diagnose.show', $r->id),
                    'overview'  => $overview ? route('admin.v2-diagnose.asset', [$r->id, $overview]) : null,
                ];
            })->values(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'source'         => ['required', 'in:existing,server_path,upload'],
            'sample_id'      => ['required_if:source,existing', 'nullable', 'integer', 'exists:samples,id'],
            'server_path'    => ['required_if:source,server_path', 'nullable', 'string', 'max:1000'],
            'wsi'            => ['required_if:source,upload', 'nullable', 'file', 'mimes:svs,tiff,tif,ndpi,scn'],
            'label'          => ['nullable', 'string', 'max:150'],
            'organ'          => ['required_unless:source,existing', 'nullable', 'string', 'max:100'],
            'stain'          => ['nullable', 'string', 'max:100'],
            'age'            => ['nullable', 'integer', 'min:0', 'max:120'],
            'sex'            => ['nullable', 'in:female,male,other'],
            'race'           => ['nullable', 'string', 'max:100'],
            'clinical_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $wsiPath = null;
        if ($v['source'] === 'existing') {
            $sample = Sample::with(self::PROFILE_WITH)->findOrFail($v['sample_id']);
            // What the archive knows fills whatever the form left empty, so a
            // field hidden as known still reaches the run.
            foreach ($this->profile($sample, collect())['form'] as $k => $known) {
                if (($v[$k] ?? null) === null || $v[$k] === '') {
                    $v[$k] = $known;
                }
            }
            if (empty($v['organ'])) {
                return back()->withInput()->withErrors(['organ' => 'This slide has no organ on record — name one.']);
            }
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

    /**
     * Delete a run: the row and everything it wrote. A run still working is
     * refused — its worker would go on writing into a folder that is gone.
     * Only a folder inside the runs directory is ever removed.
     */
    public function destroy(V2Diagnosis $run): RedirectResponse
    {
        if ($run->isRunning()) {
            return back()->withErrors(['run' => "Run #{$run->id} is still {$run->status}. Wait for it to finish, then delete it."]);
        }

        $removed = $this->purgeClaudeRecords($run);

        $root = realpath((string) config('v2_diagnose.runs_dir'));
        $dir = $run->run_dir ? realpath($run->run_dir) : false;
        if ($root && $dir && $dir !== $root && str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) {
            File::deleteDirectory($dir);
            $removed[] = $dir;
            // Earlier attempts kept by a re-run in place.
            foreach (glob($dir . '.attempt*', GLOB_ONLYDIR) ?: [] as $old) {
                File::deleteDirectory($old);
                $removed[] = $old;
            }
        } elseif ($run->run_dir && $dir) {
            Log::warning("[V2Diagnose] run #{$run->id}: folder {$run->run_dir} is outside the runs directory, left in place");
        }

        // The slide itself, its sample row and its viewer registration are
        // the archive's, shared with every other run: none of them is touched.
        $id = $run->id;
        $run->delete();
        Log::info("[V2Diagnose] run #{$id} deleted by user #" . (auth()->id() ?? '?') . '; removed: ' . implode(', ', $removed));

        return redirect()->route('admin.v2-diagnose')->with('success', "Run #{$id} deleted.");
    }

    /**
     * What the Claude CLI kept of a run outside its folder, in the worker's
     * HOME: the session transcript (~15 MB a run) under
     * .claude/projects/<the run folder, every non-alphanumeric as "-">/, and
     * one session-env/<session id> entry per session.
     *
     * @return list<string> the paths removed
     */
    private function purgeClaudeRecords(V2Diagnosis $run): array
    {
        $home = rtrim((string) (config('v2_diagnose.claude.home') ?: getenv('HOME')), '/\\');
        if ($home === '' || ! is_dir("{$home}/.claude")) {
            return [];
        }
        $removed = [];

        // The CLI names the folder after its working directory: the run folder.
        $folders = collect([$run->run_dir, $this->runner->runDir($run), $run->run_dir ? realpath($run->run_dir) : null])
            ->filter()->map(fn ($d) => preg_replace('/[^a-zA-Z0-9]/', '-', rtrim($d, '/\\')))->unique();

        $sessions = collect([$run->claude_session_id]);
        foreach ($folders as $name) {
            $project = "{$home}/.claude/projects/{$name}";
            // Only letters, digits and "-" by construction; it must also name this run.
            if (! str_ends_with($name, '-' . $run->id) || ! is_dir($project)) {
                continue;
            }
            foreach (glob("{$project}/*.jsonl") ?: [] as $f) {
                $sessions->push(basename($f, '.jsonl'));
            }
            File::deleteDirectory($project);
            $removed[] = $project;
        }

        foreach ($sessions->filter()->unique() as $id) {
            // Only a session id — never a name that could climb out of the folder.
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
                continue;
            }
            $env = "{$home}/.claude/session-env/{$id}";
            if (is_dir($env)) {
                File::deleteDirectory($env);
                $removed[] = $env;
            } elseif (is_file($env)) {
                @unlink($env);
                $removed[] = $env;
            }
        }

        return $removed;
    }

    /** A fresh run with the same inputs. The old one stays as it was. */
    /**
     * Re-run under the same id: a re-run is the same slide asked again, not
     * a new case, so it keeps its number (see V2DiagnoseRunner::rerunInPlace).
     */
    public function rerun(Request $request, V2Diagnosis $run): RedirectResponse
    {
        if ($run->isRunning()) {
            return back()->withErrors(['run' => "Run #{$run->id} is still {$run->status}."]);
        }
        $this->runner->rerunInPlace($run);

        return redirect()->route('admin.v2-diagnose.show', $run)
            ->with('success', "Run #{$run->id} queued again on the same slide; the previous attempt is kept.");
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
