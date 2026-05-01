@extends('admin.layouts.app')

@section('title', 'Workflow')

@section('content')
    <div class="page-header">
        <h3 class="page-title">Workflow — Training Sample Selection</h3>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Workflow</li>
            </ol>
        </nav>
    </div>

    {{-- ─── Filters ─────────────────────────────────────────────────── --}}
    <div class="row">
        <div class="col-12 grid-margin">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">
                        <i class="mdi mdi-filter-variant mr-1"></i>
                        Sample Filters
                    </h4>
                    <p class="card-description">
                        Use the filters below to select samples for training. Selected samples will be
                        prepared and dispatched to the training pipeline.
                    </p>

                    <form method="GET" action="{{ route('admin.workflow') }}" id="filtersForm">
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
                                            <option value="{{ $c->id }}" {{ (string) $filters['category_id'] === (string) $c->id ? 'selected' : '' }}>
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
                                    <input type="number" step="0.01" min="0" name="min_size_gb"
                                           class="form-control"
                                           value="{{ $filters['min_size_gb'] }}" placeholder="0">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Max Size (GB)</label>
                                    <input type="number" step="0.01" min="0" name="max_size_gb"
                                           class="form-control"
                                           value="{{ $filters['max_size_gb'] }}" placeholder="any">
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Organ</label>
                                    <select name="organ_id" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($organs as $o)
                                            <option value="{{ $o->id }}" {{ (string) $filters['organ_id'] === (string) $o->id ? 'selected' : '' }}>
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
                                            <option value="{{ $s->id }}" {{ (string) $filters['stain_id'] === (string) $s->id ? 'selected' : '' }}>
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
                                            <option value="{{ $d->id }}" {{ (string) $filters['data_source_id'] === (string) $d->id ? 'selected' : '' }}>
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
                                    <select name="tiling_status" class="form-control" id="tilingStatusSel">
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
                                    <label>Tile Size (px)</label>
                                    <select name="tile_size_px" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($tileSizes as $ts)
                                            <option value="{{ $ts }}" {{ (string) $filters['tile_size_px'] === (string) $ts ? 'selected' : '' }}>
                                                {{ $ts }}×{{ $ts }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Magnification</label>
                                    <select name="magnification" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($magnifications as $m)
                                            <option value="{{ $m }}" {{ $filters['magnification'] === $m ? 'selected' : '' }}>
                                                {{ $m }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

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

                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-group d-flex" style="gap:.5rem;">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="mdi mdi-magnify mr-1"></i> Apply Filters
                                    </button>
                                    <a href="{{ route('admin.workflow') }}" class="btn btn-outline-secondary">
                                        <i class="mdi mdi-refresh mr-1"></i> Reset
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- ─── Selection + Model ───────────────────────────────────────── --}}
    <form id="selectionForm" method="POST" action="#">
        @csrf

        <div class="row">
            <div class="col-12 grid-margin">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3" style="gap:1rem;">
                            <div>
                                <h4 class="card-title mb-1">
                                    Matching Samples
                                    <span class="badge badge-secondary ml-1">{{ $samples->total() }}</span>
                                </h4>
                                <small class="text-muted">
                                    <span id="selectedCount">0</span> selected on this page
                                </small>
                            </div>

                            <div class="d-flex align-items-center flex-wrap" style="gap:.75rem;">
                                <label class="mb-0 mr-2"><strong>AI Model:</strong></label>
                                <select name="ai_model_id" class="form-control" style="min-width:280px;" required>
                                    <option value="">— Select training model —</option>
                                    @foreach($aiModels as $m)
                                        <option value="{{ $m->id }}"
                                            {{ (string) $filters['ai_model_id'] === (string) $m->id || ($filters['ai_model_id'] === null && $m->is_default) ? 'selected' : '' }}>
                                            {{ $m->name }}
                                            @if($m->provider) — {{ $m->provider }} @endif
                                            @if($m->is_default) (default) @endif
                                        </option>
                                    @endforeach
                                </select>

                                <button type="button" class="btn btn-success" id="prepareBtn" disabled>
                                    <i class="mdi mdi-rocket-launch mr-1"></i>
                                    Prepare Training Set
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
                                            <td class="small" style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
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
            </div>
        </div>
    </form>

    @push('scripts')
    <script>
        (function () {
            var selectAll  = document.getElementById('selectAll');
            var checks     = document.querySelectorAll('.sample-check');
            var counterEl  = document.getElementById('selectedCount');
            var prepareBtn = document.getElementById('prepareBtn');

            function refresh() {
                var n = document.querySelectorAll('.sample-check:checked').length;
                counterEl.textContent = n;
                prepareBtn.disabled = (n === 0);
                if (selectAll) {
                    selectAll.checked       = (n > 0 && n === checks.length);
                    selectAll.indeterminate = (n > 0 && n < checks.length);
                }
            }

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    checks.forEach(function (c) { c.checked = selectAll.checked; });
                    refresh();
                });
            }
            checks.forEach(function (c) { c.addEventListener('change', refresh); });

            // Placeholder: backend dispatch endpoint is not yet wired.
            prepareBtn.addEventListener('click', function () {
                var n = document.querySelectorAll('.sample-check:checked').length;
                alert(n + ' sample(s) selected. The training-dispatch pipeline will be wired up next.');
            });

            refresh();
        })();
    </script>
    @endpush
@endsection
