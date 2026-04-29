@extends('admin.layouts.app')

@section('title', 'Case · ' . ($case->submitter_id ?? $case->case_id))

@php
    $clin     = $case->clinicalInfo;
    $samples  = $case->samples;
    // Helper: turn empty/null into a dash
    $val = fn ($v) => ($v === null || $v === '' ? '—' : $v);
@endphp

@section('content')
<div class="page-header">
    <h3 class="page-title">
        Case · {{ $case->submitter_id ?? 'Unknown' }}
        @if($clin?->vital_status)
            <span class="badge {{ strtolower($clin->vital_status) === 'alive' ? 'badge-success' : 'badge-danger' }} ml-2">
                {{ $clin->vital_status }}
            </span>
        @endif
    </h3>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.cases.index') }}">Cases</a></li>
            <li class="breadcrumb-item active">{{ $case->submitter_id ?? $case->case_id }}</li>
        </ol>
    </nav>
</div>

{{-- ── Top Identity Card ────────────────────────────────────────────────── --}}
<div class="row">
    <div class="col-12 grid-margin">
        <div class="card">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3"><small class="text-muted">Submitter ID</small><div class="font-weight-bold">{{ $val($case->submitter_id) }}</div></div>
                    <div class="col-md-5"><small class="text-muted">Case UUID</small><div class="small">{{ $case->case_id }}</div></div>
                    <div class="col-md-2"><small class="text-muted">Project</small><div>{{ $val($case->project_id) }}</div></div>
                    <div class="col-md-2"><small class="text-muted">Slides</small><div><span class="badge badge-warning">{{ $samples->count() }}</span></div></div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-4"><small class="text-muted">Primary Site</small><div>{{ $val($case->primary_site) }}</div></div>
                    <div class="col-md-4"><small class="text-muted">Disease Type</small><div>{{ $val($case->disease_type) }}</div></div>
                    <div class="col-md-4"><small class="text-muted">Data Source</small><div>{{ $val($case->dataSource?->name) }}</div></div>
                </div>
            </div>
        </div>
    </div>
</div>

@if(!$clin)
<div class="alert alert-warning">
    <i class="mdi mdi-alert-outline mr-1"></i>
    No clinical information has been imported yet for this case. Upload a
    <code>clinical.cart.*.json</code> file containing case ID
    <code>{{ $case->case_id }}</code> from the Samples page to attach it.
</div>
@endif

