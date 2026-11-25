<?php

namespace App\Console\Commands;

use App\Models\V2Diagnosis;
use App\Services\V2WrittenCheck;
use Illuminate\Console\Command;

/**
 * How well the written answers agree with the pathologists' reports, field by
 * field — type, grade (Gleason for prostate), carcinoma in situ, lymphovascular
 * invasion — per prompt version, held-out slides apart from tuning slides.
 *
 *   php artisan v2:written-check                 every finished run with a report
 *   php artisan v2:written-check --runs=158,159  those runs, row by row
 *   php artisan v2:written-check --csv=storage/app/v2_written_check.csv
 *
 * The comparison itself is V2WrittenCheck; the reference is built by
 * scripts/v2_report_reference.py.
 */
class V2WrittenCheckCommand extends Command
{
    protected $signature = 'v2:written-check
        {--runs= : comma-separated run ids; lists every check of each}
        {--prompt= : only runs made with this prompt version}
        {--csv= : also write every check to this CSV file}';

    protected $description = 'Check the written V2 answers against the pathology reports, field by field';

    public function handle(V2WrittenCheck $check): int
    {
        $tuning = array_flip(array_map('intval', (array) config('v2_diagnose.tuning_samples', [])));
        $ids = array_filter(array_map('intval', explode(',', (string) $this->option('runs'))));
        $runs = V2Diagnosis::with('sample.patientCase:id,submitter_id')
            ->where('status', 'completed')->whereNotNull('sample_id')
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->get();

        $rows = [];
        foreach ($runs as $run) {
            $res = $check->forRun($run);
            if (! $res) {
                continue;
            }
            $version = $this->promptVersion($run);
            if ($this->option('prompt') && $version !== $this->option('prompt')) {
                continue;
            }
            foreach ($res['checks'] as $c) {
                $rows[] = $c + ['run' => $run->id, 'patient' => $res['patient'], 'project' => $res['project'],
                                'prompt' => $version ?? '—', 'set' => isset($tuning[$run->sample_id]) ? 'tuning' : 'held-out'];
            }
        }
        if (! $rows) {
            $this->warn('No finished run has a written report to check against.');
            return self::SUCCESS;
        }

        if ($ids) {
            $this->table(['run', 'field', 'report', 'written', 'result', 'note'], array_map(fn ($r) => [
                $r['run'], $r['label'], $r['report'], $r['written'] ?? '—',
                V2WrittenCheck::RESULTS[$r['result']], $r['note'] ?? ''], $rows));
        }

        $keys = array_keys(V2WrittenCheck::RESULTS);
        $groups = [];
        foreach ($rows as $r) {
            $g = &$groups["{$r['prompt']} · {$r['set']} · {$r['field']}"];
            $g ??= array_fill_keys($keys, 0) + ['n' => 0];
            $g[$r['result']]++;
            $g['n']++;
            unset($g);
        }
        ksort($groups);
        $this->table(array_merge(['prompt · slides · field', 'n'], array_values(V2WrittenCheck::RESULTS), ['agree %']),
            array_map(fn ($k, $g) => array_merge([$k, $g['n']], array_map(fn ($x) => $g[$x], $keys),
                [$this->agreeShare($g)]), array_keys($groups), $groups));
        $this->line('agree % = agrees out of the checks where the answer stated the field and the slide could show it '
            . '("not stated" and "not on this slide" left out). "close": overlapping ranges, or written as possible.');

        if ($path = $this->option('csv')) {
            $f = fopen($path, 'w');
            fputcsv($f, ['run', 'patient', 'project', 'prompt', 'set', 'field', 'report', 'written', 'result', 'note', 'report_text', 'written_text']);
            foreach ($rows as $r) {
                fputcsv($f, [$r['run'], $r['patient'], $r['project'], $r['prompt'], $r['set'], $r['field'], $r['report'],
                    $r['written'], $r['result'], $r['note'], $r['report_text'], $r['written_text']]);
            }
            fclose($f);
            $this->info("Written to {$path}");
        }
        return self::SUCCESS;
    }

    private function agreeShare(array $g): string
    {
        $judged = $g['n'] - $g['not_stated'] - $g['not_seen'];
        return $judged ? round($g['agree'] / $judged * 100) . '%' : '—';
    }

    private function promptVersion(V2Diagnosis $run): ?string
    {
        $f = $run->run_dir ? $run->run_dir . '/prompt_version' : null;
        return $f && is_file($f) ? trim((string) file_get_contents($f)) : null;
    }
}
