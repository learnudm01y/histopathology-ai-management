<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SlidePrediction;
use App\Services\DiagnosisWorkflow;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every score the deployed models have produced, kept and countable.
 *
 * A validation run is not a thing you watch go by — it is a thing you come back
 * to, months later, when someone asks how a number was arrived at. So the page
 * is built around the questions that actually get asked of a run: how many were
 * answered, how many refused, and of the answered ones how many were right.
 *
 * Accuracy is reported only over rows that were both answered and have a
 * recorded truth. Counting refusals as errors would punish the model for the
 * one behaviour worth having, and counting them as successes would hide it.
 */
class AiResultsController extends Controller
{
    public function index(Request $request)
    {
        $q = SlidePrediction::with(['sample:id,entity_submitter_id,file_name'])
            ->orderByDesc('id');

        foreach (['model_key', 'decision', 'ood_status', 'site', 'truth'] as $f) {
            if ($v = $request->get($f)) {
                $q->where($f, $v);
            }
        }
        if ($request->get('only') === 'answered') {
            $q->where('decision', '!=', 'DO NOT USE');
        } elseif ($request->get('only') === 'withheld') {
            $q->where('decision', 'DO NOT USE');
        } elseif ($request->get('only') === 'wrong') {
            $q->whereNotNull('truth')->whereColumn('truth', '!=', 'call');
        }

        $rows = $q->paginate(50)->withQueryString();
        $summary = $this->summarise($request);

        return view('admin.ai-results', [
            'rows'    => $rows,
            'summary' => $summary,
            'filters' => $request->only(['model_key', 'decision', 'ood_status', 'site', 'truth', 'only']),
            'models'  => SlidePrediction::distinct()->pluck('model_key')->filter()->values(),
            'sites'   => SlidePrediction::distinct()->orderBy('site')->pluck('site')->filter()->values(),
        ]);
    }

    /**
     * One score, read back in full.
     *
     * This exists because the workflow page could not do it. That page shows a
     * result it has just produced — a session flash, or a seven-day cache entry
     * — so a link to a run from last month arrived at an intake form with no
     * answer anywhere on it. The stored row is the thing that is meant to still
     * be answerable months later, so this report is built from the row and
     * nothing else: open it today or next year and it reads the same.
     *
     * What the page has to settle in one look is not the probability. It is
     * which of the three gates the slide failed, if it failed one — inputs,
     * familiarity, confidence — because that is the difference between a result
     * you can use, one to hand to a pathologist, and one to throw away.
     */
    public function show(SlidePrediction $prediction, DiagnosisWorkflow $workflow)
    {
        $prediction->load(['sample.patientCase:id,submitter_id', 'sample.diseaseSubtype:id,name']);

        $sample  = $prediction->sample;
        $payload = $prediction->payload ?: [];
        $model   = config("diagnosis_models.models.{$prediction->model_key}");

        // Evidence is built on request and kept on disk, so it may or may not
        // exist for any given slide. Absent is a normal state, not an error.
        $evidence = null;
        if ($sample) {
            $file = $workflow->evidenceDir($sample) . '/evidence.json';
            $evidence = is_file($file)
                ? json_decode((string) file_get_contents($file), true)
                : null;
        }

        // Every other time this slide has been scored. A slide re-run after a
        // pipeline fix has two rows that disagree, and dropping the older one
        // would make the disagreement invisible rather than resolved.
        $history = SlidePrediction::where('sample_id', $prediction->sample_id)
            ->where('id', '!=', $prediction->id)
            ->orderByDesc('id')->limit(8)->get();

        return view('admin.ai-report', [
            'p'        => $prediction,
            'payload'  => $payload,
            'sample'   => $sample,
            'model'    => $model,
            'evidence' => $evidence,
            'history'  => $history,
            'gates'    => $this->gates($prediction, $payload, $model),
        ]);
    }

