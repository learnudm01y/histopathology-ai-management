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

                        {{-- Project --}}
                        <select name="project_id" class="form-control" style="width:auto;min-width:140px;">
                            <option value="">All Projects</option>
                            @foreach($projects as $p)
                                <option value="{{ $p }}" @selected(request('project_id') == $p)>{{ $p }}</option>
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
                <h4 class="card-title">Cases ({{ number_format($cases->total()) }})</h4>

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
