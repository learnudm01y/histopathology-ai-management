@extends('admin.layouts.app')
@section('title', 'Sample #' . $sample->id)

@section('content')
<div class="page-header">
    <h3 class="page-title">Sample Details</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.samples') }}">Samples</a></li>
            <li class="breadcrumb-item active">#{{ $sample->id }}</li>
        </ol>
    </nav>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="mdi mdi-check-circle mr-1"></i> {{ session('success') }}
    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
</div>
@endif

{{-- ── Action bar ──────────────────────────────────────────────────────── --}}
<div class="d-flex align-items-center mb-4 flex-wrap" style="gap:.5rem;">
    <a href="{{ route('admin.samples.edit', $sample) }}" class="btn btn-primary btn-sm">
        <i class="mdi mdi-pencil mr-1"></i> Edit
    </a>
    <form action="{{ route('admin.samples.destroy', $sample) }}" method="POST" class="d-inline"
          onsubmit="return confirm('Delete sample #{{ $sample->id }}? This cannot be undone.')">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-outline-danger btn-sm">
            <i class="mdi mdi-delete mr-1"></i> Delete
        </button>
    </form>

    {{-- Retry button — only for corrupted Drive-sourced samples --}}
    @if($sample->storage_status === 'corrupted' && $sample->gdrive_source_id)
    <form action="{{ route('admin.samples.retry', $sample) }}" method="POST" class="d-inline"
          onsubmit="return confirm('Retry upload for sample #{{ $sample->id }}?')">
        @csrf
        <button type="submit" class="btn btn-warning btn-sm">
            <i class="mdi mdi-refresh mr-1"></i> Retry Upload
        </button>
    </form>
    @elseif($sample->storage_status === 'corrupted')
    <span class="badge badge-danger px-3 py-2" title="No Google Drive source ID stored — re-upload manually">
        <i class="mdi mdi-alert-circle mr-1"></i> Upload Failed — Re-upload Required
    </span>
    @endif

    {{-- Verify Slide — 2-phase: metadata (fast) then deep WSI via queue --}}
    <button id="verify-slide-btn" type="button" class="btn btn-info btn-sm"
            data-verify-url="{{ route('admin.samples.verify', $sample) }}"
            data-csrf="{{ csrf_token() }}">
        <i class="mdi mdi-clipboard-check-outline mr-1"></i> Verify Slide
    </button>

    @if($sample->storage_link)
    <a href="{{ $sample->storage_link }}" target="_blank" class="btn btn-outline-success btn-sm ml-auto">
        <i class="mdi mdi-google-drive mr-1"></i> Open on Drive
    </a>
    @endif
</div>

@if($sample->storage_status === 'corrupted')
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="mdi mdi-alert-circle mr-1"></i>
    <strong>Upload Failed.</strong>
    The last upload attempt failed — likely due to a network/internet issue.
    @if($sample->gdrive_source_id)
        Click <strong>Retry Upload</strong> to try again.
    @else
        Please re-upload this sample using the Add Sample form.
    @endif
    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
</div>
@endif

{{-- ── Clinical Information ────────────────────────────────────────────── --}}
@php
    $clinical = $sample->patientCase?->clinicalInfo;