    /**
     * The three gates a slide passes, each with the number it was judged on and
     * the line it was judged against.
     *
     * Carrying the threshold next to the value is the whole point. A bare
     * "familiarity 40.3" means nothing; "40.3, against a refusal line at 38.7"
     * can be argued with, and being able to argue with it is exactly what the
     * reader of a refused result needs.
     */
    private function gates(SlidePrediction $p, array $payload, ?array $model): array
    {
        $problems = $payload['input_problems'] ?? [];

        $refuseAt = $payload['ood_refuse_at'] ?? ($model['familiarity']['refuse'] ?? null);
        $low      = $model['referral']['low']  ?? null;
        $high     = $model['referral']['high'] ?? null;
        $typical  = $model['familiarity']['typical'] ?? null;
        $answers  = $model['performance']['accuracy_when_answering'] ?? 95;
        $withheld = $p->wasWithheld();

        return [
            [
                'key'   => 'inputs',
                'label' => 'The slide is what the model was built for',
                'short' => 'the slide is not what this model reads',
                'state' => $problems ? 'fail' : 'pass',
                'value' => $problems
                    ? implode('; ', $problems)
                    : ($model['requires']['feature_model'] ?? 'TITAN') . ' features, '
                      . ($model['requires']['patch_px'] ?? 224) . 'px at '
                      . ($model['requires']['magnification'] ?? '20x') . ', '
                      . number_format((int) $p->patches) . ' patches',
                'note'  => $problems
                    ? 'Features from another encoder, patch size or magnification are not comparable, '
                      . 'and scoring them anyway returns a number that looks exactly like a valid one.'
                    : 'Encoder, patch size, magnification and patch count all match what it was trained on.',
            ],
            [
                'key'   => 'familiarity',
                'label' => 'The model has seen slides like this before',
                'short' => 'this slide is too unfamiliar for the model to score',
                'state' => $p->ood_status === 'refuse' ? 'fail' : ($p->ood_status === 'warn' ? 'warn' : 'pass'),
                'value' => $p->familiarity !== null
                    ? number_format($p->familiarity, 1) . ($refuseAt ? ' · refuses above ' . $refuseAt : '')
                    : 'not measured',
                'note'  => match ($p->ood_status) {
                    'refuse' => 'Further from the training set than any slide this model has been shown to '
                              . 'handle. It can only choose between IDC and ILC and has no way to answer '
                              . 'neither, so on a slide this unfamiliar it would be forced to pick one.',
                    'warn'   => 'This slide only loosely resembles the training set. The probability is '
                              . 'still worth reading, but with less weight than its calibration suggests.',
                    default  => 'Comfortably inside the range the model was calibrated on'
                              . ($typical ? ' — known slides sit around ' . $typical . '.' : '.'),
                },
            ],
            [
                'key'   => 'confidence',
                'label' => 'It is sure enough to commit',
                'short' => 'it will not commit at this probability',
                'state' => $p->referred ? 'warn' : 'pass',
                'value' => $p->p_ilc !== null
                    ? 'p(ILC) = ' . number_format($p->p_ilc, 2)
                      . ($low !== null ? ' · will not commit between ' . $low . ' and ' . $high : '')
                    : 'no probability recorded',
                // The accuracy figure belongs to answers that were delivered, so
                // it is not quoted beside one that was withheld for another
                // reason — a true number in the wrong place still misleads.
                'note'  => $p->referred
                    ? 'Inside the band this model was tuned to stay out of. The band was set so the '
                      . "answers it does give are right about {$answers}% of the time; the slides in "
                      . 'here are what buying that costs.'
                    : ($withheld
                        ? 'Outside the referral band — though that counts for nothing while a gate '
                          . 'above is failing.'
                        : "Outside the referral band, where this model is right about {$answers}% of the time."),
            ],
        ];
    }

    /**
     * The numbers a run is judged on.
     *
     * Deliberately computed over the same filter the table shows, so what is
     * counted is always what is on screen — a summary that describes a
     * different set than the rows beneath it is worse than none.
     */
    private function summarise(Request $request): array
    {
        $base = SlidePrediction::query();
        foreach (['model_key', 'site', 'truth'] as $f) {
            if ($v = $request->get($f)) {
                $base->where($f, $v);
            }
        }

        $total    = (clone $base)->count();
        $withheld = (clone $base)->where('decision', 'DO NOT USE')->count();
        $answered = $total - $withheld;

        $scored = (clone $base)->where('decision', '!=', 'DO NOT USE')->whereNotNull('truth');
        $judged = (clone $scored)->count();
        $right  = (clone $scored)->whereColumn('truth', 'call')->count();

        // The raw lean over everything with a truth, guards ignored. Useful only
        // for asking what a model without guards would have done.
        $allTruth = (clone $base)->whereNotNull('truth');
        $allJudged = (clone $allTruth)->count();
        $allRight  = (clone $allTruth)->whereColumn('truth', 'call')->count();

        return [
            'total' => $total,
            'withheld' => $withheld,
            'answered' => $answered,
            'withheld_pct' => $total ? round($withheld / $total * 100) : null,
            'judged' => $judged,
            'right' => $right,
            'accuracy' => $judged ? round($right / $judged * 100, 1) : null,
            'raw_judged' => $allJudged,
            'raw_right' => $allRight,
            'raw_accuracy' => $allJudged ? round($allRight / $allJudged * 100, 1) : null,
        ];
    }

    /** The same rows as a CSV, because a run has to leave the building. */
    public function export(Request $request): StreamedResponse
    {
        $name = 'predictions-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'scored_at', 'sample_id', 'slide', 'site', 'truth',
                           'call', 'p_ilc', 'decision', 'ood_status', 'familiarity',
                           'referred', 'patches', 'model_key']);

            SlidePrediction::with('sample:id,entity_submitter_id')
                ->when($request->get('model_key'), fn ($q, $v) => $q->where('model_key', $v))
                ->orderBy('id')
                ->chunk(500, function ($chunk) use ($out) {
                    foreach ($chunk as $r) {
                        fputcsv($out, [
                            $r->id, $r->created_at, $r->sample_id,
                            $r->sample?->entity_submitter_id, $r->site, $r->truth,
                            $r->call, $r->p_ilc, $r->decision, $r->ood_status,
                            $r->familiarity, $r->referred ? 1 : 0, $r->patches, $r->model_key,
                        ]);
                    }
                });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
