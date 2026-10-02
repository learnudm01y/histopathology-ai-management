<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One V2 Diagnose run. The parsed result (level-0 coordinates) lives on disk
 * as final.json in the run directory; this row holds everything needed to
 * find, filter and audit it.
 */
class V2Diagnosis extends Model
{
    protected $table = 'v2_diagnoses';

    public const RUNNING = ['queued', 'waiting_slide', 'waiting_quota', 'tiling', 'preparing', 'analysing', 'finalising'];

    protected $fillable = [
        'sample_id', 'user_id', 'organ', 'stain', 'age', 'sex', 'race', 'clinical_notes',
        'status', 'stage_message', 'events',
        'wsi_path', 'slide_width', 'slide_height', 'base_mpp', 'patch_size', 'target_mpp',
        'scale_l0', 'patches', 'run_dir',
        'claude_model', 'claude_session_id', 'prompt', 'raw_output', 'cost_usd',
        'duration_ms', 'num_turns', 'usage',
        'summary', 'diagnosis_code', 'diagnosis', 'confidence', 'regions_count', 'sam_refined', 'warnings', 'error',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'events'      => 'array',
        'usage'       => 'array',
        'warnings'    => 'array',
        'sam_refined' => 'boolean',
        'base_mpp'    => 'float',
        'target_mpp'  => 'float',
        'scale_l0'    => 'float',
        'cost_usd'    => 'float',
        'confidence'  => 'float',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Codes that mean carcinoma, invasive or in situ. */
    private const MALIGNANT = ['IDC', 'ILC', 'MIXED', 'DCIS', 'LCIS', 'MUC', 'TUB', 'MPC', 'MBC', 'MED'];

    /** Other codes for the same recorded diagnosis: counted as right. */
    private const SAME = [
        'LGG'  => ['ASTRO', 'OLIGO', 'ODG', 'OA', 'DA', 'AA', 'AO'],   // grade 2-3 diffuse gliomas
        'PRAD' => ['ACINAR', 'PCA'],
    ];

    /** The right family but another type or grade: counted as partial. */
    private const NEAR = [
        'GBM'  => ['LGG', 'ASTRO', 'OLIGO', 'ODG', 'OA', 'DA', 'AA', 'AO'],
        'LGG'  => ['GBM'],
        'LUAD' => ['NSCLC', 'ADSQ', 'PSC', 'PLEO'],   // pleomorphic/sarcomatoid: a non-small cell carcinoma
        'LUSC' => ['NSCLC', 'ADSQ', 'PSC', 'PLEO'],
    ];

    /** Codes that say no tumour was found. */
    private const NO_TUMOUR = ['NORMAL', 'BENIGN', 'NONDX', 'FA', 'SA', 'PAP', 'UDH', 'FCC', 'ADH', 'ALH', 'FEA'];

    /** Codes that name a benign lesion. */
    private const BENIGN_LESION = ['BENIGN', 'FA', 'PHY', 'UDH', 'FCC', 'ADENOSIS', 'PASH', 'FIBROCYSTIC', 'SA', 'PAP'];

    /** Atypia: not carcinoma, but more than a benign lesion. */
    private const ATYPIA = ['ADH', 'ALH', 'FEA'];

    /**
     * The diagnosis recorded for the slide in the archive, as a class the
     * answer can be compared with: IDC, ILC, PB, Normal — or null when the
     * slide carries no label (an upload, a slide nobody has classified).
     */
    public function recordedClass(): ?string
    {
        return self::classOf($this->sample);
    }

    /** The recorded class of any archive slide (see recordedClass). */
    public static function classOf(?Sample $s): ?string
    {
        if (! $s) {
            return null;
        }
        $sub = strtolower((string) $s->diseaseSubtype?->name);
        $cat = strtolower((string) $s->category?->label_en);
        return match (true) {
            $sub === 'idc' => 'IDC',
            $sub === 'ilc' => 'ILC',
            str_contains($sub, 'benign') || $cat === 'benign' => 'PB',
            $cat === 'normal' => 'Normal',
            $sub !== '' => strtoupper($s->diseaseSubtype->name),
            default => null,
        };
    }

    /**
     * The answer against the recorded diagnosis: correct, partial or wrong,
     * with the reason — the same rules as the evaluation report
     * (reports/v2_eval_2026-09-28.csv). Null when there is nothing to compare:
     * the run has not finished or the slide has no recorded diagnosis.
     *
     * @return array{result: string, truth: string, reason: string}|null
     */
    public function verdict(): ?array
    {
        $truth = $this->recordedClass();
        if (! $truth || $this->status !== 'completed' || ! $this->diagnosis_code) {
            return null;
        }
        $code = strtoupper($this->diagnosis_code);
        [$result, $reason] = match ($truth) {
            'Normal' => match (true) {
                in_array($code, ['NORMAL', 'BENIGN'], true) => ['correct', 'no disease called on normal tissue'],
                $code === 'NONDX' => ['partial', 'no cancer called, but reported as non-diagnostic rather than normal'],
                $code === 'SUSP' => ['partial', 'no cancer called, but flagged as suspicious on normal tissue'],
                default => ['wrong', "{$code} called on normal tissue"],
            },
            'PB' => match (true) {
                in_array($code, self::BENIGN_LESION, true) => ['correct', 'benign lesion called'],
                $code === 'NORMAL' => ['partial', 'no cancer called, but the benign lesion was missed'],
                in_array($code, [...self::ATYPIA, 'SUSP'], true) => ['partial', "no cancer called, but overcalled as {$code}"],
                default => ['wrong', "{$code} called on a benign lesion"],
            },
            default => match (true) {
                $code === $truth || in_array($code, self::SAME[$truth] ?? [], true) => ['correct', 'right type'],
                $code === 'MIXED' && in_array($truth, ['IDC', 'ILC'], true) => ['partial', 'mixed ductal-lobular called'],
                in_array($code, self::NEAR[$truth] ?? [], true) => ['partial', "tumour found, typed as the related {$code}"],
                $code === 'SUSP' => ['partial', 'tumour suspected but not called'],
                in_array($code, self::NO_TUMOUR, true) => ['wrong', "the tumour was missed ({$code})"],
                default => ['wrong', "tumour found, but typed as {$code}"],
            },
        };
        return ['result' => $result, 'truth' => $truth, 'reason' => $reason];
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }

    /** Move to a stage and append it to the log, in one write. */
    public function stage(string $status, string $message): void
    {
        $events = $this->events ?? [];
        $events[] = ['at' => now()->toDateTimeString(), 'status' => $status, 'message' => $message];
        $this->update(['status' => $status, 'stage_message' => $message, 'events' => $events]);
    }

    /** The drawable result, or null before a run finishes. */
    public function finalResult(): ?array
    {
        $path = $this->run_dir ? $this->run_dir . '/final.json' : null;
        if (! $path || ! is_file($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true) ?: null;
    }

    /**
     * The small drawing file (view.json): coverage, checks and warnings for
     * the page, without reading the multi-megabyte final.json on every view.
     */
    public function viewData(): ?array
    {
        $path = $this->run_dir ? $this->run_dir . '/view.json' : null;
        if (! $path || ! is_file($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true) ?: null;
    }
}
