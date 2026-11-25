<?php

namespace App\Services;

use App\Models\Sample;
use App\Models\V2Diagnosis;

/**
 * What the model wrote about a slide, checked field by field against what
 * the pathologist wrote about the case: histological type, grade (Gleason
 * for prostate), carcinoma in situ, and lymphovascular invasion.
 *
 * The reference is resources/reference/v2_written_reports.json, built by
 * scripts/v2_report_reference.py from the TCGA pathology reports and the GDC
 * record. It is read here only, after a run, and never reaches the model.
 *
 * The report describes the whole case and the run sees one slide, so a
 * feature the report found that the slide does not show (DCIS in another
 * block, say) is "not on this slide", not an error. Writing a feature the
 * report says is absent is an error. A grade or type nobody wrote is "not
 * stated", which is a gap in the written answer.
 */
class V2WrittenCheck
{
    public const FILE = 'reference/v2_written_reports.json';

    /** Results, in the order the page lists them. */
    public const RESULTS = [
        'agree'      => 'agrees',
        'near'       => 'close',
        'off_by_one' => 'one step off',
        'disagree'   => 'disagrees',
        'not_stated' => 'not stated',
        'not_seen'   => 'not on this slide',
    ];

    private const NO_TUMOUR = ['NORMAL', 'BENIGN', 'NONDX', 'FA', 'SA', 'PAP', 'UDH', 'FCC', 'ADH', 'ALH', 'FEA', 'SUSP', 'PHY'];

    private ?array $reference = null;

    public function __construct(private readonly ?string $path = null) {}

    /** The reference for one patient (TCGA-XX-XXXX), or null. */
    public function reference(?string $patient): ?array
    {
        if (! $patient) {
            return null;
        }
        if ($this->reference === null) {
            $file = $this->path ?? resource_path(self::FILE);
            $this->reference = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        }
        return $this->reference[$patient] ?? null;
    }

    public static function patientOf(V2Diagnosis $run): ?string
    {
        return self::patientOfSample($run->sample);
    }

    /**
     * Whether the case's report describes this slide's tissue. A TCGA slide
     * from a normal-tissue block (sample type 10-19, "-11A") holds the
     * patient's normal tissue, while the report describes the tumour.
     */
    public static function isTumourSlide(?Sample $s): bool
    {
        $id = (string) ($s?->entity_submitter_id ?: $s?->file_name);
        return ! preg_match('/^TCGA-[A-Z0-9]{2}-[A-Z0-9]{4}-(\d{2})/i', $id, $m) || (int) $m[1] < 10;
    }

    /** The TCGA patient barcode of a slide (TCGA-XX-XXXX), or null for any other source. */
    public static function patientOfSample(?Sample $s): ?string
    {
        $id = (string) ($s?->patientCase?->submitter_id ?: $s?->entity_submitter_id);
        return preg_match('/^(TCGA-[A-Z0-9]{2}-[A-Z0-9]{4})/i', $id, $m) ? strtoupper($m[1]) : null;
    }

    /**
     * The check for a finished run, or null when there is nothing to check
     * it against.
     *
     * @return array{patient: string, project: ?string, checks: list<array>}|null
     */
    public function forRun(V2Diagnosis $run): ?array
    {
        $patient = self::patientOf($run);
        $ref = $this->reference($patient);
        if (! $ref || $run->status !== 'completed' || ! $run->diagnosis_code || ! self::isTumourSlide($run->sample)) {
            return null;
        }
        // The structured fields, where the run wrote them — from the small
        // view.json, never the multi-megabyte final.json.
        $written = $run->viewData()['report'] ?? null;
        return ['patient' => $patient, 'project' => $ref['project'] ?? null,
                'checks' => $this->compare($this->read((string) $run->diagnosis_code, (string) $run->diagnosis,
                    (string) $run->summary, is_array($written) ? $written : null, $ref['project'] ?? ''), $ref)];
    }

    // ── What the answer says ─────────────────────────────────────────────

