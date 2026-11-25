{{--
  One row of the results list. Rendered by the list, and again by the live
  endpoint when a running run finishes, so the row is replaced in place.
  $live: where a running run is in its workflow (V2DiagnoseRunner::brief).
--}}
@php
  $v = $r->verdict(); $truth = $r->recordedClass();
  $state = in_array($r->status, ['completed', 'failed']) ? $r->status : 'run';
  $slide = $r->sample?->entity_submitter_id ?: ($r->sample?->file_name ?: 'sample #'.$r->sample_id);
  $conf = $r->confidence !== null ? (int) round($r->confidence * 100) : null;
  $who = collect([$r->sex ? ucfirst($r->sex) : null, $r->age ? $r->age.' y' : null, $r->race])->filter()->implode(' · ');
@endphp
<tr class="v2-r-{{ $v['result'] ?? ($state === 'failed' ? 'failed' : ($state === 'run' ? 'running' : 'none')) }}"
    data-run="{{ $r->id }}"{!! $state === 'run' ? ' data-live' : '' !!}>
  <td class="v2-c-act"><div class="v2-rowbtns">
    <a href="{{ route('admin.v2-diagnose.show', $r) }}" class="v2-eye" title="Open run #{{ $r->id }}: slide, regions, heatmap"
       aria-label="Open run #{{ $r->id }}"><i class="mdi mdi-eye-outline"></i></a>
    <button type="button" class="v2-del" data-del="{{ route('admin.v2-diagnose.destroy', $r) }}" data-id="{{ $r->id }}"
            data-slide="{{ $slide }}" data-dx="{{ $r->diagnosis }}" data-running="{{ $r->isRunning() ? $r->status : '' }}"
            title="Delete run #{{ $r->id }}" aria-label="Delete run #{{ $r->id }}"><i class="mdi mdi-trash-can-outline"></i></button>
  </div></td>
  <td class="v2-c-run">
    <a href="{{ route('admin.v2-diagnose.show', $r) }}" class="v2-runno">#{{ $r->id }}</a>
    <span class="v2-st {{ $state }}" data-st>{{ str_replace('_', ' ', $r->status) }}</span>
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
    @elseif($state === 'run')
      {{-- Drawn by V2Flow.compact from this state, then kept live by the list. --}}
      <div data-wf='@json($live)'><span class="v2-missing">Running…</span></div>
    @else
      <span class="v2-missing">—</span>
    @endif
  </td>
  <td class="v2-c-truth">
    @if($truth)<span class="v2-truth">{{ $truth }}</span>
    @else<span class="v2-missing">not recorded</span>@endif
  </td>
  <td class="v2-c-res">
    @if($v)
      <span class="v2-match {{ $v['result'] }}">{{ ['correct' => '✓ Correct', 'partial' => '≈ Partial', 'wrong' => '✗ Wrong', 'review' => '⚑ Label under review'][$v['result']] }}</span>
      <div class="v2-sub">{{ $v['reason'] }}</div>
    @else
      <span class="v2-missing">{{ $state === 'failed' ? 'failed — no answer' : ($state === 'run' ? 'when the run ends' : ($truth ? '—' : 'cannot be scored')) }}</span>
    @endif
  </td>
</tr>
