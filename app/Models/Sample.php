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
    ];

    protected $casts = [
        'md5_verified'           => 'boolean',
        'is_usable'              => 'boolean',
        'download_started_at'    => 'datetime',
        'download_completed_at'  => 'datetime',
        'tiling_completed_at'    => 'datetime',
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
}
