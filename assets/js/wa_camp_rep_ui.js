/* wa_camp_rep_ui.js — 📊 campaign report card + "What works for our campaigns" (empmanagment/wa_camp_report.php).
 *   WaCampReport.open(campaign)      the report card in a pop-up (needs WaPanels.util: modal, esc, toast)
 *   WaCampReport.learnBox(el)        the merged learnings, shown at the top of New campaign
 */
(function () {
  if (window.WaCampReport) return;
  const U = () => (window.WaPanels && WaPanels.util) || {};
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n) => Number(n || 0).toLocaleString('en-IN'); const pc = (v) => (v == null ? '—' : v + '%');
  const H = (h) => { const a = h % 12 || 12; return a + (h < 12 ? ' am' : ' pm'); };
  const CSS = `.cr-score{display:flex;gap:14px;align-items:center;background:linear-gradient(135deg,#ecfdf3,#eef6ff);border-radius:16px;padding:14px;margin-bottom:12px}
  .cr-score .n{font-size:40px;font-weight:900;line-height:1} .cr-score .n small{font-size:15px;color:#6b7684;font-weight:700} .cr-score p{margin:0;font-size:13px;line-height:1.45}
  .cr-parts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:12px} .cr-parts div{border-radius:12px;padding:8px 10px;background:#f6f7f9} .cr-parts div.g{background:#ecfdf3} .cr-parts div.r{background:#fff1f0}
  .cr-parts b{display:block;font-size:17px} .cr-parts small{font-size:11.5px;color:#6b7684}
  .cr-h{font-size:13px;font-weight:800;margin:14px 0 6px} .cr-bars{display:flex;gap:4px;align-items:flex-end;height:90px} .cr-bars div{flex:1;background:#c7d2fe;border-radius:6px 6px 0 0;position:relative;min-height:2px} .cr-bars div.top{background:#4f46e5}
  .cr-bl{display:flex;gap:4px;font-size:11px;color:#6b7684} .cr-bl span{flex:1;text-align:center}
  .cr-t{display:grid;grid-template-columns:1fr auto auto auto;gap:4px 12px;font-size:12.5px;align-items:center} .cr-t .hd{font-weight:800;color:#6b7684;font-size:11px}
  .cr-grp{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px} .cr-grp div{border:1px solid #e4e7ec;border-radius:12px;padding:10px} .cr-grp b{font-size:18px;display:block} .cr-grp button{margin-top:6px}
  .cr-adv{background:#f5f3ff;border:1px solid #ddd6fe;border-radius:14px;padding:12px;margin-top:12px;font-size:13px;line-height:1.5} .cr-adv ul{margin:4px 0 8px;padding-left:18px}
  .cr-learn{background:#fffbeb;border:1px solid #fde68a;border-radius:14px;padding:10px 12px;margin:0 0 10px;font-size:12.5px;line-height:1.5} .cr-learn ul{margin:4px 0 0;padding-left:18px}
  @media (max-width:700px){.cr-parts{grid-template-columns:repeat(2,minmax(0,1fr))}.cr-grp{grid-template-columns:1fr}}`;
  function css() { if (document.getElementById('crCss')) return; const s = document.createElement('style'); s.id = 'crCss'; s.textContent = CSS; document.head.appendChild(s); }
  const GRP = [['ordered', '🛍 Ordered', 'Thank them / ask for a review'], ['replied_no_order', '💬 Replied, did not order', 'Best follow-up audience'], ['read_ignored', '👀 Read, did not reply', 'Try a different message'], ['not_read', '📭 Not read', 'Try another time of day']];

  async function open(c) {
    css(); const u = U(); const m = u.modal('📊 Report card — ' + c.name); m.el.classList.add('cu-wide'); m.body.innerHTML = '<div class="wp-hint">Reading the results…</div>';
    let r; try { r = (await api.get('wa.camp_report', { id: c.id })).report; } catch (e) { m.body.innerHTML = `<div class="wp-note">${esc(e.message)}</div>`; return; }
    draw(m, r);
  }
  function draw(m, r) {
    const u = U(), x = r.result || {}, rt = r.rates || {};
    let h = r.ready ? `<div class="cr-score"><div class="n">${r.score}<small>/100</small></div><p><b>${r.score >= 75 ? 'Strong campaign' : r.score >= 50 ? 'Average — room to grow' : 'Weak — see what to change below'}</b><br>Compared with your own last campaigns. ${fmt(x.buyers)} people ordered · ₹${fmt(x.revenue)} sales · cost ₹${fmt(x.cost)}.</p></div>
        <div class="cr-parts">${r.parts.map((p) => `<div class="${p.good ? 'g' : 'r'}"><small>${esc(p.label)}</small><b>${p.key === 'roas' ? (p.value == null ? '—' : p.value + '×') : pc(p.value)}</b><small>your avg ${p.key === 'roas' ? p.avg + '×' : p.avg + '%'} · ${p.points}/${p.of} pts</small></div>`).join('')}</div>`
      : `<div class="wp-note">⏳ The score and Claude's advice are ready <b>3 hours after the last message</b> went out (orders need time). Last message went out ${r.age_h} h ago. Below: what is known so far.</div>`;
    h += `<div class="wp-kpis"><div class="wp-kpi"><small>Sent</small><b>${fmt(x.sent)}</b></div><div class="wp-kpi"><small>Delivered</small><b>${fmt(x.delivered)}</b></div><div class="wp-kpi"><small>Read</small><b>${fmt(x.read)}</b><span>${pc(rt.read)}</span></div><div class="wp-kpi"><small>Replied</small><b>${fmt(x.replied)}</b><span>${pc(rt.reply)}</span></div><div class="wp-kpi"><small>Ordered</small><b>${fmt(x.buyers)}</b><span>${pc(rt.order)}</span></div><div class="wp-kpi"><small>Failed</small><b>${fmt(x.failed)}</b></div></div>`;
    {   // 💰 what Meta charged: utility vs marketing (free chat messages cost nothing), and which number sent how many
      const rs = (v) => '₹' + (Math.round((+v || 0) * 100) / 100).toLocaleString('en-IN'), uN = Math.max(0, (x.sent_u || 0) - (x.u_as_m || 0)), mN = (x.sent_m || 0) + (x.u_as_m || 0);
      h += `<div class="cr-h">💰 Cost${x.u_pct ? ' · utility share ' + x.u_pct + '%' : ''}</div><div class="cr-t" style="grid-template-columns:1fr auto auto"><span class="hd">Type</span><span class="hd">Messages</span><span class="hd">Amount</span>`
        + `<span>Utility <small>(${rs(x.rate_u)} each)</small></span><span>${fmt(uN)}</span><span><b>${rs(x.cost_u)}</b></span><span>Marketing <small>(${rs(x.rate_m)} each)</small></span><span>${fmt(mN)}</span><span><b>${rs(x.cost_m)}</b></span>`
        + (x.sent_free ? `<span>Free chat message <small>(24-hour window)</small></span><span>${fmt(x.sent_free)}</span><span>₹0</span>` : '') + `<span><b>Total</b></span><span>${fmt(x.sent)}</span><span><b>${rs(x.cost)}</b></span></div>`
        + (x.u_as_m ? `<div class="wp-hint">${fmt(x.u_as_m)} utility message${x.u_as_m === 1 ? '' : 's'} Meta moved to marketing — counted at the marketing price.</div>` : '')
        + (uN ? `<div class="wp-hint">Saved about <b>${rs(uN * ((+x.rate_m || 0) - (+x.rate_u || 0)))}</b> compared with sending everything as marketing.</div>` : '')
        + (x.u_off ? `<div class="wp-note" style="margin-top:6px">🛡 Utility was switched off automatically ${esc(x.u_off.at || '')}: ${esc(x.u_off.why || '')}. The rest went as marketing.</div>` : '');
      if ((x.by_number || []).length) h += `<div class="cr-h">📱 Sent from each number</div><div class="cr-t" style="grid-template-columns:1fr auto auto auto"><span class="hd">Number</span><span class="hd">Marketing</span><span class="hd">Utility</span><span class="hd">Free</span>${x.by_number.map((n) => `<span>${esc(n.label)}</span><span>${fmt(n.m)}</span><span>${fmt(n.u)}</span><span>${fmt(n.f)}</span>`).join('')}</div>`;
    }
    if (r.hours.length) { const mx = Math.max(1, ...r.hours.map((z) => z.reply_pct || 0)); const best = r.hours.reduce((a, z) => ((z.reply_pct || 0) > (a.reply_pct || 0) && z.sent >= 10 ? z : a), r.hours[0]);
      h += `<div class="cr-h">Replies by the hour it was sent ${best && best.reply_pct ? '· best: <b>' + H(best.hour) + '</b> (' + best.reply_pct + '%)' : ''}</div><div class="cr-bars">${r.hours.map((z) => `<div class="${z === best ? 'top' : ''}" style="height:${Math.max(2, (z.reply_pct || 0) / mx * 100)}%" title="${H(z.hour)}: ${z.sent} sent · ${pc(z.reply_pct)} replied · ${pc(z.order_pct)} ordered"></div>`).join('')}</div><div class="cr-bl">${r.hours.map((z) => `<span>${z.hour}</span>`).join('')}</div>`; }
    if (r.topics.length) h += `<div class="cr-h">What customers replied ${r.reply_min != null ? '· half replied within <b>' + r.reply_min + ' min</b>' : ''}</div><div>${r.topics.map((t) => `<span class="wp-pill">${esc(t.label)} · ${t.n}</span>`).join(' ')}</div>`;
    if ((r.tag_rows || []).length) h += `<div class="cr-h">🏷 By customer tag <small style="font-weight:400;color:#6b7684">${fmt((r.overall || {}).people)} reached · ${pc((r.overall || {}).reply_pct)} replied overall</small></div><div id="crTg"></div>`;
    else if (r.tags.length) h += `<div class="cr-h">Which customers responded (by tag)</div><div class="cr-t"><span class="hd">Tag</span><span class="hd">People</span><span class="hd">Replied</span><span class="hd">Ordered</span>${r.tags.map((t) => `<span>${esc(t.tag)}</span><span>${fmt(t.n)}</span><span>${pc(t.reply_pct)}</span><span><b>${pc(t.order_pct)}</b></span>`).join('')}</div>`;
    if (x.buyers) h += `<div class="cr-h">Buyers</div><div class="wp-kv"><span>Repeat customers</span><b>${fmt(r.buyers_repeat)}</b></div><div class="wp-kv"><span>New customers</span><b>${fmt(r.buyers_new)}</b></div>`;
    h += `<div class="cr-h">Ready audiences for a follow-up</div><div class="cr-grp">${GRP.map(([k, l, s]) => `<div><small>${esc(l)}</small><b>${fmt(r.groups[k])}</b><small style="color:#6b7684">${esc(s)}</small><br><button type="button" class="wp-btn s" data-tag="${k}" ${r.groups[k] ? '' : 'disabled'}>Make it a tag</button></div>`).join('')}</div><div class="wp-hint">The tag appears in New campaign → Who within about 10 minutes, so you can send the follow-up to exactly these people.</div>`;
    h += `<div class="cr-adv" id="crAdv">${advHtml(r)}</div>`;
    m.body.innerHTML = h;
    { const box = m.body.querySelector('#crTg'); if (box) { const R = r.tag_rows, G = ['All'].concat([...new Set(R.map((t) => t.group))]); let grp = 'All', sort = 'reply', lim = 8;
        const draw = () => { let L = R.filter((t) => grp === 'All' || t.group === grp); L = L.slice().sort((a, b) => sort === 'order' ? (b.order_pct || 0) - (a.order_pct || 0) : sort === 'size' ? b.n - a.n : (b.reply_pct || 0) - (a.reply_pct || 0));
          const lf = (v) => (v == null ? '' : `<span style="font-weight:800;color:${v >= 1.3 ? '#15803d' : v < 0.7 ? '#c2262b' : '#6b7684'}">×${v}</span>`);
          box.innerHTML = `<div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:5px">${G.map((g) => `<button type="button" class="wp-btn s${g === grp ? ' p' : ''}" data-g="${esc(g)}" style="min-height:22px;padding:1px 8px;font-size:10.5px">${esc(g)}</button>`).join('')}<span style="flex:1"></span>${[['reply', 'Replied'], ['order', 'Ordered'], ['size', 'Biggest']].map(([k, l]) => `<button type="button" class="wp-btn s${k === sort ? ' p' : ''}" data-s="${k}" style="min-height:22px;padding:1px 8px;font-size:10.5px">${l}</button>`).join('')}</div>
            <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:11px;white-space:nowrap"><thead><tr style="text-align:right;color:#6b7684"><th style="text-align:left;padding:2px 3px">Tag</th><th>People</th><th>Deliv</th><th>Read</th><th>Replied</th><th>Ordered</th><th>vs all</th></tr></thead><tbody>${L.slice(0, lim).map((t) => `<tr style="text-align:right;border-top:1px solid rgba(128,128,128,.2)"><td style="text-align:left;padding:3px;white-space:normal;max-width:130px">${esc(t.label || t.tag)}</td><td>${fmt(t.n)}</td><td>${pc(t.dl_pct)}</td><td>${pc(t.rd_pct)}</td><td><b>${pc(t.reply_pct)}</b></td><td>${pc(t.order_pct)}</td><td>${lf(t.lift)}</td></tr>`).join('') || '<tr><td colspan="7" class="wp-hint">No tag in this group reached enough people.</td></tr>'}</tbody></table></div>
            ${L.length > lim ? `<div style="text-align:center;margin-top:5px"><button type="button" class="wp-btn s" data-m="1">Load more (${L.length - lim})</button></div>` : ''}<div class="wp-hint" style="margin:5px 0 0">“vs all” compares each group’s reply rate with the whole campaign: ×1.5 means they replied one and a half times as often. A person can be in several groups. Replied = wrote back within 2 days; ordered = ordered within 7 days.</div>`;
          box.querySelectorAll('[data-g]').forEach((b) => { b.onclick = () => { grp = b.dataset.g; lim = 8; draw(); }; }); box.querySelectorAll('[data-s]').forEach((b) => { b.onclick = () => { sort = b.dataset.s; draw(); }; }); const mb = box.querySelector('[data-m]'); if (mb) mb.onclick = () => { lim += 12; draw(); }; };
        draw(); } }
    m.body.querySelectorAll('[data-tag]').forEach((b) => { b.onclick = async () => { b.disabled = true; try { const t = await api.post('wa.camp_report_tag', { id: r.id, group: b.dataset.tag }); u.toast(fmt(t.n) + ' people tagged “' + t.tag + '” ✓'); b.textContent = 'Tagged ✓'; } catch (e) { u.toast(e.message, true); b.disabled = false; } }; });
    const ab = m.body.querySelector('[data-adv]'); if (ab) ab.onclick = async () => { ab.disabled = true; ab.textContent = 'Claude is reading the results…'; try { const a = await api.post('wa.camp_advice', { id: r.id, force: !!r.advice }); r.advice = a.advice; m.body.querySelector('#crAdv').innerHTML = advHtml(r); } catch (e) { u.toast(e.message, true); ab.disabled = false; ab.textContent = '✨ Ask Claude what to do better'; } };
  }
  function advHtml(r) {
    const a = r.advice; if (!r.ready) return '<b>✨ Claude’s advice</b><br>Comes 3 hours after the last message, together with the score.';
    if (!a) return '<b>✨ Claude’s advice</b><br>What went well, what to do better next time, best time and audience.<div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn p" data-adv>✨ Ask Claude what to do better</button></div>';
    const li = (l) => '<ul>' + (l || []).map((x) => `<li>${esc(x)}</li>`).join('') + '</ul>'; const n = a.next_time || {};
    return `<b>✨ ${esc(a.headline)}</b>${a.did_well && a.did_well.length ? '<div style="margin-top:6px"><b>What went well</b>' + li(a.did_well) + '</div>' : ''}${a.do_better && a.do_better.length ? '<div><b>Do better next time</b>' + li(a.do_better) + '</div>' : ''}
      <div><b>Next time</b><ul>${n.send_at ? `<li>⏰ Send at: ${esc(n.send_at)}</li>` : ''}${n.audience ? `<li>👥 Audience: ${esc(n.audience)}</li>` : ''}${n.message ? `<li>✍️ Message: ${esc(n.message)}</li>` : ''}${n.avoid ? `<li>🚫 Avoid: ${esc(n.avoid)}</li>` : ''}</ul></div>
      <div class="wp-hint">Written ${esc(a.at || '')}. Every 5 report cards are merged into “What works for our campaigns”, shown when you make a new campaign.</div><div class="wp-row"><button type="button" class="wp-btn s" data-adv>↻ Write again</button></div>`;
  }
  async function learnBox(el) {
    css(); let d; try { d = await api.get('wa.camp_learn'); } catch (e) { return; }
    const L = d.learn; const box = document.createElement('div'); box.className = 'cr-learn';
    if (!L) { if (!d.cards) return; box.innerHTML = `💡 <b>What works for our campaigns</b> — ${d.cards} report card${d.cards === 1 ? '' : 's'} so far. The summary is made after 2 cards (then every 5). <button type="button" class="wp-btn s" data-lb>Make it now</button>`; }
    else box.innerHTML = `💡 <b>What works for our campaigns</b> <span class="wp-hint" style="display:inline">(from ${L.n} campaigns · ${esc(L.at)})</span><div>${esc(L.summary)}</div><ul>${(L.points || []).map((p) => `<li>${esc(p)}</li>`).join('')}${(L.best_hours || []).length ? `<li>⏰ Best time: ${L.best_hours.map(esc).join(', ')}</li>` : ''}${(L.best_audiences || []).length ? `<li>👥 Best audiences: ${L.best_audiences.map(esc).join('; ')}</li>` : ''}${(L.avoid || []).length ? `<li>🚫 Avoid: ${L.avoid.map(esc).join('; ')}</li>` : ''}${(L.message_tips || []).length ? `<li>✍️ ${L.message_tips.map(esc).join('; ')}</li>` : ''}</ul><button type="button" class="wp-btn s" data-lb style="margin-top:6px">↻ Update with the newest campaigns</button>`;
    el.insertBefore(box, el.firstChild);
    const b = box.querySelector('[data-lb]'); if (b) b.onclick = async () => { b.disabled = true; b.textContent = 'Claude is merging…'; try { await api.post('wa.camp_learn_build', {}); box.remove(); learnBox(el); } catch (e) { U().toast(e.message, true); b.disabled = false; } };
  }
  window.WaCampReport = { open, learnBox };
})();
