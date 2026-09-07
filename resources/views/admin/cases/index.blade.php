@extends('admin.layouts.app')

@section('title', 'Cases')

@push('styles')
<style>
/* ── Case breakdown tree: Organ → Clinical Group → Disease ──────── */
.organ-band {
    display: flex; align-items: center; flex-wrap: wrap;
    padding: 9px 14px; margin: 14px 0 0;
    background: linear-gradient(90deg, #eef2ff 0%, #f8fafc 100%);
    border: 1px solid #d7deeb; border-bottom: none; border-radius: 6px 6px 0 0;
    font-weight: 600; color: #2d3748;
}
.organ-band-name { font-size: .95rem; }
.organ-band-body {
    border: 1px solid #d7deeb; border-top: none; border-radius: 0 0 6px 6px;
    padding: 10px 12px 4px; margin-bottom: 6px;
}
.tree-node { margin-bottom: 5px; }
.tree-category-header {
    display: flex; align-items: center; flex-wrap: wrap;
    padding: 8px 14px; background: #fff;
    border: 1px solid #e4e9f2; border-radius: 6px; transition: background .15s;
}
.tree-category-header:hover { background: #f5f7ff; }
.tree-toggle-btn {
    background: none; border: none; padding: 0; margin-right: 8px;
    color: #6c757d; cursor: pointer; flex-shrink: 0; line-height: 1;
}
.tree-toggle-btn .mdi { font-size: 1.3rem; transition: transform .2s ease; display: block; }
.tree-toggle-btn .mdi.rotated { transform: rotate(90deg); }
.tree-folder-icon { font-size: 1.2rem; margin-right: 8px; color: #4a6cf7; flex-shrink: 0; }
.tree-subtypes-wrap {
    margin-left: 36px; padding-left: 16px;
    border-left: 2px dashed #c8d2e0; margin-top: 4px; padding-bottom: 2px;
}
.tree-subtypes-wrap-nested { margin-left: 18px; border-left-color: #dbe3ee; }
.tree-subtype-row {
    display: flex; align-items: center; flex-wrap: wrap;
    padding: 6px 12px; background: #f8fafc;
    border: 1px solid #e4e9f2; border-radius: 5px; margin-bottom: 3px; position: relative;
}
.tree-subtype-row-nested { background: #fdfefe; }
.tree-subtype-row::before {
    content: ''; position: absolute; left: -16px; top: 50%; width: 16px;
    border-top: 1px dashed #c8d2e0; transform: translateY(-50%);
}
.tree-subtype-icon { font-size: 1rem; color: #a0aec0; margin-right: 8px; flex-shrink: 0; }
.tree-subtype-name { flex: 1; font-size: .875rem; color: #4a5568; }
.tree-count-badge { font-size: .72rem; font-weight: 600; padding: .3rem .45rem; text-decoration: none; }
a.tree-count-badge:hover { filter: brightness(.92); text-decoration: none; }
.tree-count-badge .mdi { font-size: .78rem; vertical-align: -1px; }
.tree-empty-hint { font-size: .78rem; color: #a0aec0; padding: 4px 2px 6px; }
/* The active drill-down node, so the tree says where the table came from */
.tree-node-active > .tree-category-header,
.tree-subtype-row.tree-node-active { border-color: #4a6cf7; box-shadow: 0 0 0 2px rgba(74,108,247,.12); }
</style>
@endpush

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

                        {{-- ── Taxonomy drill-down ─────────────────────────────
                             Organ → Clinical Group → Disease. A case carries no
                             diagnosis of its own, so these match through its
                             slides — and all three must be met by the SAME
                             slide, which is what makes "Breast › Tumor › IDC"
                             mean a breast IDC case. --}}
                        <div class="w-100 border-top mt-1 pt-3"></div>

                        <span class="text-muted small font-weight-medium">
                            <i class="mdi mdi-file-tree mr-1"></i>Disease:
                        </span>

                        <select name="organ_id" id="f-organ" class="form-control" style="width:auto;min-width:150px;">
                            <option value="">All organs</option>
                            @foreach($organs as $organ)
                                <option value="{{ $organ->id }}" @selected(request('organ_id') == $organ->id)>
                                    {{ $organ->name }}
                                </option>
                            @endforeach
                        </select>

                        <i class="mdi mdi-chevron-right text-muted"></i>

                        <select name="category_id" id="f-category" class="form-control" style="width:auto;min-width:170px;">
                            <option value="">All clinical groups</option>
                            @foreach($categoryOptions as $cat)
                                <option value="{{ $cat->id }}" data-organ="{{ $cat->organ_id }}"
                                        @selected(request('category_id') == $cat->id)>{{ $cat->label_en }}</option>
                            @endforeach
                        </select>

                        <i class="mdi mdi-chevron-right text-muted"></i>

                        <select name="disease_subtype_id" id="f-disease" class="form-control" style="width:auto;min-width:210px;">
                            <option value="">All diseases</option>
                            <option value="none" @selected(request('disease_subtype_id') === 'none')>
                                — Slides with no disease —
                            </option>
                            @foreach($diseaseOptions as $opt)
                                <option value="{{ $opt['id'] }}"
                                        data-organ="{{ $opt['organ_id'] }}"
                                        data-category="{{ $opt['category_id'] }}"
                                        @selected(request('disease_subtype_id') == $opt['id'])>{{ $opt['label'] }}</option>
                            @endforeach
                        </select>

                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="f-subtree"
                                   name="subtree" value="1" @checked(request('subtree'))>
                            <label class="custom-control-label" for="f-subtree"
                                   title="A case labelled only “Infiltrating ductal carcinoma” is still a “Malignant” case — count it under the coarser disease too.">
                                Include finer diseases
                            </label>
                        </div>

                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- ── Cases by disease ──────────────────────────────────────────────────── --}}
@php
    // Drilling into the tree keeps whatever else is already filtered — the
    // counts below were computed under exactly these filters, so the list a
    // badge opens has to inherit them or it would not match the number clicked.
    $carry = request()->except(['page', 'organ_id', 'category_id', 'disease_subtype_id', 'subtree']);

    $activeCategoryId = request('category_id');
    $activeDiseaseId  = request('disease_subtype_id');

    // Which group holds the disease currently drilled into — that panel opens
    // on load so the selection is visible without hunting for it.
    $activeDiseaseCategoryId = collect($diseaseOptions)
        ->firstWhere('id', (int) $activeDiseaseId)['category_id'] ?? null;

    // An organ with no cases is noise in a breakdown; it is kept only while the
    // user has explicitly narrowed to it and needs to see it is empty.
    $visible = $selectedOrganId !== null
        ? $bands
        : $bands->filter(fn ($categories, $organId) => $counts->organ((int) $organId) > 0);

    $hiddenOrgans = $bands->count() - $visible->count();
    $visible      = $visible->sortByDesc(fn ($categories, $organId) => $counts->organ((int) $organId));
@endphp
<div class="row grid-margin">
    <div class="col-12">
        <div class="card">
            <div class="card-body">

                <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                    <h4 class="card-title mb-0">
                        Cases by Disease
                        <span class="badge badge-info ml-2"
                              title="Cases the taxonomy can name at least one disease for, under the filters above">
                            {{ number_format($counts->classifiedTotal()) }} classified
                        </span>
                    </h4>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="cases-tree-expand">
                        <i class="mdi mdi-unfold-more-horizontal mr-1"></i> Expand all
                    </button>
                </div>

                <div class="alert alert-light border py-2 px-3 mb-3" style="font-size:.82rem;">
                    <i class="mdi mdi-information-outline mr-1 text-info"></i>
                    <strong>Organ → Clinical Group → Disease.</strong>
                    A case has no diagnosis of its own — it inherits whatever its slides were filed
                    under — so it is counted here once per disease its slides carry. Every badge is
                    <strong>distinct cases</strong>, never slides, and a coarse disease answers for its
                    whole branch. That is why an organ's total can be smaller than its groups added up:
                    one case with slides in two groups is still one case. Click any badge to list it.
                </div>

                @forelse($visible as $organId => $organCategories)
                    @php
                        $organ      = $organCategories->first()->organ;
                        $organCases = $counts->organ((int) $organId);
                    @endphp

                    <div class="organ-band">
                        <i class="mdi mdi-hospital-building mr-2"></i>
                        <span class="organ-band-name">{{ $organ?->name ?? 'Unknown organ' }}</span>
                        <a href="{{ route('admin.cases.index', $carry + ['organ_id' => $organId]) }}"
                           class="badge tree-count-badge ml-2 {{ $organCases > 0 ? 'badge-primary' : 'badge-light border text-muted' }}"
                           title="{{ $organCases }} case(s) have at least one slide of this organ. Click to list them.">
                            <i class="mdi mdi-account-multiple-outline"></i> {{ number_format($organCases) }}
                        </a>
                        <span class="badge badge-light border ml-1">{{ $organCategories->count() }} group(s)</span>
                    </div>

                    <div class="organ-band-body">
                        @foreach($organCategories as $cat)
                            @php
                                $categoryCases = $counts->category($cat->id);
                                $unfiledCases  = $counts->unfiled($cat->id);
                                $isActive      = $activeCategoryId == $cat->id;
                                $isOpen        = $isActive
                                                 || $selectedOrganId !== null
                                                 || $cat->id === $activeDiseaseCategoryId;
                            @endphp
                            <div class="tree-node {{ $isActive ? 'tree-node-active' : '' }}">

                                <div class="tree-category-header">
                                    <button type="button" class="tree-toggle-btn"
                                            data-cases-group="{{ $cat->id }}">
                                        <i class="mdi mdi-chevron-right {{ $isOpen ? 'rotated' : '' }}"></i>
                                    </button>

                                    <i class="mdi mdi-folder tree-folder-icon"></i>

                                    <span class="flex-grow-1 font-weight-medium" style="color:#2d3748;">
                                        {{ $cat->label_en }}
                                        <small class="text-muted font-weight-normal ml-1"
                                               title="Top-level diseases in this group">
                                            ({{ $cat->rootDiseaseSubtypes->count() }})
                                        </small>
                                    </span>

                                    <a href="{{ route('admin.cases.index', $carry + ['organ_id' => $cat->organ_id, 'category_id' => $cat->id]) }}"
                                       class="badge tree-count-badge mr-1 {{ $categoryCases > 0 ? 'badge-primary' : 'badge-light border text-muted' }}"
                                       title="{{ $categoryCases }} case(s) have a slide filed under this clinical group. Click to list them.">
                                        <i class="mdi mdi-account-multiple-outline"></i> {{ number_format($categoryCases) }}
                                    </a>

                                    {{-- Cases whose slides carry the group but no disease: they
                                         belong to no class below and are the gap between this
                                         total and the diseases in the panel. --}}
                                    @if($unfiledCases > 0)
                                        <a href="{{ route('admin.cases.index', $carry + ['organ_id' => $cat->organ_id, 'category_id' => $cat->id, 'disease_subtype_id' => 'none']) }}"
                                           class="badge badge-warning tree-count-badge mr-1"
                                           title="{{ $unfiledCases }} case(s) have a slide in this group that was never given a disease. Click to list and label them.">
                                            {{ number_format($unfiledCases) }} unfiled
                                        </a>
                                    @endif
                                </div>

                                <div class="collapse {{ $isOpen ? 'show' : '' }}" id="cases-group-{{ $cat->id }}">
                                    <div class="tree-subtypes-wrap">
                                        @forelse($cat->rootDiseaseSubtypes as $subtype)
                                            @include('admin.cases._disease-node', [
                                                'subtype' => $subtype, 'depth' => 1,
                                                'counts'  => $counts, 'carry' => $carry,
                                            ])
                                        @empty
                                            <div class="tree-empty-hint">
                                                No diseases defined in this group yet — add them in
                                                <a href="{{ route('admin.settings.categories.index', ['organ_id' => $cat->organ_id]) }}">Taxonomy</a>.
                                            </div>
                                        @endforelse
                                    </div>
                                </div>

                            </div>
                        @endforeach
                    </div>
                @empty
                    <div class="text-center text-muted py-4">
                        <i class="mdi mdi-file-tree" style="font-size:2.5rem;display:block;margin-bottom:.5rem;"></i>
                        No case matches a disease under the current filters. A case is placed in the
                        taxonomy through its slides, so cases with no slides — or slides with no
                        disease — appear nowhere here.
                    </div>
                @endforelse

                @if($hiddenOrgans > 0)
                    <div class="text-muted small mt-3">
                        <i class="mdi mdi-eye-off-outline mr-1"></i>
                        {{ $hiddenOrgans }} organ(s) with no matching cases are hidden — pick one above to see it.
                    </div>
                @endif

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
                    <div class="d-flex align-items-center" style="gap:.5rem;">
                        {{-- Export uses the filters currently applied to the list --}}
                        @php $exportParams = request()->except('page'); @endphp
                        <div class="btn-group">
                            <a href="{{ route('admin.cases.export', array_merge($exportParams, ['scope' => 'full'])) }}"
                               class="btn btn-success btn-sm">
                                <i class="mdi mdi-file-excel mr-1"></i> Export Excel
                                <span class="badge badge-light ml-1">{{ number_format($cases->total()) }}</span>
                            </a>
                            <button type="button" class="btn btn-success btn-sm dropdown-toggle dropdown-toggle-split"
                                    data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="sr-only">Export options</span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-right">
                                <h6 class="dropdown-header">Export {{ number_format($cases->total()) }} filtered case(s)</h6>
                                <a class="dropdown-item"
                                   href="{{ route('admin.cases.export', array_merge($exportParams, ['scope' => 'full'])) }}">
                                    <i class="mdi mdi-file-excel-outline mr-1 text-success"></i>
                                    Full data <small class="text-muted d-block ml-4">Case + complete clinical record</small>
                                </a>
                                <a class="dropdown-item"
                                   href="{{ route('admin.cases.export', array_merge($exportParams, ['scope' => 'table'])) }}">
                                    <i class="mdi mdi-table mr-1 text-primary"></i>
                                    Table columns <small class="text-muted d-block ml-4">Only what is shown below</small>
                                </a>
                            </div>
                        </div>
                        <button type="button" class="btn btn-danger btn-sm" data-toggle="modal" data-target="#bulkDeleteCasesModal">
                            <i class="mdi mdi-delete-sweep mr-1"></i> Bulk Delete
                        </button>
                    </div>
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
                                <th>Disease (taxonomy)</th>
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
                                {{-- What the taxonomy says this case is, read off its
                                     slides. "Disease Type" beside it is the free-text
                                     label the source file shipped with. --}}
                                <td>
                                    @forelse($c->disease_labels as $label)
                                        <span class="badge badge-outline-info mr-1">{{ $label }}</span>
                                    @empty
                                        <span class="text-muted small">unclassified</span>
                                    @endforelse
                                </td>
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
                                <td colspan="9" class="text-center text-muted py-4">
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
// ── Cases-by-disease tree ────────────────────────────────────────────────────
(function () {
    document.querySelectorAll('[data-cases-group]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var panel = document.getElementById('cases-group-' + btn.dataset.casesGroup);
            if (!panel) return;
            var open = panel.classList.toggle('show');
            btn.querySelector('.mdi').classList.toggle('rotated', open);
        });
    });

    var expandBtn = document.getElementById('cases-tree-expand');
    if (expandBtn) {
        expandBtn.addEventListener('click', function () {
            var panels = document.querySelectorAll('[id^="cases-group-"]');
            // One button for both directions: collapse only once everything is open.
            var opening = Array.prototype.some.call(panels, function (p) {
                return !p.classList.contains('show');
            });
            panels.forEach(function (p) { p.classList.toggle('show', opening); });
            document.querySelectorAll('[data-cases-group] .mdi').forEach(function (i) {
                i.classList.toggle('rotated', opening);
            });
            expandBtn.innerHTML = opening
                ? '<i class="mdi mdi-unfold-less-horizontal mr-1"></i> Collapse all'
                : '<i class="mdi mdi-unfold-more-horizontal mr-1"></i> Expand all';
        });
    }
}());

// ── Organ → Clinical Group → Disease pickers ─────────────────────────────────
// The three selects are one drill-down, so each narrows the next in the browser
// rather than costing a round trip. A choice that no longer fits the level above
// is cleared instead of silently filtering to nothing.
(function () {
    var organSel    = document.getElementById('f-organ');
    var categorySel = document.getElementById('f-category');
    var diseaseSel  = document.getElementById('f-disease');
    if (!organSel || !categorySel || !diseaseSel) return;

    function narrow(select, matches) {
        var cleared = false;
        Array.prototype.forEach.call(select.options, function (opt) {
            if (!opt.dataset.organ && !opt.dataset.category) return;   // "all" / "none"
            var keep = matches(opt);
            opt.hidden = !keep;
            opt.disabled = !keep;
            if (!keep && opt.selected) {
                select.value = '';
                cleared = true;
            }
        });
        return cleared;
    }

    function sync() {
        var organ = organSel.value;
        var group = categorySel.value;

        if (narrow(categorySel, function (o) { return !organ || o.dataset.organ === organ; })) {
            group = '';
        }

        narrow(diseaseSel, function (o) {
            return (!organ || o.dataset.organ === organ)
                && (!group || o.dataset.category === group);
        });
    }

    organSel.addEventListener('change', sync);
    categorySel.addEventListener('change', sync);
    sync();
}());

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
