/* store_bday.js — 🎂 Birthdays & gift codes for the STORE (offline customers): today's list to send by hand on web.whatsapp (message + photo, Done),
 * the 14-day list, the manager's settings and message editor, and "Check a customer's gifts". Used by Store → Billing and Store → Messages.
 *   StoreBday.mount(el)   draws the two cards into el and loads them        StoreBday.load()   reloads
 * Talks to st_cust.birthdays and st_bday.* (empmanagment/store_pos.php). Needs api.js. */
window.StoreBday = (function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const dm = (d) => d ? new Date(d + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : '';
  const CSS = `.bdcard .bdr{display:flex;align-items:center;gap:10px;padding:7px 0;border-top:1px solid var(--sx-line);font-size:13px;flex-wrap:wrap}.bdcard .bdr:first-of-type{border-top:0}
.bdcard .bdr .n{flex:1;min-width:120px}.bdcard .bdr small{display:block;color:var(--sx-mut)}
.bdtoday{background:#fffaf0;border:1px solid #f3dfb7;border-radius:14px;padding:8px 12px;margin-bottom:6px}.bdtoday h4{margin:2px 0 6px;font-size:14px}.bdq .bdmsg{margin-top:4px;color:#3b4a54;white-space:pre-wrap;max-height:3.9em;overflow:hidden;font-size:12px}
.bdcard .pill{padding:2px 10px;border-radius:999px;font-weight:800;font-size:11.5px;background:var(--sx-violet-t);color:var(--sx-violet)}.bdcard .pill.today{background:#fff4e0;color:#b45309}
.bdcard .code{font:800 12.5px ui-monospace,Menlo,Consolas,monospace;letter-spacing:.5px;background:#fff4e0;color:#b45309;border:1.5px dashed #e0a458;border-radius:8px;padding:2px 8px}
.bdcard .pill.ok{background:var(--sx-aqua-t);color:#0d7a55}.bdcard .pill.bad{background:#fdecea;color:#b3261e}
.bdset{display:grid;gap:10px;font-size:13px}.bdset .r{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.bdset label{display:inline-flex;gap:6px;align-items:center}
.bdset input[type=number]{width:70px;min-height:32px}
.minibtn{border:0;background:#1fa855;color:#fff;border-radius:8px;padding:2px 9px;font:800 11px inherit;cursor:pointer}`;
  const CARDS = `      <div class="sx-card bdcard" id="bdCard"><h3>🎂 Birthdays &amp; gift codes <small id="bdSub">next 14 days</small></h3><div id="bdAuto" style="margin:2px 0 8px"></div><div id="bdList"></div>
        <details class="sx-d" id="bdSet" style="margin-top:10px"><summary>⚙ Birthday gift settings</summary><div id="bdSetBody" style="margin-top:10px"></div></details></div>
      <div class="sx-card bdcard" id="gfCard"><h3>🎁 Check a customer’s gifts <small>review gifts, goodwill gifts and birthday codes</small></h3><div style="display:flex;gap:8px;flex-wrap:wrap"><input class="sx-in" id="gfQ" placeholder="Phone number or gift code" style="flex:1;min-width:180px"><button class="sx-btn" id="gfGo" style="padding:8px 16px">Check</button></div><div id="gfOut" style="margin-top:8px"></div></div>`;
  function css() { if (document.getElementById('sbdCss')) return; const st = document.createElement('style'); st.id = 'sbdCss'; st.textContent = CSS; document.head.appendChild(st); }
  // ---------- 🎂 birthdays: today's list to send BY HAND on web.whatsapp (message + photo), the 14-day list, settings
  const ymd = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const fillMsg = (t, x) => String(t || '').replace(/\{name\}/g, ((x.name || '').trim().split(/\s+/)[0]) || 'there').replace(/\{code\}/g, x.code || '').replace(/\{birthday\}/g, x.birthday || '').replace(/\{from\}/g, dm(x.valid_from)).replace(/\{till\}/g, dm(x.valid_to));
  const waWeb = (phone, text) => 'https://web.whatsapp.com/send?phone=91' + String(phone || '').replace(/\D/g, '').slice(-10) + '&text=' + encodeURIComponent(text);
  let bdInfo = null, bdPhoto = null, bdOpened = {};
  const toast2 = (m) => { const t = document.createElement('div'); t.textContent = m; t.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#111b21;color:#fff;padding:10px 16px;border-radius:12px;font-weight:700;z-index:99999'; document.body.appendChild(t); setTimeout(() => t.remove(), 2600); };
  async function loadBirthdays() {
    try {
      const [d, info] = await Promise.all([api.get('st_cust.birthdays', { days: 14 }), api.get('st_bday.list').catch(() => null)]); bdInfo = info;
      const L = d.list || [], V = (info && info.vouchers) || [], msg = (info && info.msg) || { text: '', has_photo: false }, today = ymd(new Date());
      const mine = V.filter((v) => !v.online);   // store customers: the cashier sends these by hand. Online customers get it by themselves.
      const todo = mine.filter((v) => v.status === 'active' && v.wa_status !== 'sent' && v.valid_from <= today && v.valid_to >= today);
      const done = mine.filter((v) => v.wa_status === 'sent' && String(v.wa_at || '').slice(0, 10) === today).length;
      $('bdSub').textContent = info ? `${info.customers_with_birthday} store customer${info.customers_with_birthday === 1 ? '' : 's'} with a birthday saved · online ones are sent by themselves` : '';
      $('bdAuto').innerHTML = !info ? '' : !info.cfg.enabled ? '<span class="pill bad">Birthday gift is switched OFF</span>' : '<span class="pill ok">🏬 Store customers: send from “To send today” below · 🤖 Online customers: their own message goes by itself the day before the birthday</span>';
      let h = `<div class="bdtoday"><h4>📤 To send today <span class="pill today">${todo.length}</span> <small style="font-weight:600;color:var(--sx-mut)">${done} done today</small></h4>`;
      h += todo.length ? todo.map((v) => { const t = fillMsg(msg.text, v), op = bdOpened[v.id];
        return `<div class="bdr bdq" data-v="${v.id}"><div class="n"><b>${esc(v.name || 'Customer')}</b><small>+${esc(v.phone)} · 🎂 ${esc(v.birthday)} · code <span class="code">${esc(v.code)}</span></small><small class="bdmsg">${esc(t)}</small></div><div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">${msg.has_photo ? `<button class="minibtn" data-bcopy="${v.id}" style="background:#6d4fc2">📷 Copy photo</button>` : ''}<button class="minibtn" data-bopen="${v.id}" style="background:#0b6ef6;padding:6px 12px;font-size:12.5px">💬 Open WhatsApp</button><button class="minibtn" data-bdone="${v.id}" style="padding:6px 12px;font-size:12.5px;${op ? '' : 'opacity:.45'}" ${op ? '' : 'disabled'}>✔ Done</button></div></div>`; }).join('')
        : `<div class="sx-mut" style="padding:6px 0;font-size:13px">${done ? '🎉 All done — every birthday message for today is sent.' : 'No store customer has a birthday today. Ask every customer for their birthday at billing.'}</div>`;
      h += '</div><h4 style="margin:12px 0 4px">Coming in the next 14 days</h4>';
      h += L.length ? L.map((x) => {
        const dt = new Date(x.date + 'T00:00:00'), v = x.voucher, onl = v && V.some((q) => q.id === v.id && q.online);
        const when = x.in_days === 0 ? '<span class="pill today">Today 🎉</span>' : `<span class="pill">${x.in_days === 1 ? 'Tomorrow' : 'in ' + x.in_days + ' days'}</span>`;
        let g = '';
        if (v) { const used = v.status === 'redeemed' || x.gifted; g = `<span class="code">${esc(v.code)}</span>` + (used ? '<span class="pill ok">🎁 gift taken</span>' : v.wa_status === 'sent' ? '<span class="pill ok">✓ message sent</span>' : onl ? '<span class="pill">🤖 goes by itself</span>' : '<span class="pill">⏳ to send</span>') + `<small style="color:var(--sx-mut)">valid till ${esc(dm(v.valid_to))}</small>`; }
        else g = '<small style="color:var(--sx-mut)">the code is made the day before the birthday</small>';
        return `<div class="bdr"><div class="n"><b>${esc(x.name || 'Customer')}</b><small>${x.phone ? '+' + esc(x.phone) + ' · ' : ''}${dt.getDate()} ${MONTHS[dt.getMonth()]}</small></div>${when}<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">${g}</div></div>`;
      }).join('') : '<div class="sx-mut" style="padding:6px 0;font-size:13px">No birthday in the next 14 days.</div>';
      $('bdList').innerHTML = h; renderBdSet();
    } catch (e) { $('bdList').innerHTML = ''; }
  }
  async function bdPhotoBlob() {   // the photo as PNG (what the clipboard accepts)
    if (!bdPhoto) { const r = await api.get('st_bday.msg_get', { photo: 1 }); bdPhoto = r.photo || ''; } if (!bdPhoto) throw new Error('No photo is set.');
    const img = new Image(); await new Promise((ok, no) => { img.onload = ok; img.onerror = no; img.src = bdPhoto; }); const c = document.createElement('canvas'); c.width = img.naturalWidth; c.height = img.naturalHeight; c.getContext('2d').drawImage(img, 0, 0);
    return await new Promise((ok) => c.toBlob(ok, 'image/png'));
  }
  const resizeImg = (file, max) => new Promise((ok, no) => { const fr = new FileReader(); fr.onerror = no; fr.onload = () => { const im = new Image(); im.onerror = no; im.onload = () => { const k = Math.min(1, max / Math.max(im.naturalWidth, im.naturalHeight)), c = document.createElement('canvas'); c.width = Math.round(im.naturalWidth * k); c.height = Math.round(im.naturalHeight * k); c.getContext('2d').drawImage(im, 0, 0, c.width, c.height); ok(c.toDataURL('image/jpeg', 0.82)); }; im.src = fr.result; }; fr.readAsDataURL(file); });
  function renderBdSet() {
    const i = bdInfo; if (!i) { $('bdSet').style.display = 'none'; return; } $('bdSet').style.display = '';
    const c = i.cfg, m = i.msg || { text: '', has_photo: false, default: '' };
    $('bdSetBody').innerHTML = i.can_manage ? `<div class="bdset">
      <div class="r"><label><input type="checkbox" id="bsOn" ${c.enabled ? 'checked' : ''}> Birthday gift is ON</label><label><input type="checkbox" id="bsWa" ${c.auto_wa ? 'checked' : ''}> Send the message by itself to online customers</label></div>
      <div class="r">Make the code <input type="number" class="sx-in" id="bsBefore" min="0" max="30" value="${c.send_before}"> days before the birthday (1 = the day before) · valid <input type="number" class="sx-in" id="bsAfter" min="0" max="60" value="${c.valid_after}"> days after it</div>
      <div class="r"><button class="sx-btn" id="bsSave" style="padding:8px 16px">Save</button><button class="lnk" id="bsRun">Make today’s codes now</button><span id="bsMsg" class="sx-mut"></span></div>
      <div class="gok" style="background:#f7f9fb;border-color:var(--sx-line)"><b>✏️ The message you send by hand</b><div class="sx-mut" style="font-size:12px;margin:2px 0 6px">The words in { } fill themselves in for each customer:</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px">${['{name}', '{code}', '{birthday}', '{from}', '{till}'].map((t) => `<button type="button" class="minibtn" data-bvar="${t}" style="background:#e8eef7;color:#25364d">${t}</button>`).join('')}</div>
        <textarea id="bmText" rows="5" style="width:100%;box-sizing:border-box;border:1px solid var(--sx-line);border-radius:10px;padding:8px;font:inherit">${esc(m.text)}</textarea>
        <div class="r" style="margin-top:6px">📷 Photo: <input type="file" id="bmFile" accept="image/*" style="max-width:210px"> ${m.has_photo ? '<button class="lnk" id="bmDel">Remove the photo</button><span class="pill ok">photo set</span>' : '<span class="sx-mut">none</span>'}</div>
        <div class="sx-mut" style="font-size:12px;margin:6px 0 2px">What it looks like:</div><div id="bmPrev" style="white-space:pre-wrap;background:#e7fbf3;border-radius:10px;padding:8px 10px;font-size:13px"></div>
        <div class="r" style="margin-top:6px"><button class="sx-btn" id="bmSave" style="padding:8px 16px">Save message</button><button class="lnk" id="bmReset">Back to the standard message</button><span id="bmNote" class="sx-mut"></span></div>
        <div class="sx-mut" style="font-size:12px;margin-top:4px">A photo cannot travel inside a web.whatsapp link, so the “📷 Copy photo” button copies it: open the chat, press Ctrl+V and send.</div></div>
      <div class="sx-mut" style="font-size:12px">Online customers (birthday asked when the order was made) get their own automatic WhatsApp message (different from this store message) the day before the birthday: see and change its words in WhatsApp → Order-update messages → “Birthday gift”. A customer gets one code a year; one code = one free 50 ml.</div></div>`
      : `<div class="bdset"><div class="sx-mut">The code is made ${c.send_before === 0 ? 'on the birthday' : c.send_before === 1 ? 'the day before the birthday' : c.send_before + ' days before it'} and is valid ${c.valid_after} days after it. A manager can change this and the message.</div></div>`;
    bmPrev();
  }
  const bmPrev = () => { const t = $('bmText'), p = $('bmPrev'); if (!t || !p) return; const d0 = new Date(), e2 = new Date(d0.getTime() + ((bdInfo && bdInfo.cfg.valid_after) || 15) * 864e5); p.textContent = fillMsg(t.value, { name: 'Rahul Sharma', code: 'BD-7K3M9P', birthday: d0.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }), valid_from: ymd(d0), valid_to: ymd(e2) }); };
  function wire() {
  $('bdCard').addEventListener('input', (e) => { if (e.target.id === 'bmText') bmPrev(); });
  $('bdCard').addEventListener('change', async (e) => {
    if (e.target.id !== 'bmFile' || !e.target.files[0]) return; const n = $('bmNote');
    try { const d = await resizeImg(e.target.files[0], 900); await api.post('st_bday.msg_save', { photo: d }); bdPhoto = null; if (n) n.textContent = 'Photo saved.'; loadBirthdays(); } catch (err) { if (n) { n.textContent = err.message || 'Could not use this picture.'; n.style.color = 'var(--sx-bad)'; } }
  });
  $('bdCard').addEventListener('click', async (e) => {
    const note = (m, bad) => { const el = $('bsMsg'); if (el) { el.textContent = m; el.style.color = bad ? 'var(--sx-bad)' : 'var(--sx-good)'; } };
    const nm = (m, bad) => { const el = $('bmNote'); if (el) { el.textContent = m; el.style.color = bad ? 'var(--sx-bad)' : 'var(--sx-good)'; } };
    const t = e.target, V = (bdInfo && bdInfo.vouchers) || [], vc = (id) => V.find((x) => x.id === +id);
    if (t.dataset.bvar) { const ta = $('bmText'); if (ta) { const a = ta.selectionStart || ta.value.length; ta.value = ta.value.slice(0, a) + t.dataset.bvar + ta.value.slice(ta.selectionEnd || a); ta.focus(); bmPrev(); } return; }
    if (t.dataset.bcopy) { try { const b = await bdPhotoBlob(); await navigator.clipboard.write([new ClipboardItem({ 'image/png': b })]); toast2('Photo copied. Open the chat and press Ctrl+V.'); } catch (err) { toast2('Could not copy the photo — right-click it in settings and save it instead.'); } return; }
    if (t.dataset.bopen) { const v = vc(t.dataset.bopen); if (!v) return; window.open(waWeb(v.phone, fillMsg((bdInfo.msg || {}).text, v)), 'tmpwa'); bdOpened[v.id] = 1; const dn = t.closest('.bdq').querySelector('[data-bdone]'); if (dn) { dn.disabled = false; dn.style.opacity = '1'; } return; }
    if (t.dataset.bdone) { t.disabled = true; try { await api.post('st_bday.mark_sent', { id: +t.dataset.bdone }); delete bdOpened[+t.dataset.bdone]; } catch (err) { alert(err.message); } loadBirthdays(); return; }
    if (t.id === 'bmSave') { try { await api.post('st_bday.msg_save', { text: $('bmText').value }); nm('Saved.'); loadBirthdays(); } catch (err) { nm(err.message, 1); } return; }
    if (t.id === 'bmReset') { if (!confirm('Go back to the standard birthday message?')) return; try { await api.post('st_bday.msg_save', { reset: 1 }); loadBirthdays(); } catch (err) { nm(err.message, 1); } return; }
    if (t.id === 'bmDel') { try { await api.post('st_bday.msg_save', { photo: '' }); bdPhoto = null; loadBirthdays(); } catch (err) { nm(err.message, 1); } return; }
    if (t.id === 'bsSave') { const b0 = +$('bsBefore').value, a0 = +$('bsAfter').value; try { await api.post('st_bday.cfg_save', { enabled: $('bsOn').checked ? 1 : 0, auto_wa: $('bsWa').checked ? 1 : 0, send_before: isNaN(b0) ? 0 : b0, valid_after: isNaN(a0) ? 15 : a0 }); note('Saved.'); loadBirthdays(); } catch (err) { note(err.message, 1); } return; }
    if (t.id === 'bsRun') { t.disabled = true; note('Working…'); try { const r = await api.post('st_bday.run', {}); note(r.message); loadBirthdays(); } catch (err) { note(err.message, 1); } t.disabled = false; return; }
  });
  }
  // ---------- 🎁 a customer's other gifts (review / goodwill in ml, birthday codes): check and mark used
  async function gfCheck() {
    const q = $('gfQ').value.trim(), out = $('gfOut'); if (q.length < 4) { out.innerHTML = '<div class="sx-mut">Write the phone number or the gift code.</div>'; return; } out.innerHTML = '<div class="sx-mut">Checking…</div>';
    try {
      const r = await api.get('st_bday.gifts', { q }); const G = r.gifts || [];
      out.innerHTML = G.length ? G.map((g) => `<div class="bdr"><div class="n"><b>${g.ml} ml free · ${esc(g.what)}</b><small>${esc(g.code)} · +${esc(g.phone)} · valid till ${esc(dm(g.valid_to))}${g.min_order ? ' · online: order above ₹' + g.min_order.toLocaleString('en-IN') : ''}${g.reason ? ' · ' + esc(g.reason) : ''}</small></div><button class="minibtn" data-guse="${g.id}" data-gkind="${g.kind}" style="padding:6px 12px;font-size:12.5px">Mark used</button></div>`).join('') : '<div class="sx-mut">No active gift for this customer.</div>';
    } catch (err) { out.innerHTML = '<div class="sx-mut" style="color:var(--sx-bad)">' + esc(err.message) + '</div>'; }
  }
  let docWired = false;
  function wireDoc() {
    if (docWired) return; docWired = true;
  document.addEventListener('click', async (e) => {
    if (e.target.id === 'gfGo') { gfCheck(); return; }
    const b = e.target.closest && e.target.closest('[data-guse]'); if (!b) return;
    const ref = prompt('Which bill or online order number is it used on?', ''); if (!ref) return; const choice = prompt('Which perfume did the customer choose?', '') || '';
    b.disabled = true; try { const r = await api.post('st_bday.gift_use', { kind: b.dataset.gkind, id: +b.dataset.guse, ref, choice }); toast2(r.message || 'Done'); gfCheck(); } catch (err) { alert(err.message); b.disabled = false; }
  });
  document.addEventListener('keydown', (e) => { if (e.target.id === 'gfQ' && e.key === 'Enter') gfCheck(); });
  }

  function mount(el) { css(); el.innerHTML = CARDS; wire(); wireDoc(); return loadBirthdays(); }
  return { mount, load: () => ($('bdList') ? loadBirthdays() : null) };
})();
