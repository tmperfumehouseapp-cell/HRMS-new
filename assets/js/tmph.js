/**
 * TMPH — the personal AI assistant panel.
 *
 * What it adds to every page (loaded by layout.js):
 *   - a floating "TMPH" button (bottom-right) that opens a chat panel on the right side
 *   - streaming replies, Markdown + code blocks, Copy buttons, Stop, Clear chat, Esc to close
 *   - chat history kept in localStorage (per login), so it survives a refresh
 *   - a "+" menu to attach photos / camera / documents (also paste and drag-drop), a mic for voice typing,
 *     pictures and videos that TMPH creates (shown in the chat with Download)
 *
 * Safety: this file never sees the AI key. It only talks to api.php (?action=ai.status / ai.chat);
 * the server checks who you are, keeps the key, and applies the limits.
 * The button only appears for people the server says may use TMPH (config.php: TMPH_ALLOWED_ROLES / TMPH_ALLOWED_USERS).
 *
 * Every CSS rule is prefixed ".tmph-" so nothing here can change the look of an existing page.
 * Needs: assets/js/config.js (API_BASE_URL) and assets/js/api.js (api) — every page already loads both.
 */
(function () {
  'use strict';
  if (window.__tmphLoaded) return;
  window.__tmphLoaded = true;
  if (typeof api === 'undefined' || typeof API_BASE_URL === 'undefined') return;   // a page without the API helper
  var token = null;
  try { token = api.token(); } catch (e) {}
  if (!token) return;                                                               // not logged in

  var me = {};
  try { me = api.currentUser() || {}; } catch (e) {}
  var UID = String(me.user_id || me.username || 'u');
  /* what the person is looking at: the page, and what a page inside the hub says is selected on it (e.g. Finance Summary posts its date range) */
  var PAGE_CTX = null;
  window.addEventListener('message', function (e) { if (e.origin === location.origin && e.data && e.data.tmphCtx && typeof e.data.tmphCtx === 'object') PAGE_CTX = e.data.tmphCtx; });
  function pageInfo() { return { title: document.title || '', url: location.pathname.split('/').pop() + location.search, ctx: PAGE_CTX }; }
  var CHAT_KEY = 'tmph_chat_' + UID;          // one saved chat per login
  var OK_KEY = 'tmph_ok_' + UID;              // remembered answer of ai.status (10 minutes)
  var OK_TTL = 10 * 60 * 1000;

  /* ------------------------------------------------------------------ is TMPH turned on for this person? */
  function getCached() {
    try {
      var c = JSON.parse(sessionStorage.getItem(OK_KEY) || 'null');
      if (c && c.caps && Date.now() - c.ts < OK_TTL) return c;
    } catch (e) {}
    return null;
  }
  function boot() {
    var c = getCached();
    if (c) { if (c.enabled) build(c); return; }
    api.get('ai.status').then(function (d) {
      var c2 = { enabled: !!(d && d.enabled), stream: !d || d.stream !== false, max: (d && d.max_chars) || 2000, caps: (d && d.caps) || { files: false, gen: false }, ts: Date.now() };
      try { sessionStorage.setItem(OK_KEY, JSON.stringify(c2)); } catch (e) {}
      if (c2.enabled) build(c2);
    }).catch(function () { /* old api.php without TMPH, or offline: stay invisible */ });
  }

  /* ------------------------------------------------------------------ styles (scoped with .tmph-) */
  var CSS = [
    '.tmph-root{--t-bg:var(--surface,#fff);--t-ink:var(--ink,#14161a);--t-muted:var(--muted,#6f7480);--t-line:var(--line,#e6e8ec);',
    '--t-soft:#f4f5f7;--t-user:var(--ink,#14161a);--t-user-ink:#fff;--t-accent:var(--accent,#ff7a2f);--t-accent-dark:var(--accent-dark,#e8651a);',
    '--t-code:#14161a;--t-code-ink:#e8eaee;--t-ok:var(--ok-fg,#16945a);--t-bad:var(--bad-fg,#d9423b);--t-bad-bg:var(--bad-bg,#fdecea);',
    '--t-radius:var(--radius,26px);--t-shadow:0 24px 60px rgba(20,22,26,.18),0 2px 6px rgba(20,22,26,.06);',
    'font-family:var(--font,"Inter","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Arial,sans-serif);font-size:14px;line-height:1.5;color:var(--t-ink)}',
    '.tmph-root.tmph-dark{--t-bg:#1b1d22;--t-ink:#eef0f3;--t-muted:#9aa0ac;--t-line:#2c3038;--t-soft:#262930;--t-user:#ff7a2f;--t-user-ink:#1b1d22;',
    '--t-code:#0f1013;--t-bad-bg:#3a2123;--t-shadow:0 24px 60px rgba(0,0,0,.55),0 0 0 1px #2c3038}',
    '.tmph-root *{box-sizing:border-box}',
    /* :where() keeps the button reset at zero specificity so the real colours below always win */
    ':where(.tmph-root) button{font:inherit;color:inherit;cursor:pointer}',
    /* the site makes every button at least 38px tall on phones — TMPH sets its own button sizes */
    '.tmph-root button{min-height:0;min-width:0;margin:0;text-transform:none;letter-spacing:normal}',
    '.tmph-fab{position:fixed;right:20px;bottom:20px;z-index:90;display:flex;align-items:center;gap:10px;height:54px;padding:0 20px 0 9px;border:none;border-radius:999px;',
    'background:#14161a;color:#fff;font-weight:800;font-size:15px;letter-spacing:.01em;box-shadow:0 10px 30px rgba(20,22,26,.28);transition:transform .18s,opacity .18s}',
    '.tmph-fab:hover{transform:translateY(-2px)}',
    '.tmph-fab-ic{width:36px;height:36px;border-radius:50%;background:var(--t-accent);display:flex;align-items:center;justify-content:center}',
    '.tmph-fab svg{width:19px;height:19px;fill:#fff}',
    '.tmph-root.tmph-open .tmph-fab{opacity:0;pointer-events:none}',
    '.tmph-panel{display:none;position:fixed;z-index:110;top:12px;right:12px;bottom:12px;width:min(430px,calc(100vw - 24px));flex-direction:column;',
    'background:var(--t-bg);border-radius:var(--t-radius);box-shadow:var(--t-shadow);overflow:hidden}',
    /* .tmph-on is put on the panel itself: layout.js watches class changes on .tmph-panel so the phone's Back button can close it */
    '.tmph-panel.tmph-on{display:flex;animation:tmphIn .26s cubic-bezier(.2,.8,.2,1)}',
    '@keyframes tmphIn{from{transform:translateX(40px);opacity:0}to{transform:none;opacity:1}}',
    '.tmph-head{display:flex;align-items:center;gap:12px;padding:16px 16px 14px 18px;border-bottom:1px solid var(--t-line);flex:none}',
    '.tmph-avatar{width:42px;height:42px;border-radius:14px;background:var(--t-accent);display:flex;align-items:center;justify-content:center;flex:none}',
    '.tmph-avatar svg{width:22px;height:22px;fill:#fff}',
    '.tmph-title{flex:1;min-width:0}',
    '.tmph-title b{display:block;font-size:17px;font-weight:800;letter-spacing:-.01em;line-height:1.2}',
    '.tmph-title span{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--t-muted)}',
    '.tmph-title span::before{content:"";width:7px;height:7px;border-radius:50%;background:#2bb673}',
    '.tmph-hbtn{display:flex;align-items:center;gap:6px;height:34px;padding:0 12px;border:none;border-radius:999px;background:var(--t-soft);font-size:12.5px;font-weight:700;color:var(--t-muted)}',
    '.tmph-hbtn:hover{color:var(--t-bad);background:var(--t-bad-bg)}',
    '.tmph-hbtn svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}',
    '.tmph-x{width:34px;height:34px;padding:0;justify-content:center;color:var(--t-ink)}',
    '.tmph-x:hover{background:var(--t-line);color:var(--t-ink)}',
    '.tmph-chat{flex:1;overflow-y:auto;padding:18px 16px 8px;display:flex;flex-direction:column;gap:14px;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:var(--t-line) transparent}',
    '.tmph-msg{display:flex;flex-direction:column;max-width:92%}',
    '.tmph-msg.tmph-u{align-self:flex-end;align-items:flex-end}',
    '.tmph-msg.tmph-a{align-self:flex-start;align-items:flex-start;max-width:100%;width:100%}',
    '.tmph-who{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--t-muted);margin:0 6px 4px}',
    '.tmph-a .tmph-who{color:var(--t-accent-dark)}',
    '.tmph-bubble{padding:10px 14px;border-radius:20px;word-wrap:break-word;overflow-wrap:anywhere;min-width:0;max-width:100%}',
    '.tmph-u .tmph-bubble{background:var(--t-user);color:var(--t-user-ink);border-bottom-right-radius:6px;white-space:pre-wrap}',
    '.tmph-a .tmph-bubble{background:var(--t-soft);border-top-left-radius:6px}',
    '.tmph-msg.tmph-err .tmph-bubble{background:var(--t-bad-bg);color:var(--t-bad);border:1px solid var(--t-bad)}',
    '.tmph-msg.tmph-err .tmph-who{color:var(--t-bad)}',
    '.tmph-bubble p{margin:0 0 8px}.tmph-bubble p:last-child{margin-bottom:0}',
    '.tmph-bubble ul,.tmph-bubble ol{margin:4px 0 8px;padding-left:20px}.tmph-bubble li{margin:2px 0}',
    '.tmph-bubble h4{margin:10px 0 4px;font-size:14px;font-weight:800}',
    '.tmph-bubble a{color:var(--t-accent-dark);font-weight:600}',
    '.tmph-bubble code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;background:rgba(127,127,127,.18);padding:1px 6px;border-radius:6px}',
    '.tmph-code{margin:6px 0 8px;border-radius:14px;overflow:hidden;background:var(--t-code)}',
    '.tmph-code-h{display:flex;justify-content:space-between;align-items:center;padding:6px 8px 6px 12px;font-size:11px;font-weight:700;color:#9aa0ac;text-transform:uppercase;letter-spacing:.05em;background:rgba(255,255,255,.06)}',
    '.tmph-code-h button{border:none;background:rgba(255,255,255,.1);color:#e8eaee;border-radius:8px;padding:3px 9px;font-size:11px;font-weight:700}',
    '.tmph-code-h button:hover{background:rgba(255,255,255,.2)}',
    '.tmph-code pre{margin:0;padding:10px 12px;overflow-x:auto;color:var(--t-code-ink)}',
    '.tmph-code pre code{background:none;padding:0;color:inherit;font-size:12.5px}',
    '.tmph-tblwrap{overflow-x:auto;margin:6px 0 8px;border-radius:12px;border:1px solid var(--t-line)}',
    '.tmph-bubble table{border-collapse:collapse;width:100%;font-size:13px;background:var(--t-bg)}',
    '.tmph-bubble th,.tmph-bubble td{padding:7px 10px;text-align:left;border-bottom:1px solid var(--t-line);white-space:nowrap}',
    '.tmph-bubble th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--t-muted)}',
    '.tmph-bubble tr:last-child td{border-bottom:none}',
    '.tmph-copy{margin:5px 0 0 6px;display:inline-flex;align-items:center;gap:5px;border:none;background:none;padding:3px 6px;border-radius:8px;font-size:12px;font-weight:700;color:var(--t-muted)}',
    '.tmph-copy:hover{background:var(--t-soft);color:var(--t-ink)}',
    '.tmph-copy svg{width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}',
    '.tmph-copy.tmph-done{color:var(--t-ok)}',
    '.tmph-typing{display:inline-flex;gap:5px;padding:4px 2px}',
    '.tmph-typing i{width:7px;height:7px;border-radius:50%;background:var(--t-muted);animation:tmphDot 1.1s infinite ease-in-out}',
    '.tmph-typing i:nth-child(2){animation-delay:.16s}.tmph-typing i:nth-child(3){animation-delay:.32s}',
    '@keyframes tmphDot{0%,80%,100%{opacity:.25;transform:translateY(0)}40%{opacity:1;transform:translateY(-4px)}}',
    '.tmph-caret::after{content:"";display:inline-block;width:7px;height:14px;margin-left:2px;vertical-align:-2px;background:var(--t-accent);border-radius:2px;animation:tmphBlink .9s steps(2) infinite}',
    '@keyframes tmphBlink{50%{opacity:0}}',
    '.tmph-empty{margin:auto 0;text-align:center;padding:10px 14px 18px}',
    '.tmph-empty .tmph-avatar{width:56px;height:56px;border-radius:18px;margin:0 auto 12px}',
    '.tmph-empty .tmph-avatar svg{width:28px;height:28px}',
    '.tmph-empty h3{margin:0 0 4px;font-size:19px;font-weight:800;letter-spacing:-.01em}',
    '.tmph-empty p{margin:0;color:var(--t-muted)}',
    '.tmph-foot{padding:10px 14px 12px;border-top:1px solid var(--t-line);flex:none}',
    '.tmph-box{display:flex;align-items:flex-end;gap:8px;background:var(--t-soft);border-radius:22px;padding:6px 6px 6px 16px;border:1.5px solid transparent;transition:border-color .15s}',
    '.tmph-box:focus-within{border-color:var(--t-accent)}',
    '.tmph-box textarea{flex:1;border:none;outline:none;resize:none;background:transparent;font:inherit;color:inherit;max-height:130px;min-height:24px;padding:8px 0;line-height:1.45;box-shadow:none;border-radius:0;width:auto}',
    '.tmph-box textarea::placeholder{color:var(--t-muted)}',
    '.tmph-send,.tmph-stop{width:40px;height:40px;border-radius:50%;border:none;display:flex;align-items:center;justify-content:center;flex:none;padding:0}',
    '.tmph-send{background:var(--t-accent);color:#fff}.tmph-send:hover{background:var(--t-accent-dark)}',
    '.tmph-send:disabled{opacity:.4;cursor:not-allowed}',
    '.tmph-stop{background:var(--t-ink);color:var(--t-bg);display:none}',
    '.tmph-stop i{width:13px;height:13px;border-radius:3px;background:currentColor;display:block}',
    '.tmph-send svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}',
    '.tmph-root.tmph-busy .tmph-send{display:none}.tmph-root.tmph-busy .tmph-stop{display:flex}',
    '.tmph-hint{margin:7px 6px 0;font-size:11px;color:var(--t-muted);text-align:center}',
    '.tmph-plus,.tmph-mic{width:38px;height:38px;border-radius:50%;border:none;background:transparent;color:var(--t-muted);display:flex;align-items:center;justify-content:center;flex:none;padding:0}',
    '.tmph-plus:hover,.tmph-mic:hover{background:var(--t-line);color:var(--t-ink)}',
    '.tmph-plus svg,.tmph-mic svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}',
    '.tmph-acts{margin:8px 0 0;display:flex;flex-direction:column;gap:6px}.tmph-act{border:1px solid var(--t-line);border-radius:12px;padding:8px 10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:var(--t-bg2,transparent)}.tmph-acttx{flex:1 1 180px;font-size:13px}.tmph-actyes,.tmph-actno{border:none;border-radius:999px;padding:7px 14px;font-weight:700;cursor:pointer;font-size:13px}.tmph-actyes{background:var(--t-ink);color:var(--t-bg,#fff)}.tmph-actno{background:var(--t-line);color:var(--t-ink)}.tmph-actmsg{font-size:12px;color:var(--t-muted)}.tmph-actdone{opacity:.8}.tmph-q{font-size:13px;font-weight:600}',
    '.tmph-mic.tmph-rec{background:var(--t-bad);color:#fff;animation:tmphRec 1.2s infinite}',
    '@keyframes tmphRec{0%,100%{box-shadow:0 0 0 0 rgba(217,66,59,.5)}50%{box-shadow:0 0 0 7px rgba(217,66,59,0)}}',
    '.tmph-foot{position:relative}',
    '.tmph-menu{display:none;position:absolute;left:14px;bottom:calc(100% - 6px);z-index:5;min-width:210px;padding:6px;background:var(--t-bg);border:1px solid var(--t-line);border-radius:18px;box-shadow:var(--t-shadow)}',
    '.tmph-menu.tmph-on{display:block;animation:tmphPop .14s ease-out}',
    '@keyframes tmphPop{from{transform:translateY(6px);opacity:0}to{transform:none;opacity:1}}',
    '.tmph-menu button{display:flex;align-items:center;gap:12px;width:100%;border:none;background:none;padding:10px 12px;border-radius:12px;font-size:14px;font-weight:600;color:var(--t-ink);text-align:left}',
    '.tmph-menu button:hover{background:var(--t-soft)}',
    '.tmph-menu svg{width:20px;height:20px;stroke:var(--t-accent-dark);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}',
    '.tmph-tray{display:none;gap:8px;padding:0 2px 8px;overflow-x:auto;scrollbar-width:thin}',
    '.tmph-tray.tmph-on{display:flex}',
    '.tmph-chip{position:relative;flex:none;display:flex;align-items:center;gap:8px;max-width:190px;height:58px;padding:0 12px 0 8px;border-radius:14px;background:var(--t-soft);border:1px solid var(--t-line);font-size:12px;font-weight:600}',
    '.tmph-chip img{width:42px;height:42px;border-radius:9px;object-fit:cover;flex:none}',
    '.tmph-chip .tmph-fi{width:42px;height:42px;border-radius:9px;background:var(--t-accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;letter-spacing:.03em;flex:none;text-transform:uppercase}',
    '.tmph-chip span.tmph-fn{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}',
    '.tmph-chip small{display:block;font-weight:600;color:var(--t-muted);font-size:11px}',
    '.tmph-chip.tmph-img{padding:0;width:58px;max-width:58px}.tmph-chip.tmph-img img{width:56px;height:56px;border-radius:13px}',
    '.tmph-chip .tmph-rm{position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;border:2px solid var(--t-bg);background:var(--t-ink);color:var(--t-bg);font-size:11px;line-height:1;padding:0;display:flex;align-items:center;justify-content:center;font-weight:800}',
    '.tmph-note{display:none;margin:0 4px 6px;padding:6px 10px;border-radius:10px;background:var(--t-bad-bg);color:var(--t-bad);font-size:12px;font-weight:600}',
    '.tmph-note.tmph-on{display:block}',
    '.tmph-drop{display:none;position:absolute;inset:0;z-index:20;align-items:center;justify-content:center;flex-direction:column;gap:8px;background:color-mix(in srgb,var(--t-bg) 88%,transparent);border:2.5px dashed var(--t-accent);border-radius:var(--t-radius);font-weight:800;font-size:16px;color:var(--t-accent-dark);pointer-events:none}',
    '.tmph-drop.tmph-on{display:flex}',
    '.tmph-atts{display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-end;margin-bottom:6px}',
    '.tmph-atts img{width:92px;height:92px;border-radius:14px;object-fit:cover;display:block;border:1px solid var(--t-line)}',
    '.tmph-atts .tmph-fchip{display:flex;align-items:center;gap:8px;max-width:210px;padding:7px 12px 7px 8px;border-radius:14px;background:var(--t-soft);color:var(--t-ink);font-size:12px;font-weight:700}',
    '.tmph-atts .tmph-fchip b{width:34px;height:34px;border-radius:9px;background:var(--t-accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;text-transform:uppercase;flex:none}',
    '.tmph-atts .tmph-fchip span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.tmph-medias{display:flex;flex-direction:column;gap:10px;margin-top:8px;width:100%}',
    '.tmph-media{border-radius:18px;overflow:hidden;background:var(--t-soft);border:1px solid var(--t-line);max-width:100%}',
    '.tmph-media img,.tmph-media video{display:block;width:100%;height:auto;max-height:420px;object-fit:contain;background:#0e0f12;cursor:pointer}',
    '.tmph-media video{cursor:default}',
    '.tmph-mbar{display:flex;align-items:center;gap:8px;padding:8px 10px}',
    '.tmph-mbar small{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--t-muted);font-size:11.5px}',
    '.tmph-mbtn{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 12px;border:none;border-radius:999px;background:var(--t-accent);color:#fff !important;font-size:12px;font-weight:800;text-decoration:none}',
    '.tmph-mbtn:hover{background:var(--t-accent-dark)}',
    '.tmph-mbtn.tmph-ghost{background:var(--t-line);color:var(--t-ink) !important}',
    '.tmph-mbtn svg{width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}',
    '.tmph-vwork{position:relative;padding:22px 16px 18px;text-align:center;background:linear-gradient(120deg,var(--t-soft),color-mix(in srgb,var(--t-accent) 18%,var(--t-soft)),var(--t-soft));background-size:220% 100%;animation:tmphShim 2.4s linear infinite}',
    '@keyframes tmphShim{from{background-position:120% 0}to{background-position:-100% 0}}',
    '.tmph-vwork b{display:block;font-size:14px;font-weight:800;margin:10px 0 2px}',
    '.tmph-vwork span.tmph-vs{display:block;font-size:12px;color:var(--t-muted)}',
    '.tmph-spin{width:34px;height:34px;margin:0 auto;border-radius:50%;border:3.5px solid color-mix(in srgb,var(--t-accent) 25%,transparent);border-top-color:var(--t-accent);animation:tmphSpin .9s linear infinite}',
    '@keyframes tmphSpin{to{transform:rotate(360deg)}}',
    '.tmph-pbar{height:5px;border-radius:5px;background:var(--t-line);margin:12px auto 0;max-width:220px;overflow:hidden}',
    '.tmph-pbar i{display:block;height:100%;width:4%;background:var(--t-accent);border-radius:5px;transition:width 1s linear}',
    '.tmph-mfail{padding:12px 14px;font-size:13px;color:var(--t-bad);background:var(--t-bad-bg);font-weight:600}',
    '.tmph-lang{border:none;background:none;padding:0 2px;font-size:11px;font-weight:800;color:var(--t-accent-dark);text-decoration:underline;text-underline-offset:2px}',
    '@media (max-width:640px){',
    '.tmph-fab{right:14px;bottom:calc(14px + env(safe-area-inset-bottom));height:50px}',
    '.tmph-panel{inset:0;width:100%;border-radius:0;height:100dvh}',
    '.tmph-foot{padding-bottom:calc(12px + env(safe-area-inset-bottom))}',
    '.tmph-hbtn span{display:none}.tmph-hbtn{padding:0;width:34px;justify-content:center}}',
    '@media print{.tmph-root{display:none !important}}',
    '@media (prefers-reduced-motion:reduce){.tmph-panel.tmph-on,.tmph-fab{animation:none;transition:none}.tmph-typing i,.tmph-caret::after,.tmph-vwork,.tmph-spin,.tmph-mic.tmph-rec{animation:none}}'
  ].join('\n');

  /* ------------------------------------------------------------------ tiny, safe Markdown renderer (escapes HTML first) */
  function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function inline(t) {
    t = esc(t);
    t = t.replace(/`([^`]+)`/g, '<code>$1</code>');
    t = t.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');
    t = t.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<i>$2</i>');
    t = t.replace(/(^|[\s(])_([^_\n]+)_(?=[\s).,!?]|$)/g, '$1<i>$2</i>');
    t = t.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');   // http(s) links only
    return t;
  }
  function md(src) {
    var blocks = [];
    var s = String(src).replace(/```(\w*)\n([\s\S]*?)```/g, function (m, l, c) { blocks.push([l, c]); return '\u0000' + (blocks.length - 1) + '\u0000'; });
    s = s.replace(/```(\w*)\n([\s\S]*)$/, function (m, l, c) { blocks.push([l, c]); return '\u0000' + (blocks.length - 1) + '\u0000'; });   // code block still streaming
    var L = s.split('\n'), out = [], i = 0, m;
    function special(x) { return /^\u0000\d+\u0000$/.test(x) || /^#{1,3}\s/.test(x) || /^\s*([-*]|\d+\.)\s+/.test(x) || /^\|.*\|\s*$/.test(x); }
    while (i < L.length) {
      var ln = L[i];
      if ((m = ln.match(/^\u0000(\d+)\u0000$/))) {
        var b = blocks[+m[1]];
        out.push('<div class="tmph-code"><div class="tmph-code-h"><span>' + esc(b[0] || 'code') + '</span><button type="button" data-codecopy>Copy</button></div><pre><code>' + esc(b[1].replace(/\n$/, '')) + '</code></pre></div>'); i++;
      } else if (/^#{1,3}\s/.test(ln)) { out.push('<h4>' + inline(ln.replace(/^#{1,3}\s+/, '')) + '</h4>'); i++; }
      else if (/^\|.*\|\s*$/.test(ln) && i + 1 < L.length && /^\|?\s*:?-{2,}/.test(L[i + 1])) {
        var rows = []; while (i < L.length && /^\|.*\|\s*$/.test(L[i])) { rows.push(L[i]); i++; }
        var cells = function (r) { return r.trim().replace(/^\||\|$/g, '').split('|').map(function (c) { return c.trim(); }); };
        var head = cells(rows[0]), body = rows.slice(2).map(cells);
        out.push('<div class="tmph-tblwrap"><table><thead><tr>' + head.map(function (h) { return '<th>' + inline(h) + '</th>'; }).join('') + '</tr></thead><tbody>' +
          body.map(function (r) { return '<tr>' + r.map(function (c) { return '<td>' + inline(c) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div>');
      } else if (/^\s*([-*]|\d+\.)\s+/.test(ln)) {
        var ord = /^\s*\d+\./.test(ln), items = [];
        while (i < L.length && /^\s*([-*]|\d+\.)\s+/.test(L[i])) { items.push(L[i].replace(/^\s*([-*]|\d+\.)\s+/, '')); i++; }
        out.push((ord ? '<ol>' : '<ul>') + items.map(function (x) { return '<li>' + inline(x) + '</li>'; }).join('') + (ord ? '</ol>' : '</ul>'));
      } else if (!ln.trim()) { i++; }
      else { var p = []; while (i < L.length && L[i].trim() && !special(L[i])) { p.push(inline(L[i])); i++; } if (!p.length) { p.push(inline(L[i])); i++; } out.push('<p>' + p.join('<br>') + '</p>'); }
    }
    return out.join('');
  }

  /* ------------------------------------------------------------------ the panel */
  var STAR = '<svg viewBox="0 0 24 24"><path d="M12 2l2.2 6.3L20.5 10.5l-6.3 2.2L12 19l-2.2-6.3L3.5 10.5l6.3-2.2L12 2zM19 15l.9 2.6 2.6.9-2.6.9L19 22l-.9-2.6-2.6-.9 2.6-.9L19 15z"/></svg>';
  var COPY_SVG = '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/></svg>';

  var IC = {
    plus: '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>',
    cam: '<svg viewBox="0 0 24 24"><path d="M4 8h3l1.6-2.4h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.6"/></svg>',
    img: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="1.8"/><path d="M21 16l-5-5-8 9"/></svg>',
    doc: '<svg viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg>',
    mic: '<svg viewBox="0 0 24 24"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21"/></svg>',
    dl: '<svg viewBox="0 0 24 24"><path d="M12 4v11M7 11l5 5 5-5M5 20h14"/></svg>',
    open: '<svg viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>'
  };
  function fileExt(n) { var m = /\.([A-Za-z0-9]{1,5})$/.exec(n || ''); return m ? m[1].toLowerCase() : 'file'; }
  function mediaUrl(id, dl) { return API_BASE_URL + '?action=ai.media_file&id=' + encodeURIComponent(id) + (dl ? '&download=1' : '') + '&token=' + encodeURIComponent(api.token()); }
  function readB64(blobOrFile) {
    return new Promise(function (res, rej) {
      var r = new FileReader(); r.onload = function () { res(String(r.result).replace(/^data:[^,]*,/, '')); }; r.onerror = function () { rej(new Error('read')); }; r.readAsDataURL(blobOrFile);
    });
  }
  /* shrinks a photo (max side px, JPEG) → {b64, mime} ; null when the browser cannot decode it (e.g. HEIC) */
  function shrinkImage(file, max, q) {
    return new Promise(function (res) {
      var url = URL.createObjectURL(file), im = new Image();
      im.onload = function () {
        try {
          var w = im.naturalWidth, h = im.naturalHeight, k = Math.min(1, max / Math.max(w, h));
          var cw = Math.max(1, Math.round(w * k)), ch = Math.max(1, Math.round(h * k));
          var c = document.createElement('canvas'); c.width = cw; c.height = ch;
          var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, cw, ch); g.drawImage(im, 0, 0, cw, ch);
          var d = c.toDataURL('image/jpeg', q); URL.revokeObjectURL(url);
          res({ b64: d.replace(/^data:[^,]*,/, ''), mime: 'image/jpeg', url: d });
        } catch (e) { URL.revokeObjectURL(url); res(null); }
      };
      im.onerror = function () { URL.revokeObjectURL(url); res(null); };
      im.src = url;
    });
  }

  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  function build(cfg) {
    var caps = cfg.caps || {};
    var MAXF = caps.max_files || 5, MAXMB = caps.max_file_mb || 10;
    var style = document.createElement('style'); style.id = 'tmph-style'; style.textContent = CSS; document.head.appendChild(style);
    var root = document.createElement('div'); root.className = 'tmph-root'; root.id = 'tmph';
    root.innerHTML =
      '<button class="tmph-fab" id="tmphFab" type="button" aria-label="Open TMPH assistant"><span class="tmph-fab-ic">' + STAR + '</span>TMPH</button>' +
      '<aside class="tmph-panel" id="tmphPanel" role="dialog" aria-label="TMPH assistant">' +
        '<header class="tmph-head"><div class="tmph-avatar">' + STAR + '</div>' +
          '<div class="tmph-title"><b>TMPH</b><span>Personal AI assistant</span></div>' +
          '<button class="tmph-hbtn" id="tmphClear" type="button" title="Clear chat"><svg viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 10v6M14 10v6"/></svg><span>Clear chat</span></button>' +
          '<button class="tmph-hbtn tmph-x" id="tmphClose" type="button" data-dp="x" aria-label="Close"><svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M6 6l12 12M18 6L6 18"/></svg></button>' +
        '</header>' +
        '<div class="tmph-chat" id="tmphChat" aria-live="polite"></div>' +
        '<footer class="tmph-foot">' +
          (caps.files ? '<div class="tmph-menu" id="tmphMenu" role="menu">' +
            '<button type="button" data-pick="cam" role="menuitem">' + IC.cam + 'Camera</button>' +
            '<button type="button" data-pick="img" role="menuitem">' + IC.img + 'Photos</button>' +
            '<button type="button" data-pick="doc" role="menuitem">' + IC.doc + 'Document · PDF, Word, Excel…</button></div>' +
            '<input type="file" id="tmphFCam" accept="image/*" capture="environment" hidden>' +
            '<input type="file" id="tmphFImg" accept="image/*" multiple hidden>' +
            '<input type="file" id="tmphFDoc" multiple hidden accept=".pdf,.docx,.xlsx,.csv,.tsv,.txt,.md,.json,.xml,.html,application/pdf,text/*' + (cfg.provider === 'claude' ? '' : ',audio/*,video/*') + '">' : '') +
          '<div class="tmph-note" id="tmphNote"></div><div class="tmph-tray" id="tmphTray"></div>' +
          '<div class="tmph-box">' +
            (caps.files ? '<button class="tmph-plus" id="tmphPlus" type="button" aria-label="Attach" aria-haspopup="true">' + IC.plus + '</button>' : '') +
            '<textarea id="tmphInput" rows="1" maxlength="' + cfg.max + '" placeholder="' + 'Ask TMPH anything…' + '"></textarea>' +
            (SR ? '<button class="tmph-mic" id="tmphMic" type="button" aria-label="Voice input">' + IC.mic + '</button>' : '') +
            '<button class="tmph-send" id="tmphSend" type="button" aria-label="Send"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>' +
            '<button class="tmph-stop" id="tmphStop" type="button" aria-label="Stop"><i></i></button>' +
          '</div><div class="tmph-hint">Enter = send · Shift+Enter = new line' + (SR ? ' · <button type="button" class="tmph-lang" id="tmphLang"></button>' : '') + '</div></footer>' +
        '<div class="tmph-drop" id="tmphDrop">' + IC.doc + 'Drop files to attach</div>' +
      '</aside>';
    document.body.appendChild(root);

    var chat = root.querySelector('#tmphChat'), input = root.querySelector('#tmphInput'), fab = root.querySelector('#tmphFab');
    var msgs = [], stickBottom = true, streaming = false, ctrl = null;

    /* ---------- saved chat ---------- */
    try { msgs = JSON.parse(localStorage.getItem(CHAT_KEY) || '[]') || []; } catch (e) { msgs = []; }
    if (!Array.isArray(msgs)) msgs = [];
    msgs = msgs.filter(function (m) { return m && (m.role === 'user' || m.role === 'ai') && typeof m.text === 'string'; });
    msgs.forEach(function (m) { if (m.media) m.media.forEach(function (x) { if (x.kind === 'video' && x.status === 'working' && !x.t) x.t = Date.now(); }); });
    function save() {
      try { localStorage.setItem(CHAT_KEY, JSON.stringify(msgs.filter(function (m) { return !m.err; }).slice(-40))); } catch (e) {}
    }

    /* ---------- drawing ---------- */
    function msgEl(m, idx) {
      var d = document.createElement('div');
      d.className = 'tmph-msg ' + (m.role === 'user' ? 'tmph-u' : 'tmph-a') + (m.err ? ' tmph-err' : ''); d.dataset.i = idx;
      if (m.role === 'user') {
        d.innerHTML = '<div class="tmph-who">You</div>';
        if (m.atts && m.atts.length) {
          var ad = document.createElement('div'); ad.className = 'tmph-atts';
          m.atts.forEach(function (a) {
            if (a.thumb) { var im = document.createElement('img'); im.src = a.thumb; im.alt = a.name || 'photo'; ad.appendChild(im); }
            else { var fc = document.createElement('div'); fc.className = 'tmph-fchip'; fc.innerHTML = '<b></b><span></span>'; fc.firstChild.textContent = fileExt(a.name); fc.lastChild.textContent = a.name || 'file'; ad.appendChild(fc); }
          });
          d.appendChild(ad);
        }
        if (m.text) { var ub = document.createElement('div'); ub.className = 'tmph-bubble'; ub.textContent = m.text; d.appendChild(ub); }
      } else if (m.err) {
        d.innerHTML = '<div class="tmph-who">TMPH</div><div class="tmph-bubble"></div>';
        d.querySelector('.tmph-bubble').textContent = m.text;
      } else {
        d.innerHTML = '<div class="tmph-who">TMPH</div><div class="tmph-bubble">' + md(m.text) + '</div><div class="tmph-medias"></div><button class="tmph-copy" type="button" data-copy>' + COPY_SVG + 'Copy</button>';
        renderMedias(d, m); renderActions(d, m);
      }
      return d;
    }

    /* ---------- changes Claude proposes (the owner presses Yes; the change runs through dp.hq_act, the Boss page's own door) ---------- */
    function renderActions(el, m) {
      var old = el.querySelector('.tmph-acts'); if (old) old.remove();
      if (!m.actions || !m.actions.length) return;
      var box = document.createElement('div'); box.className = 'tmph-acts';
      m.actions.forEach(function (pack) {
        (pack.questions || []).forEach(function (q) { var qd = document.createElement('div'); qd.className = 'tmph-q'; qd.textContent = '? ' + q; box.appendChild(qd); });
        (pack.changes || []).forEach(function (c) {
          var row = document.createElement('div'); row.className = 'tmph-act' + (c.done ? ' tmph-actdone' : '');
          var tx = document.createElement('div'); tx.className = 'tmph-acttx'; tx.textContent = c.label || c.type; row.appendChild(tx);
          if (c.done) { var ok = document.createElement('div'); ok.className = 'tmph-actmsg'; ok.textContent = '✓ ' + (c.msg || 'Done'); row.appendChild(ok); }
          else if (c.skipped) { var sk = document.createElement('div'); sk.className = 'tmph-actmsg'; sk.textContent = 'Skipped'; row.appendChild(sk); }
          else {
            var yes = document.createElement('button'); yes.type = 'button'; yes.className = 'tmph-actyes'; yes.textContent = 'Yes, do it';
            var no = document.createElement('button'); no.type = 'button'; no.className = 'tmph-actno'; no.textContent = 'No';
            yes.onclick = function () {
              yes.disabled = true; yes.textContent = 'Doing…';
              api.post('dp.hq_act', { type: c.type, args: c.args }).then(function (r) { c.done = true; c.msg = (r && r.message) || 'Done'; save(); renderActions(el, m); }).catch(function (e) { yes.disabled = false; yes.textContent = 'Yes, do it'; say((e && e.message) || 'Could not do that.'); });
            };
            no.onclick = function () { c.skipped = true; save(); renderActions(el, m); try { api.post('ll.mark', { outcome: 'rejected', label: c.label || c.type, type: c.type }).catch(function () {}); } catch (e) { /* the log is optional */ } };
            row.appendChild(yes); row.appendChild(no);
          }
          box.appendChild(row);
        });
      });
      var anchor = el.querySelector('.tmph-medias'); if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(box, anchor.nextSibling); else el.appendChild(box);
    }

    /* ---------- pictures / videos TMPH made ---------- */
    var polling = {};
    function mediaCard(it) {
      var c = document.createElement('div'); c.className = 'tmph-media'; c.dataset.mid = it.id;
      if (it.status === 'failed') { c.innerHTML = '<div class="tmph-mfail"></div>'; c.firstChild.textContent = it.error || 'This could not be made. Please try again.'; return c; }
      if (it.kind === 'image') {
        var im = document.createElement('img'); im.src = mediaUrl(it.id); im.alt = it.prompt || 'Picture made by TMPH'; im.loading = 'lazy';
        im.onclick = function () { window.open(mediaUrl(it.id), '_blank', 'noopener'); };
        c.appendChild(im);
        c.insertAdjacentHTML('beforeend', '<div class="tmph-mbar"><small></small><a class="tmph-mbtn tmph-ghost" target="_blank" rel="noopener">' + IC.open + 'Open</a><a class="tmph-mbtn" download>' + IC.dl + 'Download</a></div>');
        c.querySelector('small').textContent = it.prompt || '';
        c.querySelectorAll('a')[0].href = mediaUrl(it.id); c.querySelectorAll('a')[1].href = mediaUrl(it.id, true);
        return c;
      }
      if (it.status === 'done') {
        var v = document.createElement('video'); v.controls = true; v.playsInline = true; v.preload = 'metadata'; v.src = mediaUrl(it.id);
        c.appendChild(v);
        c.insertAdjacentHTML('beforeend', '<div class="tmph-mbar"><small></small><a class="tmph-mbtn" download>' + IC.dl + 'Download</a></div>');
        c.querySelector('small').textContent = it.prompt || '';
        c.querySelector('a').href = mediaUrl(it.id, true);
        return c;
      }
      c.innerHTML = '<div class="tmph-vwork"><div class="tmph-spin"></div><b>Making your video…</b><span class="tmph-vs">Usually 1–3 minutes. You can keep this open.</span><div class="tmph-pbar"><i></i></div></div>';
      return c;
    }
    function renderMedias(el, m) {
      var box = el.querySelector('.tmph-medias'); if (!box) return;
      box.innerHTML = '';
      (m.media || []).forEach(function (it) { box.appendChild(mediaCard(it)); if (it.kind === 'video' && it.status === 'working') pollVideo(it); });
    }
    function pollVideo(it) {
      if (polling[it.id]) return;
      var t0 = it.t || (it.t = Date.now()), tries = 0;
      polling[it.id] = setInterval(function () {
        var card = chat.querySelector('.tmph-media[data-mid="' + it.id + '"]');
        var secs = (Date.now() - t0) / 1000;
        if (card) {
          var bar = card.querySelector('.tmph-pbar i'), st = card.querySelector('.tmph-vs');
          if (bar) bar.style.width = Math.min(92, 4 + 88 * (1 - Math.exp(-secs / 70))) + '%';
          if (st) st.textContent = 'Working for ' + Math.floor(secs) + 's · usually 1–3 minutes';
        }
      }, 1000);
      function stop() { clearInterval(polling[it.id]); delete polling[it.id]; }
      (function again() {
        if (!polling[it.id]) return;
        if (Date.now() - t0 > 28 * 60 * 1000) { it.status = 'failed'; it.error = 'The video took too long. Please try again.'; stop(); save(); redrawMedia(it); return; }
        api.get('ai.media', { id: it.id }).then(function (d) {
          var r = d && d.media; if (!r || !polling[it.id]) return;
          if (r.status === 'done' || r.status === 'failed') { it.status = r.status; it.error = r.error || ''; stop(); save(); redrawMedia(it); return; }
          setTimeout(again, 5000);
        }).catch(function (e) {
          if (++tries > 40) { stop(); return; }
          setTimeout(again, 8000);
        });
      })();
    }
    function redrawMedia(it) {
      var old = chat.querySelector('.tmph-media[data-mid="' + it.id + '"]');
      if (old) { var n = mediaCard(it); old.parentNode.replaceChild(n, old); toBottom(); }
    }
    function toBottom(force) { if (force || stickBottom) chat.scrollTop = chat.scrollHeight; }
    chat.addEventListener('scroll', function () { stickBottom = chat.scrollHeight - chat.scrollTop - chat.clientHeight < 60; });
    function renderAll() {
      chat.innerHTML = '';
      if (!msgs.length) {
        chat.insertAdjacentHTML('beforeend',
          '<div class="tmph-empty"><div class="tmph-avatar">' + STAR + '</div><h3>Hi, I\'m TMPH</h3>' +
          '<p>Ask me in Gujarati, Hindi, English or Hinglish.</p></div>');
      } else msgs.forEach(function (m, i) { chat.appendChild(msgEl(m, i)); });
      toBottom(true);
    }

    /* ---------- open / close ---------- */
    function isDark() {
      var h = document.documentElement, b = document.body;
      return h.getAttribute('data-theme') === 'dark' || h.classList.contains('dark') || b.classList.contains('dark') || b.classList.contains('dark-mode');
    }
    function syncTheme() { root.classList.toggle('tmph-dark', isDark()); }
    var panel = root.querySelector('#tmphPanel');
    function open() { syncTheme(); root.classList.add('tmph-open'); panel.classList.add('tmph-on'); setTimeout(function () { input.focus(); }, 60); }
    function close() { root.classList.remove('tmph-open'); panel.classList.remove('tmph-on'); try { fab.focus({ preventScroll: true }); } catch (e) {} }
    fab.onclick = open;
    root.querySelector('#tmphClose').onclick = close;
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && root.classList.contains('tmph-open')) close(); });
    try { new MutationObserver(syncTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] }); } catch (e) {}

    /* ---------- sending ---------- */
    function setBusy(b) { streaming = b; root.classList.toggle('tmph-busy', b); }
    function autosize() { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 130) + 'px'; }

    function addError(text) {
      msgs.push({ role: 'ai', text: text, err: true });
      chat.appendChild(msgEl(msgs[msgs.length - 1], msgs.length - 1)); toBottom(true);
    }

    function send(text) {
      text = (text || '').trim();
      var sendFiles = pending.filter(function (f) { return !f.busy; });
      if ((!text && !sendFiles.length) || streaming || pending.some(function (f) { return f.busy; })) return;
      if (text.length > cfg.max) { addError('Your message is too long (maximum ' + cfg.max + ' characters).'); return; }
      if (SR && rec) stopVoice();
      msgs = msgs.filter(function (m) { return !m.err; });        // an earlier error bubble is not part of the chat
      var um = { role: 'user', text: text };
      if (sendFiles.length) um.atts = sendFiles.map(function (f) { return { name: f.name, thumb: f.thumb || '' }; });
      msgs.push(um); input.value = ''; autosize();
      var payload = msgs.map(function (m, ix) {
        var o = { role: m.role, text: m.text };
        if (m.atts && m.atts.length) o.files = ix === msgs.length - 1 ? sendFiles.map(function (f) { return { name: f.name, mime: f.mime, data: f.b64 }; }) : m.atts.map(function (a) { return { name: a.name }; });
        return o;
      });
      clearTray();
      save(); renderAll();                                         // draws the chat up to the new question…
      var ai = { role: 'ai', text: '' }; msgs.push(ai);            // …then the empty reply bubble is added on its own
      var el = msgEl(ai, msgs.length - 1), bub = el.querySelector('.tmph-bubble'), cp = el.querySelector('.tmph-copy');
      chat.appendChild(el); cp.style.display = 'none';
      bub.innerHTML = '<span class="tmph-typing"><i></i><i></i><i></i></span>'; toBottom(true); setBusy(true);

      var raf = 0;
      function paint(live) {
        // the caret is a private marker inside the text, so it sits at the end of the last line
        bub.innerHTML = live ? md(ai.text + '█').replace('█', '<span class="tmph-caret"></span>') : md(ai.text);
        toBottom();
      }
      function schedule() { if (!raf) raf = requestAnimationFrame(function () { raf = 0; paint(true); }); }
      function finish(errText, stopped) {
        if (raf) { cancelAnimationFrame(raf); raf = 0; }
        if (ctrl !== mine) return;                                  // Clear chat was pressed meanwhile: this reply is no longer wanted
        setBusy(false); ctrl = null;
        if (!ai.text && ai.media && ai.media.length) ai.text = 'Here you go.';
        if (!ai.text) {
          // nothing was written: drop the empty bubble and show the problem (stopping with no text just removes the bubble)
          var ix = msgs.indexOf(ai); if (ix > -1) msgs.splice(ix, 1);
          if (!stopped && errText) msgs.push({ role: 'ai', text: errText, err: true });
          save(); renderAll(); return;
        }
        paint(false); cp.style.display = '';
        if (errText) addError(errText);
        save();
      }

      var mine = ctrl = new AbortController();
      var gotAny = false, failText = '';
      function addMedia(it) {
        if (!it || !it.id) return;
        (ai.media = ai.media || []).push({ id: it.id, kind: it.kind, status: it.status, prompt: it.prompt || '', error: it.error || '', t: Date.now() });
        renderMedias(el, ai); toBottom(true); save();
      }
      function addActions(pack) {
        if (!pack) return; (ai.actions = ai.actions || []).push(pack); renderActions(el, ai); toBottom(true); save();
      }
      fetch(API_BASE_URL + '?action=ai.chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + api.token() },
        body: JSON.stringify({ messages: payload, stream: cfg.stream, page: pageInfo() }),
        signal: mine.signal, cache: 'no-store'
      }).then(function (res) {
        if (res.status === 401) { try { api.clearToken(); } catch (e) {} window.location.href = 'index.html'; throw new Error('Session expired'); }
        var ct = res.headers.get('content-type') || '';
        if (ct.indexOf('application/json') > -1) {            // a problem before Gemini was called, or a non-streamed reply
          return res.json().then(function (d) {
            if (d && d.reply) { ai.text = String(d.reply); gotAny = true; (d.media || []).forEach(addMedia); (d.actions || []).forEach(addActions); return; }
            failText = (d && d.error) || ('Server error (' + res.status + ').');
          });
        }
        if (!res.ok || !res.body) { failText = 'Server error (' + res.status + '). Please try again.'; return; }
        var reader = res.body.getReader(), dec = new TextDecoder('utf-8'), buf = '';
        function handle(ev) {
          ev.split('\n').forEach(function (ln) {
            if (ln.indexOf('data:') !== 0) return;
            var o; try { o = JSON.parse(ln.slice(5).trim()); } catch (e) { return; }
            if (o.t) { ai.text += o.t; gotAny = true; schedule(); }
            else if (o.media) { addMedia(o.media); gotAny = true; }
            else if (o.actions) { addActions(o.actions); gotAny = true; }
            else if (o.error) failText = o.error;
          });
        }
        function pump() {
          return reader.read().then(function (r) {
            if (r.done) { if (buf.trim()) handle(buf); return; }
            buf += dec.decode(r.value, { stream: true }).replace(/\r\n/g, '\n');
            var p; while ((p = buf.indexOf('\n\n')) > -1) { handle(buf.slice(0, p)); buf = buf.slice(p + 2); }
            return pump();
          });
        }
        return pump();
      }).then(function () {
        if (!gotAny && !failText) failText = 'TMPH could not make an answer. Please try again.';
        finish(failText, false);
      }).catch(function (err) {
        if (err && err.name === 'AbortError') { finish('', true); return; }
        if (err && err.message === 'Session expired') return;
        finish('Could not reach the server. Please check your internet and try again.', false);
      });
    }

    root.querySelector('#tmphStop').onclick = function () { if (ctrl) ctrl.abort(); };
    root.querySelector('#tmphSend').onclick = function () { send(input.value); };
    input.addEventListener('paste', function (e) {
      var cd = e.clipboardData; if (!caps.files || !cd || !cd.files || !cd.files.length) return;
      e.preventDefault(); addFiles(cd.files);
    });
    /* ---------- attachments: + menu, tray, drag-drop ---------- */
    var pending = [], tray = root.querySelector('#tmphTray'), note = root.querySelector('#tmphNote'), menu = root.querySelector('#tmphMenu'), noteT = 0;
    function say(t) { note.textContent = t; note.classList.add('tmph-on'); clearTimeout(noteT); noteT = setTimeout(function () { note.classList.remove('tmph-on'); }, 6000); }
    function drawTray() {
      tray.innerHTML = '';
      pending.forEach(function (f, i) {
        var c = document.createElement('div'); c.className = 'tmph-chip' + (f.thumb ? ' tmph-img' : '');
        if (f.thumb) { var im = document.createElement('img'); im.src = f.thumb; im.alt = f.name; c.appendChild(im); }
        else { c.innerHTML = '<div class="tmph-fi"></div><div style="min-width:0"><span class="tmph-fn"></span><small></small></div>'; c.querySelector('.tmph-fi').textContent = fileExt(f.name); c.querySelector('.tmph-fn').textContent = f.name; c.querySelector('small').textContent = f.busy ? 'Reading…' : (Math.max(1, Math.round(f.size / 1024)) + ' KB'); }
        var x = document.createElement('button'); x.type = 'button'; x.className = 'tmph-rm'; x.setAttribute('aria-label', 'Remove ' + f.name); x.textContent = '✕';
        x.onclick = function () { pending.splice(i, 1); drawTray(); }; c.appendChild(x);
        tray.appendChild(c);
      });
      tray.classList.toggle('tmph-on', pending.length > 0);
    }
    function clearTray() { pending = []; drawTray(); note.classList.remove('tmph-on'); }
    function addFiles(list) {
      if (!caps.files || streaming) return;
      var arr = Array.prototype.slice.call(list || []);
      arr.forEach(function (file) {
        if (pending.length >= MAXF) { say('You can attach up to ' + MAXF + ' files at a time.'); return; }
        var f = { name: file.name || 'photo.jpg', mime: file.type || '', size: file.size, busy: true, b64: '', thumb: '' };
        if (!file.name && /^image\//.test(file.type)) f.name = 'pasted-image.' + (file.type.split('/')[1] || 'png');
        var isImg = /^image\//.test(file.type);
        if (!isImg && file.size > MAXMB * 1048576) { say('"' + f.name + '" is too big (maximum ' + MAXMB + ' MB each).'); return; }
        if (isImg && file.size > 40 * 1048576) { say('"' + f.name + '" is too big.'); return; }
        pending.push(f); drawTray();
        var job = isImg && file.type !== 'image/gif' ? shrinkImage(file, 1600, 0.86).then(function (r) {
          if (r) { f.b64 = r.b64; f.mime = r.mime; f.thumb = null; return shrinkImage(file, 110, 0.6).then(function (t) { f.thumb = t ? t.url : r.url; f.size = Math.round(f.b64.length * 0.75); }); }
          if (file.size > MAXMB * 1048576) throw new Error('big');
          return readB64(file).then(function (b) { f.b64 = b; f.thumb = ''; });                       // e.g. HEIC the browser cannot draw
        }) : readB64(file).then(function (b) { f.b64 = b; if (isImg) f.thumb = 'data:' + file.type + ';base64,' + (b.length < 400000 ? b : ''); if (isImg && b.length >= 400000) f.thumb = ''; });
        job.then(function () { f.busy = false; drawTray(); }).catch(function () {
          var ix = pending.indexOf(f); if (ix > -1) pending.splice(ix, 1); drawTray(); say('"' + f.name + '" could not be read.');
        });
      });
    }
    if (caps.files) {
      var plus = root.querySelector('#tmphPlus');
      var inputs = { cam: root.querySelector('#tmphFCam'), img: root.querySelector('#tmphFImg'), doc: root.querySelector('#tmphFDoc') };
      plus.onclick = function (e) { e.stopPropagation(); menu.classList.toggle('tmph-on'); };
      menu.addEventListener('click', function (e) {
        var b = e.target.closest('[data-pick]'); if (!b) return;
        menu.classList.remove('tmph-on'); inputs[b.dataset.pick].click();
      });
      Object.keys(inputs).forEach(function (k) { inputs[k].addEventListener('change', function () { addFiles(inputs[k].files); inputs[k].value = ''; }); });
      document.addEventListener('click', function (e) { if (menu.classList.contains('tmph-on') && !e.target.closest('#tmphMenu')) menu.classList.remove('tmph-on'); });
      var drop = root.querySelector('#tmphDrop'), dragN = 0;
      panel.addEventListener('dragenter', function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') > -1) { dragN++; drop.classList.add('tmph-on'); } });
      panel.addEventListener('dragover', function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') > -1) e.preventDefault(); });
      panel.addEventListener('dragleave', function () { dragN = Math.max(0, dragN - 1); if (!dragN) drop.classList.remove('tmph-on'); });
      panel.addEventListener('drop', function (e) { dragN = 0; drop.classList.remove('tmph-on'); if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); addFiles(e.dataTransfer.files); } });
    }

    /* ---------- voice typing (browser speech recognition) ---------- */
    var userStop = false, rec = null, LANGS = [['en-IN', 'English'], ['hi-IN', 'हिन्दी'], ['gu-IN', 'ગુજરાતી']], langIx = 0, voiceBase = '';
    try { langIx = Math.max(0, Math.min(2, +localStorage.getItem('tmph_lang') || 0)); } catch (e) {}
    function stopVoice() { try { if (rec) rec.stop(); } catch (e) {} }
    if (SR) {
      var mic = root.querySelector('#tmphMic'), lb = root.querySelector('#tmphLang');
      var paintLang = function () { lb.textContent = '🎤 ' + LANGS[langIx][1]; lb.title = 'Voice language - tap to change'; };
      paintLang();
      lb.onclick = function () { langIx = (langIx + 1) % LANGS.length; try { localStorage.setItem('tmph_lang', langIx); } catch (e) {} paintLang(); };
      mic.onclick = function () {
        if (rec) { userStop = true; stopVoice(); return; }
        userStop = false;
        var r = new SR(); rec = r; r.lang = LANGS[langIx][0]; r.interimResults = true; r.continuous = true;
        voiceBase = input.value ? input.value.replace(/\s+$/, '') + ' ' : '';
        r.onresult = function (ev) {
          var t = ''; for (var i = 0; i < ev.results.length; i++) t += ev.results[i][0].transcript;
          input.value = (voiceBase + t).slice(0, cfg.max); autosize();
        };
        r.onerror = function (ev) { if (ev && (ev.error === 'not-allowed' || ev.error === 'service-not-allowed')) say('Allow the microphone in your browser to use voice.'); else if (ev && ev.error === 'no-speech') say('I did not hear anything. Try again.'); };
        r.onend = function () { mic.classList.remove('tmph-rec'); rec = null; input.focus(); var said = input.value.trim(); if (userStop && said && said !== voiceBase.trim()) send(said); };
        try { r.start(); mic.classList.add('tmph-rec'); } catch (e) { rec = null; }
      };
    }
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(input.value); } });
    input.addEventListener('input', autosize);

    /* ---------- clear chat / copy ---------- */
    root.querySelector('#tmphClear').onclick = function () { if (ctrl) { ctrl.abort(); ctrl = null; } msgs = []; save(); setBusy(false); renderAll(); };
    function copyText(t, btn) {
      var ok = function () { var o = btn.innerHTML; btn.classList.add('tmph-done'); btn.textContent = 'Copied ✓'; setTimeout(function () { btn.classList.remove('tmph-done'); btn.innerHTML = o; }, 1400); };
      function fb() { var a = document.createElement('textarea'); a.value = t; a.style.position = 'fixed'; a.style.opacity = '0'; document.body.appendChild(a); a.select(); try { document.execCommand('copy'); ok(); } catch (e) {} a.remove(); }
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(ok, fb); else fb();
    }
    chat.addEventListener('click', function (e) {
      var c = e.target.closest('[data-copy]'), k = e.target.closest('[data-codecopy]');
      if (c) { var m = msgs[+c.closest('.tmph-msg').dataset.i]; if (m) copyText(m.text, c); }
      else if (k) copyText(k.closest('.tmph-code').querySelector('code').textContent, k);
    });

    renderAll();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
