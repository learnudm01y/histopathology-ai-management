<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sample;
use App\Support\SampleListFilters;
use App\Support\SimpleXlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Full Excel export of the sample catalogue.
 *
 * Unlike SampleRejectionsController — which answers "why is this slide not in
 * the training set?" and therefore only reports slides that are not accepted —
 * this export is the plain catalogue: every sample, whatever its quality,
 * verification, storage or pipeline state, with one row per sample and every
 * field the samples table (and the records hanging off it) actually holds.
 *
 * Status is exported as data, never used as a filter. A row is included even
 * when the slide was never verified, never downloaded or never labelled.
 */
class SamplesExportController extends Controller
{
    /**
     * GET /admin/samples/export
     *
     * By default the file contains every sample in the database. Pass
     * `filtered=1` to apply the Samples list filters (organ, disease group,
     * storage status, search) so the file matches the list on screen; the
     * status of a sample is still never a filter either way.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $filtered = $request->boolean('filtered');

        $query = Sample::query()
            ->with([
                'organ', 'category', 'diseaseSubtype', 'dataSource', 'stain',
                'patientCase.clinicalInfo', 'slideVerification',
                'patchSize', 'patchServer', 'featureExtractionAiModel',
            ])
            ->orderBy('id');

        if ($filtered) {
            SampleListFilters::apply($query, $request);
        }

        $path = tempnam(sys_get_temp_dir(), 'samples_export') . '.xlsx';

        SimpleXlsxWriter::write($path, $this->headers(), $this->rows($query), 'Samples');

        $filename = 'samples-' . ($filtered ? 'filtered-' : 'all-')
            . now()->format('Ymd-His') . '.xlsx';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    // ── Sheet definition ─────────────────────────────────────────────────────

    /**
     * Column order is grouped the way somebody reads a slide record:
     * identity → classification → patient/case → storage → pipeline →
     * quality → measured WSI properties → timestamps.
     *
     * @return array<int, string>
     */
    private function headers(): array
    {
        return [
            // Identity
            'Sample ID', 'File name', 'Slide ID (submitter)', 'Entity ID', 'Entity type',
            'GDC file ID', 'MD5', 'MD5 verified', 'Data format', 'Data type',
            'Access level', 'GDC state',

            // Classification
            'Organ', 'Disease group', 'Disease subtype', 'Disease subtype (free text)',
            'Tissue name', 'Stain', 'Stain marker', 'Data source',

            // Patient / case
            'Case (submitter ID)', 'Case UUID', 'Project', 'Primary site', 'Disease type',
            'Gender', 'Age at index', 'Primary diagnosis', 'Vital status',

            // Storage
            'Storage status', 'File size (GB)', 'File size (bytes)', 'Upload type',
            'Storage path', 'WSI remote path', 'Storage link', 'Bulk folder original path',
            'Download started at', 'Download completed at',

            // Pipeline — tiling
            'Tiling status', 'Tile count', 'Tile size (px)', 'Magnification',
            'Tissue coverage % (tiling)', 'Tiles path', 'Tiles Drive path', 'Tiling completed at',
            'Patch size', 'Patch server',

            // Pipeline — features
            'Feature extraction status', 'Feature model', 'Feature model version',
            'Features patch count', 'Features failed patch count', 'Features Drive path',
            'Feature extraction error', 'Feature extraction completed at',

            // Pipeline — downstream
            'MIL status', 'Pathology decision status', 'Final diagnosis status',
            'Final diagnosis result',

            // Quality / verification
            'Quality status', 'Quality rejection reason', 'Is usable',
            'Verification status', 'Verified at', 'Verification notes',

            // Measured WSI properties
            'File size (MB, measured)', 'File extension', 'Open slide', 'File integrity',
            'Read test', 'Level count', 'Slide width', 'Slide height', 'MPP X', 'MPP Y',
            'Magnification power', 'Scanner vendor', 'Scanner model',
            'Tissue area %', 'Tissue patch count', 'Artifact score', 'Blur score',
            'Background ratio', 'Label', 'Label status',

            // Timestamps
            'Created at', 'Updated at',
        ];
    }

