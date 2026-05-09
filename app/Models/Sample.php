<?php

namespace App\Models;

use App\Models\SlideVerification;
use App\Models\Stain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sample extends Model
{
    protected $fillable = [
        'case_id', 'organ_id', 'data_source_id',
        'file_id', 'gdrive_source_id', 'file_name', 'md5sum',
        'file_size_bytes', 'file_size_gb',
        'data_format', 'data_type', 'access_level', 'gdc_state',
        'entity_submitter_id', 'entity_id', 'entity_type',
        'category_id', 'stain_id', 'stain_marker', 'disease_subtype', 'tissue_name', 'training_phase',
        'storage_link', 'storage_path', 'wsi_remote_path', 'upload_type', 'bulk_folder_original_path',
        'storage_status',
        'download_started_at', 'download_completed_at', 'md5_verified',
        'tiling_status', 'tile_count', 'tile_size_px',
        'magnification', 'tissue_coverage_pct', 'tiles_path', 'tiling_completed_at',
        'quality_status', 'quality_rejection_reason', 'is_usable',
        'feature_extraction_status', 'feature_extraction_completed_at',
        'mil_status', 'mil_completed_at',
        'pathology_decision_status', 'pathology_decision_completed_at',
        'final_diagnosis_status', 'final_diagnosis_result', 'final_diagnosis_completed_at',
        // Patch extraction tracking
        'patch_server_id', 'patch_size_id', 'magnification_id',
        'tiles_gdrive_folder_id', 'tiles_gdrive_path',
        // Feature extraction tracking (RunPod)
        'feature_extraction_ai_model_id', 'feature_extraction_server_id',
        'features_gdrive_path', 'features_gdrive_folder_id', 'features_runpod_path',
        'features_patch_count', 'features_failed_patch_count',
        'features_model_version', 'feature_extraction_error',
    ];

    protected $casts = [
        'md5_verified'           => 'boolean',
        'is_usable'              => 'boolean',
        'download_started_at'    => 'datetime',
        'download_completed_at'  => 'datetime',
        'tiling_completed_at'                 => 'datetime',
        'feature_extraction_completed_at'    => 'datetime',
        'mil_completed_at'                   => 'datetime',
        'pathology_decision_completed_at'    => 'datetime',
        'final_diagnosis_completed_at'       => 'datetime',
    ];

    public function organ(): BelongsTo
    {
        return $this->belongsTo(Organ::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function patientCase(): BelongsTo
    {
        return $this->belongsTo(PatientCase::class, 'case_id');
    }

    public function stain(): BelongsTo
    {
        return $this->belongsTo(Stain::class);
    }

    public function slideVerification(): HasOne
    {
        return $this->hasOne(SlideVerification::class);
    }

    public function patchServer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ServerName::class, 'patch_server_id');
    }

    public function patchSize(): BelongsTo
    {
        return $this->belongsTo(\App\Models\PatchSize::class, 'patch_size_id');
    }

    public function magnification(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Magnification::class, 'magnification_id');
    }

    // ── Helpers ─────────────────────────────────────────────

    public function getFileSizeHumanAttribute(): string
    {
        if ($this->file_size_bytes === null) {
            return '—';
        }
        $bytes = (int) $this->file_size_bytes;
        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 2) . ' GiB';
        }
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 2) . ' MiB';
        }
        if ($bytes >= 1_024) {
            return number_format($bytes / 1_024, 1) . ' KiB';
        }
        return $bytes . ' B';
    }

    public function getStorageStatusBadgeAttribute(): string
    {
        return match ($this->storage_status) {
            'available'      => 'success',
            'downloading'    => 'info',
            'verifying'      => 'info',
            'corrupted'      => 'danger',
            'missing'        => 'warning',
            default          => 'secondary',
        };
    }

    public function getTilingStatusBadgeAttribute(): string
    {
        return match ($this->tiling_status) {
            'done'       => 'success',
            'processing' => 'info',
            'failed'     => 'danger',
            default      => 'secondary',
        };
    }

    public function getQualityStatusBadgeAttribute(): string
    {
        return match ($this->quality_status) {
            'passed'       => 'success',
            'rejected'     => 'danger',
            'needs_review' => 'warning',
            default        => 'secondary',
        };
    }

    /**
     * Returns the ordered pipeline stages with their current status.
     * Stage 1 (Patch Extraction) is derived from tiling_status.
     */
    public function getPipelineStagesAttribute(): array
    {
        $tilingDone  = $this->tiling_status === 'done';
        $tilingFail  = $this->tiling_status === 'failed';
        $tilingProc  = $this->tiling_status === 'processing';

        return [
            [
                'key'          => 'patch_extraction',
                'label'        => 'Patch Extraction',
                'status'       => $tilingFail ? 'failed' : ($tilingDone ? 'completed' : ($tilingProc ? 'processing' : 'pending')),
                'completed_at' => $this->tiling_completed_at,
            ],
            [
                'key'          => 'feature_extraction',
                'label'        => 'Feature Extraction',
                'status'       => $this->feature_extraction_status ?? 'pending',
                'completed_at' => $this->feature_extraction_completed_at,
            ],
            [
                'key'          => 'mil',
                'label'        => 'MIL Stage',
                'status'       => $this->mil_status ?? 'pending',
                'completed_at' => $this->mil_completed_at,
            ],
            [
                'key'          => 'pathology_decision',
                'label'        => 'Pathology Decision',
                'status'       => $this->pathology_decision_status ?? 'pending',
                'completed_at' => $this->pathology_decision_completed_at,
            ],
            [
                'key'          => 'final_diagnosis',
                'label'        => 'Final Diagnosis',
                'status'       => $this->final_diagnosis_status ?? 'pending',
                'completed_at' => $this->final_diagnosis_completed_at,
                'result'       => $this->final_diagnosis_result,
            ],
        ];
    }
}
