@extends('admin.layouts.app')

@section('title', 'Operation — ' . $operation->name)

@section('content')
@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="mdi mdi-check-circle-outline mr-1"></i> {{ session('success') }}
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="mdi mdi-alert-circle-outline mr-1"></i> {{ session('error') }}
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
@endif

<div class="page-header">
    <h3 class="page-title">Operation Review</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.operations.audit.index') }}">Operations Audit</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $operation->reference }}</li>
        </ol>
    </nav>
</div>

{{-- ── Header ────────────────────────────────────────────────────────────── --}}
<div class="row grid-margin">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap" style="gap:1rem;">
                    <div>
                        {{-- The reference leads: it is what gets quoted elsewhere. --}}
                        <div class="mb-1">
                            <code style="font-size:.95rem;">{{ $operation->reference }}</code>
                        </div>
                        <h4 class="mb-1">{{ $operation->name }}</h4>
                        <span class="badge badge-light border mr-1">{{ $operation->type_label }}</span>
                        <span class="badge badge-{{ $operation->status_colour }}">{{ $operation->status_label }}</span>
                        <div class="text-muted small mt-2">
                            Started {{ $operation->started_at?->format('Y-m-d H:i') ?? $operation->created_at->format('Y-m-d H:i') }}
                            @if($operation->finished_at)
                                · finished {{ $operation->finished_at->format('Y-m-d H:i') }}
                                ({{ $operation->started_at?->diffForHumans($operation->finished_at, ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }})
                            @endif
                            @if($operation->user) · by {{ $operation->user->name }} @endif
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="h2 mb-0 text-{{ $operation->status_colour }}" id="op-percent">{{ $operation->progress_percent }}%</div>
                        <small class="text-muted">
                            <span id="op-completed">{{ $operation->completed_items }}</span> /
                            <span id="op-total">{{ $operation->total_items }}</span> slides
                        </small>
                        <div class="mt-2">
                            @if($operation->is_running)
                                <form method="POST" action="{{ route('admin.operations.audit.cancel', $operation) }}"
                                      class="d-inline"
                                      onsubmit="return confirm('Stop “{{ $operation->name }}”?\n\nSlides that already finished keep their output. Only the work that has not run yet is cancelled.');">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-warning">
                                        <i class="mdi mdi-stop"></i> Stop
                                    </button>
                                </form>
                            @else
                                <button type="button" class="btn btn-sm btn-outline-danger op-delete-btn"
                                        data-op-id="{{ $operation->id }}"
                                        data-op-name="{{ $operation->name }}"
                                        data-op-type="{{ $operation->type }}"
                                        data-op-slides="{{ $operation->completed_items }}"
                                        data-op-url="{{ route('admin.operations.audit.destroy', $operation) }}">
                                    <i class="mdi mdi-delete-outline"></i> Delete
                                </button>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="progress mt-3" style="height:8px;">
                    <div class="progress-bar bg-{{ $operation->status_colour }} {{ $operation->is_running ? 'progress-bar-striped progress-bar-animated' : '' }}"
                         id="op-bar" role="progressbar" style="width: {{ $operation->progress_percent }}%"></div>
                </div>

                <div class="row mt-4">
                    <div class="col-md-3 col-6 mb-2">
                        <p class="text-muted mb-0 small">Slides</p>
                        <h5 class="mb-0">{{ number_format($operation->total_items) }}</h5>
                    </div>
                    <div class="col-md-3 col-6 mb-2">
                        {{-- One patient with three slides in the run is one case. --}}
                        <p class="text-muted mb-0 small">Patient cases</p>
                        <h5 class="mb-0">{{ number_format($caseCount) }}</h5>
                    </div>
                    <div class="col-md-3 col-6 mb-2">
                        <p class="text-muted mb-0 small">Completed</p>
                        <h5 class="mb-0 text-success" id="op-completed-stat">{{ number_format($operation->completed_items) }}</h5>
                    </div>
                    <div class="col-md-3 col-6 mb-2">
                        @if($operation->is_fully_resolved)
                            {{-- Nothing outstanding: every slide this run failed was tiled later. --}}
                            <p class="text-muted mb-0 small">Failed here, resolved later</p>
                            <h5 class="mb-0 text-success">
                                {{ number_format($operation->failed_items) }}
                                <i class="mdi mdi-check-circle-outline"></i>
                            </h5>
                        @else
                            <p class="text-muted mb-0 small">Failed{{ $operation->failed_items > $operation->unresolved_failures ? ' (still outstanding)' : '' }}</p>
                            <h5 class="mb-0 text-danger" id="op-failed-stat">{{ number_format($operation->unresolved_failures) }}</h5>
                            @if($operation->failed_items > $operation->unresolved_failures)
                                <small class="text-success">
                                    +{{ $operation->failed_items - $operation->unresolved_failures }} resolved later
                                </small>
                            @endif
                        @endif
                    </div>
                </div>

                {{-- Where this run sits in the chain. --}}
                @if($parent || $followUps->isNotEmpty())
                    <hr>
                    <div class="d-flex flex-wrap align-items-center" style="gap:.5rem; font-size:.85rem;">
                        @if($parent)
                            <span class="text-muted">Continued from</span>
                            <a href="{{ route('admin.operations.audit.show', $parent) }}" class="badge badge-light border">
                                <i class="mdi mdi-arrow-left mr-1"></i>{{ $parent->name }}
                            </a>
                        @endif
                        @foreach($followUps as $child)
                            <span class="text-muted">Handed on to</span>
                            <a href="{{ route('admin.operations.audit.show', $child) }}" class="badge badge-{{ $child->status_colour }}">
                                {{ $child->name }} <i class="mdi mdi-arrow-right ml-1"></i>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if(filled($operation->params))
                    <hr>
                    <p class="text-muted mb-2 small font-weight-medium">Settings this operation ran with</p>
                    @foreach($operation->params as $key => $value)
                        @if(filled($value) && !str_ends_with((string) $key, '_id'))
                            <span class="badge badge-light border mr-1 mb-1" style="font-size:.78rem;">
                                {{ ucfirst(str_replace('_', ' ', $key)) }}:
                                <strong>{{ is_scalar($value) ? $value : json_encode($value) }}</strong>
                            </span>
                        @endif
                    @endforeach
                    @if($operation->type === 'training' && ($operation->params['training_run_id'] ?? null))
                        <span class="badge badge-info mr-1 mb-1" style="font-size:.78rem;">
                            Training run #{{ $operation->params['training_run_id'] }}
                        </span>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ── GPU pod ──────────────────────────────────────────────────────────────
     Feature extraction is submitted and then processed remotely, so the pod is
     what limits throughput, not the queue. Giving each operation its own pod is
     the only thing that makes two of them run at once. --}}
