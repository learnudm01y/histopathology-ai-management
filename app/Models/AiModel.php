<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModel extends Model
{
    protected $table = 'ai_models';

    protected $fillable = [
        'name', 'full_name', 'provider', 'version',
        'model_type', 'level',
        'huggingface_url', 'paper_url', 'repo_url',
        'input_resolution', 'embedding_dim', 'parameters', 'license',
        'description', 'notes',
        'is_active', 'is_default',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
    ];

    public function getTypeLabel(): string
    {
        return match ($this->model_type) {
            'foundation'     => 'Foundation',
            'classification' => 'Classification',
            'segmentation'   => 'Segmentation',
            'detection'      => 'Detection',
            'multimodal'     => 'Multimodal',
            default          => 'Other',
        };
    }

    public function getLevelLabel(): string
    {
        return match ($this->level) {
            'patch'  => 'Patch / Tile-level',
            'slide'  => 'Slide-level (WSI)',
            'region' => 'Region-level',
            default  => 'Other',
        };
    }

    public function getTypeBadgeClass(): string
    {
        return match ($this->model_type) {
            'foundation'     => 'primary',
            'classification' => 'info',
            'segmentation'   => 'success',
            'detection'      => 'warning',
            'multimodal'     => 'danger',
            default          => 'secondary',
        };
    }
}
