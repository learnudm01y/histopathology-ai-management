<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slide inside one operation.
 *
 * `sample_file_name` and `case_submitter_id` duplicate what the sample and case
 * rows hold. That duplication is the point: both are bulk-deletable here, and
 * the audit has to keep naming what it processed after they are gone.
 */
class OperationItem extends Model
{
    protected $fillable = [
        'operation_id', 'sample_id', 'case_id',
        'sample_file_name', 'case_submitter_id', 'status', 'message',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }

    public function patientCase(): BelongsTo
    {
        return $this->belongsTo(PatientCase::class, 'case_id');
    }

    /** The slide's name — live row when it survives, snapshot when it does not. */
    public function getDisplayNameAttribute(): string
    {
        return $this->sample?->file_name
            ?: ($this->sample_file_name ?: 'slide #' . $this->sample_id);
    }

    /** True once the slide row itself is gone and only the snapshot remains. */
    public function getIsOrphanedAttribute(): bool
    {
        return $this->sample_id === null || $this->sample === null;
    }

    public function getStatusColourAttribute(): string
    {
        return match ($this->status) {
            'completed'  => 'success',
            'failed'     => 'danger',
            'processing' => 'info',
            'skipped'    => 'secondary',
            default      => 'light',
        };
    }
}