@if($operation->type === 'feature_extraction')
<div class="row grid-margin">
    <div class="col-12">
        <div class="card border-left-info">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-2" style="gap:.5rem;">
                    <h4 class="card-title mb-0">
                        <i class="mdi mdi-expansion-card-variant mr-1 text-info"></i>GPU pod for this operation
                    </h4>
                    <button type="button" class="btn btn-sm btn-outline-info" id="pods-refresh">
                        <i class="mdi mdi-refresh mr-1"></i>Load pods &amp; live prices
                    </button>
                </div>

                @if($operation->params['pod_id'] ?? null)
                    <p class="small mb-3">
                        Currently running on
                        <strong>{{ $operation->params['pod_name'] ?? $operation->params['pod_id'] }}</strong>
                        @if($operation->params['pod_gpu'] ?? null)
                            · {{ $operation->params['pod_gpu'] }}
                        @endif
                        @if($operation->params['pod_cost_per_hr'] ?? null)
                            · <span class="text-info">${{ number_format($operation->params['pod_cost_per_hr'], 3) }}/hr</span>
                        @endif
                    </p>
                @else
                    <p class="text-muted small mb-3">
                        This operation uses whichever pod the server points at. Choose one below to give it
                        a pod of its own — then a second operation can run on a different pod at the same time.
                    </p>
                @endif

                <div id="pods-panel" class="text-muted small">
                    Press <em>Load pods &amp; live prices</em> to fetch what is available and what it costs.
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── Resume a run the worker forgot ───────────────────────────────────────
     The GPU worker keeps its queue in memory, so a restart drops whatever was
     waiting while this page goes on showing it as in flight. --}}
