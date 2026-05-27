<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TrainingRun extends Model
{
    protected $fillable = [
        'training_head_id',
        'feature_model_id',
        'server_id',
        'status',
        'sample_count',
        'label_type',
        'label_map',
        'model_type',
        'epochs',
        'learning_rate',
        'bag_size',
        'n_classes',
        'metrics',
        'gdrive_output_dir',
        'model_gdrive_path',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'label_map'   => 'array',
        'metrics'     => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function trainingHead(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'training_head_id');
    }

    public function featureModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'feature_model_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(ServerName::class, 'server_id');
    }

    public function samples(): BelongsToMany
    {
        return $this->belongsToMany(Sample::class, 'training_run_samples');
    }

    public function getBestAucAttribute(): float
    {
        return $this->metrics['best_val_auc'] ?? 0.0;
    }

    public function getIsFinishedAttribute(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled']);
    }
}
