/* ✅ 3 must-send check: address re-check · chain selection · prepaid 2% — per category, per salesperson, per order (data: ct.must3, plain text match, no AI). Used under the day-by-day chart of the Control Tower and on the Live page. */
(function () {
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = (n) => '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN');
  const K = [['a', '📍', 'Address re-check'], ['c', '⛓', 'Chain selection'], ['p', '💳', 'Prepaid 2%']];
  const S = { day: '', d: null, at: 0, f: 'none', host: null, busy: false };
  const col = (v, t) => !t ? '#9ca3af' : (v / t >= .8 ? '#16a34a' : v / t >= .5 ? '#d97706' : '#dc2626');
  const chip = (k, o) => { const v = o[k[0]]; return v ? `<span style="font-size:11px;font-weight:700;border-radius:99px;padding:2px 8px;background:#dcfce7;color:#166534" title="${v[1] === 'A' ? 'Sent automatically' : 'Sent by the salesperson'}">${k[1]} ✓ ${esc(v[0])}${v[1] === 'A' ? ' 🤖' : ''}</span>` : `<span style="font-size:11px;font-weight:700;border-radius:99px;padding:2px 8px;background:#fee2e2;color:#991b1b">${k[1]} ✗ not sent</span>`; };
  function html() {
    const d = S.d; if (!d) return `<div class="card" style="margin-bottom:14px;padding:12px;font-size:13px;color:#667781">✅ 3 must-send check — loading…</div>`;
    const t = d.team || {}, n = t.n || 0, f = S.f;
    const bar = K.map(k => { const v = t[k[0]] || 0, on = f === k[0]; return `<div onclick="Must3.set('${k[0]}')" style="cursor:pointer;flex:1;min-width:140px;border:${on ? '2px solid #111827' : '1px solid #e5e7eb'};border-radius:12px;padding:8px 10px;background:#fff" title="Tap: show only the orders where this was NOT sent"><div style="font-size:12px;color:#667781">${k[1]} ${k[2]}</div><div style="font-size:22px;font-weight:800;color:${col(v, n)}">${v}<small style="font-size:12px;color:#667781;font-weight:500"> / ${n} orders</small></div><div style="height:6px;border-radius:3px;background:#eef0f4;margin-top:4px"><div style="height:6px;border-radius:3px;width:${n ? Math.round(v / n * 100) : 0}%;background:${col(v, n)}"></div></div></div>`; }).join('');
    const tabs = [['all', 'All people'], ['ok', 'All 3 sent ✓']].map(x => `<span onclick="Must3.set('${x[0]}')" style="cursor:pointer;font-size:12px;font-weight:700;border:1px solid ${f === x[0] ? '#111827' : '#e5e7eb'};border-radius:99px;padding:3px 10px;background:${f === x[0] ? '#111827' : '#fff'};color:${f === x[0] ? '#fff' : '#111827'}">${x[1]}</span>`).join('');
    let shown = 0;
    const people = (d.people || []).map(q => {
      const orders = (q.orders || []).filter(o => f === 'a' || f === 'c' || f === 'p' ? !o[f] : true);
      if ((f === 'a' || f === 'c' || f === 'p') && !orders.length) return '';
      if (f === 'ok' && !(q.a === q.n && q.c === q.n && q.p === q.n)) return '';
      shown++;
      const miss = K.map(k => [k, q.n - (q[k[0]] || 0)]).filter(x => x[1] > 0);
      const tip = miss.length ? miss.map(x => `${x[0][2]} ${x[1]} order ma nathi gayo`).join(' · ') + ' — order pehla bhejo.' : 'Badha orders ma 3 j gaya ✓';
      const one = (f === 'a' || f === 'c' || f === 'p') ? K.find(k => k[0] === f) : null;
      const pills = one ? `<span style="font-weight:800;color:#dc2626;white-space:nowrap">${one[1]} ${q.n - (q[f] || 0)} missing of ${q.n}</span>` : K.map(k => `<span style="font-weight:800;color:${col(q[k[0]] || 0, q.n)};white-space:nowrap">${k[1]} ${q[k[0]] || 0}/${q.n}</span>`).join(' ');
      const rows = orders.map(o => `<div style="border-top:1px solid #eef0f4;padding:6px 0"><div style="display:flex;justify-content:space-between;gap:8px;font-size:12.5px;flex-wrap:wrap"><b>${esc(o.order)} <span style="font-weight:400;color:#667781">· ${esc(o.customer || '')} · ${inr(o.total)} · ${esc(o.at)}</span></b>${o.phone ? `<a href="wa_one.html?phone=${esc(o.phone)}" target="_blank" style="color:#0f6e8c;font-weight:700;text-decoration:none">Open chat ›</a>` : ''}</div><div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:4px">${K.map(k => chip(k, o)).join('')}</div>${o.nochat ? '<div style="font-size:11.5px;color:#92400e;background:#fffbeb;border-radius:8px;padding:4px 8px;margin-top:4px">📱 Chat not found for this order\'s number — add the WhatsApp number in Order Allocation → Extra mobile no.</div>' : ''}</div>`).join('');
      return `<details style="border:1px solid #e5e7eb;border-radius:12px;margin:8px 0;padding:8px 10px;background:#fff"><summary style="cursor:pointer;list-style:none"><div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:baseline"><b style="font-size:14px">${esc(q.name)} <span style="font-weight:400;color:#667781;font-size:12px">· ${q.n} orders</span></b><span style="font-size:12.5px;display:flex;gap:10px;flex-wrap:wrap">${pills}</span></div></summary><div style="font-size:12px;color:#92400e;background:#fffbeb;border-radius:8px;padding:5px 8px;margin:6px 0">💡 ${esc(tip)}</div>${rows}</details>`;
    }).join('');
    const list = f === 'none' ? '' : (people || '<div style="padding:10px;color:#667781;font-size:13px">' + (n ? 'Nobody here ✓' : 'No orders yet.') + '</div>');
    return `<div class="card" style="margin-bottom:14px;padding:12px"><div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center"><div style="font-size:15px;font-weight:800">✅ 3 must-send check · ${esc(d.day)} <span style="font-weight:400;font-size:12px;color:#667781">· tap a line to see who</span></div><div style="display:flex;gap:6px;flex-wrap:wrap">${tabs}</div></div><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">${bar}</div>${list}</div>`;
  }
  function paint() { if (S.host && document.body.contains(S.host)) S.host.innerHTML = html(); }
  async function mount(host, day) {
    if (!host) return; S.host = host; if (S.day !== day) { S.day = day; S.d = null; S.at = 0; } paint();
    if (S.at > Date.now() - 60000 || S.busy) return; S.busy = true;
    try { const d = await api.get('ct.must3', { date: day || '' }); if (S.day === day) { S.d = d; S.at = Date.now(); } } catch (e) { /* the box keeps its loading line */ }
    S.busy = false; paint();
  }
  window.Must3 = { mount, set: (f) => { S.f = S.f === f ? 'none' : f; paint(); } };
})();
