/* wa_addon_pop.js — the compact add-on pop-up with an EDIT option (owner 22 Nov: "the pop-up stays compulsory, but I must be able to edit the message:
 * change the perfume names and the prices"). waAddonPop({ options, p1, p2, ml, tot, onSend(payload), onClose }) builds it.
 * The salesperson picks one of the ready messages (3 styles), sees it, may press ✏️ Edit (free text + the two prices; the totals and per-bottle prices in the text follow the prices),
 * and presses Send. The pop-up is never skipped (no cancel); payload = { opt, text, p1, p2, edited }. */
(function () {
  'use strict';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  window.waAddonPop = function (o) {
    var old = document.getElementById('wxAdM'); if (old) old.remove();
    var opts = o.options || [], P1 = +o.p1 || 0, P2 = +o.p2 || 0, TOT = +o.tot || 0, ML = o.ml || '';
    var cur = 0, editing = false, edited = false, p1 = P1, p2 = P2, text = (opts[0] || {}).text || '';
    var d = document.createElement('div'); d.id = 'wxAdM';
    d.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);display:flex;align-items:flex-end;justify-content:center;padding:0';
    var box = document.createElement('div');
    box.style.cssText = 'background:#fff;color:#111827;border-radius:16px 16px 0 0;max-width:420px;width:100%;padding:12px 12px 14px;box-shadow:0 -6px 30px rgba(0,0,0,.3);font:inherit;max-height:92vh;overflow:auto';
    d.appendChild(box);
    var pb = function (p, n) { return Math.round(p / n); };
    // when a price changes, the same number inside the text (the add-on price, the new total, the per-bottle price) is changed with it
    function repl(t, from, to) { if (!from || from === to) return t; return t.replace(new RegExp('(^|[^0-9,])' + String(from).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![0-9])', 'g'), function (m, a) { return a + to; }); }
    function applyPrice(which, nv) {
      nv = Math.max(0, Math.round(+nv || 0)); var n = which === 1 ? 2 : 3, op = which === 1 ? p1 : p2; if (nv === op) return;
      text = repl(text, TOT + op, TOT + nv); text = repl(text, op, nv); text = repl(text, pb(op, n), pb(nv, n));   // total first (it contains the price as a part)
      if (which === 1) p1 = nv; else p2 = nv; edited = true;
    }
    function render() {
      var chips = opts.map(function (x, i) { return '<button type="button" data-ch="' + i + '" style="flex:1;min-width:0;border:1px solid ' + (i === cur ? '#128c4a' : '#d6dbe3') + ';background:' + (i === cur ? '#e8f6ee' : '#fff') + ';color:' + (i === cur ? '#0b6b38' : '#475569') + ';border-radius:99px;padding:6px 4px;font:inherit;font-weight:700;font-size:12px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(x.short || x.label) + '</button>'; }).join('');
      var body = editing
        ? '<textarea id="adT" rows="6" style="width:100%;box-sizing:border-box;border:1.5px solid #128c4a;border-radius:10px;padding:8px;font:inherit;font-size:14px;line-height:1.4;resize:vertical">' + esc(text) + '</textarea>'
          + '<div style="display:flex;gap:8px;margin-top:6px;align-items:center;font-size:12.5px;color:#334155"><label style="flex:1">2 more ' + esc(ML) + ' ml ₹<input id="adP1" type="number" inputmode="numeric" value="' + p1 + '" style="width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:6px;font:inherit;font-weight:700"></label><label style="flex:1">3 more ₹<input id="adP2" type="number" inputmode="numeric" value="' + p2 + '" style="width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:6px;font:inherit;font-weight:700"></label></div>'
          + '<div style="font-size:11.5px;color:#64748b;margin-top:4px">Change the perfume names or words in the box. When you change a price, the totals and the per-bottle price in the message change with it. Lowest allowed: ₹' + Math.round(P1 * 0.9) + ' / ₹' + Math.round(P2 * 0.9) + '. The message must show both prices.</div>'
        : '<div style="background:#f1f5f9;border-radius:10px;padding:9px 10px;font-size:14px;line-height:1.45;white-space:pre-line">' + esc(text) + '</div>'
          + ((opts[cur] || {}).btn ? '<div style="display:flex;gap:6px;margin-top:6px;font-size:12px;font-weight:700"><span style="flex:1;text-align:center;background:#e2e8f0;border-radius:8px;padding:5px">➕➕ 2 add ₹' + p1 + '</span><span style="flex:1;text-align:center;background:#e2e8f0;border-radius:8px;padding:5px">➕➕➕ 3 add ₹' + p2 + '</span><span style="flex:.7;text-align:center;background:#e2e8f0;border-radius:8px;padding:5px">❌ Nahi</span></div>' : '');
      box.innerHTML = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><b style="font-size:16px;flex:1">➕ Add-on offer</b><span style="font-size:11px;font-weight:800;background:#fee2e2;color:#b91c1c;border-radius:99px;padding:2px 8px">compulsory</span></div>'
        + '<div style="font-size:12px;color:#64748b;margin-bottom:6px">Send one message before the payment link. You may edit it.</div>'
        + '<div style="display:flex;gap:6px;margin-bottom:8px">' + chips + '</div>' + body
        + '<div style="display:flex;gap:8px;margin-top:10px"><button type="button" id="adE" style="flex:0 0 auto;border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:12px;padding:11px 14px;font:inherit;font-weight:800;font-size:14px;cursor:pointer">' + (editing ? '👁 Preview' : '✏️ Edit') + '</button><button type="button" id="adS" style="flex:1;border:0;background:#128c4a;color:#fff;border-radius:12px;padding:11px;font:inherit;font-weight:800;font-size:15px;cursor:pointer">✉️ Send to customer' + (edited ? ' (edited)' : '') + '</button></div>';
      box.querySelectorAll('[data-ch]').forEach(function (b) { b.onclick = function () { cur = +b.dataset.ch; text = opts[cur].text; p1 = P1; p2 = P2; edited = false; render(); }; });
      var tx = box.querySelector('#adT'); if (tx) tx.oninput = function () { if (tx.value !== text) edited = true; text = tx.value; var s = box.querySelector('#adS'); if (s) s.textContent = '✉️ Send to customer (edited)'; };
      ['adP1', 'adP2'].forEach(function (id, k) { var el = box.querySelector('#' + id); if (el) el.onchange = function () { var lo = Math.round((k ? P2 : P1) * 0.9); var v = Math.max(lo, Math.round(+el.value || 0)); applyPrice(k + 1, v); render(); }; });
      box.querySelector('#adE').onclick = function () { editing = !editing; render(); };
      box.querySelector('#adS').onclick = function () { var t = (box.querySelector('#adT') ? box.querySelector('#adT').value : text).trim(); text = t; d.remove(); if (o.onSend) o.onSend({ opt: cur, text: t, p1: p1, p2: p2, edited: edited || t !== (opts[cur] || {}).text || p1 !== P1 || p2 !== P2 }); };
    }
    render(); document.body.appendChild(d);
    return d;
  };
})();
