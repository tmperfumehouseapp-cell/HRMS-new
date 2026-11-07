/* wa_seg_ui.js — the customer journey strip and the audience builder (stage / value / dates) for WhatsApp.
 * Needs the global `api` (assets/js/api.js). Talks to wa_seg.php (wa.seg_*). Nothing here sends a message; it only reads counts and a customer's story.
 *
 *   WaSeg.journey(container, { phone })                     compact dated strip (dots on a line) + 3 chips (stage, value, trend) + the list, newest first.  -> { refresh(), destroy() }
 *   WaSeg.open({ phone, name })                             the same journey in a bottom sheet (for a button in the phone app's chat header).  -> { close() }
 *   WaSeg.audience(container, { onChange, filters, compact }) the audience builder for the campaign step: stage / value / source / language / number chips,
 *                                                           dates (first wrote, last wrote, woke-up, asked, bought), skip rules, a live count line with the "adds up" sentence
 *                                                           and month bars (tap a bar to pick that month, tap a second bar to pick a range).
 *                                                           onChange(filters, count) fires after every change (count = the wa.seg_count answer, null while loading).
 *                                                           -> { getFilters(), setFilters(obj), refresh(), destroy() }
 * The filter object is exactly what wa_aud_where / wa.seg_count accept (keys stage[], value[], source[], language[], first_number[], first_on_from/to, last_in_on_from/to,
 * silent_min_days, returned_within_days, asked_days, order_count_min/max, spent_min, exclude_bought_days, exclude_messaged_days, exclude_complaint).
 * CSS prefix wsg-, injected once. Mobile first (390px), light and dark (html.wxdk).
 */
