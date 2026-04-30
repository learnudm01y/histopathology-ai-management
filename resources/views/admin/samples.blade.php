@extends('admin.layouts.app')

@section('title', 'Samples')

@section('content')
<div class="page-header">
    <h3 class="page-title">Pathology Samples</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">Samples</li>
        </ol>
    </nav>
</div>

{{-- ── Stats Row ─────────────────────────────────────────────────────────── --}}
<div class="row">
    <div class="col-xl-3 col-sm-6 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-row align-items-start justify-content-between">
                    <div class="text-md-left">
                        <p class="font-weight-medium mb-1 text-muted">Total Samples</p>
                        <h3 class="font-weight-bold mb-0">{{ number_format($stats['total']) }}</h3>
                    </div>
                    <div class="ml-2">
                        <i class="mdi mdi-flask-outline icon-lg text-primary"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-row align-items-start justify-content-between">
                    <div class="text-md-left">
                        <p class="font-weight-medium mb-1 text-muted">Available on Drive</p>
                        <h3 class="font-weight-bold mb-0 text-success">{{ number_format($stats['available']) }}</h3>
                    </div>
                    <div class="ml-2">
                        <i class="mdi mdi-check-circle-outline icon-lg text-success"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-row align-items-start justify-content-between">
                    <div class="text-md-left">
                        <p class="font-weight-medium mb-1 text-muted">Not on Drive</p>
                        <h3 class="font-weight-bold mb-0 text-warning">{{ number_format($stats['not_downloaded']) }}</h3>
                    </div>
                    <div class="ml-2">
                        <i class="mdi mdi-download-outline icon-lg text-warning"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-row align-items-start justify-content-between">
                    <div class="text-md-left">
                        <p class="font-weight-medium mb-1 text-muted">Tiling Done</p>
                        <h3 class="font-weight-bold mb-0 text-info">{{ number_format($stats['tiling_done']) }}</h3>
                    </div>
                    <div class="ml-2">
                        <i class="mdi mdi-grid icon-lg text-info"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ── Flash Messages ────────────────────────────────────────────────────── --}}
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="mdi mdi-check-circle-outline mr-1"></i> {{ session('success') }}
    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
</div>
@endif

{{-- ── Filters ───────────────────────────────────────────────────────────── --}}
<div class="row grid-margin">
    <div class="col-12">
        <div class="card">
            <div class="card-body py-3">
                <form method="GET" action="{{ route('admin.samples') }}" class="form-inline flex-wrap" style="gap:.5rem;">
                    {{-- Search --}}
                    <div class="input-group" style="min-width:260px; max-width:320px;">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white"
                                  style="border-right:0; border-radius:6px 0 0 6px;">
                                <i class="mdi mdi-magnify text-muted"></i>
                            </span>
                        </div>
                        <input type="text" name="search"
                               class="form-control border-left-0"
                               style="border-radius:0 6px 6px 0;"
                               placeholder="File name, ID, Slide ID…"
                               value="{{ request('search') }}">
                    </div>

                    {{-- Organ --}}
                    <select name="organ_id" class="form-control form-control-sm">
                        <option value="">All Organs</option>
                        @foreach($organs as $organ)
                            <option value="{{ $organ->id }}" @selected(request('organ_id') == $organ->id)>
                                {{ $organ->name }}
                            </option>
                        @endforeach
                    </select>

                    {{-- Category (from DB) --}}
                    <select name="category_id" class="form-control form-control-sm">
                        <option value="">All Categories</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" @selected(request('category_id') == $cat->id)>
                                {{ $cat->label_en }}
                            </option>
                        @endforeach
                    </select>

                    {{-- Storage Status --}}
                    <select name="storage_status" class="form-control form-control-sm">
                        <option value="">All Storage Status</option>
                        <option value="not_downloaded" @selected(request('storage_status') === 'not_downloaded')>Not on Drive</option>
                        <option value="downloading"    @selected(request('storage_status') === 'downloading')>Downloading</option>
                        <option value="verifying"      @selected(request('storage_status') === 'verifying')>Verifying</option>
                        <option value="available"      @selected(request('storage_status') === 'available')>Available</option>
                        <option value="corrupted"      @selected(request('storage_status') === 'corrupted')>Corrupted</option>
                        <option value="missing"        @selected(request('storage_status') === 'missing')>Missing</option>
                    </select>

                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    @if(request()->hasAny(['search','organ_id','category_id','storage_status']))
                        <a href="{{ route('admin.samples') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
                    @endif
                </form>
            </div>
        </div>
    </div>
</div>

