/* voice.js - a start / stop microphone on every Claude box (Boss chat, Claude questions, Brain teach boxes).
   Tap the mic, speak (English, Hindi or Gujarati: tap the language chip to change), tap again to stop.
   The words go into the box; in the Boss chat they are sent at once, so Claude answers with what it understood.
   Uses the browser's own speech recognition (Chrome / Edge / Safari). Nothing is recorded or stored by us. */
(function () {
  'use strict';
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR || window.__hrmsVoice) return; window.__hrmsVoice = true;
  var LANGS = [['en-IN', 'EN'], ['hi-IN', 'हि'], ['gu-IN', 'ગુ']], ix = 0;
  try { ix = Math.max(0, Math.min(2, +localStorage.getItem('tmph_lang') || 0)); } catch (e) { /* ignore */ }
  var SEL = '#hcIn, textarea[data-cq="text"], input[data-cq="text"], textarea.br-ta, .br-q textarea';
  var css = document.createElement('style');
  css.textContent = '.hv-wrap{display:inline-flex;gap:4px;align-items:center;vertical-align:middle;margin-left:6px}.hv-mic,.hv-lang{border:1px solid #cfd4dc;background:#fff;color:#111;border-radius:999px;cursor:pointer;font:600 12px system-ui,sans-serif;padding:5px 9px;line-height:1}.hv-mic.rec{background:#d93025;border-color:#d93025;color:#fff;animation:hvp 1.2s infinite}@keyframes hvp{50%{opacity:.55}}';
  document.head.appendChild(css);
  var rec = null, cur = null, userStop = false;
  function attach(inp) {
    if (inp.__hv) return; inp.__hv = true;
    var wrap = document.createElement('span'); wrap.className = 'hv-wrap';
    var mic = document.createElement('button'); mic.type = 'button'; mic.className = 'hv-mic'; mic.textContent = '🎤 Start'; mic.title = 'Speak instead of typing';
    var lg = document.createElement('button'); lg.type = 'button'; lg.className = 'hv-lang'; lg.title = 'Voice language';
    function paint() { lg.textContent = LANGS[ix][1]; }
    paint();
    lg.onclick = function () { ix = (ix + 1) % LANGS.length; try { localStorage.setItem('tmph_lang', ix); } catch (e) { /* ignore */ } paint(); };
    mic.onclick = function () {
      if (rec) { userStop = true; try { rec.stop(); } catch (e) { /* ignore */ } return; }
      var base = inp.value ? inp.value.replace(/\s+$/, '') + ' ' : '';
      var r = new SR(); rec = r; cur = mic; userStop = false; r.lang = LANGS[ix][0]; r.interimResults = true; r.continuous = true;
      r.onresult = function (ev) { var t = ''; for (var i = 0; i < ev.results.length; i++) t += ev.results[i][0].transcript; inp.value = base + t; inp.dispatchEvent(new Event('input', { bubbles: true })); };
      r.onerror = function () { /* the box stays as it is */ };
      r.onend = function () {
        mic.classList.remove('rec'); mic.textContent = '🎤 Start'; rec = null; cur = null;
        if (userStop && inp.id === 'hcIn' && inp.value.trim() && inp.value.trim() !== base.trim()) { var f = inp.form; if (f) { if (f.requestSubmit) f.requestSubmit(); else f.dispatchEvent(new Event('submit', { cancelable: true })); } }
      };
      try { r.start(); mic.classList.add('rec'); mic.textContent = '⏹ Stop'; } catch (e) { rec = null; }
    };
    wrap.appendChild(mic); wrap.appendChild(lg);
    if (inp.id === 'hcIn' && inp.parentNode) inp.parentNode.insertBefore(wrap, inp.nextSibling);
    else inp.insertAdjacentElement('afterend', wrap);
  }
  function scan(root) { try { (root || document).querySelectorAll(SEL).forEach(attach); } catch (e) { /* ignore */ } }
  scan(document);
  var t = 0; new MutationObserver(function () { clearTimeout(t); t = setTimeout(function () { scan(document); }, 200); }).observe(document.documentElement, { childList: true, subtree: true });
})();
