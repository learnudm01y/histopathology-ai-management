<?php

namespace App\Services;

use App\Models\ClinicalCaseInformation;
use App\Models\Sample;
use App\Models\SlideVerification;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Slide verification pipeline.
 *
 * Runs every applicable check defined in SlideVerification::CHECKS for a
 * given Sample, persisting the results into the slide_verifications table.
 *
 * Deep WSI checks (level_count, mpp_x/y, magnification, tissue %, blur,
 * artifact, background ratio, OpenSlide open/integrity/read tests) require
 * an external Python+OpenSlide pipeline. This PHP service computes every
 * metadata-based check it CAN compute and leaves Python-required checks
 * marked as `not_checked` (NULL/'not_checked') so the UI shows them as
 * pending until the Python worker fills them in.
 */
class SlideVerificationService
{
    /**
     * Supported WSI extensions accepted by the downstream pipeline.
     */
    public const SUPPORTED_EXTENSIONS = ['svs', 'tif', 'tiff', 'ndpi', 'scn', 'mrxs', 'vsi'];

    /**
     * Re-verify a slide if its existing verification is older than this
     * many hours (used by the periodic worker).
     */
    public const REVERIFY_AFTER_HOURS = 12;

    /**
     * Run the verification pipeline for a sample.
     * Always returns the up-to-date SlideVerification record.
     */
    public function verify(Sample $sample): SlideVerification
    {
        $sample->loadMissing(['stain', 'category', 'patientCase.clinicalInfo', 'dataSource']);

        $clinical = $sample->patientCase?->clinicalInfo;

        $data = $this->collectMetadata($sample, $clinical);

        // Augment with OpenSlide-derived facts when the WSI file is locally
        // accessible. Drive-only files are skipped — those will be filled by
        // the dedicated worker server later.
        $localPath = $this->resolveLocalWsiPath($sample);
        if ($localPath !== null) {
            $openSlideData = $this->runOpenSlideInspector($localPath);
            // Only overwrite fields the inspector actually returned.
            foreach ($openSlideData as $key => $value) {
                if ($value !== null) {
                    $data[$key] = $value;
                }
            }

            // ── Auto-populate stain on the Sample if discovered from SVS ──
            // When the SVS metadata contains a stain name and the sample has
            // no stain assigned yet, look up or derive the Stain record and
            // link it so the UI shows the correct stain without manual entry.
            if (!empty($openSlideData['stain_normalized']) && $sample->stain_id === null) {
                $this->assignStainFromOpenSlide($sample, $openSlideData['stain_normalized']);
            }
        }

        // ── Lookup: preserve existing Python-computed values ──────────
        // (moved above to use sample_id lookup — see updateOrCreate block below)
        $slideId  = $data['slide_id'] ?? null;
        $existing = null; // will be resolved in updateOrCreate block

        // Persist or update the verification record.
        // ALWAYS key on sample_id — it is the FK used by Sample::slideVerification()
        // and by WsiPreviewJob::_persistVerification(). Using slide_id as the
        // primary key caused two separate rows (one per job) that the UI never
        // merged: the sample show page would display the WsiPreviewJob row
        // (with WSI metrics but no identity fields) instead of this one.
        $data['sample_id'] = $sample->id;

        $existing = SlideVerification::where('sample_id', $sample->id)->first();

        if ($existing) {
            // Numeric fields: keep the existing value when $data is null.
            $preserveIfNull = [
                'level_count', 'slide_width', 'slide_height',
                'mpp_x', 'mpp_y', 'magnification_power',
                'tissue_area_percent', 'background_ratio',
                'tissue_patch_count', 'artifact_score', 'blur_score',
            ];
            foreach ($preserveIfNull as $field) {
                if (($data[$field] ?? null) === null && $existing->{$field} !== null) {
                    $data[$field] = $existing->{$field};
                }
            }
            // Status fields: preserve 'passed'/'failed' when PHP can only say
            // 'not_checked' (file on Drive, no local path accessible this run).
            foreach (['open_slide_status', 'file_integrity_status', 'read_test_status'] as $col) {
                if (($data[$col] ?? 'not_checked') === 'not_checked'
                    && in_array($existing->{$col}, ['passed', 'failed'], true)) {
                    $data[$col] = $existing->{$col};
                }
            }
        }

        $verification = SlideVerification::updateOrCreate(
            ['sample_id' => $sample->id],
            $data,
        );

        // Now compute the aggregate verification_status (passed/failed/pending).
        $verification = $this->finalize($verification);

        Log::info("[SlideVerificationService] Sample #{$sample->id} verified → {$verification->verification_status}");

        return $verification;
    }

