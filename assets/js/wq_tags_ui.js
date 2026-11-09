/* 🏷 Auto tags — every tag of the shop in one list: what it means, who sets it, when it goes, how many customers have it;
 * the patterns the system learned from the tags the staff made by hand, and the tags Claude made on its own (each can be renamed, switched off or retired).
 * Backend: wq_tags.php (wq.tags_list / wq.tags_run / wq.tags_rule).   Needs api.js and wa_panels.js. */
(function () {
  'use strict';
  if (!window.WaPanels || !WaPanels.add) return;
  const { esc, toast, errBox, loading } = WaPanels.util;
  const KIND = { fact: ['📌 Fact', '#e0f2fe', '#075985'], status: ['📊 Status', '#dcfce7', '#166534'], intent: ['🎯 Intent', '#fef3c7', '#92400e'], journey: ['🗓 Journey', '#ede9fe', '#5b21b6'], manual: ['👤 Learned from you', '#f1f5f9', '#334155'], claude: ['🧠 Made by Claude', '#fae8ff', '#86198f'] };
  let CSS = false;
  function css() {
    if (CSS) return; CSS = true; const s = document.createElement('style');
    s.textContent = '.tg{display:grid;gap:10px;font-size:14px}.tg-card{background:#fff;border:1px solid #e6e8ef;border-radius:14px;padding:12px 14px}.tg-h{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-weight:800;font-size:15px}.tg-sub{color:#64748b;font-size:12.5px;margin-top:3px}.tg-sp{flex:1}'
      + '.tg-b{border:1px solid #d5d9e4;background:#fff;border-radius:10px;padding:8px 12px;min-height:38px;cursor:pointer;font-weight:700;font-size:13px}.tg-b.p{background:#5b3fd6;color:#fff;border-color:#5b3fd6}.tg-b.s{padding:5px 9px;min-height:32px;font-size:12px}.tg-b.r{color:#b91c1c}'
      + '.tg-pill{font-size:11px;font-weight:800;border-radius:99px;padding:2px 8px;white-space:nowrap}.tg-row{padding:10px 0;border-top:1px solid #f0f2f7}.tg-in{border:1px solid #d5d9e4;border-radius:8px;padding:6px 8px;min-height:34px;font-size:14px;min-width:0}'
      + '.tg-n{font-weight:800;font-size:18px}.tg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px}@media (max-width:700px){.tg-card{padding:10px}}';
    document.head.appendChild(s);
  }
  const fmt = (n) => Number(n || 0).toLocaleString('en-IN');
  function mount(el) {
    css(); let D = null, busy = false; el.innerHTML = '<div class="tg"><div class="tg-card">' + loading + '</div></div>';
    const call = async (fn) => { try { const r = await fn(); if (r && r.message) toast(r.message); return r; } catch (e) { toast(e.message || 'Something went wrong', true); return null; } };
    async function load() { try { D = await api.get('wq.tags_list'); } catch (e) { el.innerHTML = '<div class="tg">' + errBox(e.message) + '</div>'; return; } draw(); }
    const pill = (k) => { const x = KIND[k] || KIND.fact; return '<span class="tg-pill" style="background:' + x[1] + ';color:' + x[2] + '">' + x[0] + '</span>'; };
    function draw() {
      const groups = { intent: [], status: [], fact: [], journey: [] }; (D.rows || []).forEach((r) => { (groups[r.kind] || groups.fact).push(r); });
      let h = '<div class="tg"><div class="tg-card"><div class="tg-h">🏷 Auto tags<span class="tg-sp"></span><button class="tg-b p s" type="button" id="tgRun">Tag customers now</button><button class="tg-b s" type="button" id="tgDisc">🧠 Let Claude look for new tags</button></div>'
        + '<div class="tg-sub">Every tag has a meaning, a rule and a time it goes away. The system tags customers by itself from what they do — you keep tagging by hand as before; those tags are never changed. When you make a new tag, the system studies <b>why</b> you gave it, writes the rule, checks the rule against your own tagging, and if it matches well it applies the tag to everyone who fits. Claude may also make new tags on its own when it sees a useful pattern; it checks them against the existing ones so two tags never mean the same thing.' + (D.last_run ? ' Last run: ' + esc(D.last_run) + '.' : '') + '</div></div>';
      const man = D.manual || [];
      if (man.length) {
        h += '<div class="tg-card"><div class="tg-h">👤🧠 Tags made by you and by Claude — the pattern behind each</div>';
        man.forEach((m) => {
          const ok = m.status === 'active', weak = m.status === 'weak', off = m.status === 'off' || m.status === 'retired';
          h += '<div class="tg-row" data-tag="' + esc(m.tag) + '"><div class="tg-h" style="font-size:14px"><input class="tg-in" data-label value="' + esc(m.label) + '" maxlength="60" style="font-weight:800;max-width:230px">' + pill(m.learned ? (m.by_hand ? 'manual' : 'claude') : 'manual') + '<span class="tg-pill" style="background:#f1f5f9;color:#334155">' + fmt(m.n) + ' customers</span>'
            + '<span class="tg-pill" style="background:' + (ok ? '#dcfce7' : weak ? '#fef3c7' : '#f1f5f9') + ';color:' + (ok ? '#166534' : weak ? '#92400e' : '#475569') + '">' + (ok ? '✅ applied automatically' : weak ? '⚠ pattern too loose — not applied' : off ? '⏸ off' : '⏳ not studied yet') + '</span></div>'
            + (m.pattern ? '<div class="tg-sub">🧩 <b>Why it is given:</b> ' + esc(m.pattern) + '</div>' : '<div class="tg-sub">Not studied yet — it is studied in the background (or press “Tag customers now”).</div>')
            + (m.rule_text ? '<div class="tg-sub">📏 Rule: <code>' + esc(m.rule_text) + '</code> · matches ' + Math.round(m.recall * 100) + '% of customers who have it, ' + Math.round(m.falsepos * 100) + '% of those who do not</div>' : '')
            + (m.purpose ? '<div class="tg-sub">🎯 Use it for: ' + esc(m.purpose) + '</div>' : '')
            + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px">' + (m.learned ? '<label style="display:flex;gap:6px;align-items:center"><input type="checkbox" data-auto ' + (m.auto ? 'checked' : '') + '> apply to everyone who fits</label>' : '<button class="tg-b s" type="button" data-study>Study this tag now</button>')
            + '<button class="tg-b s" type="button" data-savelabel>Save name</button>' + (m.learned ? '<button class="tg-b s r" type="button" data-retire>Retire</button>' : '') + '</div></div>';
        });
        h += '</div>';
      }
      [['intent', '🎯 Intent — what the customer wants now (goes by itself)'], ['status', '📊 Status — what they are'], ['fact', '📌 Facts — how they came'], ['journey', '🗓 Journey']].forEach(([k, t]) => {
        const rows = groups[k]; if (!rows || !rows.length) return;
        h += '<div class="tg-card"><div class="tg-h">' + t + '</div><div class="tg-grid" style="margin-top:8px">' + rows.map((r) => '<div style="border:1px solid #eef0f5;border-radius:12px;padding:10px"><div style="display:flex;justify-content:space-between;gap:8px"><b>' + esc(r.label) + '</b><span class="tg-n">' + fmt(r.n) + '</span></div>'
          + (r.src === 'auto' ? '<span class="tg-pill" style="background:#dcfce7;color:#166534">🤖 automatic</span>' : '') + '<div class="tg-sub">' + esc(r.rule || '') + '</div><div class="tg-sub">⏱ ' + esc(r.expiry || '') + '</div>' + (r.used ? '<div class="tg-sub">➡ ' + esc(r.used) + '</div>' : '') + '</div>').join('') + '</div></div>';
      });
      el.innerHTML = h + '</div>'; bind();
    }
    function bind() {
      const $ = (s) => el.querySelector(s), $$ = (s) => [...el.querySelectorAll(s)];
      const run = async (body, btn) => { if (busy) return; busy = true; if (btn) btn.disabled = true; toast('Working… this can take a minute'); const r = await call(() => api.post('wq.tags_run', body)); busy = false; if (r) await load(); };
      $('#tgRun').onclick = (e) => run({}, e.target); $('#tgDisc').onclick = (e) => run({ discover: 1 }, e.target);
      $$('[data-tag]').forEach((row) => { const tag = row.dataset.tag;
        const sl = row.querySelector('[data-savelabel]'); if (sl) sl.onclick = async () => { await call(() => api.post('wq.tags_rule', { tag, label: row.querySelector('[data-label]').value })); await load(); };
        const au = row.querySelector('[data-auto]'); if (au) au.onchange = async () => { await call(() => api.post('wq.tags_rule', { tag, auto: au.checked ? 1 : 0 })); await load(); };
        const rt = row.querySelector('[data-retire]'); if (rt) rt.onclick = async () => { if (!confirm('Retire this tag? The automatic marks are removed (the ones people put stay).')) return; await call(() => api.post('wq.tags_rule', { tag, retire: 1 })); await load(); };
        const st = row.querySelector('[data-study]'); if (st) st.onclick = (e) => run({ tag }, e.target); });
    }
    load(); return () => { };
  }
  WaPanels.add('autotags', (el) => mount(el));
})();
