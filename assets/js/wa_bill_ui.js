/**
 * wa_bill_ui.js — 💰 Billing & spend (WhatsApp → Results → Billing). Server: empmanagment/wa_bill.php (wa.bill).
 *   Pick a range (Yesterday · Today · 7 days · This month · Last month · 3 months · This year · your own dates), Day or Month, and which messages.
 *   Shows: spent (our count + Meta's own bill when Meta shares it), delivered, read, replies, orders and revenue that followed, return per ₹1, cost per order,
 *   the most and least effective day, spend and revenue charts, every day / month in a table (CSV), each source and each campaign.
 */
(function () {
  'use strict';
  if (window.WaBill || !window.WaPanels || !WaPanels.util || typeof api === 'undefined') return;
  const { esc, fmt, toast } = WaPanels.util;
  const rs = (n) => '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: n >= 1000 ? 0 : 2 });
  const pad = (n) => String(n).padStart(2, '0'); const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const label = (k, long) => { if (k.length === 7) return MON[+k.slice(5) - 1] + ' ' + k.slice(0, 4); const d = new Date(k + 'T00:00:00'); return (long ? WD[d.getDay()] + ', ' : '') + d.getDate() + ' ' + MON[d.getMonth()] + (long ? ' ' + d.getFullYear() : ''); };
  const x = (v) => (v == null ? '—' : v + '×'), pct = (v) => (v == null ? '—' : v + '%');
  const PRE = [['yday', 'Yesterday'], ['today', 'Today'], ['7d', 'Last 7 days'], ['month', 'This month'], ['lmonth', 'Last month'], ['3m', 'Last 3 months'], ['year', 'This year'], ['custom', 'Pick dates']];
  const SRC = [['all', 'All paid messages'], ['campaign', 'Campaigns'], ['followup', 'Follow-ups'], ['order', 'Order updates'], ['flow', 'Flows'], ['other', 'Other templates']];
  function range(p) {
    const t = new Date(); t.setHours(0, 0, 0, 0); const d = (n) => { const a = new Date(t); a.setDate(a.getDate() + n); return a; };
    if (p === 'yday') return [ymd(d(-1)), ymd(d(-1))]; if (p === 'today') return [ymd(t), ymd(t)]; if (p === '7d') return [ymd(d(-6)), ymd(t)];
    if (p === 'lmonth') { const a = new Date(t.getFullYear(), t.getMonth() - 1, 1), b = new Date(t.getFullYear(), t.getMonth(), 0); return [ymd(a), ymd(b)]; }
    if (p === '3m') return [ymd(new Date(t.getFullYear(), t.getMonth() - 2, 1)), ymd(t)]; if (p === 'year') return [ymd(new Date(t.getFullYear(), 0, 1)), ymd(t)];
    return [ymd(new Date(t.getFullYear(), t.getMonth(), 1)), ymd(t)];
  }
  const css = `
  .wb-bar{display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-bottom:10px;} .wb-bar .wp-in{width:auto; min-height:38px; padding:6px 10px;}
  .wb-best{display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:12px; margin-bottom:12px;} .wb-day{border-radius:16px; padding:13px 16px; border:1px solid #e3e8e5; background:#fff;}
  .wb-day small{font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#6b7a73;} .wb-day .tt{display:block; font-size:18px; font-weight:800; margin:2px 0 4px;} .wb-day span b{font-weight:800;} .wp-kpi .wb-em{font-style:normal; font-weight:800; color:#1b2430;} .wb-day span{font-size:13px; color:#3b4a43; line-height:1.5;}
  .wb-day.g{border-color:#a7e3c0; background:#f2fbf6;} .wb-day.r{border-color:#f5c2c0; background:#fff6f5;}
  .wb-ch{position:relative;} .wb-ch svg{width:100%; height:auto; display:block;} .wb-ch rect.m{cursor:pointer;} .wb-ch rect.m:hover{opacity:.75;}
  .wb-tip{position:absolute; pointer-events:none; background:#1b2430; color:#fff; font-size:12px; border-radius:8px; padding:6px 9px; white-space:nowrap; transform:translate(-50%,-110%); display:none; z-index:2; line-height:1.45;}
  .wb-tw{overflow-x:auto;} .wb-tw .wp-tb td,.wb-tw .wp-tb th{white-space:nowrap;} .wb-tw .wp-tb td.n,.wb-tw .wp-tb th.n{text-align:right;} .wb-tw tr.t td{font-weight:800; border-top:2px solid #d8dfdb;} .wb-tw tr.z td{color:#9aa6a0;}
  .wb-tw tr.hb td:first-child{box-shadow:inset 3px 0 #16a34a;} .wb-tw tr.hw td:first-child{box-shadow:inset 3px 0 #dc2626;}
  .wb-mut{font-size:12px; color:#6b7a73; line-height:1.5;}`;
  if (!document.getElementById('wbCss')) { const s = document.createElement('style'); s.id = 'wbCss'; s.textContent = css; document.head.appendChild(s); }

  function chart(rows, key, color, fmtV, by) {
    const W = 700, H = 170, L = 6, B = 22, T = 10; const max = Math.max(1, ...rows.map((r) => r[key] || 0)); const n = rows.length || 1; const bw = (W - L * 2) / n; const w = Math.max(2, Math.min(28, bw - 2));
    const step = Math.ceil(n / 8); let s = `<svg viewBox="0 0 ${W} ${H}" role="img"><line x1="0" y1="${H - B}" x2="${W}" y2="${H - B}" stroke="#d8dfdb"/>`;
    rows.forEach((r, i) => { const v = r[key] || 0, h = v > 0 ? Math.max(2, (H - B - T) * v / max) : 0, cx = L + bw * i + bw / 2;
      if (h) s += `<rect class="m" data-i="${i}" x="${cx - w / 2}" y="${H - B - h}" width="${w}" height="${h}" rx="${Math.min(4, w / 2)}" fill="${color}"/>`;
      s += `<rect class="m" data-i="${i}" x="${cx - bw / 2}" y="${T}" width="${bw}" height="${H - B - T}" fill="transparent"/>`;
      if (i % step === 0) s += `<text x="${cx}" y="${H - 6}" text-anchor="middle" font-size="15" fill="#6b7a73">${esc(by === 'month' ? label(r.key).slice(0, 3) : label(r.key))}</text>`; });
    return `<div class="wb-ch" data-k="${key}">${s}</svg><div class="wb-tip"></div><div class="wb-mut">Highest: ${fmtV(max)}</div></div>`;
  }

  function screen(el) {
    const S = { pre: 'month', from: '', to: '', by: 'day', src: 'all', d: null }; [S.from, S.to] = range('month');
    try { const o = JSON.parse(localStorage.getItem('wb_state') || 'null'); if (o && o.pre) { Object.assign(S, o); if (S.pre !== 'custom') [S.from, S.to] = range(S.pre); } } catch (e) { }
    const save = () => { try { localStorage.setItem('wb_state', JSON.stringify({ pre: S.pre, from: S.from, to: S.to, by: S.by, src: S.src })); } catch (e) { } };
    el.innerHTML = `<div class="wb-bar" id="wbPre">${PRE.map(([k, t]) => `<button type="button" class="wp-chip" data-p="${k}">${t}</button>`).join('')}</div>
      <div class="wb-bar"><input type="date" class="wp-in" id="wbF"><span>to</span><input type="date" class="wp-in" id="wbT">
        <div class="wp-seg" style="margin:0" id="wbBy"><button type="button" data-b="day">Day by day</button><button type="button" data-b="month">Month by month</button></div>
        <select class="wp-in" id="wbS">${SRC.map(([k, t]) => `<option value="${k}">${t}</option>`).join('')}</select><span class="wp-grow"></span><button type="button" class="wp-btn" id="wbCsv">⬇ CSV</button></div><div id="wbOut"><div class="wp-empty">Loading…</div></div>`;
    const $ = (s) => el.querySelector(s); const out = $('#wbOut');
    const sync = () => { el.querySelectorAll('[data-p]').forEach((b) => b.classList.toggle('on', b.dataset.p === S.pre)); el.querySelectorAll('[data-b]').forEach((b) => b.classList.toggle('on', b.dataset.b === S.by)); $('#wbF').value = S.from; $('#wbT').value = S.to; $('#wbS').value = S.src; };
    el.querySelectorAll('[data-p]').forEach((b) => { b.onclick = () => { S.pre = b.dataset.p; if (S.pre !== 'custom') { [S.from, S.to] = range(S.pre); if (['year', '3m'].includes(S.pre)) S.by = 'month'; else if (['yday', 'today', '7d', 'month', 'lmonth'].includes(S.pre)) S.by = 'day'; load(); } else { sync(); $('#wbF').focus(); } }; });
    el.querySelectorAll('[data-b]').forEach((b) => { b.onclick = () => { S.by = b.dataset.b; load(); }; });
    $('#wbF').onchange = $('#wbT').onchange = () => { if (!$('#wbF').value || !$('#wbT').value) return; S.pre = 'custom'; S.from = $('#wbF').value; S.to = $('#wbT').value; load(); };
    $('#wbS').onchange = () => { S.src = $('#wbS').value; load(); };
    $('#wbCsv').onclick = () => {
      const d = S.d; if (!d) return; const m = metaFor(d); const head = ['Date', 'Sent', 'Delivered', 'Read', 'Failed', 'Paid marketing', 'Paid utility', 'Free', 'Spent ₹', 'Campaigns ₹', 'Follow-ups ₹', 'Order updates ₹', 'Flows ₹', 'Other ₹', 'Replies', 'Orders', 'Revenue ₹', 'Return per ₹1', 'Cost per order ₹'].concat(m ? ['Meta bill ₹'] : []);
      const line = (r, k) => [k, r.sent, r.delivered, r.read, r.failed, r.paid_m, r.paid_u, r.free, r.cost, r.cost_src.campaign, r.cost_src.followup, r.cost_src.order, r.cost_src.flow, r.cost_src.other, r.replied, r.orders, r.revenue, r.roas ?? '', r.cpo ?? ''].concat(m ? [m[r.key] ?? ''] : []);
      const csv = [head].concat(d.rows.map((r) => line(r, r.key)), [line(d.total, 'TOTAL').slice(0, 19).concat(m ? [d.meta.total] : [])]).map((a) => a.map((v) => /[",\n]/.test(String(v)) ? '"' + String(v).replace(/"/g, '""') + '"' : v).join(',')).join('\n');
      const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' })); a.download = `whatsapp-billing-${d.from}-to-${d.to}-${d.by}.csv`; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    };
    function metaFor(d) { if (!d.meta || d.meta.error || !d.meta.days) return null; const o = {}; Object.entries(d.meta.days).forEach(([k, v]) => { const kk = d.by === 'month' ? k.slice(0, 7) : k; o[kk] = Math.round(((o[kk] || 0) + v) * 100) / 100; }); return o; }
    let seq = 0;
    async function load() {
      sync(); save(); const my = ++seq; out.innerHTML = '<div class="wp-empty">Adding up the bill…</div>';
      let d; try { d = await api.get('wa.bill', { from: S.from, to: S.to, by: S.by, src: S.src, meta: 1 }); } catch (e) { if (my === seq) out.innerHTML = `<div class="wp-err">${esc(e.message)}</div>`; return; }
      if (my !== seq || !el.isConnected) return; S.d = d; draw(d);
    }
    function draw(d) {
      const t = d.total, m = metaFor(d), rows = d.rows, oneDay = d.from === d.to;
      const kpi = (k, v, sub) => `<div class="wp-kpi"><small>${k}</small><b>${v}</b><span>${sub || '&nbsp;'}</span></div>`;
      let h = `<div class="wb-mut" style="margin-bottom:8px">${esc(label(d.from, true))}${oneDay ? '' : ' → ' + esc(label(d.to, true))} · ${esc((SRC.find((s) => s[0] === d.src) || SRC[0])[1])}</div>`;
      h += '<div class="wp-kpis">' + kpi('Spent', rs(t.cost), m ? 'Meta’s own bill: <i class="wb-em">' + rs(d.meta.total) + '</i>' : `${fmt(t.paid_m)} marketing · ${fmt(t.paid_u)} utility`) + kpi('Delivered', fmt(t.delivered), `${fmt(t.sent)} sent · ${fmt(t.failed)} failed`)
        + kpi('Read', pct(t.read_pct), fmt(t.read) + ' people opened it') + kpi('Replied', fmt(t.replied), pct(t.reply_pct) + ' within 2 days') + kpi('Orders', fmt(t.orders), 'within 7 days of a message')
        + kpi('Revenue', rs(t.revenue), 'from those orders') + kpi('Return per ₹1', x(t.roas), t.roas != null ? `every ₹1 spent brought ${rs(t.roas)}` : 'no spend yet') + kpi('Cost per order', t.cpo != null ? rs(t.cpo) : '—', t.orders ? '' : 'no orders yet') + '</div>';
      if (!t.sent) { out.innerHTML = h + '<div class="wp-cd"><div class="wp-empty">No paid WhatsApp messages in this range.<br>Pick another range above.</div></div>'; return; }
      const U = d.by === 'month' ? 'month' : 'day';
      const dayCard = (r, cls, ttl, why) => r ? `<div class="wb-day ${cls}"><small>${ttl}</small><span class="tt">${esc(label(r.key, true))}</span><span>Spent <b>${rs(r.cost)}</b> · ${fmt(r.delivered)} delivered · ${fmt(r.replied)} replies<br><b>${fmt(r.orders)}</b> orders · ${rs(r.revenue)} revenue · <b>${x(r.roas)}</b> per ₹1<br>${why}</span></div>` : '';
      if (rows.length > 1) h += `<div class="wb-best">${dayCard(d.best, 'g', '🏆 Most effective ' + U, 'Best return for the money.') || `<div class="wb-day"><small>Most effective ${U}</small><span>Not enough spend yet to compare.</span></div>`}${dayCard(d.worst, 'r', '⚠ Least effective ' + U, d.worst && !d.worst.orders ? 'Money spent, no orders followed — check the message, the audience and the time.' : 'Lowest return for the money.')}</div>`;
      if (rows.length > 1) h += `<div class="wp-grid"><div class="wp-cd"><h3>Spent per ${U}</h3>${chart(rows, 'cost', '#2563eb', rs, d.by)}</div><div class="wp-cd"><h3>Revenue per ${U} (orders after a message)</h3>${chart(rows, 'revenue', '#16a34a', rs, d.by)}</div></div>`;
      const th = '<tr><th>' + (d.by === 'month' ? 'Month' : 'Date') + '</th><th class="n">Sent</th><th class="n">Delivered</th><th class="n">Read</th><th class="n">Spent</th>' + (m ? '<th class="n">Meta bill</th>' : '') + '<th class="n">Campaigns</th><th class="n">Follow-ups</th><th class="n">Order updates</th><th class="n">Replies</th><th class="n">Orders</th><th class="n">Revenue</th><th class="n">Per ₹1</th><th class="n">₹ / order</th></tr>';
      const tr = (r, k, cls) => `<tr class="${cls || ''}"><td>${k}</td><td class="n">${fmt(r.sent)}</td><td class="n">${fmt(r.delivered)}</td><td class="n">${pct(r.read_pct)}</td><td class="n"><b>${rs(r.cost)}</b></td>${m ? `<td class="n">${cls === 't' ? rs(d.meta.total) : (m[r.key] != null ? rs(m[r.key]) : '—')}</td>` : ''}<td class="n">${rs(r.cost_src.campaign)}</td><td class="n">${rs(r.cost_src.followup)}</td><td class="n">${rs(r.cost_src.order)}</td><td class="n">${fmt(r.replied)}</td><td class="n">${fmt(r.orders)}</td><td class="n">${rs(r.revenue)}</td><td class="n">${x(r.roas)}</td><td class="n">${r.cpo != null ? rs(r.cpo) : '—'}</td></tr>`;
      const list = rows.slice().reverse();
      h += `<div class="wp-cd"><h3>${d.by === 'month' ? 'Month by month' : 'Day by day'}<span class="sp"></span><span class="wb-mut">newest first · 🟢 most / 🔴 least effective</span></h3><div class="wb-tw"><table class="wp-tb">${th}${list.map((r) => tr(r, esc(label(r.key, d.by === 'day')), [r.sent ? '' : 'z', d.best && d.best.key === r.key ? 'hb' : '', d.worst && d.worst.key === r.key ? 'hw' : ''].join(' '))).join('')}${tr(t, 'Total', 't')}</table></div></div>`;
      if (d.sources.length) h += `<div class="wp-cd"><h3>Where the money went</h3><div class="wb-tw"><table class="wp-tb"><tr><th>Messages</th><th class="n">Delivered</th><th class="n">Spent</th><th class="n">Share</th><th class="n">Replies</th><th class="n">Orders</th><th class="n">Revenue</th><th class="n">Per ₹1</th></tr>${d.sources.map((s) => `<tr><td>${esc(s.label)}</td><td class="n">${fmt(s.delivered)}</td><td class="n"><b>${rs(s.cost)}</b></td><td class="n">${t.cost ? Math.round(100 * s.cost / t.cost) + '%' : '—'}</td><td class="n">${fmt(s.replied)}</td><td class="n">${fmt(s.orders)}</td><td class="n">${rs(s.revenue)}</td><td class="n">${x(s.roas)}</td></tr>`).join('')}</table></div></div>`;
      if (d.campaigns.length) h += `<div class="wp-cd"><h3>Campaigns in this range</h3><div class="wb-tw"><table class="wp-tb"><tr><th>Campaign</th><th>Sent on</th><th class="n">Delivered</th><th class="n">Read</th><th class="n">Spent</th><th class="n">Replies</th><th class="n">Orders</th><th class="n">Revenue</th><th class="n">Per ₹1</th><th class="n">₹ / order</th></tr>${d.campaigns.map((c) => `<tr><td><b>${esc(c.name)}</b></td><td>${esc(label(c.first.slice(0, 10)))}</td><td class="n">${fmt(c.delivered)}</td><td class="n">${pct(c.read_pct)}</td><td class="n"><b>${rs(c.cost)}</b></td><td class="n">${fmt(c.replied)}</td><td class="n">${fmt(c.orders)}</td><td class="n">${rs(c.revenue)}</td><td class="n">${x(c.roas)}</td><td class="n">${c.cpo != null ? rs(c.cpo) : '—'}</td></tr>`).join('')}</table></div></div>`;
      h += `<div class="wb-mut">How it is counted: WhatsApp charges once for each pre-made (template) message that is <b>delivered</b> — marketing ${rs(d.rates.marketing)}, utility ${rs(d.rates.utility)} (your rates in Team &amp; routing). Normal chat replies inside 24 hours are free. ${d.exact_share ? d.exact_share + '% of these messages carry Meta’s exact price type; the rest are estimated.' : 'From today Meta’s exact price type is saved with every message; older ones are estimated.'} Orders and revenue: a Shopify order from the same phone within 7 days after a delivered message, credited to the last message before the order. ${m ? '' : (d.meta && d.meta.error ? 'Meta’s own bill: ' + esc(d.meta.error) : '')}</div>`;
      out.innerHTML = h;
      out.querySelectorAll('.wb-ch').forEach((c) => { const tip = c.querySelector('.wb-tip'), k = c.dataset.k; c.querySelectorAll('rect.m').forEach((b) => {
        b.onmouseenter = () => { const r = rows[+b.dataset.i]; tip.innerHTML = `<b>${esc(label(r.key, true))}</b><br>Spent ${rs(r.cost)} · ${fmt(r.delivered)} delivered<br>${fmt(r.orders)} orders · ${rs(r.revenue)} · ${x(r.roas)}`; const bb = b.getBoundingClientRect(), cb = c.getBoundingClientRect(); tip.style.left = Math.min(cb.width - 90, Math.max(90, bb.left - cb.left + bb.width / 2)) + 'px'; tip.style.top = '40px'; tip.style.display = 'block'; };
        b.onmouseleave = () => { tip.style.display = 'none'; }; b.onclick = b.onmouseenter; }); });
    }
    load(); return () => load();
  }
  window.WaBill = { screen };
  WaPanels.add('billing', screen);
})();
