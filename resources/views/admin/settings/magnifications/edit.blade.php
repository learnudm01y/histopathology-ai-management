@extends('admin.layouts.app')
@section('title', 'Edit Magnification')

@section('content')
<div class="page-header">
    <h3 class="page-title">Edit Magnification</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.settings.magnifications.index') }}">Magnifications</a></li>
            <li class="breadcrumb-item active">{{ $magnification->label }}</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
    <div class="col-lg-6 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-4">Edit: {{ $magnification->label }}</h4>

                <form action="{{ route('admin.settings.magnifications.update', $magnification) }}" method="POST">
                    @csrf @method('PUT')

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Label <span class="text-danger">*</span></label>
                                <input type="text" name="label"
                                       class="form-control @error('label') is-invalid @enderror"
                                       value="{{ old('label', $magnification->label) }}"
                                       maxlength="20">
                                @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Value <span class="text-danger">*</span></label>
                                <input type="number" name="value" min="1" max="10000"
                                       class="form-control @error('value') is-invalid @enderror"
                                       value="{{ old('value', $magnification->value) }}">
                                @error('value')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Folder Name <span class="text-danger">*</span></label>
                                <input type="text" name="folder_name"
                                       class="form-control @error('folder_name') is-invalid @enderror"
                                       value="{{ old('folder_name', $magnification->folder_name) }}"
                                       maxlength="30">
                                @error('folder_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <input type="text" name="notes"
                               class="form-control @error('notes') is-invalid @enderror"
                               value="{{ old('notes', $magnification->notes) }}"
                               maxlength="255">
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" class="custom-control-input" id="isActive"
                                   name="is_active" value="1"
                                   {{ old('is_active', $magnification->is_active) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="isActive">Active</label>
                        </div>
                    </div>

                    <div class="d-flex" style="gap:.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="mdi mdi-content-save mr-1"></i> Update
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
