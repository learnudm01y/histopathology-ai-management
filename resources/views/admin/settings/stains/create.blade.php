@extends('admin.layouts.app')
@section('title', 'Add Stain')

@section('content')
<div class="page-header">
    <h3 class="page-title">Add Stain</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.settings.stains.index') }}">Stains</a></li>
            <li class="breadcrumb-item active">Add</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-4">New Stain</h4>

                <form action="{{ route('admin.settings.stains.store') }}" method="POST">
                    @csrf

                    <div class="row">
                        <div class="col-md-7">
                            <div class="form-group">
                                <label>Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="name"
                                       class="form-control @error('name') is-invalid @enderror"
                                       value="{{ old('name') }}"
                                       placeholder="e.g. Hematoxylin & Eosin">
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group">
                                <label>Abbreviation <span class="text-danger">*</span></label>
                                <input type="text" name="abbreviation"
                                       class="form-control @error('abbreviation') is-invalid @enderror"
                                       value="{{ old('abbreviation') }}"
                                       placeholder="e.g. H&E">
                                @error('abbreviation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Stain Type <span class="text-danger">*</span></label>
                                <select name="stain_type"
                                        class="form-control @error('stain_type') is-invalid @enderror"
                                        id="stainTypeSelect">
                                    <option value="">— Select type —</option>
                                    @foreach($stainTypes as $value => $label)
                                        <option value="{{ $value }}"
                                            {{ old('stain_type') === $value ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('stain_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6" id="markerField"
                             style="{{ in_array(old('stain_type'), ['IHC','ISH']) ? '' : 'display:none' }}">
                            <div class="form-group">
                                <label>Antibody / Marker
                                    <small class="text-muted">(IHC/ISH only — e.g. ER, HER2)</small>
                                </label>
                                <input type="text" name="marker"
                                       class="form-control @error('marker') is-invalid @enderror"
                                       value="{{ old('marker') }}"
                                       placeholder="e.g. Ki67">
                                @error('marker')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Description <small class="text-muted">optional</small></label>
                        <textarea name="description" rows="3"
                                  class="form-control @error('description') is-invalid @enderror"
                                  placeholder="What does this stain highlight? When is it used?">{{ old('description') }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <label>Notes <small class="text-muted">optional</small></label>
                        <textarea name="notes" rows="2" class="form-control">{{ old('notes') }}</textarea>
                    </div>

                    <div class="form-group">
                        <label class="d-block">Status</label>
                        <div class="custom-control custom-switch mt-1">
                            <input type="checkbox" class="custom-control-input" id="is_active"
                                   name="is_active" value="1"
                                   {{ old('is_active', true) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="is_active">Active</label>
                        </div>
                    </div>

                    <div class="d-flex" style="gap:.5rem;">
                        <button type="submit" class="btn btn-primary">Add Stain</button>
                        <a href="{{ route('admin.settings.stains.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.getElementById('stainTypeSelect').addEventListener('change', function () {
    var show = ['IHC', 'ISH'].includes(this.value);
    document.getElementById('markerField').style.display = show ? '' : 'none';
});
</script>
@endpush
@endsection
