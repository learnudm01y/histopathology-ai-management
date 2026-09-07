@extends('admin.layouts.app')

@section('title', 'Operation — ' . $operation->name)

@section('content')
<div class="page-header">
    <h3 class="page-title">Operation Review</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.operations.audit.index') }}">Operations Audit</a></li>
            <li class="breadcrumb-item active" aria-current="page">#{{ $operation->id }}</li>
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
                        <div class="h2 mb-0 text-{{ $operation->status_colour }}">{{ $operation->progress_percent }}%</div>
                        <small class="text-muted">{{ $operation->completed_items }} / {{ $operation->total_items }} slides</small>
                    </div>
                </div>

                <div class="progress mt-3" style="height:8px;">
                    <div class="progress-bar bg-{{ $operation->status_colour }}"
                         role="progressbar" style="width: {{ $operation->progress_percent }}%"></div>
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
                        <h5 class="mb-0 text-success">{{ number_format($operation->completed_items) }}</h5>
                    </div>
                    <div class="col-md-3 col-6 mb-2">
                        <p class="text-muted mb-0 small">Failed</p>
                        <h5 class="mb-0 text-danger">{{ number_format($operation->failed_items) }}</h5>
                    </div>
                </div>

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
                                <td>
                                    <span class="badge badge-{{ $item->status_colour }}">{{ ucfirst($item->status) }}</span>
                                    @if($operation->type === 'patch_extraction' && $item->sample?->tile_count)
                                        <div class="small text-muted mt-1">{{ number_format($item->sample->tile_count) }} tiles</div>
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
@endsection
