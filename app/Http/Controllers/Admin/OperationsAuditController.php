<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Operation;
use App\Models\ServerName;
use App\Services\OperationDispatcher;
use App\Services\OperationProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Review of what the Operations page has dispatched.
 *
 * The list names each run; opening one shows the slides it covered and the
 * patient case each slide belongs to. Nothing here dispatches or changes work —
 * it is a read surface over the record, whatever kind of operation produced it.
 */
class OperationsAuditController extends Controller
{
    /** Which stage each operation kind hands its finished slides on to. */
    private const NEXT_STAGE = [
        'patch_extraction'   => 'feature_extraction',
        'feature_extraction' => 'training',
    ];

    public function __construct(
        private readonly OperationProgress $progress,
        private readonly OperationDispatcher $dispatcher,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'type'   => $request->input('type'),
            'status' => $request->input('status'),
            'search' => $request->input('search'),
        ];

        // Running operations are brought up to date before they are listed, so
        // the page never shows a run as busy when its slides have all settled.
        $this->progress->syncMany(Operation::where('status', 'running')->get());

        $query = Operation::with('user:id,name')->latest('id');

        if ($filters['type']) {
            $query->where('type', $filters['type']);
        }
        if ($filters['status'] === 'running') {
            $query->whereNotIn('status', Operation::TERMINAL);
        } elseif ($filters['status']) {
            $query->where('status', $filters['status']);
        }
        if ($filters['search']) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        $operations = $query->paginate(20)->withQueryString();

        $stats = [
            'total'   => Operation::count(),
            'running' => Operation::whereNotIn('status', Operation::TERMINAL)->count(),
            'failed'  => Operation::whereIn('status', ['failed', 'completed_with_failures'])->count(),
            'slides'  => (int) Operation::sum('total_items'),
        ];

        return view('admin.operations.index', compact('operations', 'filters', 'stats'));
    }

    public function show(Request $request, Operation $operation): View
    {
        $this->progress->sync($operation);

        $itemStatus = $request->input('item_status');

        $items = $operation->items()
            ->with([
                'sample:id,file_name,organ_id,category_id,disease_subtype_id,case_id,tiling_status,feature_extraction_status,tile_count',
                'sample.organ:id,name',
                'sample.category:id,label_en',
                'sample.diseaseSubtype:id,name',
                'patientCase:id,case_id,submitter_id,disease_type',
            ])
            ->when($itemStatus, fn ($q) => $q->where('status', $itemStatus))
            ->orderByRaw("FIELD(status, 'failed', 'processing', 'pending', 'completed', 'skipped')")
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        // Which patients the run touched. A case with several slides in the run
        // is one case here — the honest answer to "whose tissue did this cover".
        $caseCount = $operation->items()->whereNotNull('case_id')->distinct()->count('case_id');

        $breakdown = $operation->items()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // ── Continuing the pipeline from here ────────────────────────────────
        // The slides this run FINISHED are the ones the next stage can take.
        // Failed and skipped slides are deliberately excluded: handing a stage
        // a slide whose patches never materialised only queues a job that must
        // fail, and writes a record claiming work that was never possible.
        $nextStage    = self::NEXT_STAGE[$operation->type] ?? null;
        $readyIds     = $operation->items()->where('status', 'completed')->pluck('sample_id')->filter()->values();
        $servers      = ServerName::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $aiModels     = AiModel::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']);
        $followUps    = $operation->children();
        $parent       = $operation->parent();

        return view('admin.operations.show', compact(
            'operation', 'items', 'caseCount', 'breakdown', 'itemStatus',
            'nextStage', 'readyIds', 'servers', 'aiModels', 'followUps', 'parent'
        ));
    }

    /**
     * Run the next stage over the slides this operation finished.
     *
     * The new run is a separate operation that records where it came from, so
     * the review chain stays readable: tiling → features → training, each with
     * its own record rather than one mutating row.
     */
    public function dispatchNext(Request $request, Operation $operation): RedirectResponse
    {
        $stage = self::NEXT_STAGE[$operation->type] ?? null;

        if ($stage !== 'feature_extraction') {
            return back()->with('error', 'This operation has no feature-extraction stage to run from here.');
        }

        $validated = $request->validate([
            'server_id'   => ['required', 'integer', 'exists:servers_names,id'],
            'ai_model_id' => ['required', 'integer', 'exists:ai_models,id'],
        ]);

        $sampleIds = $operation->items()
            ->where('status', 'completed')
            ->pluck('sample_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($sampleIds === []) {
            return back()->with('error', 'No slide in this operation has finished yet, so there is nothing to hand on.');
        }

        $result = $this->dispatcher->featureExtraction(
            $sampleIds,
            (int) $validated['server_id'],
            (int) $validated['ai_model_id'],
            $operation,
        );

        if (! $result['operation']) {
            return back()->with('error',
                "Nothing was queued: all {$result['skipped']} slide(s) were rejected because their patches are not available.");
        }

        $msg = "{$result['queued']} slide(s) queued for feature extraction as \"{$result['operation']->name}\".";
        if ($result['skipped'] > 0) {
            $msg .= " {$result['skipped']} skipped (patches not available).";
        }

        return redirect()
            ->route('admin.operations.audit.show', $result['operation'])
            ->with('success', $msg);
    }

    /**
     * Live figures for one operation, so its page can follow itself.
     *
     * Returns the per-item statuses as well as the totals: the point of
     * watching this page is seeing which slide moved, not only that the number
     * went up.
     */
    public function progress(Operation $operation): JsonResponse
    {
        $this->progress->sync($operation);
        $operation->refresh();

        return response()->json([
            'status'     => $operation->status,
            'statusText' => $operation->status_label,
            'colour'     => $operation->status_colour,
            'percent'    => $operation->progress_percent,
            'total'      => $operation->total_items,
            'completed'  => $operation->completed_items,
            'failed'     => $operation->failed_items,
            'running'    => $operation->is_running,
            'finishedAt' => $operation->finished_at?->format('Y-m-d H:i'),
            'readyForNext' => $operation->items()->where('status', 'completed')->count(),
            'items'      => $operation->items()
                ->get(['id', 'status'])
                ->mapWithKeys(fn ($item) => [$item->id => $item->status]),
        ]);
    }

    /**
     * Live figures for the dashboard's progress bars.
     *
     * Polled by the dashboard so a bar advances without a page reload; the sync
     * is the same one the audit list runs, so both surfaces agree.
     */
    public function active(): JsonResponse
    {
        $operations = Operation::whereNotIn('status', Operation::TERMINAL)
            ->latest('id')
            ->limit(10)
            ->get();

        $this->progress->syncMany($operations);

        return response()->json(
            $operations->fresh()->map(fn (Operation $op) => [
                'id'        => $op->id,
                'name'      => $op->name,
                'type'      => $op->type_label,
                'status'    => $op->status,
                'percent'   => $op->progress_percent,
                'total'     => $op->total_items,
                'completed' => $op->completed_items,
                'failed'    => $op->failed_items,
                'url'       => route('admin.operations.audit.show', $op),
            ])->values()
        );
    }
}
