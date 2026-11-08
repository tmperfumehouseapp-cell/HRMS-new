/**
 * wa_inbox.js — the WhatsApp chat screen shared by two pages:
 *   wa_chats.html  (Admin → Sales → WhatsApp → Chats)  : WaInbox.start({ mode: 'admin' })  — every chat, assign people
 *   my_chats.html  (salesperson → My Chats)             : WaInbox.start({ mode: 'mine' })   — only the chats given to me
 * Needs api.js (api.get / api.post). Everything the screen shows comes from the wa.* actions in wa.php.
 * Also in this file: ⚡ pre-made messages (with "how I understood this chat"), 📚 the media library, the private 💡 suggestion bar (only staff see it, never sent by itself)
 * and the "call this customer now" pop-up.
 */
(function () {
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // turn web addresses in an (already escaped) message into tappable links that open in a new tab
  const linkify = (h) => String(h).replace(/\b(?:https?:\/\/|www\.)[^\s<]+/gi, (u) => { let t = ''; const m = /(?:[.,!?;:)\]]|&quot;|&#39;)+$/.exec(u); if (m) { t = m[0]; u = u.slice(0, -t.length); } const href = /^www\./i.test(u) ? 'https://' + u : u; return `<a href="${href}" target="_blank" rel="noopener noreferrer" style="color:#027eb5;text-decoration:underline;word-break:break-all">${u}</a>` + t; });
  const $ = (id) => document.getElementById(id);
  // What goes in each {{n}} of a pre-made message: Meta's label / example if the message has one, else a guess from the words around it ("your order {{2}}" → order number).
  // Used to label the boxes, prefill what HRMS knows, and stop a wrong value (a sentence in an order-number box) before it reaches the customer.
  function tplVarHint(t, i) {
    const lab = String((t.var_labels || [])[i - 1] || '').toLowerCase(), ex = String((t.var_examples || [])[i - 1] || '');
    const body = String(t.body || ''), at = body.indexOf('{{' + i + '}}'), before = at > 0 ? body.slice(Math.max(0, at - 40), at).toLowerCase() : '', after = at >= 0 ? body.slice(at + 5, at + 30).toLowerCase() : '';
    const ctx = lab + ' ' + before.split(/\s+/).slice(-3).join(' ') + ' | ' + after.split(/\s+/).slice(0, 2).join(' ');
    let k = '';
    if (/name|naam|customer/.test(lab)) k = 'name';
    else if (/track|awb|courier|docket/.test(ctx)) k = 'track';
    else if (/order|ऑर्डर|#|invoice|bill/.test(ctx)) k = 'order';
    else if (/amount|total|price|rs\b|rs\.|₹|inr|pay/.test(ctx)) k = 'amount';
    else if (/date|on\b|tarikh|day/.test(lab + ' ' + before.slice(-12))) k = 'date';
    else if (/perfume|product|item|fragrance|attar/.test(ctx)) k = 'perfume';
    else if (/link|http|url/.test(ctx)) k = 'link';
    else if (i === 1 && !lab) k = 'name';   // most messages start "Hi {{1}}"
    const T = { name: ['customer’s name', 'Rakesh'], track: ['tracking number', 'TRK123456789'], order: ['order number', '#TM1234'], amount: ['amount', '₹1,499'], date: ['date', new Date().toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })], perfume: ['perfume name', 'Dior Sauvage 50 ml'], link: ['link', 'https://…'] };
    const lb = lab ? lab.replace(/_/g, ' ') : (T[k] ? T[k][0] : ''), eg = ex || (T[k] ? T[k][1] : '');
    return { k, label: lb, eg };
  }
  function tplVarBad(h, v) {   // a value that cannot be right for this box
    if (!v) return 'Fill every value first.';
    if ((h.k === 'order' || h.k === 'track' || h.k === 'amount') && !/\d/.test(v)) return 'The ' + h.label + ' box needs a number' + (h.eg ? ' (like ' + h.eg + ')' : '') + ' — not “' + v.slice(0, 30) + '”.';
    if ((h.k === 'order' || h.k === 'track') && v.split(/\s+/).length > 3) return 'Put only the ' + h.label + ' in that box' + (h.eg ? ' (like ' + h.eg + ')' : '') + '.';
    if (h.k === 'name' && v.split(/\s+/).length > 3) return 'Put only the customer’s name in the name box.';
    if (h.k === 'link' && !/^https?:\/\//i.test(v)) return 'The link box needs a web link starting with https://';
    return '';
  }
  const TAG_CLS = { price: 'b', buy: 'p', order_status: 'n', complaint: 'r', other: 'n' };
  const SRC = { query: ['New query', 'o'], campaign: ['Campaign', 'b'], community: ['Community', 'g'] };

  function parse(s) { const m = /^(\d{4})-(\d\d)-(\d\d)[ T](\d\d):(\d\d)(?::(\d\d))?/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) : null; }
  function hhmm(d) { return d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }); }
  /** WhatsApp style stamp for the chat list: the clock time of the LAST message (ours or the customer's) today ("1:58 pm"), "Yesterday", else the date. */
  function stamp(s) {
    const d = parse(s); if (!d) return ''; const n = new Date(), d0 = new Date(n.getFullYear(), n.getMonth(), n.getDate()), days = Math.round((d0 - new Date(d.getFullYear(), d.getMonth(), d.getDate())) / 86400000);
    if (days <= 0) return d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }).replace(/\s?(am|pm)$/i, (m) => ' ' + m.trim().toLowerCase()); if (days === 1) return 'Yesterday';
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  }
  function ago(s) {
    const d = parse(s); if (!d) return ''; const m = Math.floor((Date.now() - d.getTime()) / 60000);
    if (m < 1) return 'now'; if (m < 60) return m + ' min'; if (m < 1440) return Math.floor(m / 60) + ' h';
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  }
  function dayLabel(d) {
    const t = new Date(); t.setHours(0, 0, 0, 0); const x = new Date(d); x.setHours(0, 0, 0, 0); const diff = Math.round((t - x) / 86400000);
    return diff === 0 ? 'Today' : diff === 1 ? 'Yesterday' : d.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
  }
  function pretty(phone) { const p = String(phone || ''); return p.length === 10 ? '+91 ' + p.slice(0, 5) + ' ' + p.slice(5) : p; }
  function title(c) { return c.name && c.name.trim() ? c.name : pretty(c.phone); }
  /* round letter avatar, like WhatsApp: first letters of the name, one of 6 soft colours picked from the number */
  function av(c) {
    const t = (c.name && c.name.trim()) || '', w = t.split(/\s+/).filter(Boolean), ini = w.length ? (w[0][0] + (w[1] ? w[1][0] : '')).toUpperCase() : '#';
    const k = (parseInt(String(c.phone || '0').slice(-3), 10) || 0) % 6;
    return '<span class="wi-av c' + k + '" aria-hidden="true">' + esc(ini) + '</span>';
  }

  const CSS = `
  .wi{font-family:Manrope,var(--font,system-ui); -webkit-font-smoothing:antialiased; color:#1c2430; font-size:13.5px;}
  .wi *{box-sizing:border-box;} .wi button,.wi input,.wi textarea,.wi select{font:inherit;} .wi [hidden]{display:none !important;}
  .wi-kpi{display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap;}
  .wi-kpi div{flex:1; min-width:104px; background:#fff; border:1px solid var(--line,#e4e7ec); border-radius:12px; padding:8px 12px;}
  .wi-kpi small{display:block; font-size:11px; font-weight:700; color:var(--muted,#6b7684); text-transform:uppercase; letter-spacing:.3px;} .wi-kpi b{font-size:19px; font-weight:800;}
  .wi-kpi .hot b{color:#c22;}
  .wi-box{display:grid; grid-template-columns:330px minmax(0,1fr) 310px; height:calc(100vh - 190px); min-height:520px; background:#fff; border:1px solid var(--line,#e4e7ec); border-radius:16px; overflow:hidden;}
  .wi.mine .wi-box{grid-template-columns:330px minmax(0,1fr) 290px;}
  .wi-col{display:flex; flex-direction:column; min-height:0; min-width:0; border-right:1px solid #eef0f3;} .wi-col:last-child{border-right:0;}
  .wi-tabs{display:flex; gap:6px; padding:10px 10px 6px; flex-wrap:wrap;}
  .wi-tab{border:0; background:#f3f5f8; color:#3a3f4b; border-radius:8px; padding:6px 10px; font-size:12px; font-weight:700; cursor:pointer;} .wi-tab.on{background:#1c2430; color:#fff;}
  .wi-tab i{font-style:normal; opacity:.7; margin-left:3px;}
  .wi-feeds{padding:0 10px 4px; gap:5px;} .wi-chip{border:1px solid #e4e7ec; background:#fff; color:#4a5565; border-radius:99px; padding:4px 10px; font-size:11.5px; font-weight:700; cursor:pointer;} .wi-chip.on{background:#f58220; border-color:#f58220; color:#fff;} .wi-chip i{font-style:normal; opacity:.75; margin-left:3px;}
  .wi-find{display:flex; gap:6px; padding:2px 10px 8px;} .wi-find{position:relative;} .wi-find>input{flex:1.5 1 0 !important;} .wi-ep{position:static; flex:1 1 0; min-width:0; display:flex;} .wi-ep.wi-flw{flex:0 0 auto;} .wi-b{position:relative;} .wi-rb{position:absolute; top:4px; right:-30px; width:26px; height:26px; border-radius:50%; border:0; background:rgba(0,0,0,.08); color:#54656f; font-size:14px; cursor:pointer; opacity:0; transition:opacity .15s; padding:0; min-height:0;} .wi-b.out .wi-rb{right:auto; left:-30px;} .wi-b:hover .wi-rb{opacity:1;} .wi-rpb{display:flex; gap:8px; align-items:center; padding:6px 10px; background:#f0f2f5; border-top:1px solid #e9edef;} .wi-rpb>button{border:0; background:none; font-size:18px; color:#54656f; cursor:pointer; padding:4px 8px;} .wi-b .wi-qt{display:block !important; border-left:4px solid #06cf9c !important; background:rgba(0,0,0,.06) !important; border-radius:8px !important; padding:6px 9px !important; margin:0 0 6px !important; cursor:pointer; font-size:12.5px; line-height:1.35; max-width:100%;} .wi-qt b{display:block; color:#059669; font-size:12px;} .wi-qt span{display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; color:#475569;} .wi-qt:hover{background:rgba(0,0,0,.08);} @keyframes wiFl{0%,60%{box-shadow:0 0 0 3px #fbbf24; background-color:#fef3c7}100%{box-shadow:none}} .wi-fl{animation:wiFl 1.6s ease;}  .wi-ep>button{flex:1; min-width:0; text-align:left; border:1px solid var(--line,#e4e7ec); border-radius:10px; padding:7px 10px; background:#fff; font:inherit; cursor:pointer; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:inherit;} .wi-tgb{display:flex; gap:6px; padding:6px 10px 8px; flex-wrap:wrap;} .wi-tgb button{flex:1 1 auto; border:1px solid var(--line,#e4e7ec); background:#fff; border-radius:99px; padding:6px 12px; font:inherit; font-size:12.5px; cursor:pointer; white-space:nowrap;} .wi-tgb button.on{background:#fef3c7; border-color:#f59e0b; color:#92400e; font-weight:700;} .wi-flw{flex:0 0 auto;} .wi-flw>button.on{background:#ecfdf3; border-color:#22c55e; color:#15803d; font-weight:700;} .wi-flp{width:auto;} .wi-flh{display:block; font-size:11px; letter-spacing:.05em; text-transform:uppercase; color:#667781; margin:6px 4px 2px;} .wi-flo{display:flex !important; justify-content:flex-start !important; text-align:left !important; width:100%; box-sizing:border-box; align-items:center; gap:8px; padding:7px 6px; border-radius:8px; cursor:pointer; font-size:14px; font-weight:400; margin:0;} .wi-flo:hover{background:#f3f4f6;} .wi-flo input{width:18px !important; height:18px !important; flex:0 0 18px !important; margin:0 !important; min-height:0; padding:0 !important;} .wi-fla{display:flex; gap:8px; justify-content:flex-end; margin-top:8px; border-top:1px solid var(--line,#e4e7ec); padding-top:8px;} .wi-fla button{border:1px solid var(--line,#e4e7ec); background:#fff; border-radius:10px; padding:7px 14px; cursor:pointer; font:inherit;} .wi-fla button.pri{background:#00a884; border-color:#00a884; color:#fff; font-weight:700;} .wi-epp{position:absolute; left:10px; right:10px; top:calc(100% - 2px); z-index:60; width:auto; background:#fff; border:1px solid var(--line,#e4e7ec); border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.15); padding:8px;} .wi-epp input{width:100%; box-sizing:border-box; border:1px solid var(--line,#e4e7ec); border-radius:10px; padding:8px 10px; margin:0 0 6px;} #wiEl{max-height:320px; overflow:auto; display:flex; flex-direction:column;} #wiEl button{all:unset; display:block; padding:9px 10px; border-radius:8px; cursor:pointer; font-size:14px;} #wiEl button:hover,#wiEl button.on{background:#eef2ff;} #wiEl small{padding:8px 10px; color:#667781;} .wi-find input,.wi-find select{flex:1; min-width:0; border:1px solid var(--line,#e4e7ec); border-radius:10px; padding:7px 10px; background:#fff; min-height:0; margin:0;}
  .wi-list{flex:1; overflow-y:auto;}
  .wi-it{padding:10px 14px; border-bottom:1px solid #f1f3f6; cursor:pointer; border-left:3px solid transparent;} .wi-it:hover{background:#fafbfc;} .wi-it.sel{background:#fff7ef; border-left-color:#f58220;}
  .wi-it .n{display:flex; justify-content:space-between; gap:8px; font-weight:700;} .wi-it .n span:first-child{white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .wi-it .m{color:#6b7684; font-size:12.5px; margin:3px 0 5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .wi-it .t{font-size:11.5px; font-weight:600; color:#8a94a3; flex:none;} .wi-it.wait .t{color:#c22;}
  .wi-p{display:inline-block; padding:2px 9px; border-radius:99px; font-size:11px; font-weight:700; margin:0 4px 3px 0; background:#f1f2f5; color:#4a5260;}
  .wi-p.o{background:#fff1e3; color:#c2570c;} .wi-p.g{background:#e3f6ec; color:#12794a;} .wi-p.b{background:#e5eefc; color:#1f5fc4;} .wi-p.r{background:#fde6e6; color:#c22;} .wi-p.p{background:#efe7fb; color:#6b3fc0;} .wi-p.y{background:#fff6d6; color:#8a6a00;}
  .wi-empty{padding:30px 18px; text-align:center; color:#8a94a3; font-size:13px; line-height:1.6;}
  .wi-av{width:42px; height:42px; border-radius:50%; display:inline-grid; place-items:center; color:#fff; font-weight:700; font-size:14px; flex:none; background:#6b7c93;} .wi-av.c0{background:#2f9e8f;} .wi-av.c1{background:#5b7bd5;} .wi-av.c2{background:#c2569b;} .wi-av.c3{background:#e08a2d;} .wi-av.c4{background:#7c5cc4;} .wi-av.c5{background:#4a9ad4;}
  .wi-it{display:flex; gap:11px; align-items:center;} .wi-bd{flex:1; min-width:0;} .wi-it .m2{display:flex; align-items:center; gap:8px;} .wi-it .m2 .m{flex:1; min-width:0; margin:1px 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;} .wi-head .wi-av{width:36px; height:36px; font-size:12.5px;}
  .wi-chat{background:#f7f4ee;} .wi-head{display:flex; align-items:center; gap:8px; padding:10px 14px; background:#fff; border-bottom:1px solid #eef0f3; flex-wrap:wrap;} .wi-head b{font-size:15px;} .wi-head .sp{flex:1;}
  .wi-back,.wi-more{display:none; border:1px solid var(--line,#e4e7ec); background:#fff; border-radius:999px; padding:6px 12px; font-weight:700; cursor:pointer;}
  .wi-msgs{flex:1; overflow-y:auto; padding:14px 16px; display:flex; flex-direction:column; gap:7px;}
  .wi-day{align-self:center; background:#e9e5dc; color:#6b6558; font-size:11px; font-weight:700; border-radius:99px; padding:3px 12px; margin:6px 0;}
  .wi-b{max-width:72%; padding:7px 11px 5px; border-radius:11px; background:#fff; box-shadow:0 1px 2px #0001; line-height:1.4; word-break:break-word; white-space:pre-wrap; align-self:flex-start;}
  .wi-b.out{align-self:flex-end; background:#dcf8c6;} .wi-b.bot{align-self:flex-end; background:#e8e4ff;}
  .wi-b small{display:block; color:#7a8594; font-size:11px; margin-top:3px; white-space:normal; text-align:right;}
  .wi-b img{max-width:100%; max-height:300px; border-radius:8px; display:block; cursor:zoom-in;}
  .wi-b .ld{border:1px dashed #b9c0cc; background:#f7f8fa; border-radius:8px; padding:8px 12px; cursor:pointer; font-weight:700; color:#3a3f4b; font-size:12.5px;}
  .wi-b audio,.wi-b video{max-width:100%;}
  .wi-ev{align-self:center; background:#e9eef5; color:#5b6573; font-size:11.5px; font-weight:600; border-radius:99px; padding:3px 14px; margin:3px 0; text-align:center; max-width:88%;}
  .wi-un{display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 6px; border-radius:99px; background:#128c4a; color:#fff; font-size:11px; font-weight:800; flex:none;}
  .wi-it.unr .n span:first-child{font-weight:800;}
  .wi-tr{border:1px solid var(--line,#e4e7ec); background:#fff; border-radius:999px; padding:7px 10px; font-size:12.5px; font-weight:700; min-height:0; margin:0; width:auto; max-width:150px;}
  .wi-sw{position:relative; display:inline-block; width:38px; height:22px;} .wi-sw input{opacity:0; width:0; height:0;} .wi-sw span{position:absolute; inset:0; background:#c5c9d2; border-radius:99px; cursor:pointer; transition:.15s;} .wi-sw span:before{content:''; position:absolute; width:16px; height:16px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.15s;} .wi-sw input:checked + span{background:#128c4a;} .wi-sw input:checked + span:before{transform:translateX(16px);}
  .wi-slash{padding:6px 12px; background:#fff; border-top:1px solid #eef0f3; max-height:170px; overflow-y:auto;} .wi-slash div{padding:7px 10px; border-radius:8px; cursor:pointer;} .wi-slash div:hover,.wi-slash div.on{background:#f3f5f8;} .wi-slash b{display:block; font-size:12.5px;} .wi-slash small{color:#6b7684; font-size:12px; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .wi-tick.r{color:#2a8fe0;} .wi-tick.f{color:#c22;}
  .wi-note{margin:0 14px 8px; border-radius:10px; padding:8px 12px; font-size:12.5px; background:#fff6d6; color:#7a5200;} .wi-note.r{background:#fde6e6; color:#9a2620;}
  .wi-quick{padding:6px 12px; background:#fff; border-top:1px solid #eef0f3; display:flex; gap:6px; flex-wrap:wrap; max-height:96px; overflow-y:auto;}
  .wi-quick button{border:1px solid #d9dde4; background:#f9fafb; border-radius:99px; padding:5px 12px; font-size:12px; font-weight:700; cursor:pointer;}
  .wi-comp{display:flex; gap:8px; padding:10px 12px; background:#fff; border-top:1px solid #eef0f3; align-items:flex-end;}
  .wi-comp textarea{flex:1; min-height:42px; max-height:140px; resize:none; border:1px solid var(--line,#e4e7ec); border-radius:12px; padding:9px 12px; margin:0;}
  .wi-btn{border:1px solid var(--line,#e4e7ec); background:#fff; color:#2b2f3a; border-radius:999px; padding:8px 15px; font-size:12.5px; font-weight:700; cursor:pointer; width:auto; min-height:0;}
  .wi-btn.p{background:#f58220; border-color:#f58220; color:#fff;} .wi-btn.d{background:#1c2430; border-color:#1c2430; color:#fff;} .wi-btn:disabled{opacity:.5; cursor:default;}
  .wi-cu-top b{font-size:15px;} .wi-cu-ch{display:inline-block;margin-left:8px;border-radius:99px;padding:1px 9px;font-size:11px;font-weight:800;background:#efe7fd;color:#6d28d9;} .wi-cu-big{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:8px 0;} .wi-cu-big div{background:#f6f7f9;border-radius:10px;padding:7px 8px;} .wi-cu-big small{display:block;font-size:11px;color:#6b7684;} .wi-cu-big b{font-size:15px;}
  .wi-cu-fl{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px;} .wi-cu-fl span{border-radius:99px;padding:2px 9px;font-size:11.5px;font-weight:800;background:#eef0f3;color:#4b5563;} .wi-cu-fl .ok{background:#dcf5e8;color:#157a3c;} .wi-cu-fl .warn{background:#fff4e0;color:#a15c07;} .wi-cu-fl .bad{background:#fdecea;color:#b42318;} .wi-cu-fl .info{background:#e8f0fe;color:#1d4ed8;}
  .wi-cu-ol div{display:flex;gap:8px;align-items:baseline;padding:5px 0;border-top:1px solid #eef0f3;font-size:12.5px;} .wi-cu-ol span{color:#6b7684;flex:1;min-width:0;} .wi-cu-ol em{font-style:normal;font-weight:800;} .wi-cu-new{display:flex;flex-direction:column;gap:2px;} .wi-cu-new span{color:#6b7684;font-size:12.5px;}
  .wi-side{overflow-y:auto; padding:14px;} .wi-side h4{margin:14px 0 6px; font-size:11px; color:#6b7684; text-transform:uppercase; letter-spacing:.5px;} .wi-side h4:first-child{margin-top:0;}
  .wi-sb{background:#f7f9fc; border-radius:10px; padding:10px 12px; line-height:1.5;}
  .wi-kv{display:flex; justify-content:space-between; gap:10px; padding:4px 0; border-bottom:1px dashed #e9ecf1;} .wi-kv:last-child{border-bottom:0;} .wi-kv span{color:#6b7684;} .wi-kv b{text-align:right; word-break:break-word;}
  .wi-side select{width:100%; border:1px solid var(--line,#e4e7ec); border-radius:10px; padding:8px 10px; background:#fff; margin:6px 0; min-height:0;}
  .wi-act{display:flex; gap:6px; flex-wrap:wrap; margin-top:6px;}
  .wi-err{margin:6px 0; border-radius:10px; padding:8px 12px; font-size:12.5px; background:#fde8e8; color:#9a2620;}
  .wi.det .wi-x{display:inline-block;}
  .wi-cap{margin-top:5px; white-space:pre-wrap;} .wi-b video{max-height:300px; border-radius:8px; display:block;}
  .wi-tray{display:flex; gap:8px; padding:8px 12px 0; background:#fff; border-top:1px solid #eef0f3; overflow-x:auto;} .wi-tray:empty{display:none;}
  .wi-th{position:relative; flex:none; width:74px; height:74px; border-radius:10px; background:#f1f3f6; overflow:hidden; display:flex; flex-direction:column; align-items:center; justify-content:center; font-size:11px; color:#4a5260; text-align:center; padding:4px;}
  .wi-th img{position:absolute; inset:0; width:100%; height:100%; object-fit:cover;} .wi-th span{position:relative; z-index:1; word-break:break-all; line-height:1.2; max-height:2.4em; overflow:hidden;}
  .wi-th .ic{position:relative; z-index:1; font-size:24px; line-height:1;} .wi-th small{position:absolute; left:0; right:0; bottom:0; z-index:2; background:#0008; color:#fff; font-size:11px; padding:1px 0;}
  .wi-th button{position:absolute; top:2px; right:2px; z-index:3; width:22px; height:22px; min-height:0; padding:0; border:0; border-radius:50%; background:#000a; color:#fff; font-size:14px; line-height:22px; cursor:pointer;}
  .wi-th.up{opacity:.55;}
  .wi-am{position:absolute; left:10px; bottom:62px; z-index:30; background:#fff; border:1px solid var(--line,#e4e7ec); border-radius:14px; box-shadow:0 8px 24px #0002; padding:6px; min-width:200px;}
  .wi-am button{display:flex; gap:10px; align-items:center; width:100%; border:0; background:none; padding:11px 12px; border-radius:10px; font-size:14px; font-weight:700; text-align:left; cursor:pointer; min-height:44px;} .wi-am button:hover{background:#f3f5f8;}
  .wi-drop{outline:3px dashed #25d366; outline-offset:-8px;}
  .wi-lb{position:fixed; inset:0; background:#000c; display:flex; align-items:center; justify-content:center; z-index:9999; cursor:zoom-out;} .wi-lb img{max-width:94vw; max-height:92vh; border-radius:8px;}
  .wi-sug{margin:0 12px 6px; border:1px solid #e3d9ff; background:#f5f1ff; border-radius:12px; padding:8px 10px; font-size:13px;} .wi-sug.typing{display:none;}
  .wi-sug .sg1{display:flex; align-items:center; gap:6px;} .wi-sug .sg1 .ic{flex:none; font-size:14px;} .wi-sug .sg1 .tx{flex:1; min-width:0; margin:0; font-size:12.5px; line-height:1.3; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;} .wi-sug .sgb{flex:none; border:0; border-radius:999px; min-height:28px; padding:0 11px; font-size:12px; font-weight:700; background:#f0f2f5; color:#111b21; cursor:pointer;} .wi-sug .sgb.p{background:#00a884; color:#fff;} .wi-sug .sgb.g{width:28px; padding:0; color:#54656f;} .wi-sug .sgf{font-size:11.5px; color:#b3261e; margin-top:2px;}
  .wi-sug .lb{font-size:11px; font-weight:800; color:#6b3fc0; text-transform:uppercase; letter-spacing:.4px;} .wi-sug .tx{margin:4px 0; white-space:pre-wrap; line-height:1.45;} .wi-sug .why{color:#6b7684; font-size:12px;}
  .wi-sug .ac{display:flex; gap:6px; margin-top:6px; flex-wrap:wrap;} .wi-sug .wi-btn{padding:6px 12px; min-height:34px;}
  .wi-sh{position:fixed; inset:0; z-index:90; background:#0007; display:flex; align-items:flex-end; justify-content:center;}
  .wi-shb{background:#fff; width:100%; max-width:640px; max-height:88vh; max-height:88dvh; border-radius:18px 18px 0 0; display:flex; flex-direction:column; box-shadow:0 -8px 30px #0003;}
  .wi-shh{display:flex; align-items:center; justify-content:space-between; padding:14px 16px 10px; border-bottom:1px solid #eef0f3;} .wi-shh b{font-size:16px;}
  .wi-shx{border:0; background:#f1f3f6; border-radius:50%; width:36px; height:36px; font-size:16px; cursor:pointer; min-height:0; padding:0;}
  .wi-shc{overflow-y:auto; padding:12px 16px calc(16px + env(safe-area-inset-bottom)); flex:1;}
  .wi-seg{display:flex; gap:6px; margin-bottom:10px;} .wi-seg button{flex:1; border:0; background:#f3f5f8; border-radius:10px; padding:10px 8px; font-weight:800; font-size:13px; cursor:pointer; min-height:42px;} .wi-seg button.on{background:#1c2430; color:#fff;}
  .wi-card{border:1px solid #e4e7ec; border-radius:12px; padding:10px 12px; margin-bottom:8px; background:#fff;} .wi-card.tap{cursor:pointer;} .wi-card.tap:active{background:#f7f9fc;} .wi-card.off{opacity:.55;}
  .wi-card .tt{display:flex; justify-content:space-between; gap:8px; font-weight:800; font-size:13px; margin-bottom:4px;} .wi-card .bd{white-space:pre-wrap; color:#2b2f3a; line-height:1.45; font-size:13px;} .wi-card .ft{color:#6b7684; font-size:12px; margin-top:6px;}
  .wi-und{background:#eef6ff; border:1px solid #cfe3fb; border-radius:12px; padding:10px 12px; margin-bottom:10px; line-height:1.5;} .wi-und small{display:block; font-weight:800; color:#1f5fc4; font-size:11px; text-transform:uppercase; letter-spacing:.4px; margin-bottom:3px;}
  .wi-bub{background:#dcf8c6; border-radius:12px; padding:10px 12px; white-space:pre-wrap; line-height:1.45; margin:6px 0 10px; box-shadow:0 1px 2px #0001;}
  .wi-fl{display:block; font-weight:700; font-size:12px; color:#4a5260; margin:8px 0 3px;} .wi-in{width:100%; border:1px solid var(--line,#e4e7ec); border-radius:10px; padding:10px 12px; min-height:44px; margin:0;}
  .wi-lib{display:grid; grid-template-columns:repeat(auto-fill,minmax(104px,1fr)); gap:8px;} .wi-li{border:1px solid #e4e7ec; border-radius:12px; overflow:hidden; cursor:pointer; background:#fff;} .wi-li .im{height:88px; background:#f1f3f6 center/cover no-repeat; display:flex; align-items:center; justify-content:center; font-size:30px;} .wi-li div.nm{padding:6px 8px; font-size:12px; font-weight:700; line-height:1.25; max-height:3.2em; overflow:hidden;}
  .wi-call{position:fixed; left:0; right:0; top:0; z-index:95; display:flex; justify-content:center; padding:10px; pointer-events:none;} .wi-call>div{pointer-events:auto; width:100%; max-width:520px; background:#12794a; color:#fff; border-radius:16px; padding:12px 14px; box-shadow:0 10px 30px #0004;} .wi-call.esc>div{background:#b42318;}
  .wi-rules{position:fixed; left:0; right:0; top:0; z-index:94; display:flex; justify-content:center; padding:10px; pointer-events:none;} .wi-rules>div{pointer-events:auto; width:100%; max-width:520px; background:#1d4ed8; color:#fff; border-radius:16px; padding:12px 14px; box-shadow:0 8px 28px rgba(0,0,0,.25); font-size:13px;} .wi-rules b{font-size:14px;} .wi-rules ul{margin:6px 0 8px 18px; padding:0; font-size:12.5px; opacity:.95;} .wi-rules button{border:0; border-radius:999px; padding:8px 16px; font-weight:800; font-size:13px; background:#fff; color:#1d4ed8; cursor:pointer;}
  .wi-hot{display:flex; justify-content:space-between; gap:8px; background:#fee2e2; color:#991b1b; font-weight:800; font-size:12.5px; padding:8px 12px; border-top:1px solid #fecaca; animation:wiHotP 1.8s ease-in-out infinite;} .wi-hot[hidden]{display:none;}
  .wi-ub{display:flex; flex-wrap:wrap; gap:6px; padding:8px 10px 0;} .wi-ub[hidden]{display:none;} .wi-ub .u{flex:1 1 auto; border:0; border-radius:10px; padding:9px 12px; font-weight:800; font-size:12.5px; cursor:pointer; text-decoration:none; text-align:center; color:#fff; display:inline-block;}
  .wi-ub .g{background:#16a34a;} .wi-ub .r{background:#dc2626;} .wi-ub .o{background:#f59e0b;} .wi-ub .n{background:#e2e8f0; color:#0f172a;} .wi-ub .rm{align-self:center; font-size:12px; color:#92400e;}
  @keyframes wiHotP{50%{background:#fecaca;}} @media (prefers-reduced-motion:reduce){.wi-hot{animation:none;}}
  .wi-au{position:relative; margin:0; background:#fff; border-top:1px solid #e9edef; animation:wiAuIn .2s ease-out;} .wi-au[hidden]{display:none;}
  .wi-au .bar{display:block; height:4px; background:#d9fdd3; overflow:hidden;} .wi-au .bar u{display:block; height:100%; width:100%; background:#00a884; animation:wiAuBar linear forwards;}
  .wi-au .hd{display:flex; align-items:center; gap:6px; padding:5px 8px;} .wi-au .tx{flex:1; min-width:0; font-size:12.5px; line-height:1.3; color:#111b21; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; white-space:pre-wrap;}
  .wi-au button{border:0; border-radius:999px; font-weight:700; font-size:12px; cursor:pointer; min-height:28px; padding:0 11px; flex:none;} #wiAuG{background:#00a884; color:#fff;} #wiAuG b{font-variant-numeric:tabular-nums;} #wiAuX{background:#f0f2f5; color:#54656f; width:28px; padding:0; font-size:13px;}
  .wi-au.sent{background:#d9fdd3; animation:wiAuOut .45s .55s ease-in forwards;} .wi-au.sent .hd{padding:6px 10px; color:#00a884; font-size:12.5px;}
  @keyframes wiAuIn{from{opacity:0;}} @keyframes wiAuBar{to{width:0;}} @keyframes wiAuOut{to{opacity:0; height:0; padding:0; margin:0;}}
  .wi-it.wi-out{transition:all .4s ease-in; opacity:0; transform:translateX(40px); max-height:0; padding-top:0; padding-bottom:0; overflow:hidden;} .wi-auc.on{background:#0f766e; color:#fff; border-color:#0f766e;}
  @media (prefers-reduced-motion:reduce){.wi-au,.wi-au.sent,.wi-it.wi-out{animation:none; transition:none;} .wi-au .bar u{animation-timing-function:steps(20);}}
  .wi-call b{font-size:15px;} .wi-call .sm{font-size:12.5px; opacity:.92; margin:3px 0 8px;} .wi-call .ac{display:flex; gap:8px; flex-wrap:wrap;} .wi-call a,.wi-call button{border:0; border-radius:999px; padding:10px 16px; font-weight:800; font-size:13.5px; cursor:pointer; text-decoration:none; min-height:42px; display:inline-flex; align-items:center; background:#fff; color:#12794a; width:auto;} .wi-call.esc a,.wi-call.esc button{color:#b42318;} .wi-call button.gh{background:#ffffff33; color:#fff;}
  .wi-b.tpl{background:#e3f1ff;} .wi-b .tg{display:block; font-size:11px; font-weight:800; color:#1f5fc4; margin-bottom:3px; text-transform:uppercase; letter-spacing:.3px;}

  .wi-fu{margin:0 12px 6px; border:2px solid #7c3aed; background:#f8f5ff; border-radius:14px; padding:9px 12px; font-size:13px;} .wi-fu.slim{border:1px solid #e4e7ec; background:#f7f8fa; padding:7px 12px; color:#5b6573; font-size:12.5px;}
  .wi-fu .fh{display:flex; align-items:center; gap:8px; flex-wrap:wrap; cursor:pointer;} .wi-fu .fh b{font-size:13px;} .wi-fu .fh .sm{color:#6b3fc0; font-weight:700; font-size:12px; flex:1; min-width:0;} .wi-fu .fh .tg{border:0; background:none; font-size:15px; color:#6b3fc0; cursor:pointer; padding:0 4px; min-height:0; width:auto;}
  .wi-fu .tl{display:flex; justify-content:space-between; position:relative; margin:12px 4px 6px;} .wi-fu .tl:before{content:''; position:absolute; left:8px; right:8px; top:6px; height:3px; background:#ddd2fb; border-radius:2px;}
  .wi-fu .st{position:relative; flex:1; text-align:center; font-size:11px; color:#6b7684; line-height:1.3; min-width:0; padding:0 2px; overflow-wrap:anywhere;} .wi-fu .st i{display:block; width:15px; height:15px; border-radius:50%; margin:0 auto 4px; background:#fff; border:2px solid #7c3aed; position:relative;}
  .wi-fu .st.done i{background:#7c3aed;} .wi-fu .st.skip i{border-color:#b9b2c9; background:#eee;} .wi-fu .st.next i{box-shadow:0 0 0 3px #e3d9ff;} .wi-fu .st.next b{color:#1c2430;} .wi-fu .st b{display:block; font-weight:800; color:#4a5260;} .wi-fu .st.tpl em{display:block; font-style:normal; color:#1f5fc4; font-weight:700;}
  .wi-fu .why{margin:6px 0; line-height:1.5; color:#2b2f3a;} .wi-fu .why b{color:#1c2430;}
  .wi-fu .box{background:#fff; border:1px solid #e3d9ff; border-radius:10px; padding:9px 11px; white-space:pre-wrap; line-height:1.45; margin:6px 0;} .wi-fu .box.tp{background:#eef6ff; border-color:#cfe3fb;} .wi-fu .box small{display:block; font-size:11px; font-weight:800; color:#6b3fc0; text-transform:uppercase; letter-spacing:.4px; margin-bottom:3px;} .wi-fu .box.tp small{color:#1f5fc4;}
  .wi-fu textarea{width:100%; min-height:74px; border:1px solid #cdbdf6; border-radius:10px; padding:8px 10px; margin:6px 0 0; resize:vertical;} .wi-fu .ac{display:flex; gap:6px; flex-wrap:wrap; margin-top:6px;} .wi-fu .ac .wi-btn{padding:7px 13px; min-height:36px;}
  .wi-fu .ft{font-size:11.5px; color:#6b7684; margin-top:6px;} .wi-fu .er{background:#fde8e8; color:#9a2620; border-radius:8px; padding:6px 10px; margin-top:6px; font-size:12.5px;}
  .wi-hs{display:flex; gap:8px; align-items:flex-start; padding:7px 0; border-bottom:1px dashed #e9ecf1; font-size:12.5px;} .wi-hs:last-child{border-bottom:0;} .wi-hs i{flex:none; width:20px; height:20px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-style:normal; font-size:11px; font-weight:800; color:#fff; background:#12806a; margin-top:1px;}
  .wi-hs i.w{background:#f59e0b;} .wi-hs i.n{background:#9aa3b0;} .wi-hs i.f{background:#c22;} .wi-hs small{display:block; color:#7a8594; font-size:11px;}
  .wi-fu-p{display:inline-block; margin-left:4px;}
  @media (min-width:761px){ .wi-sh{align-items:center;} .wi-shb{border-radius:18px; max-height:80vh;} }
  @media (max-width:1100px){ .wi-box,.wi.mine .wi-box{grid-template-columns:300px minmax(0,1fr);} .wi-col.side{display:none;} .wi.det .wi-col.side{display:flex; position:fixed; inset:0; z-index:50; background:#fff;} .wi-more{display:inline-block;} }
  /* live colour of a chat row (wa_heat): very light, with a thin left edge so it reads at a glance */
  .wi-it.h-hot{background:#effbf3; box-shadow:inset 3px 0 0 #22c55e;} .wi-it.h-late{background:#fff8e6; box-shadow:inset 3px 0 0 #f59e0b;} .wi-it.h-active{background:#f3f8ff; box-shadow:inset 3px 0 0 #60a5fa;}
  .wi-it.h-slow{background:#f6f7f9; box-shadow:inset 3px 0 0 #c4c9d1;} .wi-it.h-cx{background:#f7f4ff; box-shadow:inset 3px 0 0 #a78bfa;} .wi-it.h-biz{background:#f1f1f1; box-shadow:inset 3px 0 0 #9ca3af; opacity:.8;}
  .wi-lg{display:flex; gap:10px; flex-wrap:wrap; padding:6px 12px; font-size:11.5px; color:#6b7684; border-bottom:1px solid #eef0f3;} .wi-lg i{display:inline-block; width:9px; height:9px; border-radius:3px; margin-right:4px; vertical-align:-1px;}
  .wi-p.biz{background:#ececec; color:#4b5563;}
  .wi-b.wi-tmp{opacity:.7;} .wi-b.wi-tmp small{font-style:italic;}
  .wi-asg{display:inline-flex; align-items:center; gap:4px; border:1px solid #cfe3ff; background:#eef5ff; color:#1d4ed8; border-radius:999px; padding:5px 11px; font-size:12.5px; font-weight:700; cursor:pointer; max-width:240px; min-height:0; width:auto;} .wi-asg span{white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .wi-asg.un{background:#fff4e5; border-color:#fbd38d; color:#9a4b00;} .wi-asg.ro{cursor:default; background:#f3f4f6; border-color:#e5e7eb; color:#4b5563;}
  .wi-asp{position:fixed; inset:0; z-index:80;} .wi-aspb{position:fixed; width:340px; max-width:calc(100vw - 16px); max-height:min(520px, 70vh); background:#fff; border-radius:14px; box-shadow:0 12px 40px rgba(15,23,42,.22); border:1px solid #e4e7ec; display:flex; flex-direction:column; padding:12px; gap:8px; font-family:inherit; color:#1c2430;}
  .wi-asph{display:flex; align-items:center; justify-content:space-between; gap:8px;} .wi-asph b{font-size:15px;} .wi-aspn{font-size:12.5px; color:#6b7684;} .wi-aspq{display:flex; gap:6px; flex-wrap:wrap;}
  .wi-aspb input{width:100%; box-sizing:border-box; border:1px solid #d0d5dd; border-radius:10px; padding:9px 11px; font-size:14px; margin:0; min-height:0;}
  .wi-aspl{overflow:auto; flex:1; min-height:60px; margin:0 -4px;} .wi-asx{font-size:11.5px; color:#8a94a3; font-weight:700; text-transform:uppercase; letter-spacing:.3px; padding:8px 8px 4px;}
  .wi-asr{display:grid; grid-template-columns:10px 1fr; column-gap:9px; align-items:center; width:100%; text-align:left; border:0; background:none; padding:8px; border-radius:10px; cursor:pointer; font:inherit; color:inherit; min-height:0;}
  .wi-asr:hover{background:#f3f6fa;} .wi-asr:disabled{opacity:.55; cursor:default;} .wi-asr i{width:9px; height:9px; border-radius:50%; background:#c4c9d1; grid-row:span 2;} .wi-asr.on i{background:#16a34a; box-shadow:0 0 0 3px #dcfce7;} .wi-asr.on small{color:#15803d; font-weight:600;}
  .wi-asr b{font-size:14px; font-weight:600;} .wi-asr small{font-size:12px; color:#6b7684;}
  .wi-mi{display:flex; gap:12px; align-items:center; width:100%; text-align:left; padding:12px 6px; border:0; background:none; border-bottom:1px solid #eef0f2; font:inherit; color:inherit; cursor:pointer;} .wi-mi span{font-size:22px; width:32px; text-align:center;} .wi-mi b{font-weight:600; font-size:15px; display:flex; flex-direction:column;} .wi-mi small{font-weight:400; font-size:12.5px; color:#6b7684; margin-top:2px;}
  .wi .wi-head .wi-ph{display:none;} .wi-comp .wi-btn .lb{display:none;}
  @media (max-width:760px){
    .wi-box,.wi.mine .wi-box{grid-template-columns:1fr; height:calc(100vh - 170px); height:calc(100dvh - 170px); min-height:420px;}
    .wi-col.chat{display:none;} .wi.open .wi-col.list{display:none;} .wi.open .wi-col.chat{display:flex;} .wi-back{display:inline-block;}
    .wi.open .wi-kpi{display:none;}
    .wi.open .wi-box{position:fixed; inset:0; height:100vh; height:100dvh; border-radius:0; border:0; z-index:40; min-height:0;}
    .wi.det .wi-col.side{padding-bottom:calc(24px + env(safe-area-inset-bottom));}
    .wi input,.wi textarea,.wi select{font-size:16px !important;}
    .wi-kpi{flex-wrap:nowrap; gap:6px; margin-bottom:8px;} .wi-kpi div{min-width:0; padding:6px 10px;} .wi-kpi small{font-size:11px;} .wi-kpi b{font-size:16px;}
    .wi-head{padding:8px 10px; gap:6px;} .wi-head .wi-p,.wi-head .ph{display:none;} .wi-head b{font-size:15px; flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;} .wi-head .sp{display:none;}
    .wi-btn,.wi-more,.wi-back,.wi-tr{min-height:40px;} .wi-head .wi-back .lb{display:none;} .wi-head .wi-back{padding:6px 12px; font-size:20px;}
    .wi .wi-head .wi-pc{display:none !important;} .wi .wi-head .wi-ph{display:inline-flex;}
    .wi-head .wi-ib{display:inline-flex; flex-direction:column; align-items:center; justify-content:center; min-width:46px; min-height:44px; padding:3px 6px; font-size:16px; line-height:1.05; border-radius:12px;} .wi-head .wi-ib .lb{display:block; font-size:11px; font-weight:700; margin-top:1px;}
    .wi-head .wi-asg{order:10; flex-basis:100%; max-width:none; justify-content:flex-start; min-height:36px;} .wi-head #wiCxB{order:11;}
    .wi-aspb{left:0 !important; right:0; bottom:0; top:auto !important; width:100%; max-width:none; border-radius:18px 18px 0 0; max-height:80vh; padding:14px 14px calc(14px + env(safe-area-inset-bottom));} .wi-asp{background:rgba(15,23,42,.45);} .wi-asr{padding:11px 8px;}
    .wi-msgs{padding:10px 10px;} .wi-b{max-width:88%;}
    .wi-comp{padding:6px 8px calc(8px + env(safe-area-inset-bottom)); gap:6px; flex-wrap:wrap;} .wi-comp textarea{min-height:44px; order:20; flex:1 1 calc(100% - 60px); min-width:0;} .wi-comp #wiSend{order:21;}
    .wi .wi-comp > .wi-btn:not(#wiSend){display:inline-flex; flex-direction:column; align-items:center; justify-content:center; flex:1 1 15%; min-width:0; min-height:44px; padding:3px 2px; font-size:17px; line-height:1.05; border-radius:12px;} .wi .wi-comp > .wi-btn .lb{display:block; font-size:11px; font-weight:700; margin-top:1px; white-space:nowrap;}
    .wi-tab{padding:9px 14px; font-size:13px;} .wi-it{padding:12px 14px;}
  }`;

  function start(opts) {
    const admin = opts.mode === 'admin';
    const S = { sort: '', flt: [], admin, scope: admin ? 'unassigned' : 'mine', src: '', q: '', emp: 0, chats: [], counts: {}, sel: '', cur: null, people: [], quick: [], busyList: false, lastMsgId: 0, tagFor: '', files: [], sug: {}, sugBusy: false, tsug: {}, fu: null, fuOpen: {}, fuEdit: false, fuBusy: false };
    const root = $(opts.root || 'wi'); root.className = 'wi ' + (admin ? 'admin' : 'mine') + (opts.one ? ' one' : '');
    const tellParent = (m) => { if (opts.one && window.parent !== window) { try { window.parent.postMessage(Object.assign({ wa: '' }, m), location.origin); } catch (e) { } } };   // wa_one.html: the side panel around this chat
    const st = document.createElement('style'); st.textContent = CSS + '.wi-new{display:inline-block;width:12px;height:12px;border-radius:50%;background:#22c55e;margin-right:6px;vertical-align:-1px;box-shadow:0 0 0 0 rgba(34,197,94,.55);animation:winew 1.6s infinite}@keyframes winew{0%{box-shadow:0 0 0 0 rgba(34,197,94,.55)}70%{box-shadow:0 0 0 8px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}'; document.head.appendChild(st);
    const tabs = admin ? [['unassigned', '🙋 Requests'], ['all', 'All open'], ['closed', 'Closed']] : [['mine', 'Open'], ['closed', 'Closed']];
    root.innerHTML = `
      <div class="wi-kpi" id="wiK"></div>
      <div class="wi-box">
        <div class="wi-col list">
          <div class="wi-tabs" id="wiT"></div>
          <div class="wi-tabs wi-feeds" id="wiF"></div>
          <div class="wi-find"><input id="wiQ" type="search" placeholder="Search name or number…">${admin ? '<select id="wiE" title="Show one person’s chats" hidden><option value="0">Everyone</option></select><div class="wi-ep"><button type="button" id="wiEb" title="Show one person’s chats">Everyone ▾</button><div id="wiEp" class="wi-epp" hidden><input id="wiEq" type="search" placeholder="Search a person…" autocomplete="off"><div id="wiEl"></div></div></div><div class="wi-ep wi-flw"><button type="button" id="wiFb" title="Sort and filter the list">⏷ Filter</button><div id="wiFp" class="wi-epp wi-flp" hidden></div></div>' : ''}</div>
          <div class="wi-list" id="wiL"><div class="wi-empty">Loading…</div></div>
        </div>
        <div class="wi-col chat" id="wiC"><div class="wi-empty" style="margin:auto">Pick a chat on the left.</div></div>
        <div class="wi-col side wi-side" id="wiS"></div>
      </div>`;

    // ---------- list
    function drawTabs() {
      const c = S.counts || {};
      const n = { unassigned: c.unassigned, all: c.all, mine: c.mine, closed: c.closed };
      $('wiT').innerHTML = tabs.map(([k, l]) => `<button type="button" class="wi-tab ${S.scope === k && !S.emp ? 'on' : ''}" data-k="${k}">${l}${n[k] != null ? '<i>' + n[k] + '</i>' : ''}</button>`).join('');
      $('wiT').querySelectorAll('button').forEach((b) => { b.onclick = () => { S.scope = b.dataset.k; S.emp = 0; if ($('wiE')) { $('wiE').value = '0'; ebSync(); } if (admin && S.src === 'assigned' && S.scope === 'unassigned') { S.src = ''; if (opts.onFeed) { try { opts.onFeed(''); } catch (e) { } } } loadList(); }; });
    }
    const FEEDS = admin ? [['', 'All'], ['query', 'Query'], ['assigned', 'Assigned'], ['community', 'Community'], ['campaign', 'Campaign']] : [['', 'All'], ['query', 'Query'], ['community', 'Community'], ['campaign', 'Campaign']];
    function drawFeeds() {
      const c = (S.counts || {}).src || {};
      $('wiF').innerHTML = FEEDS.map(([k, l]) => `<button type="button" class="wi-chip ${S.src === k ? 'on' : ''}" data-k="${k}">${l}${k && c[k] != null ? '<i>' + c[k] + '</i>' : ''}</button>`).join('');
      $('wiF').querySelectorAll('button').forEach((b) => { b.onclick = () => setFeed(b.dataset.k); });
      let x = ''; if (AU.allowed) x += `<button type="button" class="wi-chip wi-auc ${AU.on ? 'on' : ''}" id="wiAuT" title="The suggested reply counts down 10 seconds and sends itself unless you press Cancel">⚡ Auto-reply ${AU.on ? 'ON' : 'OFF'}</button>`;
      if (admin) x += `<button type="button" class="wi-chip ${S.bulk && S.bulk.on ? 'on' : ''}" id="wiBkT" title="Select many chats: send one message to all of them, or move them to another person">☑ Select chats</button>`;
      if (admin) x += `<button type="button" class="wi-chip" id="wiAuM" title="Switch auto-reply on or off for the whole team">${AU.allowed ? '🔓 Team auto-reply: ON' : '🔒 Team auto-reply: OFF'}</button>`;
      if (x) { $('wiF').insertAdjacentHTML('beforeend', x); if ($('wiAuT')) $('wiAuT').onclick = auToggle; if ($('wiBkT')) $('wiBkT').onclick = () => bkToggleMode(); if ($('wiAuM')) $('wiAuM').onclick = auMaster; }
    }
    function setFeed(k, scope) {
      S.src = k || ''; S.emp = 0; if ($('wiE')) { $('wiE').value = '0'; ebSync(); }
      if (scope) S.scope = scope; else if (admin && S.src === 'assigned' && S.scope === 'unassigned') S.scope = 'all';
      if (opts.onFeed) { try { opts.onFeed(S.src); } catch (e) { } }
      loadList();
    }
    function drawKpi() {
      const c = S.counts || {};
      $('wiK').innerHTML = admin
        ? `<div class="${c.unassigned ? 'hot' : ''}"><small>Unassigned</small><b>${c.unassigned || 0}</b></div><div class="${c.waiting ? 'hot' : ''}"><small>Waiting for a reply</small><b>${c.waiting || 0}</b></div><div><small>Open chats</small><b>${c.all || 0}</b></div><div><small>Closed</small><b>${c.closed || 0}</b></div>`
        : `<div><small>My open chats</small><b>${c.mine || 0}</b></div><div class="${c.waiting ? 'hot' : ''}"><small>Waiting for my reply</small><b>${c.waiting || 0}</b></div><div><small>Closed by me</small><b>${c.closed || 0}</b></div>`;
    }
    function pills(c) {
      let h = c.hot ? '<span class="wi-p r">🔥 Hot</span>' : '';
      if (c.state === 'bot' && !c.assigned_emp) h += '<span class="wi-p o">Bot</span>';
      if (c.biz) h += '<span class="wi-p biz" title="Answered our campaign with an automatic business reply — not a customer">🏢 Business</span>';
      if (c.assigned_name) h += `<span class="wi-p g">${esc(c.assigned_name)}</span>`;
      if (SRC[c.source]) h += `<span class="wi-p ${SRC[c.source][1]}">${SRC[c.source][0]}</span>`;
      if (c.tag_label) h += `<span class="wi-p ${TAG_CLS[c.tag] || ''}">${esc(c.tag_label)}</span>`;
      if (c.optout) h += '<span class="wi-p r">Opted out</span>';
      if ((c.numbers_used || 0) > 1) h += `<span class="wi-p" title="This customer wrote to ${c.numbers_used} of our numbers — one chat">📱 ${c.numbers_used} numbers</span>`;
      return h;
    }
    function drawList() {
      const L = $('wiL'), rows = AU.on ? S.chats.filter((c) => c.waiting) : S.chats;   // auto-reply shows only chats waiting for a reply: an answered chat leaves the list
      if (AU.on && !rows.length) { L.innerHTML = '<div class="wi-empty">✅ All caught up.<br>A new chat appears here and gets a suggestion by itself.</div>'; return; }
      if (!S.chats.length) { L.innerHTML = `<div class="wi-empty">${S.q ? 'No chat matches your search.' : (S.src ? 'No chats in this feed' + (S.scope === 'unassigned' ? ' waiting to be assigned.' : ' right now.') : S.scope === 'closed' ? 'No closed chats.' : (admin && S.scope === 'unassigned' ? 'Nothing is waiting to be assigned. 🎉' : (admin ? 'No open chats.' : 'No chats are assigned to you right now.<br>When the admin gives you a chat, it appears here.')))}</div>`; return; }
      const gr = admin ? [] : (S.grab || []).slice(0, 4); const grab = gr.length ? `<div style="background:#fff8e1;border-bottom:1px solid #f3e2a9;padding:8px 10px;font-size:12.5px">🙋 <b>${S.grab.length} waiting ${S.grabMin}+ min</b>, nobody answering — take one:${gr.map((x) => `<div style="display:flex;gap:8px;align-items:center;margin-top:5px"><span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><b>${esc(x.name || x.phone)}</b> · ${x.waiting_min}m${x.holder && x.holder.name ? ' · with ' + esc(x.holder.name) : ''}</span><button type="button" class="wi-btn" data-grab="${esc(x.phone)}" style="flex:none;padding:3px 10px">Take</button></div>`).join('')}</div>` : '';
      const lg = '<div class="wi-lg" title="Each chat is coloured by what is happening in it right now"><span><i style="background:#22c55e"></i>Hot · ready to buy</span><span><i style="background:#f59e0b"></i>Waiting too long</span><span><i style="background:#60a5fa"></i>Talking now</span><span><i style="background:#c4c9d1"></i>Gone quiet</span><span><i style="background:#a78bfa"></i>Complaint</span><span><i style="background:#9ca3af"></i>🏢 Business</span></div>';
      const en = S.emp && $('wiE') ? (($('wiE').selectedOptions[0] || {}).text || '').replace(/ \(absent\)$/, '') : '', fn = en.split(' ')[0];
      const tgb = admin && S.emp ? `<div class="wi-tgb"><button type="button" data-tg="0" class="${S.empTag ? '' : 'on'}">Now with ${esc(fn)}${S.counts.emp_now != null ? ' (' + S.counts.emp_now + ')' : ''}</button><button type="button" data-tg="1" class="${S.empTag ? 'on' : ''}">🏷 ${esc(fn)}'s tag — all${S.counts.emp_tag != null ? ' (' + S.counts.emp_tag + ')' : ''}</button></div>` : '';
      L.innerHTML = tgb + bkBarHtml() + (admin ? '' : lg) + grab + rows.map((c) => { const un = c.unread && c.phone !== S.sel, bs = S.bulk.on && S.bulk.set.has(c.phone); return `<div class="wi-it ${c.phone === S.sel && !S.bulk.on ? 'sel' : ''} ${bs ? 'bsel' : ''} ${c.waiting ? 'wait' : ''} ${un ? 'unr' : ''} ${c.heat ? 'h-' + c.heat : ''}" data-p="${esc(c.phone)}">${S.bulk.on ? '<span class="wi-ck ' + (bs ? 'on' : '') + '"></span>' : ''}${av(c)}<div class="wi-bd">
          <div class="n"><span>${esc(title(c))}</span><span class="t">${!admin && c.fresh_agent ? '<span class="wi-new" title="New for you — it stays until you reply"></span>' : ''}${c.waiting ? '● ' : ''}${esc(stamp(c.last_msg_at || c.last_at))}</span></div>
          <div class="m2"><span class="m">${esc(c.preview || '')}</span>${un ? `<span class="wi-un">${c.unread > 9 ? '9+' : c.unread}</span>` : ''}</div><div class="pl">${pills(c)}</div></div></div>`; }).join('');
      L.querySelectorAll('.wi-it').forEach((el) => { el.onclick = () => (S.bulk.on ? bkToggle(el.dataset.p) : openChat(el.dataset.p)); }); bkWire(L);
      L.querySelectorAll('[data-tg]').forEach((b) => { b.onclick = () => { S.empTag = b.dataset.tg === '1'; loadList(); }; });
      L.querySelectorAll('[data-grab]').forEach((b) => { b.onclick = async (e) => { e.stopPropagation(); b.disabled = true; try { await api.post('wq.grab', { phone: b.dataset.grab }); S.grab = (S.grab || []).filter((x) => x.phone !== b.dataset.grab); await loadList(true); openChat(b.dataset.grab); } catch (er) { alert(er.message); loadList(true); } }; });
    }
    // ---------- 🔥 hand-over: HOT strip + urgency buttons (payment link, call, remind me, order placed)
    function drawUrg() {
      const hb = $('wiHot'), ub = $('wiUb'); if (!hb || !ub) return;
      if (!S.cur) { hb.hidden = true; ub.hidden = true; return; }
      const c = S.cur.contact, on = c.assigned_emp > 0 && c.status === 'open' && !c.optout;
      if (c.hot) { S.hotT0 = Date.now() - (c.hot_age || 0) * 1000; hb.hidden = false; hb.innerHTML = '<span>🔥 HOT — the customer wants to pay. Reply now.</span><span id="wiHotT">⏱ 0:00</span>'; hotTick(); } else { hb.hidden = true; hb.innerHTML = ''; }
      if (!on) { ub.hidden = true; ub.innerHTML = ''; return; }
      ub.hidden = false;
      ub.innerHTML = `<button type="button" class="u g" data-u="pay">💳 Send payment link</button><a class="u r" href="tel:+91${esc(c.phone)}">📞 Call now</a><button type="button" class="u o" data-u="rem">⏰ Remind me</button><button type="button" class="u n" data-u="paid">✅ Order placed</button>${c.remind_at ? `<span class="rm">⏰ reminder at ${esc(String(c.remind_at).slice(11, 16))}</span>` : ''}`;
      ub.querySelectorAll('[data-u]').forEach((b) => { b.onclick = () => urgAct(b.dataset.u); });
    }
    function hotTick() { const t = $('wiHotT'); if (!t || !S.hotT0) return; const s = Math.max(0, Math.floor((Date.now() - S.hotT0) / 1000)); t.textContent = '⏱ ' + Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }
    setInterval(hotTick, 1000);
    async function urgAct(k) {
      if (!S.cur) return; const c = S.cur.contact, phone = c.phone;
      if (k === 'pay') { openPay(); return; }
      if (k === 'rem') {
        const nx = new Date(); nx.setDate(nx.getDate() + 1); nx.setHours(10, 0, 0, 0); const tom = Math.max(30, Math.round((nx - Date.now()) / 60000));
        const box = sheet('⏰ Remind me about this chat');
        box.innerHTML = [[30, 'In 30 minutes'], [60, 'In 1 hour'], [180, 'In 3 hours'], [tom, 'Tomorrow 10:00']].map(([m, l]) => `<button type="button" class="wi-btn" data-m="${m}" style="width:100%;margin:6px 0">${l}</button>`).join('') + '<div id="wiRmE"></div>';
        box.querySelectorAll('[data-m]').forEach((b) => { b.onclick = async () => { try { await api.post('wa.remind', { phone, mins: +b.dataset.m }); closeSheet(); await refreshChat(); } catch (e) { $('wiRmE').innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; } }; });
        return;
      }
      if (k === 'paid') { if (!confirm('Mark this order as placed? The HOT flag and reminders are cleared.')) return; try { await api.post('wa.hand_mark', { phone, what: 'paid' }); await refreshChat(true); loadList(true); } catch (e) { alert(e.message); } }
    }
    async function openPay() {
      const c = S.cur.contact, phone = c.phone, box = sheet('💳 Send payment link');
      box.innerHTML = '<div style="font-size:12.5px;color:#6b7684;margin-bottom:8px">Paste the payment link. This message goes to the customer:</div><input class="wi-in" id="wiPyU" type="url" placeholder="https://…" inputmode="url"><textarea class="wi-in" id="wiPyT" rows="4" style="margin-top:8px"></textarea><div id="wiPyE"></div><button type="button" class="wi-btn p" id="wiPyG" style="margin-top:8px;width:100%">Send to customer</button><div style="font-size:12px;color:#6b7684;margin-top:8px">If it is not paid, the follow-up messages continue by themselves.</div>';
      const nm = firstName(c), msg = (u) => (nm ? nm + ' ji, ' : '') + 'yahan se payment kar dijiye 🙏\n' + u + '\nPayment hote hi aapka order confirm ho jayega.';
      const U = $('wiPyU'), T = $('wiPyT'); T.value = msg('(link)'); U.oninput = () => { T.value = msg(U.value.trim() || '(link)'); };
      try { const r = await api.post('wa.paylink_make', { phone }); if (r && r.url) { U.value = r.url; U.oninput(); } } catch (e) { }   // later: the own website builds the cart + discount and returns the link
      $('wiPyG').onclick = async function () {
        const u = U.value.trim(); if (!/^https:\/\/\S+$/i.test(u)) { $('wiPyE').innerHTML = '<div class="wi-err">Paste a link that starts with https://</div>'; return; }
        let text = T.value.trim(); if (!text.includes(u)) text += '\n' + u; this.disabled = true;
        try { await api.post('wa.send', { phone, text }); await api.post('wa.hand_mark', { phone, what: 'link' }); closeSheet(); await refreshChat(true); loadList(true); }
        catch (e) { $('wiPyE').innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; this.disabled = false; }
      };
    }
    // ---------- ⚡ Auto-reply: the suggested reply counts down (10 s) and sends itself unless you press Cancel; the answered chat leaves the list and the next waiting chat opens
    const AU = S.auto = { allowed: false, secs: 20, on: false, follow: true, run: null, skip: {}, tried: {} };
    try { AU.on = localStorage.getItem('wa_auto_on') === '1'; } catch (e) { }
    const auRow = (ph) => S.chats.find((c) => c.phone === ph);
    const auSkipped = (ph, li) => { const k = AU.skip[ph]; if (!k) return false; const row = auRow(ph); return li ? k.li === li : !!(row && k.at === row.last_at); };
    function auSkip(ph, li) { const row = auRow(ph); AU.skip[ph] = { li, at: row ? row.last_at : '' }; }
    function auCfg(a) { if (!a) return; AU.allowed = !!a.on; AU.secs = Math.max(5, Math.min(60, +a.secs || 20)); if (!AU.allowed && AU.on) { AU.on = false; auStop(false); } }
    function auToggle() {
      if (!AU.on && !confirm('Auto-reply: each suggested reply is SENT to the customer after ' + AU.secs + ' seconds unless you press Cancel (or start typing). Turn it on?')) return;
      AU.on = !AU.on; try { localStorage.setItem('wa_auto_on', AU.on ? '1' : '0'); } catch (e) { }
      if (!AU.on) auStop(false); AU.follow = true; drawFeeds(); drawList(); if (AU.on) auNext();
    }
    async function auMaster() {
      const on = !AU.allowed;
      if (on && !confirm('Allow auto-reply for the team? Each person still has to switch it on for themselves. Suggested replies are then sent to customers automatically after ' + AU.secs + ' seconds unless the person presses Cancel.')) return;
      try { const r = await api.post('wa.autosend', { on }); auCfg(r); } catch (e) { alert(e.message); return; }
      drawFeeds(); drawList();
    }
    function auStop(cancelled) {
      const r = AU.run; if (!r) return; clearTimeout(r.tm); clearInterval(r.iv); AU.run = null;
      if (cancelled) auSkip(r.phone, r.li);
      const b = $('wiAu'); if (b) { b.hidden = true; b.innerHTML = ''; b.className = 'wi-au'; }
      if (S.cur) drawSug();
    }
    function auStart(text, li) {
      if (AU.run || !text || !S.cur) return; const b = $('wiAu'); if (!b) return; const phone = S.cur.contact.phone, secs = AU.secs;
      AU.run = { phone, li, text, t0: Date.now(), secs };
      b.hidden = false; b.className = 'wi-au';
      b.innerHTML = `<i class="bar"><u style="animation-duration:${secs}s"></u></i><div class="hd"><span class="tx">${esc(text)}</span><button type="button" id="wiAuG"><b id="wiAuN">${secs}</b>s · Send</button><button type="button" id="wiAuX" aria-label="Cancel">✕</button></div>`;
      $('wiAuG').onclick = () => auSend(true); $('wiAuX').onclick = () => { AU.follow = false; auStop(true); };
      AU.run.iv = setInterval(() => { const n = $('wiAuN'); if (n && AU.run) n.textContent = Math.max(0, Math.ceil(secs - (Date.now() - AU.run.t0) / 1000)); }, 250);
      AU.run.tm = setTimeout(() => auSend(false), secs * 1000);
      drawSug();
    }
    async function auSend(now) {
      const r = AU.run; if (!r || r.sending) return; r.sending = true; clearTimeout(r.tm); clearInterval(r.iv);
      if (PR.p) await Promise.race([PR.p, new Promise((z) => setTimeout(z, 1500))]);   // the photo list is ready before the text goes
      if (document.hidden && !now) { auStop(false); return; }   // nobody is looking at the screen: never send in the background
      try { await refreshChat(); } catch (e) { }
      // last look before it goes: same chat, the customer has not written again, nobody else has replied
      if (!S.cur || S.cur.contact.phone !== r.phone || lastIn() !== r.li || (lastMsg() || {}).dir !== 'in') { auStop(false); auArm(); return; }
      try { await api.post('wa.send', { phone: r.phone, text: r.text, auto: true }); prodAfter(r.phone); }   // auto: lets the server claim the customer message (wq no-answer flow); a 409 'already answered' lands in the catch below
      catch (e) { auSkip(r.phone, r.li); auStop(false); const dup = /already (been )?(answered|replied|claimed)|someone else/i.test(e.message || ''); const n = $('wiN'); if (n) n.innerHTML = dup ? '<div class="wi-note">Already answered by the bot or a colleague. Nothing was sent twice.</div>' : `<div class="wi-note r">${esc(e.message)}</div>`; return; }   // HTTP 409 from wq: a quiet note, not an error
      const b = $('wiAu'); if (b) { b.className = 'wi-au sent'; b.innerHTML = '<div class="hd"><b>✓✓ Sent</b></div>'; }
      const it = root.querySelector('.wi-it[data-p="' + r.phone + '"]'); if (it) it.classList.add('wi-out');
      AU.run = null; AU.follow = true;
      setTimeout(async () => { const x = $('wiAu'); if (x) { x.hidden = true; x.className = 'wi-au'; x.innerHTML = ''; } try { await refreshChat(true); await loadList(true); } catch (e) { } auNext(); }, 1000);
    }
    // the open chat is waiting for a reply → get its suggestion (once) and start the countdown
    function auArm() {
      if (!AU.on || !AU.allowed || AU.run || !S.cur || document.hidden) return;
      const c = S.cur.contact, lm = lastMsg(), li = lastIn(); if (!lm || lm.dir !== 'in' || !c.window_open || c.optout || !li || auSkipped(c.phone, li)) return;
      if ($('wiTx') && $('wiTx').value.trim()) return;
      if (c.hot) { auSkip(c.phone, li); const n = $('wiN'); if (n) n.innerHTML = '<div class="wi-note">🔥 Hot chat — the customer wants to pay, so a person must reply. Not auto-sent.</div>'; setTimeout(auNext, 4000); return; }
      const tk = c.phone + ':' + li, r = S.sug[li];
      if (!r) { if (AU.tried[tk] || S.sugBusy) return; AU.tried[tk] = true; maybeSuggest(true); return; }   // maybeSuggest calls auArm again when the suggestion is ready
      if (r.hidden) return;
      if (r.handoff || r.call_now) { auSkip(c.phone, li); const n = $('wiN'); if (n) n.innerHTML = '<div class="wi-note">🙋 This chat needs a person — not auto-sent. Moving on to the next one…</div>'; setTimeout(auNext, 5000); return; }
      auStart(r.suggestion, li);
    }
    // go to the next chat that is waiting for a reply
    function auNext() {
      if (!AU.on || !AU.allowed || AU.run || !AU.follow || document.hidden) return;
      if ($('wiTx') && $('wiTx').value.trim()) return;
      const cur = S.cur && auRow(S.cur.contact.phone);
      if (cur && cur.waiting && !auSkipped(cur.phone, lastIn())) { auArm(); return; }
      const nx = S.chats.find((c) => c.waiting && c.window_open && !c.optout && !auSkipped(c.phone, 0));
      if (nx) { if (nx.phone === S.sel && S.cur) auArm(); else openChat(nx.phone).then(() => auArm()); }
    }
    // ---------- 🧠 the bot's rules changed (Teach by talking): tell the person on the spot and write the open chat's suggestion again with the new rules
    let ruBox = null;
    function rulesCheck(rs) {
      if (!rs || !rs.at) return; let seen = ''; try { seen = localStorage.getItem('wa_rules_seen') || ''; } catch (e) { }
      const first = S.rulesAt === undefined, changed = rs.at !== S.rulesAt; S.rulesAt = rs.at;
      if (!changed || rs.at === seen || (first && rs.age > 86400)) return;
      const had = S.cur && S.sug[lastIn()]; S.sug = {};   // old suggestions were written with the old rules
      if (S.cur) { if (!admin || had) maybeSuggest(!admin ? false : true); else drawSug(); }
      if (ruBox) ruBox.remove(); ruBox = document.createElement('div'); ruBox.className = 'wi-rules';
      ruBox.innerHTML = `<div><b>🧠 The bot's rules were updated${rs.at ? ' at ' + esc(rs.at.slice(11, 16)) : ''}</b><div class="sm">${first ? 'New rules are active.' : 'Suggested replies are written again with them.'}</div>${(rs.lines || []).length ? '<ul>' + rs.lines.map((l) => '<li>' + esc(l) + '</li>').join('') + '</ul>' : ''}<button type="button" id="wiRuOk">OK</button></div>`;
      root.appendChild(ruBox);
      $('wiRuOk').onclick = () => { try { localStorage.setItem('wa_rules_seen', rs.at); } catch (e) { } if (ruBox) { ruBox.remove(); ruBox = null; } };
    }
    async function loadList(quiet) {
      if (opts.one) return;   // one chat only (side panel): no list to load
      if (S.busyList) return; S.busyList = true;
      try {
        const p = { scope: S.emp ? 'emp' : S.scope, q: S.q }; if (S.src) p.src = S.src; if (S.emp) p.emp = S.emp; if (!admin) p.mine = 1; if (S.emp && S.empTag) p.tag = 1; if (S.sort) p.sort = S.sort; if (S.flt.length) p.flt = S.flt.join(',');
        const d = await api.get('wa.chats', p); S.chats = d.chats || []; S.counts = d.counts || {};
        if (!admin) try { const g = await api.get('wq.grab_list'); S.grab = (g.items || []).filter((x) => !x.mine); S.grabMin = g.min || 4; } catch (e) { S.grab = []; }
        auCfg(d.rules && d.rules.auto); rulesCheck(d.rules); drawTabs(); drawFeeds(); drawKpi(); drawList(); if (opts.onCounts) { try { opts.onCounts(S.counts); } catch (e) { } } auNext();
      } catch (e) { if (!quiet) $('wiL').innerHTML = `<div class="wi-empty">${esc(e.message)}</div>`; }
      finally { S.busyList = false; }
    }
    let qt = null; $('wiQ').oninput = function () { clearTimeout(qt); const v = this.value; qt = setTimeout(() => { S.q = v.trim(); loadList(); }, 300); };
    if (admin) $('wiE').onchange = function () { S.emp = +this.value || 0; S.empTag = false; ebSync(); loadList(); };
    // 🔎 the person picker with a search box (the hidden select keeps the value)
    function ebSync() { const b = $('wiEb'), sl = $('wiE'); if (b && sl) b.textContent = ((sl.selectedOptions[0] && sl.selectedOptions[0].text) || 'Everyone') + ' ▾'; }
    function ebDraw() { const q = ($('wiEq').value || '').trim().toLowerCase(), sl = $('wiE'); $('wiEl').innerHTML = [...sl.options].filter((o) => !q || o.value === '0' || o.text.toLowerCase().includes(q)).map((o) => `<button type="button" data-ev="${esc(o.value)}"${o.value === sl.value ? ' class="on"' : ''}>${esc(o.text)}</button>`).join('') || '<small>Nobody with this name</small>'; }
    if (admin) {
      const P = $('wiEp');
      $('wiEb').onclick = (e) => { e.stopPropagation(); P.hidden = !P.hidden; if (!P.hidden) { $('wiEq').value = ''; ebDraw(); setTimeout(() => $('wiEq').focus(), 0); } };
      $('wiEq').oninput = ebDraw; $('wiEq').onkeydown = (e) => { if (e.key === 'Enter') { const f = $('wiEl').querySelector('[data-ev]:not([data-ev="0"])') || $('wiEl').querySelector('[data-ev]'); if (f) f.click(); } if (e.key === 'Escape') P.hidden = true; };
      $('wiEl').onclick = (e) => { const b = e.target.closest('[data-ev]'); if (!b) return; const sl = $('wiE'); sl.value = b.dataset.ev; P.hidden = true; sl.onchange(); };
      P.onclick = (e) => e.stopPropagation(); document.addEventListener('click', () => { P.hidden = true; });
    }
    // ⏷ Filter: the order of the list + show only … (like Quick Engage), applied together
    const SORTS = [['', 'Waiting first (default)'], ['new', 'Newest first'], ['old', 'Oldest first'], ['az', 'Name A → Z'], ['za', 'Name Z → A']];
    const FLTS = [['noreply', 'Not responded — no salesperson reply'], ['late', 'Waiting too long'], ['unassigned', 'Unassigned'], ['hot', '🔥 Hot — ready to buy'], ['complaint', 'Complaint'], ['today', '📅 Today’s chats'], ['yesterday', '🗓 Yesterday’s chats']];
    function flLabel() { const b = $('wiFb'); if (!b) return; const n = S.flt.length + (S.sort ? 1 : 0); b.textContent = '⏷ Filter' + (n ? ' · ' + n : ''); b.classList.toggle('on', n > 0); }
    function flDraw() {
      $('wiFp').innerHTML = '<b class="wi-flh">Sort by</b>' + SORTS.map(([k, l]) => `<label class="wi-flo"><input type="radio" name="wiFs" value="${k}"${S.sort === k ? ' checked' : ''}> ${l}</label>`).join('')
        + '<b class="wi-flh">Show only</b>' + FLTS.map(([k, l]) => `<label class="wi-flo"><input type="checkbox" value="${k}"${S.flt.includes(k) ? ' checked' : ''}> ${l}${k === 'noreply' && S.counts && S.counts.noresp != null ? ' <b style="margin-left:auto;color:#dc2626">' + S.counts.noresp + '</b>' : ''}</label>`).join('')
        + '<div class="wi-fla"><button type="button" data-fl="reset">Reset</button><button type="button" data-fl="go" class="pri">Apply</button></div>';
    }
    if (admin) {
      const FP = $('wiFp');
      $('wiFb').onclick = (e) => { e.stopPropagation(); FP.hidden = !FP.hidden; if (!FP.hidden) flDraw(); };
      FP.onclick = (e) => { e.stopPropagation(); const b = e.target.closest('[data-fl]'); if (!b) return;
        if (b.dataset.fl === 'reset') { S.sort = ''; S.flt = []; } else { const r = FP.querySelector('input[name="wiFs"]:checked'); S.sort = r ? r.value : ''; S.flt = [...FP.querySelectorAll('input[type=checkbox]:checked')].map((x) => x.value); }
        FP.hidden = true; flLabel(); loadList(); };
      document.addEventListener('click', () => { FP.hidden = true; });
    }

    // ---------- one chat
    const MEDIA = {};   // media id → blob url (the token must travel in a header, so photos are fetched, not linked)
    async function streamUrl(id) { const d = await api.get('wa.media_link', { id }); return api.buildUrl('wa.mfile', { id: d.id, e: d.e, s: d.s }); }   // ⚡ a signed link the browser streams (videos start at once, seek works)
    async function mediaUrl(id) {
      if (MEDIA[id]) return MEDIA[id];
      if (/^prod:/.test(id)) { await new Promise((ok) => prodReady(ok)); const u = window.WaProd ? await WaProd.imgFor(id) : ''; if (!u) throw new Error('Could not load the photo.'); return (MEDIA[id] = u); }   // a product photo sent from "Find a perfume": it lives on the website
      const res = await fetch(api.buildUrl('wa.media', { id }), { headers: { Authorization: 'Bearer ' + api.token() } });
      if (!res.ok) { let m = 'Could not load the file.'; try { m = (await res.json()).error || m; } catch (e) { } throw new Error(m); }
      return (MEDIA[id] = URL.createObjectURL(await res.blob()));
    }
    function tick(m) {
      if (m.dir !== 'out') return ''; const s = m.status;
      const failWhy = (m) => { const c = +m.err_code || 0, t = String(m.err || '').slice(0, 90); const h = { 131053: 'WhatsApp could not use this file (format / size)', 131052: 'WhatsApp could not download this file', 131047: 'the customer has not written in 24 hours', 131026: 'the customer cannot receive it (not on WhatsApp / blocked)', 131049: 'Meta limit for this customer', 130429: 'sending too fast, wait a minute', 131051: 'unsupported message type' }[c]; return (h || t) ? ' — ' + (h || t) + (c ? ' (' + c + ')' : '') : ''; };
      return s === 'failed' ? ` <span class="wi-tick f">⚠ failed${esc(failWhy(m))}</span>` : s === 'read' ? ' <span class="wi-tick r">✓✓</span>' : s === 'delivered' ? ' <span class="wi-tick">✓✓</span>' : ' <span class="wi-tick">✓</span>';
    }
    const capOf = (m) => { const x = /^[^:]{1,20}:\s*([\s\S]+)$/.exec(m.body || ''); return x ? x[1] : ''; };
    function bubble(m) {
      if (m.dir === 'sys') { const d0 = parse(m.ts), t0 = d0 ? d0.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }) : ''; return `<div class="wi-ev">${esc(m.body || '')}${t0 ? ' · <b>' + t0 + '</b>' : ''}</div>`; }   // 🕒 who took / moved the chat AND at what time
      const cls = m.dir === 'in' ? '' : (m.who === 'bot' ? 'out bot' : 'out');
      let body = ''; const cap = (m.mtype === 'image' || m.mtype === 'video') ? capOf(m) : '';
      if (m.media_id && (m.mtype === 'image' || m.mtype === 'sticker')) body = (MEDIA[m.media_id] ? `<img src="${MEDIA[m.media_id]}" alt="photo">` : `<div class="ld" data-m="${esc(m.media_id)}" data-k="img">📷 Tap to open photo</div>`) + (cap ? `<div class="wi-cap">${linkify(esc(cap))}</div>` : '');
      else if (m.media_id && m.mtype === 'audio') body = `<div class="ld" data-m="${esc(m.media_id)}" data-k="aud">🎤 Tap to play voice note</div>`;
      else if (m.media_id && m.mtype === 'video') body = (MEDIA[m.media_id] ? `<video controls preload="metadata" src="${MEDIA[m.media_id]}"></video>` : `<div class="ld" data-m="${esc(m.media_id)}" data-k="vid">🎬 Tap to play video</div>`) + (cap ? `<div class="wi-cap">${linkify(esc(cap))}</div>` : '');
      else if (m.media_id) { const fn = capOf(m) || 'Document'; body = `<div class="ld" data-m="${esc(m.media_id)}" data-k="doc" data-n="${esc(fn)}">📄 ${esc(fn)} — tap to download</div>`; }
      else body = linkify(esc(m.body || ''));
      const d = parse(m.ts); const who = m.dir === 'out' ? (m.who === 'bot' ? 'Bot' : m.who === 'campaign' ? 'Campaign' : m.who === 'followup' ? 'Follow-up' : m.who === 'phone' ? '📱 Phone app' : (m.emp_name || 'Team')) : '';
      const tg = m.mtype === 'template' ? '<span class="tg">📋 Pre-made message</span>' : '';
      const qt = m.quote ? `<div class="wi-qt" data-qid="${m.quote.id}" title="Go to that message"><b>${m.quote.dir === 'in' ? 'Customer' : 'You'}</b><span>${esc(m.quote.text)}</span></div>` : '';   // ↩ the customer swiped on this earlier message
      const rb = '<button type="button" class="wi-rb" data-rb="' + m.id + '" title="Reply to this message" aria-label="Reply">↩</button>';
      return `<div class="wi-b ${cls}${m.mtype === 'template' ? ' tpl' : ''}" data-id="${m.id}">${rb}${tg}${qt}${body}<small>${who ? esc(who) + ' · ' : ''}${esc(m.number || '')} · ${d ? hhmm(d) : ''}${tick(m)}</small></div>`;
    }
    // ↩ press ↩ on a message → the reply bar above the box; the next message you send answers it (the customer sees it quoted, like WhatsApp)
    document.addEventListener('click', (e) => { const b = e.target.closest('.wi-rb'); if (!b || !S.cur) return; const m = (S.cur.messages || S.cur.msgs || []).find((x) => String(x.id) === b.dataset.rb); const el = b.closest('.wi-b');
      const txt = m ? (String(m.body || '').trim() || ({ image: '📷 Photo', video: '🎥 Video', audio: '🎤 Voice note', document: '📄 Document' }[m.mtype] || 'Message')) : (el ? el.innerText.replace('↩', '').trim() : 'Message');
      S.rp = { id: +b.dataset.rb, dir: m ? m.dir : (el && el.classList.contains('out') ? 'out' : 'in'), text: txt.slice(0, 160), phone: S.cur.contact && S.cur.contact.phone }; wiRpDraw(); const t = $('wiTx'); if (t) t.focus(); });
    document.addEventListener('dblclick', (e) => { const el = e.target.closest('.wi-b[data-id]'); if (!el || !S.cur || e.target.closest('a,button,img,video,audio,.ld,.wi-qt')) return; const rb = el.querySelector('.wi-rb'); if (!rb) return; const sel = window.getSelection && window.getSelection(); if (sel) sel.removeAllRanges(); rb.click(); });   // double-click a message = reply to it
    function wiRpDraw() { let bar = $('wiRpB'); const comp = document.querySelector('.wi-comp'); if (!S.rp || !comp || !S.cur || !S.cur.contact || S.rp.phone !== S.cur.contact.phone) { if (bar) bar.remove(); if (S.rp && S.cur && S.cur.contact && S.rp.phone !== S.cur.contact.phone) S.rp = null; return; }
      if (!bar) { bar = document.createElement('div'); bar.id = 'wiRpB'; bar.className = 'wi-rpb'; comp.parentNode.insertBefore(bar, comp); }
      bar.innerHTML = '<div class="wi-qt" style="flex:1;margin:0;cursor:default"><b>' + (S.rp.dir === 'in' ? 'Customer' : 'You') + '</b><span>' + esc(S.rp.text) + '</span></div><button type="button" id="wiRpX" aria-label="Cancel reply">✕</button>'; $('wiRpX').onclick = () => { S.rp = null; wiRpDraw(); }; }
    // ↩ tap the quoted message → jump to it and flash it
    document.addEventListener('click', (e) => { const q = e.target.closest('.wi-qt'); if (!q) return; const t = document.querySelector('.wi-b[data-id="' + q.dataset.qid + '"]');
      const go = (el) => { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.classList.remove('wi-fl'); void el.offsetWidth; el.classList.add('wi-fl'); };
      if (t) { go(t); return; }
      (async () => {   // the quoted message is older than what is loaded: load older pages until it appears
        for (let i = 0; i < 40 && S.moreOld; i++) { await loadOlder(); const f = document.querySelector('.wi-b[data-id="' + q.dataset.qid + '"]'); if (f) { go(f); return; } }
        alert('That message could not be found in this chat.');
      })(); });
    function drawMsgs(keepScroll) {
      const box = $('wiM'); if (!box || !S.cur) return;
      const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80; let last = '', h = '', lastNum = '';
      const seen = new Set(), all = (S.older || []).concat(S.cur.messages).filter((m) => !seen.has(m.id) && seen.add(m.id));   // older pages loaded by scrolling up + the newest page
      if (S.moreOld) h += '<div class="wi-ev" id="wiOld" style="cursor:pointer">⬆ Load older messages</div>';
      all.forEach((m) => {
        const d = parse(m.ts); const k = d ? dayLabel(d) : ''; if (k && k !== last) { h += `<div class="wi-day">${esc(k)}</div>`; last = k; }
        if (m.dir !== 'sys' && m.number) { if (lastNum && m.number !== lastNum) h += `<div class="wi-ev">📱 Continued on ${esc(m.number)}</div>`; lastNum = m.number; }   // one customer, several of our numbers: show the switch
        h += bubble(m);
      });
      box.innerHTML = h || '<div class="wi-empty" style="margin:auto">No messages yet.</div>';
      if (!keepScroll || atBottom) box.scrollTop = box.scrollHeight;
      const ob = $('wiOld'); if (ob) ob.onclick = loadOlder;
      box.onscroll = () => { if (box.scrollTop < 60 && S.moreOld && !S.oldBusy) loadOlder(); };
      box.querySelectorAll('.wi-b img').forEach((im) => { im.onclick = () => lightbox(im.src); });
      box.querySelectorAll('.ld').forEach((el) => {
        el.onclick = async () => {
          const id = el.dataset.m, k = el.dataset.k; el.textContent = 'Loading…';
          try {
            const u = (k === 'vid' || k === 'doc') && !/^prod:/.test(id) && !MEDIA[id] ? await streamUrl(id) : await mediaUrl(id);   // ⚡ videos / documents stream from our server
            if (k === 'img') { el.outerHTML = `<img src="${u}" alt="photo">`; box.querySelectorAll('img').forEach((im) => { im.onclick = () => lightbox(im.src); }); }
            else if (k === 'aud') el.outerHTML = `<audio controls autoplay src="${u}"></audio>`;
            else if (k === 'vid') { MEDIA[id] = u; el.outerHTML = `<video controls autoplay playsinline preload="auto" src="${u}"></video>`; }
            else { const w = window.open(u, '_blank'); if (!w) { const a = document.createElement('a'); a.href = u; a.download = el.dataset.n || 'whatsapp-file'; document.body.appendChild(a); a.click(); a.remove(); } el.textContent = '📄 ' + (el.dataset.n || 'Document') + ' — opened'; }
          } catch (e) { el.textContent = '⚠ ' + e.message; }
        };
      });
      box.querySelectorAll('.ld[data-m^="prod:"]').forEach((el) => el.click());   // product photos show at once
    }
    // ⬆ older messages: the page opens with the newest 100; scrolling to the top (or tapping the bar) loads the 100 before them, again and again until the first message
    async function loadOlder() {
      if (!S.cur || !S.moreOld) return; while (S.oldBusy) await new Promise((z) => setTimeout(z, 80)); const box = $('wiM'), ph = S.sel; S.oldBusy = true;
      try {
        const ids = (S.older || []).concat(S.cur.messages).map((m) => m.id), d = await api.get('wa.chat', { phone: ph, before: ((S.older && S.older.length ? S.older : S.cur.messages)[0] || {}).id || Math.min.apply(null, ids), limit: 100 });
        if (S.sel !== ph || !S.cur) return;
        S.older = d.messages.concat(S.older || []); S.moreOld = !!d.more;
        const h0 = box ? box.scrollHeight : 0, t0 = box ? box.scrollTop : 0; drawMsgs(true); if (box) box.scrollTop = box.scrollHeight - h0 + t0;   // stay where you were reading
      } catch (e) { } finally { S.oldBusy = false; }
    }

    // 🖼 tap a photo → it opens full screen (phone and PC): pinch to zoom, tap anywhere or ✕ to close
    function photoOpen(src) {
      if (document.querySelector('.ph-lb')) return;   // one viewer at a time (a photo can be tapped through two handlers)
      if (!document.getElementById('phStyle')) { const st = document.createElement('style'); st.id = 'phStyle'; st.textContent = '.ph-lb{position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:99999;display:flex;align-items:center;justify-content:center;touch-action:pinch-zoom;overscroll-behavior:contain}.ph-lb img{max-width:100vw;max-height:100vh;width:auto;height:auto;object-fit:contain;touch-action:pinch-zoom}.ph-lb .ph-x{position:fixed;top:max(12px,env(safe-area-inset-top));right:14px;width:44px;height:44px;border-radius:50%;border:0;background:rgba(255,255,255,.18);color:#fff;font-size:22px;line-height:1;cursor:pointer}.ph-lb .ph-o{position:fixed;bottom:max(14px,env(safe-area-inset-bottom));left:50%;transform:translateX(-50%);background:rgba(255,255,255,.18);color:#fff;padding:10px 16px;border-radius:99px;font:600 14px system-ui,sans-serif;text-decoration:none}'; document.head.appendChild(st); }
      const d = document.createElement('div'); d.className = 'ph-lb'; d.innerHTML = '<img alt="photo"><button type="button" class="ph-x" aria-label="Close">✕</button><a class="ph-o" target="_blank" rel="noopener">Open original</a>';
      d.querySelector('img').src = src; d.querySelector('.ph-o').href = src; const close = () => { d.remove(); document.removeEventListener('keydown', esc2); }; const esc2 = (e) => { if (e.key === 'Escape') close(); };
      d.addEventListener('click', (e) => { if (e.target.closest('.ph-o')) return; close(); }); document.addEventListener('keydown', esc2); document.body.appendChild(d);
    }
    function lightbox(src) { photoOpen(src); }
    document.addEventListener('click', (e) => { const im = e.target.closest('#wiM .wi-b img'); if (im && !e.target.closest('.wi-qt,a')) { e.preventDefault(); photoOpen(im.currentSrc || im.src); } });

    async function cxBan(ph) { if (!window.CXR) return; const st = await CXR.state(ph); if (!S.cur || S.cur.contact.phone !== ph) return; const el = $('wiCxB'); if (el) el.innerHTML = CXR.bannerHtml(st); }
    const meId = () => { try { return +((api.currentUser && api.currentUser()) || {}).employee_id || 0; } catch (e) { return 0; } };
    // 👤 who has this chat — next to the customer's name. Admin: assign to anyone (search every employee). Salesperson: transfer their own chat, or take an unassigned one.
    function asgHtml(c) {
      const me = meId(), mine = c.assigned_emp && c.assigned_emp === me, who = c.assigned_emp ? (mine ? 'You' : (c.assigned_name || 'someone')) : '';
      if (admin) return `<button type="button" class="wi-asg${c.assigned_emp ? '' : ' un'}" id="wiAsg" aria-haspopup="dialog" title="Assign or move this chat">👤 <span>${c.assigned_emp ? 'With ' + esc(who) : 'Unassigned · Assign'}</span> ▾</button>`;
      if (c.status === 'closed') return c.assigned_emp ? `<span class="wi-asg ro">👤 <span>With ${esc(who)}</span></span>` : '';
      if (!c.assigned_emp) return `<button type="button" class="wi-asg un" id="wiAsg" aria-haspopup="dialog">✋ <span>Unassigned · Take it</span></button>`;
      if (mine) return `<button type="button" class="wi-asg" id="wiAsg" aria-haspopup="dialog" title="Hand this chat to a colleague">👤 <span>You · Transfer</span> ▾</button>`;
      return `<span class="wi-asg ro">👤 <span>With ${esc(who)}</span></span>`;
    }
    function asgClose(viaBack) { const p = $('wiAsp'); if (!p) return; p.remove(); if (!viaBack) layerDone('asg', false); }
    async function openAssign() {
      const c = S.cur && S.cur.contact; if (!c) return; asgClose(); const me = meId(), free = !c.assigned_emp;
      const p = document.createElement('div'); p.id = 'wiAsp'; p.className = 'wi-asp';
      const quick = admin ? ((me && c.assigned_emp !== me ? '<button type="button" class="wi-btn p" data-q="me">✋ Assign to me</button>' : '') + (c.assigned_emp ? '<button type="button" class="wi-btn" data-q="none">Unassign</button>' : ''))
        : (free ? '<button type="button" class="wi-btn p" data-q="me" style="min-height:44px">✋ Take this chat — I will reply</button>' : '');
      const list = admin || !free;
      p.innerHTML = `<div class="wi-aspb" role="dialog" aria-label="Assign this chat"><div class="wi-asph"><b>${admin ? 'Assign this chat' : free ? 'Take this chat' : 'Transfer to a colleague'}</b><button type="button" class="wi-shx" aria-label="Close">✕</button></div>
        <div class="wi-aspn">${esc(title(c))} · now with <b>${c.assigned_emp ? esc(c.assigned_emp === me ? 'you' : (c.assigned_name || 'someone')) : 'nobody'}</b></div>
        ${quick ? `<div class="wi-aspq">${quick}</div>` : ''}${list ? `<input type="search" id="wiAsQ" placeholder="${admin ? 'Search any employee…' : 'Search a colleague…'}" autocomplete="off"><div class="wi-aspl" id="wiAsL"><div class="wi-asx">Loading…</div></div>` : ''}<div id="wiAsE2"></div></div>`;
      document.body.appendChild(p); layerOpen('asg', () => asgClose(true));
      const b = $('wiAsg'), box = p.firstElementChild;
      if (b && window.innerWidth > 760) { const r = b.getBoundingClientRect(); box.style.top = Math.min(r.bottom + 6, window.innerHeight - 200) + 'px'; box.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 348)) + 'px'; }
      p.onclick = (e) => { if (e.target === p) asgClose(); }; p.querySelector('.wi-shx').onclick = () => asgClose();
      p.onkeydown = (e) => { if (e.key === 'Escape') asgClose(); };
      const err = (m) => { const x = $('wiAsE2'); if (x) x.innerHTML = `<div class="wi-err">${esc(m)}</div>`; };
      const done = async (msg, gone) => {
        asgClose(); tellParent({ wa: 'assigned', phone: c.phone, msg });
        if (gone) { layerDone('chat', false); S.sel = ''; S.cur = null; root.classList.remove('open', 'det'); $('wiC').innerHTML = '<div class="wi-empty" style="margin:auto">' + esc(msg) + '</div>'; $('wiS').innerHTML = ''; loadList(true); return; }
        await refreshChat(); await loadList(true); const n = $('wiN'); if (n) { n.innerHTML = '<div class="wi-note" style="background:#e3f6ec;color:#12794a">' + esc(msg) + '</div>'; setTimeout(() => { const x = $('wiN'); if (x && x.textContent === msg) x.innerHTML = ''; }, 3500); }
      };
      const act = async (emp, name, el) => {
        if (el) el.disabled = true;
        try {
          if (admin || emp === me && free) { await api.post('wa.assign', { phone: c.phone, emp_id: emp }); await done(emp ? (emp === me ? 'The chat is yours now.' : 'Assigned to ' + name + '.') : 'The chat is unassigned now.', false); }
          else { await api.post('wa.transfer', { phone: c.phone, emp_id: emp }); await done('Transferred. The chat moved to ' + name + '.', true); }
        } catch (e) { err(e.message); if (el) el.disabled = false; }
      };
      p.querySelectorAll('[data-q]').forEach((q) => { q.onclick = () => act(q.dataset.q === 'me' ? me : 0, 'you', q); });
      if (!list) return;
      let people = [];
      try { people = admin ? ((await api.get('wa.people', { phone: c.phone, all: 1 })).people || []) : ((await api.get('wa.people')).people || []); } catch (e) { const l = $('wiAsL'); if (l) l.innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; return; }
      if (!p.isConnected) return; if (!admin) S.people = people;
      const draw = () => {
        const l = $('wiAsL'); if (!l) return; const q = ($('wiAsQ').value || '').toLowerCase().trim().split(/\s+/).filter(Boolean);
        let h = '', other = false;
        people.filter((x) => q.every((w) => x.name.toLowerCase().includes(w))).forEach((x) => {
          if (x.other && !other) { other = true; h += '<div class="wi-asx">Other teams</div>'; }
          const cur = x.emp_id === c.assigned_emp, st = [x.present ? 'Online' : 'Offline · not punched in'];
          if (x.open != null) st.push(x.open + ' open'); if (x.full) st.push('full'); if (x.best) st.push('⭐ best now'); if (cur) st.push('has it now');
          h += `<button type="button" class="wi-asr${x.present ? ' on' : ''}" data-id="${x.emp_id}" ${cur ? 'disabled' : ''}><i></i><b>${esc(x.name)}${x.emp_id === me ? ' (you)' : ''}</b><small>${esc(st.join(' · '))}</small></button>`;
        });
        l.innerHTML = h || '<div class="wi-asx">Nobody matches.</div>';
      };
      draw(); const qi = $('wiAsQ'); qi.oninput = draw; if (window.innerWidth > 760) qi.focus();
      $('wiAsL').onclick = (e) => {
        const r = e.target.closest('[data-id]'); if (!r || r.disabled) return; const x = people.find((y) => y.emp_id === +r.dataset.id); if (!x) return;
        const warn = !x.present ? '\n' + x.name + ' is offline (not punched in) right now.' : x.full ? '\n' + x.name + ' already has ' + (x.open != null ? x.open + ' ' : 'many ') + 'open chats.' : '';
        if (!confirm((admin ? 'Assign this chat to ' : 'Transfer this chat to ') + x.name + '?' + warn)) return; act(x.emp_id, x.name, r);
      };
    }
    function drawHead() {
      const c = S.cur.contact;
      $('wiH').innerHTML = `<button type="button" class="wi-back" id="wiBack" aria-label="Back to chats">‹<span class="lb"> Chats</span></button>${av(c)}<b>${esc(title(c))}</b>${c.name ? `<span class="ph" style="color:#6b7684">${esc(pretty(c.phone))}</span>` : ''}${asgHtml(c)}${pills(Object.assign({}, c, { assigned_name: '' }))}<span class="wi-fu-p" id="wiFuP"></span><span class="wi-os" id="wiOs"></span><span class="sp"></span>${admin && c.status !== 'closed' && c.assigned_emp !== meId() ? `<button type="button" class="wi-btn wi-ib" id="wiTo" title="Take this chat into your own panel" style="background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;font-weight:800">🔄<span class="lb"> Take over</span></button>` : ''}<button type="button" class="wi-btn wi-ib" id="wiPrc" title="Send the price photo">💰<span class="lb"> Price</span></button><button type="button" class="wi-btn wi-ib" id="wiCartH" title="Make a cart with a chain (same as the salesperson app) — 🩺 Diagnose is inside">🛒<span class="lb"> Cart</span></button><button type="button" class="wi-btn wi-ib wi-pc" id="wiMoH" title="Manual order: the customer paid by QR / UPI (or pays on delivery) — no website">🧾<span class="lb"> Manual order</span></button>
        <button type="button" class="wi-btn wi-ib wi-pc" id="wiCx" title="Mark as complaint" style="background:#f3efff;color:#6d4fc2">🎫<span class="lb"> Complaint</span></button>${c.status === 'closed' ? '<button type="button" class="wi-btn wi-ib" id="wiRe" title="Open this chat again">↺<span class="lb"> Re-open</span></button>' : '<button type="button" class="wi-btn wi-ib" id="wiCl" title="Resolve (finish) this chat">✓<span class="lb"> Resolve</span></button>'}<button type="button" class="wi-more wi-ib wi-pc" id="wiMore" aria-label="Details">ⓘ<span class="lb"> Details</span></button><button type="button" class="wi-more wi-ib wi-ph" id="wiMo2" aria-label="More">⋯<span class="lb"> More</span></button><div id="wiCxB" style="flex-basis:100%"></div>`;
      const cx = () => { if (!window.CXR) { alert('Complaints are not installed yet. Refresh the page.'); return; } CXR.open({ phone: c.phone, name: c.name || '', source: 'whatsapp', onDone: () => cxBan(c.phone) }); };
      $('wiCx').onclick = cx; if ($('wiCartH')) $('wiCartH').onclick = () => openCart(c); if ($('wiMoH')) $('wiMoH').onclick = () => openManual(c);   // 🛒 the chain cart, one tap from the chat (also in Details)
      cxBan(c.phone); orderPill(c);
      if ($('wiAsg')) $('wiAsg').onclick = openAssign;
      if ($('wiTo')) $('wiTo').onclick = async function () {   // 🔄 take this chat into MY panel (whoever holds it now; they get a note at the top of their WhatsApp)
        this.disabled = true; this.textContent = 'Taking over…';
        try { const r = await api.post('wa.takeover', { phone: c.phone }); await refreshChat(true); await loadList(true); const n = $('wiN'); if (n) n.innerHTML = '<div class="wi-note">✅ The chat is yours now' + (r && r.from ? ' (taken from ' + esc(r.from) + ')' : '') + '. Reply below.</div>'; }
        catch (e) { alert(e.message); this.disabled = false; this.textContent = '🔄 Take over'; }
      };
      $('wiPrc').onclick = async function () { if (!confirm('Send the price photo to this customer?')) return; this.disabled = true; const say = (m, bad) => { const n = $('wiN'); if (n) n.innerHTML = '<div class="wi-note' + (bad ? ' r' : '') + '">' + esc(m) + '</div>'; else alert(m); };
        try { const r = await api.post('wa.price_photo', { phone: S.cur.contact.phone }); say(r.message || 'Price photo sent.'); } catch (e) { say(e.message, true); } this.disabled = false; };
      $('wiBack').onclick = () => { if (opts.one) { tellParent({ wa: 'close' }); return; } if (!layerDone('chat', true)) backChat(); };
      const det = () => { root.classList.add('det'); layerOpen('det', () => root.classList.remove('det')); };
      $('wiMore').onclick = det;
      $('wiMo2').onclick = () => {   // phone: the less-used actions, each with words
        const b0 = sheet('More for this chat'); const it = (k, ic, t, d) => `<button type="button" class="wi-mi" data-k="${k}"><span>${ic}</span><b>${t}<small>${d}</small></b></button>`;
        b0.innerHTML = it('det', 'ⓘ', 'Details', 'Cart, journey, tags, summary, profile') + it('cx', '⚠️', 'Mark as complaint', 'Send this chat to the Complaint team');
        b0.onclick = (e) => {
          const x = e.target.closest('[data-k]'); if (!x) return;
          if (x.dataset.k === 'cx') { closeSheet(); cx(); return; }
          closeSheet(true); const L = LY.st.find((y) => y.n === 'sheet'); root.classList.add('det');   // Details takes over the sheet's Back step
          if (L) { L.n = 'det'; L.f = () => root.classList.remove('det'); } else det();
        };
      };
      const b = $('wiCl') || $('wiRe');
      b.onclick = async () => { b.disabled = true; try { await api.post(c.status === 'closed' ? 'wa.reopen' : 'wa.close', { phone: c.phone }); tellParent({ wa: 'changed', phone: c.phone }); await refreshChat(); await loadList(true); if (c.status !== 'closed' && !admin) { layerDone('chat', false); root.classList.remove('open'); S.sel = ''; S.cur = null; $('wiC').innerHTML = '<div class="wi-empty" style="margin:auto">Chat closed. It re-opens by itself if the customer writes again.</div>'; $('wiS').innerHTML = ''; } } catch (e) { alert(e.message); b.disabled = false; } };
    }
    function backChat() { asgClose(true); closeSheet(true); root.classList.remove('open', 'det'); S.sel = ''; S.cur = null; drawList(); }
    // Phone Back: closes the top-most thing first (assign list → sheet → Details → the chat itself), like WhatsApp. Each one owns one history entry.
    const LY = { st: [], skip: 0 };
    function layerOpen(n, f) {
      if (n === 'chat' && (opts.one || !window.matchMedia('(max-width:760px)').matches)) return;   // PC: the list and the chat sit side by side, nothing to go back to
      if (n === 'det' && !window.matchMedia('(max-width:1100px)').matches) return;
      const o = LY.st.find((x) => x.n === n); if (o) { o.f = f; return; }
      LY.st.push({ n, f }); try { history.pushState(Object.assign({}, history.state || {}, { wiL: n }), ''); } catch (e) { }
    }
    function layerDone(n, run) {   // closed by its own button: drop its history entry (and those of anything opened on top of it)
      const i = LY.st.findIndex((x) => x.n === n); if (i < 0) return false; const gone = LY.st.splice(i);
      if (run) gone.reverse().forEach((x) => { try { x.f(); } catch (e) { } });
      LY.skip++; try { history.go(-gone.length); } catch (e) { LY.skip--; } return true;
    }
    window.addEventListener('popstate', () => { if (LY.skip) { LY.skip--; return; } const t = LY.st.pop(); if (t) t.f(); });
    function drawSide() {
      const c = S.cur.contact; const W = $('wiS');
      let h = '<button type="button" class="wi-back wi-x" id="wiX" style="margin-bottom:10px">‹ Back to chat</button>';
      h += '<h4>Customer</h4><div class="wi-sb wi-cu" id="wiCu"><span style="color:#8a94a3">Reading this customer…</span></div>';
      h += '<h4>Chain cart</h4><div class="wi-sb"><button type="button" class="wi-btn" id="wiCart" style="min-height:44px">🛒 Make cart</button><div id="wiCtS" style="margin-top:8px"></div></div>';
      h += '<h4>Journey</h4><div id="wiJr"></div>';
      h += '<h4>Query type and tags</h4><div class="wi-sb" id="wiQt"></div>';
      h += `<h4>Summary</h4><div class="wi-sb" id="wiSum">${c.summary ? esc(c.summary) : '<span style="color:#8a94a3">Not written yet.</span>'}</div><div class="wi-act"><button type="button" class="wi-btn" id="wiTag">${c.summary ? 'Re-read with AI' : 'Summarise with AI'}</button></div><div id="wiTagE"></div>`;
      // (the Assign / Move chat block and the Transfer button are gone from here: the ONE place to assign or transfer is the 👤 button in the chat header)
      const lastA = parse(c.last_at); const first = parse(c.first_seen);
      h += `<h4>Chat profile</h4><div class="wi-sb">
        <div class="wi-kv"><span>Status</span><b>${c.status === 'closed' ? 'Resolved' : (c.assigned_emp ? 'With ' + esc(c.assigned_name) : (c.state === 'bot' ? 'Bot / waiting' : 'Unassigned'))}</b></div>
        <div class="wi-kv"><span>Phone</span><b>${esc(pretty(c.phone))}</b></div>
        <div class="wi-kv"><span>Last active</span><b>${lastA ? esc(lastA.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) + ', ' + hhmm(lastA)) : '—'}</b></div>
        <div class="wi-kv"><span>First seen</span><b>${first ? esc(first.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })) : '—'}</b></div>
        <div class="wi-kv"><span>Session messages</span><b>${c.session_msgs || 0}</b></div>
        <div class="wi-kv"><span>Template messages</span><b>${c.template_msgs || 0}</b></div>
        <div class="wi-kv"><span>Source</span><b>${c.source === 'query' ? 'Meta ad' : (SRC[c.source] ? SRC[c.source][0] : 'Organic')}</b></div>
        ${c.ref_text ? `<div class="wi-kv"><span>Ad</span><b>${esc(c.ref_text)}</b></div>` : ''}
        <div class="wi-kv"><span>First message</span><b>${esc(c.first_message || '—')}</b></div>
        <div class="wi-kv"><span>Numbers used</span><b>${c.numbers_used || 1}</b></div>
        <div class="wi-kv"><span>Replies go from</span><b>${esc(c.last_number || '—')}</b></div>
        <div class="wi-kv"><span>WhatsApp window</span><b>${c.optout ? 'Opted out' : c.window_open ? 'Active · ' + Math.floor(c.window_left_min / 60) + ' h ' + (c.window_left_min % 60) + ' min left' : 'Inactive'}</b></div>
        <div class="wi-kv"><span>Opted in</span><label class="wi-sw"><input type="checkbox" id="wiOpt" ${c.optout ? '' : 'checked'}><span></span></label></div></div>`;
      if (admin && S.cur.assign_log && S.cur.assign_log.length) h += '<h4>History — who had this chat</h4><div class="wi-sb" style="font-size:12.5px">' + S.cur.assign_log.map((l) => { const d = parse(l.at); return `<div class="wi-kv"><span>${d ? esc(d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) + ' ' + hhmm(d)) : ''}</span><b>${esc(l.to || 'Unassigned')}${({ auto: ' · auto', transfer: ' · handed over' + (l.from ? ' by ' + l.from : ''), grab: ' · took a late reply', takeover: ' · took over' + (l.from ? ' from ' + l.from : ''), take: ' · took it', manual: l.by ? ' · by ' + l.by : '' })[l.how] || ''}</b></div>`; }).join('') + '</div>';
      h += '<div id="wiFuS"></div>';
      W.innerHTML = h; drawFuSide();
      loadCust(c.phone);
      segReady(() => { const el = $('wiJr'); if (el && window.WaSeg) WaSeg.journey(el, { phone: c.phone }); });
      $('wiCart').onclick = () => openCart(c); drawCartChip(c);
      qReady(() => { const el = $('wiQt'); if (el && window.WaQ) WaQ.tags(el, { noTransfer: true, noHistory: true, phone: c.phone, name: c.name || '', api, onTransferred: () => { refreshChat().then(() => loadList(true)); } }); else if (el) el.parentNode.removeChild(el.previousElementSibling), el.remove(); });
      $('wiX').onclick = () => { layerDone('det', false); root.classList.remove('det'); };
      $('wiTag').onclick = () => runTag(true);
      $('wiOpt').onchange = async function () { const on = this.checked; if (!on && !confirm('Stop all messages to this customer?')) { this.checked = true; return; } try { await api.post('wa.optin', { phone: c.phone, opted_in: on }); await refreshChat(); loadList(true); } catch (e) { alert(e.message); this.checked = !on; } };
    }
    // ---------- 👤 the customer card of this chat: lifetime orders, spend and flags (customer_facts: the same numbers as Dispatch -> Customers)
    const CU = { orders: null };
    const inr = (n) => '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
    const dmy = (t) => { const d = parse(t); return d ? d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: '2-digit' }) : '—'; };
    async function loadCust(phone, withOrders) {
      let r; try { r = await api.get('wa.cs_card', withOrders ? { phone, orders: 1 } : { phone }); } catch (e) { const el = $('wiCu'); if (el) el.style.display = 'none'; if (el && el.previousElementSibling) el.previousElementSibling.style.display = 'none'; return; }
      const el = $('wiCu'); if (!el || !S.cur || S.cur.contact.phone !== phone) return;
      const c = r.customer; if (!c || r.none || (!c.orders_n && !c.cx_n)) { el.innerHTML = '<div class="wi-cu-new"><b>New customer</b><span>No order found for this number yet.</span></div>'; return; }
      const ch = c.channel === 'both' ? 'Store + Online' : c.channel === 'store' ? 'Store' : c.channel === 'online' ? 'Online' : '';
      let h = '<div class="wi-cu-top"><div><b>' + esc(c.name || 'Customer') + '</b>' + (ch ? '<span class="wi-cu-ch">' + ch + '</span>' : '') + '</div></div>';
      h += '<div class="wi-cu-big"><div><small>Orders</small><b>' + c.orders_n + '</b></div><div><small>Spent</small><b>' + inr(c.spent) + '</b></div><div><small>Average</small><b>' + inr(c.aov) + '</b></div></div>';
      h += '<div class="wi-kv"><span>First order</span><b>' + dmy(c.first_at) + '</b></div><div class="wi-kv"><span>Last order</span><b>' + (c.last_at ? dmy(c.last_at) + (c.days_since != null ? ' · ' + c.days_since + ' d ago' : '') : '—') + '</b></div>';
      if (c.rto_n || c.cx_n) h += '<div class="wi-kv"><span>Returned · Complaints</span><b style="color:' + (c.rto_n || c.cx_open ? '#b42318' : 'inherit') + '">' + c.rto_n + ' · ' + c.cx_n + (c.cx_open ? ' (' + c.cx_open + ' open)' : '') + '</b></div>';
      if ((c.flags || []).length) h += '<div class="wi-cu-fl">' + c.flags.map((f) => '<span class="' + f[0] + '">' + (f[0] === 'bad' ? '🚩 ' : f[0] === 'ok' ? '⭐ ' : f[0] === 'warn' ? '⚠ ' : '') + esc(f[1]) + '</span>').join('') + '</div>';
      if (r.orders) h += '<div class="wi-cu-ol">' + (r.orders.length ? r.orders.map((o) => '<div><b>' + esc(o.name) + '</b><span>' + esc(dmy(o.at)) + (o.status === 'cancelled' ? ' · cancelled' : '') + (o.src === 'store' || o.src === 'pos' ? ' · store' : '') + '</span><em>' + inr(o.total) + '</em></div>').join('') : '<span style="color:#8a94a3">No orders listed.</span>') + '</div>';
      else h += '<div class="wi-act"><button type="button" class="wi-btn" id="wiCuO">Show recent orders</button></div>';
      el.innerHTML = h; const b = $('wiCuO'); if (b) b.onclick = () => { b.disabled = true; b.textContent = 'Loading…'; loadCust(phone, true); };
    }
    async function drawAssign() {
      const box = $('wiAs'); if (!box) return; const c = S.cur.contact;
      try { S.people = (await api.get('wa.people', { phone: c.phone })).people || []; } catch (e) { box.innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; return; }
      if (!box.isConnected || !S.cur || S.cur.contact.phone !== c.phone) return;   // the person moved to another chat while the list loaded
      const best = S.people.find((p) => p.best);
      box.innerHTML = (best ? `<div class="wi-kv"><span>⭐ Recommended</span><b>${esc(best.name)}</b></div><div style="color:#6b7684;font-size:12px;margin:2px 0 4px">${esc(best.why)}</div>` : '<div style="color:#8a5a00;font-size:12.5px">No salesperson is present and free right now.</div>')
        + `<select id="wiPick"><option value="0">— Unassigned —</option>${S.people.map((p) => `<option value="${p.emp_id}" ${(c.assigned_emp ? c.assigned_emp === p.emp_id : (best && best.emp_id === p.emp_id)) ? 'selected' : ''}>${esc(p.name)} — ${p.present ? 'present' : 'absent'}, ${p.open} open${p.best ? ' ⭐' : ''}${p.full ? ' (full)' : ''}</option>`).join('')}</select>
           <div class="wi-act"><button type="button" class="wi-btn p" id="wiGo">${c.assigned_emp ? 'Move chat' : 'Assign'}</button></div><div id="wiAsE"></div>`;
      $('wiGo').onclick = async function () {
        const emp = +$('wiPick').value; this.disabled = true;
        try { await api.post('wa.assign', { phone: c.phone, emp_id: emp }); await refreshChat(); await loadList(true); }
        catch (e) { $('wiAsE').innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; this.disabled = false; }
      };
    }
    async function runTag(force) {
      const c = S.cur && S.cur.contact; if (!c) return; const b = $('wiTag'); if (b) { b.disabled = true; b.textContent = 'Reading the chat…'; }
      try {
        const r = await api.post('wa.tag', { phone: c.phone, force: !!force });
        c.tag = r.tag; c.tag_label = r.tag_label; c.summary = r.summary; drawHead(); drawSide(); loadList(true);
      } catch (e) { const el = $('wiTagE'); if (el) el.innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; if (b) { b.disabled = false; b.textContent = 'Try again'; } }
    }

    // ---------- composer
    function drawComposer() {
      const c = S.cur.contact; const C = $('wiC'); S.files = [];
      C.innerHTML = `<div class="wi-head" id="wiH"></div><div class="wi-msgs" id="wiM"></div><div class="wi-fu" id="wiFu" hidden></div><div id="wiN"></div><div class="wi-hot" id="wiHot" hidden></div><div class="wi-ub" id="wiUb" hidden></div><div class="wi-au" id="wiAu" hidden></div><div class="wi-qm" id="wiQm" hidden></div><div class="wi-sug" id="wiSg" hidden></div><div id="wiPp" hidden></div><div class="wi-quick" id="wiQk" hidden></div><div class="wi-tray" id="wiTray"></div>
        <div class="wi-comp" style="position:relative"><button type="button" class="wi-btn" id="wiAt" title="Send a photo, video or document" aria-label="Attach">📎<span class="lb">File</span></button><button type="button" class="wi-btn" id="wiQb" title="Quick replies" aria-label="Quick replies">💬<span class="lb">Quick</span></button><button type="button" class="wi-btn" id="wiTb" title="Pre-made messages" aria-label="Pre-made messages">⚡<span class="lb">Pre-made</span></button><button type="button" class="wi-btn" id="wiPf" title="Find a perfume and send its photo" aria-label="Find a perfume">🧴<span class="lb">Perfume</span></button><button type="button" class="wi-btn" id="wiOr" title="Orders of this customer" aria-label="Orders">📦<span class="lb">Orders</span></button><button type="button" class="wi-btn" id="wiOd" title="Order completed: one thank-you, then no automatic messages today and tomorrow" aria-label="Order done">✅<span class="lb">Done</span></button><button type="button" class="wi-btn" id="wiLs" title="Send a ready perfume list (Top 5 winter, Best sellers…)" aria-label="Lists">🗒️<span class="lb">Lists</span></button><textarea id="wiTx" rows="1" placeholder="Type a message…" title="Ctrl+Enter sends"></textarea><button type="button" class="wi-btn p" id="wiSend">Send</button>
          <input type="file" id="wiF1" accept="image/*,video/*" multiple hidden><input type="file" id="wiF2" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,application/pdf" multiple hidden></div>`;
      $('wiQb').onclick = () => { const q = $('wiQk'); q.hidden = !q.hidden; };
      $('wiOr').onclick = openOrders; $('wiOd').onclick = markDone;
      $('wiTb').onclick = openTpl;
      $('wiPf').onclick = openFind;
      $('wiLs').onclick = () => {   // 🗒️ Lists (wa_lists_ui.js, loaded the first time)
        if (!S.cur) return; const phone = S.cur.contact.phone;
        const go = () => { const box = sheet('Lists'); WaLists.sheet(box, { phone, close: () => closeSheet(), onSent: () => { refreshChat(true); loadList(true); } }); };
        if (window.WaLists) return go(); const sc = document.createElement('script'); sc.src = 'assets/js/wa_lists_ui.js?v=l10'; sc.onload = go; document.head.appendChild(sc);
      };
      $('wiSend').onclick = send;
      $('wiTx').onkeydown = (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); } };
      $('wiTx').oninput = function () { typingPing(S.cur && S.cur.contact && S.cur.contact.phone); if (AU.run) { AU.follow = false; auStop(true); } this.style.height = 'auto'; this.style.height = Math.min(140, this.scrollHeight) + 'px'; slash(this); const sg = $('wiSg'); if (sg) sg.classList.toggle('typing', this.value.trim() !== ''); const qm = $('wiQm'); if (qm && this.value.trim()) qm.hidden = true; };   // while someone types, the private suggestion steps aside
      $('wiAt').onclick = (e) => {
        e.stopPropagation(); const old = $('wiAm'); if (old) { old.remove(); return; }
        const m = document.createElement('div'); m.className = 'wi-am'; m.id = 'wiAm';
        m.innerHTML = '<button type="button" data-f="wiF1">🖼️ Photo or video</button><button type="button" data-f="wiF2">📄 Document</button><button type="button" data-f="lib">📚 From the library</button>';
        $('wiAt').parentNode.appendChild(m);
        m.querySelectorAll('button').forEach((b) => { b.onclick = () => { m.remove(); if (b.dataset.f === 'lib') openLib(); else $(b.dataset.f).click(); }; });
        setTimeout(() => document.addEventListener('click', function off() { const x = $('wiAm'); if (x) x.remove(); document.removeEventListener('click', off); }), 0);
      };
      ['wiF1', 'wiF2'].forEach((id) => { $(id).onchange = function () { addFiles([...this.files]); this.value = ''; }; });
      $('wiTx').onpaste = (e) => { const f = [...((e.clipboardData && e.clipboardData.files) || [])]; if (f.length) { e.preventDefault(); addFiles(f); } };   // paste a screenshot / copied photo
      C.ondragover = (e) => { if (e.dataTransfer && [...e.dataTransfer.types].includes('Files')) { e.preventDefault(); C.classList.add('wi-drop'); } };
      C.ondragleave = (e) => { if (e.target === C) C.classList.remove('wi-drop'); };
      C.ondrop = (e) => { C.classList.remove('wi-drop'); const f = [...((e.dataTransfer && e.dataTransfer.files) || [])]; if (f.length) { e.preventDefault(); addFiles(f); } };
      drawQuick(); drawNote(); drawTray(); maybeSuggest(false); if (window.WaProd) drawProd();
    }
    // ---------- photos, videos, documents
    const MB = 1048576;
    function kindOf(f) {
      const t = (f.type || '').toLowerCase(), n = (f.name || '').toLowerCase();
      if (t.startsWith('image/')) return 'image'; if (t.startsWith('video/')) return 'video'; if (t.startsWith('audio/')) return 'audio';
      if (/\.(jpe?g|png|webp|heic|heif|gif|bmp)$/.test(n)) return 'image'; if (/\.(mp4|3gp|mov|m4v)$/.test(n)) return 'video'; if (/\.(aac|m4a|mp3|amr|ogg|opus)$/.test(n)) return 'audio';
      return 'doc';
    }
    async function squeeze(f) {   // a phone photo is often 6–12 MB; WhatsApp takes 5 MB. Shrink it here (JPG, long side 1920 px) so nobody has to.
      const jp = /^image\/(jpeg|png)$/.test(f.type);
      if (jp && f.size <= 3 * MB) return f;
      try {
        const bmp = await createImageBitmap(f); let sc = Math.min(1, 1920 / Math.max(bmp.width, bmp.height)), q = 0.85, blob = null;
        for (let i = 0; i < 6; i++) {
          const cv = document.createElement('canvas'); cv.width = Math.max(1, Math.round(bmp.width * sc)); cv.height = Math.max(1, Math.round(bmp.height * sc));
          const cx = cv.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height); cx.drawImage(bmp, 0, 0, cv.width, cv.height);
          blob = await new Promise((r) => cv.toBlob(r, 'image/jpeg', q));
          if (blob && blob.size <= 4.5 * MB) break; q = Math.max(0.55, q - 0.1); sc *= 0.8;
        }
        if (bmp.close) bmp.close();
        return blob ? new File([blob], (f.name || 'photo').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : f;
      } catch (e) { return f; }   // the browser cannot read it: the server explains if WhatsApp will not take it
    }
    async function addFiles(list) {
      const note = $('wiN'); const errs = [];
      for (const f0 of list) {
        if (S.files.length >= 10) { errs.push('At most 10 files at a time.'); break; }
        const k = kindOf(f0); let f = f0;
        if (k === 'image') f = await squeeze(f0);
        const lim = k === 'doc' ? 100 : 16; if (k === 'image' && f.size > 5 * MB) { errs.push(f0.name + ' is still over 5 MB after shrinking — pick another photo.'); continue; }
        if (k !== 'image' && f.size > lim * MB) { errs.push(f0.name + ' is ' + (f.size / MB).toFixed(1) + ' MB — WhatsApp allows ' + lim + ' MB for ' + (k === 'doc' ? 'a document' : 'a ' + k) + '. ' + (k === 'video' ? 'Trim or compress it first.' : '')); continue; }
        if (/\.(mov)$/i.test(f.name) || f.type === 'video/quicktime') { errs.push(f0.name + ' is a .mov file — WhatsApp needs MP4.'); continue; }
        S.files.push({ f, k, url: k === 'image' || k === 'video' ? URL.createObjectURL(f) : '' });
      }
      if (note) note.innerHTML = errs.length ? `<div class="wi-note r">${errs.map(esc).join('<br>')}</div>` : ''; else if (errs.length) alert(errs.join('\n'));
      drawTray(); if (!errs.length) drawNote(); if ($('wiTx') && !('ontouchstart' in window)) $('wiTx').focus();
    }
    function drawTray() {
      const T = $('wiTray'); if (!T) return;
      T.innerHTML = S.files.map((x, i) => `<div class="wi-th ${x.up ? 'up' : ''}">${x.k === 'image' ? `<img src="${x.url}" alt="">` : (x.k === 'video' ? `<span class="ic">🎬</span><span>${esc(x.f.name)}</span>` : `<span class="ic">${x.k === 'audio' ? '🎤' : '📄'}</span><span>${esc(x.f.name)}</span>`)}<small>${(x.f.size / MB).toFixed(1)} MB</small>${x.up ? '' : `<button type="button" data-i="${i}" aria-label="Remove">×</button>`}</div>`).join('');
      T.querySelectorAll('button').forEach((b) => { b.onclick = () => { const x = S.files.splice(+b.dataset.i, 1)[0]; if (x && x.url) URL.revokeObjectURL(x.url); drawTray(); }; });
    }
    function upload(f, phone, caption, onp) {
      return new Promise((res, rej) => {
        const fd = new FormData(); fd.append('phone', phone); fd.append('caption', caption || ''); fd.append('file', f, f.name);
        const x = new XMLHttpRequest(); x.open('POST', api.buildUrl('wa.send_media')); x.setRequestHeader('Authorization', 'Bearer ' + api.token()); x.timeout = 300000;
        x.upload.onprogress = (e) => { if (e.lengthComputable && onp) onp(e.loaded / e.total); };
        x.onload = () => { let d = {}; try { d = JSON.parse(x.responseText); } catch (e) { } if (x.status >= 200 && x.status < 300 && !d.error) res(d); else rej(new Error(d.error || ('The file could not be sent (' + x.status + ').'))); };
        x.onerror = () => rej(new Error('Network problem — check your connection and try again.')); x.ontimeout = () => rej(new Error('Sending took too long. Try a smaller file.'));
        x.send(fd);
      });
    }
    const qkCode = (t) => '/' + (String(t || '').toLowerCase().replace(/[^a-z0-9]+/g, '') || 'reply');   // the title is the shortcut: "Pre Diwali" → /prediwali
    const qkFind = (list, q) => { q = String(q || '').toLowerCase().replace(/[^a-z0-9]+/g, ''); if (!q) return list.slice(0, 12); const sc = (x) => { const c = qkCode(x.title).slice(1); return c.startsWith(q) ? 0 : c.includes(q) ? 1 : String(x.body || '').toLowerCase().replace(/[^a-z0-9]+/g, '').includes(q) ? 2 : 9; }; return list.map((x) => [sc(x), x]).filter((a) => a[0] < 9).sort((a, b) => a[0] - b[0]).map((a) => a[1]).slice(0, 12); };
    function slash(t) {   // type "/" + the first letters of a quick reply's title (like the WhatsApp Business app): tap = into the box, ➤ = send now
      let box = $('wiSl'); const v = t.value;
      if (v.charAt(0) !== '/' || v.indexOf('\n') >= 0 || v.length > 40) { if (box) box.remove(); return; }
      const first = ((S.cur && S.cur.contact.name) || '').trim().split(/\s+/)[0] || ''; const hit = qkFind(S.quick, v.slice(1));
      if (!box) { box = document.createElement('div'); box.id = 'wiSl'; box.className = 'wi-slash'; $('wiQk').before(box); }
      box.innerHTML = hit.length ? hit.map((x, i) => `<div data-i="${i}" style="display:flex;gap:8px;align-items:center"><span style="flex:1;min-width:0"><b style="color:#128c7e">${esc(qkCode(x.title))}</b> <b>${esc(x.title)}</b><small>${esc(x.body)}</small></span><button type="button" data-go="${i}" title="Send now" style="border:0;background:#128c7e;color:#fff;border-radius:50%;width:34px;height:34px;flex:none;cursor:pointer">➤</button></div>`).join('') : '<div style="color:#8a94a3">No quick reply starts with “' + esc(v.slice(1)) + '”.</div>';
      box.onclick = (e) => { const g = e.target.closest('[data-go]'), d = e.target.closest('div[data-i]'); const it = g ? hit[+g.dataset.go] : d ? hit[+d.dataset.i] : null; if (!it) return;
        t.value = String(it.body).replace(/\{name\}/gi, first); box.remove(); t.dispatchEvent(new Event('input')); t.focus(); if (g && $('wiSend')) $('wiSend').click(); };
    }
    function drawQuick() {
      const q = $('wiQk'); if (!q) return; const first = ((S.cur && S.cur.contact.name) || '').trim().split(/\s+/)[0] || '';
      q.innerHTML = S.quick.length ? S.quick.map((x, i) => `<button type="button" data-i="${i}" title="${esc(x.body)}"><span style="color:#128c7e">${esc(qkCode(x.title))}</span> ${esc(x.title)}</button>`).join('') : '<span style="color:#8a94a3;font-size:12.5px">No quick replies yet. Admin adds them in WhatsApp → Setup.</span>';
      q.querySelectorAll('button').forEach((b) => { b.onclick = () => { const t = $('wiTx'); const txt = S.quick[+b.dataset.i].body.replace(/\{name\}/gi, first); t.value = (t.value ? t.value + '\n' : '') + txt; t.dispatchEvent(new Event('input')); t.focus(); q.hidden = true; }; });
    }
    function drawNote() {
      const c = S.cur.contact, n = $('wiN'), t = $('wiTx'), s = $('wiSend'); if (!n) return;
      let note = '', cls = '';
      if (c.optout) { note = 'This customer opted out (sent STOP). Do not message them.'; cls = 'r'; }
      else if (!c.window_open) { note = 'The 24-hour reply window is closed — you can only send an approved pre-made message now. Tap ⚡.'; }
      n.innerHTML = note ? `<div class="wi-note ${cls}">${esc(note)}</div>` : '';
      const off = c.optout || !c.window_open; t.disabled = off; s.disabled = off; if ($('wiAt')) $('wiAt').disabled = off; if ($('wiTb')) $('wiTb').disabled = !!c.optout; if ($('wiQb')) $('wiQb').disabled = off;
    }
    async function send() {
      const t = $('wiTx'), s = $('wiSend'); const text = t.value.trim(); if ((!text && !S.files.length) || !S.cur) return;
      const phone = S.cur.contact.phone; s.disabled = true; $('wiAt').disabled = true; $('wiN').innerHTML = '';
      try {
        if (S.files.length) {
          let cap = text, n = S.files.length, done = 0;
          while (S.files.length) {
            const x = S.files[0]; x.up = true; drawTray(); s.textContent = 'Sending ' + (done + 1) + '/' + n + '…';
            const useCap = x.k === 'audio' ? '' : cap;   // a voice note has no caption: the text goes as its own message
            let r; try { r = await upload(x.f, phone, useCap, (p) => { s.textContent = 'Sending ' + (done + 1) + '/' + n + ' · ' + Math.round(p * 100) + '%'; }); } catch (e) { x.up = false; drawTray(); throw e; }
            if (r.media_id && x.url && (x.k === 'image' || x.k === 'video')) MEDIA[r.media_id] = x.url; else if (x.url) URL.revokeObjectURL(x.url);   // the photo you just sent shows at once, without asking Meta for it
            S.files.shift(); done++; if (x.k !== 'audio') { cap = ''; t.value = ''; t.style.height = 'auto'; } await refreshChat(true);   // the caption is used up: a retry after a failure does not repeat it
          }
          if (cap) await api.post('wa.send', { phone, text: cap });   // only reached when every file was a voice note
          t.value = ''; t.style.height = 'auto';
        } else {
          // instant: the box empties and a "sending…" bubble shows at once; the server call goes behind it. The tap id (cid) means a double tap / retry never sends twice.
          const cid = 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8), box = $('wiM'), armed = PR.armed;
          t.value = ''; t.style.height = 'auto'; s.disabled = false; $('wiAt').disabled = false;
          if (box) { box.insertAdjacentHTML('beforeend', `<div class="wi-b out wi-tmp" data-cid="${cid}">${linkify(esc(text))}<small>Sending…</small></div>`); box.scrollTop = box.scrollHeight; }
          const rp = S.rp && S.rp.phone === phone ? S.rp : null; if (rp) { S.rp = null; wiRpDraw(); }
          try { await api.post('wa.send', rp ? { phone, text, cid, reply_id: rp.id } : { phone, text, cid }); }
          catch (e) { const b = box && box.querySelector('[data-cid="' + cid + '"]'); if (b) b.remove(); if (!t.value.trim()) t.value = text; throw e; }   // not sent: the words come back into the box
          if (armed && PR.phone === phone && PR.items.length && text.slice(0, 8).toLowerCase() === armed.slice(0, 8).toLowerCase()) prodAfter(phone);
        }
        refreshChat(true); loadList(true);
        if (AU.on) { AU.follow = true; const it = root.querySelector('.wi-it[data-p="' + phone + '"]'); if (it) it.classList.add('wi-out'); setTimeout(() => loadList(true), 450); }
      } catch (e) { $('wiN').innerHTML = `<div class="wi-note r">${esc(e.message)}</div>`; }
      finally { PR.armed = ''; s.textContent = 'Send'; drawTray(); drawNote(); }
    }

    // ---------- 📨 the follow-up plan of this chat (the purple strip) — plans are written in Learning & Improvement; the engine is in Follow-ups
    const TEMP = { hot: ['🔥 Hot', 'r'], warm: ['Warm', 'o'], cold: ['Cold', 'b'] };
    const inTxt = (m) => (m == null ? '' : m <= 0 ? 'now' : m < 60 ? 'in ' + m + ' min' : m < 1440 ? 'in ' + (Math.round(m / 6) / 10) + ' h' : 'in ' + (Math.round(m / 144) / 10) + ' d');
    async function loadFu() {
      if (!S.cur) return; const phone = S.cur.contact.phone;
      try { const d = await api.get('fu.chat', { phone }); if (S.cur && S.cur.contact.phone === phone) { S.fu = d; drawFu(); } }
      catch (e) { if (S.cur && S.cur.contact.phone === phone) { S.fu = null; drawFu(); } }      // the new server files are not there yet: the strip stays hidden
    }
    function drawFuPills() {
      const el = $('wiFuP'); if (!el) return; const f = S.fu;
      if (!f || !f.active) { el.innerHTML = ''; return; }
      const lm = lastMsg(), t = lm && lm.dir === 'out' ? parse(lm.ts) : null; const q = t ? Math.max(0, Math.round((Date.now() - t.getTime()) / 60000)) : null;
      const tp = TEMP[f.temp];
      el.innerHTML = (tp ? `<span class="wi-p ${tp[1]}">${tp[0]}</span>` : '') + (q != null ? `<span class="wi-p">went quiet · ${q < 60 ? q + ' min' : q < 1440 ? Math.floor(q / 60) + ' h' : Math.floor(q / 1440) + ' d'}</span>` : '');
    }
    function drawFu() {
      drawFuPills(); drawFuSide();
      const b = $('wiFu'); if (!b || !S.cur) return; const f = S.fu, c = S.cur.contact;
      if (!f || c.optout || (f.mode === 'off')) { b.hidden = true; return; }
      if (!f.active) {
        const msg = f.status === 'stopped' ? '⏹ Follow-ups stopped — ' + (f.stop_why || 'stopped') : (f.status === 'done' && f.stop_why ? (f.stop_why === 'ordered' ? '✅ Ordered — no more follow-ups needed' : 'Follow-ups finished — ' + f.stop_why) : '');
        if (!msg) { b.hidden = true; return; } b.hidden = false; b.className = 'wi-fu slim'; b.innerHTML = esc(msg); return;
      }
      b.hidden = false; b.className = 'wi-fu';
      const key = c.phone; const small = window.matchMedia && window.matchMedia('(max-width:760px)').matches; const open = S.fuOpen[key] != null ? S.fuOpen[key] : false;   // always one thin line until the person taps it (it covered the chat on every open); it keeps working in the background
      const auto = f.mode === 'auto24' || f.mode === 'autotpl';
      const who = f.mode === 'suggest' ? 'suggestion only — nothing is sent by itself' : (f.human ? 'a person is handling this chat, so the bot only suggests' : 'the bot is handling this');
      let h = `<div class="fh" id="wiFuH"><b>📨 Follow-up plan</b><span class="sm">${esc(f.title || '')} · nudge ${f.step_n} of ${f.step_total} · ${esc(f.due_in ? (f.due_min <= 0 ? 'due now' : 'in ' + f.due_in) : '')}</span><button type="button" class="tg" aria-label="Show or hide">${open ? '▾' : '▸'}</button></div>`;
      if (open) {
        h += '<div class="tl">' + (f.steps || []).map((x) => `<div class="st ${x.state}${x.tpl ? ' tpl' : ''}"><i></i><b>${esc(x.label.replace(' · template', ''))}</b>${esc(x.at)}${x.tpl ? '<em>template</em>' : ''}</div>`).join('') + '</div>';
        h += `<div class="why"><b>Why:</b> ${esc(f.why || 'quiet after our reply')}${f.angle && f.angle !== 'stop' ? ` → angle for nudge ${f.step_n}: <b>${esc(f.angle)}</b>` : ''}. <span style="color:#6b7684">${esc(who)}.</span></div>`;
        const tpl = f.needs_template && !f.window_open;
        if (S.fuEdit && !tpl) h += `<textarea id="wiFuT">${esc(f.draft || '')}</textarea><div class="ac"><button type="button" class="wi-btn p" id="wiFuSv">Save message</button><button type="button" class="wi-btn" id="wiFuCx">Cancel</button></div>`;
        else {
          if (tpl) h += `<div class="box tp"><small>After 24 hours · approved template${f.tpl_preview ? ' · version ' + esc(f.tpl_preview.ver) : ''}</small>${f.tpl_preview ? esc(f.tpl_preview.text) : 'No approved template for this step yet — create them in Follow-ups → After-24-hours ladder. Until then nothing can be sent here.'}</div>`;
          else if (f.draft) h += `<div class="box"><small>Next message${f.edited ? ' · edited by you' : ''}</small>${esc(f.draft)}</div>`;
          else h += '<div class="box"><small>Next message</small><span style="color:#6b7684">The bot writes it a few minutes before it is due.</span></div>';
          h += '<div class="ac">';
          if (tpl) h += '<button type="button" class="wi-btn p" id="wiFuTp">Send a template…</button>';
          else h += `<button type="button" class="wi-btn p" id="wiFuNow" ${S.fuBusy ? 'disabled' : ''}>${f.draft ? 'Send now' : 'Write & send now'}</button>${f.draft ? '' : '<button type="button" class="wi-btn" id="wiFuDr">Write it now</button>'}<button type="button" class="wi-btn" id="wiFuEd">✏️ Edit</button>`;
          h += '<button type="button" class="wi-btn" id="wiFuSk">⏭ Skip this one</button><button type="button" class="wi-btn" id="wiFuSt">■ Stop follow-ups</button></div>';
        }
        h += `<div class="ft">Sent ${f.sent_n} of max ${f.max} · ${esc((f.limits || []).join(' · '))}${auto && f.needs_template ? (f.mode === 'autotpl' ? ' · templates go by themselves' : ' · after 24 h this mode only suggests') : ''}</div><div id="wiFuE"></div>`;
      }
      b.innerHTML = h;
      $('wiFuH').onclick = () => { S.fuOpen[key] = !open; S.fuEdit = false; drawFu(); };
      const act = async (name, extra, okMsg) => {
        S.fuBusy = true; drawFu(); $('wiFuE') && ($('wiFuE').innerHTML = '');
        try { await api.post(name, Object.assign({ phone: key }, extra || {})); S.fuEdit = false; await loadFu(); await refreshChat(true); loadList(true); }
        catch (e) { S.fuBusy = false; drawFu(); const x = $('wiFuE'); if (x) x.innerHTML = `<div class="er">${esc(e.message)}</div>`; return; }
        S.fuBusy = false; drawFu();
      };
      if ($('wiFuNow')) $('wiFuNow').onclick = () => act('fu.send_now', f.draft ? { text: f.draft } : {});
      if ($('wiFuDr')) $('wiFuDr').onclick = async function () { this.disabled = true; this.textContent = 'Writing…'; await act('fu.draft'); };
      if ($('wiFuEd')) $('wiFuEd').onclick = async () => { if (!f.draft) { try { const r = await api.post('fu.draft', { phone: key }); f.draft = r.draft; } catch (e) { const x = $('wiFuE'); if (x) x.innerHTML = `<div class="er">${esc(e.message)}</div>`; return; } } S.fuEdit = true; drawFu(); const t = $('wiFuT'); if (t) t.focus(); };
      if ($('wiFuSv')) $('wiFuSv').onclick = () => { const v = $('wiFuT').value.trim(); if (!v) return; act('fu.edit', { text: v }); };
      if ($('wiFuCx')) $('wiFuCx').onclick = () => { S.fuEdit = false; drawFu(); };
      if ($('wiFuSk')) $('wiFuSk').onclick = () => act('fu.skip');
      if ($('wiFuSt')) $('wiFuSt').onclick = () => { if (confirm('Stop all follow-ups for this customer?')) act('fu.stop'); };
      if ($('wiFuTp')) $('wiFuTp').onclick = () => openTpl();
    }
    function drawFuSide() {
      const el = $('wiFuS'); if (!el) return; const f = S.fu; if (!f || f.mode === 'off') { el.innerHTML = ''; return; }
      const hs = (f.history || []).map((x) => `<div class="wi-hs"><i class="${x.result === 'replied' ? '' : x.result === 'waiting' ? 'w' : x.result === 'failed' ? 'f' : 'n'}">${x.result === 'waiting' ? '…' : x.result === 'failed' ? '!' : '✓'}</i><div>${esc(x.label)}<small>${esc(x.when)} · ${x.result === 'replied' ? '<b style="color:#12806a">replied</b>' : x.result === 'waiting' ? 'waiting for a reply' : x.result === 'no reply' ? 'no reply' : esc(x.result)}</small></div></div>`).join('');
      const up = f.active ? `<div class="wi-hs"><i class="w">…</i><div>Nudge ${f.step_n} · ${esc(f.due_in ? (f.due_min <= 0 ? 'due now' : 'in ' + f.due_in) : '—')}<small>${esc(f.title || '')}</small></div></div>` : '';
      el.innerHTML = `<h4>Follow-up history</h4><div class="wi-sb">${up}${hs || (up ? '' : '<span style="color:#8a94a3">No follow-ups sent to this customer yet.</span>')}</div><h4>Limits used</h4><div class="wi-sb" style="font-size:12.5px;line-height:1.55">${esc((f.limits || []).join(' · '))}<br><span style="color:#6b7684">Set in Learning &amp; Improvement → Teach by talking.</span></div>`;
    }

    // ---------- 💡 the private suggestion (only staff see it; it is never sent by itself)
    function lastMsg() { const a = (S.cur ? S.cur.messages : []).filter((m) => m.dir !== 'sys'); return a.length ? a[a.length - 1] : null; }
    function learnedChip(L) {   // the shared brain: what the owner taught shows up here
      if (!L) return ''; const bits = [];
      if (L.lessons) bits.push(L.lessons + ' lesson' + (L.lessons === 1 ? '' : 's')); if (L.rules) bits.push('your selling rules'); if (L.limits) bits.push('your limits');
      let h = bits.length ? ` <span class="wi-p p" title="This reply was written with everything you taught in Learning &amp; Improvement">🧠 Learned from your teaching · ${esc(bits.join(' · '))}</span>` : '';
      if (L.blocked && L.blocked.length) h += ` <span class="wi-p r" title="${esc(L.blocked.join('; '))}">🛡 Kept inside your limits</span>`;
      return h;
    }
    const QK_T = [   // what the customer asked → which quick replies (by their TITLE) fit
      ['greet', /^(?:hi+|hello+|hey+|hlo|helo|namaste|namaskar|good\s*(?:morning|afternoon|evening|night)|gm|jsk|jai\s*shri\s*krishna|kem\s*cho|sir|mam|madam|bhai|team|there|all|everyone|[^\p{L}\p{N}]+)+$/iu, /\bhi\b|hello|hey|greet|welcome|namaste|morning|afternoon|evening/i, /\bhi\b|hello|hey|greet|welcome/i],
      ['price', /price|rate\b|kitna|kimat|keemat|cost|how much|bhav|mrp|₹|\brs\.?\s?\d|amount/i, /price|rate|cost|kimat/i],
      ['discount', /discount|offer|deal|cheap|kam kar|best price|\boff\b/i, /discount|offer|deal/i],
      ['cod', /\bcod\b|cash\s*on/i, /cod|cash/i],
      ['delivery', /deliver|shipping|\bship\b|courier|dispatch|kab tak|kitne din|how many days|track|pincode|pin code/i, /deliver|ship|dispatch|track|courier/i],
      ['original', /original|\breal\b|fake|authentic|genuine|duplicate|\bcopy\b|\boil\b|long.?last|lasting|quality/i, /original|authentic|genuine|oil|quality|lasting/i],
      ['pay', /payment|\bpay\b|upi|gpay|google pay|phonepe|paytm|\blink\b|order karna|confirm|\bbook\b|\bbuy\b|\bqr\b/i, /pay|link|upi|order|confirm/i],
      ['photo', /photo|\bpic\b|image|video|picture|dikhao|show me/i, /photo|pic|image|video/i],
      ['return', /return|exchange|refund|damage|broken|wrong|leak|replace/i, /return|refund|exchange|damage|replace/i],
      ['store', /address|location|\bstore\b|\bshop\b|visit|where are you|kahan|\bkaha\b|vesu/i, /address|location|store|shop|visit/i],
    ];
    const QK_G = [[/morning|\bgm\b/i, /morning/i], [/afternoon/i, /afternoon/i], [/evening|night/i, /evening|night/i], [/namaste|namaskar|jsk|jai\s*shri/i, /namaste|namaskar/i]];
    // up to 3 quick replies that fit the customer's last message (instant — no AI)
    function qkMatch(msg, list) {
      msg = String(msg || '').trim(); if (!msg || !list || !list.length) return []; const out = [], add = (x) => { if (x && out.indexOf(x) < 0) out.push(x); };
      for (const [k, re, tre, gre] of QK_T) {
        if (!re.test(msg) || (k === 'greet' && msg.length > 40)) continue;
        let hit = list.filter((x) => tre.test(x.title || ''));
        if (k === 'greet') { const sp = QK_G.find(([mre]) => mre.test(msg)); hit = sp ? hit.filter((x) => sp[1].test(x.title)).concat(hit.filter((x) => gre.test(x.title) && !sp[1].test(x.title) && !/morning|afternoon|evening|night|namaste/i.test(x.title))) : hit.filter((x) => gre.test(x.title) && !/morning|afternoon|evening|night|namaste/i.test(x.title)); }
        hit.slice(0, k === 'greet' ? 2 : 1).forEach(add); if (out.length >= 3) break;
      }
      return out.slice(0, 3);
    }
    function drawQm() {
      const q = $('wiQm'); if (!q) return; if (!S.cur || !S.quick || !S.quick.length) { if (q) q.hidden = true; return; }
      const c = S.cur.contact, lm = lastMsg(), t = $('wiTx');
      if (c.optout || !c.window_open || !lm || lm.dir !== 'in' || !lm.body || (t && t.value.trim())) { q.hidden = true; return; }
      const hit = qkMatch(lm.body, S.quick); if (!hit.length) { q.hidden = true; return; }
      const fn = ((c.name || '').trim().split(/\s+/)[0]) || ''; q.hidden = false; q.innerHTML = '<span>⚡</span>' + hit.map((x, i) => `<button type="button" data-i="${i}">${esc(x.title)}</button>`).join('');
      q.onclick = (e) => { const b = e.target.closest('button'); if (!b) return; const x = hit[+b.dataset.i], tx = $('wiTx'); if (!x || !tx) return; if (AU.run) { AU.follow = false; auStop(true); } tx.value = x.body.replace(/\{name\}/gi, fn).replace(/\s+([!,.])/g, '$1').replace(/  +/g, ' '); tx.dispatchEvent(new Event('input')); tx.focus(); q.hidden = true; };
    }
    function drawSug() {
      drawUrg(); drawQm();
      const b = $('wiSg'); if (!b || !S.cur) return; const c = S.cur.contact, lm = lastMsg(), li = lastIn();
      if (c.optout || !c.window_open || !lm) { b.hidden = true; return; }
      if ((AU.run && AU.run.phone === c.phone) || (S.sug[li] && !S.sug[li].hidden)) prodSync(c.phone, li);   // the photos that go with this reply
      if (AU.run && AU.run.phone === c.phone) { b.hidden = true; return; }   // the countdown card shows the suggestion
      const r = S.sug[li]; b.classList.toggle('typing', !!($('wiTx') && $('wiTx').value.trim()));
      if (S.sugBusy) { b.hidden = false; b.innerHTML = '<div class="sg1"><span class="ic">✨</span><div class="tx">Writing a reply…</div></div>'; return; }
      if (r && !r.hidden) { b.hidden = false; b.innerHTML = `<div class="sg1"><span class="ic">✨</span><div class="tx">${esc(r.suggestion)}</div><button type="button" class="sgb p" id="wiSgU">Send</button><button type="button" class="sgb" id="wiSgE">Edit</button><button type="button" class="sgb g" id="wiSgN" aria-label="Another" title="Another">↻</button><button type="button" class="sgb g" id="wiSgX" aria-label="Hide">✕</button></div>${r.handoff ? '<div class="sgf">🙋 A person should handle this' + (r.handoff_reason ? ': ' + esc(r.handoff_reason) : '') + '</div>' : (r.call_now ? '<div class="sgf">📞 Better to call</div>' : '')}`;
        $('wiSgU').onclick = () => { const t = $('wiTx'); t.value = r.suggestion; t.dispatchEvent(new Event('input')); PR.armed = r.suggestion; send(); };
        $('wiSgE').onclick = () => { const t = $('wiTx'); t.value = r.suggestion; t.dispatchEvent(new Event('input')); PR.armed = r.suggestion; t.focus(); };
        $('wiSgX').onclick = () => { r.hidden = true; drawSug(); }; $('wiSgN').onclick = () => maybeSuggest(true, r.suggestion); return; }
      if (lm.dir === 'in' || admin) { b.hidden = false; b.innerHTML = '<div class="sg1"><span class="ic">✨</span><div class="tx">No reply written yet</div><button type="button" class="sgb p" id="wiSgG">Write</button></div>'; $('wiSgG').onclick = () => maybeSuggest(true); }
      else b.hidden = true;
    }
    async function maybeSuggest(manual, avoid) {
      if (!S.cur) { return; } const c = S.cur.contact, lm = lastMsg(), li = lastIn();
      if (c.optout || !c.window_open || !lm || !li) { drawSug(); return; }
      if (!manual && (lm.dir !== 'in' || S.sug[li])) { drawSug(); return; }   // the reply is written before the chat is opened: show it at once
      if (S.sugBusy) return; S.sugBusy = true; drawSug();
      const phone = c.phone;
      try {
        let got = null;
        if (!avoid) { try { const g = await api.get('wa.g_sugg', { phone }); if (g && g.sugg) got = { suggestion: g.sugg.text, why: g.sugg.why || '', call_now: !!g.sugg.call_now, handoff: false, handoff_reason: '', source: g.sugg.source || 'basic', ver: g.sugg.ver || 0, learned: null }; } catch (e) { } }   // the ready-made one first (instant)
        if (!got && admin && !manual) { S.sugBusy = false; if (S.cur && S.cur.contact.phone === phone) drawSug(); return; }   // an admin is not charged an AI call for every chat opened: "Write" asks
        if (got) S.sug[li] = got;
        else { const r = await api.post('wa.suggest', { phone, avoid: avoid || '' }); S.sug[li] = { suggestion: r.suggestion, why: r.why, call_now: !!r.call_now, handoff: !!r.handoff, handoff_reason: r.handoff_reason || '', source: r.source || 'basic', ver: r.ver || 0, learned: r.learned || null }; }
      }
      catch (e) { S.sug[li] = null; const b = $('wiSg'); if (b && manual) { b.hidden = false; b.innerHTML = `<div class="sg1"><span class="ic">✨</span><div class="tx">${esc(e.message)}</div></div>`; S.sugBusy = false; return; } }
      S.sugBusy = false; if (S.cur && S.cur.contact.phone === phone) { drawSug(); auArm(); }
    }

    // ---------- query type, tags, holder and transfer history (wq.php, wq_ui.js)
    // "I am typing": at most once every 20 s the server is told, so the no-answer flow leaves this chat alone for a minute (wq.typing)
    let tpAt = 0;
    function typingPing(phone) { const n = Date.now(); if (!phone || n - tpAt < 20000) return; tpAt = n; try { api.post('wq.typing', { phone }).catch(() => { }); } catch (e) { } }
    let qLd = null;
    function qReady(cb) {
      if (window.WaQ) return cb(); if (qLd) { qLd.push(cb); return; } qLd = [cb];
      const sc = document.createElement('script'); sc.src = 'assets/js/wq_ui.js?v=q3';
      sc.onload = () => { const q = qLd || []; qLd = null; q.forEach((f) => f()); }; sc.onerror = () => { const q = qLd || []; qLd = null; q.forEach((f) => f()); }; document.head.appendChild(sc);
    }
    // ---------- 🛒 chain + perfume cart (wch.php, wch_ui.js): lazy-loaded when the details panel opens or the button is pressed
    let chLd = null;
    function chReady(cb) {
      if (window.WaCart) return cb(); if (chLd) { chLd.push(cb); return; } chLd = [cb];
      const sc = document.createElement('script'); sc.src = 'assets/js/wch_ui.js?v=ch9';
      sc.onload = () => { const q = chLd || []; chLd = null; q.forEach((f) => f()); }; sc.onerror = () => { const q = chLd || []; chLd = null; q.forEach((f) => f()); }; document.head.appendChild(sc);
    }
    // ---------- 🧾 manual order (wmo.php, wmo_ui.js): QR / UPI payment first, the customer fills the address on a link
    let moLd = null;
    function moReady(cb) {
      if (window.WaManual) return cb(); if (moLd) { moLd.push(cb); return; } moLd = [cb];
      const sc = document.createElement('script'); sc.src = 'assets/js/wmo_ui.js?v=1';
      sc.onload = () => { const q = moLd || []; moLd = null; q.forEach((f) => f()); }; sc.onerror = () => { const q = moLd || []; moLd = null; q.forEach((f) => f()); }; document.head.appendChild(sc);
    }
    function openManual(c) { moReady(() => { if (!window.WaManual) { alert('Manual order is not installed yet. Refresh the page.'); return; } WaManual.open({ phone: c.phone, name: c.name || '', api, onDone: () => { try { refreshChat(true); } catch (e) { } } }); }); }
    function openCart(c) { chReady(() => { if (!window.WaCart) { alert('The cart is not installed yet. Refresh the page.'); return; } WaCart.open({ phone: c.phone, name: c.name || '', api, onChange: () => { drawCartChip(c); } }); }); }
    function drawCartChip(c) { chReady(() => { const el = $('wiCtS'); if (el && window.WaCart) WaCart.status(el, { phone: c.phone, name: c.name || '', api, onChange: () => drawCartChip(c) }); }); }
    // ---------- customer journey (wa_seg.php, wa_seg_ui.js)
    let segLd = null;
    function segReady(cb) {
      if (window.WaSeg) return cb(); if (segLd) { segLd.push(cb); return; } segLd = [cb];
      const sc = document.createElement('script'); sc.src = 'assets/js/wa_seg_ui.js?v=1';
      sc.onload = () => { const q = segLd || []; segLd = null; q.forEach((f) => f()); }; sc.onerror = () => { segLd = null; }; document.head.appendChild(sc);
    }

    // ---------- 📦 live orders of this customer (wa_orders.php, wa_ord_ui.js)
    const OR = { ld: null };
    function ordReady(cb) {
      if (window.WaOrd) return cb(); if (OR.ld) { OR.ld.push(cb); return; } OR.ld = [cb];
      const sc = document.createElement('script'); sc.src = 'assets/js/wa_ord_ui.js?v=9';
      sc.onload = () => { const q = OR.ld || []; OR.ld = null; q.forEach((f) => f()); }; sc.onerror = () => { OR.ld = null; }; document.head.appendChild(sc);
    }
    // ✅ Order completed (wa_done.php): one thank-you, then no automatic messages today and tomorrow
    async function markDone() {
      if (!S.cur) return; const ph = S.cur.contact.phone; let st = null; try { st = await api.get('wa.order_done', { phone: ph }); } catch (e) { }
      if (st && st.muted) { if (confirm('Order already completed' + (st.order ? ' (' + st.order + ')' : '') + ' — automatic messages are off until ' + String(st.until).slice(0, 10) + ' night.\n\nSwitch automatic messages back on?')) { try { await api.post('wa.order_done', { phone: ph, undo: 1 }); alert('Automatic messages are on again.'); } catch (e) { alert(e.message); } } return; }
      const o = prompt('✅ Order completed (address + payment received)?\n\nThe customer gets ONE thank-you message, then no automatic messages today and tomorrow.\n\nOrder number (optional):', ''); if (o === null) return;
      try { const r = await api.post('wa.order_done', { phone: ph, order: o.trim() }); alert('✅ Order completed' + (r.thanks ? ' · ' + r.thanks : '')); refreshChat(true); } catch (e) { alert(e.message); }
    }
    function orderPill(c) {   // "📦 3 orders · ₹4,200" next to the name; tap = the customer's order card
      const el = $('wiOs'); if (!el) return;
      ordReady(() => { if (window.WaOrd && $('wiOs') === el) WaOrd.badge(el, { phone: c.phone, name: (c.name || '').trim(), light: true, onChange: () => { refreshChat(true); loadList(true); } }); });
    }
    function openOrders() {
      if (!S.cur) return; const c = S.cur.contact;
      ordReady(() => { if (!window.WaOrd) { alert('Could not open orders. Refresh the page.'); return; } WaOrd.open({ phone: c.phone, name: (c.name || '').trim() || '+91 ' + c.phone, onChange: () => { refreshChat(true); loadList(true); } }); });
    }

    // ---------- 🧴 products: "Find a perfume" and the photos that follow a prepared reply (wa_prod.php, wa_prod_ui.js)
    const PR = { phone: '', li: 0, items: [], flag: '', p: null, armed: '', ld: null };
    function prodReady(cb) {
      if (window.WaProd) return cb(); if (PR.ld) { PR.ld.push(cb); return; } PR.ld = [cb];
      const sc = document.createElement('script'); sc.src = 'assets/js/wa_prod_ui.js?v=pr4';
      sc.onload = () => { const q = PR.ld || []; PR.ld = null; q.forEach((f) => f()); }; sc.onerror = () => { PR.ld = null; }; document.head.appendChild(sc);
    }
    function prodSync(phone, li) {   // which photos go with the reply for the customer's newest message (max 3, none shown in the last 24 h, none while a guided flow runs)
      if (PR.phone === phone && PR.li === li) return; PR.phone = phone; PR.li = li; PR.items = []; PR.flag = ''; PR.armed = '';
      PR.p = api.get('wa.prod_attached', { phone }).then((d) => { if (PR.phone === phone && PR.li === li) { PR.items = d.items || d.products || []; PR.flag = d.flag || ''; } }).catch(() => { }).then(() => { PR.p = null; if (PR.items.length || PR.flag) prodReady(drawProd); });
    }
    function drawProd() {
      const el = $('wiPp'); if (!el) return; const c = S.cur && S.cur.contact, li = c ? lastIn() : 0, sg = c ? S.sug[li] : null;
      if (!c || !window.WaProd || PR.phone !== c.phone || PR.li !== li || (!PR.items.length && !PR.flag) || c.optout || !c.window_open || (sg && sg.hidden && !AU.run)) { el.hidden = true; el.innerHTML = ''; return; }
      el.hidden = false;
      el.innerHTML = (PR.flag ? '<div class="wpr-pt warn">⚠ ' + esc(PR.flag) + '</div>' : '') + (PR.items.length ? '<div class="wpr-pt">📷 ' + PR.items.length + (PR.items.length > 1 ? ' photos follow' : ' photo follows') + ' the text · ✕ takes one off</div><div class="wpr-pend">' + WaProd.pendHtml(PR.items) + '</div>' : '');
      el.onclick = (e) => { const b = e.target.closest('[data-rm]'); if (!b) return; PR.items.splice(+b.dataset.rm, 1); drawProd(); };
    }
    async function prodAfter(phone) {   // the text went out: now the photos (they are sent one by one, in order)
      const items = PR.phone === phone ? PR.items.slice() : []; if (!items.length || !window.WaProd) return; PR.items = []; PR.flag = ''; PR.armed = ''; drawProd();
      try {
        const r = await WaProd.send(phone, items.map((p) => p.id), 'why'); const k = (r.sent || []).length; if (S.cur && S.cur.contact.phone === phone) await refreshChat(true);
        const n = $('wiN'); if (n && k && S.cur && S.cur.contact.phone === phone) { n.innerHTML = '<div class="wi-note" style="background:#e3f6ec;color:#12794a">📷 ' + k + (k > 1 ? ' photos' : ' photo') + ' sent after the text.</div>'; setTimeout(() => { const x = $('wiN'); if (x && /photos? sent after/.test(x.textContent)) x.innerHTML = ''; }, 4000); }
      } catch (e) { const n = $('wiN'); if (n) n.innerHTML = '<div class="wi-note r">The text went, but the photos did not: ' + esc(e.message) + '</div>'; }
    }
    function openFind() {
      if (!S.cur) return; const c = S.cur.contact;
      prodReady(() => WaProd.open({ phone: c.phone, name: (c.name || '').trim() || '+91 ' + c.phone, canSend: !!c.window_open && !c.optout,
        whyNot: c.optout ? 'This customer opted out — photos cannot be sent.' : 'The 24-hour window is closed, so a photo cannot be sent now. Send a pre-made message first.', onSent: () => { refreshChat(true); loadList(true); } }));
    }

    // ---------- sheets: ⚡ pre-made messages and 📚 the media library
    function closeSheet(viaBack) { const s = $('wiSh'); if (!s) return; s.remove(); if (!viaBack) layerDone('sheet', false); }
    function sheet(t) {
      { const o = $('wiSh'); if (o) o.remove(); } const s = document.createElement('div'); s.id = 'wiSh'; s.className = 'wi-sh';   // a sheet replacing another keeps its Back step
      s.innerHTML = `<div class="wi-shb" role="dialog" aria-label="${esc(t)}"><div class="wi-shh"><b>${esc(t)}</b><button type="button" class="wi-shx" aria-label="Close">✕</button></div><div class="wi-shc" id="wiShC"></div></div>`;
      s.onclick = (e) => { if (e.target === s) closeSheet(); }; s.querySelector('.wi-shx').onclick = closeSheet; root.appendChild(s); layerOpen('sheet', () => closeSheet(true)); return $('wiShC');
    }
    const firstName = (c) => { const w = ((c && c.name) || '').trim().split(/\s+/)[0].replace(/[^\p{L}\p{N}'.\-]/gu, ''); return w.length > 1 ? w.charAt(0).toUpperCase() + w.slice(1) : ''; };
    const fill = (t, v) => String(t || '').replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => (v[+n - 1] || m));
    const sendable = (t) => (t.status === 'approved' || t.status === 'partial') && !t.media_header && !t.auto;   // 🎫 complaint / follow-up messages are sent by the system itself
    let TP = null;
    async function openTpl() {
      if (!S.cur) return; const c = S.cur.contact, box = sheet('Pre-made messages'); box.innerHTML = '<div class="wi-empty">Loading…</div>';
      try { const d = await api.get('wa.tpl_list'); TP = { list: d.templates || [], can: !!d.can_edit, tab: 'sug', q: '', all: false, sug: null, err: '' }; } catch (e) { box.innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; return; }
      drawTpl(); loadTplSug(false);
    }
    function drawTpl() {
      const box = $('wiShC'); if (!box || !TP) return; const c = S.cur.contact;
      let h = `<div class="wi-seg"><button type="button" data-t="sug" class="${TP.tab === 'sug' ? 'on' : ''}">✨ For this chat</button><button type="button" data-t="all" class="${TP.tab === 'all' ? 'on' : ''}">All messages</button></div>`;
      if (!c.window_open) h += '<div class="wi-note" style="margin:0 0 10px">The 24-hour window is closed — a pre-made message is the only thing you can send now.</div>';
      if (TP.tab === 'sug') {
        if (TP.loading) h += '<div class="wi-und"><small>Reading this chat…</small>I am reading the chat and the customer’s orders to find the best message.</div>';
        else if (TP.err) h += `<div class="wi-err">${esc(TP.err)}</div><div class="wi-act"><button type="button" class="wi-btn" id="wiTpR">Try again</button></div>`;
        else if (TP.sug) {
          const g = TP.sug; h += `<div class="wi-und"><small>How I understood this chat</small>${esc(g.understood || '')}</div>`;
          if (g.best) h += `<div class="wi-card tap" data-id="${g.best.id}"><div class="tt"><span>⭐ Best fit — ${esc(g.best.name)}</span></div><div class="bd">${esc(fill(g.best.body, [firstName(c) || 'ji']))}</div><div class="ft">${esc(g.best_reason || '')}</div><div class="wi-act"><button type="button" class="wi-btn p">Use this message ›</button></div></div>`;
          if (g.draft) {
            h += `<div class="wi-card"><div class="tt"><span>${g.best ? 'Or a new message' : 'No approved message fits well'}</span><span class="wi-p y">Needs Meta approval</span></div>${g.gap ? `<div class="ft" style="margin:0 0 6px">${esc(g.gap)}</div>` : ''}<div class="bd">${esc(g.draft.body || '')}</div>${(g.draft.buttons || []).length ? `<div class="ft">Buttons: ${g.draft.buttons.map((b) => esc(b.text)).join(' · ')}</div>` : ''}${g.draft_problem ? `<div class="wi-err">${esc(g.draft_problem)}</div>` : ''}`;
            h += g.can_create && !g.draft_problem ? '<div class="wi-act"><button type="button" class="wi-btn d" id="wiTpN">Send to Meta for approval</button></div><div id="wiTpNE"></div>' : '<div class="ft">Ask the admin to approve this wording. Once Meta approves it, it appears in “All messages”.</div>';
            h += '</div>';
          }
          if (!g.best && !g.draft) h += '<div class="wi-empty">No suggestion this time. Pick from “All messages”.</div>';
        }
      } else {
        const q = TP.q.trim().toLowerCase(); let rows = TP.list.filter((t) => !t.auto && (TP.all || sendable(t)) && (!q || (t.name + ' ' + t.body).toLowerCase().includes(q)));
        h += `<input class="wi-in" id="wiTpQ" type="search" placeholder="Search messages…" value="${esc(TP.q)}">`;
        if (TP.can) h += `<label style="display:flex;gap:8px;align-items:center;margin:8px 0;font-size:12.5px"><input type="checkbox" id="wiTpA" ${TP.all ? 'checked' : ''}> Also show messages that are waiting for Meta or were rejected</label>`;
        h += '<div style="margin-top:8px">' + (rows.length ? rows.map((t) => `<div class="wi-card ${sendable(t) ? 'tap' : 'off'}" data-id="${t.id}"><div class="tt"><span>${t.star ? '⭐ ' : ''}${esc(t.name.replace(/_/g, ' '))}</span><span class="wi-p ${t.status === 'approved' ? 'g' : (t.status === 'rejected' ? 'r' : 'y')}">${t.status === 'approved' ? 'Approved' : (t.status === 'partial' ? 'Approved' : (t.status === 'pending' ? 'Waiting for Meta' : esc(t.status)))}</span></div><div class="bd">${esc(fill(t.body, [firstName(c) || 'ji']).slice(0, 220))}</div>${t.media_header ? '<div class="ft">Has a photo on top — not available here yet.</div>' : ''}</div>`).join('') : '<div class="wi-empty">No pre-made message yet.<br>The admin creates them in WhatsApp → Pre-made messages.</div>') + '</div>';
      }
      box.innerHTML = h;
      box.querySelectorAll('.wi-seg button').forEach((b) => { b.onclick = () => { TP.tab = b.dataset.t; if (TP.tab === 'sug' && !TP.sug && !TP.loading) loadTplSug(false); drawTpl(); }; });
      box.querySelectorAll('.wi-card.tap').forEach((el) => { el.onclick = () => { const t = TP.list.find((x) => x.id === +el.dataset.id) || (TP.sug && TP.sug.best); if (t) pickTpl(t); }; });
      const r = $('wiTpR'); if (r) r.onclick = () => loadTplSug(true);
      const q = $('wiTpQ'); if (q) { q.oninput = function () { TP.q = this.value; const pos = this.selectionStart; drawTpl(); const n = $('wiTpQ'); n.focus(); try { n.setSelectionRange(pos, pos); } catch (e) { } }; }
      const a = $('wiTpA'); if (a) a.onchange = function () { TP.all = this.checked; drawTpl(); };
      const nb = $('wiTpN'); if (nb) nb.onclick = async function () {
        this.disabled = true; this.textContent = 'Sending to Meta…';
        try { const x = await api.post('wa.tpl_submit', { draft: TP.sug.draft }); $('wiTpNE').innerHTML = `<div class="wi-note" style="margin:8px 0 0;background:#e3f6ec;color:#12794a">Sent to Meta. Approval usually takes a few minutes — then it shows in “All messages”.</div>`; this.remove(); try { const d = await api.get('wa.tpl_list'); TP.list = d.templates || []; } catch (e) { } }
        catch (e) { $('wiTpNE').innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; this.disabled = false; this.textContent = 'Send to Meta for approval'; }
      };
    }
    async function loadTplSug(force) {
      const c = S.cur.contact, key = c.phone + ':' + lastId(); if (!force && S.tsug[key]) { TP.sug = S.tsug[key]; drawTpl(); return; }
      TP.loading = true; TP.err = ''; drawTpl();
      try { const r = await api.post('wa.tpl_suggest', { phone: c.phone }); TP.sug = S.tsug[key] = { understood: r.understood, best: r.best, best_reason: r.best_reason, gap: r.gap, draft: r.draft, draft_problem: r.draft_problem, can_create: r.can_create }; }
      catch (e) { TP.err = e.message; }
      TP.loading = false; if ($('wiShC') && TP) drawTpl();
    }
    function pickTpl(t) {
      if (!sendable(t)) return; const c = S.cur.contact, box = $('wiShC'), nv = t.vars || 0, fn = firstName(c) || 'ji';
      let h = `<button type="button" class="wi-btn" id="wiTpB" style="margin-bottom:8px">‹ Back</button><b>${esc(t.name.replace(/_/g, ' '))}</b><div class="wi-bub" id="wiTpP"></div>`;
      if (t.header_var) h += '<label class="wi-fl">Heading value</label><input class="wi-in" id="wiTpH" value="' + esc(fn) + '">';
      const H = []; for (let i = 1; i <= nv; i++) { const hn = H[i - 1] = tplVarHint(t, i), pre = hn.k === 'name' ? fn : hn.k === 'date' ? hn.eg : ''; h += `<label class="wi-fl">Value for {{${i}}}${hn.label ? ' — ' + esc(hn.label) : ''}</label><input class="wi-in wi-v" data-i="${i}" value="${esc(pre)}" placeholder="${hn.eg ? 'e.g. ' + esc(hn.eg) : 'Write it here'}">`; }
      h += '<div id="wiTpE"></div><div class="wi-act" style="margin-top:12px"><button type="button" class="wi-btn p" id="wiTpS">Send to customer</button></div><div class="ft" style="color:#6b7684;font-size:12px;margin-top:8px">It goes out from the right number automatically.</div>';
      box.innerHTML = h;
      const vals = () => [...box.querySelectorAll('.wi-v')].map((x) => x.value.trim());
      const prev = () => { const v = vals(); $('wiTpP').textContent = (t.header_text ? fill(t.header_text, [($('wiTpH') ? $('wiTpH').value : '')]) + '\n' : '') + fill(t.body, v) + (t.footer ? '\n' + t.footer : ''); };
      box.querySelectorAll('input').forEach((x) => { x.oninput = prev; }); prev();
      $('wiTpB').onclick = () => drawTpl();
      $('wiTpS').onclick = async function () {
        const v = vals(); const bad = v.map((x, i) => tplVarBad(H[i] || {}, x)).find(Boolean); if (bad) { $('wiTpE').innerHTML = '<div class="wi-err">' + esc(bad) + '</div>'; return; }
        this.disabled = true; this.textContent = 'Sending…';
        try { await api.post('wa.tpl_send', { phone: c.phone, id: t.id, vars: v, header_var: $('wiTpH') ? $('wiTpH').value.trim() : '' }); closeSheet(); await refreshChat(true); loadList(true); }
        catch (e) { $('wiTpE').innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; this.disabled = false; this.textContent = 'Send to customer'; }
      };
    }
    const LIB = {};
    async function libUrl(id) {
      if (LIB[id]) return LIB[id];
      const res = await fetch(api.buildUrl('wa.lib_file', { id }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (!res.ok) throw new Error('Could not load the file.');
      return (LIB[id] = URL.createObjectURL(await res.blob()));
    }
    async function openLib() {
      if (!S.cur) return; const box = sheet('Media library'); box.innerHTML = '<div class="wi-empty">Loading…</div>'; let items = [];
      try { items = (await api.get('wa.lib_list')).items || []; } catch (e) { box.innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; return; }
      let q = '';
      const draw = () => {
        const rows = items.filter((x) => !q || x.title.toLowerCase().includes(q));
        box.innerHTML = `<input class="wi-in" id="wiLbQ" type="search" placeholder="Search…" value="${esc(q)}"><div style="margin-top:10px">${rows.length ? '<div class="wi-lib">' + rows.map((x) => `<div class="wi-li" data-id="${x.id}"><div class="im" data-k="${x.kind}">${x.kind === 'image' ? '' : (x.kind === 'video' ? '🎬' : '📄')}</div><div class="nm">${esc(x.title)}</div></div>`).join('') + '</div>' : '<div class="wi-empty">The library is empty.<br>The admin adds photos, videos and PDFs in WhatsApp → Media library.</div>'}</div>`;
        box.querySelectorAll('.im[data-k="image"]').forEach(async (el) => { try { el.style.backgroundImage = 'url(' + (await libUrl(el.parentNode.dataset.id)) + ')'; } catch (e) { el.textContent = '🖼️'; } });
        box.querySelectorAll('.wi-li').forEach((el) => { el.onclick = () => pick(items.find((x) => x.id === +el.dataset.id)); });
        const qi = $('wiLbQ'); qi.oninput = function () { q = this.value.trim().toLowerCase(); const p = this.selectionStart; draw(); const n = $('wiLbQ'); n.focus(); try { n.setSelectionRange(p, p); } catch (e) { } };
      };
      const pick = (x) => {
        box.innerHTML = `<button type="button" class="wi-btn" id="wiLbB" style="margin-bottom:8px">‹ Back</button><b>${esc(x.title)}</b><div style="margin:8px 0" id="wiLbP">${x.kind === 'image' ? '<div class="wi-empty">Loading…</div>' : '<div class="wi-card">' + (x.kind === 'video' ? '🎬 ' : '📄 ') + esc(x.filename) + ' · ' + (x.size / 1048576).toFixed(1) + ' MB</div>'}</div>${x.kind === 'document' ? '' : '<label class="wi-fl">Caption (optional)</label><textarea class="wi-in" id="wiLbC" rows="2" placeholder="Write a line to go with it"></textarea>'}<div id="wiLbE"></div><div class="wi-act" style="margin-top:12px"><button type="button" class="wi-btn p" id="wiLbS">Send to customer</button></div>`;
        if (x.kind === 'image') libUrl(x.id).then((u) => { const p = $('wiLbP'); if (p) p.innerHTML = `<img src="${u}" alt="" style="max-width:100%;max-height:300px;border-radius:10px">`; }).catch(() => { const p = $('wiLbP'); if (p) p.innerHTML = ''; });
        $('wiLbB').onclick = draw;
        $('wiLbS').onclick = async function () {
          this.disabled = true; this.textContent = 'Sending…';
          try { await api.post('wa.lib_send', { phone: S.cur.contact.phone, id: x.id, caption: $('wiLbC') ? $('wiLbC').value.trim() : '' }); closeSheet(); await refreshChat(true); loadList(true); }
          catch (e) { $('wiLbE').innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; this.disabled = false; this.textContent = 'Send to customer'; }
        };
      };
      draw();
    }

    // ---------- 📞 call this customer now (salespeople)
    let callBox = null;
    function drawCall(items) {
      const list = (items || []).filter((x) => x.phone !== S.sel);
      if (callBox) { callBox.remove(); callBox = null; }
      if (!list.length) return;
      const x = list[0], m = Math.max(0, Math.floor(x.since_sec / 60));
      callBox = document.createElement('div'); callBox.className = 'wi-call' + (x.escalated ? ' esc' : '');
      callBox.innerHTML = `<div><b>${x.remind ? '⏰ Follow up now' : '📞 Call this customer now'}</b><div class="sm">${esc(x.name || pretty(x.phone))} · ${esc(pretty(x.phone))}${x.summary ? ' — ' + esc(x.summary) : ''}<br>${x.remind ? 'You asked to be reminded' + (m >= 1 ? ' · ' + m + ' min ago' : '') : (m < 1 ? 'Just given to you' : 'Waiting ' + m + ' min')}${x.escalated ? ' · the admin was told' : ''}${list.length > 1 ? ' · +' + (list.length - 1) + ' more' : ''}</div><div class="ac"><a href="tel:${esc(x.tel)}" id="wiCallA">Call now</a><button type="button" id="wiCallO">${x.remind ? 'Open chat' : '✅ Accept &amp; open'}</button><button type="button" class="gh" id="wiCallD">${x.remind ? 'Snooze 30 min' : 'Called ✓'}</button></div></div>`;
      root.appendChild(callBox);
      $('wiCallO').onclick = async () => { try { await api.post(x.remind ? 'wa.remind_done' : 'wa.accept', { phone: x.phone }); } catch (e) { } openChat(x.phone); if (callBox) { callBox.remove(); callBox = null; } };
      $('wiCallD').onclick = async () => { try { if (x.remind) await api.post('wa.remind', { phone: x.phone, mins: 30 }); else await api.post('wa.callnow_done', { phone: x.phone }); } catch (e) { } pollCall(); };
    }
    async function pollCall() { if (admin || document.hidden) return; try { drawCall((await api.get('wa.callnow')).items); } catch (e) { } }

    WaInbox.open = (p) => openChat(p);
    WaInbox.feed = (k, scope) => setFeed(k, scope);   // used by the WhatsApp Sales menu: Query / Assigned / Community / Campaign feeds
    async function openChat(phone) {
      if (AU.run && AU.run.phone !== phone) auStop(false);
      S.sel = phone; closeSheet(); asgClose(); if (root.classList.contains('det')) layerDone('det', false); drawList(); root.classList.add('open'); root.classList.remove('det'); layerOpen('chat', backChat); S.cur = null; S.tagFor = '';
      $('wiC').innerHTML = '<div class="wi-empty" style="margin:auto">Loading…</div>'; $('wiS').innerHTML = '';
      try {
        S.cur = await api.get('wa.chat', { phone }); S.older = []; S.moreOld = !!S.cur.more; S.oldBusy = false; S.lastMsgId = lastId();
        S.fu = null; S.fuEdit = false; drawComposer(); drawHead(); drawMsgs(false); drawSide(); drawNote(); loadFu();
        const c = S.cur.contact; if (S.cur.messages.some((m) => m.dir === 'in') && c.tag === '') runTag(false);
        S.tagFor = lastIn();
      } catch (e) { $('wiC').innerHTML = `<div class="wi-empty" style="margin:auto">${esc(e.message)}</div>`; }
    }
    const lastId = () => (S.cur && S.cur.messages.length ? S.cur.messages[S.cur.messages.length - 1].id : 0);
    const lastIn = () => { const a = (S.cur ? S.cur.messages : []).filter((m) => m.dir === 'in'); return a.length ? a[a.length - 1].id : 0; };
    async function refreshChat(forceScroll) {
      if (!S.sel) return;
      try {
        const q = { phone: S.sel }; if (!forceScroll && S.cur && S.cur.sig && S.cur.contact && S.cur.contact.phone === S.sel) q.sig = S.cur.sig;   // nothing changed → a tiny answer, nothing redrawn
        const d = await api.get('wa.chat', q); if (d && d.same) return; const before = lastId(), oldTag = S.cur ? S.cur.contact.tag : '', oldSt = S.cur ? S.cur.contact.status + S.cur.contact.assigned_emp : '';
        const sig = (x) => x.map((m) => m.id + m.status).join(',');
        const changed = !S.cur || sig(d.messages) !== sig(S.cur.messages);
        const keepTag = S.cur && !d.contact.tag ? S.cur.contact : null; S.cur = d; if (keepTag && keepTag.tag) { d.contact.tag = keepTag.tag; d.contact.tag_label = keepTag.tag_label; d.contact.summary = keepTag.summary; }
        drawUrg(); if (changed) drawMsgs(!forceScroll);
        if (changed || oldSt !== d.contact.status + d.contact.assigned_emp) { drawHead(); drawSide(); drawNote(); }
        if (lastId() !== before && lastIn() !== S.tagFor) { S.tagFor = lastIn(); runTag(false); }
        if (changed) { maybeSuggest(false); auArm(); }
        if (!S.fuEdit && !S.fuBusy) loadFu();
      } catch (e) { /* a quiet refresh never interrupts the person */ }
    }

    // ============================================================================================================================
    // ☑ BULK: select many chats (one by one, or "all in this view") → send one pre-made message to all of them, or move them all to one person
    // ============================================================================================================================
    S.bulk = { on: false, set: new Set(), info: {}, total: 0 };
    const bkStyle = () => {
      if (document.getElementById('bkStyle')) return; const st = document.createElement('style'); st.id = 'bkStyle';
      st.textContent = `
      .bk-bar{position:sticky;top:0;z-index:6;background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;padding:10px 12px;display:flex;flex-direction:column;gap:8px;box-shadow:0 6px 20px rgba(15,23,42,.25)}
      .bk-top{display:flex;align-items:center;gap:10px}.bk-n{font-weight:800;font-size:15px;flex:1}.bk-n small{display:block;font-weight:500;font-size:11.5px;opacity:.7;margin-top:1px}
      .bk-x{border:0;background:rgba(255,255,255,.14);color:#fff;border-radius:99px;padding:7px 12px;font-weight:700;font-size:12.5px;cursor:pointer}
      .bk-row{display:flex;gap:8px;flex-wrap:wrap}.bk-b{flex:1 1 0;min-width:120px;border:0;border-radius:12px;padding:11px 12px;font-weight:800;font-size:13.5px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:transform .08s,opacity .15s}
      .bk-b:active{transform:scale(.97)}.bk-b[disabled]{opacity:.4;cursor:not-allowed}.bk-b.send{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff}.bk-b.move{background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff}
      .bk-link{background:none;border:0;color:#93c5fd;font-weight:700;font-size:12.5px;cursor:pointer;padding:0;text-align:left}
      .wi-ck{flex:0 0 auto;width:22px;height:22px;border-radius:50%;border:2px solid #b6bfcc;margin:auto 10px auto 0;display:flex;align-items:center;justify-content:center;transition:all .12s;background:#fff}
      .wi-ck.on{background:#4f46e5;border-color:#4f46e5}.wi-ck.on:after{content:'✓';color:#fff;font-size:13px;font-weight:900;line-height:1}
      .wi-it.bsel{background:#eef2ff !important;box-shadow:inset 3px 0 0 #4f46e5}
      .bk-job{position:fixed;left:50%;top:10px;transform:translateX(-50%);z-index:9500;background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;border-radius:16px;padding:10px 14px;box-shadow:0 12px 40px rgba(15,23,42,.35);display:flex;align-items:center;gap:12px;max-width:min(94vw,720px);flex-wrap:wrap}
      .bk-jt{font-size:13px;flex:1 1 220px}.bk-jp{flex:1 1 120px;height:8px;border-radius:99px;background:rgba(255,255,255,.18);overflow:hidden}.bk-jp i{display:block;height:100%;background:linear-gradient(90deg,#22c55e,#4ade80);transition:width .25s}
      .bk-ja{display:flex;gap:6px}.bk-ja button{border:0;border-radius:99px;padding:6px 12px;font-weight:800;font-size:12.5px;cursor:pointer;background:rgba(255,255,255,.16);color:#fff}
      .bk-sh h4{margin:0 0 4px;font-size:17px}.bk-sh .sub{color:#64748b;font-size:12.5px;margin-bottom:12px}
      .bk-who{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:14px;margin-bottom:8px;cursor:pointer;transition:all .12s;background:#fff}
      .bk-who:hover{border-color:#a5b4fc}.bk-who.on{border-color:#4f46e5;background:#eef2ff;box-shadow:0 0 0 3px rgba(79,70,229,.12)}
      .bk-av{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800}
      .bk-who b{display:block;font-size:14px}.bk-who small{color:#64748b;font-size:11.5px}.bk-dot{width:9px;height:9px;border-radius:50%;background:#22c55e;margin-left:auto}.bk-dot.off{background:#cbd5e1}
      .bk-prog{height:12px;border-radius:99px;background:#e2e8f0;overflow:hidden;margin:14px 0 8px}.bk-prog i{display:block;height:100%;width:0;background:linear-gradient(90deg,#22c55e,#16a34a);transition:width .25s}
      .bk-stats{display:flex;gap:8px;margin:10px 0}.bk-stat{flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:10px;text-align:center}.bk-stat b{display:block;font-size:20px}.bk-stat small{color:#64748b;font-size:11.5px}
      .bk-stat.ok b{color:#16a34a}.bk-stat.bad b{color:#dc2626}
      .bk-fail{max-height:160px;overflow:auto;border:1px solid #fecaca;background:#fef2f2;border-radius:12px;padding:8px 10px;font-size:12px;margin-top:8px}
      .bk-map{display:flex;gap:8px;align-items:center;margin:6px 0}.bk-map label{font-weight:800;min-width:44px}.bk-map select{flex:0 0 150px}.bk-map input{flex:1}
      .bk-prev{white-space:pre-wrap;background:#dcf8c6;border-radius:12px 12px 12px 3px;padding:10px 12px;font-size:13.5px;margin:10px 0;box-shadow:0 1px 2px rgba(0,0,0,.08)}
      .bk-tpl{padding:11px 12px;border:1.5px solid #e2e8f0;border-radius:14px;margin-bottom:8px;cursor:pointer;background:#fff}.bk-tpl:hover{border-color:#86efac}.bk-tpl b{font-size:13.5px;display:block;margin-bottom:3px}.bk-tpl span{font-size:12.5px;color:#475569;display:block;max-height:3.9em;overflow:hidden}
      `; document.head.appendChild(st);
    };
    const bkN = () => S.bulk.set.size, bkFmt = (n) => Number(n).toLocaleString('en-IN');
    function bkParams() { const p = { scope: S.emp ? 'emp' : S.scope, q: S.q }; if (S.src) p.src = S.src; if (S.emp) p.emp = S.emp; if (S.emp && S.empTag) p.tag = 1; if (S.flt.length) p.flt = S.flt.join(','); return p; }
    function bkToggleMode(on) { bkStyle(); S.bulk.on = on == null ? !S.bulk.on : on; if (!S.bulk.on) { S.bulk.set.clear(); S.bulk.info = {}; } root.classList.toggle('bkon', S.bulk.on); drawFeeds(); drawList(); }
    function bkToggle(ph) { if (S.bulk.set.has(ph)) S.bulk.set.delete(ph); else { S.bulk.set.add(ph); const c = S.chats.find((x) => x.phone === ph); if (c) S.bulk.info[ph] = { name: c.name || '' }; } drawList(); }
    async function bkSelectAll() {
      const btn = $('bkAll'); if (btn) { btn.textContent = 'Selecting…'; btn.disabled = true; }
      try { const d = await api.get('wa.chats', Object.assign(bkParams(), { ids: 1 })); (d.ids || []).forEach((x) => { S.bulk.set.add(x.phone); S.bulk.info[x.phone] = { name: x.name || '' }; }); }
      catch (e) { S.chats.forEach((c) => { S.bulk.set.add(c.phone); S.bulk.info[c.phone] = { name: c.name || '' }; }); }
      drawList();
    }
    function bkBarHtml() {
      if (!S.bulk.on) return ''; const n = bkN(), loaded = S.chats.length, tot = Math.max(loaded, (S.counts && (S.scope === 'unassigned' ? S.counts.unassigned : S.scope === 'closed' ? S.counts.closed : S.counts.all)) || 0);
      const allSel = loaded > 0 && S.chats.every((c) => S.bulk.set.has(c.phone));
      return `<div class="bk-bar" id="bkBar"><div class="bk-top"><div class="bk-n">${n ? bkFmt(n) + ' selected' : 'Select chats'}<small>${n ? 'Tap a chat to add or remove it' : 'Tap chats, or select everything in this view'}</small></div><button type="button" class="bk-x" id="bkOff">✕ Done</button></div>
        <div class="bk-row"><button type="button" class="bk-link" id="bkAll">${allSel ? '☑ All ' + bkFmt(loaded) + ' shown are selected' : '☐ Select all in this view'}${tot > loaded ? ' (up to ' + bkFmt(Math.min(3000, tot)) + ')' : ''}</button>${n ? '<button type="button" class="bk-link" id="bkNone" style="margin-left:auto">Clear</button>' : ''}</div>
        <div class="bk-row"><button type="button" class="bk-b send" id="bkSend" ${n ? '' : 'disabled'}>📨 Send message</button><button type="button" class="bk-b move" id="bkMove" ${n ? '' : 'disabled'}>👤 Transfer</button></div></div>`;
    }
    function bkWire(L) {
      const b = $('bkBar'); if (!b) return;
      $('bkOff').onclick = () => bkToggleMode(false); $('bkAll').onclick = bkSelectAll; if ($('bkNone')) $('bkNone').onclick = () => { S.bulk.set.clear(); drawList(); };
      $('bkSend').onclick = bkSendOpen; $('bkMove').onclick = bkMoveOpen;
    }
    // ---------- 👤 transfer many chats to one person
    async function bkMoveOpen() {
      const n = bkN(); if (!n) return; const box = sheet('Transfer chats'); box.className = (box.className || '') + ' bk-sh'; box.innerHTML = '<div class="wi-empty">Loading people…</div>'; let people = [];
      try { people = (await api.get('wa.people', { all: 1 })).people || []; } catch (e) { box.innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; return; }
      let pick = 0, q = '';
      const draw = () => {
        const rows = people.filter((p) => !q || p.name.toLowerCase().includes(q.toLowerCase()));
        box.innerHTML = `<div class="bk-sh"><h4>Move ${bkFmt(n)} chat${n === 1 ? '' : 's'} to…</h4><div class="sub">The whole conversation moves with the customer. They keep their history; the new person gets the chat now.</div>
          <input class="wi-in" id="bkQ" type="search" placeholder="Search a person…" value="${esc(q)}" style="margin-bottom:10px">
          <div style="max-height:46vh;overflow:auto">${rows.map((p) => `<div class="bk-who ${pick === p.emp_id ? 'on' : ''}" data-e="${p.emp_id}"><div class="bk-av">${esc((p.name || '?').charAt(0).toUpperCase())}</div><div><b>${esc(p.name)}</b><small>${p.open != null ? p.open + ' open chats' : (p.present ? 'Present today' : 'Absent today')}</small></div><span class="bk-dot ${p.present ? '' : 'off'}" title="${p.present ? 'Present' : 'Absent'}"></span></div>`).join('') || '<div class="wi-empty">Nobody found.</div>'}</div>
          <div id="bkMsg"></div><div class="wi-act" style="margin-top:12px"><button type="button" class="wi-btn p" id="bkGo" ${pick ? '' : 'disabled'}>${pick ? 'Move ' + bkFmt(n) + ' chat' + (n === 1 ? '' : 's') + ' to ' + esc((people.find((p) => p.emp_id === pick) || {}).name || '') : 'Choose a person'}</button></div></div>`;
        box.querySelectorAll('[data-e]').forEach((el) => { el.onclick = () => { pick = +el.dataset.e; draw(); }; });
        const qi = $('bkQ'); if (qi) { qi.oninput = () => { q = qi.value; const pos = qi.selectionStart; draw(); const x = $('bkQ'); x.focus(); x.setSelectionRange(pos, pos); }; }
        const go = $('bkGo'); if (go) go.onclick = async () => {
          const who = (people.find((p) => p.emp_id === pick) || {}).name || ''; if (!pick || !confirm('Move ' + bkFmt(n) + ' chat' + (n === 1 ? '' : 's') + ' to ' + who + '?')) return;
          go.disabled = true; go.textContent = 'Moving…'; const phones = [...S.bulk.set]; let moved = 0, skipped = 0, why = {};
          try {
            for (let i = 0; i < phones.length; i += 400) { const r = await api.post('wa.bulk_assign', { phones: phones.slice(i, i + 400), emp_id: pick }); moved += r.moved || 0; skipped += r.skipped_n || 0; (r.skipped || []).forEach((x) => { why[x.why] = (why[x.why] || 0) + 1; }); go.textContent = 'Moving… ' + bkFmt(Math.min(phones.length, i + 400)) + ' / ' + bkFmt(phones.length); }
            box.innerHTML = `<div class="bk-sh" style="text-align:center;padding:14px 0"><div style="font-size:46px">✅</div><h4>${bkFmt(moved)} chat${moved === 1 ? '' : 's'} moved to ${esc(who)}</h4>${skipped ? `<div class="sub">${bkFmt(skipped)} not moved: ${Object.keys(why).map((k) => esc(k) + ' (' + why[k] + ')').join(', ')}</div>` : '<div class="sub">All done.</div>'}<div class="wi-act"><button type="button" class="wi-btn p" id="bkDone">Done</button></div></div>`;
            $('bkDone').onclick = () => { closeSheet(); bkToggleMode(false); loadList(true); };
          } catch (e) { $('bkMsg').innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; go.disabled = false; go.textContent = 'Try again'; }
        };
      };
      draw();
    }
    // ---------- the background job: progress bar at the top of the inbox, result on demand
    const bkLeave = (e) => { e.preventDefault(); e.returnValue = ''; return ''; };
    function bkJobStop() { if (S.bkjob) { S.bkjob.stop = true; } const b = $('bkStop'); if (b) { b.disabled = true; b.textContent = 'Stopping…'; } bkPill(); }
    function bkUpd() {
      const j = S.bkjob; if (!j) return; const pct = j.total ? Math.round(j.done * 100 / j.total) : 0;
      if ($('bkPb')) { $('bkPb').style.width = pct + '%'; $('bkD').textContent = bkFmt(j.done); $('bkO').textContent = bkFmt(j.ok); $('bkB').textContent = bkFmt(j.bad); }
      const pb = $('bkJobBar'); if (pb) pb.style.width = pct + '%'; const t = $('bkJobTxt'); if (t) t.innerHTML = j.running ? `📨 Sending “${esc(j.name)}” <b>${bkFmt(j.done)} / ${bkFmt(j.total)}</b> · ${bkFmt(j.ok)} sent${j.bad ? ' · <span style="color:#fecaca">' + bkFmt(j.bad) + ' not sent</span>' : ''}${j.stop ? ' · stopping…' : ''}` : `✅ “${esc(j.name)}” finished · <b>${bkFmt(j.ok)} sent</b>${j.bad ? ' · <span style="color:#fecaca">' + bkFmt(j.bad) + ' not sent</span>' : ''}`;
    }
    function bkPill() {
      bkStyle(); let el = $('bkJob'); const j = S.bkjob; if (!j) { if (el) el.remove(); return; }
      if (!el) { el = document.createElement('div'); el.id = 'bkJob'; el.className = 'bk-job'; root.appendChild(el); }
      el.innerHTML = `<div class="bk-jt"><span id="bkJobTxt"></span></div><div class="bk-jp"><i id="bkJobBar" style="width:${j.total ? Math.round(j.done * 100 / j.total) : 0}%"></i></div><div class="bk-ja">${j.running ? '<button type="button" id="bkJS">Stop</button>' : '<button type="button" id="bkJV">Details</button><button type="button" id="bkJX">✕</button>'}</div>`;
      if ($('bkJS')) $('bkJS').onclick = () => { if (confirm('Stop sending? The ones already sent stay sent.')) bkJobStop(); };
      if ($('bkJV')) $('bkJV').onclick = bkResult; if ($('bkJX')) $('bkJX').onclick = () => { S.bkjob = null; bkPill(); };
      bkUpd();
    }
    function bkResult() {
      const j = S.bkjob; if (!j) return; const box = sheet('Bulk send result'), left = Math.max(0, j.total - j.done), why = {}; j.fails.forEach((f) => { why[f.why] = (why[f.why] || 0) + 1; });
      box.innerHTML = `<div class="bk-sh" style="text-align:center"><div style="font-size:46px">${j.bad && !j.ok ? '⚠️' : '✅'}</div><h4>${bkFmt(j.ok)} message${j.ok === 1 ? '' : 's'} sent</h4><div class="bk-stats"><div class="bk-stat ok"><b>${bkFmt(j.ok)}</b><small>sent</small></div><div class="bk-stat bad"><b>${bkFmt(j.bad)}</b><small>not sent</small></div>${left ? `<div class="bk-stat"><b>${bkFmt(left)}</b><small>stopped before</small></div>` : ''}</div>
        ${j.bad ? `<div class="bk-fail" style="text-align:left"><b>Why some were not sent</b><br>${Object.keys(why).map((k) => esc(k) + ' — ' + why[k]).join('<br>')}</div>` : ''}<div class="wi-act" style="margin-top:12px">${j.bad && !j.running ? '<button type="button" class="wi-btn" id="bkRetry">Retry the ' + bkFmt(j.bad) + ' not sent</button>' : ''}<button type="button" class="wi-btn p" id="bkDone">Done</button></div></div>`;
      $('bkDone').onclick = () => { closeSheet(); loadList(true); };
      if ($('bkRetry')) $('bkRetry').onclick = () => { const ph = j.fails.map((f) => f.ph); closeSheet(); S.bkjob = null; bkRetryRun(j.t, j.map, j.hmap, ph); };
    }
    let bkRetryRun = () => { };
    // ---------- 📨 send one pre-made message to many chats (each customer gets their own first name)
    async function bkSendOpen() {
      const n = bkN(); if (!n) return; let box = sheet('Send to ' + bkFmt(n) + ' chats'); box.innerHTML = '<div class="wi-empty">Loading messages…</div>'; let list = [];
      try { list = ((await api.get('wa.tpl_list')).templates || []).filter((t) => !t.auto && sendable(t)); } catch (e) { box.innerHTML = `<div class="wi-err">${esc(e.message)}</div>`; return; }
      let q = '';
      const pickList = () => {
        const rows = list.filter((t) => !q || (t.name + ' ' + t.body).toLowerCase().includes(q.toLowerCase()));
        box.innerHTML = `<div class="bk-sh"><h4>Send to ${bkFmt(n)} chat${n === 1 ? '' : 's'}</h4><div class="sub">Choose an approved pre-made message. It goes out from each customer's own WhatsApp number, one by one.</div>
          <input class="wi-in" id="bkTq" type="search" placeholder="Search messages…" value="${esc(q)}" style="margin-bottom:10px"><div style="max-height:56vh;overflow:auto">${rows.map((t) => `<div class="bk-tpl" data-id="${t.id}"><b>${t.star ? '⭐ ' : ''}${esc(t.name.replace(/_/g, ' '))}</b><span>${esc(t.body)}</span></div>`).join('') || '<div class="wi-empty">No approved message found.</div>'}</div></div>`;
        box.querySelectorAll('.bk-tpl').forEach((el) => { el.onclick = () => compose(list.find((t) => String(t.id) === el.dataset.id)); });
        const qi = $('bkTq'); if (qi) qi.oninput = () => { q = qi.value; const pos = qi.selectionStart; pickList(); const x = $('bkTq'); x.focus(); x.setSelectionRange(pos, pos); };
      };
      const compose = (t) => {
        const nv = (String(t.body).match(/\{\{\s*\d+\s*\}\}/g) || []).length, hv = !!t.header_var, H = []; for (let i = 1; i <= nv; i++) H[i - 1] = tplVarHint(t, i);
        const map = []; for (let i = 0; i < nv; i++) map[i] = { how: H[i].k === 'name' ? 'name' : 'text', text: H[i].k === 'date' ? (H[i].eg || '') : '' }; const hmap = { how: 'name', text: '' };
        const sample = (() => { const ph = [...S.bulk.set][0]; return (S.bulk.info[ph] || {}).name || ''; })();
        const vals = (name) => map.map((m) => m.how === 'name' ? (name || 'ji') : m.text.trim());
        const draw = () => {
          let h = `<div class="bk-sh"><button type="button" class="wi-btn" id="bkBack" style="margin-bottom:8px">‹ Back</button><h4>${esc(t.name.replace(/_/g, ' '))}</h4><div class="sub">Fill each blank. "First name" gives every customer their own name.</div>`;
          if (hv) h += `<div class="bk-map"><label>Head</label><select data-h="how"><option value="name"${hmap.how === 'name' ? ' selected' : ''}>First name</option><option value="text"${hmap.how === 'text' ? ' selected' : ''}>Same text</option></select>${hmap.how === 'text' ? `<input class="wi-in" data-h="text" value="${esc(hmap.text)}" placeholder="Heading text">` : ''}</div>`;
          for (let i = 0; i < nv; i++) h += `<div class="bk-map"><label>{{${i + 1}}}</label><select data-i="${i}" data-k="how"><option value="name"${map[i].how === 'name' ? ' selected' : ''}>First name</option><option value="text"${map[i].how === 'text' ? ' selected' : ''}>Same text</option></select>${map[i].how === 'text' ? `<input class="wi-in" data-i="${i}" data-k="text" value="${esc(map[i].text)}" placeholder="${H[i].eg ? 'e.g. ' + esc(H[i].eg) : 'Write it here'}">` : '<span style="color:#64748b;font-size:12.5px">e.g. ' + esc(sample ? firstName({ name: sample }) || 'ji' : 'Rahul') + '</span>'}</div>`;
          h += `<div class="bk-prev" id="bkPrev"></div><div id="bkMsg"></div><div class="wi-act"><button type="button" class="wi-btn p" id="bkGo">📨 Send to ${bkFmt(n)} chat${n === 1 ? '' : 's'}</button></div><div class="ft" style="color:#64748b;font-size:12px;margin-top:8px">Customers who opted out are skipped automatically.</div></div>`;
          box.innerHTML = h;
          const prev = () => { const v = vals(firstName({ name: sample })); $('bkPrev').textContent = (t.header_text ? fill(t.header_text, [hmap.how === 'name' ? (firstName({ name: sample }) || 'ji') : hmap.text]) + '\n' : '') + fill(t.body, v) + (t.footer ? '\n' + t.footer : ''); }; prev();
          $('bkBack').onclick = pickList;
          box.querySelectorAll('select').forEach((s) => { s.onchange = () => { if (s.dataset.h) hmap.how = s.value; else map[+s.dataset.i].how = s.value; draw(); }; });
          box.querySelectorAll('input[data-k="text"],input[data-h="text"]').forEach((x) => { x.oninput = () => { if (x.dataset.h) hmap.text = x.value; else map[+x.dataset.i].text = x.value; prev(); }; });
          $('bkGo').onclick = () => {
            for (let i = 0; i < nv; i++) if (map[i].how === 'text') { if (!map[i].text.trim()) { $('bkMsg').innerHTML = `<div class="wi-err">Fill {{${i + 1}}} first.</div>`; return; } const bad = tplVarBad(H[i] || {}, map[i].text.trim()); if (bad) { $('bkMsg').innerHTML = `<div class="wi-err">${esc(bad)}</div>`; return; } }
            if (hv && hmap.how === 'text' && !hmap.text.trim()) { $('bkMsg').innerHTML = '<div class="wi-err">Fill the heading first.</div>'; return; }
            if (!confirm('Send "' + t.name.replace(/_/g, ' ') + '" to ' + bkFmt(n) + ' chat' + (n === 1 ? '' : 's') + ' now?')) return;
            run(t, map, hmap, [...S.bulk.set]);
          };
        };
        draw();
      };
      const run = (t, map, hmap, phones) => {
        if (S.bkjob && S.bkjob.running) { alert('A bulk send is still running. Wait for it to finish or press Stop in the bar at the top.'); return; }
        const job = S.bkjob = { name: t.name.replace(/_/g, ' '), total: phones.length, done: 0, ok: 0, bad: 0, stop: false, running: true, fails: [], idx: 0, phones, t, map, hmap };
        box.innerHTML = `<div class="bk-sh" style="text-align:center"><h4>Sending “${esc(job.name)}”…</h4><div class="sub">It keeps sending in the background — you can minimise this and carry on with your chats.</div><div class="bk-prog"><i id="bkPb"></i></div><div class="bk-stats"><div class="bk-stat"><b id="bkD">0</b><small>of ${bkFmt(job.total)}</small></div><div class="bk-stat ok"><b id="bkO">0</b><small>sent</small></div><div class="bk-stat bad"><b id="bkB">0</b><small>not sent</small></div></div><div class="wi-act"><button type="button" class="wi-btn p" id="bkMin">⬇ Minimise — keep sending</button><button type="button" class="wi-btn" id="bkStop">Stop</button></div></div>`;
        $('bkMin').onclick = () => closeSheet(); $('bkStop').onclick = () => bkJobStop();
        if (S.bulk.on) bkToggleMode(false);   // the selection is used up: the list is free again
        window.addEventListener('beforeunload', bkLeave); bkPill(); bkUpd();
        const one = async (ph) => {
          const nm = firstName({ name: (S.bulk.info[ph] || {}).name || '' }) || 'ji', v = job.map.map((m) => m.how === 'name' ? nm : m.text.trim());
          try { await api.post('wa.tpl_send', { phone: ph, id: job.t.id, vars: v, header_var: job.t.header_var ? (job.hmap.how === 'name' ? nm : job.hmap.text.trim()) : '' }); job.ok++; }
          catch (e) { job.bad++; job.fails.push({ ph, name: (S.bulk.info[ph] || {}).name || ph, why: e.message || 'failed' }); }
          job.done++; bkUpd();
        };
        const worker = async () => { while (!job.stop && job.idx < job.total) { const ph = job.phones[job.idx++]; await one(ph); } };
        Promise.all([worker(), worker(), worker(), worker()]).then(() => { job.running = false; window.removeEventListener('beforeunload', bkLeave); bkUpd(); bkPill(); if ($('bkPb')) bkResult(); });
      };
      bkRetryRun = (tt, mm, hh, ph) => { box = sheet('Sending…'); run(tt, mm, hh, ph); };
      pickList();
    }

    try { if (!admin && !window.WaNotices) { const s = document.createElement('script'); s.src = 'assets/js/wa_notices.js?v=3'; document.head.appendChild(s); } } catch (e) { }   // 🔔 "X took over your chat" bar (your own chats only)
    // ---------- start
    (async function init() {
      if (!admin) { try { S.people = (await api.get('wa.people')).people || []; } catch (e) { S.people = []; } }
      if (admin) {
        try { const p = (await api.get('wa.people')).people || []; $('wiE').innerHTML = '<option value="0">Everyone</option>' + p.map((x) => `<option value="${x.emp_id}">${esc(x.name)}${x.present ? '' : ' (absent)'}</option>`).join(''); } catch (e) { }
      }
      try { S.quick = (await api.get('wa.quick_list')).items || []; } catch (e) { S.quick = []; }
      await loadList();
      if (admin && S.counts && !S.counts.unassigned && S.counts.all) { S.scope = 'all'; await loadList(); }
      const want = new URLSearchParams(location.search).get('phone'); if (want) openChat(String(want).replace(/\D/g, '').slice(-10));
      setInterval(() => { if (document.hidden || !root.offsetParent) return; loadList(true); refreshChat(); }, 10000);   // only while the Chats screen is on view (another WhatsApp screen = no polling)
      if (!admin) { pollCall(); setInterval(pollCall, 12000); }
      document.addEventListener('visibilitychange', () => { if (document.hidden) { if (AU.run) auStop(false); } else { loadList(true); refreshChat(); auArm(); } });
    })();
  }
  window.WaInbox = { start };
})();
