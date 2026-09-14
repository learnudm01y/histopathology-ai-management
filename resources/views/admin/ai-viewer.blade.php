@extends('layouts.admin')

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
  <a href="{{ route('admin.ai-workflow', ['sample_id' => $sample->id, 'model' => $modelKey]) }}"
     class="btn btn-sm btn-outline-secondary">← back to the report</a>
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
    <label style="gap:.6rem">Strength
      <input type="range" id="heatOpacity" min="0" max="100" value="55" style="width:9rem">
      <span id="heatPct" style="width:2.6rem;font-variant-numeric:tabular-nums">55%</span>
    </label>
    @if(!empty($evidence))
      <span class="vw-key"><span class="vw-chip" style="background:rgb(220,40,60)"></span> towards ILC</span>
      <span class="vw-key"><span class="vw-chip" style="background:rgb(40,40,220)"></span> towards IDC</span>
      <span class="vw-key" style="margin-left:auto">
        {{ number_format((int)($evidence['patches'] ?? 0)) }} patches ·
        top 20 hold {{ round(($evidence['share_top20'] ?? 0) * 100) }}% of the decision
      </span>
    @endif
  </div>
  <div id="osd"></div>
</div>

<p class="vw-note">
  <strong>What this layer is.</strong> Colour is the model's own evidence for its IDC-versus-ILC
  call, computed per patch: because the model max-pools, every dimension of the decision came
  from exactly one patch, so this is attribution rather than an estimate.
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
  // The heat image is one pixel per patch, so it is stretched back over the
  // slide's own extent. Its width in OpenSeadragon's coordinates is 1 by
  // definition — the slide is the unit — which registers the two exactly.
  var heat = null;
  viewer.addHandler('open', function () {
    viewer.addTiledImage({
      tileSource: {
        type: 'image',
        url: '{{ route('admin.ai-workflow.evidence-image', [$sample->id, 'heatmap.png']) }}',
        buildPyramid: false,
      },
      x: 0, y: 0, width: 1,
      opacity: 0.55,
      success: function (ev) { heat = ev.item; applyHeat(); },
    });
  });

  function applyHeat() {
    if (!heat) return;
    var on = document.getElementById('heatOn').checked;
    var pct = parseInt(document.getElementById('heatOpacity').value, 10);
    heat.setOpacity(on ? pct / 100 : 0);
    document.getElementById('heatPct').textContent = pct + '%';
  }

  document.getElementById('heatOn').addEventListener('change', applyHeat);
  document.getElementById('heatOpacity').addEventListener('input', applyHeat);
  @else
  document.getElementById('heatOn').disabled = true;
  document.getElementById('heatOpacity').disabled = true;
  @endif
})();
</script>
@endsection
