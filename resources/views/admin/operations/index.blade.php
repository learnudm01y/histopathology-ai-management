@extends('admin.layouts.app')

@section('title', 'Operations Audit')

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
@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="mdi mdi-alert-circle-outline mr-1"></i> {{ $errors->first() }}
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
@endif

<div class="page-header">
    <h3 class="page-title">Operations Audit</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">Operations Audit</li>
        </ol>
    </nav>
</div>

{{-- ── Stats ─────────────────────────────────────────────────────────────── --}}
<div class="row">
    <div class="col-xl-3 col-sm-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between">
                <div>
                    <p class="font-weight-medium mb-1 text-muted">Operations</p>
                    <h3 class="font-weight-bold mb-0">{{ number_format($stats['total']) }}</h3>
                </div>
                <i class="mdi mdi-history icon-lg text-primary"></i>
            </div>
        </div></div>
    </div>
    <div class="col-xl-3 col-sm-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between">
                <div>
                    <p class="font-weight-medium mb-1 text-muted">Running</p>
                    <h3 class="font-weight-bold mb-0 text-info">{{ number_format($stats['running']) }}</h3>
                </div>
                <i class="mdi mdi-progress-clock icon-lg text-info"></i>
            </div>
        </div></div>
    </div>
    <div class="col-xl-3 col-sm-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between">
                <div>
                    <p class="font-weight-medium mb-1 text-muted" title="Runs with a failure nothing has since made good">Needing Attention</p>
                    <h3 class="font-weight-bold mb-0 text-danger">{{ number_format($stats['failed']) }}</h3>
                </div>
                <i class="mdi mdi-alert-circle-outline icon-lg text-danger"></i>
            </div>
        </div></div>
    </div>
    <div class="col-xl-3 col-sm-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between">
                <div>
                    <p class="font-weight-medium mb-1 text-muted">Slides Processed</p>
                    <h3 class="font-weight-bold mb-0 text-warning">{{ number_format($stats['slides']) }}</h3>
                </div>
                <i class="mdi mdi-image-multiple-outline icon-lg text-warning"></i>
            </div>
        </div></div>
    </div>
</div>

{{-- ── Filters ───────────────────────────────────────────────────────────── --}}
<div class="row grid-margin">
    <div class="col-12">
        <div class="card">
            <div class="card-body py-3 px-4">
                <form method="GET" action="{{ route('admin.operations.audit.index') }}">
                    <div class="d-flex flex-wrap align-items-center" style="gap:.75rem;">
                        <div class="input-group" style="min-width:260px;max-width:340px;flex:1 1 260px;">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-white border-right-0">
                                    <i class="mdi mdi-magnify text-muted"></i>
                                </span>
                            </div>
                            <input type="text" name="search" value="{{ $filters['search'] }}"
                                   class="form-control border-left-0 pl-0" placeholder="Reference (OP-…) or name">
                        </div>

                        <select name="type" class="form-control" style="width:auto;min-width:180px;">
                            <option value="">All operation types</option>
                            @foreach(\App\Models\Operation::TYPES as $value => $label)
                                <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>

                        <select name="status" class="form-control" style="width:auto;min-width:180px;">
                            <option value="">Any status</option>
                            <option value="running"                 @selected($filters['status'] === 'running')>Running</option>
                            <option value="completed"               @selected($filters['status'] === 'completed')>Completed</option>
                            <option value="completed_with_failures" @selected($filters['status'] === 'completed_with_failures')>Completed with failures</option>
                            <option value="failed"                  @selected($filters['status'] === 'failed')>Failed</option>
                            <option value="cancelled"               @selected($filters['status'] === 'cancelled')>Cancelled</option>
                        </select>

                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="mdi mdi-filter-outline mr-1"></i>Apply
                        </button>
                        <a href="{{ route('admin.operations.audit.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- ── List ──────────────────────────────────────────────────────────────── --}}
