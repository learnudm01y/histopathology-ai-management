@extends('admin.layouts.app')
@section('title', 'Stains')

@section('content')
<div class="page-header">
    <h3 class="page-title">Stains</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item">Settings</li>
            <li class="breadcrumb-item active">Stains</li>
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
                        All Stains
                        <span class="badge badge-secondary ml-2">
                            {{ $stains->flatten()->count() }}
                        </span>
                    </h4>
                    <a href="{{ route('admin.settings.stains.create') }}" class="btn btn-primary btn-sm">
                        <i class="mdi mdi-plus mr-1"></i> Add Stain
                    </a>
                </div>

                @php
                    $typeMeta = [
                        'routine'    => ['label' => 'Routine',                       'badge' => 'primary'],
                        'special'    => ['label' => 'Special Stains',                'badge' => 'info'],
                        'IHC'        => ['label' => 'Immunohistochemistry (IHC)',     'badge' => 'warning'],
                        'ISH'        => ['label' => 'In-Situ Hybridisation (ISH)',    'badge' => 'secondary'],
                        'fluorescent'=> ['label' => 'Fluorescent',                   'badge' => 'danger'],
                        'cytology'   => ['label' => 'Cytology',                      'badge' => 'dark'],
                        'other'      => ['label' => 'Other',                         'badge' => 'light'],
                    ];
                @endphp

                @forelse($stains as $type => $group)
                @php $meta = $typeMeta[$type] ?? ['label' => ucfirst($type), 'badge' => 'secondary']; @endphp

                <div class="mb-4">
                    {{-- Group header --}}
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge badge-{{ $meta['badge'] }} mr-2" style="font-size:.8rem;padding:4px 10px;">
                            {{ $meta['label'] }}
                        </span>
                        <small class="text-muted">{{ $group->count() }} stain(s)</small>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width:5%">#</th>
                                    <th style="width:15%">Abbreviation</th>
                                    <th style="width:30%">Name</th>
                                    @if($type === 'IHC' || $type === 'ISH')
                                    <th style="width:12%">Marker</th>
                                    @endif
                                    <th>Description</th>
                                    <th style="width:10%">Samples</th>
                                    <th style="width:8%">Status</th>
                                    <th style="width:10%" class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($group as $stain)
                                <tr>
                                    <td class="text-muted small">{{ $stain->id }}</td>
                                    <td>
                                        <span class="badge badge-{{ $meta['badge'] }}" style="font-size:.85rem;padding:4px 8px;">
                                            {{ $stain->abbreviation }}
                                        </span>
                                    </td>
                                    <td class="font-weight-medium">{{ $stain->name }}</td>
                                    @if($type === 'IHC' || $type === 'ISH')
                                    <td>
                                        @if($stain->marker)
                                            <code class="small">{{ $stain->marker }}</code>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    @endif
                                    <td class="text-muted small">
                                        {{ $stain->description ? Str::limit($stain->description, 80) : '—' }}
                                    </td>
                                    <td>
                                        <span class="badge badge-light border">{{ $stain->samples_count }}</span>
                                    </td>
                                    <td>
                                        @if($stain->is_active)
                                            <span class="badge badge-success">Active</span>
                                        @else
                                            <span class="badge badge-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="text-right text-nowrap">
                                        <a href="{{ route('admin.settings.stains.edit', $stain) }}"
                                           class="btn btn-outline-primary btn-sm">
                                            <i class="mdi mdi-pencil"></i>
                                        </a>
                                        <form action="{{ route('admin.settings.stains.destroy', $stain) }}"
                                              method="POST" class="d-inline"
                                              onsubmit="return confirm('Delete stain \'{{ $stain->abbreviation }}\'?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm"
                                                    {{ $stain->samples_count > 0 ? 'disabled title=Has samples attached' : '' }}>
                                                <i class="mdi mdi-delete"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @empty
                    <div class="text-center py-5 text-muted">
                        <i class="mdi mdi-flask-outline" style="font-size:3rem;"></i>
                        <p class="mt-2">No stains defined yet.
                            <a href="{{ route('admin.settings.stains.create') }}">Add the first stain</a>.
                        </p>
                    </div>
                @endforelse

            </div>
        </div>
    </div>
</div>
@endsection
