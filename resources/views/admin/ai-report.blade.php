@extends('admin.layouts.app')

@section('title', 'Report — ' . ($sample?->entity_submitter_id ?: ('slide #' . $p->sample_id)))

@section('content')
@php
    /*
     | One report, readable without scrolling.
     |
     | The order is the order the questions get asked: what was delivered, why
     | that and not something else, and does it agree with what we already knew.
     | The three gates carry their own scales, so "familiarity 40.3" is never
     | shown without the line it failed against — a number a reader cannot
     | argue with is not evidence, it is an assertion.
     */
    $withheld = $p->wasWithheld();
    $referred = (bool) $p->referred || $p->ood_status === 'warn';
    $state    = $withheld ? 'stop' : ($referred ? 'refer' : 'ok');
    $correct  = $p->isCorrect();

    $pIlc = $p->p_ilc !== null ? (float) $p->p_ilc : null;
    $low  = $model['referral']['low']  ?? 0.10;
    $high = $model['referral']['high'] ?? 0.90;

    // Familiarity is a distance with no natural ceiling, so the scale is fitted
    // to the numbers actually in play — the value, the two guard lines and where
    // known slides sit. A fixed axis would either clip the marker or squash the
    // guards into each other.
    $fWarn = $payload['ood_warn_at']   ?? ($model['familiarity']['warn']    ?? null);
    $fRef  = $payload['ood_refuse_at'] ?? ($model['familiarity']['refuse']  ?? null);
    $fTyp  = $model['familiarity']['typical'] ?? null;
    $fam   = $p->familiarity !== null ? (float) $p->familiarity : null;

    $scale = array_values(array_filter([$fam, $fWarn, $fRef, $fTyp], fn ($v) => $v !== null));
    $fLo = $scale ? floor(min($scale)) - 3 : 0;
    $fHi = $scale ? ceil(max($scale)) + 3 : 100;

    $at = function ($v, $lo, $hi) {
        if ($v === null || $hi <= $lo) return null;
        return max(0, min(100, ($v - $lo) / ($hi - $lo) * 100));
    };

    $problem = collect($gates)->firstWhere('state', 'fail')
            ?: collect($gates)->firstWhere('state', 'warn');
@endphp

