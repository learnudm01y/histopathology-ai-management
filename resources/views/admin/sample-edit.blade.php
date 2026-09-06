@extends('admin.layouts.app')
@section('title', 'Edit Sample #' . $sample->id)

@section('content')
<div class="page-header">
    <h3 class="page-title">Edit Sample</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.samples') }}">Samples</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.samples.show', $sample) }}">#{{ $sample->id }}</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<div class="row">
    <div class="col-lg-6 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-4">Edit Sample #{{ $sample->id }}</h4>

                <form action="{{ route('admin.samples.update', $sample) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="row">

                        {{-- ── Classification ── --}}
                        <div class="col-12 mb-2">
                            <h6 class="text-muted text-uppercase" style="font-size:.7rem;letter-spacing:.08em;">
                                <i class="mdi mdi-tag-outline mr-1"></i> Classification
                            </h6>
                            <hr class="mt-1 mb-3">
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Organ <span class="text-danger">*</span></label>
                                <select name="organ_id" id="edit_organ_id"
                                        class="form-control @error('organ_id') is-invalid @enderror">
                                    <option value="">— Select —</option>
                                    @foreach($organs as $organ)
                                        <option value="{{ $organ->id }}"
                                            @selected(old('organ_id', $sample->organ_id) == $organ->id)>
                                            {{ $organ->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('organ_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Data Source</label>
                                <select name="data_source_id" class="form-control">
                                    <option value="">— None —</option>
                                    @foreach($dataSources as $ds)
                                        <option value="{{ $ds->id }}"
                                            @selected(old('data_source_id', $sample->data_source_id) == $ds->id)>
                                            {{ $ds->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Clinical Group</label>
                                <select name="category_id" id="edit_category_id" class="form-control"
                                        data-selected="{{ old('category_id', $sample->category_id) }}">
                                    <option value="">— None —</option>
                                </select>
                                <small class="form-text text-muted">
                                    Groups belong to the selected organ.
                                </small>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Disease Subtype</label>
                                <select name="disease_subtype" id="edit_subtype_id" class="form-control"
                                        data-selected="{{ old('disease_subtype', $sample->disease_subtype ?? '') }}">
                                    <option value="">— None —</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Stain</label>
                                <select name="stain_id" class="form-control @error('stain_id') is-invalid @enderror">
                                    <option value="">— None —</option>
                                    @foreach($stains as $stain)
                                        <option value="{{ $stain->id }}"
                                            @selected(old('stain_id', $sample->stain_id) == $stain->id)>
                                            {{ $stain->name }}{{ $stain->abbreviation ? ' (' . $stain->abbreviation . ')' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('stain_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Stain Marker</label>
                                <input type="text" name="stain_marker" class="form-control @error('stain_marker') is-invalid @enderror"
                                       placeholder="e.g. ER, PR, HER2, Ki67"
                                       value="{{ old('stain_marker', $sample->stain_marker) }}">
                                @error('stain_marker')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        {{-- ── File Info ── --}}
                        <div class="col-12 mb-2 mt-3">
                            <h6 class="text-muted text-uppercase" style="font-size:.7rem;letter-spacing:.08em;">
                                <i class="mdi mdi-file-outline mr-1"></i> File Info
                            </h6>
                            <hr class="mt-1 mb-3">
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label>File Name</label>
                                <input type="text" name="file_name" class="form-control"
                                       value="{{ old('file_name', $sample->file_name) }}">
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Data Format</label>
                                <input type="text" name="data_format" class="form-control"
                                       placeholder="e.g. SVS, TIFF"
                                       value="{{ old('data_format', $sample->data_format) }}">
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Slide Submitter ID</label>
                                <input type="text" name="entity_submitter_id" class="form-control"
                                       value="{{ old('entity_submitter_id', $sample->entity_submitter_id) }}">
                            </div>
                        </div>

                        {{-- ── Status & Quality ── --}}
                        <div class="col-12 mb-2 mt-3">
                            <h6 class="text-muted text-uppercase" style="font-size:.7rem;letter-spacing:.08em;">
                                <i class="mdi mdi-check-circle-outline mr-1"></i> Status & Quality
                            </h6>
                            <hr class="mt-1 mb-3">
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Storage Status <span class="text-danger">*</span></label>
                                <select name="storage_status" class="form-control @error('storage_status') is-invalid @enderror">
                                    @foreach(['not_downloaded','downloading','verifying','available','corrupted','missing'] as $st)
                                        <option value="{{ $st }}"
                                            @selected(old('storage_status', $sample->storage_status) === $st)>
                                            {{ ucwords(str_replace('_', ' ', $st)) }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('storage_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Quality Status</label>
                                <select name="quality_status" class="form-control">
                                    <option value="">— Not reviewed —</option>
                                    <option value="pending"  @selected(old('quality_status', $sample->quality_status) === 'pending')>Pending</option>
                                    <option value="passed"   @selected(old('quality_status', $sample->quality_status) === 'passed')>Passed</option>
                                    <option value="rejected" @selected(old('quality_status', $sample->quality_status) === 'rejected')>Rejected</option>
                                    <option value="needs_clinical_info" @selected(old('quality_status', $sample->quality_status) === 'needs_clinical_info')>Needs Case Info</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Usable</label>
                                <div class="custom-control custom-switch mt-2">
                                    <input type="checkbox" class="custom-control-input" id="is_usable"
                                           name="is_usable" value="1"
                                           {{ old('is_usable', $sample->is_usable) ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="is_usable">Mark as usable</label>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Quality Rejection Reason</label>
                                <input type="text" name="quality_rejection_reason" class="form-control"
                                       placeholder="Leave blank if not rejected"
                                       value="{{ old('quality_rejection_reason', $sample->quality_rejection_reason) }}">
                            </div>
                        </div>

                    </div>{{-- /.row --}}

                    <div class="d-flex mt-3" style="gap:.5rem;">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="{{ route('admin.samples.show', $sample) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6 grid-margin">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="mdi mdi-file-image-outline mr-1 text-primary"></i> File Preview
                </h5>

                @if($sample->file_id && $sample->storage_status === 'available')
                    {{-- Google Drive preview --}}
                    <iframe
                        src="https://drive.google.com/file/d/{{ $sample->file_id }}/preview"
                        width="100%"
                        height="400"
                        allow="autoplay"
                        style="border:none; border-radius:4px;">
                    </iframe>
                    <p class="mt-2 small text-muted text-center">
                        <i class="mdi mdi-information-outline"></i>
                        Google Drive Preview
                    </p>
                @else
                    <div class="text-center py-4 text-muted">
                        <i class="mdi mdi-file-question-outline" style="font-size:2.5rem;"></i>
                        <p class="mt-2 mb-0 small">Preview unavailable</p>
                        <small style="font-size:.75rem;">File must be on Drive</small>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    // Taxonomy is organ-rooted: Organ → Clinical Group → Disease.
    // Each level's picker is rebuilt from the level above it, so a slide can
    // never be filed under a group or disease belonging to a different organ.
    var subtypes         = @json($diseaseSubtypesByCategory);
    var groupsByOrgan    = @json($categoriesByOrgan);

    /** Populate the Clinical Group dropdown for the given organ. */
    function populateEditGroups(organId, selectedValue) {
        var sel  = document.getElementById('edit_category_id');
        var list = organId ? (groupsByOrgan[organId] || []) : [];

        sel.innerHTML = '';
        var blank = document.createElement('option');
        blank.value = '';
        blank.textContent = list.length
            ? '— None —'
            : (organId ? '— No clinical groups for this organ —' : '— Select an organ first —');
        sel.appendChild(blank);

        list.forEach(function (g) {
            var opt = document.createElement('option');
            opt.value       = g.id;
            opt.textContent = g.label_en;
            if (String(g.id) === String(selectedValue)) opt.selected = true;
            sel.appendChild(opt);
        });
    }

    /**
     * Populate the Disease Subtype dropdown for the given category.
     * Restores `selectedValue` if it exists among the options.
     */
    function populateEditSubtypes(catId, selectedValue) {
        var sel  = document.getElementById('edit_subtype_id');
        var list = catId ? (subtypes[catId] || []) : [];

        sel.innerHTML = '';
        var blank = document.createElement('option');
        blank.value = '';
        blank.textContent = list.length ? '— None —' : (catId ? '— No subtypes —' : '— None —');
        sel.appendChild(blank);

        // Diseases nest, so the flat list is walked as a tree and each level is
        // indented: without it "Infiltrating ductal carcinoma" would sit beside
        // the "Malignant" it actually belongs under.
        (function emit(parentId, depth) {
            list.filter(function (s) {
                    return String(s.parent_id == null ? '' : s.parent_id) === String(parentId);
                })
                .forEach(function (s) {
                    var opt = document.createElement('option');
                    opt.value       = s.name;
                    // Non-breaking spaces: a browser collapses ordinary ones in
                    // an <option> and the indent would vanish.
                    opt.textContent = (depth ? '  '.repeat(depth) + '└ ' : '') + s.name;
                    if (s.name === selectedValue) opt.selected = true;
                    sel.appendChild(opt);
                    if (depth < 10) emit(s.id, depth + 1);
                });
        })('', 0);
    }

    // Re-populate when category changes (clear previous subtype selection)
    document.getElementById('edit_category_id').addEventListener('change', function () {
        populateEditSubtypes(this.value, '');
    });

    // Changing the organ invalidates both levels beneath it.
    document.getElementById('edit_organ_id').addEventListener('change', function () {
        populateEditGroups(this.value, '');
        populateEditSubtypes('', '');
    });

    // Populate on initial page load, restoring the saved / old() values top-down
    var initOrganId = document.getElementById('edit_organ_id').value;
    var initCatId   = document.getElementById('edit_category_id').dataset.selected;
    var initSubtype = document.getElementById('edit_subtype_id').dataset.selected;

    populateEditGroups(initOrganId, initCatId);
    populateEditSubtypes(document.getElementById('edit_category_id').value, initSubtype);

})();
</script>
@endpush
