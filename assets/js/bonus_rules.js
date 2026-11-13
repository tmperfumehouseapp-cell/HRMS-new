/**
 * Target bonus rules — one card on the Commission Rules page (layout.js loads this file there).
 * For the people you choose and the dates you choose, EACH DAY on its own: on a day when a person's COLLECTION reaches that day's TARGET
 * (or the target + X% more), that day's commission is raised by the step's % → final = commission + commission × %.
 * Each rule has its own steps (switch a step off to use fewer); the highest step reached wins; below the target nothing changes.
 * Saved through commission_rules.bonus_save / bonus_toggle / bonus_delete, read through commission_rules.manual_list (key "bonus").
 * The Commission page (commission.html, agg()) and the salesperson's own page (my_commission.js, through my.commission key "bonus") do the arithmetic.
 */
(function () {
  if (window.__bonusRulesLoaded) return;
  window.__bonusRulesLoaded = true;

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = (v) => '₹' + Math.round(Number(v || 0)).toLocaleString('en-IN');
  const fmtN = (v) => String(Math.round(Number(v) * 100) / 100);
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const dShow = (iso) => { const p = String(iso || '').split('-'); return p.length === 3 ? p[2] + ' ' + MON[Number(p[1]) - 1] + ' ' + p[0] : ''; };
  const todayIso = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };

  const css = `
#btb{margin-top:20px;background:#fff;border:1px solid var(--line);border-radius:var(--radius,18px);padding:18px 22px 20px}
#btb h3{margin:0 0 4px;font-size:17px}#btb .rsub{font-size:12.5px;color:var(--muted);line-height:1.5;margin:0 0 14px;max-width:860px}
#btb .brow{display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:12px 14px;border:1px solid #e6e9ee;border-radius:14px;background:#fafbfc;margin-bottom:8px}
#btb .brow.off .btxt{opacity:.5}#btb .btxt{flex:1 1 320px;min-width:0;font-size:13.5px;line-height:1.6}#btb .btxt small{display:block;color:var(--muted);font-size:12px}
#btb .chip{display:inline-block;font-size:11px;font-weight:800;border-radius:999px;padding:2px 10px;margin-right:6px}
#btb .c-live{background:#e7f7ee;color:#0f7a41}#btb .c-soon{background:#fff4db;color:#8a5a00}#btb .c-done{background:#f1f3f6;color:#6b7280}
#btb .badd{border:1px dashed #b9c0cc;background:#fff;border-radius:12px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer;color:var(--ink);min-height:0}#btb .badd:hover{border-color:var(--accent)}
#btb .bx{border:1px solid var(--line);background:#fff;border-radius:999px;padding:5px 12px;cursor:pointer;font-size:12.5px;font-weight:700;min-height:0}#btb .bx:hover{border-color:var(--accent)}#btb .bx.del:hover{color:#b91c1c;border-color:#f1b4b4}
.btb-sw{position:relative;display:inline-block;width:40px;height:24px;flex:none}.btb-sw input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;z-index:1;width:100%;height:100%}
.btb-sw i{position:absolute;inset:0;border-radius:20px;background:#cbd2dc;transition:.15s}.btb-sw i::after{content:'';position:absolute;left:3px;top:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:left .15s}
.btb-sw input:checked + i{background:#16a34a}.btb-sw input:checked + i::after{left:19px}.btb-sw input:focus-visible + i{outline:2px solid #ff7a2f;outline-offset:2px}
.btb-modal{position:fixed;inset:0;background:rgba(20,22,26,.45);z-index:90;display:none;align-items:flex-start;justify-content:center;overflow:auto;padding:24px 12px}
.btb-modal.on{display:flex}.btb-sheet{background:#fff;border-radius:22px;width:min(720px,100%);padding:20px 22px;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.btb-sheet h3{margin:0 0 12px;font-size:18px}.btb-sheet label.l{display:block;font-size:12px;font-weight:800;color:#6b7280;margin:10px 0 4px}
.btb-sheet input[type=text],.btb-sheet input[type=date],.btb-sheet input[type=number]{width:100%;border:1px solid var(--line);border-radius:12px;padding:9px 12px;font:inherit;min-height:0;background:#fff}
.btb-two{display:grid;grid-template-columns:1fr 1fr;gap:10px}@media (max-width:560px){.btb-two{grid-template-columns:1fr}}
.btb-ppl{display:flex;flex-wrap:wrap;gap:6px;max-height:190px;overflow:auto}
.btb-p{border:1px solid var(--line);background:#fff;border-radius:999px;padding:5px 12px;font-size:12.5px;font-weight:700;color:#6b7280;cursor:pointer;min-height:0}.btb-p.on{background:#14161a;color:#fff;border-color:#14161a}
.btb-tier{display:grid;grid-template-columns:auto minmax(0,1.2fr) minmax(0,1fr) 34px;gap:10px;align-items:center;padding:9px 12px;border-radius:14px;background:#f6f7f9;margin-bottom:7px}.btb-tier.off{opacity:.5}
.btb-tier b{display:block}.btb-tier small{color:#6b7280;font-size:12px}.btb-tier input{width:100%}
.btb-pv{background:#f6f7f9;border-radius:16px;padding:12px 14px;margin-top:12px;font-size:13px}.btb-pv .big{font-size:20px;font-weight:800}
.btb-act{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}.btb-err{color:#b91c1c;font-size:13px;margin-top:8px}
`;
  const mount = document.querySelector('.content') || document.querySelector('.main') || document.body;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  const root = document.createElement('div'); root.id = 'btb';
  root.innerHTML = '<div class="muted" style="color:var(--muted)">Loading target bonus rules…</div>';
  mount.appendChild(root);
  const modal = document.createElement('div'); modal.className = 'btb-modal'; modal.innerHTML = '<div class="btb-sheet" role="dialog" aria-modal="true" aria-label="Target bonus rule"></div>';
  document.body.appendChild(modal);
  const sheet = modal.firstChild;
  const $ = (id) => document.getElementById(id);

  let D = { employees: [], bonus: [] };
  const empName = (id) => { const e = D.employees.find((x) => x.employee_id === id); return e ? e.full_name : 'Employee ' + id; };
  async function load() {
    try {
      const d = await api.get('commission_rules.manual_list');
      D = { employees: (d.employees || []).map((e) => ({ employee_id: Number(e.employee_id), full_name: e.full_name })), bonus: (d.bonus || []).map((r) => ({ id: Number(r.rule_id), label: r.label || '', who: (r.who || []).map(Number), all: !!Number(r.all), on: !!Number(r.enabled), from: String(r.date_from || ''), to: String(r.date_to || ''), tiers: (r.tiers || []).map((t) => ({ on: !!Number(t.on), over: Number(t.over), bonus: Number(t.bonus) })) })) };
      render();
    } catch (e) { root.innerHTML = '<h3>Target bonus</h3><div class="alert error" style="margin:0">' + esc(e.message || 'Could not read the target bonus rules.') + ' — upload the latest api.php, then reload this page.</div>'; }
  }
  const tierTxt = (t) => (t.over === 0 ? 'Target done' : 'Target + ' + fmtN(t.over) + '%') + ' → +' + fmtN(t.bonus) + '%';
  function render() {
    const today = todayIso();
    root.innerHTML = '<h3>🎯 Target bonus</h3><p class="rsub">For the people and dates you choose: <b>each day counts on its own</b>. On a day when a person\'s <b>collection</b> reaches <b>that day\'s target</b> (or the target plus 30%, 40%, 50%… more), that day\'s commission is raised: <b>final = commission + commission × bonus %</b>. A day below its target gets no bonus. If the normal over-target steps or a boost give more on that day, the <b>higher one counts</b> (never both). The extra is paid on the chart commission; an order on an offer keeps the offer\'s own %. You choose how many steps to use. Only the selected people and dates are calculated by this rule; everyone else, and every order outside the dates, keeps the normal commission. A commission set by hand always wins.</p>' +
      (D.bonus.length ? D.bonus.map((r) => {
        const s = r.to && r.to < today ? ['c-done', 'finished'] : r.from > today ? ['c-soon', 'starts ' + dShow(r.from)] : ['c-live', 'running'];
        const steps = r.tiers.filter((t) => t.on).sort((a, b) => a.over - b.over).map(tierTxt).join(' · ');
        const names = r.all ? 'everyone (all sales people, also people added later)' : r.who.slice(0, 4).map(empName).join(', ') + (r.who.length > 4 ? ' + ' + (r.who.length - 4) + ' more' : '');
        return '<div class="brow' + (r.on ? '' : ' off') + '"><label class="btb-sw" title="Rule on / off"><input type="checkbox" data-tg="' + r.id + '"' + (r.on ? ' checked' : '') + ' aria-label="Rule on or off"><i></i></label><div class="btxt"><b>' + esc(r.label) + '</b> <span class="chip ' + s[0] + '">' + s[1] + '</span><small>' + dShow(r.from) + ' → ' + (r.to ? dShow(r.to) : 'no end date') + ' · ' + (r.all ? 'Everyone' : r.who.length + ' people') + ': ' + esc(names) + '</small><small>' + esc(steps) + '</small></div><button class="bx" type="button" data-ed="' + r.id + '">Edit</button><button class="bx del" type="button" data-dl="' + r.id + '" aria-label="Delete rule">Delete</button></div>';
      }).join('') : '<div class="muted" style="color:var(--muted);margin-bottom:10px">No target bonus rule yet.</div>') +
      '<button class="badd" type="button" id="btbAdd">+ Add target bonus rule</button>';
  }

  /* ---------- the editor ---------- */
  let E = null;
  const tiersSorted = () => E.tiers.filter((t) => t.on).sort((a, b) => a.over - b.over);
  function calc(target, ach, comm) {
    const pct = target > 0 ? (ach / target - 1) * 100 : -100; let hit = null;
    tiersSorted().forEach((t) => { if (pct + 1e-9 >= t.over) hit = t; });
    return { pct, hit, bonus: hit ? comm * hit.bonus / 100 : 0 };
  }
  function draw() {
    sheet.innerHTML = '<h3>' + (E.id ? 'Edit target bonus rule' : 'New target bonus rule') + '</h3>' +
      '<label class="l" for="btbName">Rule name</label><input type="text" id="btbName" value="' + esc(E.label) + '" maxlength="120">' +
      '<div class="btb-two"><div><label class="l" for="btbFrom">Starting date</label><input type="date" id="btbFrom" value="' + esc(E.from) + '"></div><div><label class="l" for="btbTo">Ending date</label><input type="date" id="btbTo" value="' + esc(E.to) + '"' + (E.noEnd ? ' disabled' : '') + '><label style="display:flex;gap:6px;align-items:center;font-size:12.5px;color:#6b7280;margin-top:6px"><input type="checkbox" id="btbNoEnd"' + (E.noEnd ? ' checked' : '') + '> No ending date (keeps running)</label></div></div>' +
      '<label class="l">Who gets this rule <span style="font-weight:600">(' + (E.all ? 'everyone' : E.who.length + ' of ' + D.employees.length + ' selected') + ')</span></label>' +
      '<div style="margin-bottom:8px"><button type="button" class="btb-p' + (E.all ? ' on' : '') + '" id="btbEvery" aria-pressed="' + E.all + '" style="padding:7px 16px;font-size:13.5px">★ Everyone — all sales people</button> <span style="font-size:12px;color:#6b7280">Applies to every sales person, also people added later.</span></div>' +
      (E.all ? '<div style="font-size:13px;color:#6b7280;background:#f6f7f9;border-radius:12px;padding:10px 12px">The rule is applied to all ' + D.employees.length + ' sales people. Switch Everyone off to choose people one by one.</div>'
        : '<div class="btb-ppl">' + D.employees.map((e) => '<button type="button" class="btb-p' + (E.who.indexOf(e.employee_id) > -1 ? ' on' : '') + '" data-p="' + e.employee_id + '" aria-pressed="' + (E.who.indexOf(e.employee_id) > -1) + '">' + esc(e.full_name) + '</button>').join('') + '</div>' +
          '<div style="display:flex;gap:8px;margin-top:8px"><button class="bx" type="button" id="btbAll">Select all listed</button><button class="bx" type="button" id="btbNone">Clear</button></div>') +
      '<label class="l">Bonus steps <span style="font-weight:600">(using ' + E.tiers.filter((t) => t.on).length + ' of ' + E.tiers.length + ')</span></label>' +
      E.tiers.map((t, i) => '<div class="btb-tier' + (t.on ? '' : ' off') + '"><label class="btb-sw"><input type="checkbox" data-ton="' + i + '"' + (t.on ? ' checked' : '') + ' aria-label="Use this step"><i></i></label><div><b>' + (t.over === 0 ? 'Target done' : 'Target + ' + fmtN(t.over) + '% more') + '</b><small>collection reaches <input type="number" min="0" max="500" step="1" data-tov="' + i + '" value="' + t.over + '" style="width:70px;display:inline-block;padding:3px 6px" aria-label="Percent above target"> % above target</small></div><div><small>Bonus on commission</small><input type="number" min="0" max="500" step="0.5" data-tbn="' + i + '" value="' + t.bonus + '" aria-label="Bonus percent"></div><button type="button" class="bx" data-tdl="' + i + '" aria-label="Remove step"' + (E.tiers.length < 2 ? ' disabled' : '') + '>×</button></div>').join('') +
      '<button class="bx" type="button" id="btbAddT">+ Add step</button>' +
      '<div class="btb-pv" id="btbPv"></div><div class="btb-err" id="btbErr"></div>' +
      '<div class="btb-act"><button class="btn secondary" type="button" id="btbCancel">Cancel</button><button class="btn" type="button" id="btbSave">Save rule</button></div>';
    pv();
  }
  function pv() {
    const el = $('btbPv'); if (!el) return;
    const T = 50000, A = [45000, 50000, 65000, 70000, 75000], C = 2800;
    el.innerHTML = '<b>Check with an example</b> <span style="color:#6b7280">(one day: target ₹50,000 · normal commission of that day ₹2,800)</span><div style="margin-top:6px">' + A.map((a) => { const r = calc(T, a, C); return inr(a) + ' (' + (a >= T ? '+' + Math.round((a / T - 1) * 100) + '%' : Math.round(a / T * 100) + '% of target') + ') → ' + (r.hit ? '<b>' + inr(C + r.bonus) + '</b> (+' + fmtN(r.hit.bonus) + '%)' : '<b>' + inr(C) + '</b> (no bonus)'); }).join('<br>') + '</div>';
  }
  function open(r) {
    E = r ? { id: r.id, label: r.label, from: r.from, to: r.to, noEnd: !r.to, who: r.who.slice(), all: !!r.all, on: r.on, tiers: r.tiers.map((t) => Object.assign({}, t)) }
      : { id: 0, label: 'Target bonus', from: todayIso().slice(0, 8) + '01', to: '', noEnd: false, who: [], all: false, on: true, tiers: [{ on: true, over: 0, bonus: 35 }, { on: true, over: 30, bonus: 50 }, { on: true, over: 40, bonus: 65 }, { on: true, over: 50, bonus: 80 }] };
    if (!r) { const d = new Date(), last = new Date(d.getFullYear(), d.getMonth() + 1, 0); E.to = last.getFullYear() + '-' + String(last.getMonth() + 1).padStart(2, '0') + '-' + String(last.getDate()).padStart(2, '0'); }
    modal.classList.add('on'); draw();
  }
  const close = () => { modal.classList.remove('on'); E = null; };
  async function save() {
    const err = $('btbErr'); err.textContent = '';
    const body = { rule_id: E.id, label: $('btbName').value, date_from: $('btbFrom').value, date_to: E.noEnd ? '' : $('btbTo').value, who: E.all ? [] : E.who, all: E.all ? 1 : 0, enabled: E.on ? 1 : 0, tiers: E.tiers.map((t) => ({ on: t.on ? 1 : 0, over: t.over, bonus: t.bonus })) };
    try { await api.post('commission_rules.bonus_save', body); close(); await load(); } catch (e) { err.textContent = e.message || 'Could not save.'; }
  }

  document.addEventListener('click', async (e) => {
    const t = e.target.closest('button'); if (!t) return;
    if (t.id === 'btbAdd') return open(null);
    if (t.dataset.ed) return open(D.bonus.find((r) => r.id === Number(t.dataset.ed)));
    if (t.dataset.dl) { if (t.dataset.sure !== '1') { t.dataset.sure = '1'; t.textContent = 'Sure? Delete'; setTimeout(() => { if (t.isConnected) { t.dataset.sure = ''; t.textContent = 'Delete'; } }, 3000); return; } try { await api.post('commission_rules.bonus_delete', { rule_id: Number(t.dataset.dl) }); await load(); } catch (x) { alert(x.message); } return; }
    if (!E) return;
    if (t.id === 'btbCancel') return close();
    if (t.id === 'btbSave') return save();
    if (t.id === 'btbEvery') { E.all = !E.all; return draw(); }
    if (t.id === 'btbAll') { E.who = D.employees.map((x) => x.employee_id); return draw(); }
    if (t.id === 'btbNone') { E.who = []; return draw(); }
    if (t.dataset.p) { const id = Number(t.dataset.p), i = E.who.indexOf(id); if (i > -1) E.who.splice(i, 1); else E.who.push(id); return draw(); }
    if (t.dataset.tdl) { E.tiers.splice(Number(t.dataset.tdl), 1); return draw(); }
    if (t.id === 'btbAddT') { const mx = E.tiers.reduce((a, x) => Math.max(a, x.over), 0); E.tiers.push({ on: true, over: mx + 10, bonus: 90 }); return draw(); }
  });
  document.addEventListener('change', async (e) => {
    const d = e.target.dataset || {};
    if (d.tg) { try { await api.post('commission_rules.bonus_toggle', { rule_id: Number(d.tg), enabled: e.target.checked ? 1 : 0 }); await load(); } catch (x) { alert(x.message); await load(); } return; }
    if (!E) return;
    if (d.ton !== undefined) { E.tiers[Number(d.ton)].on = e.target.checked; return draw(); }
    if (e.target.id === 'btbNoEnd') { E.noEnd = e.target.checked; return draw(); }
  });
  document.addEventListener('input', (e) => {
    if (!E) return; const d = e.target.dataset || {};
    if (d.tov !== undefined) { E.tiers[Number(d.tov)].over = Number(e.target.value) || 0; return pv(); }
    if (d.tbn !== undefined) { E.tiers[Number(d.tbn)].bonus = Number(e.target.value) || 0; return pv(); }
    if (e.target.id === 'btbName') E.label = e.target.value;
    if (e.target.id === 'btbFrom') E.from = e.target.value;
    if (e.target.id === 'btbTo') E.to = e.target.value;
  });
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && E) close(); });
  load();
})();
