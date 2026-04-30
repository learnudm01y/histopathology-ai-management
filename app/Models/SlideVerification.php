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
     * Definition of every check the verification pipeline runs.
     * Used to render the verification UI and to drive the pipeline itself.
     *
     * Each entry: [code, label, group, kind]
     *   - kind: 'status' (passed/failed/not_checked enum)
     *           'present' (column not-null/non-empty = passed)
     *           'numeric' (numeric threshold check; logic in service)
     */
    public const CHECKS = [
        // Identity & Linkage
        ['code' => 'file_path',              'label' => 'File exists',                            'group' => 'identity', 'kind' => 'present'],
        ['code' => 'slide_id',                'label' => 'Unique slide identifier',                 'group' => 'identity', 'kind' => 'present'],
        ['code' => 'patient_id',              'label' => 'Patient identifier exists',               'group' => 'identity', 'kind' => 'present'],
        ['code' => 'case_id',                 'label' => 'Case/sample identifier linked to patient','group' => 'identity', 'kind' => 'present'],
        ['code' => 'project_id',              'label' => 'Project/source identified',               'group' => 'identity', 'kind' => 'present'],

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
        ['code' => 'gender',                  'label' => 'Gender available for clinical tracking',  'group' => 'clinical', 'kind' => 'info'],
        ['code' => 'age_at_index',            'label' => 'Age available for clinical tracking',     'group' => 'clinical', 'kind' => 'info'],
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
            $results[] = [
                'code'   => $check['code'],
                'label'  => $check['label'],
                'group'  => $check['group'],
                'state'  => $state,    // passed | failed | not_checked
                'detail' => $detail,
            ];
        }

        return $results;
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
                ? ['failed', 'missing']
                : ['passed', (string) $value];
        }

        // 'info' fields are optional metadata — not_checked when absent, passed when present.
        if ($kind === 'info') {
            $value = $this->{$code} ?? null;
            return $value === null || $value === ''
                ? ['not_checked', null]
                : ['passed', (string) $value];
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
            'tissue_area_percent' => $this->numCheck($this->tissue_area_percent, fn ($v) => $v >= 10,        'min 10%'),
            'tissue_patch_count'  => $this->numCheck($this->tissue_patch_count,  fn ($v) => $v >= 50,        'min 50 patches'),
            'artifact_score'      => $this->numCheck($this->artifact_score,      fn ($v) => $v <= 0.30,      'max 0.30'),
            'blur_score'          => $this->numCheck($this->blur_score,          fn ($v) => $v <= 0.65,      'max 0.65'),
            'background_ratio'    => $this->numCheck($this->background_ratio,    fn ($v) => $v <= 0.85,      'max 0.85'),
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
