@extends('admin.layouts.app')
@section('title', 'Data Sources')

@section('content')
<div class="page-header">
    <h3 class="page-title">Data Sources</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item">Settings</li>
            <li class="breadcrumb-item active">Data Sources</li>
        </ol>
    </nav>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="mdi mdi-check-circle mr-1"></i> {{ session('success') }}
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="mdi mdi-alert-circle mr-1"></i> {{ session('error') }}
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
@endif

<div class="row">
    <div class="col-12 grid-margin">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="card-title mb-0">
                        All Data Sources
                        <span class="badge badge-secondary ml-2">{{ $dataSources->count() }}</span>
                    </h4>
                    <a href="{{ route('admin.settings.data-sources.create') }}" class="btn btn-primary btn-sm">
                        <i class="mdi mdi-plus mr-1"></i> Add Data Source
                    </a>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="width:4%">#</th>
                                <th style="width:15%">Name</th>
                                <th style="width:25%">Full Name</th>
                                <th style="width:20%">Base URL</th>
                                <th style="width:10%">Total Slides</th>
                                <th style="width:8%">Samples</th>
                                <th style="width:8%">Status</th>
                                <th style="width:10%" class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($dataSources as $ds)
                            <tr>
                                <td class="text-muted small">{{ $ds->id }}</td>
                                <td class="font-weight-bold">{{ $ds->name }}</td>
                                <td class="small text-muted">{{ $ds->full_name ?? '—' }}</td>
                                <td class="small">
                                    @if($ds->base_url)
                                        <a href="{{ $ds->base_url }}" target="_blank" class="text-truncate d-inline-block" style="max-width:180px" title="{{ $ds->base_url }}">
                                            {{ $ds->base_url }}
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="small text-center">{{ $ds->total_slides_available ?? '—' }}</td>
                                <td>
                                    <span class="badge badge-light border">{{ $ds->samples_count }}</span>
                                </td>
                                <td>
                                    @if($ds->is_active)
                                        <span class="badge badge-success">Active</span>
                                    @else
                                        <span class="badge badge-secondary">Inactive</span>
                                    @endif
                                </td>
                                <td class="text-right text-nowrap">
                                    <a href="{{ route('admin.settings.data-sources.edit', $ds) }}"
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="mdi mdi-pencil"></i>
                                    </a>
                                    <form action="{{ route('admin.settings.data-sources.destroy', $ds) }}"
                                          method="POST" class="d-inline"
                                          onsubmit="return confirm('Delete data source \'{{ $ds->name }}\'?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                                {{ $ds->samples_count > 0 ? 'disabled title=Has samples attached' : '' }}>
                                            <i class="mdi mdi-delete"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                    <i class="mdi mdi-database-off" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>
                                    No data sources yet.
                                    <a href="{{ route('admin.settings.data-sources.create') }}">Add one now</a>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
