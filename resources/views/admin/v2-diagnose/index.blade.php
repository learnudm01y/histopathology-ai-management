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
  .v2-eye{display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border-radius:6px;
          color:#4b3a94;background:#f3f1f8;font-size:1.15rem;text-decoration:none;transition:background .15s,color .15s}
  .v2-eye:hover{background:#4b3a94;color:#fff;text-decoration:none}
  table.v2-runs td:last-child{vertical-align:middle;text-align:center}
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

<div class="v2-grid">
  <form method="POST" action="{{ route('admin.v2-diagnose.store') }}" enctype="multipart/form-data" class="v2-card">
    @csrf
    <h3>1 · The slide</h3>
    <input type="hidden" name="source" id="source" value="{{ old('source', 'existing') }}">
    <div class="v2-tabs">
      <button type="button" class="v2-tab" data-src="existing">From the archive</button>
      <button type="button" class="v2-tab" data-src="server_path">Server path</button>
      <button type="button" class="v2-tab" data-src="upload">Upload</button>
    </div>

    <div class="v2-pane" data-src="existing">
      <label for="sample_search">Sample</label>
      <input type="hidden" name="sample_id" id="sample_id" value="{{ old('sample_id', $picked) }}">
      <div class="v2-combo" id="sampleCombo">
        <input type="text" id="sample_search" class="v2-in" autocomplete="off"
               placeholder="Search by case id, file name or #id">
        <button type="button" class="v2-clear" tabindex="-1" aria-label="Clear">&times;</button>
        <button type="button" class="v2-caret" tabindex="-1" aria-label="Show all"></button>
        <div class="v2-menu" role="listbox"></div>
      </div>
      <div class="v2-hint">{{ $samples->count() }} slides whose image is on Drive. Picking one fills in what the database knows about the patient.</div>
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

    <h3 style="margin-top:1.5rem">2 · Clinical context</h3>
    <div class="v2-row">
      <div>
        <label for="organ">Organ <span class="req">*</span></label>
        <div class="v2-combo" id="organCombo">
          <input type="text" name="organ" id="organ" class="v2-in" required autocomplete="off"
                 value="{{ old('organ') }}" placeholder="Choose or type">
          <button type="button" class="v2-clear" tabindex="-1" aria-label="Clear">&times;</button>
          <button type="button" class="v2-caret" tabindex="-1" aria-label="Show all"></button>
          <div class="v2-menu" role="listbox"></div>
        </div>
      </div>
      <div>
        <label for="stain">Stain</label>
        <div class="v2-combo" id="stainCombo">
          <input type="text" name="stain" id="stain" class="v2-in" autocomplete="off"
                 value="{{ old('stain', 'H&E') }}" placeholder="Choose or type">
          <button type="button" class="v2-clear" tabindex="-1" aria-label="Clear">&times;</button>
          <button type="button" class="v2-caret" tabindex="-1" aria-label="Show all"></button>
          <div class="v2-menu" role="listbox"></div>
        </div>
      </div>
      <div>
        <label for="age">Age</label>
        <input type="number" name="age" id="age" min="0" max="120" class="v2-in" value="{{ old('age') }}" placeholder="years">
      </div>
      <div>
        <label>Sex</label>
        <div class="v2-seg" id="sex">
          @foreach(['' => '—', 'female' => 'Female', 'male' => 'Male', 'other' => 'Other'] as $val => $text)
            <label><input type="radio" name="sex" value="{{ $val }}" @checked((string) old('sex', '') === $val)><span>{{ $text }}</span></label>
          @endforeach
        </div>
      </div>
    </div>
    <div class="v2-field">
      <label for="race">Race / ancestry <span class="v2-hint">(if known)</span></label>
      <input name="race" id="race" class="v2-in" value="{{ old('race') }}">
    </div>
    <div class="v2-field">
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

    <button class="btn btn-primary" style="margin-top:1rem;width:100%">Run V2 Diagnose</button>
  </form>

  <div class="v2-card">
    <h3>Every run</h3>
    @if($runs->isEmpty())
      <p style="color:#6b6480">No runs yet.</p>
    @else
    <div style="overflow-x:auto">
    <table class="v2-runs">
      <thead><tr><th>#</th><th>Slide</th><th>Context</th><th>Status</th><th>Diagnosis</th><th>When</th><th></th></tr></thead>
      <tbody>
      @foreach($runs as $r)
        <tr>
          <td><a href="{{ route('admin.v2-diagnose.show', $r) }}">#{{ $r->id }}</a></td>
          <td>{{ $r->sample?->entity_submitter_id ?: ($r->sample?->file_name ?: '—') }}
              <div class="v2-hint">sample #{{ $r->sample_id }}</div></td>
          <td>{{ $r->organ }}{{ $r->stain ? ' · '.$r->stain : '' }}
              <div class="v2-hint">{{ collect([$r->age ? $r->age.' y' : null, $r->sex, $r->race])->filter()->implode(' · ') }}</div></td>
          <td><span class="v2-st {{ in_array($r->status, ['completed','failed']) ? $r->status : 'run' }}">{{ $r->status }}</span></td>
          <td>{{ $r->diagnosis ?: '—' }}
              @if($r->confidence !== null)<div class="v2-hint">confidence {{ round($r->confidence * 100) }}% · {{ $r->regions_count }} regions</div>@endif</td>
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

  // Fill only the fields still empty: what the user typed wins over the database.
  var base = @json(url('admin/v2-diagnose/sample'));
  var sid = document.getElementById('sample_id');
  function prefill() {
    var id = parseInt(sid.value, 10);
    if (!id) return;
    fetch(base + '/' + id + '/context', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (c) {
        if (!c) return;
        if (c.organ && !document.getElementById('organ').value) organ.setValue(c.organ);
        if (c.stain && !document.getElementById('stain').value) stain.setValue(c.stain);
        ['age', 'race'].forEach(function (k) {
          var el = document.getElementById(k);
          if (c[k] != null && !el.value) el.value = c[k];
        });
        var none = document.querySelector('#sex input[value=""]');
        var sex = c.sex && document.querySelector('#sex input[value="' + String(c.sex).toLowerCase() + '"]');
        if (sex && none.checked) sex.checked = true;
      });
  }

  @php
    $sampleItems = $samples->map(fn ($s) => [
        'value' => $s->id,
        'label' => $s->entity_submitter_id ?: ($s->file_name ?: 'Sample #'.$s->id),
        'sub'   => '#'.$s->id.($s->entity_submitter_id && $s->file_name ? ' · '.\Illuminate\Support\Str::limit($s->file_name, 28) : ''),
    ])->values();
  @endphp
  var samples = @json($sampleItems);
  combo(document.getElementById('sampleCombo'), samples, { hidden: sid, onPick: prefill });
  if (sid.value) prefill();
})();
</script>
@endsection