{{-- ── Clinical Tabs ────────────────────────────────────────────────────── --}}
@if($clin)
<div class="row">
    <div class="col-12 grid-margin">
        <div class="card">
            <div class="card-body">
                <ul class="nav nav-tabs" id="caseTabs" role="tablist">
                    <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-demo">Demographic</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-dx">Diagnosis</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-staging">Staging & Pathology</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-tx">Treatments ({{ count($clin->treatments ?? []) }})</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-followup">Follow-ups ({{ count($clin->follow_ups ?? []) }})</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-mol">Molecular Tests ({{ count($clin->molecular_tests ?? []) }})</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-other">Other</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-raw">Raw JSON</a></li>
                </ul>

                <div class="tab-content pt-3">

                    {{-- ─── Demographic ─── --}}
                    <div class="tab-pane fade show active" id="tab-demo">
                        <div class="row">
                            <div class="col-md-3"><small class="text-muted">Gender</small><div>{{ $val($clin->gender) }}</div></div>
                            <div class="col-md-3"><small class="text-muted">Sex at Birth</small><div>{{ $val($clin->sex_at_birth) }}</div></div>
                            <div class="col-md-3"><small class="text-muted">Race</small><div>{{ $val($clin->race) }}</div></div>
                            <div class="col-md-3"><small class="text-muted">Ethnicity</small><div>{{ $val($clin->ethnicity) }}</div></div>

                            <div class="col-md-3 mt-3"><small class="text-muted">Age at Index</small><div>{{ $val($clin->age_at_index) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Days to Birth</small><div>{{ $val($clin->days_to_birth) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Vital Status</small><div>{{ $val($clin->vital_status) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Country</small><div>{{ $val($clin->country_of_residence_at_enrollment) }}</div></div>

                            <div class="col-md-3 mt-3"><small class="text-muted">Consent Type</small><div>{{ $val($clin->consent_type) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Days to Consent</small><div>{{ $val($clin->days_to_consent) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Lost to Follow-up</small><div>{{ $val($clin->lost_to_followup) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Index Date</small><div>{{ $val($clin->index_date) }}</div></div>
                        </div>
                    </div>

                    {{-- ─── Primary Diagnosis ─── --}}
                    <div class="tab-pane fade" id="tab-dx">
                        <div class="row">
                            <div class="col-md-6"><small class="text-muted">Primary Diagnosis</small><div class="font-weight-bold">{{ $val($clin->primary_diagnosis) }}</div></div>
                            <div class="col-md-3"><small class="text-muted">ICD-10</small><div>{{ $val($clin->icd_10_code) }}</div></div>
                            <div class="col-md-3"><small class="text-muted">Morphology</small><div>{{ $val($clin->morphology) }}</div></div>

                            <div class="col-md-6 mt-3"><small class="text-muted">Tissue / Organ of Origin</small><div>{{ $val($clin->tissue_or_organ_of_origin) }}</div></div>
                            <div class="col-md-6 mt-3"><small class="text-muted">Site of Resection / Biopsy</small><div>{{ $val($clin->site_of_resection_or_biopsy) }}</div></div>

                            <div class="col-md-3 mt-3"><small class="text-muted">Method of Diagnosis</small><div>{{ $val($clin->method_of_diagnosis) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Year of Diagnosis</small><div>{{ $val($clin->year_of_diagnosis) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Age at Diagnosis (days)</small><div>{{ $val($clin->age_at_diagnosis) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Days to Last Follow-up</small><div>{{ $val($clin->days_to_last_follow_up) }}</div></div>

                            <div class="col-md-3 mt-3"><small class="text-muted">Laterality</small><div>{{ $val($clin->laterality) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Synchronous Malignancy</small><div>{{ $val($clin->synchronous_malignancy) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Prior Malignancy</small><div>{{ $val($clin->prior_malignancy) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Prior Treatment</small><div>{{ $val($clin->prior_treatment) }}</div></div>

                            <div class="col-md-3 mt-3"><small class="text-muted">Classification</small><div>{{ $val($clin->classification_of_tumor) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Is Primary Disease</small><div>{{ $val($clin->diagnosis_is_primary_disease) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Metastasis at Diagnosis</small><div>{{ $val($clin->metastasis_at_diagnosis) }}</div></div>
                            <div class="col-md-3 mt-3"><small class="text-muted">Days to Diagnosis</small><div>{{ $val($clin->days_to_diagnosis) }}</div></div>

                            @if(!empty($clin->sites_of_involvement))
                            <div class="col-12 mt-3">
                                <small class="text-muted">Sites of Involvement</small>
                                <div>
                                    @foreach($clin->sites_of_involvement as $s)
                                        <span class="badge badge-outline-info mr-1">{{ $s }}</span>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                        </div>

                        @if(count($clin->diagnoses ?? []) > 1)
                            <hr>
                            <h6 class="text-muted">All Diagnoses ({{ count($clin->diagnoses) }})</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr>
                                        <th>Primary Diagnosis</th><th>Tissue/Organ</th><th>Morphology</th>
                                        <th>Classification</th><th>Year</th>
                                    </tr></thead>
                                    <tbody>
                                        @foreach($clin->diagnoses as $dx)
                                        <tr>
                                            <td>{{ $dx['primary_diagnosis'] ?? '—' }}</td>
                                            <td>{{ $dx['tissue_or_organ_of_origin'] ?? '—' }}</td>
                                            <td>{{ $dx['morphology'] ?? '—' }}</td>
                                            <td>{{ $dx['classification_of_tumor'] ?? '—' }}</td>
                                            <td>{{ $dx['year_of_diagnosis'] ?? '—' }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    {{-- ─── Staging & Pathology ─── --}}
                    <div class="tab-pane fade" id="tab-staging">
                        <h6 class="text-primary">AJCC Staging</h6>
                        <div class="row">
                            <div class="col-md-3"><small class="text-muted">Stage</small><div class="font-weight-bold">{{ $val($clin->ajcc_pathologic_stage) }}</div></div>
                            <div class="col-md-2"><small class="text-muted">T</small><div>{{ $val($clin->ajcc_pathologic_t) }}</div></div>
                            <div class="col-md-2"><small class="text-muted">N</small><div>{{ $val($clin->ajcc_pathologic_n) }}</div></div>
                            <div class="col-md-2"><small class="text-muted">M</small><div>{{ $val($clin->ajcc_pathologic_m) }}</div></div>
                            <div class="col-md-3"><small class="text-muted">Edition</small><div>{{ $val($clin->ajcc_staging_system_edition) }}</div></div>
                        </div>
                        <hr>
                        <h6 class="text-primary">Pathology Details</h6>
                        <div class="row">
                            <div class="col-md-3"><small class="text-muted">Lymph Nodes Positive</small><div>{{ $val($clin->lymph_nodes_positive) }}</div></div>
                            <div class="col-md-3"><small class="text-muted">Lymph Nodes Tested</small><div>{{ $val($clin->lymph_nodes_tested) }}</div></div>
                            <div class="col-md-3"><small class="text-muted">Consistent Review</small><div>{{ $val($clin->consistent_pathology_review) }}</div></div>
                            <div class="col-md-3"><small class="text-muted">State</small><div>{{ $val($clin->pathology_detail_state) }}</div></div>
                        </div>
                    </div>

                    {{-- ─── Treatments ─── --}}
                    <div class="tab-pane fade" id="tab-tx">
                        @if(empty($clin->treatments))
                            <p class="text-muted">No treatments recorded.</p>
                        @else
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead><tr>
                                    <th>Type</th><th>Intent</th><th>Therapy?</th>
                                    <th>Anatomic Sites</th><th>Margin</th><th>Outcome</th>
                                    <th>Days Start</th><th>Days End</th><th>Dose</th>
                                </tr></thead>
                                <tbody>
                                @foreach($clin->treatments as $tx)
                                <tr>
                                    <td>{{ $tx['treatment_type'] ?? '—' }}</td>
                                    <td>{{ $tx['treatment_intent_type'] ?? '—' }}</td>
                                    <td>
                                        @if(($tx['treatment_or_therapy'] ?? null) === 'yes')
                                            <span class="badge badge-success">Yes</span>
                                        @elseif(($tx['treatment_or_therapy'] ?? null) === 'no')
                                            <span class="badge badge-secondary">No</span>
                                        @else — @endif
                                    </td>
                                    <td>{{ implode(', ', $tx['treatment_anatomic_sites'] ?? []) ?: '—' }}</td>
                                    <td>{{ $tx['margin_status'] ?? '—' }}</td>
                                    <td>{{ $tx['treatment_outcome'] ?? '—' }}</td>
                                    <td>{{ $tx['days_to_treatment_start'] ?? '—' }}</td>
                                    <td>{{ $tx['days_to_treatment_end'] ?? '—' }}</td>
                                    <td>
                                        @if(isset($tx['treatment_dose']))
                                            {{ $tx['treatment_dose'] }} {{ $tx['treatment_dose_units'] ?? '' }}
                                        @elseif(isset($tx['prescribed_dose']))
                                            {{ $tx['prescribed_dose'] }} {{ $tx['prescribed_dose_units'] ?? '' }}
                                        @else — @endif
                                    </td>
                                </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        @endif
                    </div>

                    {{-- ─── Follow-ups ─── --}}
                    <div class="tab-pane fade" id="tab-followup">
                        @if(empty($clin->follow_ups))
                            <p class="text-muted">No follow-ups recorded.</p>
                        @else
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead><tr>
                                    <th>Timepoint</th><th>Days to Follow-up</th>
                                    <th>Disease Response</th><th>State</th><th>Follow-up ID</th>
                                </tr></thead>
                                <tbody>
                                @foreach($clin->follow_ups as $fu)
                                    @if(isset($fu['timepoint_category']) || isset($fu['days_to_follow_up']))
                                    <tr>
                                        <td>{{ $fu['timepoint_category'] ?? '—' }}</td>
                                        <td>{{ $fu['days_to_follow_up'] ?? '—' }}</td>
                                        <td>{{ $fu['disease_response'] ?? '—' }}</td>
                                        <td>{{ $fu['state'] ?? '—' }}</td>
                                        <td class="small text-muted">{{ $fu['follow_up_id'] ?? '—' }}</td>
                                    </tr>
                                    @endif
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        @endif
                    </div>

                    {{-- ─── Molecular Tests ─── --}}
                    <div class="tab-pane fade" id="tab-mol">
                        @if(empty($clin->molecular_tests))
                            <p class="text-muted">No molecular tests recorded.</p>
                        @else
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead><tr>
                                    <th>Method</th><th>Gene</th><th>Result</th>
                                    <th>Value Range</th><th>Intensity</th><th>Variant</th>
                                    <th>Copy #</th><th>Cell Count</th>
                                </tr></thead>
                                <tbody>
                                @foreach($clin->molecular_tests as $mt)
                                <tr>
                                    <td>{{ $mt['molecular_analysis_method'] ?? '—' }}</td>
                                    <td><strong>{{ $mt['gene_symbol'] ?? '—' }}</strong></td>
                                    <td>
                                        @php $r = $mt['test_result'] ?? null; @endphp
                                        @if($r === 'Positive')<span class="badge badge-danger">{{ $r }}</span>
                                        @elseif($r === 'Negative')<span class="badge badge-success">{{ $r }}</span>
                                        @elseif($r === 'Equivocal')<span class="badge badge-warning">{{ $r }}</span>
                                        @else {{ $r ?? '—' }} @endif
                                    </td>
                                    <td>{{ $mt['test_value_range'] ?? '—' }}</td>
                                    <td>{{ $mt['staining_intensity_value'] ?? '—' }}</td>
                                    <td>{{ $mt['variant_type'] ?? '—' }}</td>
                                    <td>{{ $mt['copy_number'] ?? '—' }}</td>
                                    <td>{{ $mt['cell_count'] ?? '—' }}</td>
                                </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        @endif
                    </div>

                    {{-- ─── Other Clinical Attributes ─── --}}
                    <div class="tab-pane fade" id="tab-other">
                        @if(empty($clin->other_clinical_attributes))
                            <p class="text-muted">No additional clinical attributes recorded.</p>
                        @else
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead><tr><th>Timepoint</th><th>Menopause Status</th><th>State</th><th>ID</th></tr></thead>
                                <tbody>
                                @foreach($clin->other_clinical_attributes as $oc)
                                <tr>
                                    <td>{{ $oc['timepoint_category'] ?? '—' }}</td>
                                    <td>{{ $oc['menopause_status'] ?? '—' }}</td>
                                    <td>{{ $oc['state'] ?? '—' }}</td>
                                    <td class="small text-muted">{{ $oc['other_clinical_attribute_id'] ?? '—' }}</td>
                                </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        @endif
                    </div>

                    {{-- ─── Raw JSON ─── --}}
                    <div class="tab-pane fade" id="tab-raw">
                        <pre style="max-height:500px;overflow:auto;font-size:11px;"
                             class="bg-light p-3 border rounded">{{ json_encode($clin->raw_json, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── Linked Slides ────────────────────────────────────────────────────── --}}
<div class="row">
    <div class="col-12 grid-margin">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title">
                    <i class="mdi mdi-image-multiple-outline mr-1"></i>
                    Linked Slides ({{ $samples->count() }})
                </h4>

                @if($samples->isEmpty())
                    <div class="alert alert-info">
                        No slides linked to this case yet. When a slide whose <code>case_id</code> matches
                        this UUID is imported, it will appear here automatically.
                    </div>
                @else
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Slide ID (entity_submitter_id)</th>
                                <th>Filename</th>
                                <th>File ID</th>
                                <th>Category</th>
                                <th>Stain</th>
                                <th>Size</th>
                                <th>Storage</th>
                                <th>Tiling</th>
                                <th>Quality</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($samples as $s)
                            <tr>
                                <td><strong>{{ $s->entity_submitter_id ?? '—' }}</strong></td>
                                <td class="small">{{ \Illuminate\Support\Str::limit($s->file_name, 50) }}</td>
                                <td class="small text-muted">{{ \Illuminate\Support\Str::limit($s->file_id, 12) }}…</td>
                                <td>{{ $s->category?->label_en ?? '—' }}</td>
                                <td>{{ $s->stain?->name ?? $s->stain_marker ?? '—' }}</td>
                                <td>{{ $s->file_size_gb ? $s->file_size_gb . ' GB' : '—' }}</td>
                                <td>
                                    <span class="badge badge-{{ ['available' => 'success', 'not_downloaded' => 'warning', 'corrupted' => 'danger', 'missing' => 'danger'][$s->storage_status] ?? 'secondary' }}">
                                        {{ str_replace('_', ' ', $s->storage_status) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-{{ ['done' => 'success', 'failed' => 'danger', 'processing' => 'info'][$s->tiling_status] ?? 'secondary' }}">
                                        {{ $s->tiling_status }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-{{ ['passed' => 'success', 'rejected' => 'danger', 'needs_review' => 'warning'][$s->quality_status] ?? 'secondary' }}">
                                        {{ str_replace('_', ' ', $s->quality_status) }}
                                    </span>
                                </td>
                                <td>
                                    <a href="{{ route('admin.samples.show', $s->id) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="mdi mdi-eye-outline"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
