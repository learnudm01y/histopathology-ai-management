@extends('admin.layouts.app')

@section('title', 'V2 Diagnose')

@section('content')
<style>
  .v2-grid{display:block}
  .v2-grid > .v2-card:first-child{max-width:46rem}
  .v2-card{background:#fff;border:1px solid #e3e0ea;border-radius:8px;padding:1.1rem 1.25rem;margin-bottom:1.1rem}
  .v2-card h3{margin:0 0 .85rem;font-size:1.02rem;font-weight:600}
  .v2-tabs{display:flex;padding:3px;margin-bottom:1rem;background:#f3f1f8;border-radius:8px}
  .v2-tab{flex:1;padding:.5rem .6rem;border:0;border-radius:6px;background:transparent;cursor:pointer;
          font-size:.86rem;font-weight:500;color:#5a5270;transition:background .15s,color .15s}
  .v2-tab:hover{color:#2d2540}
  .v2-tab.on{background:#fff;color:#4b3a94;font-weight:600;box-shadow:0 1px 3px rgba(40,30,70,.15)}
  .v2-pane{display:none}.v2-pane.on{display:block}
  .v2-row{display:grid;grid-template-columns:1fr 1fr;gap:.9rem}
  @media(max-width:520px){.v2-row{grid-template-columns:1fr}}
  .v2-field{margin-top:.9rem}
  .v2-card label{display:block;font-size:.8rem;font-weight:600;color:#41394f;margin-bottom:.35rem}
  .v2-card label .req{color:#b03d64}
  .v2-card label .v2-hint{font-weight:400}
  .v2-card .v2-in{display:block;width:100%;height:2.6rem;padding:.55rem .8rem;font-size:.92rem;line-height:1.4;color:#2d2540;
          background:#fff;border:1px solid #d9d4e5;border-radius:7px;outline:none;box-shadow:none;
          transition:border-color .15s,box-shadow .15s}
  .v2-card textarea.v2-in{height:auto;min-height:4.6rem;resize:vertical}
  .v2-card .v2-in::placeholder{color:#a39db3}
  .v2-card .v2-in:hover{border-color:#bfb7d3}
  .v2-card .v2-in:focus{border-color:#6a55c2;box-shadow:0 0 0 3px rgba(106,85,194,.16)}
  .v2-card input[type=file].v2-in{height:auto;padding:.45rem .6rem}
  .v2-card input[type=file].v2-in::file-selector-button{margin-right:.7rem;padding:.35rem .8rem;border:0;border-radius:5px;
          background:#efecf7;color:#4b3a94;font-weight:600;cursor:pointer}
  .v2-hint{font-size:.78rem;color:#7a7390;margin-top:.35rem;line-height:1.4}

  /* Searchable dropdown: the browser's <datalist> filters by the current value
     and shows raw ids, so it is replaced by this one. */
  .v2-combo{position:relative}
  .v2-combo .v2-in{padding-right:2.3rem}
  .v2-caret{position:absolute;top:0;right:0;width:2.3rem;height:2.6rem;border:0;background:transparent;cursor:pointer;
          color:#8a83a0;display:flex;align-items:center;justify-content:center}
  .v2-caret::after{content:"";width:.5rem;height:.5rem;border-right:2px solid currentColor;border-bottom:2px solid currentColor;
          transform:translateY(-2px) rotate(45deg);transition:transform .15s}
  .v2-combo.open .v2-caret::after{transform:translateY(2px) rotate(-135deg)}
  .v2-clear{position:absolute;top:.55rem;right:2.1rem;width:1.5rem;height:1.5rem;border:0;border-radius:50%;
          background:transparent;color:#a39db3;cursor:pointer;font-size:1.05rem;line-height:1;display:none}
  .v2-clear:hover{background:#f3f1f8;color:#41394f}
  .v2-combo.has-value .v2-clear{display:block}
  .v2-combo.has-value .v2-in{padding-right:3.7rem}
  .v2-menu{position:absolute;z-index:60;top:calc(100% + 4px);left:0;right:0;max-height:17rem;overflow-y:auto;
          background:#fff;border:1px solid #e0dbea;border-radius:8px;box-shadow:0 10px 28px rgba(40,30,70,.14);
          padding:.3rem;display:none}
  .v2-combo.open .v2-menu{display:block}
  .v2-opt{display:flex;align-items:baseline;justify-content:space-between;gap:.8rem;padding:.5rem .65rem;
          border-radius:5px;cursor:pointer;font-size:.9rem;color:#2d2540}
  .v2-opt small{color:#8a83a0;font-size:.76rem;white-space:nowrap}
  .v2-opt.active{background:#f0edf8}
  .v2-opt.sel{color:#4b3a94;font-weight:600}
  .v2-opt mark{background:#fdf0c4;color:inherit;padding:0;border-radius:2px}
  .v2-more,.v2-none{padding:.5rem .65rem;font-size:.8rem;color:#8a83a0}

  /* Sex as a segmented control, not a one-of-four dropdown. */
  .v2-seg{display:flex;border:1px solid #d9d4e5;border-radius:7px;overflow:hidden;height:2.6rem}
  .v2-seg label{flex:1;margin:0;display:flex;align-items:center;justify-content:center;font-size:.86rem;font-weight:500;
          color:#5a5270;cursor:pointer;border-left:1px solid #e6e2ee}
  .v2-seg label:first-of-type{border-left:0}
  .v2-seg input{position:absolute;opacity:0;pointer-events:none}
  .v2-seg input:checked + span{color:#4b3a94;font-weight:600}
  .v2-seg label:has(input:checked){background:#f0edf8}
  .v2-seg label:has(input:focus-visible){box-shadow:inset 0 0 0 2px #6a55c2}
  .v2-fixed{background:#f5f3fa;border-radius:6px;padding:.6rem .8rem;font-size:.84rem;color:#41394f}
  /* Analysis results: full width, actions first, the result's colour on the row's edge. */
  .v2-rwrap{overflow-x:auto;border:1px solid #ebe8f1;border-radius:8px}
  table.v2-runs{width:100%;border-collapse:separate;border-spacing:0;font-size:.86rem;min-width:900px}
  table.v2-runs th{background:#f7f6fa;color:#57516a;font-weight:600;font-size:.74rem;text-transform:uppercase;letter-spacing:.04em;
          padding:.6rem .75rem;text-align:left;border-bottom:1px solid #e6e2ee;white-space:nowrap}
  table.v2-runs td{padding:.7rem .75rem;border-bottom:1px solid #f0eef4;text-align:left;vertical-align:top;color:#2d2540}
  table.v2-runs tbody tr:last-child td{border-bottom:0}
  table.v2-runs tbody tr{transition:background .12s}
  table.v2-runs tbody tr:hover{background:#faf9fd}
  table.v2-runs tbody tr td:first-child{box-shadow:inset 3px 0 0 transparent}
  table.v2-runs tr.v2-r-correct td:first-child{box-shadow:inset 3px 0 0 #3a9a7a}
  table.v2-runs tr.v2-r-partial td:first-child{box-shadow:inset 3px 0 0 #d9a53a}
  table.v2-runs tr.v2-r-wrong td:first-child,table.v2-runs tr.v2-r-failed td:first-child{box-shadow:inset 3px 0 0 #c9506f}
  table.v2-runs .v2-c-act{width:1%;white-space:nowrap;vertical-align:middle;padding-right:.4rem}
  table.v2-runs .v2-c-run{width:1%;white-space:nowrap}
  table.v2-runs .v2-c-slide{max-width:17rem}
  table.v2-runs .v2-c-dx{min-width:16rem;max-width:26rem}
  table.v2-runs .v2-c-truth{width:1%;white-space:nowrap}
  table.v2-runs .v2-c-res{min-width:11rem;max-width:16rem}
  .v2-runno{font-weight:700;color:#4b3a94;margin-right:.35rem}
  .v2-c-slide .v2-id{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .v2-dxtext{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4}
  .v2-conf{display:flex;align-items:center;gap:.4rem;margin-top:.35rem;font-size:.78rem;font-weight:600;color:#41394f}
  .v2-bar{position:relative;width:4.5rem;height:.4rem;border-radius:99px;background:#ece9f3;overflow:hidden}
  .v2-bar span{position:absolute;top:0;bottom:0;left:0;border-radius:99px;background:#6a55c2}
  .v2-truth{display:inline-block;padding:.12rem .55rem;border-radius:5px;background:#eef3fb;color:#2f5586;font-weight:600;font-size:.8rem}
  .v2-c-res .v2-sub{margin-top:.3rem}
  #results nav{margin-top:.9rem}
  .v2-st{display:inline-block;padding:.1rem .5rem;border-radius:99px;font-size:.78rem;font-weight:600}
  .v2-st.completed{background:#e4f1ec;color:#2c6b5b}
  .v2-st.failed{background:#fbe9f0;color:#b03d64}
  .v2-st.run{background:#faf0da;color:#8a6414}
  .v2-match{display:inline-block;padding:.1rem .5rem;border-radius:99px;font-size:.78rem;font-weight:600;white-space:nowrap}
  .v2-match.correct{background:#e4f1ec;color:#2c6b5b}
  .v2-match.partial{background:#faf0da;color:#8a6414}
  .v2-match.wrong{background:#fbe9f0;color:#b03d64}
  .v2-tally{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:-.3rem 0 .9rem;font-size:.85rem;color:#57516a}
  .v2-eye{display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border-radius:6px;
          color:#4b3a94;background:#f3f1f8;font-size:1.15rem;text-decoration:none;transition:background .15s,color .15s}
  .v2-eye:hover{background:#4b3a94;color:#fff;text-decoration:none}
  /* Results filter: organ → classification → disease, one GET form. */
  .v2-rfilter{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:.7rem;align-items:end;margin-bottom:1rem}
  @media(max-width:900px){.v2-rfilter{grid-template-columns:1fr}}
  .v2-rfilter label{display:block;font-size:.8rem;font-weight:600;color:#41394f;margin-bottom:.35rem}
  .v2-rfilter select.v2-in{display:block;width:100%;height:2.4rem;padding:.4rem .6rem;font-size:.88rem;color:#2d2540;background:#fff;
          border:1px solid #d9d4e5;border-radius:7px;appearance:auto}
  .v2-rfilter select.v2-in:disabled{background:#f7f6fa;color:#a39db3}
  .v2-rclear{display:inline-flex;align-items:center;height:2.4rem;padding:0 .9rem;border-radius:7px;background:#f3f1f8;color:#4b3a94;
          font-size:.84rem;font-weight:600;white-space:nowrap;text-decoration:none}
  .v2-rclear:hover{background:#e8e3f6;text-decoration:none}
  .v2-rowbtns{display:flex;gap:.35rem;justify-content:flex-start}
  .v2-del{display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border:0;border-radius:6px;
          color:#b03d64;background:#fbe9f0;font-size:1.1rem;cursor:pointer;transition:background .15s,color .15s}
  .v2-del:hover{background:#b03d64;color:#fff}

  /* Archive: organ → stain → Filter, then every slide in a table. */
  .v2-filter{display:grid;grid-template-columns:minmax(0,22rem);gap:.9rem;align-items:end}
  @media(max-width:720px){.v2-filter{grid-template-columns:1fr}}
  .v2-filter .btn{height:2.6rem;padding:0 1.6rem}
  .v2-card select.v2-in{appearance:auto;padding-right:.6rem}
  .v2-card select.v2-in:disabled{background:#f7f6fa;color:#a39db3}
  .v2-archbar{display:flex;flex-wrap:wrap;gap:.6rem 1.2rem;align-items:center;margin:1.1rem 0 .6rem}
  .v2-archbar .v2-in{max-width:22rem}
  .v2-archbar .v2-chk{display:flex;align-items:center;gap:.4rem;margin:0;font-weight:500;font-size:.84rem;color:#41394f;cursor:pointer}
  .v2-archbar .v2-count{margin-left:auto;font-size:.82rem;color:#7a7390}
  .v2-tablewrap{overflow:auto;max-height:34rem;border:1px solid #ebe8f1;border-radius:8px}
  table.v2-arch{width:100%;border-collapse:collapse;font-size:.84rem}
  table.v2-arch th{position:sticky;top:0;z-index:1;background:#f7f6fa;color:#57516a;font-weight:600;font-size:.76rem;
          text-transform:uppercase;letter-spacing:.03em;padding:.55rem .6rem;text-align:left;border-bottom:1px solid #e6e2ee;white-space:nowrap}
  table.v2-arch td{padding:.55rem .6rem;border-bottom:1px solid #f0eef4;vertical-align:top;color:#2d2540}
  table.v2-arch tbody tr{cursor:pointer;transition:background .1s}
  table.v2-arch tbody tr:hover{background:#faf9fd}
  table.v2-arch tbody tr.used{background:#fffaf0}
  table.v2-arch tbody tr.used:hover{background:#fdf4e2}
  table.v2-arch tbody tr.sel{background:#efeafb;box-shadow:inset 3px 0 0 #6a55c2}
  /* Each row's own buttons: pick it, or run it straight away. */
  table.v2-arch td.v2-act{width:1%;white-space:nowrap;vertical-align:middle}
  .v2-act-wrap{display:flex;min-width:7.2rem}
  .v2-go{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;height:2rem;padding:0 .75rem;
          border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s,color .15s,border-color .15s,box-shadow .15s}
  .v2-go{border:0;background:#2c7a5f;color:#fff;box-shadow:0 1px 2px rgba(20,60,45,.25)}
  .v2-go:hover{background:#23654e}
  .v2-go:disabled{opacity:.6;cursor:wait}
  .v2-pager{display:flex;flex-wrap:wrap;gap:.6rem 1rem;align-items:center;justify-content:space-between;margin-top:.7rem}
  .v2-pages{display:flex;flex-wrap:wrap;gap:.3rem;align-items:center}
  .v2-pages button{min-width:2.1rem;height:2.1rem;padding:0 .55rem;border:1px solid #e0dbea;border-radius:6px;background:#fff;
          color:#41394f;font-size:.84rem;cursor:pointer}
  .v2-pages button:hover:not(:disabled){border-color:#6a55c2;color:#4b3a94}
  .v2-pages button.on{background:#6a55c2;border-color:#6a55c2;color:#fff;font-weight:600}
  .v2-pages button:disabled{opacity:.45;cursor:default}
  .v2-pages span{color:#8a83a0;padding:0 .2rem}
  /* The confirmation dialog (SweetAlert2 draws it outside the page card). */
  .v2-sw{text-align:left;font-size:.9rem;color:#41394f;line-height:1.5}
  .v2-sw-name{font-weight:700;font-size:1rem;color:#2d2540;margin-bottom:.35rem;word-break:break-all}
  .v2-sw-note{margin-top:.8rem;color:#57516a}
  .v2-sw-muted{color:#8a83a0;font-size:.8rem}
  .v2-sw-why{color:#7a7390;font-size:.78rem;margin-top:.15rem}
  table.v2-sw-runs{width:100%;border-collapse:collapse;margin-top:.8rem;font-size:.84rem}
  table.v2-sw-runs th{background:#f7f6fa;color:#57516a;font-size:.74rem;text-transform:uppercase;letter-spacing:.03em;
          padding:.45rem .5rem;text-align:left;border-bottom:1px solid #e6e2ee}
  table.v2-sw-runs td{padding:.5rem;border-bottom:1px solid #f0eef4;vertical-align:top;text-align:left}
  .swal2-popup .v2-match{font-size:.8rem}
  .v2-run-picked{margin-top:1rem;width:100%;height:2.7rem;font-size:.95rem}
  .v2-id{font-weight:600;word-break:break-all}
  .v2-sub{font-size:.76rem;color:#7a7390;margin-top:.15rem;line-height:1.35}
  .v2-dx{display:inline-block;padding:.08rem .5rem;border-radius:5px;background:#eef3fb;color:#2f5586;font-weight:600;font-size:.8rem}
  .v2-dx.normal{background:#e4f1ec;color:#2c6b5b}
  .v2-dx.none{background:#f3f1f8;color:#8a83a0;font-weight:500}
  .v2-used{display:inline-flex;align-items:center;gap:.3rem;padding:.1rem .5rem;border-radius:99px;background:#faf0da;color:#8a6414;
          font-weight:600;font-size:.76rem;white-space:nowrap}
  .v2-fresh{font-size:.78rem;color:#2c6b5b;white-space:nowrap}
  .v2-runlink{display:block;font-size:.76rem;margin-top:.2rem;white-space:nowrap}
  .v2-missing{color:#b8b2c8}
  .v2-empty{padding:1.4rem;text-align:center;color:#7a7390}

  /* The slide picked, in full. */
  .v2-picked{margin-top:1rem;border:1px solid #d9d0f2;background:#fbfaff;border-radius:8px;padding:1rem 1.1rem}
  .v2-picked h4{margin:0 0 .7rem;font-size:.98rem;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
  .v2-picked dl{display:grid;grid-template-columns:repeat(auto-fill,minmax(13rem,1fr));gap:.6rem 1.2rem;margin:0}
  .v2-picked dt{font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;color:#8a83a0;font-weight:600}
  .v2-picked dd{margin:.1rem 0 0;font-size:.88rem;color:#2d2540}
  .v2-prev{display:flex;flex-wrap:wrap;gap:.7rem;margin-top:.9rem}
  .v2-prev a{display:flex;gap:.6rem;align-items:center;padding:.45rem .6rem;border:1px solid #eadfc4;background:#fffaf0;border-radius:7px;
          font-size:.82rem;color:#41394f;text-decoration:none}
  .v2-prev a:hover{border-color:#d9c38f}
  .v2-prev img{width:3.4rem;height:2.6rem;object-fit:cover;border-radius:4px;background:#eee}

  /* Context the archive already holds is shown, not asked for. */
  .v2-known{display:none!important}
  form.show-known .v2-known{display:block!important}
  .v2-knownbox{background:#f0f7f4;border:1px solid #cfe5dc;border-radius:7px;padding:.7rem .85rem;font-size:.85rem;color:#2d4a40;margin-bottom:.4rem}
  .v2-knownbox b{font-weight:600}
  .v2-knownbox ul{margin:.35rem 0 0;padding-left:1.1rem}
  .v2-knownbox button{border:0;background:none;padding:0;color:#4b3a94;font-weight:600;cursor:pointer;font-size:.82rem}
  .v2-wait{background:#f7f6fa;border-radius:7px;padding:.7rem .85rem;font-size:.85rem;color:#7a7390}
</style>

<h2 style="margin-bottom:.35rem">V2 Diagnose</h2>
<p style="color:#6b6480;margin-bottom:1.3rem">
  Clinical context and a slide go in; the AI reads the tiles and answers in coordinates,
  drawn on the live slide. One request, one answer of at most five lines — no chat.
  Research prototype: every reading needs a pathologist.
</p>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())
  <div class="alert alert-danger">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
@endif

<form method="POST" action="{{ route('admin.v2-diagnose.store') }}" enctype="multipart/form-data" id="v2form">
  @csrf
  <div class="v2-card">
    <h3>1 · The slide</h3>
    <input type="hidden" name="source" id="source" value="{{ old('source', 'existing') }}">
    <div class="v2-tabs">
      <button type="button" class="v2-tab" data-src="existing">From the archive</button>
      <button type="button" class="v2-tab" data-src="server_path">Server path</button>
      <button type="button" class="v2-tab" data-src="upload">Upload</button>
    </div>

    <div class="v2-pane" data-src="existing">
      <input type="hidden" name="sample_id" id="sample_id" value="{{ $picked['id'] ?? '' }}">
      <div class="v2-filter">
        <div>
          <label for="f_organ">Organ <span class="req">*</span></label>
          <select id="f_organ" class="v2-in">
            <option value="">Choose an organ</option>
            @foreach($archive as $o)
              <option value="{{ $o['id'] }}">{{ $o['name'] }} ({{ $o['n'] }})</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="v2-hint">{{ $archive->sum('n') }} slides whose image is on Drive. Choose an organ to list its slides; those already run through V2 Diagnose are marked.</div>

      <div id="arch" hidden>
        <div class="v2-archbar">
          <input type="search" id="f_q" class="v2-in" placeholder="Search case id, file, diagnosis, site…" autocomplete="off">
          <select id="f_stain" class="v2-in" style="max-width:15rem"><option value="">Every stain</option></select>
          <label class="v2-chk"><input type="checkbox" id="f_unused"> Only slides not run before</label>
          <span class="v2-count" id="f_count"></span>
        </div>
        <div class="v2-tablewrap">
          <table class="v2-arch">
            <thead><tr><th>Action</th><th>Slide</th><th>Recorded diagnosis</th><th>Patient</th><th>Presentation</th><th>Pathology</th><th>V2 runs</th></tr></thead>
            <tbody id="f_rows"></tbody>
          </table>
        </div>
        <div class="v2-pager">
          <label class="v2-chk">Rows per page
            <select id="f_per" class="v2-in" style="width:auto;height:2.1rem;padding:.2rem .5rem">
              <option>25</option><option>50</option><option>100</option>
            </select>
          </label>
          <div class="v2-pages" id="f_pages"></div>
        </div>
      </div>
      <div id="picked" class="v2-picked" hidden></div>
    </div>
    <div class="v2-pane" data-src="server_path">
      <label for="server_path">Path</label>
      <input name="server_path" id="server_path" class="v2-in" value="{{ old('server_path') }}"
             placeholder="/var/www/HISTO_AI/…/slide.svs" spellcheck="false">
      <div class="v2-hint">Analysed in place; a copy also goes to Drive as a new sample.</div>
    </div>
    <div class="v2-pane" data-src="upload">
      <label for="wsi">Slide file</label>
      <input type="file" name="wsi" id="wsi" class="v2-in" accept=".svs,.tif,.tiff,.ndpi,.scn">
      <div class="v2-hint">For small files only. The run waits until the upload has reached Drive.</div>
    </div>
    <div class="v2-pane v2-field" data-src="server_path upload">
      <label for="label">Label <span class="v2-hint">(optional)</span></label>
      <input name="label" id="label" class="v2-in" value="{{ old('label') }}" placeholder="e.g. TCGA-AN-A046">
    </div>
  </div>

<div class="v2-grid">
  <div class="v2-card">
    <h3>2 · Clinical context</h3>
    <div class="v2-wait" id="ctxWait" hidden>Pick a slide above — whatever the archive holds about the patient is filled in here.</div>
    <div class="v2-knownbox" id="knownBox" hidden></div>
    <div class="v2-row">
      <div data-field="organ">
        <label for="organ">Organ <span class="req">*</span></label>
        <div class="v2-combo" id="organCombo">
          <input type="text" name="organ" id="organ" class="v2-in" required autocomplete="off"
                 value="{{ old('organ') }}" placeholder="Choose or type">
          <button type="button" class="v2-clear" tabindex="-1" aria-label="Clear">&times;</button>
          <button type="button" class="v2-caret" tabindex="-1" aria-label="Show all"></button>
          <div class="v2-menu" role="listbox"></div>
        </div>
      </div>
      <div data-field="stain">
        <label for="stain">Stain</label>
        <div class="v2-combo" id="stainCombo">
          <input type="text" name="stain" id="stain" class="v2-in" autocomplete="off"
                 value="{{ old('stain') }}" placeholder="e.g. H&E">
          <button type="button" class="v2-clear" tabindex="-1" aria-label="Clear">&times;</button>
          <button type="button" class="v2-caret" tabindex="-1" aria-label="Show all"></button>
          <div class="v2-menu" role="listbox"></div>
        </div>
      </div>
      <div data-field="age">
        <label for="age">Age</label>
        <input type="number" name="age" id="age" min="0" max="120" class="v2-in" value="{{ old('age') }}" placeholder="years">
      </div>
      <div data-field="sex">
        <label>Sex</label>
        <div class="v2-seg" id="sex">
          @foreach(['' => '—', 'female' => 'Female', 'male' => 'Male', 'other' => 'Other'] as $val => $text)
            <label><input type="radio" name="sex" value="{{ $val }}" @checked((string) old('sex', '') === $val)><span>{{ $text }}</span></label>
          @endforeach
        </div>
      </div>
    </div>
    <div class="v2-field" data-field="race">
      <label for="race">Race / ancestry <span class="v2-hint">(if known)</span></label>
      <input name="race" id="race" class="v2-in" value="{{ old('race') }}">
    </div>
    <div class="v2-field" data-field="clinical_notes">
      <label for="clinical_notes">Notes <span class="v2-hint">(optional)</span></label>
      <textarea name="clinical_notes" id="clinical_notes" rows="3" class="v2-in"
                maxlength="1000" placeholder="e.g. mass 2.3 cm, core biopsy">{{ old('clinical_notes') }}</textarea>
    </div>

    <h3 style="margin-top:1.3rem">3 · How it is read</h3>
    <div class="v2-fixed">
      Tiles of {{ config('v2_diagnose.tiling.patch_size') }} px at {{ config('v2_diagnose.tiling.target_mpp') }} µm/px
      (≈{{ round(config('v2_diagnose.tiling.patch_size') * config('v2_diagnose.tiling.target_mpp')) }} µm each),
      covering the tissue, at most {{ config('v2_diagnose.tiling.max_patches') }} ·
      output: ROI, heatmap, SAM prompts{{ config('v2_diagnose.sam.checkpoint') ? ' + SAM masks' : '' }}.
    </div>

    <button class="btn btn-primary" id="runBtn" style="margin-top:1rem;width:100%">Run V2 Diagnose</button>
  </div>

  <div class="v2-card" id="results">
    <h3>Analysis results <span class="v2-hint" style="font-weight:400">· {{ $runs->total() }} {{ array_filter($rf) ? 'matching the filter' : 'in all' }}</span></h3>

    {{-- The fields belong to the GET form below the page's POST form: forms cannot nest. --}}
    <div class="v2-rfilter">
      <div>
        <label for="r_organ">Organ</label>
        <select name="r_organ" id="r_organ" form="resultsFilter" class="v2-in" data-resets="r_cat r_dx">
          <option value="">Every organ</option>
          @foreach($rfOptions['organs'] as $o)
            <option value="{{ $o['id'] }}" @selected($rf['organ'] === $o['id'])>{{ $o['name'] }} ({{ $o['n'] }})</option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="r_cat">Classification</label>
        <select name="r_cat" id="r_cat" form="resultsFilter" class="v2-in" data-resets="r_dx" @disabled(! $rf['organ'])>
          <option value="">{{ $rf['organ'] ? 'Every classification' : 'Choose the organ first' }}</option>
          @foreach($rfOptions['cats'] as $c)
            <option value="{{ $c['id'] }}" @selected((string) $rf['cat'] === (string) $c['id'])>{{ $c['name'] }} ({{ $c['n'] }})</option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="r_dx">Disease</label>
        <select name="r_dx" id="r_dx" form="resultsFilter" class="v2-in" @disabled(! $rf['cat'] || $rf['cat'] === 'none' || ! $rfOptions['dxs'])>
          <option value="">{{ $rf['cat'] && $rf['cat'] !== 'none' ? 'Every disease' : 'Choose the classification first' }}</option>
          @foreach($rfOptions['dxs'] as $d)
            <option value="{{ $d['id'] }}" @selected($rf['dx'] === $d['id'])>{!! str_repeat('&nbsp;&nbsp;&nbsp;', $d['depth']) !!}{{ $d['depth'] ? '└ ' : '' }}{{ $d['name'] }} ({{ $d['n'] }})</option>
          @endforeach
        </select>
      </div>
      @if(array_filter($rf))
        <a href="{{ route('admin.v2-diagnose') }}#results" class="v2-rclear">Clear filter</a>
      @endif
    </div>

    @php $scored = array_sum($tally); @endphp
    @if($scored)
    <div class="v2-tally" title="Finished runs whose slide has a recorded diagnosis in the archive, compared with it.">
      Against the recorded diagnosis ({{ $scored }} {{ array_filter($rf) ? 'filtered ' : '' }}results):
      <span class="v2-match correct">✓ {{ $tally['correct'] }} correct</span>
      <span class="v2-match partial">≈ {{ $tally['partial'] }} partial</span>
      <span class="v2-match wrong">✗ {{ $tally['wrong'] }} wrong</span>
      <span>· {{ round($tally['correct'] / $scored * 100) }}% fully correct</span>
    </div>
    @endif
    @if($runs->isEmpty())
      <p style="color:#6b6480">{{ array_filter($rf) ? 'No result matches this filter.' : 'No results yet.' }}</p>
    @else
    <div class="v2-rwrap">
    <table class="v2-runs">
      <thead><tr>
        <th class="v2-c-act"><span class="sr-only">Actions</span></th>
        <th>Run</th><th>Slide</th><th>AI diagnosis</th><th>Recorded</th><th>Result</th>
      </tr></thead>
      <tbody>
      @foreach($runs as $r)
        @php
          $v = $r->verdict(); $truth = $r->recordedClass();
          $state = in_array($r->status, ['completed', 'failed']) ? $r->status : 'run';
          $slide = $r->sample?->entity_submitter_id ?: ($r->sample?->file_name ?: 'sample #'.$r->sample_id);
          $conf = $r->confidence !== null ? (int) round($r->confidence * 100) : null;
          $who = collect([$r->sex ? ucfirst($r->sex) : null, $r->age ? $r->age.' y' : null, $r->race])->filter()->implode(' · ');
        @endphp
        <tr class="v2-r-{{ $v['result'] ?? ($state === 'failed' ? 'failed' : 'none') }}">
          <td class="v2-c-act"><div class="v2-rowbtns">
            <a href="{{ route('admin.v2-diagnose.show', $r) }}" class="v2-eye" title="Open run #{{ $r->id }}: slide, regions, heatmap"
               aria-label="Open run #{{ $r->id }}"><i class="mdi mdi-eye-outline"></i></a>
            <button type="button" class="v2-del" data-del="{{ route('admin.v2-diagnose.destroy', $r) }}" data-id="{{ $r->id }}"
                    data-slide="{{ $slide }}" data-dx="{{ $r->diagnosis }}" data-running="{{ $r->isRunning() ? $r->status : '' }}"
                    title="Delete run #{{ $r->id }}" aria-label="Delete run #{{ $r->id }}"><i class="mdi mdi-trash-can-outline"></i></button>
          </div></td>
          <td class="v2-c-run">
            <a href="{{ route('admin.v2-diagnose.show', $r) }}" class="v2-runno">#{{ $r->id }}</a>
            <span class="v2-st {{ $state }}">{{ $r->status }}</span>
            <div class="v2-sub" title="{{ $r->created_at->format('Y-m-d H:i') }}">{{ $r->created_at->format('d M Y · H:i') }}</div>
          </td>
          <td class="v2-c-slide">
            <div class="v2-id" title="{{ $slide }}">{{ $slide }}</div>
            <div class="v2-sub">sample #{{ $r->sample_id }} · {{ $r->organ }}{{ $r->stain ? ' · '.$r->stain : '' }}</div>
            @if($who)<div class="v2-sub">{{ $who }}</div>@endif
          </td>
          <td class="v2-c-dx">
            @if($r->diagnosis)
              <div class="v2-dxtext" title="{{ $r->diagnosis }}">{{ $r->diagnosis }}</div>
              @if($conf !== null)
                <div class="v2-conf" title="Confidence {{ $conf }}%">
                  <span class="v2-bar"><span style="width:{{ $conf }}%"></span></span>
                  <span>{{ $conf }}%</span><span class="v2-sub">· {{ $r->regions_count }} regions</span>
                </div>
              @endif
            @else
              <span class="v2-missing">{{ $state === 'run' ? 'Running…' : '—' }}</span>
            @endif
          </td>
          <td class="v2-c-truth">
            @if($truth)<span class="v2-truth">{{ $truth }}</span>
            @else<span class="v2-missing">not recorded</span>@endif
          </td>
          <td class="v2-c-res">
            @if($v)
              <span class="v2-match {{ $v['result'] }}">{{ ['correct' => '✓ Correct', 'partial' => '≈ Partial', 'wrong' => '✗ Wrong'][$v['result']] }}</span>
              <div class="v2-sub">{{ $v['reason'] }}</div>
            @else
              <span class="v2-missing">{{ $state === 'failed' ? 'failed — no answer' : ($truth ? '—' : 'cannot be scored') }}</span>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
    {{ $runs->links() }}
    @endif
  </div>
</div>
</form>
{{-- Its own form: the runs table sits inside the run form, and forms cannot nest. --}}
<form method="POST" id="delForm" style="display:none">@csrf @method('DELETE')</form>
<form method="GET" id="resultsFilter" action="{{ route('admin.v2-diagnose') }}#results"></form>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
(function () {
  var src = document.getElementById('source');
  function pick(v) {
    src.value = v;
    document.querySelectorAll('.v2-tab').forEach(function (t) { t.classList.toggle('on', t.dataset.src === v); });
    document.querySelectorAll('.v2-pane').forEach(function (p) {
      p.classList.toggle('on', p.dataset.src.split(' ').indexOf(v) !== -1);
    });
  }
  document.querySelectorAll('.v2-tab').forEach(function (t) {
    t.addEventListener('click', function () { pick(t.dataset.src); });
  });
  pick(src.value);

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
  }
  function mark(text, q) {
    var t = String(text), i = q ? t.toLowerCase().indexOf(q) : -1;
    if (i < 0) return esc(t);
    return esc(t.slice(0, i)) + '<mark>' + esc(t.slice(i, i + q.length)) + '</mark>' + esc(t.slice(i + q.length));
  }

  /**
   * A searchable dropdown over `items` ({value, label, sub}). Opening it shows
   * every option whatever is typed; typing filters on any part of the label,
   * the sub-line or the value. With `hidden`, the visible text is only a label
   * and the chosen value goes to `hidden` (strict: free text is not a value).
   */
  function combo(root, items, opt) {
    opt = opt || {};
    var input = root.querySelector('input'), menu = root.querySelector('.v2-menu');
    var LIMIT = 80, shown = [], active = -1, query = '';

    function current() { return opt.hidden ? opt.hidden.value : input.value; }
    function sync() { root.classList.toggle('has-value', !!input.value); }

    function render() {
      var q = query.trim().toLowerCase(), words = q.split(/\s+/).filter(Boolean);
      var hits = items.filter(function (it) {
        var hay = (it.label + ' ' + (it.sub || '') + ' #' + it.value).toLowerCase();
        return words.every(function (w) { return hay.indexOf(w.replace(/^#/, '')) !== -1; });
      });
      shown = hits.slice(0, LIMIT);
      var cur = String(current());
      var hl = words[0] ? words[0].replace(/^#/, '') : '';
      menu.innerHTML = shown.length ? shown.map(function (it, i) {
        return '<div class="v2-opt' + (String(it.value) === cur ? ' sel' : '') + '" data-i="' + i + '" role="option">'
          + '<span>' + mark(it.label, hl) + '</span>'
          + (it.sub ? '<small>' + mark(it.sub, hl) + '</small>' : '') + '</div>';
      }).join('') + (hits.length > LIMIT ? '<div class="v2-more">' + (hits.length - LIMIT) + ' more — keep typing to narrow</div>' : '')
        : '<div class="v2-none">' + (opt.hidden ? 'No slide matches' : 'No match — what you typed is kept') + '</div>';
      var sel = shown.findIndex(function (it) { return String(it.value) === cur; });
      setActive(sel >= 0 ? sel : (q ? 0 : -1));
    }
    function setActive(i) {
      active = i;
      menu.querySelectorAll('.v2-opt').forEach(function (el, j) { el.classList.toggle('active', j === i); });
      var el = menu.querySelector('.v2-opt.active');
      if (el) el.scrollIntoView({ block: 'nearest' });
    }
    function open(q) { query = q; root.classList.add('open'); render(); }
    function close() { root.classList.remove('open'); active = -1; }
    function choose(it) {
      input.value = it.label;
      if (opt.hidden) opt.hidden.value = it.value;
      sync(); close();
      if (opt.onPick) opt.onPick(it);
    }
    function setValue(v) {
      var it = items.find(function (x) { return String(x.value) === String(v); });
      if (it) { input.value = it.label; if (opt.hidden) opt.hidden.value = it.value; }
      else if (!opt.hidden) input.value = v;
      sync();
    }

    input.addEventListener('focus', function () { open(''); input.select(); });
    input.addEventListener('click', function () { if (!root.classList.contains('open')) open(''); });
    input.addEventListener('input', function () { if (opt.hidden) opt.hidden.value = ''; sync(); open(input.value); });
    input.addEventListener('keydown', function (e) {
      var isOpen = root.classList.contains('open');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!isOpen) return open('');
        var n = shown.length; if (!n) return;
        setActive(e.key === 'ArrowDown' ? (active + 1) % n : (active - 1 + n) % n);
      } else if (e.key === 'Enter' && isOpen) {
        if (active >= 0 && shown[active]) { e.preventDefault(); choose(shown[active]); }
        else close();
      } else if (e.key === 'Escape' && isOpen) {
        e.preventDefault(); close();
      } else if (e.key === 'Tab' && isOpen && query && active >= 0 && shown[active]) {
        choose(shown[active]);
      }
    });
    input.addEventListener('blur', function () {
      close();
      if (!opt.hidden) return;
      // Strict: the text must name a slide. An exact label or #id still counts.
      if (!opt.hidden.value && input.value.trim()) {
        var t = input.value.trim().replace(/^#/, '').toLowerCase();
        var it = items.find(function (x) { return String(x.value) === t || x.label.toLowerCase() === t; });
        if (it) choose(it);
      }
    });
    menu.addEventListener('mousedown', function (e) {
      e.preventDefault(); // keep the focus in the input
      var el = e.target.closest('.v2-opt');
      if (el) choose(shown[+el.dataset.i]);
    });
    root.querySelector('.v2-caret').addEventListener('mousedown', function (e) {
      e.preventDefault();
      if (root.classList.contains('open')) close(); else { input.focus(); open(''); }
    });
    root.querySelector('.v2-clear').addEventListener('mousedown', function (e) {
      e.preventDefault();
      input.value = ''; if (opt.hidden) opt.hidden.value = '';
      sync(); input.focus(); open('');
    });

    if (opt.hidden && opt.hidden.value) setValue(opt.hidden.value);
    sync();
    return { setValue: setValue };
  }

  var names = function (list) { return list.map(function (n) { return { value: n, label: n }; }); };
  var organ = combo(document.getElementById('organCombo'), names(@json($organs)));
  var stain = combo(document.getElementById('stainCombo'), names(@json($stains)));

  /* ── The archive: organ → stain → Filter → table ─────────────────────── */
  var form = document.getElementById('v2form');
  var sid = document.getElementById('sample_id');
  var archive = @json($archive);
  var picked = @json($picked);
  var fOrgan = document.getElementById('f_organ'), fStain = document.getElementById('f_stain');
  var fQ = document.getElementById('f_q'), fUnused = document.getElementById('f_unused');
  var rowsEl = document.getElementById('f_rows'), countEl = document.getElementById('f_count');
  var archEl = document.getElementById('arch'), pickedEl = document.getElementById('picked');
  var fPer = document.getElementById('f_per'), pagesEl = document.getElementById('f_pages');
  var rows = [], byId = {}, page = 1, meta = null, seq = 0, current = null;
  var archiveUrl = @json(route('admin.v2-diagnose.archive'));
  var contextUrl = @json(url('admin/v2-diagnose/sample'));
  var VERDICT = { correct: '✓ correct', partial: '≈ partial', wrong: '✗ wrong' };

  // The organ alone lists its slides; stain, search and "not run" narrow them on the server.
  function fillStains() {
    var o = archive.find(function (x) { return String(x.id) === fOrgan.value; });
    fStain.innerHTML = '<option value="">Every stain' + (o ? ' (' + o.n + ')' : '') + '</option>'
      + (o ? o.stains.map(function (s) { return '<option value="' + esc(s.id) + '">' + esc(s.name) + ' (' + s.n + ')</option>'; }).join('') : '');
  }

  function load(p) {
    if (!fOrgan.value) { archEl.hidden = true; unpick(); return; }
    page = p || 1;
    archEl.hidden = false;
    rowsEl.innerHTML = '<tr><td colspan="7" class="v2-empty">Loading the slides…</td></tr>';
    var qs = new URLSearchParams({ organ_id: fOrgan.value, stain: fStain.value, q: fQ.value.trim(),
                                   unused: fUnused.checked ? 1 : 0, page: page, per_page: fPer.value });
    var mine = ++seq; // a slower earlier answer must not overwrite a newer one
    fetch(archiveUrl + '?' + qs, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) {
        if (mine !== seq) return;
        rows = d.samples; meta = d; byId = {};
        rows.forEach(function (s) { byId[s.id] = s; });
        render();
      })
      .catch(function (e) {
        if (mine !== seq) return;
        rows = []; byId = {}; meta = null; pagesEl.innerHTML = ''; countEl.textContent = '';
        rowsEl.innerHTML = '<tr><td colspan="7" class="v2-empty">Could not load the slides (' + esc(e.message) + ').</td></tr>';
      });
  }
  fOrgan.addEventListener('change', function () { fillStains(); unpick(); load(1); });
  var typing;
  fQ.addEventListener('input', function () { clearTimeout(typing); typing = setTimeout(function () { load(1); }, 300); });
  [fStain, fUnused, fPer].forEach(function (el) { el.addEventListener('change', function () { load(1); }); });

  function dash(v) { return v == null || v === '' ? '<span class="v2-missing">—</span>' : esc(v); }
  function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
  function dxBadge(s) {
    var name = s.disease || s.category;
    if (!name) return '<span class="v2-dx none">not recorded</span>';
    var normal = /normal/i.test(name);
    return '<span class="v2-dx' + (normal ? ' normal' : '') + '">' + esc(name) + '</span>'
      + (s.disease && s.category && s.category.toLowerCase() !== s.disease.toLowerCase() ? '<div class="v2-sub">' + esc(s.category) + '</div>' : '');
  }
  function patient(s) {
    var p = [s.sex ? cap(s.sex) : null, s.age != null ? s.age + ' y' : null].filter(Boolean).join(' · ');
    return (p ? '<span style="white-space:nowrap">' + esc(p) + '</span>' : dash(null)) + (s.race ? '<div class="v2-sub">' + esc(s.race) + '</div>' : '');
  }
  function renderPages() {
    if (!meta || meta.pages <= 1) { pagesEl.innerHTML = ''; return; }
    var n = meta.pages, p = meta.page, out = [];
    function btn(label, to, on, off) {
      return '<button type="button" data-page="' + to + '"' + (on ? ' class="on"' : '') + (off ? ' disabled' : '') + '>' + label + '</button>';
    }
    out.push(btn('‹ Prev', p - 1, false, p === 1));
    var near = [1, n, p - 2, p - 1, p, p + 1, p + 2].filter(function (x, i, a) { return x >= 1 && x <= n && a.indexOf(x) === i; })
      .sort(function (a, b) { return a - b; });
    near.forEach(function (x, i) {
      if (i && x - near[i - 1] > 1) out.push('<span>…</span>');
      out.push(btn(x, x, x === p, false));
    });
    out.push(btn('Next ›', p + 1, false, p === n));
    pagesEl.innerHTML = out.join('');
  }
  pagesEl.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-page]');
    if (b && !b.disabled) { load(+b.dataset.page); archEl.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  });

  function render() {
    var from = meta.total ? (meta.page - 1) * meta.per_page + 1 : 0;
    countEl.textContent = (meta.total ? from + '–' + (from + rows.length - 1) + ' of ' + meta.total : '0') + ' slides · '
      + meta.used_total + ' already run';
    renderPages();
    if (!rows.length) {
      rowsEl.innerHTML = '<tr><td colspan="7" class="v2-empty">No slide matches.</td></tr>';
      return;
    }
    rowsEl.innerHTML = rows.map(function (s) {
      var last = s.runs[0];
      var runs = last
        ? '<span class="v2-used" title="This slide has been through V2 Diagnose before">● Used ×' + s.runs.length + '</span>'
          + '<a class="v2-runlink" href="' + last.url + '" target="_blank" onclick="event.stopPropagation()">#' + last.id + ' · '
          + esc(last.verdict ? VERDICT[last.verdict] : last.status) + ' · ' + esc(last.when) + '</a>'
        : '<span class="v2-fresh">Not run yet</span>';
      var pres = [s.site, s.laterality && !(s.site || '').toLowerCase().includes(s.laterality.toLowerCase()) ? s.laterality : null].filter(Boolean).join(' · ');
      var path = [s.stage, s.tnm].filter(Boolean).join(' · ');
      return '<tr data-id="' + s.id + '" class="' + (s.runs.length ? 'used' : '') + (String(s.id) === sid.value ? ' sel' : '') + '">'
        + '<td class="v2-act"><div class="v2-act-wrap">'
          + '<button type="button" class="v2-go" data-act="run" title="Run V2 Diagnose on this slide now">▶ Run analysis</button>'
        + '</div></td>'
        + '<td><div class="v2-id">' + esc(s.case || s.label) + '</div><div class="v2-sub">#' + s.id
          + (s.file ? ' · ' + esc(s.file.length > 34 ? s.file.slice(0, 32) + '…' : s.file) : '') + (s.stain ? '<br>' + esc(s.stain) : '') + '</div></td>'
        + '<td>' + dxBadge(s) + (s.primary_dx ? '<div class="v2-sub">' + esc(s.primary_dx) + '</div>' : '') + '</td>'
        + '<td>' + patient(s) + '</td>'
        + '<td>' + dash(pres) + (s.method ? '<div class="v2-sub">' + esc(s.method) + '</div>' : '') + '</td>'
        + '<td>' + dash(path) + (s.receptors ? '<div class="v2-sub">' + esc(s.receptors) + '</div>' : '') + '</td>'
        + '<td>' + runs + '</td></tr>';
    }).join('');
  }
  rowsEl.addEventListener('click', function (e) {
    var tr = e.target.closest('tr[data-id]');
    var s = tr && byId[tr.dataset.id];
    if (!s) return;
    var go = e.target.closest('[data-act="run"]');
    if (go) return runNow(s, go);
    choose(s); render();
  });

  // Straight from the row: the slide's own context goes with it, nothing to fill in.
  // Every run is confirmed first; a slide run before shows how those runs scored.
  function runNow(s, btn) {
    var name = esc(s.case || s.label) + ' <span style="color:#8a83a0;font-weight:400">· sample #' + s.id + '</span>';
    var truth = s.disease || s.category;
    var facts = [truth ? 'Recorded diagnosis: <b>' + esc(truth) + '</b>' : 'No recorded diagnosis — the answer cannot be scored.',
                 [s.sex ? cap(s.sex) : null, s.age != null ? s.age + ' y' : null, s.stain].filter(Boolean).map(esc).join(' · ')]
      .filter(Boolean).map(function (x) { return '<div>' + x + '</div>'; }).join('');
    var html, opts;

    if (!s.runs.length) {
      html = '<div class="v2-sw"><div class="v2-sw-name">' + name + '</div>' + facts
        + '<div class="v2-sw-note">This slide has not been analysed before.</div></div>';
      opts = { icon: 'question', title: 'Run V2 Diagnose on this slide?', confirmButtonText: '▶ Yes, run it' };
    } else {
      var WORD = { correct: '✓ Correct', partial: '≈ Partially correct', wrong: '✗ Wrong' };
      var runs = s.runs.map(function (r) {
        var score = r.verdict
          ? '<span class="v2-match ' + r.verdict + '">' + WORD[r.verdict] + '</span><div class="v2-sw-why">' + esc(r.reason || '') + '</div>'
          : '<span class="v2-sw-muted">' + (r.status !== 'completed' ? 'Run ' + esc(r.status) + ' — no answer to score'
              : 'Not scored — no recorded diagnosis') + '</span>';
        return '<tr><td><a href="' + r.url + '" target="_blank">#' + r.id + '</a><div class="v2-sw-muted">' + esc(r.when || '') + '</div></td>'
          + '<td>' + (r.diagnosis ? esc(r.diagnosis) : '<span class="v2-sw-muted">—</span>')
          + (r.confidence != null ? '<div class="v2-sw-muted">confidence ' + r.confidence + '%</div>' : '') + '</td>'
          + '<td>' + (r.truth ? esc(r.truth) : '<span class="v2-sw-muted">—</span>') + '</td><td>' + score + '</td></tr>';
      }).join('');
      var last = s.runs[0];
      var head = last.verdict === 'correct' ? 'The last analysis of this slide was correct'
               : last.verdict === 'wrong' ? 'The last analysis of this slide was wrong'
               : last.verdict === 'partial' ? 'The last analysis of this slide was partially correct'
               : 'This slide has been analysed before';
      html = '<div class="v2-sw"><div class="v2-sw-name">' + name + '</div>' + facts
        + '<table class="v2-sw-runs"><thead><tr><th>Run</th><th>AI answer</th><th>Recorded</th><th>Result</th></tr></thead><tbody>'
        + runs + '</tbody></table><div class="v2-sw-note">Run it again? The earlier runs stay as they are.</div></div>';
      opts = { icon: last.verdict === 'correct' ? 'success' : last.verdict === 'wrong' ? 'error' : last.verdict ? 'warning' : 'info',
               title: head, confirmButtonText: '▶ Run again', showDenyButton: true, denyButtonText: 'Open the last result' };
    }

    if (!window.Swal) { // the CDN did not load: still ask
      if (confirm((s.runs.length ? 'This slide has been analysed ' + s.runs.length + ' time(s) before. Run it again?'
                                 : 'Run V2 Diagnose on this slide?'))) go(s, btn);
      return;
    }
    Swal.fire(Object.assign({
      html: html, width: s.runs.length ? 760 : 520, showCancelButton: true, cancelButtonText: 'Cancel',
      confirmButtonColor: '#2c7a5f', denyButtonColor: '#6a55c2', cancelButtonColor: '#8a83a0',
      focusCancel: false, reverseButtons: false,
    }, opts)).then(function (res) {
      if (res.isConfirmed) go(s, btn);
      else if (res.isDenied) window.open(s.runs[0].url, '_blank');
    });
  }

  function go(s, btn) {
    choose(s, true); render();
    [btn, rowsEl.querySelector('tr[data-id="' + s.id + '"] [data-act="run"]'), document.getElementById('runPicked')]
      .forEach(function (b) { if (b) { b.disabled = true; b.textContent = 'Starting…'; } });
    form.requestSubmit ? form.requestSubmit() : form.submit();
  }

  function choose(s, quiet) {
    sid.value = s.id;
    current = s;
    var item = function (k, v) { return v == null || v === '' ? '' : '<div><dt>' + k + '</dt><dd>' + esc(v) + '</dd></div>'; };
    pickedEl.innerHTML = '<h4>Selected: ' + esc(s.case || s.label) + ' <span class="v2-hint">sample #' + s.id + '</span>'
      + (s.runs.length ? '<span class="v2-used">● Already run ×' + s.runs.length + '</span>' : '<span class="v2-fresh">Not run yet</span>')
      + ' <a href="' + s.url + '" target="_blank" class="v2-hint" style="margin-left:auto">Open sample page ↗</a></h4><dl>'
      + item('Recorded diagnosis', [s.disease, s.category && s.category !== s.disease ? '(' + s.category + ')' : null].filter(Boolean).join(' '))
      + item('Primary diagnosis (clinical record)', s.primary_dx)
      + item('Sex', cap(s.sex)) + item('Age', s.age != null ? s.age + ' years' : null) + item('Race', s.race)
      + item('Site', s.site) + item('Laterality', s.laterality) + item('Obtained by', s.method)
      + item('Tumour', s.tumour) + item('Metastasis at diagnosis', s.metastasis)
      + item('Stage', [s.stage, s.tnm].filter(Boolean).join(' · ')) + item('Lymph nodes', s.nodes) + item('Receptors', s.receptors)
      + item('Organ', s.organ) + item('Stain', s.stain) + item('Project', s.project) + item('File', s.file)
      + '</dl>'
      + (s.has_clinical ? '' : '<div class="v2-hint" style="margin-top:.6rem">No clinical record is linked to this slide — the context below has to be entered by hand.</div>')
      + (s.runs.length ? '<div class="v2-prev">' + s.runs.map(function (r) {
          return '<a href="' + r.url + '" target="_blank">' + (r.overview ? '<img src="' + r.overview + '" alt="" loading="lazy">' : '')
            + '<span><b>Run #' + r.id + '</b> · ' + esc(r.when) + '<br>' + esc(r.diagnosis || r.status)
            + (r.verdict ? ' · ' + VERDICT[r.verdict] : '') + '</span></a>';
        }).join('') + '</div>' : '')
      + '<button type="button" class="v2-go v2-run-picked" id="runPicked">▶ Run V2 Diagnose on this slide</button>';
    pickedEl.hidden = false;
    applyKnown(s.form, s);
    if (!quiet) pickedEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function unpick() { sid.value = ''; current = null; pickedEl.hidden = true; applyKnown(null); }
  pickedEl.addEventListener('click', function (e) {
    if (e.target.id === 'runPicked' && current) runNow(current, e.target);
  });

  /* ── Section 2: what the archive knows is filled and not asked for ───── */
  var FIELDS = ['organ', 'stain', 'age', 'sex', 'race', 'clinical_notes'];
  var LABEL = { organ: 'Organ', stain: 'Stain', age: 'Age', sex: 'Sex', race: 'Race', clinical_notes: 'Notes' };
  var dirty = {}, auto = {};
  function get(k) {
    if (k === 'sex') { var c = document.querySelector('#sex input:checked'); return c ? c.value : ''; }
    return document.getElementById(k).value;
  }
  function set(k, v) {
    v = v == null ? '' : String(v);
    if (k === 'sex') { var r = document.querySelector('#sex input[value="' + v + '"]'); if (r) r.checked = true; }
    else if (k === 'organ') organ.setValue(v);
    else if (k === 'stain') stain.setValue(v);
    else document.getElementById(k).value = v;
  }
  FIELDS.forEach(function (k) {
    var box = document.querySelector('[data-field="' + k + '"]');
    box.addEventListener('input', function () { dirty[k] = true; });
    box.addEventListener('change', function () { dirty[k] = true; });
    // Coming back with errors: what was sent is the user's, not the archive's.
    if (@json($errors->any()) && get(k)) dirty[k] = true;
  });

  function applyKnown(known, s) {
    var shown = [];
    FIELDS.forEach(function (k) {
      var box = document.querySelector('[data-field="' + k + '"]');
      var v = known && known[k] != null ? String(known[k]) : null;
      if (v !== null && (!dirty[k] || get(k) === v)) {
        set(k, v); auto[k] = v; dirty[k] = false;
        box.classList.add('v2-known');
        shown.push([k, k === 'sex' ? cap(v) : (k === 'age' ? v + ' years' : v)]);
      } else {
        if (auto[k] !== undefined && get(k) === auto[k]) set(k, '');
        delete auto[k];
        box.classList.remove('v2-known');
      }
    });
    var kb = document.getElementById('knownBox');
    var missing = known ? FIELDS.filter(function (k) { return !auto[k]; }).map(function (k) { return LABEL[k]; }) : [];
    kb.innerHTML = shown.length
      ? '<b>From the archive — not asked again:</b><ul>' + shown.map(function (p) { return '<li>' + LABEL[p[0]] + ': ' + esc(p[1]) + '</li>'; }).join('')
        + '</ul><div style="margin-top:.4rem">' + (missing.length ? 'Not on record: ' + esc(missing.join(', ')) + ' — fill in below if known. ' : '')
        + '<button type="button" id="editKnown">' + (form.classList.contains('show-known') ? 'Hide' : 'Edit') + ' these</button></div>'
        + '<div class="v2-hint">The recorded diagnosis, stage and receptors are never sent: they are what the answer is checked against.</div>'
      : '';
    kb.hidden = !shown.length;
    document.getElementById('ctxWait').hidden = !(src.value === 'existing' && !sid.value);
  }
  document.getElementById('knownBox').addEventListener('click', function (e) {
    if (e.target.id !== 'editKnown') return;
    form.classList.toggle('show-known');
    e.target.textContent = (form.classList.contains('show-known') ? 'Hide' : 'Edit') + ' these';
  });

  // The slide's own context applies only while a slide from the archive is the input.
  function onSource() {
    var existing = src.value === 'existing';
    document.getElementById('organ').required = !existing;
    if (existing && current) applyKnown(current.form);
    else applyKnown(null);
    if (!existing && !get('stain')) set('stain', 'H&E');
  }
  document.querySelectorAll('.v2-tab').forEach(function (t) { t.addEventListener('click', onSource); });

  form.addEventListener('submit', function (e) {
    if (src.value === 'existing' && !sid.value) {
      e.preventDefault();
      (archEl.hidden ? fOrgan : archEl).scrollIntoView({ behavior: 'smooth', block: 'center' });
      alert('Choose a slide from the table first: choose the organ, then click a row.');
    }
  });

  // A slide named in the URL, or the form coming back with errors: filter to it and pick it.
  if (picked && archive.some(function (o) { return o.id === picked.organ; })) {
    fOrgan.value = picked.organ; fillStains();
    load(1);
    // It may sit on any page: its record comes on its own.
    fetch(contextUrl + '/' + picked.id + '/context', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (s) { if (s) { choose(s, true); if (meta) render(); } });
  }
  onSource();
  /* ── Delete a run, after asking ───────────────────────────────────────── */
  document.querySelectorAll('.v2-del').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = b.dataset;
      if (d.running) {
        var msg = 'Run #' + d.id + ' is still ' + d.running + '. Wait for it to finish, then delete it.';
        return window.Swal ? Swal.fire({ icon: 'info', title: 'Still running', text: msg, confirmButtonColor: '#6a55c2' }) : alert(msg);
      }
      function send() { var f = document.getElementById('delForm'); f.action = d.del; f.submit(); }
      if (!window.Swal) { if (confirm('Delete run #' + d.id + '? This cannot be undone.')) send(); return; }
      Swal.fire({
        icon: 'warning', title: 'Delete run #' + d.id + '?',
        html: '<div class="v2-sw"><div class="v2-sw-name">' + esc(d.slide) + '</div>'
          + (d.dx ? '<div>AI answer: <b>' + esc(d.dx) + '</b></div>' : '')
          + '<div class="v2-sw-note">The result, its regions, heatmap and every file the run wrote are removed. This cannot be undone.</div></div>',
        showCancelButton: true, confirmButtonText: 'Yes, delete it', cancelButtonText: 'Cancel',
        confirmButtonColor: '#b03d64', cancelButtonColor: '#8a83a0', focusCancel: true,
      }).then(function (res) {
        if (!res.isConfirmed) return;
        b.disabled = true;
        send();
      });
    });
  });
  /* ── Results filter: each choice applies at once, and clears the finer ones ── */
  document.querySelectorAll('select[form="resultsFilter"]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      (sel.dataset.resets || '').split(' ').filter(Boolean).forEach(function (id) {
        var el = document.getElementById(id); if (el) el.value = '';
      });
      var f = document.getElementById('resultsFilter');
      // Empty and disabled fields stay out of the address.
      document.querySelectorAll('select[form="resultsFilter"]').forEach(function (el) {
        el.disabled = el.disabled || !el.value;
      });
      f.submit();
    });
  });
})();
</script>
@endsection