@if($operation->type === 'feature_extraction' && $stalledCount > 0)
<div class="row grid-margin">
    <div class="col-12">
        <div class="card border-left-info">
            <div class="card-body">
                <h4 class="card-title mb-1">
                    <i class="mdi mdi-restart mr-1 text-info"></i>Resume unfinished slides
                </h4>
                <p class="text-muted small mb-3">
                    <strong>{{ $stalledCount }} slide(s)</strong> in this run have not reported back.
                    Resuming asks the worker about each one first: any it is still working on are
                    left alone, and only the ones it has forgotten are queued again —
                    <strong>inside this same operation</strong>, with their attempt count raised.
                    Pressing it while the run is healthy does nothing.
                </p>
                <form method="POST" action="{{ route('admin.operations.audit.resume', $operation) }}">
                    @csrf
                    <button type="submit" class="btn btn-info">
                        <i class="mdi mdi-restart mr-1"></i>Resume {{ $stalledCount }} slide(s)
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── Slides still owed by the source run ──────────────────────────────────
     This run covers what was ready when it started. The rest belong to the
     same piece of work, so they join it here rather than starting a second run
     over the same intent. --}}
@if($awaitingFromParent->isNotEmpty())
<div class="row grid-margin">
    <div class="col-12">
        <div class="card border-left-warning">
            <div class="card-body">
                <h4 class="card-title mb-1">
                    <i class="mdi mdi-plus-box-outline mr-1 text-warning"></i>Slides still to be added
                </h4>
                <p class="text-muted small mb-3">
                    <strong>{{ $awaitingFromParent->count() }} slide(s)</strong> of
                    <a href="{{ route('admin.operations.audit.show', $parent) }}">{{ $parent->reference }}</a>
                    are not in this run — they had no patches when it started.
                    @if($addableNow > 0)
                        <span class="text-success d-block mt-1">
                            <i class="mdi mdi-check-circle-outline mr-1"></i>{{ $addableNow }} of them are tiled now
                            and can be added to this run.
                        </span>
                    @else
                        <span class="text-warning d-block mt-1">
                            <i class="mdi mdi-clock-outline mr-1"></i>None are tiled yet. Re-run them in
                            {{ $parent->reference }} first; they can be added here once their patches exist.
                        </span>
                    @endif
                </p>
                <form method="POST" action="{{ route('admin.operations.audit.add-missing', $operation) }}"
                      onsubmit="return confirm('Add {{ $addableNow }} slide(s) to {{ $operation->reference }}?');">
                    @csrf
                    <button type="submit" class="btn btn-warning" {{ $addableNow === 0 ? 'disabled' : '' }}>
                        <i class="mdi mdi-plus mr-1"></i>Add {{ $addableNow }} slide(s) to this run
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── Retry ────────────────────────────────────────────────────────────────
     A retry opens a new operation under the same settings; this record keeps
     saying what happened here. --}}
@if($operation->type === 'patch_extraction' && $retryableCount > 0 && (! $operation->is_fully_resolved || $missingOutput > 0))
<div class="row grid-margin">
    <div class="col-12">
        <div class="card border-left-warning">
            <div class="card-body">
                <h4 class="card-title mb-1">
                    <i class="mdi mdi-refresh mr-1 text-warning"></i>Re-run {{ $missingOutput > 0 ? 'missing' : 'failed' }} slides
                </h4>
                @if($missingOutput > 0)
                    {{-- Recorded as completed, but the patches are not there any more —
                         most often because another operation covering the same slides was
                         deleted with its files. The next stage skips these silently. --}}
                    <div class="alert alert-warning py-2 px-3 mb-3" style="font-size:.85rem;">
                        <i class="mdi mdi-alert-outline mr-1"></i>
                        <strong>{{ $missingOutput }} slide(s) recorded as completed no longer have their patches.</strong>
                        Their files were removed after this run finished, so Feature Extraction
                        skips them. Re-running restores them to this operation.
                    </div>
                @endif
                <p class="text-muted small mb-3">
                    <strong>{{ $retryableCount }} slide(s)</strong> in this run need work.
                    They are re-queued <strong>inside this operation</strong> — it stays their group and
                    reports how they end up, keeping a count of the attempts it took.
                    @if($rescuedBy->isNotEmpty())
                        <span class="text-success d-block mt-1">
                            <i class="mdi mdi-check-circle-outline mr-1"></i>{{ $rescuedBy->count() }} of them
                            {{ $rescuedBy->count() === 1 ? 'has' : 'have' }} since been tiled by a later run —
                            retrying would only repeat work that is already done.
                        </span>
                    @endif
                    Retrying re-queues exactly those, with the same settings this run used
                    @if(filled($operation->params['patch_size'] ?? null))
                        ({{ $operation->params['patch_size'] }}@if(filled($operation->params['magnification'] ?? null)), {{ $operation->params['magnification'] }}@endif)
                    @endif
                    — a new record is opened and this one is left as it stands.
                </p>
                <form method="POST" action="{{ route('admin.operations.audit.retry-failed', $operation) }}"
                      onsubmit="return confirm('Re-queue {{ $retryableCount }} slide(s) from “{{ $operation->name }}”?');">
                    @csrf
                    <button type="submit" class="btn btn-warning">
                        <i class="mdi mdi-refresh mr-1"></i>Retry {{ $retryableCount }} slide(s)
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── Continue the pipeline ─────────────────────────────────────────────────
     Only the slides this run FINISHED are offered onward. Handing the next
     stage a failed slide would queue a job that can only fail again, and record
     work that was never possible. --}}
