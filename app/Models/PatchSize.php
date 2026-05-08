<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatchSize extends Model
{
    protected $table = 'patch_sizes';

    protected $fillable = [
        'size_px',
        'label',
        'wsi_level',
        'overlap_px',
        'ai_model_id',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class, 'patch_size_id');
    }

    public function getDisplayLabel(): string
    {
        $base = "{$this->size_px}×{$this->size_px} px";
        if ($this->overlap_px > 0) {
            $base .= " (overlap: {$this->overlap_px}px)";
        }
        return $base;
    }
}
