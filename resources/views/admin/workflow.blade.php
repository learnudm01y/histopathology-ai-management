@extends('admin.layouts.app')

@section('title', 'Operations')

@section('content')
    {{-- ─── Page Header ─────────────────────────────────────────────────── --}}
    <div class="page-header">
        <h3 class="page-title">
            <i class="mdi mdi-cogs mr-2"></i>Operations
        </h3>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Operations</li>
            </ol>
        </nav>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="mdi mdi-check-circle mr-1"></i>{{ session('success') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Validation Error:</strong>
            <ul class="mb-0 mt-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    {{-- STEP 1 — Select Operation Type --}}
    <div class="row">
        <div class="col-12 grid-margin">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">
                        <span class="badge badge-primary mr-2" style="font-size:.85rem;">1</span>
                        Select Operation
                    </h4>
                    <p class="card-description">
                        Choose the pipeline operation you want to execute on a set of slides.
                    </p>
                    <div class="row">
                        <div class="col-md-5">
                            <div class="form-group mb-0">
                                <label for="operationTypeSelect">Operation Type</label>
                                <select id="operationTypeSelect" class="form-control form-control-lg">
                                    <option value="">— Select an operation —</option>
                                    <option value="patch_extraction"
                                        {{ ($filters['operation_type'] ?? '') === 'patch_extraction' ? 'selected' : '' }}>
                                        🔲 Patch Extraction (Slide Tiling)
                                    </option>
                                    <option value="feature_extraction"
                                        {{ ($filters['operation_type'] ?? '') === 'feature_extraction' ? 'selected' : '' }}>
                                        📊 Feature Extraction (RunPod)
                                    </option>
                                    <option value="training"
                                        {{ ($filters['operation_type'] ?? '') === 'training' ? 'selected' : '' }}>
                                        🧠 Model Training
                                    </option>
                                    <option value="inference" disabled>
                                        🔍 Inference / Prediction (coming soon)
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ─── FEATURE EXTRACTION QUICK DISPATCH (RunPod) ──────────────────── --}}
    <div class="row {{ ($filters['operation_type'] ?? '') !== 'feature_extraction' ? 'd-none' : '' }}"
         id="featureExtractionSection">
        <div class="col-12 grid-margin">
            <div class="card border-info">
                <div class="card-header bg-info text-white py-2">
                    <h5 class="mb-0">
                        <span class="badge badge-light text-info mr-2">2</span>
                        <i class="mdi mdi-flask-outline mr-1"></i>
                        Feature Extraction — RunPod GPU Server (Virchow2 / TITAN)
                    </h5>
                </div>
                <div class="card-body">
                    <p class="card-description">
                        Send <strong>tiled samples</strong> to an external GPU server (RunPod) running TITAN / CONCH.
                        Only samples with <code>tiling_status = done</code> are eligible.
                        The output features are stored on Google Drive under
                        <code>{{ config('gdrive.root_folder', 'samples') }}/features/&lt;model&gt;/&hellip;</code>.
                    </p>

                    <form method="POST" action="{{ route('admin.workflow.dispatch.feature-extraction') }}" id="featureExtractionForm">
                        @csrf

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="feSelectServer">
                                        <i class="mdi mdi-server mr-1"></i>GPU Server (external)
                                    </label>
                                    @php $externalServers = $servers->where('type', 'external'); @endphp
                                    @if($externalServers->isEmpty())
                                        <div class="alert alert-warning py-2 px-3 mb-1" style="font-size:.85rem;">
                                            <i class="mdi mdi-alert-outline mr-1"></i>
                                            No external GPU servers configured.
                                            <a href="{{ route('admin.settings.servers.create') }}" class="alert-link">Add one now →</a>
                                        </div>
                                        <input type="hidden" name="server_id" value="">
                                    @else
                                        <select name="server_id" id="feSelectServer" class="form-control" required>
                                            <option value="">— Choose server —</option>
                                            @foreach($externalServers as $srv)
                                                <option value="{{ $srv->id }}">
                                                    {{ $srv->name }}
                                                    @if($srv->host) ({{ $srv->host }}) @endif
                                                    @if($srv->runpod_network_volume_id) — vol:{{ $srv->runpod_network_volume_id }} @endif
                                                </option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <small class="form-text text-muted">
                                        Only "external" servers (with api_url + api_key) are listed.
                                        <a href="{{ route('admin.settings.servers.index') }}" target="_blank">Manage servers</a>
                                    </small>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="feSelectModel">
                                        <i class="mdi mdi-brain mr-1"></i>AI Model
                                    </label>
                                    <select name="ai_model_id" id="feSelectModel" class="form-control" required>
                                        <option value="">— Choose model —</option>
                                        @foreach($aiModels as $m)
                                            <option value="{{ $m->id }}" {{ $m->is_default ? 'selected' : '' }}>
                                                {{ $m->name }}
                                                @if($m->version) ({{ $m->version }}) @endif
                                                — {{ $m->getLevelLabel() }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <small class="form-text text-muted">
                                        The model name is used as the GDrive output sub-folder.
                                    </small>
                                </div>
                            </div>

                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-info btn-block" id="feExecuteBtn" disabled>
                                    <i class="mdi mdi-rocket-launch mr-1"></i>
                                    Dispatch to RunPod (<span id="feSelectedCount">0</span>)
                                </button>
                            </div>
                        </div>

                        {{-- Eligible samples table (tiling_status = done) --}}
                        <div class="table-responsive mt-3" style="max-height:400px; overflow-y:auto;">
                            <table class="table table-sm table-hover">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width:40px;">
                                            <input type="checkbox" id="feSelectAll" title="Select all on this page">
                                        </th>
                                        <th>#</th>
                                        <th>File</th>
                                        <th>Case</th>
                                        <th>Patches</th>
                                        <th>Magnification</th>
                                        <th>Patch Size</th>
                                        <th>FE Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $eligible = \App\Models\Sample::with(['patientCase:id,case_id'])
                                            ->where('tiling_status', 'done')
                                            ->whereNotNull('tiles_gdrive_path')
                                            ->orderByDesc('id')
                                            ->limit(200)
                                            ->get();
                                    @endphp
                                    @forelse($eligible as $s)
                                        <tr>
                                            <td>
                                                <input type="checkbox"
                                                       name="sample_ids[]"
                                                       value="{{ $s->id }}"
                                                       class="fe-sample-cb">
                                            </td>
                                            <td>{{ $s->id }}</td>
                                            <td class="text-truncate" style="max-width:200px;">{{ $s->file_name }}</td>
                                            <td>{{ $s->patientCase?->case_id ?? '—' }}</td>
                                            <td>{{ $s->tile_count ?? '—' }}</td>
                                            <td>{{ $s->magnification_id ? \App\Models\Magnification::find($s->magnification_id)?->label : '—' }}</td>
                                            <td>{{ $s->tile_size_px ?? '—' }}px</td>
                                            <td>
                                                @php
                                                    $fe = $s->feature_extraction_status ?? 'pending';
                                                    $cls = match($fe) {
                                                        'completed'  => 'success',
                                                        'processing' => 'info',
                                                        'failed'     => 'danger',
                                                        default      => 'secondary',
                                                    };
                                                @endphp
                                                <span class="badge badge-{{ $cls }}">{{ $fe }}</span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-3">
                                                No eligible samples (need <code>tiling_status = done</code>).
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- ─── TRAINING SECTION ──────────────────────────────────────────────────── --}}
    <div class="row {{ ($filters['operation_type'] ?? '') !== 'training' ? 'd-none' : '' }}"
         id="trainingSection">
        <div class="col-12 grid-margin">
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark py-2">
                    <h5 class="mb-0">
                        <span class="badge badge-dark mr-2">2</span>
                        <i class="mdi mdi-brain mr-1"></i>
                        Model Training — CLAM (Multiple Instance Learning)
                    </h5>
                </div>
                <div class="card-body">
                    <p class="card-description">
                        Train a <strong>CLAM</strong> MIL classification head on pre-extracted features.
                        Only samples with <code>feature_extraction_status = completed</code> and a
                        GDrive features path are eligible. Select at least <strong>2 samples</strong>.
                    </p>

                    <form method="POST" action="{{ route('admin.workflow.dispatch.training') }}" id="trainingForm">
                        @csrf
                        {{-- Hidden field for serialised label map --}}
                        <input type="hidden" name="label_map" id="labelMapJson" value="">

                        <div class="row">
                            {{-- Server --}}
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="trSelectServer">
                                        <i class="mdi mdi-server mr-1"></i>Training Server
                                    </label>
                                    @php $clamServers = $servers->where('type', 'external'); @endphp
                                    @if($clamServers->isEmpty())
                                        <div class="alert alert-warning py-2 px-3 mb-1" style="font-size:.85rem;">
                                            No external servers configured.
                                            <a href="{{ route('admin.settings.servers.create') }}">Add one →</a>
                                        </div>
                                        <input type="hidden" name="server_id" value="">
                                    @else
                                        <select name="server_id" id="trSelectServer" class="form-control" required>
                                            <option value="">— Choose server —</option>
                                            @foreach($clamServers as $srv)
                                                <option value="{{ $srv->id }}">{{ $srv->name }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </div>
                            </div>

                            {{-- Training head (CLAM model) --}}
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="trSelectHead">
                                        <i class="mdi mdi-brain mr-1"></i>Training Head
                                    </label>
                                    @php $trainingHeads = $aiModels->where('model_type', 'classification'); @endphp
                                    <select name="training_head_id" id="trSelectHead" class="form-control" required>
                                        <option value="">— Choose head —</option>
                                        @foreach($trainingHeads as $m)
                                            <option value="{{ $m->id }}">{{ $m->name }} ({{ $m->version ?? 'v1' }})</option>
                                        @endforeach
                                    </select>
                                    <small class="form-text text-muted">MIL classification model (e.g. CLAM-SB)</small>
                                </div>
                            </div>

                            {{-- Feature model used --}}
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="trSelectFeat">
                                        <i class="mdi mdi-flask-outline mr-1"></i>Feature Model
                                    </label>
                                    @php $foundationModels = $aiModels->whereIn('model_type', ['foundation', 'multimodal', 'other']); @endphp
                                    <select name="feature_model_id" id="trSelectFeat" class="form-control" required>
                                        <option value="">— Choose feature model —</option>
                                        @foreach($foundationModels as $m)
                                            <option value="{{ $m->id }}" {{ $m->is_default ? 'selected' : '' }}>{{ $m->name }}</option>
                                        @endforeach
                                    </select>
                                    <small class="form-text text-muted">Which embeddings were used for these samples</small>
                                </div>
                            </div>

                            {{-- Architecture --}}
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="trModelType">Architecture</label>
                                    <select name="model_type" id="trModelType" class="form-control">
                                        <option value="clam_sb" selected>CLAM-SB (single branch)</option>
                                        <option value="clam_mb">CLAM-MB (multi branch)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            {{-- Label type --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="trLabelType">Label Source</label>
                                    <select name="label_type" id="trLabelType" class="form-control" required>
                                        <option value="category">Category (coarse)</option>
                                        <option value="disease_type">Disease Type</option>
                                        <option value="disease_subtype">Disease Subtype — exact name (hierarchical)</option>
                                    </select>
                                    <small class="form-text text-muted" id="trLabelTypeHint">
                                        Coarse family-level classes.
                                    </small>
                                </div>
                            </div>

                            {{-- Epochs --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="trEpochs">Epochs</label>
                                    <input type="number" name="epochs" id="trEpochs" class="form-control"
                                           value="20" min="1" max="200" required>
                                </div>
                            </div>

                            {{-- LR --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="trLR">Learning Rate</label>
                                    <input type="text" name="learning_rate" id="trLR" class="form-control"
                                           value="0.0001" required>
                                </div>
                            </div>

                            {{-- Bag size --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="trBagSize">Bag Size</label>
                                    <input type="number" name="bag_size" id="trBagSize" class="form-control"
                                           value="-1" min="-1" required>
                                    <small class="form-text text-muted">-1 = all patches</small>
                                </div>
                            </div>

                            {{-- Hierarchical loss weight --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="trHierWeight">Coarse Loss Weight</label>
                                    <input type="number" name="hier_weight" id="trHierWeight" class="form-control"
                                           value="0.3" min="0" max="1" step="0.05" disabled>
                                    <small class="form-text text-muted">Family-level supervision (0 = off)</small>
                                </div>
                            </div>

                            {{-- GDrive output --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="trGDriveOut">GDrive Output Dir</label>
                                    <input type="text" name="gdrive_output_dir" id="trGDriveOut" class="form-control"
                                           placeholder="training/CLAM/run_...">
                                    <small class="form-text text-muted">Leave blank for auto</small>
                                </div>
                            </div>
                        </div>

                        {{-- ── Class map (derived from the selected samples) ───────────────── --}}
                        <div class="card bg-light mt-2 mb-3">
                            <div class="card-body py-2 px-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong><i class="mdi mdi-tag-multiple mr-1"></i>Training Classes</strong>
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="trDetectClasses">
                                        <i class="mdi mdi-refresh mr-1"></i>Detect from selected samples
                                    </button>
                                </div>
                                <small class="text-muted d-block mb-2">
                                    Classes are read from the database for exactly the slides you selected —
                                    no hand-typed label map, so a mismatch can never silently relabel a slide.
                                    Pick <code>Disease Subtype</code> above to train on the exact disease name.
                                    A run is <strong>scoped to one organ</strong>: the taxonomy is
                                    <code>Organ → Clinical Group → Disease</code>, so selecting slides from two
                                    organs is refused rather than merged into one class.
                                </small>

                                <div id="trClassPreview" class="small text-muted">
                                    Select samples and choose a label source, then press
                                    <em>Detect from selected samples</em>.
                                </div>

                                {{-- Kept for backward compatibility: left blank, the server derives the map. --}}
                                <input type="hidden" name="use_class_weights" value="1">
                            </div>
                        </div>

                        {{-- ── Split Summary Bar ──────────────────────────────────────────── --}}
                        <div class="d-flex align-items-center flex-wrap gap-2 mb-2 p-2 bg-light rounded border" id="trSplitSummary" style="display:none!important;">
                            <strong class="mr-2"><i class="mdi mdi-format-list-bulleted-square mr-1"></i>Split Summary:</strong>
                            <span class="badge badge-success px-2 py-1 mr-1">Train: <span id="cntTrain">0</span></span>
                            <span class="badge badge-primary px-2 py-1 mr-1">Val: <span id="cntVal">0</span></span>
                            <span class="badge badge-warning text-dark px-2 py-1 mr-1">Test: <span id="cntTest">0</span></span>
                            <span class="badge badge-secondary px-2 py-1 mr-2">Unassigned: <span id="cntUnassigned">0</span></span>
                            <span id="trSplitError" class="text-danger small font-weight-bold" style="display:none;"></span>
                        </div>

                        {{-- ── Bulk-assign buttons ─────────────────────────────────────────── --}}
                        <div class="d-flex align-items-center flex-wrap mb-2 small" id="trBulkBtns" style="display:none!important;">
                            <span class="text-muted mr-2">Set all checked →</span>
                            <button type="button" class="btn btn-sm btn-success mr-1" id="bulkTrain">All → Train</button>
                            <button type="button" class="btn btn-sm btn-primary mr-1" id="bulkVal">All → Val</button>
                            <button type="button" class="btn btn-sm btn-warning mr-1 text-dark" id="bulkTest">All → Test</button>
                        </div>

                        {{-- Eligible samples table (feature_extraction_status = completed) --}}
                        <div class="table-responsive mt-3" style="max-height:400px; overflow-y:auto;">
                            <table class="table table-sm table-hover" id="trSamplesTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width:40px;">
                                            <input type="checkbox" id="trSelectAll" title="Select all">
                                        </th>
                                        <th>#</th>
                                        <th>File</th>
                                        <th>Case</th>
                                        <th>Category</th>
                                        <th>Disease Type</th>
                                        <th>Feature Model</th>
                                        <th>GDrive Features</th>
                                        <th style="min-width:180px;">
                                            <i class="mdi mdi-call-split mr-1"></i>Split
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $trainingEligible = \App\Models\Sample::with([
                                                'patientCase:id,case_id,disease_type',
                                                'category:id,label_en',
                                                'featureExtractionAiModel:id,name',
                                            ])
                                            ->where('feature_extraction_status', 'completed')
                                            ->whereNotNull('features_gdrive_path')
                                            ->orderByDesc('id')
                                            ->limit(500)
                                            ->get();
                                    @endphp
                                    @forelse($trainingEligible as $s)
                                        <tr data-case-id="{{ $s->patient_case_id ?? '' }}" data-sample-id="{{ $s->id }}">
                                            <td>
                                                <input type="checkbox"
                                                       name="sample_ids[]"
                                                       value="{{ $s->id }}"
                                                       class="tr-sample-cb">
                                                {{-- Hidden phase input — value set by JS --}}
                                                <input type="hidden"
                                                       name="sample_phases[{{ $s->id }}]"
                                                       value=""
                                                       class="tr-phase-input"
                                                       id="phase_{{ $s->id }}">
                                            </td>
                                            <td>{{ $s->id }}</td>
                                            <td class="text-truncate" style="max-width:180px;">{{ $s->file_name }}</td>
                                            <td>{{ $s->patientCase?->case_id ?? '—' }}</td>
                                            <td>{{ $s->category?->label_en ?? '—' }}</td>
                                            <td>{{ $s->patientCase?->disease_type ?? '—' }}</td>
                                            <td>
                                                @if($s->featureExtractionAiModel)
                                                    <span class="badge badge-info">{{ $s->featureExtractionAiModel->name }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-truncate" style="max-width:200px;">
                                                <small class="text-muted">{{ $s->features_gdrive_path ?? '—' }}</small>
                                            </td>
                                            <td>
                                                {{-- Split button group — visible only when row is checked --}}
                                                <div class="btn-group btn-group-sm tr-split-group" id="splitBtns_{{ $s->id }}" style="display:none;">
                                                    <button type="button" class="btn btn-outline-success btn-phase" data-phase="1" data-sample="{{ $s->id }}" title="Train">Train</button>
                                                    <button type="button" class="btn btn-outline-primary btn-phase" data-phase="2" data-sample="{{ $s->id }}" title="Validation">Val</button>
                                                    <button type="button" class="btn btn-outline-warning btn-phase text-dark" data-phase="3" data-sample="{{ $s->id }}" title="Test">Test</button>
                                                </div>
                                                <small class="tr-phase-label text-muted" id="phaseLabel_{{ $s->id }}"></small>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-3">
                                                No eligible samples.
                                                Samples need <code>feature_extraction_status = completed</code>
                                                and a GDrive features path.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-warning btn-lg" id="trExecuteBtn" disabled>
                                <i class="mdi mdi-brain mr-1"></i>
                                🚀 Dispatch Training Run (<span id="trSelectedCount">0</span> samples)
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- STEP 2 — Server & Patch-Size Selection --}}
    <div class="row {{ ($filters['operation_type'] ?? '') !== 'patch_extraction' ? 'd-none' : '' }}"
         id="serverSelectionSection">
        <div class="col-12 grid-margin">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white py-2">
                    <h5 class="mb-0">
                        <span class="badge badge-light text-primary mr-2">2</span>
                        Execution Server &amp; Patch Configuration
                    </h5>
                </div>
                <div class="card-body">
                    <p class="card-description">
                        Select the server that will execute the patch extraction and the patch size to use.
                        After confirming, the sample filter table will appear below.
                    </p>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="serverSelect">
                                    <i class="mdi mdi-server mr-1"></i>Execution Server
                                </label>
                                <select id="serverSelect" class="form-control">
                                    <option value="">— Choose server —</option>
                                    @forelse($servers as $srv)
                                        <option value="{{ $srv->id }}"
                                                data-type="{{ $srv->type }}"
                                                {{ (string)($filters['server_id'] ?? '') === (string)$srv->id ? 'selected' : '' }}>
                                            {{ $srv->name }} — {{ $srv->getTypeLabel() }}
                                            @if($srv->host) ({{ $srv->host }}) @endif
                                        </option>
                                    @empty
                                        <option disabled>No servers configured</option>
                                    @endforelse
                                </select>
                                <small class="form-text text-muted">
                                    "Local Server" = runs on the same machine as this web application.
                                </small>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="patchSizeSelectStep2">
                                    <i class="mdi mdi-grid mr-1"></i>Patch Size
                                </label>
                                <select id="patchSizeSelectStep2" class="form-control">
                                    <option value="">— Choose patch size —</option>
                                    @forelse($patchSizes as $ps)
                                        <option value="{{ $ps->id }}"
                                                {{ (string)($filters['patch_size_id'] ?? '') === (string)$ps->id ? 'selected' : '' }}>
                                            {{ $ps->label }}
                                            @if($ps->aiModel) — {{ $ps->aiModel->name }} @endif
                                        </option>
                                    @empty
                                        <option disabled>No patch sizes configured</option>
                                    @endforelse
                                </select>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="magnificationSelectStep2">
                                    <i class="mdi mdi-magnify-plus-outline mr-1"></i>Magnification
                                </label>
                                <select id="magnificationSelectStep2" class="form-control">
                                    <option value="">— Choose magnification —</option>
                                    @forelse($magnifications as $mag)
                                        <option value="{{ $mag->id }}"
                                                {{ (string)($filters['magnification_id'] ?? '') === (string)$mag->id ? 'selected' : '' }}>
                                            {{ $mag->label }}
                                            @if($mag->notes) — {{ $mag->notes }} @endif
                                        </option>
                                    @empty
                                        <option disabled>No magnifications configured</option>
                                    @endforelse
                                </select>
                                <small class="form-text text-muted">Used as sub-folder in the output path.</small>
                            </div>
                        </div>

                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-group w-100">
                                <button type="button" class="btn btn-primary btn-block" id="confirmServerBtn">
                                    <i class="mdi mdi-check mr-1"></i>
                                    Confirm &amp; Load Sample Filters
                                </button>
                            </div>
                        </div>
                    </div>

                    @if(($filters['server_id'] ?? '') && ($filters['patch_size_id'] ?? '') && ($filters['magnification_id'] ?? ''))
                        @php
                            $confirmedServer       = $servers->firstWhere('id', $filters['server_id']);
                            $confirmedPatchSize    = $patchSizes->firstWhere('id', $filters['patch_size_id']);
                            $confirmedMagnification = $magnifications->firstWhere('id', $filters['magnification_id']);
                        @endphp
                        @if($confirmedServer && $confirmedPatchSize && $confirmedMagnification)
                            <div class="alert alert-success py-2 mb-0 mt-2">
                                <i class="mdi mdi-check-circle mr-1"></i>
                                <strong>Confirmed:</strong>
                                Server <strong>{{ $confirmedServer->name }}</strong>
                                ({{ $confirmedServer->getTypeLabel() }}) —
                                Patch size <strong>{{ $confirmedPatchSize->label }}</strong> —
                                Magnification <strong>{{ $confirmedMagnification->label }}</strong>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- STEP 3 — Sample Filters --}}
    <div class="row {{ (($filters['server_id'] ?? '') && ($filters['patch_size_id'] ?? '') && ($filters['magnification_id'] ?? '')) ? '' : 'd-none' }}"
         id="sampleFiltersSection">
        <div class="col-12 grid-margin">
            <div class="card">
                <div class="card-header py-2">
                    <h5 class="mb-0">
                        <span class="badge badge-secondary mr-2">3</span>
                        <i class="mdi mdi-filter-variant mr-1"></i>Sample Filters
                    </h5>
                </div>
                <div class="card-body">
                    <p class="card-description">
                        Filter the slides to select which ones to process. Pagination keeps the operation context.
                    </p>

                    <form method="GET" action="{{ route('admin.workflow') }}" id="filtersForm">
                        <input type="hidden" name="operation_type" value="{{ $filters['operation_type'] ?? '' }}">
                        <input type="hidden" name="server_id"        value="{{ $filters['server_id'] ?? '' }}">
                        <input type="hidden" name="patch_size_id"    value="{{ $filters['patch_size_id'] ?? '' }}">
                        <input type="hidden" name="magnification_id" value="{{ $filters['magnification_id'] ?? '' }}">

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Uniqueness per case</label>
                                    <select name="uniqueness" class="form-control">
                                        <option value="any"    {{ $filters['uniqueness'] === 'any'    ? 'selected' : '' }}>Any (allow duplicates per case)</option>
                                        <option value="unique" {{ $filters['uniqueness'] === 'unique' ? 'selected' : '' }}>Unique (one sample per case)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Gender (clinical)</label>
                                    <select name="gender" class="form-control">
                                        <option value="">— Any —</option>
                                        <option value="male"   {{ $filters['gender'] === 'male'   ? 'selected' : '' }}>Male</option>
                                        <option value="female" {{ $filters['gender'] === 'female' ? 'selected' : '' }}>Female</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Image Quality</label>
                                    <select name="quality_status" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach(['passed','rejected','needs_review','needs_clinical_info','pending'] as $q)
                                            <option value="{{ $q }}" {{ $filters['quality_status'] === $q ? 'selected' : '' }}>
                                                {{ ucfirst(str_replace('_',' ',$q)) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Category</label>
                                    <select name="category_id" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($categories as $c)
                                            <option value="{{ $c->id }}" {{ (string)$filters['category_id'] === (string)$c->id ? 'selected' : '' }}>
                                                {{ $c->label_en }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Min Size (GB)</label>
                                    <input type="number" step="0.01" min="0" name="min_size_gb" class="form-control"
                                           value="{{ $filters['min_size_gb'] }}" placeholder="0">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Max Size (GB)</label>
                                    <input type="number" step="0.01" min="0" name="max_size_gb" class="form-control"
                                           value="{{ $filters['max_size_gb'] }}" placeholder="any">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Organ</label>
                                    <select name="organ_id" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($organs as $o)
                                            <option value="{{ $o->id }}" {{ (string)$filters['organ_id'] === (string)$o->id ? 'selected' : '' }}>
                                                {{ $o->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Stain</label>
                                    <select name="stain_id" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($stains as $s)
                                            <option value="{{ $s->id }}" {{ (string)$filters['stain_id'] === (string)$s->id ? 'selected' : '' }}>
                                                {{ $s->abbreviation }} — {{ $s->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Data Source</label>
                                    <select name="data_source_id" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($dataSources as $d)
                                            <option value="{{ $d->id }}" {{ (string)$filters['data_source_id'] === (string)$d->id ? 'selected' : '' }}>
                                                {{ $d->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Disease Type</label>
                                    <select name="disease_type" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($diseaseTypes as $dt)
                                            <option value="{{ $dt }}" {{ $filters['disease_type'] === $dt ? 'selected' : '' }}>
                                                {{ $dt }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Tiling Status</label>
                                    <select name="tiling_status" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach(['pending','processing','done','failed'] as $t)
                                            <option value="{{ $t }}" {{ $filters['tiling_status'] === $t ? 'selected' : '' }}>
                                                {{ ucfirst($t) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Magnification</label>
                                    <select name="filter_magnification_id" class="form-control">
                                        <option value="">— Any —</option>
                                        @foreach($magnifications as $m)
                                            <option value="{{ $m->id }}" {{ (string)($filters['filter_magnification_id'] ?? '') === (string)$m->id ? 'selected' : '' }}>
                                                {{ $m->label }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Usable</label>
                                    <select name="is_usable" class="form-control">
                                        <option value="">— Any —</option>
                                        <option value="1" {{ $filters['is_usable'] === '1' ? 'selected' : '' }}>Usable only</option>
                                        <option value="0" {{ $filters['is_usable'] === '0' ? 'selected' : '' }}>Not usable</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-9 d-flex align-items-end">
                                <div class="form-group d-flex" style="gap:.5rem;">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="mdi mdi-magnify mr-1"></i> Apply Filters
                                    </button>
                                    <a href="{{ route('admin.workflow') }}" class="btn btn-outline-secondary">
                                        <i class="mdi mdi-refresh mr-1"></i> Reset All
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- STEP 4 — Sample Table + Execute --}}
    <div class="row {{ (($filters['server_id'] ?? '') && ($filters['patch_size_id'] ?? '') && ($filters['magnification_id'] ?? '')) ? '' : 'd-none' }}"
         id="sampleTableSection">
        <div class="col-12 grid-margin">
            <form id="dispatchForm"
                  method="POST"
                  action="{{ route('admin.workflow.dispatch.patch-extraction') }}">
                @csrf
                <input type="hidden" name="server_id"        value="{{ $filters['server_id'] ?? '' }}">
                <input type="hidden" name="patch_size_id"    value="{{ $filters['patch_size_id'] ?? '' }}">
                <input type="hidden" name="magnification_id" value="{{ $filters['magnification_id'] ?? '' }}">

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3" style="gap:1rem;">
                            <div>
                                <h4 class="card-title mb-1">
                                    <span class="badge badge-secondary mr-2">4</span>
                                    Matching Samples
                                    <span class="badge badge-secondary ml-1">{{ $samples->total() }}</span>
                                </h4>
                                <small class="text-muted">
                                    <span id="selectedCount">0</span> selected on this page
                                </small>
                            </div>

                            <div class="d-flex align-items-center flex-wrap" style="gap:.75rem;">
                                @if(($filters['server_id'] ?? '') && ($filters['patch_size_id'] ?? '') && ($filters['magnification_id'] ?? ''))
                                    @php
                                        $srv = $servers->firstWhere('id', $filters['server_id']);
                                        $psz = $patchSizes->firstWhere('id', $filters['patch_size_id']);
                                        $mag = $magnifications->firstWhere('id', $filters['magnification_id']);
                                    @endphp
                                    <span class="badge badge-primary px-3 py-2" style="font-size:.8rem;">
                                        <i class="mdi mdi-server mr-1"></i>{{ $srv?->name ?? '—' }}
                                        &nbsp;|&nbsp;
                                        <i class="mdi mdi-grid mr-1"></i>{{ $psz?->size_px ?? '—' }}px patches
                                        &nbsp;|&nbsp;
                                        <i class="mdi mdi-magnify-plus-outline mr-1"></i>{{ $mag?->label ?? '—' }}
                                    </span>
                                @endif

                                <button type="submit" class="btn btn-success" id="executeBtn" disabled>
                                    <i class="mdi mdi-play mr-1"></i>
                                    Execute Patch Extraction
                                    (<span id="executeCount">0</span>)
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-sm">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width:40px;">
                                            <input type="checkbox" id="selectAll" title="Select all on this page">
                                        </th>
                                        <th>#</th>
                                        <th>File</th>
                                        <th>Case</th>
                                        <th>Disease</th>
                                        <th>Organ</th>
                                        <th>Stain</th>
                                        <th>Source</th>
                                        <th>Category</th>
                                        <th class="text-right">Size</th>
                                        <th>Tiling</th>
                                        <th>Tile</th>
                                        <th>Mag.</th>
                                        <th>Quality</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($samples as $s)
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="sample-check"
                                                       name="sample_ids[]" value="{{ $s->id }}">
                                            </td>
                                            <td class="text-muted small">{{ $s->id }}</td>
                                            <td class="small"
                                                style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                                title="{{ $s->file_name }}">
                                                <a href="{{ route('admin.samples.show', $s) }}" target="_blank">
                                                    {{ $s->file_name ?? '—' }}
                                                </a>
                                            </td>
                                            <td class="small">
                                                @if($s->patientCase)
                                                    <code>{{ Str::limit($s->patientCase->case_id, 12, '…') }}</code>
                                                @else — @endif
                                            </td>
                                            <td class="small">{{ $s->patientCase->disease_type ?? '—' }}</td>
                                            <td class="small">{{ $s->organ->name ?? '—' }}</td>
                                            <td class="small">
                                                @if($s->stain)
                                                    <span class="badge badge-light border">{{ $s->stain->abbreviation }}</span>
                                                @else — @endif
                                            </td>
                                            <td class="small">{{ $s->dataSource->name ?? '—' }}</td>
                                            <td class="small">{{ $s->category->label_en ?? '—' }}</td>
                                            <td class="small text-right">{{ $s->file_size_human }}</td>
                                            <td>
                                                <span class="badge badge-{{ $s->tiling_status_badge }}">
                                                    {{ $s->tiling_status }}
                                                </span>
                                            </td>
                                            <td class="small">{{ $s->tile_size_px ? $s->tile_size_px.'px' : '—' }}</td>
                                            <td class="small">{{ $s->magnification ?? '—' }}</td>
                                            <td>
                                                <span class="badge badge-{{ $s->quality_status_badge }}">
                                                    {{ $s->quality_status }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="14" class="text-center py-5 text-muted">
                                                <i class="mdi mdi-database-search-outline" style="font-size:2.5rem;"></i>
                                                <p class="mt-2 mb-0">No samples match the current filters.</p>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3">
                            {{ $samples->links() }}
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script>
    (function () {
        'use strict';

        var opTypeSelect         = document.getElementById('operationTypeSelect');
        var serverSection        = document.getElementById('serverSelectionSection');
        var sampleFiltersSection = document.getElementById('sampleFiltersSection');
        var sampleTableSection   = document.getElementById('sampleTableSection');
        var serverSelect         = document.getElementById('serverSelect');
        var patchSizeSelect      = document.getElementById('patchSizeSelectStep2');
        var magnificationSelect  = document.getElementById('magnificationSelectStep2');
        var confirmBtn           = document.getElementById('confirmServerBtn');
        var selectAll            = document.getElementById('selectAll');
        var executeBtn           = document.getElementById('executeBtn');
        var executeCountEl       = document.getElementById('executeCount');
        var selectedCountEl      = document.getElementById('selectedCount');

        function show(el) { if (el) el.classList.remove('d-none'); }
        function hide(el) { if (el) el.classList.add('d-none'); }

        function refreshCheckboxState() {
            var n = document.querySelectorAll('.sample-check:checked').length;
            var total = document.querySelectorAll('.sample-check').length;
            if (selectedCountEl) selectedCountEl.textContent = n;
            if (executeCountEl)  executeCountEl.textContent  = n;
            if (executeBtn)      executeBtn.disabled          = (n === 0);
            if (selectAll) {
                selectAll.checked       = (n > 0 && n === total);
                selectAll.indeterminate = (n > 0 && n < total);
            }
        }

        var featureSection = document.getElementById('featureExtractionSection');
        var trainingSection = document.getElementById('trainingSection');

        function onOperationTypeChange() {
            var val = opTypeSelect ? opTypeSelect.value : '';

            // patch extraction section
            if (val === 'patch_extraction') {
                show(serverSection);
                var allConfirmed = serverSelect && serverSelect.value
                                && patchSizeSelect && patchSizeSelect.value
                                && magnificationSelect && magnificationSelect.value;
                if (allConfirmed) {
                    show(sampleFiltersSection);
                    show(sampleTableSection);
                }
            } else {
                hide(serverSection);
                hide(sampleFiltersSection);
                hide(sampleTableSection);
            }

            // feature extraction section
            if (featureSection) {
                if (val === 'feature_extraction') { show(featureSection); }
                else { hide(featureSection); }
            }

            // training section
            if (trainingSection) {
                if (val === 'training') { show(trainingSection); }
                else { hide(trainingSection); }
            }
        }

        if (opTypeSelect) {
            opTypeSelect.addEventListener('change', onOperationTypeChange);
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                var sid = serverSelect       ? serverSelect.value       : '';
                var pid = patchSizeSelect    ? patchSizeSelect.value    : '';
                var mid = magnificationSelect ? magnificationSelect.value : '';
                if (!sid) { alert('Please select an execution server.'); return; }
                if (!pid) { alert('Please select a patch size.'); return; }
                if (!mid) { alert('Please select a magnification level.'); return; }
                var url = new URL(window.location.href);
                url.searchParams.set('operation_type',  'patch_extraction');
                url.searchParams.set('server_id',       sid);
                url.searchParams.set('patch_size_id',   pid);
                url.searchParams.set('magnification_id', mid);
                window.location.href = url.toString();
            });
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.sample-check').forEach(function (c) {
                    c.checked = selectAll.checked;
                });
                refreshCheckboxState();
            });
        }

        document.addEventListener('change', function (e) {
            if (e.target && e.target.classList.contains('sample-check')) {
                refreshCheckboxState();
            }
        });

        var dispatchForm = document.getElementById('dispatchForm');
        if (dispatchForm) {
            dispatchForm.addEventListener('submit', function (e) {
                var n = document.querySelectorAll('.sample-check:checked').length;
                if (n === 0) {
                    e.preventDefault();
                    alert('Please select at least one sample before executing.');
                    return;
                }
                if (!confirm('Queue ' + n + ' slide(s) for patch extraction?\n\nEach slide will be downloaded from Google Drive, patched, and the results uploaded to the "Sliced Slides" folder.')) {
                    e.preventDefault();
                }
            });
        }

        onOperationTypeChange();
        refreshCheckboxState();

        // ── Feature extraction selection tracker ──────────────────────────────
        var feAll  = document.getElementById('feSelectAll');
        var feCbs  = document.querySelectorAll('.fe-sample-cb');
        var feBtn  = document.getElementById('feExecuteBtn');
        var feCnt  = document.getElementById('feSelectedCount');
        var feSrv  = document.getElementById('feSelectServer');
        var feMod  = document.getElementById('feSelectModel');

        function feUpdate() {
            var checked = Array.from(feCbs).filter(function(cb){ return cb.checked; }).length;
            if (feCnt) feCnt.textContent = String(checked);
            var serverOk = feSrv ? feSrv.value : '';
            if (feBtn) feBtn.disabled = !(checked > 0 && serverOk && feMod && feMod.value);
        }
        if (feAll) {
            feAll.addEventListener('change', function(e) {
                feCbs.forEach(function(cb){ cb.checked = e.target.checked; });
                feUpdate();
            });
        }
        feCbs.forEach(function(cb){ cb.addEventListener('change', feUpdate); });
        if (feSrv) feSrv.addEventListener('change', feUpdate);
        if (feMod) feMod.addEventListener('change', feUpdate);
        feUpdate();

        // ── Training: Split management ────────────────────────────────────────
        var trAll     = document.getElementById('trSelectAll');
        var trCbs     = document.querySelectorAll('.tr-sample-cb');
        var trBtn     = document.getElementById('trExecuteBtn');
        var trCnt     = document.getElementById('trSelectedCount');
        var trSrv     = document.getElementById('trSelectServer');
        var trHead    = document.getElementById('trSelectHead');
        var trFeat    = document.getElementById('trSelectFeat');
        var splitSummary = document.getElementById('trSplitSummary');
        var bulkBtns     = document.getElementById('trBulkBtns');

        var PHASE_LABELS = {1: 'Train', 2: 'Val', 3: 'Test'};
        var PHASE_CLASSES = {1: 'btn-success', 2: 'btn-primary', 3: 'btn-warning'};
        var PHASE_OUTLINE = {1: 'btn-outline-success', 2: 'btn-outline-primary', 3: 'btn-outline-warning'};

        /** Update the split summary bar and enable/disable Dispatch button */
        function trUpdate() {
            var checked = Array.from(trCbs).filter(function(cb){ return cb.checked; });
            var nTrain = 0, nVal = 0, nTest = 0, nUnassigned = 0;

            checked.forEach(function(cb) {
                var id = cb.value;
                var phaseInput = document.getElementById('phase_' + id);
                var phase = phaseInput ? parseInt(phaseInput.value) : 0;
                if (phase === 1) nTrain++;
                else if (phase === 2) nVal++;
                else if (phase === 3) nTest++;
                else nUnassigned++;
            });

            if (trCnt) trCnt.textContent = String(checked.length);
            document.getElementById('cntTrain').textContent      = nTrain;
            document.getElementById('cntVal').textContent        = nVal;
            document.getElementById('cntTest').textContent       = nTest;
            document.getElementById('cntUnassigned').textContent = nUnassigned;

            var errEl = document.getElementById('trSplitError');

            // Show summary / bulk bar only when at least one sample is checked
            if (checked.length > 0) {
                splitSummary.style.setProperty('display', 'flex', 'important');
                bulkBtns.style.setProperty('display', 'flex', 'important');
            } else {
                splitSummary.style.setProperty('display', 'none', 'important');
                bulkBtns.style.setProperty('display', 'none', 'important');
            }

            // Validate
            var error = '';
            if (nUnassigned > 0) {
                error = nUnassigned + ' sample(s) are not yet assigned to a split.';
            } else if (nTrain < 1) {
                error = 'At least 1 Train sample is required.';
            } else if (nVal < 1) {
                error = 'At least 1 Validation sample is required.';
            }

            // Leakage check: same case_id in multiple splits
            if (!error) {
                var casePhaseMap = {};
                checked.forEach(function(cb) {
                    var row = cb.closest('tr');
                    var caseId = row ? row.dataset.caseId : '';
                    if (!caseId) return;
                    var phase = parseInt(document.getElementById('phase_' + cb.value).value) || 0;
                    if (casePhaseMap[caseId] !== undefined && casePhaseMap[caseId] !== phase) {
                        error = 'Data leakage: samples from the same case are in different splits!';
                    }
                    casePhaseMap[caseId] = phase;
                });
            }

            if (errEl) {
                if (error) {
                    errEl.textContent = '⚠ ' + error;
                    errEl.style.display = '';
                } else {
                    errEl.textContent = '';
                    errEl.style.display = 'none';
                }
            }

            var serverOk = trSrv  && trSrv.value;
            var headOk   = trHead && trHead.value;
            var featOk   = trFeat && trFeat.value;
            var ready = checked.length >= 2 && !error && serverOk && headOk && featOk;
            if (trBtn) trBtn.disabled = !ready;
        }

        /** Assign a phase to a row and update its button visuals */
        function assignPhase(sampleId, phase) {
            var phaseInput = document.getElementById('phase_' + sampleId);
            if (phaseInput) phaseInput.value = phase;

            var labelEl = document.getElementById('phaseLabel_' + sampleId);
            if (labelEl) {
                labelEl.textContent = '';   // hide text label; buttons show state
            }

            // Update button active state
            var group = document.getElementById('splitBtns_' + sampleId);
            if (!group) return;
            group.querySelectorAll('.btn-phase').forEach(function(btn) {
                var btnPhase = parseInt(btn.dataset.phase);
                // Remove all active classes first
                btn.classList.remove('btn-success', 'btn-primary', 'btn-warning');
                btn.classList.remove('btn-outline-success', 'btn-outline-primary', 'btn-outline-warning');
                // Re-apply correct state
                if (btnPhase === phase) {
                    btn.classList.add(PHASE_CLASSES[btnPhase]);
                } else {
                    btn.classList.add(PHASE_OUTLINE[btnPhase]);
                }
            });
        }

        /** Toggle checkbox and split button group visibility */
        function handleRowCheck(cb) {
            var id = cb.value;
            var group = document.getElementById('splitBtns_' + id);
            if (group) group.style.display = cb.checked ? '' : 'none';
            if (!cb.checked) {
                // Clear phase when deselected
                var phaseInput = document.getElementById('phase_' + id);
                if (phaseInput) phaseInput.value = '';
                // Reset buttons to outline
                if (group) {
                    group.querySelectorAll('.btn-phase').forEach(function(btn) {
                        var btnPhase = parseInt(btn.dataset.phase);
                        btn.classList.remove('btn-success', 'btn-primary', 'btn-warning');
                        btn.classList.add(PHASE_OUTLINE[btnPhase]);
                    });
                }
            }
        }

        // ── Wire up events ────────────────────────────────────────────────────
        if (trAll) {
            trAll.addEventListener('change', function(e) {
                trCbs.forEach(function(cb){
                    cb.checked = e.target.checked;
                    handleRowCheck(cb);
                });
                trUpdate();
            });
        }

        trCbs.forEach(function(cb){
            cb.addEventListener('change', function() {
                handleRowCheck(cb);
                trUpdate();
            });
        });

        // Phase buttons
        document.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('btn-phase')) {
                var phase    = parseInt(e.target.dataset.phase);
                var sampleId = e.target.dataset.sample;
                assignPhase(sampleId, phase);
                trUpdate();
            }
        });

        // Bulk assignment buttons
        document.getElementById('bulkTrain') && document.getElementById('bulkTrain').addEventListener('click', function(){
            trCbs.forEach(function(cb){ if (cb.checked) assignPhase(cb.value, 1); });
            trUpdate();
        });
        document.getElementById('bulkVal') && document.getElementById('bulkVal').addEventListener('click', function(){
            trCbs.forEach(function(cb){ if (cb.checked) assignPhase(cb.value, 2); });
            trUpdate();
        });
        document.getElementById('bulkTest') && document.getElementById('bulkTest').addEventListener('click', function(){
            trCbs.forEach(function(cb){ if (cb.checked) assignPhase(cb.value, 3); });
            trUpdate();
        });

        if (trSrv)  trSrv.addEventListener('change',  trUpdate);
        if (trHead) trHead.addEventListener('change', trUpdate);
        if (trFeat) trFeat.addEventListener('change', trUpdate);
        trUpdate();

    // ── Label source: hint text + hierarchical controls ───────────────────
    var trLabelType  = document.getElementById('trLabelType');
    var trHierWeight = document.getElementById('trHierWeight');
    var trHint       = document.getElementById('trLabelTypeHint');
    var trPreview    = document.getElementById('trClassPreview');

    var HINTS = {
        category:        'Coarse family-level classes (e.g. Normal / Tumor).',
        disease_type:    'Free-text diagnosis carried on the patient case.',
        disease_subtype: 'Exact disease name (taxonomy leaf) + its category as an auxiliary coarse head.'
    };

    function syncLabelTypeUI() {
        if (!trLabelType) return;
        var t = trLabelType.value;
        if (trHint) trHint.textContent = HINTS[t] || '';
        if (trHierWeight) {
            trHierWeight.disabled = (t !== 'disease_subtype');
            if (trHierWeight.disabled) { trHierWeight.value = '0'; }
            else if (trHierWeight.value === '0') { trHierWeight.value = '0.3'; }
        }
        if (trPreview) {
            trPreview.innerHTML = '<span class="text-muted">Label source changed — press '
                + '<em>Detect from selected samples</em> to refresh the class list.</span>';
        }
    }

    if (trLabelType) {
        trLabelType.addEventListener('change', syncLabelTypeUI);
        syncLabelTypeUI();
    }

    // ── Detect the real classes for the current selection ─────────────────
    function selectedTrainingSampleIds() {
        var ids = [];
        document.querySelectorAll('.tr-sample-cb:checked').forEach(function (cb) {
            ids.push(cb.value);
        });
        if (!ids.length) {
            document.querySelectorAll('#trainingForm input[name="sample_ids[]"]').forEach(function (el) {
                if (el.type !== 'checkbox' || el.checked) ids.push(el.value);
            });
        }
        return ids;
    }

    var detectBtn = document.getElementById('trDetectClasses');
    if (detectBtn) {
        detectBtn.addEventListener('click', function () {
            var ids = selectedTrainingSampleIds();
            if (!ids.length) {
                trPreview.innerHTML = '<span class="text-danger">Select at least one sample first.</span>';
                return;
            }

            var token = document.querySelector('input[name="_token"]');
            var body  = new FormData();
            body.append('_token', token ? token.value : '');
            body.append('label_type', trLabelType ? trLabelType.value : 'category');
            ids.forEach(function (id) { body.append('sample_ids[]', id); });

            trPreview.innerHTML = '<span class="text-muted">Detecting…</span>';

            fetch('{{ route('admin.workflow.training.class-preview') }}', {
                method: 'POST',
                body: body,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) {
                    trPreview.innerHTML = '<span class="text-danger">'
                        + (data.message || 'Could not detect classes.') + '</span>';
                    return;
                }

                var organLabel = (data.organs && data.organs.length)
                    ? data.organs.join(', ') : '—';

                var html = '<div class="mb-1">'
                    + '<span class="badge badge-primary mr-1">Organ: ' + organLabel + '</span>'
                    + '<span class="badge badge-dark mr-1">' + data.n_classes + ' classes</span>';
                if (data.hierarchical) {
                    html += '<span class="badge badge-info mr-1">'
                         + data.n_parent_classes + ' clinical groups — hierarchical</span>';
                }
                html += '<span class="badge badge-light border">' + data.eligible_count
                     + ' eligible slides</span></div>';

                // A run is scoped to one organ: the same clinical group means a
                // different disease in each organ, so mixing them merges entities.
                if (data.organ_conflict) {
                    html += '<div class="text-danger font-weight-bold mb-1">'
                         + 'This selection spans ' + data.organs.length + ' organs (' + organLabel + '). '
                         + 'A run must cover one organ only — dispatch will be refused.</div>';
                }
                if (data.missing_organ > 0) {
                    html += '<div class="text-danger mb-1">'
                         + data.missing_organ + ' selected slide(s) have no organ assigned '
                         + 'and will block dispatch.</div>';
                }

                if (data.unlabelled_count > 0) {
                    html += '<div class="text-danger mb-1">'
                         + data.unlabelled_count + ' selected slide(s) have no label for this source '
                         + 'and will block dispatch — fix or deselect them.</div>';
                }

                html += '<table class="table table-sm table-bordered mb-0" style="font-size:.8rem;">'
                     + '<thead class="thead-light"><tr><th style="width:60px;">Index</th>'
                     + '<th>Class</th><th style="width:140px;">Parent</th>'
                     + '<th style="width:80px;">Slides</th></tr></thead><tbody>';

                data.classes.forEach(function (c) {
                    var warn = c.count < 2 ? ' class="table-warning"' : '';
                    html += '<tr' + warn + '><td>' + c.index + '</td><td>' + c.label + '</td>'
                         + '<td class="text-muted">' + (c.parent || '—') + '</td>'
                         + '<td>' + c.count + '</td></tr>';
                });
                html += '</tbody></table>';
                html += '<small class="text-muted d-block mt-1">'
                     + 'Rows highlighted in yellow have too few slides to appear in both Train and Val.'
                     + '</small>';

                trPreview.innerHTML = html;
            })
            .catch(function () {
                trPreview.innerHTML = '<span class="text-danger">Class detection request failed.</span>';
            });
        });
    }

    // ── Final client-side guard on submit ─────────────────────────────────
    // The label map itself is derived server-side from the database, so there
    // is nothing to serialise here any more.
    var trForm = document.getElementById('trainingForm');
    if (trForm) {
        trForm.addEventListener('submit', function (e) {
            var errEl = document.getElementById('trSplitError');
            if (errEl && errEl.textContent) {
                e.preventDefault();
                alert(errEl.textContent);
                return;
            }
            var jsonField = document.getElementById('labelMapJson');
            if (jsonField) jsonField.value = '';   // blank = derive from data
        });
    }

})();
</script>
@endpush
@endsection
