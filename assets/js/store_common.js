/* Shared by the Store pages: tiny helpers + the barcode-scan input.
   scanBox() runs scans ONE AT A TIME (a scanner can fire several codes in a burst), keeps the box focused,
   and beeps — a high tone for OK, a low double tone for a problem — so the cashier can scan without looking at the screen. */
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const inr = (n) => '₹' + Math.round(n).toLocaleString('en-IN');
let _ac = null;
function beep(ok) {
  try {
    _ac = _ac || new (window.AudioContext || window.webkitAudioContext)();
    const tone = (f, t0, d) => { const o = _ac.createOscillator(), g = _ac.createGain(); o.frequency.value = f; g.gain.value = 0.08; o.connect(g); g.connect(_ac.destination); o.start(_ac.currentTime + t0); o.stop(_ac.currentTime + t0 + d); };
    if (ok) tone(1200, 0, 0.08); else { tone(300, 0, 0.12); tone(300, 0.17, 0.12); }
    if (!ok && navigator.vibrate) navigator.vibrate(120);
  } catch (e) { /* sound is a nice-to-have */ }
}
/** handler(code) may return true (ok) / false (problem) — decides the beep.
 *  A scanner is a keyboard that types the digits and presses Enter (some are set to Tab: both work). This catches the scan
 *  whether the box is focused or not, runs scans one at a time, and ignores a double-read of the same code within 0.7 s. */
function scanBox(input, handler, opts) {
  let chain = Promise.resolve(), last = { c: '', t: 0 };
  const submit = (code) => {
    const now = Date.now();
    if (code === last.c && now - last.t < 700) return;      // the scanner read the same bottle twice in a row
    last = { c: code, t: now };
    const run = async () => { let ok = true; try { ok = (await handler(code)) !== false; } catch (err) { ok = false; } beep(ok); };
    if (opts && opts.parallel) run(); else chain = chain.then(run);      // parallel: a burst of scans does not wait for each answer (the handler may batch them)
  };
  input.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== 'Tab') return;
    const code = input.value.replace(/\D/g, ''); if (!code) return;
    e.preventDefault(); input.value = ''; submit(code);
  });
  // Nothing useful focused (the cashier tapped the page)? A fast burst of digits + Enter is still a scan.
  let buf = '', lastKey = 0;
  document.addEventListener('keydown', (e) => {
    if (input.offsetParent === null) return;                 // this scan box is not on screen (another tab is open)
    const t = e.target, tag = t && t.tagName || '';
    if (t === input || tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) return;
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    const now = Date.now(); if (now - lastKey > 100) buf = ''; lastKey = now;       // people type slower than a scanner
    if (/^\d$/.test(e.key)) { buf += e.key; return; }
    if ((e.key === 'Enter' || e.key === 'Tab') && buf.length >= 4) { e.preventDefault(); submit(buf); buf = ''; input.focus(); }
    else buf = '';
  });
  const focus = () => { if (input.offsetParent !== null) input.focus(); };
  document.addEventListener('visibilitychange', () => { if (!document.hidden) focus(); });
  return { focus };
}
function flash(boxId, msg, bad) { $(boxId).innerHTML = msg ? `<div class="sx-msg ${bad ? 'bad' : 'ok'}">${sxi(bad ? 'xc' : 'ok')}<span>${esc(msg)}</span></div>` : ''; if (msg) setTimeout(() => { $(boxId).innerHTML = ''; }, 6000); }

/* ---- icons (24px stroke icons, drawn inline: no library) ---- */
const SXI = {
  scan: 'M4 5v14M8 5v14M12 5v14M16 5v14M20 5v14', bag: 'M6 7h12l1 13H5L6 7zM9 7a3 3 0 0 1 6 0', receipt: 'M6 3h12v18l-3-2-3 2-3-2-3 2V3zM9 8h6M9 12h6',
  ticket: 'M3 9a2 2 0 0 0 0 6v3h18v-3a2 2 0 0 1 0-6V6H3v3z', flask: 'M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3', box: 'M21 8l-9-5-9 5v8l9 5 9-5V8zM3 8l9 5 9-5M12 13v8',
  alert: 'M12 3l10 18H2L12 3zM12 10v5M12 18h.01', ok: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8 12l3 3 5-6', info: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 11v5M12 8h.01',
  xc: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9 9l6 6M15 9l-6 6', user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  clean: 'M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8L12 3zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z', cup: 'M5 8h12v6a5 5 0 0 1-5 5h-2a5 5 0 0 1-5-5V8zM17 10h2a2 2 0 0 1 0 4h-2M8 3v2M12 3v2',
  mega: 'M3 11v2a1 1 0 0 0 1 1h3l8 4V6L7 10H4a1 1 0 0 0-1 1zM18 9a4 4 0 0 1 0 6', chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2', dash: 'M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z',
  print: 'M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2M6 14h12v7H6z', sync: 'M21 12a9 9 0 1 1-3-6.7M21 4v5h-5', list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
  tag: 'M3 12V3h9l9 9-9 9-9-9zM7.5 7.5h.01', clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
};
const sxi = (n) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${SXI[n] || ''}"/></svg>`;
/** status → icon + class: color is never the only signal (the icon and the words say it too) */
const sxLevel = (lv) => ({ bad: ['xc', 'bad'], warn: ['alert', 'warn'], info: ['info', 'info'] }[lv] || ['info', 'info']);
const SX_AV = ['#2a78d6', '#eb6834', '#1baf7a', '#6250d6', '#d55181'];
const sxAv = (name) => { let h = 0; for (const c of String(name || '?')) h = (h * 31 + c.charCodeAt(0)) >>> 0; return `<span class="sx-av" style="background:${SX_AV[h % SX_AV.length]}">${esc(String(name || '?').trim().charAt(0).toUpperCase())}</span>`; };
/** hover / touch tooltip for anything with data-tip inside root */
function sxTips(root) {
  let tip = null;
  const hide = () => { if (tip) { tip.remove(); tip = null; } };
  const show = (e) => { const t = e.target.closest('[data-tip]'); if (!t || !root.contains(t)) return hide(); if (!tip) { tip = document.createElement('div'); tip.className = 'sx-tip'; document.body.appendChild(tip); } tip.textContent = t.dataset.tip; const r = t.getBoundingClientRect(); tip.style.left = (r.left + r.width / 2) + 'px'; tip.style.top = r.top + 'px'; };
  root.addEventListener('mouseover', show); root.addEventListener('mouseleave', hide); root.addEventListener('click', show); document.addEventListener('scroll', hide, true);
}
