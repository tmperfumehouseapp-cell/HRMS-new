/**
 * wa_adleads.js — WhatsApp → Queries & reports → 📣 Ad leads (whatsapp.html, Admin / HR).
 * One day, one line per customer who came from a Meta click-to-WhatsApp ad: time, the number they wrote to, the ad,
 * new or returning, and the first message they wrote that day. Pick a day and a number; nothing has to be opened to read it.
 * 🩺 Diagnose (wa.g_adcheck): the day per ad — Meta, the tracking service (ctwa-roas) and the HRMS side by side, with a report to copy.
 * 🩺 Check order (wa.g_adcheck_one): one customer — ad click received, Shopify order, matched, told to Meta.
 * Needs api.js and wa_panels.js (loaded first). Reads wa.g_adleads (wa_adleads.php); it loads only when opened or refreshed.
 */
(function () {
  if (!window.WaPanels) return;
  const { esc, $, when, errBox, loading, toast } = WaPanels.util;
  const inr = (n) => '₹' + Math.round(+n || 0).toLocaleString('en-IN');
  const unix = (t) => (t ? new Date(t * 1000).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', hour12: true }) : '—');
  const CAPI = { sent: ['Told to Meta', 'ok'], failed: ['Meta refused', 'bad'], pending: ['Waiting to send', 'warn'], expired: ['Too old to send', 'bad'], none: ['Not confirmed yet', ''] };
  const capiTag = (st) => { const c = CAPI[st] || [st || '—', '']; return `<span class="al-st ${c[1]}">${esc(c[0])}</span>`; };
  const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const shift = (day, n) => { const d = new Date(day + 'T12:00:00'); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const time = (s) => { const w = when(s); const i = w.indexOf(', '); return i >= 0 ? w.slice(i + 2) : w; };
  const CSS = `
  .al-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 10px}
  .al-bar input[type=date]{min-height:36px;padding:4px 8px;border:1px solid #d7ded9;border-radius:10px;font:inherit;width:auto}
  .al-btn{border:1px solid #d7ded9;background:#fff;border-radius:10px;min-height:36px;padding:0 12px;font:700 12.5px inherit;cursor:pointer;color:inherit}
  .al-chips{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}
  .al-chip{border:1px solid #d7ded9;background:#fff;border-radius:999px;padding:6px 12px;font:700 12.5px inherit;cursor:pointer;color:#33423a}
  .al-chip.on{background:#1b2430;border-color:#1b2430;color:#fff}.al-chip i{font-style:normal;opacity:.75;margin-left:6px}
  .al-sum{display:flex;gap:18px;flex-wrap:wrap;margin:0 0 10px;font-size:13px;color:#4a5a52}.al-sum b{font-size:20px;color:#1b2430;margin-right:5px}
  .al-tb td{vertical-align:top}.al-tb .who b{display:block}.al-tb .who span{font-size:12px;color:#6b7a73}
  .al-msg{max-width:46ch;white-space:pre-wrap;word-break:break-word}.al-ad{max-width:28ch;color:#4a5a52;font-size:12.5px}
  .al-new{display:inline-block;font-size:11px;font-weight:800;border-radius:999px;padding:2px 8px;background:#dcf7e6;color:#12794a}.al-new.r{background:#eef1ef;color:#4a5a52}
  .al-row{cursor:pointer}.al-row:hover{background:#f6faf7}
  .al-dg{border:1px solid #cfd8ff;background:#f5f7ff;border-radius:14px;padding:12px 14px;margin:0 0 12px}.al-dg h3{margin:0 0 6px;font-size:15px}
  .al-ck{display:flex;gap:8px;align-items:flex-start;font-size:13px;line-height:1.45;padding:3px 0}.al-ck i{font-style:normal;flex:none}
  .al-st{display:inline-block;font-size:11px;font-weight:800;border-radius:999px;padding:2px 8px;background:#eef1ef;color:#4a5a52;white-space:nowrap}.al-st.ok{background:#dcf7e6;color:#12794a}.al-st.bad{background:#fde6e6;color:#b42318}.al-st.warn{background:#fff3cd;color:#8a6a00}
  .al-dg table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:8px;background:#fff;border-radius:10px;overflow:hidden}.al-dg th,.al-dg td{padding:6px 8px;border-top:1px solid #edf0f7;text-align:right;white-space:nowrap}.al-dg th:first-child,.al-dg td:first-child{text-align:left;white-space:normal}.al-dg th{font-size:11px;text-transform:uppercase;color:#6b7a73;border-top:0}
  .al-tiles{display:flex;gap:8px;flex-wrap:wrap;margin:4px 0 10px}.al-tile{border:1px solid #dfe5fb;background:#fff;border-radius:12px;padding:8px 12px;min-width:118px;text-align:left;cursor:pointer;font:inherit;color:inherit}.al-tile b{display:block;font-size:22px;line-height:1.1}.al-tile span{font-size:12px;color:#5c6b64}.al-tile.on{background:#1b2430;border-color:#1b2430;color:#fff}.al-tile.on span{color:#d5dbe3}
  .al-out{min-width:210px;font-size:12.5px}.al-o{margin-top:4px;line-height:1.4;color:#33423a}
  .al-dgx{overflow-x:auto}.al-one{background:#f8faff;border:1px solid #dfe5fb;border-radius:10px;padding:8px 10px;font-size:12.5px;line-height:1.5}.al-tb .who .al-one b{display:inline;font-size:12.5px}.al-tb .who .al-one span{font-size:11px}
  .al-chk{border:1px solid #cfd8ff;background:#fff;border-radius:8px;padding:3px 9px;font:700 11.5px inherit;cursor:pointer;color:#3346a8;margin-top:4px}
  @media (max-width:820px){.al-tb,.al-tb tbody{display:block;width:100%}.al-tb thead{display:none}.al-tb tr{display:block;padding:8px 0;border-top:1px solid #edf1ee}.al-tb td{display:block;border:0;padding:2px 0}.al-msg,.al-ad{max-width:none}}
  `;
  WaPanels.add('adleads', (el, ctx) => {
    if (!document.getElementById('alCss')) { const s = document.createElement('style'); s.id = 'alCss'; s.textContent = CSS; document.head.appendChild(s); }
    let day = today(), number = 0, kind = 'rule', dgOpen = false, DG = null, dgText = '', LD = null, outF = '';
    const OUT = { shopify: ['🛒 Shopify order', 'ok'], manual: ['🧾 Manual order', 'warn'], photo_no_order: ['📷 Photo sent, no order', 'bad'], paid_no_order: ['💬 Says paid, no order', 'bad'], no_order: ['No order', ''] };
    const hm = (s) => { const w = when(s); const i = w.indexOf(', '); return i >= 0 ? w.slice(i + 2) : w; };
    function outcome(c, r) {   // what happened in one lead's chat (filled after Diagnose)
      if (!c) return '<span class="wp-hint">—</span>';
      let h = `<span class="al-st ${OUT[c.flag][1]}">${OUT[c.flag][0]}</span>`;
      (c.orders || []).forEach((o) => { h += `<div class="al-o"><b>${esc(o.name)}</b> · ${inr(o.total)} · ${esc(o.status || '')}${o.where === 'manual' ? `<br>placed by <b>${esc(o.placed_by || '?')}</b> · ${esc(o.pay || '')}${o.paid != null ? ' · paid ' + inr(o.paid) : ''}${o.utr ? ' · UTR ' + esc(o.utr) : ''} · ${o.proof ? 'screenshot attached' : '<span style="color:#b42318">no screenshot attached</span>'}${o.final ? '' : ' · not finished'}` : (o.given_to ? `<br>given to <b>${esc(o.given_to)}</b>` : '')}</div>`; });
      if (c.photos) h += `<div class="al-o">📷 ${c.photos} photo${c.photos > 1 ? 's' : ''} from the customer, last ${esc(hm(c.photo_at))}</div>`;
      if (c.says_paid) h += '<div class="al-o">💬 the customer wrote about a payment</div>';
      if (!(c.orders || []).length && r && r.holder) h += `<div class="al-o">chat held by <b>${esc(r.holder)}</b></div>`;
      return h;
    }
    function paintOutcomes() {
      const ok = DG && LD && DG.date === LD.date && DG.chat;
      el.querySelectorAll('[data-out]').forEach((td) => { const r = LD.rows[+td.dataset.out], c = ok ? DG.chat[r.phone] : null; td.innerHTML = ok ? outcome(c, r) : '<span class="wp-hint">Press Diagnose</span>'; const tr = td.closest('tr'); if (tr) tr.style.display = !outF || (c && c.flag === outF) ? '' : 'none'; });
    }
    const KINDS = [['rule', '✅ Ad leads', 'the ad’s ready-made first message, or Meta’s ad tag'], ['pre', '💬 Ready-made message', '“Can I get more info on this?”'], ['tag', '🏷 Meta ad tag', 'Meta sent the ad with the message'], ['other', '❔ Other', 'the HRMS guessed an ad from other words'], ['all', 'All', '']];
    el.innerHTML = loading;
    async function load() {
      let D; try { D = await api.get('wa.g_adleads', { date: day, number, kind }); } catch (e) { el.innerHTML = errBox(e.message); return; }
      LD = D;
      const allN = (D.numbers || []).reduce((s, n) => s + (n.n || 0), 0);
      const chips = [['0', 'All numbers', D.all != null ? D.all : allN]].concat((D.numbers || []).filter((n) => n.n > 0 || n.id === number).map((n) => [String(n.id), n.label || 'Number', n.n]));
      el.innerHTML = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>📣 Ad leads</h2><p>Customers who came from a Meta click-to-WhatsApp ad on the chosen day, with the first message they wrote. One line per customer.</p></div></div>
      <div class="al-bar"><button type="button" class="al-btn" id="alPrev">‹</button><input type="date" id="alDay" value="${esc(D.date)}" max="${today()}"><button type="button" class="al-btn" id="alNext" ${D.date >= today() ? 'disabled' : ''}>›</button><button type="button" class="al-btn" id="alToday">Today</button><button type="button" class="al-btn" id="alRef">Refresh</button><button type="button" class="al-btn" id="alDg" style="border-color:#cfd8ff;color:#3346a8">🩺 Diagnose</button></div>
      <div id="alDgBox"></div>
      <div class="al-chips">${KINDS.map(([k, l, tip]) => `<button type="button" class="al-chip ${kind === k ? 'on' : ''}" data-kind="${k}" title="${esc(tip)}">${l}<i>${(D.kinds || {})[k] != null ? D.kinds[k] : ''}</i></button>`).join('')}</div>
      <div class="al-chips">${chips.map(([k, l, n]) => `<button type="button" class="al-chip ${String(number) === k ? 'on' : ''}" data-n="${k}">📱 ${esc(l)}<i>${n}</i></button>`).join('')}</div>
      <div class="al-sum"><span><b>${D.total}</b>ad leads</span><span><b>${D.new}</b>new customers</span><span><b>${D.total - D.new}</b>came before</span></div>
      <div class="wp-cd">${D.rows.length ? `<table class="wp-tb al-tb"><thead><tr><th>Time</th><th>Customer</th><th>First message that day</th><th>Ad</th><th>Number</th><th>Order / payment</th></tr></thead><tbody>${D.rows.map((r, i) => `<tr class="al-row" data-i="${i}" title="Open the chat">
        <td style="white-space:nowrap">${esc(time(r.at))}</td>
        <td class="who"><b>${esc(r.name || r.phone)}</b><span>${esc(r.phone)}</span> <span class="al-new ${r.new ? '' : 'r'}">${r.new ? 'New' : 'Came before'}</span>${r.pre ? ' <span class="al-st ok">Ready-made message</span>' : ''}${r.tag ? ' <span class="al-st">Meta ad tag</span>' : ''}<br><button type="button" class="al-chk" data-chk="${i}">🩺 Check order</button><div class="al-onebox" data-one="${i}"></div></td>
        <td><div class="al-msg">${esc(r.first || '—')}</div></td>
        <td><div class="al-ad">${esc(r.ad || 'Meta ad')}</div></td>
        <td style="white-space:nowrap">${esc(r.number || '—')}</td><td class="al-out" data-out="${i}"></td></tr>`).join('')}</tbody></table>` : '<div class="wp-empty">No ad leads on this day' + (number ? ' for this number' : '') + '.</div>'}
      <div class="wp-hint" style="margin-top:8px">${D.more ? '<b>Only the newest 1,500 queries of the day are shown.</b> ' : ''}<b>✅ Ad leads</b> = the first message is the ad’s ready-made text (“Can I get more info on this?”) or the customer carries Meta’s ad tag. <b>❔ Other</b> = the HRMS guessed an ad from other words (for example a website or profile WhatsApp button). “New” = no earlier query or order. The ad shown is the first ad that brought the customer. The day runs midnight to midnight.</div></div></div>`;
      $(el, '#alDay').onchange = (e) => { if (e.target.value) { day = e.target.value; load(); } };
      $(el, '#alPrev').onclick = () => { day = shift(day, -1); load(); };
      $(el, '#alNext').onclick = () => { if (day < today()) { day = shift(day, 1); load(); } };
      $(el, '#alToday').onclick = () => { day = today(); load(); };
      $(el, '#alRef').onclick = load;
      $(el, '#alDg').onclick = () => { dgOpen = !dgOpen; if (dgOpen) diagnose(); else $(el, '#alDgBox').innerHTML = ''; };
      paintOutcomes(); if (dgOpen) diagnose();
      el.querySelectorAll('[data-chk]').forEach((b) => { b.onclick = (ev) => { ev.stopPropagation(); checkOne(+b.dataset.chk, D.rows[+b.dataset.chk]); }; });
      el.querySelectorAll('.al-chip[data-n]').forEach((b) => { b.onclick = () => { number = +b.dataset.n; load(); }; });
      el.querySelectorAll('.al-chip[data-kind]').forEach((b) => { b.onclick = () => { kind = b.dataset.kind; load(); }; });
      el.querySelectorAll('.al-row').forEach((tr) => { tr.onclick = () => { const r = D.rows[+tr.dataset.i]; if (r && ctx && ctx.openChat) ctx.openChat(r.phone); }; });
    }
    // 🩺 one customer: did the tracking service get the ad click, is there a Shopify order for this number, was it matched and told to Meta
    async function checkOne(i, r) {
      const box = el.querySelector('[data-one="' + i + '"]'); if (!box || !r) return; if (box.innerHTML) { box.innerHTML = ''; return; }
      box.innerHTML = '<div class="al-one">Checking…</div>'; box.onclick = (ev) => ev.stopPropagation();
      let O; try { O = await api.get('wa.g_adcheck_one', { phone: r.phone }); } catch (e) { box.innerHTML = '<div class="al-one">' + esc(e.message) + '</div>'; return; }
      if (!O.svc) { box.innerHTML = '<div class="al-one">❌ ' + esc(O.svc_error || 'The tracking service did not answer.') + '</div>'; return; }
      const ck = O.svc.clicks || [], od = O.svc.orders || [];
      let h = ck.length ? `✅ Ad click received: <b>${esc(ck[0].headline || ck[0].ad_id || 'Meta ad')}</b>, ${esc(unix(ck[0].at))}${ck.length > 1 ? ' (+' + (ck.length - 1) + ' earlier)' : ''}`
        : '⚠️ No ad click from this number reached the tracking service (it only knows clicks since it was switched on, or this customer wrote without clicking an ad).';
      h += '<br>' + (od.length ? od.map((o) => `🧾 Order <b>${esc(o.name || '')}</b> · ${inr(o.total)} · ${esc(o.financial_status || '')}${o.cancelled ? ' · cancelled' : ''} · ${esc(unix(o.at))}<br>&nbsp;&nbsp;&nbsp;${o.matched_ad_id ? '✅ matched to the ad' : '— not matched to an ad click (no click in the 7 days before it)'} ${o.matched_ad_id ? capiTag(o.capi_status) : ''}${o.capi_error ? ' <span style="color:#b42318">' + esc(o.capi_error) + '</span>' : ''}`).join('<br>')
        : '— No Shopify order seen for this number yet (the service only knows orders made or changed since it was switched on).');
      box.innerHTML = '<div class="al-one">' + h + '</div>';
    }
    // 🩺 the whole day: Meta, the tracking service and the HRMS side by side, per ad
    async function diagnose() {
      const box = $(el, '#alDgBox'); if (!box) return; box.innerHTML = '<div class="al-dg">Checking…</div>';
      try { DG = await api.get('wa.g_adcheck', { date: day, number }); } catch (e) { box.innerHTML = '<div class="al-dg">' + esc(e.message) + '</div>'; return; }
      const S = DG.svc, H = DG.hrms, L = [], T = [], line = (ic, tx, plain) => { L.push(`<div class="al-ck"><i>${ic}</i><span>${tx}</span></div>`); T.push(ic + ' ' + (plain || tx.replace(/<[^>]+>/g, ''))); };
      let table = '', ord = '';
      const M = DG.sum || {}, numName = number ? ((LD && LD.numbers || []).find((n) => n.id === number) || {}).label || 'this number' : 'all numbers';
      line('📋', `<b>${M.leads || 0} ad leads</b> on ${esc(numName)} (ready-made first message or Meta’s ad tag): <b>${M.shopify || 0}</b> ordered in Shopify (${inr(M.shopify_amt)}) · <b>${M.manual || 0}</b> by manual order (${inr(M.manual_amt)}) · <b>${M.photo_no_order || 0}</b> sent a photo but have no order · <b>${M.paid_no_order || 0}</b> wrote about a payment but have no order · ${M.no_order || 0} no order yet.`);
      const tiles = `<div class="al-tiles">${[['', 'Ad leads', M.leads || 0], ['shopify', '🛒 Shopify orders', M.shopify || 0], ['manual', '🧾 Manual orders', M.manual || 0], ['photo_no_order', '📷 Photo, no order', M.photo_no_order || 0], ['paid_no_order', '💬 Says paid, no order', M.paid_no_order || 0], ['no_order', 'No order yet', M.no_order || 0]].map(([k, l, n]) => `<button type="button" class="al-tile ${outF === k ? 'on' : ''}" data-of="${k}"><b>${n}</b><span>${l}</span></button>`).join('')}</div>`;
      const st = DG.staff || []; let staff = '';
      if (st.length) { staff = `<div class="al-dgx"><table><thead><tr><th>Salesperson</th><th>Shopify orders</th><th>Shopify ₹</th><th>Manual orders</th><th>Manual ₹</th><th>Manual without screenshot</th></tr></thead><tbody>${st.map((x) => `<tr><td>${esc(x.name)}</td><td>${x.shopify}</td><td>${inr(x.shopify_amt)}</td><td>${x.manual ? '<b>' + x.manual + '</b>' : 0}</td><td>${inr(x.manual_amt)}</td><td>${x.no_proof ? '<b style="color:#b42318">' + x.no_proof + '</b>' : 0}</td></tr>`).join('')}</tbody></table></div>`;
        T.push('', 'Salesperson | Shopify orders | Shopify Rs | Manual orders | Manual Rs | Manual without screenshot'); st.forEach((x) => T.push([x.name, x.shopify, Math.round(x.shopify_amt), x.manual, Math.round(x.manual_amt), x.no_proof].join(' | '))); T.push(''); }
      if (!S) line('❌', '<b>Tracking service:</b> ' + esc(DG.svc_error || 'no answer'));
      else {
        const hb = {}; (H.by_ad || []).forEach((a) => { hb[a.ad_id] = a.n; });
        const t = S.total || {}, sum = (k) => (S.ads || []).reduce((s, a) => s + ((a.capi || {})[k] || 0), 0), sent = sum('sent'), failed = sum('failed'), pend = sum('pending');
        const dayStart = Date.parse(DG.date + 'T00:00:00') / 1000, startedToday = S.health && S.health.first_click_at && S.health.first_click_at >= dayStart;
        line('✅', `<b>Tracking service is answering.</b> Last ad click ${esc(unix(S.health.last_click_at))}, last Shopify order ${esc(unix(S.health.last_order_at))}.`);
        const age = S.health.spend_sync_at ? Math.round((Date.now() / 1000 - S.health.spend_sync_at) / 60) : null;
        line(S.health.refreshing ? '🔄' : (age != null && age <= 10 ? '✅' : '⚠️'), `<b>Meta’s numbers were read ${age == null ? 'never' : esc(unix(S.health.spend_sync_at)) + ' (' + age + ' min ago)'}.</b>` + (S.health.refreshing ? ' Reading them again now: press Diagnose once more in a few seconds for the fresh numbers.' : ''));
        if (startedToday) line('ℹ️', `The service started counting on this day at ${esc(unix(S.health.first_click_at))}; earlier clicks of the day are not in its numbers.`);
        const mc = t.meta_conversations || 0, cl = t.queries || 0;
        if (mc > 0 && cl < mc * 0.5 && !startedToday) line('⚠️', `<b>Messages may not be reaching the service:</b> Meta counts ${mc} conversations, the service received ${cl} ad clicks.`);
        else line(cl > 0 ? '✅' : '⚠️', `<b>Ad clicks:</b> Meta counts ${mc} conversations · the service received ${cl} · the HRMS lists ${H.total} ad leads (${H.pre} with the ready-made message, ${H.tag} with Meta’s ad tag; ${H.new} new) and ${H.other} more it guessed from other words.`);
        line(S.health.orders_received_day > 0 ? '✅' : '⚠️', `<b>Shopify orders reaching the service:</b> ${S.health.orders_received_day} on this day.`);
        line(t.orders > 0 ? '✅' : 'ℹ️', `<b>Orders matched to an ad click:</b> ${t.orders || 0} (${t.confirmed_orders || 0} paid) · revenue ${inr(t.revenue)} · spend ${inr(t.spend)} · ROAS ${t.roas != null ? t.roas.toFixed(2) : '—'}.`);
        line(failed ? '❌' : sent ? '✅' : 'ℹ️', `<b>Purchases told to Meta:</b> ${sent} accepted · ${failed} refused · ${pend} waiting.` + (failed ? ' See the reason on the orders below.' : !sent ? ' Nothing to send yet (no matched paid order).' : ''));
        const ads = S.ads || []; const seen = {};
        const rows = ads.map((a) => { seen[a.ad_id] = 1; const c = a.capi || {}; return [(a.ad_name || a.ad_id) + (a.campaign_name ? '  ·  ' + a.campaign_name : ''), inr(a.spend), a.meta_conversations || 0, a.clicks || 0, hb[a.ad_id] || 0, a.orders || 0, a.confirmed_orders || 0, inr(a.revenue), a.roas != null ? a.roas.toFixed(2) : '—', (c.sent || 0) + ' / ' + (c.failed || 0) + ' / ' + (c.pending || 0)]; });
        (H.by_ad || []).forEach((a) => { if (!seen[a.ad_id]) rows.push([(a.title || 'Meta ad') + ' (ad tag in the HRMS only)', '—', '—', 0, a.n, 0, 0, '—', '—', '—']); });
        rows.push(['TOTAL', inr(t.spend), t.meta_conversations || 0, t.queries || 0, H.tag, t.orders || 0, t.confirmed_orders || 0, inr(t.revenue), t.roas != null ? t.roas.toFixed(2) : '—', sent + ' / ' + failed + ' / ' + pend]);
        const HEAD = ['Ad · campaign', 'Spend', 'Meta conv.', 'Clicks (service)', 'Ad leads (HRMS)', 'Orders', 'Paid', 'Revenue', 'ROAS', 'Meta: ok / refused / waiting'];
        table = rows.length ? `<div class="al-dgx"><table><thead><tr>${HEAD.map((x) => '<th>' + x + '</th>').join('')}</tr></thead><tbody>${rows.map((r) => '<tr>' + r.map((c) => '<td>' + esc(String(c)) + '</td>').join('') + '</tr>').join('')}</tbody></table></div>` : '';
        T.push('', HEAD.join(' | ')); rows.forEach((r) => T.push(r.join(' | ')));
        const os = S.orders || [];
        if (os.length) { ord = `<div class="al-dgx"><table><thead><tr><th>Matched order</th><th>Ad</th><th>Amount</th><th>Payment</th><th>Meta</th></tr></thead><tbody>${os.map((o) => `<tr><td>${esc(o.name || '')}${o.cancelled ? ' (cancelled)' : ''}</td><td>${esc(o.ad_name || o.ad_id || '')}</td><td>${inr(o.total)}</td><td>${esc(o.financial_status || '')}</td><td>${capiTag(o.capi_status)}${o.capi_error ? '<div style="color:#b42318;white-space:normal;text-align:left">' + esc(o.capi_error) + '</div>' : ''}</td></tr>`).join('')}</tbody></table></div>`;
          T.push('', 'Matched orders:'); os.forEach((o) => T.push([o.name, o.ad_name || o.ad_id, inr(o.total), o.financial_status, o.capi_status, o.capi_error || ''].join(' | '))); }
      }
      dgText = 'Diagnose ' + DG.date + ' (checked ' + DG.now + ')\n' + T.join('\n');
      box.innerHTML = `<div class="al-dg"><h3>🩺 Diagnose · ${esc(DG.date)}</h3>${tiles}${L.join('')}${staff}${table}${ord}<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center"><button type="button" class="al-btn" id="alCopy">Copy report</button><span class="wp-hint">Tap a tile to see only those customers in the list below. A photo is counted, not read: open the chat to see whether it is a payment screenshot. The report has no customer names or numbers. The per-ad HRMS column counts only customers with Meta’s ad tag, under the FIRST ad that brought them, so it can differ from the service. Every WhatsApp ad Meta counted a conversation for is listed, also when none of its clicks reached the service yet.</span></div></div>`;
      box.querySelectorAll('[data-of]').forEach((b) => { b.onclick = () => { outF = outF === b.dataset.of ? '' : b.dataset.of; box.querySelectorAll('[data-of]').forEach((x) => x.classList.toggle('on', x.dataset.of === outF && outF !== '')); paintOutcomes(); }; });
      paintOutcomes();
      $(el, '#alCopy').onclick = async () => { try { await navigator.clipboard.writeText(dgText); toast('Report copied'); } catch (e) { const ta = document.createElement('textarea'); ta.value = dgText; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); toast('Report copied'); } catch (e2) { toast('Could not copy'); } ta.remove(); } };
    }
    load(); return load;
  });
})();
