/**
 * wa_app.js — the WhatsApp chat app for salespeople (my_chats.html). Phone first: small, compact, fast.
 *   Chats   only the chats given to YOU; longest-waiting on top with a live timer; a customer who writes again jumps to the top.
 *   Chat    the Claude reply is already written when you open it (one tap to send); photos, video, voice, gallery, PDF, quick replies, pre-made messages.
 *   Finish  every chat ends with a result (order, will order later, price, not interested …) — that is what feeds tags, reports and the Claude suggestions.
 *   History a chat leaves the list 24 hours after the customer's last message; it comes back by itself when the customer writes again.
 *   Me      today's orders against the target, streak, leaderboard, phone alerts.
 * Needs api.js. Talks to wa.g_* (empmanagment/wa_growth.php) and the existing wa.chat / wa.send / wa.send_media / wa.tpl_* / wa.quick_list / wa.media.
 */
(function () {
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // turn web addresses in an (already escaped) message into tappable links that open in a new tab
  const linkify = (h) => String(h).replace(/\b(?:https?:\/\/|www\.)[^\s<]+/gi, (u) => { let t = ''; const m = /(?:[.,!?;:)\]]|&quot;|&#39;)+$/.exec(u); if (m) { t = m[0]; u = u.slice(0, -t.length); } const href = /^www\./i.test(u) ? 'https://' + u : u; return `<a href="${href}" target="_blank" rel="noopener noreferrer" style="color:#027eb5;text-decoration:underline;word-break:break-all">${u}</a>` + t; });
  const money = (n) => '₹' + Number(n || 0).toLocaleString('en-IN');
  const pretty = (p) => { p = String(p || ''); return p.length === 10 ? '+91 ' + p.slice(0, 5) + ' ' + p.slice(5) : p; };
  const parse = (s) => { const m = /^(\d{4})-(\d\d)-(\d\d)[ T](\d\d):(\d\d)(?::(\d\d))?/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) : null; };
  const hhmm = (s) => { const d = parse(s); return d ? d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }) : ''; };
  const dayLbl = (s) => { const d = parse(s); if (!d) return ''; const t = new Date(); const y = new Date(Date.now() - 864e5); const same = (a, b) => a.toDateString() === b.toDateString(); return same(d, t) ? 'Today' : same(d, y) ? 'Yesterday' : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }); };
  const ago = (days) => days <= 0 ? 'today' : days === 1 ? 'yesterday' : days < 30 ? days + ' days ago' : Math.round(days / 30) + ' mo ago';
  const mmss = (sec) => { sec = Math.max(0, Math.floor(sec)); if (sec < 3600) return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0'); const h = Math.floor(sec / 3600); return h + 'h ' + Math.floor((sec % 3600) / 60) + 'm'; };
  const initials = (n, p) => { const w = String(n || '').trim().split(/\s+/).filter(Boolean); return (w.length ? (w[0][0] + (w[1] ? w[1][0] : '')) : String(p || '').slice(-2)).toUpperCase(); };
  const hue = (s) => { let h = 0; for (const c of String(s)) h = (h * 31 + c.charCodeAt(0)) % 360; return h; };
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
  const first = (n) => (String(n || '').trim().split(/\s+/)[0] || '');
  const MB = 1024 * 1024;

  const CSS = `
  .wx:not(.cxm) #wxCxR{display:none !important}.wx.cxm #wxPf,.wx.cxm #wxOr,.wx.cxm #wxOd,.wx.cxm #wxCt,.wx.cxm #wxLs,.wx.cxm #wxPrc,.wx.cxm #wxFin{display:none !important}.wx.cxm #wxCxR{background:#f5f0ff;color:#5b21b6}
  .wx.emb>*:not(.wx-chat):not(.wx-md):not(.wx-toast){visibility:hidden}.wx.emb .wx-chat{left:0 !important;border-left:0 !important;animation:none}.wx.emb .wx-chat .bk{display:none !important}.wx.emb::after{display:none !important}html.wx-emb body{overflow:hidden}
  .wx{--g:#16a34a;--gd:#0f7a3a;--ink:#0f172a;--mut:#64748b;--line:#e8ecf2;--bg:#f4f6fa;--or:#f97316;--rd:#e5484d;--bl:#3b82f6;--pu:#7c5cfc;position:fixed;inset:0;display:flex;flex-direction:column;background:var(--bg);color:var(--ink);font-size:14px;overflow:hidden}
  .wx *{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}.wx button{font:inherit;color:inherit;border:0;background:none;cursor:pointer}.wx input,.wx textarea,.wx select{font:inherit;color:inherit}
  .wx [hidden]{display:none !important}
  .wx-top{display:flex;align-items:center;gap:8px;padding:calc(10px + env(safe-area-inset-top)) 14px 8px;background:var(--bg)}.wx-top h1{font-size:19px;font-weight:800;flex:1}
  .wx-pill{background:#fff;border:1px solid var(--line);border-radius:20px;padding:5px 11px;font-size:12.5px;font-weight:700;white-space:nowrap}.wx-pill.g{background:#dcfce7;border-color:#bbf7d0;color:#166534}.wx-pill.o{background:#ffedd5;border-color:#fed7aa;color:#9a3412}
  .wx-main{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding-bottom:8px}
  .wx-tabs{display:flex;background:#fff;border-top:1px solid var(--line);padding:6px 0 calc(8px + env(safe-area-inset-bottom))}.wx-tabs button{flex:1;text-align:center;font-size:11px;font-weight:700;color:#94a3b8;position:relative}.wx-tabs button.on{color:var(--g)}.wx-tabs i{display:block;font-style:normal;font-size:19px;line-height:1.2}
  .wx-tabs b{position:absolute;top:-2px;left:56%;background:var(--rd);color:#fff;border-radius:9px;min-width:17px;padding:1px 5px;font-size:11px}
  .wx-seg{display:flex;gap:6px;padding:0 14px 8px;overflow-x:auto}.wx-seg button{background:#fff;border:1px solid var(--line);border-radius:18px;padding:6px 12px;font-size:12.5px;font-weight:700;white-space:nowrap}.wx-seg button.on{background:var(--g);border-color:var(--g);color:#fff}
  .wx-card{background:#fff;border-radius:18px;border:1px solid var(--line);margin:0 12px 10px;padding:4px 12px}
  .wx-card.wx-lst{margin:0 0 6px;border-radius:0;border-width:1px 0;padding:0 12px}
  .wx-sp{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:#fff;border:1px solid var(--line);color:var(--ink);flex:none;text-decoration:none}.wx-sp:active{background:var(--bg)}
  .wx-top{padding-left:10px;padding-right:10px}.wx-row{padding:8px 0}
  .wx-h{font-size:11px;font-weight:800;letter-spacing:.4px;color:var(--mut);padding:8px 16px 4px;text-transform:uppercase}.wx-h.r{color:var(--rd)}
  .wx-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--line);cursor:pointer}.wx-row:last-child{border:0}
  .wx-av{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:800;font-size:13px;flex:none;position:relative}
  .wx-nm{font-weight:700;font-size:14px;display:flex;gap:5px;align-items:center}.wx-sub{font-size:12.5px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.wx-mid{flex:1;min-width:0}
  .wx-tm{text-align:right;font-size:11px;color:var(--mut);flex:none}.wx-t{font-weight:800;font-variant-numeric:tabular-nums}.wx-t.ok{color:var(--g)}.wx-t.w{color:var(--or)}.wx-t.r{color:var(--rd)}
  .wx-dot{display:inline-block;background:var(--g);color:#fff;border-radius:10px;font-size:11px;padding:1px 6px;margin-top:3px;font-weight:700}
  .wx-chip{display:inline-block;border-radius:20px;padding:2px 8px;font-size:11px;font-weight:800;margin-right:3px}.wx-chip.g{background:#dcfce7;color:#166534}.wx-chip.o{background:#ffedd5;color:#9a3412}.wx-chip.b{background:#dbeafe;color:#1e40af}.wx-chip.p{background:#ede9fe;color:#5b21b6}.wx-chip.r{background:#fee2e2;color:#991b1b}.wx-chip.m{background:#e2e8f0;color:#475569}
  .wx-empty{padding:44px 24px;text-align:center;color:var(--mut);line-height:1.6}.wx-empty b{display:block;font-size:34px;margin-bottom:6px}
  .wx .wx-btn{background:var(--g);color:#fff;border-radius:12px;padding:9px 15px;font-weight:800;font-size:13px;display:inline-block;min-height:38px}.wx .wx-btn.gh{background:#fff;color:var(--ink);border:1px solid var(--line)}.wx .wx-btn.r{background:#fff;color:var(--rd);border:1px solid #fecaca}.wx .wx-btn:disabled{opacity:.5}
  .wx-banner{margin:0 12px 10px;border-radius:14px;padding:10px 12px;font-size:12.5px;display:flex;gap:8px;align-items:center;font-weight:600}.wx-banner.y{background:#fef9c3;color:#854d0e}.wx-banner.b{background:#dbeafe;color:#1e40af}.wx-banner.g{background:#dcfce7;color:#166534}
  /* chat */
  .wx-chat{position:absolute;inset:0;background:var(--bg);display:flex;flex-direction:column;z-index:20;animation:wxin .18s ease-out}@keyframes wxin{from{transform:translateX(24px);opacity:0}to{transform:none;opacity:1}}
  .wx-ch{display:flex;align-items:center;gap:8px;padding:calc(8px + env(safe-area-inset-top)) 10px 8px;background:#fff;border-bottom:1px solid var(--line)}.wx-ch .bk{font-size:26px;line-height:1;padding:0 6px;color:var(--mut)}
  .wx .wx-ic{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;font-size:18px;background:var(--bg);flex:none}.wx .wx-ic.g{background:var(--g);color:#fff}
  .wx-sw{display:flex;gap:6px;align-items:center;font-size:11.5px;background:#fff;border-bottom:1px solid var(--line);padding:5px 12px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.wx-sw b{color:var(--ink)}.wx-sw .go{color:var(--g);font-weight:800}
  .wx-msgs{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:8px 0}.wx-day{text-align:center;font-size:11px;color:var(--mut);margin:8px 0 2px;font-weight:700}
  .wx-b{max-width:80%;padding:7px 11px 5px;border-radius:15px;font-size:14px;line-height:1.36;margin:2px 12px;width:fit-content;white-space:pre-wrap;word-break:break-word;position:relative}.wx-b.in{background:#fff;border:1px solid var(--line);border-bottom-left-radius:4px}.wx-b.out{background:#dcf8c6;margin-left:auto;border-bottom-right-radius:4px}.wx-b.bot{background:#e0f2fe}.wx-b.tpl{background:#ecfdf5}
  .wx-b small{display:block;text-align:right;font-size:11px;color:var(--mut);margin-top:1px}.wx-b small .f{color:var(--rd);font-weight:800}.wx-b small .r{color:var(--bl)}.wx-b.tmp{opacity:.65}
  .wx-b img{max-width:100%;max-height:260px;border-radius:10px;display:block;margin-bottom:3px}.wx-b video{max-width:100%;max-height:260px;border-radius:10px;display:block}.wx-b audio{max-width:100%;height:36px}.wx-ld{color:var(--bl);font-weight:700;cursor:pointer;padding:8px 2px}
  .wx-ev{text-align:center;font-size:11px;color:var(--mut);margin:6px 18px}.wx-tag{font-size:11px;font-weight:800;color:var(--gd);display:block;margin-bottom:1px}
  .wx-sug{position:relative;margin:0;background:#fff;border-top:1px solid #e9edef;font-size:13px}.wx-sug .bar{display:block;height:4px;background:#d9fdd3;overflow:hidden}.wx-sug .bar u{display:block;height:100%;width:100%;background:#00a884;animation:wxBar linear forwards}@keyframes wxBar{to{width:0}}
  .wx-sug .r{display:flex;align-items:center;gap:6px;padding:5px 8px}.wx-sug .ic{flex:none;font-size:14px}.wx-sug .t{flex:1;min-width:0;font-size:12.5px;line-height:1.3;color:#111b21;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;white-space:pre-wrap}.wx-sug .t.ld{color:var(--mut)}
  .wx-sug button{flex:none;border:0;border-radius:999px;min-height:30px;padding:0 12px;font-size:12.5px;font-weight:700;background:#00a884;color:#fff}.wx-sug button b{font-variant-numeric:tabular-nums}.wx-sug button.x{background:#f0f2f5;color:#54656f;width:30px;padding:0;font-size:14px}.wx-sug em{flex:none;font-style:normal;font-size:13px}
  @media (prefers-reduced-motion:reduce){.wx-sug .bar u{animation-timing-function:steps(20)}}
  .wx-lock{margin:6px 12px;background:#f1f5f9;border-radius:12px;padding:9px 12px;font-size:12.5px;color:#475569;font-weight:600;display:flex;gap:8px;align-items:center}.wx-lock span{flex:1}
  .wx-bar textarea.cxl{background:#fff7ed !important;box-shadow:inset 0 0 0 1.5px #fdba74}
  .wx-tw{flex:1;min-width:0;position:relative;display:flex}.wx-tw textarea{flex:1;width:100%;padding-right:46px!important}
  .wx-qrb{position:absolute;right:6px;bottom:4px;width:36px;height:36px;border:0;border-radius:50%;background:transparent;font-size:19px;cursor:pointer;color:#54656f;display:grid;place-items:center}.wx-qrb:active{background:rgba(0,0,0,.07)}
  .wx-qs{position:sticky;top:0;z-index:2;background:inherit;padding:2px 0 8px}.wx-sh .wx-qs{background:#fff}html.wxdk .wx-sh .wx-qs{background:#111b21}
  .wx-bar{display:flex;gap:7px;align-items:flex-end;padding:7px 9px calc(9px + env(safe-area-inset-bottom));background:#fff;border-top:1px solid var(--line)}
  .wx-bar textarea{flex:1;background:var(--bg);border:0;border-radius:20px;padding:9px 14px;font-size:16px;max-height:110px;resize:none;outline:none;line-height:1.3}.wx-bar .wx-ic{background:var(--bg)}.wx .wx-bar .snd{background:var(--g);color:#fff}
  .wx-rec{flex:1;display:flex;align-items:center;gap:10px;padding:6px 8px;font-weight:700;color:var(--rd)}.wx-rec i{width:10px;height:10px;border-radius:50%;background:var(--rd);animation:wxp 1s infinite}@keyframes wxp{50%{opacity:.25}}
  /* sheets */
  .wx-md{position:absolute;inset:0;background:rgba(15,23,42,.5);z-index:40;display:flex;align-items:flex-end;animation:wxf .15s}@keyframes wxf{from{opacity:0}to{opacity:1}}
  .wx-sh{background:#fff;width:100%;max-height:88%;border-radius:22px 22px 0 0;display:flex;flex-direction:column;animation:wxu .2s ease-out}@keyframes wxu{from{transform:translateY(40px)}to{transform:none}}
  .wx-sh>h3{padding:14px 16px 6px;font-size:16px;display:flex;align-items:center}.wx-sh>h3 span{flex:1}.wx-sh>h3 button{font-size:20px;color:var(--mut);padding:0 4px;min-width:44px;min-height:44px}.wx-sc{overflow-y:auto;padding:4px 16px calc(16px + env(safe-area-inset-bottom));-webkit-overflow-scrolling:touch}
  .wx-g4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;text-align:center;font-size:11.5px;font-weight:700;padding:8px 0 4px}.wx-g4 button i{display:grid;place-items:center;width:54px;height:54px;border-radius:50%;margin:0 auto 5px;font-style:normal;font-size:23px}
  .wx-in{width:100%;border:1px solid var(--line);border-radius:12px;padding:10px 12px;font-size:16px;background:#fff;outline:none;margin:4px 0}.wx-in:focus{border-color:var(--g)}textarea.wx-in{min-height:70px;resize:vertical}
  .wx-lb{font-size:12px;font-weight:800;color:var(--mut);margin-top:10px;display:block}
  .wx-res{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:6px 0}.wx-res button{border:1.5px solid var(--line);border-radius:14px;padding:11px 8px;font-weight:700;font-size:13px;text-align:left;display:flex;gap:7px;align-items:center}.wx-res button.on{border-color:var(--g);background:#f0fdf4;color:var(--gd)}.wx-res button i{font-style:normal;font-size:19px}
  .wx-gr{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}.wx-th{aspect-ratio:1;border-radius:13px;background:#e8ecf2 center/cover no-repeat;position:relative;overflow:hidden;display:grid;place-items:center;font-size:26px}.wx-th small{position:absolute;left:0;right:0;bottom:0;background:linear-gradient(transparent,#000a);color:#fff;font-size:11px;padding:10px 5px 3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .wx-th.sel{outline:3px solid var(--g);outline-offset:-3px}.wx-th .ck{position:absolute;top:5px;right:5px;width:22px;height:22px;border-radius:50%;background:var(--g);color:#fff;display:none;place-items:center;font-size:13px;font-weight:800}.wx-th.sel .ck{display:grid}
  .wx-fo{display:flex;gap:6px;overflow-x:auto;padding:4px 0 8px}.wx-fo button{background:var(--bg);border-radius:16px;padding:6px 12px;font-size:12.5px;font-weight:700;white-space:nowrap}.wx-fo button.on{background:var(--ink);color:#fff}.wx-fo button.cl{background:#dcfce7;color:#166534}
  .wx-kv{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid var(--line);font-size:13px}.wx-kv:last-child{border:0}.wx-kv span{color:var(--mut)}.wx-kv b{text-align:right}
  .wx-bubp{background:#dcf8c6;border-radius:13px;padding:9px 12px;white-space:pre-wrap;line-height:1.4;margin:8px 0;font-size:13.5px}
  .wx-big{font-size:34px;font-weight:800}.wx-bar2{height:10px;background:#e2e8f0;border-radius:6px;overflow:hidden;margin:8px 0}.wx-bar2 i{display:block;height:100%;background:linear-gradient(90deg,#22c55e,#16a34a);border-radius:6px;transition:width .6s}
  .wx-lbd{display:flex;align-items:center;gap:9px;padding:8px 0;border-bottom:1px solid var(--line)}.wx-lbd:last-child{border:0}.wx-lbd.me{background:#f0fdf4;margin:0 -8px;padding:8px;border-radius:10px}.wx-lbd .rk{width:22px;font-weight:800;color:var(--mut)}.wx-lbd .n{flex:1;font-weight:700}
  .wx-win{position:absolute;inset:0;z-index:60;background:rgba(15,23,42,.62);display:grid;place-items:center;animation:wxf .2s;overflow:hidden}.wx-win .c{background:#fff;border-radius:26px;padding:26px 22px;text-align:center;width:300px;max-width:88%;animation:wxpop .35s cubic-bezier(.2,1.4,.4,1)}@keyframes wxpop{from{transform:scale(.6);opacity:0}to{transform:none;opacity:1}}.wx-win .big{font-size:56px}.wx-win h2{font-size:21px;margin:4px 0}
  .wx-cf{position:absolute;top:-20px;font-size:22px;animation:wxfall linear forwards;pointer-events:none}@keyframes wxfall{to{transform:translateY(110vh) rotate(540deg)}}
  .wx-toast{position:absolute;left:50%;bottom:calc(86px + env(safe-area-inset-bottom));transform:translateX(-50%);background:#0f172a;color:#fff;padding:10px 16px;border-radius:99px;font-weight:700;font-size:13px;z-index:80;max-width:90%;text-align:center}.wx-toast.bad{background:#b42318}
  .wx-sk{height:62px;margin:0 12px 8px;border-radius:16px;background:linear-gradient(90deg,#eef1f6 25%,#f8fafc 50%,#eef1f6 75%);background-size:200% 100%;animation:wxsk 1.1s infinite}@keyframes wxsk{to{background-position:-200% 0}}
  /* ===== WhatsApp look (phone): green bars, beige wallpaper, real bubbles ===== */
  .wx{--g:#00a884;--gd:#0f6e7e;--ink:#111b21;--mut:#667781;--line:#e9edef;--bg:#fff;font-size:14px;font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
  .wx-top{background:#0f6e7e;color:#fff;padding:calc(8px + env(safe-area-inset-top)) 10px 8px;gap:8px}.wx-top h1{font-size:20px;font-weight:600;color:#fff;letter-spacing:.1px}
  .wx-sp{width:32px;height:32px;background:rgba(255,255,255,.16);border:0;color:#fff}
  .wx .wx-b{position:relative}.wx .wx-rb{position:absolute;top:4px;right:-30px;width:26px;height:26px;border-radius:50%;border:0;background:rgba(0,0,0,.08);color:#54656f;font-size:14px;cursor:pointer;opacity:0;transition:opacity .15s;padding:0}.wx .wx-b.out .wx-rb{right:auto;left:-30px}.wx .wx-b:hover .wx-rb{opacity:1}@media (hover:none){.wx .wx-rb{display:none}}.wx .wx-rpb{display:flex;gap:8px;align-items:center;padding:6px 10px;background:#f0f2f5;border-top:1px solid #e9edef}.wx .wx-rpb>button{border:0;background:none;font-size:18px;color:#54656f;cursor:pointer;padding:4px 8px}.wx .wx-qt{display:block;border-left:4px solid #06cf9c;background:rgba(0,0,0,.06);border-radius:8px;padding:5px 8px;margin:0 0 5px;cursor:pointer;font-size:13px;line-height:1.35}.wx .wx-qt b{display:block;color:#059669;font-size:12px}.wx .wx-qt span{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;opacity:.8}@keyframes wxFl{0%,60%{box-shadow:0 0 0 3px #fbbf24}100%{box-shadow:none}}.wx .wx-fl{animation:wxFl 1.6s ease}
  .wx .wx-row.sel,.wx .wx-row.sel.h-hot,.wx .wx-row.sel.h-late,.wx .wx-row.sel.h-active,.wx .wx-row.sel.h-slow,.wx .wx-row.sel.h-cx{background:#cdeedd!important;box-shadow:inset 4px 0 0 #00a884!important}.wx .wx-row.sel .wx-nm{font-weight:800}
  html.wxdk .wx .wx-row.sel,html.wxdk .wx .wx-row.sel[class*="h-"]{background:#1f4537!important;box-shadow:inset 4px 0 0 #00a884!important}
  .wx-fb{display:flex;align-items:center;gap:8px;margin:8px 12px 2px;padding:0 10px;height:40px;border-radius:10px;background:#f0f2f5;border:1.5px solid transparent;flex:none}.wx-fb:focus-within{background:#fff;border-color:#00a884}
  .wx-fb .ic{font-size:14px;opacity:.7}.wx-fb input{flex:1;min-width:0;border:0;background:transparent;font-size:14.5px;outline:none;color:inherit;height:100%}.wx-fb input::-webkit-search-cancel-button{display:none}.wx-fb button{border:0;background:none;font-size:15px;color:#54656f;cursor:pointer;padding:4px}
  .wx.wx-fnd #wxSeg{display:none!important}html.wxdk .wx-fb{background:#202c33}html.wxdk .wx-fb:focus-within{background:#111b21}#wxFR .wx-fd{width:100%;text-align:left}
  .wx-fd small{display:block;font-weight:500;color:#667781;font-size:12px;margin-top:1px}#wxFR .wx-fd.sel{background:#cdeedd;box-shadow:inset 4px 0 0 #00a884}.wx-fd .tg{display:inline-block;align-self:flex-start;white-space:nowrap;margin-top:2px;font-size:11px;font-weight:700;padding:1px 6px;border-radius:6px;background:#e7f8f0;color:#0a7a55;margin-left:6px}.wx-fd .tg.o{background:#f1f2f4;color:#54656f}
  .wx .wx-top #wxDk{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;font-size:15px;line-height:32px;flex:none}.wx-sp:active{background:rgba(255,255,255,.3)}
  .wx-top .wx-pill{background:rgba(255,255,255,.16);border:0;color:#fff;padding:4px 10px;font-size:12px;font-weight:600}.wx-top .wx-pill.g{background:#d9fdd3;color:#0f6e7e}.wx-top .wx-pill.o{background:#ffedd5;color:#9a3412}
  .wx-seg{background:#fff;padding:8px 12px;gap:6px;border-bottom:1px solid #f0f2f5}.wx-seg button{background:#f0f2f5;border:0;color:#54656f;padding:5px 12px;font-size:13px;font-weight:500}.wx-seg button.on{background:#d9fdd3;color:#0f6e7e;border:0}
  .wx-main{background:#fff}
  .wx-row{padding:8px 12px;gap:12px;border-bottom:0}.wx-row:active{background:#f5f6f6}.wx-av{width:46px;height:46px;font-size:15px;font-weight:600}
  .wx-nm{font-weight:500;font-size:16px;color:#111b21}.wx-sub{font-size:13.5px;color:#667781}.wx-tm{font-size:12px;color:#667781}.wx-dot{background:#25d366;color:#fff;min-width:20px;height:20px;line-height:20px;text-align:center;padding:0 6px;border-radius:10px;font-size:12px;font-weight:600}
  .wx-card.wx-lst{border:0;margin:0;padding:0}
  .wx-h{background:#fff;padding:10px 14px 2px;color:#0f6e7e;font-size:12.5px;font-weight:600;letter-spacing:.2px}.wx-h.r{color:#e5484d}
  .wx-tabs{background:#fff;border-top:1px solid #e9edef;padding:6px 0 calc(6px + env(safe-area-inset-bottom))}.wx-tabs button{color:#667781;font-size:12px;font-weight:500}.wx-tabs button.on{color:#111b21;font-weight:700}.wx-tabs i{display:table;margin:0 auto 2px;padding:2px 20px;border-radius:14px;font-size:18px}.wx-tabs button.on i{background:#d9fdd3}.wx-tabs b{background:#25d366;left:58%}
  .wx-chat{background:#efeae2 radial-gradient(circle at 12px 12px,rgba(11,20,26,.045) 1.4px,transparent 1.6px) 0 0/26px 26px}
  /* 🖥 PC (wide screen): the list stays on the left, the open chat on the right — like the admin Chats */
  @media (min-width:1000px){.wx{--lw:clamp(360px,32vw,460px)}.wx>*:not(.wx-chat):not(.wx-md):not(.wx-toast){width:var(--lw);max-width:var(--lw);box-sizing:border-box}.wx-tabs{border-right:1px solid var(--line)}.wx-main{border-right:1px solid var(--line)}
    .wx-chat{left:var(--lw);border-left:1px solid var(--line);animation:none}.wx-chat .bk{display:none}.wx::after{content:'💬 Pick a chat on the left';position:absolute;left:var(--lw);right:0;top:45%;text-align:center;color:#94a3b8;font-size:15px;font-weight:600;pointer-events:none}
    .wx-row.sel{background:#e7f8f0}html.wxdk .wx-row.sel{background:#103529}}
  .wx-ch{background:#0f6e7e;color:#fff;border:0;padding:calc(6px + env(safe-area-inset-top)) 8px 6px;gap:8px}.wx-ch .bk{color:#fff;font-size:28px;padding:0 2px 2px}.wx-ch .wx-nm{color:#fff;font-size:16.5px;font-weight:500}.wx-ch .wx-sub{color:rgba(255,255,255,.82);font-size:12px}
  .wx-row.h-hot{background:#effbf3;box-shadow:inset 3px 0 0 #22c55e}.wx-row.h-late{background:#fff8e6;box-shadow:inset 3px 0 0 #f59e0b}.wx-row.h-active{background:#f3f8ff;box-shadow:inset 3px 0 0 #60a5fa}.wx-row.h-slow{background:#f6f7f9;box-shadow:inset 3px 0 0 #c4c9d1}.wx-row.h-cx{background:#f7f4ff;box-shadow:inset 3px 0 0 #a78bfa}.wx-row.h-biz{background:#f1f1f1;box-shadow:inset 3px 0 0 #9ca3af;opacity:.8}
  html.wxdk .wx-row.h-hot{background:#10261a}html.wxdk .wx-row.h-late{background:#2a220e}html.wxdk .wx-row.h-active{background:#0f1e2e}html.wxdk .wx-row.h-slow{background:#1a2026}html.wxdk .wx-row.h-cx{background:#201a33}html.wxdk .wx-row.h-biz{background:#1c1f22}
  .wx-lg{display:flex;gap:9px;flex-wrap:wrap;padding:5px 14px;font-size:11px;color:#667781}.wx-lg i{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:3px}
  .wx-tm{display:flex;gap:6px;padding:8px 12px 0}.wx-tm select{flex:0 0 40%;min-width:0;border:1px solid #d0d5dd;border-radius:10px;padding:8px;font-size:14px;background:#fff}.wx-tm input{flex:1;margin:0 !important}
  .wx .wx-rt{border:0;background:#fde4e4;color:#b3261e;border-radius:10px;padding:2px 8px;font-size:11.5px;font-weight:700;margin-left:4px}
  .wx .wx-ch .wx-ib{display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:44px;height:42px;padding:0 4px;border-radius:12px;font-size:17px;line-height:1.05;background:rgba(255,255,255,.16);color:#fff;flex:none;border:0}.wx .wx-ch .wx-ib small{font-size:11px;font-weight:600;margin-top:2px;letter-spacing:.1px}.wx .wx-ch .wx-ib.g{background:#fff;color:#0f6e7e}
  .wx-ch .wx-mid{min-width:0;flex:1}.wx-ch .wx-nm{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}
  .wx-mi{display:flex;gap:12px;align-items:center;width:100%;text-align:left;padding:12px 6px;border:0;background:none;border-bottom:1px solid #eef0f2;font:inherit;color:inherit}.wx-mi span{font-size:22px;width:32px;text-align:center}.wx-mi b{font-weight:600;font-size:15px;display:flex;flex-direction:column}.wx-mi small{font-weight:400;font-size:12.5px;color:#667781;margin-top:2px}
  html.wxdk .wx-mi{border-bottom-color:#222d34}html.wxdk .wx .wx-ch .wx-ib{background:rgba(255,255,255,.1)}html.wxdk .wx .wx-ch .wx-ib.g{background:#00a884;color:#fff}
  .wx-ch .wx-av{width:36px !important;height:36px !important}.wx-ch .wx-ic{background:rgba(255,255,255,.16);color:#fff;width:34px;height:34px}.wx-ch .wx-ic.g{background:#fff;color:#0f6e7e;font-weight:700}
  .wx-sw{background:#f0f2f5;border:0;padding:3px 12px;font-size:11.5px}
  .wx-msgs{background:transparent;padding:6px 0}.wx-day{display:table;margin:8px auto 6px;background:#fff;color:#54656f;border-radius:8px;padding:4px 10px;font-size:12px;font-weight:500;box-shadow:0 1px .5px rgba(11,20,26,.13)}
  .wx-ev{display:table;margin:5px auto;background:rgba(255,255,255,.85);color:#54656f;border-radius:8px;padding:3px 10px;font-size:11.5px;box-shadow:0 1px .5px rgba(11,20,26,.1)}
  .wx-b{max-width:82%;border-radius:8px;padding:6px 8px 4px 9px;font-size:14.2px;line-height:1.32;margin:1px 10px;box-shadow:0 1px .5px rgba(11,20,26,.13);color:#111b21}.wx-b.in{background:#fff;border:0;border-top-left-radius:0}.wx-b.out{background:#d9fdd3;border-top-right-radius:0}.wx-b.bot{background:#d9fdd3}.wx-b.tpl{background:#d9fdd3}
  .wx-b small{display:inline-block;float:right;margin:5px 0 0 10px;font-size:11px;color:#667781;line-height:1}.wx-b small .r{color:#53bdeb}.wx-b:after{content:"";display:block;clear:both}
  .wx-sug{border-top:0;box-shadow:0 -1px 0 #e9edef}
  .wx-bar{background:transparent;border:0;padding:6px 8px calc(8px + env(safe-area-inset-bottom));gap:6px;align-items:flex-end}
  .wx-tools{display:flex;gap:6px;padding:4px 8px 0}.wx .wx-tl{flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;justify-content:center;height:42px;border:0;border-radius:12px;background:#fff;color:#3b4a54;font-size:16px;line-height:1.05;box-shadow:0 1px .5px rgba(11,20,26,.13);padding:0}.wx .wx-tl small{font-size:11px;font-weight:600;margin-top:1px;color:#54656f}.wx .wx-tl:disabled{opacity:.45}
  .wx .wx-tl.on{background:#dcfce7!important;color:#14532d!important}.wx-sl{max-height:260px;overflow-y:auto;background:#fff;border:1px solid #d9dde4;border-radius:14px;margin:4px 8px;box-shadow:0 -6px 20px rgba(0,0,0,.08)}.wx-sli{display:flex;gap:8px;align-items:center;padding:9px 10px;border-bottom:1px solid #f0f2f5;cursor:pointer}.wx-sli:last-child{border-bottom:0}.wx-slt{flex:1;min-width:0}.wx-slt b{color:#128c7e}.wx-slt span{font-weight:700}.wx-slt small{display:block;color:#667781;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:12.5px}.wx-sli button{border:0;background:#128c7e;color:#fff;border-radius:50%;width:38px;height:38px;font-size:16px;flex:none}.wx-sle{padding:10px 12px;color:#667781;font-size:13px}html.wxdk .wx-sl{background:#202c33;border-color:#2a3942}html.wxdk .wx .wx-tl{background:#202c33;color:#e9edef;box-shadow:none}html.wxdk .wx .wx-tl small{color:#aebac1}
  .wx-bar textarea{background:#fff;border-radius:24px;padding:10px 16px;box-shadow:0 1px .5px rgba(11,20,26,.13);font-size:15.5px}
  .wx-bar .wx-ic{width:44px;height:44px;background:#fff;color:#54656f;box-shadow:0 1px .5px rgba(11,20,26,.13);font-size:20px}.wx .wx-bar .snd,.wx .wx-bar #wxMic{background:#00a884;color:#fff;box-shadow:none}
  .wx-lock{background:#fff;box-shadow:0 1px .5px rgba(11,20,26,.13);margin:6px 8px calc(8px + env(safe-area-inset-bottom))}
  .wx-sk{border-radius:8px}
  .wx-qm{display:flex;gap:6px;align-items:center;overflow-x:auto;padding:5px 8px;background:#fff;border-top:1px solid #e9edef}.wx-qm span{flex:none;font-size:13px}.wx-qm button{flex:none;background:#f0f2f5;color:#111b21;border-radius:999px;padding:0 12px;min-height:28px;font-size:12.5px;font-weight:600;white-space:nowrap}.wx-qm button:active{background:#d9fdd3}
  `;


  // ---------------------------------------------------------------- dark mode (night use) — Auto follows the phone, or force Light / Dark
  const DARK_CSS = `
  html.wxdk .wx{--ink:#e9edef;--mut:#8696a0;--line:#222d34;--bg:#0b141a;--g:#00a884;--gd:#00a884}
  html.wxdk .wx,html.wxdk .wx-main{background:#0b141a;color:#e9edef}
  html.wxdk .wx-top{background:#1f2c34}html.wxdk .wx-top h1{color:#e9edef}
  html.wxdk .wx-sp,html.wxdk .wx-top .wx-sp{background:rgba(255,255,255,.1);border:0;color:#e9edef}
  html.wxdk .wx-pill{background:#1f2c34;border-color:#222d34;color:#e9edef}html.wxdk .wx-top .wx-pill{background:rgba(255,255,255,.1);color:#e9edef}
  html.wxdk .wx-pill.g,html.wxdk .wx-top .wx-pill.g{background:#103529;border-color:#14532d;color:#86efac}html.wxdk .wx-pill.o,html.wxdk .wx-top .wx-pill.o{background:#3a2410;border-color:#5b3412;color:#fdba74}
  html.wxdk .wx-seg{background:#111b21;border-bottom-color:#222d34}html.wxdk .wx-seg button{background:#1f2c34;color:#aebac1;border-color:#222d34}html.wxdk .wx-seg button.on{background:#103529;color:#00a884;border-color:#103529}
  html.wxdk .wx-row:active{background:#182229}html.wxdk .wx-nm{color:#e9edef}html.wxdk .wx-sub,html.wxdk .wx-tm{color:#8696a0}
  html.wxdk .wx-h{background:transparent;color:#00a884}html.wxdk .wx-h.r{color:#f87171}
  html.wxdk .wx-tabs{background:#111b21;border-top-color:#222d34}html.wxdk .wx-tabs button{color:#8696a0}html.wxdk .wx-tabs button.on{color:#e9edef}html.wxdk .wx-tabs button.on i{background:#103529}
  html.wxdk .wx-card{background:#111b21;border-color:#222d34;color:#e9edef}
  html.wxdk .wx-chip.g{background:#103529;color:#86efac}html.wxdk .wx-chip.o{background:#3a2410;color:#fdba74}html.wxdk .wx-chip.b{background:#10294d;color:#93c5fd}html.wxdk .wx-chip.p{background:#2a1f4d;color:#c4b5fd}html.wxdk .wx-chip.r{background:#4a1d1d;color:#fca5a5}html.wxdk .wx-chip.m{background:#1f2c34;color:#aebac1}
  html.wxdk .wx .wx-btn.gh{background:#1f2c34;color:#e9edef;border-color:#2a3942}html.wxdk .wx .wx-btn.r{background:#1f2c34;color:#f87171;border-color:#5b2323}
  html.wxdk .wx-banner.y{background:#3a3210;color:#fde68a}html.wxdk .wx-banner.b{background:#10294d;color:#93c5fd}html.wxdk .wx-banner.g{background:#103529;color:#86efac}
  html.wxdk .wx-chat{background:#0b141a radial-gradient(circle at 12px 12px,rgba(255,255,255,.035) 1.4px,transparent 1.6px) 0 0/26px 26px}
  html.wxdk .wx-ch{background:#1f2c34;border-bottom-color:#222d34}html.wxdk .wx-ch .wx-ic{background:rgba(255,255,255,.1);color:#e9edef}html.wxdk .wx-ch .wx-ic.g{background:#00a884;color:#fff}
  html.wxdk .wx-sw{background:#111b21;border-bottom-color:#222d34;color:#8696a0}html.wxdk .wx-sw b{color:#e9edef}
  html.wxdk .wor-sh{background:#111b21;color:#e9edef}
  html.wxdk .wx-day{background:#182229;color:#8696a0;box-shadow:none}html.wxdk .wx-ev{background:rgba(24,34,41,.92);color:#8696a0;box-shadow:none}
  html.wxdk .wx-b{color:#e9edef;box-shadow:none}html.wxdk .wx-b.in{background:#202c33;border-color:#202c33}html.wxdk .wx-b.out,html.wxdk .wx-b.bot,html.wxdk .wx-b.tpl{background:#005c4b;border-color:#005c4b}html.wxdk .wx-b small{color:#9db0ba}html.wxdk .wx-b small .r{color:#53bdeb}
  html.wxdk .wx-sug{background:#111b21;border-top-color:#222d34;box-shadow:0 -1px 0 #222d34}html.wxdk .wx-sug .bar{background:#103529}html.wxdk .wx-sug .t{color:#e9edef}html.wxdk .wx-sug button.x{background:#1f2c34;color:#aebac1}
  html.wxdk .wx-lock{background:#1f2c34;color:#aebac1;box-shadow:none}
  html.wxdk .wx-bar{background:#0b141a;border-top-color:#222d34}html.wxdk .wx-bar textarea{background:#202c33;color:#e9edef;box-shadow:none}html.wxdk .wx-bar .wx-ic{background:#202c33;color:#aebac1;box-shadow:none}
  html.wxdk .wx-qm{background:#111b21;border-top-color:#222d34}html.wxdk .wx-qm button{background:#1f2c34;color:#e9edef}html.wxdk .wx-qm button:active{background:#103529}
  html.wxdk .wx-md{background:rgba(0,0,0,.62)}html.wxdk .wx-sh{background:#111b21;color:#e9edef}
  html.wxdk .wx-in,html.wxdk .wx input,html.wxdk .wx textarea,html.wxdk .wx select{background:#1f2c34;border-color:#2a3942;color:#e9edef}
  html.wxdk .wx-res button{border-color:#2a3942;color:#e9edef}html.wxdk .wx-res button.on{background:#103529;color:#86efac;border-color:#00a884}
  html.wxdk .wx-fo button{background:#1f2c34;color:#e9edef}html.wxdk .wx-fo button.on{background:#e9edef;color:#111b21}html.wxdk .wx-fo button.cl{background:#103529;color:#86efac}
  html.wxdk .wx-bubp{background:#005c4b}html.wxdk .wx-bar2{background:#2a3942}html.wxdk .wx-lbd.me{background:#103529}
  html.wxdk .wx-win .c{background:#1f2c34;color:#e9edef}html.wxdk .wx-th{background:#202c33}
  html.wxdk .wx-sk{background:linear-gradient(90deg,#182229 25%,#202c33 50%,#182229 75%);background-size:200% 100%}
  html.wxdk .wpr-sh{background:#111b21;color:#e9edef}html.wxdk .wpr-x{background:#1f2c34;color:#aebac1}html.wxdk .wpr-sb input{background:#1f2c34;border-color:#2a3942;color:#e9edef}
  html.wxdk .wpr-chip{background:#1f2c34;border-color:#2a3942;color:#e9edef}html.wxdk .wpr-chip.on{background:#103529;color:#86efac;border-color:#00a884}
  html.wxdk .wpr-note{color:#8696a0}html.wxdk .wpr-row{border-bottom-color:#222d34}html.wxdk .wpr-row.sel{background:#14262b}html.wxdk .wpr-th{background:#202c33}
  html.wxdk .wpr-nt{color:#aebac1}html.wxdk .wpr-og{color:#8696a0}html.wxdk .wpr-ch{background:#202c33;color:#aebac1}html.wxdk .wpr-rk{background:#103529;color:#86efac}html.wxdk .wpr-why{color:#86efac}
  html.wxdk .wpr-empty b{color:#e9edef}html.wxdk .wpr-ft{background:#111b21;border-top-color:#222d34}html.wxdk .wpr-seg{background:#202c33}html.wxdk .wpr-seg button{color:#aebac1}html.wxdk .wpr-seg button.on{background:#2a3942;color:#e9edef}
  html.wxdk .wpr-pend,html.wxdk .wpr-pt{background:#111b21;border-top-color:#222d34;color:#8696a0}html.wxdk .wpr-pc{background:#1f2c34;border-color:#222d34}html.wxdk .wpr-pcx{background:#2a3942;color:#aebac1}
  html.wxdk .wpr-snd.again{background:#202c33;color:#aebac1}html.wxdk .wpr-snd.done{background:#103529;color:#86efac}
  `;
  const THEME_LIGHT = '#0f6e7e', THEME_DARK = '#111b21';   // the colour of the phone's top bar (not green, so it is not mistaken for the call bar)
  function themeMode() { try { const m = localStorage.getItem('wa_theme'); return m === 'light' || m === 'dark' ? m : 'auto'; } catch (e) { return 'auto'; } }
  function themeIsDark() { const m = themeMode(); return m === 'dark' || (m === 'auto' && !!(window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches)); }
  function themeApply() {
    if (!document.getElementById('wxDark')) { const s = document.createElement('style'); s.id = 'wxDark'; s.textContent = DARK_CSS; document.head.appendChild(s); }
    const d = themeIsDark(); document.documentElement.classList.toggle('wxdk', d);
    let m = document.querySelector('meta[name="theme-color"]'); if (!m) { m = document.createElement('meta'); m.name = 'theme-color'; document.head.appendChild(m); } m.content = d ? THEME_DARK : THEME_LIGHT;
    const b = document.getElementById('wxDk'); if (b) b.textContent = d ? '☀️' : '🌙';
    const l = document.getElementById('wxLk'); if (l) l.textContent = themeMode() === 'auto' ? 'Auto (phone)' : (themeMode() === 'dark' ? 'Dark 🌙' : 'Light ☀️');
  }
  function themeSet(m) { try { localStorage.setItem('wa_theme', m); } catch (e) { } themeApply(); }
  function themeNext() { themeSet({ auto: 'dark', dark: 'light', light: 'auto' }[themeMode()]); }
  try { if (window.matchMedia) matchMedia('(prefers-color-scheme: dark)').addEventListener('change', themeApply); } catch (e) { }

  function start(root) {
    if (!document.getElementById('wxCss')) { const s = document.createElement('style'); s.id = 'wxCss'; s.textContent = CSS; document.head.appendChild(s); }
    themeApply();
    root.innerHTML = ''; const R = document.createElement('div'); R.className = 'wx'; root.appendChild(R);
    const EMB = new URLSearchParams(location.search).get('embed') === '1'; if (EMB) { R.classList.add('emb'); document.documentElement.classList.add('wx-emb'); }
    const CXID = EMB ? (Number(new URLSearchParams(location.search).get('cx')) || 0) : 0; if (CXID) R.classList.add('cxm');   // 🎫 inside a complaint ticket: voice + attach + ✨ Replies (Claude's suggestion + the complaint pre-made messages); no sales tools   // 🎫 ONE chat inside another page (the complaint ticket): the same chat box — voice notes, photos, videos, "/" quick replies, pre-made messages — no list
    const S = { tab: 'chats', filter: '', chats: [], counts: {}, stats: {}, cfg: { unans_min: 3, target: 5, sound: true }, me: {}, results: [], mentions: [], reminders: [], fetched: 0, loaded: false,
      hist: { rows: [], page: 0, more: false, q: '', f: '', total: 0, loading: false }, board: null, cur: null, sound: (localStorage.getItem('wa_sound') || '1') === '1', seen: null, quick: null, tpls: null, gallery: null, push: null };
    const $ = (q, r) => (r || R).querySelector(q);
    let audioCtx = null;

    // ------------------------------------------------------------------ sound + feedback
    function ctx() { try { if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)(); if (audioCtx.state === 'suspended') audioCtx.resume(); } catch (e) { } return audioCtx; }
    function tone(freqs, dur, vol) {
      if (!S.sound) return; const a = ctx(); if (!a) return; let t = a.currentTime;
      freqs.forEach((f) => { const o = a.createOscillator(), g = a.createGain(); o.type = 'sine'; o.frequency.value = f; g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(vol || .15, t + .02); g.gain.exponentialRampToValueAtTime(.0001, t + dur); o.connect(g); g.connect(a.destination); o.start(t); o.stop(t + dur + .02); t += dur * .75; });
    }
    const beepNew = () => { tone([880, 1175], .16, .12); try { navigator.vibrate && navigator.vibrate([60, 40, 60]); } catch (e) { } };
    const chime = () => { tone([523, 659, 784, 1047], .22, .18); try { navigator.vibrate && navigator.vibrate([40, 30, 40, 30, 90]); } catch (e) { } };
    document.addEventListener('touchstart', ctx, { once: true, passive: true }); document.addEventListener('click', ctx, { once: true });
    function toast(m, bad) { const t = document.createElement('div'); t.className = 'wx-toast' + (bad ? ' bad' : ''); t.textContent = m; R.appendChild(t); setTimeout(() => t.remove(), bad ? 5000 : 2200); }
    function sheet(title, onClose) {
      if (S.cur && S.cur.sg && S.cur.sg.counting) { suggStop(); S.auDone[S.cur.phone + ':' + S.cur.sg.li] = 1; suggDraw(); }
      const m = document.createElement('div'); m.className = 'wx-md'; m.innerHTML = `<div class="wx-sh"><h3><span>${esc(title)}</span><button type="button" aria-label="Close">✕</button></h3><div class="wx-sc"></div></div>`;
      R.appendChild(m); const shut = () => { if (!m.isConnected) return false; m.remove(); if (onClose) onClose(); return true; };
      const close = () => { if (shut() && history.state && history.state.wxSheet) { S.popSkip = (S.popSkip || 0) + 1; history.back(); } };
      m._shut = shut; try { const st = { wxSheet: 1, wxChat: history.state && history.state.wxChat }; if (S.reuseSheet && history.state && history.state.wxSheet) history.replaceState(st, ''); else history.pushState(st, ''); } catch (e) { } S.reuseSheet = 0;   // phone Back closes this sheet first
      m.onclick = (e) => { if (e.target === m) close(); }; m.querySelector('button').onclick = close;
      return { el: m, body: m.querySelector('.wx-sc'), close, title: (t) => { m.querySelector('h3 span').textContent = t; } };
    }

    // ------------------------------------------------------------------ data
    // 🙋 Requests: new chats not given to anybody yet — any salesperson takes one; once a chat has an owner it leaves this list
    async function loadReq() {
      S.reqAt = Date.now();
      try { const r = await api.get('wq.req_list'); const n = r.items || [], lt = r.late || []; const key = (a) => JSON.stringify(a.map((x) => x.phone + '|' + (x.mins ?? x.waiting_min)));
        const ch = key(n) !== key(S.req || []) || key(lt) !== key(S.reqLate || []);
        if (S.req && S.sound !== false && n.some((x) => !S.req.some((y) => y.phone === x.phone))) beepNew();   // 🔔 a new request came in
        S.req = n; S.reqLate = lt; S.reqMin = r.late_min || 4;
        if (ch && S.tab === 'chats' && (!S.cur || wide())) { if (held()) pend = 1; else drawList(); } } catch (e) { }
    }
    // ⚠ my customers' complaint tickets (read-only — the Complaint team handles them): status, who answered last, Claude's line (bd.cx_wa_board)
    async function loadCxB() { try { S.cxB = await api.get('bd.cx_wa_board', { f: 'all' }); } catch (e) { S.cxB = { items: [], err: e.message }; } if (S.tab === 'chats' && S.filter === 'cx' && !S.cur) drawList(); }
    function drawCxB() {
      const M = $('#wxM'), d = S.cxB; if (!d) { M.innerHTML = '<div class="wx-sk"></div><div class="wx-sk"></div>'; return; }
      const AI = { not_solved: ['#fee2e2', '#7f1d1d', '🔴 Not solved'], in_progress: ['#fef3c7', '#78350f', '🟡 In progress'], solved: ['#dcfce7', '#14532d', '🟢 Solved'] };
      const ago = (t) => { if (!t) return ''; const m = Math.max(0, Math.round((Date.now() - new Date(String(t).replace(' ', 'T')).getTime()) / 60000)); return m < 60 ? m + ' min ago' : m < 1440 ? Math.floor(m / 60) + ' h ago' : Math.floor(m / 1440) + ' d ago'; };
      M.innerHTML = '<div style="padding:10px 12px 2px"><button type="button" id="wxCxNew" style="width:100%;padding:12px;border-radius:14px;border:0;background:#00a884;color:#fff;font:inherit;font-weight:800;font-size:15px;cursor:pointer">＋ Raise a complaint</button><div style="font-size:12px;color:#667781;margin-top:5px;text-align:center">Any customer — just the phone number. It goes straight to the Complaint team (no group needed).</div></div>'
        + '<div class="wx-h">🎫 Complaints of my customers · ' + d.items.length + ' <small style="font-weight:600;text-transform:none;letter-spacing:0">(read-only — the Complaint team replies)</small></div>' + (d.err ? '<div class="wx-empty">' + esc(d.err) + '</div>' : d.items.length ? '<div class="wx-card wx-lst">' + d.items.map((x) => { const a = x.ai ? AI[x.ai.state] || AI.in_progress : null; return `<div data-p="${esc(x.phone)}" style="padding:11px 14px;border-bottom:1px solid var(--line);cursor:pointer;${x.flag ? 'background:#fff7f7' : ''}"><div style="display:flex;gap:6px;align-items:baseline;flex-wrap:wrap"><b style="font-family:ui-monospace,Menlo,monospace;background:#eef2ff;color:#3730a3;border-radius:6px;padding:0 5px;font-size:12px">${esc(x.code)}</b><b>${esc(x.name)}</b><span style="color:var(--mut);font-size:13px">${x.icon} ${esc(x.problem)} · ${esc(x.owner)}</span></div><div style="font-size:12.5px;color:var(--mut);margin-top:2px">${x.status === 'resolved' || x.status === 'closed' ? '✅ ' + esc(x.status) + ' · ' : ''}${x.status === 'reopened' ? '<b style="color:#c2410c">🔁 re-opened' + (x.repeat ? ' (repeat ×' + x.repeat.n + ')' : '') + '</b> · ' : ''}${x.flag ? '<b style="color:#c2410c">❌ waiting for a reply</b> · ' : ''}${x.last_out ? 'last reply ' + esc(x.last_who || '') + ' ' + ago(x.last_out) : 'no reply yet'}</div>${a ? `<div style="margin-top:5px;font-size:12.5px;border-radius:9px;padding:5px 8px;background:${a[0]};color:${a[1]}">🤖 <b>${a[2]}</b> — ${esc(x.ai.line)}</div>` : ''}</div>`; }).join('') + '</div>' : '<div class="wx-empty"><b>🎉</b>None of your customers has an open complaint.</div>');
      M.onclick = (e) => { if (e.target.closest('#wxCxNew')) { if (!window.CXR) { toast('Refresh the page and try again.', true); return; } CXR.open({ askCustomer: true, source: 'whatsapp', onDone: () => loadCxB() }); return; } const p = e.target.closest('[data-p]'); if (p && p.dataset.p) openChat(p.dataset.p); };
    }
    const reqN = () => (S.req ? S.req.length : 0) + (S.reqLate ? S.reqLate.length : 0);
    function drawReq() {
      const M = $('#wxM'), L = S.req, LT = S.reqLate || [];
      if (!L) { M.innerHTML = '<div class="wx-sk"></div><div class="wx-sk"></div>'; return; }
      const SRC = { query: '📢 Ad', campaign: '📣 Campaign' };
      const clock = (m) => { const d = new Date(Date.now() - m * 60000); let h = d.getHours(); const ap = h >= 12 ? 'pm' : 'am'; h = h % 12 || 12; return h + ':' + String(d.getMinutes()).padStart(2, '0') + ' ' + ap; };
      const since = (m) => m < 1 ? 'just now' : m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min';
      // one request: name · the time the query came · how long ago   [✋ Take over]  /  the customer's last message under it
      const line = (x, late) => { const m = late ? +x.waiting_min || 0 : +x.mins || 0, red = late || m >= (S.reqMin || 4);
        return `<div data-peek="${esc(x.phone)}" data-pk="${late ? 'grab' : 'req'}" role="button" tabindex="0" style="display:flex;gap:10px;align-items:center;padding:12px 14px;border-bottom:1px solid var(--line);cursor:pointer"><div style="flex:1;min-width:0">`
        + `<div style="display:flex;align-items:baseline;gap:6px;min-width:0"><b style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0">${esc(x.name || pretty(x.phone))}</b>${!late && SRC[x.src] ? `<small style="flex:none;font-weight:700;color:#7c3aed;background:#ede9fe;border-radius:99px;padding:1px 7px">${SRC[x.src]}</small>` : ''}<small style="flex:none;font-weight:700;color:${red ? '#dc2626' : '#667781'};white-space:nowrap">🕒 ${clock(m)} · ${since(m)}</small></div>`
        + `<div style="color:var(--ink,#111827);font-size:14.5px;line-height:1.4;margin-top:3px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word">${x.last_in ? esc(x.last_in) : '…'}</div></div>`
        + `<button type="button" data-tk="${esc(x.phone)}" data-pk="${late ? 'grab' : 'req'}" style="flex:none;border:0;border-radius:99px;background:#00a884;color:#fff;font-weight:800;font-size:13.5px;padding:9px 14px;min-height:40px;cursor:pointer">✋ Take over</button></div>`; };
      const byNew = (a, b) => (+a.mins || 0) - (+b.mins || 0), byNewL = (a, b) => (+a.waiting_min || 0) - (+b.waiting_min || 0);
      let h = '';   // 🆕 the newest query is always on top; the ones nobody answered for 4+ min come after
      h += '<div class="wx-h">🆕 New — not given to anyone · ' + L.length + '</div>' + (L.length ? '<div class="wx-card wx-lst">' + L.slice().sort(byNew).map((x) => line(x, false)).join('') + '</div>' : '<div class="wx-empty"><b>🙋</b>No new requests — every chat has someone.</div>');
      if (LT.length) h += `<div class="wx-h r">⏱ Waiting ${S.reqMin}+ min — nobody replied · ${LT.length}</div><div class="wx-card wx-lst">${LT.slice().sort(byNewL).map((x) => line(x, true)).join('')}</div>`;
      M.innerHTML = h;
      M.onclick = async (e) => {
        const t = e.target.closest('[data-tk]');
        if (t) { e.stopPropagation(); const ph = t.dataset.tk, late = t.dataset.pk === 'grab'; t.disabled = true; t.textContent = 'Taking…';
          try { await api.post(late ? 'wq.grab' : 'wq.req_take', { phone: ph }); const x = (S.req || []).concat(S.reqLate || []).find((y) => y.phone === ph) || {}; S.req = (S.req || []).filter((y) => y.phone !== ph); S.reqLate = (S.reqLate || []).filter((y) => y.phone !== ph); S.grab = (S.grab || []).filter((y) => y.phone !== ph); toast('The chat is yours now ✓'); loadList(true); openChat(ph, { name: x.name || '' }); }
          catch (er) { toast(er.message, true); t.disabled = false; t.textContent = '✋ Take over'; loadReq(); } return; }
        const b = e.target.closest('[data-peek]'); if (!b) return; const ph = b.dataset.peek, x = (S.req || []).concat(S.reqLate || []).find((y) => y.phone === ph) || {}; openChat(ph, { peek: b.dataset.pk, name: x.name || '' }); };
    }
    async function loadList(quiet) {
      try {
        const d = await api.get('wa.g_inbox', S.fl && S.fl.tag ? { tag: 1 } : {}); const old = new Set(S.chats.map((c) => c.phone + '|' + c.last_at));
        const firstLoad = !S.loaded; S.chats = d.chats || []; S.counts = d.counts || {}; S.stats = d.stats || {}; S.cfg = d.cfg || S.cfg; S.me = d.me || {}; S.results = d.results || []; S.mentions = d.mentions || []; S.reminders = d.reminders || []; S.fetched = Date.now(); S.loaded = true;
        if (!firstLoad && S.chats.some((c) => c.waiting && !old.has(c.phone + '|' + c.last_at) && !(S.cur && S.cur.phone === c.phone))) beepNew();
        if (S.tab === 'chats' && (!S.cur || wide())) { if (quiet && held()) pend = 1; else drawList(); } drawTop(); drawTabs();
        if (Date.now() - (S.reqAt || 0) > 5000) loadReq();
        if (Date.now() - (S.grabAt || 0) > 30000) { S.grabAt = Date.now(); api.get('wq.grab_list').then((g) => { const n = (g.items || []).filter((x) => !x.mine); const ch = JSON.stringify(n.map((x) => x.phone)) !== JSON.stringify((S.grab || []).map((x) => x.phone)); S.grab = n; S.grabMin = g.min || 4; if (ch && S.tab === 'chats' && (!S.cur || wide())) { if (held()) pend = 1; else drawList(); } }).catch(() => {}); }   // the late-reply list: every 30 s is enough
      } catch (e) { if (!quiet) toast(e.message, true); }
    }
    let POLL = 0;
    // One beat every 4 s: the open chat (if any) — and the chat list every beat on the Chats tab, every 2nd beat anywhere else, so new customers
    // still beep and the Chats badge stays right while a chat or another tab is open. A call that is still running is never started again (slow 3G).
    let beat = 0; const busy = { poll: false, list: false };
    async function once(k, fn) { if (busy[k]) return; busy[k] = true; try { await fn(); } catch (e) { } finally { busy[k] = false; } }
    // ✋ hold: while a dropdown (Everyone / salesperson…) is open, or a finger is on the screen / the list is scrolling, the auto refresh does NOT redraw
    //    the list (redrawing closed the dropdown and jumped the list). It waits and catches up a moment after you pick or let go.
    let holdUntil = 0, touching = false, pend = 0;
    function held() { return touching || Date.now() < holdUntil; }
    function holdFor(ms) { holdUntil = Math.max(holdUntil, Date.now() + ms); }
    const isSel = (e) => e && e.target && e.target.tagName === 'SELECT';
    document.addEventListener('focusin', (e) => { if (isSel(e)) holdFor(20000); });
    document.addEventListener('mousedown', (e) => { if (isSel(e)) holdFor(20000); });
    document.addEventListener('change', (e) => { if (isSel(e)) holdUntil = 0; }, true);
    document.addEventListener('focusout', (e) => { if (isSel(e)) holdUntil = Date.now() + 300; });
    document.addEventListener('touchstart', () => { touching = true; }, { passive: true });
    const letGo = () => { touching = false; holdFor(1200); };
    document.addEventListener('touchend', letGo, { passive: true }); document.addEventListener('touchcancel', letGo, { passive: true });
    document.addEventListener('scroll', () => holdFor(1200), { passive: true, capture: true });
    setInterval(() => { if (!pend || held()) return; pend = 0; if (S.cur) return; if (S.tab === 'chats') drawList(); else if (S.tab === 'history') drawHistory(false); }, 400);
    function loop() { clearInterval(POLL); POLL = setInterval(() => { if (document.hidden) return; beat++; if (S.cur) once('poll', pollChat); if ((!S.cur && S.tab === 'chats') || beat % 2 === 0) once('list', () => loadList(true)); if (!S.cur && S.tab === 'chats' && S.filter === 'team' && beat % 2 === 0) once('team', loadTeam); }, 5000); }   // ⚡ every 5 s (was 4 s): 20% fewer requests from every salesperson
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { if (S.cur) pollChat(); loadList(true); } });

    function wide() { return !!(window.matchMedia && matchMedia('(min-width:1000px)').matches); }   // 🖥 PC: list + chat side by side
    // ------------------------------------------------------------------ shell
    R.innerHTML = `<div class="wx-top"><a class="wx-sp" href="my_sales.html" title="My Sales" aria-label="Back to My Sales"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/></svg></a><h1 id="wxT">Chats</h1><button type="button" class="wx-sp" id="wxCxN" aria-label="Raise a complaint" title="Raise a complaint — any customer, just the phone number (also customers on our old phone)">⚠</button><button type="button" class="wx-sp" id="wxDk" aria-label="Dark mode" title="Dark / light">🌙</button><span class="wx-pill" id="wxS1"></span><span class="wx-pill" id="wxS2"></span></div><div class="wx-fb"><span class="ic" aria-hidden="true">🔍</span><input id="wxFb" type="search" inputmode="search" autocomplete="off" placeholder="Search any customer — number or name" aria-label="Search any customer by number or name"><button type="button" id="wxFbX" aria-label="Clear search" hidden>✕</button></div><div class="wx-seg" id="wxSeg"></div><div class="wx-main" id="wxM"></div><div class="wx-main" id="wxFR" hidden></div>
      <div class="wx-tabs" id="wxTabs"><button data-t="chats"><i>💬</i>Chats</button><button data-t="queries"><i>📋</i>Queries</button><button data-t="history"><i>🕘</i>History</button><button data-t="me"><i>🏆</i>Me</button><button data-home="1" onclick="location.href='my_sales.html'"><i>📈</i>My Sales</button></div>`;
    { const dk = R.querySelector('#wxDk'); if (dk) dk.onclick = themeNext; themeApply(); }
    { const cn = R.querySelector('#wxCxN'); if (cn) cn.onclick = () => { if (!window.CXR) { toast('Refresh the page and try again.', true); return; } CXR.open({ askCustomer: true, source: 'whatsapp', onDone: () => { try { loadCxB(); } catch (e) { } } }); }; }   // ⚠ raise a complaint for ANY number — not the chat that is open
    { const fb = R.querySelector('#wxFb'); if (fb) custFindBar(fb); }
    // the open chat stays marked in the list on the left (PC), whichever list is drawn (Chats, Team, Requests, History)
    function markSel() { const p = S.cur && S.cur.phone; R.querySelectorAll('#wxM [data-p]').forEach((r) => r.classList.toggle('sel', !!p && r.dataset.p === p)); }
    try { new MutationObserver(() => markSel()).observe(R.querySelector('#wxM'), { childList: true }); } catch (e) { }
    // ---- 🔍 Search any customer (bar at the top of Chats): number with or without +91, part of a number, or a name — who has the chat; open it if allowed
    //      (a colleague's chat opens read only with Take over). The results replace the list until the box is cleared.
    function custFindBar(inp) {
      const out = R.querySelector('#wxFR'), x = R.querySelector('#wxFbX'); let t = 0, seq = 0;
      const show = (on) => { out.hidden = !on; R.querySelector('#wxM').hidden = on; R.classList.toggle('wx-fnd', on); x.hidden = !inp.value; };   // .wx-fnd hides the filter chips while searching
      const hint = '<div class="wx-empty" style="padding:22px 8px"><b>🔍</b>Type at least 3 digits or letters</div>';
      const draw = (items, q) => {
        if (!items.length) { out.innerHTML = '<div class="wx-empty" style="padding:22px 8px"><b>🤷</b>No customer found for “' + esc(q) + '”</div>'; return; }
        out.innerHTML = '<div class="wx-h">Customers · ' + items.length + '</div>' + items.map((c) => `<button type="button" class="wx-mi wx-fd" data-ph="${c.phone}" ${c.open ? '' : 'style="opacity:.6"'}><span>${c.cx ? '⚠️' : '💬'}</span><b>${esc(c.name || pretty(c.phone))}${c.mine ? '<span class="tg">Yours</span>' : (c.with ? '<span class="tg o">with ' + esc(c.with) + '</span>' : '<span class="tg o">not given to anyone</span>')}<small>${esc(pretty(c.phone))}${c.last ? ' · ' + esc(String(c.last).slice(0, 16)) : ''}${c.open ? '' : ' · ask ' + esc(c.with || 'your manager')}</small></b></button>`).join('');
      };
      S.findClear = () => { inp.value = ''; seq++; clearTimeout(t); show(false); };
      inp.oninput = () => { const q = inp.value.trim(); clearTimeout(t); if (!q) { S.findClear(); return; } show(true);
        if (q.replace(/\s/g, '').length < 3) { out.innerHTML = hint; return; }
        t = setTimeout(async () => { const my = ++seq; out.innerHTML = '<div class="wx-sk"></div><div class="wx-sk"></div>'; try { const d = await api.get('wa.g_find', { q }); if (my === seq) { S.find = d.items || []; draw(S.find, q); } } catch (e) { if (my === seq) out.innerHTML = '<div class="wx-empty">' + esc(e.message) + '</div>'; } }, 300); };
      inp.onkeydown = (e) => { if (e.key === 'Escape') S.findClear(); };
      x.onclick = () => { S.findClear(); inp.focus(); };
      out.onclick = (e) => { const b = e.target.closest('[data-ph]'); if (!b) return; const c = (S.find || []).find((y) => y.phone === b.dataset.ph); if (!c) return;
        if (!c.open) { toast('This chat is with ' + (c.with || 'someone else') + ' — ask them or your manager', true); return; }
        R.querySelectorAll('#wxFR .wx-fd').forEach((r) => r.classList.toggle('sel', r === b)); openChat(c.phone, { name: c.name }); };
    }
    R.querySelector('#wxTabs').onclick = (e) => { const b = e.target.closest('button[data-t]'); if (b) setTab(b.dataset.t); };
    function setTab(t) { if (S.findClear) S.findClear(); if (S.cur) closeChat(); if (t !== 'queries' && S.qView && S.qView.destroy) { S.qView.destroy(); S.qView = null; } S.tab = t; S.cur = null; R.querySelectorAll('.wx-chat').forEach((c) => c.remove()); draw(); }   // the old Queries view stops completely (its listener + 30 s timer)
    function drawTabs() {
      const n = (S.chats || []).filter((c) => c.waiting && c.window).length; R.querySelectorAll('#wxTabs button').forEach((b) => { b.classList.toggle('on', b.dataset.t === S.tab); const bd = b.querySelector('b'); if (bd) bd.remove(); });
      if (n) { const b = $('#wxTabs [data-t=chats]'); b.insertAdjacentHTML('beforeend', `<b>${n}</b>`); }
    }
    function drawTop() {
      const st = S.stats || {}; $('#wxS1').innerHTML = st.streak > 0 ? `🔥 ${st.streak}` : '🔥 0'; $('#wxS2').className = 'wx-pill' + (st.orders_today >= S.cfg.target ? ' g' : ''); $('#wxS2').textContent = `🎯 ${st.orders_today || 0}/${S.cfg.target || 5}`;
      $('#wxT').textContent = S.tab === 'chats' ? 'Chats' : S.tab === 'queries' ? 'My queries' : S.tab === 'history' ? 'History' : (S.me.name || 'Me');
    }
    function draw() { drawTabs(); drawTop(); if (S.tab === 'chats') { drawList(); } else if (S.tab === 'queries') drawQueries(); else if (S.tab === 'history') drawHistory(true); else drawMe(); }
    // ---- 📋 My queries (WhatsApp Sales: wq.php / wq_ui.js). The list, the type pills and the waiting timers live in wq_ui.js; a tap on a row opens the chat here.
    let qLd = null;
    function qReady(cb) {
      if (window.WaQ) return cb(); if (qLd) { qLd.push(cb); return; } qLd = [cb];
      const sc = document.createElement('script'); sc.src = 'assets/js/wq_ui.js?v=q3';
      sc.onload = () => { const q = qLd || []; qLd = null; q.forEach((f) => f()); }; sc.onerror = () => { const q = qLd || []; qLd = null; q.forEach((f) => f()); }; document.head.appendChild(sc);
    }
    function drawQueries() {
      $('#wxSeg').hidden = true; const M = $('#wxM'); if (S.qView && S.qView.destroy) S.qView.destroy(); S.qView = null; M.innerHTML = '<div class="wx-sk"></div><div class="wx-sk"></div>';
      qReady(() => {
        if (S.tab !== 'queries') return;
        if (!window.WaQ) { M.innerHTML = '<div class="wx-empty"><b>📋</b>Could not open My queries. Refresh the page.</div>'; return; }
        M.innerHTML = ''; S.qView = WaQ.myQueries(M, { api, onOpen: (p) => openChat(p) });
      });
    }

    // ------------------------------------------------------------------ chats list
    const SRC = { query: ['Query', 'o'], community: ['Community', 'g'], campaign: ['Campaign', 'b'] };
    function badgeOf(c) { const b = c.badge || {}; return b.k === 'vip' ? '<span title="Very good customer">⭐</span>' : b.k === 'hot' ? '<span title="Ready to pay">🔥</span>' : b.k === 'repeat' ? '<span title="Repeat buyer">🔁</span>' : ''; }
    function timerOf(c) {
      if (!c.waiting || !c.window) return `<div>${hhmm(c.last_at)}</div>`;
      const s = c.wait_sec + (Date.now() - S.fetched) / 1000, lim = (S.cfg.unans_min || 3) * 60; const cls = s >= lim ? 'r' : s >= lim / 2 ? 'w' : 'ok';
      return `<div class="wx-t ${cls}" data-s="${c.wait_sec}">${mmss(s)}</div>`;
    }
    // ---- 👥 Team (WhatsApp managers only): every live chat of the team, filter by salesperson or search; open one → "Take over"
    async function loadTeam() {
      if (!(S.me && S.me.team)) return; S.tm = S.tm || { emp: 0, q: '', rows: null, people: null };
      try { const d = await api.get('wa.g_inbox', S.tm.emp ? { all: 1, emp: S.tm.emp } : { all: 1 }); S.tm.rows = d.chats || []; } catch (e) { S.tm.err = e.message; }
      if (!S.tm.people) { try { S.tm.people = (await api.get('wa.people')).people || []; } catch (e) { S.tm.people = []; } }
      if (S.tab === 'chats' && S.filter === 'team' && (!S.cur || wide())) { if (held()) pend = 1; else drawTeam(); }
    }
    function drawTeam() {
      const M = $('#wxM'), T = S.tm || {}; if (!T.rows) { M.innerHTML = '<div class="wx-sk"></div><div class="wx-sk"></div>'; return; }
      const q = (T.q || '').toLowerCase().trim(), rows = T.rows.filter((c) => !q || (c.name || '').toLowerCase().includes(q) || String(c.phone).includes(q.replace(/\D/g, '').slice(-10) || '@'));
      const opts = '<option value="0">Everyone</option>' + (T.people || []).map((p) => `<option value="${p.emp_id}" ${T.emp === p.emp_id ? 'selected' : ''}>${esc(p.name)}${p.present ? '' : ' (away)'}</option>`).join('');
      const keep = document.activeElement && document.activeElement.id === 'wxTq';
      M.innerHTML = `<div class="wx-tm"><select id="wxTe" aria-label="Salesperson">${opts}</select><input id="wxTq" class="wx-in" type="search" placeholder="Search name or number" value="${esc(T.q || '')}"></div>
        <div class="wx-h">Team live chats · ${rows.length}${T.emp ? ' · ' + esc(((T.people || []).find((p) => p.emp_id === T.emp) || {}).name || '') : ''}</div>`
        + (rows.length ? `<div class="wx-card wx-lst">${rows.map((c) => row(Object.assign({}, c, { team: 1 }))).join('')}</div>` : '<div class="wx-empty"><b>👥</b>No live chats here.</div>');
      $('#wxTe').onchange = function () { T.emp = +this.value; T.rows = null; drawTeam(); loadTeam(); };
      const ti = $('#wxTq'); ti.oninput = () => { const p = ti.selectionStart; T.q = ti.value; drawTeam(); const n = $('#wxTq'); n.focus(); n.setSelectionRange(p, p); }; if (keep) { ti.focus(); ti.setSelectionRange(ti.value.length, ti.value.length); }
      M.querySelectorAll('.wx-row[data-p]').forEach((r) => { r.onclick = () => openChat(r.dataset.p); });
    }
    // 🙋 a Requests chat read before taking it: the big button at the bottom takes it (new chat → wq.req_take, a late one → wq.grab)
    async function takeReq() {
      if (!S.cur || !S.cur.peek) return; const phone = S.cur.phone, late = S.cur.peek === 'grab', b = $('#wxPk'); if (b) { b.disabled = true; b.textContent = 'Taking…'; }
      try { await api.post(late ? 'wq.grab' : 'wq.req_take', { phone }); S.req = (S.req || []).filter((x) => x.phone !== phone); S.reqLate = (S.reqLate || []).filter((x) => x.phone !== phone); S.grab = (S.grab || []).filter((x) => x.phone !== phone);
        if (S.cur && S.cur.phone === phone) { S.cur.peek = ''; S.cur.row.emp = S.me && S.me.emp_id; const bx = $('#wxPkB'); if (bx) bx.remove(); drawFoot(); } toast('The chat is yours now ✓ — reply below'); loadList(true); }
      catch (er) { toast(er.message, true); if (b) { b.disabled = false; b.textContent = '✋ Take over this chat'; } loadReq(); }
    }
    async function takeOver() {
      if (!S.cur) return; const phone = S.cur.phone, b = $('#wxToB'); if (b) { b.disabled = true; b.textContent = 'Taking over…'; }
      try { const r = await api.post('wa.takeover', { phone }); if (S.cur && S.cur.phone === phone) { S.cur.row.emp = S.me.emp_id; S.cur.watch = false; const bx = $('#wxTo'); if (bx) bx.remove(); const f = $('#wxFoot'); if (f) f.innerHTML = ''; drawFoot(); } toast(r.from ? 'You took over from ' + r.from + ' ✓' : 'The chat is yours now ✓'); loadList(true); if (S.tm) loadTeam(); }
      catch (e) { toast(e.message, true); if (b) { b.disabled = false; b.textContent = '🔄 Take over'; } }
    }
    // ---- ↪️ Hand over (every salesperson): give your own chat to a colleague — search by name, see who is at work / full
    function openHandover() {
      if (!S.cur) return; const phone = S.cur.phone, sh = sheet('Hand over this chat'); let people = null, q = '';
      const draw = () => {
        if (!people) { sh.body.innerHTML = '<div class="wx-sk"></div>'; return; }
        const me = S.me && S.me.emp_id, l = people.filter((p) => p.emp_id !== me && (!q || p.name.toLowerCase().includes(q)));
        sh.body.innerHTML = `<input class="wx-in" id="wxHo" type="search" placeholder="Search a colleague" value="${esc(q)}" style="margin-top:0">` + (l.length ? l.map((p) => `<button type="button" class="wx-mi" data-e="${p.emp_id}" ${p.present && !p.full ? '' : 'style="opacity:.6"'}><span>${p.present ? '🟢' : '⚪'}</span><b>${esc(p.name)}<small>${p.present ? (p.full ? 'Online · full' : 'Online') : 'Offline · not punched in'}</small></b></button>`).join('') : '<div class="wx-empty">Nobody matches.</div>');
        const i = $('#wxHo', sh.body) || sh.body.querySelector('#wxHo'); i.oninput = () => { q = i.value.toLowerCase().trim(); const p = i.selectionStart; draw(); const n = sh.body.querySelector('#wxHo'); n.focus(); n.setSelectionRange(p, p); };
      };
      sh.body.onclick = async (e) => {
        const b = e.target.closest('[data-e]'); if (!b) return; const p = people.find((x) => x.emp_id === +b.dataset.e); if (!p) return;
        if (!confirm('Hand this chat to ' + p.name + '?' + (p.present ? (p.full ? '\n' + p.name + ' already has many open chats.' : '') : '\n' + p.name + ' is not at work right now.'))) return;
        try { await api.post('wa.transfer', { phone, emp_id: p.emp_id }); sh.close(); toast('Handed over to ' + p.name + ' ✓'); if (history.state && history.state.wxChat) history.back(); else closeChat(); }
        catch (er) { toast(er.message, true); }
      };
      draw(); api.get('wa.people').then((d) => { people = d.people || []; draw(); }).catch((er) => { sh.body.innerHTML = `<div class="wx-empty">${esc(er.message)}</div>`; });
    }
    function row(c) {
      const src = SRC[c.src], h = hue(c.phone);
      return `<div class="wx-row ${c.heat ? 'h-' + c.heat : ''}${S.cur && S.cur.phone === c.phone ? ' sel' : ''}" data-p="${c.phone}"><div class="wx-av" style="background:hsl(${h} 55% 48%)">${esc(initials(c.name, c.phone))}</div><div class="wx-mid"><div class="wx-nm">${esc(c.name || pretty(c.phone))} ${badgeOf(c)}</div>
        <div class="wx-sub">${c.mine ? '✓ ' : ''}${esc(c.preview)}</div><div style="margin-top:2px">${c.not_mine ? `<span class="wx-chip" style="background:#fef3c7;color:#92400e">🏷 now with ${esc(c.emp_name || 'nobody')}</span>` : ''}${src ? `<span class="wx-chip ${src[1]}">${src[0]}</span>` : ''}${c.origin ? `<span class="wx-chip" style="background:#fdf2f8;color:#9d174d">${esc(c.origin)}</span>` : ''}${c.num ? `<span class="wx-chip m">${esc(String(c.num).replace(/^Number\s*/i, 'N').slice(0, 8))}</span>` : ''}${c.hot ? '<span class="wx-chip r">PAY NOW</span>' : ''}${c.biz ? '<span class="wx-chip m">🏢 Business</span>' : ''}${c.team && c.emp_name ? `<span class="wx-chip b">👤 ${esc(c.emp_name)}</span>` : ''}</div></div>
        <div class="wx-tm">${timerOf(c)}${c.unread ? `<span class="wx-dot">${c.unread}</span>` : ''}</div></div>`;
    }
    // ⏷ Filter (same as the admin Chats): the order of my list + show only … — works on top of All / Waiting / From ad / 🔥
    const FL_SORT = [['', 'Waiting first (default)'], ['new', 'Newest first'], ['old', 'Oldest first'], ['az', 'Name A → Z'], ['za', 'Name Z → A']];
    const FL_ONLY = [['noresp', 'Not responded — I have not answered yet'], ['late', 'Waiting too long'], ['hot', '🔥 Hot — ready to buy'], ['cx', 'Complaint'], ['ad', '📣 From ad']];
    function flApply(list, F) {
      const has = (k) => F.only.includes(k); let L = list.filter((c) => (!has('noresp') || c.noresp) && (!has('late') || c.overdue) && (!has('hot') || c.hot) && (!has('cx') || c.cx) && (!has('ad') || c.src === 'query'));
      const nm = (c) => String(c.name || '').trim().toLowerCase(), t = (c) => String(c.last_at || '');
      if (F.sort === 'new') L = L.slice().sort((a, b) => t(b).localeCompare(t(a))); else if (F.sort === 'old') L = L.slice().sort((a, b) => t(a).localeCompare(t(b)));
      else if (F.sort === 'az' || F.sort === 'za') { const d = F.sort === 'az' ? 1 : -1; L = L.slice().sort((a, b) => (!nm(a)) - (!nm(b)) || d * nm(a).localeCompare(nm(b)) || d * String(a.phone).localeCompare(String(b.phone))); }
      return L;
    }
    function openFilter() {
      const F = S.fl || (S.fl = { sort: '', only: [] }), sh = sheet('Filter'), nr = (S.chats || []).filter((c) => c.noresp).length;
      const opt = (type, name, k, l, on, extra) => `<label style="display:flex;align-items:center;gap:12px;padding:12px 4px;border-bottom:1px solid var(--line);font-size:15px;cursor:pointer"><input type="${type}" name="${name}" value="${k}"${on ? ' checked' : ''} style="width:22px;height:22px;flex:none;margin:0;accent-color:#00a884"><span style="flex:1">${l}</span>${extra || ''}</label>`;
      sh.body.innerHTML = '<div style="font-size:12px;font-weight:800;letter-spacing:.05em;color:var(--mut);margin:4px 0">CHATS</div>' + opt('radio', 'flt', '', 'My chats now', !F.tag) + opt('radio', 'flt', 'tag', '🏷 My tag — every chat that was ever mine (30 days)', !!F.tag)
        + '<div style="font-size:12px;font-weight:800;letter-spacing:.05em;color:var(--mut);margin:14px 0 4px">SORT BY</div>' + FL_SORT.map(([k, l]) => opt('radio', 'fls', k, l, F.sort === k)).join('')
        + '<div style="font-size:12px;font-weight:800;letter-spacing:.05em;color:var(--mut);margin:14px 0 4px">SHOW ONLY</div>' + FL_ONLY.map(([k, l]) => opt('checkbox', 'flo', k, l, F.only.includes(k), k === 'noresp' ? `<b style="color:#dc2626">${nr}</b>` : '')).join('')
        + '<div style="display:flex;gap:10px;margin-top:16px"><button type="button" class="wx-btn" data-x="reset" style="flex:1;min-height:48px;border-radius:99px;background:#fff;color:#111827;border:1px solid #d1d5db;font-weight:700">Reset</button><button type="button" class="wx-btn" data-x="go" style="flex:2;min-height:48px;border-radius:99px;background:#00a884;color:#fff;border:0;font-weight:800">Apply</button></div>';
      sh.body.onclick = (e) => { const b = e.target.closest('[data-x]'); if (!b) return;
        const wasTag = !!F.tag; if (b.dataset.x === 'reset') { F.sort = ''; F.only = []; F.tag = false; } else { const r = sh.body.querySelector('input[name=fls]:checked'); F.sort = r ? r.value : ''; F.only = [...sh.body.querySelectorAll('input[name=flo]:checked')].map((x) => x.value); const tg = sh.body.querySelector('input[name=flt]:checked'); F.tag = !!(tg && tg.value === 'tag'); }
        try { localStorage.setItem('wx_fl', JSON.stringify(F)); } catch (er) { } sh.close(); if (wasTag !== !!F.tag) { S.loaded = false; drawList(); loadList(); } else drawList(); };
    }
    try { const sv = JSON.parse(localStorage.getItem('wx_fl') || 'null'); if (sv && Array.isArray(sv.only)) S.fl = { sort: String(sv.sort || ''), only: sv.only.map(String), tag: !!sv.tag }; } catch (e) { }
    function drawList() {
      $('#wxSeg').hidden = false; const M = $('#wxM'); if (!S.loaded) { M.innerHTML = '<div class="wx-sk"></div><div class="wx-sk"></div><div class="wx-sk"></div>'; $('#wxSeg').innerHTML = ''; return; }
      const f = S.filter; const all = S.chats; let list = all.filter((c) => !f || (f === 'wait' ? c.waiting : f === 'new' ? c.src === 'query' : f === 'hot' ? c.hot : true));
      const FL = S.fl || (S.fl = { sort: '', only: [] }); list = flApply(list, FL);
      const segs = [['', 'All ' + all.length], ['wait', '⏱ Waiting ' + (S.counts.waiting || 0)], ['req', '🙋 Requests ' + (S.req ? reqN() : '')], ['cx', '⚠ Complaints' + (S.cxB && S.cxB.items.length ? ' ' + S.cxB.items.length : '')], ['new', '📣 From ad'], ['hot', '🔥 ' + (S.counts.hot || 0)]]; if (S.me && S.me.team) segs.push(['team', '👥 Team']);
      const fln = FL.only.length + (FL.sort ? 1 : 0) + (FL.tag ? 1 : 0);
      $('#wxSeg').innerHTML = `<button data-fl="1" class="${fln ? 'on' : ''}" style="${fln ? 'background:#00a884;color:#fff;border-color:#00a884;' : ''}font-weight:700">⏷ Filter${fln ? ' · ' + fln : ''}</button>` + segs.map(([k, l]) => `<button data-f="${k}" class="${f === k ? 'on' : ''}"${k === 'req' && f !== k && S.req && reqN() ? ' style="background:#f97316;color:#fff;border-color:#f97316;font-weight:800"' : ''}>${l}</button>`).join('');   // 🙋 orange while chats are waiting for someone
      $('#wxSeg').onclick = (e) => { if (e.target.closest('[data-fl]')) { openFilter(); return; } const b = e.target.closest('button'); if (b) { S.filter = b.dataset.f; if (S.filter === 'team') loadTeam(); if (S.filter === 'req') loadReq(); if (S.filter === 'cx') loadCxB(); drawList(); } };
      if (f === 'team') { drawTeam(); return; }
      if (f === 'req') { drawReq(); return; }
      if (f === 'cx') { drawCxB(); return; }
      let h = false ? '<div class="wx-lg"><span><i style="background:#22c55e"></i>Hot</span><span><i style="background:#f59e0b"></i>Waiting</span><span><i style="background:#60a5fa"></i>Talking</span><span><i style="background:#c4c9d1"></i>Quiet</span><span><i style="background:#a78bfa"></i>Complaint</span><span><i style="background:#9ca3af"></i>Business</span></div>' : '';
      const rem = (S.reminders || []).map((r) => `<div class="wx-banner y" data-p="${r.phone}" style="cursor:pointer">⏰ Remind: <b>${esc(r.name || pretty(r.phone))}</b></div>`).join(''); const men = (S.mentions || []).slice(0, 2).map((m) => `<div class="wx-banner b" data-p="${m.phone}" style="cursor:pointer">💬 ${esc(m.from)}: ${esc(m.text)}</div>`).join('');
      const gr = []; const grab = gr.length ? `<div class="wx-banner y" style="display:block">🙋 <b>${S.grab.length} customer${S.grab.length === 1 ? '' : 's'} waiting ${S.grabMin}+ min</b> with nobody answering — free? take one:${gr.map((x) => `<div style="display:flex;gap:8px;align-items:center;margin-top:6px"><span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><b>${esc(x.name || pretty(x.phone))}</b> · waiting ${x.waiting_min} min${x.last_in ? ' · ' + esc(x.last_in) : ''}</span><button type="button" data-grab="${esc(x.phone)}" style="border:0;background:#128c7e;color:#fff;border-radius:99px;padding:6px 12px;font-weight:700;flex:none">Take</button></div>`).join('')}</div>` : '';
      h += grab + rem + men + pushPrompt();
      if (!list.length) h += `<div class="wx-empty"><b>🎉</b>${all.length ? 'Nothing in this filter.' : 'No chats right now.<br>A new query will pop up here with an alert.'}</div>`;
      else {
        const late = FL.sort ? [] : list.filter((c) => c.overdue), rest = FL.sort ? list : list.filter((c) => !c.overdue);   // a chosen order is kept as it is
        if (late.length) h += `<div class="wx-h r">⏱ Waiting too long</div><div class="wx-card wx-lst">${late.map(row).join('')}</div>`;
        if (rest.length) h += `${late.length ? '<div class="wx-h">Others</div>' : ''}<div class="wx-card wx-lst">${rest.map(row).join('')}</div>`;
      }
      M.innerHTML = h; M.onclick = async (e) => { const g = e.target.closest('[data-grab]'); if (g) { g.disabled = true; g.textContent = '…'; try { await api.post('wq.grab', { phone: g.dataset.grab }); S.grab = (S.grab || []).filter((x) => x.phone !== g.dataset.grab); await loadList(true); openChat(g.dataset.grab); } catch (er) { alert(er.message); loadList(true); } return; } const p = e.target.closest('[data-p]'); if (p) { const cx = (S.chats || []).find((y) => y.phone === p.dataset.p); openChat(p.dataset.p, cx && cx.not_mine ? { peek: 'tag', name: cx.name || '' } : undefined); } const a = e.target.closest('[data-push]'); if (a) pushAction(a.dataset.push); };
    }
    setInterval(() => { R.querySelectorAll('.wx-t[data-s]').forEach((el) => { const s = (+el.dataset.s) + (Date.now() - S.fetched) / 1000, lim = (S.cfg.unans_min || 3) * 60; el.textContent = mmss(s); el.className = 'wx-t ' + (s >= lim ? 'r' : s >= lim / 2 ? 'w' : 'ok'); }); }, 1000);

    // ------------------------------------------------------------------ phone alerts
    const iOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
    function pushPrompt() {
      if (S.push === 'on' || S.push === 'gone') return ''; if (!('Notification' in window)) return iOS && !standalone ? '<div class="wx-banner b">🔔 iPhone: tap Share → <b>Add to Home Screen</b>, open from the icon, then allow alerts.</div>' : '';
      if (Notification.permission === 'granted') { subscribePush(false); return ''; } if (Notification.permission === 'denied') return '';
      const asked = +localStorage.getItem('wa_push_asked') || 0; if (Date.now() - asked < 3 * 864e5) return '';
      if (iOS && !standalone) return '<div class="wx-banner b">🔔 Want an alert when a query is given to you? iPhone: tap Share → <b>Add to Home Screen</b>, open from the icon, then allow.</div>';
      return '<div class="wx-banner g">🔔 <span style="flex:1">Get an alert the moment a query is given to you</span><button class="wx-btn" data-push="allow" style="padding:6px 12px;min-height:32px">Allow</button><button class="wx-btn gh" data-push="later" style="padding:6px 10px;min-height:32px">Later</button></div>';
    }
    async function subscribePush(interactive) {
      try {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return false; const reg = await navigator.serviceWorker.register('sw.js', { updateViaCache: 'none' }); await navigator.serviceWorker.ready;
        if (Notification.permission !== 'granted') { if (!interactive) return false; if ((await Notification.requestPermission()) !== 'granted') return false; }
        const k = await api.get('push.key'); const toKey = (b64) => { const pad = '='.repeat((4 - b64.length % 4) % 4); const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/')); const o = new Uint8Array(raw.length); for (let i = 0; i < raw.length; i++) o[i] = raw.charCodeAt(i); return o; };
        let sub = await reg.pushManager.getSubscription(); if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toKey(k.key) });
        const j = sub.toJSON(); await api.post('push.subscribe', { endpoint: j.endpoint, keys: j.keys, device: navigator.userAgent.slice(0, 160) }); S.push = 'on'; return true;
      } catch (e) { return false; }
    }
    async function pushAction(a) {
      localStorage.setItem('wa_push_asked', String(Date.now()));
      if (a === 'allow') { const ok = await subscribePush(true); toast(ok ? 'Alerts are on 🔔' : 'Alerts were not allowed', !ok); }
      if (S.tab === 'chats') drawList(); else if (S.tab === 'me') drawMe();
    }

    // ------------------------------------------------------------------ history
    async function loadHist(reset) {
      const H = S.hist; if (H.loading) return; H.loading = true; if (reset) { H.page = 0; H.rows = []; }
      try {
        const p = { page: H.page, q: H.q }; if (H.f === 'noreply') p.noreply = 1; if (H.f === 'noresult') p.noresult = 1; if (H.emp) p.emp = H.emp; const d = await api.get('wa.g_history', p);
        H.rows = H.rows.concat(d.chats || []); H.more = !!d.more; H.total = d.total || 0; H.page++;
      } catch (e) { toast(e.message, true); }
      H.loading = false; if (S.tab === 'history') { if (held()) pend = 1; else drawHistory(false); }
    }
    function drawHistory(fresh) {
      const H = S.hist; $('#wxSeg').hidden = false;
      $('#wxSeg').innerHTML = [['', 'All'], ['noreply', '😶 Never answered'], ['noresult', 'No result noted']].map(([k, l]) => `<button data-f="${k}" class="${H.f === k ? 'on' : ''}">${l}</button>`).join('');
      $('#wxSeg').onclick = (e) => { const b = e.target.closest('button'); if (b) { H.f = b.dataset.f; loadHist(true); drawHistory(false); } };
      const M = $('#wxM'); if (fresh && !H.rows.length && !H.loading) loadHist(true);
      const rows = H.rows.map((c) => `<div class="wx-row" data-p="${c.phone}"><div class="wx-av" style="background:hsl(${hue(c.phone)} 40% 55%)">${esc(initials(c.name, c.phone))}</div><div class="wx-mid"><div class="wx-nm">${esc(c.name || pretty(c.phone))}</div><div class="wx-sub">${esc(c.preview)}</div>
        <div style="margin-top:2px">${c.result ? `<span class="wx-chip g">${c.result_i} ${esc(c.result_t)}</span>` : '<span class="wx-chip m">no result</span>'}${c.unanswered ? '<span class="wx-chip r">never answered</span>' : ''}${c.orders_n ? `<span class="wx-chip p">${c.orders_n} orders</span>` : ''}</div></div><div class="wx-tm">${ago(c.ago_days)}</div></div>`).join('');
      const team = !!(S.me && S.me.team); if (team && !S.tmP) { S.tmP = 1; api.get('wa.people').then((d) => { S.tm = S.tm || { emp: 0, q: '', rows: null, people: null }; S.tm.people = d.people || []; if (S.tab === 'history') drawHistory(false); }).catch(() => { }); }
      const hOpts = team ? '<select id="wxHe" aria-label="Salesperson" style="margin:0 0 8px;width:100%;min-height:42px;border:1px solid var(--line);border-radius:12px;padding:0 10px;font-size:15px;background:#fff"><option value="0">Everyone</option><option value="-1"' + (H.emp === -1 ? ' selected' : '') + '>Nobody (not given)</option>' + ((S.tm && S.tm.people) || []).map((x) => `<option value="${x.emp_id}" ${H.emp === x.emp_id ? 'selected' : ''}>${esc(x.name)}</option>`).join('') + '</select>' : '';
      M.innerHTML = `<div style="padding:0 14px 8px">${hOpts}<input class="wx-in" id="wxHq" placeholder="Search name or number" value="${esc(H.q)}" style="margin:0"></div><div class="wx-h">Chats older than 24 hours · ${H.total}${H.emp > 0 && S.tm && S.tm.people ? ' · ' + esc((S.tm.people.find((x) => x.emp_id === H.emp) || {}).name || '') : ''}</div>` +
        (rows ? `<div class="wx-card wx-lst">${rows}</div>` : (H.loading ? '<div class="wx-sk"></div><div class="wx-sk"></div>' : '<div class="wx-empty"><b>🕘</b>Nothing here yet.<br>A chat moves here 24 hours after the customer\'s last message, and comes back when they write again.</div>')) + (H.more ? '<div style="text-align:center;padding:4px 0 12px"><button class="wx-btn gh" id="wxHm">Load more</button></div>' : '');
      const he = $('#wxHe'); if (he) he.onchange = function () { H.emp = +this.value || 0; loadHist(true); drawHistory(false); };   // 👤 one person's history
      let t; $('#wxHq').oninput = function () { clearTimeout(t); const v = this.value; t = setTimeout(() => { H.q = v.trim(); loadHist(true); }, 350); }; const hm = $('#wxHm'); if (hm) hm.onclick = () => loadHist(false);
      M.onclick = (e) => { const p = e.target.closest('.wx-row[data-p]'); if (p) openChat(p.dataset.p); };
    }

    // ------------------------------------------------------------------ me
    async function drawMe() {
      $('#wxSeg').hidden = true; const M = $('#wxM'); const st = S.stats || {}, tg = S.cfg.target || 5; const pc = Math.min(100, Math.round(100 * (st.orders_today || 0) / tg));
      M.innerHTML = `<div class="wx-card" style="padding:14px"><div style="display:flex;align-items:baseline;gap:8px"><span class="wx-big">${st.orders_today || 0}</span><span style="color:var(--mut);font-weight:700">of ${tg} orders today</span><span style="margin-left:auto;font-weight:800">${money(st.revenue_today)}</span></div><div class="wx-bar2"><i style="width:${pc}%"></i></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><span class="wx-pill">🔥 Streak ${st.streak || 0} days</span><span class="wx-pill">🏅 Rank ${st.rank || '—'}${st.team ? ' of ' + st.team : ''}</span><span class="wx-pill">📅 Week ${st.week_orders || 0}</span><span class="wx-pill">💬 Chats today ${st.chats_today || 0}</span></div></div>
        <div id="wxLb"><div class="wx-sk"></div></div>
        <div class="wx-card" style="padding:10px 12px"><div class="wx-kv"><span>Look</span><button class="wx-btn gh" id="wxLk">Auto (phone)</button></div><div class="wx-kv"><span>Sound</span><button class="wx-btn gh" id="wxSnd">${S.sound ? 'On 🔊' : 'Off 🔇'}</button></div><div class="wx-kv"><span>Phone alerts</span><button class="wx-btn gh" id="wxPs">${'Notification' in window && Notification.permission === 'granted' ? 'On 🔔' : 'Turn on'}</button></div><div class="wx-kv"><span>Check my phone</span><button class="wx-btn gh" id="wxPc">Run check</button></div><div class="wx-kv"><span>How it works</span><button class="wx-btn gh" id="wxGd">Open</button></div></div>`;
      $('#wxLk').onclick = themeNext; themeApply();
      $('#wxSnd').onclick = function () { S.sound = !S.sound; localStorage.setItem('wa_sound', S.sound ? '1' : '0'); this.textContent = S.sound ? 'On 🔊' : 'Off 🔇'; if (S.sound) beepNew(); };
      $('#wxPc').onclick = phoneCheck; $('#wxGd').onclick = () => showGuide(false);
      $('#wxPs').onclick = () => { if (iOS && !standalone) return toast('iPhone: Share → Add to Home Screen first, then open from the icon.', true); pushAction('allow').then(drawMe); };
      try { const d = await api.get('wa.g_me'); S.stats = d.stats; S.board = d.board; drawTop(); const lb = $('#wxLb'); if (lb) lb.innerHTML = ['today', 'week'].map((k) => `<div class="wx-h">${k === 'today' ? 'Leaderboard · today' : 'Leaderboard · 7 days'}</div><div class="wx-card">${(d.board[k] || []).map((r, i) => `<div class="wx-lbd ${r.me ? 'me' : ''}"><span class="rk">${['🥇', '🥈', '🥉'][i] || i + 1}</span><span class="n">${esc(r.name)}</span><b>${r.n}</b><span style="color:var(--mut);font-size:12px">${money(r.amount)}</span></div>`).join('') || '<div class="wx-empty" style="padding:14px">No orders yet.</div>'}</div>`).join(''); } catch (e) { }
    }


    // ------------------------------------------------------------------ "How it works" guide + "Check my phone"
    const GUIDE = [
      ['💬', 'Longest wait is on top', 'Answer the red timers first.'],
      ['🔔', 'A chat is given to you', 'Your phone buzzes. If nobody replies in time it moves to the next free person.'],
      ['⏱', '24-hour rule', 'Free typing for 24 h after the customer writes. After that, only Pre-made (open it from History).'],
      ['✅', 'Tap the result', 'Ordered, Thinking, Price, No reply… one tap — this is how orders are counted.'],
      ['🚫', 'STOP means stop', 'The chat is switched off. Do not message them another way.'],
    ];
    async function showGuide(first) {
      const sh = sheet('How it works', () => { if (first) api.post('wa.g_guide_seen', {}).catch(() => { }); });
      sh.body.innerHTML = `<div style="padding:0 14px 12px">${GUIDE.map((g) => `<div style="display:flex;gap:12px;align-items:flex-start;padding:9px 0;border-bottom:1px solid var(--line)"><span style="font-size:20px;width:26px;text-align:center">${g[0]}</span><div><b style="font-size:14.5px">${esc(g[1])}</b><div style="color:var(--mut);margin-top:1px;line-height:1.35;font-size:13px">${esc(g[2])}</div></div></div>`).join('')}<button class="wx-btn" id="wxGotIt" style="width:100%;min-height:44px;margin-top:12px;border-radius:24px">Got it</button></div>`;
      sh.body.querySelector('#wxGotIt').onclick = () => { api.post('wa.g_guide_seen', {}).catch(() => { }); sh.close(); };
    }
    async function firstGuide() { if (EMB) return; try { const d = await api.get('wa.g_guide_state'); if (!d.seen) setTimeout(() => showGuide(true), 800); } catch (e) { } }
    const devName = () => { const u = navigator.userAgent; let m; if (/iPhone/.test(u)) return 'iPhone · iOS ' + ((/OS (\d+)/.exec(u) || [])[1] || ''); if (/iPad/.test(u)) return 'iPad'; if ((m = /Android [\d.]+; ([^)]+?)(?: Build|\))/.exec(u))) return 'Android · ' + m[1].trim(); return (navigator.platform || 'Device') + ' · Chrome ' + ((/Chrome\/(\d+)/.exec(u) || [])[1] || ''); };
    async function phoneCheck() {
      const sh = sheet('Check my phone'), B = sh.body; const K = { heard: null, buzz: '' };
      async function run() {
        B.innerHTML = '<div style="padding:14px"><div class="wx-sk"></div><div class="wx-sk"></div></div>'; const R2 = [];
        const add = (ok, t, fix, act) => R2.push({ ok, t, fix, act });
        const supports = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
        add(standalone || !iOS, standalone ? 'Opened from the home-screen icon' : (iOS ? 'Not opened from the home-screen icon' : 'Running in the browser (works; installing is nicer)'), iOS && !standalone ? 'iPhone only gives alerts to apps added to the Home Screen: tap Share → Add to Home Screen, then open Chats from that icon and run this check again.' : '');
        add(supports, supports ? 'This phone supports alerts' : 'This browser cannot show alerts', supports ? '' : 'Use Chrome on Android, or Safari on iPhone (iOS 16.4 or newer) from the Home Screen icon.');
        const perm = supports && Notification.permission === 'granted'; add(perm, perm ? 'Alerts are allowed' : 'Alerts are not allowed yet', perm ? '' : (supports && Notification.permission === 'denied' ? 'Alerts are blocked. Phone Settings → Notifications → this app (or the browser) → Allow.' : 'Tap “Allow alerts” below and press Allow.'), perm ? '' : 'allow');
        let reg = false, ms = 0; try { const t0 = performance.now(); const d = await api.get('wa.g_push_state'); ms = performance.now() - t0; reg = !!d.subscribed; } catch (e) { }
        add(reg, reg ? 'This phone is registered for alerts' : 'This phone is not registered for alerts', reg ? '' : 'Allow alerts first; it registers by itself.');
        add(ms > 0 && ms < 2000, ms ? 'Connection is fast enough (' + Math.round(ms) + ' ms)' : 'Could not reach the server', ms && ms < 2000 ? '' : 'Move to better signal or Wi-Fi and run the check again.');
        const fit = document.documentElement.scrollWidth <= window.innerWidth + 1; add(fit, fit ? 'Screen fits without sideways scrolling' : 'The screen scrolls sideways', fit ? '' : 'Tell your Admin the phone model — this should not happen.');
        const sound = K.heard === null ? null : K.heard; add(sound === true, sound === null ? 'Sound: not tested yet' : (sound ? 'You heard the sound' : 'You did not hear the sound'), sound === false ? 'Turn the phone off silent mode and raise the volume, then test again. On iPhone the ring/silent switch mutes web sound.' : '', 'sound');
        const ok = R2.filter((r) => r.ok).length; const dev = devName();
        B.innerHTML = `<div style="padding:4px 14px 14px"><div class="wx-card" style="padding:12px 14px;margin:0 0 10px"><b style="font-size:16px">${ok} of ${R2.length} passed</b><div style="color:var(--mut);font-size:12.5px;margin-top:2px">${esc(dev)}</div></div>
          ${R2.map((r) => `<div class="wx-card" style="padding:10px 14px;margin:0 0 8px"><div style="display:flex;gap:10px;align-items:flex-start"><span style="font-size:18px">${r.ok ? '✅' : '❌'}</span><div style="flex:1"><b style="font-size:14px">${esc(r.t)}</b>${r.fix ? `<div style="color:var(--mut);font-size:12.5px;margin-top:3px;line-height:1.45">${esc(r.fix)}</div>` : ''}${r.act === 'allow' ? '<button class="wx-btn" data-a="allow" style="margin-top:8px">Allow alerts</button>' : ''}${r.act === 'sound' ? '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap"><button class="wx-btn gh" data-a="beep">▶ Play sound</button><button class="wx-btn" data-a="heard">I heard it</button><button class="wx-btn gh" data-a="nohear">I did not</button></div>' : ''}</div></div></div>`).join('')}
          <button class="wx-btn gh" data-a="buzz" style="width:100%;min-height:46px;margin-top:4px">Send a test buzz to this phone</button>${K.buzz ? `<div style="margin:8px 2px;font-size:13px;color:var(--mut)">${esc(K.buzz)}</div>` : ''}
          <button class="wx-btn" data-a="done" style="width:100%;min-height:48px;margin-top:10px">Finish</button></div>`;
        api.post('wa.g_dev_report', { device: dev, pass: ok, of: R2.length, standalone: !!standalone, push: reg, sw: supports, sound: K.heard === true, w: window.innerWidth }).catch(() => { });
      }
      B.onclick = async (e) => {
        const b = e.target.closest('[data-a]'); if (!b) return; const a = b.dataset.a;
        if (a === 'allow') { if (iOS && !standalone) { toast('iPhone: Share → Add to Home Screen first.', true); return; } await pushAction('allow'); run(); }
        else if (a === 'beep') { const was = S.sound; S.sound = true; tone([880, 1175, 1568], .2, .2); try { navigator.vibrate && navigator.vibrate([60, 40, 60]); } catch (x) { } S.sound = was; }
        else if (a === 'heard') { K.heard = true; run(); } else if (a === 'nohear') { K.heard = false; run(); }
        else if (a === 'buzz') { b.disabled = true; try { const d = await api.post('wa.g_push_self', {}); K.buzz = d.note || ''; } catch (x) { K.buzz = x.message; } run(); }
        else if (a === 'done') sh.close();
      };
      run();
    }

    // ------------------------------------------------------------------ one chat
    const MEDIA = {};
    async function streamUrl(id) { const d = await api.get('wa.media_link', { id }); return api.buildUrl('wa.mfile', { id: d.id, e: d.e, s: d.s }); }   // ⚡ a signed link the browser streams (videos start at once, seek works)
    async function mediaUrl(id) {
      if (MEDIA[id]) return MEDIA[id];
      if (/^prod:/.test(id)) { await new Promise((ok) => prodReady(ok)); const u = window.WaProd ? await WaProd.imgFor(id) : ''; if (!u) throw new Error('Could not load the photo.'); return (MEDIA[id] = u); }   // a product photo from "Find a perfume": it lives on the website
      const res = await fetch(api.buildUrl('wa.media', { id }), { headers: { Authorization: 'Bearer ' + api.token() } });
      if (!res.ok) { let m = 'Could not load the file.'; try { m = (await res.json()).error || m; } catch (e) { } throw new Error(m); } return (MEDIA[id] = URL.createObjectURL(await res.blob()));
    }
    const capOf = (m) => { const x = /^[^:]{1,20}:\s*([\s\S]+)$/.exec(m.body || ''); return x ? x[1] : ''; };
    function tick(m) { if (m.dir !== 'out') return ''; const s = m.status; return s === 'failed' ? (m.retry ? ` <button type="button" class="wx-rt" data-rt="${esc(m.cid)}">⚠ Not sent · Retry</button>` : ' <span class="f">⚠ failed</span>') : s === 'read' ? ' <span class="r">✓✓</span>' : s === 'delivered' ? ' ✓✓' : s === 'sending' ? ' …' : ' ✓'; }
    function bubble(m) {
      if (m.dir === 'sys') return `<div class="wx-ev">${esc(m.body || '')}</div>`;
      const cls = m.dir === 'in' ? 'in' : (m.who === 'bot' ? 'out bot' : m.mtype === 'template' ? 'out tpl' : 'out'); let b = '';
      const cap = (m.mtype === 'image' || m.mtype === 'video') ? capOf(m) : '';
      if (m.media_id && (m.mtype === 'image' || m.mtype === 'sticker')) b = (MEDIA[m.media_id] ? `<img src="${MEDIA[m.media_id]}" alt="">` : `<div class="wx-ld" data-m="${esc(m.media_id)}" data-k="img">📷 Photo</div>`) + (cap ? linkify(esc(cap)) : '');
      else if (m.media_id && m.mtype === 'audio') b = `<div class="wx-ld" data-m="${esc(m.media_id)}" data-k="aud">🎤 Voice note — tap to play</div><div class="tx" data-tm="${esc(m.media_id)}" hidden></div>`;
      else if (m.media_id && m.mtype === 'video') b = (MEDIA[m.media_id] ? `<video controls preload="metadata" src="${MEDIA[m.media_id]}"></video>` : `<div class="wx-ld" data-m="${esc(m.media_id)}" data-k="vid">🎬 Video</div>`) + (cap ? esc(cap) : '');
      else if (m.media_id) b = `<div class="wx-ld" data-m="${esc(m.media_id)}" data-k="doc" data-n="${esc(capOf(m) || 'Document')}">📄 ${esc(capOf(m) || 'Document')}</div>`;
      else b = linkify(esc(m.body || ''));
      const qt = m.quote ? `<div class="wx-qt" data-qid="${m.quote.id}"><b>${m.quote.dir === 'in' ? 'Customer' : 'You'}</b><span>${esc(m.quote.text)}</span></div>` : '';   // ↩ the customer swiped on this earlier message
      const rb = !m.tmp && m.dir !== 'sys' && /^\d+$/.test(String(m.id)) ? '<button type="button" class="wx-rb" data-rb="' + m.id + '" title="Reply to this message" aria-label="Reply">↩</button>' : '';   // ↩ reply to this message (on a phone: swipe it right)
      return `<div class="wx-b ${cls} ${m.tmp ? 'tmp' : ''}" data-id="${m.id}">${rb}${m.who === 'bot' ? '<span class="wx-tag">🤖 BOT</span>' : m.who === 'phone' ? '<span class="wx-tag">📱 PHONE APP</span>' : m.who === 'flow' ? '<span class="wx-tag">⚡ AUTO</span>' : m.mtype === 'template' ? '<span class="wx-tag">PRE-MADE</span>' : ''}${qt}${b}<small>${hhmm(m.ts)}${tick(m)}</small></div>`;
    }

    // 🖼 tap a photo → it opens full screen (phone and PC): pinch to zoom, tap anywhere or ✕ to close
    function photoOpen(src) {
      if (document.querySelector('.ph-lb')) return;   // one viewer at a time (a photo can be tapped through two handlers)
      if (!document.getElementById('phStyle')) { const st = document.createElement('style'); st.id = 'phStyle'; st.textContent = '.ph-lb{position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:99999;display:flex;align-items:center;justify-content:center;touch-action:pinch-zoom;overscroll-behavior:contain}.ph-lb img{max-width:100vw;max-height:100vh;width:auto;height:auto;object-fit:contain;touch-action:pinch-zoom}.ph-lb .ph-x{position:fixed;top:max(12px,env(safe-area-inset-top));right:14px;width:44px;height:44px;border-radius:50%;border:0;background:rgba(255,255,255,.18);color:#fff;font-size:22px;line-height:1;cursor:pointer}.ph-lb .ph-o{position:fixed;bottom:max(14px,env(safe-area-inset-bottom));left:50%;transform:translateX(-50%);background:rgba(255,255,255,.18);color:#fff;padding:10px 16px;border-radius:99px;font:600 14px system-ui,sans-serif;text-decoration:none}'; document.head.appendChild(st); }
      const d = document.createElement('div'); d.className = 'ph-lb'; d.innerHTML = '<img alt="photo"><button type="button" class="ph-x" aria-label="Close">✕</button><a class="ph-o" target="_blank" rel="noopener">Open original</a>';
      d.querySelector('img').src = src; d.querySelector('.ph-o').href = src; const close = () => { d.remove(); document.removeEventListener('keydown', esc2); }; const esc2 = (e) => { if (e.key === 'Escape') close(); };
      d.addEventListener('click', (e) => { if (e.target.closest('.ph-o')) return; close(); }); document.addEventListener('keydown', esc2); document.body.appendChild(d);
    }
    document.addEventListener('click', (e) => { const im = e.target.closest('#wxMs .wx-b img'); if (im && !e.target.closest('.wx-qt,a')) { e.preventDefault(); photoOpen(im.currentSrc || im.src); } });
    document.addEventListener('click', (e) => { const q = e.target.closest('.wx-qt'); if (!q) return; const t = document.querySelector('.wx-b[data-id="' + q.dataset.qid + '"]');
      const go = (el) => { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.classList.remove('wx-fl'); void el.offsetWidth; el.classList.add('wx-fl'); };
      if (t) { go(t); return; }
      (async () => {   // the quoted message is older than what is loaded: load older pages until it appears
        for (let i = 0; i < 40 && S.cur && S.cur.moreOld; i++) { await loadOlder(); const f = document.querySelector('.wx-b[data-id="' + q.dataset.qid + '"]'); if (f) { go(f); return; } }
        toast('That message could not be found in this chat.');
      })(); });
    // ↩ reply to one message (WhatsApp style): swipe a bubble right on the phone, or press ↩ on it; a bar above the box shows what you answer
    function rpText(m) { const lab = { image: '📷 Photo', video: '🎥 Video', audio: '🎤 Voice note', document: '📄 Document', sticker: '🙂 Sticker' }[m.mtype] || ''; const b = String(m.body || '').trim(); return (lab && b ? lab + ' · ' + b : (b || lab || 'Message')).slice(0, 160); }
    function rpSet(id) { if (!S.cur) return; if (S.cur.contact && (S.cur.contact.optout || !S.cur.contact.window_open)) { toast('24 hours passed — only a pre-made message can go now, so you cannot reply to one message.'); return; } const m = S.cur.msgs.find((x) => String(x.id) === String(id)); if (!m) return; S.cur.rp = { id: m.id, dir: m.dir, text: rpText(m) }; rpDraw(); const t = $('#wxTx'); if (t) t.focus(); }
    function rpDraw() {
      let bar = $('#wxRpB'); const f = $('#wxFoot'); if (!S.cur || !S.cur.rp || !f) { if (bar) bar.remove(); return; }
      if (!bar) { bar = document.createElement('div'); bar.id = 'wxRpB'; bar.className = 'wx-rpb'; f.parentNode.insertBefore(bar, f); }
      bar.innerHTML = '<div class="wx-qt" style="flex:1;margin:0"><b>' + (S.cur.rp.dir === 'in' ? 'Customer' : 'You') + '</b><span>' + esc(S.cur.rp.text) + '</span></div><button type="button" aria-label="Cancel reply" id="wxRpX">✕</button>';
      $('#wxRpX').onclick = () => { S.cur.rp = null; rpDraw(); };
    }
    { let sx = 0, sy = 0, el = null, dx = 0;   // swipe right on a bubble = reply
      R.addEventListener('touchstart', (e) => { const b = e.target.closest('#wxMs .wx-b[data-id]'); el = b && /^\d+$/.test(b.dataset.id) ? b : null; if (!el) return; sx = e.touches[0].clientX; sy = e.touches[0].clientY; dx = 0; }, { passive: true });
      R.addEventListener('touchmove', (e) => { if (!el) return; const x = e.touches[0].clientX - sx, y = e.touches[0].clientY - sy; if (Math.abs(y) > 30 && Math.abs(y) > Math.abs(x)) { el.style.transform = ''; el = null; return; } dx = Math.max(0, Math.min(80, x)); el.style.transform = 'translateX(' + dx + 'px)'; }, { passive: true });
      let lastTap = { id: '', t: 0 };   // double tap on a bubble = reply (like double-clicking on PC)
      R.addEventListener('dblclick', (e) => { const b = e.target.closest('#wxMs .wx-b[data-id]'); if (b && /^\d+$/.test(b.dataset.id) && !e.target.closest('a,button,img,video,audio,.wx-ld')) { e.preventDefault(); const sel = window.getSelection && window.getSelection(); if (sel) sel.removeAllRanges(); rpSet(b.dataset.id); } });
      R.addEventListener('touchend', (e) => { const b0 = e.target.closest && e.target.closest('#wxMs .wx-b[data-id]'); if (b0 && dx < 8 && /^\d+$/.test(b0.dataset.id) && !e.target.closest('a,button,img,video,audio,.wx-ld,.wx-qt')) { const now = Date.now(); if (lastTap.id === b0.dataset.id && now - lastTap.t < 320) { lastTap = { id: '', t: 0 }; try { e.preventDefault(); } catch (er) { } rpSet(b0.dataset.id); return; } lastTap = { id: b0.dataset.id, t: now }; } }, { passive: false });
      R.addEventListener('touchend', () => { if (!el) return; const b = el; el = null; b.style.transition = 'transform .15s'; b.style.transform = ''; setTimeout(() => { b.style.transition = ''; }, 160); if (dx >= 55) { try { navigator.vibrate && navigator.vibrate(15); } catch (e) { } rpSet(b.dataset.id); } }); }
    function msgsHtml() {
      let last = '', h = S.cur.moreOld ? '<div class="wx-ev" id="wxOld" style="cursor:pointer">⬆ Load older messages</div>' : '', lastNum = ''; for (const m of S.cur.msgs) { const d = dayLbl(m.ts); if (d !== last) { h += `<div class="wx-day">${d}</div>`; last = d; } if (m.dir !== 'sys' && m.number) { if (lastNum && m.number !== lastNum) h += `<div class="wx-ev">📱 Continued on ${esc(m.number)}</div>`; lastNum = m.number; } h += bubble(m); } return h;
    }
    // ⬆ older messages: the chat opens with the newest 80; scrolling to the top (or tapping the bar) loads the 80 before them, until the first message
    async function loadOlder() {
      if (!S.cur || !S.cur.moreOld) return; while (S.cur && S.cur.oldBusy) await new Promise((z) => setTimeout(z, 80)); if (!S.cur || !S.cur.moreOld) return; const phone = S.cur.phone, box = $('#wxMs'); S.cur.oldBusy = true;
      try {
        const ids = S.cur.msgs.filter((m) => /^\d+$/.test(String(m.id))).map((m) => +m.id), d = await api.get('wa.chat', { phone, before: (S.cur.msgs.find((m) => /^\d+$/.test(String(m.id))) || {}).id || Math.min.apply(null, ids), limit: 80 });
        if (!S.cur || S.cur.phone !== phone) return;
        const have = new Set(S.cur.msgs.map((m) => m.id)); S.cur.msgs = d.messages.filter((m) => !have.has(m.id)).concat(S.cur.msgs); S.cur.moreOld = !!d.more;
        const h0 = box.scrollHeight, t0 = box.scrollTop; box.innerHTML = msgsHtml(); box.scrollTop = box.scrollHeight - h0 + t0;   // stay where you were reading
      } catch (e) { } finally { if (S.cur) S.cur.oldBusy = false; }
    }
    function scrollEnd(force) { const e = $('#wxMs'); if (!e) return; if (force || e.scrollHeight - e.scrollTop - e.clientHeight < 160) e.scrollTop = e.scrollHeight; }
    async function openChat(phone, opt) {
      if (S.cur && S.cur.phone === phone && $('.wx-chat')) return;   // already open (a double tap)
      if (S.cur) { stopRec(true); suggStop(); } R.querySelectorAll('.wx-chat').forEach((c) => c.remove());   // never two chat screens on top of each other
      const row = S.chats.find((c) => c.phone === phone) || ((S.tm && S.tm.rows) || []).find((c) => c.phone === phone) || S.hist.rows.find((c) => c.phone === phone) || {};
      setTimeout(markSel, 0);
      S.cur = { phone, msgs: [], contact: { phone, name: row.name || (opt && opt.name) || '', window_open: true }, row, sugg: undefined, info: null, last: 0, tmp: 0, files: [], peek: (opt && opt.peek) || '' };
      const v = document.createElement('div'); v.className = 'wx-chat'; R.appendChild(v); drawChatShell(); loadChat();
      if (wide() && S.tab === 'chats' && !['team', 'req', 'cx'].includes(S.filter)) { try { drawList(); } catch (e) { } }
      try { if (!(history.state && history.state.wxChat)) history.pushState({ wxChat: phone }, ''); } catch (e) { }   // phone Back closes the chat (back to the list), not the app
    }
    function drawChatShell() {
      const v = $('.wx-chat'), c = S.cur.contact, r = S.cur.row;
      S.cur.watch = !!(S.me && S.me.team && r.emp && r.emp !== S.me.emp_id);   // a colleague's chat opened from 👥 Team: read only until "Take over"
      v.innerHTML = `<div class="wx-ch"><button class="bk" id="wxBk" aria-label="Back">‹</button><div class="wx-av" style="background:hsl(${hue(c.phone)} 55% 48%);width:34px;height:34px;font-size:12px">${esc(initials(c.name, c.phone))}</div>
        <div class="wx-mid" id="wxCi" style="cursor:pointer"><div class="wx-nm" id="wxCn">${esc(c.name || pretty(c.phone))} ${badgeOf(r)}</div><div class="wx-sub" id="wxCs">${esc(pretty(c.phone))}</div></div>
        <button class="wx-ib" id="wxPrc" title="Send the price photo">💰<small>Price</small></button><button class="wx-ib g" id="wxFin" title="Finish this chat">✔<small>Done</small></button><button class="wx-ib" id="wxMore" aria-haspopup="dialog" title="More: complaint, report, journey, customer">⋯<small>More</small></button></div>
        <div id="wxCxB"></div><div id="wxOdB"></div>${S.cur.peek === 'tag' ? `<div class="wx-banner y" id="wxPkB">🏷 This chat was yours first — now with <b>${esc(((S.chats || []).find((y) => y.phone === S.cur.phone) || {}).emp_name || 'someone else')}</b>. You can read it; only they can reply.</div>` : S.cur.peek ? `<div class="wx-banner b" id="wxPkB">🙋 A ${S.cur.peek === 'grab' ? 'late chat (nobody replied)' : 'new chat — not given to anyone yet'}. Read it, then take it with the button below.</div>` : ''}${S.cur.watch ? `<div class="wx-banner b" id="wxTo" style="display:flex;gap:8px;align-items:center">👀 <span style="flex:1">With <b>${esc(r.emp_name || 'a colleague')}</b> — you are watching.</span>${r.heat === 'cx' ? '<small>Complaint chat</small>' : '<button class="wx-btn" id="wxToB">🔄 Take over</button>'}</div>` : ''}<div class="wx-sw" id="wxNum" hidden></div><div class="wx-msgs" id="wxMs"><div class="wx-sk"></div></div><div class="wx-qm" id="wxQk" hidden></div><div id="wxSug"></div><div id="wxPp"></div><div id="wxFoot"></div>`;
      $('#wxBk').onclick = () => { if (history.state && history.state.wxChat) history.back(); else closeChat(); }; $('#wxCi').onclick = openCustomer; $('#wxFin').onclick = openFinish; $('#wxMore').onclick = openMore;
      if ($('#wxToB')) $('#wxToB').onclick = takeOver;
      $('#wxPrc').onclick = async function () { if (!confirm('Send the price photo to this customer?')) return; this.disabled = true; try { const r = await api.post('wa.price_photo', { phone: S.cur.phone }); toast(r.message || 'Price photo sent.'); } catch (e) { toast(e.message, true); } this.disabled = false; }; cxBanner(); odBanner();
      $('#wxMs').onclick = async (e) => {
        const rb0 = e.target.closest('[data-rb]'); if (rb0) { rpSet(rb0.dataset.rb); return; }
        const rt = e.target.closest('[data-rt]'); if (rt) { const m = S.cur && S.cur.msgs.find((x) => x.cid === rt.dataset.rt); if (m) sendText(m.body, null, false, m); return; }   // Retry: same id, so it can never go twice
        const l = e.target.closest('.wx-ld'); if (!l) return; l.textContent = 'Loading…';
        try {
          const k = l.dataset.k, u = (k === 'vid' || k === 'doc') && !/^prod:/.test(l.dataset.m) && !MEDIA[l.dataset.m] ? await streamUrl(l.dataset.m) : await mediaUrl(l.dataset.m);
          if (k === 'img') { l.outerHTML = `<img src="${u}" alt="">`; } else if (k === 'vid') { MEDIA[l.dataset.m] = u; l.outerHTML = `<video controls autoplay playsinline preload="auto" src="${u}"></video>`; }
          else if (k === 'aud') { l.outerHTML = `<audio controls autoplay src="${u}"></audio>`; transcribe(l.dataset.m); } else { const w = window.open(u, '_blank'); if (!w) { const a = document.createElement('a'); a.href = u; a.download = l.dataset.n || 'file'; a.click(); } l.textContent = '📄 ' + (l.dataset.n || 'Document'); }
        } catch (er) { l.textContent = er.message; }
      };
    }
    // ⋯ More: the actions staff use less often, each with a plain-words label (no guessing what an icon means)
    function openMore() {
      const sh = sheet('More for this chat'); const it = (k, ic, t, d) => `<button type="button" class="wx-mi" data-k="${k}"><span>${ic}</span><b>${t}<small>${d}</small></b></button>`;
      sh.body.innerHTML = it('cust', '👤', 'Customer details', 'Name, orders, tags, notes') + it('jr', '🧭', 'Customer journey', 'Everything this customer did, step by step')
        + it('hand', '↪️', 'Hand over to a colleague', 'Search a name — the chat moves to them') + it('cx', '⚠️', 'Mark as complaint', 'Send this chat to the Complaint team') + it('rep', '🚩', 'Report a bad bot reply', 'The owner sees it on the Teach screen');
      sh.body.onclick = (e) => {
        const b = e.target.closest('[data-k]'); if (!b) return; const k = b.dataset.k;
        if (k === 'cust' || k === 'rep' || k === 'hand') { sh.el._shut(); S.reuseSheet = 1; (k === 'cust' ? openCustomer : k === 'hand' ? openHandover : reportBad)(); return; }   // the next sheet takes this one's Back step
        sh.close(); setTimeout(() => (k === 'jr' ? openJourney : markComplaint)(), 60);
      };
    }
    // ---- ✅ Order completed: one thank-you, then no automatic messages today and tomorrow (wa_done.php). A green strip says so; Undo switches them back on.
    async function odBanner() {
      const ph = S.cur && S.cur.phone; if (!ph) return; let st; try { st = await api.get('wa.order_done', { phone: ph }); } catch (e) { return; } if (!S.cur || S.cur.phone !== ph) return;
      const b = $('#wxOdB'); if (!b) return; const tb = $('#wxOd'); if (tb) tb.classList.toggle('on', !!(st && st.muted));
      if (!st || !st.muted) { b.innerHTML = ''; return; }
      const till = new Date(String(st.until).replace(' ', 'T')); const tl = isNaN(till) ? '' : till.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
      b.innerHTML = `<div class="wx-banner" style="background:#dcfce7;color:#14532d;display:flex;gap:8px;align-items:center">✅ <span style="flex:1">Order completed${st.order ? ' · ' + esc(st.order) : ''}${st.how === 'shopify' ? ' (Shopify)' : st.by ? ' by ' + esc(st.by) : ''} — automatic messages off until ${esc(tl)} night. You can still write.</span><button type="button" id="wxOdU" style="border:0;background:#fff;border-radius:99px;padding:5px 10px;font-weight:700;color:#14532d">Undo</button></div>`;
      $('#wxOdU').onclick = async () => { if (!confirm('Switch automatic messages back on for this customer?')) return; try { await api.post('wa.order_done', { phone: ph, undo: 1 }); toast('Automatic messages are on again'); } catch (e) { toast(e.message, true); } odBanner(); };
    }
    async function markDone() {
      if (!S.cur) return; const ph = S.cur.phone; const o = prompt('✅ Order completed (address + payment received)?\n\nThe customer gets ONE thank-you message, then no automatic messages today and tomorrow.\n\nOrder number (optional):', '');
      if (o === null) return; try { const r = await api.post('wa.order_done', { phone: ph, order: o.trim() }); toast('✅ Order completed' + (r.thanks ? ' · ' + r.thanks : '')); } catch (e) { toast(e.message, true); } odBanner();
    }
    // ---- Complaints: the ⚠ button raises a case; once the chat is with the Complaint team a red strip says so
    async function cxBanner() {
      const ph = S.cur && S.cur.phone; if (!ph || !window.CXR) return; const st = await CXR.state(ph); if (!S.cur || S.cur.phone !== ph) return;
      const b = $('#wxCxB'); if (b) b.innerHTML = CXR.bannerHtml(st); const x = $('#wxMore'); if (x && st && st.case) { x.style.background = '#f3efff'; x.style.color = '#6d4fc2'; }
      // ⚠ open complaint, owned by the complaint team: the customer hears ONE voice — this box writes a 📝 note for the team instead (the chat stays here)
      S.cur.cxLock = !!(st && st.case && !st.mine); const tx = $('#wxTx'); if (tx) { tx.placeholder = S.cur.cxLock ? '📝 Note for the complaint team (the customer will not see it)' : 'Message'; tx.classList.toggle('cxl', S.cur.cxLock); }
    }
    // 🚩 one brain, staff side: a bad bot reply goes to the owner's Teach screen (note + screenshots + the last messages). Nothing changes until the owner approves it.
    function reportBad() {
      const sh = sheet('Report a bad reply'), shots = [], ph = S.cur.phone;
      const last = (S.cur.msgs || []).filter((m) => m.dir !== 'sys' && m.body).slice(-8).map((m) => (m.dir === 'in' ? 'Customer: ' : (m.who === 'bot' ? 'Bot: ' : 'Us: ')) + String(m.body).slice(0, 300)).join('\n');
      const draw = () => { sh.body.innerHTML = '<p style="margin:0 0 8px;font-size:13px;color:#667">Tell the owner what the bot (or the suggestion) did wrong and what it should do instead. The last messages of this chat are added for you.</p>'
        + '<textarea id="rpN" rows="4" style="width:100%;box-sizing:border-box;font:inherit;padding:8px;border:1px solid #d9dde3;border-radius:10px" placeholder="e.g. When the customer chose a chain, the bot sent the cart link. It should first send our community link."></textarea>'
        + '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px"><label class="wx-btn gh" style="cursor:pointer">📷 Screenshots<input type="file" id="rpF" accept="image/*" multiple hidden></label>' + shots.map((x, i) => '<img src="data:' + x.mime + ';base64,' + x.data + '" alt="screenshot ' + (i + 1) + '" style="height:48px;border-radius:6px;border:1px solid #ddd">').join('') + '<span style="font-size:12px;color:#889">' + (shots.length ? shots.length + ' of 4' : 'optional') + '</span></div>'
        + '<div id="rpE"></div><button class="wx-btn" id="rpGo" style="width:100%;margin-top:10px">Send to the owner</button>';
        const keep = sh.body.dataset.note || ''; $('#rpN', sh.body).value = keep; $('#rpN', sh.body).oninput = (e) => { sh.body.dataset.note = e.target.value; };
        $('#rpF', sh.body).onchange = async (e) => { for (const f of [...e.target.files]) { if (shots.length >= 4) break; const x = await shrink(f); if (x) shots.push(x); } draw(); };
        $('#rpGo', sh.body).onclick = async function () { const note = $('#rpN', sh.body).value.trim(); if (!note && !shots.length) { $('#rpE', sh.body).innerHTML = '<div class="wx-banner y">Write what was wrong, or add a screenshot.</div>'; return; }
          this.disabled = true; this.textContent = 'Sending…';
          try { const r = await api.post('teach.report', { note, chat: last, phone: ph, images: shots }); sh.close(); toast(r.message || 'Sent to the owner.'); } catch (er) { $('#rpE', sh.body).innerHTML = '<div class="wx-banner y">' + esc(er.message) + '</div>'; this.disabled = false; this.textContent = 'Send to the owner'; } };
      };
      const shrink = (file) => new Promise((ok) => { if (!file || !/^image\//.test(file.type)) return ok(null); const fr = new FileReader(); fr.onload = () => { const im = new Image(); im.onload = () => { const k = Math.min(1, 1400 / Math.max(im.width, im.height)); const cv = document.createElement('canvas'); cv.width = Math.round(im.width * k); cv.height = Math.round(im.height * k); cv.getContext('2d').drawImage(im, 0, 0, cv.width, cv.height); ok({ mime: 'image/jpeg', data: cv.toDataURL('image/jpeg', 0.8).split(',')[1] }); }; im.onerror = () => ok(null); im.src = fr.result; }; fr.onerror = () => ok(null); fr.readAsDataURL(file); });
      draw();
    }
    function markComplaint() {
      if (!window.CXR) { toast('Complaints are not installed yet. Refresh the page.', true); return; }
      const c = S.cur.contact || {}; CXR.open({ phone: S.cur.phone, name: c.name || '', source: 'whatsapp', onDone: () => { cxBanner(); } });
    }
    async function transcribe(mid) {
      const box = $('[data-tm="' + mid + '"]'); if (!box) return; box.hidden = false; box.style.cssText = 'font-size:12.5px;color:var(--mut);margin-top:4px'; box.textContent = 'Reading the voice note…';
      try { const d = await api.post('wa.g_transcribe', { phone: S.cur.phone, media_id: mid }); box.style.color = 'var(--ink)'; box.textContent = '“' + d.text + '”'; } catch (e) { box.textContent = e.message; }
    }
    async function loadChat() {
      const phone = S.cur.phone;
      try {
        const d = await api.get('wa.chat', { phone, limit: 80 }); if (!S.cur || S.cur.phone !== phone) return; S.cur.contact = d.contact; S.cur.msgs = d.messages; S.cur.moreOld = !!d.more; S.cur.last = Math.max(0, ...d.messages.map((m) => m.id)); S.cur.row = Object.assign({}, S.cur.row, { name: d.contact.name });
        const c = d.contact; $('#wxCn').innerHTML = esc(c.name || pretty(c.phone)) + ' ' + badgeOf(S.cur.row); $('#wxMs').innerHTML = msgsHtml(); scrollEnd(true); { const mb = $('#wxMs'); mb.onscroll = () => { if (mb.scrollTop < 60) loadOlder(); }; mb.onclick = (e) => { if (e.target.closest('#wxOld')) loadOlder(); }; } drawFoot(); loadSugg(false); loadInfo();
        const imgs = S.cur.msgs.filter((m) => m.media_id && (m.mtype === 'image') && !MEDIA[m.media_id]).slice(-4); imgs.forEach((m) => mediaUrl(m.media_id).then((u) => { const el = $('.wx-ld[data-m="' + m.media_id + '"]'); if (el) { el.outerHTML = `<img src="${u}" alt="">`; } }).catch(() => { }));
      } catch (e) { toast(e.message, true); closeChat(); }
    }
    async function loadInfo() {
      const phone = S.cur && S.cur.phone; if (!phone) return;
      try { const d = await api.get('wa.g_customer', { phone }); if (!S.cur || S.cur.phone !== phone) return;   // another chat was opened meanwhile: never show this name in it S.cur.info = d; S.cur.row.badge = d.badge; const n = d.numbers || {}, bar = $('#wxNum'); if (bar && n.reply_from) { bar.hidden = false; bar.innerHTML = `📱 Reply from <b>${esc(n.reply_from)}</b>${n.earlier && n.earlier.length > 1 ? ' · also chatted on ' + n.earlier.filter((x) => x.label !== n.reply_from).map((x) => esc(x.label)).join(', ') : ''}${n.switch ? ' · <span class="go">try ' + esc(n.next_utility) + '</span>' : ''}`; }
        $('#wxCn').innerHTML = esc(d.name || pretty(d.phone)) + ' ' + badgeOf({ badge: d.badge }); $('#wxCs').textContent = [pretty(d.phone), d.orders_n ? d.orders_n + ' orders · ' + money(d.ltv) : 'no orders yet'].join(' · '); } catch (e) { }
    }
    async function pollChat() {
      if (!S.cur) return; const phone = S.cur.phone;
      try {
        const d = await api.get('wa.g_poll', { phone, after: S.cur.last }); if (!S.cur || S.cur.phone !== phone) return;
        const fresh = (d.messages || []).filter((m) => !S.cur.msgs.some((x) => x.id === m.id)); const had = S.cur.msgs.length;
        if (fresh.length) {   // only the new bubbles are added; a "sending…" bubble is swapped for the real one in place — no full redraw (that was the lag)
          const box = $('#wxMs'), lastOld = S.cur.msgs[S.cur.msgs.length - 1], add = fresh.slice(); let full = !box || (lastOld && dayLbl(lastOld.ts) !== dayLbl(fresh[0].ts)) || fresh.some((m) => m.number && lastOld && lastOld.number && m.number !== lastOld.number);
          const kept = [];
          for (const m of S.cur.msgs) {
            const i = m.tmp ? add.findIndex((f) => f.dir === 'out' && f.body === m.body) : -1; if (i < 0) { kept.push(m); continue; }
            const f = add.splice(i, 1)[0], el = box && box.querySelector('[data-id="' + m.id + '"]');
            if (el && !full) { el.outerHTML = bubble(f); kept.push(f); } else add.push(f);   // the sent copy takes the "sending…" bubble's place
          }
          S.cur.msgs = kept;
          fresh.forEach((f) => { if (!S.cur.msgs.includes(f)) S.cur.msgs.push(f); }); S.cur.last = Math.max(S.cur.last, ...fresh.map((m) => m.id));
          if (full) box.innerHTML = msgsHtml(); else add.forEach((f) => box.insertAdjacentHTML('beforeend', bubble(f))); scrollEnd(false); if (fresh.some((m) => m.dir === 'in')) { beepNew(); loadSugg(true); } }
        (d.status || []).forEach((u) => { const m = S.cur.msgs.find((x) => x.id === u.id); if (m && m.status !== u.status) { m.status = u.status; redrawBubble(m); } });   // ticks: only that bubble changes
        if (S.cur.contact.window_open !== d.window_open || (d.optout != null && !!S.cur.contact.optout !== !!d.optout)) { S.cur.contact.window_open = d.window_open; if (d.optout != null) S.cur.contact.optout = !!d.optout; drawFoot(); }   // STOP while the chat is open locks the box at once
        const imgs = fresh.filter((m) => m.media_id && m.mtype === 'image'); imgs.forEach((m) => mediaUrl(m.media_id).then((u) => { const el = $('.wx-ld[data-m="' + m.media_id + '"]'); if (el) el.outerHTML = `<img src="${u}" alt="">`; }).catch(() => { }));
      } catch (e) {
        if (/assigned to someone else|not found/i.test(e.message || '') && S.cur && S.cur.phone === phone && !S.cur.lost) {   // a colleague took this chat (late-reply pick-up / transfer): say so, stop typing into it
          S.cur.lost = true; const f = $('#wxFoot'); if (f) f.innerHTML = '<div class="wx-banner y" style="margin:8px">🔒 This chat was given to a colleague. You can no longer reply here. <button class="wx-btn gh" id="wxLostBk" style="margin-left:6px">Back to chats</button></div>';
          const bk = $('#wxLostBk'); if (bk) bk.onclick = () => { if (history.state && history.state.wxChat) history.back(); else closeChat(); }; toast('This chat was given to a colleague.', true); loadList(true);
        }
      }
    }
    function closeChat() { stopRec(true); suggStop(); const phone = S.cur && S.cur.phone; S.cur = null; markSel(); const v = $('.wx-chat'); if (v) v.remove(); loadList(true); if (S.tab === 'history') drawHistory(false); if (S.tab === 'queries' && S.qView && S.qView.refresh) S.qView.refresh(); }

    // ---- Claude's suggestion (written before you open the chat)
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
    async function drawQk() {
      const q = $('#wxQk'); if (!q || !S.cur) return; const phone = S.cur.phone, c = S.cur.contact, last = S.cur.msgs.filter((m) => m.dir !== 'sys').slice(-1)[0];
      if (!c.window_open || c.optout || !last || last.dir !== 'in' || !last.body) { q.hidden = true; return; }
      if (!S.quick) { try { S.quick = (await api.get('wa.quick_list')).items || []; } catch (e) { S.quick = []; } }
      if (!S.cur || S.cur.phone !== phone) return; const hit = qkMatch(last.body, S.quick), t = $('#wxTx');
      if (!hit.length || (t && t.value.trim())) { q.hidden = true; return; }
      const fn = first(c.name); q.hidden = false; q.innerHTML = '<span>⚡</span>' + hit.map((x, i) => `<button data-i="${i}">${esc(x.title)}</button>`).join('');
      q.onclick = (e) => { const b = e.target.closest('button'); if (!b) return; const x = hit[+b.dataset.i], tx = $('#wxTx'); if (!x || !tx) return; suggStop(); if (S.cur.sg) S.auDone[S.cur.phone + ':' + S.cur.sg.li] = 1; tx.value = x.body.replace(/\{name\}/gi, fn).replace(/\s+([!,.])/g, '$1').replace(/  +/g, ' '); autoGrow(tx); swapSend(); tx.focus(); q.hidden = true; if (S.cur.sg) { suggDraw(); } };
    }
    // Claude has already written the reply when the message arrived. A thin green line runs for the team's countdown (20 s); at zero it goes by itself — unless ✕ (cancel) or typing stops it.
    S.auDone = S.auDone || {};
    function suggStop() { const g = S.cur && S.cur.sg; if (g) { clearTimeout(g.tm); clearInterval(g.iv); g.counting = false; } }
    function suggDraw() {
      const box = $('#wxSug'), g = S.cur && S.cur.sg; if (!box) return; if (!g) { box.innerHTML = ''; drawPp(); return; } const n = g.counting;
      box.innerHTML = `<div class="wx-sug">${n ? `<i class="bar"><u style="animation-duration:${g.secs}s;animation-delay:-${Math.max(0, (Date.now() - g.t0) / 1000).toFixed(2)}s"></u></i>` : ''}<div class="r"><span class="ic">✨</span><span class="t" id="wxSgT">${esc(g.text)}</span>${g.call ? '<em title="Better to call">📞</em>' : ''}<button id="wxSgS">${n ? `<b id="wxSgN">${g.secs}</b>s · ` : ''}Send</button><button class="x" id="wxSgX" aria-label="${n ? 'Cancel' : 'Dismiss'}">✕</button></div></div>`;
      $('#wxSgS').onclick = () => { suggStop(); const t = g.text, pr = g.prod; S.cur.sg = null; box.innerHTML = ''; sendText(t, pr); };
      $('#wxSgT').onclick = () => { suggStop(); S.auDone[S.cur.phone + ':' + g.li] = 1; const t = $('#wxTx'); S.cur.armed = g.prod && g.prod.length ? { text: g.text, prod: g.prod } : null; S.cur.sg = null; box.innerHTML = ''; drawPp(); if (t) { t.value = g.text; autoGrow(t); t.focus(); swapSend(); } };
      $('#wxSgX').onclick = () => { if (n) { suggStop(); S.auDone[S.cur.phone + ':' + g.li] = 1; suggDraw(); } else { S.cur.sg = null; box.innerHTML = ''; drawPp(); } };
      if (n) { g.iv = setInterval(() => { const el = $('#wxSgN'); if (el) el.textContent = Math.max(0, Math.ceil(g.secs - (Date.now() - g.t0) / 1000)); }, 250); }
      drawPp();
    }
    async function suggAuto(g) {
      if (!S.cur || S.cur.sg !== g || !g.counting) return; const phone = S.cur.phone; clearInterval(g.iv); g.counting = false;
      const t = $('#wxTx'); if (document.hidden || document.querySelector('.wx-sh') || (t && t.value.trim())) { suggDraw(); return; }   // nobody is looking / something else is open / they are typing: never send behind their back
      try { await pollChat(); } catch (e) { }   // last look before it goes
      if (!S.cur || S.cur.phone !== phone || S.cur.sg !== g) return;
      const last = S.cur.msgs.filter((m) => m.dir !== 'sys' && !m.tmp).slice(-1)[0]; const t2 = $('#wxTx');
      if (!last || last.dir !== 'in' || last.id !== g.li || (t2 && t2.value.trim())) { suggDraw(); return; }
      S.auDone[phone + ':' + g.li] = 1; const pr = g.prod; S.cur.sg = null; $('#wxSug').innerHTML = ''; sendText(g.text, pr, true);
    }
    async function loadSugg(force) {
      const box = $('#wxSug'); if (!box || !S.cur) return; const phone = S.cur.phone; const c = S.cur.contact; const last = S.cur.msgs.filter((m) => m.dir !== 'sys').slice(-1)[0];
      suggStop(); S.cur.sg = null; S.cur.armed = null; drawPp(); drawQk();
      if (!c.window_open || c.optout || !last || last.dir !== 'in') { box.innerHTML = ''; return; }
      if (S.cur.sugg === undefined || force) box.innerHTML = '<div class="wx-sug"><div class="r"><span class="ic">✨</span><span class="t ld">Writing a reply…</span></div></div>';
      try {
        const d = await api.get('wa.g_sugg', { phone, make: 1 }); if (!S.cur || S.cur.phone !== phone) return; S.cur.sugg = d.sugg;
        const nowLast = S.cur.msgs.filter((m) => m.dir !== 'sys' && !m.tmp).slice(-1)[0]; if (!nowLast || nowLast.dir !== 'in' || nowLast.id !== last.id) return;   // the customer wrote again meanwhile: the newer call draws
        if (!d.sugg) { box.innerHTML = ''; return; }
        let prod = [], flag = ''; try { await new Promise((ok) => prodReady(ok)); if (window.WaProd) { const a = await Promise.race([WaProd.attached(phone), new Promise((z) => setTimeout(() => z(null), 1200))]); if (a) { prod = a.items || a.products || []; flag = a.flag || ''; } } } catch (e) { }   // the photos are known before the countdown starts, so it never jumps
        if (!S.cur || S.cur.phone !== phone) return; const nl2 = S.cur.msgs.filter((m) => m.dir !== 'sys' && !m.tmp).slice(-1)[0]; if (!nl2 || nl2.dir !== 'in' || nl2.id !== last.id) return;
        const au = d.auto || {}, secs = Math.max(5, Math.min(60, +au.secs || 20)), t = $('#wxTx');
        const go = !!au.on && !d.sugg.call_now && !d.hot && !document.hidden && !S.auDone[phone + ':' + last.id] && !(t && t.value.trim()) && !c.hot;
        const g = S.cur.sg = { text: d.sugg.text, li: last.id, secs, call: !!d.sugg.call_now, counting: go, t0: Date.now(), prod, flag };
        suggDraw(); if (go) g.tm = setTimeout(() => suggAuto(g), secs * 1000);
        scrollEnd(false);
      } catch (e) { box.innerHTML = ''; }
    }
    document.addEventListener('visibilitychange', () => { if (document.hidden && S.cur && S.cur.sg && S.cur.sg.counting) { suggStop(); suggDraw(); } });

    // ---- 🧴 products: "Find a perfume" + the photos that follow a prepared reply (wa_prod.php / wa_prod_ui.js)
    let prodLd = null;
    function prodReady(cb) {
      if (window.WaProd) return cb(); if (prodLd) { prodLd.push(cb); return; } prodLd = [cb];
      const sc = document.createElement('script'); sc.src = 'assets/js/wa_prod_ui.js?v=pr4';
      sc.onload = () => { const q = prodLd || []; prodLd = null; q.forEach((f) => f()); }; sc.onerror = () => { const q = prodLd || []; prodLd = null; q.forEach((f) => f()); }; document.head.appendChild(sc);
    }
    function drawPp() {
      const el = $('#wxPp'); if (!el) return; const g = S.cur && S.cur.sg, list = g ? g.prod : (S.cur && S.cur.armed ? S.cur.armed.prod : null), flag = g ? g.flag : '';
      if (!window.WaProd || (!(list && list.length) && !flag)) { el.innerHTML = ''; return; }
      el.innerHTML = (flag ? '<div class="wpr-pt warn">⚠ ' + esc(flag) + '</div>' : '') + (list && list.length ? '<div class="wpr-pt">📷 ' + list.length + (list.length > 1 ? ' photos follow' : ' photo follows') + ' the text · ✕ takes one off</div><div class="wpr-pend">' + WaProd.pendHtml(list) + '</div>' : '');
      el.onclick = (e) => { const b = e.target.closest('[data-rm]'); if (!b || !list) return; list.splice(+b.dataset.rm, 1); drawPp(); };
    }
    // ---- customer journey (wa_seg.php / wa_seg_ui.js)
    let segLd = null;
    function segReady(cb) {
      if (window.WaSeg) return cb(); if (segLd) { segLd.push(cb); return; } segLd = [cb];
      const sc = document.createElement('script'); sc.src = 'assets/js/wa_seg_ui.js?v=1';
      sc.onload = () => { const q = segLd || []; segLd = null; q.forEach((f) => f()); }; sc.onerror = () => { const q = segLd || []; segLd = null; q.forEach((f) => f()); }; document.head.appendChild(sc);
    }
    function openJourney() {
      if (!S.cur) return; const c = S.cur.contact || {}, p = c.phone || S.cur.phone, nm = (c.name || (S.cur.row && S.cur.row.name) || '').trim() || pretty(p);
      segReady(() => { if (!window.WaSeg) { toast('Could not open the journey. Refresh the page.', true); return; } WaSeg.open({ phone: p, name: nm }); });
    }
    // ---- 🛒 chain + perfume cart (wch.php / wch_ui.js): lazy-loaded the first time the entry is used
    let chLd = null;
    function chReady(cb) {
      if (window.WaCart) return cb(); if (chLd) { chLd.push(cb); return; } chLd = [cb];
      const sc = document.createElement('script'); sc.src = 'assets/js/wch_ui.js?v=ch9';
      sc.onload = () => { const q = chLd || []; chLd = null; q.forEach((f) => f()); }; sc.onerror = () => { const q = chLd || []; chLd = null; q.forEach((f) => f()); }; document.head.appendChild(sc);
    }
    // ---- 🧾 manual order (wmo.php / wmo_ui.js): QR / UPI payment first, the customer fills the address on a link
    let moLd = null;
    function moReady(cb) {
      if (window.WaManual) return cb(); if (moLd) { moLd.push(cb); return; } moLd = [cb];
      const sc = document.createElement('script'); sc.src = 'assets/js/wmo_ui.js?v=1';
      sc.onload = () => { const q = moLd || []; moLd = null; q.forEach((f) => f()); }; sc.onerror = () => { const q = moLd || []; moLd = null; q.forEach((f) => f()); }; document.head.appendChild(sc);
    }
    function openManual() {
      if (!S.cur) return; const c = S.cur.contact || {}, p = c.phone || S.cur.phone, nm = (c.name || (S.cur.row && S.cur.row.name) || '').trim() || pretty(p);
      moReady(() => { if (!window.WaManual) { toast('Could not open the manual order. Refresh the page.', true); return; } WaManual.open({ phone: p, name: nm, api, onDone: () => { try { pollChat(); } catch (e) { } } }); });
    }
    function openCart() {
      if (!S.cur) return; const c = S.cur.contact || {}, p = c.phone || S.cur.phone, nm = (c.name || (S.cur.row && S.cur.row.name) || '').trim() || pretty(p);
      chReady(() => { if (!window.WaCart) { toast('Could not open the cart. Refresh the page.', true); return; } WaCart.open({ phone: p, name: nm, api, onChange: () => { try { pollChat(); } catch (e) { } } }); });
    }
    // ---- 📦 live orders of this customer (wa_orders.php / wa_ord_ui.js)
    let ordLd = null;
    function ordReady(cb) {
      if (window.WaOrd) return cb(); if (ordLd) { ordLd.push(cb); return; } ordLd = [cb];
      const sc = document.createElement('script'); sc.src = 'assets/js/wa_ord_ui.js?v=2';
      sc.onload = () => { const q = ordLd || []; ordLd = null; q.forEach((f) => f()); }; sc.onerror = () => { const q = ordLd || []; ordLd = null; q.forEach((f) => f()); }; document.head.appendChild(sc);
    }
    function openOrders() {
      if (!S.cur) return; const c = S.cur.contact || {}, p = c.phone || S.cur.phone, nm = (c.name || (S.cur.row && S.cur.row.name) || '').trim() || pretty(p);
      ordReady(() => { if (!window.WaOrd) { toast('Could not open orders. Refresh the page.', true); return; } WaOrd.open({ phone: p, name: nm, onChange: () => { pollChat(); } }); });
    }
    function openFind() {
      if (!S.cur) return; const c = S.cur.contact;
      prodReady(() => { if (!window.WaProd) { toast('Could not open the perfume list. Refresh the page.', true); return; } WaProd.open({ phone: c.phone, name: (c.name || '').trim() || pretty(c.phone), canSend: !!c.window_open && !c.optout, whyNot: 'The 24-hour window is closed, so a photo cannot be sent now.', onSent: () => { pollChat(); } }); });
    }

    // ---- footer: composer or the 24-hour lock
    function drawFoot() {
      const f = $('#wxFoot'); if (!f) return; const c = S.cur.contact; const off = c.optout || !c.window_open;
      if (S.cur.peek === 'tag') { f.innerHTML = '<div style="padding:12px 14px calc(14px + env(safe-area-inset-bottom));text-align:center;color:#92400e;background:#fffbeb;font-weight:700">👀 Read only — this chat is with someone else now</div>'; return; }
      if (S.cur.peek) { f.innerHTML = '<div style="padding:10px 12px calc(12px + env(safe-area-inset-bottom))"><button class="wx-btn" id="wxPk" style="width:100%;min-height:52px;font-size:17px;font-weight:800;border-radius:99px;background:#00a884;color:#fff;border:0">✋ Take over this chat</button></div>'; $('#wxPk').onclick = takeReq; return; }
      if (S.cur.watch) { f.innerHTML = '<div class="wx-lock"><span>👀 You are watching a colleague’s chat. Take over to reply.</span>' + (S.cur.row.heat === 'cx' ? '' : '<button class="wx-btn" id="wxTo2">🔄 Take over</button>') + '</div>'; const t2 = $('#wxTo2'); if (t2) t2.onclick = takeOver; return; }
      if (c.optout) { f.innerHTML = '<div class="wx-lock"><span>🚫 This customer opted out. Do not message them.</span></div>'; return; }
      if (off) { f.innerHTML = '<div class="wx-lock"><span>🔒 24 hours passed since their last message. Only a pre-made message can go now.</span><button class="wx-btn" id="wxTp">Pre-made</button></div>'; $('#wxTp').onclick = openTemplates; return; }
      if (f.querySelector('#wxTx')) return;
      f.innerHTML = `<div class="wx-tools"><button class="wx-tl" id="wxAt" title="Send a photo, video or document">📎<small>Attach</small></button><button class="wx-tl" id="wxPf" title="Find a perfume and send its photo">🧴<small>Perfume</small></button><button class="wx-tl" id="wxOr" title="Orders of this customer">📦<small>Orders</small></button><button class="wx-tl" id="wxOd" title="Order completed: one thank-you, then no automatic messages today and tomorrow">✅<small>Order done</small></button><button class="wx-tl" id="wxCt" title="Make a cart with a chain">🛒<small>Cart</small></button><button class="wx-tl" id="wxLs" title="Send a ready perfume list">🗒️<small>Lists</small></button><button class="wx-tl" id="wxCxR" title="Claude's suggested reply and the complaint pre-made messages — pick one, edit, send">✨<small>Replies ▾</small></button></div><div class="wx-bar"><div class="wx-tw"><textarea id="wxTx" rows="1" placeholder="Message"></textarea><button type="button" class="wx-qrb" id="wxQr" aria-label="Quick replies" title="Quick replies">⚡</button></div><button class="wx-ic" id="wxMic" aria-label="Voice note">🎤</button><button class="wx-ic snd" id="wxGo" aria-label="Send" hidden>➤</button></div>`;
      const t = $('#wxTx'); t.oninput = () => { typingPing(); autoGrow(t); swapSend(); slashW(t); { const qk = $('#wxQk'); if (qk && t.value.trim()) qk.hidden = true; } if (S.cur && S.cur.sg && S.cur.sg.counting) { suggStop(); S.auDone[S.cur.phone + ':' + S.cur.sg.li] = 1; suggDraw(); } }; $('#wxAt').onclick = openAttach; $('#wxPf').onclick = openFind; $('#wxOr').onclick = openOrders; $('#wxOd').onclick = markDone; $('#wxCt').onclick = openCart; $('#wxLs').onclick = openLists; { const cr = $('#wxCxR'); if (cr) cr.onclick = openCxReplies; } $('#wxMic').onclick = startRec; $('#wxGo').onclick = () => { const v = t.value.trim(); if (v) { const ar = S.cur && S.cur.armed; const pr = ar && v.slice(0, 8).toLowerCase() === ar.text.slice(0, 8).toLowerCase() ? ar.prod : null; if (S.cur) S.cur.armed = null; t.value = ''; autoGrow(t); swapSend(); sendText(v, pr); } };
      t.onkeydown = (e) => { if (e.key === 'Enter' && !e.shiftKey && !('ontouchstart' in window)) { e.preventDefault(); $('#wxGo').click(); } };
      { const qr = $('#wxQr'); if (qr) qr.onclick = openQuick; }
    }
    // ✨ Replies (complaint ticket): Claude's suggested reply + the complaint team's pre-made messages in ONE list — tap one, it goes into the box, edit, send
    async function openCxReplies() {
      if (!CXID) return; const sh = sheet('✨ Replies'); sh.body.innerHTML = '<input class="wx-in" id="wxCrQ" placeholder="Search the pre-made messages" style="width:100%;box-sizing:border-box;margin-bottom:8px"><div id="wxCrL"><div class="wx-sk"></div></div>';
      let sug = null, quick = [];
      const put = (txt) => { const t = $('#wxTx'); if (!t) return; t.value = (t.value.trim() ? t.value.trim() + '\n' : '') + txt; autoGrow(t); swapSend(); sh.close(); setTimeout(() => { t.focus(); try { t.setSelectionRange(t.value.length, t.value.length); } catch (e) { } }, 60); };
      const draw = () => { const q = ($('#wxCrQ', sh.el) || {}).value ? $('#wxCrQ', sh.el).value.trim().toLowerCase() : ''; const L = quick.filter((x) => !q || (String(x.title) + ' ' + String(x.text)).toLowerCase().includes(q));
        $('#wxCrL', sh.el).innerHTML = (sug && sug.text && !q ? '<div class="wx-h" style="padding:4px 2px">✨ Claude suggests</div><div class="wx-row" data-s="1" style="cursor:pointer;border:1px solid #e9d5ff;background:#faf5ff;border-radius:12px;padding:10px 12px;margin-bottom:6px;white-space:pre-wrap">' + esc(sug.text) + '</div><button type="button" class="wx-btn gh" id="wxCrRe" style="margin-bottom:10px">↻ Another suggestion</button>' : (!q && sug === null ? '<div class="wx-sub" style="padding:4px 2px 10px">✨ Claude is reading the chat…</div>' : ''))
          + '<div class="wx-h" style="padding:4px 2px">📋 Pre-made messages</div>' + (L.length ? L.map((x, i) => '<div class="wx-row" data-q="' + quick.indexOf(x) + '" style="cursor:pointer;border:1px solid var(--line,#e9edef);border-radius:12px;padding:10px 12px;margin-bottom:6px;display:block"><b style="display:block">' + esc(x.title) + '</b><span style="white-space:pre-wrap;color:#54656f;font-size:13px">' + esc(String(x.text).slice(0, 220)) + '</span></div>').join('') : '<div class="wx-empty">No pre-made message' + (q ? ' matches' : ' yet — add them in Complaints → Settings → Quick replies') + '.</div>');
        const re = $('#wxCrRe', sh.el); if (re) re.onclick = () => { sug = null; draw(); loadSug(); }; };
      const loadSug = () => api.get('bd.cx_suggest', { id: CXID }).then((d) => { sug = d && d.text ? d : { text: '' }; if (sh.el.isConnected) draw(); }).catch(() => { sug = { text: '' }; if (sh.el.isConnected) draw(); });
      sh.body.onclick = (e) => { const r = e.target.closest('[data-q],[data-s]'); if (!r) return; if (r.dataset.s) { if (sug && sug.text) put(sug.text); return; } const x = quick[+r.dataset.q]; if (x) put(String(x.text)); };
      $('#wxCrQ', sh.el).oninput = draw;
      try { const d = await api.get('bd.cx_quick', { id: CXID }); quick = (d && d.quick) || []; } catch (e) { quick = []; } draw(); loadSug();
    }
    const autoGrow = (t) => { t.style.height = 'auto'; t.style.height = Math.min(110, t.scrollHeight) + 'px'; };
    // "I am typing": at most once every 20 s the server is told, so the no-answer flow leaves this chat alone for a minute (wq.typing)
    const tpAt = {};   // per customer: typing in one chat never hides that you are typing in another
    function typingPing() { const n = Date.now(); if (!S.cur || n - (tpAt[S.cur.phone] || 0) < 20000) return; tpAt[S.cur.phone] = n; try { api.post('wq.typing', { phone: S.cur.phone }).catch(() => { }); } catch (e) { } }
    function swapSend() { const t = $('#wxTx'); if (!t) return; const has = t.value.trim() !== ''; $('#wxGo').hidden = !has; $('#wxMic').hidden = has; }

    // ---- sending (optimistic: the bubble is there at once)
    // Sending: the bubble shows at once (only it is added — the chat is not redrawn), the server call goes behind it. Every tap carries its own id (cid),
    // so a slow network, a double tap or a Retry can never make the customer get the message twice.
    function addBubble(m) {
      const box = $('#wxMs'); if (!box) return; const prev = S.cur.msgs[S.cur.msgs.length - 2];
      if (prev && dayLbl(prev.ts) === dayLbl(m.ts)) box.insertAdjacentHTML('beforeend', bubble(m)); else box.innerHTML = msgsHtml();
    }
    function redrawBubble(m) { const el = $('#wxMs') && $('#wxMs').querySelector('[data-id="' + m.id + '"]'); if (el) el.outerHTML = bubble(m); else if ($('#wxMs')) $('#wxMs').innerHTML = msgsHtml(); }
    async function sendText(text, prod, auto, again) {
      if (!S.cur) return; const phone = S.cur.phone;
      if (S.cur.cxLock && !again) { if (auto) return; try { await api.post('wa.cx_note', { phone, text }); toast('📝 Note sent to the complaint team — the customer does not see it.'); } catch (e) { toast(e.message, true); const t = $('#wxTx'); if (t && !t.value) t.value = text; } return; }
      const cid = again ? again.cid : 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
      const now = new Date(), ts = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')} ${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}:00`;
      const rp = !auto && !again && S.cur.rp ? S.cur.rp : null; if (rp) { S.cur.rp = null; rpDraw(); }
      const tmp = again || { id: 'tmp' + (++S.cur.tmp), dir: 'out', mtype: 'text', body: text, who: 'emp', ts, tmp: true, cid, quote: rp ? { id: rp.id, dir: rp.dir, text: rp.text } : null, reply_id: rp ? rp.id : 0 };
      tmp.status = 'sending'; tmp.retry = false; tmp.tmp = true;
      suggStop(); S.cur.sg = null; S.cur.armed = null; $('#wxSug').innerHTML = ''; { const pp = $('#wxPp'); if (pp) pp.innerHTML = ''; }
      if (again) redrawBubble(tmp); else { S.cur.msgs.push(tmp); addBubble(tmp); } scrollEnd(true);
      try {
        await api.post('wa.send', auto ? { phone, text, auto: true, cid } : (tmp.reply_id ? { phone, text, cid, reply_id: tmp.reply_id } : { phone, text, cid }));   // auto: the countdown reply; lets the server claim the customer message (wq no-answer flow)
        if (prod && prod.length && window.WaProd) { try { await WaProd.send(phone, prod.map((p) => p.id), 'why'); } catch (e) { toast('The text went, but the photos did not: ' + e.message, true); } }   // the photos follow the text, one by one
        if (S.cur && S.cur.phone === phone) pollChat();
      }
      catch (e) {
        if (auto && /already (been )?(answered|replied|claimed)|someone else/i.test(e.message || '')) { if (S.cur) { S.cur.msgs = S.cur.msgs.filter((m) => m !== tmp); $('#wxMs').innerHTML = msgsHtml(); } toast('Already answered by the bot or a colleague. Nothing was sent twice.'); return; }   // HTTP 409 from wq: quiet
        if (/open complaint/i.test(e.message || '')) { S.cur.cxLock = true; S.cur.msgs = S.cur.msgs.filter((m) => m !== tmp); $('#wxMs').innerHTML = msgsHtml(); const t = $('#wxTx'); if (t) { t.value = text; t.placeholder = '📝 Note for the complaint team (the customer will not see it)'; t.classList.add('cxl'); } toast(e.message, true); return; }   // the server says: complaint team only — keep the words, send them as a note
        tmp.status = 'failed'; tmp.retry = !auto && !/opted out|window is closed|assigned to someone/i.test(e.message || ''); if (S.cur && S.cur.phone === phone) redrawBubble(tmp); toast(e.message, true);
      }
    }
    function upload(f, phone, caption, onp) {
      return new Promise((res, rej) => {
        const fd = new FormData(); fd.append('phone', phone); fd.append('caption', caption || ''); fd.append('file', f, f.name); const x = new XMLHttpRequest(); x.open('POST', api.buildUrl('wa.send_media')); x.setRequestHeader('Authorization', 'Bearer ' + api.token()); x.timeout = 300000;
        x.upload.onprogress = (e) => { if (e.lengthComputable && onp) onp(e.loaded / e.total); };
        x.onload = () => { let d = {}; try { d = JSON.parse(x.responseText); } catch (e) { } if (x.status >= 200 && x.status < 300 && !d.error) res(d); else rej(new Error(d.error || ('The file could not be sent (' + x.status + ').'))); };
        x.onerror = () => rej(new Error('Network problem — check the connection.')); x.ontimeout = () => rej(new Error('Sending took too long. Try a smaller file.')); x.send(fd);
      });
    }
    async function squeeze(f) {
      if (f.type === 'image/jpeg' && f.size < 1.2 * MB) return f;
      try { const bmp = await createImageBitmap(f); let sc = Math.min(1, 1600 / Math.max(bmp.width, bmp.height)), q = .82, blob = null;
        for (let i = 0; i < 5; i++) { const cv = document.createElement('canvas'); cv.width = Math.max(1, Math.round(bmp.width * sc)); cv.height = Math.max(1, Math.round(bmp.height * sc)); const cx = cv.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height); cx.drawImage(bmp, 0, 0, cv.width, cv.height); blob = await new Promise((r) => cv.toBlob(r, 'image/jpeg', q)); if (blob && blob.size <= 4.5 * MB) break; q = Math.max(.55, q - .1); sc *= .8; }
        return blob ? new File([blob], (f.name || 'photo').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : f; } catch (e) { return f; }
    }
    async function sendFiles(list, caption) {
      if (S.cur && S.cur.sg) { suggStop(); S.cur.sg = null; const bx = $('#wxSug'); if (bx) bx.innerHTML = ''; drawPp(); }
      if (!S.cur) return; const phone = S.cur.phone; let cap = caption || '';
      for (const f0 of list) {
        const isImg = /^image\//.test(f0.type), isVid = /^video\//.test(f0.type), isAud = /^audio\//.test(f0.type);
        if (/\.mov$/i.test(f0.name) || f0.type === 'video/quicktime') { toast(f0.name + ' is .mov — WhatsApp needs MP4.', true); continue; }
        let f = f0; if (isImg) f = await squeeze(f0); const lim = isVid ? 16 : isImg ? 5 : isAud ? 16 : 100; if (f.size > lim * MB) { toast(f0.name + ' is ' + (f.size / MB).toFixed(1) + ' MB — WhatsApp allows ' + lim + ' MB.', true); continue; }
        const here = () => S.cur && S.cur.phone === phone;   // the person may leave this chat while files upload: the upload goes on, the screen of another chat is never touched
        const url = isImg || isVid ? URL.createObjectURL(f) : ''; const tmp = { id: 'tmp' + (here() ? ++S.cur.tmp : Date.now()), dir: 'out', mtype: isImg ? 'image' : isVid ? 'video' : isAud ? 'audio' : 'document', body: '', status: 'sending', who: 'emp', ts: (function () { const n = new Date(); return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}-${String(n.getDate()).padStart(2, '0')} ${String(n.getHours()).padStart(2, '0')}:${String(n.getMinutes()).padStart(2, '0')}:00`; })(), tmp: true, media_id: 'local' + Date.now() };
        if (url) MEDIA[tmp.media_id] = url; if (here()) { S.cur.msgs.push(tmp); $('#wxMs').innerHTML = msgsHtml(); scrollEnd(true); }
        try { const r = await upload(f, phone, isAud ? '' : cap); cap = ''; if (r.media_id && url) MEDIA[r.media_id] = url; if (here()) { S.cur.msgs = S.cur.msgs.filter((m) => m !== tmp); await pollChat(); } }
        catch (e) { if (here()) { S.cur.msgs = S.cur.msgs.filter((m) => m !== tmp); $('#wxMs').innerHTML = msgsHtml(); } toast(e.message, true); }
      }
    }

    // ---- voice note (MP3 / MP4 only: that is what WhatsApp accepts)
    let REC = null;
    async function startRec() {
      if (S.cur && S.cur.sg && S.cur.sg.counting) { suggStop(); S.auDone[S.cur.phone + ':' + S.cur.sg.li] = 1; suggDraw(); }
      if (!navigator.mediaDevices || !window.MediaRecorder) return toast('This phone cannot record here. Use 📎 Attach → Voice to pick a voice file.', true);
      try {
        const st = await navigator.mediaDevices.getUserMedia({ audio: true }); const types = ['audio/ogg;codecs=opus', 'audio/mp4;codecs=mp4a.40.2', 'audio/webm;codecs=opus', 'audio/webm', 'audio/mp4']; const mt = types.find((t) => MediaRecorder.isTypeSupported(t)) || '';   // WhatsApp plays OGG-Opus and MP4-AAC; anything else is turned into MP3 below
        const mr = new MediaRecorder(st, mt ? { mimeType: mt } : {}); const chunks = []; mr.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); }; REC = { mr, st, chunks, t0: Date.now(), mt: mr.mimeType || mt, req: mt, cancel: false };
        mr.onstop = async () => { st.getTracks().forEach((t) => t.stop()); const r = REC; REC = null; drawFoot2(); if (!r || r.cancel || !chunks.length) return; await finishRec(new Blob(chunks, { type: r.mt }), r.req); };
        mr.start(); const f = $('#wxFoot'); f.innerHTML = `<div class="wx-bar"><button class="wx-ic" id="wxRx" aria-label="Cancel">🗑</button><div class="wx-rec"><i></i><span id="wxRt">0:00</span></div><button class="wx-ic snd" id="wxRs" aria-label="Send voice note">➤</button></div>`;
        $('#wxRx').onclick = () => stopRec(true); $('#wxRs').onclick = () => stopRec(false); REC.tm = setInterval(() => { const e = $('#wxRt'); if (e && REC) e.textContent = mmss((Date.now() - REC.t0) / 1000); }, 500);
      } catch (e) { const nm = (e && e.name) || ''; toast(nm === 'NotAllowedError' || nm === 'SecurityError' ? 'Allow the microphone for this app (phone Settings → the browser → Microphone), then try again.' : nm === 'NotFoundError' ? 'No microphone found on this phone.' : nm === 'NotReadableError' ? 'Another app is using the microphone (a call?). Close it and try again.' : 'Could not start recording: ' + (e.message || nm), true); }
    }
    function stopRec(cancel) { if (!REC) return; clearInterval(REC.tm); REC.cancel = !!cancel; try { REC.mr.stop(); } catch (e) { } }
    function drawFoot2() { const f = $('#wxFoot'); if (f) { f.innerHTML = ''; if (S.cur) drawFoot(); } }
    async function finishRec(blob, req) {
      try {
        let file; const ty = (blob.type || '') + ' ' + (req || '');
        // Only two recordings go as they are: OGG with Opus (Firefox) and MP4 with AAC (iPhone). Chrome's WebM and Chrome's newer "MP4 with Opus" are refused
        // by WhatsApp at delivery (the voice note shows as failed), so they are turned into MP3 here.
        if (/ogg/.test(ty) && !/mp4|webm/.test(blob.type || '')) file = new File([blob], 'voice.ogg', { type: 'audio/ogg' });
        else if (/mp4a|aac/.test(ty)) file = new File([blob], 'voice.m4a', { type: 'audio/mp4' });
        else file = new File([await toMp3(blob)], 'voice.mp3', { type: 'audio/mpeg' });
        await sendFiles([file], '');
      } catch (e) { toast('Could not prepare the voice note: ' + e.message, true); }
    }
    function loadScript(src) { return new Promise((res, rej) => { if (window.lamejs) return res(); const s = document.createElement('script'); s.src = src; s.onload = res; s.onerror = () => rej(new Error('Could not load the voice converter (check the internet).')); document.head.appendChild(s); }); }
    async function toMp3(blob) {
      await loadScript('https://cdnjs.cloudflare.com/ajax/libs/lamejs/1.2.1/lame.min.js'); const a = new (window.AudioContext || window.webkitAudioContext)(); const buf = await a.decodeAudioData(await blob.arrayBuffer()); const ch = buf.getChannelData(0), n = ch.length, pcm = new Int16Array(n);
      for (let i = 0; i < n; i++) { const s = Math.max(-1, Math.min(1, ch[i])); pcm[i] = s < 0 ? s * 0x8000 : s * 0x7FFF; } const enc = new lamejs.Mp3Encoder(1, buf.sampleRate, 64), out = []; for (let i = 0; i < n; i += 1152) { const b = enc.encodeBuffer(pcm.subarray(i, i + 1152)); if (b.length) out.push(new Int8Array(b)); } const e = enc.flush(); if (e.length) out.push(new Int8Array(e)); a.close(); return new Blob(out, { type: 'audio/mpeg' });
    }

    // ---- attach sheet
    function pick(accept, multiple, cb) { const i = document.createElement('input'); i.type = 'file'; i.accept = accept; i.multiple = !!multiple; i.style.display = 'none'; i.onchange = () => { const l = [...i.files]; i.remove(); if (l.length) cb(l); }; document.body.appendChild(i); i.click(); }
    function openAttach() {
      const sh = sheet('Send'); sh.body.innerHTML = `<div class="wx-g4"><button data-a="photo"><i style="background:#dcfce7">📷</i>Photo</button><button data-a="video"><i style="background:#dbeafe">🎥</i>Video</button><button data-a="voice"><i style="background:#fee2e2">🎤</i>Voice</button><button data-a="gallery"><i style="background:#ede9fe">🖼</i>Gallery</button>
        <button data-a="pdf"><i style="background:#ffedd5">📄</i>PDF</button><button data-a="tpl"><i style="background:#e0f2fe">📋</i>Pre-made</button><button data-a="lists"><i style="background:#ede9fe">🗒️</i>Lists</button><button data-a="cust"><i style="background:#f1f5f9">👤</i>Customer</button></div>`;
      sh.body.onclick = (e) => { const b = e.target.closest('[data-a]'); if (!b) return; const a = b.dataset.a; sh.close();
        if (a === 'photo') pick('image/*', true, (l) => sendFiles(l, '')); else if (a === 'video') pick('video/*', false, (l) => sendFiles(l, '')); else if (a === 'voice') startRec(); else if (a === 'pdf') pick('application/pdf', false, (l) => sendFiles(l, ''));
        else if (a === 'gallery') openGallery(); else if (a === 'quick') openQuick(); else if (a === 'tpl') openTemplates(); else if (a === 'lists') openLists(); else openCustomer(); };
    }
    // 🗒️ Lists (wa_lists_ui.js, loaded the first time): ready perfume lists — the ones matching what the customer asked come first
    function openLists() {
      if (!S.cur) return; const phone = S.cur.phone;
      const go = () => { const sh = sheet('Lists'); WaLists.sheet(sh.body, { phone, close: sh.close, onSent: () => { if (S.cur && S.cur.phone === phone) pollChat(); } }); };
      if (window.WaLists) return go(); const sc = document.createElement('script'); sc.src = 'assets/js/wa_lists_ui.js?v=l10'; sc.onload = go; sc.onerror = () => toast('Could not open the lists. Check the internet.', true); document.head.appendChild(sc);
    }
    const qkCode = (t) => '/' + (String(t || '').toLowerCase().replace(/[^a-z0-9]+/g, '') || 'reply');   // the title is the shortcut: "Pre Diwali" → /prediwali
    const qkFind = (list, q) => { q = String(q || '').toLowerCase().replace(/[^a-z0-9]+/g, ''); if (!q) return list.slice(0, 12); const sc = (x) => { const c = qkCode(x.title).slice(1); return c.startsWith(q) ? 0 : c.includes(q) ? 1 : String(x.body || '').toLowerCase().replace(/[^a-z0-9]+/g, '').includes(q) ? 2 : 9; }; return list.map((x) => [sc(x), x]).filter((a) => a[0] < 9).sort((a, b) => a[0] - b[0]).map((a) => a[1]).slice(0, 12); };
    // ⚡ type "/" in the message box (like the WhatsApp Business app): matching quick replies pop up — tap one to put it in the box, or ➤ to send it at once
    async function slashW(t) {
      let box = $('#wxSl'); const v = t.value;
      if (v.charAt(0) !== '/' || v.indexOf('\n') >= 0 || v.length > 40) { if (box) box.remove(); return; }
      if (!S.quick) { try { S.quick = (await api.get('wa.quick_list')).items || []; } catch (e) { S.quick = []; } }
      const hit = qkFind(S.quick, v.slice(1)), fn = first(S.cur && S.cur.contact.name);
      if (!box) { box = document.createElement('div'); box.id = 'wxSl'; box.className = 'wx-sl'; const f = $('#wxFoot') || t.parentNode; f.insertBefore(box, f.firstChild); }
      box.innerHTML = hit.length ? hit.map((x, i) => `<div class="wx-sli" data-i="${i}"><div class="wx-slt"><b>${esc(qkCode(x.title))}</b> <span>${esc(x.title)}</span><small>${esc(x.body)}</small></div><button type="button" data-go="${i}" title="Send now">➤</button></div>`).join('') : `<div class="wx-sle">No quick reply starts with “${esc(v.slice(1))}”.</div>`;
      box.onclick = (e) => { const g = e.target.closest('[data-go]'), r0 = e.target.closest('[data-i]'); const it = g ? hit[+g.dataset.go] : r0 ? hit[+r0.dataset.i] : null; if (!it) return;
        t.value = String(it.body).replace(/\{name\}/gi, fn); box.remove(); autoGrow(t); swapSend(); t.focus(); if (g) { const go = $('#wxGo'); if (go) go.click(); } };
    }
    async function openQuick() {
      const sh = sheet('⚡ Quick replies'); sh.body.innerHTML = '<div class="wx-sk"></div>';
      try { if (!S.quick) S.quick = (await api.get('wa.quick_list')).items || []; } catch (e) { S.quick = []; }
      const fn = first(S.cur && S.cur.contact.name), all = S.quick.map((x, i) => Object.assign({ i }, x));
      sh.body.innerHTML = `<div class="wx-qs"><input class="wx-in" id="wxQs" type="search" placeholder="🔍 Search quick replies — e.g. price, cod, delivery" autocomplete="off" style="margin:0"></div><div id="wxQl"></div>`;
      const inp = sh.body.querySelector('#wxQs'), box = sh.body.querySelector('#wxQl');
      const norm = (v) => String(v || '').toLowerCase();
      const draw = () => {
        const q = norm(inp.value).trim(), words = q.split(/\s+/).filter(Boolean);
        const hit = !words.length ? all : all.map((x) => { const t = norm(x.title), b = norm(x.body), c = norm(qkCode(x.title)); let sc = 0;
          for (const w of words) { if (t.startsWith(w) || c.startsWith(w.replace(/^\//, ''))) sc += 3; else if (t.includes(w)) sc += 2; else if (b.includes(w)) sc += 1; else return null; } return [sc, x]; }).filter(Boolean).sort((p, q2) => q2[0] - p[0]).map((p) => p[1]);
        box.innerHTML = !all.length ? '<div class="wx-empty">No quick replies yet — Admin adds them in WhatsApp → Replies.</div>' : (hit.length ? hit.map((x) => `<div class="wx-row" data-i="${x.i}"><div class="wx-mid"><div class="wx-nm"><b style="color:#128c7e">${esc(qkCode(x.title))}</b> · ${esc(x.title)}</div><div class="wx-sub" style="white-space:normal">${esc(x.body)}</div></div></div>`).join('') : `<div class="wx-empty" style="padding:18px 8px">Nothing matches “${esc(inp.value)}”</div>`);
        S.qkHit = hit;
      };
      const pick = (i) => { const t = $('#wxTx'); if (t) { t.value = S.quick[i].body.replace(/\{name\}/gi, fn); autoGrow(t); swapSend(); t.focus(); } sh.close(); };
      inp.oninput = draw; inp.onkeydown = (e) => { if (e.key === 'Enter' && S.qkHit && S.qkHit.length) { e.preventDefault(); pick(S.qkHit[0].i); } };
      box.onclick = (e) => { const r = e.target.closest('[data-i]'); if (r) pick(+r.dataset.i); };
      draw(); setTimeout(() => inp.focus(), 60);
    }
    async function openTemplates() {
      const sh = sheet('Pre-made messages'); sh.body.innerHTML = '<div class="wx-sk"></div>'; const c = S.cur.contact;
      try { if (!S.tpls) S.tpls = (await api.get('wa.tpl_list')).templates || []; } catch (e) { sh.body.innerHTML = `<div class="wx-empty">${esc(e.message)}</div>`; return; }
      const ok = S.tpls.filter((t) => (t.status === 'approved' || t.status === 'partial') && !t.media_header && !t.auto).sort((a, b) => (a.category === 'UTILITY' ? 0 : 1) - (b.category === 'UTILITY' ? 0 : 1) || (b.star ? 1 : 0) - (a.star ? 1 : 0));
      const fill = (t, v) => String(t || '').replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => v[+n - 1] || m);
      const list = () => { sh.title('Pre-made messages'); sh.body.innerHTML = (ok.length ? ok.map((t, i) => `<div class="wx-row" data-i="${i}"><div class="wx-mid"><div class="wx-nm">${esc(t.name.replace(/_/g, ' '))} <span class="wx-chip ${t.category === 'UTILITY' ? 'g' : 'o'}">${t.category === 'UTILITY' ? 'UTILITY' : 'MARKETING'}</span></div><div class="wx-sub" style="white-space:normal">${esc(t.body)}</div></div></div>`).join('') : '<div class="wx-empty">No approved pre-made message yet. The Admin makes them in WhatsApp → Pre-made messages.</div>'); };
      list(); sh.body.onclick = (e) => { const r = e.target.closest('[data-i]'); if (!r) return; one(ok[+r.dataset.i]); };
      function one(t) {
        const nv = t.vars || 0, fn = first(c.name) || 'ji'; sh.title(t.name.replace(/_/g, ' ')); let h = '<div class="wx-bubp" id="wxTpP"></div>';
        if (t.header_var) h += `<label class="wx-lb">Heading value</label><input class="wx-in" id="wxTpH" value="${esc(fn)}">`; const H = []; for (let i = 1; i <= nv; i++) { const hn = H[i - 1] = tplVarHint(t, i), pre = hn.k === 'name' ? fn : hn.k === 'date' ? hn.eg : ''; h += `<label class="wx-lb">{{${i}}}${hn.label ? ' · ' + esc(hn.label) : ''}</label><input class="wx-in wxv" data-i="${i}" value="${esc(pre)}" placeholder="${hn.eg ? 'e.g. ' + esc(hn.eg) : 'Write it here'}">`; }
        h += '<div id="wxTpE"></div><div style="display:flex;gap:8px;margin-top:10px"><button class="wx-btn gh" id="wxTpB">‹ Back</button><button class="wx-btn" id="wxTpS" style="flex:1">Send</button></div>'; sh.body.innerHTML = h; sh.body.onclick = null;
        const vals = () => [...sh.body.querySelectorAll('.wxv')].map((x) => x.value.trim()); const prev = () => { $('#wxTpP').textContent = (t.header_text ? fill(t.header_text, [$('#wxTpH') ? $('#wxTpH').value : '']) + '\n' : '') + fill(t.body, vals()) + (t.footer ? '\n' + t.footer : ''); }; sh.body.querySelectorAll('input').forEach((x) => { x.oninput = prev; }); prev();
        $('#wxTpB').onclick = () => { list(); sh.body.onclick = (e) => { const r = e.target.closest('[data-i]'); if (r) one(ok[+r.dataset.i]); }; };
        $('#wxTpS').onclick = async function () { const v = vals(); const bad = v.map((x, i) => tplVarBad(H[i] || {}, x)).find(Boolean); if (bad) { $('#wxTpE').innerHTML = '<div class="wx-banner y">' + esc(bad) + '</div>'; return; } this.disabled = true; this.textContent = 'Sending…';
          try { await api.post('wa.tpl_send', { phone: c.phone, id: t.id, vars: v, header_var: $('#wxTpH') ? $('#wxTpH').value.trim() : '' }); sh.close(); toast('Sent ✓'); pollChat(); } catch (er) { $('#wxTpE').innerHTML = `<div class="wx-banner y">${esc(er.message)}</div>`; this.disabled = false; this.textContent = 'Send'; } };
      }
    }

    // ---- gallery (topic folders + "Our storage")
    const THUMB = {}; let thumbQ = 0;
    async function thumb(id) { if (THUMB[id]) return THUMB[id]; while (thumbQ >= 4) await new Promise((r) => setTimeout(r, 80)); thumbQ++; try { const res = await fetch(api.buildUrl('wa.g_thumb', { id }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (!res.ok) return ''; return (THUMB[id] = URL.createObjectURL(await res.blob())); } catch (e) { return ''; } finally { thumbQ--; } }
    const KEYMAP = [[/price|rate|kitn|cost|list|prize/i, 'prices'], [/size|ml|kitna bada|quantity/i, 'sizes'], [/gel/i, 'gel'], [/attar|itra/i, 'attars'], [/offer|discount|sale|deal/i, 'offers'], [/review|proof|real|fake|original/i, 'reviews'], [/box|pack|gift/i, 'packaging'], [/video/i, 'videos'], [/bottle|perfume|photo|pic|dikhao|show/i, 'bottles']];
    async function openGallery() {
      const sh = sheet('Gallery'); sh.body.innerHTML = '<div class="wx-sk"></div>'; try { if (!S.gallery) S.gallery = (await api.get('wa.g_gallery')).gallery; } catch (e) { sh.body.innerHTML = `<div class="wx-empty">${esc(e.message)}</div>`; return; }
      const G = S.gallery, sel = new Set(); let mode = 'folders', folder = ''; const lastIn = (S.cur.msgs.filter((m) => m.dir === 'in').slice(-1)[0] || {}).body || ''; const sugg = (KEYMAP.find(([re]) => re.test(lastIn)) || [])[1] || ''; folder = sugg && G.folders.some((f) => f.k === sugg) ? sugg : (G.folders[0] || {}).k || '';
      function items() { return mode === 'storage' ? G.items : G.items.filter((i) => i.folder === folder); }
      function draw() {
        const q = (sh.body.querySelector('#wxGq') || {}).value || ''; const its = items().filter((i) => !q || i.title.toLowerCase().includes(q.toLowerCase()));
        sh.body.innerHTML = `<div class="wx-fo"><button class="${mode === 'folders' ? 'on' : ''}" data-m="folders">Gallery</button><button class="${mode === 'storage' ? 'on' : ''}" data-m="storage">Our storage · ${G.items.length}</button></div>` +
          (mode === 'folders' ? `<div class="wx-fo">${G.folders.map((f) => `<button class="${folder === f.k ? 'on' : (sugg === f.k ? 'cl' : '')}" data-f="${f.k}">${sugg === f.k ? '✨ ' : ''}${esc(f.t)} ${f.n}</button>`).join('')}</div>${sugg && folder !== sugg && G.folders.some((f) => f.k === sugg) ? '' : ''}` : `<input class="wx-in" id="wxGq" placeholder="Search saved photos" value="${esc(q)}" style="margin-top:0">`) +
          `<div class="wx-gr">${its.map((i) => `<div class="wx-th ${sel.has(i.id) ? 'sel' : ''}" data-id="${i.id}">${i.kind === 'image' ? '' : (i.kind === 'video' ? '🎬' : '📄')}<small>${esc(i.title)}</small><span class="ck">✓</span></div>`).join('') || '<div class="wx-empty" style="grid-column:1/-1">Nothing here.</div>'}</div>` +
          (sel.size ? `<div style="position:sticky;bottom:0;background:#fff;padding:10px 0 2px"><input class="wx-in" id="wxGc" placeholder="Caption (optional)" style="margin-top:0"><button class="wx-btn" id="wxGs" style="width:100%;margin-top:6px">Send ${sel.size}</button></div>` : '');
        sh.body.querySelectorAll('.wx-th[data-id]').forEach((el) => { const it = G.items.find((x) => x.id === +el.dataset.id); if (it && it.kind === 'image') thumb(it.id).then((u) => { if (u) el.style.backgroundImage = `url(${u})`; }); });
        const gq = sh.body.querySelector('#wxGq'); if (gq) { gq.oninput = () => { const p = gq.selectionStart; draw(); const n = sh.body.querySelector('#wxGq'); n.focus(); n.setSelectionRange(p, p); }; }
        const gs = sh.body.querySelector('#wxGs'); if (gs) gs.onclick = async function () { this.disabled = true; this.textContent = 'Sending…'; try { const r = await api.post('wa.g_gallery_send', { phone: S.cur.phone, ids: [...sel], caption: (sh.body.querySelector('#wxGc') || {}).value || '' }); sh.close(); toast('Sent ' + r.sent + ' ✓'); pollChat(); } catch (e) { toast(e.message, true); this.disabled = false; this.textContent = 'Send ' + sel.size; } };
      }
      sh.body.onclick = (e) => { const m = e.target.closest('[data-m]'), f = e.target.closest('[data-f]'), t = e.target.closest('.wx-th[data-id]'); if (m) { mode = m.dataset.m; draw(); } else if (f) { folder = f.dataset.f; draw(); } else if (t) { const id = +t.dataset.id; if (sel.has(id)) sel.delete(id); else if (sel.size < 6) sel.add(id); else toast('Up to 6 at a time'); draw(); } };
      draw();
    }

    // ---- customer card
    async function openCustomer() {
      const sh = sheet(S.cur.contact.name || pretty(S.cur.contact.phone)); sh.body.innerHTML = '<div class="wx-sk"></div>'; let d;
      try { d = S.cur.info && Date.now() - (S.cur.infoAt || 0) < 3e4 ? S.cur.info : await api.get('wa.g_customer', { phone: S.cur.phone }); S.cur.info = d; S.cur.infoAt = Date.now(); } catch (e) { sh.body.innerHTML = `<div class="wx-empty">${esc(e.message)}</div>`; return; }
      const n = d.numbers || {};
      const mountQ = () => { const b = sh.body.querySelector('#wxQt'); if (b) qReady(() => { if (window.WaQ && b.isConnected) WaQ.tags(b, { noTransfer: true, phone: d.phone || (S.cur && S.cur.phone), name: d.name || '', api, onTransferred: () => { sh.close(); closeChat(); toast('Transferred ✓'); } }); }); };
      const mountCart = () => { const mo = sh.body.querySelector('#wxMoGo'); if (mo) mo.onclick = () => { sh.close(); openManual(); }; const b = sh.body.querySelector('#wxCtGo'); if (!b) return; b.onclick = () => { sh.close(); openCart(); }; chReady(() => { const st = sh.body.querySelector('#wxCtS'); if (window.WaCart && st && st.isConnected) WaCart.status(st, { phone: d.phone || (S.cur && S.cur.phone), name: d.name || '', api, onChange: () => { try { pollChat(); } catch (e) { } } }); }); };
      const draw = () => { sh.body.innerHTML = `<div id="wxCt2" style="margin:0 0 12px"><button class="wx-btn" id="wxCtGo" style="width:100%;min-height:44px">🛒 Make cart</button><button class="wx-btn" id="wxMoGo" style="width:100%;min-height:44px;margin-top:6px">🧾 Manual order (QR / UPI / COD)</button><div id="wxCtS" style="margin-top:8px"></div></div><div id="wxQt" style="margin:0 0 12px"></div><div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px">${d.badge && d.badge.t ? `<span class="wx-chip ${d.badge.k === 'vip' ? 'p' : d.badge.k === 'hot' ? 'r' : 'g'}">${esc(d.badge.t)}</span>` : ''}${(d.tags || []).map((t) => `<span class="wx-chip m">${esc(t.t)}</span>`).join('')}</div>
        <div class="wx-kv"><span>Phone</span><b><a href="tel:+91${d.phone}" style="color:inherit">${pretty(d.phone)}</a></b></div><div class="wx-kv"><span>Orders</span><b>${d.orders_n} · ${money(d.ltv)}</b></div>${d.ad ? `<div class="wx-kv"><span>Came from ad</span><b style="max-width:60%">${esc(d.ad)}</b></div>` : ''}${d.summary ? `<div class="wx-kv"><span>Claude summary</span><b style="max-width:65%;font-weight:600">${esc(d.summary)}</b></div>` : ''}
        ${n.why ? `<div class="wx-banner b" style="margin:8px 0">📱 ${esc(n.why)}</div>` : ''}
        ${(d.orders || []).length ? `<div class="wx-h" style="padding-left:0">Orders</div>${d.orders.map((o) => `<div class="wx-kv"><span>${esc(o.name || '#')} · ${esc(String(o.at || '').slice(0, 10))}</span><b>${money(o.total)} <span class="wx-chip ${o.paid ? 'g' : 'o'}">${esc(o.status || '')}</span></b></div>`).join('')}` : ''}
        ${(d.results || []).length ? `<div class="wx-h" style="padding-left:0">Earlier results</div>${d.results.map((r) => `<div class="wx-kv"><span>${esc(String(r.at).slice(0, 10))} · ${esc(r.by)}</span><b>${r.i} ${esc(r.t)}${r.note ? ' — ' + esc(r.note) : ''}</b></div>`).join('')}` : ''}
        <div class="wx-h" style="padding-left:0">Private notes</div>${(d.notes || []).map((x) => `<div class="wx-kv"><span>${esc(x.by)}${x.to ? ' → ' + esc(x.to) : ''}</span><b style="font-weight:600;max-width:70%">${esc(x.text)}</b></div>`).join('') || '<div class="wx-sub">No notes yet.</div>'}
        <textarea class="wx-in" id="wxNt" placeholder="Write a note for yourself or tag a colleague" style="min-height:54px"></textarea><div style="display:flex;gap:8px"><select class="wx-in" id="wxNm" style="margin:0"><option value="0">Only me</option>${(d.colleagues || []).map((p) => `<option value="${p.emp_id}">@ ${esc(p.name)}</option>`).join('')}</select><button class="wx-btn" id="wxNa">Save</button></div>`;
        mountQ(); mountCart(); $('#wxNa').onclick = async function () { const t = $('#wxNt').value.trim(); if (!t) return; this.disabled = true; try { await api.post('wa.g_note_add', { phone: d.phone, text: t, mention: +$('#wxNm').value }); d = await api.get('wa.g_customer', { phone: d.phone }); S.cur.info = d; draw(); toast('Saved ✓'); } catch (e) { toast(e.message, true); this.disabled = false; } };
      };
      draw();
    }

    // ---- finish: every chat ends with a result
    function openFinish() {
      const sh = sheet('How did this chat end?'); let code = '', remind = 0;
      const draw = () => { sh.body.innerHTML = `<div class="wx-res">${S.results.map((r) => `<button data-k="${r.k}" class="${code === r.k ? 'on' : ''}"><i>${r.i}</i>${esc(r.t)}</button>`).join('')}</div>
        ${code === 'order' ? '<label class="wx-lb">Order amount (₹) — optional</label><input class="wx-in" id="wxAm" inputmode="decimal" placeholder="e.g. 1499">' : ''}
        ${['later', 'price', 'info', 'no_reply'].includes(code) ? `<label class="wx-lb">Remind me</label><div class="wx-fo">${[[0, 'No'], [60, 'In 1 hour'], [180, 'In 3 hours'], [1440, 'Tomorrow'], [4320, 'In 3 days']].map(([m, l]) => `<button data-r="${m}" class="${remind === m ? 'on' : ''}">${l}</button>`).join('')}</div>` : ''}
        <label class="wx-lb">Note (optional)</label><textarea class="wx-in" id="wxFn" placeholder="e.g. wants a discount on 50ml"></textarea><button class="wx-btn" id="wxFd" style="width:100%;margin-top:8px" ${code ? '' : 'disabled'}>Save and close chat</button>`;
        $('#wxFd').onclick = save; };
      async function save() {
        const b = $('#wxFd'); b.disabled = true; b.textContent = 'Saving…'; const phone = S.cur.phone;
        try { const r = await api.post('wa.g_outcome', { phone, code, note: $('#wxFn').value.trim(), amount: +(($('#wxAm') || {}).value || 0), remind_mins: remind }); await api.post('wa.close', { phone }); sh.close(); S.stats = r.stats || S.stats; closeChat(); if (code === 'order') celebrate(); else toast('Chat closed ✓'); }
        catch (e) { toast(e.message, true); b.disabled = false; b.textContent = 'Save and close chat'; }
      }
      sh.body.onclick = (e) => { const k = e.target.closest('[data-k]'), r = e.target.closest('[data-r]'); if (k) { code = k.dataset.k; remind = 0; const n = ($('#wxFn') || {}).value, a = ($('#wxAm') || {}).value; draw(); if (n) $('#wxFn').value = n; if (a && $('#wxAm')) $('#wxAm').value = a; } else if (r) { remind = +r.dataset.r; const n = $('#wxFn').value; draw(); $('#wxFn').value = n; } };
      draw();
    }
    function celebrate() {
      chime(); const st = S.stats || {}, tg = S.cfg.target || 5, n = st.orders_today || 1, pc = Math.min(100, Math.round(100 * n / tg)); const w = document.createElement('div'); w.className = 'wx-win';
      w.innerHTML = `<div class="c"><div class="big">🎉</div><h2>Order #${n} today!</h2><div style="color:var(--mut);font-weight:600">${n >= tg ? 'Target reached — superb!' : (tg - n) + ' more to hit your target'}</div><div class="wx-bar2"><i style="width:0"></i></div><div style="display:flex;gap:8px;justify-content:center;margin:10px 0"><span class="wx-pill">🔥 Streak ${st.streak || 1}</span><span class="wx-pill">🏅 Rank ${st.rank || '—'}</span></div><button class="wx-btn" style="width:100%">Keep going</button></div>`;
      R.appendChild(w); for (let i = 0; i < 28; i++) { const c = document.createElement('span'); c.className = 'wx-cf'; c.textContent = ['🎊', '✨', '🟢', '⭐', '💚'][i % 5]; c.style.left = Math.random() * 100 + '%'; c.style.animationDuration = (1.6 + Math.random() * 1.6) + 's'; c.style.animationDelay = Math.random() * .5 + 's'; w.appendChild(c); }
      setTimeout(() => { const b = w.querySelector('.wx-bar2 i'); if (b) b.style.width = pc + '%'; }, 120); const done = () => { w.remove(); loadList(true); }; w.querySelector('button').onclick = done; setTimeout(done, 6000);
    }

    // ------------------------------------------------------------------ go
    drawTabs(); drawList(); loadList(false); loop(); firstGuide();
    { const wt = new URLSearchParams(location.search).get('tab'); if (wt === 'queries' || wt === 'history' || wt === 'me') setTab(wt); }
    const want = new URLSearchParams(location.search).get('phone'); if (want) setTimeout(() => openChat(String(want).replace(/\D/g, '').slice(-10)), 400);
    window.addEventListener('popstate', () => {
      if (S.popSkip) { S.popSkip--; return; }   // our own history.back() after ✕ closed a sheet
      const tops = R.querySelectorAll('.wx-md'); if (tops.length) { tops[tops.length - 1]._shut ? tops[tops.length - 1]._shut() : tops[tops.length - 1].remove(); return; }
      if (S.cur && !(history.state && history.state.wxChat)) closeChat();
    });
  }
  window.WaApp = { start };
})();
