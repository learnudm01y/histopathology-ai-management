<?php

namespace App\Console\Commands;

use App\Models\Sample;
use App\Models\SlidePrediction;
use App\Services\DiagnosisWorkflow;
use Illuminate\Console\Command;

/**
 * Score every slide that is ready, and keep the result.
 *
 *   php artisan ai:score-slides --model=idc_ilc_titan_v1 --dry-run
 *   php artisan ai:score-slides --source=TCGA-BRCA --site=AN
 *
 * Deliberately a command rather than eighty-four AdvanceWorkflow chains. The
 * chain exists to walk one slide through transfer and extraction while someone
 * watches a page; for a batch where every slide already has its features, that
 * is eighty-four supervisors taking turns on a single worker to do something
 * that takes a second each.
 *
 * Re-running is the normal case. A slide already scored by this model is
 * skipped unless --rescore is given, so the command is the unit of retry after
 * an interruption and nothing is double-counted.
 */
class ScoreSlides extends Command
{
    protected $signature = 'ai:score-slides
        {--model= : key in config/diagnosis_models; defaults to the configured default}
        {--site= : only slides whose barcode carries this TSS code, e.g. AN}
        {--source= : only slides from this data source name}
        {--limit=0 : stop after this many}
        {--rescore : score slides that already have a result from this model}
        {--dry-run : list what would be scored and stop}';

    protected $description = 'Run a registered model over every ready slide and record the results';

    public function handle(DiagnosisWorkflow $workflow): int
    {
        $key = $this->option('model') ?: config('diagnosis_models.default');
        $model = config("diagnosis_models.models.{$key}");

        if (! $model) {
            $this->error("No model registered under '{$key}'.");
            return self::FAILURE;
        }

        $q = Sample::with(['diseaseSubtype:id,name'])
            ->where('feature_extraction_status', 'completed')
            ->whereNotNull('features_gdrive_path');

        if ($site = $this->option('site')) {
            $q->where('entity_submitter_id', 'like', "TCGA-{$site}-%");
        }
        if ($source = $this->option('source')) {
            $q->whereHas('dataSource', fn ($s) => $s->where('name', $source));
        }
        if (! $this->option('rescore')) {
            $q->whereNotIn('id', SlidePrediction::where('model_key', $key)->pluck('sample_id'));
        }

        $q->orderBy('id');
        if ($limit = (int) $this->option('limit')) {
            $q->limit($limit);
        }

        $samples = $q->get();
        $this->info("{$samples->count()} slide(s) to score with '{$key}'.");

        if ($this->option('dry-run')) {
            foreach ($samples as $s) {
                $this->line(sprintf('  #%-6d %-28s truth=%s',
                    $s->id, $s->entity_submitter_id ?: $s->file_name,
                    $s->diseaseSubtype?->name ?: '—'));
            }
            return self::SUCCESS;
        }

        $tally = ['scored' => 0, 'withheld' => 0, 'failed' => 0];
        $bar = $this->output->createProgressBar($samples->count());
        $bar->start();

        foreach ($samples as $sample) {
            // The stage check is not redundant with the query above: a model
            // states its own requirements — encoder, patch size, magnification —
            // and a slide with features from the wrong one would otherwise be
            // scored into a number that looks exactly like a valid one.
            $state = $workflow->inspect($sample, $model);
            if ($state['stage'] !== DiagnosisWorkflow::STAGE_READY) {
                $tally['failed']++;
                $this->newLine();
                $this->warn("  #{$sample->id} skipped: " . ($state['blocking'] ?: 'not ready'));
                $bar->advance();
                continue;
            }

            $result = $workflow->predict($sample, $model);
            if (isset($result['error'])) {
                $tally['failed']++;
                $this->newLine();
                $this->warn("  #{$sample->id} failed: {$result['error']}");
                $bar->advance();
                continue;
            }

            $result['model_key'] = $key;
            $result['model_label'] = $model['label'] ?? $key;
            SlidePrediction::record($sample, $result, $key);

            $tally['scored']++;
            if (($result['decision'] ?? '') === 'DO NOT USE') {
                $tally['withheld']++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('scored %d · withheld %d · failed %d',
            $tally['scored'], $tally['withheld'], $tally['failed']));
        $this->line('Read them under AI - Results.');

        return self::SUCCESS;
    }
}