window.WaSeg = (function () {
  'use strict';
  if (window.WaSeg) return window.WaSeg;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const errMsg = (e) => (e && e.message) || 'Something went wrong. Try again.';
  const nf = (n) => Number(n || 0).toLocaleString('en-IN');
  const inr = (n) => '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
  const parse = (s) => { const m = /^(\d{4})-(\d\d)-(\d\d)(?:[ T](\d\d):(\d\d))?/.exec(String(s || '')); return m ? new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)) : null; };
  const dshort = (s) => { const d = parse(s); return d ? d.getDate() + ' ' + MON[d.getMonth()] : ''; };
  const dlong = (s) => { const d = parse(s); return d ? d.getDate() + ' ' + MON[d.getMonth()] + ' ' + d.getFullYear() : ''; };
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const monthLabel = (m) => { const x = /^(\d{4})-(\d\d)$/.exec(m || ''); return x ? MON[+x[2] - 1] + ' ' + x[1] : ''; };
  const monthEnd = (m) => { const x = /^(\d{4})-(\d\d)$/.exec(m); return ymd(new Date(+x[1], +x[2], 0)); };
  const daysAgoDate = (n) => { const d = new Date(); d.setHours(12, 0, 0, 0); d.setDate(d.getDate() - n); return ymd(d); };

  const CSS = `
  .wsg{font-family:Inter,"Segoe UI",system-ui,-apple-system,Arial,sans-serif;color:#111b21;-webkit-tap-highlight-color:transparent;font-size:14px} .wsg *{box-sizing:border-box}
  .wsg-card{background:#fff;border:1px solid #e9edef;border-radius:16px;padding:12px;margin:8px 0}
  .wsg-h{font-size:17px;font-weight:800;margin:0 0 6px} .wsg-l{font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#667781;margin:12px 0 6px} .wsg-l small{text-transform:none;letter-spacing:0;font-weight:600;margin-left:4px}
  .wsg-sub{font-size:12.5px;color:#667781;line-height:1.35;margin:2px 0 6px}
  .wsg-chips{display:flex;flex-wrap:wrap;gap:8px}
  .wsg-c{border:1.5px solid #e9edef;background:#fff;border-radius:999px;padding:8px 13px;min-height:40px;font:inherit;font-size:14px;font-weight:700;color:#111b21;cursor:pointer;text-align:center;line-height:1.2}
  .wsg-c small{color:#667781;font-weight:600;margin-left:5px;font-size:12.5px}
  .wsg-c.on{border-color:#00a884;background:#e7fbf3;color:#007a62} .wsg-c.on small{color:#007a62}
  .wsg-c.sm{min-height:34px;padding:6px 11px;font-size:13px}
  .wsg-in{border:1.5px solid #e9edef;border-radius:12px;padding:9px 11px;font:inherit;font-size:16px;background:#fff;color:#111b21;min-width:0;width:100%;-webkit-appearance:none;appearance:none;outline:0} .wsg-in:focus{border-color:#00a884}
  .wsg-two{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px} .wsg-two>div{min-width:0} .wsg-in[type=date]{min-width:0;max-width:100%;padding:9px 8px;font-size:15px} .wsg-two label{font-size:12px;font-weight:700;color:#667781;display:block;margin-bottom:3px}
  .wsg-bars{display:flex;align-items:flex-end;gap:2px;height:76px;margin:10px 0 0;padding:0 1px} .wsg-bar{flex:1 1 0;min-width:4px;display:flex;flex-direction:column;justify-content:flex-end;height:100%;cursor:pointer;border:0;background:none;padding:0}
  .wsg-bar i{display:block;width:100%;background:#cfd6db;border-radius:3px 3px 0 0;min-height:2px} .wsg-bar i.imp{background:#f2c46d;border-radius:3px 3px 0 0} .wsg-bar.on i{background:#1fa855} .wsg-bar.on i.imp{background:#8fd7a8}
  .wsg-ml{display:flex;gap:2px;padding:0 1px;margin-top:3px} .wsg-ml span{flex:1 1 0;min-width:4px;text-align:center;font-size:11px;color:#8696a0;font-weight:700;overflow:visible;white-space:nowrap}
  .wsg-note{font-size:13px;color:#3b4a54;margin:6px 0 0;line-height:1.35} .wsg-note b{font-weight:800}
  .wsg-sw{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 0;border-top:1px solid #f0f2f5;font-size:14px;font-weight:600;cursor:pointer} .wsg-sw:first-of-type{border-top:0}
  .wsg-sw input{width:22px;height:22px;accent-color:#00a884;flex:none} .wsg-sw span small{display:block;color:#667781;font-weight:500;font-size:12px}
  .wsg-live{background:#e7fbf3;border:1px solid #bfe9d6;border-radius:16px;padding:12px 14px;margin:8px 0;display:flex;align-items:center;gap:12px}
  .wsg-live .big{font-size:32px;font-weight:800;color:#007a62;line-height:1;min-width:0} .wsg-live .tx{font-size:13.5px;font-weight:700;color:#0b6b57;line-height:1.3} .wsg-live .tx small{display:block;font-weight:600;color:#3b7a6c}
  .wsg-live.load .big{opacity:.45}
  .wsg-add{background:#fff;border:1px solid #e9edef;border-radius:14px;padding:10px 12px;margin:8px 0;font-size:13px;line-height:1.45;color:#3b4a54} .wsg-add b{color:#111b21}
  .wsg-add.bad{border-color:#f3c5c2;background:#fffafa}
  .wsg-stack{display:flex;height:9px;border-radius:99px;overflow:hidden;margin:8px 0 4px;background:#e9edef} .wsg-stack i{display:block;height:100%} .wsg-leg{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:12px;color:#54656f} .wsg-leg span:before{content:"";display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:5px;background:var(--c)}
  .wsg-err{background:#fde4e4;border-radius:12px;padding:10px 12px;font-size:13px;color:#7a1d17;margin:8px 0} .wsg-err button{border:0;background:none;color:#b3261e;font:inherit;font-weight:800;text-decoration:underline;cursor:pointer;padding:0 0 0 6px}
  .wsg-reset{border:0;background:none;color:#008069;font:inherit;font-size:13px;font-weight:800;cursor:pointer;padding:8px 0}
  .wsg-more{border:0;background:none;color:#008069;font:inherit;font-size:13px;font-weight:800;cursor:pointer;padding:6px 0}
  /* journey */
  .wsg-j3{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
  .wsg-j3>div{border:1px solid #e9edef;border-radius:14px;padding:9px 10px;background:#f7f8fa;min-width:0} .wsg-j3 small{display:block;font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#667781}
  .wsg-j3 b{display:block;font-size:17px;font-weight:800;margin-top:2px;line-height:1.15} .wsg-j3 em{display:block;font-style:normal;font-size:12px;color:#54656f;margin-top:2px;line-height:1.3}
  .wsg-j3 .st{background:#e7fbf3;border-color:#bfe9d6} .wsg-j3 .st b{color:#007a62} .wsg-j3 .va{background:#f1edff;border-color:#ddd3ff} .wsg-j3 .va b{color:#5b3fd1}
  .wsg-j3 .tr.up b{color:#007a62} .wsg-j3 .tr.down b{color:#b3261e}
  .wsg-first{font-size:13.5px;margin:10px 0 2px;color:#111b21;font-weight:600}
  .wsg-flags{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 0} .wsg-fl{font-size:12px;font-weight:700;background:#f0f2f5;color:#3b4a54;border-radius:999px;padding:4px 10px}
  .wsg-fl.pos{background:#e7f5ff;color:#0b5394} .wsg-fl.warn{background:#fff1d6;color:#8a5a00} .wsg-fl.bad{background:#fde4e4;color:#b3261e}
  .wsg-strip{position:relative;height:46px;margin:14px 6px 2px} .wsg-line{position:absolute;left:0;right:0;top:14px;height:3px;background:#e9edef;border-radius:3px}
  .wsg-gap{position:absolute;top:12px;height:7px;border-radius:4px;background:repeating-linear-gradient(90deg,#d9dee2 0 4px,transparent 4px 8px)}
  .wsg-dot{position:absolute;top:8px;width:15px;height:15px;margin-left:-7px;border-radius:50%;border:3px solid #8696a0;background:#fff;padding:0} .wsg-dot.session{border-color:#1fa855} .wsg-dot.order{border-color:#7c5cf0;background:#7c5cf0} .wsg-dot.campaign{border-color:#f59e0b} .wsg-dot.tag{border-color:#3b82f6} .wsg-dot.woke{background:#1fa855;box-shadow:0 0 0 4px #d5f3e0} .wsg-dot.first{border-color:#3b82f6;background:#fff}
  .wsg-dl{position:absolute;top:30px;font-size:11px;font-weight:700;color:#8696a0;white-space:nowrap} .wsg-dl.l{left:0} .wsg-dl.r{right:0}
  .wsg-tl{list-style:none;margin:8px 0 0;padding:0 0 0 14px;border-left:2px dotted #cfd6db}
  .wsg-tl li{position:relative;padding:0 0 11px 10px;margin:0} .wsg-tl li:before{content:"";position:absolute;left:-22px;top:3px;width:12px;height:12px;border-radius:50%;background:#fff;border:3px solid #8696a0}
  .wsg-tl li.session:before{border-color:#1fa855} .wsg-tl li.order:before{border-color:#7c5cf0;background:#7c5cf0} .wsg-tl li.campaign:before{border-color:#f59e0b} .wsg-tl li.tag:before{border-color:#3b82f6} .wsg-tl li.first:before{border-color:#3b82f6} .wsg-tl li.woke:before{background:#1fa855;border-color:#1fa855}
  .wsg-tl time{display:block;font-size:12px;font-weight:800;color:#667781} .wsg-tl p{margin:1px 0 0;font-size:13.5px;line-height:1.35;color:#111b21;word-break:break-word}
  .wsg-tl li.gap{padding:0 0 11px 10px} .wsg-tl li.gap:before{border-style:dashed;border-color:#b6bfc5;width:10px;height:10px;left:-21px} .wsg-tl li.gap p{color:#667781;background:#f7f8fa;border:1px dashed #d5dbe0;border-radius:10px;padding:6px 10px;font-size:12.5px;font-weight:600}
  .wsg-skel{height:14px;border-radius:6px;background:#eef0f2;margin:8px 0;animation:wsgp 1.2s ease-in-out infinite} @keyframes wsgp{50%{opacity:.5}}
  .wsg-empty{font-size:13px;color:#667781;padding:10px 2px}
  .wsg-ov{position:fixed;inset:0;z-index:2147483000;background:rgba(17,27,33,.55);display:flex;align-items:flex-end;justify-content:center}
  .wsg-sh{background:#f0f2f5;width:100%;max-width:520px;max-height:92vh;overflow:auto;border-radius:22px 22px 0 0;padding:12px 12px calc(14px + env(safe-area-inset-bottom))}
  .wsg-shh{display:flex;align-items:center;justify-content:space-between;padding:2px 4px 0} .wsg-shh b{font-size:17px;font-weight:800} .wsg-x{width:38px;height:38px;border:0;border-radius:50%;background:#e4e7ea;color:#54656f;font-size:22px;cursor:pointer;line-height:1}
  @media (min-width:700px){.wsg-ov{align-items:center}.wsg-sh{border-radius:22px}}
  html.wxdk .wsg-sh{background:#111b21;color:#e9edef} html.wxdk .wsg-x{background:#2a3942;color:#aebac1}
  /* dark mode (html.wxdk) */
  html.wxdk .wsg{color:#e9edef} html.wxdk .wsg-card,html.wxdk .wsg-add{background:#202c33;border-color:#2a3942} html.wxdk .wsg-add{color:#aebac1} html.wxdk .wsg-add b{color:#e9edef} html.wxdk .wsg-add.bad{background:#2b2020;border-color:#5a2e2b}
  html.wxdk .wsg-l,html.wxdk .wsg-sub,html.wxdk .wsg-two label,html.wxdk .wsg-c small,html.wxdk .wsg-sw span small,html.wxdk .wsg-j3 small,html.wxdk .wsg-tl time,html.wxdk .wsg-empty,html.wxdk .wsg-leg{color:#8696a0}
  html.wxdk .wsg-c{background:#111b21;border-color:#2a3942;color:#e9edef} html.wxdk .wsg-c.on{background:#0b3d33;border-color:#00a884;color:#7fe0c6} html.wxdk .wsg-c.on small{color:#7fe0c6}
  html.wxdk .wsg-in{background:#111b21;border-color:#2a3942;color:#e9edef}
  html.wxdk .wsg-bar i{background:#3b4a54} html.wxdk .wsg-bar.on i{background:#25c26a} html.wxdk .wsg-bar i.imp{background:#8a6a2a} html.wxdk .wsg-bar.on i.imp{background:#3e8f5c}
  html.wxdk .wsg-note{color:#aebac1} html.wxdk .wsg-sw{border-color:#2a3942}
  html.wxdk .wsg-live{background:#0b3d33;border-color:#1c5a4b} html.wxdk .wsg-live .big{color:#7fe0c6} html.wxdk .wsg-live .tx{color:#a7ead8} html.wxdk .wsg-live .tx small{color:#7fb8a8}
  html.wxdk .wsg-stack{background:#2a3942} html.wxdk .wsg-reset,html.wxdk .wsg-more{color:#53bdeb} html.wxdk .wsg-err{background:#3a1f1f;color:#f5b5b0}
  html.wxdk .wsg-j3>div{background:#111b21;border-color:#2a3942} html.wxdk .wsg-j3 .st{background:#0b3d33;border-color:#1c5a4b} html.wxdk .wsg-j3 .st b{color:#7fe0c6} html.wxdk .wsg-j3 .va{background:#262046;border-color:#3d3566} html.wxdk .wsg-j3 .va b{color:#b6a6ff}
  html.wxdk .wsg-j3 em{color:#aebac1} html.wxdk .wsg-j3 .tr.up b{color:#7fe0c6} html.wxdk .wsg-j3 .tr.down b{color:#ff8a80} html.wxdk .wsg-first{color:#e9edef}
  html.wxdk .wsg-fl{background:#2a3942;color:#d1d7db} html.wxdk .wsg-fl.pos{background:#16344a;color:#8fc7f0} html.wxdk .wsg-fl.warn{background:#4a3a14;color:#f5cf86} html.wxdk .wsg-fl.bad{background:#4a1f1f;color:#ff9b93}
  html.wxdk .wsg-line{background:#2a3942} html.wxdk .wsg-dot{background:#202c33} html.wxdk .wsg-dot.order{background:#7c5cf0} html.wxdk .wsg-dot.woke{background:#25c26a} html.wxdk .wsg-dl{color:#8696a0}
  html.wxdk .wsg-tl{border-color:#3b4a54} html.wxdk .wsg-tl li:before{background:#202c33} html.wxdk .wsg-tl li.order:before{background:#7c5cf0} html.wxdk .wsg-tl li.woke:before{background:#25c26a} html.wxdk .wsg-tl p{color:#e9edef}
  html.wxdk .wsg-tl li.gap p{background:#111b21;border-color:#3b4a54;color:#8696a0} html.wxdk .wsg-skel{background:#2a3942}
  @media (min-width:700px){.wsg-bars{height:96px}}
  `;
  function css() { if (document.getElementById('wsg-css')) return; const s = document.createElement('style'); s.id = 'wsg-css'; s.textContent = CSS; document.head.appendChild(s); }

  /* ------------------------------------------------------------------ journey */
  const TREND = { rising: ['Rising', '↗', 'up'], steady: ['Steady', '→', ''], falling: ['Falling', '↘', 'down'], none: ['No signal', '–', ''] };
  const FLAGS = { discount_buyer: ['Buys on discount', 'warn'], asks_not_buys: ['Asks a lot, does not buy', 'warn'], cod: ['Pays cash on delivery', ''], prepaid: ['Pays online', ''], complaint: ['Open complaint', 'bad'] };
  function journey(container, opts) {
    css(); opts = opts || {}; const phone = String(opts.phone || ''); let dead = false, seq = 0;
    container.classList.add('wsg');
    const root = document.createElement('div'); container.innerHTML = ''; container.appendChild(root);
    const skel = () => { root.innerHTML = '<div class="wsg-card"><div class="wsg-skel" style="width:60%"></div><div class="wsg-skel"></div><div class="wsg-skel" style="width:80%"></div></div>'; };
    function stripHtml(d) {
      const items = (d.timeline || []).slice().reverse().filter((x) => parse(x.at)); if (!items.length) return '';
      const t0 = parse(d.first && d.first.at) || parse(items[0].at), t1 = new Date(); const lo = Math.min(t0.getTime(), parse(items[0].at).getTime()), hi = Math.max(t1.getTime(), parse(items[items.length - 1].at).getTime()), span = Math.max(hi - lo, 86400000);
      const pos = (dt) => Math.max(0, Math.min(100, ((dt.getTime() - lo) / span) * 100));
      let h = '<div class="wsg-strip" aria-hidden="true"><div class="wsg-line"></div>';
      if (d.woke_at && parse(d.woke_at)) { const w = parse(d.woke_at); const prev = items.filter((x) => parse(x.at) < w); const pe = prev.length ? parse(prev[prev.length - 1].at) : null; if (pe) h += '<div class="wsg-gap" style="left:' + pos(pe) + '%;width:' + Math.max(0, pos(w) - pos(pe)) + '%"></div>'; }
      h += '<span class="wsg-dot first" style="left:' + pos(t0) + '%" title="First contact ' + esc(dlong(d.first && d.first.at)) + '"></span>';
      items.forEach((x) => { h += '<span class="wsg-dot ' + esc(x.type) + '" style="left:' + pos(parse(x.at)) + '%" title="' + esc(dshort(x.at) + ': ' + x.text) + '"></span>'; });
      h += '<span class="wsg-dl l">' + esc(dshort(d.first && d.first.at || items[0].at)) + '</span><span class="wsg-dl r">Now</span></div>';
      return h;
    }
    function gapText(days) { return days >= 60 ? 'about ' + Math.round(days / 30) + ' months' : days + ' days'; }
    function render(d) {
      const tr = TREND[d.interest_trend] || TREND.none, f = d.facts || {}, flags = (f.flags || []).filter((x) => FLAGS[x]);
      let sub = d.stage_help || ''; if (d.stage === 'woke_up' && d.woke_at) sub = 'came back ' + dshort(d.woke_at) + ' after a long silence';
      let h = '<div class="wsg-card">';
      h += '<div class="wsg-j3"><div class="st"><small>Stage</small><b>' + esc(d.stage_label || d.stage) + '</b><em>' + esc(sub) + '</em></div>';
      h += '<div class="va"><small>Value</small><b>' + esc(d.value_label || d.value_tier) + '</b><em>' + (d.orders ? esc(inr(d.spent)) + ' in ' + d.orders + (d.orders === 1 ? ' order' : ' orders') : 'No order yet') + '</em></div>';
      h += '<div class="tr ' + tr[2] + '"><small>Trend</small><b>' + tr[1] + ' ' + tr[0] + '</b><em>' + (f.asks_30 != null ? esc(f.sessions_30 + (f.sessions_30 === 1 ? ' chat' : ' chats') + ' in 30 days') : '') + '</em></div></div>';
      h += '<div class="wsg-first">' + esc((d.first && d.first.label) || '') + (d.first && d.first.number ? ' · on ' + esc(d.first.number) : '') + '</div>';
      const chips = []; flags.forEach((x) => chips.push('<span class="wsg-fl ' + FLAGS[x][1] + '">' + esc(FLAGS[x][0]) + '</span>'));
      if (f.optout) chips.unshift('<span class="wsg-fl bad">Opted out: never message</span>');
      if (f.language && f.language !== 'unknown') chips.push('<span class="wsg-fl">Writes in ' + esc({ en: 'English', hi: 'Hindi', gu: 'Gujarati', hinglish: 'Hinglish' }[f.language] || f.language) + '</span>');
      if (f.best_hour_label) chips.push('<span class="wsg-fl pos">Usually writes ' + esc(f.best_wday_label ? f.best_wday_label + ' ' : '') + 'around ' + esc(f.best_hour_label) + '</span>');
      if (f.campaigns_got != null && f.campaigns_got > 0) chips.push('<span class="wsg-fl">Got ' + f.campaigns_got + ' campaigns, read ' + f.campaigns_read + ', replied ' + f.campaigns_replied + '</span>');
      if (chips.length) h += '<div class="wsg-flags">' + chips.join('') + '</div>';
      h += stripHtml(d);
      h += '<div class="wsg-l">Journey <small>newest first</small></div>';
      const tl = d.timeline || [];
      if (!tl.length) h += '<div class="wsg-empty">No messages, orders or campaigns recorded for this number yet.</div>';
      else {
        h += '<ol class="wsg-tl">';
        tl.forEach((x, i) => {
          const nxt = tl[i + 1];
          h += '<li class="' + esc(x.type) + '"><time>' + esc(dlong(x.at)) + '</time><p>' + esc(x.text) + '</p></li>';
          if (nxt && parse(x.at) && parse(nxt.at)) { const gd = Math.round((parse(x.at) - parse(nxt.at)) / 86400000); if (gd >= 45) h += '<li class="gap"><p>Silent for ' + esc(gapText(gd)) + ' (' + esc(dshort(nxt.at)) + ' to ' + esc(dshort(x.at)) + ')</p></li>'; }
        });
        h += '</ol>';
        if (tl.length >= 40) h += '<div class="wsg-sub">Showing the latest 40 events.</div>';
      }
      h += '</div>'; root.innerHTML = h;
    }
    async function load() {
      if (dead) return; const my = ++seq; if (!phone) { root.innerHTML = ''; return; } skel();
      try { const d = await api.get('wa.seg_customer', { phone }); if (dead || my !== seq) return; render(d); }
      catch (e) { if (dead || my !== seq) return; root.innerHTML = '<div class="wsg-card"><div class="wsg-err">Could not load the journey. ' + esc(errMsg(e)) + ' <button type="button" data-r>Try again</button></div></div>'; const b = root.querySelector('[data-r]'); if (b) b.onclick = load; }
    }
    load();
    return { refresh: load, destroy() { dead = true; container.innerHTML = ''; } };
  }

  /* ------------------------------------------------------------------ audience */
  const STAGES_ORDER = ['new', 'woke_up', 'warm', 'active', 'cooling', 'sleeping', 'never_replied', 'imported_unknown'];
  const VALUES_ORDER = ['vip', 'high', 'mid', 'low', 'not_bought'];
  const LANGS = [['en', 'English'], ['hi', 'Hindi'], ['gu', 'Gujarati'], ['hinglish', 'Hinglish'], ['unknown', 'Unknown']];
  const DATE_KEYS = ['first_on_from', 'first_on_to', 'last_in_on_from', 'last_in_on_to'];
  const KEYS = ['stage', 'value', 'source', 'language', 'first_number', 'first_on_from', 'first_on_to', 'last_in_on_from', 'last_in_on_to', 'silent_min_days', 'returned_within_days', 'asked_days', 'order_count_min', 'order_count_max', 'spent_min', 'exclude_bought_days', 'exclude_messaged_days', 'exclude_complaint'];
  const REASON = { optout: 'opted out', complaint: 'open complaint', bought: 'bought lately', messaged: 'messaged lately' };
  let metaCache = null, metaAt = 0;
  async function meta() { if (metaCache && Date.now() - metaAt < 60000) return metaCache; metaCache = await api.get('wa.seg_stages'); metaAt = Date.now(); return metaCache; }

  function audience(container, opts) {
    css(); opts = opts || {}; let dead = false, cSeq = 0, hSeq = 0, tC = 0, tH = 0, M = null, H = null, last = null, hKey = '', err = '', more = false;
    const F = {}; const sel = { first: [], last: [] };   // month picks (YYYY-MM): [] none, [a] one month, [a,b] range
    container.classList.add('wsg'); const root = document.createElement('div'); container.innerHTML = ''; container.appendChild(root);
    const has = (k) => F[k] != null && !(Array.isArray(F[k]) && !F[k].length);
    const arr = (k) => (Array.isArray(F[k]) ? F[k] : []);
    const num = (k) => (F[k] == null ? '' : F[k]);
    function set(obj) {
      KEYS.forEach((k) => delete F[k]); sel.first = []; sel.last = [];
      Object.keys(obj || {}).forEach((k) => { const v = obj[k]; if (KEYS.indexOf(k) >= 0 && v != null && v !== '' && !(Array.isArray(v) && !v.length)) F[k] = Array.isArray(v) ? v.slice() : v; });
    }
    const filters = () => { const o = {}; KEYS.forEach((k) => { if (has(k)) o[k] = Array.isArray(F[k]) ? F[k].slice() : F[k]; }); return o; };
    const nonDateKey = () => { const o = filters(); DATE_KEYS.forEach((k) => delete o[k]); return JSON.stringify(o); };
    function toggle(k, v) { const a = arr(k).slice(), i = a.indexOf(v); if (i >= 0) a.splice(i, 1); else a.push(v); if (a.length) F[k] = a; else delete F[k]; }
    function setNum(k, v) { if (v === '' || v == null || isNaN(+v)) delete F[k]; else F[k] = Math.max(0, +v); }
    function clearDates(which) { const [a, b] = which === 'first' ? ['first_on_from', 'first_on_to'] : ['last_in_on_from', 'last_in_on_to']; delete F[a]; delete F[b]; sel[which] = []; }
    function setRange(which, from, to) { const [a, b] = which === 'first' ? ['first_on_from', 'first_on_to'] : ['last_in_on_from', 'last_in_on_to']; if (from) F[a] = from; else delete F[a]; if (to) F[b] = to; else delete F[b]; sel[which] = []; }
    function pickMonth(which, m) {
      const s = sel[which]; if (s.length === 1 && s[0] !== m) s.push(m); else if (s.length === 1 && s[0] === m) { clearDates(which); return; } else { sel[which] = [m]; }
      const mm = sel[which].slice().sort(); const [a, b] = which === 'first' ? ['first_on_from', 'first_on_to'] : ['last_in_on_from', 'last_in_on_to'];
      F[a] = mm[0] + '-01'; F[b] = monthEnd(mm[mm.length - 1]); if (sel[which].length === 2) sel[which] = mm;
    }
    const presets = {
      first: [['Any time', null], ['Last 30 days', () => [daysAgoDate(30), null]], ['Last 3 months', () => [daysAgoDate(90), null]], ['This year', () => [new Date().getFullYear() + '-01-01', null]], ['Before this year', () => [null, (new Date().getFullYear() - 1) + '-12-31']]],
      last: [['Any time', null], ['Last 30 days', () => [daysAgoDate(30), null]], ['1 to 3 months ago', () => [daysAgoDate(90), daysAgoDate(30)]], ['3 to 10 months ago', () => [daysAgoDate(300), daysAgoDate(90)]], ['Over 10 months ago', () => [null, daysAgoDate(300)]]],
    };
    function presetOn(which, i) { const [a, b] = which === 'first' ? ['first_on_from', 'first_on_to'] : ['last_in_on_from', 'last_in_on_to']; if (i === 0) return !F[a] && !F[b]; if (sel[which].length) return false; const p = presets[which][i][1](); return (p[0] || undefined) === F[a] && (p[1] || undefined) === F[b]; }
    const chip = (on, label, n, attr) => '<button type="button" class="wsg-c' + (on ? ' on' : '') + '" ' + attr + ' aria-pressed="' + (on ? 'true' : 'false') + '">' + esc(label) + (n != null ? '<small>' + nf(n) + '</small>' : '') + '</button>';
    function barsHtml(which, rows) {
      if (!rows || !rows.length) return '<div class="wsg-empty">No dates to show.</div>';
      const mx = Math.max.apply(null, rows.map((r) => r.n)) || 1; const [a, b] = which === 'first' ? ['first_on_from', 'first_on_to'] : ['last_in_on_from', 'last_in_on_to'];
      const fa = F[a] ? F[a].slice(0, 7) : '', fb = F[b] ? F[b].slice(0, 7) : ''; const inSel = (m) => (fa || fb) && (!fa || m >= fa) && (!fb || m <= fb);
      let bars = '', lab = '';
      rows.forEach((r, i) => { const hp = Math.max(3, Math.round((r.n / mx) * 100)), imp = r.imported ? Math.round((r.imported / r.n) * 100) : 0; const mo = +r.m.slice(5);
        bars += '<button type="button" class="wsg-bar' + (inSel(r.m) ? ' on' : '') + '" data-bar="' + which + '|' + r.m + '" title="' + esc(monthLabel(r.m) + ': ' + nf(r.n)) + '" aria-label="' + esc(monthLabel(r.m) + ', ' + nf(r.n) + ' people') + '"><i style="height:' + hp + '%"></i></button>';
        void imp; lab += '<span>' + (rows.length <= 14 || i === 0 || mo === 1 || mo === 7 ? MON[mo - 1].charAt(0) + (mo === 1 || i === 0 ? '<br>' + r.m.slice(2, 4) : '') : '') + '</span>'; });
      return '<div class="wsg-bars">' + bars + '</div><div class="wsg-ml">' + lab + '</div>';
    }
    function rangeText(which, rows) {
      const [a, b] = which === 'first' ? ['first_on_from', 'first_on_to'] : ['last_in_on_from', 'last_in_on_to']; const verb = which === 'first' ? 'first wrote' : 'last wrote';
      if (!rows || !rows.length) return '';
      const fa = F[a] ? F[a].slice(0, 7) : '', fb = F[b] ? F[b].slice(0, 7) : ''; const tot = rows.reduce((s, r) => s + r.n, 0);
      if (!fa && !fb) return '<b>' + nf(tot) + '</b> people ' + verb + ' between ' + esc(monthLabel(rows[0].m)) + ' and ' + esc(monthLabel(rows[rows.length - 1].m)) + '.';
      const pick = rows.filter((r) => (!fa || r.m >= fa) && (!fb || r.m <= fb)).reduce((s, r) => s + r.n, 0);
      return 'Selected: <b>' + esc(F[a] ? dlong(F[a]) : 'the start') + ' to ' + esc(F[b] ? dlong(F[b]) : 'today') + '</b> · ' + nf(pick) + ' of ' + nf(tot) + ' ' + verb + ' then.';
    }
    function dateBlock(which, title, hint, rows) {
      const [a, b] = which === 'first' ? ['first_on_from', 'first_on_to'] : ['last_in_on_from', 'last_in_on_to'];
      let h = '<div class="wsg-l">' + esc(title) + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</div><div class="wsg-chips">';
      presets[which].forEach((p, i) => { h += chip(presetOn(which, i), p[0], null, 'data-pre="' + which + '|' + i + '"'); }); h += '</div>';
      h += barsHtml(which, rows); const rt = rangeText(which, rows); if (rt) h += '<div class="wsg-note">' + rt + '</div>';
      h += '<div class="wsg-two"><div><label for="wsg-' + a + '">From date</label><input id="wsg-' + a + '" class="wsg-in" type="date" data-date="' + a + '" value="' + esc(F[a] || '') + '"></div><div><label for="wsg-' + b + '">To date</label><input id="wsg-' + b + '" class="wsg-in" type="date" data-date="' + b + '" value="' + esc(F[b] || '') + '"></div></div>';
      return h;
    }
    function countHtml() {
      const c = last; let h = '';
      if (err) h += '<div class="wsg-err">' + esc(err) + ' <button type="button" data-retry>Try again</button></div>';
      h += '<div class="wsg-live' + (c ? '' : ' load') + '" aria-live="polite"><span class="big">' + (c ? nf(c.sendable) : '…') + '</span><span class="tx">people will get this<small>updates live as you tap</small></span></div>';
      if (c) {
        const why = c.excluded || {}; const parts = Object.keys(REASON).filter((k) => why[k] > 0).map((k) => nf(why[k]) + ' ' + REASON[k]);
        const text = (c.adds_up && c.adds_up.text) || ('Total ' + nf(c.total) + ' = sending ' + nf(c.sendable) + ' + skipped ' + nf(c.skipped) + (parts.length ? ' (' + parts.join(', ') + ')' : ''));
        h += '<div class="wsg-add' + (c.adds_up && c.adds_up.ok === false ? ' bad' : '') + '"><b>' + esc(text) + '</b>';
        const tot = Math.max(1, c.total); h += '<div class="wsg-stack"><i style="width:' + (c.sendable / tot) * 100 + '%;background:#1fa855"></i><i style="width:' + ((why.bought || 0) + (why.messaged || 0)) / tot * 100 + '%;background:#f59e0b"></i><i style="width:' + ((why.optout || 0) + (why.complaint || 0)) / tot * 100 + '%;background:#e5484d"></i></div>';
        h += '<div class="wsg-leg"><span style="--c:#1fa855">Sending ' + nf(c.sendable) + '</span><span style="--c:#f59e0b">Bought / messaged lately ' + nf((why.bought || 0) + (why.messaged || 0)) + '</span><span style="--c:#e5484d">Opted out / complaint ' + nf((why.optout || 0) + (why.complaint || 0)) + '</span></div>';
        if (c.unbuilt) h += '<div class="wsg-sub">' + nf(c.unbuilt) + ' newer contacts are not analysed yet (done in the nightly run).</div>';
        h += '</div>';
      }
      return h;
    }
    function render() {
      if (dead) return; const st = (M && M.stages) || [], va = (M && M.values) || []; const byKey = (a) => a.reduce((o, x) => (o[x.key] = x, o), {});
      const S = byKey(st), V = byKey(va); let h = '<div class="wsg-card"><div class="wsg-h">Who</div><div class="wsg-l" style="margin-top:2px">Stage <small>pick one or more</small></div><div class="wsg-chips">';
      STAGES_ORDER.forEach((k) => { if (S[k]) h += chip(arr('stage').indexOf(k) >= 0, S[k].label, S[k].n, 'data-tg="stage|' + k + '" title="' + esc(S[k].help) + '"'); }); h += '</div>';
      h += '<div class="wsg-l">Value <small>by money spent</small></div><div class="wsg-chips">';
      VALUES_ORDER.forEach((k) => { if (V[k]) h += chip(arr('value').indexOf(k) >= 0, V[k].label, V[k].n, 'data-tg="value|' + k + '" title="' + esc(V[k].help) + '"'); }); h += '</div>';
      const cu = M && M.cutoffs; if (cu && cu.vip) h += '<div class="wsg-sub">VIP = spent ' + esc(inr(cu.vip)) + ' or more · High from ' + esc(inr(cu.high)) + ' · Mid from ' + esc(inr(cu.mid)) + ' (from your own buyers).</div>';
      h += '<button type="button" class="wsg-more" data-more>' + (more ? 'Fewer options' : 'More: where they came from, language, number') + '</button>';
      if (more) {
        const srcs = (M && M.sources) || {}; h += '<div class="wsg-l" style="margin-top:2px">Came from</div><div class="wsg-chips">'; Object.keys(srcs).forEach((k) => { h += chip(arr('source').indexOf(k) >= 0, srcs[k], null, 'data-tg="source|' + k + '"'); }); h += '</div>';
        const lg = (M && M.language) || {}; h += '<div class="wsg-l">Language they write in</div><div class="wsg-chips">'; LANGS.forEach(([k, l]) => { if (lg[k] || arr('language').indexOf(k) >= 0) h += chip(arr('language').indexOf(k) >= 0, l, lg[k] || 0, 'data-tg="language|' + k + '"'); }); h += '</div>';
        const nums = (M && M.numbers) || []; if (nums.length > 1) { h += '<div class="wsg-l">First wrote on number</div><div class="wsg-chips">'; nums.forEach((n) => { h += chip(arr('first_number').indexOf(n.id) >= 0, n.label, null, 'data-tgn="first_number|' + n.id + '"'); }); h += '</div>'; }
      }
      h += '</div><div class="wsg-card"><div class="wsg-h">Dates</div>';
      h += dateBlock('first', 'First wrote', '', H && H.first); h += dateBlock('last', 'Last wrote', 'their last message to us', H && H.last);
      h += '<div class="wsg-l">Special</div><div class="wsg-chips">';
      h += chip(F.silent_min_days != null && F.returned_within_days != null, 'Woke up: back after a long silence', S.woke_up ? S.woke_up.n : null, 'data-woke');
      [7, 30, 90].forEach((d) => { h += chip(F.asked_days === d, 'Asked about a perfume in ' + d + ' days', null, 'data-ask="' + d + '"'); }); h += '</div>';
      h += '<div class="wsg-l">Orders</div><div class="wsg-chips">' + chip(F.order_count_min === 1 && F.order_count_max == null, 'Has bought', null, 'data-ord="bought"') + chip(F.order_count_max === 0 && F.order_count_min == null, 'Never bought', null, 'data-ord="never"') + chip(F.order_count_min === 2, 'Bought 2+ times', null, 'data-ord="repeat"') + '</div>';
      h += '<div class="wsg-two"><div><label for="wsg-sp">Spent at least (₹)</label><input id="wsg-sp" class="wsg-in" type="number" inputmode="numeric" min="0" data-num="spent_min" value="' + esc(num('spent_min')) + '" placeholder="Any"></div><div></div></div>';
      h += '</div><div class="wsg-card"><div class="wsg-h">Skip these people</div><div class="wsg-sub">Opted-out numbers are always skipped.</div>';
      h += '<label class="wsg-sw"><span>Bought in the last 14 days<small>do not push a sale right after an order</small></span><input type="checkbox" data-ex="exclude_bought_days|14"' + (F.exclude_bought_days === 14 ? ' checked' : '') + '></label>';
      h += '<label class="wsg-sw"><span>Messaged this week<small>they already heard from us in the last 7 days</small></span><input type="checkbox" data-ex="exclude_messaged_days|7"' + (F.exclude_messaged_days === 7 ? ' checked' : '') + '></label>';
      h += '<label class="wsg-sw"><span>Has an open complaint<small>sort the problem first</small></span><input type="checkbox" data-ex="exclude_complaint|1"' + (F.exclude_complaint ? ' checked' : '') + '></label>';
      h += '</div><div data-count>' + countHtml() + '</div>';
      if (Object.keys(filters()).length) h += '<button type="button" class="wsg-reset" data-reset>Clear everything</button>';
      const keepScroll = root.scrollTop; root.innerHTML = h; root.scrollTop = keepScroll;
    }
    function paintCount() { const el = root.querySelector('[data-count]'); if (el) el.innerHTML = countHtml(); }
    function emit() { try { if (typeof opts.onChange === 'function') opts.onChange(filters(), last); } catch (e) { /* the host's problem */ } }
    async function loadCount() {
      const my = ++cSeq; err = ''; try { const r = await api.post('wa.seg_count', { filters: filters() }); if (dead || my !== cSeq) return; last = r; } catch (e) { if (dead || my !== cSeq) return; err = 'Could not count. ' + errMsg(e); last = null; }
      paintCount(); emit();
    }
    async function loadHist(force) {
      const k = nonDateKey(); if (!force && k === hKey && H) return; hKey = k; const my = ++hSeq; const f = filters(); DATE_KEYS.forEach((x) => delete f[x]);
      try { const r = await api.post('wa.seg_hist', { filters: f }); if (dead || my !== hSeq) return; H = r; } catch (e) { if (dead || my !== hSeq) return; }
      render();
    }
    function changed(full) { last = null; clearTimeout(tC); clearTimeout(tH); if (full) render(); else paintCount(); tC = setTimeout(loadCount, 250); tH = setTimeout(() => loadHist(false), 500); }
    root.addEventListener('click', (e) => {
      const t = e.target.closest('button,[data-tg],[data-bar]'); if (!t) return;
      if (t.hasAttribute('data-tg')) { const [k, v] = t.getAttribute('data-tg').split('|'); toggle(k, v); return changed(true); }
      if (t.hasAttribute('data-tgn')) { const [k, v] = t.getAttribute('data-tgn').split('|'); toggle(k, +v); return changed(true); }
      if (t.hasAttribute('data-pre')) { const [w, i] = t.getAttribute('data-pre').split('|'); const p = presets[w][+i][1]; if (!p) clearDates(w); else { const r = p(); setRange(w, r[0], r[1]); } return changed(true); }
      if (t.hasAttribute('data-bar')) { const [w, m] = t.getAttribute('data-bar').split('|'); pickMonth(w, m); return changed(true); }
      if (t.hasAttribute('data-woke')) { if (F.silent_min_days != null) { delete F.silent_min_days; delete F.returned_within_days; } else { F.silent_min_days = (M && M.config && M.config.wake_days) || 60; F.returned_within_days = (M && M.config && M.config.cool_days) || 30; } return changed(true); }
      if (t.hasAttribute('data-ask')) { const d = +t.getAttribute('data-ask'); if (F.asked_days === d) delete F.asked_days; else F.asked_days = d; return changed(true); }
      if (t.hasAttribute('data-ord')) { const o = t.getAttribute('data-ord'); const was = (o === 'bought' && F.order_count_min === 1 && F.order_count_max == null) || (o === 'never' && F.order_count_max === 0 && F.order_count_min == null) || (o === 'repeat' && F.order_count_min === 2);
        delete F.order_count_min; delete F.order_count_max; if (!was) { if (o === 'bought') F.order_count_min = 1; else if (o === 'never') F.order_count_max = 0; else F.order_count_min = 2; } return changed(true); }
      if (t.hasAttribute('data-more')) { more = !more; return render(); }
      if (t.hasAttribute('data-reset')) { set({}); return changed(true); }
      if (t.hasAttribute('data-retry')) { last = null; paintCount(); return loadCount(); }
    });
    root.addEventListener('change', (e) => {
      const t = e.target;
      if (t.hasAttribute('data-ex')) { const [k, v] = t.getAttribute('data-ex').split('|'); if (t.checked) F[k] = +v; else delete F[k]; return changed(false); }
      if (t.hasAttribute('data-date')) { const k = t.getAttribute('data-date'); if (t.value) F[k] = t.value; else delete F[k]; sel[k.indexOf('first') === 0 ? 'first' : 'last'] = []; return changed(true); }
      if (t.hasAttribute('data-num')) { setNum(t.getAttribute('data-num'), t.value); return changed(true); }
    });
    set(opts.filters); render();
    meta().then((m) => { if (dead) return; M = m; render(); loadHist(true); loadCount(); }).catch((e) => { if (dead) return; err = 'Could not load the audience options. ' + errMsg(e); render(); });
    return {
      getFilters: filters,
      setFilters(o) { set(o); changed(true); },
      refresh() { metaCache = null; meta().then((m) => { M = m; render(); loadHist(true); loadCount(); }).catch(() => {}); },
      destroy() { dead = true; clearTimeout(tC); clearTimeout(tH); container.innerHTML = ''; },
    };
  }
  function open(opts) {
    css(); opts = opts || {}; const ov = document.createElement('div'); ov.className = 'wsg wsg-ov'; ov.innerHTML = '<div class="wsg-sh" role="dialog" aria-modal="true"><div class="wsg-shh"><b></b><button type="button" class="wsg-x" aria-label="Close">&times;</button></div><div class="wsg-shb"></div></div>';
    ov.querySelector('b').textContent = opts.name || 'Customer journey'; document.body.appendChild(ov);
    const j = journey(ov.querySelector('.wsg-shb'), { phone: opts.phone }); const close = () => { j.destroy(); ov.remove(); document.removeEventListener('keydown', esck); };
    const esck = (e) => { if (e.key === 'Escape') close(); }; document.addEventListener('keydown', esck);
    ov.addEventListener('click', (e) => { if (e.target === ov || e.target.closest('.wsg-x')) close(); });
    return { close };
  }
  return { journey, audience, open };
}());