    /**
     * Collect every metadata-derived field for the verification record.
     *
     * Strategy: pull every value we can from the existing relational data
     * (samples + cases + clinical_slide_case_information + data_sources +
     * stains) so the verification dashboard reflects what the system
     * already knows. Truly WSI-only properties (mpp, level_count, slide
     * dimensions, blur, artifacts…) are left for the OpenSlide worker.
     *
     * @return array<string, mixed>
     */
    private function collectMetadata(Sample $sample, ?ClinicalCaseInformation $clinical): array
    {
        // ── Identity & linkage ──────────────────────────────────────────
        $slideId    = $sample->entity_submitter_id ?: ($sample->entity_id ?: null);
        $filePath   = $sample->wsi_remote_path ?: $sample->storage_path;

        // patient_id — prefer clinical info or linked case; fall back to a
        // best-effort parse of the barcode embedded in the file_name.
        // Supports both TCGA (TCGA-BH-A203-11A-…) and GTEx (GTEX-1117F-2826.svs).
        $patientId = $clinical?->submitter_id
            ?: $sample->patientCase?->submitter_id
            ?: $this->parsePatientIdFromFileName($sample->file_name);

        // case_id — GDC case UUID or GTEx tissue sample ID.
        $caseId = $sample->patientCase?->case_id
            ?: $clinical?->case_id
            ?: $this->parseUuidFromPath($sample->storage_path)
            ?: $this->parseUuidFromPath($sample->wsi_remote_path)
            ?: $this->parseGtexSampleId($sample->file_name);

        // project_id — linked case → clinical → data_source name (TCGA-*).
        $projectId = $sample->patientCase?->project_id
            ?: $clinical?->project_id
            ?: $this->deriveProjectIdFromDataSource($sample);

        // ── File-level facts available without OpenSlide ───────────────
        $extension = strtolower(pathinfo($sample->file_name ?? '', PATHINFO_EXTENSION))
                  ?: strtolower($sample->data_format ?? '');
        $extension = $extension ?: null;

        $sizeMb = $sample->file_size_bytes
            ? round(((int) $sample->file_size_bytes) / 1_048_576, 3)
            : null;

        // ── Health checks (require OpenSlide / Python worker) ──────────
        // We mark these `not_checked` here. A Python worker can later set
        // them to 'passed' / 'failed' on the same row keyed by sample_id.
        $openSlide       = 'not_checked';
        $fileIntegrity   = 'not_checked';
        $readTestStatus  = 'not_checked';

        // We CAN do a basic file-format sanity check though: if the
        // extension is not in the supported list, we can mark
        // open_slide_status as 'failed' upfront — it cannot be opened
        // by the pipeline at all in that case.
        if ($extension !== null && !in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            $openSlide      = 'failed';
            $fileIntegrity  = 'failed';
            $readTestStatus = 'failed';
        }

        // ── Sample / clinical metadata ──────────────────────────────────
        // entity_type is GDC-specific (slide/aliquot/…); for non-GDC sources
        // like GTEx derive a meaningful label from the category instead.
        $sampleType = $this->humanizeSampleType($sample->entity_type);
        if (!$sampleType && $sample->category) {
            $catLabel = strtolower($sample->category->label_en ?? $sample->category->name ?? '');
            $sampleType = match ($catLabel) {
                'tumor'        => 'Tumor Tissue',
                'normal'       => 'Normal Tissue',
                'metastatic'   => 'Metastatic Tissue',
                'adjacent'     => 'Adjacent Normal',
                default        => ucfirst($catLabel) ?: null,
            };
        }

        // stain_type — prefer the Stain model. For TCGA diagnostic slides
        // (which is virtually all of TCGA-BRCA pathology) stain is H&E by
        // default unless an explicit stain has been recorded.
        $stainType = $sample->stain?->name
            ?? $sample->stain?->stain_type
            ?? $sample->stain_marker
            ?? $this->defaultStainForProject($projectId);

        $gender   = $clinical?->gender;
        $ageAtIdx = $clinical?->age_at_index;

        // Category model uses label_en as the human label (e.g. tumor / normal)
        $label = $sample->category?->label_en
            ?? ($sample->getAttributes()['category'] ?? null);

        // Determine label clarity
        $labelStatus = null;
        if ($label) {
            $lower = strtolower((string) $label);
            $labelStatus = match (true) {
                in_array($lower, ['unknown', 'mixed', 'ambiguous', 'n/a'], true) => 'ambiguous',
                default                                                          => 'valid',
            };
        }

        // ── Tissue / WSI fields the system already knows ───────────────
        // samples.magnification is a string like "20x" → extract numeric.
        $magnificationPower = $this->parseMagnification($sample->magnification);

        // tissue_coverage_pct on samples is the tiling-pipeline output;
        // tile_count is the produced patch count. Re-use both.
        $tissueAreaPercent = $sample->tissue_coverage_pct !== null
            ? (float) $sample->tissue_coverage_pct
            : null;
        $tissuePatchCount  = $sample->tile_count !== null
            ? (int) $sample->tile_count
            : null;

        return [
            'slide_id'              => $slideId,
            'file_path'             => $filePath,
            'patient_id'            => $patientId,
            'case_id'               => $caseId,
            'project_id'            => $projectId,

            'file_extension'        => $extension,
            'file_size_mb'          => $sizeMb,

            'open_slide_status'     => $openSlide,
            'file_integrity_status' => $fileIntegrity,
            'read_test_status'      => $readTestStatus,

            // Pure WSI properties — populated by the OpenSlide worker.
            'level_count'           => null,
            'slide_width'           => null,
            'slide_height'          => null,
            'mpp_x'                 => null,
            'mpp_y'                 => null,
            'magnification_power'   => $magnificationPower,

            'sample_type'           => $sampleType,
            'stain_type'            => $stainType,
            'gender'                => $gender,
            'age_at_index'          => $ageAtIdx,
            'label'                 => $label,
            'label_status'          => $labelStatus,

            // Tissue / patch metrics — pulled from the tiling pipeline
            'tissue_area_percent'   => $tissueAreaPercent,
            'tissue_patch_count'   => $tissuePatchCount,

            // Heavy QC scores — handled by the future CLAM/OpenSlide worker
            'artifact_score'        => null,
            'blur_score'            => null,
            'background_ratio'      => null,

            'verified_at'           => now(),
        ];
    }

