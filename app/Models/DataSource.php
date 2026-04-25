<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends Model
{
    protected $fillable = [
        'name', 'full_name', 'base_url',
        'description', 'total_slides_available', 'is_active',
    ];

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class);
    }
}
