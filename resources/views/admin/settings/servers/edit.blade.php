@extends('admin.layouts.app')
@section('title', 'Edit Server')

@section('content')
<div class="page-header">
    <h3 class="page-title">Edit Server</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.settings.servers.index') }}">Servers</a></li>
            <li class="breadcrumb-item active">{{ $server->name }}</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-4">Edit: {{ $server->name }}</h4>

                <form action="{{ route('admin.settings.servers.update', $server) }}" method="POST" autocomplete="off">
                    @csrf @method('PUT')

                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Server Name <span class="text-danger">*</span></label>
                                <input type="text" name="name"
                                       class="form-control @error('name') is-invalid @enderror"
                                       value="{{ old('name', $server->name) }}"
                                       placeholder="e.g. Hostinger Server">
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Type <span class="text-danger">*</span></label>
                                <select name="type" id="serverTypeSelect"
                                        class="form-control @error('type') is-invalid @enderror">
                                    <option value="local"    {{ old('type', $server->type) === 'local'    ? 'selected' : '' }}>Local Server</option>
                                    <option value="external" {{ old('type', $server->type) === 'external' ? 'selected' : '' }}>External Server</option>
                                </select>
                                @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Host / IP Address</label>
                                <input type="text" name="host"
                                       class="form-control @error('host') is-invalid @enderror"
                                       value="{{ old('host', $server->host) }}"
                                       placeholder="e.g. localhost">
                                @error('host')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6" id="apiUrlField"
                             style="{{ old('type', $server->type) === 'external' ? '' : 'display:none' }}">
                            <div class="form-group">
                                <label>API Base URL</label>
                                <input type="url" name="api_url"
                                       class="form-control @error('api_url') is-invalid @enderror"
                                       value="{{ old('api_url', $server->api_url) }}"
                                       placeholder="https://api.yourserver.com">
                                @error('api_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div id="apiKeyField"
                         style="{{ old('type', $server->type) === 'external' ? '' : 'display:none' }}">
                        <div class="form-group">
                            <label>Callback API Key / Shared Secret</label>
                            <input type="password" name="api_key"
                                   class="form-control @error('api_key') is-invalid @enderror"
                                   value="{{ old('api_key') }}"
                                   autocomplete="new-password"
                                   placeholder="Leave blank to keep the existing key">
                            @error('api_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <small class="form-text text-muted">
                                The shared secret used to authenticate callbacks FROM the GPU server.
                                Must match <code>RUNPOD_API_KEY</code> on the pod. Leave blank to keep unchanged.
                            </small>
                        </div>
                    </div>

                    {{-- RunPod-specific fields --}}
                    <div id="runpodFields"
                         style="{{ old('type', $server->type) === 'external' ? '' : 'display:none' }}">
                        <hr class="my-3">
                        <h6 class="text-muted mb-3"><i class="mdi mdi-lightning-bolt mr-1"></i>RunPod Settings</h6>
                        <div class="row">
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label>RunPod Account API Key
                                        <span class="badge badge-warning ml-1" style="font-size:.7rem;">sensitive</span>
                                    </label>
                                    <input type="password" name="runpod_api_key"
                                           class="form-control @error('runpod_api_key') is-invalid @enderror"
                                           value="{{ old('runpod_api_key') }}"
                                           autocomplete="new-password"
                                           placeholder="{{ $server->runpod_api_key ? '••••••••  (saved — leave blank to keep)' : 'rpa_…  (from RunPod account settings)' }}">
                                    @error('runpod_api_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <small class="form-text text-muted">
                                        Your RunPod account key (starts with <code>rpa_</code>). Leave blank to keep existing value.
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Network Volume ID</label>
                                    <input type="text" name="runpod_network_volume_id"
                                           class="form-control @error('runpod_network_volume_id') is-invalid @enderror"
                                           value="{{ old('runpod_network_volume_id', $server->runpod_network_volume_id) }}"
                                           placeholder="e.g. pk49baz3ni">
                                    @error('runpod_network_volume_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <small class="form-text text-muted">
                                        Persistent volume mounted at <code>/workspace</code> on the pod.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="2"
                                  class="form-control @error('description') is-invalid @enderror"
                                  placeholder="Short description…">{{ old('description', $server->description) }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" class="custom-control-input" id="isActive"
                                   name="is_active" value="1"
                                   {{ old('is_active', $server->is_active) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="isActive">Active</label>
                        </div>
                    </div>

                    <div class="d-flex" style="gap:.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="mdi mdi-content-save mr-1"></i> Update Server
                        </button>
                        <a href="{{ route('admin.settings.servers.index') }}" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var typeSelect   = document.getElementById('serverTypeSelect');
    var apiUrlField  = document.getElementById('apiUrlField');
    var apiKeyField  = document.getElementById('apiKeyField');
    var runpodFields = document.getElementById('runpodFields');

    function toggleApiFields() {
        var isExternal = typeSelect && typeSelect.value === 'external';
        if (apiUrlField)  apiUrlField.style.display  = isExternal ? '' : 'none';
        if (apiKeyField)  apiKeyField.style.display  = isExternal ? '' : 'none';
        if (runpodFields) runpodFields.style.display = isExternal ? '' : 'none';
    }

    // Run immediately so the UI always reflects the current type value
    toggleApiFields();

    if (typeSelect) {
        typeSelect.addEventListener('change', toggleApiFields);
    }
});
</script>
@endpush
@endsection
