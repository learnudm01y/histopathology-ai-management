<?php

namespace App\Console\Commands;

use App\Jobs\RunV2Diagnosis;
use App\Models\Sample;
use App\Models\V2Diagnosis;
use App\Services\V2DiagnoseRunner;
use Illuminate\Console\Command;

/**
 * How V2 Diagnose scores against the archive, measured the way that can be
 * trusted: on slides no rule was written from.
 *
 *   php artisan v2:evaluate                          the report
 *   php artisan v2:evaluate --queue --per-class=5    run unseen slides, blind
 *
 * --queue picks slides that have never had a V2 run and are not tuning
 * samples, the same number from each recorded class, at random (--seed makes
 * the pick repeatable). They are run blind: organ and stain only, because age
 * and race exist for TCGA slides and not for BRACS ones, and their absence
 * alone tells the model which archive a slide came from.
 *
 * The report splits held-out slides from tuning samples
 * (config v2_diagnose.tuning_samples) and counts by prompt version, so a rule
 * change is judged only on runs made with it. A run voided by the integrity
 * check counts as void, never as an answer.
 */
class V2Evaluate extends Command
{
    protected $signature = 'v2:evaluate
        {--queue : queue unseen slides instead of reporting}
        {--per-class=5 : with --queue, how many slides from each class}
        {--classes=IDC,ILC,PB,Normal : with --queue, which recorded classes}
        {--organ=Breast : with --queue, the organ the slides come from}
        {--seed= : with --queue, makes the random pick repeatable}
        {--prompt= : report on this prompt version only (default: the current one)}
        {--all-versions : report on every run, whatever its prompt version}
        {--dry-run : with --queue, list the pick and stop}';

    protected $description = 'Score V2 Diagnose on unseen archive slides, or queue a blind batch of them';

    public function handle(V2DiagnoseRunner $runner): int
    {
        return $this->option('queue') ? $this->queueBatch($runner) : $this->report($runner);
    }

    private function queueBatch(V2DiagnoseRunner $runner): int
    {
        $classes = array_filter(array_map('trim', explode(',', (string) $this->option('classes'))));
        $per = max(1, (int) $this->option('per-class'));
        $seed = $this->option('seed') !== null ? (int) $this->option('seed') : random_int(1, PHP_INT_MAX);
        $tuning = (array) config('v2_diagnose.tuning_samples', []);

        $used = V2Diagnosis::whereNotNull('sample_id')->distinct()->pluck('sample_id')->all();
        $pool = Sample::with(['organ:id,name', 'stain:id,name', 'category:id,label_en', 'diseaseSubtype:id,name'])
            ->where('storage_status', 'available')->whereNotNull('wsi_remote_path')
            ->whereHas('organ', fn ($q) => $q->where('name', $this->option('organ')))
            ->whereNotIn('id', array_merge($used, $tuning))
            ->get()
            ->groupBy(fn ($s) => V2Diagnosis::classOf($s));

        $picked = collect();
        foreach ($classes as $class) {
            $have = $pool->get($class, collect());
            if ($have->count() < $per) {
                $this->warn("{$class}: only {$have->count()} unseen slides available");
            }
            // Ordered by a seeded hash of the id: the same seed picks the same slides.
            $picked = $picked->merge($have->sortBy(fn ($s) => hash('sha256', "{$seed}:{$s->id}"))->take($per));
        }

        $this->table(['sample', 'slide', 'class'], $picked->map(fn ($s) => [
            $s->id, $s->entity_submitter_id ?: $s->file_name, V2Diagnosis::classOf($s)])->all());
        $this->line("seed {$seed} · prompt version {$runner->promptVersion()}");
        if ($this->option('dry-run') || $picked->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($picked as $s) {
            $run = V2Diagnosis::create(['sample_id' => $s->id, 'organ' => $s->organ?->name,
                'stain' => $s->stain?->name, 'status' => 'queued']);
            $run->stage('queued', 'Queued — blind evaluation batch (seed ' . $seed . ').');
            RunV2Diagnosis::dispatch($run->id);
        }
        $this->info("{$picked->count()} runs queued. Report when they finish: php artisan v2:evaluate");
        return self::SUCCESS;
    }

    private function report(V2DiagnoseRunner $runner): int
    {
        $want = $this->option('all-versions') ? null : ($this->option('prompt') ?: $runner->promptVersion());
        $tuning = array_flip((array) config('v2_diagnose.tuning_samples', []));

        $rows = [];
        $tally = [];
        $runs = V2Diagnosis::with(['sample:id,entity_submitter_id,file_name,category_id,disease_subtype_id',
                'sample.category:id,label_en', 'sample.diseaseSubtype:id,name'])
            ->whereIn('status', ['completed', 'failed'])->whereNotNull('sample_id')->orderBy('id')->get();

        foreach ($runs as $r) {
            $truth = $r->recordedClass();
            if (! $truth) {
                continue;
            }
            $version = trim((string) @file_get_contents($runner->runDir($r) . '/prompt_version')) ?: '-';
            if ($want && $version !== $want) {
                continue;
            }
            $set = isset($tuning[$r->sample_id]) ? 'tuning' : 'held-out';
            $error = (string) $r->error;
            $result = match (true) {
                str_starts_with($error, 'Integrity check failed') => 'VOID',
                str_starts_with($error, 'Refused') => 'refused',
                $r->status === 'failed' => 'failed',
                default => $r->verdict()['result'] ?? '-',
            };
            $rows[] = [$r->id, $r->sample->entity_submitter_id ?: $r->sample->file_name, $truth,
                $r->diagnosis_code ?? '-', $r->confidence ?? '-', $result, $set, $version];
            $tally[$set][$truth][$result] = ($tally[$set][$truth][$result] ?? 0) + 1;
        }

        $this->table(['run', 'slide', 'truth', 'answer', 'conf', 'result', 'set', 'prompt'], $rows);
        $this->line('Prompt version: ' . ($want ?? 'all') . ($want === $runner->promptVersion() ? ' (current)' : ''));

        foreach (['held-out', 'tuning'] as $set) {
            if (empty($tally[$set])) {
                continue;
            }
            $sum = [];
            foreach ($tally[$set] as $class => $counts) {
                foreach ($counts as $k => $n) {
                    $sum[$k] = ($sum[$k] ?? 0) + $n;
                }
                $this->line(sprintf('  %-8s %-7s %s', $set, $class, $this->counts($counts)));
            }
            $scored = ($sum['correct'] ?? 0) + ($sum['partial'] ?? 0) + ($sum['wrong'] ?? 0);
            $this->line(sprintf('  %-8s %-7s %s — %s', $set, 'ALL', $this->counts($sum),
                $scored ? round(100 * ($sum['correct'] ?? 0) / $scored) . '% correct of ' . $scored . ' scored' : 'nothing scored'));
        }
        if (empty($tally['held-out'])) {
            $this->warn('No held-out runs for this prompt version: its accuracy is unmeasured. '
                . 'Queue some: php artisan v2:evaluate --queue --per-class=5');
        }
        return self::SUCCESS;
    }

    private function counts(array $c): string
    {
        return implode(', ', array_map(fn ($k) => "{$k} {$c[$k]}",
            array_values(array_filter(['correct', 'partial', 'wrong', 'VOID', 'failed', 'refused'], fn ($k) => isset($c[$k])))));
    }
}
