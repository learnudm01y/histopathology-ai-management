@extends('admin.layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="page-header">
    <h3 class="page-title">Dashboard</h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item active" aria-current="page">Overview</li>
        </ol>
    </nav>
</div>

{{-- ── Samples Statistics ───────────────────────────────────────────────── --}}
<h5 class="text-muted font-weight-bold mb-2" style="font-size:.8rem; letter-spacing:.05em; text-transform:uppercase;">
    <i class="mdi mdi-flask-outline mr-1"></i> Samples
</h5>
<div class="row mb-2">
    {{-- Total --}}
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Total Samples</p>
                    <h4 class="font-weight-bold mb-0">{{ number_format($sampleStats['total']) }}</h4>
                </div>
                <i class="mdi mdi-database-outline icon-lg text-secondary"></i>
            </div>
        </div>
    </div>
    {{-- Available --}}
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Available</p>
                    <h4 class="font-weight-bold mb-0 text-success">{{ number_format($sampleStats['available']) }}</h4>
                </div>
                <i class="mdi mdi-check-circle-outline icon-lg text-success"></i>
            </div>
        </div>
    </div>
    {{-- Not Downloaded --}}
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Not Downloaded</p>
                    <h4 class="font-weight-bold mb-0 text-warning">{{ number_format($sampleStats['not_downloaded']) }}</h4>
                </div>
                <i class="mdi mdi-download-off-outline icon-lg text-warning"></i>
            </div>
        </div>
    </div>
    {{-- Downloading --}}
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Downloading</p>
                    <h4 class="font-weight-bold mb-0 text-info">{{ number_format($sampleStats['downloading']) }}</h4>
                </div>
                <i class="mdi mdi-download-outline icon-lg text-info"></i>
            </div>
        </div>
    </div>
    {{-- Corrupted --}}
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Corrupted</p>
                    <h4 class="font-weight-bold mb-0 text-danger">{{ number_format($sampleStats['corrupted']) }}</h4>
                </div>
                <i class="mdi mdi-alert-circle-outline icon-lg text-danger"></i>
            </div>
        </div>
    </div>
    {{-- Missing --}}
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Missing</p>
                    <h4 class="font-weight-bold mb-0 text-danger">{{ number_format($sampleStats['missing']) }}</h4>
                </div>
                <i class="mdi mdi-file-alert-outline icon-lg text-danger"></i>
            </div>
        </div>
    </div>
</div>

{{-- ── Tiling Row ───────────────────────────────────────────────────────── --}}
<div class="row mb-2">
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Tiling Done</p>
                    <h4 class="font-weight-bold mb-0 text-success">{{ number_format($sampleStats['tiling_done']) }}</h4>
                </div>
                <i class="mdi mdi-grid-large icon-lg text-success"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Tiling Pending</p>
                    <h4 class="font-weight-bold mb-0 text-warning">{{ number_format($sampleStats['tiling_pending']) }}</h4>
                </div>
                <i class="mdi mdi-timer-sand icon-lg text-warning"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Tiling Failed</p>
                    <h4 class="font-weight-bold mb-0 text-danger">{{ number_format($sampleStats['tiling_failed']) }}</h4>
                </div>
                <i class="mdi mdi-grid-off icon-lg text-danger"></i>
            </div>
        </div>
    </div>
</div>

{{-- ── Quality Row ──────────────────────────────────────────────────────── --}}
<div class="row mb-4">
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Quality Passed</p>
                    <h4 class="font-weight-bold mb-0 text-success">{{ number_format($sampleStats['quality_passed']) }}</h4>
                </div>
                <i class="mdi mdi-shield-check-outline icon-lg text-success"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100 border-danger" style="border-width:2px!important;">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-danger mb-1" style="font-size:.73rem; font-weight:600;">Rejected</p>
                    <h4 class="font-weight-bold mb-0 text-danger">{{ number_format($sampleStats['quality_rejected']) }}</h4>
                </div>
                <i class="mdi mdi-close-octagon-outline icon-lg text-danger"></i>
            </div>
            @if($sampleStats['quality_rejected'] > 0)
            <div class="card-footer py-1 px-3" style="background:transparent;">
                <button class="btn btn-link btn-sm text-danger p-0" data-toggle="collapse"
                        data-target="#rejectedTable" aria-expanded="false" aria-controls="rejectedTable">
                    <i class="mdi mdi-eye-outline mr-1"></i>View rejected
                </button>
            </div>
            @endif
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Needs Review</p>
                    <h4 class="font-weight-bold mb-0 text-warning">{{ number_format($sampleStats['quality_review']) }}</h4>
                </div>
                <i class="mdi mdi-eye-check-outline icon-lg text-warning"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Quality Pending</p>
                    <h4 class="font-weight-bold mb-0 text-secondary">{{ number_format($sampleStats['quality_pending']) }}</h4>
                </div>
                <i class="mdi mdi-progress-clock icon-lg text-secondary"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Not Usable</p>
                    <h4 class="font-weight-bold mb-0 text-danger">{{ number_format($sampleStats['not_usable']) }}</h4>
                </div>
                <i class="mdi mdi-cancel icon-lg text-danger"></i>
            </div>
        </div>
    </div>
</div>

{{-- ── Rejected Samples Collapsible Table ──────────────────────────────── --}}
@if($sampleStats['quality_rejected'] > 0)
<div class="collapse mb-4" id="rejectedTable">
    <div class="card shadow-sm">
        <div class="card-header py-2 px-3 d-flex align-items-center justify-content-between">
            <h6 class="mb-0 text-danger">
                <i class="mdi mdi-close-octagon-outline mr-1"></i>
                Rejected Samples
                <span class="badge badge-danger ml-1">{{ $rejectedSamples->count() }}</span>
            </h6>
            <button class="btn btn-sm btn-light" onclick="$('#rejectedTable').collapse('hide')" type="button">
                <i class="mdi mdi-close"></i>
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:.82rem;">
                    <thead class="thead-light">
                        <tr>
                            <th class="px-3" style="width:40px;">#</th>
                            <th class="px-3">File Name</th>
                            <th class="px-3" style="width:110px;">Storage</th>
                            <th class="px-3">Rejection Reason</th>
                            <th class="px-3 text-center" style="width:100px;">Preview</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rejectedSamples as $s)
                        <tr>
                            <td class="px-3 text-muted align-middle">{{ $s->id }}</td>
                            <td class="px-3 align-middle" style="max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <span title="{{ $s->file_name }}">{{ $s->file_name ?? '—' }}</span>
                            </td>
                            <td class="px-3 align-middle">
                                <span class="badge badge-{{ $s->storage_status_badge ?? 'secondary' }}">
                                    {{ str_replace('_', ' ', $s->storage_status) }}
                                </span>
                            </td>
                            <td class="px-3 align-middle text-muted" style="font-size:.78rem;">
                                {{ $s->quality_rejection_reason ?? '—' }}
                            </td>
                            <td class="px-3 align-middle text-center">
                                <a href="{{ route('admin.samples.show', $s->id) }}"
                                   target="_blank"
                                   class="btn btn-sm btn-outline-primary py-0 px-2"
                                   title="Open sample preview in new tab">
                                    <i class="mdi mdi-eye-outline mr-1"></i>View
                                </a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">No rejected samples.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── Slide Verification Statistics ───────────────────────────────────── --}}
