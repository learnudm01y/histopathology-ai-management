<?php

namespace App\Services;

use App\Models\ClinicalCaseInformation;
use App\Models\PatientCase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Turns GDC case records into `cases` + `clinical_slide_case_information` rows.
 *
 * The shape it expects is whatever the GDC /cases endpoint returns when asked to
 * expand demographic, diagnoses and follow_ups — the same JSON the portal hands
 * out, so a file downloaded from the portal and a response fetched over the API
 * land here identically.
 *
 * This lived inside ImportsController, reachable only by uploading a file
 * through the admin page. It was lifted out unchanged so a command can reach it
 * too: pulling the same records straight from the API is the difference between
 * waiting on someone to export a file and simply having the data.
 *
 * Idempotent — keyed on the GDC case UUID, so re-importing refreshes a case
 * rather than duplicating it. Every freshly written case also sweeps up orphan
 * samples that were filed before their case existed, which is the normal order
 * of events when slides are imported from a manifest first.
 */
class GdcCaseImporter
{
    public function __construct(private readonly CaseLinker $linker) {}

    /**
     * @param  array  $cases  One GDC case record, or a list of them.
     * @return array{rows:int, cases_created:int, cases_updated:int,
     *               clinical_created:int, clinical_updated:int, samples_linked:int}
     */
    public function importCases(array $cases): array
    {
        if (Arr::isAssoc($cases)) {
            $cases = [$cases];
        }

        $tally = [
            'rows' => 0, 'cases_created' => 0, 'cases_updated' => 0,
            'clinical_created' => 0, 'clinical_updated' => 0, 'samples_linked' => 0,
        ];

        DB::transaction(function () use ($cases, &$tally) {
            foreach ($cases as $row) {
                if (! is_array($row)) continue;

                // A portal export names the UUID `case_id`; the API returns the
                // same value as `id`. Accept either so both routes land here.
                $caseUuid    = $row['case_id'] ?? $row['id'] ?? null;
                $submitterId = $row['submitter_id'] ?? null;
                if (! $caseUuid) continue;

                $tally['rows']++;

                // 1) Upsert the minimal case record
                $caseAttrs = array_filter([
                    'case_id'      => $caseUuid,
                    'submitter_id' => $submitterId,
                    'project_id'   => $row['project']['project_id'] ?? null,
                    'primary_site' => $row['primary_site'] ?? null,
                    'disease_type' => $row['disease_type'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');

                $caseExisting = PatientCase::where('case_id', $caseUuid)->first();
                if ($caseExisting) {
                    $caseExisting->fill($caseAttrs)->save();
                    $tally['cases_updated']++;
                    $caseRow = $caseExisting;
                } else {
                    $caseRow = PatientCase::create($caseAttrs);
                    $tally['cases_created']++;
                }

                // Slides are usually filed before their case exists, so adopt
                // whichever orphans belong to this one.
                $tally['samples_linked'] += $this->linker->linkCaseToOrphanSamples($caseRow);

                // 2) The rich clinical record
                $clinicalAttrs = $this->buildClinicalAttributes($row, $caseUuid, $submitterId);

                $clinical = ClinicalCaseInformation::where('case_id', $caseUuid)->first();
                if ($clinical) {
                    $clinical->fill($clinicalAttrs)->save();
                    $tally['clinical_updated']++;
                } else {
                    ClinicalCaseInformation::create($clinicalAttrs);
                    $tally['clinical_created']++;
                }
            }
        });

        return $tally;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Flatten one GDC case into the clinical table's columns.
     *
     * `diagnoses[0]` is treated as the primary diagnosis for the flat columns,
     * while the whole array is kept in the JSON column — a case can carry a
     * synchronous or prior primary alongside the one being studied, and the flat
     * columns alone would hide that.
     */
    private function buildClinicalAttributes(array $row, string $caseUuid, ?string $submitterId): array
    {
        $demographic = $row['demographic'] ?? [];
        $diagnoses   = $row['diagnoses']   ?? [];
        $primaryDx   = $diagnoses[0] ?? [];
        $treatments  = $primaryDx['treatments'] ?? [];
        $pathDetail  = $primaryDx['pathology_details'][0] ?? [];
        $followUps   = $row['follow_ups'] ?? [];

        $molecularTests     = [];
        $otherClinicalAttrs = [];
        foreach ($followUps as $fu) {
            foreach ($fu['molecular_tests'] ?? [] as $mt) {
                $molecularTests[] = $mt;
            }
            foreach ($fu['other_clinical_attributes'] ?? [] as $oc) {
                $otherClinicalAttrs[] = $oc;
            }
        }

        return [
            'case_id'          => $caseUuid,
            'submitter_id'     => $submitterId,
            'project_id'       => $row['project']['project_id'] ?? null,
            'disease_type'     => $row['disease_type'] ?? null,
            'primary_site'     => $row['primary_site'] ?? null,
            'index_date'       => $row['index_date'] ?? null,
            'consent_type'     => $row['consent_type'] ?? null,
            'days_to_consent'  => $row['days_to_consent'] ?? null,
            'lost_to_followup' => $row['lost_to_followup'] ?? null,
            'state'            => $row['state'] ?? null,
            'updated_datetime' => $row['updated_datetime'] ?? null,

            // Demographic
            'demographic_id'                     => $demographic['demographic_id'] ?? null,
            'gender'                             => $demographic['gender'] ?? null,
            'sex_at_birth'                       => $demographic['sex_at_birth'] ?? null,
            'race'                               => $demographic['race'] ?? null,
            'ethnicity'                          => $demographic['ethnicity'] ?? null,
            'age_at_index'                       => $demographic['age_at_index'] ?? null,
            'days_to_birth'                      => $demographic['days_to_birth'] ?? null,
            'vital_status'                       => $demographic['vital_status'] ?? null,
            'age_is_obfuscated'                  => $demographic['age_is_obfuscated'] ?? null,
            'country_of_residence_at_enrollment' => $demographic['country_of_residence_at_enrollment'] ?? null,
            'demographic_state'                  => $demographic['state'] ?? null,
            'demographic_updated_datetime'       => $demographic['updated_datetime'] ?? null,

            // Primary diagnosis
            'diagnosis_id'                 => $primaryDx['diagnosis_id'] ?? null,
            'diagnosis_submitter_id'       => $primaryDx['submitter_id'] ?? null,
            'primary_diagnosis'            => $primaryDx['primary_diagnosis'] ?? null,
            'tissue_or_organ_of_origin'    => $primaryDx['tissue_or_organ_of_origin'] ?? null,
            'site_of_resection_or_biopsy'  => $primaryDx['site_of_resection_or_biopsy'] ?? null,
            'icd_10_code'                  => $primaryDx['icd_10_code'] ?? null,
            'morphology'                   => $primaryDx['morphology'] ?? null,
            'classification_of_tumor'      => $primaryDx['classification_of_tumor'] ?? null,
            'diagnosis_is_primary_disease' => $primaryDx['diagnosis_is_primary_disease'] ?? null,
            'method_of_diagnosis'          => $primaryDx['method_of_diagnosis'] ?? null,
            'synchronous_malignancy'       => $primaryDx['synchronous_malignancy'] ?? null,
            'laterality'                   => $primaryDx['laterality'] ?? null,
            'prior_malignancy'             => $primaryDx['prior_malignancy'] ?? null,
            'prior_treatment'              => $primaryDx['prior_treatment'] ?? null,
            'metastasis_at_diagnosis'      => $primaryDx['metastasis_at_diagnosis'] ?? null,
            'year_of_diagnosis'            => $primaryDx['year_of_diagnosis'] ?? null,
            'days_to_diagnosis'            => $primaryDx['days_to_diagnosis'] ?? null,
            'days_to_last_follow_up'       => $primaryDx['days_to_last_follow_up'] ?? null,
            'age_at_diagnosis'             => $primaryDx['age_at_diagnosis'] ?? null,
            'diagnosis_state'              => $primaryDx['state'] ?? null,
            'diagnosis_updated_datetime'   => $primaryDx['updated_datetime'] ?? null,

            // AJCC staging
            'ajcc_pathologic_stage'       => $primaryDx['ajcc_pathologic_stage'] ?? null,
            'ajcc_pathologic_t'           => $primaryDx['ajcc_pathologic_t'] ?? null,
            'ajcc_pathologic_n'           => $primaryDx['ajcc_pathologic_n'] ?? null,
            'ajcc_pathologic_m'           => $primaryDx['ajcc_pathologic_m'] ?? null,
            'ajcc_staging_system_edition' => $primaryDx['ajcc_staging_system_edition'] ?? null,

            // Pathology details
            'pathology_detail_id'               => $pathDetail['pathology_detail_id'] ?? null,
            'pathology_detail_submitter_id'     => $pathDetail['submitter_id'] ?? null,
            'consistent_pathology_review'       => $pathDetail['consistent_pathology_review'] ?? null,
            'lymph_nodes_positive'              => $pathDetail['lymph_nodes_positive'] ?? null,
            'lymph_nodes_tested'                => $pathDetail['lymph_nodes_tested'] ?? null,
            'pathology_detail_state'            => $pathDetail['state'] ?? null,
            'pathology_detail_created_datetime' => $pathDetail['created_datetime'] ?? null,
            'pathology_detail_updated_datetime' => $pathDetail['updated_datetime'] ?? null,

            // JSON columns — the nested arrays are kept whole
            'sites_of_involvement'      => $primaryDx['sites_of_involvement'] ?? null,
            'diagnoses'                 => $diagnoses,
            'treatments'                => $treatments,
            'follow_ups'                => $followUps,
            'molecular_tests'           => $molecularTests,
            'other_clinical_attributes' => $otherClinicalAttrs,
            'raw_json'                  => $row,
        ];
    }
}
