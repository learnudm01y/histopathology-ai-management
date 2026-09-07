<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DataSource;
use App\Models\DiseaseSubtype;
use App\Models\Organ;
use App\Models\PatientCase;
use App\Support\CaseTaxonomyCounts;
use App\Support\SimpleXlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CasesController extends Controller
{
    /**
     * Clinical columns exported in the "full" sheet, in table order.
     * (raw_json is deliberately excluded — it duplicates every other column.)
     */
    private const CLINICAL_COLUMNS = [
        'index_date', 'consent_type', 'days_to_consent', 'lost_to_followup', 'state', 'updated_datetime',
        // Demographic
        'gender', 'sex_at_birth', 'race', 'ethnicity', 'age_at_index', 'days_to_birth', 'vital_status',
        'age_is_obfuscated', 'country_of_residence_at_enrollment',
        // Primary diagnosis
        'diagnosis_submitter_id', 'primary_diagnosis', 'tissue_or_organ_of_origin', 'site_of_resection_or_biopsy',
        'icd_10_code', 'morphology', 'classification_of_tumor', 'diagnosis_is_primary_disease',
        'method_of_diagnosis', 'synchronous_malignancy', 'laterality', 'prior_malignancy', 'prior_treatment',
        'metastasis_at_diagnosis', 'year_of_diagnosis', 'days_to_diagnosis', 'days_to_last_follow_up',
        'age_at_diagnosis',
        // AJCC staging
        'ajcc_pathologic_stage', 'ajcc_pathologic_t', 'ajcc_pathologic_n', 'ajcc_pathologic_m',
        'ajcc_staging_system_edition',
        // Pathology detail
        'consistent_pathology_review', 'lymph_nodes_positive', 'lymph_nodes_tested',
    ];

    /** JSON clinical columns — flattened to readable text in the sheet. */
    private const CLINICAL_JSON_COLUMNS = [
        'sites_of_involvement', 'diagnoses', 'treatments', 'follow_ups',
        'molecular_tests', 'other_clinical_attributes',
    ];

    /**
     * GET /admin/cases
     * List clinical cases with their slide counts and status badges.
     */
    public function index(Request $request): View
    {
        $query = PatientCase::query()
            ->with(['clinicalInfo', 'dataSource'])
            ->withCount('samples')
            ->orderByDesc('id');

        $this->applyFilters($query, $request);

        $cases = $query->paginate(20)->withQueryString();

        // The tree counts follow every filter EXCEPT the taxonomy ones. Feeding
        // them the taxonomy filter too would collapse the tree onto the node
        // already selected, which is precisely the context needed to move to a
        // sibling disease.
        $counts = CaseTaxonomyCounts::build(
            tap(PatientCase::query(), fn ($q) => $this->applyBaseFilters($q, $request))
        );

        $stats = [
            'total'             => PatientCase::count(),
            'with_clinical'     => PatientCase::whereHas('clinicalInfo')->count(),
            'with_slides'       => PatientCase::has('samples')->count(),
            'fully_linked'      => PatientCase::has('samples')->whereHas('clinicalInfo')->count(),
        ];

        $projects = PatientCase::query()
            ->whereNotNull('project_id')
            ->distinct()
            ->orderBy('project_id')
            ->pluck('project_id');

        $dataSources = DataSource::orderBy('name')->get(['id', 'name']);

        $selectedOrganId = $request->filled('organ_id') ? $request->integer('organ_id') : null;

        $organs = Organ::orderBy('name')->get(['id', 'name']);

        // The whole tree, always — the pickers have to keep offering the groups
        // and diseases of every organ so switching organ stays a client-side
        // change. Only the rendered breakdown narrows to the chosen organ.
        $taxonomy = $this->taxonomyTree();

        $categoryOptions = $taxonomy->flatten(1)->values();
        $diseaseOptions  = $this->diseaseOptions($taxonomy);

        $bands = $selectedOrganId === null
            ? $taxonomy
            : $taxonomy->filter(fn ($categories, $organId) => (int) $organId === $selectedOrganId);

        return view('admin.cases.index', compact(
            'cases', 'stats', 'projects', 'dataSources',
            'counts', 'bands', 'organs', 'selectedOrganId', 'categoryOptions', 'diseaseOptions'
        ));
    }

    /**
     * Organ → Clinical Group → Disease → finer Disease, grouped by organ.
     *
     * Groups with no organ are left out: they predate the organ root and have
     * no place in an organ-first breakdown — the Taxonomy page is where they
     * get assigned one.
     */
    private function taxonomyTree(): \Illuminate\Support\Collection
    {
        return Category::query()
            ->with([
                'organ:id,name',
                'rootDiseaseSubtypes' => fn ($q) => $q->orderBy('name'),
                'rootDiseaseSubtypes.childrenRecursive',
            ])
            ->whereNotNull('organ_id')
            ->orderBy('organ_id')
            ->orderBy('label_en')
            ->get()
            ->groupBy('organ_id');
    }

    /**
     * The disease picker's options, in tree order and indented by depth, each
     * tagged with its organ and group so the three selects can filter one
     * another in the browser without a round trip.
     *
     * @return array<int, array{id:int, organ_id:int|null, category_id:int|null, label:string}>
     */
    private function diseaseOptions(\Illuminate\Support\Collection $taxonomy): array
    {
        $options = [];

        $walk = function ($subtypes, int $depth) use (&$walk, &$options): void {
            foreach ($subtypes as $subtype) {
                $options[] = [
                    'id'          => $subtype->id,
                    'organ_id'    => $subtype->organ_id,
                    'category_id' => $subtype->category_id,
                    'label'       => str_repeat('— ', $depth - 1) . $subtype->name,
                ];

                $walk($subtype->loadedChildren(), $depth + 1);
            }
        };

        foreach ($taxonomy->flatten(1) as $category) {
            $walk($category->rootDiseaseSubtypes, 1);
        }

        return $options;
    }

    /**
     * GET /admin/cases/export
     * Download every case matching the current filters as a real .xlsx file.
     *
     * scope=full  (default) — case columns + the complete clinical record
     * scope=table           — only the columns shown in the on-screen table
     */
    public function export(Request $request): BinaryFileResponse
    {
        $full = $request->get('scope', 'full') !== 'table';

        $query = PatientCase::query()
            ->with(['dataSource', 'organ', 'clinicalInfo'])
            ->withCount('samples')
            ->orderByDesc('id');

        // Slide IDs are only needed by the full sheet — eager loaded (rather than
        // queried per case) so a large export stays at a couple of queries per chunk.
        if ($full) {
            $query->with(['samples' => fn ($q) => $q
                ->select('id', 'case_id', 'entity_submitter_id', 'organ_id', 'category_id', 'disease_subtype_id')
                ->with(['category:id,label_en', 'diseaseSubtype:id,name'])
                ->orderBy('entity_submitter_id')]);
        }

        $this->applyFilters($query, $request);

        $headers = $full ? $this->fullHeaders() : $this->tableHeaders();

        $path = tempnam(sys_get_temp_dir(), 'cases_export') . '.xlsx';

        SimpleXlsxWriter::write(
            $path,
            $headers,
            $this->exportRows($query, $full),
            'Cases'
        );

        $filename = 'cases-' . ($full ? 'full' : 'summary') . '-' . now()->format('Ymd-His') . '.xlsx';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * GET /admin/cases/{case}
     * Show a single case with all slides + full clinical record.
     */
    public function show(PatientCase $case): View
    {
        $case->load([
            'clinicalInfo',
            'samples' => fn ($q) => $q->with(['organ', 'category', 'stain'])->orderBy('entity_submitter_id'),
            'organ',
            'dataSource',
        ]);

        return view('admin.cases.show', compact('case'));
    }

    /**
     * The single source of truth for the Cases list filters — shared by the
     * on-screen table and the Excel export so both always agree.
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $this->applyBaseFilters($query, $request);
        $this->applyTaxonomyFilters($query, $request);
    }

    /** Everything except the Organ → Group → Disease drill-down. */
    private function applyBaseFilters(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('case_id', 'like', "%{$term}%")
                  ->orWhere('submitter_id', 'like', "%{$term}%")
                  ->orWhere('project_id', 'like', "%{$term}%")
                  ->orWhere('disease_type', 'like', "%{$term}%")
                  ->orWhere('primary_site', 'like', "%{$term}%");
            });
        }

        if ($request->filled('data_source_id')) {
            $query->where('data_source_id', $request->data_source_id);
        }

        if ($request->filled('with_clinical')) {
            $query->whereHas('clinicalInfo');
        }

        if ($request->filled('without_clinical')) {
            $query->whereDoesntHave('clinicalInfo');
        }

        if ($request->filled('with_slides')) {
            $query->has('samples');
        }

        if ($request->filled('without_slides')) {
            $query->doesntHave('samples');
        }

        // Fully linked = has both slides AND clinical info
        if ($request->filled('fully_linked')) {
            $query->has('samples')->whereHas('clinicalInfo');
        }
    }

    /**
     * Organ → Clinical Group → Disease, matched through the case's slides —
     * a case has no diagnosis column of its own.
     *
     * All three conditions go inside ONE whereHas so they must be satisfied by
     * the SAME slide. Drilling into Breast › Tumor › IDC has to mean "this case
     * has a breast IDC slide", not "a breast slide somewhere and an IDC slide
     * somewhere", which is also exactly how CaseTaxonomyCounts counts — so a
     * badge in the tree and the list it opens can never disagree.
     */
    private function applyTaxonomyFilters(Builder $query, Request $request): void
    {
        $organId    = $request->filled('organ_id') ? $request->integer('organ_id') : null;
        $categoryId = $request->filled('category_id') ? $request->integer('category_id') : null;
        $disease    = $request->filled('disease_subtype_id') ? (string) $request->disease_subtype_id : null;

        if ($organId === null && $categoryId === null && $disease === null) {
            return;
        }

        $subtree = $request->boolean('subtree');

        $query->whereHas('samples', function ($q) use ($organId, $categoryId, $disease, $subtree) {
            if ($organId !== null) {
                $q->where('organ_id', $organId);
            }

            if ($categoryId !== null) {
                $q->where('category_id', $categoryId);
            }

            if ($disease === null) {
                return;
            }

            // 'none' isolates the cases whose slides carry a clinical group but
            // no disease — the gap between a group's total and its diseases.
            if ($disease === 'none') {
                $q->whereNull('disease_subtype_id');
            } elseif ($subtree) {
                // A coarse disease owns its refinements: a case labelled only
                // "IDC" is still a "Malignant" case.
                $q->whereIn('disease_subtype_id', DiseaseSubtype::subtreeIds((int) $disease));
            } else {
                $q->where('disease_subtype_id', (int) $disease);
            }
        });
    }

    /** Headers matching the on-screen table. */
    private function tableHeaders(): array
    {
        return [
            'Submitter ID', 'Case UUID', 'Project', 'Disease Type', 'Primary Site',
            'Slides', 'Clinical',
        ];
    }

    /** Headers for the complete export (case + clinical). */
    private function fullHeaders(): array
    {
        $headers = [
            'ID', 'Submitter ID', 'Case UUID', 'Project', 'Disease Type', 'Primary Site',
            'Organ', 'Data Source', 'Slides', 'Slide IDs', 'Diseases', 'Clinical', 'Created At', 'Updated At',
        ];

        foreach (self::CLINICAL_COLUMNS as $column) {
            $headers[] = $this->humanize($column);
        }

        foreach (self::CLINICAL_JSON_COLUMNS as $column) {
            $headers[] = $this->humanize($column);
        }

        return $headers;
    }

    /**
     * Stream the matching cases as export rows, chunked so a large export
     * never loads the whole result set into memory at once.
     */
    private function exportRows(Builder $query, bool $full): \Generator
    {
        foreach ($query->lazy(500) as $case) {
            yield $full ? $this->fullRow($case) : $this->tableRow($case);
        }
    }

    private function tableRow(PatientCase $case): array
    {
        return [
            $case->submitter_id,
            $case->case_id,
            $case->project_id,
            $case->disease_type,
            $case->primary_site,
            (int) $case->samples_count,
            $case->clinicalInfo ? 'Yes' : 'No',
        ];
    }

    private function fullRow(PatientCase $case): array
    {
        $clinical = $case->clinicalInfo;

        $row = [
            (int) $case->id,
            $case->submitter_id,
            $case->case_id,
            $case->project_id,
            $case->disease_type,
            $case->primary_site,
            $case->organ?->name,
            $case->dataSource?->name,
            (int) $case->samples_count,
            $case->samples->pluck('entity_submitter_id')->filter()->implode(', ') ?: null,
            $case->disease_labels->implode(', ') ?: null,
            $clinical ? 'Yes' : 'No',
            $case->created_at?->format('Y-m-d H:i:s'),
            $case->updated_at?->format('Y-m-d H:i:s'),
        ];

        foreach (self::CLINICAL_COLUMNS as $column) {
            $row[] = $clinical?->{$column};
        }

        foreach (self::CLINICAL_JSON_COLUMNS as $column) {
            $row[] = $this->flattenJson($clinical?->{$column});
        }

        return $row;
    }

    /**
     * Turn a nested JSON clinical field into one readable cell:
     * a list of scalars becomes "a; b", a list of objects becomes
     * "key: value | key: value" per entry, separated by newlines.
     */
    private function flattenJson(mixed $value): ?string
    {
        if ($value === null || $value === [] || $value === '') {
            return null;
        }

        if (! is_array($value)) {
            return (string) $value;
        }

        $entries = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $pairs = [];
                foreach ($item as $k => $v) {
                    if ($v === null || $v === '' || $v === []) {
                        continue;
                    }
                    $pairs[] = $this->humanize((string) $k) . ': '
                        . (is_array($v) ? $this->flattenJson($v) : (string) $v);
                }
                if ($pairs !== []) {
                    $entries[] = implode(' | ', $pairs);
                }
            } elseif ($item !== null && $item !== '') {
                $entries[] = is_string($key) ? $this->humanize($key) . ': ' . $item : (string) $item;
            }
        }

        return $entries === [] ? null : implode("\n", $entries);
    }

    /** snake_case column name -> "Snake Case" header label. */
    private function humanize(string $column): string
    {
        return ucwords(str_replace('_', ' ', $column));
    }
}
