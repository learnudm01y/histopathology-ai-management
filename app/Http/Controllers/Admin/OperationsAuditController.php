<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Operation;
use App\Services\OperationProgress;
use Illuminate\Http\JsonResponse;
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
    public function __construct(private readonly OperationProgress $progress)
    {
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

        return view('admin.operations.show', compact(
            'operation', 'items', 'caseCount', 'breakdown', 'itemStatus'
        ));
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
