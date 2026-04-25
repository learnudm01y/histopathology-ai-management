@extends('admin.layouts.app')
@section('title', 'Edit Organ')

@section('content')
<div class="page-header">
    <h3 class="page-title">Edit Organ</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.settings.organs.index') }}">Organs</a></li>
            <li class="breadcrumb-item active">Edit: {{ $organ->name }}</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-4">Edit — {{ $organ->name }}</h4>

                <form action="{{ route('admin.settings.organs.update', $organ) }}" method="POST">
                    @csrf @method('PUT')

                    <div class="form-group">
                        <label>Name <span class="text-danger">*</span></label>
                        <input type="text" name="name"
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $organ->name) }}">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <label>Notes <small class="text-muted">optional</small></label>
                        <textarea name="notes" rows="2" class="form-control">{{ old('notes', $organ->notes) }}</textarea>
                    </div>

                    <div class="form-group">
                        <label class="d-block">Status</label>
                        <div class="custom-control custom-switch mt-1">
                            <input type="checkbox" class="custom-control-input" id="is_active"
                                   name="is_active" value="1"
                                   {{ old('is_active', $organ->is_active) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="is_active">Active</label>
                        </div>
                    </div>

                    <div class="d-flex" style="gap:.5rem;">
                        <button type="submit" class="btn btn-primary">Update Organ</button>
                        <a href="{{ route('admin.settings.organs.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