<div class="row">
    <div class="col-12 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title">
                    Operations
                    <span class="badge badge-primary ml-2">{{ number_format($operations->total()) }}</span>
                </h4>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th style="min-width:150px;">Reference</th>
                                <th>Operation</th>
                                <th>Type</th>
                                <th style="min-width:180px;">Progress</th>
                                <th class="text-center">Slides</th>
                                <th>Status</th>
                                <th>By</th>
                                <th>Started</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($operations as $operation)
                            <tr>
                                {{-- The handle to quote when referring to this run elsewhere. --}}
                                <td>
                                    <code class="op-reference" style="font-size:.82rem;">{{ $operation->reference }}</code>
                                </td>
                                <td>
                                    {{-- The name is the way in: everything about the run is behind it. --}}
                                    <a href="{{ route('admin.operations.audit.show', $operation) }}"
                                       class="font-weight-medium">
                                        {{ $operation->name }}
                                    </a>
                                </td>
                                <td><span class="badge badge-light border">{{ $operation->type_label }}</span></td>
                                <td>
                                    <div class="progress" style="height:6px;">
                                        <div class="progress-bar bg-{{ $operation->status_colour }}"
                                             role="progressbar"
                                             style="width: {{ $operation->progress_percent }}%"
                                             aria-valuenow="{{ $operation->progress_percent }}"
                                             aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <small class="text-muted">
                                        {{ $operation->progress_percent }}% —
                                        {{ $operation->completed_items }} done
                                        @if($operation->is_fully_resolved)
                                            · <span class="text-success">{{ $operation->failed_items }} resolved later</span>
                                        @elseif($operation->failed_items)
                                            · <span class="text-danger">{{ $operation->unresolved_failures }} failed</span>
                                            @if($operation->failed_items > $operation->unresolved_failures)
                                                · <span class="text-success">{{ $operation->failed_items - $operation->unresolved_failures }} resolved later</span>
                                            @endif
                                        @endif
                                    </small>
                                </td>
                                <td class="text-center">{{ number_format($operation->total_items) }}</td>
                                <td>
                                    <span class="badge badge-{{ $operation->status_colour }}">
                                        {{ $operation->status_label }}
                                    </span>
                                </td>
                                <td class="text-muted">{{ $operation->user?->name ?? '—' }}</td>
                                <td class="text-muted">
                                    {{ $operation->started_at?->format('Y-m-d H:i') ?? $operation->created_at->format('Y-m-d H:i') }}
                                </td>
                                <td class="text-right text-nowrap">
                                    @if($operation->is_running)
                                        {{-- Stopping is offered only while there is something left to stop. --}}
                                        <form method="POST" action="{{ route('admin.operations.audit.cancel', $operation) }}"
                                              class="d-inline"
                                              onsubmit="return confirm('Stop “{{ $operation->name }}”?\n\nSlides that already finished keep their output. Only the work that has not run yet is cancelled.');">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Stop this operation">
                                                <i class="mdi mdi-stop"></i> Stop
                                            </button>
                                        </form>
                                    @else
                                        <button type="button" class="btn btn-sm btn-outline-danger op-delete-btn"
                                                data-op-id="{{ $operation->id }}"
                                                data-op-name="{{ $operation->name }}"
                                                data-op-type="{{ $operation->type }}"
                                                data-op-slides="{{ $operation->completed_items }}"
                                                data-op-url="{{ route('admin.operations.audit.destroy', $operation) }}"
                                                title="Delete this operation">
                                            <i class="mdi mdi-delete-outline"></i> Delete
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    <i class="mdi mdi-history icon-md d-block mb-2"></i>
                                    No operations recorded yet. Dispatch one from
                                    <a href="{{ route('admin.workflow') }}">Operations</a> and it will appear here.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if($operations->hasPages())
                    <div class="mt-3">{{ $operations->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>


@include("admin.operations._delete-modal")
@endsection
