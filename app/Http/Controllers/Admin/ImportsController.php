<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClinicalCaseInformation;
use App\Models\DataSource;
use App\Models\PatientCase;
use App\Models\Sample;
use App\Services\CaseLinker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handles uploads of GDC artefacts:
 *   • manifest (.txt, TSV with id|filename|md5|size|state)
 *   • metadata.cart.*.json (slide-level metadata + associated case_id)
 *   • clinical.cart.*.json (case-level clinical data)
 *
 * All operations are idempotent (upsert by natural keys: file_id, case_id).
 * Linkage:
 *   manifest → samples by file_id
 *   metadata → samples by file_id  +  cases by case_id  +  links sample.case_id → case.id
 *   clinical → cases by case_id    +  clinical_slide_case_information by case_id
 *
 * Either order works — the natural keys reconcile records whenever the
 * counterpart arrives later.
 */
class ImportsController extends Controller
{
    /**
     * POST /admin/imports
     * Accepts one or more files. Each file is auto-detected by its content.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'import_files'   => ['required', 'array', 'min:1'],
            'import_files.*' => ['required', 'file', 'max:51200', 'mimetypes:application/json,text/plain,text/tab-separated-values,application/octet-stream'],
            'data_source_id' => ['nullable', 'exists:data_sources,id'],
        ], [
            'import_files.required' => 'Please choose at least one file (manifest, metadata or clinical).',
        ]);

        $dataSourceId = $request->input('data_source_id') ?: $this->defaultDataSourceId();

        $summary = [
            'manifest' => ['files' => 0, 'rows' => 0, 'samples_created' => 0, 'samples_updated' => 0],
            'metadata' => ['files' => 0, 'rows' => 0, 'samples_created' => 0, 'samples_updated' => 0, 'cases_created' => 0, 'cases_updated' => 0],
            'clinical' => ['files' => 0, 'rows' => 0, 'cases_created' => 0, 'cases_updated' => 0, 'clinical_created' => 0, 'clinical_updated' => 0, 'samples_linked' => 0],
            'unknown'  => 0,
            'errors'   => [],
        ];

        foreach ($request->file('import_files') as $file) {
            $name    = $file->getClientOriginalName();
            $content = file_get_contents($file->getRealPath());

            try {
                $kind = $this->detectKind($name, $content);

                switch ($kind) {
                    case 'manifest':
                        $this->importManifest($content, $dataSourceId, $summary);
                        break;
                    case 'metadata':
                        $this->importMetadata($content, $dataSourceId, $summary);
                        break;
                    case 'clinical':
                        $this->importClinical($content, $summary);
                        break;
                    default:
                        $summary['unknown']++;
                        $summary['errors'][] = "Unrecognised file format: {$name}";
                }
            } catch (Throwable $e) {
                Log::error('Import failure', ['file' => $name, 'error' => $e->getMessage()]);
                $summary['errors'][] = "Failed to import {$name}: " . $e->getMessage();
            }
        }

        // After any imports, reconcile sample.case_id ↔ cases.id by case UUID
        // (full sweep — also links the new cases to any orphan samples that
        //  arrived before clinical info, in BOTH directions).
        $reconciled = app(CaseLinker::class)->reconcileAllOrphans()
                    + $this->reconcileSamplesToCases();
        if ($reconciled > 0) {
            $summary['clinical']['samples_linked'] += $reconciled;
        }

        return back()->with('import_summary', $summary)
                     ->with('success', $this->summaryMessage($summary));
    }

    // ─────────────────────────────────────────────────────────────────
    //  Detection
    // ─────────────────────────────────────────────────────────────────

    private function detectKind(string $name, string $content): string
    {
        $lower = strtolower($name);
        $head  = ltrim($content);

        // JSON-shaped → metadata or clinical
        if (str_starts_with($head, '[') || str_starts_with($head, '{')) {
            $decoded = json_decode($head, true);
            if (is_array($decoded)) {
                $first = $decoded[0] ?? $decoded;
                if (is_array($first)) {
                    if (Arr::has($first, 'associated_entities') || Arr::has($first, 'data_format')) {
                        return 'metadata';
                    }
                    if (Arr::has($first, 'diagnoses') || Arr::has($first, 'demographic') || Arr::has($first, 'follow_ups')) {
                        return 'clinical';
                    }
                }
            }
            // Fallback by name
            if (str_contains($lower, 'metadata')) return 'metadata';
            if (str_contains($lower, 'clinical')) return 'clinical';
        }

        // TSV → manifest
        $firstLine = strtolower(strtok($content, "\n") ?: '');
        if (str_contains($firstLine, "id\tfilename\tmd5\tsize")) {
            return 'manifest';
        }
        if (str_contains($lower, 'manifest')) return 'manifest';

        return 'unknown';
    }

    // ─────────────────────────────────────────────────────────────────
    //  Manifest (TSV)
    // ─────────────────────────────────────────────────────────────────

    private function importManifest(string $content, ?int $dataSourceId, array &$summary): void
    {
        $summary['manifest']['files']++;

        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        if (count($lines) < 2) return;

        $header = array_map('strtolower', explode("\t", array_shift($lines)));
        $idx = [
            'id'       => array_search('id', $header, true),
            'filename' => array_search('filename', $header, true),
            'md5'      => array_search('md5', $header, true),
            'size'     => array_search('size', $header, true),
            'state'    => array_search('state', $header, true),
        ];

        DB::transaction(function () use ($lines, $idx, $dataSourceId, &$summary) {
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $cols = explode("\t", $line);

                $fileId   = $cols[$idx['id']]       ?? null;
                $fileName = $cols[$idx['filename']] ?? null;
                $md5      = $cols[$idx['md5']]      ?? null;
                $sizeRaw  = $cols[$idx['size']]     ?? null;
                $state    = $cols[$idx['state']]    ?? null;

                if (!$fileId) continue;
                $summary['manifest']['rows']++;

                $sizeBytes = is_numeric($sizeRaw) ? (int) $sizeRaw : null;
                $sizeGb    = $sizeBytes ? round($sizeBytes / 1073741824, 3) : null;

                $existing = Sample::where('file_id', $fileId)->first();

                $payload = array_filter([
                    'file_id'         => $fileId,
                    'file_name'       => $fileName,
                    'md5sum'          => $md5,
                    'file_size_bytes' => $sizeBytes,
                    'file_size_gb'    => $sizeGb,
                    'gdc_state'       => $state,
                    'data_source_id'  => $dataSourceId,
                    'entity_submitter_id' => $this->extractSubmitterFromFilename($fileName),
                ], fn ($v) => $v !== null && $v !== '');

                if ($existing) {
                    $existing->fill($payload)->save();
                    $summary['manifest']['samples_updated']++;
                } else {
                    Sample::create($payload);
                    $summary['manifest']['samples_created']++;
                }
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    //  Metadata JSON
    // ─────────────────────────────────────────────────────────────────

    private function importMetadata(string $content, ?int $dataSourceId, array &$summary): void
    {
        $summary['metadata']['files']++;
        $items = json_decode($content, true);
        if (!is_array($items)) return;
        if (Arr::isAssoc($items)) $items = [$items];

        DB::transaction(function () use ($items, $dataSourceId, &$summary) {
            foreach ($items as $row) {
                $summary['metadata']['rows']++;

                $fileId   = $row['file_id']   ?? null;
                $fileName = $row['file_name'] ?? null;
                if (!$fileId) continue;

                $entity = $row['associated_entities'][0] ?? [];
                $caseUuid           = $entity['case_id']  ?? null;
                $entityId           = $entity['entity_id'] ?? null;
                $entitySubmitterId  = $entity['entity_submitter_id'] ?? null;
                $entityType         = $entity['entity_type'] ?? 'slide';

                // Upsert / link case
                $caseRow = null;
                if ($caseUuid) {
                    $caseExisting = PatientCase::where('case_id', $caseUuid)->first();
                    $caseAttrs = array_filter([
                        'case_id'        => $caseUuid,
                        'submitter_id'   => $this->extractPatientSubmitter($entitySubmitterId),
                        'data_source_id' => $dataSourceId,
                    ], fn ($v) => $v !== null && $v !== '');

                    if ($caseExisting) {
                        $caseExisting->fill($caseAttrs)->save();
                        $caseRow = $caseExisting;
                        $summary['metadata']['cases_updated']++;
                    } else {
                        $caseRow = PatientCase::create($caseAttrs);
                        $summary['metadata']['cases_created']++;
                    }

                    // Reverse-link: if this case is new/changed, attach any orphan
                    // sample that already matches its submitter_id.
                    $linked = app(CaseLinker::class)->linkCaseToOrphanSamples($caseRow);
                    if ($linked > 0) {
                        $summary['clinical']['samples_linked'] += $linked;
                    }
                }

                // Upsert sample
                $sizeBytes = isset($row['file_size']) && is_numeric($row['file_size']) ? (int) $row['file_size'] : null;
                $sizeGb    = $sizeBytes ? round($sizeBytes / 1073741824, 3) : null;

                $payload = array_filter([
                    'file_id'             => $fileId,
                    'file_name'           => $fileName,
                    'md5sum'              => $row['md5sum'] ?? null,
                    'file_size_bytes'     => $sizeBytes,
                    'file_size_gb'        => $sizeGb,
                    'data_format'         => $row['data_format'] ?? null,
                    'data_type'           => $row['data_type'] ?? null,
                    'access_level'        => $row['access'] ?? null,
                    'gdc_state'           => $row['state'] ?? null,
                    'entity_id'           => $entityId,
                    'entity_submitter_id' => $entitySubmitterId,
                    'entity_type'         => $entityType,
                    'data_source_id'      => $dataSourceId,
                    'case_id'             => $caseRow?->id,  // FK to cases.id
                ], fn ($v) => $v !== null && $v !== '');

                $sample = Sample::where('file_id', $fileId)->first();
                if ($sample) {
                    $sample->fill($payload)->save();
                    $summary['metadata']['samples_updated']++;
                } else {
                    Sample::create($payload);
                    $summary['metadata']['samples_created']++;
                }
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    //  Clinical JSON
    // ─────────────────────────────────────────────────────────────────

    private function importClinical(string $content, array &$summary): void
    {
        $summary['clinical']['files']++;
        $items = json_decode($content, true);
        if (!is_array($items)) return;
        if (Arr::isAssoc($items)) $items = [$items];

        DB::transaction(function () use ($items, &$summary) {
            foreach ($items as $row) {
                $summary['clinical']['rows']++;

                $caseUuid     = $row['case_id'] ?? null;
                $submitterId  = $row['submitter_id'] ?? null;
                if (!$caseUuid) continue;

                // 1) Upsert minimal case record
                $caseAttrs = array_filter([
                    'case_id'      => $caseUuid,
                    'submitter_id' => $submitterId,
                    'project_id'   => $row['project']['project_id'] ?? null,
                    'primary_site' => $row['primary_site'] ?? null,
                    'disease_type' => $row['disease_type'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');

                $caseExisting = PatientCase::where('case_id', $caseUuid)->first();
                if ($caseExisting) {
                    $caseExisting->fill($caseAttrs)->save();
                    $summary['clinical']['cases_updated']++;
                    $caseRow = $caseExisting;
                } else {
                    $caseRow = PatientCase::create($caseAttrs);
                    $summary['clinical']['cases_created']++;
                }

                // Reverse-link orphan samples to this freshly upserted case
                $linked = app(CaseLinker::class)->linkCaseToOrphanSamples($caseRow);
                if ($linked > 0) {
                    $summary['clinical']['samples_linked'] += $linked;
                }

                // 2) Build the rich clinical_slide_case_information record
                $demographic    = $row['demographic'] ?? [];
                $diagnoses      = $row['diagnoses']   ?? [];
                $primaryDx      = $diagnoses[0] ?? [];
                $treatments     = $primaryDx['treatments'] ?? [];
                $pathDetail     = $primaryDx['pathology_details'][0] ?? [];
                $followUps      = $row['follow_ups'] ?? [];

                // Flatten molecular_tests + other_clinical_attributes from follow_ups
                $molecularTests        = [];
                $otherClinicalAttrs    = [];
                foreach ($followUps as $fu) {
                    foreach ($fu['molecular_tests'] ?? [] as $mt) {
                        $molecularTests[] = $mt;
                    }
                    foreach ($fu['other_clinical_attributes'] ?? [] as $oc) {
                        $otherClinicalAttrs[] = $oc;
                    }
                }

                $clinicalAttrs = [
                    'case_id'                          => $caseUuid,
                    'submitter_id'                     => $submitterId,
                    'project_id'                       => $row['project']['project_id'] ?? null,
                    'disease_type'                     => $row['disease_type'] ?? null,
                    'primary_site'                     => $row['primary_site'] ?? null,
                    'index_date'                       => $row['index_date'] ?? null,
                    'consent_type'                     => $row['consent_type'] ?? null,
                    'days_to_consent'                  => $row['days_to_consent'] ?? null,
                    'lost_to_followup'                 => $row['lost_to_followup'] ?? null,
                    'state'                            => $row['state'] ?? null,
                    'updated_datetime'                 => $row['updated_datetime'] ?? null,

                    // Demographic
                    'demographic_id'                       => $demographic['demographic_id'] ?? null,
                    'gender'                               => $demographic['gender'] ?? null,
                    'sex_at_birth'                         => $demographic['sex_at_birth'] ?? null,
                    'race'                                 => $demographic['race'] ?? null,
                    'ethnicity'                            => $demographic['ethnicity'] ?? null,
                    'age_at_index'                         => $demographic['age_at_index'] ?? null,
                    'days_to_birth'                        => $demographic['days_to_birth'] ?? null,
                    'vital_status'                         => $demographic['vital_status'] ?? null,
                    'age_is_obfuscated'                    => $demographic['age_is_obfuscated'] ?? null,
                    'country_of_residence_at_enrollment'   => $demographic['country_of_residence_at_enrollment'] ?? null,
                    'demographic_state'                    => $demographic['state'] ?? null,
                    'demographic_updated_datetime'         => $demographic['updated_datetime'] ?? null,

                    // Primary Diagnosis
                    'diagnosis_id'                  => $primaryDx['diagnosis_id'] ?? null,
                    'diagnosis_submitter_id'        => $primaryDx['submitter_id'] ?? null,
                    'primary_diagnosis'             => $primaryDx['primary_diagnosis'] ?? null,
                    'tissue_or_organ_of_origin'     => $primaryDx['tissue_or_organ_of_origin'] ?? null,
                    'site_of_resection_or_biopsy'   => $primaryDx['site_of_resection_or_biopsy'] ?? null,
                    'icd_10_code'                   => $primaryDx['icd_10_code'] ?? null,
                    'morphology'                    => $primaryDx['morphology'] ?? null,
                    'classification_of_tumor'       => $primaryDx['classification_of_tumor'] ?? null,
                    'diagnosis_is_primary_disease'  => $primaryDx['diagnosis_is_primary_disease'] ?? null,
                    'method_of_diagnosis'           => $primaryDx['method_of_diagnosis'] ?? null,
                    'synchronous_malignancy'        => $primaryDx['synchronous_malignancy'] ?? null,
                    'laterality'                    => $primaryDx['laterality'] ?? null,
                    'prior_malignancy'              => $primaryDx['prior_malignancy'] ?? null,
                    'prior_treatment'               => $primaryDx['prior_treatment'] ?? null,
                    'metastasis_at_diagnosis'       => $primaryDx['metastasis_at_diagnosis'] ?? null,
                    'year_of_diagnosis'             => $primaryDx['year_of_diagnosis'] ?? null,
                    'days_to_diagnosis'             => $primaryDx['days_to_diagnosis'] ?? null,
                    'days_to_last_follow_up'        => $primaryDx['days_to_last_follow_up'] ?? null,
                    'age_at_diagnosis'              => $primaryDx['age_at_diagnosis'] ?? null,
                    'diagnosis_state'               => $primaryDx['state'] ?? null,
                    'diagnosis_updated_datetime'    => $primaryDx['updated_datetime'] ?? null,

                    // AJCC Staging
                    'ajcc_pathologic_stage'         => $primaryDx['ajcc_pathologic_stage'] ?? null,
                    'ajcc_pathologic_t'             => $primaryDx['ajcc_pathologic_t'] ?? null,
                    'ajcc_pathologic_n'             => $primaryDx['ajcc_pathologic_n'] ?? null,
                    'ajcc_pathologic_m'             => $primaryDx['ajcc_pathologic_m'] ?? null,
                    'ajcc_staging_system_edition'   => $primaryDx['ajcc_staging_system_edition'] ?? null,

                    // Pathology details
                    'pathology_detail_id'                 => $pathDetail['pathology_detail_id'] ?? null,
                    'pathology_detail_submitter_id'       => $pathDetail['submitter_id'] ?? null,
                    'consistent_pathology_review'         => $pathDetail['consistent_pathology_review'] ?? null,
                    'lymph_nodes_positive'                => $pathDetail['lymph_nodes_positive'] ?? null,
                    'lymph_nodes_tested'                  => $pathDetail['lymph_nodes_tested'] ?? null,
                    'pathology_detail_state'              => $pathDetail['state'] ?? null,
                    'pathology_detail_created_datetime'   => $pathDetail['created_datetime'] ?? null,
                    'pathology_detail_updated_datetime'   => $pathDetail['updated_datetime'] ?? null,

                    // JSON columns (full nested arrays preserved)
                    'sites_of_involvement'      => $primaryDx['sites_of_involvement'] ?? null,
                    'diagnoses'                 => $diagnoses,
                    'treatments'                => $treatments,
                    'follow_ups'                => $followUps,
                    'molecular_tests'           => $molecularTests,
                    'other_clinical_attributes' => $otherClinicalAttrs,
                    'raw_json'                  => $row,
                ];

                $clinical = ClinicalCaseInformation::where('case_id', $caseUuid)->first();
                if ($clinical) {
                    $clinical->fill($clinicalAttrs)->save();
                    $summary['clinical']['clinical_updated']++;
                } else {
                    ClinicalCaseInformation::create($clinicalAttrs);
                    $summary['clinical']['clinical_created']++;
                }
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    //  Reconciliation:
    //    Whenever any import runs, link any sample whose entity carries a
    //    case_uuid (already in samples.case_id pointing to cases.id) — and
    //    also try to attach samples that arrived earlier via manifest only
    //    (no case_id) to a case discovered by clinical/metadata.
    // ─────────────────────────────────────────────────────────────────

    private function reconcileSamplesToCases(): int
    {
        $linked = 0;

        // Strategy 1: match orphan samples by entity_submitter_id → cases.submitter_id.
        $orphans = Sample::whereNull('case_id')
            ->whereNotNull('entity_submitter_id')
            ->get();

        foreach ($orphans as $sample) {
            $patientSub = $this->extractPatientSubmitter($sample->entity_submitter_id);
            if (!$patientSub) continue;

            $case = PatientCase::where('submitter_id', $patientSub)->first();
            if ($case) {
                $sample->case_id = $case->id;
                $sample->save();
                $linked++;
            }
        }

        // Strategy 2: match remaining orphans via file_name (handles bulk-uploaded TCGA
        // slides where entity_submitter_id was derived from the folder path, not the filename).
        $remaining = Sample::whereNull('case_id')
            ->whereNotNull('file_name')
            ->get();

        foreach ($remaining as $sample) {
            // Extract TCGA slide submitter from the actual WSI filename.
            $entitySub = null;
            if (preg_match('/^([A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+(?:-[A-Z0-9]+(?:-\d+)?(?:-[A-Z0-9]+)?)?)/i', $sample->file_name, $m)) {
                $entitySub = $m[1];
            }
            if (!$entitySub) continue;

            $patientSub = $this->extractPatientSubmitter($entitySub);
            if (!$patientSub) continue;

            $case = PatientCase::where('submitter_id', $patientSub)->first();
            if ($case) {
                $sample->case_id = $case->id;
                // Correct entity_submitter_id if it was set to the synthetic folder-path value.
                if ($sample->entity_submitter_id !== $entitySub) {
                    $sample->entity_submitter_id = $entitySub;
                }
                $sample->save();
                $linked++;
            }
        }

        // Strategy 3: match orphans whose bulk_folder_original_path is a GDC file UUID —
        // look up that UUID in samples that were imported via manifest to find the case.
        $bulkOrphans = Sample::whereNull('case_id')
            ->whereNotNull('bulk_folder_original_path')
            ->get();

        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        foreach ($bulkOrphans as $sample) {
            $folderUuid = $sample->bulk_folder_original_path;
            if (!preg_match($uuidPattern, $folderUuid)) continue;

            // The folder UUID IS the GDC file_id. If file_id is wrong (Google Drive ID),
            // update it so subsequent metadata imports can link correctly.
            if (!$sample->file_id || !preg_match($uuidPattern, $sample->file_id)) {
                $sample->file_id = strtolower($folderUuid);
                $sample->save();
                $linked++;
            }
        }

        return $linked;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * From "TCGA-A1-A0SB-01Z-00-DX1.uuid.svs"  →  "TCGA-A1-A0SB"
     * Returns null if the pattern doesn't look like a TCGA filename.
     */
    private function extractSubmitterFromFilename(?string $name): ?string
    {
        if (!$name) return null;
        if (!preg_match('/^([A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+(?:-[A-Z0-9]+(?:-\d+)?(?:-[A-Z0-9]+)?)?)/i', $name, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * From "TCGA-A1-A0SB-01Z-00-DX1"  →  "TCGA-A1-A0SB" (first 3 hyphen segments).
     */
    private function extractPatientSubmitter(?string $entitySubmitterId): ?string
    {
        if (!$entitySubmitterId) return null;
        $parts = explode('-', $entitySubmitterId);
        if (count($parts) < 3) return null;
        return implode('-', array_slice($parts, 0, 3));
    }

    private function defaultDataSourceId(): ?int
    {
        return DataSource::where('name', 'TCGA')
            ->orWhere('name', 'like', 'TCGA%')
            ->value('id');
    }

    private function summaryMessage(array $s): string
    {
        $bits = [];
        if ($s['manifest']['files'])  $bits[] = "Manifest: {$s['manifest']['rows']} rows ({$s['manifest']['samples_created']} new, {$s['manifest']['samples_updated']} updated)";
        if ($s['metadata']['files'])  $bits[] = "Metadata: {$s['metadata']['rows']} rows, cases (+{$s['metadata']['cases_created']}/✎{$s['metadata']['cases_updated']}), samples (+{$s['metadata']['samples_created']}/✎{$s['metadata']['samples_updated']})";
        if ($s['clinical']['files'])  $bits[] = "Clinical: {$s['clinical']['rows']} cases (+{$s['clinical']['clinical_created']}/✎{$s['clinical']['clinical_updated']})";
        if ($s['clinical']['samples_linked']) $bits[] = "Linked {$s['clinical']['samples_linked']} sample(s) to cases";
        if ($s['unknown']) $bits[] = "{$s['unknown']} unrecognised file(s)";
        return $bits ? implode(' · ', $bits) : 'Nothing was imported.';
    }
}
