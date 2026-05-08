@extends('admin.layouts.app')

@section('title', 'Cases')

@section('content')
<div class="page-header">
    <h3 class="page-title">Patient Cases</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">Cases</li>
        </ol>
    </nav>
</div>

{{-- ── Stats ─────────────────────────────────────────────────────────────── --}}
<div class="row">
    <div class="col-xl-3 col-sm-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between">
                <div>
                    <p class="font-weight-medium mb-1 text-muted">Total Cases</p>
                    <h3 class="font-weight-bold mb-0">{{ number_format($stats['total']) }}</h3>
                </div>
                <i class="mdi mdi-account-multiple-outline icon-lg text-primary"></i>
            </div>
        </div></div>
    </div>
    <div class="col-xl-3 col-sm-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between">
                <div>
                    <p class="font-weight-medium mb-1 text-muted">With Clinical Info</p>
                    <h3 class="font-weight-bold mb-0 text-info">{{ number_format($stats['with_clinical']) }}</h3>
                </div>
                <i class="mdi mdi-clipboard-text-outline icon-lg text-info"></i>
            </div>
        </div></div>
    </div>
    <div class="col-xl-3 col-sm-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between">
                <div>
                    <p class="font-weight-medium mb-1 text-muted">With Slides</p>
                    <h3 class="font-weight-bold mb-0 text-warning">{{ number_format($stats['with_slides']) }}</h3>
                </div>
                <i class="mdi mdi-image-multiple-outline icon-lg text-warning"></i>
            </div>
        </div></div>
    </div>
    <div class="col-xl-3 col-sm-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between">
                <div>
                    <p class="font-weight-medium mb-1 text-muted">Fully Linked</p>
                    <h3 class="font-weight-bold mb-0 text-success">{{ number_format($stats['fully_linked']) }}</h3>
                </div>
                <i class="mdi mdi-check-decagram icon-lg text-success"></i>
            </div>
        </div></div>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="mdi mdi-check-circle-outline mr-1"></i> {{ session('success') }}
    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
</div>
@endif

{{-- ── Filters ───────────────────────────────────────────────────────────── --}}
<div class="row grid-margin">
    <div class="col-12">
        <div class="card">
            <div class="card-body py-3 px-4">
                <form method="GET" action="{{ route('admin.cases.index') }}" id="cases-filter-form">
                    <div class="d-flex flex-wrap align-items-center" style="gap:.75rem;">

                        {{-- Search --}}
                        <div class="input-group" style="min-width:260px;max-width:320px;flex:1 1 260px;">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-white border-right-0">
                                    <i class="mdi mdi-magnify text-muted"></i>
                                </span>
                            </div>
                            <input type="text" name="search" value="{{ request('search') }}"
                                   class="form-control border-left-0 pl-0"
                                   placeholder="Case ID, submitter, project, disease…">
                        </div>

                        {{-- Data Source --}}
                        <select name="data_source_id" class="form-control" style="width:auto;min-width:140px;">
                            <option value="">All Sources</option>
                            @foreach($dataSources as $ds)
                                <option value="{{ $ds->id }}" @selected(request('data_source_id') == $ds->id)>{{ $ds->name }}</option>
                            @endforeach
                        </select>

                        {{-- Divider --}}
                        <div class="border-left" style="height:28px;"></div>

                        {{-- Clinical filters --}}
                        <div class="d-flex align-items-center" style="gap:.6rem;">
                            <span class="text-muted small font-weight-medium">Clinical:</span>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="f-with-clinical"
                                       name="with_clinical" value="1" @checked(request('with_clinical'))>
                                <label class="custom-control-label" for="f-with-clinical">Has clinical</label>
                            </div>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="f-without-clinical"
                                       name="without_clinical" value="1" @checked(request('without_clinical'))>
                                <label class="custom-control-label" for="f-without-clinical">Missing clinical</label>
                            </div>
                        </div>

                        {{-- Divider --}}
                        <div class="border-left" style="height:28px;"></div>

                        {{-- Slides filters --}}
                        <div class="d-flex align-items-center" style="gap:.6rem;">
                            <span class="text-muted small font-weight-medium">Slides:</span>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="f-with-slides"
                                       name="with_slides" value="1" @checked(request('with_slides'))>
                                <label class="custom-control-label" for="f-with-slides">Has slides</label>
                            </div>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="f-without-slides"
                                       name="without_slides" value="1" @checked(request('without_slides'))>
                                <label class="custom-control-label" for="f-without-slides">No slides</label>
                            </div>
                        </div>

                        {{-- Divider --}}
                        <div class="border-left" style="height:28px;"></div>

                        {{-- Fully linked shortcut --}}
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="f-fully-linked"
                                   name="fully_linked" value="1" @checked(request('fully_linked'))>
                            <label class="custom-control-label" for="f-fully-linked">
                                <i class="mdi mdi-check-decagram text-success"></i> Fully linked
                            </label>
                        </div>

                        {{-- Actions --}}
                        <div class="d-flex align-items-center ml-auto" style="gap:.5rem;">
                            <button type="submit" class="btn btn-primary btn-sm px-3">
                                <i class="mdi mdi-filter-outline mr-1"></i>Apply
                            </button>
                            <a href="{{ route('admin.cases.index') }}" class="btn btn-outline-secondary btn-sm px-3">
                                <i class="mdi mdi-close mr-1"></i>Reset
                            </a>
                        </div>

                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- ── Table ─────────────────────────────────────────────────────────────── --}}
