/* Boss Engine — helpers shared by the pages (Boss Board, Inbox, Duties, Setup, My Day) */
const BD = (() => {
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const $ = (id) => document.getElementById(id);
  let tt;
  function toast(t, bad) {
    let e = $('bdToast'); if (!e) { e = document.createElement('div'); e.id = 'bdToast'; document.body.appendChild(e); }
    e.textContent = t; e.className = 'bd-toast show' + (bad ? ' bad' : ''); clearTimeout(tt); tt = setTimeout(() => { e.className = 'bd-toast'; }, bad ? 5000 : 2600);
  }
  const inr = (n) => '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN');
  const DOW = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  function when(d) {
    const f = d.freq;
    if (f === 'daily') return d.dow ? 'Every ' + d.dow.split(',').map((x) => DOW[x - 1]).join(', ') : 'Every day';
    if (f === 'weekly') return 'Every ' + (d.dow ? d.dow.split(',').map((x) => DOW[x - 1]).join(', ') : 'Mon');
    if (f === 'monthly') return 'Monthly on day ' + (d.dom || 1);
    if (f === 'once') return 'Once on ' + (d.on_date || '');
    return f;
  }
  function t12(hhmm) { if (!/^\d\d:\d\d$/.test(hhmm || '')) return hhmm || ''; const [h, m] = hhmm.split(':').map(Number); return ((h + 11) % 12 + 1) + ':' + String(m).padStart(2, '0') + ' ' + (h >= 12 ? 'PM' : 'AM'); }
  const imgCache = {};
  async function photo(task_id) {
    if (imgCache[task_id]) return imgCache[task_id];
    const r = await fetch(api.buildUrl('bd.photo', { task_id }), { headers: { Authorization: 'Bearer ' + api.token() } });
    if (!r.ok) throw new Error('photo');
    return (imgCache[task_id] = URL.createObjectURL(await r.blob()));
  }
  let lockY = null;      // where the page was when a popup opened
  function modal(html, opts) {
    close();
    const o = document.createElement('div'); o.className = 'bd-ov'; o.id = 'bdModal';
    o.innerHTML = '<div class="bd-modal" role="dialog">' + html + '</div>';
    o.addEventListener('mousedown', (e) => { if (e.target === o && !(opts && opts.sticky)) close(); });
    // Stop the page behind from scrolling WITHOUT losing its place: body { overflow:hidden } (with html, body { height:100% }) cut the page to one screen, so it jumped to the top
    // and stayed there after the popup closed. Locking the root element keeps the scroll position; it is put back on close as well, in case a browser still moved it.
    if (lockY === null) lockY = [window.scrollX, window.scrollY];
    document.body.appendChild(o); document.documentElement.style.overflow = 'hidden';
    return o.firstChild;
  }
  function close() { const o = $('bdModal'); if (o) o.remove(); document.documentElement.style.overflow = ''; if (o && lockY) { const y = lockY; lockY = null; if (Math.abs(window.scrollY - y[1]) > 1) window.scrollTo(y[0], y[1]); } if (BD.onClose) { const f = BD.onClose; BD.onClose = null; f(); } }
  function confirm(title, msg, ok, danger) {
    return new Promise((res) => {
      const m = modal('<h3>' + esc(title) + '</h3><p style="font-size:14px;line-height:1.5;color:#4a505c;margin:0">' + esc(msg) + '</p><div class="acts"><button class="bd-btn" id="bdNo">Cancel</button><button class="bd-btn ' + (danger ? 'bad' : 'pri') + '" id="bdYes">' + esc(ok || 'OK') + '</button></div>', { sticky: true });
      m.querySelector('#bdNo').onclick = () => { close(); res(false); }; m.querySelector('#bdYes').onclick = () => { close(); res(true); };
    });
  }
  const MODE = {
    shadow: { name: 'Test mode', short: 'Test', text: 'The Boss Engine is only watching. It builds the daily duties and shows you what would happen, but staff do not see any task, nobody gets a reminder and nobody is fined. Use this for a few days to check the duties and people are right.' },
    soft: { name: 'Staff can see their duties', short: 'Staff see tasks', text: 'Staff now see their duties in My Day and get reminders. Missed duties are recorded, and the penalty that WOULD be given is shown to you, but no money is taken.' },
    live: { name: 'Fully live', short: 'Live', text: 'Missed duties create real penalties. Each penalty waits in the Boss Inbox for a yes or no (unless that duty is set to automatic). Staff can appeal. Daily and monthly limits protect everyone.' },
  };
  const KIND = {
    penalty: ['Penalty', 'bad'], appeal: ['Appeal', 'warn'], ai_review: ['Check photo', 'info'], uncovered: ['Nobody free', 'bad'], collision: ['Clash', 'warn'], new_section: ['New section', 'info'],
    new_joiner: ['New joiner', 'info'], season: ['Festival', 'ok'], problem: ['Problem reported', 'warn'], changed: ['Changed', 'info'], digest: ['Daily summary', ''], note: ['Note', ''],
    doc: ['Document', 'warn'], handover: ['Handover', 'bad'], loop: ['Keeps coming back', 'bad'],
    dp_pen: ['Penalty', 'bad'], dp_appeal: ['Appeal', 'warn'], dp_note: ['Departments', 'info'], dp_punchout: ['Punch out', 'warn'],
  };
  const isTop = () => { try { return window.self === window.top; } catch (e) { return true; } };
  async function who() { try { return await api.get('bd.badge'); } catch (e) { return {}; } }
  return { esc, $, toast, inr, when, t12, photo, modal, close, confirm, MODE, KIND, DOW, who, isTop };
})();
