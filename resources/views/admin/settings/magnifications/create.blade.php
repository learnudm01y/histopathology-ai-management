@extends('admin.layouts.app')
@section('title', 'Add Magnification')

@section('content')
<div class="page-header">
    <h3 class="page-title">Add Magnification</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.settings.magnifications.index') }}">Magnifications</a></li>
            <li class="breadcrumb-item active">Add</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
    <div class="col-lg-6 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-4">New Magnification Level</h4>

                <form action="{{ route('admin.settings.magnifications.store') }}" method="POST">
                    @csrf

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Label <span class="text-danger">*</span></label>
                                <input type="text" name="label"
                                       class="form-control @error('label') is-invalid @enderror"
                                       value="{{ old('label') }}"
                                       placeholder="e.g. x20"
                                       maxlength="20">
                                @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <small class="form-text text-muted">Shown in dropdowns (x10, x20, x40).</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Value <span class="text-danger">*</span></label>
                                <input type="number" name="value" min="1" max="10000"
                                       class="form-control @error('value') is-invalid @enderror"
                                       value="{{ old('value') }}"
                                       placeholder="e.g. 20">
                                @error('value')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <small class="form-text text-muted">Numeric multiplier (10, 20, 40).</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Folder Name <span class="text-danger">*</span></label>
                                <input type="text" name="folder_name"
                                       class="form-control @error('folder_name') is-invalid @enderror"
                                       value="{{ old('folder_name') }}"
                                       placeholder="e.g. 20x"
                                       maxlength="30">
                                @error('folder_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <small class="form-text text-muted">Used in Google Drive folder paths.</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <input type="text" name="notes"
                               class="form-control @error('notes') is-invalid @enderror"
                               value="{{ old('notes') }}"
                               placeholder="e.g. Standard magnification for diagnostic pathology"
                               maxlength="255">
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" class="custom-control-input" id="isActive"
                                   name="is_active" value="1"
                                   {{ old('is_active', '1') ? 'checked' : '' }}>
                            <label class="custom-control-label" for="isActive">Active</label>
                        </div>
                    </div>

                    <div class="d-flex" style="gap:.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="mdi mdi-content-save mr-1"></i> Save
                        </button>
                        <a href="{{ route('admin.settings.magnifications.index') }}" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
