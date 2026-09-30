@extends('admin.layouts.app')

@section('title', 'V2 Diagnose')

@section('content')
<style>
  .v2-grid{display:grid;gap:1.25rem}
  @media(min-width:1100px){.v2-grid{grid-template-columns:minmax(0,30rem) minmax(0,1fr);align-items:start}}
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
  table.v2-runs{width:100%;border-collapse:collapse;font-size:.87rem}
  table.v2-runs td,table.v2-runs th{padding:.45rem .5rem;border-bottom:1px solid #f0eef4;text-align:left;vertical-align:top}
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
  table.v2-runs td:last-child{vertical-align:middle;text-align:center}

  /* Archive: organ → stain → Filter, then every slide in a table. */
  .v2-filter{display:grid;grid-template-columns:minmax(0,16rem) minmax(0,20rem) auto;gap:.9rem;align-items:end}
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
  table.v2-arch td.v2-radio{width:1.8rem;padding-right:0}
  table.v2-arch td.v2-radio input{margin-top:.2rem;accent-color:#6a55c2;pointer-events:none}
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
        <div>
          <label for="f_stain">Stain <span class="req">*</span></label>
          <select id="f_stain" class="v2-in" disabled><option value="">Choose the organ first</option></select>
        </div>
        <div><button type="button" id="f_go" class="btn btn-primary" disabled>Filter</button></div>
      </div>
      <div class="v2-hint">{{ $archive->sum('n') }} slides whose image is on Drive. Slides already run through V2 Diagnose are marked.</div>

      <div id="arch" hidden>
        <div class="v2-archbar">
          <input type="search" id="f_q" class="v2-in" placeholder="Search case id, file, diagnosis, site…" autocomplete="off">
          <label class="v2-chk"><input type="checkbox" id="f_unused"> Only slides not run before</label>
          <span class="v2-count" id="f_count"></span>
        </div>
        <div class="v2-tablewrap">
          <table class="v2-arch">
            <thead><tr><th></th><th>Slide</th><th>Recorded diagnosis</th><th>Patient</th><th>Presentation</th><th>Pathology</th><th>V2 runs</th></tr></thead>
            <tbody id="f_rows"></tbody>
          </table>
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

  <div class="v2-card">
    <h3>Every run</h3>
    @php $scored = array_sum($tally); @endphp
    @if($scored)
    <div class="v2-tally" title="Finished runs whose slide has a recorded diagnosis in the archive, compared with it.">
      Against the recorded diagnosis ({{ $scored }} runs):
      <span class="v2-match correct">✓ {{ $tally['correct'] }} correct</span>
      <span class="v2-match partial">≈ {{ $tally['partial'] }} partial</span>
      <span class="v2-match wrong">✗ {{ $tally['wrong'] }} wrong</span>
      <span>· {{ round($tally['correct'] / $scored * 100) }}% fully correct</span>
    </div>
    @endif
    @if($runs->isEmpty())
      <p style="color:#6b6480">No runs yet.</p>
    @else
    <div style="overflow-x:auto">
    <table class="v2-runs">
      <thead><tr><th>#</th><th>Slide</th><th>Context</th><th>Status</th><th>Diagnosis</th><th>Recorded</th><th>Match</th><th>When</th><th></th></tr></thead>
      <tbody>
      @foreach($runs as $r)
        @php $v = $r->verdict(); $truth = $r->recordedClass(); @endphp
        <tr>
          <td><a href="{{ route('admin.v2-diagnose.show', $r) }}">#{{ $r->id }}</a></td>
          <td>{{ $r->sample?->entity_submitter_id ?: ($r->sample?->file_name ?: '—') }}
              <div class="v2-hint">sample #{{ $r->sample_id }}</div></td>
          <td>{{ $r->organ }}{{ $r->stain ? ' · '.$r->stain : '' }}
              <div class="v2-hint">{{ collect([$r->age ? $r->age.' y' : null, $r->sex, $r->race])->filter()->implode(' · ') }}</div></td>
          <td><span class="v2-st {{ in_array($r->status, ['completed','failed']) ? $r->status : 'run' }}">{{ $r->status }}</span></td>
          <td>{{ $r->diagnosis ?: '—' }}
              @if($r->confidence !== null)<div class="v2-hint">confidence {{ round($r->confidence * 100) }}% · {{ $r->regions_count }} regions</div>@endif</td>
          <td>{{ $truth ?: '—' }}
              @if(! $truth)<div class="v2-hint">no recorded diagnosis</div>@endif</td>
          <td>
            @if($v)
              <span class="v2-match {{ $v['result'] }}" title="{{ $v['reason'] }}">
                {{ ['correct' => '✓ Correct', 'partial' => '≈ Partial', 'wrong' => '✗ Wrong'][$v['result']] }}</span>
              <div class="v2-hint">{{ $v['reason'] }}</div>
            @else
              <span class="v2-hint">—</span>
            @endif
          </td>
          <td class="v2-hint">{{ $r->created_at->format('Y-m-d H:i') }}</td>
          <td><a href="{{ route('admin.v2-diagnose.show', $r) }}" class="v2-eye" title="Open run #{{ $r->id }}: slide, regions, heatmap"
                 aria-label="Open run #{{ $r->id }}"><i class="mdi mdi-eye-outline"></i></a></td>
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
  var fGo = document.getElementById('f_go'), fQ = document.getElementById('f_q'), fUnused = document.getElementById('f_unused');
  var rowsEl = document.getElementById('f_rows'), countEl = document.getElementById('f_count');
  var archEl = document.getElementById('arch'), pickedEl = document.getElementById('picked');
  var rows = [], byId = {}, truncated = false;
  var archiveUrl = @json(route('admin.v2-diagnose.archive'));
  var VERDICT = { correct: '✓ correct', partial: '≈ partial', wrong: '✗ wrong' };

  function fillStains() {
    var o = archive.find(function (x) { return String(x.id) === fOrgan.value; });
    fStain.innerHTML = o
      ? '<option value="">Choose a stain</option>'
        + o.stains.map(function (s) { return '<option value="' + s.id + '">' + esc(s.name) + ' (' + s.n + ')</option>'; }).join('')
        + (o.stains.length > 1 ? '<option value="all">Any stain (' + o.n + ')</option>' : '')
      : '<option value="">Choose the organ first</option>';
    fStain.disabled = !o;
    if (o && o.stains.length === 1) fStain.value = o.stains[0].id;
    fGo.disabled = !fStain.value;
  }
  fOrgan.addEventListener('change', fillStains);
  fStain.addEventListener('change', function () { fGo.disabled = !fStain.value; });

  function load(thenPick) {
    if (!fOrgan.value || !fStain.value) return;
    fGo.disabled = true; fGo.textContent = 'Loading…';
    fetch(archiveUrl + '?organ_id=' + encodeURIComponent(fOrgan.value) + '&stain=' + encodeURIComponent(fStain.value),
          { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) {
        rows = d.samples; truncated = d.truncated; byId = {};
        rows.forEach(function (s) { byId[s.id] = s; });
        archEl.hidden = false;
        if (byId[sid.value]) choose(byId[sid.value], true);
        else if (!thenPick) unpick();
        render();
      })
      .catch(function (e) {
        archEl.hidden = false; rows = []; render();
        rowsEl.innerHTML = '<tr><td colspan="7" class="v2-empty">Could not load the slides (' + esc(e.message) + ').</td></tr>';
      })
      .finally(function () { fGo.disabled = false; fGo.textContent = 'Filter'; });
  }
  fGo.addEventListener('click', function () { load(false); });

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
  function haystack(s) {
    return [s.id, s.label, s.file, s.case, s.project, s.disease, s.category, s.primary_dx, s.site, s.laterality,
            s.method, s.stage, s.receptors, s.sex, s.race].filter(Boolean).join(' ').toLowerCase();
  }

  function render() {
    var words = fQ.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    var list = rows.filter(function (s) {
      if (fUnused.checked && s.runs.length) return false;
      var h = haystack(s);
      return words.every(function (w) { return h.indexOf(w.replace(/^#/, '')) !== -1; });
    });
    var used = rows.filter(function (s) { return s.runs.length; }).length;
    countEl.textContent = list.length + ' of ' + rows.length + ' slides · ' + used + ' already run'
      + (truncated ? ' · only the newest ' + rows.length + ' shown' : '');
    if (!list.length) {
      rowsEl.innerHTML = '<tr><td colspan="7" class="v2-empty">' + (rows.length ? 'No slide matches.' : 'No slide in the archive for this organ and stain.') + '</td></tr>';
      return;
    }
    rowsEl.innerHTML = list.map(function (s) {
      var last = s.runs[0];
      var runs = last
        ? '<span class="v2-used" title="This slide has been through V2 Diagnose before">● Used ×' + s.runs.length + '</span>'
          + '<a class="v2-runlink" href="' + last.url + '" target="_blank" onclick="event.stopPropagation()">#' + last.id + ' · '
          + esc(last.verdict ? VERDICT[last.verdict] : last.status) + ' · ' + esc(last.when) + '</a>'
        : '<span class="v2-fresh">Not run yet</span>';
      var pres = [s.site, s.laterality && !(s.site || '').toLowerCase().includes(s.laterality.toLowerCase()) ? s.laterality : null].filter(Boolean).join(' · ');
      var path = [s.stage, s.tnm].filter(Boolean).join(' · ');
      return '<tr data-id="' + s.id + '" class="' + (s.runs.length ? 'used' : '') + (String(s.id) === sid.value ? ' sel' : '') + '">'
        + '<td class="v2-radio"><input type="radio" tabindex="-1"' + (String(s.id) === sid.value ? ' checked' : '') + '></td>'
        + '<td><div class="v2-id">' + esc(s.case || s.label) + '</div><div class="v2-sub">#' + s.id
          + (s.file ? ' · ' + esc(s.file.length > 34 ? s.file.slice(0, 32) + '…' : s.file) : '') + (s.stain ? '<br>' + esc(s.stain) : '') + '</div></td>'
        + '<td>' + dxBadge(s) + (s.primary_dx ? '<div class="v2-sub">' + esc(s.primary_dx) + '</div>' : '') + '</td>'
        + '<td>' + patient(s) + '</td>'
        + '<td>' + dash(pres) + (s.method ? '<div class="v2-sub">' + esc(s.method) + '</div>' : '') + '</td>'
        + '<td>' + dash(path) + (s.receptors ? '<div class="v2-sub">' + esc(s.receptors) + '</div>' : '') + '</td>'
        + '<td>' + runs + '</td></tr>';
    }).join('');
  }
  fQ.addEventListener('input', render);
  fUnused.addEventListener('change', render);
  rowsEl.addEventListener('click', function (e) {
    var tr = e.target.closest('tr[data-id]');
    if (tr && byId[tr.dataset.id]) { choose(byId[tr.dataset.id]); render(); }
  });

  function choose(s, quiet) {
    sid.value = s.id;
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
        }).join('') + '</div>' : '');
    pickedEl.hidden = false;
    applyKnown(s.form, s);
    if (!quiet) pickedEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function unpick() { sid.value = ''; pickedEl.hidden = true; applyKnown(null); }

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
    if (existing && byId[sid.value]) applyKnown(byId[sid.value].form);
    else applyKnown(null);
    if (!existing && !get('stain')) set('stain', 'H&E');
  }
  document.querySelectorAll('.v2-tab').forEach(function (t) { t.addEventListener('click', onSource); });

  form.addEventListener('submit', function (e) {
    if (src.value === 'existing' && !sid.value) {
      e.preventDefault();
      (archEl.hidden ? fOrgan : archEl).scrollIntoView({ behavior: 'smooth', block: 'center' });
      alert('Choose a slide from the table first: organ, stain, Filter, then click a row.');
    }
  });

  // A slide named in the URL, or the form coming back with errors: filter to it and pick it.
  if (picked && archive.some(function (o) { return o.id === picked.organ; })) {
    fOrgan.value = picked.organ; fillStains();
    if ([].some.call(fStain.options, function (o) { return o.value === picked.stain; })) fStain.value = picked.stain;
    fGo.disabled = !fStain.value;
    load(true);
  }
  onSource();
})();
</script>
@endsection
