@extends('admin.layouts.app')
@section('title', 'Add Category')

@section('content')
<div class="page-header">
    <h3 class="page-title">Add Category</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.settings.categories.index') }}">Categories</a></li>
            <li class="breadcrumb-item active">Add</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-4">New Category</h4>

                <form action="{{ route('admin.settings.categories.store') }}" method="POST">
                    @csrf

                    {{-- Label EN --}}
                    <div class="form-group">
                        <label>Label <span class="text-danger">*</span></label>
                        <input type="text" name="label_en" class="form-control @error('label_en') is-invalid @enderror"
                               placeholder="e.g. Normal" value="{{ old('label_en') }}">
                        @error('label_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Notes --}}
                    <div class="form-group">
                        <label>Notes <small class="text-muted">optional</small></label>
                        <textarea name="notes" rows="2" class="form-control" placeholder="Optional notes…">{{ old('notes') }}</textarea>
                    </div>

                    {{-- Active --}}
                    <div class="form-group">
                        <label class="d-block">Status</label>
                        <div class="custom-control custom-switch mt-1">
                            <input type="checkbox" class="custom-control-input" id="is_active"
                                   name="is_active" value="1"
                                   {{ old('is_active', '1') ? 'checked' : '' }}>
                            <label class="custom-control-label" for="is_active">Active</label>
                        </div>
                    </div>

                    <div class="d-flex" style="gap:.5rem;">
                        <button type="submit" class="btn btn-primary">Save Category</button>
                        <a href="{{ route('admin.settings.categories.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
