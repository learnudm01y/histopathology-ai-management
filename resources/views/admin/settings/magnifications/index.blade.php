@extends('admin.layouts.app')
@section('title', 'Magnifications')

@section('content')
<div class="page-header">
    <h3 class="page-title">
        <i class="mdi mdi-magnify-plus-outline mr-2"></i>Magnifications
    </h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item">Settings</li>
            <li class="breadcrumb-item active">Magnifications</li>
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
                        All Magnifications
                        <span class="badge badge-secondary ml-2">{{ $magnifications->count() }}</span>
                    </h4>
                    <a href="{{ route('admin.settings.magnifications.create') }}" class="btn btn-primary btn-sm">
                        <i class="mdi mdi-plus mr-1"></i> Add Magnification
                    </a>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Label</th>
                                <th class="text-center">Value</th>
                                <th>Folder Name</th>
                                <th>Notes</th>
                                <th class="text-center">Samples</th>
                                <th class="text-center">Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($magnifications as $mag)
                                <tr>
                                    <td class="text-muted small">{{ $mag->id }}</td>
                                    <td>
                                        <span class="badge badge-primary" style="font-size:.85rem;">{{ $mag->label }}</span>
                                    </td>
                                    <td class="text-center">{{ $mag->value }}×</td>
                                    <td><code>{{ $mag->folder_name }}</code></td>
                                    <td class="small text-muted">{{ $mag->notes ?? '—' }}</td>
                                    <td class="text-center">
                                        <span class="badge badge-light border">{{ $mag->samples_count }}</span>
                                    </td>
                                    <td class="text-center">
                                        @if($mag->is_active)
                                            <span class="badge badge-success">Active</span>
                                        @else
                                            <span class="badge badge-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('admin.settings.magnifications.edit', $mag) }}"
                                           class="btn btn-outline-primary btn-sm">
                                            <i class="mdi mdi-pencil"></i>
                                        </a>
                                        <form method="POST"
                                              action="{{ route('admin.settings.magnifications.destroy', $mag) }}"
                                              class="d-inline-block"
                                              onsubmit="return confirm('Delete magnification \'{{ addslashes($mag->label) }}\'?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                <i class="mdi mdi-delete"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        No magnification levels defined yet.
                                        <a href="{{ route('admin.settings.magnifications.create') }}">Add one now.</a>
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
