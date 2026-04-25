<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'label_en', 'is_active', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class);
    }

    public function diseaseSubtypes(): HasMany
    {
        return $this->hasMany(DiseaseSubtype::class);
    }
}