    /**
     * The fields of a written answer. Structured fields, when the run wrote
     * them, win over what is read from the free text.
     */
    public function read(string $code, string $diagnosis, string $summary, ?array $structured, string $project): array
    {
        $code = strtoupper(trim($code));
        $text = trim($diagnosis . "\n" . $summary);
        $organ = $this->organOf($project);
        $ai = ['code' => $code, 'tumour' => ! in_array($code, self::NO_TUMOUR, true),
               'type' => $this->typeOf($code, $text, $organ), 'type_text' => $this->firstLine($summary) ?: $diagnosis];

        if ($organ === 'prostate') {
            if (preg_match('/gleason(?:\s+score)?[^0-9]{0,12}([345])\s*\+\s*([345])/i', $text, $m)) {
                $ai['gleason'] = ['primary' => (int) $m[1], 'secondary' => (int) $m[2], 'text' => $this->around($text, $m[0])];
                $ai['grade'] = ['value' => [self::gradeGroup((int) $m[1], (int) $m[2])], 'text' => $this->around($text, $m[0])];
            } elseif (preg_match('/grade\s+group\s*([1-5])/i', $text, $m)) {
                $ai['grade'] = ['value' => [(int) $m[1]], 'text' => $this->around($text, $m[0])];
            }
        } elseif ($organ === 'brain') {
            if (preg_match('/(?:WHO|CNS)[^.;]{0,12}grade\s*(IV|III|II|[234])\b|grade\s*(IV|III|II|[234])\b/i', $text, $m)) {
                $g = self::roman($m[1] ?: $m[2]);
                $ai['grade'] = ['value' => [$g], 'text' => $this->around($text, $m[0])];
            } elseif ($code === 'GBM') {
                $ai['grade'] = ['value' => [4], 'text' => 'GBM (grade 4 by definition)'];
            }
        } else {
            if ($g = $this->histologicGrade($text)) {
                $ai['grade'] = $g;
            }
        }
        if ($organ === 'breast') {
            $ai['in_situ'] = $this->presence($text, '/\bDCIS\b|\bLCIS\b|in[\s-]situ|intraductal carcinoma|lobular neoplasia/i');
        }
        $ai['lvi'] = $this->presence($text, '/\bLVI\b|lympho-?vascular|angio-?lymphatic|(?:blood\s+)?vascular invasion|lymphatic invasion|tumou?r emboli|vascular space invasion/i');

        foreach (['grade', 'in_situ', 'lvi'] as $k) {
            if (isset($structured[$k]) && $structured[$k] !== '' && $structured[$k] !== null) {
                $basis = $k === 'grade' ? ($structured['grade_basis'] ?? null) : null;
                $ai[$k] = $this->fromStructured($k, $structured[$k], $basis) ?? ($ai[$k] ?? null);
            }
        }
        if (isset($structured['gleason']) && preg_match('/([345])\s*\+\s*([345])/', (string) $structured['gleason'], $m)) {
            $ai['gleason'] = ['primary' => (int) $m[1], 'secondary' => (int) $m[2], 'text' => (string) $structured['gleason']];
            $ai['grade'] = ['value' => [self::gradeGroup((int) $m[1], (int) $m[2])], 'text' => (string) $structured['gleason']];
        }
        return $ai;
    }

    private function fromStructured(string $k, mixed $v, ?string $text): ?array
    {
        if ($k === 'grade') {
            if (! preg_match('/^\s*([1-5])(?:\s*[-–]\s*([1-5]))?\s*$/', (string) $v, $m)) {
                return null;                                  // "not applicable", "not assessable"
            }
            $vals = array_values(array_unique(array_filter([(int) $m[1], isset($m[2]) ? (int) $m[2] : null])));
            return ['value' => $vals, 'text' => $text ? "grade {$v}: {$text}" : "grade {$v}"];
        }
        $v = strtolower(trim((string) $v));
        $state = match (true) {
            str_starts_with($v, 'present') => 'present',
            str_starts_with($v, 'possible') => 'possible',
            str_starts_with($v, 'absent') => 'absent',
            default => null,                                  // "not assessable": nothing said
        };
        return $state ? ['value' => $state, 'text' => $text ?: $v] : null;
    }

    private function organOf(string $project): string
    {
        return match ($project) {
            'TCGA-BRCA' => 'breast',
            'TCGA-PRAD' => 'prostate',
            'TCGA-GBM', 'TCGA-LGG' => 'brain',
            'TCGA-LUAD', 'TCGA-LUSC' => 'lung',
            default => 'other',
        };
    }

