/* Add-ons shown on an order, the same everywhere in HRMS. An order row from the server carries:
 *   o.addons (groups: items, price, pre, cod), o.addon_total, o.addon_text
 *  AddonView.block(o)     the purple "Add-on" box: products + amount (+ prepaid / COD)   ('' when the order has none)
 *  AddonView.tag(o)       a small "+ Add-on ₹1,450" tag for compact lists
 *  AddonView.total(o)     number: order amount + add-on
 *  AddonView.cols(o)      [products text, add-on amount, order total with add-on] for Excel / CSV
 */
(function () {
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function inr(v) { return '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 }); }
  function itemTxt(i) { var sz = i.size_ml ? ' ' + i.size_ml + (/ml/i.test(i.size_ml) ? '' : ' ML') : ''; return i.product_name + sz + (Number(i.qty) > 1 ? ' ×' + Number(i.qty) : ''); }
  function has(o) { return !!(o && o.addons && o.addons.length); }
  window.AddonView = {
    has: has,
    block: function (o) {
      if (!has(o)) return '';
      return o.addons.map(function (a) {
        return '<div class="addon-view" style="background:#f0e8ff;border:1px solid #c9b0ff;border-radius:8px;padding:6px 9px;margin-top:5px;font-size:12.5px;line-height:1.45;color:#2a1f45;white-space:normal;">' +
          '<b>➕ Add-on:</b> ' + esc(a.items.map(itemTxt).join(', ')) + '<br><b>' + inr(a.price) + '</b>' +
          (a.pre > 0 ? ' · Prepaid ' + inr(a.pre) : '') + (a.cod > 0 ? ' · <b>Collect ' + inr(a.cod) + ' on delivery</b>' : '') + '</div>';
      }).join('');
    },
    tag: function (o) {
      return has(o) ? '<span class="addon-tag" title="' + esc(o.addon_text) + '" style="display:inline-block;background:#f0e8ff;color:#5b2bbd;border:1px solid #c9b0ff;border-radius:99px;padding:0 7px;font-size:11px;font-weight:700;margin-left:4px;white-space:nowrap;">+ Add-on ' + inr(o.addon_total) + '</span>' : '';
    },
    total: function (o) { return Number(o.total || 0) + Number((o && o.addon_total) || 0); },
    cols: function (o) {
      if (!has(o)) return ['', '', ''];
      return [o.addons.map(function (a) { return a.items.map(itemTxt).join(', '); }).join(' | '), Math.round(Number(o.addon_total) * 100) / 100, Math.round((Number(o.total || 0) + Number(o.addon_total)) * 100) / 100];
    }
  };
})();