@if($nextStage === 'feature_extraction')
<div class="row grid-margin">
    <div class="col-12">
        <div class="card border-left-info">
            <div class="card-body">
                <h4 class="card-title mb-1">
                    <i class="mdi mdi-arrow-right-bold-circle-outline mr-1 text-info"></i>Continue: Feature Extraction
                </h4>
                <p class="text-muted small mb-3">
                    Runs over the
                    <strong><span id="op-ready-count">{{ $readyIds->count() }}</span> slide(s)</strong>
                    of this operation whose patches are on Drive right now.
                    @if($missingOutput > 0)
                        <span class="text-warning d-block mt-1">
                            <i class="mdi mdi-alert-outline mr-1"></i>{{ $missingOutput }} other slide(s) are recorded as
                            completed but their patches are gone, so they cannot go forward until they are re-run.
                        </span>
                    @endif
                    @if($operation->failed_items > 0)
                        The {{ $operation->failed_items }} failed slide(s) are left out — their patches were never produced.
                    @endif
                    @if($operation->is_running)
                        <span class="text-info d-block mt-1">
                            <i class="mdi mdi-progress-clock mr-1"></i>This operation is still running; you can start now with
                            what is finished, or wait and take the rest in one go.
                        </span>
                    @endif
                </p>

                <form method="POST" action="{{ route('admin.operations.audit.dispatch-next', $operation) }}">
                    @csrf
                    <div class="d-flex flex-wrap align-items-end" style="gap:.75rem;">
                        <div class="form-group mb-0" style="min-width:220px;">
                            <label class="small text-muted mb-1">Server</label>
                            <select name="server_id" class="form-control" required>
                                <option value="">— Choose server —</option>
                                @foreach($servers as $srv)
                                    <option value="{{ $srv->id }}">{{ $srv->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group mb-0" style="min-width:220px;">
                            <label class="small text-muted mb-1">Feature model</label>
                            <select name="ai_model_id" class="form-control" required>
                                <option value="">— Choose model —</option>
                                @foreach($aiModels as $m)
                                    <option value="{{ $m->id }}" {{ $m->is_default ? 'selected' : '' }}>{{ $m->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <button type="submit" class="btn btn-info" id="op-next-btn"
                                    {{ $readyIds->isEmpty() ? 'disabled' : '' }}>
                                <i class="mdi mdi-play mr-1"></i>Run on
                                <span id="op-ready-count-btn">{{ $readyIds->count() }}</span> slide(s)
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@elseif($nextStage === 'training')
<div class="row grid-margin">
    <div class="col-12">
        <div class="card border-left-info">
            <div class="card-body">
                <h4 class="card-title mb-1">
                    <i class="mdi mdi-arrow-right-bold-circle-outline mr-1 text-info"></i>Continue: Training
                </h4>
                <p class="text-muted small mb-3">
                    {{ $readyIds->count() }} slide(s) here have features ready.
                    {{-- Training is not offered inline: a run needs a train/val/test phase per
                         slide and a class map, which are decisions, not a button. --}}
                    A training run needs a train / validation / test split and a label type
                    chosen per run, so it is set up on the Operations page rather than launched from here.
                </p>
                <a href="{{ route('admin.workflow', ['operation_type' => 'training']) }}" class="btn btn-info">
                    <i class="mdi mdi-school-outline mr-1"></i>Set up a training run
                </a>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── Items ─────────────────────────────────────────────────────────────── --}}
<div class="row">
    <div class="col-12 grid-margin">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-3" style="gap:.5rem;">
                    <h4 class="card-title mb-0">Slides &amp; Cases in this operation</h4>
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="{{ route('admin.operations.audit.show', $operation) }}"
                           class="btn btn-{{ $itemStatus ? 'outline-' : '' }}secondary">
                            All <span class="badge badge-light ml-1">{{ $operation->total_items }}</span>
                        </a>
                        @foreach(['completed' => 'success', 'failed' => 'danger', 'processing' => 'info', 'pending' => 'secondary', 'skipped' => 'dark'] as $status => $colour)
                            @if(($breakdown[$status] ?? 0) > 0)
                                <a href="{{ route('admin.operations.audit.show', [$operation, 'item_status' => $status]) }}"
                                   class="btn btn-{{ $itemStatus === $status ? '' : 'outline-' }}{{ $colour }}">
                                    {{ ucfirst($status) }}
                                    <span class="badge badge-light ml-1">{{ $breakdown[$status] }}</span>
                                </a>
                            @endif
                        @endforeach
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Slide</th>
                                <th>Patient Case</th>
                                <th>Organ</th>
                                <th>Clinical Group</th>
                                <th>Disease</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($items as $item)
                            <tr>
                                <td style="max-width:340px;">
                                    @if($item->sample)
                                        <a href="{{ route('admin.samples.show', $item->sample) }}"
                                           class="text-break">{{ $item->display_name }}</a>
                                    @else
                                        {{-- The slide row is gone; the snapshot is all that is left. --}}
                                        <span class="text-break">{{ $item->display_name }}</span>
                                        <span class="badge badge-warning ml-1" title="This slide has since been deleted from the system">deleted</span>
                                    @endif
                                    @if($item->message)
                                        <div class="small text-danger mt-1">{{ $item->message }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if($item->patientCase)
                                        <a href="{{ route('admin.cases.show', $item->patientCase) }}">
                                            {{ $item->patientCase->submitter_id ?? $item->patientCase->case_id }}
                                        </a>
                                    @elseif($item->case_submitter_id)
                                        <span>{{ $item->case_submitter_id }}</span>
                                        <span class="badge badge-warning ml-1" title="This case has since been deleted from the system">deleted</span>
                                    @else
                                        <span class="text-muted small">no case linked</span>
                                    @endif
                                </td>
                                <td>{{ $item->sample?->organ?->name ?? '—' }}</td>
                                <td>{{ $item->sample?->category?->label_en ?? '—' }}</td>
                                <td>
                                    @if($item->sample?->diseaseSubtype)
                                        <span class="badge badge-light border">{{ $item->sample->diseaseSubtype->name }}</span>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                                <td data-item-id="{{ $item->id }}">
                                    <span class="badge badge-{{ $item->status_colour }} item-status-badge">{{ ucfirst($item->status) }}</span>
                                    @if($operation->type === 'patch_extraction' && $item->sample?->tile_count)
                                        <div class="small text-muted mt-1">{{ number_format($item->sample->tile_count) }} tiles</div>
                                    @endif
                                    {{-- A slide the group had to try more than once. Kept visible
                                         after it succeeds: "done on the second attempt" is the part
                                         of the failure worth remembering. --}}
                                    @if($item->attempts > 1)
                                        <div class="small text-muted mt-1" title="Re-queued inside this operation after failing">
                                            <i class="mdi mdi-refresh"></i>
                                            attempt {{ $item->attempts }}@if($item->status === 'completed'), succeeded @endif
                                        </div>
                                    @endif
                                    {{-- This run did not finish the slide, but a later one did. Said
                                         here so a failure on record is not mistaken for a slide that
                                         is still missing. --}}
                                    @if($rescuedBy->has($item->sample_id))
                                        @php($rescue = $rescuedBy[$item->sample_id])
                                        <div class="small mt-1">
                                            <span class="text-success">
                                                <i class="mdi mdi-check-circle-outline"></i> recovered later
                                            </span>
                                            <a href="{{ route('admin.operations.audit.show', $rescue) }}"
                                               title="This slide was completed by a later run">
                                                {{ $rescue->reference }}
                                            </a>
                                        </div>
                                    @elseif(in_array($item->status, ['failed', 'cancelled'], true))
                                        <div class="small text-danger mt-1">
                                            <i class="mdi mdi-alert-outline"></i> still not tiled
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    No slides match this filter.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if($items->hasPages())
                    <div class="mt-3">{{ $items->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>

@include('admin.operations._delete-modal')
@endsection

@push('scripts')
<script>
// ── Follow this operation from its own page ──────────────────────────────────
// Tiling a slide takes minutes and a run holds dozens of them, so without this
// the only way to see the run advance is to keep reloading. Polls the same
// derived progress the audit list uses, updates the header, each slide's badge
// and the "run on N slides" button, then stops as soon as the run settles.
(function () {
    var bar = document.getElementById('op-bar');
    if (!bar || !@json($operation->is_running)) return;

    var ENDPOINT = @json(route('admin.operations.audit.progress', $operation));
    var EVERY_MS = 8000;

    var COLOURS = {
        completed: 'success', failed: 'danger', processing: 'info',
        skipped: 'secondary', pending: 'light'
    };

    function text(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function paint(d) {
        bar.style.width = d.percent + '%';
        bar.className = 'progress-bar bg-' + d.colour +
            (d.running ? ' progress-bar-striped progress-bar-animated' : '');

        text('op-percent', d.percent + '%');
        text('op-completed', d.completed);
        text('op-total', d.total);
        text('op-completed-stat', d.completed.toLocaleString());
        text('op-failed-stat', d.failed.toLocaleString());
        text('op-ready-count', d.readyForNext);
        text('op-ready-count-btn', d.readyForNext);

        // The next stage becomes available the moment the first slide lands.
        var next = document.getElementById('op-next-btn');
        if (next) next.disabled = d.readyForNext === 0;

        Object.keys(d.items || {}).forEach(function (itemId) {
            var cell = document.querySelector('[data-item-id="' + itemId + '"] .item-status-badge');
            if (!cell) return;                       // on another page of the table
            var status = d.items[itemId];
            cell.className = 'badge badge-' + (COLOURS[status] || 'light') + ' item-status-badge';
            cell.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        });

        // A run that has settled needs one reload to pick up the parts of the
        // page that are rendered server-side — the finished timestamp, the
        // status badge, the follow-up links.
        if (!d.running) {
            clearInterval(timer);
            window.location.reload();
        }
    }

    var timer = setInterval(function () {
        fetch(ENDPOINT, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d) paint(d); })
            .catch(function () { /* a dropped poll is not worth a visible error */ });
    }, EVERY_MS);
}());
</script>
@endpush

@if($operation->type === 'feature_extraction')
@push('scripts')
<script>
// ── Pod picker ───────────────────────────────────────────────────────────────
// Loaded on demand rather than on page load: it calls RunPod, and a review page
// should not hit a paid third-party API every time someone opens it.
(function () {
    var button = document.getElementById('pods-refresh');
    var panel  = document.getElementById('pods-panel');
    if (!button || !panel) return;

    var PODS_URL   = @json(route('admin.operations.audit.pods', $operation));
    var ASSIGN_URL = @json(route('admin.operations.audit.assign-pod', $operation));
    var CSRF       = @json(csrf_token());

    function money(v)  { return v === null || v === undefined ? '—' : '$' + Number(v).toFixed(3) + '/hr'; }
    function hours(h)  {
        if (h === null || h === undefined) return null;
        return h < 1 ? Math.round(h * 60) + ' min' : h.toFixed(1) + ' hr';
    }

    function podRow(p) {
        var est = p.estimate || {};
        // Only shown when it was actually measured — an invented figure on a
        // page about money is worse than no figure.
        var forecast = (est.cost !== null && est.cost !== undefined)
            ? '<div class="small text-muted">' + est.slides + ' slide(s) left · about ' +
              hours(est.hours) + ' · <strong>~$' + est.cost.toFixed(2) + '</strong> at this rate</div>'
            : '<div class="small text-muted">' + (est.slides || 0) + ' slide(s) left · no timing history yet, so no estimate</div>';

        return '' +
        '<tr>' +
          '<td>' +
            '<strong>' + (p.name || p.id) + '</strong>' +
            (p.bound ? ' <span class="badge badge-info ml-1">in use here</span>' : '') +
            '<div class="small text-muted">' + (p.gpu || 'GPU') + '</div>' +
          '</td>' +
          '<td><span class="badge badge-' + (p.running ? 'success' : 'secondary') + '">' + p.status + '</span></td>' +
          '<td class="text-nowrap">' + money(p.cost) + '</td>' +
          '<td>' + forecast + '</td>' +
          '<td class="text-right">' +
            '<button class="btn btn-sm btn-' + (p.running ? 'info' : 'outline-secondary') + ' pod-pick" data-pod="' + p.id + '">' +
              (p.running ? 'Run here' : 'Start pod') +
            '</button>' +
          '</td>' +
        '</tr>';
    }

    function gpuRow(g) {
        return '<tr>' +
            '<td>' + g.name + '</td>' +
            '<td class="text-muted">' + (g.memory_gb ? g.memory_gb + ' GB' : '—') + '</td>' +
            '<td>' + money(g.on_demand) + '</td>' +
            '<td>' + (g.spot_saves
                ? '<span class="text-success">' + money(g.spot) + '</span>'
                : '<span class="text-muted">' + money(g.spot) + '</span>') + '</td>' +
            '<td class="small text-muted">' + (g.spot_saves ? 'cheaper on spot' : 'no spot saving') + '</td>' +
        '</tr>';
    }

    function render(d) {
        var noSaving = (d.gpu_types || []).every(function (g) { return !g.spot_saves; });

        panel.innerHTML =
            '<div class="table-responsive mb-3"><table class="table table-sm mb-0">' +
              '<thead><tr><th>Pod</th><th>Status</th><th>Rate</th><th>This operation</th><th></th></tr></thead>' +
              '<tbody>' + (d.pods.length ? d.pods.map(podRow).join('') :
                '<tr><td colspan="5" class="text-muted">No pods on this RunPod account.</td></tr>') + '</tbody>' +
            '</table></div>' +
            '<details><summary class="text-muted small mb-2" style="cursor:pointer;">' +
              'GPU catalogue — what a new pod would cost' +
            '</summary>' +
            (noSaving ? '<p class="small text-muted mt-2 mb-2">' +
                'RunPod is quoting the same rate for spot as for on-demand on this account, ' +
                'so there is no spot discount to take right now.</p>' : '') +
            '<div class="table-responsive"><table class="table table-sm">' +
              '<thead><tr><th>GPU</th><th>VRAM</th><th>On-demand</th><th>Spot</th><th></th></tr></thead>' +
              '<tbody>' + (d.gpu_types || []).map(gpuRow).join('') + '</tbody>' +
            '</table></div></details>';
    }

    button.addEventListener('click', function () {
        button.disabled = true;
        panel.textContent = 'Asking RunPod…';

        fetch(PODS_URL, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                if (!res.ok) { panel.innerHTML = '<span class="text-danger">' + (res.d.error || 'Could not load pods.') + '</span>'; return; }
                render(res.d);
            })
            .catch(function () { panel.innerHTML = '<span class="text-danger">Could not reach the server.</span>'; })
            .finally(function () { button.disabled = false; });
    });

    // Choosing a pod spends money, so it always asks first and says the rate.
    panel.addEventListener('click', function (e) {
        var pick = e.target.closest('.pod-pick');
        if (!pick) return;

        var row  = pick.closest('tr');
        var rate = row.children[2].textContent.trim();
        if (!confirm('Run ' + @json($operation->reference) + ' on this pod?\n\nRate: ' + rate)) return;

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = ASSIGN_URL;
        form.innerHTML = '<input type="hidden" name="_token" value="' + CSRF + '">' +
                         '<input type="hidden" name="pod_id" value="' + pick.dataset.pod + '">';
        document.body.appendChild(form);
        form.submit();
    });
}());
</script>
@endpush
@endif
