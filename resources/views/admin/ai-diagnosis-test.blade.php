@extends('admin.layouts.app')

@section('title', 'AI - Diagnosis Test')

@section('content')

{{-- ─── Page Header ───────────────────────────────────────────────────── --}}
<div class="page-header">
    <h3 class="page-title">
        <i class="mdi mdi-flask-outline mr-2"></i>AI &mdash; Diagnosis Test
    </h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">AI - Diagnosis Test</li>
        </ol>
    </nav>
</div>

{{-- ─── Alerts ─────────────────────────────────────────────────────────── --}}
@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="mdi mdi-check-circle mr-1"></i>{{ session('success') }}
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
@endif

@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <strong><i class="mdi mdi-alert-circle-outline mr-1"></i>Validation Error:</strong>
        <ul class="mb-0 mt-1">
            @foreach($errors->all() as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════════ --}}
{{-- DISPATCH FORM                                                          --}}
{{-- ═══════════════════════════════════════════════════════════════════════ --}}
<form method="POST" action="{{ route('admin.ai-diagnosis-test.dispatch') }}" id="inferenceForm">
    @csrf

    {{-- ─── STEP 1 — Select Trained Model ─────────────────────────────── --}}
    <div class="row">
        <div class="col-12 grid-margin">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white py-2">
                    <h5 class="mb-0">
                        <span class="badge badge-light text-primary mr-2">1</span>
                        <i class="mdi mdi-brain mr-1"></i>
                        Select Trained Model
                    </h5>
                </div>
                <div class="card-body">
                    <p class="card-description text-muted">
                        Choose a completed training run whose checkpoint will be used for inference.
                        Only runs with a saved model checkpoint are listed.
                    </p>

                    @if($trainingRuns->isEmpty())
                        <div class="alert alert-warning mb-0">
                            <i class="mdi mdi-information-outline mr-1"></i>
                            No completed training runs found. Go to
                            <a href="{{ route('admin.workflow') }}">Operations → Model Training</a>
                            to train a model first.
                        </div>
                    @else
                        <div class="row">
                            {{-- Dropdown select --}}
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="training_run_id">Training Run <span class="text-danger">*</span></label>
                                    <select name="training_run_id"
                                            id="training_run_id"
                                            class="form-control @error('training_run_id') is-invalid @enderror"
                                            required>
                                        <option value="">— Select a training run —</option>
                                        @foreach($trainingRuns as $tr)
                                            <option value="{{ $tr->id }}"
                                                data-head="{{ $tr->trainingHead?->name ?? '—' }}"
                                                data-feature="{{ $tr->featureModel?->name ?? '—' }}"
                                                data-type="{{ strtoupper($tr->model_type) }}"
                                                data-classes="{{ $tr->n_classes }}"
                                                data-auc="{{ isset($tr->metrics['best_val_auc']) ? number_format($tr->metrics['best_val_auc'], 4) : '—' }}"
                                                data-checkpoint="{{ $tr->model_gdrive_path ?? '—' }}"
                                                data-labelmap="{{ json_encode($tr->label_map ?? []) }}"
                                                {{ ($selectedRunId && $selectedRunId === $tr->id) ? 'selected' : '' }}>
                                                Run #{{ $tr->id }}
                                                — {{ $tr->trainingHead?->name ?? 'Unknown Head' }}
                                                ({{ $tr->featureModel?->name ?? '?' }})
                                                @if(isset($tr->metrics['best_val_auc']))
                                                    — AUC: {{ number_format($tr->metrics['best_val_auc'], 3) }}
                                                @endif
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('training_run_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            {{-- Model details panel --}}
                            <div class="col-md-6">
                                <div id="modelDetailsPanel" class="d-none">
                                    <label class="d-block text-muted small mb-1">Model Details</label>
                                    <div class="p-3 bg-light rounded border">
                                        <div class="row text-sm">
                                            <div class="col-6 mb-1">
                                                <span class="text-muted">Head:</span>
                                                <strong id="detailHead">—</strong>
                                            </div>
                                            <div class="col-6 mb-1">
                                                <span class="text-muted">Arch:</span>
                                                <strong id="detailType">—</strong>
                                            </div>
                                            <div class="col-6 mb-1">
                                                <span class="text-muted">Features:</span>
                                                <strong id="detailFeature">—</strong>
                                            </div>
                                            <div class="col-6 mb-1">
                                                <span class="text-muted">Classes:</span>
                                                <strong id="detailClasses">—</strong>
                                            </div>
                                            <div class="col-6 mb-1">
                                                <span class="text-muted">Best AUC:</span>
                                                <strong id="detailAuc" class="text-success">—</strong>
                                            </div>
                                            <div class="col-12 mt-1">
                                                <span class="text-muted small">Label Map:</span>
                                                <span id="detailLabelMap" class="badge badge-light text-dark small">—</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Server selection --}}
                        <div class="row mt-2">
                            <div class="col-md-5">
                                <div class="form-group mb-0">
                                    <label for="server_id">Inference Server <span class="text-danger">*</span></label>
                                    <select name="server_id"
                                            id="server_id"
                                            class="form-control @error('server_id') is-invalid @enderror"
                                            required>
                                        <option value="">— Select server —</option>
                                        @foreach($servers as $srv)
                                            <option value="{{ $srv->id }}" {{ old('server_id') == $srv->id ? 'selected' : '' }}>
                                                {{ $srv->name }}
                                                @if($srv->runpod_port) (port {{ $srv->runpod_port }})@endif
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('server_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ─── STEP 2 — Select Test Slide ─────────────────────────────────── --}}
    <div class="row {{ $trainingRuns->isEmpty() ? 'd-none' : '' }}" id="slideSelectionSection">
        <div class="col-12 grid-margin">
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark py-2">
                    <h5 class="mb-0">
                        <span class="badge badge-dark mr-2">2</span>
                        <i class="mdi mdi-image-outline mr-1"></i>
                        Select Test Slide
                    </h5>
                </div>
                <div class="card-body">
                    {{-- Hidden input for slide_source --}}
                    <input type="hidden" name="slide_source" id="slideSourceInput" value="sample">

                    {{-- Tab pills --}}
                    <ul class="nav nav-pills mb-3" id="slideSourceTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="tab-sample" data-toggle="pill"
                               href="#panel-sample" role="tab" aria-selected="true"
                               onclick="document.getElementById('slideSourceInput').value='sample'">
                                <i class="mdi mdi-database-search mr-1"></i>From DB Samples
                            </a>
                        </li>
                        <li class="nav-item ml-2">
                            <a class="nav-link" id="tab-gdrive" data-toggle="pill"
                               href="#panel-gdrive" role="tab" aria-selected="false"
                               onclick="document.getElementById('slideSourceInput').value='gdrive'">
                                <i class="mdi mdi-google-drive mr-1"></i>GDrive Features Path
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content">
                        {{-- ── TAB: FROM DB SAMPLES ──────────────────────────────────── --}}
                        <div class="tab-pane fade show active" id="panel-sample" role="tabpanel">
                            <p class="text-muted small mb-2">
                                <i class="mdi mdi-information-outline mr-1"></i>
                                Only samples whose features were extracted with the <strong>same feature model</strong>
                                used in the selected training run are eligible.
                                <span id="eligibleModelNote" class="badge badge-primary ml-1"></span>
                            </p>

                            {{-- Loading spinner --}}
                            <div id="samplesLoading" class="text-center py-3 d-none">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="sr-only">Loading…</span>
                                </div>
                                <p class="text-muted mt-2 small">Loading eligible samples…</p>
                            </div>

                            {{-- No run selected yet --}}
                            <div id="samplesNoRun" class="alert alert-light border">
                                <i class="mdi mdi-arrow-up-circle-outline mr-1"></i>
                                Select a training run above to see eligible samples.
                            </div>

                            {{-- Samples table --}}
                            <div id="samplesTableWrapper" class="d-none">
                                <div id="samplesEmptyMsg" class="alert alert-warning d-none">
                                    <i class="mdi mdi-alert-outline mr-1"></i>
                                    No samples with completed feature extraction found for the selected feature model.
                                </div>

                                <div class="table-responsive" id="samplesTableContainer">
                                    <table class="table table-hover table-sm" id="samplesTable">
                                        <thead class="thead-light">
                                            <tr>
                                                <th style="width:40px;">
                                                    <input type="radio" name="_sample_select_all" disabled>
                                                </th>
                                                <th>#</th>
                                                <th>File Name</th>
                                                <th>Category</th>
                                                <th>GDrive Features Path</th>
                                            </tr>
                                        </thead>
                                        <tbody id="samplesTableBody">
                                            {{-- Populated by JS --}}
                                        </tbody>
                                    </table>
                                </div>

                                {{-- Hidden inputs for selected sample --}}
                                <input type="hidden" name="sample_id" id="selectedSampleId" value="{{ old('sample_id') }}">
                                @error('sample_id')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        {{-- ── TAB: GDRIVE FEATURES PATH ────────────────────────────── --}}
                        <div class="tab-pane fade" id="panel-gdrive" role="tabpanel">
                            <p class="text-muted small mb-3">
                                <i class="mdi mdi-information-outline mr-1"></i>
                                Provide the Google Drive path to a pre-computed <code>.h5</code> features file
                                for a slide <strong>not already in the database</strong>.
                                The features must have been extracted using the <strong>same feature model</strong>
                                as the selected training run.
                            </p>

                            <div class="row">
                                <div class="col-md-5">
                                    <div class="form-group">
                                        <label for="slide_name">
                                            Slide Name / Label
                                            <span class="text-danger">*</span>
                                            <small class="text-muted">(display name only)</small>
                                        </label>
                                        <input type="text"
                                               name="slide_name"
                                               id="slide_name"
                                               class="form-control @error('slide_name') is-invalid @enderror"
                                               placeholder="e.g. TCGA-AB-1234.svs"
                                               value="{{ old('slide_name') }}">
                                        @error('slide_name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-7">
                                    <div class="form-group">
                                        <label for="slide_features_gdrive_path">
                                            GDrive Features Path (.h5)
                                            <span class="text-danger">*</span>
                                        </label>
                                        <input type="text"
                                               name="slide_features_gdrive_path"
                                               id="slide_features_gdrive_path"
                                               class="form-control @error('slide_features_gdrive_path') is-invalid @enderror"
                                               placeholder="e.g. samples/features/TITAN/sample_99/features.h5"
                                               value="{{ old('slide_features_gdrive_path') }}">
                                        <small class="form-text text-muted">
                                            Path relative to the GDrive root configured in rclone
                                            (gdrive:&lt;path&gt;).
                                        </small>
                                        @error('slide_features_gdrive_path')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ─── STEP 3 — Dispatch ──────────────────────────────────────────── --}}
    <div class="row {{ $trainingRuns->isEmpty() ? 'd-none' : '' }}" id="dispatchSection">
        <div class="col-12 grid-margin">
            <div class="card">
                <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <h5 class="mb-1">
                            <i class="mdi mdi-rocket-launch-outline mr-1"></i>
                            Ready to Run?
                        </h5>
                        <p class="text-muted mb-0 small">
                            The inference job will be dispatched to the selected server via the queue.
                            Results appear in the history table below once the run completes.
                        </p>
                    </div>
                    <button type="submit"
                            class="btn btn-success btn-lg px-4"
                            id="dispatchBtn">
                        <i class="mdi mdi-flask mr-1"></i>
                        Run AI Diagnosis Test
                    </button>
                </div>
            </div>
        </div>
    </div>

