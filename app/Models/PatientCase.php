<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

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

    public function clinicalInfo(): HasOne
    {
        return $this->hasOne(ClinicalCaseInformation::class, 'case_id', 'case_id');
    }

    /**
     * What this case is a case *of*, read off its slides.
     *
     * A case has no diagnosis column — the label lives on the slide, where the
     * taxonomy puts it. A slide filed only under a clinical group falls back to
     * that group's name, because "we know it is a Tumor but not which one" is a
     * different answer from "we know nothing". A case whose slides carry two
     * diseases honestly reports both rather than picking one.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function getDiseaseLabelsAttribute(): Collection
    {
        return $this->samples
            ->map(fn (Sample $sample) => $sample->diseaseSubtype?->name ?? $sample->category?->label_en)
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }
}
