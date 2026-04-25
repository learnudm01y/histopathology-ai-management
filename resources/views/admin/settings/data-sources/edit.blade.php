@extends('admin.layouts.app')
@section('title', 'Edit Data Source')

@section('content')
<div class="page-header">
    <h3 class="page-title">Edit Data Source</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.settings.data-sources.index') }}">Data Sources</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-4">Edit: {{ $dataSource->name }}</h4>

                <form action="{{ route('admin.settings.data-sources.update', $dataSource) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Short Name <span class="text-danger">*</span></label>
                                <input type="text" name="name"
                                       class="form-control @error('name') is-invalid @enderror"
                                       value="{{ old('name', $dataSource->name) }}">
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="full_name" class="form-control"
                                       value="{{ old('full_name', $dataSource->full_name) }}">
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Base URL</label>
                                <input type="url" name="base_url" class="form-control @error('base_url') is-invalid @enderror"
                                       value="{{ old('base_url', $dataSource->base_url) }}">
                                @error('base_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Total Slides Available</label>
                                <input type="number" name="total_slides_available" class="form-control"
                                       min="0" value="{{ old('total_slides_available', $dataSource->total_slides_available) }}">
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description" rows="2"
                                          class="form-control">{{ old('description', $dataSource->description) }}</textarea>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="form-group mb-3">
                                <label class="d-block">Status</label>
                                <div class="custom-control custom-switch mt-1">
                                    <input type="checkbox" class="custom-control-input" id="is_active"
                                           name="is_active" value="1"
                                           {{ old('is_active', $dataSource->is_active) ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex" style="gap:.5rem;">
                        <button type="submit" class="btn btn-primary">Update Data Source</button>
                        <a href="{{ route('admin.settings.data-sources.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