<div class="row">
    <div class="col-12 grid-margin">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="card-title mb-0">Cases ({{ number_format($cases->total()) }})</h4>
                    <button type="button" class="btn btn-danger btn-sm" data-toggle="modal" data-target="#bulkDeleteCasesModal">
                        <i class="mdi mdi-delete-sweep mr-1"></i> Bulk Delete
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Submitter ID</th>
                                <th>Case UUID</th>
                                <th>Project</th>
                                <th>Disease Type</th>
                                <th>Primary Site</th>
                                <th class="text-center">Slides</th>
                                <th class="text-center">Clinical</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($cases as $c)
                            <tr>
                                <td><strong>{{ $c->submitter_id ?? '—' }}</strong></td>
                                <td class="small text-muted">{{ $c->case_id }}</td>
                                <td>
                                    @if($c->project_id)
                                        <span class="badge badge-outline-primary">{{ $c->project_id }}</span>
                                    @else — @endif
                                </td>
                                <td>{{ $c->disease_type ?? '—' }}</td>
                                <td>{{ $c->primary_site ?? '—' }}</td>
                                <td class="text-center">
                                    @if($c->samples_count > 0)
                                        <span class="badge badge-warning">{{ $c->samples_count }}</span>
                                    @else
                                        <span class="text-muted small">none</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($c->clinicalInfo)
                                        <i class="mdi mdi-check-circle text-success"></i>
                                    @else
                                        <i class="mdi mdi-minus-circle-outline text-muted"></i>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('admin.cases.show', $c->id) }}"
                                       class="btn btn-icon-text btn-sm btn-outline-primary">
                                        <i class="mdi mdi-eye-outline"></i> View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    No cases found. Import a clinical / metadata file from the
                                    <a href="{{ route('admin.samples') }}">Samples</a> page.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">{{ $cases->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection

