@extends('admin.layouts.app')
@section('title', 'Organs')

@section('content')
<div class="page-header">
    <h3 class="page-title">Organs</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item">Settings</li>
            <li class="breadcrumb-item active">Organs</li>
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
                        All Organs
                        <span class="badge badge-secondary ml-2">{{ $organs->count() }}</span>
                    </h4>
                    <a href="{{ route('admin.settings.organs.create') }}" class="btn btn-primary btn-sm">
                        <i class="mdi mdi-plus mr-1"></i> Add Organ
                    </a>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="width:5%">#</th>
                                <th style="width:50%">Name</th>
                                <th style="width:15%">Samples</th>
                                <th style="width:15%">Status</th>
                                <th style="width:15%" class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($organs as $organ)
                            <tr>
                                <td class="text-muted small">{{ $organ->id }}</td>
                                <td class="font-weight-medium">{{ $organ->name }}</td>
                                <td>
                                    <span class="badge badge-light border">{{ $organ->samples_count }}</span>
                                </td>
                                <td>
                                    @if($organ->is_active)
                                        <span class="badge badge-success">Active</span>
                                    @else
                                        <span class="badge badge-secondary">Inactive</span>
                                    @endif
                                </td>
                                <td class="text-right text-nowrap">
                                    <a href="{{ route('admin.settings.organs.edit', $organ) }}"
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="mdi mdi-pencil"></i>
                                    </a>
                                    <form action="{{ route('admin.settings.organs.destroy', $organ) }}"
                                          method="POST" class="d-inline"
                                          onsubmit="return confirm('Delete organ \'{{ $organ->name }}\'?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                                {{ $organ->samples_count > 0 ? 'disabled title=Has samples attached' : '' }}>
                                            <i class="mdi mdi-delete"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No organs found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
