<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The leaf of the taxonomy — one exact disease entity within one organ:
 *
 *      Organ  →  Category (clinical group)  →  DiseaseSubtype (this)
 *
 * This is the level a fine-grained training run turns into classes, so its
 * identity has to be stable: `organ_id` is denormalised from the parent group
 * and carries a UNIQUE(organ_id, name) constraint, because two rows with the
 * same disease name under one organ are the same clinical entity and must never
 * become two training classes.
 */
class DiseaseSubtype extends Model
{
    protected $fillable = ['organ_id', 'category_id', 'name', 'is_active', 'notes'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // The organ is owned by the parent group — never set independently.
        $syncOrgan = function (DiseaseSubtype $subtype) {
            if ($subtype->isDirty('category_id') || $subtype->organ_id === null) {
                $organId = Category::whereKey($subtype->category_id)->value('organ_id');
                if ($organId !== null) {
                    $subtype->organ_id = $organId;
                }
            }
        };

        static::creating($syncOrgan);
        static::updating($syncOrgan);
    }

    public function organ(): BelongsTo
    {
        return $this->belongsTo(Organ::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class);
    }

    /** Diseases belonging to one organ, regardless of clinical group. */
    public function scopeForOrgan(Builder $query, ?int $organId): Builder
    {
        return $organId === null ? $query : $query->where('organ_id', $organId);
    }

    /** "Breast › Malignant › Invasive ductal carcinoma" — the full taxonomy path. */
    public function getQualifiedNameAttribute(): string
    {
        $parts = array_filter([
            $this->organ?->name,
            $this->category?->label_en,
            $this->name,
        ]);
        return implode(' › ', $parts);
    }
}