<h5 class="text-muted font-weight-bold mb-2" style="font-size:.8rem; letter-spacing:.05em; text-transform:uppercase;">
    <i class="mdi mdi-shield-search mr-1"></i> Slide Verification
</h5>
<div class="row mb-2">
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Total Verified</p>
                    <h4 class="font-weight-bold mb-0">{{ number_format($verifStats['total']) }}</h4>
                </div>
                <i class="mdi mdi-shield-outline icon-lg text-secondary"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Passed</p>
                    <h4 class="font-weight-bold mb-0 text-success">{{ number_format($verifStats['passed']) }}</h4>
                </div>
                <i class="mdi mdi-shield-check-outline icon-lg text-success"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100 border-danger" style="border-width:2px!important;">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-danger mb-1" style="font-size:.73rem; font-weight:600;">Failed</p>
                    <h4 class="font-weight-bold mb-0 text-danger">{{ number_format($verifStats['failed']) }}</h4>
                </div>
                <i class="mdi mdi-shield-off-outline icon-lg text-danger"></i>
            </div>
            @if($verifStats['failed'] > 0)
            <div class="card-footer py-1 px-3" style="background:transparent;">
                <button class="btn btn-link btn-sm text-danger p-0" data-toggle="collapse"
                        data-target="#failedVerifTable" aria-expanded="false" aria-controls="failedVerifTable">
                    <i class="mdi mdi-eye-outline mr-1"></i>View failed
                </button>
            </div>
            @endif
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Pending</p>
                    <h4 class="font-weight-bold mb-0 text-warning">{{ number_format($verifStats['pending']) }}</h4>
                </div>
                <i class="mdi mdi-shield-half-full icon-lg text-warning"></i>
            </div>
        </div>
    </div>
