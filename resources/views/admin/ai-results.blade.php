@extends('admin.layouts.app')

@section('title', 'AI Results')

@section('content')
<style>
  .rs-cards{display:flex;gap:.8rem;flex-wrap:wrap;margin-bottom:1.1rem}
  .rs-card{background:#fff;border:1px solid #e6e2ef;border-radius:8px;padding:.85rem 1.1rem;min-width:9.5rem}
  .rs-card b{display:block;font-size:1.5rem;line-height:1.2}
  .rs-card span{font-size:.82rem;color:#6b6480}
  .rs-note{font-size:.86rem;color:#6b6480;line-height:1.55;margin:0 0 1rem}
  table.rs{width:100%;border-collapse:collapse;font-size:.88rem;background:#fff}
  table.rs th{text-align:left;padding:.55rem .6rem;border-bottom:2px solid #e6e2ef;font-size:.8rem;
              text-transform:uppercase;letter-spacing:.03em;color:#57516a}
  table.rs td{padding:.5rem .6rem;border-bottom:1px solid #f0edf5}
  .rs-mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.84rem}
  .pill{display:inline-block;padding:.12rem .5rem;border-radius:999px;font-size:.78rem;font-weight:600}
  .pill.stop{background:#fdf0f2;color:#a3343a}
  .pill.ok{background:#eef8f0;color:#2b6b3d}
  .pill.refer{background:#fdf6e9;color:#8a6410}
  .hit{color:#2b6b3d;font-weight:700}
  .miss{color:#a3343a;font-weight:700}
  .rs-bar{display:flex;gap:.5rem;flex-wrap:wrap;align-items:end;margin-bottom:1rem}
  .rs-bar select,.rs-bar .btn{height:2.35rem}
</style>

<div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:.6rem">
  <h2 style="margin:0 0 .3rem">AI Results</h2>
  <a class="btn btn-sm btn-outline-secondary"
     href="{{ route('admin.ai-results.export', request()->query()) }}">Download CSV</a>
</div>
<p class="rs-note">Every score the deployed models have produced, kept permanently.</p>

@php $s = $summary; @endphp
<div class="rs-cards">
  <div class="rs-card"><b>{{ number_format($s['total']) }}</b><span>slides scored</span></div>
  <div class="rs-card"><b>{{ number_format($s['answered']) }}</b><span>answered</span></div>
  <div class="rs-card"><b>{{ number_format($s['withheld']) }}</b>
    <span>withheld{{ $s['withheld_pct'] !== null ? " ({$s['withheld_pct']}%)" : '' }}</span></div>
  <div class="rs-card"><b>{{ $s['accuracy'] !== null ? $s['accuracy'] . '%' : '—' }}</b>
    <span>right, of {{ $s['judged'] }} answered with a known truth</span></div>
  <div class="rs-card"><b>{{ $s['raw_accuracy'] !== null ? $s['raw_accuracy'] . '%' : '—' }}</b>
    <span>raw lean, guards ignored ({{ $s['raw_judged'] }})</span></div>
</div>

<p class="rs-note">
  Accuracy counts only slides that were both answered and have a recorded diagnosis. Refusals
  are excluded rather than scored: counting them as errors would punish the model for the one
  behaviour worth having, and counting them as successes would hide it. The raw figure beside it
  ignores every guard — it answers "what would this model have done if it were allowed to commit
  to everything", which is the number to watch when the refusal rate is high.
</p>

<form method="GET" class="rs-bar">
  <div><label style="font-size:.8rem;display:block;margin:0">Model</label>
    <select name="model_key" class="form-control" onchange="this.form.submit()">
      <option value="">all</option>
      @foreach($models as $m)
        <option value="{{ $m }}" @selected(($filters['model_key'] ?? '') === $m)>{{ $m }}</option>
      @endforeach
    </select></div>
  <div><label style="font-size:.8rem;display:block;margin:0">Site</label>
    <select name="site" class="form-control" onchange="this.form.submit()">
      <option value="">all</option>
      @foreach($sites as $st)
        <option value="{{ $st }}" @selected(($filters['site'] ?? '') === $st)>{{ $st }}</option>
      @endforeach
    </select></div>
  <div><label style="font-size:.8rem;display:block;margin:0">Truth</label>
    <select name="truth" class="form-control" onchange="this.form.submit()">
      <option value="">all</option>
      @foreach(['IDC', 'ILC'] as $t)
        <option value="{{ $t }}" @selected(($filters['truth'] ?? '') === $t)>{{ $t }}</option>
      @endforeach
    </select></div>
  <div><label style="font-size:.8rem;display:block;margin:0">Show</label>
    <select name="only" class="form-control" onchange="this.form.submit()">
      <option value="">everything</option>
      <option value="answered" @selected(($filters['only'] ?? '') === 'answered')>answered only</option>
      <option value="withheld" @selected(($filters['only'] ?? '') === 'withheld')>withheld only</option>
      <option value="wrong"    @selected(($filters['only'] ?? '') === 'wrong')>got it wrong</option>
    </select></div>
  <a class="btn btn-outline-secondary" href="{{ route('admin.ai-results') }}">Clear</a>
</form>

<table class="rs">
  <tr>
    <th>Scored</th><th>Slide</th><th>Site</th><th>Truth</th>
    <th>Model said</th><th>p(ILC)</th><th>Familiarity</th><th>Delivered</th><th></th>
  </tr>
  @forelse($rows as $r)
    @php $correct = $r->isCorrect(); @endphp
    <tr>
      <td style="white-space:nowrap">{{ $r->created_at->format('Y-m-d H:i') }}</td>
      <td class="rs-mono">{{ $r->sample?->entity_submitter_id ?: ('#' . $r->sample_id) }}</td>
      <td>{{ $r->site ?: '—' }}</td>
      <td><strong>{{ $r->truth ?: '—' }}</strong></td>
      <td>
        @if($correct === true)<span class="hit">{{ $r->call }}</span>
        @elseif($correct === false)<span class="miss">{{ $r->call }}</span>
        @else {{ $r->call ?: '—' }}
        @endif
      </td>
      <td>{{ $r->p_ilc !== null ? number_format($r->p_ilc, 2) : '—' }}</td>
      <td>{{ $r->familiarity !== null ? number_format($r->familiarity, 1) : '—' }}</td>
      <td>
        @if($r->wasWithheld())<span class="pill stop">withheld</span>
        @elseif($r->referred)<span class="pill refer">refer</span>
        @else<span class="pill ok">answered</span>
        @endif
      </td>
      <td style="white-space:nowrap">
        <a href="{{ route('admin.ai-workflow', ['sample_id' => $r->sample_id, 'model' => $r->model_key]) }}">report</a>
        ·
        <a href="{{ route('admin.ai-workflow.viewer', ['sample' => $r->sample_id, 'model' => $r->model_key]) }}">slide</a>
      </td>
    </tr>
  @empty
    <tr><td colspan="9" style="padding:1.4rem;color:#6b6480">
      Nothing scored yet. Run a slide through the workflow and it will be kept here.
    </td></tr>
  @endforelse
</table>

<div style="margin-top:1rem">{{ $rows->links() }}</div>
@endsection
