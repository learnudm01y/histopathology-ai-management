{{--
    Inline-editable verification field chip.
    Variables:
      $colName     – DB column name (string)
      $rawValue    – current value from the verification model (mixed|null)
      $meta        – ['type'=>'text'|'number'|'select'|'textarea', 'options'=>[], 'step'=>, 'min'=>, 'max'=>]
      $placeholder – optional placeholder for text/number inputs (string)
--}}
@php
    $rv      = ($rawValue !== null && $rawValue !== '') ? (string) $rawValue : null;
    $dispVal = $rv !== null
        ? (mb_strlen($rv) > 34 ? '…' . mb_substr($rv, -33) : $rv)
        : null;
    $mtype   = $meta['type'] ?? 'text';
@endphp

<div class="vv-field" data-field="{{ $colName }}" data-current="{{ $rv ?? '' }}">

    {{-- Display chip --}}
    <span class="vv-display badge badge-light border small"
          style="cursor:pointer; max-width:190px; overflow:hidden; text-overflow:ellipsis;
                 white-space:nowrap; display:inline-block; vertical-align:middle;"
          title="{{ $rv }}">
        {{ $dispVal ?? '—' }}&nbsp;<i class="mdi mdi-pencil-outline" style="font-size:.62rem; opacity:.4;"></i>
    </span>

    {{-- Edit group (hidden until clicked) --}}
    <div class="vv-input-group d-none" style="min-width:140px;">

        @if($mtype === 'select')
            <select class="form-control form-control-sm vv-input">
                <option value="">— clear —</option>
                @foreach($meta['options'] as $opt => $lbl)
                    <option value="{{ $opt }}" @if($rv === $opt) selected @endif>{{ $lbl }}</option>
                @endforeach
            </select>

        @elseif($mtype === 'textarea')
            <textarea class="form-control form-control-sm vv-input" rows="2">{{ $rv }}</textarea>

        @elseif($mtype === 'number')
            <input type="number"
                   class="form-control form-control-sm vv-input"
                   value="{{ $rv }}"
                   placeholder="{{ $placeholder ?? '' }}"
                   style="width:130px;"
                   @isset($meta['step']) step="{{ $meta['step'] }}" @endisset
                   @isset($meta['min'])  min="{{ $meta['min'] }}"   @endisset
                   @isset($meta['max'])  max="{{ $meta['max'] }}"   @endisset>

        @else
            <input type="text"
                   class="form-control form-control-sm vv-input"
                   value="{{ $rv }}"
                   placeholder="{{ $placeholder ?? '' }}"
                   style="width:160px;">
        @endif

        <div class="mt-1 d-flex" style="gap:3px;">
            <button type="button" class="btn btn-success btn-xs vv-save py-0 px-2"
                    style="font-size:.72rem; line-height:1.5;">Save</button>
            <button type="button" class="btn btn-light btn-xs vv-cancel py-0 px-2"
                    style="font-size:.72rem; line-height:1.5;">✕</button>
        </div>
    </div>

</div>