@endphp
<div class="row">
    <div class="col-12 grid-margin">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="mdi mdi-medical-bag mr-1 text-primary"></i> Clinical Information
                </h5>

                @if(!$clinical)
                    <div class="text-muted small">
                        <i class="mdi mdi-information-outline mr-1"></i>
                        No clinical information linked to this slide.
                    </div>
                @else
                <div class="row">

                    {{-- ── Patient / Case identity ── --}}
                    <div class="col-lg-4 mb-4">
                        <h6 class="text-uppercase text-muted mb-2" style="font-size:.7rem; letter-spacing:.07em;">
                            Patient &amp; Case
                        </h6>
                        @foreach([
                            ['Case ID',           $clinical->case_id],
                            ['Submitter ID',       $clinical->submitter_id],
                            ['Project',            $clinical->project_id],
                            ['Disease Type',       $clinical->disease_type],
                            ['Primary Site',       $clinical->primary_site],
                            ['Gender',             $clinical->gender],
                            ['Sex at Birth',       $clinical->sex_at_birth],
                            ['Race',               $clinical->race],
                            ['Ethnicity',          $clinical->ethnicity],
                            ['Age at Index',       $clinical->age_at_index ? $clinical->age_at_index . ' yrs' : null],
                            ['Vital Status',       $clinical->vital_status],
                        ] as [$lbl, $val])
                        <div class="d-flex border-bottom py-1">
                            <span class="text-muted" style="min-width:145px;font-size:.82rem;">{{ $lbl }}</span>
                            <span class="font-weight-medium" style="font-size:.85rem;">
                                {{ $val ?? '—' }}
                            </span>
                        </div>
                        @endforeach
                    </div>

                    {{-- ── Primary Diagnosis ── --}}
                    <div class="col-lg-4 mb-4">
                        <h6 class="text-uppercase text-muted mb-2" style="font-size:.7rem; letter-spacing:.07em;">
                            Primary Diagnosis
                        </h6>
                        @foreach([
                            ['Primary Diagnosis',    $clinical->primary_diagnosis],
                            ['Tissue/Organ Origin',  $clinical->tissue_or_organ_of_origin],
                            ['Site of Biopsy',       $clinical->site_of_resection_or_biopsy],
                            ['ICD-10 Code',          $clinical->icd_10_code],
                            ['Morphology',           $clinical->morphology],
                            ['Tumor Classification', $clinical->classification_of_tumor],
                            ['Year of Diagnosis',    $clinical->year_of_diagnosis],
                            ['Method of Diagnosis',  $clinical->method_of_diagnosis],
                            ['Prior Malignancy',     $clinical->prior_malignancy],
                            ['Prior Treatment',      $clinical->prior_treatment],
                            ['Synchronous Malig.',   $clinical->synchronous_malignancy],
                            ['Laterality',           $clinical->laterality],
                        ] as [$lbl, $val])
                        <div class="d-flex border-bottom py-1">
                            <span class="text-muted" style="min-width:145px;font-size:.82rem;">{{ $lbl }}</span>
                            <span class="font-weight-medium" style="font-size:.85rem;">
                                {{ $val ?? '—' }}
                            </span>
                        </div>
                        @endforeach
                    </div>

                    {{-- ── Staging & Pathology ── --}}
                    <div class="col-lg-4 mb-4">
                        <h6 class="text-uppercase text-muted mb-2" style="font-size:.7rem; letter-spacing:.07em;">
                            Staging &amp; Pathology
                        </h6>
                        @foreach([
                            ['AJCC Pathologic Stage',  $clinical->ajcc_pathologic_stage],
                            ['AJCC T',                 $clinical->ajcc_pathologic_t],
                            ['AJCC N',                 $clinical->ajcc_pathologic_n],
                            ['AJCC M',                 $clinical->ajcc_pathologic_m],
                            ['AJCC Edition',           $clinical->ajcc_staging_system_edition],
                            ['Lymph Nodes Positive',   $clinical->lymph_nodes_positive],
                            ['Lymph Nodes Tested',     $clinical->lymph_nodes_tested],
                            ['Metastasis at Dx',       $clinical->metastasis_at_diagnosis],
                            ['Consistent Path. Review',$clinical->consistent_pathology_review],
                        ] as [$lbl, $val])
                        <div class="d-flex border-bottom py-1">
                            <span class="text-muted" style="min-width:145px;font-size:.82rem;">{{ $lbl }}</span>
                            <span class="font-weight-medium" style="font-size:.85rem;">
                                {{ $val ?? '—' }}
                            </span>
                        </div>
                        @endforeach

                        @if($clinical->sites_of_involvement)
                        <div class="d-flex border-bottom py-1">
                            <span class="text-muted" style="min-width:145px;font-size:.82rem;">Sites of Involvement</span>
                            <span style="font-size:.85rem;">
                                {{ implode(', ', (array) $clinical->sites_of_involvement) }}
                            </span>
                        </div>
                        @endif
                    </div>

                </div>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row">

    {{-- ── Left: File & Storage ───────────────────────────────────────────── --}}
    <div class="col-lg-6 grid-margin">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="mdi mdi-file-outline mr-1 text-primary"></i> File & Storage
                </h5>

                @php
                function detail_row($label, $value, $badge = null, $badgeClass = 'secondary') {
                    echo '<div class="d-flex border-bottom py-2">';
                    echo '<span class="text-muted" style="min-width:160px;font-size:.85rem;">' . e($label) . '</span>';
                    if ($badge) {
                        echo '<span class="badge badge-' . e($badgeClass) . '">' . e($value) . '</span>';
                    } else {
                        echo '<span class="font-weight-medium" style="font-size:.875rem;">' . (is_null($value) ? '<span class=\'text-muted\'>—</span>' : e($value)) . '</span>';
                    }
                    echo '</div>';
                }
                @endphp

                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Sample ID</span>
                    <span class="font-weight-bold">#{{ $sample->id }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">File Name</span>
                    <span class="font-weight-medium small text-break">{{ $sample->file_name ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Format</span>
                    <span class="badge badge-info">{{ $sample->data_format ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">File Size</span>
                    <span class="font-weight-medium">{{ $sample->file_size_human }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">File Size (bytes)</span>
                    <span class="small text-muted">{{ $sample->file_size_bytes ? number_format($sample->file_size_bytes) : '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Drive File ID</span>
                    <span class="small" style="font-family:monospace;">{{ $sample->file_id ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">MD5</span>
                    <span class="small" style="font-family:monospace;">{{ $sample->md5sum ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">MD5 Verified</span>
                    @if($sample->md5_verified)
                        <span class="badge badge-success"><i class="mdi mdi-check"></i> Yes</span>
                    @else
                        <span class="badge badge-secondary">No</span>
                    @endif
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Storage Status</span>
                    <span class="badge badge-{{ $sample->storage_status_badge }}">
                        {{ str_replace('_', ' ', ucfirst($sample->storage_status)) }}
                    </span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Drive Path</span>
                    <span class="small text-break text-muted">{{ $sample->storage_path ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Drive Link</span>
                    @if($sample->storage_link)
                        <a href="{{ $sample->storage_link }}" target="_blank" class="small">
                            <i class="mdi mdi-open-in-new mr-1"></i>Open
                        </a>
                    @else
                        <span class="text-muted small">—</span>
                    @endif
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Download Started</span>
                    <span class="small">{{ $sample->download_started_at?->format('Y-m-d H:i') ?? '—' }}</span>
                </div>
                <div class="d-flex py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Download Completed</span>
                    <span class="small">{{ $sample->download_completed_at?->format('Y-m-d H:i') ?? '—' }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Right: Classification & Quality ────────────────────────────────── --}}
    <div class="col-lg-6 grid-margin">

        {{-- Classification --}}
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="mdi mdi-tag-outline mr-1 text-primary"></i> Classification
                </h5>

                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Organ</span>
                    <span class="badge badge-light border">{{ $sample->organ->name ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Data Source</span>
                    <span class="font-weight-medium small">{{ $sample->dataSource->name ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Category</span>
                    @if($sample->category)
                        <span class="badge badge-secondary">{{ $sample->category->label_en }}</span>
                    @else
                        <span class="text-muted small">—</span>
                    @endif
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Disease Subtype</span>
                    <span class="small font-weight-medium">{{ $sample->disease_subtype ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Slide Submitter ID</span>
                    <span class="small" style="font-family:monospace;">{{ $sample->entity_submitter_id ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Entity ID</span>
                    <span class="small" style="font-family:monospace;">{{ $sample->entity_id ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Entity Type</span>
                    <span class="small">{{ $sample->entity_type ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Tissue Name</span>
                    <span class="small text-muted">{{ $sample->tissue_name ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Training Phase</span>
                    <span class="small">{{ $sample->training_phase ? 'Phase ' . $sample->training_phase : '—' }}</span>
                </div>
                <div class="d-flex py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Usable</span>
                    @if($sample->is_usable)
                        <span class="badge badge-success"><i class="mdi mdi-check"></i> Yes</span>
                    @else
                        <span class="badge badge-danger"><i class="mdi mdi-close"></i> No</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Quality & Tiling --}}
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-4">
                    <i class="mdi mdi-grid mr-1 text-primary"></i> Quality & Tiling
                </h5>

                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Quality Status</span>
                    <span class="badge badge-{{ $sample->quality_status_badge }}">
                        {{ str_replace('_', ' ', ucfirst($sample->quality_status ?? 'pending')) }}
                    </span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Rejection Reason</span>
                    <span class="small text-muted">{{ $sample->quality_rejection_reason ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Tiling Status</span>
                    <span class="badge badge-{{ $sample->tiling_status_badge }}">
                        {{ ucfirst($sample->tiling_status ?? 'pending') }}
                    </span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Tile Count</span>
                    <span class="small">{{ $sample->tile_count ? number_format($sample->tile_count) : '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Tile Size (px)</span>
                    <span class="small">{{ $sample->tile_size_px ?? '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Magnification</span>
                    <span class="small">{{ $sample->magnification ? $sample->magnification . 'x' : '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Tissue Coverage</span>
                    <span class="small">{{ $sample->tissue_coverage_pct ? $sample->tissue_coverage_pct . '%' : '—' }}</span>
                </div>
                <div class="d-flex border-bottom py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Tiles Path</span>
                    <span class="small text-muted text-break">{{ $sample->tiles_path ?? '—' }}</span>
                </div>
                <div class="d-flex py-2">
                    <span class="text-muted" style="min-width:160px;font-size:.85rem;">Tiling Completed</span>
                    <span class="small">{{ $sample->tiling_completed_at?->format('Y-m-d H:i') ?? '—' }}</span>
                </div>
            </div>
        </div>

    </div>

    {{-- ── Right: File Preview ──────────────────────────────────────── --}}
    <div class="col-lg-6 grid-margin">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="mdi mdi-file-image-outline mr-1 text-primary"></i> File Preview
                    <span class="badge badge-warning ml-2" style="font-size:.65rem;" title="Preview downloads the slide temporarily for OpenSlide inspection">
                        <i class="mdi mdi-flask-outline"></i> OpenSlide
                    </span>
                </h5>

                @if($sample->file_id || $sample->wsi_remote_path || $sample->storage_path)
                    {{-- ── Preview trigger button ── --}}
                    <div id="wsi-preview-idle" class="text-center py-4">
                        <p class="text-muted small mb-3">
                            <i class="mdi mdi-information-outline mr-1"></i>
                            The slide will be fetched from Google Drive to our server,
                            opened with OpenSlide, and displayed here.<br>
                            <strong>Verification checks will be updated automatically.</strong>
                        </p>
                        <button id="wsi-load-btn" type="button" class="btn btn-primary">
                            <i class="mdi mdi-download-outline mr-1"></i> Load WSI Preview
                        </button>
                    </div>

                    {{-- ── Loading state ── --}}
                    <div id="wsi-preview-loading" class="text-center py-4 d-none">
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="sr-only">Loading…</span>
                        </div>
                        <p class="text-muted small mb-0" id="wsi-loading-msg">
                            Fetching slide from Google Drive…<br>
                            <span class="text-danger small">Large files may take several minutes.</span>
                        </p>
                        <div class="progress mt-3" style="height:6px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary w-100"></div>
                        </div>
                    </div>

                    {{-- ── Error state ── --}}
                    <div id="wsi-preview-error" class="d-none">
                        <div class="alert alert-danger small mb-2">
                            <i class="mdi mdi-alert-circle-outline mr-1"></i>
                            <strong>Preview failed:</strong> <span id="wsi-error-msg"></span>
                        </div>
                        <button id="wsi-retry-btn" type="button" class="btn btn-outline-secondary btn-sm">
                            <i class="mdi mdi-refresh mr-1"></i> Retry
                        </button>
                    </div>

                    {{-- ── Ready: summary + open-viewer button ── --}}
                    <div id="wsi-preview-ready" class="d-none">
                        {{-- check results banner --}}
                        <div id="wsi-checks-banner" class="mb-2 d-flex flex-wrap" style="gap:.4rem;"></div>

                        {{-- duplicate warning --}}
                        <div id="wsi-duplicate-alert" class="alert alert-warning small py-2 px-3 mb-2 d-none">
                            <i class="mdi mdi-alert-outline mr-1"></i>
                            <strong>Duplicate detected:</strong> <span id="wsi-duplicate-msg"></span>
                        </div>

                        {{-- WSI metadata chips --}}
                        <div id="wsi-meta-chips" class="mb-3 d-flex flex-wrap" style="gap:.3rem;"></div>

                        {{-- Open full-screen viewer (hidden after first close — single-shot viewing) --}}
                        <div id="wsi-open-modal-wrap" class="text-center py-3" style="display:none;">
                            <button id="wsi-open-modal-btn" type="button" class="btn btn-primary btn-lg">
                                <i class="mdi mdi-fullscreen mr-1"></i>
                                Open Full Preview
                            </button>
                            <p class="small text-muted mt-2 mb-0">
                                <i class="mdi mdi-information-outline mr-1"></i>
                                Single-shot viewing — temp file is deleted on close.
                            </p>
                        </div>

                        {{-- Delete temp file (secondary action) --}}
                        <div class="text-right mt-1">
                            <button id="wsi-close-btn" type="button"
                                    class="btn btn-outline-secondary btn-sm" style="font-size:.72rem;">
                                <i class="mdi mdi-delete-outline mr-1"></i>
                                Delete Temp File
                            </button>
                        </div>
                    </div>

                @else
                    <div class="text-center py-5 text-muted">
                        <i class="mdi mdi-file-question-outline" style="font-size:3rem;"></i>
                        <p class="mt-3 mb-0">Preview not available</p>
                        <small>Upload the slide to Google Drive first to enable preview.</small>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ── Slide Verification ──────────────────────────────────────────────── --}}
@php
    $verification  = $sample->slideVerification;
    $checks        = $verification?->evaluateChecks() ?? [];
    $grouped       = collect($checks)->groupBy('group');
    $failedChecks  = collect($checks)->where('state', 'failed');
    $statusBadge   = match ($verification?->verification_status) {
        'passed'  => 'success',
        'failed'  => 'danger',
        'pending' => 'warning',
        default   => 'secondary',
    };
    $countsByState = collect($checks)->countBy('state');
    $verifUrl      = $verification ? route('admin.samples.verification.update', $sample) : '#';

    // Per-column field metadata for inline editing
    $fieldMeta = [
        'file_path'             => ['type' => 'text'],
        'slide_id'              => ['type' => 'text'],
        'patient_id'            => ['type' => 'text'],
        'case_id'               => ['type' => 'text'],
        'project_id'            => ['type' => 'text'],
        'file_extension'        => ['type' => 'text'],
        'file_size_mb'          => ['type' => 'number', 'step' => '0.001', 'min' => '0'],
        'open_slide_status'     => ['type' => 'select', 'options' => ['not_checked' => 'Not Checked', 'passed' => 'Passed', 'failed' => 'Failed']],
        'file_integrity_status' => ['type' => 'select', 'options' => ['not_checked' => 'Not Checked', 'passed' => 'Passed', 'failed' => 'Failed']],
        'read_test_status'      => ['type' => 'select', 'options' => ['not_checked' => 'Not Checked', 'passed' => 'Passed', 'failed' => 'Failed']],
        'level_count'           => ['type' => 'number', 'step' => '1',       'min' => '0'],
        'slide_width'           => ['type' => 'number', 'step' => '1',       'min' => '0'],
        'slide_height'          => ['type' => 'number', 'step' => '1',       'min' => '0'],
        'mpp_x'                 => ['type' => 'number', 'step' => '0.000001','min' => '0'],
        'mpp_y'                 => ['type' => 'number', 'step' => '0.000001','min' => '0'],
        'magnification_power'   => ['type' => 'number', 'step' => '0.01',   'min' => '0'],
        'sample_type'           => ['type' => 'text'],
        'stain_type'            => ['type' => 'text'],
        'gender'                => ['type' => 'select', 'options' => ['male' => 'Male', 'female' => 'Female', 'unknown' => 'Unknown']],
        'age_at_index'          => ['type' => 'number', 'step' => '1', 'min' => '0', 'max' => '150'],
        'label'                 => ['type' => 'text'],
        'label_status'          => ['type' => 'select', 'options' => ['valid' => 'Valid', 'ambiguous' => 'Ambiguous', 'unknown' => 'Unknown']],
        'tissue_area_percent'   => ['type' => 'number', 'step' => '0.01',   'min' => '0', 'max' => '100'],
        'tissue_patch_count'    => ['type' => 'number', 'step' => '1',      'min' => '0'],
        'artifact_score'        => ['type' => 'number', 'step' => '0.0001', 'min' => '0', 'max' => '1'],
        'blur_score'            => ['type' => 'number', 'step' => '0.0001', 'min' => '0', 'max' => '1'],
        'background_ratio'      => ['type' => 'number', 'step' => '0.0001', 'min' => '0', 'max' => '1'],
        'notes'                 => ['type' => 'textarea'],
    ];
@endphp

<div class="row">
    <div class="col-12 grid-margin">
        <div class="card" id="vv-card"
             data-update-url="{{ $verifUrl }}"
             data-csrf="{{ csrf_token() }}">
            <div class="card-body">

                {{-- ── Header ── --}}
                <div class="d-flex align-items-center mb-3 flex-wrap" style="gap:.5rem;">
                    <h5 class="card-title mb-0">
                        <i class="mdi mdi-shield-check-outline mr-1 text-primary"></i>
                        Slide Verification
                    </h5>

                    @if($verification)
                        <span class="badge badge-{{ $statusBadge }} ml-2 px-2 py-1" id="vv-overall-badge">
                            {{ ucfirst($verification->verification_status) }}
                        </span>
                        <span class="text-muted small ml-2">
                            {{ $verification->verified_at?->format('Y-m-d H:i') ?? '—' }}
                        </span>
                        <span class="ml-auto d-flex" style="gap:.75rem;">
                            <span class="text-success small"><i class="mdi mdi-check-circle"></i> {{ $countsByState['passed'] ?? 0 }}</span>
                            <span class="text-danger small"><i class="mdi mdi-close-circle"></i> {{ $countsByState['failed'] ?? 0 }}</span>
                            <span class="text-muted small"><i class="mdi mdi-clock-outline"></i> {{ $countsByState['not_checked'] ?? 0 }}</span>
                        </span>
                    @else
                        <span class="badge badge-secondary ml-2">Not Verified Yet</span>
                    @endif
                </div>

                @if(!$verification)
                    <div class="alert alert-light border mb-0 small">
                        <i class="mdi mdi-information-outline mr-1"></i>
                        Not processed yet. Click <strong>Verify Slide</strong> above.
                    </div>
                @else

                    {{-- ── Failures summary (compact bullet list) ── --}}
                    @if($failedChecks->isNotEmpty())
                        <div class="alert alert-danger py-2 px-3 mb-3 small">
                            <strong><i class="mdi mdi-alert-circle-outline mr-1"></i>Issues:</strong>
                            <ul class="mb-0 mt-1 pl-3">
                                @foreach($failedChecks as $fc)
                                    <li>
                                        {{ $fc['label'] }}
                                        @if($fc['detail'])
                                            &mdash; <em>{{ Str::limit($fc['detail'], 55) }}</em>
                                        @else
                                            &mdash; <em>missing / not set</em>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- ── Per-group compact tables ── --}}
                    @foreach(\App\Models\SlideVerification::GROUP_LABELS as $groupCode => $groupLabel)
                        @if($grouped->has($groupCode))
                            <h6 class="text-uppercase text-muted mb-1 mt-3"
                                style="font-size:.68rem; letter-spacing:.08em;">
                                {{ $groupLabel }}
                            </h6>
                            <table class="table table-sm table-borderless mb-2"
                                   style="table-layout:fixed; width:100%;">
                                <colgroup>
                                    <col style="width:24px;">
                                    <col style="width:36%;">
                                    <col>
                                    <col style="width:68px;">
                                </colgroup>
                                <tbody>
                                @foreach($grouped[$groupCode] as $row)
                                    @php
                                        [$icon, $rowClass, $stateLabel] = match ($row['state']) {
                                            'passed'      => ['mdi-check-circle text-success', '',             'Passed'],
                                            'failed'      => ['mdi-close-circle text-danger',  'table-danger', 'Failed'],
                                            'not_checked' => ['mdi-clock-outline text-muted',  '',             'Pending'],
                                            default       => ['mdi-help-circle text-muted',    '',             '—'],
                                        };
                                        $isVirtual = ($row['code'] === 'slide_dimensions');
                                        $colName   = $isVirtual ? null : $row['code'];
                                        $rawValue  = !$isVirtual ? ($verification->{$colName} ?? null) : null;
                                        $colMeta   = !$isVirtual ? ($fieldMeta[$colName] ?? ['type' => 'text']) : null;
                                    @endphp
                                    <tr class="{{ $rowClass }}">
                                        <td class="align-middle" style="padding:3px 5px;">
                                            <i class="mdi {{ $icon }}" style="font-size:.9rem;"></i>
                                        </td>
                                        <td class="align-middle small" style="padding:3px 5px; overflow:hidden;">
                                            <span class="font-weight-medium">{{ $row['label'] }}</span>
                                            <br>
                                            <small class="text-muted" style="font-size:.68rem;">({{ $row['code'] }})</small>
                                        </td>
                                        <td class="align-middle" style="padding:3px 5px; overflow:hidden;">
                                            @if($isVirtual)
                                                <div class="d-flex align-items-center" style="gap:4px; flex-wrap:nowrap;">
                                                    @include('admin.partials.vv-field', [
                                                        'colName'     => 'slide_width',
                                                        'rawValue'    => $verification->slide_width,
                                                        'meta'        => $fieldMeta['slide_width'],
                                                        'placeholder' => 'W',
                                                    ])
                                                    <span class="text-muted small">×</span>
                                                    @include('admin.partials.vv-field', [
                                                        'colName'     => 'slide_height',
                                                        'rawValue'    => $verification->slide_height,
                                                        'meta'        => $fieldMeta['slide_height'],
                                                        'placeholder' => 'H',
                                                    ])
                                                </div>
                                            @else
                                                @include('admin.partials.vv-field', [
                                                    'colName'     => $colName,
                                                    'rawValue'    => $rawValue,
                                                    'meta'        => $colMeta,
                                                    'placeholder' => '',
                                                ])
                                            @endif
                                        </td>
                                        <td class="text-right align-middle" style="padding:3px 5px;">
                                            <span class="badge badge-outline-{{ $row['state'] === 'passed' ? 'success' : ($row['state'] === 'failed' ? 'danger' : 'secondary') }}"
                                                  style="font-size:.67rem;">
                                                {{ $stateLabel }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endif
                    @endforeach

                    {{-- ── Notes ── --}}
                    <div class="mt-3 pt-2 border-top">
                        <div class="small text-muted font-weight-bold mb-1">
                            <i class="mdi mdi-note-outline mr-1"></i>Notes
                        </div>
                        <div class="vv-field" data-field="notes" data-current="{{ $verification->notes ?? '' }}">
                            <div class="vv-display small text-muted"
                                 style="cursor:pointer; min-height:22px; padding:3px 6px;
                                        border:1px dashed #ccc; border-radius:4px;"
                                 title="Click to edit notes">
                                {{ $verification->notes ?: '— click to add notes —' }}
                                <i class="mdi mdi-pencil-outline ml-1" style="font-size:.7rem; opacity:.5;"></i>
                            </div>
                            <div class="vv-input-group d-none">
                                <textarea class="form-control form-control-sm vv-input" rows="2">{{ $verification->notes }}</textarea>
                                <div class="mt-1 d-flex" style="gap:4px;">
                                    <button type="button" class="btn btn-success btn-sm py-0 px-2 vv-save"
                                            style="font-size:.75rem; line-height:1.5;">Save</button>
                                    <button type="button" class="btn btn-light btn-sm py-0 px-2 vv-cancel"
                                            style="font-size:.75rem; line-height:1.5;">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>

                @endif
            </div>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════
     Verify Slide — 2-phase progress overlay
     ══════════════════════════════════════════════════════════════════════ --}}
<div id="verify-progress-overlay"
     style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh;
            background:rgba(0,0,0,.72); z-index:9998; align-items:center;
            justify-content:center; font-family:inherit;">
    <div style="background:#1c1c1c; border:1px solid #333; border-radius:10px;
                width:100%; max-width:520px; padding:2rem; margin:1rem; color:#ddd;">

        {{-- Header --}}
        <div class="d-flex align-items-center mb-3" style="gap:.6rem;">
            <i class="mdi mdi-clipboard-check-outline" style="font-size:1.5rem; color:#17a2b8;"></i>
            <h5 class="mb-0" style="font-size:1rem; font-weight:600; color:#eee;">
                Full Slide Verification
            </h5>
        </div>

        {{-- Phase list --}}
        <div id="vp-phase1-row" class="d-flex align-items-center mb-2" style="gap:.7rem;">
            <span id="vp-phase1-icon" style="width:20px; text-align:center;">
                <span class="spinner-border spinner-border-sm text-info" style="width:.85rem;height:.85rem;"></span>
            </span>
            <span style="font-size:.85rem;">
                <strong>Phase 1:</strong> Metadata verification
                <span id="vp-phase1-detail" class="text-muted ml-1" style="font-size:.78rem;"></span>
            </span>
        </div>
        <div id="vp-phase2-row" class="d-flex align-items-center mb-3" style="gap:.7rem; opacity:.4;">
            <span id="vp-phase2-icon" style="width:20px; text-align:center;">
                <i class="mdi mdi-clock-outline" style="color:#888;"></i>
            </span>
            <span style="font-size:.85rem;">
                <strong>Phase 2:</strong> Deep WSI analysis (OpenSlide + Python)
                <span id="vp-phase2-detail" class="text-muted ml-1" style="font-size:.78rem;"></span>
            </span>
        </div>

        {{-- Progress bar --}}
        <div class="progress mb-3" style="height:6px; background:#333;">
            <div id="vp-progress-bar"
                 class="progress-bar progress-bar-striped progress-bar-animated bg-info"
                 style="width:10%;"></div>
        </div>

        {{-- Status message --}}
        <p id="vp-status-msg" class="small mb-3" style="color:#aaa; min-height:1.2em;"></p>

        {{-- Error box (hidden until error) --}}
        <div id="vp-error-box" class="alert alert-danger small py-2 px-3 mb-3 d-none">
            <i class="mdi mdi-alert-circle-outline mr-1"></i>
            <span id="vp-error-msg"></span>
        </div>

        {{-- Action buttons --}}
        <div class="d-flex" style="gap:.5rem;">
            <button id="vp-close-btn" type="button" class="btn btn-sm btn-secondary d-none">
                Close
            </button>
            <button id="vp-reload-btn" type="button" class="btn btn-sm btn-success d-none"
                    onclick="window.location.reload()">
                <i class="mdi mdi-refresh mr-1"></i>Reload Page
            </button>
        </div>
    </div>
</div>

@if($sample->file_id || $sample->wsi_remote_path || $sample->storage_path)
<script>
(function () {
    'use strict';

    var btn = document.getElementById('verify-slide-btn');
    if (!btn) return;

    var overlay   = document.getElementById('verify-progress-overlay');
    var ph1Icon   = document.getElementById('vp-phase1-icon');
    var ph1Detail = document.getElementById('vp-phase1-detail');
    var ph2Row    = document.getElementById('vp-phase2-row');
    var ph2Icon   = document.getElementById('vp-phase2-icon');
    var ph2Detail = document.getElementById('vp-phase2-detail');
    var pbar      = document.getElementById('vp-progress-bar');
    var statusMsg = document.getElementById('vp-status-msg');
    var errBox    = document.getElementById('vp-error-box');
    var errMsg    = document.getElementById('vp-error-msg');
    var closeBtn  = document.getElementById('vp-close-btn');
    var reloadBtn = document.getElementById('vp-reload-btn');

    var pollTimer = null;
    var pollCount = 0;
    var MAX_POLLS = 480; // 40 min
    var statusUrl = null;

    var MSGS_PHASE2 = [
        'Downloading slide from Google Drive…',
        'Transferring slide data…',
        'Computing MD5 checksum…',
        'Opening slide with OpenSlide…',
        'Testing reads from multiple regions…',
        'Computing quality metrics…',
        'Generating preview thumbnail…',
        'Almost done…',
    ];

    function openOverlay() {
        overlay.style.display  = 'flex';
        document.body.style.overflow = 'hidden';
        // Reset state
        setPhase1Spinner();
        ph2Row.style.opacity   = '0.4';
        ph2Icon.innerHTML      = '<i class="mdi mdi-clock-outline" style="color:#888;"></i>';
        ph2Detail.textContent  = '';
        pbar.style.width       = '10%';
        statusMsg.textContent  = 'Verifying metadata…';
        errBox.classList.add('d-none');
        closeBtn.classList.add('d-none');
        reloadBtn.classList.add('d-none');
    }

    function closeOverlay() {
        clearTimeout(pollTimer);
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    }

    function setPhase1Spinner() {
        ph1Icon.innerHTML = '<span class="spinner-border spinner-border-sm text-info" style="width:.85rem;height:.85rem;"></span>';
        ph1Detail.textContent = '';
    }

    function setPhase1Done(detail) {
        ph1Icon.innerHTML     = '<i class="mdi mdi-check-circle" style="color:#28a745;"></i>';
        ph1Detail.textContent = detail || '✓ complete';
    }

    function setPhase2Running() {
        ph2Row.style.opacity  = '1';
        ph2Icon.innerHTML     = '<span class="spinner-border spinner-border-sm text-info" style="width:.85rem;height:.85rem;"></span>';
        pbar.style.width      = '30%';
    }

    function setPhase2Done() {
        ph2Icon.innerHTML     = '<i class="mdi mdi-check-circle" style="color:#28a745;"></i>';
        ph2Detail.textContent = '✓ complete';
        pbar.style.width      = '100%';
        pbar.classList.remove('progress-bar-animated');
    }

    function showError(msg) {
        clearTimeout(pollTimer);
        errMsg.textContent = msg;
        errBox.classList.remove('d-none');
        pbar.classList.add('bg-danger');
        pbar.classList.remove('bg-info', 'progress-bar-animated');
        closeBtn.classList.remove('d-none');
        statusMsg.textContent = '';
    }

    function poll() {
        if (pollCount++ > MAX_POLLS) {
            showError('Timeout — analysis is taking too long. You can close this window; the process continues in the background.');
            closeBtn.classList.remove('d-none');
            return;
        }

        var idx = Math.min(Math.floor(pollCount / 6), MSGS_PHASE2.length - 1);
        statusMsg.textContent = MSGS_PHASE2[idx];
        // Advance bar between 30-90%
        var pct = Math.min(90, 30 + Math.floor(pollCount * 60 / MAX_POLLS));
        pbar.style.width = pct + '%';

        fetch(statusUrl, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': btn.dataset.csrf },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.status || data.status === 'pending' || data.status === 'not_started') {
                pollTimer = setTimeout(poll, 5000);
            } else if (data.status === 'ready') {
                setPhase2Done();
                statusMsg.textContent = 'Verification complete.';
                reloadBtn.classList.remove('d-none');
            } else {
                // error
                setPhase2Done();
                ph2Icon.innerHTML = '<i class="mdi mdi-alert-circle" style="color:#dc3545;"></i>';
                showError(data.error || 'Deep analysis failed.');
            }
        })
        .catch(function () {
            pollTimer = setTimeout(poll, 5000); // retry on network error
        });
    }

    btn.addEventListener('click', function () {
        openOverlay();
        btn.disabled = true;

        fetch(btn.dataset.verifyUrl, {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': btn.dataset.csrf,
                'Accept':       'application/json',
            },
            body: JSON.stringify({}),
        })
        .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || 'HTTP ' + r.status); return d; }); })
        .then(function (data) {
            setPhase1Done();

            if (data.phase2_queued && data.status_url) {
                statusUrl = data.status_url;
                statusMsg.textContent = 'Phase 2: queued — downloading from Google Drive…';
                setPhase2Running();
                pollCount = 0;
                pollTimer = setTimeout(poll, 3000);
            } else {
                // No Drive source — done after Phase 1
                setPhase2Done();
                ph2Icon.innerHTML     = '<i class="mdi mdi-minus-circle" style="color:#888;"></i>';
                ph2Detail.textContent = 'No Drive source';
                statusMsg.textContent = data.message || 'Verification complete (metadata only).';
                reloadBtn.classList.remove('d-none');
            }
        })
        .catch(function (err) {
            showError(err.message);
        })
        .finally(function () {
            btn.disabled = false;
        });
    });

    if (closeBtn) closeBtn.addEventListener('click', closeOverlay);

})();
</script>
@else
{{-- Fallback for samples with no Drive source (plain form submit) --}}
<script>
(function () {
    var btn = document.getElementById('verify-slide-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = btn.dataset.verifyUrl;
        var csrfInput = document.createElement('input');
        csrfInput.type = 'hidden'; csrfInput.name = '_token'; csrfInput.value = btn.dataset.csrf;
        form.appendChild(csrfInput);
        document.body.appendChild(form);
        form.submit();
    });
})();
</script>
@endif
<div class="row">
    <div class="col-12">
        <p class="text-muted small text-right mb-0">
            Created: {{ $sample->created_at?->format('Y-m-d H:i') }} &nbsp;|&nbsp;
            Updated: {{ $sample->updated_at?->format('Y-m-d H:i') }}
        </p>
    </div>
</div>

@if($sample->file_id || $sample->wsi_remote_path || $sample->storage_path)
{{-- ══════════════════════════════════════════════════════════════════════
     WSI Fullscreen Viewer Overlay
     Fixed overlay covering the entire viewport — opened programmatically
     by JS when the thumbnail is ready.  Pure CSS/JS, no Bootstrap modal.
     ══════════════════════════════════════════════════════════════════════ --}}
<div id="wsi-fullscreen-modal"
     style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh;
            background:#0d0d0d; z-index:9999; flex-direction:column;
            font-family:inherit;">

    {{-- ── Top bar ── --}}
    <div id="wsi-modal-topbar"
         style="flex-shrink:0; background:#151515; border-bottom:1px solid #2a2a2a;
                padding:.55rem 1.5rem; margin:.5rem .75rem 0 .75rem; border-radius:6px;
                display:flex; align-items:center; gap:.6rem; min-height:48px;">
        <i class="mdi mdi-microscope" style="color:#4a90e2; font-size:1.25rem;"></i>
        <span style="color:#e0e0e0; font-weight:600; font-size:.9rem; white-space:nowrap;">
            WSI Viewer
        </span>
        <span id="modal-sample-label"
              style="color:#777; font-size:.78rem; white-space:nowrap; overflow:hidden;
                     text-overflow:ellipsis; max-width:260px;">
            {{ $sample->file_name ?? ('Sample #' . $sample->id) }}
        </span>
        {{-- Check badges (filled by JS) --}}
        <div id="modal-checks-banner"
             style="display:flex; flex-wrap:wrap; gap:.3rem; margin-left:.5rem;"></div>
        {{-- Spacer --}}
        <div style="flex:1; min-width:.5rem;"></div>
        {{-- Zoom controls --}}
        <button id="modal-zoom-out" type="button" title="Zoom out (−)"
                style="background:none; border:1px solid #3a3a3a; color:#bbb; border-radius:4px;
                       padding:1px 10px; cursor:pointer; font-size:1.1rem; line-height:1.5;">−</button>
        <span id="modal-zoom-label"
              style="color:#999; font-size:.78rem; min-width:44px; text-align:center;">100%</span>
        <button id="modal-zoom-in" type="button" title="Zoom in (+)"
                style="background:none; border:1px solid #3a3a3a; color:#bbb; border-radius:4px;
                       padding:1px 10px; cursor:pointer; font-size:1.1rem; line-height:1.5;">+</button>
        <button id="modal-zoom-fit" type="button" title="Fit to screen"
                style="background:none; border:1px solid #3a3a3a; color:#bbb; border-radius:4px;
                       padding:2px 10px; cursor:pointer; font-size:.75rem;">
            <i class="mdi mdi-fit-to-page-outline"></i> Fit
        </button>
        {{-- Close X --}}
        <button id="modal-close-x" type="button" title="Close viewer"
                style="background:none; border:1px solid #444; color:#ccc; border-radius:4px;
                       padding:2px 12px; cursor:pointer; font-size:1.15rem; line-height:1.3;
                       margin-left:.75rem;">×</button>
    </div>

    {{-- ── Body: left editable panel + image canvas ── --}}
    <div style="flex:1; display:flex; min-height:0;">

        {{-- ── Left side panel: editable verification checks ── --}}
        @if($verification)
        <aside id="modal-side-panel"
               class="vv-card-modal"
               data-update-url="{{ $verifUrl }}"
               data-csrf="{{ csrf_token() }}"
               style="flex-shrink:0; width:340px; background:#161616;
                      border-right:1px solid #2a2a2a; overflow-y:auto;
                      padding:.85rem 1rem; color:#cfcfcf;">

            <div class="d-flex align-items-center mb-2" style="gap:.45rem;">
                <i class="mdi mdi-shield-check-outline" style="color:#4a90e2; font-size:1.1rem;"></i>
                <h6 class="mb-0" style="color:#e0e0e0; font-size:.85rem; font-weight:600;">
                    Verification Checks
                </h6>
                <span id="modal-vv-overall-badge"
                      class="badge ml-auto px-2 py-1
                            {{ $verification->verification_status === 'passed' ? 'badge-success'
                              : ($verification->verification_status === 'failed' ? 'badge-danger' : 'badge-warning') }}"
                      style="font-size:.66rem;">
                    {{ ucfirst($verification->verification_status ?? 'pending') }}
                </span>
            </div>
            <p class="mb-3" style="font-size:.7rem; color:#777; line-height:1.4;">
                Click any value to edit. Saved changes recompute the overall status.
            </p>

            @php $modalGrouped = collect($checks)->groupBy('group'); @endphp

            @foreach(\App\Models\SlideVerification::GROUP_LABELS as $groupCode => $groupLabel)
                @continue($groupCode === 'identity')
                @if($modalGrouped->has($groupCode))
                    <h6 class="text-uppercase mt-3 mb-1"
                        style="font-size:.62rem; letter-spacing:.08em; color:#7a7a7a;">
                        {{ $groupLabel }}
                    </h6>
                    <div class="modal-vv-group" style="border-top:1px solid #232323;">
                        @foreach($modalGrouped[$groupCode] as $row)
                            @php
                                [$icon, $stateLabel, $stateColor] = match ($row['state']) {
                                    'passed'      => ['mdi-check-circle',  'OK',      '#28a745'],
                                    'failed'      => ['mdi-close-circle',  'Failed',  '#dc3545'],
                                    'not_checked' => ['mdi-clock-outline', 'Pending', '#888'],
                                    default       => ['mdi-help-circle',   '—',       '#888'],
                                };
                                $isVirtual = ($row['code'] === 'slide_dimensions');
                                $colName   = $isVirtual ? null : $row['code'];
                                $rawValue  = !$isVirtual ? ($verification->{$colName} ?? null) : null;
                                $colMeta   = !$isVirtual ? ($fieldMeta[$colName] ?? ['type' => 'text']) : null;
                            @endphp
                            <div class="d-flex align-items-start py-2"
                                 style="border-bottom:1px solid #1f1f1f; gap:.45rem;">
                                <i class="mdi {{ $icon }}"
                                   style="color:{{ $stateColor }}; font-size:.95rem; line-height:1.2; flex-shrink:0; margin-top:1px;"></i>
                                <div style="flex:1; min-width:0;">
                                    <div style="font-size:.74rem; color:#cfcfcf; line-height:1.25;">
                                        {{ $row['label'] }}
                                    </div>
                                    <div style="font-size:.62rem; color:#666; margin-bottom:3px;">
                                        ({{ $row['code'] }})
                                    </div>
                                    @if($isVirtual)
                                        <div class="d-flex align-items-center" style="gap:4px; flex-wrap:nowrap;">
                                            @include('admin.partials.vv-field', [
                                                'colName'     => 'slide_width',
                                                'rawValue'    => $verification->slide_width,
                                                'meta'        => $fieldMeta['slide_width'],
                                                'placeholder' => 'W',
                                            ])
                                            <span style="color:#666; font-size:.7rem;">×</span>
                                            @include('admin.partials.vv-field', [
                                                'colName'     => 'slide_height',
                                                'rawValue'    => $verification->slide_height,
                                                'meta'        => $fieldMeta['slide_height'],
                                                'placeholder' => 'H',
                                            ])
                                        </div>
                                    @else
                                        @include('admin.partials.vv-field', [
                                            'colName'     => $colName,
                                            'rawValue'    => $rawValue,
                                            'meta'        => $colMeta,
                                            'placeholder' => '',
                                        ])
                                    @endif
                                </div>
                                <span class="badge" style="background:{{ $stateColor }}22; color:{{ $stateColor }};
                                           border:1px solid {{ $stateColor }}55; font-size:.6rem; flex-shrink:0;">
                                    {{ $stateLabel }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endforeach

            {{-- Notes --}}
            <h6 class="text-uppercase mt-3 mb-1"
                style="font-size:.62rem; letter-spacing:.08em; color:#7a7a7a;">
                Notes
            </h6>
            <div class="vv-field" data-field="notes" data-current="{{ $verification->notes ?? '' }}">
                <div class="vv-display"
                     style="cursor:pointer; min-height:30px; padding:5px 8px;
                            border:1px dashed #333; border-radius:4px;
                            font-size:.72rem; color:#aaa; background:#1a1a1a;"
                     title="Click to edit notes">
                    {{ $verification->notes ?: '— click to add notes —' }}
                    <i class="mdi mdi-pencil-outline ml-1" style="font-size:.7rem; opacity:.5;"></i>
                </div>
                <div class="vv-input-group d-none">
                    <textarea class="form-control form-control-sm vv-input" rows="3"
                              style="background:#1a1a1a; color:#ddd; border-color:#333;">{{ $verification->notes }}</textarea>
                    <div class="mt-1 d-flex" style="gap:4px;">
                        <button type="button" class="btn btn-success btn-sm py-0 px-2 vv-save"
                                style="font-size:.72rem;">Save</button>
                        <button type="button" class="btn btn-secondary btn-sm py-0 px-2 vv-cancel"
                                style="font-size:.72rem;">Cancel</button>
                    </div>
                </div>
            </div>
        </aside>
        @endif

        {{-- ── Image canvas ── --}}
        <div id="modal-thumb-container"
             style="flex:1; overflow:hidden; position:relative; background:#111;
                    cursor:grab; min-height:0; user-select:none;">
            {{-- Loading spinner (hidden after img loads) --}}
            <div id="modal-img-spinner"
                 style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
                        text-align:center; color:#555; pointer-events:none; z-index:1;">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Loading…</span>
                </div>
                <p style="margin-top:.5rem; font-size:.8rem; color:#666;">Loading thumbnail…</p>
            </div>
            <img id="modal-thumb-img" src="" alt="WSI"
                 style="position:absolute; top:50%; left:50%;
                        transform:translate(-50%,-50%) scale(1);
                        transform-origin:center center; max-width:none; max-height:none;
                        will-change:transform; display:none;"
                 draggable="false">
        </div>
    </div>

    {{-- ── Bottom action bar ── --}}
    <div id="wsi-modal-bottombar"
         style="flex-shrink:0; background:#151515; border-top:1px solid #2a2a2a;
                padding:.55rem 1.5rem; margin:0 .75rem .5rem .75rem; border-radius:6px;
                display:flex; align-items:center;
                flex-wrap:wrap; gap:.5rem; min-height:52px;">
        {{-- Meta chips (filled by JS) --}}
        <div id="modal-meta-chips"
             style="display:flex; flex-wrap:wrap; gap:.3rem; flex:1; min-width:0;"></div>
        {{-- Action buttons --}}
        <div style="display:flex; align-items:center; gap:.5rem; flex-shrink:0;">
            <span style="color:#666; font-size:.72rem;">Scroll: zoom · Drag: pan</span>
            <button id="modal-approve-btn" type="button" class="btn btn-success btn-sm">
                <i class="mdi mdi-check-circle-outline mr-1"></i>
                Mark Reviewed &amp; Close
            </button>
            <button id="modal-nodecision-btn" type="button" class="btn btn-sm"
                    style="border:1px solid #444; color:#aaa; background:none;">
                <i class="mdi mdi-close mr-1"></i>Close
            </button>
        </div>
    </div>
</div>
@endif

@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    // Bind the inline-edit handlers to every vv-card root (the main page card
    // AND the modal side panel). Both use the same vv-field partial.
    var roots = [].concat(
        Array.prototype.slice.call(document.querySelectorAll('#vv-card')),
        Array.prototype.slice.call(document.querySelectorAll('.vv-card-modal'))
    );
    if (roots.length === 0) return;

    roots.forEach(function (card) {
        bindVvHandlers(card);
    });

    function bindVvHandlers(card) {
        var updateUrl = card.dataset.updateUrl;
        var csrf      = card.dataset.csrf;
        if (!updateUrl) return;

        /* ── open inline editor on display-chip click ── */
        card.addEventListener('click', function (e) {
            var disp = e.target.closest('.vv-display');
            if (!disp || !card.contains(disp)) return;
            var field = disp.closest('.vv-field');
            if (!field) return;
            disp.classList.add('d-none');
            var ig = field.querySelector('.vv-input-group');
            if (ig) {
                ig.classList.remove('d-none');
                var inp = ig.querySelector('.vv-input');
                if (inp) { inp.focus(); if (typeof inp.select === 'function') inp.select(); }
            }
        });

        /* ── cancel ── */
        card.addEventListener('click', function (e) {
            var btn = e.target.closest('.vv-cancel');
            if (!btn || !card.contains(btn)) return;
            var field = btn.closest('.vv-field');
            if (!field) return;
            field.querySelector('.vv-input-group').classList.add('d-none');
            field.querySelector('.vv-display').classList.remove('d-none');
        });

        /* ── save via AJAX PATCH ── */
        card.addEventListener('click', function (e) {
            var btn = e.target.closest('.vv-save');
            if (!btn || !card.contains(btn)) return;

            var field     = btn.closest('.vv-field');
            var fieldName = field.dataset.field;
            var inp       = field.querySelector('.vv-input');
            var value     = inp ? inp.value : '';

            btn.disabled    = true;
            btn.textContent = '…';

            fetch(updateUrl, {
                method:  'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept':       'application/json',
                },
                body: JSON.stringify({ field: fieldName, value: value }),
            })
            .then(function (r) {
                if (!r.ok) return r.json().then(function (d) { throw new Error(d.error || 'HTTP ' + r.status); });
                return r.json();
            })
            .then(function (data) {
                if (!data.success) throw new Error(data.error || 'Unknown error');

                /* update the display chip */
                var disp   = field.querySelector('.vv-display');
                var trunc  = value.length > 34 ? '…' + value.slice(-33) : value;
                var pencil = '<i class="mdi mdi-pencil-outline" style="font-size:.62rem;opacity:.4;"></i>';

                if (disp.tagName === 'SPAN') {
                    disp.innerHTML = (trunc || '—') + '&nbsp;' + pencil;
                    disp.title     = value;
                } else {
                    /* notes textarea display */
                    disp.innerHTML = (value || '— click to add notes —') + ' ' + pencil;
                }
                field.dataset.current = value;

                field.querySelector('.vv-input-group').classList.add('d-none');
                disp.classList.remove('d-none');

                /* refresh BOTH overall status badges (page card + modal panel) */
                if (data.verification_status) {
                    var map = { passed: 'badge-success', failed: 'badge-danger', pending: 'badge-warning' };
                    ['vv-overall-badge', 'modal-vv-overall-badge'].forEach(function (id) {
                        var b = document.getElementById(id);
                        if (!b) return;
                        var base = (id === 'modal-vv-overall-badge')
                            ? 'badge ml-auto px-2 py-1 '
                            : 'badge ml-2 px-2 py-1 ';
                        b.className   = base + (map[data.verification_status] || 'badge-secondary');
                        b.textContent = data.verification_status.charAt(0).toUpperCase() + data.verification_status.slice(1);
                    });
                }
            })
            .catch(function (err) {
                alert('Save failed: ' + err.message);
            })
            .finally(function () {
                btn.disabled    = false;
                btn.textContent = 'Save';
            });
        });
    }
})();
</script>

{{-- ══════════════════════════════════════════════════════════════════════
     WSI On-Demand Preview — download → OpenSlide → fullscreen viewer
     ══════════════════════════════════════════════════════════════════════ --}}
@if($sample->file_id || $sample->wsi_remote_path || $sample->storage_path)
<script>
(function () {
    'use strict';

    /* ── URL constants ── */
    var CSRF        = '{{ csrf_token() }}';
    var URL_START   = '{{ route("admin.samples.wsi-preview.start",    $sample) }}';
    var URL_STATUS  = '{{ route("admin.samples.wsi-preview.status",   $sample) }}';
    var URL_THUMB   = '{{ route("admin.samples.wsi-preview.thumbnail",$sample) }}';
    var URL_CLEANUP = '{{ route("admin.samples.wsi-preview.cleanup",  $sample) }}';

    /* ── Card DOM refs ── */
    var elIdle        = document.getElementById('wsi-preview-idle');
    var elLoading     = document.getElementById('wsi-preview-loading');
    var elError       = document.getElementById('wsi-preview-error');
    var elReady       = document.getElementById('wsi-preview-ready');
    var elLoadBtn     = document.getElementById('wsi-load-btn');
    var elRetryBtn    = document.getElementById('wsi-retry-btn');
    var elCloseBtn    = document.getElementById('wsi-close-btn');      // delete temp
    var elOpenModal   = document.getElementById('wsi-open-modal-btn'); // open viewer
    var elMsg         = document.getElementById('wsi-loading-msg');
    var elErrMsg      = document.getElementById('wsi-error-msg');
    var elBanner      = document.getElementById('wsi-checks-banner');
    var elMeta        = document.getElementById('wsi-meta-chips');
    var elDupAlert    = document.getElementById('wsi-duplicate-alert');
    var elDupMsg      = document.getElementById('wsi-duplicate-msg');

    /* ── Modal DOM refs ── */
    var elModal         = document.getElementById('wsi-fullscreen-modal');
    var elModalBanner   = document.getElementById('modal-checks-banner');
    var elModalMeta     = document.getElementById('modal-meta-chips');
    var elModalThumb    = document.getElementById('modal-thumb-img');
    var elModalSpinner  = document.getElementById('modal-img-spinner');
    var elModalContainer= document.getElementById('modal-thumb-container');
    var elModalZoomLbl  = document.getElementById('modal-zoom-label');
    var elModalZoomIn   = document.getElementById('modal-zoom-in');
    var elModalZoomOut  = document.getElementById('modal-zoom-out');
    var elModalZoomFit  = document.getElementById('modal-zoom-fit');
    var elModalCloseX   = document.getElementById('modal-close-x');
    var elModalApprove  = document.getElementById('modal-approve-btn');
    var elModalClose    = document.getElementById('modal-nodecision-btn');

    if (!elIdle) return; // page has no preview section

    /* ── State ── */
    var pollTimer  = null;
    var pollCount  = 0;
    var MAX_POLLS  = 480; // 480 × 5 s = 40 min max wait
    var lastData   = null; // cached result for "Re-open"

    /* ── Zoom / pan state ── */
    var mScale = 1, mOriginX = 0, mOriginY = 0;

    /* ─────────────────────────────────────────────────────────────────── */
    /* Helpers                                                               */
    /* ─────────────────────────────────────────────────────────────────── */
    function showOnly(el) {
        [elIdle, elLoading, elError, elReady].forEach(function (e) {
            if (e) e.classList.add('d-none');
        });
        if (el) el.classList.remove('d-none');
    }

    function post(url, body) {
        return fetch(url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body:    JSON.stringify(body || {}),
        }).then(function (r) {
            return r.json().then(function (d) {
                if (!r.ok) throw new Error(d.error || ('HTTP ' + r.status));
                return d;
            });
        });
    }

    function get(url) {
        return fetch(url, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        }).then(function (r) { return r.json(); });
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /* Start                                                                 */
    /* ─────────────────────────────────────────────────────────────────── */
    function startPreview() {
        showOnly(elLoading);
        if (elMsg) elMsg.innerHTML =
            'Fetching slide from Google Drive…<br>'
            + '<span class="text-danger small">Large files may take several minutes.</span>';

        post(URL_START)
            .then(function (data) {
                if (!data.success) throw new Error(data.error || 'Failed to start preview.');
                if (data.status === 'ready' && data.from_cache) {
                    pollOnce(); // already done — just fetch status once
                } else {
                    pollCount = 0;
                    schedulePoll();
                }
            })
            .catch(function (err) { showError(err.message); });
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /* Polling                                                               */
    /* ─────────────────────────────────────────────────────────────────── */
    function schedulePoll() { pollTimer = setTimeout(pollOnce, 5000); }

    function pollOnce() {
        if (pollCount++ > MAX_POLLS) {
            showError('Timeout: the inspection is taking too long. Please try again.');
            return;
        }
        var msgs = [
            'Downloading from Google Drive…',
            'Transferring slide data…',
            'Computing MD5 checksum…',
            'Opening slide with OpenSlide…',
            'Testing reads from multiple regions…',
            'Generating preview thumbnail…',
            'Almost done…',
        ];
        var idx = Math.min(Math.floor(pollCount / 5), msgs.length - 1);
        if (elMsg) elMsg.innerHTML =
            msgs[idx]
            + '<br><span class="text-danger small">Large files may take several minutes.</span>';

        get(URL_STATUS)
            .then(function (data) {
                if (!data.status || data.status === 'pending' || data.status === 'not_started') {
                    schedulePoll();
                } else if (data.status === 'ready') {
                    clearTimeout(pollTimer);
                    showReady(data);
                } else {
                    clearTimeout(pollTimer);
                    showError(data.error || 'Inspection failed.');
                }
            })
            .catch(function () { schedulePoll(); });
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /* Show result (card summary)                                            */
    /* ─────────────────────────────────────────────────────────────────── */
    function showReady(data) {
        lastData = data;

        // ── Check badges (card + modal) ──
        var checksHtml = buildCheckBadgesHtml(data.checks || {});
        if (elBanner)       elBanner.innerHTML      = checksHtml;
        if (elModalBanner)  elModalBanner.innerHTML = checksHtml;

        // ── Duplicate warning ──
        if (data.duplicate_of) {
            if (elDupMsg)   elDupMsg.textContent = data.duplicate_of;
            if (elDupAlert) elDupAlert.classList.remove('d-none');
        }

        // ── Meta chips (card + modal) ──
        var metaHtml = buildMetaHtml(data.wsi_meta || {});
        if (elMeta)       elMeta.innerHTML      = metaHtml;
        if (elModalMeta)  elModalMeta.innerHTML = metaHtml;

        showOnly(elReady);

        // ── Auto-open the fullscreen viewer immediately ──
        openModal(data);
    }

    function buildCheckBadgesHtml(checks) {
        var defs = [
            { key: 'open_slide_status',     label: 'Can open file' },
            { key: 'file_integrity_status', label: 'File integrity' },
            { key: 'read_test_status',      label: 'Read test' },
        ];
        return defs.map(function (d) {
            var val  = checks[d.key] || 'not_checked';
            var cls  = val === 'passed' ? 'badge-success' : val === 'failed' ? 'badge-danger' : 'badge-secondary';
            var icon = val === 'passed' ? 'mdi-check-circle' : val === 'failed' ? 'mdi-close-circle' : 'mdi-clock-outline';
            return '<span class="badge ' + cls + ' px-2 py-1" style="font-size:.72rem;">'
                 + '<i class="mdi ' + icon + ' mr-1"></i>' + d.label + '</span>';
        }).join('');
    }

    function buildMetaHtml(m) {
        return [
            { label: 'Levels',  val: m.level_count },
            { label: 'Width',   val: m.slide_width  ? m.slide_width  + ' px' : null },
            { label: 'Height',  val: m.slide_height ? m.slide_height + ' px' : null },
            { label: 'MPP-X',   val: m.mpp_x        ? m.mpp_x + ' µm'        : null },
            { label: 'MPP-Y',   val: m.mpp_y        ? m.mpp_y + ' µm'        : null },
            { label: 'Magnif.', val: m.magnification_power ? m.magnification_power + '×' : null },
            { label: 'Tissue',  val: m.tissue_area_percent != null ? m.tissue_area_percent + '%' : null },
        ].filter(function (c) { return c.val != null; })
         .map(function (c) {
             return '<span class="badge badge-light border" style="font-size:.72rem; font-weight:normal;">'
                  + '<strong>' + c.label + ':</strong> ' + c.val + '</span>';
         }).join('');
    }

    function showError(msg) {
        if (elErrMsg) elErrMsg.textContent = msg;
        showOnly(elError);
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /* Fullscreen Modal                                                      */
    /* ─────────────────────────────────────────────────────────────────── */
    function openModal(data) {
        if (!elModal) return;

        // Reset transform
        mScale = 1; mOriginX = 0; mOriginY = 0;
        applyModalTransform();
        if (elModalZoomLbl) elModalZoomLbl.textContent = '100%';

        // Show modal
        elModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        // Load thumbnail
        if (elModalThumb && data && data.thumbnail_url) {
            elModalThumb.style.display = 'none';
            if (elModalSpinner) elModalSpinner.style.display = 'block';

            elModalThumb.onerror = function () {
                if (elModalSpinner) elModalSpinner.innerHTML =
                    '<i class="mdi mdi-image-broken-variant" style="font-size:3rem; color:#555;"></i>'
                    + '<p style="margin-top:.5rem; font-size:.8rem; color:#666;">Could not load thumbnail.</p>';
            };
            elModalThumb.onload = function () {
                if (elModalSpinner) elModalSpinner.style.display = 'none';
                elModalThumb.style.display = 'block';
                fitToScreen();
            };
            elModalThumb.src = data.thumbnail_url + '?t=' + Date.now();
        }

        // Keyboard shortcut hint (ESC to close)
        document.addEventListener('keydown', onModalKeyDown);
    }

    function closeModal() {
        if (!elModal) return;
        elModal.style.display = 'none';
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onModalKeyDown);
    }

    function onModalKeyDown(e) {
        if (e.key === 'Escape') confirmCloseAndDelete();
        if (e.key === '+' || e.key === '=') { mScale = Math.min(mScale * 1.2, 30); applyModalTransform(); }
        if (e.key === '-')                   { mScale = Math.max(mScale * 0.83, 0.05); applyModalTransform(); }
        if (e.key === '0')                   { fitToScreen(); }
    }

    /* Confirm + cleanup (one-shot viewing). */
    function confirmCloseAndDelete() {
        if (confirm(
            'Close preview and delete the temporary file?\n\n'
            + 'Note:\n'
            + '\u2022 The slide will be permanently deleted from the server\n'
            + '\u2022 The preview cannot be reopened after closing\n'
            + '\u2022 Verification results and thumbnail are saved in the database\n\n'
            + 'Click OK to confirm.'
        )) {
            doCleanup(true);
        }
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /* Pan / Zoom (modal)                                                    */
    /* ─────────────────────────────────────────────────────────────────── */
    function applyModalTransform() {
        if (!elModalThumb) return;
        elModalThumb.style.transform =
            'translate(calc(-50% + ' + mOriginX + 'px), calc(-50% + ' + mOriginY + 'px)) scale(' + mScale + ')';
        if (elModalZoomLbl) elModalZoomLbl.textContent = Math.round(mScale * 100) + '%';
    }

    function fitToScreen() {
        if (!elModalThumb || !elModalContainer) return;
        var cw = elModalContainer.clientWidth;
        var ch = elModalContainer.clientHeight;
        var iw = elModalThumb.naturalWidth  || elModalThumb.width  || cw;
        var ih = elModalThumb.naturalHeight || elModalThumb.height || ch;
        mScale   = Math.min(cw / iw, ch / ih, 1) * 0.97; // slight padding
        mOriginX = 0;
        mOriginY = 0;
        applyModalTransform();
    }

    if (elModalContainer) {
        // Mouse wheel zoom (zooms toward cursor position)
        elModalContainer.addEventListener('wheel', function (e) {
            e.preventDefault();
            var rect   = elModalContainer.getBoundingClientRect();
            var cx     = e.clientX - rect.left - rect.width  / 2;
            var cy     = e.clientY - rect.top  - rect.height / 2;
            var factor = e.deltaY < 0 ? 1.12 : 0.89;
            var newScale = Math.min(Math.max(mScale * factor, 0.05), 30);
            mOriginX = cx + (mOriginX - cx) * (newScale / mScale);
            mOriginY = cy + (mOriginY - cy) * (newScale / mScale);
            mScale   = newScale;
            applyModalTransform();
        }, { passive: false });

        // Mouse drag
        var dragging = false, dragStartX = 0, dragStartY = 0;
        elModalContainer.addEventListener('mousedown', function (e) {
            dragging   = true;
            dragStartX = e.clientX - mOriginX;
            dragStartY = e.clientY - mOriginY;
            elModalContainer.style.cursor = 'grabbing';
        });
        window.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            mOriginX = e.clientX - dragStartX;
            mOriginY = e.clientY - dragStartY;
            applyModalTransform();
        });
        window.addEventListener('mouseup', function () {
            dragging = false;
            if (elModalContainer) elModalContainer.style.cursor = 'grab';
        });

        // Touch pinch-zoom + drag
        var lastPinchDist = 0;
        elModalContainer.addEventListener('touchstart', function (e) {
            if (e.touches.length === 2) {
                lastPinchDist = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY);
            } else if (e.touches.length === 1) {
                dragging   = true;
                dragStartX = e.touches[0].clientX - mOriginX;
                dragStartY = e.touches[0].clientY - mOriginY;
            }
        }, { passive: true });
        elModalContainer.addEventListener('touchmove', function (e) {
            if (e.touches.length === 2) {
                e.preventDefault();
                var dist   = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY);
                var ratio  = dist / (lastPinchDist || dist);
                mScale = Math.min(Math.max(mScale * ratio, 0.05), 30);
                lastPinchDist = dist;
                applyModalTransform();
            } else if (e.touches.length === 1 && dragging) {
                mOriginX = e.touches[0].clientX - dragStartX;
                mOriginY = e.touches[0].clientY - dragStartY;
                applyModalTransform();
            }
        }, { passive: false });
        elModalContainer.addEventListener('touchend', function () { dragging = false; });
    }

    // Zoom buttons
    if (elModalZoomIn)  elModalZoomIn.addEventListener('click',  function () { mScale = Math.min(mScale * 1.25, 30); applyModalTransform(); });
    if (elModalZoomOut) elModalZoomOut.addEventListener('click', function () { mScale = Math.max(mScale * 0.8, 0.05); applyModalTransform(); });
    if (elModalZoomFit) elModalZoomFit.addEventListener('click', fitToScreen);

    /* ─────────────────────────────────────────────────────────────────── */
    /* Cleanup (delete temp file)                                            */
    /* ─────────────────────────────────────────────────────────────────── */
    function doCleanup(fromModal) {
        if (fromModal) closeModal();
        clearTimeout(pollTimer);
        showOnly(elLoading);
        if (elMsg) elMsg.innerHTML = 'Deleting temporary files from server…';

        post(URL_CLEANUP)
            .then(function () { window.location.reload(); })
            .catch(function (err) {
                alert('Cleanup error: ' + err.message);
                showOnly(elReady);
            });
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /* Event listeners                                                       */
    /* ─────────────────────────────────────────────────────────────────── */
    if (elLoadBtn)    elLoadBtn.addEventListener('click', startPreview);
    if (elRetryBtn)   elRetryBtn.addEventListener('click', startPreview);

    // Re-open fullscreen viewer is DISABLED — single-shot viewing.
    // After the modal closes the temp file is deleted, so reopen is not allowed.
    if (elOpenModal) {
        elOpenModal.style.display = 'none';
    }

    // Card: delete temp file button (without opening modal)
    if (elCloseBtn) {
        elCloseBtn.addEventListener('click', function () {
            if (confirm(
                'Delete the temporary file from the server?\n\n'
                + '\u2022 Verification results are saved\n'
                + '\u2022 Preview cannot be reopened after deletion\n'
                + '\u2022 The file can be re-downloaded later from Google Drive\n\n'
                + 'Click OK to confirm.'
            )) { doCleanup(false); }
        });
    }

    // Modal: close (X / Close button) — always confirms then deletes the temp file.
    if (elModalCloseX) elModalCloseX.addEventListener('click', confirmCloseAndDelete);
    if (elModalClose)  elModalClose .addEventListener('click', confirmCloseAndDelete);

    // Modal: approve & close (delete temp file)
    if (elModalApprove) {
        elModalApprove.addEventListener('click', function () {
            if (confirm(
                'Confirm: slide reviewed visually?\n\n'
                + '\u2022 The temporary file will be permanently deleted from the server\n'
                + '\u2022 Verification results are already saved\n'
                + '\u2022 Preview cannot be reopened after deletion\n\n'
                + 'Click OK to confirm.'
            )) { doCleanup(true); }
        });
    }

})();
</script>
@endif
@endpush