    private function typeOf(string $code, string $text, string $organ): ?string
    {
        if ($organ === 'brain' && ! in_array($code, ['GBM', 'MEN', 'MET'], true)) {
            // The lineage the answer names first is the one it favours; a later
            // one is usually "… not excluded".
            foreach (preg_split('/\n/', $text) as $line) {
                if (preg_match('/oligoastrocytoma|mixed glioma|(oligodendroglioma|oligodendroglial)|(astrocytoma|astrocytic)/i', $line, $m)) {
                    return ! empty($m[2]) ? 'ASTRO' : (! empty($m[1]) ? 'OLIGO' : 'OA');
                }
            }
            return $code;
        }
        if ($organ === 'prostate' && in_array($code, ['PRAD', 'ACINAR', 'PCA'], true)) {
            return 'PRAD';
        }
        return $code ?: null;
    }

    /** Breast or lung: Nottingham grade, IASLC grade, or differentiation; a range keeps both ends. */
    private function histologicGrade(string $text): ?array
    {
        // A nuclear grade, or the grade of an in-situ lesion, is not the tumour's grade.
        $t = preg_replace('/nuclear\s+grade\s*[1-3I]+(?:\s*[–-]\s*[1-3])?|grade\s*[1-3]\s+nucle\w*'
            . '|(?:high|intermediate|low)[\s-]grade\s+(?:DCIS|ductal carcinoma in situ|in[\s-]situ|comedo\S*\s+DCIS|PIN|dysplasia)/i', ' ', $text);
        // "grade 2–3" and "Nottingham 2-3" are ranges; "grade 2/3" is grade 2 of three.
        if (preg_match('/(?:grade|nottingham)\s*(?:grade\s*)?([1-3]|III|II|I)\b(?:\s*(?:–|-|to|or)\s*([1-3])\b)?(?!\s*(?:\/|of)\s*4)/i', $t, $m)) {
            $vals = array_values(array_unique(array_filter([self::roman($m[1]), isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null])));
            sort($vals);
            return ['value' => $vals, 'text' => $this->around($text, $m[0])];
        }
        foreach (['/\b(?:well[\s-]+to[\s-]+moderately|moderately[\s-]+to[\s-]+well)[\s-]+differentiated/i' => [1, 2],
                  '/\b(?:moderately[\s-]+to[\s-]+poorly|poorly[\s-]+to[\s-]+moderately)[\s-]+differentiated/i' => [2, 3],
                  '/\bwell[\s-]+differentiated/i' => [1], '/\bmoderately[\s-]+differentiated/i' => [2], '/\bpoorly[\s-]+differentiated/i' => [3]] as $re => $vals) {
            if (preg_match($re, $t, $m)) {
                return ['value' => $vals, 'text' => $this->around($text, $m[0])];
            }
        }
        // "High grade" only where the answer describes this tumour, not in the
        // caveat line, where "high-grade IDC" is usually a differential.
        $own = implode("\n", array_filter(preg_split('/\n/', $t), fn ($l) => ! preg_match('/^\s*(caveats?|ruled out|differential|pathologist)\b/i', $l)));
        foreach (['/\bhigh[\s-]grade\b/i' => [3], '/\bintermediate[\s-]grade\b/i' => [2], '/\blow[\s-]grade\b/i' => [1]] as $re => $vals) {
            if (preg_match($re, $own, $m)) {
                return ['value' => $vals, 'text' => $this->around($text, $m[0])];
            }
        }
        return null;
    }

