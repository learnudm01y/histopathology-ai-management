<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlideVerification extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'verified_at'         => 'datetime',
        'file_size_mb'        => 'float',
        'mpp_x'               => 'float',
        'mpp_y'               => 'float',
        'magnification_power' => 'float',
        'tissue_area_percent' => 'float',
        'artifact_score'      => 'float',
        'blur_score'          => 'float',
        'background_ratio'    => 'float',
    ];

    /**
     * Aggregate verification outcomes, worst first.
     */
    public const STATUS_FAILED       = 'failed';
    public const STATUS_NEEDS_CASE   = 'needs_clinical_info';
    public const STATUS_PENDING      = 'pending';
    public const STATUS_PASSED       = 'passed';

    /**
     * Per-check states returned by evaluateChecks().
     */
    public const STATE_PASSED      = 'passed';
    public const STATE_FAILED      = 'failed';
    public const STATE_WARNING     = 'warning';
    public const STATE_NEEDS_INFO  = 'needs_info';
    public const STATE_NOT_CHECKED = 'not_checked';

    /**
     * Checks that are advisory rather than disqualifying.
     *
     * WSI_TRAINING_ELIGIBILITY_STANDARD.md, section C, marks C5 (heavy ink /
     * background), C6 (out-of-focus blur) and C7 (artifacts) as تحذير —
     * warnings — while C1 (tissue ≥ 10%) and C2 (≥ 50 patches) are إلزامي,
     * mandatory. The code used to reject on all five, which rejected 587
     * slides on blur and 556 on background against the project's own rules.
     *
     * An advisory check that does not meet its threshold is reported, and is
     * visible on the slide, but it does not reject the slide.
     */
    public const ADVISORY_CHECKS = ['blur_score', 'artifact_score', 'background_ratio'];

    /**
     * Definition of every check the verification pipeline runs.
     * Used to render the verification UI and to drive the pipeline itself.
     *
     * Each entry: [code, label, group, kind]
     *   - kind: 'status'   (passed/failed/not_checked enum)
     *           'present'  (column not-null/non-empty = passed)
     *           'numeric'  (numeric threshold check; logic in service)
     *           'clinical' (patient / case information: missing does NOT
     *                       reject the slide, it holds it in
     *                       needs_clinical_info until a human fills it in)
     */
    public const CHECKS = [
        // Identity & Linkage
        ['code' => 'file_path',              'label' => 'File exists',                            'group' => 'identity', 'kind' => 'present'],
        ['code' => 'slide_id',                'label' => 'Unique slide identifier',                 'group' => 'identity', 'kind' => 'present'],
        ['code' => 'patient_id',              'label' => 'Patient identifier exists',               'group' => 'identity', 'kind' => 'clinical'],
        ['code' => 'case_id',                 'label' => 'Case/sample identifier linked to patient','group' => 'identity', 'kind' => 'clinical'],
        ['code' => 'project_id',              'label' => 'Project/source identified',               'group' => 'identity', 'kind' => 'clinical'],

        // File & format
        ['code' => 'file_extension',          'label' => 'Supported file format',                   'group' => 'file',     'kind' => 'present'],
        ['code' => 'file_size_mb',            'label' => 'File size is reasonable (not too small)', 'group' => 'file',     'kind' => 'numeric'],

        // File health (deep checks — typically require OpenSlide)
        ['code' => 'open_slide_status',       'label' => 'File can be opened successfully',         'group' => 'health',   'kind' => 'status'],
        ['code' => 'file_integrity_status',   'label' => 'File is not corrupted',                   'group' => 'health',   'kind' => 'status'],
        ['code' => 'read_test_status',        'label' => 'No read failure when sampling regions',   'group' => 'health',   'kind' => 'status'],

        // WSI technical properties
        ['code' => 'level_count',             'label' => 'Multi-resolution levels exist',           'group' => 'wsi',      'kind' => 'numeric'],
        ['code' => 'slide_dimensions',        'label' => 'Slide dimensions are sufficient',         'group' => 'wsi',      'kind' => 'numeric'],
        ['code' => 'mpp_x',                   'label' => 'MPP-X value exists',                      'group' => 'wsi',      'kind' => 'numeric'],
        ['code' => 'mpp_y',                   'label' => 'MPP-Y value exists',                      'group' => 'wsi',      'kind' => 'numeric'],
        ['code' => 'magnification_power',     'label' => 'Resolution is suitable',                  'group' => 'wsi',      'kind' => 'numeric'],

        // Sample / clinical metadata
        ['code' => 'sample_type',             'label' => 'Sample type is appropriate',              'group' => 'clinical', 'kind' => 'present'],
        ['code' => 'stain_type',              'label' => 'Stain type is appropriate',               'group' => 'clinical', 'kind' => 'present'],
        ['code' => 'gender',                  'label' => 'Gender available for clinical tracking',  'group' => 'clinical', 'kind' => 'clinical'],
        ['code' => 'age_at_index',            'label' => 'Age available for clinical tracking',     'group' => 'clinical', 'kind' => 'clinical'],
        ['code' => 'label',                   'label' => 'Label exists (for supervised training)',  'group' => 'clinical', 'kind' => 'present'],
        ['code' => 'label_status',            'label' => 'Label is not ambiguous',                  'group' => 'clinical', 'kind' => 'status'],

        // Tissue quality
        ['code' => 'tissue_area_percent',     'label' => 'Sufficient tissue present',               'group' => 'tissue',   'kind' => 'numeric'],
        ['code' => 'tissue_patch_count',      'label' => 'Sufficient number of tissue patches',     'group' => 'tissue',   'kind' => 'numeric'],
        ['code' => 'artifact_score',          'label' => 'No severe artifacts',                     'group' => 'tissue',   'kind' => 'numeric'],
        ['code' => 'blur_score',              'label' => 'Blur is within acceptable range',         'group' => 'tissue',   'kind' => 'numeric'],
        ['code' => 'background_ratio',        'label' => 'Background does not dominate',            'group' => 'tissue',   'kind' => 'numeric'],
    ];

    public const GROUP_LABELS = [
        'identity' => 'Identity & Linkage',
        'file'     => 'File & Format',
        'health'   => 'File Health',
        'wsi'      => 'WSI Technical Properties',
        'clinical' => 'Sample / Clinical Metadata',
        'tissue'   => 'Tissue Quality',
    ];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }

    /**
     * Evaluate every check and return an array describing pass/fail/skip
     * suitable for rendering on the sample-show page.
     *
     * @return array<int, array{code:string,label:string,group:string,state:string,detail:?string}>
     */
    public function evaluateChecks(): array
    {
        $results = [];

        foreach (self::CHECKS as $check) {
            [$state, $detail] = $this->evaluateSingle($check);

            // Advisory checks report, they do not disqualify.
            if ($state === self::STATE_FAILED && in_array($check['code'], self::ADVISORY_CHECKS, true)) {
                $state = self::STATE_WARNING;
            }

            $results[] = [
                'code'   => $check['code'],
                'label'  => $check['label'],
                'group'  => $check['group'],
                'kind'   => $check['kind'],
                'state'  => $state,    // passed | failed | warning | needs_info | not_checked
                'detail' => $detail,
            ];
        }

        return $results;
    }

    /**
     * Codes of the checks that describe the patient / case behind the slide.
     *
     * @return array<int, string>
     */
    public static function clinicalCheckCodes(): array
    {
        return array_column(
            array_filter(self::CHECKS, fn (array $c) => $c['kind'] === 'clinical'),
            'code'
        );
    }

    /**
     * Human labels of the case-information fields that are still empty.
     *
     * @return array<int, string>
     */
    public function missingClinicalLabels(): array
    {
        $missing = [];

        foreach (self::CHECKS as $check) {
            if ($check['kind'] !== 'clinical') {
                continue;
            }
            $value = $this->{$check['code']} ?? null;
            if ($value === null || $value === '') {
                $missing[] = $check['label'];
            }
        }

        return $missing;
    }

    /**
     * True when the slide itself is fine but its case information is not
     * complete — the slide is neither accepted nor rejected.
     */
    public function needsClinicalInfo(): bool
    {
        return $this->verification_status === self::STATUS_NEEDS_CASE;
    }

    /**
     * @return array{0:string,1:?string} [state, detail]
     */
    private function evaluateSingle(array $check): array
    {
        $code = $check['code'];
        $kind = $check['kind'];

        if ($kind === 'status') {
            $value = $this->{$code} ?? 'not_checked';
            return match ($value) {
                'passed', 'valid' => ['passed', null],
                'failed', 'ambiguous', 'unknown' => ['failed', (string) $value],
                default           => ['not_checked', null],
            };
        }

        if ($kind === 'present') {
            $value = $this->{$code} ?? null;
            return $value === null || $value === ''
                ? [self::STATE_FAILED, 'missing']
                : [self::STATE_PASSED, (string) $value];
        }

        // Patient / case information. A gap here is not a defect of the slide:
        // the file can be perfectly good and still be unusable for training
        // until somebody records who it came from. It therefore never rejects
        // the slide — it holds it in `needs_clinical_info` instead.
        if ($kind === 'clinical') {
            $value = $this->{$code} ?? null;
            return $value === null || $value === ''
                ? [self::STATE_NEEDS_INFO, 'awaiting case information']
                : [self::STATE_PASSED, (string) $value];
        }

        // numeric thresholds
        return match ($code) {
            'file_size_mb'        => $this->numCheck($this->file_size_mb,        fn ($v) => $v >= 5,         'min 5 MB'),
            'level_count'         => $this->numCheck($this->level_count,         fn ($v) => $v >= 2,         'pyramidal (≥ 2 levels)'),
            'slide_dimensions'    => (function () {
                $w = $this->slide_width; $h = $this->slide_height;
                if ($w === null || $h === null) return ['not_checked', null];
                return ($w >= 1024 && $h >= 1024) ? ['passed', "{$w} × {$h}"] : ['failed', "{$w} × {$h} (min 1024)"];
            })(),
            'magnification_power' => $this->numCheck($this->magnification_power, fn ($v) => $v >= 20,        'min 20x'),
            'mpp_x'               => $this->numCheck($this->mpp_x,               fn ($v) => $v > 0,          '> 0'),
            'mpp_y'               => $this->numCheck($this->mpp_y,               fn ($v) => $v > 0,          '> 0'),
            // C1 and C2 of the eligibility standard — mandatory.
            'tissue_area_percent' => $this->numCheck($this->tissue_area_percent, fn ($v) => $v >= 10,        'min 10%'),
            'tissue_patch_count'  => $this->numCheck($this->tissue_patch_count,  fn ($v) => $v >= 50,        'min 50 patches'),
            // C5–C7 — advisory (see ADVISORY_CHECKS).
            'artifact_score'      => $this->numCheck($this->artifact_score,      fn ($v) => $v <= 0.30,      'max 0.30'),
            'blur_score'          => $this->numCheck($this->blur_score,          fn ($v) => $v <= 0.65,      'max 0.65'),
            // background_ratio is computed as 1 - tissue_area_percent/100 by
            // both inspectors, so it is the same measurement as C1 stated the
            // other way round. At 0.85 it was STRICTER than C1 and contradicted
            // it: a slide with 12% tissue passed "sufficient tissue" and failed
            // "background does not dominate" on the identical number. The
            // threshold is now C1's exact complement.
            'background_ratio'    => $this->numCheck($this->background_ratio,    fn ($v) => $v <= 0.90,      'max 0.90'),
            default               => ['not_checked', null],
        };
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function numCheck($value, callable $pass, string $rule): array
    {
        if ($value === null) {
            return ['not_checked', $rule];
        }
        return $pass($value)
            ? ['passed', (string) $value]
            : ['failed', $value . ' — required ' . $rule];
    }
}
