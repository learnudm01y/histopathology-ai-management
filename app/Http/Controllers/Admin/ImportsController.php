<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClinicalCaseInformation;
use App\Models\DataSource;
use App\Models\PatientCase;
use App\Models\Sample;
use App\Services\CaseLinker;
use App\Services\GdcCaseImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handles uploads of GDC artefacts:
 *   • manifest (.txt, TSV with id|filename|md5|size|state)
 *   • metadata.cart.*.json (slide-level metadata + associated case_id)
 *   • clinical.cart.*.json (case-level clinical data)
 *   • clinical CSV (flat, one row per case; links to EXISTING samples only)
 *   • cohort JSON (nested batches → patients → slides; same link-only rules)
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
            'import_files.*' => ['required', 'file', 'max:51200', 'mimetypes:application/json,text/plain,text/tab-separated-values,text/csv,application/csv,application/vnd.ms-excel,application/octet-stream'],
            'data_source_id' => ['nullable', 'exists:data_sources,id'],
        ], [
            'import_files.required' => 'Please choose at least one file (manifest, metadata or clinical).',
        ]);

        $dataSourceId = $request->input('data_source_id') ?: $this->defaultDataSourceId();

        $summary = [
            'manifest' => ['files' => 0, 'rows' => 0, 'samples_created' => 0, 'samples_updated' => 0],
            'metadata' => ['files' => 0, 'rows' => 0, 'samples_created' => 0, 'samples_updated' => 0, 'cases_created' => 0, 'cases_updated' => 0],
            'clinical' => ['files' => 0, 'rows' => 0, 'cases_created' => 0, 'cases_updated' => 0, 'clinical_created' => 0, 'clinical_updated' => 0, 'samples_linked' => 0, 'samples_unmatched' => 0],
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
                    case 'clinical_csv':
                        $this->importClinicalCsv($content, $summary);
                        break;
                    case 'clinical_batch_json':
                        $this->importClinicalBatchJson($content, $summary);
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
                    // Our own cohort export: batches → patients → slides. Checked
                    // before the GDC shapes and before the filename fallback,
                    // which would otherwise route it to the GDC clinical reader
                    // and silently import nothing.
                    if (Arr::has($decoded, 'batches')
                        || Arr::has($decoded, 'patients')
                        || (Arr::has($first, 'gdc_case_id') && Arr::has($first, 'slides'))) {
                        return 'clinical_batch_json';
                    }
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

        // Flat clinical CSV. Two shapes are in circulation and both are the same
        // fact filed differently:
        //   one row per CASE  — …,file_ids,file_names,md5sums  (';'-separated lists)
        //   one row per SLIDE — …,file_id,file_name,md5sum     (a single value)
        // Matching on the singular stem accepts both; requiring `file_names`
        // rejected every per-slide export as an unrecognised file.
        // A UTF-8 BOM on the first column name is common when the file came
        // through Excel, so strip it before matching.
        $csvHeader = str_replace("\xEF\xBB\xBF", '', $firstLine);
        if (str_contains($csvHeader, ',')
            && str_contains($csvHeader, 'submitter_id')
            && str_contains($csvHeader, 'gdc_case_id')
            && (str_contains($csvHeader, 'file_name') || str_contains($csvHeader, 'file_id'))) {
            return 'clinical_csv';
        }

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

        // The mapping itself lives in GdcCaseImporter so the same records can
        // arrive either as an uploaded file or straight from the GDC API.
        $tally = app(GdcCaseImporter::class)->importCases($items);

        foreach ($tally as $key => $n) {
            $summary['clinical'][$key] = ($summary['clinical'][$key] ?? 0) + $n;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Clinical (flat CSV, one row per case)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Import a flat clinical CSV and attach its facts to slides ALREADY in the
     * system. Expected columns:
     *
     *   submitter_id, gdc_case_id, age, sex, race, histological_diagnosis,
     *   histological_subtype, icd_o_3_histology, cohort, cohort_label,
     *   tcga_sample_code, tcga_analyte_code, slide_count,
     *   file_ids, file_names, md5sums
     *
     * When a case carries more than one slide, file_ids / file_names / md5sums
     * hold ';'-separated lists in matching order.
     *
     * Samples are NEVER created here: a row only links to slides that already
     * exist, matched on samples.file_name first and samples.file_id second.
     * Slides that are absent are counted in samples_unmatched and reported.
     *
     * The clinical record is written to clinical_slide_case_information because
     * that is what SlideVerificationService reads (patientCase->clinicalInfo)
     * for the gender / age_at_index checks.
     */
    private function importClinicalCsv(string $content, array &$summary): void
    {
        $summary['clinical']['files']++;

        $rows = $this->parseCsvRows($content);
        if (!$rows) return;

        $this->applyClinicalRows($rows, $summary);
    }

    /**
     * Import our own nested cohort JSON — the multi-batch companion to the flat
     * CSV above, carrying every batch in one file:
     *
     *   { "batches": [ { "patients": [ { …case facts…, "slides": [ {file_id, file_name} ] } ] } ] }
     *
     * A bare { "patients": [...] } object and a plain list of patient objects
     * are accepted too. Each patient carries the same fields as a CSV row, so
     * the slides are flattened onto file_names / file_ids and handed to the
     * shared importer — same rules, including "link only, never create".
     */
    private function importClinicalBatchJson(string $content, array &$summary): void
    {
        $summary['clinical']['files']++;

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) return;

        $rows = $this->flattenCohortJson($decoded);
        if (!$rows) return;

        $this->applyClinicalRows($rows, $summary);
    }

    /**
     * Flatten nested cohort JSON into the header-keyed rows the CSV path yields.
     *
     * @return array<int, array<string, mixed>>
     */
    private function flattenCohortJson(array $decoded): array
    {
        if (isset($decoded['batches']) && is_array($decoded['batches'])) {
            $patients = [];
            foreach ($decoded['batches'] as $batch) {
                foreach ($batch['patients'] ?? [] as $patient) {
                    $patients[] = $patient;
                }
            }
        } elseif (isset($decoded['patients']) && is_array($decoded['patients'])) {
            $patients = $decoded['patients'];
        } else {
            $patients = array_values(array_filter($decoded, 'is_array'));
        }

        $rows = [];
        foreach ($patients as $patient) {
            if (!is_array($patient) || !isset($patient['gdc_case_id'])) continue;

            $names = [];
            $ids   = [];
            foreach ($patient['slides'] ?? [] as $slide) {
                if (!is_array($slide)) continue;
                $names[] = (string) ($slide['file_name'] ?? '');
                $ids[]   = (string) ($slide['file_id'] ?? '');
            }

            // Keep the untouched patient object under raw_json; only add the
            // two list columns the shared importer reads.
            $patient['file_names'] = implode(';', $names);
            $patient['file_ids']   = implode(';', $ids);
            $rows[] = $patient;
        }

        return $rows;
    }

    /**
     * Shared writer for both clinical shapes: upsert the case, upsert the
     * clinical record the verification reads, then attach existing slides.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function applyClinicalRows(array $rows, array &$summary): void
    {
        DB::transaction(function () use ($rows, &$summary) {
            foreach ($rows as $row) {
                $summary['clinical']['rows']++;

                $caseUuid = trim((string) ($row['gdc_case_id'] ?? ''));
                if ($caseUuid === '') continue;

                $submitterId = $this->blankToNull($row['submitter_id'] ?? null);
                $diagnosis   = $this->blankToNull($row['histological_diagnosis'] ?? null);
                $subtype     = $this->blankToNull($row['histological_subtype'] ?? null);

                // 1) Upsert the case shell (samples.case_id points at cases.id).
                $caseAttrs = array_filter([
                    'case_id'      => $caseUuid,
                    'submitter_id' => $submitterId,
                    'disease_type' => $diagnosis,
                ], fn ($v) => $v !== null && $v !== '');

                $caseRow = PatientCase::where('case_id', $caseUuid)->first();
                if ($caseRow) {
                    $caseRow->fill($caseAttrs)->save();
                    $summary['clinical']['cases_updated']++;
                } else {
                    $caseRow = PatientCase::create($caseAttrs);
                    $summary['clinical']['cases_created']++;
                }

                // 2) Upsert the clinical record the verification reads.
                $clinicalAttrs = array_filter([
                    'case_id'           => $caseUuid,
                    'submitter_id'      => $submitterId,
                    'disease_type'      => $diagnosis,
                    'gender'            => $this->blankToNull($row['sex'] ?? null),
                    'race'              => $this->blankToNull($row['race'] ?? null),
                    'age_at_index'      => is_numeric($row['age'] ?? null) ? (int) $row['age'] : null,
                    'primary_diagnosis' => $subtype ?: $diagnosis,
                    'morphology'        => $this->blankToNull($row['icd_o_3_histology'] ?? null),
                    'raw_json'          => $row,
                ], fn ($v) => $v !== null && $v !== '');

                $clinical = ClinicalCaseInformation::where('case_id', $caseUuid)->first();
                if ($clinical) {
                    $clinical->fill($clinicalAttrs)->save();
                    $summary['clinical']['clinical_updated']++;
                } else {
                    ClinicalCaseInformation::create($clinicalAttrs);
                    $summary['clinical']['clinical_created']++;
                }

                // 3) Attach only slides that already exist.
                $names = $this->slideCells($row, 'file_names', 'file_name');
                $ids   = $this->slideCells($row, 'file_ids', 'file_id');

                foreach ($names as $i => $fileName) {
                    $gdcFileId = $ids[$i] ?? null;

                    $sample = Sample::where('file_name', $fileName)->first();
                    if (!$sample && $gdcFileId) {
                        $sample = Sample::where('file_id', $gdcFileId)->first();
                    }
                    if (!$sample) {
                        $summary['clinical']['samples_unmatched']++;
                        continue;
                    }

                    $dirty = false;
                    if ((int) $sample->case_id !== (int) $caseRow->id) {
                        $sample->case_id = $caseRow->id;
                        $dirty = true;
                    }
                    // Record the GDC file UUID when the slide arrived without one.
                    if ($gdcFileId && !$sample->file_id) {
                        $sample->file_id = $gdcFileId;
                        $dirty = true;
                    }
                    if ($dirty) {
                        $sample->save();
                        $summary['clinical']['samples_linked']++;
                    }
                }
            }
        });
    }

    /**
     * Parse a CSV string into header-keyed rows. Strips a UTF-8 BOM from the
     * first column name and skips short rows.
     *
     * @return array<int, array<string, string>>
     */
    private function parseCsvRows(string $content): array
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle, 0, ',', '"', '');
        if (!$header) {
            fclose($handle);
            return [];
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header    = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $width     = count($header);

        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($line === [null] || count($line) < $width) continue;
            $rows[] = array_combine($header, array_slice($line, 0, $width));
        }
        fclose($handle);

        return $rows;
    }

    /**
     * The slide values of a clinical row, whichever shape the file uses.
     *
     * A per-case export lists every slide of the patient in one ';'-separated
     * cell (`file_names`); a per-slide export gives each slide its own row with
     * a single value (`file_name`). Reading both here means neither producer
     * has to be reshaped before it can be imported, and a per-slide file no
     * longer parses into zero slides while still reporting a successful case.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function slideCells(array $row, string $plural, string $singular): array
    {
        return $this->splitList($row[$plural] ?? $row[$singular] ?? null);
    }

    /**
     * Split a ';'-separated cell into trimmed, non-empty values.
     *
     * @return array<int, string>
     */
    private function splitList(?string $value): array
    {
        if ($value === null || trim($value) === '') return [];

        return array_values(array_filter(
            array_map('trim', explode(';', $value)),
            fn ($v) => $v !== ''
        ));
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return ($value === null || $value === '') ? null : $value;
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
        if ($s['clinical']['samples_unmatched']) $bits[] = "{$s['clinical']['samples_unmatched']} slide(s) in the file are not in the system — skipped";
        if ($s['unknown']) $bits[] = "{$s['unknown']} unrecognised file(s)";
        return $bits ? implode(' · ', $bits) : 'Nothing was imported.';
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  GTEx CSV import
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /admin/imports/gtex
     * Accepts a GTEx Portal CSV and runs the gtex:import-manifest artisan command.
     */
    public function storeGtex(Request $request): RedirectResponse
    {
        $request->validate([
            'gtex_csv' => ['required', 'file', 'max:10240',
                'mimetypes:text/csv,text/plain,application/csv,application/octet-stream,application/vnd.ms-excel'],
        ], [
            'gtex_csv.required' => 'Please choose a GTEx Portal CSV file.',
        ]);

        // Use the PHP upload temp file directly — no extra store/unlink needed.
        // Artisan::call() is synchronous so the temp file is guaranteed to exist
        // for the full duration of the command execution.
        $file      = $request->file('gtex_csv');
        $fullPath  = $file->getRealPath();
        $resultKey = 'gtex_import_result_' . uniqid('', true);

        $params = ['file' => $fullPath, '--result-key' => $resultKey];
        if ($request->boolean('dry_run'))      $params['--dry-run']      = true;
        if ($request->boolean('force_relink')) $params['--force-relink'] = true;

        $exitCode = Artisan::call('gtex:import-manifest', $params);
        $output   = Artisan::output();

        // Read structured results stored by the command via Cache
        $result = Cache::pull($resultKey);

        $redirectUrl = route('admin.samples') . '#tab-main-gtex-link';

        if ($exitCode !== 0) {
            return redirect($redirectUrl)
                ->with('gtex_import_error', trim($output) ?: 'Import failed with exit code ' . $exitCode);
        }

        return redirect($redirectUrl)
            ->with('gtex_import_result', $result)
            ->with('gtex_import_output', trim($output));
    }
}
