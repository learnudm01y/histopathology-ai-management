<?php

namespace Tests\Unit;

use App\Models\Sample;
use App\Services\V2WrittenCheck;
use Tests\TestCase;

/**
 * The written answer read field by field and set against a pathology report.
 * The wording comes from real answers and reports.
 */
class V2WrittenCheckTest extends TestCase
{
    private V2WrittenCheck $check;

    protected function setUp(): void
    {
        parent::setUp();
        $this->check = new V2WrittenCheck('/nonexistent.json');
    }

    private function results(string $project, array $ref, string $code, string $summary, ?array $structured = null): array
    {
        $ai = $this->check->read($code, $code . ' — x', $summary, $structured, $project);
        $out = [];
        foreach ($this->check->compare($ai, ['project' => $project] + $ref) as $c) {
            $out[$c['field']] = [$c['result'], $c['written']];
        }
        return $out;
    }

    public function test_breast_grade_range_and_a_list_negation(): void
    {
        $r = $this->results('TCGA-BRCA', [
            'type' => ['value' => 'IDC'], 'grade' => ['value' => 2, 'system' => 'Nottingham'],
            'in_situ' => ['value' => 'present'], 'lvi' => ['value' => 'absent'],
        ], 'IDC', "IDC — invasive carcinoma NST, probably Nottingham grade 2–3; confidence 0.8.\n"
            . "Caveats: no DCIS, necrosis or LVI identified with certainty.");

        $this->assertSame(['agree', 'IDC'], $r['type']);
        $this->assertSame(['near', '2–3'], $r['grade'], 'a range that holds the report grade is close');
        $this->assertSame(['not_seen', 'absent'], $r['in_situ'], 'DCIS elsewhere in the case is not an error');
        $this->assertSame(['agree', 'absent'], $r['lvi'], '"no DCIS, necrosis or LVI" negates all three');
    }

    public function test_possible_and_to_be_checked_are_not_present(): void
    {
        $r = $this->results('TCGA-BRCA', ['grade' => ['value' => 3], 'in_situ' => ['value' => 'absent'], 'lvi' => ['value' => 'absent']],
            'IDC', "IDC — invasive carcinoma NST, high grade.\nThe rounded nests with comedonecrosis could be high-grade DCIS. Also check for LVI.");

        $this->assertSame(['agree', '3'], $r['grade'], '"high grade" reads as 3; "high-grade DCIS" is not the tumour grade');
        $this->assertSame(['near', 'possible'], $r['in_situ']);
        $this->assertSame(['not_stated', null], $r['lvi'], '"check for LVI" says nothing about it');
    }

    public function test_a_differential_in_the_caveat_is_not_the_grade(): void
    {
        $r = $this->results('TCGA-BRCA', ['grade' => ['value' => 2]], 'ILC',
            "ILC — invasive lobular carcinoma.\nPleomorphic nuclei, no tubules: about Nottingham 2-3.\n"
            . "Caveat: E-cadherin is needed to confirm lobular type against high-grade IDC with discohesion.");
        $this->assertSame(['near', '2–3'], $r['grade']);
    }

    public function test_prostate_gleason(): void
    {
        $ref = ['type' => ['value' => 'PRAD'], 'grade' => ['value' => 3, 'system' => 'Grade Group'],
                'gleason' => ['primary' => 4, 'secondary' => 3, 'score' => 7, 'group' => 3]];
        $this->assertSame('near', $this->results('TCGA-PRAD', $ref, 'PRAD', 'PRAD — acinar adenocarcinoma, Gleason score 3+4=7 (Grade Group 2).')['grade'][0],
            'the same score with the patterns the other way round');
        $this->assertSame('agree', $this->results('TCGA-PRAD', $ref, 'PRAD', 'PRAD — Gleason 4+3=7, Grade Group 3.')['grade'][0]);
        $this->assertSame(['disagree', '4+5, GG 5'], $this->results('TCGA-PRAD', $ref, 'PRAD', 'PRAD — Gleason score 4+5=9.')['grade']);
    }

    public function test_glioma_lineage_is_the_one_named_first(): void
    {
        $r = $this->results('TCGA-LGG', ['type' => ['value' => 'ASTRO'], 'grade' => ['value' => 3, 'system' => 'CNS WHO']],
            'LGG', "LGG — diffuse glioma, favour astrocytoma (IDH-mutant type), CNS WHO grade 2; oligodendroglioma not excluded.");
        $this->assertSame(['agree', 'ASTRO'], $r['type']);
        $this->assertSame(['off_by_one', '2'], $r['grade']);
    }

    public function test_lung_differentiation_ranges(): void
    {
        $r = $this->results('TCGA-LUSC', ['grade' => ['value' => 3]], 'LUSC',
            'LUSC — squamous cell carcinoma, keratin pearls not identified (poorly to moderately differentiated).');
        $this->assertSame(['near', '2–3'], $r['grade']);
    }

    public function test_the_structured_report_wins_over_the_text(): void
    {
        $r = $this->results('TCGA-BRCA', ['grade' => ['value' => 3], 'lvi' => ['value' => 'present']], 'IDC',
            'IDC — invasive carcinoma NST, Nottingham grade 2.',
            ['grade' => '3', 'grade_basis' => 'tubules 3, nuclei 3, mitoses 2', 'in_situ' => 'absent', 'lvi' => 'present']);
        $this->assertSame(['agree', '3'], $r['grade']);
        $this->assertSame(['agree', 'present'], $r['lvi']);
    }

    public function test_no_tumour_called_is_a_type_disagreement_and_nothing_else(): void
    {
        $r = $this->results('TCGA-BRCA', ['type' => ['value' => 'IDC'], 'grade' => ['value' => 2], 'lvi' => ['value' => 'present']],
            'BENIGN', 'BENIGN — fibrous stroma, no carcinoma identified.');
        $this->assertSame(['type'], array_keys($r));
        $this->assertSame('disagree', $r['type'][0]);
    }

    public function test_only_tumour_blocks_are_compared(): void
    {
        $this->assertFalse(V2WrittenCheck::isTumourSlide((new Sample)->forceFill(['entity_submitter_id' => 'TCGA-E9-A1RF-11A-03-TSC'])),
            'a normal-tissue block: the report describes the tumour, not this tissue');
        $this->assertTrue(V2WrittenCheck::isTumourSlide((new Sample)->forceFill(['entity_submitter_id' => 'TCGA-UU-A93S-01Z-00-DX1'])));
        $this->assertSame('TCGA-UU-A93S', V2WrittenCheck::patientOfSample((new Sample)->forceFill(['entity_submitter_id' => 'TCGA-UU-A93S-01Z-00-DX1'])));
        $this->assertNull(V2WrittenCheck::patientOfSample((new Sample)->forceFill(['entity_submitter_id' => 'BRACS_1408'])));
    }
}