</form>

{{-- ═══════════════════════════════════════════════════════════════════════ --}}
{{-- HISTORY TABLE                                                          --}}
{{-- ═══════════════════════════════════════════════════════════════════════ --}}
<div class="row">
    <div class="col-12 grid-margin">
        <div class="card">
            <div class="card-header py-2 d-flex align-items-center justify-content-between">
                <h5 class="mb-0">
                    <i class="mdi mdi-history mr-1"></i>
                    Inference History
                    <span class="badge badge-secondary ml-1">{{ $history->total() }}</span>
                </h5>
                <a href="{{ route('admin.ai-diagnosis-test') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="mdi mdi-refresh mr-1"></i>Refresh
                </a>
            </div>
            <div class="card-body p-0">
                @if($history->isEmpty())
                    <div class="p-4 text-center text-muted">
                        <i class="mdi mdi-flask-empty-outline" style="font-size:2rem;"></i>
                        <p class="mt-2 mb-0">No inference runs yet. Run your first test above.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>Training Run</th>
                                    <th>Slide</th>
                                    <th>Source</th>
                                    <th>Status</th>
                                    <th>Prediction</th>
                                    <th>Confidence</th>
                                    <th>GDrive Output</th>
                                    <th>Date</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($history as $run)
                                    <tr id="inferenceRow{{ $run->id }}">
                                        <td><code class="small">{{ $run->id }}</code></td>
                                        <td>
                                            <span class="badge badge-primary small">Run #{{ $run->training_run_id }}</span>
                                            <br>
                                            <small class="text-muted">
                                                {{ $run->trainingRun?->trainingHead?->name ?? '—' }}
                                                /
                                                {{ $run->trainingRun?->featureModel?->name ?? '—' }}
                                            </small>
                                        </td>
                                        <td>
                                            <span class="text-truncate d-inline-block" style="max-width:180px;"
                                                  title="{{ $run->slide_name }}">
                                                {{ $run->slide_name ?? '—' }}
                                            </span>
                                            @if($run->sample)
                                                <br><small class="text-muted">Sample #{{ $run->sample_id }}</small>
                                            @endif
                                        </td>
                                        <td>
                                            @if($run->slide_source === 'sample')
                                                <span class="badge badge-light border">DB</span>
                                            @else
                                                <span class="badge badge-light border">
                                                    <i class="mdi mdi-google-drive"></i> GDrive
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge badge-{{ $run->status_badge_class }}"
                                                  id="status{{ $run->id }}">
                                                {{ ucfirst($run->status) }}
                                            </span>
                                        </td>
                                        <td>
                                            @if($run->status === 'completed' && $run->prediction)
                                                <strong class="text-{{ $run->prediction['class_index'] == 0 ? 'success' : 'danger' }}">
                                                    {{ $run->prediction['class_label'] ?? '—' }}
                                                </strong>
                                            @elseif($run->status === 'failed')
                                                <span class="text-danger small" title="{{ $run->error }}">
                                                    <i class="mdi mdi-alert-outline"></i> Error
                                                </span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td id="confidence{{ $run->id }}">
                                            @if($run->status === 'completed' && $run->prediction)
                                                <div class="d-flex align-items-center">
                                                    <div class="progress mr-2" style="width:60px;height:8px;">
                                                        <div class="progress-bar bg-success"
                                                             style="width:{{ number_format(($run->prediction['confidence'] ?? 0) * 100, 0) }}%">
                                                        </div>
                                                    </div>
                                                    <span class="small">{{ $run->confidence_percent }}</span>
                                                </div>
                                            @else
                                                <span class="text-muted small">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($run->gdrive_output_dir)
                                                <code class="small text-muted" title="{{ $run->gdrive_output_dir }}">
                                                    {{ Str::limit($run->gdrive_output_dir, 30) }}
                                                </code>
                                            @else
                                                <span class="text-muted small">—</span>
                                            @endif
                                        </td>
                                        <td class="text-muted small">
                                            {{ $run->created_at->format('Y-m-d H:i') }}
                                        </td>
                                        <td>
                                            {{-- Detail modal trigger --}}
                                            @if($run->prediction || $run->error)
                                                <button type="button"
                                                        class="btn btn-xs btn-outline-secondary"
                                                        data-toggle="modal"
                                                        data-target="#detailModal{{ $run->id }}">
                                                    <i class="mdi mdi-eye-outline"></i>
                                                </button>
                                            @endif

                                            {{-- Poll button for running jobs --}}
                                            @if(in_array($run->status, ['pending', 'processing']))
                                                <button type="button"
                                                        class="btn btn-xs btn-outline-info ml-1"
                                                        onclick="pollStatus({{ $run->id }})">
                                                    <i class="mdi mdi-refresh"></i>
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Pagination --}}
                    @if($history->hasPages())
                        <div class="p-3 border-top">
                            {{ $history->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>

@endsection

{{-- ═══════════════════════════════════════════════════════════════════════ --}}
{{-- DETAIL MODALS                                                          --}}
{{-- ═══════════════════════════════════════════════════════════════════════ --}}
@push('modals')
    @foreach($history as $run)
        @if($run->prediction || $run->error)
            <div class="modal fade" id="detailModal{{ $run->id }}" tabindex="-1" role="dialog"
                 aria-labelledby="detailModalLabel{{ $run->id }}" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="detailModalLabel{{ $run->id }}">
                                <i class="mdi mdi-flask mr-1"></i>
                                Inference Run #{{ $run->id }} — {{ $run->slide_name }}
                            </h5>
                            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        </div>
                        <div class="modal-body">
                            @if($run->status === 'completed' && $run->prediction)
                                {{-- Prediction result --}}
                                <div class="text-center mb-3">
                                    <div class="display-4 font-weight-bold
                                        {{ ($run->prediction['class_index'] ?? -1) == 0 ? 'text-success' : 'text-danger' }}">
                                        {{ $run->prediction['class_label'] ?? '—' }}
                                    </div>
                                    <div class="mt-1 text-muted">
                                        Confidence: <strong>{{ $run->confidence_percent }}</strong>
                                    </div>
                                </div>

                                {{-- Probabilities bar chart --}}
                                @if(!empty($run->prediction['probabilities']))
                                    <h6 class="text-muted small mb-2">Class Probabilities</h6>
                                    @foreach($run->prediction['probabilities'] as $classIdx => $prob)
                                        @php
                                            $labelMap  = $run->trainingRun?->label_map ?? [];
                                            $label     = $labelMap[$classIdx] ?? "Class {$classIdx}";
                                            $pct       = number_format($prob * 100, 1);
                                            $barClass  = $classIdx == ($run->prediction['class_index'] ?? -1)
                                                ? 'bg-primary' : 'bg-secondary';
                                        @endphp
                                        <div class="mb-2">
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span>{{ $label }}</span>
                                                <strong>{{ $pct }}%</strong>
                                            </div>
                                            <div class="progress" style="height:10px;">
                                                <div class="progress-bar {{ $barClass }}"
                                                     style="width:{{ $pct }}%"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                @endif

                                {{-- Attention map --}}
                                @if($run->attention_map_gdrive_path)
                                    <hr>
                                    <h6 class="text-muted small mb-1">Attention Map (GDrive)</h6>
                                    <code class="small">{{ $run->attention_map_gdrive_path }}</code>
                                @endif

                                {{-- Metadata --}}
                                <hr>
                                <dl class="row small mb-0">
                                    <dt class="col-5 text-muted">Training Run</dt>
                                    <dd class="col-7">Run #{{ $run->training_run_id }}</dd>
                                    <dt class="col-5 text-muted">Head</dt>
                                    <dd class="col-7">{{ $run->trainingRun?->trainingHead?->name ?? '—' }}</dd>
                                    <dt class="col-5 text-muted">Feature Model</dt>
                                    <dd class="col-7">{{ $run->trainingRun?->featureModel?->name ?? '—' }}</dd>
                                    <dt class="col-5 text-muted">GDrive Output</dt>
                                    <dd class="col-7"><code>{{ $run->gdrive_output_dir ?? '—' }}</code></dd>
                                    <dt class="col-5 text-muted">Finished At</dt>
                                    <dd class="col-7">{{ $run->finished_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                                </dl>

                            @elseif($run->status === 'failed')
                                <div class="alert alert-danger mb-0">
                                    <strong>Error:</strong><br>
                                    <pre class="mb-0 small" style="white-space:pre-wrap;">{{ $run->error }}</pre>
                                </div>
                            @endif
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endforeach
@endpush

@push('scripts')
<script>
(function () {
    'use strict';

    /* ── DOM refs ─────────────────────────────────────────────────────── */
    const runSelect        = document.getElementById('training_run_id');
    const modelPanel       = document.getElementById('modelDetailsPanel');
    const samplesNoRun     = document.getElementById('samplesNoRun');
    const samplesLoading   = document.getElementById('samplesLoading');
    const samplesWrapper   = document.getElementById('samplesTableWrapper');
    const samplesEmpty     = document.getElementById('samplesEmptyMsg');
    const samplesBody      = document.getElementById('samplesTableBody');
    const selectedSampleId = document.getElementById('selectedSampleId');
    const eligibleNote     = document.getElementById('eligibleModelNote');

    /* ── Training run dropdown change ─────────────────────────────────── */
    if (runSelect) {
        runSelect.addEventListener('change', function () {
            const opt = runSelect.options[runSelect.selectedIndex];
            if (!opt || !opt.value) {
                modelPanel.classList.add('d-none');
                resetSamplesTable();
                return;
            }

            // Update model details panel
            document.getElementById('detailHead').textContent    = opt.dataset.head    || '—';
            document.getElementById('detailType').textContent    = opt.dataset.type    || '—';
            document.getElementById('detailFeature').textContent = opt.dataset.feature || '—';
            document.getElementById('detailClasses').textContent = opt.dataset.classes || '—';
            document.getElementById('detailAuc').textContent     = opt.dataset.auc     || '—';

            try {
                const lm = JSON.parse(opt.dataset.labelmap || '{}');
                const lmStr = Object.entries(lm).map(([k,v]) => k + '→' + v).join(', ');
                document.getElementById('detailLabelMap').textContent = lmStr || '—';
            } catch(e) {
                document.getElementById('detailLabelMap').textContent = '—';
            }
            modelPanel.classList.remove('d-none');

            // Load eligible samples for this run
            loadSamplesForRun(opt.value, opt.dataset.feature);
        });

        // Trigger on page load if a run is pre-selected
        if (runSelect.value) {
            runSelect.dispatchEvent(new Event('change'));
        }
    }

    /* ── Load eligible samples via AJAX ───────────────────────────────── */
    function loadSamplesForRun(runId, featureModelName) {
        samplesNoRun.classList.add('d-none');
        samplesLoading.classList.remove('d-none');
        samplesWrapper.classList.add('d-none');
        samplesEmpty.classList.add('d-none');
        samplesBody.innerHTML = '';
        selectedSampleId.value = '';

        if (eligibleNote) {
            eligibleNote.textContent = featureModelName ? 'Feature Model: ' + featureModelName : '';
        }

        fetch('{{ route('admin.ai-diagnosis-test.samples-for-run', ':id') }}'.replace(':id', runId), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            samplesLoading.classList.add('d-none');
            samplesWrapper.classList.remove('d-none');

            if (!data.samples || data.samples.length === 0) {
                samplesEmpty.classList.remove('d-none');
                return;
            }

            data.samples.forEach(function (s) {
                const tr = document.createElement('tr');
                tr.style.cursor = 'pointer';
                tr.dataset.sampleId = s.id;
                tr.innerHTML = `
                    <td>
                        <div class="form-check">
                            <input type="radio" name="_sample_radio" class="form-check-input sample-radio"
                                   value="${s.id}" id="radio_${s.id}"
                                   ${selectedSampleId.value == s.id ? 'checked' : ''}>
                        </div>
                    </td>
                    <td><code class="small">${s.id}</code></td>
                    <td class="text-truncate" style="max-width:220px;" title="${escHtml(s.file_name)}">
                        ${escHtml(s.file_name)}
                    </td>
                    <td>${escHtml(s.category ? s.category.label_en : '—')}</td>
                    <td class="text-muted small text-truncate" style="max-width:200px;"
                        title="${escHtml(s.features_gdrive_path)}">
                        ${escHtml(s.features_gdrive_path)}
                    </td>`;
                tr.addEventListener('click', function () {
                    tr.querySelector('.sample-radio').checked = true;
                    selectedSampleId.value = s.id;
                    // Highlight
                    samplesBody.querySelectorAll('tr').forEach(r => r.classList.remove('table-active'));
                    tr.classList.add('table-active');
                });
                samplesBody.appendChild(tr);
            });

            // Set initial selection from hidden input
            if (selectedSampleId.value) {
                const selRow = samplesBody.querySelector(`tr[data-sample-id="${selectedSampleId.value}"]`);
                if (selRow) selRow.classList.add('table-active');
            }
        })
        .catch(() => {
            samplesLoading.classList.add('d-none');
            samplesWrapper.classList.remove('d-none');
            samplesEmpty.textContent = 'Failed to load samples. Please refresh the page.';
            samplesEmpty.classList.remove('d-none');
        });
    }

    function resetSamplesTable() {
        samplesNoRun.classList.remove('d-none');
        samplesLoading.classList.add('d-none');
        samplesWrapper.classList.add('d-none');
        samplesEmpty.classList.add('d-none');
        samplesBody.innerHTML = '';
        selectedSampleId.value = '';
    }

    /* ── Radio click updates hidden input ──────────────────────────────── */
    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('sample-radio')) {
            selectedSampleId.value = e.target.value;
        }
    });

    /* ── Tab switch: update slide_source hidden input ─────────────────── */
    document.querySelectorAll('#slideSourceTabs .nav-link').forEach(function (tab) {
        tab.addEventListener('click', function () {
            const src = this.id === 'tab-gdrive' ? 'gdrive' : 'sample';
            document.getElementById('slideSourceInput').value = src;
        });
    });

    /* ── Form validation ──────────────────────────────────────────────── */
    document.getElementById('inferenceForm')?.addEventListener('submit', function (e) {
        const src = document.getElementById('slideSourceInput').value;
        if (src === 'sample' && !selectedSampleId.value) {
            e.preventDefault();
            alert('Please select a sample from the table, or switch to the "GDrive Features Path" tab to enter a path manually.');
            return false;
        }
        document.getElementById('dispatchBtn').disabled = true;
        document.getElementById('dispatchBtn').innerHTML =
            '<span class="spinner-border spinner-border-sm mr-1"></span> Dispatching…';
    });

    /* ── Poll status for running inference runs ────────────────────────── */
    window.pollStatus = function (runId) {
        fetch('{{ route('admin.ai-diagnosis-test.status', ':id') }}'.replace(':id', runId), {
            headers: { 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(data => {
            const statusBadge = document.getElementById('status' + runId);
            if (statusBadge) {
                const classMap = { completed: 'success', processing: 'info', failed: 'danger', pending: 'secondary' };
                statusBadge.className = 'badge badge-' + (classMap[data.status] || 'secondary');
                statusBadge.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
            }
            if (data.status === 'completed' || data.status === 'failed') {
                setTimeout(() => window.location.reload(), 800);
            } else {
                alert('Status: ' + data.status + '. Still running…');
            }
        })
        .catch(() => alert('Failed to fetch status. Check your connection.'));
    };

    /* ── Utility ──────────────────────────────────────────────────────── */
    function escHtml(str) {
        if (!str) return '—';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
</script>
@endpush
