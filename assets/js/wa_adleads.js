/**
 * wa_adleads.js — WhatsApp → Queries & reports → 📣 WhatsApp Ads (whatsapp.html, Admin / HR).
 * One page for the click-to-WhatsApp ads of a day and a number. It fills by itself when opened (no button to press):
 *   · the numbers on top (spend, Meta's conversations, WhatsApp ad leads, CPR, CPC, orders, revenue, ROAS)
 *   · the ads, like Ads Manager, with what the HRMS found for each (leads, orders, revenue) — tap an ad to see its customers
 *   · what happened to the leads (Shopify order / manual order / photo sent but no order …) and who placed the orders
 *   · the customers: first message of the day, ad, number, order / payment
 *   · a small system check at the bottom (tracking service, Meta read time, purchases told to Meta)
 * An ad lead = the first message is the ad's ready-made text ("Can I get more info on this?") or the customer carries Meta's ad tag.
 * Reads wa.g_adleads, wa.g_adcheck, wa.g_adcheck_one (wa_adleads.php). It loads when opened, when a filter changes and on Refresh — never by a timer.
 * Needs api.js and wa_panels.js (loaded first).
 */
(function () {
  if (!window.WaPanels) return;
  const { esc, $, when, errBox, loading, toast } = WaPanels.util;
  const inr = (n) => '₹' + Math.round(+n || 0).toLocaleString('en-IN');
  const per = (sp, n) => (n > 0 ? '₹' + (sp / n).toFixed(2) : '—');   // CPR = spend ÷ Meta's conversations · CPC (all) = spend ÷ Meta's clicks (all), as in Ads Manager
  const x2 = (rev, sp) => (sp > 0 ? (rev / sp).toFixed(2) : '—');
  const unix = (t) => (t ? new Date(t * 1000).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', hour12: true }) : '—');
  const CAPI = { sent: ['Told to Meta', 'ok'], failed: ['Meta refused', 'bad'], pending: ['Waiting to send', 'warn'], expired: ['Too old to send', 'bad'], none: ['Not confirmed yet', ''] };
  const capiTag = (st) => { const c = CAPI[st] || [st || '—', '']; return `<span class="al-st ${c[1]}">${esc(c[0])}</span>`; };
  const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const shift = (day, n) => { const d = new Date(day + 'T12:00:00'); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const time = (s) => { const w = when(s); const i = w.indexOf(', '); return i >= 0 ? w.slice(i + 2) : w; };
  const KINDS = [['rule', '✅ Ad leads', 'the ad’s ready-made first message, or Meta’s ad tag'], ['pre', '💬 Ready-made message', '“Can I get more info on this?”'], ['tag', '🏷 Meta ad tag', 'Meta sent the ad with the message'], ['other', '❔ Other', 'the HRMS guessed an ad from other words'], ['all', 'All', '']];
  const OUT = { shopify: ['🛒 Shopify order', 'ok'], manual: ['🧾 Manual order', 'warn'], photo_no_order: ['📷 Photo sent, no order', 'bad'], paid_no_order: ['💬 Says paid, no order', 'bad'], no_order: ['No order', ''] };
  const CSS = `
  .al-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 10px}
  .al-bar input[type=date]{min-height:36px;padding:4px 8px;border:1px solid #d7ded9;border-radius:10px;font:inherit;width:auto}
  .al-btn{border:1px solid #d7ded9;background:#fff;border-radius:10px;min-height:36px;padding:0 12px;font:700 12.5px inherit;cursor:pointer;color:inherit}.al-btn:disabled{opacity:.5;cursor:default}
  .al-chips{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}
  .al-chip{border:1px solid #d7ded9;background:#fff;border-radius:999px;padding:6px 12px;font:700 12.5px inherit;cursor:pointer;color:#33423a}
  .al-chip.on{background:#1b2430;border-color:#1b2430;color:#fff}.al-chip i{font-style:normal;opacity:.75;margin-left:6px}
  .al-h{font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#5c6b64;margin:16px 2px 8px}
  .al-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(128px,1fr));gap:8px;margin:0 0 4px}
  .al-k{border:1px solid #e3e8e5;background:#fff;border-radius:14px;padding:10px 12px}.al-k b{display:block;font-size:21px;line-height:1.15;font-variant-numeric:tabular-nums}.al-k span{font-size:12px;color:#5c6b64}.al-k.hi{background:#1b2430;border-color:#1b2430;color:#fff}.al-k.hi span{color:#d5dbe3}
  .al-card{border:1px solid #e3e8e5;background:#fff;border-radius:14px;overflow:hidden}.al-x{overflow-x:auto}
  .al-t{width:100%;border-collapse:collapse;font-size:13px}.al-t th,.al-t td{padding:8px 10px;border-top:1px solid #eef1ef;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
  .al-t th{font-size:11px;text-transform:uppercase;color:#6b7a73;border-top:0;background:#f8faf9}.al-t th:first-child,.al-t td:first-child{text-align:left;white-space:normal;min-width:200px}
  .al-t td small{display:block;color:#6b7a73;font-size:11.5px}.al-t tr.tot td{font-weight:800;background:#f8faf9}.al-t tr.pick{cursor:pointer}.al-t tr.pick:hover{background:#f6faf7}.al-t tr.on td{background:#eef4ff}
  .al-tiles{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 10px}.al-tile{border:1px solid #e3e8e5;background:#fff;border-radius:12px;padding:8px 12px;min-width:118px;text-align:left;cursor:pointer;font:inherit;color:inherit}.al-tile b{display:block;font-size:22px;line-height:1.1}.al-tile span{font-size:12px;color:#5c6b64}.al-tile.on{background:#1b2430;border-color:#1b2430;color:#fff}.al-tile.on span{color:#d5dbe3}
  .al-st{display:inline-block;font-size:11px;font-weight:800;border-radius:999px;padding:2px 8px;background:#eef1ef;color:#4a5a52;white-space:nowrap}.al-st.ok{background:#dcf7e6;color:#12794a}.al-st.bad{background:#fde6e6;color:#b42318}.al-st.warn{background:#fff3cd;color:#8a6a00}
  .al-sum{display:flex;gap:18px;flex-wrap:wrap;margin:0 0 10px;font-size:13px;color:#4a5a52}.al-sum b{font-size:20px;color:#1b2430;margin-right:5px}
  .al-tb td{vertical-align:top}.al-tb .who b{display:block}.al-tb .who span{font-size:12px;color:#6b7a73}
  .al-msg{max-width:46ch;white-space:pre-wrap;word-break:break-word}.al-ad{max-width:28ch;color:#4a5a52;font-size:12.5px}
  .al-new{display:inline-block;font-size:11px;font-weight:800;border-radius:999px;padding:2px 8px;background:#dcf7e6;color:#12794a}.al-new.r{background:#eef1ef;color:#4a5a52}
  .al-row{cursor:pointer}.al-row:hover{background:#f6faf7}
  .al-out{min-width:210px;font-size:12.5px}.al-o{margin-top:4px;line-height:1.4;color:#33423a}
  .al-tb .who .al-one{background:#f8faff;border:1px solid #dfe5fb;border-radius:10px;padding:8px 10px;font-size:12.5px;line-height:1.5;margin-top:4px}.al-tb .who .al-one b{display:inline;font-size:12.5px}.al-tb .who .al-one span{font-size:11px}
  .al-chk{border:1px solid #cfd8ff;background:#fff;border-radius:8px;padding:3px 9px;font:700 11.5px inherit;cursor:pointer;color:#3346a8;margin-top:4px}
  .al-sys{border:1px solid #e3e8e5;background:#fff;border-radius:14px;padding:8px 12px;margin-top:14px;font-size:13px}.al-sys summary{cursor:pointer;font-weight:800}.al-sys.bad{border-color:#f3b4ae;background:#fff7f6}
  .al-ck{display:flex;gap:8px;align-items:flex-start;line-height:1.45;padding:3px 0}.al-ck i{font-style:normal;flex:none}
  .al-note{font-size:12px;color:#6b7a73;margin:6px 2px 0;line-height:1.45}
  @media (max-width:820px){.al-tb,.al-tb tbody{display:block;width:100%}.al-tb thead{display:none}.al-tb tr{display:block;padding:8px 0;border-top:1px solid #edf1ee}.al-tb td{display:block;border:0;padding:2px 0}.al-msg,.al-ad{max-width:none}}
  `;
  WaPanels.add('adleads', (el, ctx) => {
    if (!document.getElementById('alCss')) { const s = document.createElement('style'); s.id = 'alCss'; s.textContent = CSS; document.head.appendChild(s); }
    let day = today(), number = 0, kind = 'rule', LD = null, DG = null, outF = '', adF = '', report = '', seq = 0, again = null;
    el.innerHTML = loading;

    function outcome(c, r) {   // what happened in one lead's chat
      if (!c) return '<span class="wp-hint">—</span>';
      let h = `<span class="al-st ${OUT[c.flag][1]}">${OUT[c.flag][0]}</span>`;
      (c.orders || []).forEach((o) => { h += `<div class="al-o"><b>${esc(o.name)}</b> · ${inr(o.total)} · ${esc(o.status || '')}${o.where === 'manual' ? `<br>placed by <b>${esc(o.placed_by || '?')}</b> · ${esc(o.pay || '')}${o.paid != null ? ' · paid ' + inr(o.paid) : ''}${o.utr ? ' · UTR ' + esc(o.utr) : ''} · ${o.proof ? 'screenshot attached' : '<span style="color:#b42318">no screenshot attached</span>'}${o.final ? '' : ' · not finished'}` : (o.given_to ? `<br>given to <b>${esc(o.given_to)}</b>` : '')}</div>`; });
      if (c.photos) h += `<div class="al-o">📷 ${c.photos} photo${c.photos > 1 ? 's' : ''} from the customer, last ${esc(time(c.photo_at))}</div>`;
      if (c.says_paid) h += '<div class="al-o">💬 the customer wrote about a payment</div>';
      if (!(c.orders || []).length && r && r.holder) h += `<div class="al-o">chat held by <b>${esc(r.holder)}</b></div>`;
      return h;
    }
    function paintRows() {   // the order / payment column and the two filters (what happened · one ad)
      if (!LD) return; const ok = DG && DG.date === LD.date && DG.chat; let shown = 0;
      el.querySelectorAll('[data-out]').forEach((td) => {
        const r = LD.rows[+td.dataset.out], c = ok ? DG.chat[r.phone] : null; td.innerHTML = ok ? outcome(c, r) : '<span class="wp-hint">Checking…</span>';
        const show = (!outF || (c && c.flag === outF)) && (!adF || (adF === '-' ? !r.tag : r.ad_id === adF)); const tr = td.closest('tr'); if (tr) tr.style.display = show ? '' : 'none'; if (show) shown++;
      });
      const f = $(el, '#alFilt'); if (f) f.innerHTML = (outF || adF) ? `Showing ${shown} of ${LD.rows.length} customers <button type="button" class="al-btn" id="alClr" style="min-height:28px;margin-left:6px">Show all</button>` : '';
      const c = $(el, '#alClr'); if (c) c.onclick = () => { outF = ''; adF = ''; paintTop(); paintRows(); };
    }

    // ---------------------------------------------------------------- the top of the page: numbers, ads, what happened, who, system check
    function paintTop() {
      const box = $(el, '#alTop'); if (!box) return;
      if (!DG) { box.innerHTML = '<div class="al-card" style="padding:14px">Reading Meta, the orders and the chats…</div>'; return; }
      const S = DG.svc, H = DG.hrms, M = DG.sum || {}, T = [], t = (S && S.total) || {}, ads = (S && S.ads) || [];
      const rev = (M.shopify_amt || 0) + (M.manual_amt || 0), ordered = (M.shopify || 0) + (M.manual || 0), spend = t.spend || 0;
      const numName = number ? ((LD && LD.numbers || []).find((n) => n.id === number) || {}).label || 'this number' : 'all numbers';
      const kpi = [['hi', inr(spend), 'Spend'], ['', t.meta_conversations || 0, 'Meta conversations'], ['', H.total, 'WhatsApp ad leads'], ['', per(spend, t.meta_conversations), 'CPR (cost per conversation)'], ['', per(spend, t.meta_clicks), 'CPC (all)'], ['', ordered, 'Leads who ordered'], ['', inr(rev), 'Revenue'], ['hi', x2(rev, spend), 'ROAS']];
      T.push('WhatsApp Ads ' + DG.date + ' · ' + numName + ' (read ' + DG.now + ')', kpi.map((k) => k[2] + ': ' + k[1]).join(' | '), '');
      // ads, like Ads Manager, with what the HRMS found
      const hb = {}; (H.by_ad || []).forEach((a) => { hb[a.ad_id] = a; }); const seen = {};
      const rows = ads.map((a) => { seen[a.ad_id] = 1; const h = hb[a.ad_id] || { n: 0, orders: 0, amt: 0 }; return { id: a.ad_id, name: a.ad_name || a.ad_id, camp: a.campaign_name || '', c: [inr(a.spend), a.meta_conversations || 0, per(a.spend, a.meta_conversations), per(a.spend, a.meta_clicks), h.n, h.orders, inr(h.amt), x2(h.amt, a.spend)] }; });
      (H.by_ad || []).forEach((a) => { if (!seen[a.ad_id]) rows.push({ id: a.ad_id, name: a.title || 'Meta ad', camp: 'not in the connected ad accounts', c: ['—', '—', '—', '—', a.n, a.orders, inr(a.amt), '—'] }); });
      const nt = H.no_tag || { n: 0, orders: 0, amt: 0 }; if (nt.n) rows.push({ id: '-', name: 'Ready-made message, no ad tag', camp: 'the ad is not known', c: ['—', '—', '—', '—', nt.n, nt.orders, inr(nt.amt), '—'] });
      const HEAD = ['Ad', 'Spend', 'Meta conv.', 'CPR', 'CPC (all)', 'Ad leads', 'Orders', 'Revenue', 'ROAS'];
      const totC = [inr(spend), t.meta_conversations || 0, per(spend, t.meta_conversations), per(spend, t.meta_clicks), H.total, (H.by_ad || []).reduce((s, a) => s + a.orders, 0) + nt.orders, inr(rev), x2(rev, spend)];
      const adsTb = rows.length ? `<div class="al-card al-x"><table class="al-t"><thead><tr>${HEAD.map((x) => '<th>' + x + '</th>').join('')}</tr></thead><tbody>${rows.map((r) => `<tr class="pick ${adF === r.id ? 'on' : ''}" data-ad="${esc(r.id)}" title="Show this ad’s customers"><td>${esc(r.name)}<small>${esc(r.camp)}</small></td>${r.c.map((c) => '<td>' + esc(String(c)) + '</td>').join('')}</tr>`).join('')}<tr class="tot"><td>Total</td>${totC.map((c) => '<td>' + esc(String(c)) + '</td>').join('')}</tr></tbody></table></div>`
        : `<div class="al-card" style="padding:14px">${S ? 'No click-to-WhatsApp ad on this day.' : 'The ads could not be read: ' + esc(DG.svc_error || 'the tracking service did not answer') + '.'}</div>`;
      T.push(HEAD.join(' | ')); rows.forEach((r) => T.push([r.name + (r.camp ? ' · ' + r.camp : '')].concat(r.c).join(' | '))); T.push(['TOTAL'].concat(totC).join(' | '), '');
      // what happened to the leads
      const tl = [['', 'Ad leads', M.leads || 0], ['shopify', '🛒 Shopify orders', M.shopify || 0], ['manual', '🧾 Manual orders', M.manual || 0], ['photo_no_order', '📷 Photo, no order', M.photo_no_order || 0], ['paid_no_order', '💬 Says paid, no order', M.paid_no_order || 0], ['no_order', 'No order yet', M.no_order || 0]];
      T.push('What happened to the ad leads: ' + tl.map((x) => x[1].replace(/^[^A-Za-z]+/, '') + ' ' + x[2]).join(' | '), '');
      const st = DG.staff || [];
      const staff = st.length ? `<div class="al-card al-x"><table class="al-t"><thead><tr><th>Salesperson</th><th>Shopify orders</th><th>Shopify ₹</th><th>Manual orders</th><th>Manual ₹</th><th>Manual without screenshot</th></tr></thead><tbody>${st.map((x) => `<tr><td>${esc(x.name)}</td><td>${x.shopify}</td><td>${inr(x.shopify_amt)}</td><td>${x.manual ? '<b>' + x.manual + '</b>' : 0}</td><td>${inr(x.manual_amt)}</td><td>${x.no_proof ? '<b style="color:#b42318">' + x.no_proof + '</b>' : 0}</td></tr>`).join('')}</tbody></table></div>`
        : '<div class="al-card" style="padding:12px 14px;color:#5c6b64">No order found for these ad leads yet, so there is nobody to list.</div>';
      if (st.length) { T.push('Salesperson | Shopify orders | Shopify Rs | Manual orders | Manual Rs | Manual without screenshot'); st.forEach((x) => T.push([x.name, x.shopify, Math.round(x.shopify_amt), x.manual, Math.round(x.manual_amt), x.no_proof].join(' | '))); T.push(''); }
      // system check
      const L = []; let bad = false; const line = (ic, tx) => { L.push(`<div class="al-ck"><i>${ic}</i><span>${tx}</span></div>`); T.push(ic + ' ' + tx.replace(/<[^>]+>/g, '')); if (ic === '❌') bad = true; };
      let ord = '', refreshing = false;
      if (!S) line('❌', '<b>Tracking service:</b> ' + esc(DG.svc_error || 'no answer') + ' Spend, Meta’s numbers and the purchases told to Meta are missing; the leads and orders below come from the HRMS and are complete.');
      else {
        const hl = S.health || {}, sum = (k) => ads.reduce((s, a) => s + ((a.capi || {})[k] || 0), 0), sent = sum('sent'), failed = sum('failed'), pend = sum('pending');
        const age = hl.spend_sync_at ? Math.max(0, Math.round((Date.now() / 1000 - hl.spend_sync_at) / 60)) : null; refreshing = !!hl.refreshing;
        line('✅', `<b>Tracking service is answering.</b> Last ad click ${esc(unix(hl.last_click_at))}, last Shopify order ${esc(unix(hl.last_order_at))}; ${hl.orders_received_day || 0} Shopify orders reached it on this day.`);
        line(refreshing ? '🔄' : (age != null && age <= 10 ? '✅' : '⚠️'), `<b>Meta’s numbers were read ${age == null ? 'never' : esc(unix(hl.spend_sync_at)) + ' (' + age + ' min ago)'}.</b>` + (refreshing ? ' Reading them again now; this page updates by itself in a few seconds.' : ''));
        const dayStart = Date.parse(DG.date + 'T00:00:00') / 1000, startedToday = hl.first_click_at && hl.first_click_at >= dayStart, mc = t.meta_conversations || 0;
        const diff = mc - H.total; line(Math.abs(diff) <= Math.max(5, mc * 0.1) ? '✅' : '⚠️', `<b>Meta against WhatsApp:</b> Meta counts ${mc} conversations, the HRMS lists ${H.total} ad leads (${H.pre} with the ready-made message, ${H.tag} with Meta’s ad tag)${diff > 0 ? ' — ' + diff + ' fewer in WhatsApp' : diff < 0 ? ' — ' + (-diff) + ' more in WhatsApp' : ''}. ${H.other} more chats were guessed as ads from other words and are not counted.`);
        line('ℹ️', `<b>Ad clicks the service received:</b> ${t.queries || 0}.` + (startedToday ? ` It started counting on this day at ${esc(unix(hl.first_click_at))}, so earlier clicks are missing from this number.` : ''));
        line(failed ? '❌' : sent ? '✅' : 'ℹ️', `<b>Purchases told to Meta:</b> ${sent} accepted · ${failed} refused · ${pend} waiting.` + (failed ? ' The reason is on the orders below.' : !sent ? ' Nothing sent yet: the service has not matched a paid Shopify order to an ad click. Manual orders are not sent to Meta yet.' : ''));
        const os = S.orders || [];
        if (os.length) { ord = `<div class="al-x" style="margin-top:6px"><table class="al-t"><thead><tr><th>Order sent to Meta</th><th>Ad</th><th>Amount</th><th>Payment</th><th>Meta</th></tr></thead><tbody>${os.map((o) => `<tr><td>${esc(o.name || '')}${o.cancelled ? ' (cancelled)' : ''}</td><td>${esc(o.ad_name || o.ad_id || '')}</td><td>${inr(o.total)}</td><td>${esc(o.financial_status || '')}</td><td>${capiTag(o.capi_status)}${o.capi_error ? '<div style="color:#b42318;white-space:normal">' + esc(o.capi_error) + '</div>' : ''}</td></tr>`).join('')}</tbody></table></div>`;
          T.push('', 'Orders matched by the service:'); os.forEach((o) => T.push([o.name, o.ad_name || o.ad_id, inr(o.total), o.financial_status, o.capi_status, o.capi_error || ''].join(' | '))); }
      }
      report = T.join('\n');
      box.innerHTML = `<div class="al-kpi">${kpi.map(([c, v, l]) => `<div class="al-k ${c}"><b>${esc(String(v))}</b><span>${l}</span></div>`).join('')}</div>
        <div class="al-note">For ${esc(numName)}. Orders and revenue are what the HRMS order list holds for these ad leads (Shopify and manual orders, matched on the phone number, from this day on).</div>
        <div class="al-h">Ads</div>${adsTb}<div class="al-note">Spend, conversations, CPR and CPC come from Meta. Ad leads, orders and revenue per ad count the customers who carry that ad’s tag, under the first ad that brought them. Tap an ad to see its customers.</div>
        <div class="al-h">What happened to the ad leads</div><div class="al-tiles">${tl.map(([k, l, n]) => `<button type="button" class="al-tile ${outF === k && k ? 'on' : ''}" data-of="${k}"><b>${n}</b><span>${l}</span></button>`).join('')}</div>
        <div class="al-h">Who placed the orders</div>${staff}
        <details class="al-sys ${bad ? 'bad' : ''}" ${bad ? 'open' : ''}><summary>${bad ? '❌ System check: something needs attention' : '✅ System check'} <button type="button" class="al-btn" id="alCopy" style="min-height:28px;margin-left:8px">Copy report</button></summary>${L.join('')}${ord}<div class="al-note">A photo is counted, not read: open the chat to see whether it is a payment screenshot. The report has no customer names or numbers.</div></details>`;
      box.querySelectorAll('[data-of]').forEach((b) => { b.onclick = () => { outF = outF === b.dataset.of ? '' : b.dataset.of; paintTop(); paintRows(); }; });
      box.querySelectorAll('[data-ad]').forEach((r) => { r.onclick = () => { adF = adF === r.dataset.ad ? '' : r.dataset.ad; paintTop(); paintRows(); }; });
      $(el, '#alCopy').onclick = async (ev) => { ev.preventDefault(); try { await navigator.clipboard.writeText(report); toast('Report copied'); } catch (e) { const ta = document.createElement('textarea'); ta.value = report; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); toast('Report copied'); } catch (e2) { toast('Could not copy'); } ta.remove(); } };
      clearTimeout(again); if (refreshing) again = setTimeout(() => { if (el.isConnected) check(true); }, 7000);   // Meta is being read again: show the fresh numbers once, by itself
    }
    async function check(quiet) {   // the day's numbers, orders and chats (one call)
      const my = seq; if (!quiet) { DG = null; paintTop(); paintRows(); }
      let d; try { d = await api.get('wa.g_adcheck', { date: day, number }); } catch (e) { if (my === seq) { const b = $(el, '#alTop'); if (b) b.innerHTML = errBox(e.message); } return; }
      if (my !== seq) return; DG = d; paintTop(); paintRows();
    }

    // 🩺 one customer, from the tracking service: the ad click it received, the Shopify orders it saw, matched, told to Meta
    async function checkOne(i, r) {
      const box = el.querySelector('[data-one="' + i + '"]'); if (!box || !r) return; if (box.innerHTML) { box.innerHTML = ''; return; }
      box.innerHTML = '<div class="al-one">Checking…</div>'; box.onclick = (ev) => ev.stopPropagation();
      let O; try { O = await api.get('wa.g_adcheck_one', { phone: r.phone }); } catch (e) { box.innerHTML = '<div class="al-one">' + esc(e.message) + '</div>'; return; }
      if (!O.svc) { box.innerHTML = '<div class="al-one">❌ ' + esc(O.svc_error || 'The tracking service did not answer.') + '</div>'; return; }
      const ck = O.svc.clicks || [], od = O.svc.orders || [];
      let h = ck.length ? `✅ Ad click received by the service: <b>${esc(ck[0].headline || ck[0].ad_id || 'Meta ad')}</b>, ${esc(unix(ck[0].at))}${ck.length > 1 ? ' (+' + (ck.length - 1) + ' earlier)' : ''}`
        : '⚠️ No ad click from this number reached the tracking service (it only knows clicks since it was switched on).';
      h += '<br>' + (od.length ? od.map((o) => `🧾 Shopify order <b>${esc(o.name || '')}</b> · ${inr(o.total)} · ${esc(o.financial_status || '')}${o.cancelled ? ' · cancelled' : ''} · ${esc(unix(o.at))}<br>&nbsp;&nbsp;&nbsp;${o.matched_ad_id ? '✅ matched to the ad' : '— not matched to an ad click'} ${o.matched_ad_id ? capiTag(o.capi_status) : ''}${o.capi_error ? ' <span style="color:#b42318">' + esc(o.capi_error) + '</span>' : ''}`).join('<br>')
        : '— The service has seen no Shopify order for this number.');
      box.innerHTML = '<div class="al-one">' + h + '</div>';
    }

    // ---------------------------------------------------------------- the customers
    async function load() {
      const my = ++seq; clearTimeout(again);
      let D; try { D = await api.get('wa.g_adleads', { date: day, number, kind }); } catch (e) { if (my === seq) el.innerHTML = errBox(e.message); return; }
      if (my !== seq) return; LD = D;
      const chips = [['0', 'All numbers', D.all != null ? D.all : 0]].concat((D.numbers || []).filter((n) => n.n > 0 || n.id === number).map((n) => [String(n.id), n.label || 'Number', n.n]));
      el.innerHTML = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>📣 WhatsApp Ads</h2><p>The click-to-WhatsApp ads of one day: what Meta counted, who reached WhatsApp, who ordered and who placed the order.</p></div></div>
      <div class="al-bar"><button type="button" class="al-btn" id="alPrev">‹</button><input type="date" id="alDay" value="${esc(D.date)}" max="${today()}"><button type="button" class="al-btn" id="alNext" ${D.date >= today() ? 'disabled' : ''}>›</button><button type="button" class="al-btn" id="alToday">Today</button><button type="button" class="al-btn" id="alRef">↻ Refresh</button></div>
      <div class="al-chips">${chips.map(([k, l, n]) => `<button type="button" class="al-chip ${String(number) === k ? 'on' : ''}" data-n="${k}">📱 ${esc(l)}<i>${n}</i></button>`).join('')}</div>
      <div id="alTop"></div>
      <div class="al-h">Customers</div>
      <div class="al-chips">${KINDS.map(([k, l, tip]) => `<button type="button" class="al-chip ${kind === k ? 'on' : ''}" data-kind="${k}" title="${esc(tip)}">${l}<i>${(D.kinds || {})[k] != null ? D.kinds[k] : ''}</i></button>`).join('')}</div>
      <div class="al-sum"><span><b>${D.total}</b>customers</span><span><b>${D.new}</b>new</span><span><b>${D.total - D.new}</b>came before</span><span id="alFilt"></span></div>
      <div class="wp-cd">${D.rows.length ? `<table class="wp-tb al-tb"><thead><tr><th>Time</th><th>Customer</th><th>First message that day</th><th>Ad</th><th>Number</th><th>Order / payment</th></tr></thead><tbody>${D.rows.map((r, i) => `<tr class="al-row" data-i="${i}" title="Open the chat">
        <td style="white-space:nowrap">${esc(time(r.at))}</td>
        <td class="who"><b>${esc(r.name || r.phone)}</b><span>${esc(r.phone)}</span> <span class="al-new ${r.new ? '' : 'r'}">${r.new ? 'New' : 'Came before'}</span>${r.pre ? ' <span class="al-st ok">Ready-made message</span>' : ''}${r.tag ? ' <span class="al-st">Meta ad tag</span>' : ''}<br><button type="button" class="al-chk" data-chk="${i}">🩺 Check with Meta</button><div class="al-onebox" data-one="${i}"></div></td>
        <td><div class="al-msg">${esc(r.first || '—')}</div></td>
        <td><div class="al-ad">${esc(r.ad || 'Meta ad')}</div></td>
        <td style="white-space:nowrap">${esc(r.number || '—')}</td><td class="al-out" data-out="${i}"></td></tr>`).join('')}</tbody></table>` : '<div class="wp-empty">No customers of this kind on this day' + (number ? ' for this number' : '') + '.</div>'}
      <div class="wp-hint" style="margin-top:8px">${D.more ? '<b>Only the newest 1,500 queries of the day are shown.</b> ' : ''}<b>✅ Ad leads</b> = the first message is the ad’s ready-made text (“Can I get more info on this?”) or the customer carries Meta’s ad tag. <b>❔ Other</b> = the HRMS guessed an ad from other words (for example a website or profile WhatsApp button). “New” = no earlier query or order. The ad shown is the first ad that brought the customer. The day runs midnight to midnight.</div></div></div>`;
      $(el, '#alDay').onchange = (e) => { if (e.target.value) { day = e.target.value; reset(); } };
      $(el, '#alPrev').onclick = () => { day = shift(day, -1); reset(); };
      $(el, '#alNext').onclick = () => { if (day < today()) { day = shift(day, 1); reset(); } };
      $(el, '#alToday').onclick = () => { day = today(); reset(); };
      $(el, '#alRef').onclick = () => reset();
      el.querySelectorAll('.al-chip[data-n]').forEach((b) => { b.onclick = () => { number = +b.dataset.n; reset(); }; });
      el.querySelectorAll('.al-chip[data-kind]').forEach((b) => { b.onclick = () => { kind = b.dataset.kind; load().then(() => { if (!DG) check(); }); }; });   // the numbers on top do not depend on the kind of customers listed
      el.querySelectorAll('.al-row').forEach((tr) => { tr.onclick = () => { const r = D.rows[+tr.dataset.i]; if (r && ctx && ctx.openChat) ctx.openChat(r.phone); }; });
      el.querySelectorAll('[data-chk]').forEach((b) => { b.onclick = (ev) => { ev.stopPropagation(); checkOne(+b.dataset.chk, D.rows[+b.dataset.chk]); }; });
      paintTop(); paintRows();
    }
    function reset() { outF = ''; adF = ''; DG = null; load().then(() => check()); }   // a new day / number / Refresh: the list, then the numbers
    reset(); return reset;
  });
})();
