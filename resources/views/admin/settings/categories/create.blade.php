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
                <h4 class="card-title mb-1">New Clinical Group</h4>
                <p class="card-description mb-4">
                    A clinical group is the middle level of the taxonomy:
                    <strong>Organ → Clinical Group → Disease</strong>.
                    The organ is chosen from the organs list — it is never typed here.
                </p>

                <form action="{{ route('admin.settings.categories.store') }}" method="POST">
                    @csrf

                    {{-- Organ (root — selected, never typed) --}}
                    <div class="form-group">
                        <label>Organ <span class="text-danger">*</span></label>
                        <select name="organ_id" class="form-control @error('organ_id') is-invalid @enderror" required>
                            <option value="">— Choose organ —</option>
                            @foreach($organs as $organ)
                                <option value="{{ $organ->id }}"
                                    @selected(old('organ_id', $selectedOrganId) == $organ->id)>{{ $organ->name }}</option>
                            @endforeach
                        </select>
                        @error('organ_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            Comes from <a href="{{ route('admin.settings.organs.index') }}" target="_blank">Organs</a>.
                            The same group name may exist under different organs — they stay separate classes.
                        </small>
                    </div>

                    {{-- Label EN --}}
                    <div class="form-group">
                        <label>Clinical Group Name <span class="text-danger">*</span></label>
                        <input type="text" name="label_en" class="form-control @error('label_en') is-invalid @enderror"
                               placeholder="e.g. Malignant" value="{{ old('label_en') }}">
                        @error('label_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            Must be unique within the selected organ. This becomes the coarse (auxiliary) class in training.
                        </small>
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