</div>

{{-- ── Failed Verifications Collapsible Table ───────────────────────────── --}}
@if($verifStats['failed'] > 0)
<div class="collapse mb-4" id="failedVerifTable">
    <div class="card shadow-sm">
        <div class="card-header py-2 px-3 d-flex align-items-center justify-content-between">
            <h6 class="mb-0 text-danger">
                <i class="mdi mdi-shield-off-outline mr-1"></i>
                Failed Slide Verifications
                <span class="badge badge-danger ml-1">{{ $failedVerifications->count() }}</span>
            </h6>
            <button class="btn btn-sm btn-light" onclick="$('#failedVerifTable').collapse('hide')" type="button">
                <i class="mdi mdi-close"></i>
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:.82rem;">
                    <thead class="thead-light">
                        <tr>
                            <th class="px-3" style="width:40px;">#</th>
                            <th class="px-3">File Name</th>
                            <th class="px-3" style="width:110px;">Storage</th>
                            <th class="px-3" style="width:140px;">Verified At</th>
                            <th class="px-3">Notes</th>
                            <th class="px-3 text-center" style="width:100px;">Preview</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($failedVerifications as $v)
                        @php $s = $v->sample; @endphp
                        <tr>
                            <td class="px-3 text-muted align-middle">{{ $s?->id ?? '—' }}</td>
                            <td class="px-3 align-middle" style="max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <span title="{{ $s?->file_name }}">{{ $s?->file_name ?? '—' }}</span>
                            </td>
                            <td class="px-3 align-middle">
                                @if($s)
                                <span class="badge badge-{{ $s->storage_status_badge }}">
                                    {{ str_replace('_', ' ', $s->storage_status) }}
                                </span>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="px-3 align-middle text-muted" style="font-size:.78rem;">
                                {{ $v->verified_at?->format('Y-m-d H:i') ?? '—' }}
                            </td>
                            <td class="px-3 align-middle text-muted" style="font-size:.78rem; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                                title="{{ $v->notes }}">
                                {{ $v->notes ?? '—' }}
                            </td>
                            <td class="px-3 align-middle text-center">
                                @if($s)
                                <a href="{{ route('admin.samples.show', $s->id) }}"
                                   target="_blank"
                                   class="btn btn-sm btn-outline-danger py-0 px-2"
                                   title="Open sample in new tab">
                                    <i class="mdi mdi-eye-outline mr-1"></i>View
                                </a>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-3">No failed verifications.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── Cases Statistics ─────────────────────────────────────────────────── --}}
<h5 class="text-muted font-weight-bold mb-2" style="font-size:.8rem; letter-spacing:.05em; text-transform:uppercase;">
    <i class="mdi mdi-folder-multiple-outline mr-1"></i> Cases