{{-- ── Main Table ────────────────────────────────────────────────────────── --}}
<div class="row">
    <div class="col-12 grid-margin">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="card-title mb-0">
                        Samples
                        <span class="badge badge-secondary ml-2">{{ $samples->total() }}</span>
                    </h4>
                    <div class="d-flex align-items-center" style="gap:.75rem;">
                        <small class="text-muted">
                            Showing {{ $samples->firstItem() ?? 0 }}–{{ $samples->lastItem() ?? 0 }}
                            of {{ $samples->total() }}
                        </small>
                        {{-- ── Verify All Unverified Samples ── --}}
                        <button type="button" class="btn btn-outline-warning btn-sm"
                                id="bulkVerifyBtn"
                                title="Queue all unverified / pending samples for slide verification (skips failed and already-verified)">
                            <i class="mdi mdi-shield-search mr-1"></i> Verify All Unverified Samples
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addSampleModal">
                            <i class="mdi mdi-plus"></i> Add Sample
                        </button>
                    </div>
                </div>

                @if($samples->isEmpty())
                    <div class="text-center py-5 text-muted">
                        <i class="mdi mdi-flask-empty-outline" style="font-size:3rem;"></i>
                        <p class="mt-2">No pathology samples found.</p>
                    </div>
                @else
                <div class="table-responsive">
                    <table class="table table-hover table-sm" style="table-layout:fixed; width:100%;">
                        <colgroup>
                            <col style="width:40px;">
                            <col style="width:260px;">{{-- File Name --}}
                            <col style="width:82px;">
                            <col style="width:74px;">
                            <col style="width:62px;">
                            <col style="width:100px;">
                            <col style="width:78px;">
                            <col style="width:62px;">
                            <col style="width:62px;">
                            <col style="width:80px;">
                        </colgroup>
                        <thead class="thead-light">
                            <tr>
                                <th style="padding:6px 8px;">#</th>
                                <th style="padding:6px 8px;">File Name</th>
                                <th style="padding:6px 4px;">Organ</th>
                                <th style="padding:6px 4px;">Source</th>
                                <th style="padding:6px 4px;">Category</th>
                                <th style="padding:6px 4px;">Subtype</th>
                                <th style="padding:6px 4px;">Size</th>
                                <th style="padding:6px 4px;">Storage</th>
                                <th style="padding:6px 4px;">Tiling</th>
                                <th style="padding:6px 4px;">Quality</th>
                                <th style="padding:6px 4px; text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($samples as $sample)
                            <tr>
                                <td style="padding:6px 8px;" class="text-muted small align-middle">{{ $sample->id }}</td>

                                {{-- File name --}}
                                <td style="padding:6px 8px; overflow:hidden;" class="align-middle">
                                    <span title="{{ $sample->file_name }}" class="small d-block text-truncate">
                                        {{ $sample->file_name ?? '—' }}
                                    </span>
                                </td>

                                {{-- Organ --}}
                                <td style="padding:6px 4px; overflow:hidden;" class="align-middle">
                                    <span class="badge badge-light border d-block text-truncate" style="max-width:100%;">
                                        {{ $sample->organ->name ?? '—' }}
                                    </span>
                                </td>

                                {{-- Data Source --}}
                                <td style="padding:6px 4px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;" class="small text-muted align-middle">{{ $sample->dataSource->name ?? '—' }}</td>

                                {{-- Category --}}
                                <td style="padding:6px 4px;" class="align-middle">
                                    <span class="badge badge-secondary">
                                        {{ $sample->category->label_en ?? '—' }}
                                    </span>
                                </td>

                                {{-- Disease Subtype --}}
                                <td style="padding:6px 4px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;" class="small align-middle" title="{{ $sample->disease_subtype }}">{{ $sample->disease_subtype ?? '—' }}</td>

                                {{-- File Size --}}
                                <td style="padding:6px 4px; white-space:nowrap;" class="small text-muted align-middle">
                                    {{ $sample->file_size_human }}
                                </td>

                                {{-- Storage Status --}}
                                <td style="padding:6px 4px;" class="align-middle">
                                    <span class="badge badge-{{ $sample->storage_status_badge }}">
                                        {{ str_replace('_', ' ', $sample->storage_status) }}
                                    </span>
                                </td>

                                {{-- Tiling Status --}}
                                <td style="padding:6px 4px;" class="align-middle">
                                    <span class="badge badge-{{ $sample->tiling_status_badge }}">
                                        {{ $sample->tiling_status }}
                                        @if($sample->tiling_status === 'done' && $sample->tile_count)
                                            ({{ number_format($sample->tile_count) }})
                                        @endif
                                    </span>
                                </td>

                                {{-- Quality Status --}}
                                <td style="padding:6px 4px;" class="align-middle">
                                    <span class="badge badge-{{ $sample->quality_status_badge }}">
                                        {{ str_replace('_', ' ', $sample->quality_status) }}
                                    </span>
                                </td>

                                {{-- Actions --}}
                                <td style="padding:4px 4px; text-align:right;" class="align-middle">
                                    <div style="display:inline-flex; align-items:center; gap:2px;">
                                        <a href="{{ route('admin.samples.show', $sample) }}"
                                           class="btn btn-outline-info btn-sm p-0"
                                           style="width:26px; height:26px; display:inline-flex; align-items:center; justify-content:center;"
                                           title="View details">
                                            <i class="mdi mdi-eye" style="font-size:.85rem; line-height:1;"></i>
                                        </a>
                                        <a href="{{ route('admin.samples.edit', $sample) }}"
                                           class="btn btn-outline-primary btn-sm p-0"
                                           style="width:26px; height:26px; display:inline-flex; align-items:center; justify-content:center;"
                                           title="Edit">
                                            <i class="mdi mdi-pencil" style="font-size:.85rem; line-height:1;"></i>
                                        </a>
                                        <form action="{{ route('admin.samples.destroy', $sample) }}"
                                              method="POST" style="display:contents;"
                                              onsubmit="return confirm('Delete sample #{{ $sample->id }}?')">
                                            @csrf @method('DELETE')
                                            <button type="submit"
                                                    class="btn btn-outline-danger btn-sm p-0"
                                                    style="width:26px; height:26px; display:inline-flex; align-items:center; justify-content:center;"
                                                    title="Delete">
                                                <i class="mdi mdi-delete" style="font-size:.85rem; line-height:1;"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Pagination --}}
                <div class="d-flex justify-content-end mt-3">
                    {{ $samples->links('pagination::bootstrap-4') }}
                </div>
                @endif

            </div>
        </div>
    </div>