{{-- ── Bulk Delete Cases Modal ──────────────────────────────────────────── --}}
@push('modals')
<div class="modal fade" id="bulkDeleteCasesModal" tabindex="-1" role="dialog" aria-labelledby="bulkDeleteCasesLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content border-danger" style="border-width:2px;">
            <div class="modal-header bg-danger text-white py-2">
                <h5 class="modal-title mb-0" id="bulkDeleteCasesLabel">
                    <i class="mdi mdi-delete-sweep mr-1"></i> Bulk Delete Cases
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="mdi mdi-alert-outline mr-1"></i>
                    <strong>Note:</strong> Cases will be deleted from the database along with their clinical information.
                    Slides linked to these cases will <strong>not</strong> be deleted — they will be unlinked instead.
                </div>

                {{-- Filters --}}
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group mb-2">
                            <label class="small font-weight-medium">Data Source</label>
                            <select id="bc-data-source" class="form-control form-control-sm">
                                <option value="">— Any —</option>
                                @foreach($dataSources as $ds)
                                    <option value="{{ $ds->id }}">{{ $ds->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-2">
                            <label class="small font-weight-medium">Condition</label>
                            <div class="custom-control custom-checkbox mt-2">
                                <input type="checkbox" class="custom-control-input" id="bc-no-slides">
                                <label class="custom-control-label" for="bc-no-slides">
                                    <strong>No slides available</strong>
                                    <small class="text-muted d-block">Delete only cases with no linked slides</small>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="bcPreviewBtn">
                            <i class="mdi mdi-magnify mr-1"></i> Preview Matching Cases
                        </button>
                    </div>
                </div>

                {{-- Preview result --}}
                <div id="bcPreviewResult" class="d-none">
                    <hr class="my-2">
                    <div class="d-flex align-items-center mb-2">
                        <span class="font-weight-bold text-danger mr-2">
                            <i class="mdi mdi-alert-circle-outline mr-1"></i>
                            <span id="bcMatchCount">0</span> case(s) will be deleted
                        </span>
                    </div>
                    <div id="bcPreviewList" class="small text-muted bg-light rounded p-2" style="max-height:120px;overflow-y:auto;"></div>
                </div>

                {{-- Confirm step --}}
                <div id="bcConfirmStep" class="mt-3 d-none">
                    <hr class="my-2">
                    <div class="form-group mb-0">
                        <label class="small font-weight-medium text-danger">
                            Type <code>DELETE</code> to confirm:
                        </label>
                        <input type="text" id="bcConfirmInput" class="form-control form-control-sm mt-1"
                               placeholder="DELETE" autocomplete="off">
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm" id="bcExecuteBtn" disabled>
                    <i class="mdi mdi-delete-forever mr-1"></i> Delete Cases
                </button>
            </div>
        </div>
    </div>
</div>

<form id="bcDeleteForm" method="POST" action="{{ route('admin.bulk.cases.delete') }}" style="display:none;">
    @csrf
    @method('DELETE')
    <input type="hidden" name="data_source_id" id="bcF-data-source">
    <input type="hidden" name="no_slides_only" id="bcF-no-slides">
    <input type="hidden" name="confirm"         id="bcF-confirm">
</form>
@endpush

@push('scripts')
<script>
(function () {
    var previewUrl = "{{ route('admin.bulk.cases.preview') }}";

    document.getElementById('bcPreviewBtn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span> Loading…';

        fetch(previewUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                data_source_id: document.getElementById('bc-data-source').value || null,
                no_slides_only: document.getElementById('bc-no-slides').checked ? 1 : 0,
            }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            document.getElementById('bcMatchCount').textContent = data.count;
            var list = document.getElementById('bcPreviewList');
            list.innerHTML = data.preview.length
                ? data.preview.map(function (n) { return '<div>' + n + '</div>'; }).join('') +
                  (data.count > data.preview.length ? '<div class="text-muted">… and ' + (data.count - data.preview.length) + ' more</div>' : '')
                : '<em>No cases to show</em>';
            document.getElementById('bcPreviewResult').classList.remove('d-none');
            document.getElementById('bcConfirmStep').classList.toggle('d-none', data.count === 0);
        })
        .catch(function () { alert('Preview failed.'); })
        .finally(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="mdi mdi-magnify mr-1"></i> Preview Matching Cases';
        });
    });

    document.getElementById('bcConfirmInput').addEventListener('input', function () {
        document.getElementById('bcExecuteBtn').disabled = (this.value.trim() !== 'DELETE');
    });

    document.getElementById('bcExecuteBtn').addEventListener('click', function () {
        document.getElementById('bcF-data-source').value = document.getElementById('bc-data-source').value;
        document.getElementById('bcF-no-slides').value   = document.getElementById('bc-no-slides').checked ? '1' : '';
        document.getElementById('bcF-confirm').value     = document.getElementById('bcConfirmInput').value;
        document.getElementById('bcDeleteForm').submit();
    });

    document.getElementById('bulkDeleteCasesModal').addEventListener('hidden.bs.modal', function () {
        document.getElementById('bcPreviewResult').classList.add('d-none');
        document.getElementById('bcConfirmStep').classList.add('d-none');
        document.getElementById('bcConfirmInput').value = '';
        document.getElementById('bcExecuteBtn').disabled = true;
    });
}());
</script>
@endpush
