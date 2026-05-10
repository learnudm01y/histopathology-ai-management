@extends('admin.layouts.app')
@section('title', 'RunPod Pods — ' . $server->name)

@section('content')
<div class="page-header">
    <h3 class="page-title">
        <i class="mdi mdi-server-network mr-2"></i>RunPod Pods
        <small class="text-muted ml-2">{{ $server->name }}</small>
    </h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item">Settings</li>
            <li class="breadcrumb-item"><a href="{{ route('admin.settings.servers.index') }}">Servers</a></li>
            <li class="breadcrumb-item active">RunPod Pods</li>
        </ol>
    </nav>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="mdi mdi-check-circle mr-1"></i> {{ session('success') }}
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="mdi mdi-alert-circle mr-1"></i> {{ session('error') }}
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
@endif

{{-- Current active URL --}}
<div class="row mb-3">
    <div class="col-12">
        <div class="card border-left-info">
            <div class="card-body py-2">
                <div class="d-flex align-items-center">
                    <i class="mdi mdi-link-variant text-info mr-2" style="font-size:1.4rem"></i>
                    <div>
                        <small class="text-muted d-block">Current Active API URL</small>
                        <strong id="current-api-url">{{ $server->api_url ?? '— not set —' }}</strong>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary ml-auto" onclick="refreshPods()">
                        <i class="mdi mdi-refresh"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Pod list --}}
