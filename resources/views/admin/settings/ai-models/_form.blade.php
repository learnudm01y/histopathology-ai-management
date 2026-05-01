@extends('admin.layouts.app')
@section('title', isset($model) ? 'Edit AI Model' : 'Add AI Model')

@section('content')
@php $isEdit = isset($model); @endphp
<div class="page-header">
    <h3 class="page-title">{{ $isEdit ? 'Edit AI Model' : 'Add AI Model' }}</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.settings.ai-models.index') }}">AI Models</a></li>
            <li class="breadcrumb-item active">{{ $isEdit ? 'Edit: ' . $model->name : 'Add' }}</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
    <div class="col-lg-10 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-4">{{ $isEdit ? 'Edit — ' . $model->name : 'New AI Model' }}</h4>

                <form action="{{ $isEdit ? route('admin.settings.ai-models.update', $model) : route('admin.settings.ai-models.store') }}"
                      method="POST">
                    @csrf
                    @if($isEdit) @method('PUT') @endif

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Name <span class="text-danger">*</span></label>
                                <input type="text" name="name"
                                       class="form-control @error('name') is-invalid @enderror"
                                       value="{{ old('name', $model->name ?? '') }}"
                                       placeholder="e.g. TITAN">
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group">
                                <label>Full Name <small class="text-muted">optional</small></label>
                                <input type="text" name="full_name"
                                       class="form-control @error('full_name') is-invalid @enderror"
                                       value="{{ old('full_name', $model->full_name ?? '') }}"
                                       placeholder="e.g. Multimodal Whole Slide Foundation Model">
                                @error('full_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Version</label>
                                <input type="text" name="version"
                                       class="form-control"
                                       value="{{ old('version', $model->version ?? '') }}"
                                       placeholder="e.g. v1">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Provider</label>
                                <input type="text" name="provider"
                                       class="form-control"
                                       value="{{ old('provider', $model->provider ?? '') }}"
                                       placeholder="e.g. MahmoodLab">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Model Type <span class="text-danger">*</span></label>
                                <select name="model_type"
                                        class="form-control @error('model_type') is-invalid @enderror">
                                    @foreach($modelTypes as $value => $label)
                                        <option value="{{ $value }}"
                                            {{ old('model_type', $model->model_type ?? 'foundation') === $value ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('model_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Level <span class="text-danger">*</span></label>
                                <select name="level"
                                        class="form-control @error('level') is-invalid @enderror">
                                    @foreach($levels as $value => $label)
                                        <option value="{{ $value }}"
                                            {{ old('level', $model->level ?? 'slide') === $value ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Hugging Face URL</label>
                                <input type="url" name="huggingface_url"
                                       class="form-control @error('huggingface_url') is-invalid @enderror"
                                       value="{{ old('huggingface_url', $model->huggingface_url ?? '') }}"
                                       placeholder="https://huggingface.co/...">
                                @error('huggingface_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Paper URL</label>
                                <input type="url" name="paper_url"
                                       class="form-control @error('paper_url') is-invalid @enderror"
                                       value="{{ old('paper_url', $model->paper_url ?? '') }}">
                                @error('paper_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Repository URL</label>
                                <input type="url" name="repo_url"
                                       class="form-control @error('repo_url') is-invalid @enderror"
                                       value="{{ old('repo_url', $model->repo_url ?? '') }}">
                                @error('repo_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Input Resolution</label>
                                <input type="text" name="input_resolution"
                                       class="form-control"
                                       value="{{ old('input_resolution', $model->input_resolution ?? '') }}"
                                       placeholder="e.g. 512x512">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Embedding Dim</label>
                                <input type="text" name="embedding_dim"
                                       class="form-control"
                                       value="{{ old('embedding_dim', $model->embedding_dim ?? '') }}"
                                       placeholder="e.g. 768">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Parameters</label>
                                <input type="text" name="parameters"
                                       class="form-control"
                                       value="{{ old('parameters', $model->parameters ?? '') }}"
                                       placeholder="e.g. 1.1B">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>License</label>
                                <input type="text" name="license"
                                       class="form-control"
                                       value="{{ old('license', $model->license ?? '') }}"
                                       placeholder="e.g. CC-BY-NC-ND-4.0">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="4" class="form-control">{{ old('description', $model->description ?? '') }}</textarea>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="2" class="form-control">{{ old('notes', $model->notes ?? '') }}</textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="custom-control custom-switch mt-1">
                                <input type="checkbox" class="custom-control-input" id="is_active"
                                       name="is_active" value="1"
                                       {{ old('is_active', $isEdit ? $model->is_active : true) ? 'checked' : '' }}>
                                <label class="custom-control-label" for="is_active">Active</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="custom-control custom-switch mt-1">
                                <input type="checkbox" class="custom-control-input" id="is_default"
                                       name="is_default" value="1"
                                       {{ old('is_default', $isEdit ? $model->is_default : false) ? 'checked' : '' }}>
                                <label class="custom-control-label" for="is_default">
                                    Default training model
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex mt-4" style="gap:.5rem;">
                        <button type="submit" class="btn btn-primary">
                            {{ $isEdit ? 'Update' : 'Add' }} Model
                        </button>
                        <a href="{{ route('admin.settings.ai-models.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
