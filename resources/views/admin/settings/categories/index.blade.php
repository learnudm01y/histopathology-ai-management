@extends('admin.layouts.app')
@section('title', 'Categories')

@push('styles')
<style>
/* ── Tree Layout ─────────────────────────────────────────────── */
.tree-node { margin-bottom: 5px; }

/* Category (parent) row */
.tree-category-header {
    display: flex;
    align-items: center;
    padding: 9px 14px;
    background: #ffffff;
    border: 1px solid #e4e9f2;
    border-radius: 6px;
    transition: background .15s;
}
.tree-category-header:hover { background: #f5f7ff; }

/* Toggle chevron button */
.tree-toggle-btn {
    background: none;
    border: none;
    padding: 0;
    margin-right: 8px;
    color: #6c757d;
    cursor: pointer;
    flex-shrink: 0;
    line-height: 1;
}
.tree-toggle-btn .mdi {
    font-size: 1.3rem;
    transition: transform .2s ease;
    display: block;
}
.tree-toggle-btn .mdi.rotated { transform: rotate(90deg); }

/* Folder icon */
.tree-folder-icon {
    font-size: 1.2rem;
    margin-right: 8px;
    color: #4a6cf7;
    flex-shrink: 0;
}

/* ── Subtypes container (connector lines) ───────────────────── */
.tree-subtypes-wrap {
    margin-left: 36px;
    padding-left: 16px;
    border-left: 2px dashed #c8d2e0;
    margin-top: 4px;
    padding-bottom: 2px;
}

/* Subtype leaf row */
.tree-subtype-row {
    display: flex;
    align-items: center;
    padding: 7px 12px;
    background: #f8fafc;
    border: 1px solid #e4e9f2;
    border-radius: 5px;
    margin-bottom: 3px;
    position: relative;
}
/* Horizontal connector dash */
.tree-subtype-row::before,
.tree-add-row::before {
    content: '';
    position: absolute;
    left: -16px;
    top: 50%;
    width: 16px;
    border-top: 1px dashed #c8d2e0;
    transform: translateY(-50%);
}
.tree-subtype-icon {
    font-size: 1rem;
    color: #a0aec0;
    margin-right: 8px;
    flex-shrink: 0;
}
.tree-subtype-name {
    flex: 1;
    font-size: .875rem;
    color: #4a5568;
}

/* Add subtype form row */
.tree-add-row {
    display: flex;
    align-items: center;
    padding: 5px 4px;
    margin-top: 2px;
    position: relative;
}
</style>
@endpush

@section('content')
<div class="page-header">
    <h3 class="page-title">Sample Categories</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item">Settings</li>
            <li class="breadcrumb-item active">Categories</li>
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

                {{-- Header --}}
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="card-title mb-0">
                        All Categories
                        <span class="badge badge-secondary ml-2">{{ $categories->count() }}</span>
                    </h4>
                    <a href="{{ route('admin.settings.categories.create') }}" class="btn btn-primary btn-sm">
                        <i class="mdi mdi-plus mr-1"></i> Add Category
                    </a>
                </div>

                {{-- Tree --}}
                @if($categories->isEmpty())
                    <div class="text-center text-muted py-5">
                        <i class="mdi mdi-tag-multiple" style="font-size:3rem;display:block;margin-bottom:.5rem;"></i>
                        No categories yet.
                        <a href="{{ route('admin.settings.categories.create') }}">Add one now</a>
                    </div>
                @else
                <div class="category-tree">
                    @foreach($categories as $cat)
                    <div class="tree-node" id="cat-node-{{ $cat->id }}">

                        {{-- ── Category Header ── --}}
                        <div class="tree-category-header">

                            {{-- Toggle chevron --}}
                            <button class="tree-toggle-btn"
                                    type="button"
                                    onclick="toggleSubtypes({{ $cat->id }}, this)">
                                <i class="mdi mdi-chevron-right"></i>
                            </button>

                            {{-- Folder icon --}}
                            <i class="mdi mdi-folder tree-folder-icon"></i>

                            {{-- Label + subtype count --}}
                            <span class="flex-grow-1 font-weight-medium" style="color:#2d3748;">
                                {{ $cat->label_en }}
                                <small class="text-muted font-weight-normal ml-1">({{ $cat->disease_subtypes_count }})</small>
                            </span>

                            {{-- Samples badge --}}
                            <span class="badge badge-light border mr-2" title="Samples attached">
                                <i class="mdi mdi-image-multiple" style="font-size:.7rem;vertical-align:middle;"></i>
                                {{ $cat->samples_count }}
                            </span>

                            {{-- Status --}}
                            @if($cat->is_active)
                                <span class="badge badge-success mr-3">Active</span>
                            @else
                                <span class="badge badge-secondary mr-3">Inactive</span>
                            @endif

                            {{-- Edit --}}
                            <a href="{{ route('admin.settings.categories.edit', $cat) }}"
                               class="btn btn-outline-primary btn-sm mr-1">
                                <i class="mdi mdi-pencil"></i>
                            </a>

                            {{-- Delete --}}
                            <form action="{{ route('admin.settings.categories.destroy', $cat) }}"
                                  method="POST" class="d-inline"
                                  onsubmit="return confirm('Delete category \'{{ addslashes($cat->label_en) }}\'?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger btn-sm"
                                        {{ $cat->samples_count > 0 ? 'disabled title="Has samples attached"' : '' }}>
                                    <i class="mdi mdi-delete"></i>
                                </button>
                            </form>
                        </div>{{-- /.tree-category-header --}}

                        {{-- ── Subtypes Collapse Panel ── --}}
                        <div class="collapse" id="subtypes-{{ $cat->id }}">
                            <div class="tree-subtypes-wrap">

                                @foreach($cat->diseaseSubtypes as $subtype)
                                <div class="tree-subtype-row">
                                    <i class="mdi mdi-chevron-right tree-subtype-icon"></i>
                                    <span class="tree-subtype-name">{{ $subtype->name }}</span>

                                    @if($subtype->is_active)
                                        <span class="badge badge-success mr-2" style="font-size:.7rem;">Active</span>
                                    @else
                                        <span class="badge badge-secondary mr-2" style="font-size:.7rem;">Inactive</span>
                                    @endif

                                    <a href="{{ route('admin.settings.subtypes.edit', [$cat, $subtype]) }}"
                                       class="btn btn-outline-primary btn-sm mr-1" style="padding:.2rem .5rem;">
                                        <i class="mdi mdi-pencil" style="font-size:.85rem;"></i>
                                    </a>

                                    <form action="{{ route('admin.settings.subtypes.destroy', [$cat, $subtype]) }}"
                                          method="POST" class="d-inline"
                                          onsubmit="return confirm('Delete subtype \'{{ addslashes($subtype->name) }}\'?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                                style="padding:.2rem .5rem;">
                                            <i class="mdi mdi-delete" style="font-size:.85rem;"></i>
                                        </button>
                                    </form>
                                </div>
                                @endforeach

                                {{-- ── Add Subtype inline form ── --}}
                                <div class="tree-add-row">
                                    <form action="{{ route('admin.settings.subtypes.store', $cat) }}"
                                          method="POST"
                                          class="d-flex align-items-center w-100">
                                        @csrf
                                        <input type="hidden" name="category_id" value="{{ $cat->id }}">
                                        <i class="mdi mdi-plus-circle-outline mr-2 text-muted"></i>
                                        <input type="text" name="name"
                                               class="form-control form-control-sm mr-2 {{ $errors->any() && old('category_id') == $cat->id ? 'is-invalid' : '' }}"
                                               placeholder="New disease subtype name…"
                                               style="max-width:300px;"
                                               value="{{ old('category_id') == $cat->id ? old('name') : '' }}">
                                        @if($errors->any() && old('category_id') == $cat->id)
                                            <span class="text-danger small mr-2">{{ $errors->first('name') }}</span>
                                        @endif
                                        <button type="submit" class="btn btn-outline-primary btn-sm">
                                            <i class="mdi mdi-plus mr-1"></i>Add
                                        </button>
                                    </form>
                                </div>

                            </div>{{-- /.tree-subtypes-wrap --}}
                        </div>{{-- /.collapse --}}

                    </div>{{-- /.tree-node --}}
                    @endforeach
                </div>{{-- /.category-tree --}}
                @endif

            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function toggleSubtypes(id, btn) {
    var panel = document.getElementById('subtypes-' + id);
    var icon  = btn.querySelector('.mdi');
    if (panel.classList.contains('show')) {
        panel.classList.remove('show');
        icon.classList.remove('rotated');
    } else {
        panel.classList.add('show');
        icon.classList.add('rotated');
    }
}

$(function () {
    // Auto-expand after add / edit / delete subtype
    @if(session('open_category'))
    var openId = {{ (int) session('open_category') }};
    var panel = document.getElementById('subtypes-' + openId);
    if (panel) {
        panel.classList.add('show');
        var btn = document.querySelector('[onclick="toggleSubtypes(' + openId + ', this)"] .mdi');
        if (btn) btn.classList.add('rotated');
    }
    @endif

    // Auto-expand when there are validation errors (inline add)
    @if($errors->any() && old('category_id'))
    var errId = {{ (int) old('category_id') }};
    var errPanel = document.getElementById('subtypes-' + errId);
    if (errPanel) {
        errPanel.classList.add('show');
        var errBtn = document.querySelector('[onclick="toggleSubtypes(' + errId + ', this)"] .mdi');
        if (errBtn) errBtn.classList.add('rotated');
    }
    @endif
});
</script>
@endpush
