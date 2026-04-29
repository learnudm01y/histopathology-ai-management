<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalCaseInformation extends Model
{
    protected $table = 'clinical_slide_case_information';

    protected $guarded = ['id'];

    protected $casts = [
        'sites_of_involvement'      => 'array',
        'diagnoses'                 => 'array',
        'treatments'                => 'array',
        'follow_ups'                => 'array',
        'molecular_tests'           => 'array',
        'other_clinical_attributes' => 'array',
        'raw_json'                  => 'array',
    ];

    /**
     * Link by GDC case UUID — the natural key shared with cases.case_id.
     */
    public function patientCase(): BelongsTo
    {
        return $this->belongsTo(PatientCase::class, 'case_id', 'case_id');
    }
}
