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
                </h5>

                @if($sample->file_id && $sample->storage_status === 'available')
                    {{-- Google Drive preview --}}
                    <iframe
                        src="https://drive.google.com/file/d/{{ $sample->file_id }}/preview"
                        width="100%"
                        height="500"
                        allow="autoplay"
                        style="border:none; border-radius:4px;">
                    </iframe>
                    <p class="mt-3 small text-muted text-center">
                        <i class="mdi mdi-information-outline"></i>
                        Powered by Google Drive
                    </p>
                @else
                    <div class="text-center py-5 text-muted">
                        <i class="mdi mdi-file-question-outline" style="font-size:3rem;"></i>
                        <p class="mt-3 mb-0">Preview not available</p>
                        <small>File must be available on Drive to preview</small>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ── Timestamps row ─────────────────────────────────────────────────── --}}
<div class="row">
    <div class="col-12">
        <p class="text-muted small text-right mb-0">
            Created: {{ $sample->created_at?->format('Y-m-d H:i') }} &nbsp;|&nbsp;
            Updated: {{ $sample->updated_at?->format('Y-m-d H:i') }}
        </p>
    </div>
</div>
@endsection
