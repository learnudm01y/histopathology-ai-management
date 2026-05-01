@extends('admin.layouts.app')
@section('title', 'AI Models')

@section('content')
<div class="page-header">
    <h3 class="page-title">AI Models</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item">Settings</li>
            <li class="breadcrumb-item active">AI Models</li>
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
                        Registered AI Models
                        <span class="badge badge-secondary ml-2">{{ $models->count() }}</span>
                    </h4>
                    <a href="{{ route('admin.settings.ai-models.create') }}" class="btn btn-primary btn-sm">
                        <i class="mdi mdi-plus mr-1"></i> Add Model
                    </a>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="width:5%">#</th>
                                <th>Name</th>
                                <th>Provider</th>
                                <th>Type</th>
                                <th>Level</th>
                                <th>Parameters</th>
                                <th>License</th>
                                <th>Links</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($models as $m)
                            <tr>
                                <td class="text-muted small">{{ $m->id }}</td>
                                <td>
                                    <strong>{{ $m->name }}</strong>
                                    @if($m->is_default)
                                        <span class="badge badge-success ml-1" title="Default training model">
                                            <i class="mdi mdi-star"></i> default
                                        </span>
                                    @endif
                                    @if($m->full_name)
                                        <div class="text-muted small">{{ $m->full_name }}</div>
                                    @endif
                                </td>
                                <td>{{ $m->provider ?? '—' }}</td>
                                <td>
                                    <span class="badge badge-{{ $m->getTypeBadgeClass() }}">
                                        {{ $m->getTypeLabel() }}
                                    </span>
                                </td>
                                <td class="small">{{ $m->getLevelLabel() }}</td>
                                <td class="small">{{ $m->parameters ?? '—' }}</td>
                                <td class="small">{{ $m->license ?? '—' }}</td>
                                <td class="small text-nowrap">
                                    @if($m->huggingface_url)
                                        <a href="{{ $m->huggingface_url }}" target="_blank" rel="noopener"
                                           title="Hugging Face">
                                            <i class="mdi mdi-open-in-new"></i> HF
                                        </a>
                                    @endif
                                    @if($m->paper_url)
                                        <a href="{{ $m->paper_url }}" target="_blank" rel="noopener"
                                           title="Paper" class="ml-2">
                                            <i class="mdi mdi-file-document-outline"></i>
                                        </a>
                                    @endif
                                    @if($m->repo_url)
                                        <a href="{{ $m->repo_url }}" target="_blank" rel="noopener"
                                           title="Repository" class="ml-2">
                                            <i class="mdi mdi-github-circle"></i>
                                        </a>
                                    @endif
                                </td>
                                <td>
                                    @if($m->is_active)
                                        <span class="badge badge-success">Active</span>
                                    @else
                                        <span class="badge badge-secondary">Inactive</span>
                                    @endif
                                </td>
                                <td class="text-right text-nowrap">
                                    <a href="{{ route('admin.settings.ai-models.edit', $m) }}"
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="mdi mdi-pencil"></i>
                                    </a>
                                    <form action="{{ route('admin.settings.ai-models.destroy', $m) }}"
                                          method="POST" class="d-inline"
                                          onsubmit="return confirm('Delete model \'{{ $m->name }}\'?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="mdi mdi-delete"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">
                                    <i class="mdi mdi-robot-outline" style="font-size:3rem;"></i>
                                    <p class="mt-2">
                                        No AI models registered.
                                        <a href="{{ route('admin.settings.ai-models.create') }}">Add the first one</a>.
                                    </p>
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
