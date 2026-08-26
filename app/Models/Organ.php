<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The root of the taxonomy.
 *
 *      Organ (this)  →  Category (clinical group)  →  DiseaseSubtype
 *
 * Organ names are curated here and only ever *selected* elsewhere — never typed
 * into the taxonomy portal — so a disease can never end up under the wrong
 * anatomical site.
 */
class Organ extends Model
{
    protected $fillable = ['name', 'is_active', 'notes'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(PatientCase::class);
    }

    /** Clinical groups defined for this organ. */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** Every disease under this organ, across all of its clinical groups. */
    public function diseaseSubtypes(): HasMany
    {
        return $this->hasMany(DiseaseSubtype::class);
    }

    public function trainingRuns(): HasMany
    {
        return $this->hasMany(TrainingRun::class);
    }
}
