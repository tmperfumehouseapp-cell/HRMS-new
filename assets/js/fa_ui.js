/**
 * fa_ui.js — 🔁 FOLLOW-UP AUTOPILOT screen. window.FuAuto.mount(el, {view})
 *   Used in: Learn & Setup → 🔁 Follow-ups (learning.html), WhatsApp → Automation → Follow-ups.
 *   Tabs: Plan (mode, festival, tell Claude, the 6 paths with their days — teach each day, add / remove days) · Offers (ladder + the approved offer messages)
 *         · Results (sent → replied → ordered → ₹ per path and day) · Messages (every follow-up message in one list, edit in place).
 * Server: wa_autopilot.php (fu.ap_*).
 */
(function () {
  'use strict';
  if (window.FuAuto) return;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = (n) => '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN');
  const CSS = `
.fa{--ln:#e6e8ef;--mut:#64748b;--ink:#0f172a;--g:#059669;--gb:#ecfdf5;--o:#c2410c;--ob:#fff7ed;--p:#5b3fd6;--pb:#f4f1ff;font-size:14px;color:var(--ink);display:grid;gap:12px;min-width:0}
.fa *{box-sizing:border-box}.fa-card{background:#fff;border:1px solid var(--ln);border-radius:14px;padding:14px 16px;min-width:0}
.fa-h{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 8px;font-size:15px;font-weight:800}.fa-h .sp{flex:1}.fa-sub{color:var(--mut);font-size:12.5px;line-height:1.45}
.fa-seg{display:flex;gap:6px;flex-wrap:wrap}.fa-seg button,.fa-b{border:1px solid var(--ln);background:#fff;border-radius:999px;padding:6px 13px;font-weight:700;font-size:13px;cursor:pointer;color:var(--ink)}
.fa-seg button.on{background:var(--ink);color:#fff;border-color:var(--ink)}.fa-b.p{background:var(--g);border-color:var(--g);color:#fff}.fa-b.s{padding:4px 10px;font-size:12px}.fa-b.r{color:#b42318;border-color:#f3c0bb}.fa-b:disabled{opacity:.55;cursor:wait}
.fa-in,.fa-ta,.fa-sel{width:100%;border:1px solid var(--ln);border-radius:10px;padding:8px 10px;font:inherit;background:#fff;min-width:0}.fa-ta{min-height:64px;resize:vertical}.fa-sel{width:auto}
.fa-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.fa-mode button.on.off{background:#64748b;border-color:#64748b}.fa-mode button.on.sug{background:#b45309;border-color:#b45309}.fa-mode button.on.auto{background:var(--g);border-color:var(--g)}
.fa-path{border:1px solid var(--ln);border-radius:14px;margin-top:10px;overflow:hidden}.fa-ph{display:flex;align-items:center;gap:8px;padding:10px 12px;background:#fafbfc;cursor:pointer;flex-wrap:wrap}.fa-ph b{font-size:14.5px}.fa-ph .sp{flex:1}
.fa-tl{display:flex;gap:6px;flex-wrap:wrap;padding:8px 12px;border-top:1px solid var(--ln)}.fa-pill{font-size:11.5px;font-weight:700;border-radius:999px;padding:2px 8px;background:#eef0f3;color:#334155;white-space:nowrap}.fa-pill.o{background:var(--ob);color:var(--o)}.fa-pill.g{background:var(--gb);color:#065f46}
.fa-days{padding:10px 12px;display:grid;gap:8px;border-top:1px solid var(--ln)}.fa-day{border:1px solid var(--ln);border-radius:12px;padding:10px;display:grid;gap:6px}.fa-day.offer{background:var(--ob);border-color:#fed7aa}
.fa-dh{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.fa-dh .n{font-weight:800}.fa-num{width:76px}.fa-teach{display:flex;gap:6px}.fa-teach .fa-in{flex:1}.fa-st{font-size:11.5px;color:var(--mut)}
.fa-prop{border:1px solid #d9d0ff;background:var(--pb);border-radius:12px;padding:10px 12px;margin-top:10px}.fa-prop li{margin:2px 0}
.fa-kpi{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}.fa-k{border:1px solid var(--ln);border-radius:12px;padding:8px 10px}.fa-k small{display:block;font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--mut)}.fa-k b{font-size:19px}
.fa-tbl{width:100%;border-collapse:collapse;font-size:13px}.fa-tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--mut);text-align:left;padding:5px 6px}.fa-tbl td{padding:6px;border-top:1px solid var(--ln)}.fa-tbl td.n,.fa-tbl th.n{text-align:right}
.fa-err{background:#fff1f0;border:1px solid #f3c0bb;color:#a12;border-radius:10px;padding:8px 10px}.fa-ok{background:var(--gb);border:1px solid #bfe5cd;color:#065f46;border-radius:10px;padding:8px 10px}
.fa-ov{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px}.fa-pop{background:#fff;border-radius:16px;max-width:640px;width:100%;max-height:88vh;overflow:auto;padding:16px 18px;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.fa-q{background:#fff8e6;border:1px solid #fde3a7;border-radius:10px;padding:8px 10px;margin:8px 0}.fa-q li{margin:3px 0}.fa-turn{font-size:12.5px;padding:4px 0;border-top:1px dashed var(--ln)}.fa-turn b{color:var(--p)}
.fa-toast{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:#0f172a;color:#fff;padding:9px 14px;border-radius:12px;z-index:99999;font-weight:700;max-width:90vw}.fa-toast.bad{background:#b42318}
@media (max-width:700px){.fa-kpi{grid-template-columns:repeat(2,minmax(0,1fr))}.fa-card{padding:12px}.fa-num{width:64px}}`;
  function css() { if (document.getElementById('faCss')) return; const s = document.createElement('style'); s.id = 'faCss'; s.textContent = CSS; document.head.appendChild(s); }
  function toast(t, bad) { const e = document.createElement('div'); e.className = 'fa-toast' + (bad ? ' bad' : ''); e.textContent = t; document.body.appendChild(e); setTimeout(() => e.remove(), 3200); }
  async function call(fn) { try { const r = await fn(); if (r && r.message) toast(r.message); return r; } catch (e) { toast(e.message || 'Something went wrong', true); return null; } }
  const when = (h) => { h = Number(h); if (h < 1) return Math.round(h * 60) + ' min'; if (h < 24) return (Math.round(h * 10) / 10) + ' h'; const d = h / 24; return 'day ' + (Math.round(d * 10) / 10); };
  const STATUS = { approved: ['🟢 Approved by Meta', 'g'], approved_old: ['🟡 New words wait for Meta — the older approved one goes', ''], pending: ['🟡 Waiting for Meta', ''], rejected: ['🔴 Meta said no — change the words', 'o'], none: ['Not sent to Meta yet', ''] };

  function mount(el, opts) {
    css(); opts = opts || {}; let D = null, tab = opts.view || 'plan', open = {}, R = null, rdays = 30, edits = {};
    el.innerHTML = '<div class="fa"><div class="fa-card">Loading…</div></div>';
    async function load() { try { D = await api.get('fu.ap_get'); } catch (e) { el.innerHTML = '<div class="fa"><div class="fa-err">' + esc(e.message) + '</div></div>'; return; } draw(); }
    const offerOpts = (k) => (D.offers || []).map((o) => '<option value="' + esc(o.k) + '"' + (o.k === k ? ' selected' : '') + '>🎁 ' + esc(o.label) + '</option>').join('');
    function head() {
      const m = D.mode === 'autotpl' || D.mode === 'auto24' ? 'auto' : D.mode;
      const tabs = opts.view === 'messages' ? [['messages', '💬 Follow-up messages'], ['offers', '🎁 Offer messages']] : [['plan', '🔁 Plan'], ['offers', '🎁 Offers & festival'], ['results', '📊 Results'], ['messages', '💬 Messages']];
      return '<div class="fa-card"><div class="fa-h">🔁 Follow-up Autopilot<span class="sp"></span><span class="fa-seg fa-mode">' + [['off', 'Off', 'off'], ['suggest', 'Suggest only', 'sug'], ['autotpl', 'Auto', 'auto']].map(([k, l, c]) => '<button type="button" data-mode="' + k + '" class="' + c + (m === (k === 'autotpl' ? 'auto' : k) ? ' on' : '') + '">' + l + '</button>').join('') + '</span></div>'
        + '<div class="fa-sub">Every customer who asked and did not order gets their own path: 3–4 messages Claude writes from their chat in the first 24 hours, then offers on day 2, 4, 7 and 10. It stops by itself when they reply, order, say stop, sound angry or have a complaint. Nothing goes ' + esc(D.limits.quiet) + '. '
        + (D.mode === 'suggest' ? '<b>Suggest only</b>: messages are prepared and shown in the chat — nothing is sent. Switch to <b>Auto</b> when the wording looks right.' : D.mode === 'off' ? '<b>Off</b>: nothing is prepared or sent.' : '<b>Auto</b>: messages and offers go by themselves.') + '</div>'
        + (D.season_now ? '<div class="fa-ok" style="margin-top:8px">🎉 Festival on now: <b>' + esc(D.season_now) + '</b> — every message and offer uses it.</div>' : '')
        + (!D.ai ? '' : '') + '<div class="fa-seg" style="margin-top:10px">' + tabs.map(([k, l]) => '<button type="button" data-tab="' + k + '" class="' + (tab === k ? 'on' : '') + '">' + l + '</button>').join('') + '</div></div>';
    }
    function offerText(k) {
      const o = (D.offers || []).find((z) => z.k === k); if (!o) return '';
      const b = String(o.body || '').replace(/\{\{1\}\}/g, '[Name]').replace(/\{\{2\}\}/g, '[Perfume]').replace(/\{\{3\}\}/g, '[Offer]');
      return '<div class="fa-st" style="background:#fff;border:1px solid #e4e7ec;border-radius:10px;padding:8px 10px;margin-top:4px;color:#111b21"><b>' + esc(o.label) + '</b>' + (o.rule ? ' — ' + esc(o.rule) : '') + (b ? '<div style="margin-top:4px;white-space:pre-wrap">' + esc(b) + '</div>' : '') + '<div style="margin-top:4px;color:#667781">[Name] = the customer, [Perfume] = what they asked for, [Offer] = the deal from your price chart. Change these words in 🎁 Offers &amp; festival.</div></div>';
    }
    function dayCard(p, x, i) {
      const isO = x.kind === 'offer', v = x.after_h >= 24 ? Math.round(x.after_h / 24 * 10) / 10 : (x.after_h < 1 ? Math.round(x.after_h * 60) : Math.round(x.after_h * 10) / 10), u = x.after_h >= 24 ? 'd' : (x.after_h < 1 ? 'm' : 'h');
      return '<div class="fa-day' + (isO ? ' offer' : '') + '" data-day="' + i + '"><div class="fa-dh"><span class="n">#' + (i + 1) + '</span> after <input class="fa-in fa-num" type="number" min="1" step="any" data-f="v" value="' + v + '"><select class="fa-sel" data-f="u"><option value="m"' + (u === 'm' ? ' selected' : '') + '>minutes</option><option value="h"' + (u === 'h' ? ' selected' : '') + '>hours</option><option value="d"' + (u === 'd' ? ' selected' : '') + '>days</option></select>'
        + '<select class="fa-sel" data-f="kind"><option value="nudge"' + (!isO ? ' selected' : '') + '>💬 Message</option><option value="offer"' + (isO ? ' selected' : '') + '>🎁 Offer</option></select>' + (isO ? '<select class="fa-sel" data-f="offer">' + offerOpts(x.offer) + '</select>' : '')
        + '<span class="sp" style="flex:1"></span><span class="fa-st">' + (x.sent ? x.sent + ' sent · ' + x.replied + ' replied' : '') + '</span><button class="fa-b s r" type="button" data-rm="' + i + '" title="Remove this day">✕</button></div>'
        + (isO ? '<div class="fa-st">' + (x.after_h >= 23.5 ? 'Goes as the approved offer message — Claude fills the customer\'s perfume and the offer from your price chart.' : 'Inside 24 hours — Claude writes the offer as a normal message.') + '</div>' + offerText(x.offer) : '<textarea class="fa-ta" data-f="text" placeholder="What this message should say (Claude writes it for each customer; without Claude these words go as they are)">' + esc(x.text) + '</textarea>' + (x.after_h >= 23.5 ? '<div class="fa-st">⚠️ After 24 hours a free message cannot reach the customer — this day is skipped unless it is an offer.</div>' : ''))
        + '<div class="fa-teach"><input class="fa-in" data-f="remark" placeholder="Teach this day — e.g. instead of a free 10 ml, offer a combo" value="' + esc(x.remark) + '"><button class="fa-b s" type="button" data-teach="' + i + '">Teach</button></div></div>';
    }
    function readDays(box) { return [...box.querySelectorAll('.fa-day')].map((d) => { const g = (f) => { const e = d.querySelector('[data-f="' + f + '"]'); return e ? e.value : ''; }; const v = Number(g('v')) || 0, u = g('u');
      return { after_h: u === 'm' ? v / 60 : (u === 'd' ? v * 24 : v), kind: g('kind'), offer: g('offer'), text: g('text'), remark: g('remark') }; }); }
    function planTab() {
      let h = '<div class="fa-card"><div class="fa-h">🧠 Claude — tell it anything about the follow-ups</div><div class="fa-sub">Your general summary in your own words (Hindi / Gujarati / English) — e.g. “For price-felt-high, move the combo to day 3 and add a day 12 with the mega offer”, or “Diwali is over, all offers should say New Year”. A pop-up shows what Claude understood, its questions and every change; reply there until it is right. Nothing changes before you press Approve.</div>'
        + '<textarea class="fa-ta" id="faSum" style="margin-top:8px" placeholder="Write what to change…"></textarea><div class="fa-row" style="margin-top:6px"><button class="fa-b p" type="button" id="faSumGo">Ask Claude</button>' + (D.ai ? '' : '<span class="fa-st">Claude is not set up on the server.</span>') + '</div>';
      const pr = D.proposal; if (pr && pr.understood) h += '<div class="fa-prop"><b>🧠 Claude is waiting for you:</b> ' + esc(pr.understood) + ' <button class="fa-b s p" type="button" id="faOpenProp">Open</button></div>';
      h += '</div><div class="fa-card"><div class="fa-h">🛤 The paths<span class="sp"></span><button class="fa-b s" type="button" id="faReset" title="Put the Autopilot plan back to its starting days">Reset to the starting plan</button></div><div class="fa-sub">Claude reads each quiet chat and puts the customer on one path. Open a path to change its days, teach a day, or add one (up to 10 days, up to 30 days long).</div>';
      for (const p of D.paths) {
        const op = open[p.id];
        h += '<div class="fa-path" data-p="' + esc(p.id) + '"><div class="fa-ph" data-open="' + esc(p.id) + '"><b>' + esc(p.title) + '</b><span class="fa-st">' + esc(p.desc) + '</span><span class="sp"></span><button type="button" class="fa-b s p" data-ptpop="' + esc(p.id) + '" onclick="event.stopPropagation()">🎤 Teach</button><label class="fa-st" style="display:flex;gap:4px;align-items:center" onclick="event.stopPropagation()"><input type="checkbox" data-on="' + esc(p.id) + '"' + (p.on ? ' checked' : '') + '> on</label><span>' + (op ? '▲' : '▼') + '</span></div>'
          + '<div class="fa-tl">' + p.steps.map((x) => '<span class="fa-pill' + (x.kind === 'offer' ? ' o' : '') + '">' + when(x.after_h) + (x.kind === 'offer' ? ' · 🎁 ' + esc(((D.offers || []).find((o) => o.k === x.offer) || {}).label || x.offer) : ' · 💬') + '</span>').join('') + '</div>'
          + (op ? '<div class="fa-days">' + p.steps.map((x, i) => dayCard(p, x, i)).join('') + '<div class="fa-row"><button class="fa-b" type="button" data-add="' + esc(p.id) + '">＋ Add a day</button><button class="fa-b p" type="button" data-save="' + esc(p.id) + '">Save this path</button></div>'
            + '<div class="fa-teach" style="margin-top:6px"><input class="fa-in" data-pt="' + esc(p.id) + '" placeholder="🧠 Teach this WHOLE path — e.g. be friendly, no pressure, small discount on the last day"><button class="fa-b s p" type="button" data-pteach="' + esc(p.id) + '">Teach whole path</button></div></div>' : '') + '</div>';
      }
      return h + '</div>';
    }
    function offersTab() {
      let h = '<div class="fa-card"><div class="fa-h">🎁 The offer ladder</div><div class="fa-sub">What each offer means — Claude follows your rule for every customer, with the real price from your price chart, never below your lowest price. The message is the approved WhatsApp message (after 24 hours): {{1}} name · {{2}} the perfume they asked about · {{3}} the offer. New words go to Meta for approval; until then the older approved one goes.</div>';
      h += (D.offers || []).map((o) => { const s = STATUS[o.status] || STATUS.none; return '<div class="fa-day offer" style="margin-top:10px" data-of="' + esc(o.k) + '"><div class="fa-dh"><input class="fa-in" style="max-width:180px" data-f="label" value="' + esc(o.label) + '"><span class="fa-pill ' + s[1] + '">' + s[0] + '</span><span class="sp" style="flex:1"></span><button class="fa-b s" type="button" data-tpl="' + esc(o.k) + '">Send to Meta</button></div>'
        + '<label class="fa-st">Your rule</label><input class="fa-in" data-f="rule" value="' + esc(o.rule) + '"><label class="fa-st">The approved message</label><textarea class="fa-ta" data-f="body">' + esc(o.body) + '</textarea></div>'; }).join('');
      h += '<div class="fa-row" style="margin-top:10px"><button class="fa-b p" type="button" id="faOfSave">Save the offers</button></div></div>';
      if (opts.view !== 'messages') { const s = D.season || {}; h += '<div class="fa-card"><div class="fa-h">🎉 Festival / season</div><div class="fa-sub">While it is on (between the dates), Claude uses these words in every message and offer — e.g. “Happy New Year offers” instead of Diwali. It switches back by itself after the end date. No new Meta approval is needed: the festival goes into the offer\'s blanks.</div>'
        + '<div class="fa-row" style="margin-top:8px"><label class="fa-row"><input type="checkbox" id="faSOn"' + (s.on ? ' checked' : '') + '> On</label><input class="fa-in" id="faSText" style="flex:1;min-width:200px" placeholder="e.g. Happy New Year offers — 2 perfumes, 1 gift" value="' + esc(s.text) + '"></div>'
        + '<div class="fa-row" style="margin-top:6px">From <input class="fa-in" type="date" id="faSF" style="width:auto" value="' + esc(s.from) + '"> to <input class="fa-in" type="date" id="faST" style="width:auto" value="' + esc(s.to) + '"><button class="fa-b p" type="button" id="faSSave">Save</button></div></div>'; }
      return h;
    }
    function resultsTab() {
      if (!R) return '<div class="fa-card">Loading…</div>'; const t = R.total, name = (sid) => ((D.paths.find((p) => p.id === sid) || {}).title) || sid;
      let h = '<div class="fa-card"><div class="fa-h">📊 Results<span class="sp"></span><span class="fa-seg">' + [7, 30, 90].map((d) => '<button type="button" data-rd="' + d + '" class="' + (rdays === d ? 'on' : '') + '">' + d + ' days</button>').join('') + '</span></div>'
        + '<div class="fa-kpi">' + [['Sent', t.sent], ['Replied', t.replied + (t.sent ? ' (' + Math.round(t.replied / t.sent * 100) + '%)' : '')], ['Ordered', t.ordered], ['Sales', inr(t.sales)], ['Pre-made (paid)', t.tpl]].map((x) => '<div class="fa-k"><small>' + x[0] + '</small><b>' + x[1] + '</b></div>').join('') + '</div>'
        + '<div class="fa-sub" style="margin-top:6px">An order within 7 days after a follow-up counts for the last follow-up the customer got.</div><div style="overflow-x:auto;margin-top:8px"><table class="fa-tbl"><thead><tr><th>Path</th><th>Day</th><th class="n">Sent</th><th class="n">Replied</th><th class="n">Ordered</th><th class="n">Sales</th></tr></thead><tbody>'
        + (R.steps.length ? R.steps.sort((a, b) => a.sid.localeCompare(b.sid) || a.step - b.step).map((x) => { const p = D.paths.find((q) => q.id === x.sid), st = p && p.steps[x.step]; return '<tr><td>' + esc(name(x.sid)) + '</td><td>#' + (x.step + 1) + (st ? ' · ' + when(st.after_h) + (st.kind === 'offer' ? ' 🎁' : '') : '') + '</td><td class="n">' + x.sent + '</td><td class="n">' + x.replied + '</td><td class="n"><b>' + x.ordered + '</b></td><td class="n">' + inr(x.sales) + '</td></tr>'; }).join('') : '<tr><td colspan="6" class="fa-st">Nothing sent in this period yet.</td></tr>')
        + '</tbody></table></div></div>';
      return h;
    }
    function messagesTab() {
      let h = '<div class="fa-card"><div class="fa-h">💬 Every follow-up message</div><div class="fa-sub">The words of each message day, path by path (Claude writes each one for the customer from these words; without Claude they go as they are). Change and press Save. The offers\' approved messages are on the 🎁 tab.</div>';
      for (const p of D.paths) { const L = p.steps.map((x, i) => [x, i]).filter(([x]) => x.kind !== 'offer'); if (!L.length) continue;
        h += '<div class="fa-path" data-p="' + esc(p.id) + '"><div class="fa-ph"><b>' + esc(p.title) + '</b></div><div class="fa-days">' + L.map(([x, i]) => '<div class="fa-day" data-mi="' + i + '"><div class="fa-dh"><span class="n">#' + (i + 1) + ' · ' + when(x.after_h) + '</span><span class="fa-st">' + (x.sent ? x.sent + ' sent · ' + x.replied + ' replied' : '') + '</span></div><textarea class="fa-ta" data-f="text">' + esc(x.text) + '</textarea></div>').join('')
          + '<div class="fa-row"><button class="fa-b p" type="button" data-msave="' + esc(p.id) + '">Save</button></div></div></div>'; }
      return h + '</div>';
    }
    function draw() {
      if (!D) return; let h = '<div class="fa">' + head();
      if (!D.installed && tab === 'plan') h += '<div class="fa-card"><div class="fa-h">Start the Autopilot</div><div class="fa-sub">It replaces the old follow-up plan (kept as a backup) with the 6 paths and starts in <b>Suggest only</b>.</div><button class="fa-b p" type="button" id="faInst" style="margin-top:8px">Start the Autopilot</button></div>';
      h += tab === 'plan' ? planTab() : tab === 'offers' ? offersTab() : tab === 'results' ? resultsTab() : messagesTab();
      el.innerHTML = h + '</div>'; bind();
    }
    function bind() {
      const $ = (s) => el.querySelector(s), $$ = (s) => [...el.querySelectorAll(s)];
      $$('[data-tab]').forEach((b) => b.onclick = async () => { tab = b.dataset.tab; draw(); if (tab === 'results') { R = null; draw(); try { R = await api.get('fu.ap_results', { days: rdays }); } catch (e) { R = { total: { sent: 0, replied: 0, ordered: 0, sales: 0, tpl: 0 }, steps: [] }; } draw(); } });
      $$('[data-rd]').forEach((b) => b.onclick = async () => { rdays = Number(b.dataset.rd); R = null; draw(); try { R = await api.get('fu.ap_results', { days: rdays }); } catch (e) { } draw(); });
      $$('[data-mode]').forEach((b) => b.onclick = async () => { const m = b.dataset.mode; if (m === 'autotpl' && !confirm('Auto: follow-ups and offers go to customers by themselves. Did you check the wording in Suggest only?')) return; const r = await call(() => api.post('fu.ap_mode', { mode: m })); if (r) { D = r; draw(); toast(m === 'off' ? 'Follow-ups are off' : m === 'suggest' ? 'Suggest only — nothing is sent' : 'Auto is on ✓'); } });
      const inst = $('#faInst'); if (inst) inst.onclick = async () => { inst.disabled = true; const r = await call(() => api.post('fu.ap_install', {})); if (r) { D = r; draw(); toast('The Autopilot is set up ✓ — Suggest only'); } else inst.disabled = false; };
      const rs = $('#faReset'); if (rs) rs.onclick = async () => { if (!confirm('Put every path back to its starting days? Your own changes to the days are replaced (the old plan is kept as a backup).')) return; const r = await call(() => api.post('fu.ap_install', {})); if (r) { D = r; draw(); toast('Back to the starting plan'); } };
      { const sm = $('#faSum'); if (sm) sm.after(mic(sm)); }   // 🎤 say it instead of typing
      const sg = $('#faSumGo'); if (sg) sg.onclick = async () => { const t = $('#faSum').value.trim(); if (!t) return; sg.disabled = true; sg.textContent = 'Claude is reading…'; const r = await call(() => api.post('fu.ap_summary', { text: t })); sg.disabled = false; sg.textContent = 'Ask Claude'; if (r) { D = r; draw(); popup(); } };
      const op = $('#faOpenProp'); if (op) op.onclick = popup;
      $$('[data-open]').forEach((b) => b.onclick = () => { open[b.dataset.open] = !open[b.dataset.open]; draw(); });
      $$('[data-on]').forEach((c) => c.onchange = async () => { const p = D.paths.find((x) => x.id === c.dataset.on); const r = await call(() => api.post('fu.ap_path_save', { sid: p.id, steps: p.steps, on: c.checked })); if (r) { D = r; draw(); } });
      const pathBox = (sid) => el.querySelector('.fa-path[data-p="' + sid + '"] .fa-days');
      $$('[data-add]').forEach((b) => b.onclick = () => { const p = D.paths.find((x) => x.id === b.dataset.add); const cur = readDays(pathBox(p.id)); if (cur.length >= 10) { toast('Up to 10 days per path', true); return; } const last = cur.length ? cur[cur.length - 1].after_h : 0; p.steps = cur.concat([{ after_h: Math.max(last + 48, 24 * 12), kind: 'offer', offer: 'mega', text: '', remark: '' }]); draw(); });
      $$('[data-rm]').forEach((b) => b.onclick = () => { const box = b.closest('.fa-path'), p = D.paths.find((x) => x.id === box.dataset.p); const cur = readDays(pathBox(p.id)); cur.splice(Number(b.dataset.rm), 1); p.steps = cur; draw(); });
      $$('.fa-day [data-f="kind"]').forEach((s) => s.onchange = () => { const box = s.closest('.fa-path'), p = D.paths.find((x) => x.id === box.dataset.p); const cur = readDays(pathBox(p.id)); const i = Number(s.closest('.fa-day').dataset.day); cur[i].kind = s.value; if (s.value === 'offer' && !cur[i].offer) cur[i].offer = (D.offers[0] || {}).k; p.steps = cur; draw(); });
      $$('[data-save]').forEach((b) => b.onclick = async () => { const sid = b.dataset.save; b.disabled = true; const r = await call(() => api.post('fu.ap_path_save', { sid, steps: readDays(pathBox(sid)) })); b.disabled = false; if (r) { D = r; open[sid] = false; draw(); toast('Saved — this path is closed and works from now on'); } });
      $$('.fa-day [data-f="offer"]').forEach((sl) => sl.onchange = () => { const box = sl.closest('.fa-path'), p = D.paths.find((x) => x.id === box.dataset.p); const cur = readDays(pathBox(p.id)); cur[Number(sl.closest('.fa-day').dataset.day)].offer = sl.value; p.steps = cur; draw(); });
      $$('[data-teach]').forEach((b) => b.onclick = async () => { const box = b.closest('.fa-path'), sid = box.dataset.p, i = Number(b.dataset.teach), t = b.parentNode.querySelector('[data-f="remark"]').value.trim(); if (!t) { toast('Write what this day should do', true); return; } b.disabled = true; b.textContent = 'Teaching…';
        const r = await call(() => api.post('fu.ap_step_teach', { sid, n: i + 1, text: t })); if (r) { D = r; draw(); if (r.popup) popup(); } else { b.disabled = false; b.textContent = 'Teach'; } });
      $$('[data-pteach]').forEach((b) => b.onclick = async () => { const sid = b.dataset.pteach, inp = el.querySelector('[data-pt="' + sid + '"]'), t = inp.value.trim(); if (!t) { toast('Write what this path should do', true); return; } b.disabled = true; b.textContent = 'Claude is reading…';
        const r = await call(() => api.post('fu.ap_path_teach', { sid, text: t })); b.disabled = false; b.textContent = 'Teach whole path'; if (r) { D = r; draw(); if (r.popup) popup(); } });
      $$('[data-ptpop]').forEach((b) => b.onclick = (e) => { e.stopPropagation(); teachPop(b.dataset.ptpop); });
      $$('[data-msave]').forEach((b) => b.onclick = async () => { const sid = b.dataset.msave, p = D.paths.find((x) => x.id === sid), st = p.steps.map((x) => Object.assign({}, x)); b.closest('.fa-path').querySelectorAll('[data-mi]').forEach((d) => { st[Number(d.dataset.mi)].text = d.querySelector('[data-f="text"]').value; });
        b.disabled = true; const r = await call(() => api.post('fu.ap_path_save', { sid, steps: st })); b.disabled = false; if (r) { D = r; draw(); } });
      const os = $('#faOfSave'); if (os) os.onclick = async () => { const offers = $$('[data-of]').map((d) => ({ k: d.dataset.of, label: d.querySelector('[data-f="label"]').value, rule: d.querySelector('[data-f="rule"]').value, body: d.querySelector('[data-f="body"]').value })); os.disabled = true; const r = await call(() => api.post('fu.ap_offers_save', { offers })); os.disabled = false; if (r) { D = r; draw(); } };
      $$('[data-tpl]').forEach((b) => b.onclick = async () => { b.disabled = true; const r = await call(() => api.post('fu.ap_tpl', { k: b.dataset.tpl })); b.disabled = false; if (r) { D = r; draw(); } });
      const ss = $('#faSSave'); if (ss) ss.onclick = async () => { const r = await call(() => api.post('fu.ap_season_save', { on: $('#faSOn').checked, text: $('#faSText').value, from: $('#faSF').value, to: $('#faST').value })); if (r) { D = r; draw(); } };
    }
    // 🎤 voice typing: Start / Stop, in Hindi, Gujarati or English (Chrome's built-in speech reader; nothing is recorded or kept)
    function mic(target) {
      const SR = window.SpeechRecognition || window.webkitSpeechRecognition; const w = document.createElement('div'); w.className = 'fa-mic';
      if (!document.getElementById('faMicCss')) { const st = document.createElement('style'); st.id = 'faMicCss'; st.textContent = '.fa-mic{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px}.fa-mic select{width:auto;min-width:90px}'; document.head.appendChild(st); }
      if (!SR) { w.innerHTML = '<span class="fa-st">🎤 Voice typing works in Google Chrome — you can still write here.</span>'; return w; }
      w.innerHTML = '<select class="fa-in" data-lg><option value="hi-IN">हिन्दी</option><option value="gu-IN">ગુજરાતી</option><option value="en-IN">English</option></select><button type="button" class="fa-b s" data-mic>🎤 Start</button><span class="fa-st" data-ms></span>';
      const btn = w.querySelector('[data-mic]'), ms = w.querySelector('[data-ms]'), lg = w.querySelector('[data-lg]'); let rec = null, on = false, base = '', fin = '';
      try { lg.value = localStorage.getItem('faLang') || 'hi-IN'; } catch (e) { /* ok */ }
      const stop = () => { on = false; if (rec) { try { rec.stop(); } catch (e) { /* ok */ } } btn.textContent = '🎤 Start'; btn.classList.remove('r'); };
      btn.onclick = () => {
        if (on) { stop(); ms.textContent = ' Stopped. You can edit the text, or press Start to add more.'; return; }
        rec = new SR(); rec.lang = lg.value; rec.continuous = true; rec.interimResults = true; try { localStorage.setItem('faLang', lg.value); } catch (e) { /* ok */ }
        base = target.value ? target.value.replace(/\s+$/, '') + ' ' : ''; fin = '';
        rec.onresult = (e) => { let interim = ''; for (let i = e.resultIndex; i < e.results.length; i++) { const r = e.results[i]; if (r.isFinal) fin += r[0].transcript + ' '; else interim += r[0].transcript; } target.value = (base + fin + interim).trim(); };
        rec.onerror = (e) => { ms.textContent = e.error === 'not-allowed' ? ' Please allow the microphone for this site.' : ' ' + e.error; stop(); };
        rec.onend = () => { if (on) { try { base = target.value.replace(/\s+$/, '') + ' '; fin = ''; rec.start(); } catch (e) { stop(); } } };   // keeps listening until you press Stop
        try { rec.start(); on = true; btn.textContent = '⏹ Stop'; btn.classList.add('r'); ms.textContent = ' Listening… speak, then press Stop.'; } catch (e) { ms.textContent = ' Could not start the microphone.'; }
      };
      return w;
    }
    // 🧠 the teaching pop-up of one path: say (or write) everything once; Claude reads the Teach bot + store facts and prepares days, hours and messages; answer / correct as many times as needed
    function teachPop(sid) {
      const pth = D.paths.find((x) => x.id === sid); if (!pth) return; document.querySelectorAll('.fa-ov').forEach((x) => x.remove());
      const ov = document.createElement('div'); ov.className = 'fa-ov';
      ov.innerHTML = '<div class="fa-pop fa"><div class="fa-h">🧠 Teach · ' + esc(pth.title) + '<span class="sp"></span><button class="fa-b s" type="button" data-x>✕</button></div>'
        + '<div class="fa-sub">Tap <b>Start</b>, say the whole summary of what this path should do differently — days and hours, what to say, offers, what to stop — then tap <b>Stop</b>. Claude reads everything already taught (Teach bot, store facts, price chart, limits) and prepares the new days and messages. <b>Nothing changes until you press Approve.</b> If Claude does not understand, just say it again.</div>'
        + '<textarea class="fa-ta" id="faTp" style="margin-top:8px;min-height:130px" placeholder="Tap Start and speak, or write here…"></textarea><div id="faTpMic"></div>'
        + '<div class="fa-row" style="margin-top:10px"><button class="fa-b p" type="button" id="faTpGo">Send to Claude</button></div></div>';
      document.body.appendChild(ov); const ta = ov.querySelector('#faTp'); ov.querySelector('#faTpMic').appendChild(mic(ta)); setTimeout(() => ta.focus(), 50);
      ov.onclick = (e) => { if (e.target === ov) ov.remove(); }; ov.querySelector('[data-x]').onclick = () => ov.remove();
      ov.querySelector('#faTpGo').onclick = async function () { const t = ta.value.trim(); if (!t) { toast('Say or write what this path should do', true); return; } this.disabled = true; this.textContent = 'Claude is reading…';
        const r = await call(() => api.post('fu.ap_path_teach', { sid, text: t })); if (r) { D = r; ov.remove(); draw(); if (r.popup) popup(); } else { this.disabled = false; this.textContent = 'Send to Claude'; } };
    }
    // 🧠 the pop-up: what Claude understood, its questions, every change — reply until it is right, then Approve (nothing changes before)
    function popup() {
      const pr = D && D.proposal; if (!pr || !pr.understood) return; document.querySelectorAll('.fa-ov').forEach((x) => x.remove());
      const ov = document.createElement('div'); ov.className = 'fa-ov';
      ov.innerHTML = '<div class="fa-pop fa"><div class="fa-h">🧠 What Claude understood<span class="sp"></span><button class="fa-b s" type="button" data-x>✕</button></div>' + (pr.scope ? '<div class="fa-st" style="margin-bottom:6px">About: ' + esc(pr.scope) + '</div>' : '')
        + '<div style="font-size:14.5px;line-height:1.5">' + esc(pr.understood) + '</div>'
        + ((pr.questions || []).length ? '<div class="fa-q"><b>❓ Claude asks you:</b><ul>' + pr.questions.map((q) => '<li>' + esc(q) + '</li>').join('') + '</ul>Answer below and press Send.</div>' : '')
        + ((pr.changes || []).length ? '<div style="margin-top:8px"><b>What will change:</b><ul>' + pr.changes.map((c) => '<li>' + esc(c) + '</li>').join('') + '</ul></div>' : '<div class="fa-st" style="margin-top:8px">Nothing is changed yet — answer Claude\'s questions or say it more clearly.</div>')
        + ((pr.turns || []).length > 2 ? '<details style="margin-top:6px"><summary class="fa-st">The conversation so far</summary>' + pr.turns.map((t) => '<div class="fa-turn"><b>' + (t.who === 'you' ? 'You' : 'Claude') + ':</b> ' + esc(t.text) + '</div>').join('') + '</details>' : '')
        + '<textarea class="fa-ta" id="faRep" style="margin-top:10px" placeholder="' + ((pr.questions || []).length ? 'Answer Claude\'s questions, or correct what it understood…' : 'Correct or add something (optional)…') + '"></textarea>'
        + '<div class="fa-row" style="margin-top:8px"><button class="fa-b" type="button" id="faRepGo">Send to Claude</button><span class="sp" style="flex:1"></span><button class="fa-b r" type="button" id="faDrop2">Drop</button>' + ((pr.changes || []).length ? '<button class="fa-b p" type="button" id="faApr2">✓ Approve the changes</button>' : '') + '</div></div>';
      document.body.appendChild(ov); const close = () => ov.remove(); { const rb = ov.querySelector('#faRep'); if (rb) rb.after(mic(rb)); }   // 🎤 answer / correct by voice, as many times as needed
      ov.onclick = (e) => { if (e.target === ov) close(); }; ov.querySelector('[data-x]').onclick = close;
      ov.querySelector('#faRepGo').onclick = async function () { const t = ov.querySelector('#faRep').value.trim(); if (!t) { toast('Write your answer first', true); return; } this.disabled = true; this.textContent = 'Claude is reading…';
        const r = await call(() => api.post('fu.ap_summary', { text: t, more: 1 })); if (r) { D = r; draw(); popup(); } else { this.disabled = false; this.textContent = 'Send to Claude'; } };
      ov.querySelector('#faDrop2').onclick = async () => { const r = await call(() => api.post('fu.ap_apply', { drop: 1 })); if (r) { D = r; close(); draw(); } };
      const a2 = ov.querySelector('#faApr2'); if (a2) a2.onclick = async () => { a2.disabled = true; const r = await call(() => api.post('fu.ap_apply', {})); if (r) { D = r; close(); draw(); } else a2.disabled = false; };
    }
    load(); return () => load();
  }

  window.FuAuto = { mount };
})();
