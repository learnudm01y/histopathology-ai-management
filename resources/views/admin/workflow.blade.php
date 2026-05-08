@extends('admin.layouts.app')

@section('title', 'Operations')

@section('content')
    {{-- ─── Page Header ─────────────────────────────────────────────────── --}}
    <div class="page-header">
        <h3 class="page-title">
            <i class="mdi mdi-cogs mr-2"></i>Operations
        </h3>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Operations</li>
            </ol>
        </nav>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="mdi mdi-check-circle mr-1"></i>{{ session('success') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Validation Error:</strong>
            <ul class="mb-0 mt-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    {{-- STEP 1 — Select Operation Type --}}
    <div class="row">
        <div class="col-12 grid-margin">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">
                        <span class="badge badge-primary mr-2" style="font-size:.85rem;">1</span>
                        Select Operation
                    </h4>
                    <p class="card-description">
                        Choose the pipeline operation you want to execute on a set of slides.
                    </p>
                    <div class="row">
                        <div class="col-md-5">
                            <div class="form-group mb-0">
                                <label for="operationTypeSelect">Operation Type</label>
                                <select id="operationTypeSelect" class="form-control form-control-lg">
                                    <option value="">— Select an operation —</option>
                                    <option value="patch_extraction"
                                        {{ ($filters['operation_type'] ?? '') === 'patch_extraction' ? 'selected' : '' }}>
                                        🔲 Patch Extraction (Slide Tiling)
                                    </option>
                                    <option value="feature_extraction" disabled>
                                        📊 Feature Extraction (coming soon)
                                    </option>
                                    <option value="training" disabled>
                                        🧠 Model Training (coming soon)
                                    </option>
                                    <option value="inference" disabled>
                                        🔍 Inference / Prediction (coming soon)
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- STEP 2 — Server & Patch-Size Selection --}}
    <div class="row {{ ($filters['operation_type'] ?? '') !== 'patch_extraction' ? 'd-none' : '' }}"
         id="serverSelectionSection">
        <div class="col-12 grid-margin">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white py-2">
                    <h5 class="mb-0">
                        <span class="badge badge-light text-primary mr-2">2</span>
                        Execution Server &amp; Patch Configuration
                    </h5>
                </div>
                <div class="card-body">
                    <p class="card-description">
                        Select the server that will execute the patch extraction and the patch size to use.
                        After confirming, the sample filter table will appear below.
                    </p>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="serverSelect">
                                    <i class="mdi mdi-server mr-1"></i>Execution Server
                                </label>
                                <select id="serverSelect" class="form-control">
                                    <option value="">— Choose server —</option>
                                    @forelse($servers as $srv)
                                        <option value="{{ $srv->id }}"
                                                data-type="{{ $srv->type }}"
                                                {{ (string)($filters['server_id'] ?? '') === (string)$srv->id ? 'selected' : '' }}>
                                            {{ $srv->name }} — {{ $srv->getTypeLabel() }}
                                            @if($srv->host) ({{ $srv->host }}) @endif
                                        </option>
                                    @empty
                                        <option disabled>No servers configured</option>
                                    @endforelse
                                </select>
                                <small class="form-text text-muted">
                                    "Local Server" = runs on the same machine as this web application.
                                </small>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="patchSizeSelectStep2">
                                    <i class="mdi mdi-grid mr-1"></i>Patch Size
                                </label>
                                <select id="patchSizeSelectStep2" class="form-control">
                                    <option value="">— Choose patch size —</option>
                                    @forelse($patchSizes as $ps)
                                        <option value="{{ $ps->id }}"
                                                {{ (string)($filters['patch_size_id'] ?? '') === (string)$ps->id ? 'selected' : '' }}>
                                            {{ $ps->label }}
                                            @if($ps->aiModel) — {{ $ps->aiModel->name }} @endif
                                        </option>
                                    @empty
                                        <option disabled>No patch sizes configured</option>
                                    @endforelse
                                </select>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="magnificationSelectStep2">
                                    <i class="mdi mdi-magnify-plus-outline mr-1"></i>Magnification
                                </label>
                                <select id="magnificationSelectStep2" class="form-control">
                                    <option value="">— Choose magnification —</option>
                                    @forelse($magnifications as $mag)
                                        <option value="{{ $mag->id }}"
                                                {{ (string)($filters['magnification_id'] ?? '') === (string)$mag->id ? 'selected' : '' }}>
                                            {{ $mag->label }}
                                            @if($mag->notes) — {{ $mag->notes }} @endif
                                        </option>
                                    @empty
                                        <option disabled>No magnifications configured</option>
                                    @endforelse
                                </select>
                                <small class="form-text text-muted">Used as sub-folder in the output path.</small>
                            </div>
                        </div>

                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-group w-100">
                                <button type="button" class="btn btn-primary btn-block" id="confirmServerBtn">
                                    <i class="mdi mdi-check mr-1"></i>
                                    Confirm &amp; Load Sample Filters
                                </button>
                            </div>
                        </div>
                    </div>

                    @if(($filters['server_id'] ?? '') && ($filters['patch_size_id'] ?? '') && ($filters['magnification_id'] ?? ''))
                        @php
                            $confirmedServer       = $servers->firstWhere('id', $filters['server_id']);
                            $confirmedPatchSize    = $patchSizes->firstWhere('id', $filters['patch_size_id']);
                            $confirmedMagnification = $magnifications->firstWhere('id', $filters['magnification_id']);
                        @endphp
                        @if($confirmedServer && $confirmedPatchSize && $confirmedMagnification)
                            <div class="alert alert-success py-2 mb-0 mt-2">
                                <i class="mdi mdi-check-circle mr-1"></i>
                                <strong>Confirmed:</strong>
                                Server <strong>{{ $confirmedServer->name }}</strong>
                                ({{ $confirmedServer->getTypeLabel() }}) —
                                Patch size <strong>{{ $confirmedPatchSize->label }}</strong> —
                                Magnification <strong>{{ $confirmedMagnification->label }}</strong>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- STEP 3 — Sample Filters --}}
    <div class="row {{ (($filters['server_id'] ?? '') && ($filters['patch_size_id'] ?? '') && ($filters['magnification_id'] ?? '')) ? '' : 'd-none' }}"
         id="sampleFiltersSection">
        <div class="col-12 grid-margin">
            <div class="card">
                <div class="card-header py-2">
                    <h5 class="mb-0">
                        <span class="badge badge-secondary mr-2">3</span>
                        <i class="mdi mdi-filter-variant mr-1"></i>Sample Filters
                    </h5>
                </div>
                <div class="card-body">
                    <p class="card-description">
                        Filter the slides to select which ones to process. Pagination keeps the operation context.
                    </p>

                    <form method="GET" action="{{ route('admin.workflow') }}" id="filtersForm">
                        <input type="hidden" name="operation_type" value="{{ $filters['operation_type'] ?? '' }}">
                        <input type="hidden" name="server_id"        value="{{ $filters['server_id'] ?? '' }}">
                        <input type="hidden" name="patch_size_id"    value="{{ $filters['patch_size_id'] ?? '' }}">
                        <input type="hidden" name="magnification_id" value="{{ $filters['magnification_id'] ?? '' }}">

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Uniqueness per case</label>
                                    <select name="uniqueness" class="form-control">
                                        <option value="any"    {{ $filters['uniqueness'] === 'any'    ? 'selected' : '' }}>Any (allow duplicates per case)</option>
                                        <option value="unique" {{ $filters['uniqueness'] === 'unique' ? 'selected' : '' }}>Unique (one sample per case)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Gender (clinical)</label>
                                    <select name="gender" class="form-control">
                                        <option value="">— Any —</option>
                                        <option value="male"   {{ $filters['gender'] === 'male'   ? 'selected' : '' }}>Male</option>
                                        <option value="female" {{ $filters['gender'] === 'female' ? 'selected' : '' }}>Female</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Image Quality</label>
                                    <select name="quality_status" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach(['passed','rejected','needs_review','pending'] as $q)
                                            <option value="{{ $q }}" {{ $filters['quality_status'] === $q ? 'selected' : '' }}>
                                                {{ ucfirst(str_replace('_',' ',$q)) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Category</label>
                                    <select name="category_id" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($categories as $c)
                                            <option value="{{ $c->id }}" {{ (string)$filters['category_id'] === (string)$c->id ? 'selected' : '' }}>
                                                {{ $c->label_en }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Min Size (GB)</label>
                                    <input type="number" step="0.01" min="0" name="min_size_gb" class="form-control"
                                           value="{{ $filters['min_size_gb'] }}" placeholder="0">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Max Size (GB)</label>
                                    <input type="number" step="0.01" min="0" name="max_size_gb" class="form-control"
                                           value="{{ $filters['max_size_gb'] }}" placeholder="any">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Organ</label>
                                    <select name="organ_id" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($organs as $o)
                                            <option value="{{ $o->id }}" {{ (string)$filters['organ_id'] === (string)$o->id ? 'selected' : '' }}>
                                                {{ $o->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Stain</label>
                                    <select name="stain_id" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($stains as $s)
                                            <option value="{{ $s->id }}" {{ (string)$filters['stain_id'] === (string)$s->id ? 'selected' : '' }}>
                                                {{ $s->abbreviation }} — {{ $s->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Data Source</label>
                                    <select name="data_source_id" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($dataSources as $d)
                                            <option value="{{ $d->id }}" {{ (string)$filters['data_source_id'] === (string)$d->id ? 'selected' : '' }}>
                                                {{ $d->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Disease Type</label>
                                    <select name="disease_type" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($diseaseTypes as $dt)
                                            <option value="{{ $dt }}" {{ $filters['disease_type'] === $dt ? 'selected' : '' }}>
                                                {{ $dt }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Tiling Status</label>
                                    <select name="tiling_status" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach(['pending','processing','done','failed'] as $t)
                                            <option value="{{ $t }}" {{ $filters['tiling_status'] === $t ? 'selected' : '' }}>
                                                {{ ucfirst($t) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Magnification</label>
                                    <select name="filter_magnification_id" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($magnifications as $m)
                                            <option value="{{ $m->id }}" {{ (string)($filters['filter_magnification_id'] ?? '') === (string)$m->id ? 'selected' : '' }}>
                                                {{ $m->label }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Usable</label>
                                    <select name="is_usable" class="form-control">
                                        <option value="">— Any —</option>
                                        <option value="1" {{ $filters['is_usable'] === '1' ? 'selected' : '' }}>Usable only</option>
                                        <option value="0" {{ $filters['is_usable'] === '0' ? 'selected' : '' }}>Not usable</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-9 d-flex align-items-end">
                                <div class="form-group d-flex" style="gap:.5rem;">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="mdi mdi-magnify mr-1"></i> Apply Filters
                                    </button>
                                    <a href="{{ route('admin.workflow') }}" class="btn btn-outline-secondary">
                                        <i class="mdi mdi-refresh mr-1"></i> Reset All
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- STEP 4 — Sample Table + Execute --}}
    <div class="row {{ (($filters['server_id'] ?? '') && ($filters['patch_size_id'] ?? '') && ($filters['magnification_id'] ?? '')) ? '' : 'd-none' }}"
         id="sampleTableSection">
        <div class="col-12 grid-margin">
            <form id="dispatchForm"
                  method="POST"
                  action="{{ route('admin.workflow.dispatch.patch-extraction') }}">
                @csrf
                <input type="hidden" name="server_id"        value="{{ $filters['server_id'] ?? '' }}">
                <input type="hidden" name="patch_size_id"    value="{{ $filters['patch_size_id'] ?? '' }}">
                <input type="hidden" name="magnification_id" value="{{ $filters['magnification_id'] ?? '' }}">

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3" style="gap:1rem;">
                            <div>
                                <h4 class="card-title mb-1">
                                    <span class="badge badge-secondary mr-2">4</span>
                                    Matching Samples
                                    <span class="badge badge-secondary ml-1">{{ $samples->total() }}</span>
                                </h4>
                                <small class="text-muted">
                                    <span id="selectedCount">0</span> selected on this page
                                </small>
                            </div>

                            <div class="d-flex align-items-center flex-wrap" style="gap:.75rem;">
                                @if(($filters['server_id'] ?? '') && ($filters['patch_size_id'] ?? '') && ($filters['magnification_id'] ?? ''))
                                    @php
                                        $srv = $servers->firstWhere('id', $filters['server_id']);
                                        $psz = $patchSizes->firstWhere('id', $filters['patch_size_id']);
                                        $mag = $magnifications->firstWhere('id', $filters['magnification_id']);
                                    @endphp
                                    <span class="badge badge-primary px-3 py-2" style="font-size:.8rem;">
                                        <i class="mdi mdi-server mr-1"></i>{{ $srv?->name ?? '—' }}
                                        &nbsp;|&nbsp;
                                        <i class="mdi mdi-grid mr-1"></i>{{ $psz?->size_px ?? '—' }}px patches
                                        &nbsp;|&nbsp;
                                        <i class="mdi mdi-magnify-plus-outline mr-1"></i>{{ $mag?->label ?? '—' }}
                                    </span>
                                @endif

                                <button type="submit" class="btn btn-success" id="executeBtn" disabled>
                                    <i class="mdi mdi-play mr-1"></i>
                                    Execute Patch Extraction
                                    (<span id="executeCount">0</span>)
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-sm">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width:40px;">
                                            <input type="checkbox" id="selectAll" title="Select all on this page">
                                        </th>
                                        <th>#</th>
                                        <th>File</th>
                                        <th>Case</th>
                                        <th>Disease</th>
                                        <th>Organ</th>
                                        <th>Stain</th>
                                        <th>Source</th>
                                        <th>Category</th>
                                        <th class="text-right">Size</th>
                                        <th>Tiling</th>
                                        <th>Tile</th>
                                        <th>Mag.</th>
                                        <th>Quality</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($samples as $s)
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="sample-check"
                                                       name="sample_ids[]" value="{{ $s->id }}">
                                            </td>
                                            <td class="text-muted small">{{ $s->id }}</td>
                                            <td class="small"
                                                style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                                title="{{ $s->file_name }}">
                                                <a href="{{ route('admin.samples.show', $s) }}" target="_blank">
                                                    {{ $s->file_name ?? '—' }}
                                                </a>
                                            </td>
                                            <td class="small">
                                                @if($s->patientCase)
                                                    <code>{{ Str::limit($s->patientCase->case_id, 12, '…') }}</code>
                                                @else — @endif
                                            </td>
                                            <td class="small">{{ $s->patientCase->disease_type ?? '—' }}</td>
                                            <td class="small">{{ $s->organ->name ?? '—' }}</td>
                                            <td class="small">
                                                @if($s->stain)
                                                    <span class="badge badge-light border">{{ $s->stain->abbreviation }}</span>
                                                @else — @endif
                                            </td>
                                            <td class="small">{{ $s->dataSource->name ?? '—' }}</td>
                                            <td class="small">{{ $s->category->label_en ?? '—' }}</td>
                                            <td class="small text-right">{{ $s->file_size_human }}</td>
                                            <td>
                                                <span class="badge badge-{{ $s->tiling_status_badge }}">
                                                    {{ $s->tiling_status }}
                                                </span>
                                            </td>
                                            <td class="small">{{ $s->tile_size_px ? $s->tile_size_px.'px' : '—' }}</td>
                                            <td class="small">{{ $s->magnification ?? '—' }}</td>
                                            <td>
                                                <span class="badge badge-{{ $s->quality_status_badge }}">
                                                    {{ $s->quality_status }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="14" class="text-center py-5 text-muted">
                                                <i class="mdi mdi-database-search-outline" style="font-size:2.5rem;"></i>
                                                <p class="mt-2 mb-0">No samples match the current filters.</p>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3">
                            {{ $samples->links() }}
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script>
    (function () {
        'use strict';

        var opTypeSelect         = document.getElementById('operationTypeSelect');
        var serverSection        = document.getElementById('serverSelectionSection');
        var sampleFiltersSection = document.getElementById('sampleFiltersSection');
        var sampleTableSection   = document.getElementById('sampleTableSection');
        var serverSelect         = document.getElementById('serverSelect');
        var patchSizeSelect      = document.getElementById('patchSizeSelectStep2');
        var magnificationSelect  = document.getElementById('magnificationSelectStep2');
        var confirmBtn           = document.getElementById('confirmServerBtn');
        var selectAll            = document.getElementById('selectAll');
        var executeBtn           = document.getElementById('executeBtn');
        var executeCountEl       = document.getElementById('executeCount');
        var selectedCountEl      = document.getElementById('selectedCount');

        function show(el) { if (el) el.classList.remove('d-none'); }
        function hide(el) { if (el) el.classList.add('d-none'); }

        function refreshCheckboxState() {
            var n = document.querySelectorAll('.sample-check:checked').length;
            var total = document.querySelectorAll('.sample-check').length;
            if (selectedCountEl) selectedCountEl.textContent = n;
            if (executeCountEl)  executeCountEl.textContent  = n;
            if (executeBtn)      executeBtn.disabled          = (n === 0);
            if (selectAll) {
                selectAll.checked       = (n > 0 && n === total);
                selectAll.indeterminate = (n > 0 && n < total);
            }
        }

        function onOperationTypeChange() {
            var val = opTypeSelect ? opTypeSelect.value : '';
            if (val === 'patch_extraction') {
                show(serverSection);
                var allConfirmed = serverSelect && serverSelect.value
                                && patchSizeSelect && patchSizeSelect.value
                                && magnificationSelect && magnificationSelect.value;
                if (allConfirmed) {
                    show(sampleFiltersSection);
                    show(sampleTableSection);
                }
            } else {
                hide(serverSection);
                hide(sampleFiltersSection);
                hide(sampleTableSection);
            }
        }

        if (opTypeSelect) {
            opTypeSelect.addEventListener('change', onOperationTypeChange);
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                var sid = serverSelect       ? serverSelect.value       : '';
                var pid = patchSizeSelect    ? patchSizeSelect.value    : '';
                var mid = magnificationSelect ? magnificationSelect.value : '';
                if (!sid) { alert('Please select an execution server.'); return; }
                if (!pid) { alert('Please select a patch size.'); return; }
                if (!mid) { alert('Please select a magnification level.'); return; }
                var url = new URL(window.location.href);
                url.searchParams.set('operation_type',  'patch_extraction');
                url.searchParams.set('server_id',       sid);
                url.searchParams.set('patch_size_id',   pid);
                url.searchParams.set('magnification_id', mid);
                window.location.href = url.toString();
            });
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.sample-check').forEach(function (c) {
                    c.checked = selectAll.checked;
                });
                refreshCheckboxState();
            });
        }

        document.addEventListener('change', function (e) {
            if (e.target && e.target.classList.contains('sample-check')) {
                refreshCheckboxState();
            }
        });

        var dispatchForm = document.getElementById('dispatchForm');
        if (dispatchForm) {
            dispatchForm.addEventListener('submit', function (e) {
                var n = document.querySelectorAll('.sample-check:checked').length;
                if (n === 0) {
                    e.preventDefault();
                    alert('Please select at least one sample before executing.');
                    return;
                }
                if (!confirm('Queue ' + n + ' slide(s) for patch extraction?\n\nEach slide will be downloaded from Google Drive, patched, and the results uploaded to the "Sliced Slides" folder.')) {
                    e.preventDefault();
                }
            });
        }

        onOperationTypeChange();
        refreshCheckboxState();
    })();
    </script>
    @endpush
@endsection
