@extends('public.layout')

@section('title', 'API documentation')
@section('description', 'MAIND PATH API reference: the organ, category and disease taxonomy, stains, how incoming slides are classified, the processing pipeline and every endpoint.')

@section('styles')
    .docs{display:grid;grid-template-columns:230px minmax(0,1fr);gap:48px;align-items:start;}
    .toc{position:sticky;top:96px;font-size:14.5px;border-left:2px solid var(--line);padding-left:16px;}
    .toc a{display:block;text-decoration:none;color:var(--muted);padding:4px 0;}
    .toc a:hover{color:var(--brand-dark);}
    .toc strong{display:block;color:var(--ink);font-size:12px;letter-spacing:.1em;text-transform:uppercase;margin-bottom:8px;}
    .docs h2{margin-top:56px;padding-top:8px;scroll-margin-top:90px;}
    .docs h2:first-child{margin-top:0;}
    .docs h3{scroll-margin-top:90px;}
    pre{
        background:#0F1911;color:#DDEBDF;border-radius:10px;padding:16px 18px;overflow-x:auto;
        font:13.5px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;margin:14px 0 20px;
    }
    pre code{background:none;border:0;padding:0;color:inherit;font-size:inherit;word-break:normal;}
    table.ref{border-collapse:collapse;width:100%;margin:16px 0 24px;font-size:14.5px;display:block;overflow-x:auto;}
    table.ref th,table.ref td{border:1px solid var(--line);padding:9px 12px;text-align:left;vertical-align:top;}
    table.ref th{background:var(--wash);color:var(--ink);font-weight:650;white-space:nowrap;}
    table.ref td code{word-break:normal;white-space:nowrap;}
    .ep{border:1px solid var(--line);border-radius:12px;padding:20px 22px;margin:22px 0;}
    .ep h3{margin:0 0 8px;font-size:16.5px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
    .ep h3 code{font-size:15px;color:var(--ink);}
    .verb{font:700 12px/1 ui-monospace,Menlo,Consolas,monospace;padding:5px 8px;border-radius:6px;color:#fff;}
    .verb.get{background:#2A8F21;} .verb.post{background:#1F5FA8;}
    .auth{font-size:12px;font-weight:600;color:var(--muted);border:1px solid var(--line);border-radius:6px;padding:3px 7px;}
    .flow{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:18px 0 22px;font-size:14.5px;}
    .flow span{background:var(--wash);border:1px solid var(--line);border-radius:8px;padding:7px 12px;color:var(--ink);font-weight:600;}
    .flow i{color:var(--muted);font-style:normal;}
    .tree ul{list-style:none;margin:4px 0 4px 10px;padding-left:18px;border-left:1px dashed var(--line);}
    .tree > ul{margin-left:0;padding-left:0;border-left:0;}
    .tree li{margin:5px 0;}
    .node{display:inline-block;border-radius:6px;padding:2px 9px;font-size:14.5px;font-weight:600;}
    .node.organ{background:var(--brand-deep);color:#fff;}
    .node.category{background:#E4F3E6;color:var(--brand-deep);border:1px solid #BFE0C3;}
    .node.disease.leaf{background:#fff;border:1px solid var(--brand);color:var(--ink);}
    .node.disease.branch{background:#fff;border:1px dashed var(--muted);color:var(--muted);}
    .nid{color:var(--muted);font-size:12.5px;margin-left:4px;font-family:ui-monospace,Menlo,Consolas,monospace;}
    .tag{font-size:12px;color:var(--muted);margin-left:6px;}
    .empty{color:var(--muted);font-size:14px;font-style:italic;}
    .organs{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-top:18px;}
    .organs .card{padding:18px 20px;}
    .legend{display:flex;gap:14px;flex-wrap:wrap;font-size:13.5px;margin:10px 0 4px;}
    @media (max-width:900px){
        .docs{grid-template-columns:minmax(0,1fr);gap:0;}
        .toc{position:static;border-left:0;padding-left:0;margin-bottom:28px;columns:2;}
    }
    @media (max-width:520px){ .toc{columns:1;} }
@endsection

@section('body')

<div class="page-head">
    <div class="wrap">
        <h1>API documentation</h1>
        <p class="meta">
            MAIND PATH&trade; platform API &middot; version <code>v1</code> &middot;
            base URL <code>{{ $baseUrl }}/api</code> &middot;
            the taxonomy on this page is read live from the platform database
        </p>
    </div>
</div>

<section>
<div class="wrap docs">

<nav class="toc" aria-label="Contents">
    <strong>Contents</strong>
    <a href="#overview">1. Overview</a>
    <a href="#auth">2. Authentication</a>
    <a href="#tree">3. The classification tree</a>
    <a href="#live-tree">4. Organs, categories, diseases</a>
    <a href="#stains">5. Stains</a>
    <a href="#reference">6. Scan &amp; processing settings</a>
    <a href="#intake">7. How incoming slides are classified</a>
    <a href="#pipeline">8. Pipeline stages &amp; statuses</a>
    <a href="#endpoints">9. Endpoint reference</a>
    <a href="#outbound">10. Calls to GPU servers</a>
    <a href="#labels">11. From taxonomy to model classes</a>
    <a href="#errors">12. Errors &amp; troubleshooting</a>
</nav>

<div class="doc" style="max-width:none">

{{-- ───────────────────────────────────────────────────────────── 1 --}}
<h2 id="overview">1. Overview</h2>
<p>
    The platform receives whole-slide images (WSI) from outside sources such as the GDC/TCGA archive,
    GTEx and direct uploads. It files each slide under a controlled vocabulary, checks the file, cuts
    it into patches, extracts features on GPU servers, and trains or runs diagnosis models on those
    features.
</p>
<p>The API has two audiences:</p>
<ul>
    <li><strong>Systems that label slides before sending them in.</strong> They read the taxonomy
        (<code>/api/v1/taxonomy…</code>) and use <code>/resolve</code> to check that a label will be
        accepted.</li>
    <li><strong>GPU workers</strong> (RunPod feature-extraction, CLAM training and inference servers).
        The platform sends them jobs, and they report back through the callback endpoints.</li>
</ul>
<div class="flow" aria-label="Pipeline">
    <span>Intake</span><i>→</i><span>Classification</span><i>→</i><span>Storage (Drive)</span><i>→</i>
    <span>Verification</span><i>→</i><span>Patch extraction</span><i>→</i><span>Feature extraction</span><i>→</i>
    <span>Training / Diagnosis</span>
</div>
<p>
    All responses are JSON. Send <code>Accept: application/json</code> so that validation errors come
    back as JSON instead of a redirect. Timestamps are ISO-8601.
</p>

{{-- ───────────────────────────────────────────────────────────── 2 --}}
<h2 id="auth">2. Authentication</h2>
<p>
    Every endpoint under <code>/api/v1</code> requires a server API key. Keys belong to server records
    (admin → Settings → Servers). Only keys on <strong>active</strong> servers are accepted. Send the key
    in either header:
</p>
<pre><code>Authorization: Bearer &lt;api_key&gt;
X-API-Key: &lt;api_key&gt;</code></pre>
<p>
    Keys are compared in constant time. The server that owns the key is recorded against the calls it
    makes; for example, a feature-extraction report is stamped with the reporting server.
</p>
<table class="ref">
    <tr><th>Status</th><th>Body</th><th>Meaning</th></tr>
    <tr><td><code>401</code></td><td><code>{"success":false,"message":"Missing Authorization Bearer token."}</code></td><td>No key was sent.</td></tr>
    <tr><td><code>403</code></td><td><code>{"success":false,"message":"Invalid API key."}</code></td><td>The key is unknown, or its server is inactive.</td></tr>
</table>
<p><code>GET /api/health</code> is the only endpoint that works without a key. Use it to test connectivity.</p>

{{-- ───────────────────────────────────────────────────────────── 3 --}}
<h2 id="tree">3. The classification tree</h2>
<p>Every slide is filed under a three-level tree. The third level can nest further:</p>
<div class="flow">
    <span>Organ</span><i>→</i><span>Category (clinical group)</span><i>→</i><span>Disease</span><i>→</i><span>finer disease …</span>
    <i>(up to {{ $maxDepth }} disease levels)</i>
</div>
<table class="ref">
    <tr><th>Level</th><th>Table</th><th>What it is</th><th>Identity rule</th></tr>
    <tr>
        <td>Organ</td><td><code>organs</code></td>
        <td>The anatomical site; the root of the tree. Organs are curated by an administrator and
            only ever <em>selected</em> elsewhere, never typed in, so a disease cannot end up under the
            wrong site.</td>
        <td>Name is unique.</td>
    </tr>
    <tr>
        <td>Category</td><td><code>categories</code></td>
        <td>A clinical group inside one organ, for example <em>Tumor</em> or <em>Normal</em>. Because
            the group belongs to its organ, "Tumor" of the breast and "Tumor" of the lung are two
            separate groups.</td>
        <td><code>UNIQUE(organ_id, label_en)</code></td>
    </tr>
    <tr>
        <td>Disease</td><td><code>disease_subtypes</code></td>
        <td>A disease entity inside a category. A disease can hold finer diseases through
            <code>parent_id</code>; for example, <em>Malignant</em> can be refined into
            <em>IDC</em> and <em>ILC</em>.</td>
        <td><code>UNIQUE(organ_id, name)</code> at every depth: within one organ, a disease name
            identifies exactly one clinical entity.</td>
    </tr>
</table>

<h3>Rules the platform enforces</h3>
<ul>
    <li><strong>The leaf rule.</strong> A slide must be labelled with the most specific disease
        available. If a disease has finer diseases under it, it cannot be chosen. Detail that was
        never captured cannot be recovered later. The error names the choices, for example:
        <code>"Malignant" is refined further — pick one of: IDC, ILC.</code></li>
    <li><strong>Organ boundary.</strong> A category belongs to one organ. A disease takes its
        <code>organ_id</code> from its category and never sets it on its own. If a category is moved
        to another organ, its diseases move with it. The move is refused if the category already has
        slides, or if one of its disease names already exists in the destination organ.</li>
    <li><strong>Depth limit.</strong> Diseases nest at most {{ $maxDepth }} levels deep, where a root
        disease is depth 1.</li>
    <li><strong>Deletion guards.</strong> The platform refuses to delete an organ, category, disease or
        stain that slides still use. It also refuses to delete a category that still has diseases, or
        a disease that still has children.</li>
    <li><strong>Renames carry through.</strong> Renaming a disease rewrites the denormalised
        <code>samples.disease_subtype</code> text on every slide filed under it.</li>
    <li><strong>Inactive rows</strong> stay in the tree so that historical slides keep their labels,
        but they are hidden from pickers. <code>/resolve</code> accepts an inactive row and adds a
        warning.</li>
</ul>
<p>
    Each row has a qualified name that reads as a path, for example
    <code>Breast › Tumor › Malignant › IDC</code>. The <code>/resolve</code> endpoint returns this path.
</p>

{{-- ───────────────────────────────────────────────────────────── 4 --}}
<h2 id="live-tree">4. Organs, categories and diseases (live)</h2>
<p>
    This is the current tree for active organs, generated from the database when the page loads.
    <code>GET /api/v1/taxonomy</code> returns the same data as JSON. The number after each name is its
    id, which the API accepts in place of the name.
</p>
<div class="legend">
    <span><span class="node organ">Organ</span></span>
    <span><span class="node category">Category</span></span>
    <span><span class="node disease leaf">Disease — selectable</span></span>
    <span><span class="node disease branch">Disease — refined further</span></span>
</div>

@php
    $withGroups    = array_values(array_filter($tree, fn ($o) => $o['categories'] !== []));
    $withoutGroups = array_values(array_filter($tree, fn ($o) => $o['categories'] === []));
@endphp

<div class="organs">
@forelse ($withGroups as $organ)
    <div class="card tree">
        <ul>
            <li>
                <span class="node organ">{{ $organ['name'] }}</span><span class="nid">#{{ $organ['id'] }}</span>
                <ul>
                    @foreach ($organ['categories'] as $category)
                        <li>
                            <span class="node category">{{ $category['label'] }}</span><span class="nid">#{{ $category['id'] }}</span>
                            @if ($category['diseases'] === [])
                                <div class="empty">No diseases yet. Slides are filed at group level.</div>
                            @else
                                <ul>
                                    @foreach ($category['diseases'] as $node)
                                        @include('public.partials.disease-node', ['node' => $node])
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </li>
        </ul>
    </div>
@empty
    <p class="empty">No organ has categories defined yet.</p>
@endforelse
</div>

@if ($withoutGroups !== [])
    <h3>Organs with no categories yet</h3>
    <p>
        These organs exist and can be chosen, but no clinical groups are defined under them yet, so a
        slide cannot be filed against them until an administrator adds one:
    </p>
    <p>
        @foreach ($withoutGroups as $organ)
            <code>{{ $organ['name'] }} #{{ $organ['id'] }}</code>@if (! $loop->last) &nbsp;@endif
        @endforeach
    </p>
@endif

@if ($unrooted !== [])
    <h3>Categories without an organ</h3>
    <p>
        These groups were created before organs became mandatory. A slide cannot be filed against them
        until they are assigned to an organ. <code>/resolve</code> will not find them.
    </p>
    <p>
        @foreach ($unrooted as $c)
            <code>{{ $c['label'] }} #{{ $c['id'] }}</code>@if (! $loop->last) &nbsp;@endif
        @endforeach
    </p>
@endif

{{-- ───────────────────────────────────────────────────────────── 5 --}}
<h2 id="stains">5. Stains</h2>
<p>
    The stain says how the tissue was prepared. It is recorded next to the tree, not inside it. A stain
    can be matched by id, by abbreviation or by full name, ignoring case. For immunohistochemistry
    stains, the <code>marker</code> column names the target protein.
</p>
<table class="ref">
    <tr><th>ID</th><th>Abbreviation</th><th>Name</th><th>Type</th><th>Marker</th></tr>
    @forelse ($stains as $s)
        <tr>
            <td>{{ $s['id'] }}</td>
            <td><code>{{ $s['abbreviation'] }}</code></td>
            <td>{{ $s['name'] }}</td>
            <td>{{ $s['type_label'] }}</td>
            <td>{{ $s['marker'] ?? '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="empty">No stains defined.</td></tr>
    @endforelse
</table>
<p>
    Allowed types: <code>routine</code>, <code>special</code>, <code>IHC</code>, <code>ISH</code>,
    <code>fluorescent</code>, <code>cytology</code>, <code>other</code>. Names and abbreviations are
    unique.
</p>
<p>
    <strong>How a slide's stain is set.</strong> A stain chosen at upload is kept. Otherwise:
</p>
<ul>
    <li>Slides from TCGA and GTEx projects are assumed to be H&amp;E during verification.</li>
    <li>If the slide file names a stain, verification reads it with OpenSlide. A stain name the
        platform has never seen is added automatically as a <code>routine</code> stain, so it should be
        reviewed afterwards.</li>
</ul>

{{-- ───────────────────────────────────────────────────────────── 6 --}}
<h2 id="reference">6. Scan and processing settings</h2>
<p>
    These settings decide how a slide is processed. They also decide which models can read its features:
    a model only accepts features made with the same patch size, magnification and feature extractor it
    was trained on. <code>GET /api/v1/taxonomy/reference</code> returns all three tables below.
</p>

<h3>Magnifications</h3>
<table class="ref">
    <tr><th>ID</th><th>Label</th><th>Value</th><th>Folder name</th><th>Active</th></tr>
    @foreach ($reference['magnifications'] as $m)
        <tr><td>{{ $m['id'] }}</td><td>{{ $m['label'] }}</td><td>{{ $m['value'] }}×</td><td><code>{{ $m['folder_name'] }}</code></td><td>{{ $m['is_active'] ? 'yes' : 'no' }}</td></tr>
    @endforeach
</table>

<h3>Patch sizes</h3>
<table class="ref">
    <tr><th>ID</th><th>Patch</th><th>Overlap</th><th>WSI level</th><th>Active</th></tr>
    @foreach ($reference['patch_sizes'] as $p)
        <tr><td>{{ $p['id'] }}</td><td>{{ $p['size_px'] }} × {{ $p['size_px'] }} px</td><td>{{ $p['overlap_px'] }} px</td><td>{{ $p['wsi_level'] }}</td><td>{{ $p['is_active'] ? 'yes' : 'no' }}</td></tr>
    @endforeach
</table>

<h3>Data sources</h3>
<table class="ref">
    <tr><th>ID</th><th>Name</th><th>Full name</th></tr>
    @foreach ($reference['data_sources'] as $d)
        <tr><td>{{ $d['id'] }}</td><td><code>{{ $d['name'] }}</code></td><td>{{ $d['full_name'] ?: '—' }}</td></tr>
    @endforeach
</table>

{{-- ───────────────────────────────────────────────────────────── 7 --}}
<h2 id="intake">7. How incoming slides are classified</h2>
<p>
    Slides enter the platform in several ways. They do not all carry the same information, so the table
    below shows which part of the classification each one sets. Every path is idempotent: each slide is
    identified by a natural key, so running an import again updates rows instead of duplicating them.
</p>
<table class="ref">
    <tr><th>Entry point</th><th>Identified by</th><th>Sets organ / category / disease?</th><th>Notes</th></tr>
    <tr>
        <td>GDC manifest TSV<br><small>admin → Imports</small></td>
        <td><code>file_id</code> (GDC UUID)</td>
        <td>No</td>
        <td>Creates or updates slide rows with file name, md5, size, state and data source. The
            barcode is read from the file name.</td>
    </tr>
    <tr>
        <td>GDC <code>metadata.cart.*.json</code></td>
        <td><code>file_id</code>; case by GDC case UUID</td>
        <td>No</td>
        <td>Adds entity ids, format and access level, and links each slide to its case.</td>
    </tr>
    <tr>
        <td>GDC <code>clinical.cart.*.json</code>, clinical CSV, cohort JSON</td>
        <td>Case UUID; slides matched by file name, then <code>file_id</code></td>
        <td>No</td>
        <td>Writes the patient's clinical record. Only links to slides that already exist; it never
            creates slides.</td>
    </tr>
    <tr>
        <td><code>php artisan tcga:import-slides</code></td>
        <td><code>file_id</code></td>
        <td><strong>Yes</strong>, all of it</td>
        <td>Organ, category, disease and data source are named on the command line
            (<code>--organ=Breast --category=tumor --subtype=IDC --source=TCGA-BRCA</code>). The command
            refuses to run if any of them does not exist. It downloads with gdc-client, checks size and
            md5, uploads to Drive, then writes the row. The stain is the first stain row, H&amp;E.</td>
    </tr>
    <tr>
        <td><code>php artisan gtex:import-manifest</code></td>
        <td>GTEx donor id</td>
        <td>Case organ only</td>
        <td>The tissue <code>"Breast - Mammary Tissue"</code> becomes organ <code>Breast</code> on the
            case, taken from the text before the first dash. Writes demographics and the Hardy death
            scale. Only donors with a matching slide are imported.</td>
    </tr>
    <tr>
        <td>Manual upload<br><small>admin → Samples</small></td>
        <td><code>file_name</code> / Drive id / GDC folder UUID</td>
        <td><strong>Yes</strong>, chosen in the form</td>
        <td>Organ is required. The category must belong to that organ. The disease must belong to that
            organ and pass the leaf rule.</td>
    </tr>
    <tr>
        <td>AI workflow intake<br><small>admin → AI workflow</small></td>
        <td>varies</td>
        <td>Fixed to Breast › tumor, TCGA-BRCA</td>
        <td>Built for the IDC vs ILC model, so the organ, category and source are fixed.</td>
    </tr>
</table>
<div class="callout">
    <p>
        <strong>Before sending a slide in from an outside system</strong>, call
        <code>GET /api/v1/taxonomy/resolve</code> with the organ, category, disease and stain you plan to
        use. If it returns <code>200</code>, the label will be accepted. If it returns <code>422</code>,
        the <code>errors</code> array says exactly which part is wrong and what the valid choices are.
    </p>
</div>

<h3>Linking a slide to its patient case</h3>
<p>
    A slide is linked to its case by the patient barcode. The platform tries
    <code>entity_submitter_id</code> first, then the file name.
</p>
<ul>
    <li><strong>TCGA:</strong> the first three barcode segments name the patient
        (<code>TCGA-AC-A23C</code>-01Z-00-DX1 → <code>TCGA-AC-A23C</code>).</li>
    <li><strong>GTEx:</strong> the first two segments name the donor
        (<code>GTEX-1117F</code>-0126 → <code>GTEX-1117F</code>).</li>
</ul>
<p>
    Linking works in both directions: a case that arrives after its slides picks up those orphan slides.
    Changing a slide's case, organ, category or disease queues a new verification.
</p>

<h3>Refining labels from the clinical record</h3>
<p>
    A slide imported with only a coarse label, such as "Malignant", often has its exact diagnosis in
    the patient's GDC clinical record. <code>php artisan taxonomy:refile-from-clinical</code> moves such
    slides down to the matching leaf disease. It is a dry run unless <code>--apply</code> is passed. It
    matches on the ICD-O-3 morphology code, not the free-text diagnosis, because the code is a
    controlled vocabulary:
</p>
<table class="ref">
    <tr><th>ICD-O-3</th><th>Meaning</th><th>Filed as</th></tr>
    <tr><td><code>8500/3</code></td><td>Infiltrating duct carcinoma, NOS</td><td><code>IDC</code></td></tr>
    <tr><td><code>8520/3</code></td><td>Infiltrating lobular carcinoma, NOS</td><td><code>ILC</code></td></tr>
</table>
<p>A slide is only moved when all of these hold:</p>
<ul>
    <li>the target disease exists in the slide's own organ <em>and</em> its own category;</li>
    <li>the barcode's sample type is a tumour (<code>01</code>–<code>09</code>), not adjacent normal
        tissue (<code>10</code>–<code>19</code>). The diagnosis belongs to the patient, not to every
        slide the patient has;</li>
    <li>the slide currently has no disease, or has a coarser <em>ancestor</em> of the target. A slide
        already filed under a different leaf is reported as a conflict and never overwritten.</li>
</ul>
<p>
    Mixed tumours (<code>8522/3</code>, <code>8523/3</code>, <code>8524/3</code>) are distinct entities
    and need a pathologist's decision, so they are reported and left unchanged.
</p>

<h3>Verification</h3>
<p>
    Every slide is checked before it can be used. The checks are: can OpenSlide open the file, is the
    file intact, and can a region be read. The platform also checks that the slide has at least 2
    pyramid levels, is at least 1024 px on each side, was scanned at 20× or more, and has a valid
    microns-per-pixel value. It needs enough tissue (at least 10 % of the area or 50 patches) and a
    usable label. The clinical fields patient id, case id, project, gender and age at index must be
    present. A slide is <code>failed</code> if any check fails, <code>needs_clinical_info</code> if only
    clinical fields are missing, and otherwise <code>passed</code> or <code>pending</code>. Blur,
    artefact and background scores only produce warnings.
</p>

{{-- ───────────────────────────────────────────────────────────── 8 --}}
<h2 id="pipeline">8. Pipeline stages and statuses</h2>
<table class="ref">
    <tr><th>Stage</th><th>Column</th><th>Values</th><th>Driven by</th></tr>
    <tr><td>Storage</td><td><code>storage_status</code></td>
        <td><code>not_downloaded</code> · <code>downloading</code> · <code>verifying</code> · <code>available</code> · <code>corrupted</code> · <code>missing</code> · <code>upload_failed</code></td>
        <td>Upload to Google Drive</td></tr>
    <tr><td>Verification</td><td><code>slide_verifications.verification_status</code></td>
        <td><code>pending</code> · <code>passed</code> · <code>failed</code> · <code>needs_clinical_info</code></td>
        <td>Verification job</td></tr>
    <tr><td>Quality</td><td><code>quality_status</code></td>
        <td><code>pending</code> · <code>passed</code> · <code>rejected</code> · <code>needs_review</code> · <code>needs_clinical_info</code></td>
        <td>Mirrors verification</td></tr>
    <tr><td>Patches</td><td><code>tiling_status</code></td>
        <td><code>pending</code> · <code>processing</code> · <code>done</code> · <code>failed</code></td>
        <td>Patch extraction on the platform host</td></tr>
    <tr><td>Features</td><td><code>feature_extraction_status</code></td>
        <td><code>pending</code> · <code>processing</code> · <code>completed</code> · <code>failed</code></td>
        <td>GPU server → <code>POST /api/v1/feature-extraction/report</code></td></tr>
    <tr><td>Training run</td><td><code>training_runs.status</code></td>
        <td><code>pending</code> · <code>processing</code> · <code>completed</code> · <code>failed</code> · <code>cancelled</code></td>
        <td>CLAM server → <code>/api/v1/training/*</code></td></tr>
    <tr><td>Inference run</td><td><code>inference_runs.status</code></td>
        <td><code>pending</code> · <code>processing</code> · <code>completed</code> · <code>failed</code></td>
        <td>CLAM server → <code>/api/v1/inference/*</code></td></tr>
</table>
<p>
    A slide can be used for training only when its features are <code>completed</code>, its quality is
    <code>passed</code>, its verification is <code>passed</code>, and it is marked usable.
</p>
<p>Drive paths follow the tree, so files are laid out by source, organ and group:</p>
<pre><code>samples/{source}/{organ}/{category}/{file_id}/{file_name}
sliced_slides/{magnification}/{source}/{organ}/{category}/{case}/sample_{id}_{px}px/patches.tar.gz
features/{model}/{magnification}/{source}/{organ}/{category}/{case}/sample_{id}_{px}px/</code></pre>

{{-- ───────────────────────────────────────────────────────────── 9 --}}
<h2 id="endpoints">9. Endpoint reference</h2>

<h3 style="margin-top:8px">Health</h3>

<div class="ep" id="ep-health">
    <h3><span class="verb get">GET</span><code>/api/health</code><span class="auth">no auth</span></h3>
    <p>Checks connectivity. Needs no key.</p>
<pre><code>curl {{ $baseUrl }}/api/health

{"success":true,"service":"histopathology-management-api","time":"2026-09-24T10:00:00+00:00"}</code></pre>
</div>

<div class="ep">
    <h3><span class="verb get">GET</span><code>/api/v1/health</code><span class="auth">API key</span></h3>
    <p>Checks that your key works, and returns the name of the server the key belongs to.</p>
<pre><code>curl -H "Authorization: Bearer $KEY" {{ $baseUrl }}/api/v1/health

{"success":true,"server":"runpod-titan-1","time":"2026-09-24T10:00:00+00:00"}</code></pre>
</div>

<h3>Taxonomy (read-only)</h3>

<div class="ep" id="ep-taxonomy">
    <h3><span class="verb get">GET</span><code>/api/v1/taxonomy</code><span class="auth">API key</span></h3>
    <p>Returns the full tree: organ → categories → diseases, nested to any depth.</p>
    <table class="ref">
        <tr><th>Query</th><th>Type</th><th>Description</th></tr>
        <tr><td><code>organ</code></td><td>name or id</td><td>Return only this organ. Unknown organ → <code>404</code>.</td></tr>
        <tr><td><code>include_inactive</code></td><td>boolean</td><td>Include inactive organs, categories and diseases.</td></tr>
    </table>
<pre><code>curl -H "Authorization: Bearer $KEY" "{{ $baseUrl }}/api/v1/taxonomy?organ=Breast"

{
  "success": true,
  "levels": ["organ", "category", "disease"],
  "max_disease_depth": {{ $maxDepth }},
  "organs": [{
    "id": 1, "name": "Breast", "is_active": true,
    "categories": [{
      "id": 4, "label": "Tumor", "is_active": true,
      "diseases": [{
        "id": 7, "name": "Malignant", "depth": 1, "is_leaf": false, "is_active": true,
        "children": [
          {"id": 11, "name": "IDC", "depth": 2, "is_leaf": true, "is_active": true, "children": []},
          {"id": 12, "name": "ILC", "depth": 2, "is_leaf": true, "is_active": true, "children": []}
        ]
      }]
    }]
  }],
  "unrooted_categories": []
}</code></pre>
    <p>
        <code>is_leaf</code> counts inactive children too, which is how the leaf rule counts them. Only
        diseases with <code>is_leaf: true</code> can be used to label a slide.
        <code>unrooted_categories</code> lists groups with no organ; they cannot be used.
    </p>
</div>

<div class="ep">
    <h3><span class="verb get">GET</span><code>/api/v1/taxonomy/organs</code><span class="auth">API key</span></h3>
    <p>Returns a flat list of organs, with the number of categories and diseases under each.
        Accepts <code>include_inactive</code>.</p>
<pre><code>{"success":true,"organs":[{"id":1,"name":"Breast","is_active":true,"categories_count":2,"diseases_count":3}, …]}</code></pre>
</div>

<div class="ep">
    <h3><span class="verb get">GET</span><code>/api/v1/taxonomy/stains</code><span class="auth">API key</span></h3>
    <p>Returns the stain vocabulary (section 5). Accepts <code>include_inactive</code>.</p>
<pre><code>{"success":true,"stains":[{"id":1,"name":"Hematoxylin &amp; Eosin","abbreviation":"H&amp;E","type":"routine",
  "type_label":"Routine","marker":null,"description":"…","is_active":true}, …]}</code></pre>
</div>

<div class="ep">
    <h3><span class="verb get">GET</span><code>/api/v1/taxonomy/reference</code><span class="auth">API key</span></h3>
    <p>Returns magnifications, patch sizes and data sources (section 6).</p>
<pre><code>{"success":true,
 "magnifications":[{"id":2,"label":"x20","value":20,"folder_name":"20x","is_active":true}, …],
 "patch_sizes":[{"id":1,"size_px":224,"overlap_px":0,"wsi_level":0,"label":"224×224 px","ai_model_id":1,"is_active":true}, …],
 "data_sources":[{"id":1,"name":"TCGA-BRCA","full_name":"…","base_url":null,"is_active":true}, …]}</code></pre>
</div>

<div class="ep" id="ep-resolve">
    <h3><span class="verb get">GET</span><code>/api/v1/taxonomy/resolve</code><span class="auth">API key</span></h3>
    <p>
        Checks a label and turns names into ids. It applies the same rules the platform uses when it
        files a slide, and it writes nothing. Each parameter accepts a name (ignoring case) or an id.
    </p>
    <table class="ref">
        <tr><th>Query</th><th>Required</th><th>Matched against</th></tr>
        <tr><td><code>organ</code></td><td>yes</td><td>organ name</td></tr>
        <tr><td><code>category</code></td><td>yes</td><td>category label, only within that organ</td></tr>
        <tr><td><code>disease</code></td><td>no</td><td>disease name within that organ. It must sit in the given category and be a leaf.</td></tr>
        <tr><td><code>stain</code></td><td>no</td><td>abbreviation, then full name</td></tr>
    </table>
<pre><code>curl -G -H "Authorization: Bearer $KEY" {{ $baseUrl }}/api/v1/taxonomy/resolve \
     --data-urlencode organ=Breast --data-urlencode category=Tumor \
     --data-urlencode disease=IDC  --data-urlencode "stain=H&amp;E"

HTTP 200
{
  "success": true,
  "valid": true,
  "classification": {
    "organ":    {"id": 1, "name": "Breast"},
    "category": {"id": 4, "label": "Tumor"},
    "disease":  {"id": 11, "name": "IDC", "parent_id": 7, "depth": 2, "is_leaf": true, "path": ["Malignant", "IDC"]},
    "stain":    {"id": 1, "name": "Hematoxylin &amp; Eosin", "abbreviation": "H&amp;E"}
  },
  "qualified_name": "Breast › Tumor › Malignant › IDC",
  "errors": [],
  "warnings": []
}</code></pre>
    <p>An invalid label returns <code>422</code> with <code>valid: false</code>. Whatever did resolve is
        still returned, and every problem is listed in <code>errors</code>:</p>
<pre><code>GET /api/v1/taxonomy/resolve?organ=Breast&amp;category=Tumor&amp;disease=Malignant

HTTP 422
{"success":true,"valid":false, …,
 "errors":["\"Malignant\" is refined further — pick one of: IDC, ILC."],"warnings":[]}</code></pre>
    <table class="ref">
        <tr><th>Error</th><th>Cause</th></tr>
        <tr><td><code>organ is required.</code> / <code>category is required.</code></td><td>The parameter is missing.</td></tr>
        <tr><td><code>No organ matches "…".</code></td><td>The organ is unknown.</td></tr>
        <tr><td><code>Organ "X" has no category "…" — choose one of: …</code></td><td>The category exists only in another organ, or not at all.</td></tr>
        <tr><td><code>Organ "X" has no disease "…".</code></td><td>The disease name is unknown in that organ.</td></tr>
        <tr><td><code>Disease "D" belongs to category "A", not "B".</code></td><td>The disease is in the right organ but a different group.</td></tr>
        <tr><td><code>"D" is refined further — pick one of: …</code></td><td>Leaf rule: choose a finer disease.</td></tr>
        <tr><td><code>No stain matches "…".</code></td><td>The stain is unknown.</td></tr>
    </table>
    <p>
        Warnings do not make a label invalid. They report inactive rows, and the case where no disease
        was given although the category defines diseases, so the slide would be filed at group level
        only.
    </p>
</div>

<h3>Feature extraction callbacks</h3>

<div class="ep">
    <h3><span class="verb post">POST</span><code>/api/v1/feature-extraction/report</code><span class="auth">API key</span></h3>
    <p>The GPU worker reports progress on the job it was given (section 10). Send one call each time the
        status changes.</p>
    <table class="ref">
        <tr><th>Field</th><th>Type</th><th>Notes</th></tr>
        <tr><td><code>sample_id</code></td><td>integer, required</td><td>Must exist.</td></tr>
        <tr><td><code>status</code></td><td>required</td><td><code>processing</code> · <code>completed</code> · <code>failed</code></td></tr>
        <tr><td><code>slide_id</code></td><td>string</td><td>File name, for logs.</td></tr>
        <tr><td><code>features_gdrive_path</code>, <code>features_gdrive_folder_id</code>, <code>runpod_output_path</code></td><td>string</td><td>Where the features were written. Stored on <code>completed</code>.</td></tr>
        <tr><td><code>patch_count</code>, <code>failed_patch_count</code></td><td>integer ≥ 0</td><td>Stored on <code>completed</code>.</td></tr>
        <tr><td><code>model_name</code>, <code>model_version</code></td><td>string</td><td>The version is checked later against model requirements, for example <code>TITAN</code>.</td></tr>
        <tr><td><code>error_message</code></td><td>string ≤ 2000</td><td>Stored on <code>failed</code>.</td></tr>
    </table>
<pre><code>curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -H "Accept: application/json" \
  {{ $baseUrl }}/api/v1/feature-extraction/report -d '{
    "sample_id": 42, "slide_id": "TCGA-AC-A23C-01Z-00-DX1.svs", "status": "completed",
    "features_gdrive_path": "features/TITAN/20x/TCGA-BRCA/Breast/Tumor/…/sample_42_224px",
    "patch_count": 5312, "failed_patch_count": 0, "model_name": "TITAN", "model_version": "TITAN-v1"
  }'

{"success":true,"message":"Status updated.","sample":{"id":42,"status":"completed"}}</code></pre>
</div>

<div class="ep">
    <h3><span class="verb get">GET</span><code>/api/v1/feature-extraction/jobs/{sample}</code><span class="auth">API key</span></h3>
    <p>Returns the platform's current record of a slide's feature extraction. A worker can use it to
        resume after a restart.</p>
<pre><code>{"success":true,"sample":{"id":42,"slide_id":"TCGA-….svs","feature_extraction_status":"completed",
  "feature_extraction_completed_at":"…","features_gdrive_path":"…","features_gdrive_folder_id":null,
  "features_runpod_path":null,"features_patch_count":5312,"features_failed_patch_count":0,
  "features_model_version":"TITAN-v1","feature_extraction_error":null}}</code></pre>
</div>

<div class="ep">
    <h3><span class="verb post">POST</span><code>/api/v1/servers/{serverId}/update-url</code><span class="auth">API key</span></h3>
    <p>A worker registers its own public URL when it boots. Body: <code>{"api_url":"https://…"}</code>.
        The URL is always written to the server that owns the key; <code>{serverId}</code> in the path
        is ignored, so one server can never overwrite another's URL.</p>
</div>

<h3>Training callbacks</h3>

<div class="ep">
    <h3><span class="verb post">POST</span><code>/api/v1/training/progress</code><span class="auth">API key</span></h3>
    <p>Sent once per epoch. Each call is appended to <code>metrics.history</code>, and the run is marked
        <code>processing</code>.</p>
<pre><code>{"run_id": 17, "epoch": 3, "total_epochs": 50,
 "metrics": {"train_loss": 0.41, "val_loss": 0.47, "val_auc": 0.91}}</code></pre>
</div>

<div class="ep">
    <h3><span class="verb post">POST</span><code>/api/v1/training/report</code><span class="auth">API key</span></h3>
    <p>The final result. <code>status</code> is one of <code>completed</code> · <code>failed</code> ·
        <code>cancelled</code>. If <code>metrics</code> is omitted, the existing metrics are kept.</p>
<pre><code>{"run_id": 17, "status": "completed", "metrics": {…},
 "model_path": "training/CLAM/run_17/best_model.pt", "error": null}</code></pre>
    <p>Both training endpoints return <code>{"ok":true}</code>, or <code>404 {"error":"Run not found"}</code>.</p>
</div>

<h3>Inference callbacks</h3>

<div class="ep">
    <h3><span class="verb post">POST</span><code>/api/v1/inference/progress</code><span class="auth">API key</span></h3>
    <p>Optional progress messages. The first one moves the run from <code>pending</code> to
        <code>processing</code>.</p>
<pre><code>{"inference_run_id": 5, "stage": "loading_features", "message": "Loading .h5 features from Drive"}</code></pre>
</div>

<div class="ep">
    <h3><span class="verb post">POST</span><code>/api/v1/inference/report</code><span class="auth">API key</span></h3>
    <p>The final result. <code>status</code> is <code>completed</code> or <code>failed</code>.
        <code>class_label</code> is a label from the training run's <code>label_map</code>.</p>
<pre><code>{"inference_run_id": 5, "status": "completed",
 "prediction": {"class_label": "IDC", "class_index": 0, "confidence": 0.923,
                "probabilities": {"0": 0.923, "1": 0.077}},
 "attention_map_path": "inference/results/run_5/attention.png", "error": null}</code></pre>
    <p>Both inference endpoints return <code>{"ok":true}</code>, or <code>404 {"error":"Inference run not found"}</code>.</p>
</div>

{{-- ───────────────────────────────────────────────────────────── 10 --}}
<h2 id="outbound">10. Calls the platform makes to GPU servers</h2>
<p>
    A GPU worker must serve these endpoints. The platform calls them with the worker's own key as the
    Bearer token. Every worker must answer <code>GET /health</code> without authentication. The platform
    calls <code>/health</code> before each dispatch: if it is unhealthy, the job is retried after 60 s;
    if it is unreachable, after 90 s.
</p>

<h3>Feature extraction worker</h3>
<table class="ref">
    <tr><th>Call</th><th>Purpose</th></tr>
    <tr><td><code>POST {worker}/jobs/start</code></td><td>Start one slide. The worker replies <code>{"status":"accepted","job_id":"…"}</code>, and the platform keeps the <code>job_id</code>.</td></tr>
    <tr><td><code>GET {worker}/jobs/{job_id}</code></td><td>Used to resume a stalled operation. <code>queued</code> or <code>running</code> means the job is still alive; any other answer re-queues it.</td></tr>
</table>
<pre><code>POST {worker}/jobs/start
{
  "sample_id": 42,
  "slide_id": "TCGA-AC-A23C-01Z-00-DX1.svs",
  "patch_size_px": 224,
  "magnification": "x20", "magnification_folder": "20x",
  "gdrive_input_path": "sliced_slides/20x/TCGA-BRCA/Breast/Tumor/…/sample_42_224px",
  "gdrive_input_archive": "patches.tar.gz",
  "gdrive_output_path": "features/TITAN/20x/TCGA-BRCA/Breast/Tumor/…/sample_42_224px",
  "ai_model": {"id": 1, "name": "TITAN", "slug": "…", "huggingface": "…", "version": "v1",
               "embedding_dim": "768", "input_resolution": "512x512"},
  "callback": {"url": "{{ $baseUrl }}/api/v1/feature-extraction/report", "token": "&lt;api_key&gt;", "method": "POST"},
  "dispatched_at": "2026-09-24T10:00:00+00:00"
}</code></pre>
<p>A <code>401</code> or <code>403</code> from the worker fails the job at once. A <code>404</code> or
    <code>5xx</code> is retried.</p>

<h3>CLAM training and inference server</h3>
<p>This server's <code>/health</code> must report <code>"service":"clam_training"</code>. This stops a
    training run from being sent to a feature-extraction worker.</p>
<table class="ref">
    <tr><th>Call</th><th>Body (main fields)</th></tr>
    <tr><td><code>POST {server}/training/start</code></td>
        <td><code>run_id</code>, <code>feature_model</code>, <code>samples_train</code> / <code>samples_val</code> / <code>samples_test</code>
            (each <code>[{sample_id, label, training_phase, gdrive_features_path, parent_label?}]</code>),
            <code>training_params</code> (<code>model_type</code>, <code>epochs</code>, <code>learning_rate</code>, <code>bag_size</code>, <code>n_classes</code>,
            <code>use_class_weights</code>, <code>seed</code>, <code>dropout</code>, …, plus <code>n_parent_classes</code>, <code>hier_weight</code>,
            <code>child_to_parent</code> for hierarchical runs), <code>label_map</code>, <code>parent_label_map</code>, <code>gdrive_output_dir</code></td></tr>
    <tr><td><code>POST {server}/inference/start</code></td>
        <td><code>inference_run_id</code>, <code>model_checkpoint_path</code>, <code>feature_model</code>, <code>slide_features_path</code>,
            <code>slide_name</code>, <code>n_classes</code>, <code>model_type</code>, <code>label_map</code>, <code>gdrive_output_dir</code></td></tr>
</table>

{{-- ───────────────────────────────────────────────────────────── 11 --}}
<h2 id="labels">11. From taxonomy to model classes</h2>
<p>A training run turns the tree into class labels, in one of three ways:</p>
<table class="ref">
    <tr><th><code>label_type</code></th><th>One class per</th><th>Hierarchy</th></tr>
    <tr><td><code>category</code></td><td>category (clinical group)</td><td>flat</td></tr>
    <tr><td><code>disease_subtype</code></td><td>disease</td><td>Two levels: each disease (fine class) is paired with its category (coarse class). If there is more than one category, the model also learns the coarse level.</td></tr>
    <tr><td><code>disease_type</code></td><td>the case's GDC <code>disease_type</code> text</td><td>flat</td></tr>
</table>
<p>
    A run is limited to <strong>one organ</strong>: the organ is the scope, the category is the coarse
    class, and the disease is the fine class. Labels are fixed when the run is dispatched, so editing a
    slide afterwards cannot change what the model was trained on. Other rules:
</p>
<ul>
    <li>Every slide in the run must use the same feature model, patch size and magnification.</li>
    <li>Slides from one patient never appear in more than one split.</li>
    <li>Every class needs at least one training slide.</li>
    <li>Validation needs at least 2 slides covering at least 2 classes.</li>
</ul>

@if ($models !== [])
<h3>Deployed diagnosis models</h3>
<table class="ref">
    <tr><th>Model</th><th>Classes</th><th>Requires</th></tr>
    @foreach ($models as $key => $m)
        <tr>
            <td><code>{{ $key }}</code><br>{{ $m['label'] ?? '' }}</td>
            <td>@foreach (($m['classes'] ?? []) as $cls)<code>{{ $cls }}</code> @endforeach</td>
            <td>
                @foreach (($m['requires'] ?? []) as $rk => $rv)
                    {{ str_replace('_', ' ', $rk) }}: <code>{{ $rv }}</code><br>
                @endforeach
            </td>
        </tr>
    @endforeach
</table>
<p>
    Before a slide is scored, its features are checked against the model's requirements. If they were
    made with a different feature extractor, patch size or magnification, or have too few patches, the
    slide is refused and the reason is given. A model's classes are plain names, for example
    <code>IDC</code>; they are not linked to disease ids in the tree.
</p>
@endif

{{-- ───────────────────────────────────────────────────────────── 12 --}}
<h2 id="errors">12. Errors and troubleshooting</h2>
<table class="ref">
    <tr><th>Status</th><th>Meaning</th></tr>
    <tr><td><code>200</code></td><td>OK.</td></tr>
    <tr><td><code>401</code> / <code>403</code></td><td>The key is missing, or it is invalid or inactive (section 2).</td></tr>
    <tr><td><code>404</code></td><td>The sample, run or organ is unknown.</td></tr>
    <tr><td><code>422</code></td><td>Validation failed. Laravel returns <code>{"message":"…","errors":{"field":["…"]}}</code>. For <code>/resolve</code> it means the label cannot be filed (see above).</td></tr>
    <tr><td><code>5xx</code></td><td>Server error. Retry with backoff. Callbacks are idempotent, so resending the same final report is safe.</td></tr>
</table>
<ul>
    <li><strong>You get an HTML page instead of JSON:</strong> add <code>Accept: application/json</code>.</li>
    <li><strong><code>403</code> with a key that used to work:</strong> the server may have been marked
        inactive, or its key was rotated in admin → Settings → Servers.</li>
    <li><strong>A slide never moves past <code>needs_clinical_info</code>:</strong> import the case's
        clinical record (GDC clinical JSON or CSV) so that gender and age at index are present.</li>
    <li><strong>A training run is refused for mixed organs:</strong> a run covers one organ only. Check
        that every selected slide's category belongs to the same organ.</li>
</ul>

</div>
</div>
</section>

@endsection
