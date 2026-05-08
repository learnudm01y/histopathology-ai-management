@extends('admin.layouts.app')
@section('title', 'Patch Sizes')

@section('content')
<div class="page-header">
    <h3 class="page-title">
        <i class="mdi mdi-grid mr-2"></i>Patch Sizes
    </h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item">Settings</li>
            <li class="breadcrumb-item active">Patch Sizes</li>
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
                        All Patch Sizes
                        <span class="badge badge-secondary ml-2">{{ $patchSizes->count() }}</span>
                    </h4>
                    <a href="{{ route('admin.settings.patch-sizes.create') }}" class="btn btn-primary btn-sm">
                        <i class="mdi mdi-plus mr-1"></i> Add Patch Size
                    </a>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Label</th>
                                <th class="text-center">Size (px)</th>
                                <th class="text-center">WSI Level</th>
                                <th class="text-center">Overlap (px)</th>
                                <th>AI Model</th>
                                <th>Notes</th>
                                <th class="text-center">Samples</th>
                                <th class="text-center">Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($patchSizes as $ps)
                                <tr>
                                    <td class="text-muted small">{{ $ps->id }}</td>
                                    <td><strong>{{ $ps->label }}</strong></td>
                                    <td class="text-center">
                                        <span class="badge badge-primary">{{ $ps->size_px }}×{{ $ps->size_px }}</span>
                                    </td>
                                    <td class="text-center small">{{ $ps->wsi_level }}</td>
                                    <td class="text-center small">
                                        @if($ps->overlap_px > 0)
                                            <span class="badge badge-info">{{ $ps->overlap_px }}px</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if($ps->aiModel)
                                            <span class="badge badge-light border">{{ $ps->aiModel->name }}</span>
                                        @else
                                            <span class="text-muted">Any</span>
                                        @endif
                                    </td>
                                    <td class="small text-muted"
                                        style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                        title="{{ $ps->notes }}">
                                        {{ $ps->notes ?? '—' }}
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-light border">{{ $ps->samples_count }}</span>
                                    </td>
                                    <td class="text-center">
                                        @if($ps->is_active)
                                            <span class="badge badge-success">Active</span>
                                        @else
                                            <span class="badge badge-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('admin.settings.patch-sizes.edit', $ps) }}"
                                           class="btn btn-outline-primary btn-sm">
                                            <i class="mdi mdi-pencil"></i>
                                        </a>
                                        <form method="POST"
                                              action="{{ route('admin.settings.patch-sizes.destroy', $ps) }}"
                                              class="d-inline-block"
                                              onsubmit="return confirm('Delete patch size \'{{ addslashes($ps->label) }}\'?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                <i class="mdi mdi-delete"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">
                                        No patch sizes configured yet.
                                        <a href="{{ route('admin.settings.patch-sizes.create') }}">Add one now.</a>
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
