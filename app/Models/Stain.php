<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stain extends Model
{
    protected $fillable = [
        'name',
        'abbreviation',
        'stain_type',
        'marker',
        'description',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Human-readable stain type label.
     */
    public function getTypeLabel(): string
    {
        return match ($this->stain_type) {
            'routine'    => 'Routine',
            'special'    => 'Special',
            'IHC'        => 'Immunohistochemistry (IHC)',
            'ISH'        => 'In-Situ Hybridisation (ISH)',
            'fluorescent'=> 'Fluorescent',
            'cytology'   => 'Cytology',
            default      => 'Other',
        };
    }

    /**
     * Badge colour for the stain type (Bootstrap class suffix).
     */
    public function getTypeBadgeClass(): string
    {
        return match ($this->stain_type) {
            'routine'    => 'primary',
            'special'    => 'info',
            'IHC'        => 'warning',
            'ISH'        => 'secondary',
            'fluorescent'=> 'danger',
            'cytology'   => 'dark',
            default      => 'light',
        };
    }
}
