<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeleteOperationArtifactsJob;
use App\Models\AiModel;
use App\Models\Operation;
use App\Models\ServerName;
use App\Services\OperationCanceller;
use App\Services\OperationDispatcher;
use App\Services\OperationProgress;
use App\Services\RunPodService;
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
        private readonly OperationCanceller $canceller,
        private readonly \App\Services\PodAllocator $allocator,
    ) {
    }

    /**
     * Stop a running operation.
     *
     * Nothing that already finished is undone — a slide that was tiled stays
     * tiled and keeps its patches. Stopping only prevents the work that has
     * not happened yet.
     */
    public function cancel(Operation $operation): RedirectResponse
    {
        // Sync first: the run may have finished in the seconds between the page
        // being rendered and the button being pressed, and cancelling a
        // finished operation would rewrite a completed record as "cancelled".
        $this->progress->sync($operation);

        if (! $operation->refresh()->is_running) {
            return back()->with('error', "\"{$operation->name}\" has already finished — there is nothing left to stop.");
        }

        $result = $this->canceller->cancel($operation);

        return back()->with('success', sprintf(
            'Stopped "%s": %d slide(s) cancelled, %d queued job(s) removed. %s',
            $operation->name,
            $result['cancelled_items'],
            $result['dequeued_jobs'],
            $result['slides_reset'] > 0
                ? "{$result['slides_reset']} slide(s) returned to pending; any job still mid-run will stop at its next checkpoint."
                : 'Slides that already finished keep their output.'
        ));
    }

    /**
     * Delete an operation, and optionally the files it produced.
     *
     * Two different actions behind one button, because they answer two
     * different questions: "stop showing me this record" and "throw away the
     * patches this run made". The second is queued — purging dozens of Drive
     * folders takes minutes — and never touches the whole-slide images.
     */
    public function destroy(Request $request, Operation $operation): RedirectResponse
    {
        $validated = $request->validate([
            'delete_files' => ['nullable', 'boolean'],
            'confirm'      => ['required', 'in:DELETE'],
        ], [
            'confirm.required' => 'Type DELETE to confirm.',
            'confirm.in'       => 'Type DELETE exactly to confirm.',
        ]);

        if ($operation->is_running) {
            return back()->with('error',
                "\"{$operation->name}\" is still running. Stop it first, then delete it.");
        }

        $name = $operation->name;

        if ($request->boolean('delete_files')) {
            // The record is deleted by the job, after the files it points at
            // are gone — deleting it here first would leave the job with no
            // list of what to purge.
            DeleteOperationArtifactsJob::dispatch($operation->id, true);

            return redirect()
                ->route('admin.operations.audit.index')
                ->with('success', "Deleting \"{$name}\" and the files it produced. The slides themselves are untouched; this runs in the background and the record disappears when it finishes.");
        }

        $operation->delete();

        return redirect()
            ->route('admin.operations.audit.index')
            ->with('success', "Deleted the record for \"{$name}\". The files it produced were kept.");
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
            $search = trim($filters['search']);

            // A reference is OP-<date>-<id>, and the id is the part that finds
            // the row. Someone quoting a reference should land on it, whether
            // they paste the whole thing or just the number.
            $referenceId = preg_match('/^OP-\d{8}-(\d+)$/i', $search, $m)
                ? (int) $m[1]
                : (ctype_digit($search) ? (int) $search : null);

            $query->where(function ($q) use ($search, $referenceId) {
                $q->where('name', 'like', '%' . $search . '%');
                if ($referenceId !== null) {
                    $q->orWhere('id', $referenceId);
                }
            });
        }

        $operations = $query->paginate(20)->withQueryString();

        // One query for the page, so "1 failed" can be told apart from
        // "1 failed, since tiled by a retry".
        Operation::loadResolution($operations->getCollection());

        $withFailures = Operation::whereIn('status', ['failed', 'completed_with_failures'])->get();
        Operation::loadResolution($withFailures);

        $stats = [
            'total'   => Operation::count(),
            'running' => Operation::whereNotIn('status', Operation::TERMINAL)->count(),
            // Only runs with something still outstanding — a failure a later run
            // has made good is not a problem anyone needs to look at.
            'failed'  => $withFailures->filter(fn ($o) => $o->unresolved_failures > 0)->count(),
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
        // What the next stage will ACTUALLY take: a completed item whose patches
        // have since gone is skipped by the dispatcher, so counting it here
        // would promise 50 slides and queue 27 with no explanation.
        $nextStage = self::NEXT_STAGE[$operation->type] ?? null;
        $readyIds  = $operation->items()
            ->join('samples as s', 's.id', '=', 'operation_items.sample_id')
            ->where('operation_items.status', 'completed')
            ->when($operation->type === 'patch_extraction',
                fn ($q) => $q->whereNotNull('s.tiles_gdrive_path'))
            ->pluck('operation_items.sample_id')
            ->filter()
            ->values();
        $servers      = ServerName::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $aiModels     = AiModel::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']);
        $followUps    = $operation->children();
        $parent       = $operation->parent();

        // Slides that did not finish, plus any whose output has gone missing.
        $retryableCount = $operation->sampleIdsNeedingWork()->count();
        $missingOutput  = $operation->missingOutputCount();

        // Slides of the source run this follow-up never took on, and how many
        // of them could join right now.
        $awaitingFromParent = $parent && $operation->type === 'feature_extraction'
            ? $this->missingFromParent($operation, $parent)
            : collect();

        // Slides that have not reported back, which resuming would ask about.
        $stalledCount = $operation->type === 'feature_extraction'
            ? $operation->items()->whereNotIn('status', ['completed', 'skipped'])->count()
            : 0;

        $addableNow = $awaitingFromParent->isEmpty() ? 0 : \App\Models\Sample::whereIn('id', $awaitingFromParent)
            ->where('tiling_status', 'done')
            ->whereNotNull('tiles_gdrive_path')
            ->count();

        // A slide this run failed may have been finished by a later run. This
        // record stays truthful about what IT did, but a reader looking at a
        // failure needs to know whether the slide was ever recovered — without
        // it, work that has since been done reads as work still missing.
        $unfinished     = $items->getCollection()
            ->whereIn('status', ['failed', 'cancelled'])
            ->pluck('sample_id')->filter();

        $rescuedBy = $unfinished->isEmpty()
            ? collect()
            : \App\Models\OperationItem::with('operation')
                ->whereIn('sample_id', $unfinished)
                ->where('operation_id', '>', $operation->id)
                ->where('status', 'completed')
                // Descending, so keyBy leaves the LOWEST id in place: the first
                // run that recovered the slide, not the most recent one to touch it.
                ->orderByDesc('operation_id')
                ->get(['id', 'sample_id', 'operation_id'])
                ->keyBy('sample_id')
                ->map(fn ($item) => $item->operation);

        return view('admin.operations.show', compact(
            'operation', 'items', 'caseCount', 'breakdown', 'itemStatus',
            'nextStage', 'readyIds', 'servers', 'aiModels', 'followUps', 'parent',
            'retryableCount', 'rescuedBy', 'missingOutput', 'awaitingFromParent', 'addableNow',
            'stalledCount'
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
            'pod_id'      => ['nullable', 'string', 'max:64'],
        ]);

        // Choosing the pod HERE, rather than after the run exists, is what lets
        // two runs work at the same time on different cards: the run is bound
        // before its first job leaves, so no slide is ever sent to whichever pod
        // the shared server field happened to point at.
        $pod = null;

        if (! empty($validated['pod_id'])) {
            try {
                $pod = $this->resolvePod((int) $validated['server_id'], $validated['pod_id']);
            } catch (\Throwable $e) {
                return back()->with('error', $e->getMessage());
            }
        }

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
            $pod,
        );

        if (! $result['operation']) {
            return back()->with('error',
                "Nothing was queued: all {$result['skipped']} slide(s) were rejected because their patches are not available.");
        }

        $msg = "{$result['queued']} slide(s) queued for feature extraction as \"{$result['operation']->name}\".";
        if ($pod) {
            $msg .= " Running on pod {$pod['name']}" . ($pod['gpu'] ? " ({$pod['gpu']})" : "") . ".";
        }
        if ($result['skipped'] > 0) {
            $msg .= " {$result['skipped']} skipped (patches not available).";
        }

        return redirect()
            ->route('admin.operations.audit.show', $result['operation'])
            ->with('success', $msg);
    }

    /**
     * Re-run the slides this operation failed on, inside this operation.
     *
     * The slides stay in the group they were dispatched with, and that group
     * records how they finally turned out. Each item counts its attempts, so
     * the failed try is still on record after the retry succeeds.
     */
    public function retryFailed(Operation $operation): RedirectResponse
    {
        if ($operation->type !== 'patch_extraction') {
            return back()->with('error', 'Only a tiling operation can be retried from here.');
        }

        if (empty($operation->params['server_id']) || empty($operation->params['patch_size_id'])
            || empty($operation->params['magnification_id'])) {
            return back()->with('error',
                'This operation did not record the settings it ran with, so it cannot be repeated automatically. Re-dispatch it from the Operations page.');
        }

        // Covers both the slides that never finished and the ones whose patches
        // have since gone missing — the record is only true again once both
        // kinds are back.
        $sampleIds = $operation->sampleIdsNeedingWork()->all();

        if ($sampleIds === []) {
            return back()->with('error', 'Every slide in this operation is finished and its patches are in place — there is nothing to re-run.');
        }

        $result = $this->dispatcher->retryWithin($operation, $sampleIds);

        if ($result['queued'] === 0) {
            return back()->with('error', 'None of those slides still exist, so there was nothing to retry.');
        }

        $message = "Re-queued {$result['queued']} slide(s) inside {$operation->reference}. "
                 . 'They stay in this operation, and it will report how they finish.';

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} slide(s) no longer exist and were left out.";
        }

        return redirect()
            ->route('admin.operations.audit.show', $operation)
            ->with('success', $message);
    }

    /**
     * Restart the slides of this run that the GPU worker has forgotten.
     *
     * Stays in this operation: the slides keep their place, their attempt count
     * goes up, and no second record is opened. Slides the worker is still
     * working on are left alone, so pressing this twice costs nothing.
     */
    public function resume(Operation $operation): RedirectResponse
    {
        if ($operation->type !== 'feature_extraction') {
            return back()->with('error', 'Only a feature-extraction run can be resumed from here.');
        }

        $result = $this->dispatcher->resumeStalled($operation);

        if ($result['unreachable']) {
            return back()->with('error',
                'The GPU worker did not answer, so nothing was re-queued — a silent worker is not proof the work was lost, and guessing wrong would run every slide twice. Check the pod is running and try again.');
        }

        if ($result['requeued'] === 0) {
            return back()->with('success', $result['still_running'] > 0
                ? "Nothing to resume: the worker still has all {$result['still_running']} unfinished slide(s) in its queue."
                : 'Nothing to resume: every slide in this run has finished.');
        }

        $message = "Re-queued {$result['requeued']} slide(s) inside {$operation->reference}.";
        if ($result['still_running'] > 0) {
            $message .= " {$result['still_running']} were left alone because the worker is still on them.";
        }

        return back()->with('success', $message);
    }

    /**
     * Take on the slides of the source run that this one never covered.
     *
     * A feature run started from a tiling operation covers whatever was ready
     * at that moment. The rest belong to the same piece of work, so once they
     * are ready they join this run instead of starting a second one over the
     * same intent.
     */
    public function addMissing(Operation $operation): RedirectResponse
    {
        $parent = $operation->parent();

        if ($operation->type !== 'feature_extraction' || ! $parent) {
            return back()->with('error', 'This operation was not started from another run, so there is nothing to add to it.');
        }

        $missing = $this->missingFromParent($operation, $parent);

        if ($missing->isEmpty()) {
            return back()->with('error', "Every slide of {$parent->reference} is already in this run.");
        }

        $result = $this->dispatcher->addToFeatureRun($operation, $missing->all());

        if ($result['queued'] === 0) {
            return back()->with('error',
                "None of the {$missing->count()} remaining slide(s) have patches yet, so they cannot be added. Re-run them in {$parent->reference} first.");
        }

        $message = "Added {$result['queued']} slide(s) to {$operation->reference} — they are queued for feature extraction in this run.";

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} still have no patches and were left out.";
        }

        return back()->with('success', $message);
    }

    /**
     * Slides the source run completed that this follow-up never took on.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function missingFromParent(Operation $operation, Operation $parent): \Illuminate\Support\Collection
    {
        $mine = $operation->items()->pluck('sample_id')->filter();

        return $parent->items()
            ->where('status', 'completed')
            ->pluck('sample_id')
            ->filter()
            ->diff($mine)
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /**
     * The pods this operation could run on, with what each actually costs.
     *
     * Prices come from RunPod, unmodified. Where the spot rate equals the
     * on-demand rate — which is what this account currently returns — that is
     * reported as "no spot saving" rather than dressed up as a discount.
     */
    public function pods(Operation $operation, Request $request): JsonResponse
    {
        // The continue-to-next-stage form picks the server for the run it is about
        // to start, which is usually NOT the server this operation itself ran on.
        // When it names one, list that server's pods rather than this one's.
        $requested = $request->integer("server_id");
        $server    = $requested ? ServerName::find($requested) : $this->podServerFor($operation);

        if ($server && ! $server->runpod_api_key) {
            $server = null;
        }

        if (! $server) {
            return response()->json(['error' => 'This operation has no RunPod server configured.'], 422);
        }

        $runpod = new RunPodService($server->runpod_api_key);

        try {
            $pods     = $runpod->listPods();
            $gpuTypes = $runpod->listGpuTypes();
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not reach RunPod: ' . $e->getMessage()], 502);
        }

        $port      = $server->runpod_port ?: 8000;
        $boundPod  = $operation->params['pod_id'] ?? null;
        $allocator = $this->allocator;

        return response()->json([
            'server'    => ['id' => $server->id, 'name' => $server->name, 'port' => $port],
            'bound_pod' => $boundPod,
            'pods'      => collect($pods)->map(fn (array $p) => [
                'id'       => $p['id'] ?? null,
                'name'     => $p['name'] ?? $p['id'] ?? '?',
                'gpu'      => $p['machine']['gpuDisplayName'] ?? null,
                'status'   => $p['desiredStatus'] ?? 'UNKNOWN',
                'running'  => ($p['desiredStatus'] ?? null) === 'RUNNING',
                'cost'     => isset($p['costPerHr']) ? (float) $p['costPerHr'] : null,
                'endpoint' => $runpod->proxyUrlFor($p, $port),
                'bound'    => ($p['id'] ?? null) === $boundPod,
                'estimate' => $allocator->estimate($operation, isset($p['costPerHr']) ? (float) $p['costPerHr'] : null, $server->id),
            ])->values(),
            // The catalogue is advisory: it says what a card would cost if one
            // were created, so a choice is made on price rather than on habit.
            'gpu_types' => collect($gpuTypes)->take(20)->map(fn (array $t) => $t + [
                'spot_saves' => ($t['spot'] !== null && $t['on_demand'] !== null && $t['spot'] < $t['on_demand']),
            ])->values(),
        ]);
    }

    /**
     * Run this operation on the chosen pod.
     *
     * Each operation holds its own pod, which is what lets two of them run at
     * once — the work is processed remotely, so one pod per operation is the
     * only thing that actually adds throughput.
     */
    public function assignPod(Request $request, Operation $operation): RedirectResponse
    {
        $validated = $request->validate(['pod_id' => ['required', 'string']]);

        $server = $this->podServerFor($operation);

        if (! $server) {
            return back()->with('error', 'This operation has no RunPod server configured.');
        }

        $runpod = new RunPodService($server->runpod_api_key);

        try {
            $pod = $runpod->getPod($validated['pod_id']);

            if (! $pod) {
                return back()->with('error', 'That pod no longer exists on RunPod.');
            }

            // Starting costs money from the moment it runs, so it is only done
            // because the operator picked this pod for this operation.
            if (($pod['desiredStatus'] ?? null) !== 'RUNNING') {
                $runpod->startPod($pod['id']);

                return back()->with('success', sprintf(
                    'Starting pod "%s" (%s, $%s/hr). It takes a minute to boot — select it again once it is running to send this operation to it.',
                    $pod['name'] ?? $pod['id'], $pod['machine']['gpuDisplayName'] ?? 'GPU', $pod['costPerHr'] ?? '?'
                ));
            }

            $result = $this->allocator->assign($operation, $server, $pod, $runpod);
        } catch (\Throwable $e) {
            return back()->with('error', 'RunPod: ' . $e->getMessage());
        }

        return back()->with('success', sprintf(
            '%s is now running on pod "%s" (%s, $%s/hr). %d slide(s) sent to it; work already accepted by another pod was left with it.',
            $operation->reference,
            $pod['name'] ?? $pod['id'],
            $pod['machine']['gpuDisplayName'] ?? 'GPU',
            $pod['costPerHr'] ?? '?',
            $result['queued']
        ));
    }

    /** The server whose RunPod credentials this operation runs under. */

    /**
     * Turn a pod id into the binding a run needs: its name, card and endpoint.
     *
     * Resolved BEFORE the run is created, so a pod that is stopped, deleted or
     * on another account is refused up front — rather than after fifty jobs have
     * been queued against an endpoint that can never answer.
     *
     * @return array{id:string,name:string,gpu:?string,cost:?float,endpoint:string}
     */
    private function resolvePod(int $serverId, string $podId): array
    {
        $server = ServerName::find($serverId);

        if (! $server || ! $server->runpod_api_key) {
            throw new \RuntimeException('That server has no RunPod API key, so a pod cannot be chosen for it.');
        }

        $runpod = new RunPodService($server->runpod_api_key);
        $pod    = collect($runpod->listPods())->firstWhere('id', $podId);

        if (! $pod) {
            throw new \RuntimeException('That pod no longer exists on RunPod.');
        }

        $endpoint = $runpod->proxyUrlFor($pod, $server->runpod_port ?: 8000);

        if ($endpoint === null) {
            throw new \RuntimeException('Pod "' . ($pod['name'] ?? $podId) . '" is not running, so it has no endpoint yet. Start it first.');
        }

        return [
            'id'       => $podId,
            'name'     => $pod['name'] ?? $podId,
            'gpu'      => $pod['machine']['gpuDisplayName'] ?? null,
            'cost'     => isset($pod['costPerHr']) ? (float) $pod['costPerHr'] : null,
            'endpoint' => $endpoint,
        ];
    }

    private function podServerFor(Operation $operation): ?ServerName
    {
        $serverId = $operation->params['server_id'] ?? null;

        $server = $serverId ? ServerName::find($serverId) : null;

        return $server && $server->runpod_api_key ? $server : null;
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
