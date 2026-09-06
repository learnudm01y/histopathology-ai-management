@extends('admin.layouts.app')
@section('title', 'Categories')

@push('styles')
<style>
/* ── Organ band (root level of the taxonomy) ─────────────────── */
.organ-band {
    display: flex;
    align-items: center;
    padding: 9px 14px;
    margin: 18px 0 0;
    background: linear-gradient(90deg, #eef2ff 0%, #f8fafc 100%);
    border: 1px solid #d7dEEB;
    border-bottom: none;
    border-radius: 6px 6px 0 0;
    font-weight: 600;
    color: #2d3748;
}
.organ-band-unrooted {
    background: #fffaf0;
    border-color: #f0d9a8;
    color: #8a6d3b;
}
.organ-band-name { font-size: .95rem; }
.organ-band-body {
    border: 1px solid #d7dEEB;
    border-top: none;
    border-radius: 0 0 6px 6px;
    padding: 10px 12px 4px;
    margin-bottom: 6px;
}

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
/* A disease refined by finer diseases — indented one step further in */
.tree-subtypes-wrap-nested {
    margin-left: 18px;
    border-left-color: #dbe3ee;
}
.tree-subtype-row-nested { background: #fdfefe; }

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

/* ── Slide-count badges ─────────────────────────────────────── */
.tree-count-badge {
    font-size: .72rem;
    font-weight: 600;
    padding: .3rem .45rem;
    text-decoration: none;
}
a.tree-count-badge:hover { filter: brightness(.92); text-decoration: none; }
.tree-count-badge .mdi { font-size: .78rem; vertical-align: -1px; }

/* Add subtype form row. The slot around it is closed by default, so a tree of
   diseases no longer reserves a blank row under every single node. */
.tree-add-slot[hidden] { display: none !important; }
.tree-add-slot-nested {
    margin-left: 18px;
    padding-left: 16px;
    border-left: 2px dashed #dbe3ee;
}
.tree-add-row {
    display: flex;
    align-items: center;
    padding: 5px 4px;
    margin-top: 2px;
    position: relative;
}
.tree-empty-hint {
    font-size: .78rem;
    color: #a0aec0;
    padding: 4px 2px 6px;
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
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                    <h4 class="card-title mb-0">
                        Disease Taxonomy
                        <span class="badge badge-secondary ml-2">{{ $categories->count() }} groups</span>
                    </h4>
                    <div class="d-flex align-items-center" style="gap:.5rem;">
                        {{-- Organ filter --}}
                        <form method="GET" class="d-flex align-items-center mb-0" style="gap:.35rem;">
                            <label class="mb-0 small text-muted">Organ</label>
                            <select name="organ_id" class="form-control form-control-sm"
                                    style="min-width:180px;" onchange="this.form.submit()">
                                <option value="">All organs</option>
                                @foreach($organs as $organ)
                                    <option value="{{ $organ->id }}" @selected($selectedOrganId == $organ->id)>
                                        {{ $organ->name }}
                                    </option>
                                @endforeach
                            </select>
                        </form>
                        <a href="{{ route('admin.settings.categories.create', ['organ_id' => $selectedOrganId]) }}"
                           class="btn btn-primary btn-sm text-nowrap">
                            <i class="mdi mdi-plus mr-1"></i> Add Clinical Group
                        </a>
                    </div>
                </div>

                @if($unrootedCount > 0)
                    <div class="alert alert-warning py-2 px-3 mb-3" style="font-size:.82rem;">
                        <i class="mdi mdi-alert-outline mr-1"></i>
                        <strong>{{ $unrootedCount }}</strong> clinical group(s) have no organ assigned. They predate the
                        organ root and had no slides to infer it from — they cannot receive diseases or be trained on
                        until you edit each one and pick its organ.
                    </div>
                @endif

                {{-- Tree --}}
                @if($categories->isEmpty())
                    <div class="text-center text-muted py-5">
                        <i class="mdi mdi-tag-multiple" style="font-size:3rem;display:block;margin-bottom:.5rem;"></i>
                        No categories yet.
                        <a href="{{ route('admin.settings.categories.create') }}">Add one now</a>
                    </div>
                @else
                <div class="alert alert-light border py-2 px-3 mb-3" style="font-size:.82rem;">
                    <i class="mdi mdi-information-outline mr-1 text-info"></i>
                    <strong>Organ → Clinical Group → Disease → finer Disease.</strong>
                    The organ is the root and is never typed here — it comes from
                    <a href="{{ route('admin.settings.organs.index') }}">Organs</a>. A disease name is unique
                    <em>within its organ</em>, so "Adenocarcinoma" of the lung and of the colon stay two
                    separate entities. A disease can be refined further — add
                    "Infiltrating ductal carcinoma" under "Malignant" from the row's own <em>+</em> field.
                    In training, a run is scoped to one organ, each <strong>disease</strong>
                    becomes a class, and its <strong>clinical group</strong> is the auxiliary coarse label.
                </div>

                @foreach($grouped as $organId => $organCategories)
                @php $organ = $organCategories->first()->organ; @endphp

                {{-- ── Organ band (root level) ── --}}
                <div class="organ-band {{ $organ ? '' : 'organ-band-unrooted' }}">
                    <i class="mdi {{ $organ ? 'mdi-hospital-building' : 'mdi-help-circle-outline' }} mr-2"></i>
                    <span class="organ-band-name">{{ $organ->name ?? 'Not assigned to an organ' }}</span>
                    <span class="badge badge-light border ml-2">{{ $organCategories->count() }} group(s)</span>
                    <span class="badge badge-light border ml-1">
                        {{ $organCategories->sum('disease_subtypes_count') }} disease(s)
                    </span>
                    <span class="badge badge-light border ml-1">
                        {{ number_format($organCategories->sum('samples_count')) }} slide(s)
                    </span>
                    <span class="badge badge-success ml-1"
                          title="Downloaded and stored on Drive — the slides this organ can actually be trained on today">
                        {{ number_format($organCategories->sum('stored_samples_count')) }} on Drive
                    </span>
                </div>

                <div class="category-tree organ-band-body">
                    @foreach($organCategories as $cat)
                    @php
                        // Everything the diseases under this group account for.
                        // Whatever the group has on top of that was never given a
                        // disease, and would be invisible if only the tree reported.
                        $filedSlides   = $cat->rootDiseaseSubtypes->sum(fn ($s) => $s->branchCount('samples_count'));
                        $unfiledSlides = max(0, $cat->samples_count - $filedSlides);
                    @endphp
                    <div class="tree-node" id="cat-node-{{ $cat->id }}">

                        {{-- ── Category Header ── --}}
                        <div class="tree-category-header">

                            {{-- Toggle chevron --}}
                            <button class="tree-toggle-btn"
                                    type="button"
                                    data-toggle-cat="{{ $cat->id }}"
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

                            {{-- Slides filed under this group, and how many of them
                                 are actually downloaded. `$unfiledSlides` is the gap
                                 between this total and the sum of the diseases
                                 below: slides that carry the group but no disease,
                                 so they belong to no training class yet. --}}
                            <a href="{{ route('admin.samples', array_filter(['organ_id' => $cat->organ_id, 'category_id' => $cat->id])) }}"
                               class="badge tree-count-badge {{ $cat->samples_count > 0 ? 'badge-primary' : 'badge-light border text-muted' }} mr-1"
                               title="{{ $cat->samples_count }} slide(s) are filed under this clinical group. Click to open them.">
                                <i class="mdi mdi-image-multiple"></i> {{ number_format($cat->samples_count) }}
                            </a>

                            <a href="{{ route('admin.samples', array_filter(['organ_id' => $cat->organ_id, 'category_id' => $cat->id, 'storage_status' => 'available'])) }}"
                               class="badge tree-count-badge {{ $cat->stored_samples_count > 0 ? 'badge-success' : 'badge-light border text-muted' }} mr-1"
                               title="{{ $cat->stored_samples_count }} of {{ $cat->samples_count }} slide(s) are downloaded and stored on Drive. Click to open them.">
                                <i class="mdi mdi-cloud-check"></i> {{ number_format($cat->stored_samples_count) }}
                            </a>

                            @if($unfiledSlides > 0)
                                <a href="{{ route('admin.samples', array_filter(['organ_id' => $cat->organ_id, 'category_id' => $cat->id]) + ['disease_subtype_id' => 'none']) }}"
                                   class="badge badge-warning tree-count-badge mr-2"
                                   title="{{ $unfiledSlides }} slide(s) carry this clinical group but no disease, so they are counted here and under no disease below. Click to open and label them."><!--
                                    -->{{ number_format($unfiledSlides) }} unfiled
                                </a>
                            @else
                                <span class="mr-1"></span>
                            @endif

                            {{-- Status --}}
                            @if($cat->is_active)
                                <span class="badge badge-success mr-3">Active</span>
                            @else
                                <span class="badge badge-secondary mr-3">Inactive</span>
                            @endif

                            {{-- Add a disease — sits with the other row actions
                                 rather than as a permanent blank row underneath --}}
                            <button type="button" class="btn btn-outline-secondary btn-sm mr-1"
                                    title="Add a disease to this clinical group"
                                    onclick="toggleAddForm('cat-{{ $cat->id }}', this)">
                                <i class="mdi mdi-plus"></i>
                            </button>

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

                                @forelse($cat->rootDiseaseSubtypes as $subtype)
                                    @include('admin.settings.categories._disease-node', [
                                        'cat' => $cat, 'subtype' => $subtype, 'depth' => 1,
                                    ])
                                @empty
                                    <div class="tree-empty-hint">
                                        No diseases in this group yet — use the
                                        <i class="mdi mdi-plus"></i> button on the row above to add one.
                                    </div>
                                @endforelse

                                {{-- Add a top-level disease. Opened by the group's
                                     "+" button, so it costs no space while closed. --}}
                                @include('admin.settings.categories._disease-add', [
                                    'cat' => $cat, 'parent' => null,
                                ])

                            </div>{{-- /.tree-subtypes-wrap --}}

                        </div>{{-- /.collapse --}}

                    </div>{{-- /.tree-node --}}
                    @endforeach
                </div>{{-- /.category-tree --}}
                @endforeach{{-- /organ band --}}
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

function expandCategory(id) {
    var panel = document.getElementById('subtypes-' + id);
    if (!panel) return;
    panel.classList.add('show');
    var chevron = document.querySelector('[data-toggle-cat="' + id + '"] .mdi');
    if (chevron) chevron.classList.add('rotated');
}

// The "add a disease" forms are collapsed so they cost no vertical space; the
// "+" on a row opens the one belonging to it, expanding the group first when the
// row sits inside a panel that is still closed.
function toggleAddForm(key, btn) {
    var slot = document.getElementById('add-' + key);
    if (!slot) return;

    var opening = slot.hasAttribute('hidden');
    if (opening) {
        slot.removeAttribute('hidden');
        var panel = slot.closest('.collapse');
        if (panel) expandCategory(panel.id.replace('subtypes-', ''));
        var input = slot.querySelector('input[name="name"]');
        if (input) input.focus();
    } else {
        slot.setAttribute('hidden', '');
    }

    if (btn) {
        btn.classList.toggle('btn-outline-secondary', !opening);
        btn.classList.toggle('btn-secondary', opening);
    }
}

$(function () {
    // Auto-expand after add / edit / delete subtype
    @if(session('open_category'))
    expandCategory({{ (int) session('open_category') }});
    @endif

    // Auto-expand when there are validation errors (inline add). The form that
    // failed renders already open, so this only has to reveal the panel holding it.
    @if($errors->any() && old('category_id'))
    expandCategory({{ (int) old('category_id') }});
    @endif
});
</script>
@endpush
