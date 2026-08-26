<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A clinical group — the middle level of the taxonomy:
 *
 *      Organ  →  Category (this)  →  DiseaseSubtype
 *
 * A category belongs to exactly one organ. The organ is never typed by hand; it
 * is chosen from the `organs` table, which is what keeps "Malignant" of the
 * breast and "Malignant" of the lung two distinct groups instead of one mixed
 * bucket.
 */
class Category extends Model
{
    protected $fillable = [
        'organ_id', 'label_en', 'is_active', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Moving a group to another organ must carry its diseases with it,
        // otherwise the denormalised organ_id on disease_subtypes goes stale and
        // the UNIQUE(organ_id, name) guarantee silently stops meaning anything.
        static::updated(function (Category $category) {
            if ($category->wasChanged('organ_id')) {
                $category->diseaseSubtypes()->update(['organ_id' => $category->organ_id]);
            }
        });
    }

    public function organ(): BelongsTo
    {
        return $this->belongsTo(Organ::class);
    }

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class);
    }

    public function diseaseSubtypes(): HasMany
    {
        return $this->hasMany(DiseaseSubtype::class);
    }

    /** Groups belonging to one organ. */
    public function scopeForOrgan(Builder $query, ?int $organId): Builder
    {
        return $organId === null ? $query : $query->where('organ_id', $organId);
    }

    /** Groups that have not been assigned an organ yet (legacy rows). */
    public function scopeUnrooted(Builder $query): Builder
    {
        return $query->whereNull('organ_id');
    }

    /** "Breast › Malignant" — unambiguous label for pickers and logs. */
    public function getQualifiedNameAttribute(): string
    {
        $organ = $this->organ?->name;
        return $organ ? "{$organ} › {$this->label_en}" : $this->label_en;
    }
}
