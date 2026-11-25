{{--
  The V2 workflow bar: the eight steps a run goes through, from the queue to
  the result drawn on the slide, and where the run is now. One renderer for
  both places it appears — the full bar on a run's page, and the compact one
  in each running row of the results list. The pages poll and hand it each
  new state; it updates the elements it built in place (no re-render, so the
  bars animate instead of jumping), and the clocks tick every second.
--}}
@once
@push('styles')
<style>
  .wf{background:#fff;border:1px solid #e3e0ea;border-radius:8px;padding:1rem 1.2rem 1.05rem;margin-bottom:1rem}
  .wf-head{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem}
  .wf-head h3{margin:0;font-size:1rem;font-weight:600;color:#2c2540}
  .wf-sub{font-size:.84rem;color:#6b6480;margin-top:.15rem}
  .wf-pct{font-size:1.75rem;font-weight:700;color:#4b3a94;font-variant-numeric:tabular-nums;line-height:1}
  .wf.is-completed .wf-pct{color:#2f9a74}.wf.is-failed .wf-pct{color:#b03d64}
  .wf-bar{height:8px;background:#eeebf5;border-radius:4px;overflow:hidden;margin:.75rem 0 1.05rem}
  .wf-fill{height:100%;width:0;background:linear-gradient(90deg,#6c5bc4,#4b3a94);border-radius:4px;transition:width .8s ease;position:relative;overflow:hidden}
  .wf.is-running .wf-fill::after,.wfc-segs i.active b::after{content:"";position:absolute;inset:0;transform:translateX(-100%);
    background:linear-gradient(90deg,transparent,rgba(255,255,255,.45),transparent);animation:wf-shine 1.8s linear infinite}
  .wf.is-completed .wf-fill{background:#2f9a74}.wf.is-failed .wf-fill{background:#b03d64}
  .wf.is-waiting .wf-fill{background:linear-gradient(90deg,#d9a53a,#c58a1a)}

  ol.wf-steps{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(8,minmax(0,1fr))}
  .wf-steps li{position:relative;text-align:center;padding:0 .25rem;cursor:help}
  .wf-dot{position:relative;z-index:1;display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;
          background:#fff;border:2px solid #dcd7e8;color:#aaa3bd;font-size:.78rem;font-weight:700;transition:background .3s,border-color .3s,color .3s}
  .wf-line{position:absolute;top:14px;left:calc(50% + 18px);right:calc(-50% + 18px);height:3px;background:#e6e2ef;border-radius:2px;overflow:hidden}
  .wf-line i{display:block;height:100%;width:0;background:#2f9a74;transition:width .8s ease}
  .wf-steps li:last-child .wf-line{display:none}
  .wf-name{display:block;margin-top:.4rem;font-size:.8rem;font-weight:600;color:#aaa3bd;line-height:1.25}
  .wf-long{display:none}
  .wf-time{display:block;font-size:.74rem;color:#9a93ad;font-variant-numeric:tabular-nums;min-height:1.1em;margin-top:.1rem}
  .wf-steps li.done .wf-dot{background:#2f9a74;border-color:#2f9a74;color:#fff}
  .wf-steps li.done .wf-name{color:#3c3552}
  .wf-steps li.active .wf-dot{border-color:#4b3a94;color:#4b3a94;box-shadow:0 0 0 4px rgba(108,91,196,.16)}
  .wf-steps li.active .wf-dot::after{content:"";position:absolute;inset:-6px;border-radius:50%;border:2.5px solid transparent;
                                     border-top-color:#6c5bc4;animation:wf-rot 1s linear infinite}
  .wf-steps li.active .wf-line i{background:#6c5bc4}
  .wf-steps li.active .wf-name{color:#2c2540}
  .wf-steps li.active .wf-time{color:#4b3a94;font-weight:600}
  .wf-steps li.waiting .wf-dot{border-color:#c58a1a;color:#8a6414;background:#fdf3dc;box-shadow:0 0 0 4px rgba(217,165,58,.18)}
  .wf-steps li.waiting .wf-name,.wf-steps li.waiting .wf-time{color:#8a6414}
  .wf-steps li.failed .wf-dot{background:#b03d64;border-color:#b03d64;color:#fff}
  .wf-steps li.failed .wf-name,.wf-steps li.failed .wf-time{color:#b03d64}

  .wf-now{display:flex;gap:.8rem;align-items:flex-start;background:#f5f3fa;border-radius:8px;padding:.7rem .9rem;margin-top:1rem}
  .wf.is-waiting .wf-now{background:#fdf6e7}
  .wf-now-ic{flex:none;width:32px;height:32px;border-radius:50%;background:#4b3a94;color:#fff;display:flex;align-items:center;
             justify-content:center;font-weight:700;font-size:.85rem}
  .wf.is-waiting .wf-now-ic{background:#c58a1a}
  .wf-now-txt{flex:1;min-width:0}
  .wf-now-txt b{display:block;font-size:.97rem;color:#2c2540}
  .wf-det{font-size:.87rem;color:#57516a;margin-top:.15rem;overflow-wrap:anywhere}
  .wf-why{font-size:.79rem;color:#8a83a0;margin-top:.3rem}
  .wf-now-side{flex:none;text-align:right;min-width:6.5rem}
  .wf-mini{height:5px;background:#e3def0;border-radius:3px;overflow:hidden;width:6.5rem;margin:.4rem 0 .3rem auto}
  .wf-mini i{display:block;height:100%;width:0;background:#6c5bc4;transition:width .8s ease}
  .wf-since{font-size:.82rem;color:#4b3a94;font-weight:600;font-variant-numeric:tabular-nums}
  .wf-meta{font-size:.8rem;color:#8a83a0;margin-top:.6rem;font-variant-numeric:tabular-nums;display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap}
  .wf-live{display:inline-flex;align-items:center;gap:.4rem}
  .wf-live::before{content:"";width:7px;height:7px;border-radius:50%;background:#2f9a74;animation:wf-blink 1.6s ease-in-out infinite}
  .wf-live.off::before{background:#c58a1a;animation:none}
  @media(max-width:760px){
    ol.wf-steps{grid-template-columns:1fr}
    .wf-steps li{display:flex;align-items:center;gap:.65rem;text-align:left;padding:.28rem 0}
    .wf-line{display:none}
    .wf-name{margin:0;flex:1}.wf-short{display:none}.wf-long{display:inline}
    .wf-time{margin:0}
    .wf-now-side{min-width:0}
  }

  /* Compact: one running row of the results list */
  .wfc{min-width:15rem;max-width:26rem}
  .wfc-top{display:flex;align-items:center;gap:.55rem}
  .wfc-segs{display:flex;gap:3px;flex:1}
  .wfc-segs i{flex:1;height:7px;border-radius:2px;background:#e9e5f2;position:relative;overflow:hidden;cursor:help}
  .wfc-segs i b{position:absolute;left:0;top:0;bottom:0;width:0;background:#6c5bc4;transition:width .8s ease;overflow:hidden}
  .wfc-segs i.done{background:#2f9a74}
  .wfc-segs i.active{background:#d9d3ef}
  .wfc-segs i.active.indet b{width:40%!important;animation:wf-slide 1.4s ease-in-out infinite alternate}
  .wfc-segs i.waiting{background:#e8c36a}
  .wfc-segs i.failed{background:#c9506f}
  .wfc-pct{font-weight:700;color:#4b3a94;font-size:.82rem;font-variant-numeric:tabular-nums;min-width:2.6rem;text-align:right}
  .wfc.is-waiting .wfc-pct{color:#8a6414}
  .wfc-label{font-size:.82rem;font-weight:600;color:#2c2540;margin-top:.35rem;line-height:1.3}
  .wfc-det{font-size:.76rem;color:#7a7390;margin-top:.12rem;line-height:1.35;overflow-wrap:anywhere}
  .wfc-det time{color:#4b3a94;font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap}
  .wfc.is-waiting .wfc-label,.wfc.is-waiting .wfc-det time{color:#8a6414}

  @keyframes wf-shine{to{transform:translateX(100%)}}
  @keyframes wf-rot{to{transform:rotate(360deg)}}
  @keyframes wf-blink{50%{opacity:.25}}
  @keyframes wf-slide{from{left:0}to{left:60%}}
  @media (prefers-reduced-motion:reduce){
    .wf-fill::after,.wfc-segs i b::after,.wf-steps li.active .wf-dot::after,.wfc-segs i.active.indet b,.wf-live::before{animation:none}
  }
</style>
@endpush
<script>
window.V2Flow = (function () {
  var META = @json(\App\Services\V2DiagnoseRunner::stepMeta());
  var skew = 0;                       // server clock minus ours, so the timers match the server

  function dur(s) {
    s = Math.max(0, Math.round(s));
    var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
    if (d) return d + 'd ' + h + 'h';
    return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (x < 10 ? '0' : '') + x;
  }
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  function now() { return Date.now() / 1000 + skew; }
  function finished(P) { return P.status === 'completed' || P.status === 'failed'; }
  function stateOf(P, i) { return P.steps ? P.steps[i].state : P.states[i]; }
  function sync(P) { if (P && P.now) skew = P.now - Date.now() / 1000; }

  // Every element with data-since shows how long ago that moment was.
  function tick() {
    var t = now();
    document.querySelectorAll('[data-since]').forEach(function (el) {
      var s = +el.getAttribute('data-since');
      if (s) el.textContent = dur(t - s);
    });
  }
  setInterval(tick, 1000);

  function since(el, ts) {
    if (ts) { el.setAttribute('data-since', ts); el.textContent = dur(now() - ts); }
    else { el.removeAttribute('data-since'); el.textContent = ''; }
  }

  /* ── The full bar, on a run's page ─────────────────────────────────────── */
  function full(root, P, stageMessage) {
    sync(P);
    var r = root._wf;
    if (!r) {
      root.innerHTML =
        '<div class="wf-head"><div><h3 data-r="title"></h3><div class="wf-sub" data-r="sub"></div></div>'
        + '<span class="wf-pct" data-r="pct"></span></div>'
        + '<div class="wf-bar"><div class="wf-fill" data-r="fill"></div></div>'
        + '<ol class="wf-steps">' + META.map(function (m, i) {
            return '<li><span class="wf-dot">' + (i + 1) + '</span>'
              + '<span class="wf-name"><span class="wf-short">' + esc(m.short) + '</span><span class="wf-long">' + esc(m.label) + '</span></span>'
              + '<span class="wf-time"></span><span class="wf-line"><i></i></span></li>';
          }).join('') + '</ol>'
        + '<div class="wf-now" data-r="now"><span class="wf-now-ic" data-r="nowNo"></span>'
        + '<div class="wf-now-txt"><b data-r="nowLabel"></b><div class="wf-det" data-r="nowDet"></div><div class="wf-why" data-r="nowWhy"></div></div>'
        + '<div class="wf-now-side"><div class="wf-mini" data-r="mini"><i></i></div><span class="wf-since" data-r="nowSince"></span></div></div>'
        + '<div class="wf-meta"><span data-r="meta"></span><span class="wf-live" data-r="live"></span></div>';
      r = root._wf = { shown: 0, lis: root.querySelectorAll('.wf-steps li'), q: {} };
      root.querySelectorAll('[data-r]').forEach(function (el) { r.q[el.getAttribute('data-r')] = el; });
    }
    var q = r.q, done = finished(P), at = P.index, n = P.count, m = META[at];
    // The bar never moves back while a run is going (a step can refine its estimate).
    r.shown = done ? P.percent : Math.max(r.shown, P.percent);
    root.classList.toggle('is-running', !done && !P.waiting);
    root.classList.toggle('is-waiting', !done && !!P.waiting);
    root.classList.toggle('is-completed', P.status === 'completed');
    root.classList.toggle('is-failed', P.status === 'failed');

    var total = P.finished && P.began ? P.finished - P.began : null;
    if (P.status === 'completed') {
      q.title.textContent = 'How this result was reached';
      q.sub.textContent = 'All ' + n + ' steps completed' + (total !== null ? ' in ' + dur(total) : '');
    } else if (P.status === 'failed') {
      q.title.textContent = 'Analysis workflow';
      q.sub.textContent = 'Stopped at step ' + (at + 1) + ' of ' + n + ' — ' + m.label;
    } else {
      q.title.textContent = 'Analysis workflow';
      q.sub.textContent = 'Step ' + (at + 1) + ' of ' + n + ' — ' + m.label + (P.waiting ? ' (waiting)' : '');
    }
    q.pct.textContent = r.shown + '%';
    q.fill.style.width = r.shown + '%';

    r.lis.forEach(function (li, i) {
      var st = stateOf(P, i), s = P.steps ? P.steps[i] : {}, meta = META[i];
      li.className = st;
      li.querySelector('.wf-dot').textContent = st === 'done' ? '✓' : st === 'failed' ? '!' : String(i + 1);
      var t = li.querySelector('.wf-time');
      if (st === 'active' || st === 'waiting') {
        since(t, i === at ? P.since : null);
        if (st === 'waiting' && !P.since) t.textContent = 'waiting';
      } else {
        since(t, null);
        t.textContent = st === 'done' && s.seconds != null ? dur(s.seconds) : st === 'failed' ? 'stopped here' : '';
      }
      var line = li.querySelector('.wf-line i');
      line.style.width = (st === 'done' ? 100 : st === 'active' && P.frac != null ? Math.round(P.frac * 100) : 0) + '%';
      li.title = meta.label + ' — ' + meta.hint
        + (st === 'done' && s.seconds != null ? ' (took ' + dur(s.seconds) + ')' : '');
    });

    // What is happening right now, while it runs.
    q.now.style.display = done ? 'none' : '';
    if (!done) {
      q.nowNo.textContent = at + 1;
      q.nowLabel.textContent = m.label;
      q.nowDet.textContent = P.detail || stageMessage || '';
      q.nowWhy.textContent = 'What happens here: ' + m.hint;
      q.mini.style.display = P.frac != null ? '' : 'none';
      q.mini.firstChild.style.width = Math.round((P.frac || 0) * 100) + '%';
      since(q.nowSince, P.since);
    }
    if (done) {
      since(q.meta, null);
      q.meta.textContent = P.status === 'failed' && total !== null ? 'Ran for ' + dur(total) + ' before it stopped' : '';
      q.live.style.display = 'none';
    } else {
      q.meta.textContent = '';
      var lbl = document.createElement('span');
      q.meta.appendChild(document.createTextNode(P.waiting ? 'Waiting for ' : (at === 0 ? 'In the queue for ' : 'Running for ')));
      since(lbl, at === 0 || P.waiting ? P.since || P.began : P.began);
      q.meta.appendChild(lbl);
      q.live.style.display = '';
    }
  }

  /** The live dot under the full bar: on while updates arrive, amber while they do not. */
  function liveState(root, ok) {
    var el = root._wf && root._wf.q.live;
    if (!el) return;
    el.classList.toggle('off', !ok);
    el.textContent = ok ? 'Live — updates every few seconds' : 'Connection lost — retrying…';
  }

  /* ── The compact bar, in a running row of the results list ─────────────── */
  function compact(el, P) {
    sync(P);
    var r = el._wf;
    if (!r) {
      el.classList.add('wfc');
      el.innerHTML = '<div class="wfc-top"><span class="wfc-segs">'
        + META.map(function (m, i) { return '<i title="' + esc((i + 1) + '. ' + m.label + ' — ' + m.hint) + '"><b></b></i>'; }).join('')
        + '</span><span class="wfc-pct"></span></div><div class="wfc-label"></div><div class="wfc-det"><span></span> <time></time></div>';
      r = el._wf = { shown: 0, segs: el.querySelectorAll('.wfc-segs i'), pct: el.querySelector('.wfc-pct'),
                     label: el.querySelector('.wfc-label'), det: el.querySelector('.wfc-det span'), time: el.querySelector('.wfc-det time') };
    }
    var at = P.index, m = META[at];
    r.shown = Math.max(r.shown, P.percent);
    el.classList.toggle('is-waiting', !!P.waiting);
    r.segs.forEach(function (s, i) {
      var st = stateOf(P, i);
      s.className = st + (st === 'active' && P.frac == null ? ' indet' : '');
      s.firstChild.style.width = st === 'active' && P.frac != null ? Math.round(P.frac * 100) + '%' : '';
    });
    r.pct.textContent = r.shown + '%';
    r.label.textContent = 'Step ' + (at + 1) + ' of ' + P.count + ' · ' + m.label;
    r.det.textContent = P.detail || (P.waiting ? 'Waiting' : '');
    since(r.time, P.since);
  }

  return { meta: META, dur: dur, esc: esc, full: full, compact: compact, liveState: liveState };
})();
</script>
@endonce
