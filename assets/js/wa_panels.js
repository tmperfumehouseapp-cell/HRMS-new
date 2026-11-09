/**
 * wa_panels.js — the screens of the WhatsApp console (whatsapp.html): Numbers, Contacts, Campaigns, Pre-made messages,
 * Media library, Follow-ups, Team & routing, Reports. Needs api.js. Every screen is phone-first: cards, big buttons, bottom sheets.
 *   WaPanels.mount('numbers', element, { go(key), openChat(phone) })  →  returns a function that reloads the screen
 */
(function () {
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const $ = (r, q) => r.querySelector(q);
  const fmt = (n) => Number(n || 0).toLocaleString('en-IN');
  const money = (n) => '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
  const pretty = (p) => { p = String(p || ''); return p.length === 10 ? '+91 ' + p.slice(0, 5) + ' ' + p.slice(5) : p; };
  function parse(s) { const m = /^(\d{4})-(\d\d)-(\d\d)[ T](\d\d):(\d\d)(?::(\d\d))?/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) : null; }
  function when(s) { const d = parse(s); return d ? d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) + ', ' + d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }) : '—'; }
  function mins(m) { if (m == null) return '—'; m = Math.round(m); return m < 1 ? '< 1 min' : m < 60 ? m + ' min' : m < 1440 ? Math.floor(m / 60) + ' h ' + (m % 60) + ' min' : Math.round(m / 1440 * 10) / 10 + ' days'; }
  const vars = (s) => [...new Set([...String(s || '').matchAll(/\{\{\s*(\d+)\s*\}\}/g)].map((m) => +m[1]))].sort((a, b) => a - b);
  const fill = (t, v) => String(t || '').replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => (v[+n - 1] || m));
  const loading = '<div class="wp-empty">Loading…</div>';
  const errBox = (m) => `<div class="wp-err">${esc(m)}</div>`;

  const CSS = `
  .wp{font-family:var(--font,system-ui); color:#1b2430; max-width:1100px; margin:0 auto;} .wp *{box-sizing:border-box;} .wp [hidden]{display:none !important;}
  .wp button,.wp input,.wp select,.wp textarea{font:inherit;}
  .wp-top{display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:14px;} .wp-top h2{margin:0; font-size:17px; flex:1; min-width:140px;} .wp-top p{margin:2px 0 0; font-size:12.5px; color:#6b7a73; font-weight:500;}
  .wp-cd{background:#fff; border:1px solid #e3e8e5; border-radius:16px; padding:14px 16px; margin-bottom:12px;} .wp-cd h3{margin:0 0 8px; font-size:14px; font-weight:800; display:flex; gap:8px; align-items:center;} .wp-cd h3 .sp{flex:1;}
  .wp-btn{border:1px solid #d8dfdb; background:#fff; color:#1b2430; border-radius:999px; padding:9px 16px; font-weight:700; font-size:13px; cursor:pointer; min-height:40px; width:auto;} .wp-btn:hover{background:#f3f6f4;}
  .wp-btn.p{background:#12794a; border-color:#12794a; color:#fff;} .wp-btn.p:hover{background:#0f6a40;} .wp-btn.d{background:#1b2430; border-color:#1b2430; color:#fff;} .wp-btn.r{color:#b42318; border-color:#f0c4c0;} .wp-btn.s{padding:6px 12px; min-height:34px; font-size:12px;} .wp-btn:disabled{opacity:.5; cursor:default;}
  .wp-in{width:100%; border:1px solid #d8dfdb; border-radius:11px; padding:10px 12px; min-height:44px; background:#fff; color:#1b2430;} textarea.wp-in{min-height:84px; resize:vertical; line-height:1.45;}
  .wp-fl{display:block; font-weight:700; font-size:12.5px; color:#46564e; margin:12px 0 4px;} .wp-fl small{font-weight:500; color:#8a998f;} .wp-hint{font-size:12px; color:#6b7a73; margin-top:4px; line-height:1.45;}
  .wp-row{display:flex; gap:8px; flex-wrap:wrap; align-items:center;} .wp-grow{flex:1; min-width:120px;} .wp-2{display:grid; grid-template-columns:1fr 1fr; gap:10px;}
  .wp-pill{display:inline-block; font-size:11px; font-weight:700; padding:3px 9px; border-radius:99px; margin:2px 4px 2px 0; background:#eef1ef; color:#4a5a52; white-space:nowrap;} .wp-pill.ok{background:#dcf7e6; color:#12794a;} .wp-pill.warn{background:#fff3cd; color:#8a6a00;} .wp-pill.bad{background:#fde6e6; color:#b42318;} .wp-pill.b{background:#e5eefc; color:#1f5fc4;} .wp-pill.o{background:#fff1e3; color:#c2570c;} .wp-pill.p{background:#efe7fb; color:#6b3fc0;}
  .wp-empty{padding:30px 16px; text-align:center; color:#7b8a82; line-height:1.6;} .wp-err{background:#fde8e8; color:#9a2620; border-radius:12px; padding:11px 14px; margin:8px 0; line-height:1.45;} .wp-ok{background:#e3f6ec; color:#12794a; border-radius:12px; padding:11px 14px; margin:8px 0; line-height:1.45;} .wp-note{background:#fff7ed; border:1px solid #fed7aa; color:#8a4a0d; border-radius:12px; padding:10px 13px; margin:10px 0; font-size:12.5px; line-height:1.5;}
  .wp-seg{display:flex; gap:6px; margin:0 0 12px; flex-wrap:wrap;} .wp-seg button{border:0; background:#eef1ef; border-radius:10px; padding:9px 14px; font-weight:800; font-size:13px; cursor:pointer; min-height:40px;} .wp-seg button.on{background:#1b2430; color:#fff;}
  .wp-sw{position:relative; display:inline-block; width:44px; height:26px; flex:none;} .wp-sw input{opacity:0; width:0; height:0; position:absolute;} .wp-sw span{position:absolute; inset:0; background:#c5cbc8; border-radius:99px; cursor:pointer; transition:.15s;} .wp-sw span:before{content:''; position:absolute; width:20px; height:20px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.15s;} .wp-sw input:checked + span{background:#12794a;} .wp-sw input:checked + span:before{transform:translateX(18px);}
  .wp-kv{display:flex; justify-content:space-between; gap:10px; padding:8px 0; border-top:1px solid #edf1ee; font-size:13px;} .wp-kv:first-of-type{border-top:0;} .wp-kv span{color:#6b7a73;} .wp-kv b{text-align:right; word-break:break-word;}
  .wp-bar{height:9px; background:#e9eeeb; border-radius:6px; overflow:hidden; margin:7px 0 3px;} .wp-bar i{display:block; height:100%; background:#25d366; border-radius:6px; min-width:2px;} .wp-bar i.w{background:#f0a500;} .wp-bar i.b{background:#d92d20;}
  .wp-grid{display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:12px;} .wp-grid .wp-cd{margin:0;}
  .wp-kpis{display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; margin-bottom:12px;} .wp-kpi{background:#fff; border:1px solid #e3e8e5; border-radius:14px; padding:11px 14px;} .wp-kpi small{display:block; font-size:11px; font-weight:800; color:#6b7a73; text-transform:uppercase; letter-spacing:.06em;} .wp-kpi b{font-size:24px; font-weight:800; line-height:1.2;} .wp-kpi span{display:block; font-size:11.5px; color:#6b7a73;}
  .wp-av{width:46px; height:46px; border-radius:50%; background:#25d366 center/cover no-repeat; color:#0e1a15; font-weight:800; display:flex; align-items:center; justify-content:center; flex:none; font-size:18px;}
  .wp-md{position:fixed; inset:0; z-index:200; background:#0008; display:flex; align-items:flex-end; justify-content:center;} .wp-mb{background:#fff; width:100%; max-width:720px; max-height:92vh; max-height:92dvh; border-radius:20px 20px 0 0; display:flex; flex-direction:column;}
  .wp-mh{display:flex; align-items:center; gap:10px; padding:14px 16px 10px; border-bottom:1px solid #edf1ee;} .wp-mh b{flex:1; font-size:16px;} .wp-x{border:0; background:#eef1ef; border-radius:50%; width:38px; height:38px; font-size:16px; cursor:pointer; padding:0; min-height:0;}
  .wp-mc{overflow-y:auto; padding:12px 16px calc(18px + env(safe-area-inset-bottom)); -webkit-overflow-scrolling:touch;}
  .wp-toast{position:fixed; left:50%; bottom:calc(84px + env(safe-area-inset-bottom)); transform:translateX(-50%); background:#1b2430; color:#fff; padding:11px 18px; border-radius:99px; font-weight:700; font-size:13px; z-index:300; max-width:90vw; box-shadow:0 8px 24px #0004;} .wp-toast.bad{background:#b42318;}
  .wp-bub{background:#dcf8c6; border-radius:12px; padding:10px 13px; white-space:pre-wrap; line-height:1.45; box-shadow:0 1px 2px #0001; margin:6px 0;} .wp-btns{display:flex; gap:6px; flex-wrap:wrap; margin-top:6px;} .wp-btns span{background:#fff; border:1px solid #cfe3d5; color:#1f6fb5; font-weight:700; border-radius:10px; padding:6px 12px; font-size:12.5px;}
  .wp-und{background:#eef6ff; border:1px solid #cfe3fb; border-radius:12px; padding:10px 13px; margin:8px 0; line-height:1.5;} .wp-und small{display:block; font-weight:800; color:#1f5fc4; font-size:11px; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px;}
  .wp-chip{border:1px solid #d8dfdb; background:#fff; border-radius:99px; padding:7px 13px; font-weight:700; font-size:12.5px; cursor:pointer; min-height:36px;} .wp-chip.on{background:#1b2430; border-color:#1b2430; color:#fff;}
  .wp-lib{display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px;} .wp-li{background:#fff; border:1px solid #e3e8e5; border-radius:14px; overflow:hidden;} .wp-li .im{height:116px; background:#eef1ef center/cover no-repeat; display:flex; align-items:center; justify-content:center; font-size:38px; cursor:pointer;} .wp-li .nm{padding:8px 10px 2px; font-weight:700; font-size:13px; line-height:1.3; max-height:3.5em; overflow:hidden;} .wp-li .mt{padding:0 10px 6px; font-size:11.5px; color:#6b7a73;} .wp-li .ac{display:flex; gap:6px; padding:0 8px 10px;}
  .wp-bars{display:flex; align-items:flex-end; gap:2px; height:130px; padding-top:6px;} .wp-bars>div{flex:1; display:flex; flex-direction:column; justify-content:flex-end; height:100%; min-width:2px;} .wp-bars i{display:block; width:100%; min-height:0;} .wp-bars .a{background:#7cc4f2;} .wp-bars .b2{background:#25d366;} .wp-bars .c{background:#f6b26b;}
  .wp-leg{display:flex; gap:14px; font-size:12px; color:#6b7a73; margin-top:6px; flex-wrap:wrap;} .wp-leg i{display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:5px;}
  .wp-tb{width:100%; border-collapse:collapse; font-size:13px;} .wp-tb th{text-align:left; font-size:11px; color:#6b7a73; text-transform:uppercase; letter-spacing:.05em; padding:6px 8px; border-bottom:1px solid #e3e8e5;} .wp-tb td{padding:9px 8px; border-bottom:1px solid #f0f3f1; vertical-align:top;} .wp-tb td.n,.wp-tb th.n{text-align:right;}
  .wp-code{background:#0e1a15; color:#bff0d3; border-radius:12px; padding:12px 14px; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; word-break:break-all; line-height:1.5;}
  .wp-pk{max-height:220px; overflow-y:auto; border:1px solid #e3e8e5; border-radius:12px; padding:4px 10px;} .wp-pk label{display:flex; gap:10px; align-items:center; padding:9px 0; border-top:1px solid #f0f3f1; font-size:13.5px; cursor:pointer;} .wp-pk label:first-child{border-top:0;} .wp-pk input{width:20px; height:20px; flex:none; margin:0;}
  .wp-sq{width:58px; height:58px; border-radius:14px; display:flex; align-items:center; justify-content:center; flex:none; background:#e9eeeb; color:#4a5a52;} .wp-sq b{font-size:21px; font-weight:800; line-height:1;} .wp-sq small{font-size:12px; font-weight:700; opacity:.75;}
  .wp-sq.good{background:#dcf7e6; color:#0f6a40;} .wp-sq.mid{background:#fff3cd; color:#8a6a00;} .wp-sq.low{background:#fde6e6; color:#b42318;}
  @media (max-width:640px){ .wp-2{grid-template-columns:1fr;} .wp-grid{grid-template-columns:1fr;} .wp-btn{min-height:44px;} .wp-btn.s{min-height:38px;} .wp-in{font-size:16px !important;} .wp-tb{font-size:12.5px;} }
  @media (min-width:761px){ .wp-md{align-items:center;} .wp-mb{border-radius:20px; max-height:86vh;} }`;
  if (!document.getElementById('wpCss')) { const s = document.createElement('style'); s.id = 'wpCss'; s.textContent = CSS; document.head.appendChild(s); }

  // ---------------------------------------------------------------------------------------------------------------- helpers
  function toast(msg, bad) { const t = document.createElement('div'); t.className = 'wp-toast' + (bad ? ' bad' : ''); t.textContent = msg; document.body.appendChild(t); setTimeout(() => t.remove(), bad ? 5200 : 2600); }
  function modal(title) {
    const m = document.createElement('div'); m.className = 'wp wp-md'; m.innerHTML = '<div class="wp-mb" role="dialog"><div class="wp-mh"><b></b><button type="button" class="wp-x" aria-label="Close">✕</button></div><div class="wp-mc"></div></div>';
    m.querySelector('b').textContent = title; document.body.appendChild(m);
    const close = () => { m.remove(); document.removeEventListener('keydown', onk); }; const onk = (e) => { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', onk); m.onclick = (e) => { if (e.target === m) close(); }; m.querySelector('.wp-x').onclick = close;
    return { el: m, body: m.querySelector('.wp-mc'), close, title: (t) => { m.querySelector('b').textContent = t; } };
  }
  function sw(id, on) { return `<label class="wp-sw"><input type="checkbox" id="${id}" ${on ? 'checked' : ''}><span></span></label>`; }
  function sel(id, opts, val, cls) { return `<select class="wp-in ${cls || ''}" id="${id}">${opts.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(val) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`; }
  function upload(action, fd, onp) {
    return new Promise((res, rej) => {
      const x = new XMLHttpRequest(); x.open('POST', api.buildUrl(action)); x.setRequestHeader('Authorization', 'Bearer ' + api.token()); x.timeout = 300000;
      x.upload.onprogress = (e) => { if (e.lengthComputable && onp) onp(e.loaded / e.total); };
      x.onload = () => { let d = {}; try { d = JSON.parse(x.responseText); } catch (e) { } if (x.status >= 200 && x.status < 300 && !d.error) res(d); else rej(new Error(d.error || ('Upload failed (' + x.status + ').'))); };
      x.onerror = () => rej(new Error('Network problem — check the connection and try again.')); x.ontimeout = () => rej(new Error('It took too long. Try a smaller file.'));
      x.send(fd);
    });
  }
  // a phone photo → a square 640 px JPG (what WhatsApp wants for a profile photo)
  async function squarePhoto(f) {
    const bmp = await createImageBitmap(f), s = Math.min(bmp.width, bmp.height), cv = document.createElement('canvas'); cv.width = cv.height = Math.min(640, s);
    cv.getContext('2d').drawImage(bmp, (bmp.width - s) / 2, (bmp.height - s) / 2, s, s, 0, 0, cv.width, cv.height); if (bmp.close) bmp.close();
    return new File([await new Promise((r) => cv.toBlob(r, 'image/jpeg', 0.9))], 'profile.jpg', { type: 'image/jpeg' });
  }
  const QL = { GREEN: ['Quality: High', 'ok'], YELLOW: ['Quality: Medium', 'warn'], RED: ['Quality: Low', 'bad'], UNKNOWN: ['New — not rated yet', ''] };
  const FIL = {
    st: [['', 'Everyone'], ['waiting', 'Waiting for our reply'], ['noreply', 'Never wrote to us'], ['optout', 'Opted out']],
    ord: [['', 'Any number of orders'], ['0', 'Never ordered'], ['1', 'Ordered 1×'], ['2', 'Ordered 2×'], ['3', 'Ordered 3× or more']],
    dsl: [['', 'Any time since last order'], ['30', 'Last order within 30 days'], ['60', 'Last order 31–60 days ago'], ['90', 'Last order 61–90 days ago'], ['91', 'Last order over 90 days ago']],
    ign: [['0', 'Any'], ['2', 'Ignored 2 or more of our messages'], ['3', 'Ignored 3 or more']],
    src: [['', 'Any source'], ['query', 'Query (Meta ad)'], ['campaign', 'Campaign reply'], ['community', 'Community'], ['none', 'No source']],
    gtag: [['', 'Any tag']],
    wrote: [['', 'Any'], ['7', 'Wrote in the last 7 days'], ['30', 'Wrote in the last 30 days'], ['old', 'Wrote more than 30 days ago']],
  };
  const P = {};

  // ================================================================================================================ NUMBERS
  P.numbers = (el, ctx) => {
    el.innerHTML = loading; let D = null;
    async function load(live) {
      try { D = await api.get('wa.numbers', live ? { live: 1 } : {}); } catch (e) { el.innerHTML = errBox(e.message); return; }
      draw();
    }
    function draw() {
      const pf = D.portfolio, nums = D.numbers;
      let h = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Our WhatsApp numbers</h2><p>Photo, bio and who handles each number — and how healthy it is.</p></div><button type="button" class="wp-btn" id="nLive">Check with Meta now</button><button type="button" class="wp-btn p" id="nAdd">＋ Add a number</button></div>`;
      if (pf) h += `<div class="wp-cd"><div class="wp-kv"><span>Daily limit (shared by every number)</span><b>${pf.assumed ? 'not told by Meta' : fmt(pf.tier) + ' customers'}</b></div><div class="wp-kv"><span>Started in the last 24 h</span><b>${fmt(pf.used)}</b></div><div class="wp-hint">Meta gives the limit to the whole business account, not to each number — adding numbers does not add room. A new number also starts slowly on purpose (see “Room today”).</div></div>`;
      if (!nums.length) h += '<div class="wp-empty">No number yet. Press “Add a number”.</div>';
      h += '<div class="wp-grid">' + nums.map((n, i) => {
        const q = QL[n.quality] || QL.UNKNOWN, ok = n.status === 'CONNECTED', bar = n.cap.cap > 0 ? Math.min(100, Math.round(n.cap.used * 100 / n.cap.cap)) : 100;
        return `<div class="wp-cd"><div class="wp-row" style="flex-wrap:nowrap;align-items:flex-start"><div class="wp-av" style="background-color:${['#25d366', '#7cc4f2', '#f6b26b', '#c9a7f0'][i % 4]}">${esc((n.label || '?').charAt(0).toUpperCase())}</div><div class="wp-grow"><b style="font-size:15px">${esc(n.label)}</b>${n.is_main ? ' <span class="wp-pill">main</span>' : ''}<div class="wp-hint" style="margin:0">${esc(n.display)}${n.verified_name ? ' · ' + esc(n.verified_name) : ''}</div>
          <div><span class="wp-pill ${n.error && !n.status ? 'bad' : (ok ? 'ok' : 'bad')}">${n.error && !n.status ? 'No answer from Meta' : (ok ? 'Connected' : esc(String(n.status || '—').toLowerCase().replace(/_/g, ' ')))}</span>${n.pay_blocked ? '<span class="wp-pill bad">Payment problem — fix in Meta</span>' : ''}<span class="wp-pill ${q[1]}">${q[0]}</span>${n.name_status && !['APPROVED', 'AVAILABLE_WITHOUT_REVIEW'].includes(n.name_status) ? `<span class="wp-pill warn">Name: ${esc(n.name_status.toLowerCase().replace(/_/g, ' '))}</span>` : ''}${n.subscribed === true ? '<span class="wp-pill ok">Receiving messages</span>' : (n.subscribed === false ? '<span class="wp-pill bad">Webhook not connected</span>' : '')}${n.camp_ok ? '' : '<span class="wp-pill warn">Off for campaigns</span>'}</div></div></div>
          <div class="wp-kv"><span>Messages this week</span><b>${fmt(n.in_7d)} in · ${fmt(n.out_7d)} out</b></div>
          <div class="wp-kv"><span>Room today <small class="wp-hint">${esc(n.cap.why || '')}</small></span><b>${fmt(n.cap.left)} of ${fmt(n.cap.cap)}</b></div><div class="wp-bar"><i class="${bar >= 90 ? 'b' : (bar >= 70 ? 'w' : '')}" style="width:${Math.max(bar, 1)}%"></i></div>
          <div class="wp-kv"><span>Who handles it</span><b>${n.handler_names.length ? esc(n.handler_names.join(', ')) : 'Automatic (everyone)'}</b></div>
          <div class="wp-row" style="margin-top:10px"><button type="button" class="wp-btn p s" data-o="profile" data-i="${i}">Profile &amp; photo</button><button type="button" class="wp-btn s" data-o="people" data-i="${i}">Who handles</button><button type="button" class="wp-btn s" data-o="conn" data-i="${i}">Connection</button>${n.is_main || n.main ? '' : `<button type="button" class="wp-btn r s" data-o="del" data-i="${i}">Remove</button>`}</div></div>`;
      }).join('') + '</div></div>';
      el.innerHTML = h;
      $(el, '#nLive').onclick = function () { this.disabled = true; this.textContent = 'Asking Meta…'; load(true); };
      $(el, '#nAdd').onclick = addNumber;
      el.querySelectorAll('[data-o]').forEach((b) => { b.onclick = async () => {
        const n = D.numbers[+b.dataset.i];
        if (b.dataset.o !== 'del') { openNumber(n, b.dataset.o); return; }
        if (!confirm('Remove “' + n.label + '” from the list? (A number that already has chats cannot be removed.)')) return;
        try { await api.post('wa.number_del', { id: n.id }); toast('Removed'); load(false); } catch (e) { toast(e.message, true); }
      }; });
    }
    function addNumber() {
      const m = modal('Add a number');
      m.body.innerHTML = `<p class="wp-hint" style="margin-top:0">Meta → WhatsApp Manager → your account → Phone numbers. Copy the <b>Phone number ID</b> (about 15 digits — not the phone number itself).</p>
        <label class="wp-fl">Name for this number</label><input class="wp-in" id="aL" placeholder="e.g. TM Perfume House Two"><label class="wp-fl">Phone number ID</label><input class="wp-in" id="aP" inputmode="numeric" placeholder="digits only">
        <label class="wp-fl">WhatsApp Business Account ID <small>(only if it is a different account from the first number)</small></label><input class="wp-in" id="aW" inputmode="numeric"><div id="aE"></div><div class="wp-row" style="margin-top:14px"><button type="button" class="wp-btn p" id="aS">Add number</button></div>`;
      $(m.body, '#aS').onclick = async function () {
        this.disabled = true;
        try { await api.post('wa.number_save', { label: $(m.body, '#aL').value, phone_id: $(m.body, '#aP').value, waba_id: $(m.body, '#aW').value }); m.close(); toast('Number added. Open it → Connection → connect the webhook.'); load(false); }
        catch (e) { $(m.body, '#aE').innerHTML = errBox(e.message); this.disabled = false; }
      };
    }
    function openNumber(n, tab) {
      const m = modal(n.label); const body = m.body; let cur = tab;
      const tabs = () => `<div class="wp-seg"><button type="button" data-t="profile" class="${cur === 'profile' ? 'on' : ''}">Profile</button><button type="button" data-t="people" class="${cur === 'people' ? 'on' : ''}">Who handles</button><button type="button" data-t="conn" class="${cur === 'conn' ? 'on' : ''}">Connection</button></div>`;
      const draw = () => { body.innerHTML = tabs() + '<div id="nt"></div>'; body.querySelectorAll('.wp-seg button').forEach((b) => { b.onclick = () => { cur = b.dataset.t; draw(); }; }); ({ profile, people, conn })[cur](); };
      async function profile() {
        const box = $(body, '#nt'); box.innerHTML = loading; let d;
        try { d = await api.get('wa.num_profile', { id: n.id }); } catch (e) { box.innerHTML = errBox(e.message) + '<div class="wp-row"><button type="button" class="wp-btn" id="pR">Try again</button></div>'; $(box, '#pR').onclick = profile; return; }
        const p = d.profile, w = p.websites || [];
        box.innerHTML = `<div class="wp-row" style="flex-wrap:nowrap;align-items:center"><div class="wp-av" id="pPh" style="width:76px;height:76px;font-size:28px;${p.photo ? `background-image:url('${esc(p.photo)}')` : ''}">${p.photo ? '' : esc((n.label || '?').charAt(0).toUpperCase())}</div><div class="wp-grow"><button type="button" class="wp-btn s" id="pPick">Change photo</button><input type="file" id="pFile" accept="image/jpeg,image/png,image/*" hidden><div class="wp-hint" id="pPs">A square JPG or PNG. Phone photos are cropped and shrunk for you.</div></div></div>
          <label class="wp-fl">Display name <small>(read-only)</small></label><input class="wp-in" value="${esc(n.verified_name || '—')}" disabled><div class="wp-hint">${n.name_status && !['APPROVED', 'AVAILABLE_WITHOUT_REVIEW'].includes(n.name_status) ? 'Status at Meta: ' + esc(n.name_status.toLowerCase().replace(/_/g, ' ')) + '. ' : ''}The name comes from Meta’s approval and cannot be changed from here — it is fetched automatically.</div>
          <label class="wp-fl">Bio (About) <small id="cA"></small></label><input class="wp-in" id="pA" maxlength="139" value="${esc(p.about)}"><div class="wp-hint">The short line under the name. Up to 139 characters.</div>
          <label class="wp-fl">Description <small id="cD"></small></label><textarea class="wp-in" id="pD" maxlength="512">${esc(p.description)}</textarea>
          <label class="wp-fl">Address</label><input class="wp-in" id="pAd" maxlength="256" value="${esc(p.address)}">
          <div class="wp-2"><div><label class="wp-fl">Email</label><input class="wp-in" id="pE" type="email" value="${esc(p.email)}"></div><div><label class="wp-fl">Category</label>${sel('pV', Object.entries(d.verticals), p.vertical || 'BEAUTY')}</div></div>
          <label class="wp-fl">Website 1</label><input class="wp-in" id="pW1" placeholder="https://" value="${esc(w[0] || '')}"><label class="wp-fl">Website 2</label><input class="wp-in" id="pW2" placeholder="https://" value="${esc(w[1] || '')}">
          <div id="pErr"></div><div class="wp-row" style="margin-top:14px"><button type="button" class="wp-btn p" id="pSave">Save profile</button></div>`;
        const cnt = () => { $(box, '#cA').textContent = $(box, '#pA').value.length + '/139'; $(box, '#cD').textContent = $(box, '#pD').value.length + '/512'; }; cnt(); $(box, '#pA').oninput = cnt; $(box, '#pD').oninput = cnt;
        $(box, '#pPick').onclick = () => $(box, '#pFile').click();
        $(box, '#pFile').onchange = async function () {
          const f0 = this.files[0]; this.value = ''; if (!f0) return; const st = $(box, '#pPs'); st.textContent = 'Preparing the photo…';
          try { const f = await squarePhoto(f0); const fd = new FormData(); fd.append('id', n.id); fd.append('file', f, f.name); st.textContent = 'Uploading…'; await upload('wa.num_photo', fd, (x) => { st.textContent = 'Uploading… ' + Math.round(x * 100) + '%'; }); st.textContent = '✓ Photo changed. WhatsApp can take a minute to show it.'; $(box, '#pPh').style.backgroundImage = 'url(' + URL.createObjectURL(f) + ')'; $(box, '#pPh').textContent = ''; }
          catch (e) { st.innerHTML = '<span style="color:#b42318">' + esc(e.message) + '</span>'; }
        };
        $(box, '#pSave').onclick = async function () {
          this.disabled = true; $(box, '#pErr').innerHTML = '';
          try { await api.post('wa.num_profile_save', { id: n.id, about: $(box, '#pA').value, description: $(box, '#pD').value, address: $(box, '#pAd').value, email: $(box, '#pE').value, vertical: $(box, '#pV').value, websites: [$(box, '#pW1').value, $(box, '#pW2').value] }); toast('Profile saved at Meta ✓'); }
          catch (e) { $(box, '#pErr').innerHTML = errBox(e.message); } this.disabled = false;
        };
      }
      function people() {
        const box = $(body, '#nt'), ids = new Set(n.handlers);
        box.innerHTML = `<label class="wp-fl" style="margin-top:0">Name of this number</label><input class="wp-in" id="hL" value="${esc(n.raw_label || n.label)}">
          <label class="wp-fl">Who handles this number</label><div class="wp-hint" style="margin:0 0 6px">New chats on this number go first to the people ticked here (if they are at work and have room). Tick nobody to let the system choose from everyone.</div>
          <div class="wp-pk">${D.people.map((p) => `<label><input type="checkbox" value="${p.emp_id}" ${ids.has(p.emp_id) ? 'checked' : ''}>${esc(p.name)}</label>`).join('') || '<div class="wp-empty">No salespeople found.</div>'}</div>
          <div class="wp-kv" style="margin-top:10px"><span>Use this number for campaigns</span>${sw('hC', n.camp_ok)}</div>
          <div class="wp-kv" style="margin-top:10px"><span>📢 Every chat on this number is Campaign</span>${sw('hT', n.camp_tag)}</div><div class="wp-hint" style="margin:4px 0 0">Everyone who writes to this number gets the Campaign tag, their query is a Campaign query and their order is allocated as Campaign.</div><div id="hE"></div><div class="wp-row" style="margin-top:14px"><button type="button" class="wp-btn p" id="hS">Save</button></div>`;
        $(box, '#hS').onclick = async function () {
          this.disabled = true; const h = [...box.querySelectorAll('.wp-pk input:checked')].map((x) => +x.value);
          try { const r = await api.post('wa.num_set', { id: n.id, label: $(box, '#hL').value, camp_ok: $(box, '#hC').checked, camp_tag: $(box, '#hT').checked, handlers: h }); toast(r && r.marked ? 'Saved ✓ — ' + r.marked + ' chats on this number are now Campaign' : 'Saved ✓'); m.close(); load(false); }
          catch (e) { $(box, '#hE').innerHTML = errBox(e.message); this.disabled = false; }
        };
      }
      function conn() {
        const box = $(body, '#nt');
        box.innerHTML = `<div class="wp-cd" style="margin:0"><div class="wp-kv"><span>Phone number ID</span><b>${esc(n.phone_id)}</b></div><div class="wp-kv"><span>WhatsApp account ID</span><b>${esc(n.waba_id || '—')}</b></div><div class="wp-kv"><span>Status</span><b>${esc(n.status || '—')}</b></div></div><div id="cR"></div>
          <div class="wp-row" style="margin-top:12px"><button type="button" class="wp-btn" id="cCh">Check with Meta</button><button type="button" class="wp-btn" id="cSub">Connect the webhook</button></div>
          <label class="wp-fl">Register the number (6-digit PIN)</label><div class="wp-row"><input class="wp-in wp-grow" id="cPin" inputmode="numeric" maxlength="6" placeholder="6 digits"><button type="button" class="wp-btn" id="cReg">Register</button></div><div class="wp-hint">Only needed once for a brand-new number. Choose any 6 digits and keep them safe.</div>
          <div class="wp-row" style="margin-top:18px"><button type="button" class="wp-btn r" id="cDel">Remove this number</button></div>`;
        const out = (h) => { $(box, '#cR').innerHTML = h; };
        $(box, '#cCh').onclick = async () => { out('<div class="wp-hint">Asking Meta…</div>'); try { const d = (await api.get('wa.number_check', { id: n.id })).check; out(d.error ? errBox(d.error) : `<div class="wp-ok">${esc((d.info || {}).verified_name || 'Connected')} · ${esc((d.info || {}).status || '')} · ${d.subscribed ? 'receiving messages' : '<b>webhook NOT connected</b>'}</div>`); } catch (e) { out(errBox(e.message)); } };
        $(box, '#cSub').onclick = async () => { try { const d = await api.post('wa.subscribe', { id: n.id }); out(`<div class="wp-ok">${esc(d.message)}</div>`); } catch (e) { out(errBox(e.message)); } };
        $(box, '#cReg').onclick = async () => { try { const d = await api.post('wa.register', { id: n.id, pin: $(box, '#cPin').value }); out(`<div class="wp-ok">${esc(d.message)}</div>`); } catch (e) { out(errBox(e.message)); } };
        $(box, '#cDel').onclick = async () => { if (!confirm('Remove “' + n.label + '” from the list? (A number that already has chats cannot be removed.)')) return; try { await api.post('wa.number_del', { id: n.id }); m.close(); toast('Removed'); load(false); } catch (e) { out(errBox(e.message)); } };
      }
      draw();
    }
    load(false); return () => load(false);
  };

  // ---- add customers (Contacts / Customers screen): an Excel / CSV file or pasted lines → check (Claude fixes the rows it can) → add with tags. addCustomers(onDone)
  function addCustomers(onDone) {
  // ---- add customers: an Excel / CSV file (or pasted lines) → check (Claude fixes the rows it can) → add with tags
  const SAMPLE = 'phone,name,tags\n9876543210,Rakesh Shah,"vip, diwali"\n+91 99887 76655,Seema Patel,retail\n';
  function csvRows(text) {
    const rows = []; let row = [], cur = '', q = false; text = String(text || '').replace(/^﻿/, '');
    const first = (text.split(/\r?\n/)[0] || ''), delim = (first.split(';').length > first.split(',').length) ? ';' : (first.split('\t').length > first.split(',').length ? '\t' : ',');
    for (let i = 0; i < text.length; i++) {
      const c = text[i];
      if (q) { if (c === '"') { if (text[i + 1] === '"') { cur += '"'; i++; } else q = false; } else cur += c; }
      else if (c === '"') q = true; else if (c === delim) { row.push(cur); cur = ''; }
      else if (c === '\n' || c === '\r') { if (c === '\r' && text[i + 1] === '\n') i++; row.push(cur); cur = ''; if (row.some((x) => x.trim() !== '')) rows.push(row); row = []; }
      else cur += c;
    }
    row.push(cur); if (row.some((x) => x.trim() !== '')) rows.push(row); return rows;
  }
  // the same rules as the server (wa_imp_phone): +91 / 91 / 0 / 0091 in front, spaces, dashes, dots, '.0' from Excel, two numbers in a cell → the 10-digit mobile; never guesses
  const tenDigits = (v) => {
    v = String(v == null ? '' : v).trim(); if (!v || /\d[.,]\d+e\+?\d+/i.test(v)) return '';
    for (let p of v.split(/\s*[\/,;|&]\s*|\s+or\s+/i)) {
      p = p.trim().replace(/\.0+$/, ''); let d = p.replace(/\D/g, '');
      if (d.length === 14 && d.startsWith('0091')) d = d.slice(4); else if (d.length === 13 && d.startsWith('091')) d = d.slice(3); else if (d.length === 12 && d.startsWith('91')) d = d.slice(2); else if (d.length === 11 && d[0] === '0') d = d.slice(1);
      if (/^[6-9]\d{9}$/.test(d)) return d;
    }
    return '';
  };
  const whyBad = (v) => { v = String(v == null ? '' : v).trim(); if (!v) return 'empty'; if (/e\+/i.test(v)) return 'Excel shortened it (format the column as Text)'; const d = v.replace(/\D/g, ''); return d.length < 10 ? 'too short' : 'not an Indian mobile'; };
  let xlsxLib = null;
  const loadXlsx = () => xlsxLib || (xlsxLib = new Promise((ok, no) => { if (window.XLSX) return ok(window.XLSX); const s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'; s.onload = () => ok(window.XLSX); s.onerror = () => { xlsxLib = null; no(new Error('Could not load the Excel reader. Save the file as CSV and upload that.')); }; document.head.appendChild(s); }));
  const openAdd = (done) => {
    const m = modal('Add customers'); let R = [], hdr = false, pc = 0, nc = -1, tc = -1, fileName = '', fixed = [], fixLeft = null, allTags = '';
    const steps = (n) => `<div class="stp"><span class="d ${n >= 1 ? 'on' : ''}">1</span><span class="l">Upload</span><hr><span class="d ${n >= 2 ? 'on' : ''}">2</span><span class="l">Check</span><hr><span class="d ${n >= 3 ? 'on' : ''}">3</span><span class="l">Add</span></div>`;
    const dl = (name, text) => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + text], { type: 'text/csv' })); a.download = name; document.body.appendChild(a); a.click(); a.remove(); };
    function step1(msg) {
      m.body.innerHTML = steps(1) + `<div class="drop" id="iDrop"><div style="font-size:26px">📄</div>Choose an Excel or CSV file, or drop it here<small>Up to about 10,000 customers. Columns: <b>phone</b> (with or without +91), <b>name</b>, <b>tags</b> (optional, comma-separated). Only the first sheet is read.</small><input type="file" id="iF" accept=".xlsx,.xls,.csv,.txt,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" hidden></div>
        <div class="wp-row" style="margin:10px 0"><button type="button" class="wp-btn s" id="iSample">Download sample file</button><span class="wp-hint">or</span><button type="button" class="wp-btn s" id="iPaste">Paste numbers instead</button></div><div id="iE">${msg ? errBox(msg) : ''}</div>`;
      const drop = $(m.body, '#iDrop'), f = $(m.body, '#iF');
      drop.onclick = () => f.click(); f.onchange = () => { if (f.files[0]) read(f.files[0]); };
      drop.ondragover = (e) => { e.preventDefault(); drop.classList.add('over'); }; drop.ondragleave = () => drop.classList.remove('over');
      drop.ondrop = (e) => { e.preventDefault(); drop.classList.remove('over'); if (e.dataTransfer.files[0]) read(e.dataTransfer.files[0]); };
      $(m.body, '#iSample').onclick = () => dl('customers-sample.csv', SAMPLE);
      $(m.body, '#iPaste').onclick = () => { R = []; hdr = false; pasteStep(); };
    }
    function columns() {
      hdr = !R[0].some((c) => tenDigits(c)); const H = hdr ? R[0].map((c) => String(c).toLowerCase()) : [];
      pc = hdr ? H.findIndex((c) => /phone|mobile|whatsapp|number|contact|cell/.test(c)) : -1; nc = hdr ? H.findIndex((c) => /name|customer/.test(c)) : -1; tc = hdr ? H.findIndex((c) => /tag|label|segment|group|category|type/.test(c)) : -1;
      if (pc < 0) { let best = -1, bc = 0; (R[hdr ? 1 : 0] || []).forEach((_, ci) => { const n = R.slice(hdr ? 1 : 0, 60).filter((r) => tenDigits(r[ci])).length; if (n > bc) { bc = n; best = ci; } }); pc = best < 0 ? 0 : best; }
      if (nc < 0 && hdr && R[0].length > 1) nc = R[0].findIndex((_, ci) => ci !== pc && ci !== tc);
      fixed = []; fixLeft = null; step2();
    }
    async function read(file) {
      fileName = file.name; const isX = /\.xlsx?$/i.test(file.name);
      try {
        if (isX) { $(m.body, '#iE').innerHTML = '<div class="wp-hint">Reading the Excel file…</div>'; const X = await loadXlsx(); const wb = X.read(await file.arrayBuffer(), { type: 'array' }); const ws = wb.Sheets[wb.SheetNames[0]];
          R = X.utils.sheet_to_json(ws, { header: 1, raw: false, defval: '' }).map((r) => r.map((x) => String(x == null ? '' : x))).filter((r) => r.some((x) => x.trim() !== ''));
          // a phone stored as an Excel NUMBER shows as 9.19876E+11 when formatted: read the real value of those cells
          const raw = X.utils.sheet_to_json(ws, { header: 1, raw: true, defval: '' }).filter((r) => r.some((x) => String(x).trim() !== ''));
          R = R.map((r, i) => r.map((x, j) => (/e\+/i.test(x) && typeof (raw[i] || [])[j] === 'number' && Number.isSafeInteger(raw[i][j])) ? String(raw[i][j]) : x));
        } else R = csvRows(await file.text());
      } catch (e) { step1(e.message || 'Could not read the file.'); return; }
      if (!R.length) { step1('That file looks empty.'); return; } columns();
    }
    const rowTags = (r) => (tc >= 0 && r[tc] ? String(r[tc]).split(/[,;|]/).map((x) => x.trim()).filter(Boolean) : []);
    const rowName = (r) => (nc >= 0 && r[nc] ? String(r[nc]).replace(/[\d+()\-,;:|]+/g, ' ').replace(/\s+/g, ' ').trim() : '');
    function analyse() {
      const body = R.slice(hdr ? 1 : 0), ok = [], bad = [], seen = new Set(); let dup = 0;
      const fx = new Map(fixed.map((f) => [f.i, f]));
      body.forEach((r) => { const ph = tenDigits(r[pc]); if (!ph) { bad.push(r); return; } if (seen.has(ph)) { dup++; return; } seen.add(ph); ok.push({ phone: ph, name: rowName(r), tags: rowTags(r) }); });
      const still = []; bad.forEach((r, i) => { const f = fx.get(i); if (f && !seen.has(f.phone)) { seen.add(f.phone); ok.push({ phone: f.phone, name: f.name || rowName(r), tags: rowTags(r), fixed: true }); } else if (!f) still.push([r, i]); });
      return { ok, bad, still, dup, total: body.length, nFixed: ok.filter((x) => x.fixed).length };
    }
    function step2() {
      const cols = R[0].map((c, i) => [i, hdr ? (String(c).trim() || 'Column ' + (i + 1)) : 'Column ' + (i + 1) + ' — e.g. ' + String(c).slice(0, 14)]);
      const A = analyse(); const why = (fixLeft ? new Map(fixLeft.map((x) => [x.i, x.why])) : null);
      m.body.innerHTML = steps(2) + `<div class="wp-hint" style="margin:0 0 8px"><b>${esc(fileName)}</b> · ${fmt(A.total)} rows</div>
        <div class="cmap"><div class="a">Phone number is in</div><span></span>${sel('iPc', cols, pc)}<div class="a">Name is in</div><span></span>${sel('iNc', [[-1, '— no name —']].concat(cols), nc)}<div class="a">Tags are in</div><span></span>${sel('iTc', [[-1, '— no tags column —']].concat(cols), tc)}</div>
        <label class="wp-fl" style="margin-top:8px">Tags for everyone in this file <span class="wp-hint">(optional, comma-separated)</span></label><input class="wp-in" id="iAll" placeholder="e.g. diwali_list, surat" value="${esc(allTags)}">
        <div class="wp-hint" style="margin:4px 0 8px">Every customer also gets <b>campaign</b> and <b>import_${esc(new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: '2-digit' }).replace(/\s/g, '').toLowerCase())}</b>, so a campaign can pick exactly this file.</div>
        <div class="wp-ok"><b>${fmt(A.ok.length)}</b> ready to add${A.nFixed ? ' (' + fmt(A.nFixed) + ' fixed by Claude ✨)' : ''}${A.dup ? ' · ' + fmt(A.dup) + ' repeated in the file (merged)' : ''}${A.still.length ? ' · <b>' + fmt(A.still.length) + '</b> rows without a valid Indian mobile' : ''}</div>
        ${A.still.length ? `<div style="max-height:150px;overflow:auto;border:1px solid #e3e8ef;border-radius:8px;margin:6px 0;font-size:12.5px">${A.still.slice(0, 40).map(([r, i]) => `<div style="padding:4px 8px;border-bottom:1px solid #f0f2f5"><b>${esc(String(r[pc] || '—'))}</b> ${esc(rowName(r))} <span class="wp-hint">· ${esc((why && why.get(i)) || whyBad(r[pc]))}</span></div>`).join('')}${A.still.length > 40 ? '<div class="wp-hint" style="padding:4px 8px">…and ' + fmt(A.still.length - 40) + ' more</div>' : ''}</div>
        <div class="wp-row">${fixLeft ? '' : `<button type="button" class="wp-btn s" id="iFix">✨ Let Claude fix these rows</button>`}<button type="button" class="wp-btn s" id="iBad">Download the rows left out</button></div>` : ''}
        <label style="display:flex;gap:10px;align-items:flex-start;margin:12px 0;font-size:13px;line-height:1.45"><input type="checkbox" id="iC" style="width:20px;height:20px;margin:0;flex:none"> These customers agreed to receive WhatsApp messages from us.</label><div id="iE"></div>
        <div class="wp-row"><button type="button" class="wp-btn" id="iBack">‹ Back</button><button type="button" class="wp-btn p" id="iGo" ${A.ok.length ? '' : 'disabled'}>Add ${fmt(A.ok.length)} customers</button></div>`;
      const keep = () => { allTags = $(m.body, '#iAll').value; };
      $(m.body, '#iPc').onchange = function () { keep(); pc = +this.value; fixed = []; fixLeft = null; step2(); }; $(m.body, '#iNc').onchange = function () { keep(); nc = +this.value; step2(); }; $(m.body, '#iTc').onchange = function () { keep(); tc = +this.value; step2(); };
      $(m.body, '#iBack').onclick = () => step1(); const bb = $(m.body, '#iBad'); if (bb) bb.onclick = () => dl('rows-left-out.csv', 'why,row\n' + A.still.map(([r, i]) => '"' + [(why && why.get(i)) || whyBad(r[pc])].concat(r).map((x) => String(x).replace(/"/g, '""')).join('","') + '"').join('\n'));
      const fb = $(m.body, '#iFix'); if (fb) fb.onclick = async () => { keep(); fb.disabled = true; fb.textContent = 'Claude is checking ' + Math.min(150, A.bad.length) + ' rows…';
        try { const d = await api.post('wa.contact_fix', { rows: A.bad.slice(0, 150) }); fixed = d.fixed || []; fixLeft = d.left || []; step2(); }
        catch (e) { $(m.body, '#iE').innerHTML = errBox(e.message); fb.disabled = false; fb.textContent = '✨ Let Claude fix these rows'; } };
      $(m.body, '#iGo').onclick = () => { keep(); send({ rows: A.ok.map(({ phone, name, tags }) => ({ phone, name, tags })), tags: allTags }, $(m.body, '#iC').checked); };
    }
    function pasteStep() {
      m.body.innerHTML = steps(1) + `<p class="wp-hint" style="margin-top:0">One customer per line — a phone number, with a name if you like. Example: <b>Rakesh Shah +91 98765 43210</b></p><textarea class="wp-in" id="iT" rows="8" placeholder="9876543210&#10;Seema 99887 76655"></textarea>
        <label class="wp-fl" style="margin-top:8px">Tags for everyone <span class="wp-hint">(optional)</span></label><input class="wp-in" id="iAll" placeholder="e.g. diwali_list">
        <label style="display:flex;gap:10px;align-items:flex-start;margin:12px 0;font-size:13px;line-height:1.45"><input type="checkbox" id="iC" style="width:20px;height:20px;margin:0;flex:none"> These customers agreed to receive WhatsApp messages from us.</label><div id="iE"></div><div class="wp-row"><button type="button" class="wp-btn" id="iBack">‹ Back</button><button type="button" class="wp-btn p" id="iGo">Add</button></div>`;
      $(m.body, '#iBack').onclick = () => step1(); $(m.body, '#iGo').onclick = () => send({ text: $(m.body, '#iT').value, tags: $(m.body, '#iAll').value }, $(m.body, '#iC').checked);
    }
    async function send(payload, consent) {
      const btn = $(m.body, '#iGo'); btn.disabled = true; btn.textContent = 'Adding…';
      try { const d = await api.post('wa.contact_import', Object.assign({ consent }, payload));
        m.body.innerHTML = steps(3) + `<div class="wp-ok"><b>${fmt(d.added)}</b> added · ${fmt(d.already)} already in the list (tags added to them too) · ${fmt(d.invalid)} not valid Indian mobile numbers</div>
          <div class="wp-hint" style="margin:8px 0">Tagged: ${(d.tags || []).map((t) => '<b>' + esc(t) + '</b>').join(', ')}. To message exactly these people: <b>Campaigns → New → Who gets it → tag “${esc(d.batch_tag || '')}”</b>. Opted-out customers are left out by the campaign automatically.</div>
          <div class="wp-row"><button type="button" class="wp-btn p" id="iD">Done</button></div>`;
        $(m.body, '#iD').onclick = () => { m.close(); if (done) done(); }; }
      catch (e) { const bx = $(m.body, '#iE'); if (bx) bx.innerHTML = errBox(e.message); btn.disabled = false; btn.textContent = 'Try again'; }
    }
    step1();
  };
    openAdd(onDone);
  }
  // ================================================================================================================ CONTACTS
  P.contacts = (el, ctx) => {
    if (window.WaCust) return WaCust.mount(el, ctx, { addCustomers, util: { esc, $, fmt, money, pretty, when, loading, errBox, toast, modal, sel } });   // the Customers screen (wa_cust_ui.js): date range first, tags, pages, select, bulk actions
    const S = { q: '', st: '', ord: '', dsl: '', ign: '0', src: '', wrote: '', off: 0, rows: [], total: 0, all: 0, busy: false };
    el.innerHTML = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Contacts</h2><p>Every customer, all numbers together. One person = one row, however many of your numbers they use.</p></div><button type="button" class="wp-btn" id="kExp">Download list</button><button type="button" class="wp-btn p" id="kAdd">＋ Add customers</button></div>
      <div class="wp-cd"><input class="wp-in" id="kQ" type="search" placeholder="Search name or number…"><div class="wp-row" style="margin-top:10px" id="kChips"></div>
        <div class="wp-hint" style="margin:10px 0 4px"><b>Tags</b> — tap one to see only those customers</div><div class="wp-row" id="kTags" style="gap:6px"><span class="wp-hint">Loading tags…</span></div>
        <div class="wp-2" style="margin-top:10px"><div><div class="wp-hint" style="margin:0 0 2px"><b>Last wrote</b> between</div><div class="wp-row" style="flex-wrap:nowrap;gap:6px"><input class="wp-in" type="date" id="kIf"><input class="wp-in" type="date" id="kIt"></div></div><div><div class="wp-hint" style="margin:0 0 2px"><b>Added / first message</b> between</div><div class="wp-row" style="flex-wrap:nowrap;gap:6px"><input class="wp-in" type="date" id="kAf"><input class="wp-in" type="date" id="kAt"></div></div></div>
        <details style="margin-top:10px"><summary style="cursor:pointer;font-weight:700;font-size:13px">More filters</summary><div class="wp-2" style="margin-top:8px"><div>${sel('kOrd', FIL.ord, '')}</div><div>${sel('kDsl', FIL.dsl, '')}</div><div>${sel('kIgn', FIL.ign, '0')}</div><div>${sel('kSrc', FIL.src, '')}</div><div>${sel('kWr', FIL.wrote, '')}</div><div>${sel('kTg', FIL.gtag, '')}</div></div></details></div>
      <div id="kInfo" class="wp-hint" style="margin:0 2px 8px"></div><div id="kList"></div><div class="wp-row" style="justify-content:center;margin:10px 0"><button type="button" class="wp-btn" id="kMore" hidden>Show more</button></div></div>`;
    const chips = () => { $(el, '#kChips').innerHTML = FIL.st.map(([v, l]) => `<button type="button" class="wp-chip ${S.st === v ? 'on' : ''}" data-v="${v}">${l}</button>`).join(''); $(el, '#kChips').querySelectorAll('button').forEach((b) => { b.onclick = () => { S.st = b.dataset.v; chips(); load(true); }; }); };
    const tagsOf = (r) => {
      let h = r.orders === 0 ? '<span class="wp-pill">No order yet</span>' : `<span class="wp-pill ${r.orders >= 3 ? 'p' : (r.orders === 2 ? 'b' : 'ok')}">Ordered ${r.orders}×</span>`;
      if (r.days != null) h += `<span class="wp-pill">${r.days === 0 ? 'Ordered today' : 'Last order ' + r.days + ' d ago'}</span>`;
      if (r.ignored >= 2) h += `<span class="wp-pill warn">Ignored ${r.ignored} messages</span>`; if (r.optout) h += '<span class="wp-pill bad">Opted out</span>';
      const src = { query: ['Query', 'o'], campaign: ['Campaign', 'b'], community: ['Community', 'ok'] }[r.source]; if (src) h += `<span class="wp-pill ${src[1]}">${src[0]}</span>`;
      if (r.assigned_name) h += `<span class="wp-pill">With ${esc(r.assigned_name)}</span>`;
      (r.tags || []).forEach((t) => { h += `<span class="wp-pill ${/_query$/.test(t.k) ? 'o' : t.k === 'campaign' || /^import_/.test(t.k) ? 'b' : ''}" title="Tag">🏷 ${esc(t.t)}</span>`; }); return h;
    };
    function draw() {
      const L = $(el, '#kList');
      L.innerHTML = S.rows.length ? S.rows.map((r, i) => `<div class="wp-cd" style="padding:12px 14px"><div class="wp-row" style="flex-wrap:nowrap"><div class="wp-grow"><b>${esc(r.name || pretty(r.phone))}</b>${r.name ? `<span class="wp-hint"> · ${esc(pretty(r.phone))}</span>` : ''}<div>${tagsOf(r)}</div><div class="wp-hint">${r.last_in_at ? 'Last wrote ' + esc(when(r.last_in_at)) : 'Has not written to us'}${r.last_number ? ' · via ' + esc(r.last_number) : ''}${r.spent ? ' · spent ' + money(r.spent) : ''}</div></div>${r.known && r.last_in_at ? `<button type="button" class="wp-btn s" data-i="${i}">Open chat</button>` : ''}</div></div>`).join('') : '<div class="wp-empty">Nobody matches these filters.</div>';
      L.querySelectorAll('[data-i]').forEach((b) => { b.onclick = () => ctx.openChat(S.rows[+b.dataset.i].phone); });
      $(el, '#kInfo').textContent = fmt(S.total) + ' customer' + (S.total === 1 ? '' : 's') + (S.total !== S.all ? ' match (of ' + fmt(S.all) + ')' : ' in total'); $(el, '#kMore').hidden = S.rows.length >= S.total;
    }
    if (window.WaGrowth && WaGrowth.tagOptions) WaGrowth.tagOptions().then((o) => { const x = $(el, '#kTg'); if (x) x.innerHTML = o.map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join(''); }).catch(() => { });
    const params = () => ({ q: S.q, st: S.st, ord: S.ord, dsl: S.dsl, ign: S.ign, src: S.src, wrote: S.wrote, gtag: S.gtag || '', in_from: S.in_from || '', in_to: S.in_to || '', added_from: S.added_from || '', added_to: S.added_to || '' });
    let TAGS = [];
    const tagBar = () => { const x = $(el, '#kTags'); if (!x) return; x.innerHTML = TAGS.length ? TAGS.map((t) => `<button type="button" class="wp-chip ${S.gtag === t.k ? 'on' : ''}" data-tg="${esc(t.k)}">${esc(t.t)} ${fmt(t.all)}</button>`).join('') : '<span class="wp-hint">No tags yet — import a file with a tags column, or tags appear as chats come in.</span>';
      x.querySelectorAll('[data-tg]').forEach((b) => { b.onclick = () => { S.gtag = S.gtag === b.dataset.tg ? '' : b.dataset.tg; const sl = $(el, '#kTg'); if (sl) sl.value = S.gtag; tagBar(); load(true); }; }); };
    api.get('wa.g_tags').then((d) => { TAGS = (d.tags || []).filter((t) => t.all > 0); tagBar(); }).catch(() => { TAGS = []; tagBar(); });
    [['kIf', 'in_from'], ['kIt', 'in_to'], ['kAf', 'added_from'], ['kAt', 'added_to']].forEach(([id, k]) => { $(el, '#' + id).onchange = function () { S[k] = this.value; load(true); }; });
    async function load(reset) {
      if (S.busy) return; S.busy = true; if (reset) { S.off = 0; S.rows = []; $(el, '#kList').innerHTML = loading; }
      try { const d = await api.get('wa.contacts', Object.assign(params(), { off: S.off, lim: 50 })); S.rows = S.rows.concat(d.contacts); S.total = d.total; S.all = d.all; S.off = S.rows.length; draw(); }
      catch (e) { $(el, '#kList').innerHTML = errBox(e.message); } S.busy = false;
    }
    let t = null; $(el, '#kQ').oninput = function () { clearTimeout(t); const v = this.value.trim(); t = setTimeout(() => { S.q = v; load(true); }, 300); };
    [['kOrd', 'ord'], ['kDsl', 'dsl'], ['kIgn', 'ign'], ['kSrc', 'src'], ['kWr', 'wrote'], ['kTg', 'gtag']].forEach(([id, k]) => { $(el, '#' + id).onchange = function () { S[k] = this.value; load(true); }; });
    $(el, '#kMore').onclick = () => load(false);
    $(el, '#kExp').onclick = async function () {
      this.disabled = true; this.textContent = 'Preparing…'; const rows = []; let off = 0;
      try { for (let i = 0; i < 60; i++) { const d = await api.get('wa.contacts', Object.assign(params(), { off, lim: 100 })); rows.push(...d.contacts); off += d.contacts.length; if (!d.contacts.length || off >= d.total) break; }
        const q = (v) => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
        const csv = ['Phone,Name,Orders,Last order,Days since last order,Total spent,Ignored messages,Opted out,Source,Handled by,Last number'].concat(rows.map((r) => [r.phone, r.name, r.orders, r.last_order, r.days == null ? '' : r.days, r.spent, r.ignored, r.optout ? 'yes' : '', r.source, r.assigned_name, r.last_number].map(q).join(','))).join('\n');
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' })); a.download = 'whatsapp-contacts.csv'; document.body.appendChild(a); a.click(); a.remove(); }
      catch (e) { toast(e.message, true); } this.disabled = false; this.textContent = 'Download list';
    };
    $(el, '#kAdd').onclick = () => addCustomers(() => load(true));
    chips(); load(true); return () => load(true);
  };

  // ================================================================================================================ PRE-MADE MESSAGES
  // (P.premade removed: replaced by a newer screen or no longer reachable from the menu)

  // ================================================================================================================ MEDIA LIBRARY
  P.media = (el, ctx) => {
    let items = [], can = false; const URLS = {};
    const url = async (id) => { if (URLS[id]) return URLS[id]; const r = await fetch(api.buildUrl('wa.lib_file', { id }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (!r.ok) throw new Error('Could not load the file.'); return (URLS[id] = URL.createObjectURL(await r.blob())); };
    el.innerHTML = loading;
    async function load() { try { const d = await api.get('wa.lib_list'); items = d.items || []; can = !!d.can_edit; } catch (e) { el.innerHTML = errBox(e.message); return; } draw(); }
    function draw() {
      el.innerHTML = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Media library</h2><p>Photos, videos and PDFs saved once. In any chat tap 📎 → “From the library” to send one.</p></div><button type="button" class="wp-btn" id="mShr" title="Make every saved photo smaller so the library uses less space">🗜 Make photos smaller</button><button type="button" class="wp-btn p" id="mAdd">＋ Add files</button><input type="file" id="mF" multiple hidden accept="image/jpeg,image/png,video/mp4,video/3gpp,application/pdf,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt"></div>
        <div class="wp-note">WhatsApp takes photos as JPG/PNG up to 5 MB, videos as MP4 up to 16 MB and documents up to 100 MB. Meta keeps its own copy for only 30 days — we keep the original here and send it again automatically.</div><div id="mUp"></div>
        ${items.length ? '<div class="wp-lib">' + items.map((x) => `<div class="wp-li"><div class="im" data-id="${x.id}" data-k="${x.kind}">${x.kind === 'image' ? '' : (x.kind === 'video' ? '🎬' : '📄')}</div><div class="nm">${esc(x.title)}</div><div class="mt">${esc(x.kind)} · ${(x.size / 1048576).toFixed(1)} MB</div><div class="ac"><button type="button" class="wp-btn s" data-r="${x.id}">Rename</button><button type="button" class="wp-btn s r" data-d="${x.id}">Delete</button></div></div>`).join('') + '</div>' : '<div class="wp-empty">The library is empty. Press “Add files”.</div>'}</div>`;
      el.querySelectorAll('.im[data-k="image"]').forEach(async (e) => { try { e.style.backgroundImage = 'url(' + (await url(e.dataset.id)) + ')'; } catch (x) { e.textContent = '🖼️'; } });
      el.querySelectorAll('.im').forEach((e) => { e.onclick = async () => { const x = items.find((i) => i.id === +e.dataset.id); try { const u = await url(x.id); if (x.kind === 'image') { const m = modal(x.title); m.body.innerHTML = `<img src="${u}" alt="" style="max-width:100%;border-radius:12px">`; } else if (x.kind === 'video') { const m = modal(x.title); m.body.innerHTML = `<video controls src="${u}" style="max-width:100%;border-radius:12px"></video>`; } else { const a = document.createElement('a'); a.href = u; a.download = x.filename; document.body.appendChild(a); a.click(); a.remove(); } } catch (er) { toast(er.message, true); } }; });
      el.querySelectorAll('[data-r]').forEach((b) => { b.onclick = async () => { const x = items.find((i) => i.id === +b.dataset.r), t = prompt('Name', x.title); if (t == null || !t.trim()) return; try { await api.post('wa.lib_rename', { id: x.id, title: t.trim() }); load(); } catch (e) { toast(e.message, true); } }; });
      el.querySelectorAll('[data-d]').forEach((b) => { b.onclick = async () => { const x = items.find((i) => i.id === +b.dataset.d); if (!confirm('Delete “' + x.title + '” from the library?')) return; try { await api.post('wa.lib_del', { id: x.id }); load(); } catch (e) { toast(e.message, true); } }; });
      $(el, '#mAdd').onclick = () => $(el, '#mF').click();
      $(el, '#mShr').onclick = async (ev) => { const bt = ev.currentTarget; bt.disabled = true; bt.textContent = 'Working…'; try { const r = await api.post('wa.lib_shrink', {}); toast(r.message || 'Done'); load(); } catch (e) { toast(e.message, true); bt.disabled = false; bt.textContent = '🗜 Make photos smaller'; } };
      $(el, '#mF').onchange = async function () {
        const files = [...this.files]; this.value = ''; const box = $(el, '#mUp'); let errs = [];
        for (let i = 0; i < files.length; i++) {
          let f = files[i]; box.innerHTML = `<div class="wp-hint">Uploading ${i + 1} of ${files.length}: ${esc(f.name)}…</div>`;
          try { if (/^image\//.test(f.type) && (f.size > 4.5 * 1048576 || !/jpeg|png/.test(f.type))) { const bmp = await createImageBitmap(f); const sc = Math.min(1, 1920 / Math.max(bmp.width, bmp.height)); const cv = document.createElement('canvas'); cv.width = Math.round(bmp.width * sc); cv.height = Math.round(bmp.height * sc); cv.getContext('2d').drawImage(bmp, 0, 0, cv.width, cv.height); f = new File([await new Promise((r) => cv.toBlob(r, 'image/jpeg', 0.85))], f.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }); }
            const fd = new FormData(); fd.append('file', f, f.name); fd.append('title', f.name.replace(/\.\w+$/, '').replace(/[_-]+/g, ' ')); await upload('wa.lib_add', fd, (p) => { box.innerHTML = `<div class="wp-hint">Uploading ${i + 1} of ${files.length}: ${esc(f.name)} · ${Math.round(p * 100)}%</div>`; }); }
          catch (e) { errs.push(f.name + ': ' + e.message); }
        }
        box.innerHTML = errs.length ? errBox(errs.join('\n')) : ''; load();
      };
    }
    load(); return load;
  };

  // ================================================================================================================ FOLLOW-UPS
  const classicFu = (el, ctx) => {
    let D = null; el.innerHTML = loading;
    const label = (m) => m % 1440 === 0 ? (m / 1440) + (m / 1440 === 1 ? ' day' : ' days') : (m % 60 === 0 ? (m / 60) + (m / 60 === 1 ? ' hour' : ' hours') : m + ' minutes');
    async function load() { try { D = await api.get('wa.fu_list'); } catch (e) { el.innerHTML = errBox(e.message); return; } draw(); }
    function draw() {
      const rules = D.rules, eng = D.engine;
      let h = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Follow-ups</h2><p>If a customer goes quiet after we answered, send an approved pre-made message automatically.</p></div><button type="button" class="wp-btn p" id="fAdd">＋ Add a step</button></div>
        ${eng.cron_ok ? '' : '<div class="wp-note"><b>The sending engine is not running by itself yet.</b> Follow-ups are sent by it. Set it up once in <b>Team &amp; routing → Engine</b> (a one-line Hostinger cron job).</div>'}
        <div class="wp-cd"><div style="line-height:1.55;font-size:13px">A step sends when <b>the customer’s last message is older than the time you set</b>, a person has answered it, the customer has not written again, has not ordered since, has not opted out, and the chat is not closed. If several steps are due at once, only the latest one is sent. Each step is sent once per quiet period.<br><span style="color:#6b7a73">Each message costs like a marketing message. Only messages with one variable (the name) can be used.</span></div></div>`;
      if (!rules.length) h += '<div class="wp-cd"><div class="wp-empty">No steps yet.<br><br><button type="button" class="wp-btn p" id="fPre">Create the 5 standard steps (2 h · 24 h · 48 h · 30 d · 60 d)</button></div></div>';
      h += rules.map((r, i) => `<div class="wp-cd" data-i="${i}"><h3>${esc(r.name)}<span class="sp"></span>${sw('fA' + i, r.active)}</h3>
        <div class="wp-2"><div><label class="wp-fl" style="margin-top:0">Wait (minutes)</label><input class="wp-in" id="fM${i}" type="number" min="30" value="${r.after_min}"><div class="wp-hint">= ${label(r.after_min)}</div></div><div><label class="wp-fl" style="margin-top:0">Only chats tagged</label>${sel('fT' + i, [['', 'Any']].concat(Object.entries(D.tags)), r.tag)}</div></div>
        <label class="wp-fl">Message to send</label>${sel('fP' + i, [['0', '— choose a message —']].concat(D.templates.map((t) => [t.id, t.name.replace(/_/g, ' ')])), r.tpl_id)}<div class="wp-bub" id="fB${i}" ${r.tpl_id ? '' : 'hidden'}></div>
        <div class="wp-hint">Sent ${r.counts.sent} · skipped ${r.counts.skipped} · failed ${r.counts.failed}</div><div id="fE${i}"></div><div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn p s" data-s="${i}">Save</button><button type="button" class="wp-btn r s" data-x="${i}">Delete</button></div></div>`).join('');
      if (D.recent.length) h += `<div class="wp-cd"><h3>Recently sent</h3>${D.recent.map((x) => `<div class="wp-kv"><span>${esc(x.name || pretty(x.phone))}</span><b>${esc(x.status)} · ${esc(when(x.sent_at))}</b></div>`).join('')}</div>`;
      el.innerHTML = h + '</div>';
      const pre = $(el, '#fPre'); if (pre) pre.onclick = async () => { try { await api.post('wa.fu_preset', {}); load(); } catch (e) { toast(e.message, true); } };
      rules.forEach((r, i) => { const pv = () => { const t = D.templates.find((x) => x.id === +$(el, '#fP' + i).value), b = $(el, '#fB' + i); b.hidden = !t; if (t) b.textContent = fill(t.body, ['Rakesh']); }; $(el, '#fP' + i).onchange = pv; pv(); });
      el.querySelectorAll('[data-s]').forEach((b) => { b.onclick = async () => { const i = +b.dataset.s, r = rules[i]; b.disabled = true; try { await api.post('wa.fu_save', { id: r.id, name: r.name, after_min: +$(el, '#fM' + i).value, tpl_id: +$(el, '#fP' + i).value, active: $(el, '#fA' + i).checked, tag: $(el, '#fT' + i).value }); toast('Saved ✓'); load(); } catch (e) { $(el, '#fE' + i).innerHTML = errBox(e.message); b.disabled = false; } }; });
      el.querySelectorAll('[data-x]').forEach((b) => { b.onclick = async () => { if (!confirm('Delete this step?')) return; try { await api.post('wa.fu_del', { id: rules[+b.dataset.x].id }); load(); } catch (e) { toast(e.message, true); } }; });
      $(el, '#fAdd').onclick = async () => { try { await api.post('wa.fu_save', { name: 'New step', after_min: 1440, tpl_id: 0, active: false }); load(); } catch (e) { toast(e.message, true); } };
    }
    load(); return load;
  };

  // ================================================================================================================ FOLLOW-UPS (live plan · after-24-hours ladder · classic steps)
  const FU_MODES = [['off', 'Off', 'Nothing is prepared or sent.'], ['suggest', 'Suggest only', 'The bot prepares each follow-up and shows it inside the chat. A person sends it. Nothing goes by itself.'],
    ['auto24', 'Auto inside 24 h', 'Follow-ups that fall inside the 24-hour window go by themselves. After 24 hours the bot only suggests.'], ['autotpl', 'Auto + templates', 'Inside 24 hours a normal message goes by itself; after 24 hours an approved template from the ladder goes. Chats a person is handling only get suggestions.']];
  const TEMPC = { hot: ['hot', 'bad'], warm: ['warm', 'warn'], cold: ['cold', 'b'] };
  const inT = (m) => (m <= 0 ? 'now' : m < 60 ? m + ' min' : m < 1440 ? (Math.round(m / 6) / 10) + ' h' : (Math.round(m / 144) / 10) + ' d');
  const TST = { approved: ['Approved', 'ok'], partial: ['Approved (1 account)', 'ok'], pending: ['Pending at Meta', 'warn'], rejected: ['Rejected', 'bad'], paused: ['Paused', 'warn'], draft: ['Draft', 'b'], missing: ['Not at Meta', 'warn'] };
  // ⏱ ONE Journey: the Timeline (jny_ui.js) is the plan; the rest of the tabs are its own screens (wj_ui.js) and the order messages.
  P.followups = (el, ctx) => {
    const T = [['jtimeline', '⏱ Timeline'], ['wjclaude', '🧠 Claude'], ['wjtopics', '💡 Topics'], ['wjfest', '🪔 Festivals'], ['wjresults', '📊 Results'], ['orderupd', '📦 Order updates'], ['afterdel', '⭐ After delivery'], ['birthdays', '🎂 Birthdays']]; let cur = 'jtimeline'; try { const sv = localStorage.getItem('wa_fu_sub2'); cur = T.some((x) => x[0] === sv) ? sv : 'jtimeline'; } catch (e) { /* private mode */ }
    el.innerHTML = '<div id="fuOneK"></div><div id="fuOneT" style="display:flex;gap:6px;margin:0 0 10px;flex-wrap:wrap;align-items:center"></div><div id="fuOneX"></div>';
    // 🎯 THE number that matters: orders that came from follow-ups (Autopilot, Journey, and chats people won through Rescue)
    const KP = el.querySelector('#fuOneK'); let kd = 30;
    const inr = (n) => '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN');
    const loadK = async () => {
      KP.innerHTML = '<div class="wp-card" style="margin-bottom:10px"><div class="wp-sub">Counting orders from follow-ups…</div></div>';
      const g = async (a, p) => { try { return await api.get(a, p); } catch (e) { return null; } };
      const [ap, wj, rs] = await Promise.all([g('fu.ap_results', { days: kd }), g('fu.wj_results', { days: kd }), g('wq.rescue_list', { days: kd, status: 'won' })]);
      const t = (r) => (r && r.total) || {}; const A = t(ap), J = t(wj), R = (rs && rs.summary) || {};
      const tile = (ic, name, n, s, sub) => '<div style="flex:1 1 150px;border:1px solid var(--line,#e6e8ef);border-radius:12px;padding:10px 12px;background:#fff"><div style="font-size:12px;color:#64748b;font-weight:700">' + ic + ' ' + name + '</div><div style="font-size:24px;font-weight:800;letter-spacing:-.02em">' + (n == null ? '—' : n) + ' <span style="font-size:13px;font-weight:700;color:#64748b">orders</span></div><div style="font-size:12.5px;color:#475569">' + (s == null ? '' : inr(s) + ' · ') + sub + '</div></div>';
      KP.innerHTML = '<div class="wp-card" style="margin-bottom:10px"><div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px"><b style="font-size:15px">🎯 Orders from follow-ups</b><span style="flex:1"></span><button type="button" id="fuKnows" class="wp-btn" title="What Claude reads before it writes a message">🧠 What Claude knows</button>'
        + [7, 30, 90].map((d) => '<button type="button" data-kd="' + d + '" class="wp-btn ' + (kd === d ? 'p' : '') + '">' + d + ' days</button>').join('') + '</div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap">' + tile('🔁', 'Autopilot (days 1–10)', ap ? (A.ordered || 0) : null, ap ? A.sales : null, (A.sent || 0) + ' messages sent')
        + tile('🗓', 'Journey (days 11–60)', wj ? (J.ordered || 0) : null, wj ? J.sales : null, (J.sent || 0) + ' messages sent') + tile('🛟', 'Rescue (people)', rs ? (R.won || 0) : null, rs ? R.revenue : null, 'chats won after a person took them') + '</div>'
        + '<div class="wp-sub" style="margin-top:6px">An order counts for the last follow-up sent in the 7 days before it. Rescue counts orders after a person took the chat. The same order can show in two boxes, so I do not add them up.</div></div>';
      KP.querySelectorAll('[data-kd]').forEach((b) => { b.onclick = () => { kd = +b.dataset.kd; loadK(); }; });
      const kb = KP.querySelector('#fuKnows'); if (kb) kb.onclick = async () => {
        const m = modal('🧠 What Claude knows when it writes'); m.body.innerHTML = loading;
        try {
          const d = await api.get('wq.rescue_knows'), p = d.parts || {}, ok = (v) => v ? '✅' : '⚠️ nothing yet';
          m.body.innerHTML = '<div class="wp-sub" style="margin-bottom:8px">Every message Claude writes (follow-ups, offers, Journey, Rescue drafts) reads the same things. Change them in Teach — all writers change together.</div>'
            + '<div class="wp-kv"><span>📚 Facts, price chart &amp; selling rules (Teach)</span><b>' + ok(p.facts) + '</b></div>'
            + '<div class="wp-kv"><span>🚧 Limits (lowest price, max discount…)</span><b>' + ok(p.limits) + '</b></div>'
            + '<div class="wp-kv"><span>🎓 Lessons you taught (' + fmt(d.lessons_total) + ' in all)</span><b>' + (p.lessons ? p.lessons + ' used for a typical chat' : '⚠️ none') + '</b></div>'
            + '<div class="wp-kv"><span>📖 Live guidebook</span><b>' + (d.guidebook ? 'v' + d.guidebook.version + ' · ' + esc(d.guidebook.source) + (d.guidebook.at ? ' · ' + esc(String(d.guidebook.at).slice(0, 10)) : '') : '⚠️ no live guidebook') + '</b></div>'
            + '<div class="wp-kv"><span>🔢 Numbers a message may state</span><b>' + fmt(d.numbers) + '</b></div>'
            + '<h3 style="font-size:14px;margin:12px 0 4px">Who reads it</h3>' + (d.writers || []).map((w) => '<div class="wp-kv"><span>' + esc(w[0]) + '</span><b>' + (w[1] ? '✅ connected' : '⚠️ not connected') + '</b></div>').join('')
            + '<h3 style="font-size:14px;margin:12px 0 4px">What it reads (the start)</h3><pre style="white-space:pre-wrap;font-size:12px;max-height:260px;overflow:auto;background:#f8fafc;border-radius:8px;padding:8px">' + esc(d.preview || '(nothing)') + '</pre>';
        } catch (e) { m.body.innerHTML = errBox(e.message); }
      };
    };
    loadK();
    const X = el.querySelector('#fuOneX'), TT = el.querySelector('#fuOneT'), re = {};
    const show = () => {
      if (re[cur] !== undefined) return; re[cur] = null;
      { const hb = document.createElement('div'); hb.dataset.x = cur; X.appendChild(hb); re[cur] = P[cur] ? P[cur](hb, ctx || {}) : (hb.innerHTML = '<div class="wp-sub">This screen could not load. Refresh the page.</div>'); }
    };
    const draw = () => {
      TT.innerHTML = T.map(([k, l]) => '<button type="button" data-s="' + k + '" class="wp-btn ' + (cur === k ? 'p' : '') + '">' + l + '</button>').join('') + '<span class="wp-sub" style="margin-left:6px">One Journey for every customer: the Timeline shows every step from the first minutes to day 60 — and the order, delivery and birthday messages sit here too.</span>';
      X.querySelectorAll('[data-x]').forEach((d) => { d.hidden = d.dataset.x !== cur; }); show();
    };
    TT.onclick = (e) => { const b = e.target.closest('[data-s]'); if (!b) return; cur = b.dataset.s; try { localStorage.setItem('wa_fu_sub2', cur); } catch (x) { /* private mode */ } draw(); };
    draw();
    return () => { const f = re[cur]; if (typeof f === 'function') f(); };
  };
  // 🧭 Automatic messages live in ONE place each (no second editor here): follow-ups → Automation → Follow-ups · complaints → Complaints → Settings
  P.automsgs = (el) => { el.innerHTML = '<div class="wp-card"><h3 style="margin:0 0 6px">Automatic messages</h3><div class="wp-sub" style="margin-bottom:10px">Each automatic message has one home, so there is never a second copy to keep in step.</div>'
      + '<div class="wp-row" style="flex-wrap:wrap;gap:8px"><a class="wp-btn p" href="whatsapp.html#followups" target="_top">🔁 Follow-up &amp; offer messages</a><a class="wp-btn p" href="complaints.html?tab=settings&amp;go=msgs" target="_top">🎫 Complaint messages (photo &amp; words)</a><a class="wp-btn" href="whatsapp.html#orderupd" target="_top">📦 Order update messages</a></div></div>'; };
  P.fumsgs = P.automsgs; P.cxmsgs = P.automsgs;   // old saved tabs open the same page
  P.team = (el, ctx) => {
    let D = null; el.innerHTML = loading;
    async function load() { try { D = await api.get('wa.team'); } catch (e) { el.innerHTML = errBox(e.message); return; } draw(); }
    function draw() {
      const c = D.cfg, al = new Set(c.alert_emps);
      el.innerHTML = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Team &amp; routing</h2><p>Who gets which chat, how fast they must react, and the engine that runs it.</p></div></div>
        <div class="wp-cd"><h3>Giving chats to people</h3><div class="wp-kv"><span>Give new chats to the best present salesperson automatically</span>${sw('tA', c.auto_assign)}</div>
          <div class="wp-2"><div><label class="wp-fl">Most open chats per person</label><input class="wp-in" id="tM" type="number" min="1" max="50" value="${c.max_open}"></div><div></div><div><label class="wp-fl">…after this many customer messages</label><input class="wp-in" id="tN" type="number" min="1" max="20" value="${c.after_msgs}"></div><div><label class="wp-fl">…or after this many minutes</label><input class="wp-in" id="tI" type="number" min="1" max="60" value="${c.after_min}"></div></div>
          <div class="wp-hint">Order of choice: at work today → has room → handles that number → fewest open chats → quickest to reply → waited longest since the last chat.</div></div>
        <div class="wp-cd"><h3>“Call this customer now”</h3><div class="wp-kv"><span>Pop-up + push to the salesperson when a chat is given to them</span>${sw('tC', c.callnow)}</div>
          <label class="wp-fl">If nobody reacts within (minutes), alert the people below</label><input class="wp-in" id="tE" type="number" min="1" max="30" value="${c.escalate_min}" style="max-width:140px">
          <label class="wp-fl">Alert these people</label><input class="wp-in" id="tF" type="search" placeholder="Search a name…" style="margin-bottom:6px"><div class="wp-pk" id="tPk"></div><div class="wp-hint">The salesperson’s phone gets the push too. Alerts need the HRMS push notifications to be switched on.</div></div>
        <div class="wp-cd"><h3>Source tags &amp; cost</h3><label class="wp-fl" style="margin-top:0">Community link word <small>(a customer whose first message contains it is counted as “Community”)</small></label><input class="wp-in" id="tK" value="${esc(c.community_kw)}" placeholder="e.g. COMMUNITY">
          <div class="wp-2"><div><label class="wp-fl">Cost per marketing message (₹)</label><input class="wp-in" id="tR1" type="number" step="0.01" value="${c.rate_marketing}"></div><div><label class="wp-fl">Cost per utility message (₹)</label><input class="wp-in" id="tR2" type="number" step="0.01" value="${c.rate_utility}"></div></div><div class="wp-hint">Used only for the estimates in Campaigns and Reports. Check Meta’s current India price list.</div>
          <div class="wp-kv" style="margin-top:8px"><span>Slow start for new numbers (250 → 500 → 1,000 → … a day) — only while Meta’s limit is under 10,000; after that Meta’s own limit is used</span>${sw('tRa', c.ramp)}</div></div>
        <div id="tErr"></div><div class="wp-row" style="margin:0 0 14px"><button type="button" class="wp-btn p" id="tSave">Save rules</button></div>
        <div class="wp-cd"><h3>People</h3>${D.people.length ? `<table class="wp-tb"><tr><th>Name</th><th class="n">Open</th><th class="n">Median reply</th><th class="n">≤ 5 min</th></tr>${D.people.map((p) => `<tr><td>${esc(p.name)}<div><span class="wp-pill ${p.present ? 'ok' : ''}">${p.present ? 'At work' : 'Not at work'}</span></div></td><td class="n">${p.open}</td><td class="n">${mins(p.median)}</td><td class="n">${p.fast == null ? '—' : p.fast + '%'}</td></tr>`).join('')}</table><div class="wp-hint">Reply speed = last 30 days, from a customer’s message to the first answer.</div>` : '<div class="wp-empty">No salespeople found (department name must contain “sales”).</div>'}</div>
        <div class="wp-cd" id="tMgr"><h3>Taking over chats</h3><div class="wp-hint" style="margin:0 0 8px"><b>Everyone in a Sales department</b> can open <b>👥 Team</b> in their chat app (every live chat, filter by salesperson, search) and press <b>🔄 Take over</b> — the chat moves to them and the other person’s copy locks (“<i>Y took over from X</i>” is written in the chat history). Watching is read-only; replying needs Take over. Complaint chats are never taken over. <b>Tick only the people who must NOT take over</b> — nobody else needs setting up.</div>
          <input class="wp-in" id="tMq" type="search" placeholder="Search a salesperson…" style="margin-bottom:6px"><div class="wp-pk" id="tMpk">Loading…</div><div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn p" id="tMs">Save</button><span class="wp-hint" id="tMn" style="margin:0"></span></div></div>
        <div class="wp-cd" id="tCx"><h3>Complaint team</h3><div class="wp-hint" style="margin:0 0 8px">When a salesperson marks a chat ⚠️ complaint, <b>the chat stays with that salesperson</b> (it is never moved by itself or taken over) and the case also appears in the <b>Complaint section</b>, given to the least-busy person of the right desk. That person reads and answers the same chat from the complaint board. Each desk needs at least one person — the complaint people log in with their normal HRMS login and see <b>⚠️ Complaints</b> on their phone.</div><div id="tCxB">Loading…</div>
          <div class="wp-row" style="margin-top:8px"><a class="wp-btn p" href="complaints.html?tab=settings" target="_top">Set the complaint team</a><a class="wp-btn" href="complaints.html" target="_top">Open the complaint board</a></div></div>
        <div class="wp-cd"><h3>Engine <span class="sp"></span>${D.cron.last && (Date.now() - (parse(D.cron.last) || 0)) < 180000 ? '<span class="wp-pill ok">Running</span>' : '<span class="wp-pill warn">Not set up yet</span>'}</h3>
          <div class="wp-hint" style="margin:0 0 8px">The engine sends campaigns and follow-ups and keeps assigning chats even when nobody has a page open. Last run: ${D.cron.last ? esc(when(D.cron.last)) : 'never'}.</div>
          <ol style="margin:0 0 10px 18px;padding:0;line-height:1.6;font-size:13px"><li>Hostinger → <b>Advanced → Cron Jobs</b> → Create.</li><li>Choose <b>Every minute</b> (* * * * *).</li><li>Paste this in “Command to run” and save:</li></ol><div class="wp-code" id="tCmd">${esc(D.cron.command)}</div><div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn s" id="tCp">Copy command</button></div><div class="wp-hint">The key in the command is a secret — do not share it.</div></div></div>`;
      const pk = () => { const q = ($(el, '#tF').value || '').toLowerCase(); $(el, '#tPk').innerHTML = D.employees.filter((x) => !q || x.name.toLowerCase().includes(q)).map((x) => `<label><input type="checkbox" value="${x.emp_id}" ${al.has(x.emp_id) ? 'checked' : ''}>${esc(x.name)}</label>`).join('') || '<div class="wp-hint" style="padding:8px">Nobody found.</div>'; $(el, '#tPk').querySelectorAll('input').forEach((i) => { i.onchange = () => { if (i.checked) al.add(+i.value); else al.delete(+i.value); }; }); };
      pk(); $(el, '#tF').oninput = pk;
      api.get('bd.cx_settings').then((x) => {   // ⚠️ who sits at each complaint desk — an empty desk means its complaint chats would wait with nobody
        const box = $(el, '#tCxB'); if (!box) return; const nm = {}; (x.employees || []).forEach((e) => { nm[e.id] = e.name; });
        const empty = (x.desks || []).filter((d) => !(d.emps || []).length);
        box.innerHTML = (empty.length ? `<div class="wp-note" style="background:#fff4e5;color:#9a4b00">⚠️ ${empty.length} desk${empty.length === 1 ? ' has' : 's have'} nobody: ${empty.map((d) => esc(d.label)).join(', ')}. Complaints of that kind would wait with nobody — set a person.</div>` : '<div class="wp-note" style="background:#ecfdf3;color:#067647">✅ Every desk has someone.</div>')
          + (x.desks || []).map((d) => `<div class="wp-kv"><span>${d.icon || ''} ${esc(d.label)}</span><b>${(d.emps || []).length ? (d.emps || []).map((i) => esc(nm[i] || '#' + i)).join(', ') : '<span style="color:#b42318">nobody</span>'}</b></div>`).join('');
      }).catch((er) => { const b = $(el, '#tCxB'); if (b) b.innerHTML = '<span class="wp-hint">' + esc(er.message || 'Could not read the complaint team.') + '</span>'; });
      api.get('wa.to_get').then((m) => {   // who may NOT take over (everyone else in Sales may)
        const no = new Set(m.blocked || []); const box = $(el, '#tMpk'); if (!box) return;
        const cnt = () => { const n = $(el, '#tMn'); if (n) n.textContent = (m.sales || []).length - [...no].filter((i) => (m.sales || []).some((x) => x.emp_id === i)).length + ' of ' + (m.sales || []).length + ' salespeople can take over'; };
        const draw = () => { const q = ($(el, '#tMq').value || '').toLowerCase(); box.innerHTML = (m.sales || []).filter((x) => no.has(x.emp_id) || !q || x.name.toLowerCase().includes(q)).sort((a, b) => (no.has(b.emp_id) - no.has(a.emp_id)) || a.name.localeCompare(b.name)).map((x) => `<label><input type="checkbox" value="${x.emp_id}" ${no.has(x.emp_id) ? 'checked' : ''}> 🚫 ${esc(x.name)}</label>`).join('') || '<span class="wp-hint">Nobody is in a Sales department.</span>'; cnt(); };
        draw(); $(el, '#tMq').oninput = draw; box.onchange = (e) => { const v = +e.target.value; if (e.target.checked) no.add(v); else no.delete(v); cnt(); };
        $(el, '#tMs').onclick = async function () { this.disabled = true; try { await api.post('wa.to_save', { blocked: [...no] }); toast('Saved ✓'); } catch (er) { toast(er.message, true); } this.disabled = false; };
      }).catch(() => { const b = $(el, '#tMpk'); if (b) b.textContent = 'Could not load the people.'; });
      $(el, '#tCp').onclick = async () => { try { await navigator.clipboard.writeText(D.cron.command); toast('Copied ✓'); } catch (e) { const r = document.createRange(); r.selectNodeContents($(el, '#tCmd')); const s = getSelection(); s.removeAllRanges(); s.addRange(r); toast('Selected — press copy'); } };
      $(el, '#tSave').onclick = async function () {
        this.disabled = true;
        try { await api.post('wa.team_save', { auto_assign: $(el, '#tA').checked, max_open: +$(el, '#tM').value, after_msgs: +$(el, '#tN').value, after_min: +$(el, '#tI').value, callnow: $(el, '#tC').checked, escalate_min: +$(el, '#tE').value, alert_emps: [...al], community_kw: $(el, '#tK').value, rate_marketing: +$(el, '#tR1').value, rate_utility: +$(el, '#tR2').value, ramp: $(el, '#tRa').checked }); toast('Saved ✓'); load(); }
        catch (e) { $(el, '#tErr').innerHTML = errBox(e.message); this.disabled = false; }
      };
    }
    load(); return load;
  };

  // ================================================================================================================ REPORTS
  P.reports = (el, ctx) => {
    let days = 30; el.innerHTML = loading;
    async function load() {
      let d; try { d = await api.get('wa.reports', { days }); } catch (e) { el.innerHTML = errBox(e.message); return; }
      const mx = Math.max(1, ...d.daily.map((x) => x.in + x.out + x.tpl)), t = d.totals, w = d.waiting;
      el.innerHTML = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Reports</h2><p>Only the new numbers. AiSensy is not counted.</p></div>${sel('rD', [['7', 'Last 7 days'], ['30', 'Last 30 days'], ['90', 'Last 90 days']], days, 'wp-grow')}</div>
        <div class="wp-kpis"><div class="wp-kpi"><small>Customer messages</small><b>${fmt(t.in)}</b></div><div class="wp-kpi"><small>Our replies</small><b>${fmt(t.out)}</b></div><div class="wp-kpi"><small>Pre-made messages sent</small><b>${fmt(t.tpl)}</b><span>≈ ${money(d.cost.estimate)} at ${money(d.cost.rate)} each</span></div><div class="wp-kpi"><small>Waiting for a reply now</small><b style="${w.n ? 'color:#b42318' : ''}">${w.n}</b><span>${w.n ? 'oldest ' + mins(w.max_min) : 'all answered'}</span></div></div>
        <div class="wp-cd"><h3>Messages per day</h3><div class="wp-bars">${d.daily.map((x) => `<div title="${x.d}: ${x.in} in, ${x.out} replies, ${x.tpl} pre-made"><i class="c" style="height:${x.tpl * 100 / mx}%"></i><i class="b2" style="height:${x.out * 100 / mx}%"></i><i class="a" style="height:${x.in * 100 / mx}%"></i></div>`).join('')}</div><div class="wp-leg"><span><i style="background:#7cc4f2"></i>Customers wrote</span><span><i style="background:#25d366"></i>We replied</span><span><i style="background:#f6b26b"></i>Pre-made / campaign</span></div></div>
        <div class="wp-cd"><h3>Where customers came from</h3><div class="wp-row"><span class="wp-pill o">Query (Meta ad): ${d.sources.query}</span><span class="wp-pill b">Campaign replies: ${d.sources.campaign}</span><span class="wp-pill ok">Community: ${d.sources.community}</span></div></div>
        <div class="wp-cd"><h3>People — how fast they answer</h3>${d.people.length ? `<div style="overflow-x:auto"><table class="wp-tb"><tr><th>Name</th><th class="n">Replies</th><th class="n">Median</th><th class="n">Average</th><th class="n">≤ 5 min</th><th class="n">Open now</th></tr>${d.people.map((p) => `<tr><td>${esc(p.name)}</td><td class="n">${p.replies}</td><td class="n">${mins(p.median)}</td><td class="n">${mins(p.avg)}</td><td class="n">${p.fast == null ? '—' : p.fast + '%'}</td><td class="n">${p.open}</td></tr>`).join('')}</table></div>` : '<div class="wp-empty">No data yet.</div>'}</div>
        <div class="wp-cd"><h3>Numbers</h3><div style="overflow-x:auto"><table class="wp-tb"><tr><th>Number</th><th class="n">In</th><th class="n">Replies</th><th class="n">Pre-made</th><th class="n">Delivered</th><th class="n">Read</th><th class="n">Failed</th></tr>${d.numbers.map((n) => `<tr><td>${esc(n.number)}</td><td class="n">${fmt(n.in)}</td><td class="n">${fmt(n.out)}</td><td class="n">${fmt(n.tpl)}</td><td class="n">${fmt(n.delivered)}</td><td class="n">${fmt(n.read)}</td><td class="n" style="${n.failed ? 'color:#b42318' : ''}">${fmt(n.failed)}</td></tr>`).join('')}</table></div></div>
        <div class="wp-cd"><h3>Campaigns</h3>${d.campaigns.length ? d.campaigns.map((c) => `<div class="wp-kv" style="display:block"><b>${esc(c.name)}</b> <span class="wp-pill">${esc(c.status)}</span><div class="wp-hint">Sent ${fmt(c.sent)} of ${fmt(c.total)} · delivered ${fmt(c.delivered)} · read ${fmt(c.read)} · failed ${fmt(c.failed)} · skipped ${fmt(c.skipped)}</div><div class="wp-hint"><b>${fmt(c.replied)}</b> replied · <b>${c.orders}</b> order${c.orders === 1 ? '' : 's'} from ${c.buyers} customer${c.buyers === 1 ? '' : 's'} = <b>${money(c.revenue)}</b> · cost ≈ ${money(c.cost)}</div></div>`).join('') : '<div class="wp-empty">No campaign in this period.</div>'}<div class="wp-hint">Replies: within 2 days of the message. Orders: customer’s Shopify order within 7 days after the message.</div></div></div>`;
      $(el, '#rD').onchange = function () { days = +this.value; load(); };
    }
    load(); return load;
  };

  // ================================================================================================================ WHATSAPP SALES (new-number data only)
  const SRC = { query: ['Query', 'o'], community: ['Community', 'ok'], campaign: ['Campaign', 'b'] };
  const srcPill = (s) => (SRC[s] ? `<span class="wp-pill ${SRC[s][1]}">${SRC[s][0]}</span>` : '');
  const waitTxt = (m) => (m < 1 ? 'just now' : m < 60 ? m + ' min' : m < 1440 ? Math.floor(m / 60) + ' h ' + (m % 60) + ' min' : Math.floor(m / 1440) + ' d');

  // (P.sboard removed: replaced by a newer screen or no longer reachable from the menu)

  P.sgive = (el, ctx) => {
    let D = null, busy = false; el.innerHTML = loading;
    async function load(quiet) {
      if (busy) return; busy = true;
      try { D = await api.get('wa.sales_give'); } catch (e) { if (!quiet) el.innerHTML = errBox(e.message); busy = false; return; }
      busy = false; draw();
    }
    const left = (p) => (p.full ? ' · full' : '') + (p.present ? '' : ' · not at work');
    // say WHY nobody is suggested (instead of a bare "nobody"), so the admin knows what to fix: punch-ins, or the open-chat limit
    const whyNobody = (d) => { const pp = d.people || [], pr = pp.filter((p) => p.present); if (!pp.length) return 'No salespeople found — the person’s department must be a Sales department'; if (!pr.length) return 'Nobody has punched in yet (' + pp.length + ' salespeople) — use Choose person'; return pr.length + ' at work, all full (' + (d.max_open || 'max') + ' open chats each) — use Choose person, or raise the limit in Team settings'; };
    function draw() {
      const d = D, b = d.best, c = d.cfg;
      el.innerHTML = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Give queries</h2><p>Customers on the new numbers that nobody has taken yet.</p></div></div>
        <div class="wp-kpis"><div class="wp-kpi"><small>Waiting to be given</small><b>${d.items.length}</b></div></div>
        ${d.items.length ? d.items.map((x) => `<div class="wp-cd" data-p="${x.phone}"><div class="wp-row" style="flex-wrap:nowrap;align-items:flex-start"><div class="wp-grow" style="min-width:0"><b>${esc(x.name || pretty(x.phone))}</b> ${srcPill(x.source)}${x.tag_label ? `<span class="wp-pill">${esc(x.tag_label)}</span>` : ''}
          <div class="wp-hint" style="margin-top:2px">${esc(pretty(x.phone))} · waiting <b style="color:${x.waiting_min >= c.focus_min ? '#b42318' : 'inherit'}">${waitTxt(x.waiting_min)}</b></div>${x.preview ? `<div style="margin-top:6px;font-size:13px;line-height:1.4">“${esc(x.preview)}”</div>` : ''}</div></div>
          <div class="wp-row" style="margin-top:10px"><button type="button" class="wp-btn p s" data-act="pick">Choose person…</button><button type="button" class="wp-btn s" data-act="open">Open chat</button></div></div>`).join('') : '<div class="wp-empty">Nothing is waiting to be given. 🎉<br>The engine also gives out chats by itself (Team & routing).</div>'}</div>`;
      el.querySelectorAll('[data-p]').forEach((card) => {
        const ph = card.dataset.p;
        card.querySelectorAll('[data-act]').forEach((bt) => { bt.onclick = () => { const a = bt.dataset.act; if (a === 'open') ctx.openChat(ph); else if (a === 'best') give(ph, D.best.emp_id, D.best.name, bt); else pick(ph); }; });
      });
      const all = $(el, '#gvAll'); if (all) all.onclick = shareOut;
      el.querySelectorAll('.wp-cd[data-p]').forEach((card) => { card.style.cursor = 'pointer'; card.addEventListener('click', (e) => { if (!e.target.closest('button')) ctx.openChat(card.dataset.p); }); });   // tap a customer: the chat opens beside the list
    }
    // the side chat assigned / closed one → refresh, and open the next waiting customer (work through them one by one)
    const onSide = async (e) => {
      if (!el.isConnected) { document.removeEventListener('wa-side', onSide); return; }
      await load(true); const nx = (D && D.items || []).find((x) => x.phone !== e.detail.phone);
      if (e.detail.wa === 'assigned') { toast((e.detail.msg || 'Done') + (nx ? ' Next customer opened.' : ' Nothing else is waiting. 🎉')); if (nx) ctx.openChat(nx.phone); else if (ctx.closeSide) ctx.closeSide(); }
    };
    document.addEventListener('wa-side', onSide);
    async function give(ph, emp, name, bt) {
      if (bt) bt.disabled = true;
      try { await api.post('wa.assign', { phone: ph, emp_id: emp }); toast('Given to ' + name + ' ✓'); await load(true); } catch (e) { if (bt) bt.disabled = false; toast(e.message, true); }
    }
    function pick(ph) {
      const m = modal('Give to…');
      m.body.innerHTML = D.people.map((p) => `<div class="wp-kv" style="align-items:center"><div><b>${esc(p.name)}</b>${p.best ? ' <span class="wp-pill ok">Best now</span>' : ''}<div class="wp-hint">${p.open} open chats${left(p)}</div></div><button type="button" class="wp-btn s ${p.best ? 'p' : ''}" data-e="${p.emp_id}">Give</button></div>`).join('') || '<div class="wp-empty">No salespeople found.</div>';
      m.body.querySelectorAll('[data-e]').forEach((bt) => { bt.onclick = async () => { const p = D.people.find((x) => x.emp_id === +bt.dataset.e); m.close(); await give(ph, p.emp_id, p.name); }; });
    }
    // gives each waiting chat to whoever is present, not full, and has the fewest open chats — counting the chats just given
    async function shareOut() {
      const max = D.max_open || 30, items = D.items.slice(), ppl = D.people.filter((p) => p.present && !p.full).map((p) => ({ id: p.emp_id, name: p.name, open: p.open }));
      if (!ppl.length) { toast(whyNobody(D), true); return; }
      const m = modal('Share out fairly');
      m.body.innerHTML = `<p style="line-height:1.5">This gives ${items.length} waiting chat${items.length === 1 ? '' : 's'} to the people at work with the fewest open chats, one after another. Each person is told by notification.</p><div class="wp-row"><button type="button" class="wp-btn p" id="soGo">Give them out</button><button type="button" class="wp-btn" id="soX">Cancel</button></div><div id="soP" class="wp-hint" style="margin-top:10px"></div>`;
      $(m.body, '#soX').onclick = m.close;
      $(m.body, '#soGo').onclick = async function () {
        this.disabled = true; let done = 0, fail = '';
        for (const x of items) {
          ppl.sort((a, b) => a.open - b.open); const p = ppl[0]; if (!p || p.open >= max) { fail = 'everyone at work now has the most open chats allowed'; break; }
          try { await api.post('wa.assign', { phone: x.phone, emp_id: p.id }); p.open++; done++; $(m.body, '#soP').textContent = 'Given ' + done + ' of ' + items.length + '…'; } catch (e) { fail = e.message; break; }
        }
        m.close(); toast(fail ? 'Stopped after ' + done + ': ' + fail : 'Gave out ' + done + ' chat' + (done === 1 ? '' : 's') + ' ✓', !!fail); load(true);
      };
    }
    load(); return () => load(true);
  };

  // (P.sfocus removed: replaced by a newer screen or no longer reachable from the menu)

  // ================================================================================================================ LEARNING
  // (P.learn removed: replaced by a newer screen or no longer reachable from the menu)

  // ================================================================================================================ CAMPAIGNS
  // ================================================================================================================ GREETINGS (wa_greet.php)
  P.greet = (el, ctx) => {
    let D = null, rows = [], reps = []; el.innerHTML = loading;
    async function load() { try { D = await api.get('wa.greet_get'); rows = JSON.parse(JSON.stringify(D.cfg.rows || [])); reps = JSON.parse(JSON.stringify(D.cfg.replies || [])); } catch (e) { el.innerHTML = errBox(e.message); return; } if (!el.isConnected) return; draw(); }
    const sayO = () => Object.entries(D.says), whoO = () => Object.entries(D.whos);
    function draw() {
      const ro = !D.can_edit ? 'disabled' : '';
      el.innerHTML = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Greetings</h2><p>The decided first answer when a customer only says hi / good morning / namaste — a different one for each kind of customer — and the fixed replies that come next. Saving writes them into the Teach bot's selling rules too, so the bot, the suggestions and Learning &amp; Improvement all use the same words.</p></div>${D.can_edit ? '<button type="button" class="wp-btn p" id="gSave">Save &amp; teach the bot</button>' : ''}</div>
        ${D.origin ? `<div class="wp-cd" id="oCd"><h3>📍 Ask where the customer came from <span class="sp"></span>${sw('oOn', D.origin.on)}</h3><div class="wp-hint" style="margin:0 0 8px">When a new enquiry starts (first message, or after 24 hours quiet) and we don't already know where the customer came from, WhatsApp asks this once — free, inside the 24-hour window. Not asked after a Meta ad click, a reply to our campaign, the campaign number, or an open complaint. <b>Community / offer message → Campaign</b> (query + order); Instagram, ad, friend → Query. The answer shows as a line in the chat and a chip in the salesperson's list.</div>
          <label class="wp-fl" style="margin-top:0">Question</label><textarea class="wp-in" id="oTx" rows="3" maxlength="900" ${ro}>${esc(D.origin.text)}</textarea>
          <div class="wp-2"><div><label class="wp-fl">Button (max 20 letters)</label><input class="wp-in" id="oBt" maxlength="20" value="${esc(D.origin.button)}" ${ro}></div><div></div></div>
          <label class="wp-fl">Options (max 24 letters each)</label><div class="wp-2">${[['instagram', 'Instagram'], ['community', 'WhatsApp community → Campaign'], ['campaign', 'Our offer / campaign → Campaign'], ['ad', 'Facebook / Instagram ad'], ['friend', 'Friend / other']].map(([k, l]) => `<div><div class="wp-hint" style="margin:4px 0 2px">${l}</div><input class="wp-in" id="oO_${k}" maxlength="24" value="${esc(D.origin.opts[k] || '')}" ${ro}></div>`).join('')}</div>
          <div class="wp-row" style="margin-top:10px">${D.can_edit ? '<button type="button" class="wp-btn p" id="oSave">Save</button>' : ''}<span class="wp-hint" style="margin:0">The customer sees: the question + a <b>${esc(D.origin.button)}</b> button that opens the options.</span></div><div id="oE"></div></div>` : ''}
        <div class="wp-cd"><h3>1 · Greetings <span class="sp"></span>${D.can_edit ? '<button type="button" class="wp-btn s" id="gAdd">＋ Greeting</button>' : ''}</h3><div class="wp-hint" style="margin:0 0 8px">The most specific row wins: a <b>campaign customer</b> saying “good morning” gets the campaign + morning row if you have one, else the campaign + hi row, else everyone + morning. Use <b>{name}</b> for the first name.</div>
          ${rows.map((r, i) => `<div class="wp-cd" style="margin:0 0 8px;background:#fafbfc"><div class="wp-2"><div><label class="wp-fl" style="margin-top:0">Customer says</label>${sel('gS' + i, sayO(), r.say)}</div><div><label class="wp-fl" style="margin-top:0">Who</label>${sel('gW' + i, whoO(), r.who)}</div></div>
            <textarea class="wp-in" id="gT${i}" rows="3" maxlength="1000" style="margin-top:6px" ${ro}>${esc(r.text)}</textarea>
            ${D.can_edit ? `<div class="wp-row" style="margin-top:6px"><input class="wp-in" id="gQ${i}" placeholder="Tell Claude what you want, e.g. mention Diwali offer, more friendly" style="flex:1;min-width:180px"><button type="button" class="wp-btn s" data-ai="${i}">✨ Claude: write it</button><button type="button" class="wp-btn s r" data-rm="${i}">Remove</button></div>` : ''}</div>`).join('')}
        </div>
        <div class="wp-cd"><h3>2 · Fixed replies after the greeting <span class="sp"></span>${D.can_edit ? '<button type="button" class="wp-btn s" id="rAdd">＋ Fixed reply</button>' : ''}</h3><div class="wp-hint" style="margin:0 0 8px">What customers ask right after hi (price, COD, delivery, photos…). Each one is also saved as a Quick reply (⚡ in every chat).</div>
          ${reps.length ? reps.map((r, i) => `<div class="wp-cd" style="margin:0 0 8px;background:#fafbfc"><div class="wp-2"><div><label class="wp-fl" style="margin-top:0">Title</label><input class="wp-in" id="rT${i}" maxlength="60" value="${esc(r.title)}" ${ro}></div><div><label class="wp-fl" style="margin-top:0">When the customer asks <small>(words)</small></label><input class="wp-in" id="rW${i}" maxlength="200" value="${esc(r.words)}" placeholder="price, rate, kitna" ${ro}></div></div>
            <textarea class="wp-in" id="rB${i}" rows="3" maxlength="1500" style="margin-top:6px" ${ro}>${esc(r.body)}</textarea>
            ${D.can_edit ? `<div class="wp-row" style="margin-top:6px"><input class="wp-in" id="rQ${i}" placeholder="Tell Claude what you want" style="flex:1;min-width:180px"><button type="button" class="wp-btn s" data-rai="${i}">✨ Claude: write it</button><button type="button" class="wp-btn s r" data-rrm="${i}">Remove</button></div>` : ''}</div>`).join('') : '<div class="wp-hint">No fixed replies yet.' + (D.quick.length ? ' <button type="button" class="wp-btn s" id="rImp">Bring in my ' + D.quick.length + ' quick replies</button>' : '') + '</div>'}
        </div>
        ${D.cfg.at ? `<div class="wp-hint">Last saved ${esc(D.cfg.at)}${D.cfg.by ? ' by ' + esc(D.cfg.by) : ''}.</div>` : '<div class="wp-hint">Not saved yet — these are suggestions. Until you save, the bot keeps using the greeting quick replies.</div>'}<div id="gE"></div></div>`;
      if (!D.can_edit) return;
      const read = () => { rows = rows.map((r, i) => ({ say: $(el, '#gS' + i).value, who: $(el, '#gW' + i).value, text: $(el, '#gT' + i).value })); reps = reps.map((r, i) => ({ title: $(el, '#rT' + i).value, words: $(el, '#rW' + i).value, body: $(el, '#rB' + i).value })); };
      { const ob = $(el, '#oSave'); if (ob) ob.onclick = async function () { this.disabled = true; const opts = {}; ['instagram', 'community', 'campaign', 'ad', 'friend'].forEach((k) => { opts[k] = $(el, '#oO_' + k).value; });
          try { const r = await api.post('wa.origin_save', { on: $(el, '#oOn').checked, text: $(el, '#oTx').value, button: $(el, '#oBt').value, opts }); D.origin = r.origin; toast(r.origin.on ? 'Saved ✓ — new enquiries will be asked where they came from' : 'Saved ✓ — the question is switched off'); $(el, '#oE').innerHTML = ''; }
          catch (e) { $(el, '#oE').innerHTML = errBox(e.message); } this.disabled = false; }; }
      $(el, '#gAdd').onclick = () => { read(); rows.push({ say: 'hi', who: 'not_ordered', text: '' }); draw(); };
      $(el, '#rAdd').onclick = () => { read(); reps.push({ title: '', words: '', body: '' }); draw(); };
      const imp = $(el, '#rImp'); if (imp) imp.onclick = () => { reps = D.quick.filter((q) => !/hi|hello|morning|afternoon|evening|namaste|greet/i.test(q.title)).map((q) => ({ title: q.title, words: '', body: q.body })); draw(); };
      el.querySelectorAll('[data-rm]').forEach((b) => { b.onclick = () => { read(); rows.splice(+b.dataset.rm, 1); draw(); }; });
      el.querySelectorAll('[data-rrm]').forEach((b) => { b.onclick = () => { read(); reps.splice(+b.dataset.rrm, 1); draw(); }; });
      const ai = async (b, payload, target) => { read(); const t0 = b.textContent; b.disabled = true; b.textContent = 'Claude is writing…';
        try { const r = await api.post('wa.greet_ai', payload); $(el, target).value = r.text; toast('Written from your Teach rules — check it, then Save'); } catch (e) { toast(e.message, true); } b.disabled = false; b.textContent = t0; };
      el.querySelectorAll('[data-ai]').forEach((b) => { const i = +b.dataset.ai; b.onclick = () => ai(b, { kind: 'greeting', say: $(el, '#gS' + i).value, who: $(el, '#gW' + i).value, ask: $(el, '#gQ' + i).value, current: $(el, '#gT' + i).value }, '#gT' + i); });
      el.querySelectorAll('[data-rai]').forEach((b) => { const i = +b.dataset.rai; b.onclick = () => ai(b, { kind: 'reply', title: $(el, '#rT' + i).value, words: $(el, '#rW' + i).value, ask: $(el, '#rQ' + i).value, current: $(el, '#rB' + i).value }, '#rB' + i); });
      $(el, '#gSave').onclick = async function () { read(); this.disabled = true; this.textContent = 'Saving…';
        try { const r = await api.post('wa.greet_save', { rows, replies: reps }); toast('Saved ✓ — ' + r.teach_lines + ' lines in the Teach selling rules, ' + r.quick + ' quick replies'); load(); }
        catch (e) { $(el, '#gE').innerHTML = errBox(e.message); this.disabled = false; this.textContent = 'Save & teach the bot'; } };
    }
    load(); return () => load();
  };

  // ================================================================================================================ LATE REPLIES — TAKE ONE (wq.grab_*)
  P.grab = (el, ctx) => {
    let timer = 0, days = 7; el.innerHTML = loading;
    const pc = (v) => (v == null ? '—' : v + '%');
    function board(b) {
      if (!b) return '';
      const rows = b.people.filter((x) => x.taken || x.lost || x.queries);
      return `<div class="wp-cd"><h3>🏆 Who takes the most <span class="sp"></span><span class="wp-seg">${[[1, 'Today'], [7, '7 days'], [30, '30 days']].map(([v, l]) => `<button type="button" data-days="${v}" class="${days === v ? 'on' : ''}">${l}</button>`).join('')}</span></h3>
        <div class="wp-hint" style="margin:0 0 8px">${fmt(b.total_taken)} late chats taken. A taken chat's query counts for the person who took it, so <b>Queries</b> and <b>Order %</b> include them. <b>Taken from them</b> = they were late and someone else had to answer.</div>
        ${rows.length ? `<div style="overflow-x:auto"><table class="wp-tb" style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr style="text-align:left;border-bottom:1px solid #e3e8ef"><th style="padding:6px 4px">#</th><th>Person</th><th title="Late chats they took">Taken</th><th title="Of those, got a reply">Replied</th><th title="Of those, ordered">Orders</th><th title="They were late, someone took it">Taken from them</th><th title="All their queries (taken ones included)">Queries</th><th title="Queries that ordered">Order %</th></tr></thead><tbody>
          ${rows.map((x, i) => `<tr style="border-bottom:1px solid #f0f2f5"><td style="padding:6px 4px">${i === 0 && x.taken ? '🥇' : i === 1 && x.taken ? '🥈' : i === 2 && x.taken ? '🥉' : i + 1}</td><td><b>${esc(x.name)}</b></td><td><b>${fmt(x.taken)}</b></td><td>${fmt(x.taken_replied)} <span class="wp-hint">${pc(x.reply_pct)}</span></td><td>${fmt(x.taken_orders)}</td><td style="color:${x.lost ? '#b42318' : 'inherit'}">${fmt(x.lost)}</td><td>${fmt(x.queries)}${x.q_taken ? ' <span class="wp-hint">(' + fmt(x.q_taken) + ' taken)</span>' : ''}</td><td><b>${pc(x.conv_pct)}</b></td></tr>`).join('')}</tbody></table></div>` : '<div class="wp-hint">Nothing in this period yet.</div>'}</div>`;
    }
    // 🔁 the auto-transfer switch + ⏰ who is late (now / today) — from wa_late (a chat marked late once it waits 10+ min for its salesperson)
    function lateTop(lb) {
      const L = lb.late || { now: [], today: [], min: 10 }, mm = (m) => (m >= 60 ? Math.floor(m / 60) + 'h ' + (m % 60) + 'm' : m + ' min');
      return `<div class="wp-cd" style="border-left:4px solid ${lb.auto_transfer ? '#16a34a' : '#64748b'}"><h3>🔁 Auto transfer <span class="sp"></span><label style="display:flex;gap:8px;align-items:center;font-weight:700;cursor:pointer"><input type="checkbox" id="atSw" ${lb.auto_transfer ? 'checked' : ''} style="width:22px;height:22px">${lb.auto_transfer ? 'ON' : 'OFF'}</label></h3>
        <div class="wp-hint" style="margin:0">${lb.auto_transfer ? 'A chat its salesperson does not accept in time moves to the next free person by itself (at most 2 times).' : '<b>Chats stay with their salesperson.</b> Nothing moves by itself — the reminder pop-ups still go to them, and you see below who is late and transfer by hand (open the chat → Assign).'} Automatic messages to the customer are not affected.</div></div>
        <div class="wp-cd" style="border-left:4px solid #dc2626"><h3>⏰ Late now <span class="wp-pill ${L.now_total ? 'bad' : 'ok'}">${L.now_total || 0}</span><span class="sp"></span><span class="wp-hint" style="margin:0">waiting ${L.min}+ min for their own salesperson</span></h3>
        ${(L.now || []).length ? L.now.map((p) => `<div class="wp-row" style="border-bottom:1px solid #eef1f4;padding:7px 0;align-items:center"><b style="min-width:150px">${esc(p.name)}</b><span class="wp-pill bad">${p.n} waiting · longest ${mm(p.longest)}</span><span style="flex:1"></span>${p.phones.map((c, i) => `<a href="#" data-lc="${esc(c.phone)}" class="wp-btn s${i >= 5 ? ' lx' : ''}" style="text-decoration:none${i >= 5 ? ';display:none' : ''}">${esc(c.name || '+91 ' + c.phone)} · ${mm(c.mins)}</a>`).join(' ')}${p.n > 5 ? `<button type="button" class="wp-btn s" data-lmore="${p.n - 5}">+${p.n - 5} more</button>` : ''}</div>`).join('') : '<div class="wp-hint" style="margin:0">Nobody is late right now. 👍</div>'}</div>
        <div class="wp-cd"><h3>📋 Late today <span class="wp-pill">${L.today_total || 0}</span></h3>${(L.today || []).length ? `<div style="overflow-x:auto"><table class="wp-tb" style="width:100%;font-size:13px"><thead><tr><th style="text-align:left">Person</th><th>Late chats</th><th>Average answer</th><th>Slowest</th><th>While not punched in</th></tr></thead><tbody>${L.today.map((p) => `<tr><td><b>${esc(p.name)}</b></td><td style="text-align:center"><b>${p.n}</b></td><td style="text-align:center">${p.avg != null ? mm(p.avg) : 'not answered yet'}</td><td style="text-align:center">${p.max != null ? mm(p.max) : '—'}</td><td style="text-align:center">${p.off_shift || 0}</td></tr>`).join('')}</tbody></table></div>` : '<div class="wp-hint" style="margin:0">No late reply today.</div>'}</div>`;
    }
    async function load() {
      let d, b = null, lb = null; try { [d, b, lb] = await Promise.all([api.get('wq.grab_list'), api.get('wq.grab_board', { days }).catch(() => null), api.get('wa.late_board').catch(() => null)]); } catch (e) { el.innerHTML = errBox(e.message); return; } if (!el.isConnected) { clearInterval(timer); return; }
      el.innerHTML = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Late replies — take one</h2><p>Customers who have waited ${d.min}+ minutes and nobody has answered. Anyone free can take one and reply. A chat someone is answering or typing in right now is not shown.</p></div><button type="button" class="wp-btn" id="gbR">Refresh</button></div>
        ${d.items.length ? `<div class="wp-cd">${d.items.map((x) => `<div class="wp-row" style="border-bottom:1px solid #eef1f4;padding:8px 0;align-items:center"><div style="flex:1;min-width:180px"><b>${esc(x.name || x.phone)}</b> <span class="wp-pill warn">⏱ ${x.waiting_min} min</span><div class="wp-hint" style="margin:2px 0 0">${x.holder.emp_id ? 'With ' + esc(x.holder.name || 'someone') : 'Nobody has it'} · “${esc(x.last_in || '…')}”</div></div><button type="button" class="wp-btn p s" data-take="${esc(x.phone)}">${x.mine ? 'Open — it is yours' : 'Take & reply'}</button></div>`).join('')}</div>` : '<div class="wp-cd"><div class="wp-hint">🎉 Nobody is waiting too long right now.</div></div>'}${board(b)}</div>`;
      if (lb) { el.querySelector('.wp').insertAdjacentHTML('afterbegin', lateTop(lb)); const sw = $(el, '#atSw'); if (sw) sw.onchange = async () => { sw.disabled = true; try { const r = await api.post('wa.reassign_set', { on: sw.checked }); toast(r.on ? 'Auto transfer ON — a chat not accepted in time moves to the next free person' : 'Auto transfer OFF — chats stay with their salesperson'); } catch (e) { toast(e.message, true); } load(); };
        el.querySelectorAll('[data-lc]').forEach((a) => { a.onclick = (e) => { e.preventDefault(); if (ctx.openChat) ctx.openChat(a.dataset.lc); }; });
        el.querySelectorAll('[data-lmore]').forEach((b) => { b.onclick = () => { b.parentElement.querySelectorAll('.lx').forEach((x) => { x.style.display = ''; }); b.remove(); }; }); }   // the rest of this person's late customers, longest wait first
      $(el, '#gbR').onclick = load; el.querySelectorAll('[data-days]').forEach((x) => { x.onclick = () => { days = +x.dataset.days; load(); }; });
      el.querySelectorAll('[data-take]').forEach((b) => { b.onclick = async () => { b.disabled = true; try { await api.post('wq.grab', { phone: b.dataset.take }); toast('It is yours now — reply'); if (ctx.openChat) ctx.openChat(b.dataset.take); } catch (e) { toast(e.message, true); load(); } }; });
    }
    clearInterval(timer); timer = setInterval(() => { if (!el.isConnected) clearInterval(timer); else if (!document.hidden && el.offsetParent) load(); }, 30000);   // only while on view
    load(); return () => load();
  };

  // ================================================================================================================ QUICK REPLIES
  P.quick = (el, ctx) => {
    let items = [], q = ''; el.innerHTML = loading;
    async function load() { try { items = (await api.get('wa.quick_list')).items || []; } catch (e) { el.innerHTML = errBox(e.message); return; } if (!el.isConnected) return; draw(); }
    function list() {
      const f = items.filter((x) => !q || (x.title + ' ' + x.body).toLowerCase().includes(q));
      $(el, '#qL').innerHTML = f.length ? f.map((x) => `<div class="qr"><span class="tg">/${esc(x.title.toLowerCase().replace(/[^a-z0-9]+/g, '') || 'reply')}</span><div class="t"><b>${esc(x.title)}</b><span>${esc(x.body)}</span></div><button type="button" class="wp-btn s" data-e="${x.id}">Edit</button></div>`).join('') : `<div class="wp-empty">${items.length ? 'No quick reply matches your search.' : 'No quick replies yet. Press “＋ New quick reply” to add the first one.'}</div>`;
      el.querySelectorAll('[data-e]').forEach((b) => { b.onclick = () => edit(items.find((x) => x.id === +b.dataset.e)); });
    }
    function draw() {
      el.innerHTML = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Quick replies</h2><p>Your own saved answers. In any chat type <b>/</b> and the first letters of the title (e.g. <b>/prediwali</b>) — tap it to put it in the box, or ➤ to send it at once. ⚡ shows them all. When a customer asks about price, COD, delivery, original, payment, photos, a discount, a return or the store — or only says hi — the matching reply appears by itself as a one-tap chip (it matches on the title, so keep words like Price / COD / Delivery in the title). They are not sent to Meta.</p></div><button type="button" class="wp-btn" id="qSt" title="Adds hi / hello / good morning and the common questions — only the ones you do not have yet">Add starter set</button><button type="button" class="wp-btn o" id="qNew">＋ New quick reply</button></div>
        <div class="wp-row" style="align-items:flex-start">
          <div class="wp-cd wp-grow" style="flex:2;min-width:300px"><input class="wp-in" id="qS" type="search" placeholder="Search quick replies" value="${esc(q)}" style="margin-bottom:6px"><div id="qL"></div></div>
          <div class="wp-cd wp-grow" style="flex:1;min-width:260px"><h3>How they work</h3><div class="wp-hint" style="line-height:1.6">Quick replies work inside the <b>24-hour window</b> — while the customer has written to you in the last 24 hours.<br><br>After that WhatsApp only allows an approved message. Use <b>Pre-made messages</b> for those.<br><br>Use <b>{name}</b> in a reply to put the customer’s first name in.</div></div>
        </div></div>`;
      $(el, '#qNew').onclick = () => edit(null);
      $(el, '#qSt').onclick = async function () { this.disabled = true; try { const r = await api.post('wa.quick_starter', {}); toast(r.added ? 'Added ' + r.added + ' quick replies — check the wording' : 'You already have all of them'); load(); } catch (e) { toast(e.message, true); } this.disabled = false; };
      $(el, '#qS').oninput = function () { q = this.value.trim().toLowerCase(); list(); };
      list();
    }
    function edit(it) {
      const m = modal(it ? 'Edit quick reply' : 'New quick reply');
      m.body.innerHTML = `<label class="wp-fl" style="margin-top:0">Title <small>(short — it is also the shortcut: “Pre Diwali” → type <b>/prediwali</b> in any chat)</small></label><input class="wp-in" id="qT" maxlength="60" value="${esc(it ? it.title : '')}" placeholder="e.g. Offer details">
        <label class="wp-fl">Message</label><textarea class="wp-in" id="qB" rows="6" maxlength="1500" placeholder="Write the message you send again and again">${esc(it ? it.body : '')}</textarea>
        <div id="qE"></div><div class="wp-row" style="margin-top:12px"><button type="button" class="wp-btn p" id="qSv">Save</button>${it ? '<button type="button" class="wp-btn r" id="qDl">Delete</button>' : ''}</div>`;
      $(m.body, '#qSv').onclick = async function () {
        this.disabled = true;
        try { await api.post('wa.quick_save', { id: it ? it.id : 0, title: $(m.body, '#qT').value, body: $(m.body, '#qB').value }); m.close(); toast('Saved'); load(); }
        catch (e) { $(m.body, '#qE').innerHTML = errBox(e.message); this.disabled = false; }
      };
      const dl = $(m.body, '#qDl'); if (dl) dl.onclick = async function () { if (!confirm('Delete “' + it.title + '”?')) return; try { await api.post('wa.quick_del', { id: it.id }); m.close(); toast('Deleted'); load(); } catch (e) { toast(e.message, true); } };
    }
    load(); return load;
  };

  // (P.campaigns removed: replaced by a newer screen or no longer reachable from the menu)

  window.WaPanels = {
    ver: 'wa2',
    mount(key, el, ctx) { if (!P[key]) return null; el.classList.add('pad'); const wrap = document.createElement('div'); el.innerHTML = ''; el.appendChild(wrap); wrap.className = 'wp-host'; return P[key](wrap, ctx || {}); },
    has: (k) => !!P[k],
    add(k, fn) { P[k] = fn; },   // wa_growth.js adds its screens here
    util: { esc, $, fmt, money, pretty, when, mins, toast, modal, sw, sel, upload, errBox, loading },
  };
})();
