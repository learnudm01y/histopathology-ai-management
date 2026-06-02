<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InferenceRun extends Model
{
    protected $fillable = [
        'training_run_id',
        'server_id',
        'slide_source',
        'sample_id',
        'slide_name',
        'slide_features_gdrive_path',
        'status',
        'prediction',
        'attention_map_gdrive_path',
        'gdrive_output_dir',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'prediction'  => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function trainingRun(): BelongsTo
    {
        return $this->belongsTo(TrainingRun::class, 'training_run_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(ServerName::class, 'server_id');
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class, 'sample_id');
    }

    /** Best predicted class label (if completed). */
    public function getPredictedLabelAttribute(): ?string
    {
        return $this->prediction['class_label'] ?? null;
    }

    /** Confidence score 0-1 (if completed). */
    public function getConfidenceAttribute(): ?float
    {
        return isset($this->prediction['confidence'])
            ? (float) $this->prediction['confidence']
            : null;
    }

    /** Confidence as a percentage string. */
    public function getConfidencePercentAttribute(): string
    {
        $c = $this->confidence;
        return $c !== null ? number_format($c * 100, 1) . '%' : '—';
    }

    /** Status badge Bootstrap class. */
    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            'completed'  => 'success',
            'processing' => 'info',
            'failed'     => 'danger',
            default      => 'secondary',
        };
    }
}
