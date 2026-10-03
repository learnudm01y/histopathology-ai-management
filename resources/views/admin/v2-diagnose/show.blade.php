@extends('admin.layouts.app')

@section('title', 'V2 Diagnose #' . $run->id)

@push('styles')
{{-- In <head>: full page detaches the page body, and styles kept there would go with it. --}}
<style>
  .v2-card{background:#fff;border:1px solid #e3e0ea;border-radius:8px;padding:1rem 1.2rem;margin-bottom:1rem}
  .v2-card h3{margin:0 0 .75rem;font-size:1rem;font-weight:600}
  .v2-top{display:grid;gap:1rem}
  @media(min-width:1100px){.v2-top{grid-template-columns:minmax(0,1fr) 24rem}}
  .v2-summary{white-space:pre-line;font-size:.95rem;line-height:1.6;background:#f5f3fa;border-radius:6px;padding:.8rem 1rem}
  .v2-dx{font-size:1.35rem;font-weight:700;margin:.1rem 0 .5rem}
  .v2-kv{display:flex;justify-content:space-between;padding:.28rem 0;font-size:.86rem;border-bottom:1px solid #f3f1f7}
  .v2-kv:last-child{border-bottom:0}
  .v2-kv b{font-variant-numeric:tabular-nums;text-align:right}
  .v2-ev{font-size:.82rem;color:#57516a;border-left:2px solid #e3e0ea;padding:.15rem 0 .15rem .6rem;margin-bottom:.2rem}
  .v2-ev time{color:#9a93ad;margin-right:.4rem}
  .v2-err{background:#fbe9f0;border:1px solid #f0c2d3;border-radius:6px;padding:.7rem .9rem;font-size:.87rem;margin-bottom:.8rem;white-space:pre-wrap}
  .v2-warn{background:#faf0da;border:1px solid #e8d19a;border-radius:6px;padding:.6rem .85rem;font-size:.85rem;margin-top:.7rem}
  .vw-wrap{display:grid;gap:0;grid-template-columns:minmax(0,1fr)}
  @media(min-width:1100px){.vw-wrap{grid-template-columns:minmax(0,1fr) 20rem}}
  .vw-bar{display:flex;gap:.9rem;align-items:center;flex-wrap:wrap;padding:.6rem .8rem;
          background:#faf9fc;border:1px solid #e6e2ef;border-radius:8px 8px 0 0}
  .vw-bar label{margin:0;font-size:.86rem;display:flex;align-items:center;gap:.35rem;font-weight:500}
  #osd{height:calc(100vh - 260px);min-height:30rem;background:#111;border:1px solid #e6e2ef;border-top:0;position:relative}
  .vw-side{border:1px solid #e6e2ef;border-left:0;height:calc(100vh - 260px + 2.9rem);min-height:32.9rem;overflow:auto;background:#fff}
  @media(max-width:1099px){.vw-side{border-left:1px solid #e6e2ef;height:auto;max-height:24rem}}
  .vw-side h4{font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:#8a83a0;margin:.8rem .8rem .4rem}
  .vw-item{padding:.45rem .8rem;border-top:1px solid #f3f1f7;cursor:pointer;font-size:.84rem}
  .vw-item:hover,.vw-item.on{background:#f3f1fb}
  .vw-item small{color:#8a83a0;display:block}
  .vw-chip{width:12px;height:12px;border-radius:3px;display:inline-block;vertical-align:-1px;margin-right:.3rem}
  .vw-tip{position:absolute;pointer-events:none;background:rgba(20,16,32,.92);color:#fff;font-size:.8rem;
          padding:.35rem .55rem;border-radius:5px;max-width:18rem;z-index:10;display:none}
  .vw-legend{display:flex;align-items:center;gap:.4rem;font-size:.8rem;color:#57516a}
  .v2-sheets{display:grid;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:.6rem}
  .v2-sheets img{width:100%;border-radius:4px;border:1px solid #e3e0ea}
  /* Zoomed out, thousands of nest borders would merge into one solid mass:
     below ~1 screen px per 30 um the mask is shown as a light fill only. */
  #osd svg g.lowzoom path{stroke-opacity:0;fill-opacity:.28}
  .vw-bar.vw-bar-fp{margin:10px;border-radius:8px;background:rgba(250,249,252,.94);
                     box-shadow:0 2px 10px rgba(0,0,0,.25);width:max-content;max-width:calc(100vw - 190px)}
  .vw-status{position:absolute;z-index:11;left:50%;top:14px;transform:translateX(-50%);padding:.45rem .9rem;
             border-radius:6px;background:rgba(20,16,32,.88);color:#fff;font-size:.85rem;pointer-events:auto}
  .vw-status.err{background:#b03d64;cursor:pointer}
  .v2-vs{border-radius:6px;padding:.5rem .8rem;margin:0 0 .7rem;font-size:.9rem;border:1px solid}
  .v2-vs.correct{background:#e4f1ec;border-color:#b9ddd0;color:#1f5446}
  .v2-vs.partial{background:#faf0da;border-color:#e8d19a;color:#6b4e0e}
  .v2-vs.wrong{background:#fbe9f0;border-color:#f0c2d3;color:#8c2a4d}
  .v2-vs.review{background:#eceaf4;border-color:#d6d2e6;color:#3f3a56}
  .v2-vs.none{background:#f5f3fa;border-color:#e3e0ea;color:#6b6480}
  .v2-code{display:inline-block;background:#4b3a94;color:#fff;border-radius:6px;padding:.05rem .55rem;margin-right:.35rem;letter-spacing:.03em}
  /* Live progress of a run */
  .pg-head{display:flex;justify-content:space-between;align-items:baseline;gap:1rem}
  .pg-head h3{margin:0}
  .pg-pct{font-size:1.9rem;font-weight:700;color:#4b3a94;font-variant-numeric:tabular-nums;line-height:1}
  .pg-bar{height:12px;background:#eeebf5;border-radius:6px;overflow:hidden;margin:.7rem 0 .9rem}
  .pg-fill{height:100%;background:linear-gradient(90deg,#6c5bc4,#4b3a94);border-radius:6px;transition:width .8s ease;position:relative;overflow:hidden}
  .pg-fill::after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.35),transparent);
                  animation:pg-shine 1.8s linear infinite;transform:translateX(-100%)}
  .pg-failed .pg-fill{background:#b03d64}.pg-failed .pg-fill::after{display:none}
  @keyframes pg-shine{to{transform:translateX(100%)}}
  .pg-now{background:#f5f3fa;border-radius:6px;padding:.65rem .85rem;display:flex;gap:.7rem;align-items:flex-start}
  .pg-now b{display:block;font-size:.98rem;color:#2c2540}
  .pg-now .pg-det{font-size:.87rem;color:#57516a;margin-top:.15rem}
  .pg-meta{font-size:.8rem;color:#8a83a0;margin:.45rem 0 .2rem;font-variant-numeric:tabular-nums}
  .pg-spin{flex:none;width:18px;height:18px;margin-top:2px;border:2.5px solid #d9d3ea;border-top-color:#4b3a94;border-radius:50%;animation:pg-rot .9s linear infinite}
  @keyframes pg-rot{to{transform:rotate(360deg)}}
  .pg-steps{list-style:none;margin:.8rem 0 0;padding:0}
  .pg-steps li{display:flex;align-items:center;gap:.6rem;padding:.38rem 0;font-size:.88rem;border-bottom:1px solid #f3f1f7}
  .pg-steps li:last-child{border-bottom:0}
  .pg-steps .ic{flex:none;width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700}
  .pg-steps .done .ic{background:#e4f1ec;color:#1f7a5a}
  .pg-steps .done{color:#57516a}
  .pg-steps .active{color:#2c2540;font-weight:600}
  .pg-steps .active .ic{border:2.5px solid #d9d3ea;border-top-color:#4b3a94;animation:pg-rot .9s linear infinite;width:16px;height:16px;margin:0 2px}
  .pg-steps .pending{color:#aaa3bd}
  .pg-steps .pending .ic{border:2px solid #e3e0ea}
  .pg-steps .failed{color:#b03d64;font-weight:600}
  .pg-steps .failed .ic{background:#fbe9f0;color:#b03d64}
  .pg-steps .lbl{flex:1}
  .pg-steps time{font-size:.78rem;color:#9a93ad;font-weight:400;font-variant-numeric:tabular-nums}
  .pg-mini{height:4px;background:#eeebf5;border-radius:2px;width:5rem;overflow:hidden}
  .pg-mini i{display:block;height:100%;background:#6c5bc4;transition:width .8s ease}
</style>
@endpush

@section('content')

<div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:.6rem;margin-bottom:.8rem">
  <h2 style="margin:0">V2 Diagnose #{{ $run->id }} ·
    <span style="font-weight:400">{{ $run->sample?->entity_submitter_id ?: ($run->sample?->file_name ?: 'slide') }}</span></h2>
  <div style="display:flex;gap:.5rem">
    @unless($run->isRunning())
    <form method="POST" action="{{ route('admin.v2-diagnose.rerun', $run) }}">@csrf
      <button class="btn btn-sm btn-outline-secondary">Re-run with the same inputs</button></form>
    @endunless
    <a href="{{ route('admin.v2-diagnose') }}" class="btn btn-sm btn-outline-secondary">← all runs</a>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

<div class="v2-top">
  <div class="v2-card">
    @if($run->status === 'completed')
      <h3>AI reading</h3>
      @php
        $code = $run->diagnosis_code;
        $name = $code ? trim(preg_replace('/^' . preg_quote($code, '/') . '\s*[—:-]\s*/u', '', (string) $run->diagnosis)) : $run->diagnosis;
      @endphp
      <div class="v2-dx">@if($code)<span class="v2-code">{{ $code }}</span> @endif{{ $name }}
        <span style="font-weight:400;font-size:1rem;color:#6b6480">· confidence {{ round(($run->confidence ?? 0) * 100) }}%</span></div>
      @php $v = $run->verdict(); @endphp
      <div class="v2-vs {{ $v['result'] ?? 'none' }}">
        @if($v)
          <b>{{ ['correct' => '✓ Correct', 'partial' => '≈ Partially correct', 'wrong' => '✗ Wrong', 'review' => '⚑ Label under review'][$v['result']] }}</b>
          against the recorded diagnosis <b>{{ $v['truth'] }}</b> — {{ $v['reason'] }}.
        @else
          No recorded diagnosis for this slide in the archive, so the answer cannot be checked against one.
        @endif
      </div>
      <div class="v2-summary">{{ $run->summary }}</div>
      @if(!empty($run->warnings))
        <div class="v2-warn">@foreach($run->warnings as $w)<div>{{ $w }}</div>@endforeach</div>
      @endif
      <p style="font-size:.8rem;color:#8a83a0;margin:.7rem 0 0">
        An automated first-pass reading by an AI model, for research. Not a diagnosis; a pathologist decides.</p>
    @else
      @if($run->status === 'failed')
        <h3>The run failed</h3>
        <div class="v2-err">{{ $run->error }}</div>
      @endif
      <div id="pg" class="{{ $run->status === 'failed' ? 'pg-failed' : '' }}">
        <div class="pg-head">
          <h3>{{ $run->status === 'failed' ? 'Where it stopped' : 'Analysis in progress' }}</h3>
          <span class="pg-pct" id="pgPct">{{ $progress['percent'] }}%</span>
        </div>
        <div class="pg-bar"><div class="pg-fill" id="pgFill" style="width:{{ $progress['percent'] }}%"></div></div>
        @unless($run->status === 'failed')
        <div class="pg-now">
          <span class="pg-spin"></span>
          <div><b id="pgLabel">{{ $progress['label'] }}</b><div class="pg-det" id="pgDetail"></div></div>
        </div>
        <div class="pg-meta" id="pgMeta"></div>
        @endunless
        <ol class="pg-steps" id="pgSteps"></ol>
        @unless($run->status === 'failed')
        <p style="font-size:.8rem;color:#8a83a0;margin:.6rem 0 0">Updates live; the page shows the result by itself when the run ends.</p>
        @endunless
      </div>
    @endif
  </div>

  <div class="v2-card">
    <h3>Run record</h3>
    <div class="v2-kv"><span>Context</span><b>{{ $run->organ }}{{ $run->stain ? ' · '.$run->stain : '' }}</b></div>
    <div class="v2-kv"><span>Patient</span><b>{{ collect([$run->age !== null ? $run->age.' y' : null, $run->sex, $run->race])->filter()->implode(' · ') ?: '—' }}</b></div>
    <div class="v2-kv"><span>Tiles</span><b>{{ $run->patches ?? '—' }}{{ $run->patch_size ? ' × '.$run->patch_size.' px @ '.$run->target_mpp.' µm/px' : '' }}</b></div>
    @php $cov = $final['coverage'] ?? null; @endphp
    <div class="v2-kv" title="Share of the slide's tissue that was tiled and read. Before full-slide tiling, large slides were read only in part.">
      <span>Tissue read</span>
      <b style="{{ isset($cov['tissue_read']) && $cov['tissue_read'] < 0.99 ? 'color:#b03d64' : '' }}">
        {{ isset($cov['tissue_read']) ? round($cov['tissue_read'] * 100, 1).'% of the slide' : 'not measured (older run)' }}
        @if($cov && isset($cov['tumour_tiles'])) · {{ $cov['tumour_tiles'] }} tumour tiles @endif
      </b></div>
    <div class="v2-kv"><span>Slide</span><b>{{ $run->slide_width ? number_format($run->slide_width).' × '.number_format($run->slide_height) : '—' }}</b></div>
    @php
      $u = $run->usage ?? [];
      $tok = fn ($n) => $n >= 1e6 ? round($n / 1e6, 1).'M' : ($n >= 1e3 ? round($n / 1e3).'k' : (string) $n);
      $read = ($u['input_tokens'] ?? 0) + ($u['cache_read_input_tokens'] ?? 0) + ($u['cache_creation_input_tokens'] ?? 0);
    @endphp
    <div class="v2-kv"><span>Turns · time</span><b>{{ $run->num_turns ?? '—' }} · {{ $run->duration_ms ? round($run->duration_ms / 60000, 1).' min' : '—' }}</b></div>
    <div class="v2-kv" title="What the analysis consumed. Cached reads count for much less than fresh ones."><span>Tokens read · written</span>
      <b>{{ $u ? $tok($read).' ('.$tok($u['cache_read_input_tokens'] ?? 0).' cached) · '.$tok($u['output_tokens'] ?? 0) : '—' }}</b></div>
    <div class="v2-kv"><span>SAM masks</span><b>{{ $run->sam_refined ? 'refined by SAM' : 'prompts only' }}</b></div>
    <div class="v2-kv"><span>By · at</span><b>{{ $run->user?->name ?? '—' }} · {{ $run->created_at->format('Y-m-d H:i') }}</b></div>
    @if($run->run_dir)
    <div style="margin-top:.6rem;font-size:.82rem">Records:
      @foreach(['final' => 'result (slide coords)', 'result' => 'raw result', 'prompt' => 'instructions', 'tiling' => 'tiling', 'density' => 'density'] as $k => $lbl)
        <a href="{{ route('admin.v2-diagnose.download', [$run, $k]) }}">{{ $lbl }}</a>@if(!$loop->last) · @endif
      @endforeach
    </div>
    @endif
    <details style="margin-top:.6rem"><summary style="font-size:.84rem;cursor:pointer">Stage log</summary>
      <div id="evLog" style="margin-top:.4rem">
        @foreach($run->events ?? [] as $e)
          <div class="v2-ev"><time>{{ substr($e['at'], 11) }}</time>{{ $e['message'] }}</div>
        @endforeach
      </div>
    </details>
  </div>
</div>

@if($viewer)
<div class="vw-wrap">
  <div>
    <div class="vw-bar">
      <label>Heat
        <select id="heatWhich" class="form-control" style="height:2rem;width:12rem;padding:.1rem .4rem">
          <option value="model">Tumour heatmap</option>
          <option value="density">Measured nuclear density</option>
          <option value="off">Off</option>
        </select>
      </label>
      <label>Strength <input type="range" id="heatOpacity" min="0" max="100" value="60" style="width:6.5rem"></label>
      <label title="Hide heat cells whose value is below this level">Hide below
        <input type="range" id="heatFloor" min="0" max="80" value="10" style="width:6.5rem">
        <span id="heatFloorPct" style="width:2.4rem;font-variant-numeric:tabular-nums">10%</span></label>
      <label title="Precise tumour border, cut from the pixel-level mask"><input type="checkbox" id="lyMask" checked> Tumour mask</label>
      <label title="The model's own rough region borders"><input type="checkbox" id="lyPoly" checked> Outlines</label>
      <label title="SAM prompts: green inside, red outside"><input type="checkbox" id="lyPts" checked> SAM points</label>
      <label id="lySamLbl" title="SAM's own masks"><input type="checkbox" id="lySam" checked> SAM masks</label>
      <label title="Each region's bounding box (dashed yellow)"><input type="checkbox" id="lyBox"> ROI boxes</label>
      <label title="The tiles analysed (dotted, coloured by tumour score)"><input type="checkbox" id="lyTiles"> Tiles</label>
      <span class="vw-legend"><span class="vw-chip" style="background:linear-gradient(90deg,#3b4cc0,#22d0d0,#a3f24a,#f9a825,#c0392b);width:52px"></span>low → high</span>
    </div>
    <div id="vwHint" style="display:none;background:#faf0da;border:1px solid #e8d19a;border-top:0;padding:.45rem .8rem;font-size:.82rem;color:#5c4a14"></div>
    <div id="osd"><div class="vw-tip" id="tip"></div><div class="vw-status" id="vwStatus" style="display:none"></div></div>
  </div>
  <div class="vw-side" id="side">
    @if(!$final)<p style="padding:.8rem;color:#8a83a0;font-size:.85rem">Regions appear here when the run completes.</p>@endif
  </div>
</div>
<p style="font-size:.82rem;color:#6b6480;margin:.6rem 0 1rem;line-height:1.55">
  <strong>Reading the layers.</strong> The <em>tumour mask</em> is the precise border: cut by the platform from a pixel-level nuclear-density map inside each region marked as tumour, minus what was marked as not tumour (negative points, lymphoid and other regions). <em>Outlines</em> are the model's own rough region borders (green tumour, amber in-situ, red LVI,
  grey necrosis, blue lymphoid). <em>SAM points</em> are the prompts for SAM — green inside, red outside; <em>SAM masks</em> are SAM's own mask where SAM
  was run. The <em>measured density</em> is lymphocyte-suppressed nuclear density, not a tumour probability; the <em>tumour heatmap</em>
  is that density kept inside the tumour mask and damped elsewhere, or the model's own grid where it gave one.
  Click a region to go to it.
</p>
@else
<div class="v2-card" style="color:#6b6480">The live slide cannot be shown: the image is not readable on this server
  or the tile service could not be told about it.</div>
@endif

@if($run->run_dir && in_array($run->status, ['analysing','finalising','completed'], true))
@php
  // JPEG where the run has it: the PNGs of earlier runs were 6-7 MB each and
  // held up the viewer's own data on a slow link.
  $img = fn ($n) => is_file("{$run->run_dir}/{$n}.jpg") ? "{$n}.jpg" : (is_file("{$run->run_dir}/{$n}.png") ? "{$n}.png" : null);
  $coverageImg = $img('coverage'); $overviewImg = $img('overview');
@endphp
<details class="v2-card" id="tilesCard">
  <summary style="cursor:pointer;font-weight:600;font-size:1rem">
    Tiles analysed — coverage map, overview and {{ (int) ceil(($run->patches ?? 0) / 16) }} contact sheets</summary>
  @if($coverageImg)
  <p style="font-size:.82rem;color:#6b6480;margin:.6rem 0 .7rem">
    First image: the coverage map — tissue that was read in green, tissue left out in red, with the tile grid.</p>
  @endif
  {{-- Loaded only when opened, so they never compete with the viewer's data. --}}
  <div class="v2-sheets">
    @foreach(array_filter([$coverageImg, $overviewImg]) as $n)
    <a href="{{ route('admin.v2-diagnose.asset', [$run, $n]) }}" target="_blank">
      <img data-src="{{ route('admin.v2-diagnose.asset', [$run, $n]) }}" alt="{{ $n }}"></a>
    @endforeach
    @for($i = 1; $i <= (int) ceil(($run->patches ?? 0) / 16); $i++)
      @php $p = sprintf('sheets/sheet_%02d.jpg', $i); @endphp
      <a href="{{ route('admin.v2-diagnose.asset', [$run, $p]) }}" target="_blank">
        <img data-src="{{ route('admin.v2-diagnose.asset', [$run, $p]) }}" alt="sheet {{ $i }}"></a>
    @endfor
  </div>
</details>
<script>
document.getElementById('tilesCard').addEventListener('toggle', function () {
  if (!this.open) return;
  this.querySelectorAll('img[data-src]').forEach(function (i) { i.src = i.dataset.src; i.removeAttribute('data-src'); });
});
</script>
@endif

@if($progress)
<script>
(function () {
  var running = @json($run->isRunning());
  var P = @json($progress), msg = @json($run->stage_message);
  var shown = 0, skew = 0;               // the bar never moves back; server clock minus ours
  var $ = function (id) { return document.getElementById(id); };

  function dur(s) {
    s = Math.max(0, Math.round(s));
    var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
    return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (x < 10 ? '0' : '') + x;
  }
  function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

  function render() {
    shown = running ? Math.max(shown, P.percent) : P.percent;
    $('pgPct').textContent = shown + '%';
    $('pgFill').style.width = shown + '%';
    if (running) {
      $('pgLabel').textContent = P.label;
      $('pgDetail').textContent = P.detail || msg || '';
    }
    var icon = { done: '✓', failed: '!', active: '', pending: '' };
    $('pgSteps').innerHTML = P.steps.map(function (s) {
      var right = '';
      if (s.state === 'done' && s.seconds !== null) right = '<time>' + dur(s.seconds) + '</time>';
      if (s.state === 'active' && P.frac !== null) right = '<span class="pg-mini"><i style="width:' + Math.round(P.frac * 100) + '%"></i></span>';
      if (s.state === 'active' && P.since) right += ' <time data-since>' + dur(Date.now() / 1000 + skew - P.since) + '</time>';
      return '<li class="' + s.state + '"><span class="ic">' + icon[s.state] + '</span><span class="lbl">' + esc(s.label) + '</span>' + right + '</li>';
    }).join('');
    tick();
  }

  // Clocks run every second between polls.
  function tick() {
    if (!running) return;
    var now = Date.now() / 1000 + skew;
    $('pgMeta').textContent = 'Elapsed ' + dur(now - P.began) + (P.since ? ' · this step ' + dur(now - P.since) : '');
    var t = document.querySelector('#pgSteps time[data-since]');
    if (t && P.since) t.textContent = dur(now - P.since);
  }

  function poll() {
    setTimeout(function () {
      fetch(@json(route('admin.v2-diagnose.status', $run)), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (s) {
          if (s.status === 'completed' || s.status === 'failed') {
            P = s.progress; render(); setTimeout(function () { location.reload(); }, 900); return;
          }
          P = s.progress; msg = s.stage_message; skew = P.now - Date.now() / 1000;
          render();
          var log = $('evLog');
          if (log && s.events) log.innerHTML = s.events.map(function (e) {
            return '<div class="v2-ev"><time>' + esc(e.at.substr(11)) + '</time>' + esc(e.message) + '</div>';
          }).join('');
          poll();
        }).catch(poll);
    }, 3000);
  }

  skew = P.now - Date.now() / 1000;
  render();
  if (running) { setInterval(tick, 1000); poll(); }
})();
</script>
@endif

@if($viewer)
<script src="https://cdnjs.cloudflare.com/ajax/libs/openseadragon/4.1.0/openseadragon.min.js"></script>
<script>
(function () {
  var viewer = OpenSeadragon({
    id: 'osd',
    prefixUrl: 'https://cdnjs.cloudflare.com/ajax/libs/openseadragon/4.1.0/images/',
    @if($tiles['direct'])
    // DeepZoom geometry of wsi_tile_server.py (512 px tiles, 1 px overlap,
    // levels up to the full slide), described here instead of by a .dzi file.
    tileSources: {
      width: {{ (int) $run->slide_width }}, height: {{ (int) $run->slide_height }},
      tileSize: 512, tileOverlap: 1, minLevel: 0,
      maxLevel: Math.ceil(Math.log2(Math.max({{ (int) $run->slide_width }}, {{ (int) $run->slide_height }}))),
      getTileUrl: function (level, x, y) {
        return @json($tiles['url']) + level + '/' + x + '/' + y + '?wsi_path=' + encodeURIComponent(@json($tiles['wsi']));
      },
    },
    @else
    tileSources: @json($tiles['url']),
    @endif
    showNavigator: true, navigatorPosition: 'BOTTOM_RIGHT',
    maxZoomPixelRatio: 2, animationTime: 0.4,
    gestureSettingsMouse: { clickToZoom: false },
  });
  @if(app()->isLocal()) window.__v2viewer = viewer;   // local debugging and browser tests only
  @endif

  @if($final)
  var R = @json(route('admin.v2-diagnose.result', $run));
  var RH = @json(route('admin.v2-diagnose.heat', $run));
  var NS = 'http://www.w3.org/2000/svg';
  var COLORS = { tumour: '#18c964', in_situ: '#f5a524', lymphovascular_invasion: '#f31260',
                 necrosis: '#9aa0a6', lymphoid: '#3b82f6', normal: '#a78bfa', other: '#e5e7eb' };
  var data, W, svg, gAll, layers = {}, heatItem = null, scaleNow = 0;
  var hits = { regions: [], mask: [] };

  var heat = null;          // byte grids per tile, arrive after the vectors

  // The state of the overlay, on the viewer itself: a result that is still on
  // its way, or that failed, must never look like a result with nothing in it.
  var status = document.getElementById('vwStatus');
  function say(text, isError) {
    status.style.display = text ? '' : 'none';
    status.textContent = text || '';
    status.classList.toggle('err', !!isError);
  }

  function getJson(url) {
    return fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      });
  }

  function load() {
    say('Loading the tumour mask and the regions…');
    getJson(R).then(function (d) {
      data = d; W = d.slide_width;
      var go = function () { build(); say(''); loadHeat(); };
      if (viewer.world.getItemCount()) go(); else viewer.addOnceHandler('open', go);
    }).catch(function (e) {
      say('The tumour mask and regions could not be loaded (' + e.message + '). Click to retry.', true);
      status.onclick = function () { status.onclick = null; load(); };
    });
  }

  function loadHeat() {
    getJson(RH).then(function (h) {
      heat = { grid: h.grid, model: decodeAll(h.model), density: decodeAll(h.density) };
      drawHeat();
    }).catch(function (e) {
      say('The heatmap could not be loaded (' + e.message + '); the mask and regions are shown.', true);
    });
  }

  function decodeAll(m) {
    var outp = {};
    Object.keys(m || {}).forEach(function (k) {
      var bin = atob(m[k]), a = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i);
      outp[k] = a;
    });
    return outp;
  }
  load();

  // ── SVG overlay in level-0 pixels, re-transformed on every viewport change ──
  function el(tag, attrs, parent) {
    var e = document.createElementNS(NS, tag);
    for (var k in attrs) e.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(e);
    return e;
  }
  function pts(a) { return a.map(function (p) { return p[0] + ',' + p[1]; }).join(' '); }

  function build() {
    svg = el('svg', { style: 'position:absolute;inset:0;width:100%;height:100%;pointer-events:none' });
    viewer.canvas.appendChild(svg);
    gAll = el('g', {}, svg);
    ['tiles', 'box', 'mask', 'poly', 'sam', 'pts'].forEach(function (k) { layers[k] = el('g', {}, gAll); });

    data.tiles.forEach(function (t) {
      var c = t.tumour_score >= 0.5 ? '#f31260' : t.tumour_score >= 0.2 ? '#f5a524' : '#9aa0a6';
      var r = el('rect', { x: t.x, y: t.y, width: t.size_l0, height: t.size_l0, fill: 'none', stroke: c,
        'stroke-width': 1.5, 'vector-effect': 'non-scaling-stroke', 'stroke-dasharray': '2 5' }, layers.tiles);
    });

    var side = document.getElementById('side');
    side.innerHTML = '<h4>Regions (' + data.regions.length + ')</h4>';
    data.regions.forEach(function (g) {
      var col = COLORS[g.label] || '#fff';
      var tipHtml = '<b>' + g.id + '</b> · ' + g.label.replace(/_/g, ' ') + ' · ' + Math.round(g.confidence * 100) + '%<br>tile ' + g.patch + (g.note ? '<br>' + esc(g.note) : '');
      var b = g.box;
      el('rect', { x: b[0], y: b[1], width: b[2] - b[0], height: b[3] - b[1], fill: 'none', stroke: '#facc15',
        'stroke-width': 3, 'stroke-dasharray': '14 8', 'vector-effect': 'non-scaling-stroke' }, layers.box);
      var poly = el('polygon', { points: pts(g.polygon), fill: col, 'fill-opacity': 0.18, stroke: col,
        'stroke-width': 2.2, 'vector-effect': 'non-scaling-stroke', 'data-id': g.id }, layers.poly);
      hits.regions.push({ g: g, rings: [g.polygon], bbox: bboxOf(g.polygon), html: tipHtml });
      (g.sam_polygons || []).forEach(function (sp) {
        el('polygon', { points: pts(sp), fill: 'none', stroke: '#22d3ee', 'stroke-width': 2,
          'stroke-dasharray': '5 3', 'vector-effect': 'non-scaling-stroke' }, layers.sam);
      });
      g.positive_points.forEach(function (p) { el('circle', { cx: p[0], cy: p[1], 'data-r': 1, fill: '#18c964', stroke: '#000', 'stroke-width': 1.2, 'vector-effect': 'non-scaling-stroke' }, layers.pts); });
      g.negative_points.forEach(function (p) { el('circle', { cx: p[0], cy: p[1], 'data-r': 1, fill: '#f31260', stroke: '#000', 'stroke-width': 1.2, 'vector-effect': 'non-scaling-stroke' }, layers.pts); });

      var it = document.createElement('div');
      it.className = 'vw-item';
      it.innerHTML = '<span class="vw-chip" style="background:' + col + '"></span><b>' + g.id + '</b> ' + g.label.replace(/_/g, ' ') +
        ' · ' + Math.round(g.confidence * 100) + '%<small>tile ' + g.patch + (g.note ? ' — ' + esc(g.note) : '') + '</small>';
      it.addEventListener('click', function () { go(g); });
      it.addEventListener('mouseenter', function () { poly.setAttribute('fill-opacity', 0.45); });
      it.addEventListener('mouseleave', function () { poly.setAttribute('fill-opacity', 0.18); });
      side.appendChild(it);
    });

    var tumourTiles = data.tiles.filter(function (t) { return t.tumour_score >= 0.5; }).length;
    var h = document.createElement('h4');
    h.textContent = 'Tiles (' + data.tiles.length + ', ' + tumourTiles + ' tumour)';
    side.appendChild(h);
    data.tiles.slice().sort(function (a, b) { return b.tumour_score - a.tumour_score; }).forEach(function (t) {
      var it = document.createElement('div');
      it.className = 'vw-item';
      it.innerHTML = '<b>' + t.id + '</b> ' + t.tissue + ' · ' + Math.round(t.tumour_score * 100) + '%' + (t.note ? '<small>' + esc(t.note) + '</small>' : '');
      it.addEventListener('click', function () { fit(t.x, t.y, t.x + t.size_l0, t.y + t.size_l0, 0.05); });
      side.appendChild(it);
    });

    // Say plainly when a control has nothing to change, instead of letting it
    // look broken: no SAM masks when SAM was not run, and outlines, boxes,
    // tiles and the two heat layers all coincide when regions cover whole tiles.
    var hint = [];
    if (!data.regions.some(function (g) { return (g.sam_polygons || []).length; })) {
      var sb = document.getElementById('lySam');
      sb.checked = false; sb.disabled = true;
      document.getElementById('lySamLbl').style.opacity = 0.45;
      document.getElementById('lySamLbl').title = 'SAM was not run for this slide — only its prompts (SAM points) exist.';
    }
    var sizeOf = {}; data.tiles.forEach(function (t) { sizeOf[t.id] = t.size_l0; });
    var whole = data.regions.filter(function (g) {
      var s = sizeOf[g.patch] || 1;
      return (g.box[2] - g.box[0]) * (g.box[3] - g.box[1]) >= 0.85 * s * s;
    }).length;
    // The tumour mask: one slide-wide set of rings, cut across tile borders.
    // Every nest is its own ring and the stroma between them a hole (even-odd
    // fill). Split into paths of a few hundred polygons so the browser can
    // skip the ones off screen; a polygon (outer ring + its holes) is never
    // split across two paths, or its holes would be painted as filled.
    var maskPolys = (data.tumour_mask && data.tumour_mask.polygons) || [];
    var ringPath = function (r) { return 'M' + r.map(function (p) { return p[0] + ' ' + p[1]; }).join('L') + 'Z'; };
    for (var i = 0; i < maskPolys.length; i += 200) {
      var d = maskPolys.slice(i, i + 200).map(function (poly) { return poly.map(ringPath).join(''); }).join('');
      var mp = el('path', { d: d, 'fill-rule': 'evenodd', fill: COLORS.tumour, 'fill-opacity': 0.35,
        stroke: COLORS.tumour, 'stroke-width': 1.6, 'vector-effect': 'non-scaling-stroke' }, layers.mask);
    }
    maskPolys.forEach(function (poly) { hits.mask.push({ rings: poly, bbox: bboxOf(poly[0]) }); });
    var hasMask = maskPolys.length > 0;
    if (hasMask) {
      // Both stay on: the mask is where the tumour is, the outlines are the
      // regions the reading was based on. Over the mask the outlines keep only
      // a faint fill so the mask is not hidden under them.
      layers.poly.querySelectorAll('polygon').forEach(function (e) { e.setAttribute('fill-opacity', 0.06); });
    } else {
      var mb = document.getElementById('lyMask');
      mb.checked = false; mb.disabled = true;
      mb.parentNode.style.opacity = 0.45;
      mb.parentNode.title = 'No pixel-level mask for this run (made before masks existed).';
    }
    if (!hasMask && whole && whole >= data.regions.length / 2) {
      hint.push(whole + ' of ' + data.regions.length + ' regions cover almost their whole tile, so Outlines, ROI boxes and Tiles '
        + 'draw nearly the same squares, and the tumour heatmap is nearly the measured density. Re-run with the tighter prompt for nest-level outlines.');
    }
    if (hint.length) { var h = document.getElementById('vwHint'); h.textContent = hint.join(' '); h.style.display = ''; }

    ['lyMask', 'lyPoly', 'lyTiles'].forEach(shown);

    // Full page shows only the viewer, so the toolbar rides inside it while
    // full page is on and goes back to its place after.
    var bar = document.querySelector('.vw-bar'), barHome = bar.parentNode, barNext = bar.nextSibling;
    var barStyle = bar.style.cssText;
    viewer.addHandler('full-page', function (e) {
      if (e.fullPage) {
        bar.classList.add('vw-bar-fp');
        viewer.addControl(bar, { anchor: OpenSeadragon.ControlAnchor.TOP_RIGHT, autoFade: false });
      } else {
        viewer.removeControl(bar);
        bar.classList.remove('vw-bar-fp');
        bar.style.cssText = barStyle;
        barHome.insertBefore(bar, barNext);
      }
    });

    ['Mask', 'Poly', 'Sam', 'Box', 'Pts', 'Tiles'].forEach(function (k) {
      document.getElementById('ly' + k).addEventListener('change', applyVis);
    });
    document.getElementById('heatWhich').addEventListener('change', drawHeatSoon);
    document.getElementById('heatFloor').addEventListener('input', drawHeatSoon);
    document.getElementById('heatOpacity').addEventListener('input', function () {
      if (heatItem) heatItem.setOpacity(this.value / 100);
    });

    ['animation', 'update-viewport', 'resize', 'open'].forEach(function (e) { viewer.addHandler(e, place); });
    applyVis(); place(); drawHeat();
  }

  function place() {
    if (!gAll || !viewer.world.getItemCount()) return;
    var p = viewer.viewport.pixelFromPoint(new OpenSeadragon.Point(0, 0), true);
    var s = viewer.viewport.getZoom(true) * viewer.viewport.getContainerSize().x / W;
    gAll.setAttribute('transform', 'translate(' + p.x + ',' + p.y + ') scale(' + s + ')');
    gAll.classList.toggle('lowzoom', s < 0.06);
    if (!scaleNow || Math.abs(s - scaleNow) / scaleNow > 0.02) {
      scaleNow = s;
      layers.pts.querySelectorAll('circle').forEach(function (c) { c.setAttribute('r', 5 / s); });
    }
  }

  function applyVis() {
    var map = { mask: 'lyMask', poly: 'lyPoly', sam: 'lySam', box: 'lyBox', pts: 'lyPts', tiles: 'lyTiles' };
    Object.keys(map).forEach(function (k) {
      var on = document.getElementById(map[k]).checked;
      layers[k].style.display = on ? '' : 'none';
    });
  }

  function fit(x0, y0, x1, y1, pad) {
    var w = x1 - x0, h = y1 - y0, m = Math.max(w, h) * (pad || 0.25);
    viewer.viewport.fitBounds(new OpenSeadragon.Rect((x0 - m) / W, (y0 - m) / W, (w + 2 * m) / W, (h + 2 * m) / W));
  }
  function go(g) {
    fit(g.box[0], g.box[1], g.box[2], g.box[3]);
  }

  // ── Heat: every tile's grid placed in one small canvas covering the slide,
  //    one pixel per cell, and let the browser's smoothing do the rest. ──
  function jet(v) {
    var stops = [[0, [59, 76, 192]], [0.25, [34, 208, 208]], [0.5, [163, 242, 74]], [0.75, [249, 168, 37]], [1, [192, 57, 43]]];
    for (var i = 1; i < stops.length; i++) {
      if (v <= stops[i][0]) {
        var a = stops[i - 1], b = stops[i], f = (v - a[0]) / (b[0] - a[0]);
        return [0, 1, 2].map(function (k) { return Math.round(a[1][k] + f * (b[1][k] - a[1][k])); });
      }
    }
    return stops[stops.length - 1][1];
  }

  // A new layer loads asynchronously, so a slider drag fires many draws whose
  // layers arrive late and out of order. Each draw takes a number; only the
  // newest one's layer is kept, and it replaces the old one only once it has
  // loaded, so the heat never blinks off while dragging.
  var heatGen = 0, heatTimer = null;
  function drawHeatSoon() {
    document.getElementById('heatFloorPct').textContent = document.getElementById('heatFloor').value + '%';
    clearTimeout(heatTimer);
    heatTimer = setTimeout(drawHeat, 60);
  }

  function drawHeat() {
    var gen = ++heatGen;
    var which = document.getElementById('heatWhich').value;
    if (which === 'off') {
      if (heatItem) { viewer.world.removeItem(heatItem); heatItem = null; }
      return;
    }
    if (!heat) return;                                    // drawn when it arrives
    var grids = which === 'model' ? heat.model : heat.density;
    var floor = document.getElementById('heatFloor').value / 100;
    var n = heat.grid, cell = data.tiles[0].size_l0 / n;
    var cols = Math.ceil(data.slide_width / cell), rows = Math.ceil(data.slide_height / cell);
    var cv = document.createElement('canvas'); cv.width = cols; cv.height = rows;
    var ctx = cv.getContext('2d'), img = ctx.createImageData(cols, rows);
    data.tiles.forEach(function (t) {
      var g = grids[t.id]; if (!g) return;
      var gx = Math.round(t.x / cell), gy = Math.round(t.y / cell);
      for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) {
        var v = g[r * n + c] / 255, X = gx + c, Y = gy + r;
        if (v <= 0 || v < floor || X >= cols || Y >= rows) continue;
        var rgb = jet(Math.min(1, v)), o = (Y * cols + X) * 4;
        img.data[o] = rgb[0]; img.data[o + 1] = rgb[1]; img.data[o + 2] = rgb[2];
        img.data[o + 3] = Math.round(120 + 135 * Math.min(1, v));
      }
    });
    ctx.putImageData(img, 0, 0);
    viewer.addTiledImage({
      tileSource: { type: 'image', url: cv.toDataURL('image/png'), buildPyramid: false },
      x: 0, y: 0, width: cols * cell / W,
      opacity: document.getElementById('heatOpacity').value / 100,
      index: 1,
      success: function (ev) {
        if (gen !== heatGen) { viewer.world.removeItem(ev.item); return; }   // a newer draw won
        if (heatItem) viewer.world.removeItem(heatItem);
        heatItem = ev.item;
        heatItem.setOpacity(document.getElementById('heatOpacity').value / 100);
      },
    });
  }

  // ── Hover and click ──
  // The drawn shapes never take the pointer. When they did, every move across
  // a nest border read to the viewer as leaving its canvas and coming back,
  // dozens of times a second, and each time it faded its controls out and
  // in, which in full-page mode shook the whole screen. The pointer is read
  // here instead, once per frame, and tested against the shapes' geometry.
  var tip = document.getElementById('tip');
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  function bboxOf(ring) {
    var b = [Infinity, Infinity, -Infinity, -Infinity];
    ring.forEach(function (p) {
      if (p[0] < b[0]) b[0] = p[0];
      if (p[1] < b[1]) b[1] = p[1];
      if (p[0] > b[2]) b[2] = p[0];
      if (p[1] > b[3]) b[3] = p[1];
    });
    return b;
  }
  function inRing(x, y, r) {
    var c = false;
    for (var i = 0, j = r.length - 1; i < r.length; j = i++) {
      if ((r[i][1] > y) !== (r[j][1] > y) && x < (r[j][0] - r[i][0]) * (y - r[i][1]) / (r[j][1] - r[i][1]) + r[i][0]) c = !c;
    }
    return c;
  }
  function inRings(x, y, rings) {            // even-odd, so a hole counts as outside
    var c = false;
    for (var i = 0; i < rings.length; i++) if (inRing(x, y, rings[i])) c = !c;
    return c;
  }
  function inBox(x, y, b) { return x >= b[0] && x <= b[2] && y >= b[1] && y <= b[3]; }
  var switches = {};
  function shown(id) {
    var e = switches[id] || (switches[id] = document.getElementById(id));
    return !!(e && e.checked);
  }

  function tileAt(x, y) {
    for (var i = 0; i < data.tiles.length; i++) {
      var t = data.tiles[i];
      if (x >= t.x && x < t.x + t.size_l0 && y >= t.y && y < t.y + t.size_l0) return t;
    }
    return null;
  }
  function tileHtml(t) {
    return '<b>' + t.id + '</b> · ' + t.tissue + ' · tumour ' + Math.round(t.tumour_score * 100) + '%' + (t.note ? '<br>' + esc(t.note) : '');
  }

  // What is under a slide point: a region outline, the tumour mask, a tile,
  // only among the layers switched on, the outline first.
  function hitAt(x, y) {
    var i, h;
    if (shown('lyPoly')) for (i = 0; i < hits.regions.length; i++) {
      h = hits.regions[i];
      if (inBox(x, y, h.bbox) && inRings(x, y, h.rings)) return { html: h.html, region: h.g };
    }
    if (shown('lyMask')) for (i = 0; i < hits.mask.length; i++) {
      h = hits.mask[i];
      if (inBox(x, y, h.bbox) && inRings(x, y, h.rings)) {
        var t = tileAt(x, y);
        var g = t && data.regions.filter(function (r) {
          return r.patch === t.id && r.label === 'tumour' && inBox(x, y, r.box);
        })[0];
        return { html: '<b>Tumour mask</b>' + (t ? '<br>' + tileHtml(t) : ''), region: g || null };
      }
    }
    if (shown('lyTiles')) {
      var tt = tileAt(x, y);
      if (tt) return { html: tileHtml(tt), region: null };
    }
    return null;
  }

  function slidePoint(clientX, clientY) {
    // Viewport units are slide widths, so one multiplication gives level-0
    // pixels, with no per-image conversion (ambiguous once the heat layer is
    // a second image in the world).
    var vp = viewer.viewport.windowToViewportCoordinates(
      new OpenSeadragon.Point(clientX + window.pageXOffset, clientY + window.pageYOffset));
    return [vp.x * W, vp.y * W];
  }

  @if(app()->isLocal()) window.__v2debug = { hitAt: hitAt, slidePoint: slidePoint };   // local tests only
  @endif
  var pending = null, frame = 0;
  function showHover() {
    frame = 0;
    if (!pending || !data) return;
    var p = slidePoint(pending.clientX, pending.clientY), h = hitAt(p[0], p[1]);
    viewer.canvas.style.cursor = h && h.region ? 'pointer' : '';
    if (!h) { tip.style.display = 'none'; return; }
    var b = document.getElementById('osd').getBoundingClientRect();
    if (tip.innerHTML !== h.html) tip.innerHTML = h.html;
    tip.style.left = (pending.clientX - b.left + 14) + 'px';
    tip.style.top = (pending.clientY - b.top + 14) + 'px';
    tip.style.display = 'block';
  }
  viewer.canvas.addEventListener('pointermove', function (e) {
    pending = { clientX: e.clientX, clientY: e.clientY };
    if (!frame) frame = requestAnimationFrame(showHover);
  }, { passive: true });
  viewer.canvas.addEventListener('pointerleave', function () { pending = null; tip.style.display = 'none'; });
  viewer.addHandler('canvas-drag', function () { tip.style.display = 'none'; });
  viewer.addHandler('canvas-click', function (e) {
    if (!e.quick || !data) return;
    var r = viewer.canvas.getBoundingClientRect();
    var p = slidePoint(r.left + e.position.x, r.top + e.position.y), h = hitAt(p[0], p[1]);
    if (h && h.region) go(h.region);
  });
  @endif
})();
</script>
@endif
@endsection