<div class="row" id="pods-container">
    @forelse($pods as $pod)
        @php
            $running = $pod['desiredStatus'] === 'RUNNING';
            $stopped = in_array($pod['desiredStatus'], ['EXITED', 'TERMINATED', 'STOPPED']);
            $gpu = $pod['machine']['gpuDisplayName'] ?? 'Unknown GPU';
            $location = $pod['machine']['location'] ?? '';
            $volume = $pod['networkVolume']['name'] ?? null;
            $volumeId = $pod['networkVolume']['id'] ?? null;
            $hasVolume = $volumeId === '{{ config("runpod.network_volume_id", "") }}' || $volume;
            $uptime = $pod['runtime']['uptimeInSeconds'] ?? null;
            $podProxy = 'https://' . $pod['id'] . '-8000.proxy.runpod.net';
            $isSelected = $server->api_url === $podProxy;
        @endphp
        <div class="col-lg-6 col-xl-4 mb-4 pod-card" data-pod-id="{{ $pod['id'] }}" data-status="{{ $pod['desiredStatus'] }}">
            <div class="card h-100 {{ $isSelected ? 'border-success' : '' }}">
                <div class="card-header d-flex align-items-center justify-content-between py-2">
                    <span class="font-weight-bold text-truncate" style="max-width:60%">
                        {{ $pod['name'] ?? $pod['id'] }}
                    </span>
                    <span class="badge badge-{{ $running ? 'success' : ($stopped ? 'secondary' : 'warning') }} pod-status-badge">
                        {{ $pod['desiredStatus'] }}
                    </span>
                </div>
                <div class="card-body py-3">
                    <div class="small mb-2">
                        <i class="mdi mdi-gpu text-muted mr-1"></i>
                        <strong>{{ $gpu }}</strong>
                        @if($location) <span class="text-muted ml-1">({{ $location }})</span> @endif
                    </div>
                    <div class="small mb-2">
                        <i class="mdi mdi-identifier text-muted mr-1"></i>
                        <code class="small">{{ $pod['id'] }}</code>
                    </div>
                    @if($volume)
                    <div class="small mb-2">
                        <i class="mdi mdi-harddisk text-muted mr-1"></i>
                        {{ $volume }}
                        <span class="text-muted">({{ $volumeId }})</span>
                    </div>
                    @endif
                    @if($running && $uptime !== null)
                    <div class="small mb-2">
                        <i class="mdi mdi-clock-outline text-muted mr-1"></i>
                        Uptime: {{ gmdate('H:i:s', $uptime) }}
                    </div>
                    @endif
                    <div class="small mb-2">
                        <i class="mdi mdi-currency-usd text-muted mr-1"></i>
                        ${{ number_format($pod['costPerHr'] ?? 0, 3) }}/hr
                    </div>
                    @if($running)
                    <div class="small">
                        <i class="mdi mdi-link text-muted mr-1"></i>
                        <a href="{{ $podProxy }}/health" target="_blank" rel="noopener" class="text-truncate d-inline-block" style="max-width:200px">
                            {{ $podProxy }}
                        </a>
                    </div>
                    @endif

                    @if($isSelected)
                    <div class="mt-2">
                        <span class="badge badge-success"><i class="mdi mdi-check mr-1"></i>Active Server</span>
                    </div>
                    @endif
                </div>
                <div class="card-footer bg-transparent py-2">
                    <div class="d-flex gap-2" style="gap:.5rem">
                        @if($stopped)
                            <button class="btn btn-success btn-sm flex-fill pod-action-btn"
                                    data-action="start"
                                    data-pod-id="{{ $pod['id'] }}"
                                    data-pod-name="{{ $pod['name'] ?? $pod['id'] }}">
                                <i class="mdi mdi-play mr-1"></i> Start
                            </button>
                        @elseif($running)
                            <button class="btn btn-warning btn-sm pod-action-btn"
                                    data-action="stop"
                                    data-pod-id="{{ $pod['id'] }}"
                                    data-pod-name="{{ $pod['name'] ?? $pod['id'] }}">
                                <i class="mdi mdi-stop mr-1"></i> Stop
                            </button>
                            <button class="btn btn-primary btn-sm flex-fill pod-action-btn {{ $isSelected ? 'disabled' : '' }}"
                                    data-action="select"
                                    data-pod-id="{{ $pod['id'] }}"
                                    data-pod-name="{{ $pod['name'] ?? $pod['id'] }}"
                                    {{ $isSelected ? 'disabled' : '' }}>
                                <i class="mdi mdi-check-circle mr-1"></i>
                                {{ $isSelected ? 'Selected' : 'Use This Pod' }}
                            </button>
                        @else
                            <button class="btn btn-secondary btn-sm flex-fill" disabled>
                                <i class="mdi mdi-loading mdi-spin mr-1"></i> {{ $pod['desiredStatus'] }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5 text-muted">
                    <i class="mdi mdi-server-off" style="font-size:3rem"></i>
                    <p class="mt-3">No pods found in this RunPod account.</p>
                </div>
            </div>
        </div>
    @endforelse
</div>
@endsection

@push('scripts')
<script>
const CSRF   = document.querySelector('meta[name="csrf-token"]').content;
const SERVER = {{ $server->id }};

const URLS = {
    start:   '{{ route("admin.settings.servers.runpod.start",   $server) }}',
    stop:    '{{ route("admin.settings.servers.runpod.stop",    $server) }}',
    select:  '{{ route("admin.settings.servers.runpod.select",  $server) }}',
    refresh: '{{ route("admin.settings.servers.runpod.refresh", $server) }}',
};

// ── Button click handlers ──────────────────────────────────────────────────
document.querySelectorAll('.pod-action-btn').forEach(btn => {
    btn.addEventListener('click', () => handleAction(btn));
});

async function handleAction(btn) {
    const action  = btn.dataset.action;
    const podId   = btn.dataset.podId;
    const podName = btn.dataset.podName;

    if (action === 'stop' && !confirm(`Stop pod "${podName}"?`)) return;
    if (action === 'select' && !confirm(`Use pod "${podName}" as the active server? This will update the API URL.`)) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="mdi mdi-loading mdi-spin mr-1"></i> …';

    try {
        const res = await fetch(URLS[action], {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body:    JSON.stringify({ pod_id: podId }),
        });
        const data = await res.json();

        if (data.success) {
            showAlert('success', data.message || 'Done.');
            if (action === 'select' && data.api_url) {
                document.getElementById('current-api-url').textContent = data.api_url;
            }
            // Refresh cards after 3 seconds so status updates
            setTimeout(refreshPods, 3000);
        } else {
            showAlert('danger', data.message || 'Error.');
            btn.disabled = false;
            btn.innerHTML = restoreLabel(action);
        }
    } catch (err) {
        showAlert('danger', 'Network error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = restoreLabel(action);
    }
}

// ── Refresh pod list ───────────────────────────────────────────────────────
async function refreshPods() {
    try {
        const res  = await fetch(URLS.refresh, { headers: { 'X-CSRF-TOKEN': CSRF } });
        const data = await res.json();
        if (data.success) {
            // Reload page to re-render Blade cards
            window.location.reload();
        } else {
            showAlert('danger', data.message);
        }
    } catch (err) {
        showAlert('danger', 'Refresh failed: ' + err.message);
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────
function showAlert(type, msg) {
    const el = document.createElement('div');
    el.className = `alert alert-${type} alert-dismissible fade show`;
    el.innerHTML = `${msg} <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>`;
    document.querySelector('.page-header').after(el);
    setTimeout(() => el.remove(), 6000);
}

function restoreLabel(action) {
    const labels = {
        start:  '<i class="mdi mdi-play mr-1"></i> Start',
        stop:   '<i class="mdi mdi-stop mr-1"></i> Stop',
        select: '<i class="mdi mdi-check-circle mr-1"></i> Use This Pod',
    };
    return labels[action] || action;
}
</script>
@endpush
