/**
 * Chat / call proof — shared by Order Allocation, My Sales and the Orders page.
 *   Convo.open({ kind: 'order', source, order_id, order_name, total, day, id?, admin?, onChange? })   // upload + review sheet
 *   Convo.open({ kind: 'lost', id?, onChange? })                                                     // a chat that did not become an order
 *   Convo.forOrders(keys) -> { 'source|id': {id, status, score, ...} }                               // row chips
 *   Convo.chip(entry, o) -> small button html for a row (entry from forOrders, or undefined)
 * Backend: convo.upload / convo.analyze / convo.get / convo.file / convo.remove_file / convo.flag (api.php).
 * Rules: 3–10 screenshots (or 1 screen-recording video) from the customer's first message to the order confirmation; the same
 * screenshot twice (also re-saved / slightly cropped) is refused here at once and again on the server; the call recording is optional.
 */
(function () {
  'use strict';
  const C = window.Convo = {};
  let CFG = { min_shots: 3, max_shots: 10, shot_mb: 8, media_mb: 12, video_ok: false, call_ok: false, video_sec: 90, keep_days: 60 };
  C.setCfg = (c) => { if (c && typeof c === 'object') CFG = Object.assign(CFG, c); };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const inr = (n) => '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN');
  const DUP_BITS = 32;          // the browser's resample is rougher than the server's (server: 18): repeats measure ~5–20 here, different screenshots 60+

  // ---------- styles ----------
  const css = `
.cvx{position:fixed; inset:0; z-index:10050; background:rgba(15,23,42,.55); display:flex; align-items:flex-start; justify-content:center; padding:24px 12px; overflow:auto; font-family:var(--font, system-ui)}
.cvx-box{background:var(--surface,#fff); color:var(--ink,#14161a); width:100%; max-width:520px; border-radius:22px; box-shadow:0 24px 60px rgba(0,0,0,.3); padding:18px 18px 16px; position:relative}
.cvx-x{position:absolute; right:12px; top:10px; border:0; background:transparent; font-size:22px; cursor:pointer; color:var(--muted,#6f7480); width:40px; height:40px; border-radius:50%}
.cvx-x:hover{background:rgba(0,0,0,.05)}
.cvx h3{margin:0 40px 2px 0; font-size:18px}
.cvx .mut{color:var(--muted,#6f7480); font-size:12.5px}
.cvx-grid{display:grid; grid-template-columns:repeat(5,1fr); gap:8px; margin:12px 0}
.cvx-sh{position:relative; aspect-ratio:9/16; border-radius:10px; border:2px solid #22c55e; overflow:hidden; background:#f1f5f9}
.cvx-sh img{width:100%; height:100%; object-fit:cover; display:block; cursor:zoom-in}
.cvx-sh b{position:absolute; left:4px; bottom:4px; background:#16a34a; color:#fff; font-size:11px; border-radius:6px; padding:1px 5px; pointer-events:none}
.cvx-sh.dup{border-color:#f59e0b}.cvx-sh.dup b{background:#d97706}
.cvx-sh.new{border-style:dashed}
.cvx-sh .rm{position:absolute; right:3px; top:3px; width:24px; height:24px; border-radius:50%; border:0; background:rgba(15,23,42,.65); color:#fff; font-size:13px; cursor:pointer; line-height:24px; padding:0}
.cvx-add{aspect-ratio:9/16; border:2px dashed #cbd5e1; border-radius:10px; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#64748b; font-size:26px; cursor:pointer; background:#f8fafc}
.cvx-add small{font-size:11px; font-weight:700}
.cvx-warn{background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; border-radius:12px; padding:9px 11px; font-size:13px; margin:8px 0}
.cvx-ok{background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; border-radius:12px; padding:10px 12px; font-size:13.5px; font-weight:700; margin:8px 0}
.cvx-chk{font-size:13px; margin:4px 0}
.cvx-or{display:flex; align-items:center; gap:8px; color:#94a3b8; font-size:12px; margin:10px 0}.cvx-or:before,.cvx-or:after{content:""; flex:1; height:1px; background:var(--line,#e6e8ec)}
.cvx-opt{border:1px dashed #cbd5e1; border-radius:12px; padding:9px 11px; font-size:13px; margin-top:6px; display:flex; justify-content:space-between; align-items:center; gap:8px}
.cvx-opt.on{border-style:solid; border-color:#86efac; background:#f0fdf4}
.cvx-opt button, .cvx-lnk{border:0; background:transparent; color:var(--accent-dark,#e8651a); font-weight:800; cursor:pointer; font-size:13px; padding:4px 2px; white-space:nowrap}
.cvx-btn{display:block; width:100%; border:0; background:#16a34a; color:#fff; border-radius:12px; padding:12px; font-weight:800; font-size:15px; margin-top:12px; cursor:pointer}
.cvx-btn[disabled]{background:#cbd5e1; cursor:not-allowed}
.cvx-btn.ghost{background:transparent; color:var(--ink,#14161a); border:1px solid var(--line,#e6e8ec)}
.cvx textarea{width:100%; box-sizing:border-box; border:1px solid var(--line,#e6e8ec); border-radius:10px; padding:8px 10px; font:inherit; font-size:13.5px; margin-top:8px; min-height:54px; resize:vertical}
.cvx-tag{display:inline-block; font-size:11px; font-weight:700; border-radius:999px; padding:3px 9px; margin:2px 3px 2px 0; background:#ecfdf5; color:#065f46}
.cvx-tag.w{background:#fff7ed; color:#9a3412}
.cvx-score{display:flex; align-items:center; gap:12px; margin:10px 0}
.cvx-ring{width:64px; height:64px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex:none}
.cvx-ring span{background:var(--surface,#fff); width:50px; height:50px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:17px}
.cvx-sk{display:flex; justify-content:space-between; font-size:12.5px; margin-top:6px}
.cvx-bar{height:7px; border-radius:5px; background:#e2e8f0; margin:3px 0 2px; overflow:hidden}.cvx-bar i{display:block; height:100%; border-radius:5px}
.cvx-tip{border-radius:12px; padding:9px 11px; font-size:13px; margin:7px 0; line-height:1.45}
.cvx-tip.win{background:#ecfdf5; border:1px solid #bbf7d0}.cvx-tip.foc{background:#fff7ed; border:1px solid #fed7aa}
.cvx-tip .q{display:block; margin-top:4px; font-style:italic; color:#7c2d12}
.cvx-h{font-size:12px; font-weight:800; letter-spacing:.06em; color:var(--muted,#6f7480); margin:14px 0 4px; text-transform:uppercase}
.cvx-spin{display:inline-block; width:16px; height:16px; border:3px solid #bbf7d0; border-top-color:#16a34a; border-radius:50%; animation:cvxs 0.8s linear infinite; vertical-align:-3px; margin-right:6px}
@keyframes cvxs{to{transform:rotate(360deg)}}
.cvx-fi{width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; font-size:26px; background:#f8fafc}.cvx-fi small{font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase}
.cvx-meet{background:#fef2f2; border:1px solid #fecaca; color:#991b1b; border-radius:12px; padding:10px 12px; font-size:13.5px; font-weight:700; margin:8px 0}
.cvx-lb{position:fixed; inset:0; z-index:10060; background:rgba(0,0,0,.85); display:flex; align-items:center; justify-content:center; padding:16px}
.cvx-lb img{max-width:100%; max-height:100%; border-radius:8px}
.cvx-files{display:flex; gap:6px; flex-wrap:wrap; margin-top:6px}
.cvx-files .cvx-sh{width:54px; border-width:1px; border-color:var(--line,#e6e8ec)}
.cvx audio, .cvx video{width:100%; margin-top:6px}
.cvchip{display:inline-flex; align-items:center; gap:4px; border:1px solid var(--line,#e6e8ec); background:#fff; border-radius:999px; padding:4px 10px; font-size:12px; font-weight:800; cursor:pointer; white-space:nowrap; color:var(--ink,#14161a); margin-top:4px}
.cvchip.none{color:#64748b; border-style:dashed}
.cvchip.ok{background:#ecfdf5; border-color:#a7f3d0; color:#065f46}
.cvchip.mid{background:#fff7ed; border-color:#fed7aa; color:#9a3412}
.cvchip.run{background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8}
@media (max-width:640px){ .cvx-box{zoom:.8} }
@media (max-width:520px){ .cvx{padding:10px 6px} .cvx-box{margin-top:auto; border-radius:20px 20px 14px 14px; padding:16px 14px} .cvx-grid{grid-template-columns:repeat(4,1fr)} }
`;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  // ---------- small helpers ----------
  // The employee bottom menu (My Day / My Sales / Orders / Chats, #wsEmpNav) is fixed on top of the page - and when this page sits inside
  // another page (Orders > Allocate order), that menu belongs to the OUTER page and covers the bottom of the pop-up, Save button included.
  // navGap() = how many px of this window's bottom edge the menu covers; the pop-up keeps that much free space under it.
  function navGap() {
    try {
      const inFrame = window.parent !== window, doc = inFrame ? window.parent.document : document;
      const nav = doc.getElementById('wsEmpNav'); if (!nav || doc.defaultView.getComputedStyle(nav).display === 'none') return 0;
      const nr = nav.getBoundingClientRect();
      if (!inFrame) return Math.max(0, Math.round(window.innerHeight - nr.top));
      return Math.max(0, Math.round(window.frameElement.getBoundingClientRect().bottom - nr.top));
    } catch (e) { return 0; }
  }
  const keepAboveNav = (root) => { const g = navGap(); if (g > 0) root.style.paddingBottom = (g + 12) + 'px'; };
  const BLOBS = new Map();
  async function fileUrl(id, n) {
    const k = id + ':' + n;
    if (BLOBS.has(k)) return BLOBS.get(k);
    const r = await fetch(api.buildUrl('convo.file', { id, n }), { headers: { Authorization: 'Bearer ' + api.token() } });
    if (!r.ok) throw new Error('gone');
    const u = URL.createObjectURL(await r.blob()); BLOBS.set(k, u); return u;
  }
  // Same 256-bit difference hash as the server (middle of the screenshot, header and keyboard cut off).
  async function dhash(blob) {
    const bmp = await createImageBitmap(blob);
    // shrink in halves (area average, like the server's resample) so thin chat bubbles are not skipped
    const y0 = Math.round(bmp.height * 0.12), ch = Math.max(1, Math.round(bmp.height * 0.76));
    let w = bmp.width, h = ch, src = bmp, sx = 0, sy = y0;
    while (w > 34 || h > 32) {
      const nw = Math.max(17, Math.ceil(w / 2)), nh = Math.max(16, Math.ceil(h / 2));
      const c2 = document.createElement('canvas'); c2.width = nw; c2.height = nh;
      const x2 = c2.getContext('2d'); x2.imageSmoothingQuality = 'high'; x2.drawImage(src, sx, sy, w, h, 0, 0, nw, nh);
      src = c2; sx = 0; sy = 0; w = nw; h = nh;
    }
    const cv = document.createElement('canvas'); cv.width = 17; cv.height = 16;
    const x = cv.getContext('2d'); x.imageSmoothingQuality = 'high'; x.drawImage(src, sx, sy, w, h, 0, 0, 17, 16);
    const d = x.getImageData(0, 0, 17, 16).data; let bits = '';
    for (let r = 0; r < 16; r++) for (let c = 1; c < 17; c++) {
      const g = (i) => d[i] * 0.299 + d[i + 1] * 0.587 + d[i + 2] * 0.114;
      bits += g((r * 17 + c) * 4) > g((r * 17 + c - 1) * 4) ? '1' : '0';
    }
    bmp.close && bmp.close();
    return bits;
  }
  const ham = (a, b) => { if (!a || !b || a.length !== b.length) return 999; let n = 0; for (let i = 0; i < a.length; i++) if (a[i] !== b[i]) n++; return n; };
  async function shrink(file) {
    try {
      const bmp = await createImageBitmap(file); const sc = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
      const cv = document.createElement('canvas'); cv.width = Math.round(bmp.width * sc); cv.height = Math.round(bmp.height * sc);
      const x = cv.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, cv.width, cv.height); x.drawImage(bmp, 0, 0, cv.width, cv.height);
      const b = await new Promise((res) => cv.toBlob(res, 'image/jpeg', 0.88));
      return b && b.size < file.size ? b : file;
    } catch (e) { return file; }
  }
  function mediaSeconds(file, kind) {
    return new Promise((res) => {
      const m = document.createElement(kind); const u = URL.createObjectURL(file);
      m.preload = 'metadata'; m.onloadedmetadata = () => { res(m.duration); URL.revokeObjectURL(u); }; m.onerror = () => { res(null); URL.revokeObjectURL(u); };
      m.src = u; setTimeout(() => res(null), 4000);
    });
  }
  const scoreColor = (s) => s >= 8 ? '#16a34a' : s >= 6.5 ? '#22c55e' : '#f59e0b';     // never red: a low score is a "focus", not a failure
  const scoreWord = (s) => s >= 8 ? 'Great conversation 🌟' : s >= 6.5 ? 'Good conversation 👏' : s >= 5 ? 'Good base — small fixes 💪' : 'Let\'s improve together 💪';
  const SKILL = { reply_speed: '⚡ Reply speed', greeting: '🙏 Greeting', need_discovery: '🙋 Need understanding', suggestion: '🧴 Suggestion', upsell: '➕ 10 ml / combo offer',
    objection: '💬 Price objection', prepaid: '💳 Prepaid ask', closing: '✅ Closing', tone: '😊 Tone', listening: '👂 Listening' };
  C.ring = (s, size) => `<div class="cvx-ring" style="background:conic-gradient(${scoreColor(s)} ${Math.max(0, Math.min(100, s * 10))}%, #e2e8f0 0);${size ? `width:${size}px;height:${size}px;` : ''}"><span${size ? ` style="width:${size - 14}px;height:${size - 14}px"` : ''}>${s == null ? '—' : Number(s).toFixed(1)}</span></div>`;

  // ---------- row chip ----------
  C.chip = function (e) {
    if (!e) return `<button type="button" class="cvchip none" data-cv="1" title="WhatsApp chat screenshots / call recording">📎 Chat proof</button>`;
    const s = e.status;
    if (s === 'done') return `<button type="button" class="cvchip ok" data-cv="1" title="AI chat review">${e.score != null ? '💬 ' + Number(e.score).toFixed(1) : ''}${e.score != null && e.call_score != null ? ' · ' : ''}${e.call_score != null ? '📞 ' + Number(e.call_score).toFixed(1) : ''}${e.score == null && e.call_score == null ? '✓ saved' : ''}</button>`;
    if (s === 'need_more') return `<button type="button" class="cvchip mid" data-cv="1">📎 ${e.shots}/${CFG.min_shots} · add more</button>`;
    if (s === 'saved' || s === 'analyzing') return `<button type="button" class="cvchip run" data-cv="1">⏳ AI review…</button>`;
    if (s === 'incomplete') return `<button type="button" class="cvchip mid" data-cv="1">⚠ Add screenshots</button>`;
    return `<button type="button" class="cvchip mid" data-cv="1">↻ Retry review</button>`;
  };
  C.forOrders = async function (keys) {
    if (!keys.length) return {};
    const d = await api.post('convo.for_orders', { keys });
    C.setCfg(d.cfg);
    return d.map || {};
  };

  // ---------- the sheet ----------
  let S = null;            // state of the open sheet
  function close() {
    if (!S) return;
    const done = S.opts.onChange; const up = S.up;
    S.picks.forEach((p) => URL.revokeObjectURL(p.url));
    S.root.remove(); S = null;
    document.removeEventListener('keydown', onKey);
    if (done) try { done(up); } catch (e) {}
  }
  const onKey = (e) => { if (e.key === 'Escape') { const lb = document.querySelector('.cvx-lb'); if (lb) lb.remove(); else close(); } };
  C.open = async function (opts) {
    if (S) close();
    S = { opts: Object.assign({ kind: 'order' }, opts || {}), up: null, picks: [], video: null, call: null, rejected: [], view: 'upload', busy: false, note: '', err: '' };
    S.root = document.createElement('div'); S.root.className = 'cvx';
    S.root.innerHTML = '<div class="cvx-box"><button type="button" class="cvx-x" aria-label="Close">×</button><div class="cvx-body"><p class="mut"><span class="cvx-spin"></span>Loading…</p></div></div>';
    document.body.appendChild(S.root); keepAboveNav(S.root);
    S.root.addEventListener('click', onClick); S.root.addEventListener('change', onChange);
    document.addEventListener('keydown', onKey);
    if (S.opts.id) await reload(); else draw();
  };
  async function reload() {
    try {
      const d = await api.get('convo.get', { id: S.opts.id || S.up.id });
      if (!S) return;
      C.setCfg(d.cfg); S.up = d.upload; S.opts.id = d.upload.id;
      S.view = ['done'].includes(S.up.status) || (['incomplete', 'error', 'waiting', 'analyzing', 'saved'].includes(S.up.status)) ? 'review' : 'upload';
      if (S.up.status === 'need_more') S.view = 'upload';
    } catch (e) { if (S) S.err = e.message; }
    draw();
    if (S && S.up && S.up.status === 'saved' && !S.kick) { S.kick = true; analyze(false); return; }     // saved but the review never started (page closed early): start it now
    if (S && S.up && S.up.status === 'analyzing') setTimeout(() => { if (S && S.up && S.up.status === 'analyzing') reload(); }, 4000);
  }
  const shotsOf = (up) => (up && up.files ? up.files.filter((f) => f.kind === 'shot') : []);
  const goodPicks = () => S.picks.filter((p) => !p.dup);
  function counts() {
    const have = S.up ? S.up.shots : 0, add = goodPicks().length;
    const hasVideo = !!S.video || !!(S.up && S.up.has_video);
    return { have, add, total: have + add, hasVideo, need: hasVideo ? 0 : Math.max(0, CFG.min_shots - have - add) };
  }

  function draw() {
    if (!S) return;
    const b = S.root.querySelector('.cvx-body');
    b.innerHTML = S.view === 'review' && S.up ? reviewView() : uploadView();
    // existing screenshots: load the pictures
    b.querySelectorAll('img[data-n]').forEach(async (im) => { try { im.src = await fileUrl(S.up.id, im.dataset.n); } catch (e) { im.alt = 'deleted'; } });
    b.querySelectorAll('[data-media]').forEach(async (m) => { try { m.src = await fileUrl(S.up.id, m.dataset.media); } catch (e) { m.replaceWith(Object.assign(document.createElement('span'), { className: 'mut', textContent: 'File deleted (kept ' + CFG.keep_days + ' days).' })); } });
  }
  function titleHtml() {
    const o = S.opts, up = S.up || {};
    if (up.kind === 'qcall' && !up.order_name) return `<h3>📞 Query call</h3><div class="mut">AI order સાથે match કરી રહ્યું છે…</div>`;
    if (up.kind === 'lost' && up.has_call && !up.shots && !up.has_video) return `<h3>📞 Call — no order</h3><div class="mut">આ call માંથી order ના આવ્યો — AI કહે છે શું અલગ કરી શકાય (study માટે).</div>`;
    if ((up.kind || o.kind) === 'lost') return `<h3>💔 Lost chat</h3><div class="mut">Customer એ પૂછ્યું પણ order ના આવ્યો — AI કહેશે કે શું અલગ કરી શકાય.</div>`;
    return `<h3>📎 WhatsApp chat proof${(up.order_name || o.order_name) ? ' · ' + esc(up.order_name || o.order_name) : ''}</h3>`
      + `<div class="mut">Customer ના પહેલા message થી order confirm સુધીના બધા screenshots · min ${CFG.min_shots} · max ${CFG.max_shots}${(up.order_total || o.total) ? ' · ' + inr(up.order_total || o.total) : ''}</div>`;
  }
  function uploadView() {
    const c = counts(), up = S.up || {};
    const ex = shotsOf(up), canEdit = !up.status || !['analyzing'].includes(up.status);
    let k = 0;
    const exHtml = ex.map((f) => { k++; return `<div class="cvx-sh"><img data-n="${f.n}" alt="Screenshot ${k}" data-zoom="1"><b>${k} ✓</b>${canEdit ? `<button type="button" class="rm" data-rmf="${f.n}" title="Remove">✕</button>` : ''}</div>`; }).join('');
    const pkHtml = S.picks.map((p, i) => { if (!p.dup) k++; return `<div class="cvx-sh new ${p.dup ? 'dup' : ''}">${p.raw ? `<div class="cvx-fi">📄<small>${esc((p.name.split('.').pop() || 'file').slice(0, 5))}</small></div>` : `<img src="${p.url}" alt="" data-zoom="1">`}<b>${p.dup ? 'Same as ' + p.dup : k + ' ✓'}</b><button type="button" class="rm" data-rmp="${i}" title="Remove">✕</button></div>`; }).join('');
    const full = c.total >= CFG.max_shots;
    const dups = S.picks.filter((p) => p.dup);
    const isLost = (up.kind || S.opts.kind) === 'lost';
    const saveLbl = S.busy ? '<span class="cvx-spin"></span>Saving…' : c.need > 0 ? `Save (need ${c.need} more screenshot${c.need === 1 ? '' : 's'})` : (c.add || S.video || S.call ? 'Save & get AI review ✓' : 'Get AI review ✓');
    const canSave = !S.busy && (c.need === 0 ? (c.add > 0 || !!S.video || !!S.call || ['saved', 'error', 'waiting'].includes(up.status)) : (c.add > 0 || !!S.call));
    return titleHtml()
      + (isLost ? `<textarea id="cvxNote" maxlength="300" placeholder="Customer શું પૂછતા હતા? (optional) — e.g. Oud 100 ml price પૂછ્યો">${esc(S.note || up.note || '')}</textarea>` : '')
      + `<div class="cvx-grid">${exHtml}${pkHtml}${full ? '' : `<label class="cvx-add">＋<small>Screenshots</small><input type="file" multiple hidden id="cvxShots"></label>`}</div>`
      + dups.map((p) => `<div class="cvx-warn">⚠️ આ screenshot, screenshot ${p.dup} જેવો જ છે — repeat નહીં ચાલે. ✕ થી કાઢો અને બાકીની chat upload કરો.</div>`).join('')
      + S.rejected.map((r) => `<div class="cvx-warn">⚠️ ${esc(r.name)}: ${esc(r.why)}</div>`).join('')
      + (S.notes || []).map((r) => `<div class="cvx-warn">ℹ️ ${esc(r.name)}: ${esc(r.why)}</div>`).join('')
      + dupHtml(S.dupNote)
      + (up.msg && up.status !== 'done' ? `<div class="cvx-warn">🤖 ${esc(up.msg)}</div>` : '')
      + (S.err ? `<div class="cvx-warn">${esc(S.err)}</div>` : '')
      + `<div class="cvx-chk">${c.total ? '✅' : '⬜'} ${c.total} unique screenshot${c.total === 1 ? '' : 's'}</div>`
      + (c.hasVideo ? `<div class="cvx-chk">✅ Screen recording added</div>` : `<div class="cvx-chk">${c.need ? '⏳ Need at least ' + c.need + ' more (min ' + CFG.min_shots + ')' : '✅ Enough screenshots'}</div>`)
      + `<div class="cvx-chk">☑️ Chat ની શરૂઆત થી order confirm / address સુધી — AI check કરશે</div>`
      + (CFG.video_ok ? `<div class="cvx-or">OR</div><div class="cvx-opt ${S.video || up.has_video ? 'on' : ''}"><span>🎬 ${S.video ? esc(S.video.name) : up.has_video ? 'Screen recording saved' : '1 screen-recording video (chat scroll કરો)'}</span>`
        + `<label class="cvx-lnk">${S.video || up.has_video ? 'Change' : '≤ ' + CFG.video_sec + ' s · Add'}<input type="file" accept="video/*" hidden id="cvxVideo"></label></div>` : '')
      + `<div class="cvx-opt ${S.call || up.has_call ? 'on' : ''}"><span>📞 ${S.call ? esc(S.call.name) : up.has_call ? 'Call recording saved' : (isLost ? 'Call recording <span class="mut">— if you spoke to this customer on a call</span>' : 'Call recording <span class="mut">(optional)</span>')}${CFG.call_ok ? '' : ' <span class="mut">· review needs Admin AI setup</span>'}</span>`
      + `<label class="cvx-lnk">${S.call || up.has_call ? 'Change' : '+ Add'}<input type="file" hidden id="cvxCall"></label></div>`
      + `<button type="button" class="cvx-btn" data-save="1" ${canSave ? '' : 'disabled'}>${saveLbl}</button>`
      + (up.status === 'done' ? `<button type="button" class="cvx-btn ghost" data-view="review">← Back to review</button>` : '');
  }
  function skillBars(scores) {
    return Object.keys(scores || {}).filter((k) => scores[k] >= 0).map((k) => {
      const v = scores[k];
      return `<div class="cvx-sk"><span>${SKILL[k] || esc(k)}</span><b>${v}</b></div><div class="cvx-bar"><i style="width:${v * 10}%;background:${v >= 7 ? '#16a34a' : '#f59e0b'}"></i></div>`;
    }).join('');
  }
  function tipsHtml(r) {
    return (r.well_done || []).map((t) => `<div class="cvx-tip win">✅ <b>Well done:</b> ${esc(t)}</div>`).join('')
      + (r.focus || []).map((f) => `<div class="cvx-tip foc">🎯 <b>Focus next time:</b> ${esc(f.text)}${f.try_line ? `<span class="q">💬 Try: "${esc(f.try_line)}"</span>` : ''}</div>`).join('');
  }
  function reviewView() {
    const u = S.up, ch = u.chat, cl = u.call, adm = !!S.opts.admin;
    let h = titleHtml();
    if (adm && u.employee) h += `<div class="mut" style="margin-top:2px">👤 ${esc(u.employee)} · ${esc(String(u.created_at || '').slice(0, 16))}</div>`;
    if (u.status === 'analyzing' || u.status === 'saved') {
      return h + `<div class="cvx-ok" style="font-weight:600"><span class="cvx-spin"></span><b>All attachments saved — ${u.shots ? u.shots + ' screenshots' : 'screen recording'}${u.has_call ? ' + 1 call' : ''}</b><br>AI review ~30 sec માં તૈયાર થશે. તમે આ બંધ કરી શકો છો — result My Sales માં દેખાશે.</div>`;
    }
    if (u.status === 'incomplete') h += `<div class="cvx-warn">🤖 ${esc(u.msg)}</div><button type="button" class="cvx-btn" data-view="upload">＋ Add screenshots</button>`;
    if (u.status === 'error' || u.status === 'waiting') h += `<div class="cvx-warn">⚠️ ${esc(u.msg || 'The review could not finish.')}</div><button type="button" class="cvx-btn" data-retry="1">↻ Try the review again</button>`;
    if (u.tags && u.tags.length) h += `<div style="margin-top:8px">${u.tags.map((t) => `<span class="cvx-tag ${t[0] === '⚠' ? 'w' : ''}">${esc(t)}${t[0] === '⚠' ? '' : ' ✓'}</span>`).join('')}</div>`;
    if (ch && ch.is_chat) {
      h += `<div class="cvx-score">${C.ring(ch.overall)}<div><b>${scoreWord(ch.overall)}</b><div class="mut">${u.kind === 'lost' ? 'Lost chat' + (ch.lost_reason ? ' · reason: ' + esc(ch.lost_reason) : '') : 'Chat review'}${ch.first_reply_min >= 0 ? ' · first reply ~' + Math.round(ch.first_reply_min) + ' min' : ''}</div></div></div>`
        + skillBars(ch.scores) + tipsHtml(ch);
      if (adm && ch.summary) h += `<div class="mut" style="margin-top:6px">📝 ${esc(ch.summary)}</div>`;
    }
    if (u.has_call) {
      h += `<div class="cvx-h">📞 Call recording</div>`;
      if (cl && u.call_status === 'done') {
        h += `<div class="cvx-score">${C.ring(cl.overall, 54)}<div><b>${scoreWord(cl.overall)}</b><div class="mut">${cl.minutes ? '~' + Math.round(cl.minutes) + ' min · ' : ''}you talked ${Math.round((cl.talk_ratio_sales || 0) * 100)}% · ${esc(cl.tone || '')}${cl.interruptions ? ' · interrupted ' + cl.interruptions + '×' : ''}</div></div></div>`
          + skillBars(cl.scores) + tipsHtml(cl);
      } else if (u.call_status === 'setup') h += `<div class="mut">Call review માટે Admin એ AI setup કરવું પડશે — recording save છે.</div>`;
      else if (u.call_status === 'not_call') h += `<div class="mut">આ recording sales call જેવું નથી લાગતું.</div>`;
      else if (u.call_status === 'error') h += `<div class="cvx-warn">Call review could not finish.</div><button type="button" class="cvx-btn ghost" data-retry="1">↻ Retry call review</button>`;
      else h += `<div class="mut"><span class="cvx-spin"></span>Call review pending…</div>`;
    }
    // files
    const shots = shotsOf(u), media = (u.files || []).filter((f) => f.kind !== 'shot');
    if (!u.files_gone && (shots.length || media.length)) {
      h += `<div class="cvx-h">Files</div><div class="cvx-files">${shots.map((f, i) => `<div class="cvx-sh"><img data-n="${f.n}" alt="${i + 1}" data-zoom="1"></div>`).join('')}</div>`
        + media.map((f) => f.kind === 'call' ? `<audio controls preload="none" data-media="${f.n}"></audio>` : `<video controls preload="metadata" data-media="${f.n}"></video>`).join('');
    } else if (u.files_gone) h += `<div class="mut" style="margin-top:8px">Files deleted after ${CFG.keep_days} days — scores and notes are kept.</div>`;
    if (u.status === 'done' && Number(u.shots || 0) < Number(CFG.max_shots || 10)) h += `<button type="button" class="cvx-btn ghost" data-view="upload">＋ Add more screenshots${u.kind === 'lost' ? ' of this chat' : ''}</button>`;
    if (u.status === 'done' && !u.has_call) h += `<button type="button" class="cvx-btn ghost" data-view="upload">📞 Add call recording${u.kind === 'lost' ? ' (call with this customer)' : ''}</button>`;
    if (adm) h += `<button type="button" class="cvx-btn ghost" data-flag="${u.flag_wrong ? 0 : 1}">${u.flag_wrong ? '↶ AI review is fine (count it again)' : '🚩 AI review is wrong (leave it out of scores)'}</button>`;
    h += `<button type="button" class="cvx-btn ghost" data-close="1">Close</button>`;
    return h;
  }

  // ---------- events ----------
  async function addShots(files) {
    for (const f of files) {
      const isImg = /^image\/(jpeg|png|webp|gif|bmp)$/.test(f.type) || /\.(jpe?g|png|webp)$/i.test(f.name);
      if (f.size > CFG.shot_mb * 1048576) { S.rejected.push({ name: f.name, why: 'too big (max ' + CFG.shot_mb + ' MB)' }); continue; }
      if (counts().total >= CFG.max_shots) { S.rejected.push({ name: f.name, why: 'max ' + CFG.max_shots + ' screenshots' }); continue; }
      if (!isImg) { S.picks.push({ file: f, name: f.name, size: f.size, url: '', hash: '', dup: null, raw: 1 }); continue; }   // any other file type (HEIC, PDF …) is accepted as it is
      const blob = await shrink(f); let h = '';
      try { h = await dhash(blob); } catch (e) {}
      // compare with the screenshots already picked (the server checks the saved ones too)
      let dup = null, n = S.up ? S.up.shots : 0;
      for (const p of S.picks) { if (p.dup) continue; n++; if (ham(h, p.hash) <= DUP_BITS || (p.size === f.size && p.name === f.name)) { dup = n; break; } }
      S.picks.push({ file: blob, name: f.name, size: f.size, url: URL.createObjectURL(blob), hash: h, dup });
    }
    draw();
  }
  async function onChange(e) {
    const t = e.target;
    if (t.id === 'cvxShots') { S.rejected = []; await addShots(Array.from(t.files || [])); }
    else if (t.id === 'cvxNote') S.note = t.value;
    else if (t.id === 'cvxVideo' || t.id === 'cvxCall') {
      const f = (t.files || [])[0]; if (!f) return;
      S.rejected = [];
      if (f.size > CFG.media_mb * 1048576) S.rejected.push({ name: f.name, why: 'too big (max ' + CFG.media_mb + ' MB)' + (t.id === 'cvxVideo' ? ' — record a shorter scroll' : '') });
      else if (t.id === 'cvxVideo') { const s = await mediaSeconds(f, 'video'); if (s && s > CFG.video_sec + 2) S.rejected.push({ name: f.name, why: 'video is ' + Math.round(s) + ' s — max ' + CFG.video_sec + ' s' }); else S.video = f; }
      else S.call = f;
      draw();
    }
  }
  async function onClick(e) {
    const t = e.target;
    if (t === S.root || t.closest('.cvx-x') || t.closest('[data-close]')) { close(); return; }
    const z = t.closest('[data-zoom]');
    if (z && z.src) { const lb = document.createElement('div'); lb.className = 'cvx-lb'; lb.innerHTML = `<img src="${z.src}" alt="">`; lb.onclick = () => lb.remove(); document.body.appendChild(lb); return; }
    const rp = t.closest('[data-rmp]');
    if (rp) { const p = S.picks.splice(Number(rp.dataset.rmp), 1)[0]; if (p) URL.revokeObjectURL(p.url); recheckDups(); draw(); return; }
    const rf = t.closest('[data-rmf]');
    if (rf) {
      try { const d = await api.post('convo.remove_file', { id: S.up.id, n: Number(rf.dataset.rmf) }); S.up = d.upload; } catch (er) { S.err = er.message; }
      draw(); return;
    }
    const v = t.closest('[data-view]'); if (v) { S.view = v.dataset.view; S.rejected = []; draw(); return; }
    if (t.closest('[data-save]')) { save(); return; }
    if (t.closest('[data-retry]')) { analyze(true); return; }
    const fl = t.closest('[data-flag]');
    if (fl) { try { const d = await api.post('convo.flag', { id: S.up.id, wrong: fl.dataset.flag === '1' }); S.up = Object.assign(S.up, d.upload); } catch (er) { S.err = er.message; } draw(); }
  }
  function recheckDups() {
    let n = S.up ? S.up.shots : 0; const seen = [];
    for (const p of S.picks) {
      p.dup = null;
      for (const q of seen) if (ham(p.hash, q.hash) <= DUP_BITS) { p.dup = q.no; break; }
      if (!p.dup) { n++; seen.push({ hash: p.hash, no: n }); }
    }
  }
  async function save() {
    if (S.busy) return;
    S.busy = true; S.err = ''; draw();
    const fd = new FormData(), o = S.opts;
    if (S.up && S.up.id) fd.append('id', S.up.id);
    else if (o.kind === 'lost') fd.append('kind', 'lost');
    else { fd.append('kind', 'order'); fd.append('source', o.source); fd.append('order_id', o.order_id); fd.append('order_name', o.order_name || ''); fd.append('order_total', o.total || 0); fd.append('order_day', o.day || ''); }
    if ((S.up ? S.up.kind : o.kind) === 'lost') fd.append('note', S.note || (S.up && S.up.note) || '');
    goodPicks().forEach((p, i) => fd.append('shots[]', p.file, p.raw ? p.name : 'shot' + (i + 1) + '.jpg'));
    if (S.video) fd.append('video', S.video, S.video.name);
    if (S.call) fd.append('call', S.call, S.call.name);
    try {
      const d = await api.upload('convo.upload', fd);
      if (!S) return;
      C.setCfg(d.cfg);
      S.picks.forEach((p) => URL.revokeObjectURL(p.url));
      S.picks = []; S.video = null; S.call = null; S.rejected = d.rejected || [];
      S.up = Object.assign(S.up || {}, d.upload); S.opts.id = d.upload.id; S.busy = false; S.dupNote = d.dup_note || null; S.notes = d.notes || [];
      if (d.upload.status === 'need_more') { await reload(); return; }
      if (S.rejected.length && !d.added) { await reload(); S.view = 'upload'; draw(); return; }
      analyze(false);
    } catch (er) { if (S) { S.busy = false; S.err = er.message; draw(); } }
  }
  async function analyze(force) {
    S.up.status = 'analyzing'; S.view = 'review'; draw();
    const id = S.up.id;
    try {
      const d = await Promise.race([api.post('convo.analyze', { id, force: !!force }), new Promise((_, rej) => setTimeout(() => rej(new Error('slow')), 150000))]);
      if (!S || S.up.id !== id) return;
      S.up = d.upload; if (d.busy) setTimeout(() => S && reload(), 4000);
    } catch (er) { if (!S || S.up.id !== id) return; if (er.message === 'slow') { reload(); return; } S.err = er.message; S.up.status = 'error'; S.up.msg = er.message; }
    S.view = (S.up.status === 'need_more') ? 'upload' : 'review';
    await reload();
  }


  // A repeated screenshot: first a soft note, then a firmer one, then "meet admin" (levels come from the server).
  function dupHtml(n) {
    if (!n) return '';
    const cls = n.level === 'meet' ? 'cvx-meet' : 'cvx-warn';
    return `<div class="${cls}">${n.level === 'meet' ? '🚨' : '🔁'} ${esc(n.gu || n.text || '')}${n.gu && n.text ? `<div class="mut" style="margin-top:3px">${esc(n.text)}</div>` : ''}</div>`;
  }
  C.dupHtml = dupHtml;

  // ---------- Proof first, allocation right after ----------
  // Convo.proof({ source, order_id, order_name, total, day, need: 'chat' | 'call', msg, gu }) -> Promise<{ok, id, upload}>:
  //   ok = something was saved (any file type) and the caller can try allocation.set again. The server decides what is enough.
  C.proof = function (opts) {
    opts = opts || {};
    return new Promise((resolve) => {
      if (S) close();
      const P = { picks: [], call: null, busy: false, rejected: [], notes: [], dupNote: null, err: '', saved: null };
      const root = document.createElement('div'); root.className = 'cvx';
      root.innerHTML = '<div class="cvx-box"><button type="button" class="cvx-x" aria-label="Close">×</button><div class="cvx-body"></div></div>';
      document.body.appendChild(root); keepAboveNav(root);
      let done = false;
      const finish = (v) => { if (done) return; done = true; P.picks.forEach((p) => p.url && URL.revokeObjectURL(p.url)); root.remove(); document.removeEventListener('keydown', key); resolve(v); };
      const key = (e) => { if (e.key === 'Escape') { const lb = document.querySelector('.cvx-lb'); if (lb) lb.remove(); else finish({ ok: false }); } };
      document.addEventListener('keydown', key);
      const needCall = opts.need === 'call';
      function draw() {
        const b = root.querySelector('.cvx-body');
        const tiles = P.picks.map((p, i) => `<div class="cvx-sh new ${p.dup ? 'dup' : ''}">${p.raw ? `<div class="cvx-fi">📄<small>${esc((p.name.split('.').pop() || 'file').slice(0, 5))}</small></div>` : `<img src="${p.url}" alt="" data-zoom="1">`}<b>${p.dup ? 'Same as ' + p.dup : (i + 1) + ' ✓'}</b><button type="button" class="rm" data-rmp="${i}" title="Remove">✕</button></div>`).join('');
        const good = P.picks.filter((p) => !p.dup).length;
        const ok = !P.busy && (needCall ? !!P.call : good > 0 || !!P.call);
        b.innerHTML = `<h3>📎 પહેલા proof, પછી allocation${opts.order_name ? ' · ' + esc(opts.order_name) : ''}</h3>`
          + `<div class="${needCall ? 'cvx-meet' : 'cvx-warn'}">${esc(opts.gu || (needCall ? 'આ order માટે call recording જોઈએ.' : 'Chat ના screenshots (કોઈ પણ file ચાલશે) upload કરો, પછી order allocate થશે.'))}${opts.msg ? `<div class="mut" style="margin-top:3px">${esc(opts.msg)}</div>` : ''}</div>`
          + `<div class="cvx-h">1 · Chat screenshots <span class="mut" style="text-transform:none;letter-spacing:0">(customer ના પહેલા message થી order confirm સુધી · કોઈ પણ file type)</span></div>`
          + `<div class="cvx-grid">${tiles}<label class="cvx-add">＋<small>Screenshots</small><input type="file" multiple hidden id="cvxPShots"></label></div>`
          + `<div class="cvx-h">2 · Call recording ${needCall ? '<span style="color:#b91c1c">(જરૂરી)</span>' : '<span class="mut" style="text-transform:none;letter-spacing:0">(હોય તો)</span>'}</div>`
          + `<div class="cvx-opt ${P.call ? 'on' : ''}"><span>📞 ${P.call ? esc(P.call.name) : 'Call recording — કોઈ પણ file'}</span><label class="cvx-lnk">${P.call ? 'Change' : '+ Add'}<input type="file" hidden id="cvxPCall"></label></div>`
          + P.picks.filter((p) => p.dup).map((p) => `<div class="cvx-warn">⚠️ આ screenshot, screenshot ${p.dup} જેવો જ છે — repeat નહીં ચાલે.</div>`).join('')
          + P.rejected.map((r) => `<div class="cvx-warn">⚠️ ${esc(r.name)}: ${esc(r.why)}</div>`).join('')
          + P.notes.map((r) => `<div class="cvx-warn">ℹ️ ${esc(r.name)}: ${esc(r.why)}</div>`).join('')
          + dupHtml(P.dupNote)
          + (P.err ? `<div class="cvx-warn">${esc(P.err)}</div>` : '')
          + `<button type="button" class="cvx-btn" data-psave="1" ${ok ? '' : 'disabled'}>${P.busy ? '<span class="cvx-spin"></span>Saving…' : 'Save proof &amp; allocate ✓'}</button>`
          + `<button type="button" class="cvx-btn ghost" data-pclose="1">Not now</button>`;
      }
      root.addEventListener('click', async (e) => {
        const t = e.target;
        if (t === root || t.closest('.cvx-x') || t.closest('[data-pclose]')) { finish({ ok: false }); return; }
        const z = t.closest('[data-zoom]');
        if (z && z.src) { const lb = document.createElement('div'); lb.className = 'cvx-lb'; lb.innerHTML = `<img src="${z.src}" alt="">`; lb.onclick = () => lb.remove(); document.body.appendChild(lb); return; }
        const rp = t.closest('[data-rmp]');
        if (rp) { const p = P.picks.splice(Number(rp.dataset.rmp), 1)[0]; if (p && p.url) URL.revokeObjectURL(p.url); draw(); return; }
        if (t.closest('[data-psave]')) await save();
      });
      root.addEventListener('change', async (e) => {
        const t = e.target;
        if (t.id === 'cvxPShots') {
          P.rejected = [];
          for (const f of Array.from(t.files || [])) {
            if (f.size > CFG.shot_mb * 1048576) { P.rejected.push({ name: f.name, why: 'too big (max ' + CFG.shot_mb + ' MB)' }); continue; }
            const isImg = /^image\/(jpeg|png|webp|gif|bmp)$/.test(f.type) || /\.(jpe?g|png|webp)$/i.test(f.name);
            if (!isImg) { P.picks.push({ file: f, name: f.name, size: f.size, url: '', hash: '', dup: null, raw: 1 }); continue; }
            const blob = await shrink(f); let h = ''; try { h = await dhash(blob); } catch (er) {}
            let dup = null, n = 0;
            for (const p of P.picks) { if (p.dup) continue; n++; if (p.hash && h && ham(h, p.hash) <= DUP_BITS) { dup = n; break; } }
            P.picks.push({ file: blob, name: f.name, size: f.size, url: URL.createObjectURL(blob), hash: h, dup });
          }
          draw();
        } else if (t.id === 'cvxPCall') {
          const f = (t.files || [])[0]; if (!f) return;
          P.rejected = [];
          if (f.size > CFG.media_mb * 1048576) P.rejected.push({ name: f.name, why: 'too big (max ' + CFG.media_mb + ' MB)' }); else P.call = f;
          draw();
        }
      });
      async function save() {
        if (P.busy) return;
        P.busy = true; P.err = ''; draw();
        const fd = new FormData();
        fd.append('kind', 'order'); fd.append('source', opts.source); fd.append('order_id', opts.order_id); fd.append('pre_alloc', '1');
        fd.append('order_name', opts.order_name || ''); fd.append('order_total', opts.total || 0); fd.append('order_day', opts.day || '');
        if (opts.employee_id) fd.append('employee_id', opts.employee_id);
        P.picks.filter((p) => !p.dup).forEach((p, i) => fd.append('shots[]', p.file, p.raw ? p.name : 'shot' + (i + 1) + '.jpg'));
        if (P.call) fd.append('call', P.call, P.call.name);
        try {
          const d = await api.upload('convo.upload', fd);
          C.setCfg(d.cfg);
          P.busy = false; P.rejected = d.rejected || []; P.notes = d.notes || []; P.dupNote = d.dup_note || null;
          if (d.added > 0) {
            P.picks.forEach((p) => p.url && URL.revokeObjectURL(p.url)); P.picks = []; P.call = null;
            // gentle notes stay visible for a moment, then the caller goes on with allocation
            if (P.rejected.length || P.notes.length || P.dupNote) { draw(); await new Promise((r) => setTimeout(r, P.dupNote && P.dupNote.level === 'meet' ? 3500 : 1800)); }
            finish({ ok: true, id: d.upload && d.upload.id, upload: d.upload });
          } else draw();
        } catch (er) { P.busy = false; P.err = er.message; draw(); }
      }
      draw();
    });
  };

  // ---------- Today's call recordings (Punch Out / My Sales) ----------
  // Convo.calls({ punch: true }) → Promise<boolean>: true = go on with Punch Out (enough recordings, warn mode, or a reason written).
  C.calls = function (opts) {
    opts = opts || {};
    return new Promise((resolve) => {
      if (S) close();
      const st = { d: null, busy: false, lines: [], skip: false, reason: '', err: '' };
      const root = document.createElement('div'); root.className = 'cvx';
      root.innerHTML = '<div class="cvx-box"><button type="button" class="cvx-x" aria-label="Close">×</button><div class="cvx-body"><p class="mut"><span class="cvx-spin"></span>Loading…</p></div></div>';
      document.body.appendChild(root); keepAboveNav(root);
      let done = false;
      const finish = (v) => { if (done) return; done = true; root.remove(); document.removeEventListener('keydown', key); resolve(v); if (opts.onChange) try { opts.onChange(); } catch (e) {} };
      const key = (e) => { if (e.key === 'Escape') finish(false); };
      document.addEventListener('keydown', key);
      const load = async () => { try { st.d = await api.get('convo.calls_today'); C.setCfg(st.d.cfg); } catch (e) { st.err = e.message; } draw(); };
      const kick = () => { try { fetch(api.buildUrl('convo.analyze_pending'), { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + api.token() }, body: '{}' }).catch(() => {}); } catch (e) {} };
      function draw() {
        const b = root.querySelector('.cvx-body'), d = st.d;
        if (!d) { b.innerHTML = `<h3>📞 Today's call recordings</h3><div class="cvx-warn">${esc(st.err || 'Could not load.')}</div>`; return; }
        const req = d.required, upl = d.uploaded, left = Math.max(0, req - upl), pct = req ? Math.min(100, upl / req * 100) : 100;
        const block = opts.punch && d.rule.mode === 'block' && left > 0 && !d.skip;
        let h = `<h3>📞 Today's call recordings</h3>`
          + (req ? `<div class="mut">આજે તમને <b>${d.queries} queries</b> મળી → ઓછામાં ઓછા <b>${req} call recordings</b> upload કરો — order થયો હોય કે ના થયો હોય, બંને. AI order વાળા calls ને order સાથે જોડશે, બાકીના study માટે.</div>`
            : `<div class="mut">આજે કોઈ queries assign નથી — તો પણ calls upload કરી શકો, AI coaching આપશે.</div>`)
          + `<div class="cvx-score"><div style="flex:1"><div class="cvx-sk"><span>${upl} of ${req || upl} uploaded</span><b>${left ? left + ' left' : '✅ Done'}</b></div><div class="cvx-bar" style="height:10px"><i style="width:${pct}%;background:${left ? '#f59e0b' : '#16a34a'}"></i></div></div></div>`
          + st.lines.map((l) => `<div class="cvx-chk">${l}</div>`).join('')
          + (st.err ? `<div class="cvx-warn">${esc(st.err)}</div>` : '')
          + `<label class="cvx-btn" style="text-align:center; ${st.busy ? 'opacity:.6; pointer-events:none;' : ''}">${st.busy ? '<span class="cvx-spin"></span>Uploading…' : '＋ Upload call recordings (choose many)'}<input type="file" accept="audio/*,.m4a,.mp3,.aac,.wav,.ogg,.opus,.mp4,video/mp4" multiple hidden id="cvxCalls"></label>`
          + (d.ai_call_ok ? '' : `<div class="mut" style="margin-top:6px">Recordings save થશે; AI review માટે Admin એ setup કરવું પડશે.</div>`);
        if (d.list && d.list.length) {
          h += `<div class="cvx-h">Uploaded today</div>` + d.list.slice(0, 12).map((u) => `<div class="cvx-opt" style="border-style:solid"><span>${u.kind === 'qcall' && !u.order_name ? '⏳ AI checking…' : u.kind === 'lost' ? '💔 No order — study' : '✅ Order ' + esc(u.order_name)}</span><b>${u.call_score != null ? '📞 ' + Number(u.call_score).toFixed(1) : ''}</b></div>`).join('');
        }
        if (opts.punch) {
          if (d.skip) h += `<div class="cvx-ok" style="font-weight:600">Reason saved: ${esc(d.skip.reason)}</div>`;
          h += `<button type="button" class="cvx-btn" data-go="1" ${block ? 'disabled' : ''}>${left && !block ? '⏰ Punch Out (' + left + ' recordings missing)' : '✅ Continue to Punch Out'}</button>`;
          if (block) h += st.skip ? `<textarea id="cvxReason" maxlength="300" placeholder="Recordings કેમ નથી? (Admin જોશે અને Discipline rating માં ગણાશે)">${esc(st.reason)}</textarea><button type="button" class="cvx-btn ghost" data-skipsave="1">Punch out without recordings</button>`
            : `<button type="button" class="cvx-btn ghost" data-skip="1">Recordings નથી? Reason લખીને punch out</button>`;
        } else h += `<button type="button" class="cvx-btn ghost" data-close="1">Close</button>`;
        b.innerHTML = h;
      }
      root.addEventListener('click', async (e) => {
        const t = e.target;
        if (t === root || t.closest('.cvx-x') || t.closest('[data-close]')) { finish(false); return; }
        if (t.closest('[data-go]')) { finish(true); return; }
        if (t.closest('[data-skip]')) { st.skip = true; draw(); return; }
        if (t.closest('[data-skipsave]')) {
          st.reason = (root.querySelector('#cvxReason') || {}).value || '';
          try { await api.post('convo.punch_skip', { reason: st.reason }); finish(true); } catch (er) { st.err = er.message; draw(); }
        }
      });
      root.addEventListener('change', async (e) => {
        if (e.target.id !== 'cvxCalls') return;
        const files = Array.from(e.target.files || []); if (!files.length) return;
        st.busy = true; st.err = ''; st.lines = []; draw();
        let ok = 0;
        for (const f of files) {
          if (f.size > CFG.media_mb * 1048576) { st.lines.push('⚠️ ' + esc(f.name) + ': too big (max ' + CFG.media_mb + ' MB)'); continue; }
          if (/\.amr$/i.test(f.name)) { st.lines.push('⚠️ ' + esc(f.name) + ': AMR cannot be read — set the recorder to MP3 / M4A'); continue; }
          const fd = new FormData(); fd.append('call', f, f.name);
          try { await api.upload('convo.upload_calls', fd); ok++; st.lines.push('✅ ' + esc(f.name)); }
          catch (er) { st.lines.push('⚠️ ' + esc(er.message)); }
          draw();
        }
        st.busy = false;
        if (ok) { st.lines.push(`<b>✅ ${ok} recording${ok === 1 ? '' : 's'} saved</b> — AI review ~30 sec each, background માં.`); kick(); }
        await load();
      });
      load();
    });
  };
})();