<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organ extends Model
{
    protected $fillable = ['name', 'is_active', 'notes'];

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(PatientCase::class);
    }
}
