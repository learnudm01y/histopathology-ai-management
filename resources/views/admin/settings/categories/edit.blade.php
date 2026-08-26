@extends('admin.layouts.app')
@section('title', 'Edit Category')

@section('content')
<div class="page-header">
    <h3 class="page-title">Edit Category</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.settings.categories.index') }}">Categories</a></li>
            <li class="breadcrumb-item active">Edit: {{ $category->label_en }}</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-4">Edit — {{ $category->label_en }}</h4>

                <form action="{{ route('admin.settings.categories.update', $category) }}" method="POST">
                    @csrf @method('PUT')

                    {{-- Label EN --}}
                    {{-- Organ (root — selected, never typed) --}}
                    @php $lockedOrgan = $category->samples()->exists(); @endphp
                    <div class="form-group">
                        <label>Organ <span class="text-danger">*</span></label>
                        <select name="organ_id" class="form-control @error('organ_id') is-invalid @enderror"
                                {{ $lockedOrgan ? 'disabled' : '' }} required>
                            <option value="">— Choose organ —</option>
                            @foreach($organs as $organ)
                                <option value="{{ $organ->id }}"
                                    @selected(old('organ_id', $category->organ_id) == $organ->id)>{{ $organ->name }}</option>
                            @endforeach
                        </select>
                        @if($lockedOrgan)
                            {{-- Disabled selects are not submitted; keep the value intact. --}}
                            <input type="hidden" name="organ_id" value="{{ $category->organ_id }}">
                            <small class="form-text text-warning">
                                <i class="mdi mdi-lock-outline mr-1"></i>
                                Locked: {{ $category->samples()->count() }} slide(s) are classified under this group.
                                Moving it to another organ would silently relabel them.
                            </small>
                        @endif
                        @error('organ_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <label>Clinical Group Name <span class="text-danger">*</span></label>
                        <input type="text" name="label_en" class="form-control @error('label_en') is-invalid @enderror"
                               value="{{ old('label_en', $category->label_en) }}">
                        @error('label_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Notes --}}
                    <div class="form-group">
                        <label>Notes <small class="text-muted">optional</small></label>
                        <textarea name="notes" rows="2" class="form-control">{{ old('notes', $category->notes) }}</textarea>
                    </div>

                    {{-- Active --}}
                    <div class="form-group">
                        <label class="d-block">Status</label>
                        <div class="custom-control custom-switch mt-1">
                            <input type="checkbox" class="custom-control-input" id="is_active"
                                   name="is_active" value="1"
                                   {{ old('is_active', $category->is_active) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="is_active">Active</label>
                        </div>
                    </div>

                    <div class="d-flex" style="gap:.5rem;">
                        <button type="submit" class="btn btn-primary">Update Category</button>
                        <a href="{{ route('admin.settings.categories.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
