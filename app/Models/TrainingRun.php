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
        'parent_label_map',
        'child_to_parent',
        'model_type',
        'epochs',
        'learning_rate',
        'bag_size',
        'n_classes',
        'n_parent_classes',
        'hier_weight',
        'use_class_weights',
        'hierarchy_consistent_inference',
        'metrics',
        'gdrive_output_dir',
        'model_gdrive_path',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'label_map'        => 'array',
        'parent_label_map' => 'array',
        'child_to_parent'  => 'array',
        'metrics'          => 'array',
        'hier_weight'      => 'float',
        'use_class_weights' => 'boolean',
        'hierarchy_consistent_inference' => 'boolean',
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
        return $this->belongsToMany(Sample::class, 'training_run_samples')
                    ->withPivot('training_phase', 'label', 'parent_label');
    }

    public function getBestAucAttribute(): float
    {
        return $this->metrics['best_val_auc'] ?? 0.0;
    }

    public function getIsFinishedAttribute(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled']);
    }

    // ── Hierarchical helpers ────────────────────────────────────────────────

    /** True when this run was trained with a coarse (parent) auxiliary head. */
    public function isHierarchical(): bool
    {
        return (int) $this->n_parent_classes > 1 && ! empty($this->child_to_parent);
    }

    /** Human-readable name of a fine (leaf / exact disease) class index. */
    public function fineLabel(int $idx): string
    {
        return $this->label_map[$idx] ?? "class_{$idx}";
    }

    /** Human-readable name of a coarse (parent category) class index. */
    public function parentLabel(int $idx): string
    {
        return $this->parent_label_map[$idx] ?? "parent_{$idx}";
    }

    /**
     * Primary model-selection metric.
     * Binary runs report AUC; multi-class fine-grained runs report macro-AUC,
     * falling back to balanced accuracy when AUC is undefined.
     */
    public function getBestMetricAttribute(): array
    {
        $m = $this->metrics ?? [];
        if (isset($m['best_val_macro_auc']) && $m['best_val_macro_auc'] > 0) {
            return ['name' => 'Macro AUC', 'value' => (float) $m['best_val_macro_auc']];
        }
        if (isset($m['best_val_balanced_acc'])) {
            return ['name' => 'Balanced Acc', 'value' => (float) $m['best_val_balanced_acc']];
        }
        return ['name' => 'AUC', 'value' => (float) ($m['best_val_auc'] ?? 0.0)];
    }
}
