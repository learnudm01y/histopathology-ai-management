<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A disease entity within one organ:
 *
 *      Organ  →  Category (clinical group)  →  DiseaseSubtype (this, self-nesting)
 *
 * A disease may itself hold finer diseases — "Malignant" is a real diagnosis and
 * also the parent of "Infiltrating ductal carcinoma" — so this level recurses via
 * `parent_id` instead of stopping at a single tier.
 *
 * Nesting does NOT weaken the identity guarantee that training depends on:
 * `organ_id` is denormalised from the parent group and carries
 * UNIQUE(organ_id, name), because two rows with the same disease name under one
 * organ are the same clinical entity and must never become two training classes —
 * whatever depth they sit at.
 */
class DiseaseSubtype extends Model
{
    /**
     * How deep the disease chain may go, root disease = 1. A guard against a
     * taxonomy that grows unreadable rather than a clinical limit.
     */
    public const MAX_DEPTH = 5;

    protected $fillable = ['organ_id', 'category_id', 'parent_id', 'name', 'is_active', 'notes'];

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

    /** The coarser disease this one refines, or null at the top of the group. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Finer diseases under this one. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    /** `children` with the whole subtree eager-loaded, for rendering the tree. */
    public function childrenRecursive(): HasMany
    {
        return $this->children()->with('childrenRecursive');
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

    /** Diseases sitting directly under their clinical group. */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /** Root disease = 1, its children = 2, … */
    public function getDepthAttribute(): int
    {
        return count($this->ancestors()) + 1;
    }

    /**
     * Ancestors nearest-first. Walks by id and stops at MAX_DEPTH so a corrupt
     * row can never spin this into an infinite loop.
     *
     * @return array<int, DiseaseSubtype>
     */
    public function ancestors(): array
    {
        $chain  = [];
        $node   = $this;
        $guard  = 0;

        while ($node->parent_id !== null && $guard++ < self::MAX_DEPTH) {
            $node = $node->relationLoaded('parent') && $node->parent
                ? $node->parent
                : self::find($node->parent_id);

            if ($node === null) {
                break;
            }
            $chain[] = $node;
        }

        return $chain;
    }

    /** Every disease below this one, at any depth. */
    public function descendants(): Collection
    {
        return $this->children->flatMap(
            fn (self $child) => collect([$child])->merge($child->descendants())
        );
    }

    /** "Breast › Tumor › Malignant › Infiltrating ductal carcinoma" — the full path. */
    public function getQualifiedNameAttribute(): string
    {
        $parts = array_filter([
            $this->organ?->name,
            $this->category?->label_en,
            ...array_reverse(array_map(fn (self $a) => $a->name, $this->ancestors())),
            $this->name,
        ]);

        return implode(' › ', $parts);
    }
}
