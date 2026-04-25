@extends('admin.layouts.app')
@section('title', 'Edit Disease Subtype')

@section('content')
<div class="page-header">
    <h3 class="page-title">Edit Disease Subtype</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.settings.categories.index') }}">Categories</a></li>
            <li class="breadcrumb-item active">Edit Subtype</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
    <div class="col-lg-6 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-1">Edit Subtype</h4>
                <p class="text-muted small mb-4">
                    <i class="mdi mdi-folder mr-1 text-primary"></i>
                    Category: <strong>{{ $category->label_en }}</strong>
                </p>

                <form action="{{ route('admin.settings.subtypes.update', [$category, $subtype]) }}"
                      method="POST">
                    @csrf
                    @method('PUT')

                    <div class="form-group">
                        <label>Name <span class="text-danger">*</span></label>
                        <input type="text" name="name"
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $subtype->name) }}"
                               placeholder="e.g. Invasive Ductal Carcinoma">
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="2"
                                  class="form-control">{{ old('notes', $subtype->notes) }}</textarea>
                    </div>

                    <div class="form-group mb-4">
                        <label class="d-block">Status</label>
                        <div class="custom-control custom-switch mt-1">
                            <input type="checkbox" class="custom-control-input" id="is_active"
                                   name="is_active" value="1"
                                   {{ old('is_active', $subtype->is_active) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="is_active">Active</label>
                        </div>
                    </div>

                    <div class="d-flex" style="gap:.5rem;">
                        <button type="submit" class="btn btn-primary">Update Subtype</button>
                        <a href="{{ route('admin.settings.categories.index') }}"
                           class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