</div>
@endsection

{{-- ── Add Sample Modal ──────────────────────────────────────────────────── --}}
@push('modals')
<div class="modal fade" id="addSampleModal" tabindex="-1" role="dialog" aria-labelledby="addSampleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">

            {{-- ── Modal header with top-level tabs ── --}}
            <div class="modal-header pb-0" style="align-items:flex-start;">
                <div class="w-100">
                    <div class="d-flex align-items-center mb-2">
                        <h5 class="modal-title mb-0" id="addSampleModalLabel">
                            <i class="mdi mdi-flask-plus-outline mr-1"></i> Add New Sample
                        </h5>
                        <button type="button" class="close ml-auto" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <ul class="nav nav-tabs border-bottom-0" id="modalMainTab" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="tab-main-sample-link"
                               data-toggle="tab" href="#pane-main-sample" role="tab">
                                <i class="mdi mdi-flask-plus-outline mr-1"></i> Add Sample
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="tab-main-gdc-link"
                               data-toggle="tab" href="#pane-main-gdc" role="tab">
                                <i class="mdi mdi-cloud-upload-outline mr-1"></i> Import GDC Files
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="tab-content border-top">

                {{-- ── Tab 1: Add Sample ── --}}
                <div class="tab-pane fade show active" id="pane-main-sample" role="tabpanel">

            <form method="POST" action="{{ route('admin.samples.store') }}" id="addSampleForm"
                  enctype="multipart/form-data">
                @csrf
                {{-- Hidden field — updated by JS when user switches tabs --}}
                <input type="hidden" name="upload_method" id="uploadMethodInput" value="upload">

                <div class="modal-body">

                    {{-- Flash validation errors inside modal --}}
                    @if($errors->any())
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <ul class="mb-0 pl-3">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                    </div>
                    @endif

                    {{-- ─── Upload Method Tabs ─────────────────────────────── --}}
                    <ul class="nav nav-tabs mb-3" id="uploadMethodTab" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link {{ old('upload_method', 'upload') === 'upload' ? 'active' : '' }}"
                               id="tab-upload-link" data-toggle="tab" href="#pane-upload" role="tab"
                               data-method="upload">
                                <i class="mdi mdi-upload mr-1"></i> Upload File
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ old('upload_method') === 'gdrive' ? 'active' : '' }}"
                               id="tab-gdrive-link" data-toggle="tab" href="#pane-gdrive" role="tab"
                               data-method="gdrive">
                                <i class="mdi mdi-google-drive mr-1"></i> Google Drive Link
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ old('upload_method') === 'bulk' ? 'active' : '' }}"
                               id="tab-bulk-link" data-toggle="tab" href="#pane-bulk" role="tab"
                               data-method="bulk">
                                <i class="mdi mdi-folder-multiple mr-1"></i> Bulk Folder (TCGA)
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content mb-3">

                        {{-- Method 1: Upload File --}}
                        <div class="tab-pane fade {{ old('upload_method', 'upload') === 'upload' ? 'show active' : '' }}"
                             id="pane-upload" role="tabpanel">
                            <div class="form-group mb-0">
                                <label>Slide / Image File <span class="text-danger">*</span></label>
                                <div class="custom-file">
                                    <input type="file" class="custom-file-input @error('sample_file') is-invalid @enderror"
                                           name="sample_file" id="sampleFileInput"
                                           accept=".svs,.tiff,.tif,.ndpi,.scn,.mrxs,.vsi,.czi,.bif">
                                    <label class="custom-file-label" for="sampleFileInput">Choose file…</label>
                                </div>
                                <small class="form-text text-muted">
                                    Supported: SVS, TIFF, NDPI, SCN, MRXS, VSI, CZI, BIF, DICOM and other whole-slide formats.
                                    The file will be uploaded directly to Google Drive — nothing is stored on the server.
                                </small>
                                @error('sample_file')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        {{-- Method 2: Google Drive Sharing Link --}}
                        <div class="tab-pane fade {{ old('upload_method') === 'gdrive' ? 'show active' : '' }}"
                             id="pane-gdrive" role="tabpanel">
                            <div class="form-group mb-0">
                                <label>Google Drive Sharing Link <span class="text-danger">*</span></label>
                                <input type="url" name="gdrive_link"
                                       class="form-control @error('gdrive_link') is-invalid @enderror"
                                       value="{{ old('upload_method') === 'gdrive' ? old('gdrive_link') : '' }}"
                                       placeholder="https://drive.google.com/file/d/…/view?usp=sharing">
                                <small class="form-text text-muted">
                                    Paste a publicly-shared Google Drive link. The file will be copied to our Drive folder automatically.
                                </small>
                                @error('gdrive_link')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        {{-- Method 3: Bulk Folder Upload (TCGA) --}}
                        <div class="tab-pane fade {{ old('upload_method') === 'bulk' ? 'show active' : '' }}"
                             id="pane-bulk" role="tabpanel">
                            <div class="alert alert-info mb-3 py-2">
                                <i class="mdi mdi-information-outline mr-1"></i>
                                <strong>Bulk Folder Upload (TCGA):</strong>
                                Paste the full path to your TCGA folder. Upload runs in the background — the browser will <strong>not freeze</strong>.
                            </div>
                            <div class="form-group mb-0">
                                <label for="bulk_folder_path">Folder Path <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="mdi mdi-folder-open"></i></span>
                                    </div>
                                    <input type="text"
                                           class="form-control @error('bulk_folder_path') is-invalid @enderror"
                                           name="bulk_folder_path"
                                           id="bulkFolderPathInput"
                                           value="{{ old('bulk_folder_path') }}"
                                           placeholder="e.g. C:\Users\Legion\Downloads\normal tissue"
                                           autocomplete="off"
                                           spellcheck="false">
                                    @error('bulk_folder_path')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <small class="form-text text-muted mt-1">
                                    Copy the folder path from File Explorer address bar and paste it here.
                                </small>
                            </div>
                        </div>

                    </div>{{-- /tab-content --}}

                    <hr class="mt-0 mb-3">

                    {{-- ─── Classification ─────────────────────────────────── --}}
                    <h6 class="font-weight-bold text-primary border-bottom pb-1 mb-3">Classification</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Organ <span class="text-danger">*</span></label>
                                <select name="organ_id" id="modal_organ_id" class="form-control @error('organ_id') is-invalid @enderror" required>
                                    <option value="">— Select organ —</option>
                                    @foreach($organs as $organ)
                                        <option value="{{ $organ->id }}" @selected(old('organ_id') == $organ->id)>
                                            {{ $organ->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('organ_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Category</label>
                                <select name="category_id" id="modal_category_id" class="form-control">
                                    <option value="">— Select category —</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->id }}" @selected(old('category_id') == $cat->id)>
                                            {{ $cat->label_en }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Data Source</label>
                                <select name="data_source_id" id="modal_data_source_id" class="form-control">
                                    <option value="">— Select source —</option>
                                    @foreach($dataSources as $source)
                                        <option value="{{ $source->id }}" data-name="{{ $source->name }}" @selected(old('data_source_id') == $source->id)>
                                            {{ $source->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Disease Subtype</label>
                                <select name="disease_subtype_id" id="modal_disease_subtype_id" class="form-control">
                                    <option value="">— Select category first —</option>
                                </select>
                                <small class="form-text text-muted">Select a category first to load available subtypes.</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Training Phase</label>
                                <select name="training_phase" class="form-control">
                                    <option value="">— None —</option>
                                    <option value="1" @selected(old('training_phase') == 1)>Phase 1</option>
                                    <option value="2" @selected(old('training_phase') == 2)>Phase 2</option>
                                    <option value="3" @selected(old('training_phase') == 3)>Phase 3</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Stain</label>
                                <select name="stain_id" class="form-control @error('stain_id') is-invalid @enderror">
                                    <option value="">— None —</option>
                                    @foreach($stains as $stain)
                                        <option value="{{ $stain->id }}" @selected(old('stain_id') == $stain->id)>
                                            {{ $stain->name }}{{ $stain->abbreviation ? ' (' . $stain->abbreviation . ')' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('stain_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Stain Marker</label>
                                <input type="text" name="stain_marker"
                                       class="form-control @error('stain_marker') is-invalid @enderror"
                                       placeholder="e.g. ER, PR, HER2, Ki67"
                                       value="{{ old('stain_marker') }}">
                                @error('stain_marker')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>
                                    Tissue Name
                                    <small class="text-muted font-weight-normal">(auto-generated from your selections)</small>
                                </label>
                                <div class="form-control bg-light font-italic text-muted" id="tissue_name_preview"
                                     style="min-height:38px; font-size:.85rem; word-break:break-all;">
                                    — select Data Source, Category &amp; Disease Subtype to preview —
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group mb-0">
                                <label>
                                    Sample Identifier
                                    <small class="text-muted font-weight-normal">(auto-generated — used as Drive folder name)</small>
                                </label>
                                <div class="form-control bg-light font-italic text-muted" id="sample_id_preview"
                                     style="min-height:38px; font-size:.85rem; word-break:break-all;">
                                    — generated upon save —
                                </div>
                            </div>
                        </div>{{-- /col-md-12 --}}
                    </div>{{-- /row --}}
                </div>{{-- /modal-body --}}

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="addSampleSubmitBtn">
                        <i class="mdi mdi-upload mr-1"></i> Upload &amp; Process
                    </button>
                </div>
            </form>

                </div>{{-- /tab-pane #pane-main-sample --}}

                {{-- ── Tab 2: Import GDC Files ── --}}
                <div class="tab-pane fade" id="pane-main-gdc" role="tabpanel">
            <div class="modal-body bg-light">
                <div class="alert alert-info py-2 small">
                    <i class="mdi mdi-information-outline mr-1"></i>
                    Upload one or more files. Each file is auto-detected:
                    <ul class="mb-0 mt-1 pl-3">
                        <li><strong>Manifest TSV</strong> (<code>.txt</code> with columns
                            <code>id, filename, md5, size, state</code>) → creates / updates samples.</li>
                        <li><strong>metadata.cart.*.json</strong> → creates samples + cases and links them.</li>
                        <li><strong>clinical.cart.*.json</strong> → creates / updates the full clinical record per case.</li>
                    </ul>
                    <small class="d-block mt-2">
                        Order doesn't matter — linkage happens automatically by GDC <code>case_id</code> and <code>file_id</code>.
                    </small>
                </div>

                @if(session('import_summary'))
                    @php $s = session('import_summary'); @endphp
                    <div class="alert alert-success small">
                        <strong>Last import:</strong>
                        <ul class="mb-0 mt-1 pl-3">
                            @if($s['manifest']['files'])<li>Manifest — {{ $s['manifest']['rows'] }} rows ({{ $s['manifest']['samples_created'] }} new, {{ $s['manifest']['samples_updated'] }} updated)</li>@endif
                            @if($s['metadata']['files'])<li>Metadata — {{ $s['metadata']['rows'] }} rows; cases +{{ $s['metadata']['cases_created'] }}/✎{{ $s['metadata']['cases_updated'] }}; samples +{{ $s['metadata']['samples_created'] }}/✎{{ $s['metadata']['samples_updated'] }}</li>@endif
                            @if($s['clinical']['files'])<li>Clinical — {{ $s['clinical']['rows'] }} cases; clinical +{{ $s['clinical']['clinical_created'] }}/✎{{ $s['clinical']['clinical_updated'] }}</li>@endif
                            @if($s['clinical']['samples_linked'])<li>Linked {{ $s['clinical']['samples_linked'] }} sample(s) to cases</li>@endif
                            @foreach($s['errors'] as $err)<li class="text-danger">{{ $err }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.imports.store') }}" enctype="multipart/form-data" id="gdcImportForm">
                    @csrf
                    <div class="row">
                        <div class="col-md-7">
                            <div class="form-group mb-2">
                                <label class="small">Files <span class="text-danger">*</span></label>
                                <div class="custom-file">
                                    <input type="file"
                                           class="custom-file-input @error('import_files') is-invalid @enderror @error('import_files.*') is-invalid @enderror"
                                           name="import_files[]" id="importFilesInput"
                                           accept=".txt,.json,.tsv" multiple required>
                                    <label class="custom-file-label" for="importFilesInput">Choose files…</label>
                                </div>
                                <small class="form-text text-muted">
                                    Multiple files supported (Ctrl/Cmd + click). Max 50 MB per file.
                                </small>
                                @error('import_files')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                @error('import_files.*')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group mb-2">
                                <label class="small">Default Data Source <span class="text-muted">(optional)</span></label>
                                <select name="data_source_id" class="form-control form-control-sm">
                                    <option value="">— Auto-detect (TCGA) —</option>
                                    @foreach($dataSources as $ds)
                                        <option value="{{ $ds->id }}">{{ $ds->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <button type="submit" class="btn btn-info btn-sm">
                            <i class="mdi mdi-cloud-upload-outline mr-1"></i> Import GDC Files
                        </button>
                    </div>
                </form>
            </div>
                </div>{{-- /tab-pane #pane-main-gdc --}}

            </div>{{-- /tab-content --}}

        </div>
    </div>
</div>

{{-- ── Upload Report Modal ────────────────────────────────────────────── --}}
<div class="modal fade" id="uploadReportModal" tabindex="-1" role="dialog" aria-labelledby="uploadReportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadReportModalLabel">
                    <i class="mdi mdi-clipboard-list-outline mr-1"></i> Upload Report
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">

                @php
                    $report  = session('upload_report', []);
                    $queued  = $report['queued']  ?? [];
                    $skipped = $report['skipped'] ?? [];
                @endphp

                {{-- Summary badges --}}
                <div class="d-flex mb-4" style="gap:.75rem;">
                    <span class="badge badge-success px-3 py-2" style="font-size:.9rem;">
                        <i class="mdi mdi-check-circle-outline mr-1"></i>
                        {{ count($queued) }} Queued
                    </span>
                    <span class="badge badge-warning px-3 py-2" style="font-size:.9rem;">
                        <i class="mdi mdi-skip-next-circle-outline mr-1"></i>
                        {{ count($skipped) }} Skipped
                    </span>
                </div>

                {{-- Queued list --}}
                @if(!empty($queued))
                <div class="mb-4">
                    <h6 class="font-weight-bold text-success border-bottom pb-1 mb-2">
                        <i class="mdi mdi-check-circle-outline mr-1"></i>
                        Successfully Queued ({{ count($queued) }})
                    </h6>
                    <p class="text-muted small mb-2">These files are new and have been queued for upload to Google Drive.</p>
                    <ul class="list-group list-group-flush">
                        @foreach($queued as $item)
                        <li class="list-group-item py-2 d-flex align-items-center" style="border-color:#d4edda;">
                            <i class="mdi mdi-file-upload-outline text-success mr-2" style="font-size:1.1rem;"></i>
                            <div>
                                <span class="font-weight-medium small">{{ $item['name'] }}</span>
                                @if(!empty($item['folder']) && $item['folder'] !== $item['name'])
                                    <br><small class="text-muted">Folder: {{ $item['folder'] }}</small>
                                @endif
                            </div>
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                {{-- Skipped list --}}
                @if(!empty($skipped))
                <div class="mb-2">
                    <h6 class="font-weight-bold text-warning border-bottom pb-1 mb-2">
                        <i class="mdi mdi-skip-next-circle-outline mr-1"></i>
                        Skipped — Already Exists ({{ count($skipped) }})
                    </h6>
                    <p class="text-muted small mb-2">These files were ignored because a matching record already exists in the database.</p>
                    <ul class="list-group list-group-flush">
                        @foreach($skipped as $item)
                        <li class="list-group-item py-2 d-flex align-items-center" style="border-color:#ffeeba;">
                            <i class="mdi mdi-file-remove-outline text-warning mr-2" style="font-size:1.1rem;"></i>
                            <div>
                                <span class="font-weight-medium small">{{ $item['name'] }}</span>
                                @if(!empty($item['folder']) && $item['folder'] !== $item['name'])
                                    <br><small class="text-muted">Folder: {{ $item['folder'] }}</small>
                                @endif
                                <br><small class="text-danger">{{ $item['reason'] }}</small>
                            </div>
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                @if(empty($queued) && empty($skipped))
                    <p class="text-muted text-center py-3">No upload data available.</p>
                @endif

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

{{-- ── Import GDC Files Modal ────────────────────────────────────────────── --}}
{{--
    NOTE: The standalone `importFilesModal` was removed.
    Its functionality has been merged into `addSampleModal` as the last
    section "Import GDC Files (Manifest / Metadata / Clinical)".
--}}

{{-- ── Bulk Verify — Confirmation + Progress Modal ──────────────────────── --}}
<div class="modal fade" id="bulkVerifyModal" tabindex="-1" role="dialog"
     aria-labelledby="bulkVerifyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkVerifyModalLabel">
                    <i class="mdi mdi-shield-search mr-1"></i> Verify All Unverified Samples
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                {{-- Step 1: Confirmation --}}
                <div id="bulkVerifyConfirm">
                    <p class="mb-2">
                        This will queue every sample that has <strong>never been verified</strong>
                        or is currently in <strong>pending</strong> status for background
                        verification.
                    </p>
                    <ul class="mb-2" style="font-size:.88rem;">
                        <li>Samples already marked <span class="badge badge-danger">failed</span> are <strong>skipped</strong>.</li>
                        <li>Samples with status <span class="badge badge-success">passed</span> are <strong>skipped</strong>.</li>
                        <li>Jobs run in the queue — large slides may take several minutes each.</li>
                    </ul>
                    <p class="text-muted mb-0" style="font-size:.83rem;">
                        You can continue using the system while the queue processes in the background.
                    </p>
                </div>
                {{-- Step 2: Running --}}
                <div id="bulkVerifyRunning" style="display:none; text-align:center; padding:.5rem 0;">
                    <div class="spinner-border text-warning mb-3" role="status">
                        <span class="sr-only">Loading…</span>
                    </div>
                    <p class="mb-0 font-weight-bold">Queuing samples…</p>
                </div>
                {{-- Step 3: Done --}}
                <div id="bulkVerifyDone" style="display:none; text-align:center; padding:.5rem 0;">
                    <i class="mdi mdi-check-circle-outline text-success" style="font-size:2.5rem;"></i>
                    <p class="font-weight-bold mt-2 mb-1" id="bulkVerifyDoneMsg"></p>
                    <p class="text-muted mb-0" style="font-size:.83rem;">
                        The queue workers will process the jobs in the background.
                        Refresh the page or check individual samples to see updated statuses.
                    </p>
                </div>
                {{-- Error --}}
                <div id="bulkVerifyError" style="display:none;" class="alert alert-danger mb-0 mt-2"></div>
            </div>
            <div class="modal-footer" id="bulkVerifyFooter">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="bulkVerifyConfirmBtn">
                    <i class="mdi mdi-play-circle-outline mr-1"></i> Start Verification Queue
                </button>
            </div>
        </div>
    </div>
</div>
@endpush

@push('scripts')
<script>
(function () {
    'use strict';
    @if(session('upload_report'))
    $(document).ready(function () {
        $('#uploadReportModal').modal('show');
    });
    @endif
    // ── Disease subtypes data (keyed by category_id) ─────────────────────────
    var diseaseSubtypesByCategory = @json($diseaseSubtypesByCategory);

    // ── Sync hidden upload_method field when tab changes ─────────────────────
    document.querySelectorAll('#uploadMethodTab .nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            document.getElementById('uploadMethodInput').value = this.getAttribute('data-method');
        });
        $(link).on('shown.bs.tab', function () {
            document.getElementById('uploadMethodInput').value = this.getAttribute('data-method');
        });
    });

    // ── Read active method directly from DOM (most reliable) ─────────────────
    function getActiveUploadMethod() {
        var activeLink = document.querySelector('#uploadMethodTab .nav-link.active');
        if (activeLink) {
            return activeLink.getAttribute('data-method');
        }
        return document.getElementById('uploadMethodInput').value || 'upload';
    }

    // ── Show chosen file name in custom-file label ───────────────────────────
    var fileInput = document.getElementById('sampleFileInput');
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var label = this.nextElementSibling;
            label.textContent = this.files.length ? this.files[0].name : 'Choose file…';
            // Clear other methods
            var gdriveEl = document.querySelector('input[name="gdrive_link"]');
            var pathEl   = document.getElementById('bulkFolderPathInput');
            if (gdriveEl) gdriveEl.value = '';
            if (pathEl)   pathEl.value   = '';
        });
    }

    // ── Import files label ───────────────────────────────────────────────────
    var importFilesEl = document.getElementById('importFilesInput');
    if (importFilesEl) {
        importFilesEl.addEventListener('change', function () {
            var lbl = this.nextElementSibling;
            if (!lbl) return;
            lbl.textContent = this.files.length === 0
                ? 'Choose files…'
                : this.files.length === 1
                    ? this.files[0].name
                    : this.files.length + ' files selected';
        });
    }

    // ── Bulk path input: clear other methods on change ────────────────────────
    var bulkPathInput = document.getElementById('bulkFolderPathInput');
    if (bulkPathInput) {
        bulkPathInput.addEventListener('input', function () {
            if (!this.value.trim()) return;
            var fileEl   = document.getElementById('sampleFileInput');
            var gdriveEl = document.querySelector('input[name="gdrive_link"]');
            if (fileEl)   fileEl.value   = '';
            if (gdriveEl) gdriveEl.value = '';
        });
    }

    // ── Clear other methods when entering Google Drive link ──────────────────
    var gdriveInput = document.querySelector('input[name="gdrive_link"]');
    if (gdriveInput) {
        gdriveInput.addEventListener('focus', function () {
            var fileEl = document.getElementById('sampleFileInput');
            var pathEl = document.getElementById('bulkFolderPathInput');
            if (fileEl) fileEl.value = '';
            if (pathEl) pathEl.value = '';
            var preview = document.getElementById('bulkFolderPreview');
            if (preview) preview.style.display = 'none';
        });
    }

    // ── Form submission: sync active method then submit ───────────────────────
    document.getElementById('addSampleForm').addEventListener('submit', function (e) {
        // Always read the active tab from DOM before submitting
        var activeMethod = getActiveUploadMethod();
        document.getElementById('uploadMethodInput').value = activeMethod;
        // No client-side blocking — server handles all validation
    });

    // ── Populate disease subtype dropdown based on category selection ────────
    function updateDiseaseSubtypeDropdown() {
        var catId   = document.getElementById('modal_category_id').value;
        var select  = document.getElementById('modal_disease_subtype_id');
        select.innerHTML = '';

        var defaultOpt = document.createElement('option');
        defaultOpt.value = '';

        var subtypes = catId ? (diseaseSubtypesByCategory[catId] || []) : [];
        defaultOpt.textContent = subtypes.length
            ? '— Select subtype —'
            : (catId ? '— No subtypes for this category —' : '— Select category first —');
        select.appendChild(defaultOpt);

        subtypes.forEach(function (sub) {
            var opt = document.createElement('option');
            opt.value = sub.id;
            opt.textContent = sub.name;
            select.appendChild(opt);
        });

        updatePreviews();
    }

    // ── Build and display tissue_name / sample_id previews ───────────────────
    function updatePreviews() {
        var sourceSelect  = document.getElementById('modal_data_source_id');
        var catSelect     = document.getElementById('modal_category_id');
        var subtypeSelect = document.getElementById('modal_disease_subtype_id');

        var sourceName  = sourceSelect.value
            ? sourceSelect.options[sourceSelect.selectedIndex].text.trim() : '';
        var catName     = catSelect.value
            ? catSelect.options[catSelect.selectedIndex].text.trim() : '';
        var subtypeName = subtypeSelect.value
            ? subtypeSelect.options[subtypeSelect.selectedIndex].text.trim() : '';

        var previewEl   = document.getElementById('tissue_name_preview');
        var sampleEl    = document.getElementById('sample_id_preview');

        if (sourceName || catName || subtypeName) {
            var parts     = [sourceName, catName, subtypeName].filter(Boolean);
            var sampleId  = parts.join('-') + '-{UUID}';
            var pathParts = [sourceName, catName].filter(Boolean).join('/');
            var tissue    = '/' + (pathParts ? pathParts + '/' : '') + sampleId + '/';

            previewEl.textContent = tissue;
            previewEl.classList.remove('font-italic');
            sampleEl.textContent  = sampleId;
            sampleEl.classList.remove('font-italic');
        } else {
            previewEl.textContent = '— select Data Source, Category & Disease Subtype to preview —';
            previewEl.classList.add('font-italic');
            sampleEl.textContent  = '— generated upon save —';
            sampleEl.classList.add('font-italic');
        }
    }

    document.getElementById('modal_category_id').addEventListener('change', updateDiseaseSubtypeDropdown);
    document.getElementById('modal_data_source_id').addEventListener('change', updatePreviews);
    document.getElementById('modal_disease_subtype_id').addEventListener('change', updatePreviews);

    @if($errors->any())
    // Re-open modal when validation errors are returned
    $(document).ready(function () {
        $('#addSampleModal').modal('show');
        @if(old('upload_method') === 'gdrive')
        $('#tab-gdrive-link').tab('show');
        @endif
        // Restore subtype dropdown for the previously selected category
        updateDiseaseSubtypeDropdown();
        @if(old('disease_subtype_id'))
        document.getElementById('modal_disease_subtype_id').value = '{{ old('disease_subtype_id') }}';
        @endif
    });
    @endif
})();
</script>

{{-- ── Bulk Verify JS ─────────────────────────────────────────────────────── --}}
<script>
(function () {
    'use strict';

    var bulkVerifyBtn     = document.getElementById('bulkVerifyBtn');
    var confirmBtn        = document.getElementById('bulkVerifyConfirmBtn');
    var confirmBox        = document.getElementById('bulkVerifyConfirm');
    var runningBox        = document.getElementById('bulkVerifyRunning');
    var doneBox           = document.getElementById('bulkVerifyDone');
    var errorBox          = document.getElementById('bulkVerifyError');
    var doneMsg           = document.getElementById('bulkVerifyDoneMsg');
    var footer            = document.getElementById('bulkVerifyFooter');

    // Open modal when the toolbar button is clicked
    if (bulkVerifyBtn) {
        bulkVerifyBtn.addEventListener('click', function () {
            // Reset modal to confirmation step
            confirmBox.style.display  = 'block';
            runningBox.style.display  = 'none';
            doneBox.style.display     = 'none';
            errorBox.style.display    = 'none';
            errorBox.textContent      = '';
            footer.style.display      = '';
            confirmBtn.disabled       = false;
            $('#bulkVerifyModal').modal('show');
        });
    }

    // User confirms — call the API
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            confirmBtn.disabled      = true;
            confirmBox.style.display = 'none';
            runningBox.style.display = 'block';
            footer.style.display     = 'none';

            fetch('{{ route('admin.samples.verify-unverified') }}', {
                method:  'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'Accept':           'application/json',
                    'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]')
                                        ? document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                                        : '{{ csrf_token() }}',
                },
            })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (resp) {
                runningBox.style.display = 'none';
                if (resp.ok) {
                    var count = resp.data.queued || 0;
                    doneMsg.textContent = count > 0
                        ? count + ' sample' + (count !== 1 ? 's' : '') + ' queued for verification.'
                        : 'No unverified samples found — nothing was queued.';
                    doneBox.style.display = 'block';
                    footer.innerHTML = '<button type="button" class="btn btn-success" data-dismiss="modal">Close</button>';
                    footer.style.display = '';
                } else {
                    errorBox.textContent  = resp.data.message || 'An error occurred. Please try again.';
                    errorBox.style.display = 'block';
                    confirmBox.style.display = 'block';
                    footer.style.display = '';
                    confirmBtn.disabled  = false;
                }
            })
            .catch(function (err) {
                runningBox.style.display  = 'none';
                errorBox.textContent      = 'Network error: ' + err.message;
                errorBox.style.display    = 'block';
                confirmBox.style.display  = 'block';
                footer.style.display      = '';
                confirmBtn.disabled       = false;
            });
        });
    }
}());
</script>
@endpush