<style>
  /* Everything that decides how to read this result sits in one screen. The
     detail underneath is for the second question, not the first. */
  .rp-head{display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;
           flex-wrap:wrap;margin-bottom:.9rem}
  .rp-head h2{margin:.15rem 0 .1rem;font-size:1.32rem;letter-spacing:.01em}
  .rp-back{font-size:.84rem;color:#6b6480;text-decoration:none}
  .rp-back:hover{color:#4b3a94}
  .rp-sub{font-size:.85rem;color:#6b6480}
  .rp-sub b{color:#41394f;font-weight:600}
  .rp-dot{opacity:.45;margin:0 .45rem}

  /* Three columns only once there is room for three — the sidebar takes ~17rem
     of whatever the viewport has, and a 1366 screen splitting the rest three
     ways leaves a column too narrow to set a heading in. */
  .rp-hero{display:grid;gap:1rem;align-items:stretch;margin-bottom:1.1rem}
  @media(min-width:1460px){.rp-hero{grid-template-columns:minmax(0,1fr) minmax(0,1.35fr) 17rem}}
  .rp-side{display:flex;flex-direction:column;gap:1rem}
  /* Two columns: the side cards lie down across the full width instead of
     queueing under one of them, which keeps the whole report inside a 768-tall
     screen — the height most of these machines actually have. */
  @media(min-width:820px) and (max-width:1459px){
    .rp-hero{grid-template-columns:repeat(2,minmax(0,1fr))}
    .rp-side{grid-column:1/-1;flex-direction:row}
    .rp-side > *{flex:1 1 0;min-width:0}
  }

  .rp-card{background:#fff;border:1px solid #e6e2ef;border-radius:10px;padding:.95rem 1.05rem}
  .rp-card h3{margin:0 0 .7rem;font-size:.78rem;font-weight:700;text-transform:uppercase;
              letter-spacing:.06em;color:#8b849e}

  /* The answer, and the one sentence that governs how it may be used. */
  .rp-verdict{border-radius:10px;padding:1.05rem 1.15rem;display:flex;flex-direction:column}
  .rp-verdict.ok{background:#e9f4ef;border:1px solid #b9ddd0}
  .rp-verdict.refer{background:#fbf3e2;border:1px solid #e8d19a}
  .rp-verdict.stop{background:#fceaf0;border:1px solid #f0c2d3}
  .rp-kicker{font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;opacity:.65}
  .rp-word{font-size:2.6rem;line-height:1.05;font-weight:700;margin:.25rem 0 .1rem;letter-spacing:-.02em}
  .rp-verdict.ok .rp-word{color:#24614a}
  .rp-verdict.refer .rp-word{color:#8a6410}
  .rp-verdict.stop .rp-word{color:#a3343a}
  .rp-lede{font-size:.9rem;line-height:1.55;color:#443d54;margin:.35rem 0 0}
  .rp-disown{margin-top:auto;padding-top:.75rem;border-top:1px solid rgba(0,0,0,.11);font-size:.85rem;line-height:1.5}
  .rp-disown .t{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;opacity:.7}

  /* The gates, each carrying the scale it was judged on. */
  .rp-gate{padding:.6rem 0;border-bottom:1px solid #f2f0f7}
  .rp-gate:first-of-type{padding-top:0}
  .rp-gate:last-child{border-bottom:0;padding-bottom:0}
  /* The reading and the claim it belongs to stay on one line where they fit and
     stack where they do not. Squeezing the heading into a one-word-per-line
     column to keep them side by side is the worse trade. */
  .rp-gate-top{display:flex;gap:.55rem;align-items:baseline;flex-wrap:wrap}
  .rp-mark{flex:0 0 auto;width:1.05rem;height:1.05rem;border-radius:50%;font-size:.68rem;color:#fff;
           display:inline-flex;align-items:center;justify-content:center;font-weight:700;transform:translateY(.12rem)}
  .rp-mark.pass{background:#2c6b5b}.rp-mark.warn{background:#d9a94e}.rp-mark.fail{background:#b03d64}
  .rp-gate .lbl{font-weight:600;font-size:.91rem;color:#2f2a3d;flex:1 1 14rem;min-width:0}
  .rp-gate .val{flex:0 1 auto;font-size:.84rem;color:#57516a;font-variant-numeric:tabular-nums;
                margin-left:auto;text-align:right}
  .rp-gate .note{font-size:.81rem;color:#6b6480;line-height:1.5;margin:.25rem 0 0 1.6rem}
  .rp-gate.on .note{color:#54415f}

  /* One scale, two uses: green where the model is inside its competence,
     amber where it declines, red where it refuses. */
  /* The marker's own value sits above the track and the scale's labels below
     it, so a marker parked at either end cannot land on top of the label that
     explains that end — which is exactly where the interesting cases sit. */
  .rp-scale{margin:.45rem 0 .1rem 1.6rem;position:relative;height:2.75rem}
  .rp-track{position:absolute;left:0;right:0;top:1.05rem;height:.5rem;border-radius:99px;overflow:hidden;background:#eee}
  .rp-zone{position:absolute;top:0;bottom:0}
  .rp-zone.good{background:#cfe6db}.rp-zone.mid{background:#f5e3bd}.rp-zone.bad{background:#f4ccd8}
  .rp-line{position:absolute;top:.85rem;height:.9rem;width:1px;background:#9a93ad}
  .rp-pin{position:absolute;top:.92rem;transform:translateX(-50%);width:.82rem;height:.82rem}
  .rp-pin i{box-sizing:border-box;display:block;width:.82rem;height:.82rem;border-radius:50%;
            border:2.5px solid #fff;background:#2f2a3d;box-shadow:0 0 0 1px rgba(0,0,0,.3)}
  .rp-pin b{position:absolute;bottom:1.05rem;left:50%;transform:translateX(-50%);
            font-size:.73rem;font-weight:700;font-variant-numeric:tabular-nums;color:#2f2a3d;white-space:nowrap}
  .rp-ends{position:absolute;left:0;right:0;top:1.75rem;display:flex;justify-content:space-between;
           font-size:.7rem;color:#8b849e}
  .rp-ends span:nth-child(2){position:absolute;left:50%;transform:translateX(-50%)}
  /* A label that belongs to a tick, not to the middle of the scale — put it
     anywhere else and it reads as describing a value it has nothing to do with. */
  .rp-tickly{position:absolute;top:1.75rem;transform:translateX(-50%);font-size:.7rem;
             color:#8b849e;white-space:nowrap}

  /* Truth, and whether it was met. */
  .rp-truth{text-align:center}
  .rp-truth .dx{font-size:1.5rem;font-weight:700;color:#2f2a3d;line-height:1.1}
  .rp-agree{display:inline-block;margin-top:.45rem;padding:.2rem .7rem;border-radius:99px;
            font-size:.79rem;font-weight:600}
  .rp-agree.hit{background:#e4f1ec;color:#24614a}
  .rp-agree.miss{background:#fceaf0;color:#a3343a}
  .rp-agree.none{background:#f2f0f7;color:#6b6480}
  .rp-truth .exp{font-size:.78rem;color:#6b6480;line-height:1.45;margin:.45rem 0 0}

  .rp-thumb{display:block;width:100%;border-radius:6px;border:1px solid #e6e2ef;background:#faf9fc}
  .rp-mini{font-size:.79rem;color:#6b6480;line-height:1.45;margin:.5rem 0 0}
  .rp-share{display:flex;align-items:baseline;gap:.45rem}
  .rp-share b{font-size:1.55rem;font-variant-numeric:tabular-nums;color:#2f2a3d;line-height:1}
  .rp-share span{font-size:.79rem;color:#6b6480;line-height:1.3}

  /* The two pictures, side by side and large enough to be read rather than
     recognised — a thumbnail of a patch montage tells you a montage exists and
     nothing else, and the whole claim here is that these tiles are judgeable. */
  .rp-ev{display:grid;gap:1rem;margin-top:1rem}
  @media(min-width:900px){.rp-ev{grid-template-columns:repeat(2,minmax(0,1fr));align-items:start}}
  .rp-fig{margin:0;background:#fff;border:1px solid #e6e2ef;border-radius:10px;padding:.9rem 1rem 1rem}
  .rp-fig h4{margin:0 0 .15rem;font-size:.95rem;font-weight:600;color:#2f2a3d}
  .rp-fig .cap{font-size:.81rem;color:#6b6480;line-height:1.5;margin:0 0 .7rem}
  .rp-fig img{display:block;width:100%;height:auto;border-radius:6px;background:#fff}
  .rp-fig a.full{font-size:.78rem;color:#6b6480;text-decoration:none;display:inline-block;margin-top:.5rem}
  .rp-fig a.full:hover{color:#4b3a94}

  .rp-kv{display:flex;justify-content:space-between;gap:.6rem;font-size:.84rem;padding:.3rem 0;
         border-bottom:1px solid #f4f2f8}
  .rp-kv:last-child{border-bottom:0}
  .rp-kv b{font-variant-numeric:tabular-nums;color:#2f2a3d}

  .rp-more{display:grid;gap:1rem}
  @media(min-width:1000px){.rp-more{grid-template-columns:minmax(0,1.3fr) minmax(0,1fr)}}
  table.rp-t{width:100%;border-collapse:collapse;font-size:.84rem}
  table.rp-t th{text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;
                color:#8b849e;padding:.3rem .45rem;border-bottom:1px solid #ece9f4;font-weight:700}
  table.rp-t td{padding:.34rem .45rem;border-bottom:1px solid #f6f4fa}
  table.rp-t tr:last-child td{border-bottom:0}
  .rp-mono{font-family:ui-monospace,Consolas,monospace;font-size:.8rem}
  .rp-limits{margin:0;padding-left:1.05rem}
  .rp-limits li{font-size:.82rem;color:#57516a;margin-bottom:.28rem;line-height:1.45}
  .rp-sec{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
          color:#8b849e;margin:1.5rem 0 .4rem}
  .rp-foot{font-size:.8rem;color:#8b849e;margin:1.1rem 0 0;line-height:1.5}
  details.rp-raw{margin-top:.8rem}
  details.rp-raw pre{background:#faf9fc;border:1px solid #ece9f4;border-radius:6px;padding:.7rem;
                     font-size:.76rem;max-height:20rem;overflow:auto;margin:.5rem 0 0}

  @media print{
    .sidebar,.navbar,.rp-acts,footer,.page-footer{display:none!important}
    .main-panel,.content-wrapper{margin:0!important;padding:0!important;width:100%!important}
    .rp-card,.rp-verdict,.rp-fig{break-inside:avoid}
  }
</style>

<div class="rp-head">
  <div>
    <a class="rp-back" href="{{ route('admin.ai-results') }}">← All results</a>
    <h2>{{ $sample?->entity_submitter_id ?: ($sample?->file_name ?: 'slide #' . $p->sample_id) }}</h2>
    <div class="rp-sub">
      slide #{{ $p->sample_id }}
      @if($p->site)<span class="rp-dot">·</span>site {{ $p->site }}@endif
      @if($sample?->patientCase)<span class="rp-dot">·</span>patient {{ $sample->patientCase->submitter_id }}@endif
      <span class="rp-dot">·</span><b>{{ $p->model_label ?: $p->model_key }}</b>
      <span class="rp-dot">·</span>scored {{ $p->created_at->format('j M Y, H:i') }}
      <span class="rp-dot">·</span>report #{{ $p->id }}
    </div>
  </div>
  <div class="rp-acts" style="display:flex;gap:.45rem;flex-wrap:wrap">
    @if($sample)
      <a class="btn btn-sm btn-primary"
         href="{{ route('admin.ai-workflow.viewer', ['sample' => $p->sample_id, 'model' => $p->model_key]) }}">
        Open the slide</a>
      <a class="btn btn-sm btn-outline-secondary"
         href="{{ route('admin.ai-workflow', ['sample_id' => $p->sample_id, 'model' => $p->model_key]) }}">
        Score it again</a>
    @endif
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print</button>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())
  <div class="alert alert-danger">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
@endif

<div class="rp-hero">

  {{-- the answer ───────────────────────────────────────────────────────── --}}
  <section class="rp-verdict {{ $state }}">
    <div class="rp-kicker">
      {{ $withheld ? 'Delivered' : ($referred ? 'Delivered' : 'Suggests') }}
    </div>
    <div class="rp-word">{{ $withheld ? 'Do not use' : ($referred ? 'Refer' : ($p->call ?: '—')) }}</div>

    <p class="rp-lede">
      @if($withheld)
        A guard fired, so this slide has no usable answer:
        {{ $problem['short'] ?? 'the model would have been guessing' }}.
        Read it as the model declining, not as a finding.
      @elseif($referred)
        The model will not commit here. It is inside the band it was tuned to stay out of, so the
        slide goes to a pathologist rather than into a count.
      @else
        p(ILC) = {{ number_format($pIlc ?? 0, 2) }}, calibrated — it reads as a real frequency, not a
        confidence score. A research prototype: a pathologist still signs this.
      @endif
    </p>

    @if($withheld)
      {{-- What it would have said, kept visible and clearly disowned. Hiding it
           makes the model impossible to evaluate: you cannot tell a refusal
           that saved you from a wrong answer apart from one that threw away a
           right one. --}}
      <div class="rp-disown">
        <div class="t">Withheld — for auditing this model, not for this patient</div>
        <div style="margin-top:.2rem">
          It leaned <strong>{{ $p->call ?: '—' }}</strong>, p(ILC) =
          {{ $pIlc !== null ? number_format($pIlc, 2) : '—' }}. Record it if you are testing the
          model. Do not act on it.
        </div>
      </div>
    @elseif($referred && $p->call)
      {{-- Which way it leaned. Not shown as the answer — it is precisely the
           thing the model declined to stand behind — but a reader sent to a
           pathologist is owed the direction they are being sent in. --}}
      <div class="rp-disown">
        <div class="t">Which way it leaned</div>
        <div style="margin-top:.2rem">
          Towards <strong>{{ $p->call }}</strong>, p(ILC) =
          {{ $pIlc !== null ? number_format($pIlc, 2) : '—' }} — not far enough from the middle
          for this model to stand behind it.
        </div>
      </div>
    @endif
  </section>

  {{-- why it came out that way ──────────────────────────────────────────── --}}
  <section class="rp-card">
    <h3>Why — the three gates, in order</h3>

    @foreach($gates as $g)
      <div class="rp-gate {{ $g['state'] !== 'pass' ? 'on' : '' }}">
        <div class="rp-gate-top">
          <span class="rp-mark {{ $g['state'] }}">{{ $g['state'] === 'pass' ? '✓' : ($g['state'] === 'warn' ? '!' : '✕') }}</span>
          <span class="lbl">{{ $g['label'] }}</span>
          <span class="val">{{ $g['key'] === 'inputs' && $g['state'] === 'fail' ? '' : $g['value'] }}</span>
        </div>

        @if($g['key'] === 'familiarity' && $fam !== null)
          <div class="rp-scale">
            <div class="rp-track">
              <div class="rp-zone good" style="left:0;width:{{ $at($fWarn, $fLo, $fHi) ?? 60 }}%"></div>
              <div class="rp-zone mid"  style="left:{{ $at($fWarn, $fLo, $fHi) ?? 60 }}%;
                   width:{{ max(0, ($at($fRef, $fLo, $fHi) ?? 80) - ($at($fWarn, $fLo, $fHi) ?? 60)) }}%"></div>
              <div class="rp-zone bad"  style="left:{{ $at($fRef, $fLo, $fHi) ?? 80 }}%;
                   width:{{ max(0, 100 - ($at($fRef, $fLo, $fHi) ?? 80)) }}%"></div>
            </div>
            @if($fTyp !== null)
              <div class="rp-line" style="left:{{ $at($fTyp, $fLo, $fHi) }}%"></div>
              <span class="rp-tickly" style="left:{{ max(12, min(78, $at($fTyp, $fLo, $fHi))) }}%">
                known slides ≈ {{ $fTyp }}</span>
            @endif
            <div class="rp-pin" style="left:{{ $at($fam, $fLo, $fHi) }}%">
              <b>{{ number_format($fam, 1) }}</b><i></i>
            </div>
            <div class="rp-ends">
              <span></span>
              <span></span>
              <span>refuses above {{ $fRef ?? '—' }}</span>
            </div>
          </div>
        @endif

        @if($g['key'] === 'confidence' && $pIlc !== null)
          <div class="rp-scale">
            <div class="rp-track">
              <div class="rp-zone good" style="left:0;width:{{ $low * 100 }}%"></div>
              <div class="rp-zone mid"  style="left:{{ $low * 100 }}%;width:{{ ($high - $low) * 100 }}%"></div>
              <div class="rp-zone good" style="left:{{ $high * 100 }}%;width:{{ (1 - $high) * 100 }}%"></div>
            </div>
            <div class="rp-line" style="left:50%" title="the line between the two classes"></div>
            <div class="rp-pin" style="left:{{ $at($pIlc, 0, 1) }}%">
              <b>{{ number_format($pIlc, 2) }}</b><i></i>
            </div>
            <div class="rp-ends">
              <span>IDC · 0</span>
              <span>will not commit</span>
              <span>1 · ILC</span>
            </div>
          </div>
        @endif

        <div class="note">{{ $g['note'] }}</div>
      </div>
    @endforeach
  </section>

  {{-- what we already knew, and what it looked at ───────────────────────── --}}
  <section class="rp-side">
    <div class="rp-card rp-truth">
      <h3 style="text-align:left">Recorded diagnosis</h3>
      <div class="dx">{{ $p->truth ?: 'none on file' }}</div>
      @if($correct === true)
        <span class="rp-agree hit">{{ $withheld ? 'the withheld lean was right' : 'the model agreed' }}</span>
      @elseif($correct === false)
        <span class="rp-agree miss">{{ $withheld ? 'the withheld lean was wrong' : 'the model missed it' }}</span>
      @else
        <span class="rp-agree none">nothing to score against</span>
      @endif
      <p class="exp">
        @if($correct === null)
          No diagnosis was on file for this slide when it was scored, so this run counts towards
          coverage but not towards accuracy.
        @elseif($withheld)
          Kept for auditing the guard only. A refusal that hid a correct lean and one that hid a
          wrong lean look identical from outside — this is the difference.
        @else
          The truth as it stood at scoring time, copied onto the row so a later relabelling cannot
          rewrite how this run appears to have scored.
        @endif
      </p>
    </div>

    <div class="rp-card">
      <h3>What it looked at</h3>
      @if(!empty($evidence) && $sample)
        {{-- The summary here, the pictures at full size below. Showing the map
             twice on one page would cost the space the patches need to be
             judged at all, and judging them is the point of having them. --}}
        <div class="rp-share">
          <b>{{ round(($evidence['share_top20'] ?? 0) * 100) }}%</b>
          <span>of the decision sits in the top 20 patches</span>
        </div>
        <p class="rp-mini" style="margin-top:.45rem">
          @if(($evidence['share_top20'] ?? 0) < 0.35)
            Spread thin — no region to point at; the model is reading overall texture.
          @else
            A handful of patches carry it. If they are blood, fat or edge, the answer is worth nothing.
          @endif
        </p>
        <p class="rp-mini">
          <a href="#evidence">The map and those patches ↓</a>
          @if($sample)
            · <a href="{{ route('admin.ai-workflow.viewer', ['sample' => $p->sample_id, 'model' => $p->model_key]) }}">on the slide itself</a>
          @endif
        </p>
      @elseif($sample)
        <p class="rp-mini" style="margin-top:0">
          Not built for this slide yet. The model max-pools, so every dimension of the decision comes
          from exactly one patch — this is real attribution, not a saliency guess. Building it re-reads
          the features and cuts the winning tiles out of the archive: a couple of minutes.
        </p>
        <form method="POST" action="{{ route('admin.ai-workflow.evidence') }}" style="margin-top:.5rem">
          @csrf
          <input type="hidden" name="sample_id" value="{{ $p->sample_id }}">
          <input type="hidden" name="model" value="{{ $p->model_key }}">
          <input type="hidden" name="return_to" value="{{ $p->id }}">
          <button class="btn btn-sm btn-primary">Show me what it looked at</button>
        </form>
      @endif
    </div>
  </section>
</div>

{{-- everything that is a second question ──────────────────────────────────── --}}
<div class="rp-more">
  <div>
    @if(!empty($payload['nearest_cases']))
    <div class="rp-card" style="margin-bottom:1rem">
      <h3>Nearest known cases</h3>
      <p class="rp-mini" style="margin:0 0 .5rem">
        @if($withheld)
          The archived slides this one resembles most — though "most" is still far, which is why it
          was refused. Comparing them by eye is worth more than anything above.
        @else
          Archived slides closest to this one. Opening them and comparing beats the probability.
        @endif
      </p>
      <table class="rp-t">
        <tr><th>Patient</th><th>Site</th><th>Diagnosis</th><th style="text-align:right">Distance</th></tr>
        @foreach($payload['nearest_cases'] as $n)
          <tr>
            <td class="rp-mono">{{ $n['patient'] }}</td>
            <td>{{ $n['site'] }}</td>
            <td><strong>{{ $n['diagnosis'] }}</strong></td>
            <td style="text-align:right;font-variant-numeric:tabular-nums">{{ $n['distance'] }}</td>
          </tr>
        @endforeach
      </table>
    </div>
    @endif

    @if($history->isNotEmpty())
    <div class="rp-card">
      <h3>This slide has been scored before</h3>
      <table class="rp-t">
        <tr><th>When</th><th>Model</th><th>Lean</th><th>p(ILC)</th><th>Delivered</th><th></th></tr>
        @foreach($history as $h)
          <tr>
            <td style="white-space:nowrap">{{ $h->created_at->format('j M Y H:i') }}</td>
            <td class="rp-mono">{{ $h->model_key }}</td>
            <td>{{ $h->call ?: '—' }}</td>
            <td style="font-variant-numeric:tabular-nums">{{ $h->p_ilc !== null ? number_format($h->p_ilc, 2) : '—' }}</td>
            <td>{{ $h->decision }}</td>
            <td><a href="{{ route('admin.ai-results.show', $h) }}">open</a></td>
          </tr>
        @endforeach
      </table>
    </div>
    @endif
  </div>

  <div>
    <div class="rp-card" style="margin-bottom:1rem">
      <h3>The model that said it</h3>
      <div class="rp-kv"><span>Patches read</span><b>{{ number_format((int) $p->patches) }}</b></div>
      @if(!empty($model['performance']))
        @php $perf = $model['performance']; @endphp
        <div class="rp-kv"><span>AUC, unseen laboratories</span>
          <b>{{ $perf['auc_unseen_sites'] }} <span style="color:#8b849e;font-weight:400">({{ implode('–', $perf['auc_ci']) }})</span></b></div>
        <div class="rp-kv"><span>Calibration error</span><b>{{ $perf['calibration_err'] }}</b></div>
        <div class="rp-kv"><span>Answers at all</span><b>{{ $perf['answers_pct'] }}%</b></div>
        <div class="rp-kv"><span>Right when it answers</span><b>{{ $perf['accuracy_when_answering'] }}%</b></div>
        <div class="rp-kv"><span>Trained on</span><b>{{ $perf['trained_on'] }} slides</b></div>
      @endif
      <p class="rp-mini">{{ $model['description'] ?? '' }}</p>
    </div>

    @if(!empty($model['limits']))
    <div class="rp-card">
      <h3>What it cannot do</h3>
      <ul class="rp-limits">
        @foreach($model['limits'] as $l)<li>{{ $l }}</li>@endforeach
      </ul>
    </div>
    @endif
  </div>
</div>

{{-- the pictures, at a size you can actually judge ──────────────────────────── --}}
@if(!empty($evidence) && $sample)
<h3 class="rp-sec" id="evidence">Where the evidence is</h3>
<p class="rp-mini" style="margin:0 0 .2rem;max-width:62rem">
  The model max-pools over its patches, so every dimension of the decision comes from exactly one
  patch. That makes this real attribution rather than a saliency guess — these are the tiles the
  answer was actually built from. <strong>What it is not is a tumour map:</strong> the model was
  never trained to find tumour or mark its border, only to tell two carcinoma types apart on a
  slide that already contains one. Red means "this pushed towards ILC", never "the disease is here".
</p>
<div class="rp-ev">
  <figure class="rp-fig">
    <h4>Every patch, and which way it pushed</h4>
    <p class="cap">
      Blue pushed towards IDC, red towards ILC, grey supplied nothing either way — and most of a
      slide supplies nothing, which is why most of it is grey. Circled: the 20 patches carrying
      most of the decision.
    </p>
    <a href="{{ route('admin.ai-workflow.evidence-image', [$p->sample_id, 'evidence_map.png']) }}"
       target="_blank" rel="noopener">
      <img src="{{ route('admin.ai-workflow.evidence-image', [$p->sample_id, 'evidence_map.png']) }}"
           alt="every patch on the slide, coloured by which class it pushed the answer towards">
    </a>
    <a class="full" href="{{ route('admin.ai-workflow.evidence-image', [$p->sample_id, 'evidence_map.png']) }}"
       target="_blank" rel="noopener">Open full size ↗</a>
  </figure>

  @if(!empty($evidence['top_patches']))
  <figure class="rp-fig">
    <h4>The patches the decision rested on</h4>
    <p class="cap">
      The circled ones, cut out of the archive and ordered by how much they carried, each labelled
      with the class it voted for and by how much. <strong>This is the part to read as a
      pathologist</strong> — if they are blood, fat, or the edge of the section, the probability
      above is worth nothing whatever it says.
    </p>
    <a href="{{ route('admin.ai-workflow.evidence-image', [$p->sample_id, 'top_patches.png']) }}"
       target="_blank" rel="noopener">
      <img src="{{ route('admin.ai-workflow.evidence-image', [$p->sample_id, 'top_patches.png']) }}"
           alt="the twenty patches that carried the decision, strongest first">
    </a>
    <a class="full" href="{{ route('admin.ai-workflow.evidence-image', [$p->sample_id, 'top_patches.png']) }}"
       target="_blank" rel="noopener">Open full size ↗</a>
  </figure>
  @endif
</div>
@endif

<p class="rp-foot">
  Built from the stored record of run #{{ $p->id }}, not from a cache — this page reads the same
  today and next year.
  <details class="rp-raw">
    <summary style="cursor:pointer;color:#6b6480">The result exactly as the model returned it</summary>
    <pre>{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
  </details>
</p>
@endsection
