<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientCase extends Model
{
    protected $table = 'cases';

    protected $fillable = [
        'case_id', 'submitter_id', 'project_id',
        'organ_id', 'data_source_id',
        'primary_site', 'disease_type',
    ];

    public function organ(): BelongsTo
    {
        return $this->belongsTo(Organ::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class, 'case_id');
    }
}
