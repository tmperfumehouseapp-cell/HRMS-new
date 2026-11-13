/* fp_export.js — the "FP" export sheet of the manual orders (same columns and cell types as Prem_Final_Export: Date, Order No, Order Type, AWB NO, … Transaction Id).
 *   FPX.COLS            the 32 column titles (the first one is exactly "<!--  -->Date", as in the sample file)
 *   FPX.rows(orders)    one row per order (array of arrays: Date and Time are real Excel date / time values)
 *   FPX.book(XLSX, orders) a workbook with the sheet "FP" and an empty sheet "Exchange " (same titles)
 * Products = the real perfume names the salesperson chose ("Name | 50 ml"), one per piece; Buying Price = our purchase cost of the order (cost per size typed on the
 * Manual orders page, + chain / other product cost) — empty while a size has no cost.
 * Columns we cannot work out (Broadcast, Tax, Overall Quantity, JV Code, FL Code) are left empty. */
(function (root) {
  'use strict';
  const COLS = ['<!--  -->Date', 'Order No', 'Order Type', 'AWB NO', 'Num of Products', 'Products', 'Broadcast', 'Name', 'Customer Number', 'Alternative Number', 'Weight', 'Address', 'City', 'State', 'Pincode', 'Email', 'Mode', 'Prepaid', 'COD', 'Buying Price', 'Overall Amount', 'Tax', 'Tax Type', 'Overall Quantity', 'JV Code', 'FL Code', 'Group Name', 'Group Leader Name', 'Salesman Name', 'Status', 'Time', 'Transaction Id'];
  const num = (v) => { const n = Number(String(v == null ? '' : v).replace(/\D+/g, '')); return n > 0 ? n : ''; };
  const parse = (s) => { const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(s || '')); return m ? { y: +m[1], mo: +m[2], d: +m[3], h: +m[4], mi: +m[5] } : null; };
  // one entry per piece: the REAL perfume the salesperson chose ("Dior Sauvage | 50 ml"), the chain, or the other product — and, in the same order, what the customer pays for that piece
  function products(r) {
    const out = [];
    (r.lines || []).forEach((l) => { const q = Math.max(1, +l.qty || 1), nm = String(l.short || l.title || '').trim(); for (let i = 0; i < q; i++) { if (l.chain) out.push('Chain' + (nm && !/^chain$/i.test(nm) ? ' | ' + nm : '')); else if (l.single) out.push((nm || 'Product') + (l.label ? ' | ' + l.label : '')); else { const m = /(\d+(?:\.\d+)?)\s*ml/i.exec(l.label || ''); out.push((nm || 'Perfume') + ' | ' + (m ? m[1] + ' ml' : (l.label || '')).trim()); } } });
    return out;
  }
  // Buying Price = OUR purchase cost of the whole order: each piece at the cost typed for its size (perfume), or the chain / other-product cost.
  // Empty when a piece has no cost typed yet (better empty than a wrong total).
  function cost(r, C) {
    if (!C) return ''; const sz = C.sizes || {}; let t = 0;
    for (const l of (r.lines || [])) {
      const q = Math.max(1, +l.qty || 1); let c = null;
      if (l.chain) c = C.chain; else if (l.single) c = C.other; else { const m = /(\d+(?:\.\d+)?)\s*ml/i.exec(l.label || ''); c = m ? sz[String(Number(m[1]))] : null; }
      if (c == null || c === '' || !isFinite(Number(c))) return ''; t += Number(c) * q;
    }
    return Math.round(t * 100) / 100;
  }
  function row(r) {
    const c = r.cust || {}, p = products(r), dt = parse(r.created_at), pcs = (r.lines || []).reduce((a, l) => a + (+l.qty || 1), 0), kg = Math.max(0.5, Math.round(0.12 * pcs * 100) / 100);
    const addr = [c.addr1, c.addr2].filter(Boolean).join(', ') + (c.landmark ? '  Near By Landmark:-' + c.landmark : '');
    const st = String(c.state || '').trim();
    return [
      dt ? new Date(Date.UTC(dt.y, dt.mo - 1, dt.d)) : '', r.order_no, 'new', r.parcel && r.parcel.awb ? r.parcel.awb : '', p.length, p.join(' :: '), '', c.name || '', num(c.phone || r.phone), c.phone2 ? num(c.phone2) : '.', kg.toFixed(2) + ' KG', addr.trim(), c.city || '', st, num(c.pin), c.email || '.',
      'FP Credit card', Number(r.pay === 'cod' ? 0 : r.paid) || 0, Number(r.cod) || 0, cost(r, row.costs), Number(r.total) || 0, '', /^gujarat$/i.test(st) ? 'Intrastate' : 'Interstate', '', '', '', 'N/A', 'N/A', r.emp || 'N/A',
      r.status === 'confirmed' ? 'Confirmed' : r.status === 'cancelled' ? 'Cancelled' : 'Waiting for address', dt ? (dt.h * 60 + dt.mi) / 1440 : '', String(r.utr || '00000')
    ];
  }
  function book(XLSX, orders, costs) {
    row.costs = costs || null; const wb = XLSX.utils.book_new(), aoa = [COLS].concat(orders.map(row)), ws = XLSX.utils.aoa_to_sheet(aoa, { cellDates: true });
    for (let i = 1; i < aoa.length; i++) {
      const a = ws['A' + (i + 1)]; if (a && a.v instanceof Date) { a.t = 'd'; a.z = 'mm-dd-yy'; }
      const t = ws['AE' + (i + 1)]; if (t && typeof t.v === 'number') t.z = 'h:mm';
      const tr = ws['AF' + (i + 1)]; if (tr) { tr.t = 's'; tr.z = '@'; }
    }
    ws['!cols'] = COLS.map((c, i) => ({ wch: [5, 1, 11].includes(i) ? 12 : i === 5 ? 46 : i === 11 ? 64 : i === 0 ? 11 : 14 }));
    XLSX.utils.book_append_sheet(wb, ws, 'FP'); XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([COLS]), 'Exchange ');
    return wb;
  }
  root.FPX = { COLS, rows: (o, costs) => { row.costs = costs || null; return o.map(row); }, book };
  if (typeof module !== 'undefined') module.exports = root.FPX;
})(typeof window !== 'undefined' ? window : globalThis);
