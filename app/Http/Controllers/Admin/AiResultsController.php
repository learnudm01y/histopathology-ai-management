<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SlidePrediction;
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