</h5>
<div class="row mb-4">
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Total Cases</p>
                    <h4 class="font-weight-bold mb-0">{{ number_format($caseStats['total']) }}</h4>
                </div>
                <i class="mdi mdi-account-multiple-outline icon-lg text-secondary"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">With Clinical Info</p>
                    <h4 class="font-weight-bold mb-0 text-info">{{ number_format($caseStats['with_clinical']) }}</h4>
                </div>
                <i class="mdi mdi-clipboard-text-outline icon-lg text-info"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">With Slides</p>
                    <h4 class="font-weight-bold mb-0 text-warning">{{ number_format($caseStats['with_slides']) }}</h4>
                </div>
                <i class="mdi mdi-image-multiple-outline icon-lg text-warning"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <p class="text-muted mb-1" style="font-size:.73rem;">Fully Linked</p>
                    <h4 class="font-weight-bold mb-0 text-success">{{ number_format($caseStats['fully_linked']) }}</h4>
                </div>
                <i class="mdi mdi-link-variant icon-lg text-success"></i>
            </div>
        </div>
    </div>
</div>

{{-- ── Disease Type Distribution Chart ─────────────────────────────────── --}}
@php
    $diseaseLabels     = $diseaseChartData['labels']     ?? [];
    $diseaseCategories = $diseaseChartData['categories'] ?? [];
    $diseaseMatrix     = $diseaseChartData['matrix']     ?? [];
    $diseaseTotals     = $diseaseChartData['totals']     ?? [];
@endphp
@if(count($diseaseLabels) > 0)
<h5 class="text-muted font-weight-bold mb-2 mt-2" style="font-size:.8rem; letter-spacing:.05em; text-transform:uppercase;">
    <i class="mdi mdi-dna mr-1"></i> Disease Type Distribution
    <span class="badge badge-secondary ml-2" style="font-size:.7rem; vertical-align:middle;">
        {{ count($diseaseLabels) }} {{ Str::plural('disease', count($diseaseLabels)) }}
    </span>
    <span class="text-muted ml-2" style="font-size:.72rem; font-weight:400; text-transform:none;">
        — categorized by slide type
    </span>
</h5>

{{-- Category legend --}}
<div class="d-flex flex-wrap mb-2" style="gap:.4rem;">
    @foreach($diseaseCategories as $cat)
    @php
        $cl = strtolower($cat);
        if (str_contains($cl,'tumor')||str_contains($cl,'malignant')||str_contains($cl,'cancer')) {
            $badgeColor = 'danger';
        } elseif (str_contains($cl,'normal')||str_contains($cl,'benign')||str_contains($cl,'healthy')) {
            $badgeColor = 'success';
        } elseif (str_contains($cl,'unknown')||str_contains($cl,'uncat')) {
            $badgeColor = 'secondary';
        } else {
            $badgeColor = 'primary';
        }
    @endphp
    <span class="badge badge-{{ $badgeColor }}" style="font-size:.75rem; padding:.35em .7em;">
        <i class="mdi mdi-circle mr-1" style="font-size:.6rem;"></i>{{ $cat }}
    </span>
    @endforeach
</div>

