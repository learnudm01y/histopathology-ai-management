<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Magnification extends Model
{
    protected $fillable = [
        'label',
        'value',
        'folder_name',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'value'     => 'integer',
        'is_active' => 'boolean',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class, 'magnification_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Human-readable display label, e.g. "x20 — Standard". */
    public function getDisplayLabel(): string
    {
        return $this->notes
            ? "{$this->label} — {$this->notes}"
            : $this->label;
    }
}
