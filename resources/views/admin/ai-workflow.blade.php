@extends('admin.layouts.app')

@section('title', 'AI Diagnosis Workflow')

@section('content')
@php
    $stage = $state['stage'] ?? null;
    $ready = $stage === 'ready';
    $perf  = $model['performance'] ?? [];
@endphp

<style>
  .wf-grid{display:grid;gap:1.25rem}
  @media(min-width:1100px){.wf-grid{grid-template-columns:minmax(0,1fr) 22rem;align-items:start}}
  .wf-card{background:#fff;border:1px solid #e3e0ea;border-radius:8px;padding:1.1rem 1.25rem;margin-bottom:1.1rem}
  .wf-card h3{margin:0 0 .85rem;font-size:1.02rem;font-weight:600}
  .wf-step{display:grid;grid-template-columns:1.6rem 1fr;gap:.7rem;padding:.6rem 0;border-bottom:1px solid #f0eef4}
  .wf-step:last-child{border-bottom:0}
  .wf-dot{width:1.1rem;height:1.1rem;border-radius:50%;margin-top:.15rem;border:2px solid #cfc9db}
  .wf-dot.done{background:#2c6b5b;border-color:#2c6b5b}
  .wf-dot.run{background:#d9a94e;border-color:#d9a94e}
  .wf-dot.fail{background:#b03d64;border-color:#b03d64}
  .wf-step .lbl{font-weight:600;font-size:.93rem}
  .wf-step .det{color:#6b6480;font-size:.85rem}
  .wf-tabs{display:flex;gap:.4rem;margin-bottom:1rem;flex-wrap:wrap}
  .wf-tab{padding:.45rem .9rem;border:1px solid #ddd9e6;border-radius:6px;background:#faf9fc;cursor:pointer;font-size:.9rem}
  .wf-tab.on{background:#4b3a94;color:#fff;border-color:#4b3a94}
  .wf-pane{display:none}.wf-pane.on{display:block}
  .wf-verdict{border-radius:8px;padding:1.15rem 1.3rem;margin-bottom:1rem}
  .wf-verdict.ok{background:#e4f1ec;border:1px solid #b9ddd0}
  .wf-verdict.refer{background:#faf0da;border:1px solid #e8d19a}
  .wf-verdict.stop{background:#fbe9f0;border:1px solid #f0c2d3}
  .wf-verdict .big{font-size:1.6rem;font-weight:700;margin:.2rem 0}
  .wf-bar{height:.55rem;border-radius:99px;background:#ece9f4;overflow:hidden;margin:.5rem 0}
  .wf-bar span{display:block;height:100%;background:linear-gradient(90deg,#4b3a94,#b03d64)}
  .wf-kv{display:flex;justify-content:space-between;padding:.3rem 0;font-size:.88rem;border-bottom:1px solid #f3f1f7}
  .wf-kv:last-child{border-bottom:0}
  .wf-kv b{font-variant-numeric:tabular-nums}
  .wf-limits li{font-size:.84rem;color:#57516a;margin-bottom:.3rem}
  .wf-mono{font-family:ui-monospace,Consolas,monospace;font-size:.85rem}
  table.wf-nn{width:100%;border-collapse:collapse;font-size:.87rem}
  table.wf-nn td,table.wf-nn th{padding:.35rem .5rem;border-bottom:1px solid #f0eef4;text-align:left}
</style>

<h2 style="margin-bottom:.35rem">AI Diagnosis Workflow</h2>
<p style="color:#6b6480;margin-bottom:1.3rem">
  Pick a slide, check where it is in the pipeline, run the model. Research prototype —
  every call needs a pathologist.
</p>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())
  <div class="alert alert-danger">
    @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
  </div>
@endif

<div class="wf-grid">
  <div>

    {{-- 1 ─ where the slide comes from ─────────────────────────────── --}}
    <div class="wf-card">
      <h3>1 · Choose a slide</h3>
      <div class="wf-tabs">
        <div class="wf-tab on" data-pane="existing">From the archive</div>
        <div class="wf-tab" data-pane="upload">Upload a file</div>
        <div class="wf-tab" data-pane="gdrive">From a Drive link</div>
      </div>

      <div class="wf-pane on" id="pane-existing">
        <form method="GET" action="{{ route('admin.ai-workflow') }}">
          <input type="hidden" name="model" value="{{ $modelKey }}">
          <div style="display:flex;gap:.6rem;flex-wrap:wrap">
            <select name="sample_id" class="form-control" style="flex:1;min-width:20rem" required>
              <option value="">— ready to score ({{ $ready_count ?? $ready->count() }}) —</option>
              @foreach($ready as $s)
                <option value="{{ $s->id }}" @selected($sample && $sample->id === $s->id)>
                  #{{ $s->id }} · {{ $s->entity_submitter_id ?: $s->file_name }}
                  @if($s->diseaseSubtype) · {{ $s->diseaseSubtype->name }} @endif
                  · {{ number_format((int)$s->features_patch_count) }} patches
                </option>
              @endforeach
              @if($pending->count())
                <option disabled>— not yet processed ({{ $pending->count() }}) —</option>
                @foreach($pending as $s)
                  <option value="{{ $s->id }}" @selected($sample && $sample->id === $s->id)>
                    #{{ $s->id }} · {{ $s->entity_submitter_id ?: $s->file_name }}
                    · patches: {{ $s->tiling_status }} · features: {{ $s->feature_extraction_status }}
                  </option>
                @endforeach
              @endif
            </select>
            <button class="btn btn-primary">Inspect</button>
          </div>
        </form>
      </div>

      <div class="wf-pane" id="pane-upload">
        <form method="POST" action="{{ route('admin.ai-workflow.intake') }}" enctype="multipart/form-data">
          @csrf
          <input type="hidden" name="source" value="upload">
          <input type="hidden" name="model" value="{{ $modelKey }}">
          <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
            <input type="file" name="wsi" accept=".svs,.tif,.tiff,.ndpi,.scn" class="form-control" style="flex:1;min-width:18rem" required>
            <input type="text" name="label" class="form-control" placeholder="label (optional)" style="width:14rem">
            <button class="btn btn-primary">Accept slide</button>
          </div>
          <p style="color:#6b6480;font-size:.84rem;margin:.6rem 0 0">
            The file is copied to Drive first. Patch and feature extraction are offered once it lands.
          </p>
        </form>
      </div>

      <div class="wf-pane" id="pane-gdrive">
        <form method="POST" action="{{ route('admin.ai-workflow.intake') }}">
          @csrf
          <input type="hidden" name="source" value="gdrive">
          <input type="hidden" name="model" value="{{ $modelKey }}">
          <div style="display:flex;gap:.6rem;flex-wrap:wrap">
            <input type="text" name="gdrive_link" class="form-control" style="flex:1;min-width:20rem"
                   placeholder="https://drive.google.com/file/d/…/view" required>
            <input type="text" name="label" class="form-control" placeholder="label (optional)" style="width:12rem">
            <button class="btn btn-primary">Fetch</button>
          </div>
          <p style="color:#6b6480;font-size:.84rem;margin:.6rem 0 0">
            The file must be shared with this account, or the link cannot be read.
          </p>
        </form>
      </div>
    </div>

    {{-- 2 ─ where it stands ─────────────────────────────────────────── --}}
    @if($sample)
    <div class="wf-card">
      <h3>2 · Where this slide stands</h3>
      <p class="wf-mono" style="color:#57516a;margin-bottom:.8rem">
        #{{ $sample->id }} · {{ $sample->entity_submitter_id ?: $sample->file_name }}
        @if($sample->patientCase) · patient {{ $sample->patientCase->submitter_id }} @endif
      </p>

      @foreach($state['steps'] as $step)
        @php
          $cls = !empty($step['done']) ? 'done' : (!empty($step['running']) ? 'run'
               : (!empty($step['failed']) ? 'fail' : ''));
        @endphp
        <div class="wf-step">
          <div class="wf-dot {{ $cls }}"></div>
          <div>
            <div class="lbl">{{ $step['label'] }}</div>
            <div class="det">{{ $step['detail'] }}</div>
          </div>
        </div>
      @endforeach

      @if(!empty($state['requirement_failures']))
        <div class="wf-verdict stop" style="margin-top:1rem">
          <strong>This slide does not match the selected model</strong>
          <ul style="margin:.5rem 0 0;padding-left:1.2rem">
            @foreach($state['requirement_failures'] as $f)<li>{{ $f }}</li>@endforeach
          </ul>
        </div>
      @elseif($state['blocking'])
        <div class="wf-verdict refer" style="margin-top:1rem">
          <div>{{ $state['blocking'] }}</div>
          @if($state['next_action'] === 'patches')
            <form method="POST" action="{{ route('admin.ai-workflow.advance') }}" style="margin-top:.7rem">
              @csrf
              <input type="hidden" name="sample_id" value="{{ $sample->id }}">
              <input type="hidden" name="step" value="patches">
              <input type="hidden" name="model" value="{{ $modelKey }}">
              <button class="btn btn-warning">Extract patches — 224px at 20x</button>
            </form>
          @elseif($state['next_action'] === 'features')
            <form method="POST" action="{{ route('admin.ai-workflow.advance') }}" style="margin-top:.7rem">
              @csrf
              <input type="hidden" name="sample_id" value="{{ $sample->id }}">
              <input type="hidden" name="step" value="features">
              <input type="hidden" name="model" value="{{ $modelKey }}">
              <button class="btn btn-warning">Extract features — TITAN on the GPU pod</button>
            </form>
          @endif
        </div>
      @else
        <form method="POST" action="{{ route('admin.ai-workflow.predict') }}" style="margin-top:1rem">
          @csrf
          <input type="hidden" name="sample_id" value="{{ $sample->id }}">
          <input type="hidden" name="model" value="{{ $modelKey }}">
          <button class="btn btn-success btn-lg">Run {{ $model['label'] ?? 'the model' }}</button>
        </form>
      @endif
    </div>
    @endif

    {{-- 3 ─ the answer ──────────────────────────────────────────────── --}}
    @if($prediction)
    @php
      $p = (float)($prediction['p_ilc'] ?? 0);
      $blocked = ($prediction['ood_status'] ?? '') === 'refuse' || !empty($prediction['input_problems']);
      $refer = !$blocked && (!empty($prediction['referred']) || ($prediction['ood_status'] ?? '') === 'warn');
      $cls = $blocked ? 'stop' : ($refer ? 'refer' : 'ok');
    @endphp
    <div class="wf-card">
      <h3>3 · Result — {{ $prediction['model_label'] ?? '' }}</h3>

      <div class="wf-verdict {{ $cls }}">
        @if($blocked)
          <div style="font-weight:700">Do not use this result</div>
          <div style="margin-top:.35rem;font-size:.9rem">
            @foreach(($prediction['input_problems'] ?? []) as $ip)<div>· {{ $ip }}</div>@endforeach
            @if(($prediction['ood_status'] ?? '') === 'refuse')
              <div>· This slide is unlike anything the model was trained on. It has two
                   answers and no way to say “neither”, so the answer is not meaningful.</div>
            @endif
          </div>
        @else
          <div style="font-size:.85rem;color:#57516a">Suggests</div>
          <div class="big">{{ $prediction['call'] ?? '—' }}</div>
          <div class="wf-bar"><span style="width:{{ round($p*100) }}%"></span></div>
          <div style="font-size:.88rem">p(ILC) = {{ number_format($p, 2) }}
            — calibrated, so this reads as a real frequency</div>
          @if($refer)
            <div style="margin-top:.6rem;font-weight:600">Refer to a pathologist</div>
            <div style="font-size:.87rem">
              @if(!empty($prediction['referred']))
                The probability sits in the band where this model does not commit.
              @endif
              @if(($prediction['ood_status'] ?? '') === 'warn')
                This slide only loosely resembles the training set.
              @endif
            </div>
          @endif
        @endif
      </div>

      <div class="wf-kv"><span>Patches read</span><b>{{ number_format((int)($prediction['patches'] ?? 0)) }}</b></div>
      <div class="wf-kv"><span>Familiarity (lower is more familiar)</span><b>{{ $prediction['familiarity'] ?? '—' }}</b></div>
      <div class="wf-kv"><span>Model AUC on unseen laboratories</span><b>{{ $prediction['model']['loso_auc'] ?? '—' }}</b></div>

      @if(!empty($prediction['nearest_cases']))
        <h3 style="margin-top:1.1rem">Nearest known cases</h3>
        <p style="color:#6b6480;font-size:.85rem;margin-bottom:.5rem">
          Archived slides closest to this one. Opening them and comparing is worth more
          than the probability above.
        </p>
        <table class="wf-nn">
          <tr><th>Patient</th><th>Site</th><th>Diagnosis</th><th>Distance</th></tr>
          @foreach($prediction['nearest_cases'] as $n)
            <tr>
              <td class="wf-mono">{{ $n['patient'] }}</td>
              <td>{{ $n['site'] }}</td>
              <td><strong>{{ $n['diagnosis'] }}</strong></td>
              <td>{{ $n['distance'] }}</td>
            </tr>
          @endforeach
        </table>
      @endif
    </div>
    @endif

  </div>

  {{-- side ─ the model and its limits ──────────────────────────────── --}}
  <div>
    <div class="wf-card">
      <h3>Model</h3>
      <form method="GET" action="{{ route('admin.ai-workflow') }}">
        @if($sample)<input type="hidden" name="sample_id" value="{{ $sample->id }}">@endif
        <select name="model" class="form-control" onchange="this.form.submit()">
          @foreach($models as $key => $m)
            <option value="{{ $key }}" @selected($key === $modelKey)>{{ $m['label'] }}</option>
          @endforeach
        </select>
      </form>
      <p style="color:#57516a;font-size:.86rem;margin:.8rem 0 0">{{ $model['description'] ?? '' }}</p>
    </div>

    @if($perf)
    <div class="wf-card">
      <h3>How well it does</h3>
      <div class="wf-kv"><span>AUC, unseen laboratories</span><b>{{ $perf['auc_unseen_sites'] }}</b></div>
      <div class="wf-kv"><span>95% interval</span><b>{{ implode(' – ', $perf['auc_ci']) }}</b></div>
      <div class="wf-kv"><span>Brier score</span><b>{{ $perf['brier'] }}</b></div>
      <div class="wf-kv"><span>Calibration error</span><b>{{ $perf['calibration_err'] }}</b></div>
      <div class="wf-kv"><span>Answers</span><b>{{ $perf['answers_pct'] }}%</b></div>
      <div class="wf-kv"><span>Right when it answers</span><b>{{ $perf['accuracy_when_answering'] }}%</b></div>
      <div class="wf-kv"><span>Trained on</span><b>{{ $perf['trained_on'] }} slides</b></div>
    </div>
    @endif

    @if(!empty($model['limits']))
    <div class="wf-card">
      <h3>What it cannot do</h3>
      <ul class="wf-limits" style="margin:0;padding-left:1.1rem">
        @foreach($model['limits'] as $l)<li>{{ $l }}</li>@endforeach
      </ul>
    </div>
    @endif

    @if(!empty($model['requires']))
    <div class="wf-card">
      <h3>What a slide must be</h3>
      @foreach($model['requires'] as $k => $v)
        <div class="wf-kv"><span>{{ str_replace('_', ' ', $k) }}</span><b>{{ $v }}</b></div>
      @endforeach
    </div>
    @endif
  </div>
</div>

<script>
  document.querySelectorAll('.wf-tab').forEach(function (t) {
    t.addEventListener('click', function () {
      document.querySelectorAll('.wf-tab').forEach(function (x) { x.classList.remove('on'); });
      document.querySelectorAll('.wf-pane').forEach(function (x) { x.classList.remove('on'); });
      t.classList.add('on');
      document.getElementById('pane-' + t.dataset.pane).classList.add('on');
    });
  });
</script>
@endsection