<div class="row mb-3">
    {{-- Stacked bar chart --}}
    <div class="col-12 col-xl-8 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-header py-2 px-3" style="background:transparent;">
                <small class="text-muted font-weight-bold" style="font-size:.75rem; text-transform:uppercase; letter-spacing:.04em;">
                    <i class="mdi mdi-chart-bar-stacked mr-1"></i> Slides per Disease × Type
                </small>
            </div>
            <div class="card-body px-3 py-2">
                <div style="position:relative; height:{{ max(200, count($diseaseLabels) * 42) }}px;">
                    <canvas id="diseaseSlideChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Summary table --}}
    <div class="col-12 col-xl-4 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-header py-2 px-3" style="background:transparent;">
                <small class="text-muted font-weight-bold" style="font-size:.75rem; text-transform:uppercase; letter-spacing:.04em;">
                    <i class="mdi mdi-table mr-1"></i> Breakdown Table
                </small>
            </div>
            <div class="card-body p-0" style="overflow-y:auto; max-height:{{ max(200, count($diseaseLabels) * 42) }}px;">
                <table class="table table-sm table-hover mb-0" style="font-size:.78rem;">
                    <thead class="thead-light" style="position:sticky; top:0;">
                        <tr>
                            <th class="px-3" style="min-width:140px;">Disease Type</th>
                            @foreach($diseaseCategories as $cat)
                            <th class="px-2 text-center">{{ $cat }}</th>
                            @endforeach
                            <th class="px-2 text-center font-weight-bold">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($diseaseLabels as $disease)
                        <tr>
                            <td class="px-3 align-middle" style="max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="{{ $disease }}">
                                {{ $disease }}
                            </td>
                            @foreach($diseaseCategories as $cat)
                            @php $cnt = $diseaseMatrix[$disease][$cat] ?? 0; @endphp
                            @php
                                $cl2 = strtolower($cat);
                                if (str_contains($cl2,'tumor')||str_contains($cl2,'malignant')||str_contains($cl2,'cancer')) {
                                    $textColor = 'text-danger';
                                } elseif (str_contains($cl2,'normal')||str_contains($cl2,'benign')||str_contains($cl2,'healthy')) {
                                    $textColor = 'text-success';
                                } else {
                                    $textColor = 'text-muted';
                                }
                            @endphp
                            <td class="px-2 text-center align-middle {{ $cnt > 0 ? $textColor : 'text-muted' }}">
                                {{ $cnt > 0 ? number_format($cnt) : '—' }}
                            </td>
                            @endforeach
                            <td class="px-2 text-center align-middle font-weight-bold">
                                {{ number_format($diseaseTotals[$disease] ?? 0) }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

@endsection

@push('scripts')
@if(count($diseaseChartData['labels'] ?? []) > 0)
<script>
(function () {
    'use strict';

    var labels     = @json($diseaseChartData['labels']);
    var categories = @json($diseaseChartData['categories']);
    var matrix     = @json($diseaseChartData['matrix']);

    // Assign a colour to each category based on its name
    function categoryColor(cat, alpha) {
        var c = cat.toLowerCase();
        if (c.indexOf('tumor') !== -1 || c.indexOf('malignant') !== -1 || c.indexOf('cancer') !== -1) {
            return 'rgba(220,53,69,' + alpha + ')';
        }
        if (c.indexOf('normal') !== -1 || c.indexOf('benign') !== -1 || c.indexOf('healthy') !== -1) {
            return 'rgba(40,167,69,' + alpha + ')';
        }
        if (c.indexOf('unknown') !== -1 || c.indexOf('uncat') !== -1) {
            return 'rgba(108,117,125,' + alpha + ')';
        }
        // Fallback palette for other categories
        var palette = [
            'rgba(63,81,181,',
            'rgba(0,188,212,',
            'rgba(255,152,0,',
            'rgba(103,58,183,',
            'rgba(233,30,99,'
        ];
        return palette[categories.indexOf(cat) % palette.length] + alpha + ')';
    }

    var datasets = categories.map(function (cat) {
        return {
            label: cat,
            data: labels.map(function (disease) {
                return (matrix[disease] && matrix[disease][cat]) ? parseInt(matrix[disease][cat]) : 0;
            }),
            backgroundColor: categoryColor(cat, '.75'),
            borderColor:     categoryColor(cat, '1'),
            borderWidth: 1
        };
    });

    var ctx = document.getElementById('diseaseSlideChart').getContext('2d');
    new Chart(ctx, {
        type: 'horizontalBar',
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: {
                display: true,
                position: 'top',
                labels: { fontSize: 11, padding: 12, boxWidth: 14 }
            },
            tooltips: {
                mode: 'index',
                intersect: false,
                callbacks: {
                    label: function (item, data) {
                        var val = item.xLabel;
                        if (val === 0) return null;
                        return ' ' + data.datasets[item.datasetIndex].label + ': ' + val + ' slide' + (val !== 1 ? 's' : '');
                    }
                }
            },
            scales: {
                xAxes: [{
                    stacked: true,
                    ticks: { beginAtZero: true, precision: 0, fontColor: '#6c757d', fontSize: 11 },
                    gridLines: { color: 'rgba(0,0,0,.05)' }
                }],
                yAxes: [{
                    stacked: true,
                    ticks: { fontColor: '#495057', fontSize: 10 },
                    gridLines: { display: false }
                }]
            }
        }
    });
}());
</script>
@endif
@endpush
