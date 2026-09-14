@extends('admin.layouts.app')

@section('title', 'Slide viewer')

@section('content')
<style>
  .vw-wrap{display:flex;flex-direction:column;height:calc(100vh - 150px);min-height:32rem}
  .vw-bar{display:flex;gap:1rem;align-items:center;flex-wrap:wrap;
          padding:.7rem .9rem;background:#faf9fc;border:1px solid #e6e2ef;border-radius:8px 8px 0 0}
  .vw-bar label{margin:0;font-size:.9rem;display:flex;align-items:center;gap:.4rem}
  #osd{flex:1;background:#111;border:1px solid #e6e2ef;border-top:0;border-radius:0 0 8px 8px}
  .vw-key{display:flex;align-items:center;gap:.45rem;font-size:.85rem;color:#57516a}
  .vw-chip{width:14px;height:14px;border-radius:3px;display:inline-block}
  .vw-note{font-size:.86rem;color:#6b6480;margin:.7rem 0 0;line-height:1.55}
  .vw-warn{background:#fdf4f5;border:1px solid #f0d4d8;border-radius:8px;
           padding:.8rem .95rem;font-size:.88rem;margin-bottom:1rem}
</style>

<div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:.6rem">
  <h2 style="margin:0">{{ $sample->entity_submitter_id ?: $sample->file_name }}</h2>
  @if(!empty($scored))
    <a href="{{ route('admin.ai-results.show', $scored) }}"
       class="btn btn-sm btn-outline-secondary">← back to the report</a>
  @else
    <a href="{{ route('admin.ai-workflow', ['sample_id' => $sample->id, 'model' => $modelKey]) }}"
       class="btn btn-sm btn-outline-secondary">← back to the workflow</a>
  @endif
</div>

@if(!empty($prediction) && ($prediction['ood_status'] ?? '') === 'refuse')
<div class="vw-warn">
  <strong>The model refused this slide.</strong> The heat layer below still shows where its
  evidence fell, because that is worth seeing — but it is the evidence behind an answer that
  was withheld, and nothing on this page should be read as a diagnosis.
</div>
@endif

<div class="vw-wrap">
  <div class="vw-bar">
    <label><input type="checkbox" id="heatOn" checked> Heat layer</label>

    @if(!empty($evidence['heatmap_dense']))
    <label style="gap:.45rem">Show
      <select id="heatWhich" class="form-control" style="height:2.1rem;width:15rem;padding:.1rem .4rem">
        <option value="dense" selected>Lobular-ness — every patch</option>
        <option value="evidence">Evidence — the patches that voted</option>
      </select>
    </label>
    @endif

    <label style="gap:.6rem">Strength
      <input type="range" id="heatOpacity" min="0" max="100" value="70" style="width:9rem">
      <span id="heatPct" style="width:2.6rem;font-variant-numeric:tabular-nums">70%</span>
    </label>
    <label><input type="checkbox" id="heatCrisp"> Crisp blocks</label>

    <span class="vw-key" id="keyDense">
      <span class="vw-chip" style="background:linear-gradient(90deg,#3b4cc0,#22d0d0,#a3f24a,#f9a825,#c0392b);width:60px"></span>
      ductal-looking → lobular-looking
    </span>
    <span class="vw-key" id="keyEvidence" style="display:none">
      <span class="vw-chip" style="background:rgb(235,20,45)"></span> voted ILC
      <span class="vw-chip" style="background:rgb(25,20,235);margin-left:.5rem"></span> voted IDC
    </span>

    @if(!empty($evidence))
      <span class="vw-key" style="margin-left:auto">
        {{ number_format((int)($evidence['patches'] ?? 0)) }} patches ·
        top 20 hold {{ round(($evidence['share_top20'] ?? 0) * 100) }}% of the decision
      </span>
    @endif
  </div>
  <div id="osd"></div>
</div>

<p class="vw-note">
  <strong>Two layers, two questions.</strong> <em>Lobular-ness</em> asks the model where every single
  patch falls between the two classes, so the colour is continuous and covers all the tissue.
  <em>Evidence</em> shows only the few hundred patches that actually supplied the slide-level
  answer — sparse because most patches supply nothing, and blank where that is the truth.
  <strong>What it is not:</strong> it is not a tumour map. The model was never trained to find
  tumour or to mark its border — it was trained to tell two carcinoma types apart on slides that
  already contain carcinoma. A red region means "this pushed the answer towards ILC", never
  "the disease is here".
</p>

<script src="https://cdnjs.cloudflare.com/ajax/libs/openseadragon/4.1.0/openseadragon.min.js"></script>
<script>
(function () {
  var viewer = OpenSeadragon({
    id: 'osd',
    prefixUrl: 'https://cdnjs.cloudflare.com/ajax/libs/openseadragon/4.1.0/images/',
    tileSources: '/wsi/{{ $sample->id }}.dzi',
    showNavigator: true,
    navigatorPosition: 'BOTTOM_RIGHT',
    maxZoomPixelRatio: 2,
    animationTime: 0.4,
    gestureSettingsMouse: { clickToZoom: false },
  });

  @if(!empty($evidence['heatmap']))
  // Both layers cover the slide's whole extent, so a width of 1 in
  // OpenSeadragon's coordinates — where the slide is the unit — registers them
  // against the tissue exactly. They are loaded together and swapped by
  // opacity, which keeps switching instant.
  var layers = {};

  function addLayer(key, url, opacity) {
    viewer.addTiledImage({
      tileSource: { type: 'image', url: url, buildPyramid: false },
      x: 0, y: 0, width: 1,
      opacity: opacity,
      success: function (ev) { layers[key] = ev.item; applyHeat(); },
    });
  }

  viewer.addHandler('open', function () {
    @if(!empty($evidence['heatmap_dense']))
    addLayer('dense', '{{ route('admin.ai-workflow.evidence-image', [$sample->id, 'heatmap_dense.png']) }}', 0.7);
    @endif
    addLayer('evidence', '{{ route('admin.ai-workflow.evidence-image', [$sample->id, 'heatmap.png']) }}', 0);
  });

  function currentKey() {
    var sel = document.getElementById('heatWhich');
    return sel ? sel.value : 'evidence';
  }

  function applyHeat() {
    var on = document.getElementById('heatOn').checked;
    var pct = parseInt(document.getElementById('heatOpacity').value, 10);
    var want = currentKey();
    document.getElementById('heatPct').textContent = pct + '%';

    Object.keys(layers).forEach(function (k) {
      layers[k].setOpacity(on && k === want ? pct / 100 : 0);
    });

    var kd = document.getElementById('keyDense'), ke = document.getElementById('keyEvidence');
    if (kd && ke) {
      kd.style.display = want === 'dense' ? '' : 'none';
      ke.style.display = want === 'dense' ? 'none' : '';
    }

    // Smoothing drains the evidence layer, where each patch is one cell blown
    // up over hundreds of slide pixels and a lone vote gets averaged into its
    // empty neighbours. The continuous layer is meant to be smooth, so the
    // control only makes sense on the sparse one.
    var crispBox = document.getElementById('heatCrisp');
    crispBox.disabled = (want === 'dense');
    var crisp = crispBox.checked && want !== 'dense';
    Object.keys(layers).forEach(function (k) {
      if (typeof layers[k].setImageSmoothingEnabled === 'function') {
        layers[k].setImageSmoothingEnabled(!crisp);
      }
    });
    viewer.forceRedraw();
  }

  document.getElementById('heatOn').addEventListener('change', applyHeat);
  document.getElementById('heatCrisp').addEventListener('change', applyHeat);
  document.getElementById('heatOpacity').addEventListener('input', applyHeat);
  var whichSel = document.getElementById('heatWhich');
  if (whichSel) whichSel.addEventListener('change', applyHeat);
  @else
  document.getElementById('heatOn').disabled = true;
  document.getElementById('heatOpacity').disabled = true;
  @endif
})();
</script>
@endsection
