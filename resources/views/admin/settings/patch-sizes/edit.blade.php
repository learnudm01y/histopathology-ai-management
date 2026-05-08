@extends('admin.layouts.app')
@section('title', 'Edit Patch Size')

@section('content')
<div class="page-header">
    <h3 class="page-title">Edit Patch Size</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.settings.patch-sizes.index') }}">Patch Sizes</a></li>
            <li class="breadcrumb-item active">{{ $patchSize->label }}</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-4">Edit: {{ $patchSize->label }}</h4>

                <form action="{{ route('admin.settings.patch-sizes.update', $patchSize) }}" method="POST">
                    @csrf @method('PUT')

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Patch Size (px) <span class="text-danger">*</span></label>
                                <input type="number" name="size_px" min="1" max="4096"
                                       class="form-control @error('size_px') is-invalid @enderror"
                                       value="{{ old('size_px', $patchSize->size_px) }}">
                                @error('size_px')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>WSI Level <span class="text-danger">*</span></label>
                                <input type="number" name="wsi_level" min="0" max="20"
                                       class="form-control @error('wsi_level') is-invalid @enderror"
                                       value="{{ old('wsi_level', $patchSize->wsi_level) }}">
                                @error('wsi_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Overlap (px) <span class="text-danger">*</span></label>
                                <input type="number" name="overlap_px" min="0" max="2048"
                                       class="form-control @error('overlap_px') is-invalid @enderror"
                                       value="{{ old('overlap_px', $patchSize->overlap_px) }}">
                                @error('overlap_px')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Label <span class="text-danger">*</span></label>
                        <input type="text" name="label"
                               class="form-control @error('label') is-invalid @enderror"
                               value="{{ old('label', $patchSize->label) }}">
                        @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <label>Linked AI Model <small class="text-muted">(optional)</small></label>
                        <select name="ai_model_id"
                                class="form-control @error('ai_model_id') is-invalid @enderror">
                            <option value="">— Any / Not model-specific —</option>
                            @foreach($aiModels as $m)
                                <option value="{{ $m->id }}"
                                    {{ old('ai_model_id', $patchSize->ai_model_id) == $m->id ? 'selected' : '' }}>
                                    {{ $m->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('ai_model_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="2"
                                  class="form-control @error('notes') is-invalid @enderror"
                                  placeholder="Optional notes…">{{ old('notes', $patchSize->notes) }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" class="custom-control-input" id="isActive"
                                   name="is_active" value="1"
                                   {{ old('is_active', $patchSize->is_active) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="isActive">Active</label>
                        </div>
                    </div>

                    <div class="d-flex" style="gap:.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="mdi mdi-content-save mr-1"></i> Update
                        </button>
                        <a href="{{ route('admin.settings.patch-sizes.index') }}" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