    /**
     * present / possible / absent for one feature, from the clause it is
     * named in: "no DCIS, LVI or necrosis" negates all three; "may be DCIS"
     * is possible.
     */
    private function presence(string $text, string $subject): ?array
    {
        $found = [];
        foreach (preg_split('/(?<=[.;])\s+|\n/', $text) as $clause) {
            if (! preg_match_all($subject, $clause, $ms, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($ms[0] as [$word, $at]) {
                $before = substr($clause, max(0, $at - 60), min(60, $at));
                $before = preg_split('/[:]|\bwith\b|\bbut\b|\bplus\b|\bwhile\b/i', $before);
                $before = end($before);
                $after = substr($clause, $at + strlen($word), 50);
                $negated = (preg_match('/\b(?:no|not|without|nor|ruled out|excluded|absent|negative for|none)\b/i', $before)
                        && ! preg_match('/\bnot\s+(?:excluded|ruled out)\b/i', $before))
                    || preg_match('/^\W{0,3}(?:not\s+(?:seen|identified|present)|absent|ruled out|excluded\b)/i', $after);
                $possible = preg_match('/\b(?:may|might|could|possible|possibly|probable|probably|suspicious|suspected|favou?r\w*|equivocal|cannot be excluded|query|questionable)\b|\?/i', $before . ' ' . substr($after, 0, 30))
                    || preg_match('/^\W{0,3}(?:not\s+excluded|cannot be excluded)/i', $after);
                // Something still to be done about it ("check for LVI", "LVI needs
                // a pathologist") says nothing about whether it is there.
                $toDo = preg_match('/^[\s\w,\/-]{0,25}?\b(?:need|needs|needed|requires?|to be (?:assessed|excluded|checked|confirmed)|not assessable|cannot be assessed|should be)\b/i', $after)
                    || preg_match('/\b(?:assess|check|look for|stain for|to exclude|confirm)\b[^.;]{0,20}$/i', $before);
                $state = match (true) {
                    (bool) $negated => 'absent',
                    (bool) $possible => 'possible',
                    (bool) $toDo => null,
                    default => 'present',
                };
                if ($state === null) {
                    continue;
                }
                $found[$state] ??= trim($clause);
            }
        }
        foreach (['present', 'possible', 'absent'] as $s) {
            if (isset($found[$s])) {
                return ['value' => $s, 'text' => self::cut($found[$s])];
            }
        }
        return null;
    }

    // ── Against the report ───────────────────────────────────────────────

    /** @return list<array{field: string, label: string, report: ?string, report_text: ?string, written: ?string, written_text: ?string, result: string, note: ?string}> */
    public function compare(array $ai, array $ref): array
    {
        $organ = $this->organOf($ref['project'] ?? '');
        $checks = [];
        if (isset($ref['type'])) {
            [$result, $note] = $this->typeResult($ai, (string) $ref['type']['value'], $organ);
            $checks[] = $this->row('type', 'Histological type', (string) $ref['type']['value'], $ref['type']['text'] ?? null,
                $ai['type'] ?? $ai['code'], $ai['type_text'] ?? null, $result, $note);
        }
        if (! $ai['tumour']) {
            return $checks;          // no tumour called: grade and the rest have nothing to be read from
        }
        if (isset($ref['grade'])) {
            $system = $ref['grade']['system'] ?? 'grade';
            $want = (array) $ref['grade']['value'];
            $got = $ai['grade']['value'] ?? null;
            [$result, $note] = $this->gradeResult($want, $got);
            if ($organ === 'prostate' && isset($ref['gleason'], $ai['gleason'])) {
                [$result, $note] = $this->gleasonResult($ref['gleason'], $ai['gleason'], $result, $note);
            }
            $label = $organ === 'prostate' ? 'Gleason / Grade Group' : "Grade ({$system})";
            $checks[] = $this->row('grade', $label,
                $organ === 'prostate' && isset($ref['gleason']) ? "{$ref['gleason']['primary']}+{$ref['gleason']['secondary']}={$ref['gleason']['score']}, GG {$ref['gleason']['group']}" : self::list($want),
                $ref['grade']['text'] ?? null,
                $organ === 'prostate' && isset($ai['gleason']) ? "{$ai['gleason']['primary']}+{$ai['gleason']['secondary']}, GG " . self::gradeGroup($ai['gleason']['primary'], $ai['gleason']['secondary'])
                    : ($got ? self::list($got) : null),
                $ai['grade']['text'] ?? null, $result, $note);
        }
        foreach (['in_situ' => 'Carcinoma in situ', 'lvi' => 'Lymphovascular invasion'] as $k => $label) {
            if (! isset($ref[$k])) {
                continue;
            }
            [$result, $note] = $this->presenceResult($ref[$k]['value'], $ai[$k]['value'] ?? null);
            $checks[] = $this->row($k, $label, $ref[$k]['value'], $ref[$k]['text'] ?? null,
                $ai[$k]['value'] ?? null, $ai[$k]['text'] ?? null, $result, $note);
        }
        return $checks;
    }

    private function typeResult(array $ai, string $want, string $organ): array
    {
        $got = (string) ($ai['type'] ?? $ai['code']);
        if (! $ai['tumour']) {
            return ['disagree', "no tumour called ({$ai['code']})"];
        }
        if ($got === $want) {
            return ['agree', null];
        }
        return match ($organ) {
            'breast' => match (true) {
                in_array('MIXED', [$got, $want], true) && array_intersect([$got, $want], ['IDC', 'ILC']) => ['near', 'mixed ductal-lobular on one side'],
                $got === 'IDC' && in_array($want, ['MBC', 'CLEAR', 'MUC', 'MPC', 'PAP', 'MED', 'TUB'], true) => ['near', 'carcinoma found, special type not named'],
                default => ['disagree', null],
            },
            'brain' => match (true) {
                in_array($want, ['ASTRO', 'GBM'], true) && in_array($got, ['ASTRO', 'GBM'], true) => ['near', 'astrocytic glioma, grade differs'],
                $want === 'OA' && in_array($got, ['ASTRO', 'OLIGO', 'LGG'], true) => ['near', 'one component of a mixed glioma'],
                $got === 'LGG' => ['near', 'lower-grade glioma, lineage not named'],
                default => ['disagree', null],
            },
            'lung' => in_array($got, ['NSCLC', 'ADSQ'], true) ? ['near', 'non-small cell carcinoma, type not settled'] : ['disagree', null],
            default => ['disagree', null],
        };
    }

    private function gradeResult(array $want, ?array $got): array
    {
        if (! $got) {
            return ['not_stated', 'the written answer gives no grade'];
        }
        if (array_values($want) == array_values($got)) {
            return ['agree', null];
        }
        if (array_intersect($want, $got)) {
            return ['near', 'the ranges overlap'];
        }
        $gap = min(array_map(fn ($a) => min(array_map(fn ($b) => abs($a - $b), $got)), $want));
        return $gap === 1 ? ['off_by_one', max($got) > max($want) ? 'graded one step higher' : 'graded one step lower']
                          : ['disagree', max($got) > max($want) ? 'graded higher' : 'graded lower'];
    }

    private function gleasonResult(array $want, array $got, string $result, ?string $note): array
    {
        if ($want['primary'] === $got['primary'] && $want['secondary'] === $got['secondary']) {
            return ['agree', null];
        }
        if ($want['primary'] + $want['secondary'] === $got['primary'] + $got['secondary']) {
            return ['near', 'same score, patterns in the other order'];
        }
        return [$result, $note];
    }

    private function presenceResult(string $want, ?string $got): array
    {
        return match (true) {
            $got === null => ['not_stated', null],
            $want === 'present' && $got === 'present' => ['agree', null],
            $want === 'present' && $got === 'possible' => ['near', 'written as possible'],
            $want === 'present' && $got === 'absent' => ['not_seen', 'in the report, not seen on this slide'],
            $want === 'absent' && $got === 'absent' => ['agree', null],
            $want === 'absent' && $got === 'possible' => ['near', 'written as possible; the report has none'],
            default => ['disagree', 'written as present; the report has none'],
        };
    }

    private function row(string $field, string $label, ?string $report, ?string $reportText, ?string $written,
                         ?string $writtenText, string $result, ?string $note): array
    {
        return ['field' => $field, 'label' => $label, 'report' => $report, 'report_text' => $reportText,
                'written' => $written, 'written_text' => $writtenText ? self::cut($writtenText) : null,
                'result' => $result, 'note' => $note];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public static function gradeGroup(int $primary, int $secondary): int
    {
        $s = $primary + $secondary;
        return match (true) {
            $s <= 6 => 1,
            $s === 7 => $primary === 3 ? 2 : 3,
            $s === 8 => 4,
            default => 5,
        };
    }

    private static function roman(string $g): int
    {
        return match (strtoupper(trim($g))) { 'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, default => (int) $g };
    }

    private static function list(array $v): string
    {
        return implode('–', array_map('strval', $v));
    }

    private function firstLine(string $text): string
    {
        return trim(strtok(trim($text), "\n") ?: '');
    }

    private function around(string $text, string $hit): string
    {
        foreach (preg_split('/(?<=[.;])\s+|\n/', $text) as $c) {
            if (str_contains($c, $hit)) {
                return self::cut(trim($c));
            }
        }
        return $hit;
    }

    private static function cut(string $s, int $n = 220): string
    {
        return mb_strlen($s) <= $n ? $s : rtrim(mb_substr($s, 0, $n)) . '…';
    }
}
