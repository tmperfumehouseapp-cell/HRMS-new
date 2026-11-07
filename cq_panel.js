/* Claude asks — the one question panel, shared by People, Boss Engine → Today and Questions.
   Talks to empmanagment/claude_q.php (bd.cq_list / bd.cq_answer). The questions are not a fixed list: Claude asks about whatever is unclear
   (a department with no tasks, two rules that clash, one department holding two kinds of people …) and a question disappears when answered or when the gap closes elsewhere.
   Usage: CQ.mount(element, { max: 3 }) ; CQ.countFor(sectionCode) ; CQ.refresh() ; window event "cq:changed" after an answer. */
(function () {
  'use strict';
  if (window.CQ) return;
  var CQ = window.CQ = { items: [], recent: [], at: '', loaded: false, err: '', hosts: [], draft: {}, depts: null };
  var css = '.cq{--cq-ink:#171a21;--cq-mut:#6b7385;--cq-line:#e4e7ee;--cq-bg:#fff;--cq-acc:#6d5dfc;--cq-go:#0f7a4d;--cq-warn:#b45f06;--cq-bad:#c0392b;margin:0 0 14px;font-family:inherit;color:var(--cq-ink)}'
    + '.cq *{box-sizing:border-box}.cq-head{display:flex;align-items:center;gap:10px;margin:0 0 8px;flex-wrap:wrap}.cq-head h3{margin:0;font-size:15px;font-weight:800;letter-spacing:-.01em}'
    + '.cq-n{background:var(--cq-acc);color:#fff;border-radius:99px;font-size:12px;font-weight:800;padding:2px 9px}.cq-live{margin-left:auto;font-size:12px;color:var(--cq-mut)}.cq-live button{border:0;background:none;color:var(--cq-acc);cursor:pointer;font:inherit;font-weight:700;padding:0 0 0 6px}'
    + '.cq-card{background:var(--cq-bg);border:1px solid var(--cq-line);border-left:4px solid var(--cq-acc);border-radius:16px;padding:14px 16px;margin:0 0 10px;box-shadow:0 1px 2px rgba(20,22,26,.04)}'
    + '.cq-card.k-conflict{border-left-color:var(--cq-bad)}.cq-card.k-structure{border-left-color:var(--cq-warn)}.cq-card.k-notice{border-left-color:var(--cq-go);background:#f6fbf8}.cq-card.k-followup{border-left-color:#17a2b8}.cq-card.k-handover{border-left-color:#d6336c}'
    + '.cq-h{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px;color:var(--cq-mut);margin-bottom:6px}.cq-tag{font-weight:800;text-transform:uppercase;letter-spacing:.04em;font-size:11px;color:var(--cq-acc)}'
    + '.k-conflict .cq-tag{color:var(--cq-bad)}.k-structure .cq-tag{color:var(--cq-warn)}.k-notice .cq-tag{color:var(--cq-go)}.k-handover .cq-tag{color:#d6336c}.cq-step{margin-left:auto;font-weight:700}'
    + '.cq-t{font-size:17px;font-weight:800;line-height:1.3;letter-spacing:-.01em;margin:0 0 4px}.cq-b{font-size:13.5px;line-height:1.5;color:#464d5c;margin:0 0 10px;white-space:pre-line}.cq-ck{border:1px dashed var(--cq-line);border-radius:12px;padding:9px 12px;margin:0 0 10px;font-size:13px;color:#464d5c;background:#fafbfd}.cq-ck b{display:block;font-size:12px;margin-bottom:3px}.cq-ck ul{margin:0;padding-left:18px}.cq-ck li{margin:1px 0}'
    + '.cq-sides{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:0 0 10px}@media(max-width:640px){.cq-sides{grid-template-columns:1fr}}'
    + '.cq-side{border:1px solid var(--cq-line);border-radius:12px;padding:10px 12px;font-size:13px;line-height:1.45;background:#fafbfd}.cq-side small{display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--cq-mut);margin-bottom:3px}'
    + '.cq-side.new{border-color:#bfe6d2;background:#f1faf5}.cq-side.old{border-color:#f1cfca;background:#fdf4f2}.cq-side b{display:block;font-size:14px;margin-bottom:3px}.cq-side span{display:inline-block;margin:2px 10px 0 0;color:#464d5c}'
    + '.cq textarea,.cq input[type=text],.cq input[type=number],.cq select{width:100%;border:1px solid #cfd4de;border-radius:12px;padding:10px 12px;font:inherit;font-size:14px;background:#fff;color:inherit}.cq textarea{min-height:64px;resize:vertical}'
    + '.cq-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.cq-btn{border:1px solid #cfd4de;background:#fff;color:var(--cq-ink);border-radius:99px;padding:9px 16px;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer}'
    + '.cq-btn.go{background:#171a21;border-color:#171a21;color:#fff}.cq-btn.good{background:var(--cq-go);border-color:var(--cq-go);color:#fff}.cq-btn:disabled{opacity:.55;cursor:default}.cq-btn:hover:not(:disabled){filter:brightness(.96)}'
    + '.cq-sub{display:flex;gap:8px;margin-top:10px;align-items:center}.cq-sub>*:first-child{flex:1}.cq-res{font-size:13px;margin-top:8px;color:var(--cq-bad)}'
    + '.cq-flash{background:#e8f6ee;border:1px solid #bfe6d2;color:#0f6b43;border-radius:14px;padding:10px 14px;font-size:13.5px;font-weight:600;margin:0 0 10px}'
    + '.cq-more{border:0;background:none;color:var(--cq-acc);font:inherit;font-weight:700;cursor:pointer;padding:4px 0}.cq-clear{font-size:13px;color:var(--cq-mut);padding:6px 0}'
    + '.cq-ex{font-size:12px;color:var(--cq-mut);margin:-4px 0 8px}'
    + '.cq-stack{flex-direction:column;align-items:stretch}.cq-stack .cq-btn{text-align:left;min-height:52px;border-radius:16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}.cq-btn small{display:block;width:100%;font-weight:500;font-size:12.5px;color:var(--cq-mut)}.cq-btn.go small{color:#c9ced8}.cq-sg{margin-left:auto;font-size:11.5px;font-weight:800;background:#ece9ff;color:#3d33a0;border-radius:99px;padding:3px 9px}.cq-btn.go .cq-sg{background:#6d5dfc;color:#fff}';   // answers with a hint line (Who does the daily check of a place?)
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function inr(n) { return '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN'); }
  function t12(h) { var m = /^(\d\d):(\d\d)/.exec(h || ''); if (!m) return h || ''; var x = Number(m[1]); return ((x + 11) % 12 + 1) + ':' + m[2] + ' ' + (x >= 12 ? 'PM' : 'AM'); }
  var TAG = { gap: 'Claude asks', handover: 'Person left', conflict: 'Conflict', structure: 'Two kinds of work', notice: 'Done automatically', followup: 'Follow-up' };

  function load(fresh) {
    return api.get('bd.cq_list', fresh ? { fresh: 1 } : {}).then(function (r) { CQ.items = r.items || []; CQ.recent = r.recent || []; CQ.at = r.at || ''; CQ.loaded = true; CQ.err = ''; drawAll(); })
      .catch(function (e) { CQ.loaded = true; CQ.err = (e && e.message) || 'error'; drawAll(); });
  }
  CQ.refresh = function () { return load(true); };
  CQ.count = function () { return CQ.items.length; };
  CQ.countFor = function (code, name) { return CQ.items.filter(function (i) { return (code && i.section_code === code) || (name && i.kind === 'notice' && i.subject === name); }).length; };

  function pl0(q) { return q && q.payload ? q.payload : {}; }
  function sideHtml(label, o, kind, cls) {
    if (!o) return '';
    var bits = [];
    if (kind === 'rule') bits.push('<span>Penalty ' + inr(o.pen) + '</span>');
    else { bits.push('<span>Penalty ' + (o.pen > 0 ? inr(o.pen) : 'none') + '</span>'); if (o.time) bits.push('<span>By ' + esc(t12(o.time)) + '</span>'); if (o.target != null) bits.push('<span>Target ' + esc(o.target) + (o.unit ? ' ' + esc(o.unit) : '') + '</span>'); if (o.owner) bits.push('<span>' + esc(o.owner) + '</span>'); if (o.since) bits.push('<span>Added ' + esc(o.since) + '</span>'); }
    return '<div class="cq-side ' + cls + '"><small>' + label + '</small><b>' + esc(o.title) + '</b>' + bits.join('') + '</div>';
  }
  function cardHtml(host, q) {
    var pl = q.payload || {}, key = String(q.id), sub = host.sub[key], h = '';
    h += '<div class="cq-card k-' + esc(q.kind) + '" data-id="' + q.id + '"><div class="cq-h"><span class="cq-tag">' + esc(TAG[q.kind] || 'Claude asks') + '</span>' + (q.subject ? '<span>' + esc(q.subject) + '</span>' : '') + (q.kind === 'gap' && q.seq ? '<span class="cq-step">Question ' + q.seq + (q.total ? ' of ' + q.total : ' of ~4') + ' · I keep asking until this is clear</span>' : '') + '</div>';
    h += '<div class="cq-t">' + esc(q.title) + '</div>' + (q.body ? '<div class="cq-b">' + esc(q.body) + '</div>' : '');
    if (pl.checklist && pl.checklist.length) h += '<div class="cq-ck"><b>' + esc(pl.checklist_title || 'Still to do by hand:') + '</b><ul>' + pl.checklist.map(function (c) { return '<li>' + esc(c) + '</li>'; }).join('') + '</ul></div>';
    if (q.kind === 'conflict' && pl.new && pl.old) h += '<div class="cq-sides">' + sideHtml('Old rule', pl.old, pl.kind, 'old') + sideHtml('New rule', pl.new, pl.kind, 'new') + '</div>';
    var opts = q.options || [];
    if (q.input === 'lines') {
      var dv = CQ.draft[key + ':t'];
      h += (pl.example ? '<div class="cq-ex">For example:<br>' + esc(pl.example).replace(/\n/g, '<br>') + '</div>' : '') + '<textarea data-cq="text" placeholder="One duty per line…">' + esc(dv == null ? '' : dv) + '</textarea><div class="cq-acts"><button class="cq-btn go" data-cq="send">Make these tasks</button></div>';
    } else if (q.input === 'long') {
      h += '<textarea data-cq="text" placeholder="' + esc(pl.placeholder || '') + '">' + esc(CQ.draft[key + ':t'] || '') + '</textarea><div class="cq-acts"><button class="cq-btn go" data-cq="send">Save</button>' + opts.map(function (o) { return '<button class="cq-btn" data-cq="opt" data-o="' + esc(o.id) + '">' + esc(o.label) + '</button>'; }).join('') + '</div>';
    } else if (q.input === 'text') {
      h += '<input type="text" data-cq="text" placeholder="' + esc(pl.placeholder || '') + '" value="' + esc(CQ.draft[key + ':t'] || '') + '"><div class="cq-acts"><button class="cq-btn go" data-cq="send">Add</button>' + opts.map(function (o) { return '<button class="cq-btn" data-cq="opt" data-o="' + esc(o.id) + '">' + esc(o.label) + '</button>'; }).join('') + '</div>';
    } else if (q.input === 'amount') {
      h += '<div class="cq-acts" style="margin-top:0">' + opts.map(function (o) { return '<button class="cq-btn' + (o.id === 'none' ? '' : ' go') + '" data-cq="opt" data-o="' + esc(o.id) + '">' + esc(o.label) + '</button>'; }).join('') + '</div><div class="cq-sub"><input type="number" min="' + (pl.min0 ? 0 : 1) + '" max="' + (pl.max || 500) + '" data-cq="text" placeholder="' + esc(pl.placeholder || 'Other amount (₹)') + '" value="' + esc(CQ.draft[key + ':t'] || '') + '"><button class="cq-btn" data-cq="send">Set</button></div>';
    } else {
      var hinted = opts.some(function (o) { return o.hint; });
      h += '<div class="cq-acts' + (hinted ? ' cq-stack' : '') + '">' + opts.map(function (o, ix) { var on = sub === o.id; return '<button class="cq-btn' + (o.tone === 'go' ? ' good' : ((ix === 0 && q.kind !== 'notice' && opts.length <= 3) || (ix === 0 && q.kind === 'handover') || (hinted && ix === 0 && o.hint) ? ' go' : '')) + (on ? ' go' : '') + '" data-cq="opt" data-o="' + esc(o.id) + '">' + esc(o.label) + (hinted && ix === 0 && o.hint ? '<span class="cq-sg">Suggested</span>' : '') + (o.hint ? '<small>' + esc(o.hint) + '</small>' : '') + '</button>'; }).join('') + '</div>';
      var so = opts.filter(function (o) { return o.id === sub; })[0];
      if (so && so.input === 'text') h += '<div class="cq-sub"><input type="text" data-cq="text" placeholder="' + esc(so.placeholder || '') + '" value="' + esc(CQ.draft[key + ':t'] || '') + '"><button class="cq-btn go" data-cq="send" data-o="' + esc(so.id) + '">Save</button></div>';
      if (so && so.input === 'person') h += '<div class="cq-sub"><select data-cq="text"><option value="">Choose a person…</option>' + (pl.people || CQ.people || []).map(function (d) { return '<option value="' + d.id + '">' + esc(d.name) + '</option>'; }).join('') + '</select><button class="cq-btn go" data-cq="send" data-o="' + esc(so.id) + '">Save</button></div>';
      if (so && so.input === 'dept') h += '<div class="cq-sub"><select data-cq="text">' + (CQ.depts || []).filter(function (d) { return d.name !== q.subject; }).map(function (d) { return '<option value="' + d.id + '">' + esc(d.name) + '</option>'; }).join('') + '</select><button class="cq-btn go" data-cq="send" data-o="' + esc(so.id) + '">Move them</button></div>';
    }
    return h + '<div class="cq-res" data-res></div></div>';
  }
  function draw(host) {
    var el = host.el; if (!el || !document.body.contains(el)) return;
    if (CQ.err) { el.innerHTML = ''; return; }   // not for this person (Admin / HR only) or the server is unreachable
    var all = CQ.items.filter(function (i) { return !host.opts.section || i.section_code === host.opts.section; });
    var lim = host.opts.max || 99, shown = host.expanded ? all : all.slice(0, lim);
    var h = '<div class="cq"><div class="cq-head"><h3>Claude asks</h3>' + (all.length ? '<span class="cq-n">' + all.length + '</span>' : '') + '<span class="cq-live">' + (CQ.loaded ? 'Live · checked ' + esc(CQ.at) : 'Looking…') + '<button data-cq="refresh" title="Ask Claude to look again now">↻</button></span></div>';
    if (host.flash) h += '<div class="cq-flash">✓ ' + esc(host.flash) + '</div>';
    if (!CQ.loaded) h += '<div class="cq-clear">Claude is looking at your departments, tasks and rules…</div>';
    else if (!all.length) h += '<div class="cq-clear">Nothing to ask right now — every department is clear and no rules clash.</div>';
    else h += shown.map(function (q) { return cardHtml(host, q); }).join('') + (all.length > shown.length ? '<button class="cq-more" data-cq="more">Show ' + (all.length - shown.length) + ' more question' + (all.length - shown.length === 1 ? '' : 's') + '</button>' : (host.expanded && all.length > lim ? '<button class="cq-more" data-cq="less">Show fewer</button>' : ''));
    el.innerHTML = h + '</div>';
  }
  function drawAll() { CQ.hosts = CQ.hosts.filter(function (h) { return document.body.contains(h.el); }); CQ.hosts.forEach(function (h) { if (!typing(h)) draw(h); }); }
  function typing(h) { var a = document.activeElement; return !!(a && h.el && h.el.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)); }

  function answer(host, card, choice, text) {
    var id = /^\d+$/.test(card.dataset.id) ? Number(card.dataset.id) : card.dataset.id, res = card.querySelector('[data-res]'), btns = card.querySelectorAll('button'); btns.forEach(function (b) { b.disabled = true; }); if (res) res.textContent = '';
    api.post('bd.cq_answer', { id: id, choice: choice || '', text: text || '' }).then(function (r) {
      CQ.items = r.items || []; delete CQ.draft[id + ':t']; delete host.sub[String(id)]; CQ.at = new Date().toTimeString().slice(0, 5);
      CQ.hosts.forEach(function (h) { h.flash = h === host ? r.message : ''; draw(h); }); clearTimeout(host._f); host._f = setTimeout(function () { host.flash = ''; draw(host); }, 9000);
      try { window.dispatchEvent(new CustomEvent('cq:changed', { detail: { message: r.message } })); } catch (e) { /* old browser */ }
    }).catch(function (e) { btns.forEach(function (b) { b.disabled = false; }); if (res) res.textContent = (e && e.message) || 'Could not save.'; });
  }
  function onClick(host, e) {
    var b = e.target.closest('[data-cq]'); if (!b || !host.el.contains(b)) return; var a = b.dataset.cq, card = b.closest('.cq-card');
    if (a === 'refresh') { b.disabled = true; load(true); return; }
    if (a === 'more') { host.expanded = true; draw(host); return; }
    if (a === 'less') { host.expanded = false; draw(host); return; }
    if (!card) return; var q = CQ.items.filter(function (i) { return String(i.id) === card.dataset.id; })[0]; if (!q) return;
    if (a === 'opt') {
      var o = (q.options || []).filter(function (x) { return x.id === b.dataset.o; })[0] || {};
      if (o.input) {
        host.sub[String(q.id)] = o.id;
        if (o.input === 'person' && !(pl0(q).people) && !CQ.people && pl0(q).zone_id) { api.get('of.pl_get', { zone_id: pl0(q).zone_id }).then(function (r) { CQ.people = (r.employees || []).map(function (e) { return { id: e.id, name: e.name }; }); draw(host); }).catch(function () { CQ.people = []; draw(host); }); }   // a place question: the list of people comes from the place itself
        if (o.input === 'dept' && !CQ.depts) { api.get('bd.pp_boot').then(function (r) { CQ.depts = r.departments || []; draw(host); }).catch(function () { CQ.depts = []; draw(host); }); } else draw(host);
        return;
      }
      if (q.input === 'amount' || q.input === 'text' || q.input === 'choice') return answer(host, card, o.id, '');
    }
    if (a === 'send') {
      var inp = card.querySelector('[data-cq=text]'), v = inp ? inp.value.trim() : '';
      if (!v) { var rs = card.querySelector('[data-res]'); if (rs) rs.textContent = 'Type your answer first.'; return; }
      if (q.input === 'amount') return answer(host, card, v, v);
      return answer(host, card, b.dataset.o || '', v);
    }
  }
  CQ.mount = function (el, opts) {
    if (!el) return; opts = opts || {};
    var host = CQ.hosts.filter(function (h) { return h.el === el; })[0];
    if (!host) {
      var prev = CQ.hosts.filter(function (h) { return !document.body.contains(h.el); });   // the page re-drew itself: keep what was typed / opened
      host = { el: el, opts: opts, sub: (prev[0] && prev[0].sub) || {}, expanded: !!(prev[0] && prev[0].expanded), flash: (prev[0] && prev[0].flash) || '' };
      CQ.hosts = CQ.hosts.filter(function (h) { return document.body.contains(h.el); }); CQ.hosts.push(host);
      el.addEventListener('click', function (e) { onClick(host, e); });
      el.addEventListener('input', function (e) { var t = e.target, c = t.closest && t.closest('.cq-card'); if (c && t.dataset.cq === 'text' && t.tagName !== 'SELECT') CQ.draft[c.dataset.id + ':t'] = t.value; });
      el.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { var s = e.target.closest('.cq-card').querySelector('[data-cq=send]'); if (s) s.click(); } });
    } else host.opts = opts;
    draw(host);
    if (!CQ.loaded && !CQ._loading) { CQ._loading = true; load(false).then(function () { CQ._loading = false; }); }
  };
  setInterval(function () { if (document.visibilityState === 'visible' && CQ.hosts.some(function (h) { return document.body.contains(h.el); })) load(false); }, 60000);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && CQ.loaded) load(false); });
})();
