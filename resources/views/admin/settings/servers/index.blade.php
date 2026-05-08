@extends('admin.layouts.app')
@section('title', 'Servers')

@section('content')
<div class="page-header">
    <h3 class="page-title">
        <i class="mdi mdi-server mr-2"></i>Servers
    </h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item">Settings</li>
            <li class="breadcrumb-item active">Servers</li>
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
                        All Servers
                        <span class="badge badge-secondary ml-2">{{ $servers->count() }}</span>
                    </h4>
                    <a href="{{ route('admin.settings.servers.create') }}" class="btn btn-primary btn-sm">
                        <i class="mdi mdi-plus mr-1"></i> Add Server
                    </a>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Host / IP</th>
                                <th>API URL</th>
                                <th>Description</th>
                                <th class="text-center">Samples</th>
                                <th class="text-center">Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($servers as $srv)
                                <tr>
                                    <td class="text-muted small">{{ $srv->id }}</td>
                                    <td><strong>{{ $srv->name }}</strong></td>
                                    <td>
                                        <span class="badge badge-{{ $srv->getTypeBadgeClass() }}">
                                            {{ $srv->getTypeLabel() }}
                                        </span>
                                    </td>
                                    <td class="small text-muted">{{ $srv->host ?? '—' }}</td>
                                    <td class="small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                        @if($srv->api_url)
                                            <a href="{{ $srv->api_url }}" target="_blank" rel="noopener noreferrer">
                                                {{ $srv->api_url }}
                                            </a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="small text-muted"
                                        style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                        title="{{ $srv->description }}">
                                        {{ $srv->description ?? '—' }}
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-light border">{{ $srv->samples_count }}</span>
                                    </td>
                                    <td class="text-center">
                                        @if($srv->is_active)
                                            <span class="badge badge-success">Active</span>
                                        @else
                                            <span class="badge badge-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('admin.settings.servers.edit', $srv) }}"
                                           class="btn btn-outline-primary btn-sm">
                                            <i class="mdi mdi-pencil"></i>
                                        </a>
                                        <form method="POST"
                                              action="{{ route('admin.settings.servers.destroy', $srv) }}"
                                              class="d-inline-block"
                                              onsubmit="return confirm('Delete server \'{{ addslashes($srv->name) }}\'?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                <i class="mdi mdi-delete"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">
                                        No servers configured yet.
                                        <a href="{{ route('admin.settings.servers.create') }}">Add one now.</a>
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