    /**
     * Streamed row generator — `lazy()` keeps memory flat regardless of how
     * large the catalogue grows.
     */
    private function rows(Builder $query): \Generator
    {
        foreach ($query->lazy(500) as $sample) {
            $v        = $sample->slideVerification;
            $case     = $sample->patientCase;
            $clinical = $case?->clinicalInfo;

            yield [
                // Identity
                (int) $sample->id,
                $sample->file_name,
                $sample->entity_submitter_id,
                $sample->entity_id,
                $sample->entity_type,
                $sample->file_id,
                $sample->md5sum,
                $this->bool($sample->md5_verified),
                $sample->data_format,
                $sample->data_type,
                $sample->access_level,
                $sample->gdc_state,

                // Classification
                $sample->organ?->name,
                $sample->category?->label_en,
                $sample->diseaseSubtype?->name,
                $sample->disease_subtype,
                $sample->tissue_name,
                $sample->stain?->name,
                $sample->stain_marker,
                $sample->dataSource?->name,

                // Patient / case
                $case?->submitter_id,
                $case?->case_id,
                $case?->project_id,
                $case?->primary_site,
                $case?->disease_type,
                $clinical?->gender,
                $clinical?->age_at_index,
                $clinical?->primary_diagnosis,
                $clinical?->vital_status,

                // Storage
                $sample->storage_status,
                $sample->file_size_gb,
                $sample->file_size_bytes,
                $sample->upload_type,
                $sample->storage_path,
                $sample->wsi_remote_path,
                $sample->storage_link,
                $sample->bulk_folder_original_path,
                $this->dt($sample->download_started_at),
                $this->dt($sample->download_completed_at),

                // Pipeline — tiling
                $sample->tiling_status,
                $sample->tile_count,
                $sample->tile_size_px,
                $sample->magnification,
                $sample->tissue_coverage_pct,
                $sample->tiles_path,
                $sample->tiles_gdrive_path,
                $this->dt($sample->tiling_completed_at),
                $sample->patchSize?->label,
                $sample->patchServer?->name,

                // Pipeline — features
                $sample->feature_extraction_status,
                $sample->featureExtractionAiModel?->name,
                $sample->features_model_version,
                $sample->features_patch_count,
                $sample->features_failed_patch_count,
                $sample->features_gdrive_path,
                $sample->feature_extraction_error,
                $this->dt($sample->feature_extraction_completed_at),

                // Pipeline — downstream
                $sample->mil_status,
                $sample->pathology_decision_status,
                $sample->final_diagnosis_status,
                $sample->final_diagnosis_result,

                // Quality / verification
                $sample->quality_status_label,
                $sample->quality_rejection_reason,
                $this->bool($sample->is_usable),
                $v?->verification_status,
                $this->dt($v?->verified_at),
                $v?->notes,

                // Measured WSI properties
                $v?->file_size_mb,
                $v?->file_extension,
                $v?->open_slide_status,
                $v?->file_integrity_status,
                $v?->read_test_status,
                $v?->level_count,
                $v?->slide_width,
                $v?->slide_height,
                $v?->mpp_x,
                $v?->mpp_y,
                $v?->magnification_power,
                $v?->scanner_vendor,
                $v?->scanner_model,
                $v?->tissue_area_percent,
                $v?->tissue_patch_count,
                $v?->artifact_score,
                $v?->blur_score,
                $v?->background_ratio,
                $v?->label,
                $v?->label_status,

                // Timestamps
                $this->dt($sample->created_at),
                $this->dt($sample->updated_at),
            ];
        }
    }

    // ── Formatting ───────────────────────────────────────────────────────────

    /** Booleans read better as Yes/No than as 1/0, and null must stay blank. */
    private function bool(mixed $value): ?string
    {
        return $value === null ? null : ($value ? 'Yes' : 'No');
    }

    /** Dates are exported as text so Excel cannot reinterpret the format. */
    private function dt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d H:i:s')
            : (string) $value;
    }
}