    /**
     * Attempt to extract a TCGA or GTEx donor/patient barcode from a slide
     * file name.
     * - TCGA: "TCGA-BH-A203-11A-…" → "TCGA-BH-A203"
     * - GTEx: "GTEX-1117F-2826.svs" → "GTEX-1117F" (donor ID)
     */
    private function parsePatientIdFromFileName(?string $fileName): ?string
    {
        if (!$fileName) {
            return null;
        }
        // TCGA: three leading barcode segments
        if (preg_match('/(TCGA-[A-Z0-9]{2}-[A-Z0-9]{4})/i', $fileName, $m)) {
            return strtoupper($m[1]);
        }
        // GTEx: donor ID = first two dash-separated segments of the filename
        if (preg_match('/^(GTEX-[A-Z0-9]+)/i', $fileName, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    /**
     * Extract a GTEx tissue sample ID from a file name.
     * "GTEX-1117F-2826.svs" → "GTEX-1117F-2826"
     */
    private function parseGtexSampleId(?string $fileName): ?string
    {
        if (!$fileName) {
            return null;
        }
        if (preg_match('/^(GTEX-[A-Z0-9]+-[A-Z0-9]+)/i', $fileName, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    /**
     * Extract a UUID v4 substring from a path (used to recover case_id from
     * a Drive storage path like ".../TCGA-BRCA/Normal/<uuid>/<file>.svs").
     */
    private function parseUuidFromPath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $path, $m)) {
            return strtolower($m[0]);
        }
        return null;
    }

    /**
     * Derive a project_id from a sample's data source. TCGA data sources
     * are typically named "TCGA-BRCA", "TCGA-LUAD" etc. — a perfectly
     * valid project_id when no explicit case linkage is available.
     */
    private function deriveProjectIdFromDataSource(Sample $sample): ?string
    {
        $name = $sample->dataSource?->name;
        if (!$name) {
            return null;
        }
        return preg_match('/^TCGA-[A-Z]{2,}$/i', $name) === 1
            ? strtoupper($name)
            : $name;
    }

    /**
     * Map the GDC entity_type ('slide', 'aliquot', 'analyte'…) to a more
     * human-readable sample_type for display in the verification panel.
     */
    private function humanizeSampleType(?string $entityType): ?string
    {
        if (!$entityType) {
            return null;
        }
        return match (strtolower($entityType)) {
            'slide'   => 'Diagnostic Slide',
            'aliquot' => 'Aliquot',
            'analyte' => 'Analyte',
            'portion' => 'Portion',
            default   => ucwords($entityType),
        };
    }

    /**
     * Parse the leading numeric value from samples.magnification ("20x" → 20).
     */
    private function parseMagnification(?string $magnification): ?float
    {
        if (!$magnification) {
            return null;
        }
        if (preg_match('/(\d+(?:\.\d+)?)/', $magnification, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    /**
     * Resolve a sample's WSI file to a local filesystem path the
     * OpenSlide inspector can read. Returns null when the file is only
     * available on remote storage (e.g. Google Drive) — those will be
     * handled by the future dedicated slide worker.
     *
     * Resolution order:
     *   1. SLIDES_LOCAL_ROOT env var + sample.wsi_remote_path / storage_path
     *      (rebases a known prefix onto a local mount).
     *   2. The user's original bulk-upload folder (when stored).
     *   3. Returns null otherwise.
     */
    private function resolveLocalWsiPath(Sample $sample): ?string
    {
        $remoteRel = $sample->wsi_remote_path ?: $sample->storage_path;
        $localRoot = env('SLIDES_LOCAL_ROOT');

        // 1) Locally-mounted slide root: SLIDES_LOCAL_ROOT/<remote_path>
        if ($localRoot && $remoteRel) {
            $candidate = rtrim($localRoot, "/\\") . DIRECTORY_SEPARATOR
                       . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $remoteRel), '/\\');
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // 2) Bulk-upload original local folder (only the basename was kept,
        //    so we can only attempt this if the user has SLIDES_BULK_ROOT set).
        $bulkRoot = env('SLIDES_BULK_ROOT');
        if ($bulkRoot && $sample->bulk_folder_original_path && $sample->file_name) {
            $candidate = rtrim($bulkRoot, "/\\") . DIRECTORY_SEPARATOR
                       . $sample->bulk_folder_original_path . DIRECTORY_SEPARATOR
                       . $sample->file_name;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Invoke scripts/openslide_inspect.py against the given local file and
     * decode the JSON result. Returns an empty array on any failure so the
     * caller can continue with the metadata-only fields it already gathered.
     *
     * @return array<string, mixed>
     */
    private function runOpenSlideInspector(string $localPath): array
    {
        $script = base_path('scripts/openslide_inspect.py');
        if (!is_file($script)) {
            return [];
        }

        // Use the configured python executable (env PYTHON), falling back
        // to the OS default. On Windows the launcher is "py", on POSIX it
        // is typically "python3".
        $python = env('PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');

        try {
            $process = new Process([$python, $script, $localPath]);
            $process->setTimeout(180);
            $process->run();

            $stdout = trim($process->getOutput());
            if ($stdout === '') {
                Log::warning("[SlideVerificationService] OpenSlide inspector returned empty output for {$localPath}. stderr: " . $process->getErrorOutput());
                return [];
            }

            $decoded = json_decode($stdout, true);
            if (!is_array($decoded)) {
                Log::warning("[SlideVerificationService] OpenSlide inspector produced non-JSON output: {$stdout}");
                return [];
            }

            if (!empty($decoded['error'])) {
                Log::info("[SlideVerificationService] OpenSlide inspector reported: {$decoded['error']}");
            }

            // Drop the meta 'error' key — the rest is column-shaped.
            unset($decoded['error']);

            return $decoded;
        } catch (ProcessFailedException $e) {
            Log::warning('[SlideVerificationService] OpenSlide inspector process failed: ' . $e->getMessage());
            return [];
        } catch (\Throwable $e) {
            Log::warning('[SlideVerificationService] OpenSlide inspector exception: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * For TCGA and GTEx diagnostic slides where no explicit Stain is
     * recorded, we default to H&E since that is the de-facto stain.
     * This is intentionally narrow to avoid masking missing data elsewhere.
     */
    private function defaultStainForProject(?string $projectId): ?string
    {
        if (!$projectId) {
            return null;
        }
        return (preg_match('/^TCGA-/i', $projectId) || preg_match('/^GTEX/i', $projectId))
            ? 'H&E'
            : null;
    }

    /**
     * Look up or create the Stain record matching the normalized name
     * returned by OpenSlide, then link it to the Sample.
     */
    public function assignStainFromOpenSlide(Sample $sample, string $normalizedName): void
    {
        try {
            $stain = \App\Models\Stain::where('name', $normalizedName)
                ->orWhere('abbreviation', $normalizedName)
                ->first();

            if (!$stain) {
                // First time we see this stain — create a minimal record.
                $stain = \App\Models\Stain::create([
                    'name'         => $normalizedName,
                    'abbreviation' => $normalizedName,
                    'stain_type'   => 'routine',
                    'is_active'    => true,
                ]);
            }

            $sample->stain_id = $stain->id;
            $sample->save();

            Log::info("[SlideVerificationService] Auto-assigned stain '{$normalizedName}' to Sample #{$sample->id} from SVS metadata.");
        } catch (\Throwable $e) {
            Log::warning("[SlideVerificationService] Failed to assign stain from OpenSlide for Sample #{$sample->id}: {$e->getMessage()}");
        }
    }

    /**
     * Compute the aggregate verification_status from the per-check results.
     *
     * Rules:
     *   - any 'failed' check → verification_status = 'failed'
     *   - else if every check is 'passed' → 'passed'
     *   - otherwise (some checks still 'not_checked') → 'pending'
     */
    /**
     * Public wrapper: recompute aggregate verification_status from current check results.
     * Called after a field is updated inline via the PATCH endpoint.
     */
    public function recomputeStatus(SlideVerification $verification): void
    {
        $this->finalize($verification);
    }

    private function finalize(SlideVerification $verification): SlideVerification
    {
        $results = $verification->evaluateChecks();

        $hasFailed     = false;
        $hasNotChecked = false;

        foreach ($results as $r) {
            if ($r['state'] === 'failed') {
                $hasFailed = true;
            } elseif ($r['state'] === 'not_checked') {
                $hasNotChecked = true;
            }
        }

        $status = match (true) {
            $hasFailed     => 'failed',
            $hasNotChecked => 'pending',
            default        => 'passed',
        };

        $verification->update(['verification_status' => $status]);

        // Sync Sample.quality_status so the samples table reflects verification.
        $qualityStatus = match ($status) {
            'passed'  => 'passed',
            'failed'  => 'rejected',
            default   => 'pending',
        };
        Sample::where('id', $verification->sample_id)
              ->update(['quality_status' => $qualityStatus]);

        return $verification->fresh();
    }
}
