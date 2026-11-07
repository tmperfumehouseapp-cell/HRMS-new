<?php
/* TM SEO — "Ask Claude" page (dashboard -> Claude, one button).
   Open:  https://fourpirates.space/analytics/seo_ai4.php   (same view key and login cookie as report.php)
   What it does: you paste the "Copy request" text from an SEO card, press "Ask Claude", and the server asks Claude
   for the Google title, Google description, page address and text. Nothing is published, nothing on your site changes.
   Setup (one time): add ONE line to config.php, inside the array, before the closing  ];
        'anthropic_key' => 'PASTE_YOUR_KEY_HERE',
   Optional lines in config.php:
        'anthropic_model'  => 'claude-sonnet-5-5',   // model used
        'ai_daily_max'     => 40,                    // max questions per day (cost cap)
        'blocked_words'    => ['word one', 'word two'],   // extra words that must never appear in the text
   The key stays on the server. It is never sent to the browser.
   Link from a card:  seo_ai4.php?q=<the request text, url-encoded>  opens this page with the request already filled in. */
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$CFG = require __DIR__ . '/config.php';

/* ── access: same key and cookie as report.php ── */
$RK = (string)($CFG['viewkey'] ?? '');
if ($RK === '') { http_response_code(403); echo 'Add a viewkey line to config.php first.'; exit; }
$tok = hash('sha256', 'tmrep|' . $RK);
$k = (string)($_GET['k'] ?? '');
$kOk = $k !== '' && hash_equals($RK, $k);
$cOk = hash_equals($tok, (string)($_COOKIE['tm_rep'] ?? ''));
if (!$kOk && !$cOk) { http_response_code(403); echo 'Open this page with your key:  ' . htmlspecialchars(basename($_SERVER['SCRIPT_NAME'] ?? 'seo_ai4.php'), ENT_QUOTES) . '?k=YOUR_VIEW_KEY'; exit; }
if ($kOk) {
  $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
  setcookie('tm_rep', $tok, ['expires' => time() + 86400 * 60, 'path' => $dir, 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax']);
  $keep = array_diff_key($_GET, ['k' => 1]);
  header('Location: ' . basename($_SERVER['SCRIPT_NAME'] ?? 'seo_ai4.php') . ($keep ? '?' . http_build_query($keep) : ''));
  exit;
}

/* ── settings ── */
$API_KEY   = trim((string)($CFG['anthropic_key'] ?? ''));
$MODEL     = trim((string)($CFG['anthropic_model'] ?? '')) ?: 'claude-sonnet-5-5';
$API_URL   = trim((string)($CFG['anthropic_url'] ?? '')) ?: 'https://api.anthropic.com/v1/messages';
$DAILY_MAX = max(1, (int)($CFG['ai_daily_max'] ?? 40));
$CSRF      = hash('sha256', 'ai|' . $tok);
$USAGE     = __DIR__ . '/data/ai_usage.json';

/* words that must never be in the text: other brands' names and risky claims (whole words, any case) */
$BLOCK = ['dior', 'chanel', 'gucci', 'armani', 'versace', 'creed', 'tom ford', 'ysl', 'yves saint laurent', 'paco rabanne', 'prada',
  'hermes', 'hermès', 'givenchy', 'burberry', 'bvlgari', 'bulgari', 'calvin klein', 'hugo boss', 'jean paul gaultier', 'montblanc',
  'mont blanc', 'azzaro', 'dolce', 'gabbana', 'louis vuitton', 'amouage', 'lattafa', 'afnan', 'ajmal', 'armaf', 'carolina herrera',
  'valentino', 'coty', 'davidoff', 'issey miyake', 'narciso rodriguez', 'lancome', 'lancôme', 'byredo', 'maison margiela', 'initio',
  'parfums de marly', 'xerjoff', 'kilian', 'nishane', 'hawas', 'sauvage', 'aventus', 'black opium', 'khamrah',
  'inspired by', 'replica', 'clone', 'dupe', 'identical', '100% match', 'smells exactly', 'same as the original', 'same as original'];
if (!empty($CFG['blocked_words']) && is_array($CFG['blocked_words'])) {
  foreach ($CFG['blocked_words'] as $w) { $w = trim((string)$w); if ($w !== '') $BLOCK[] = $w; }
}
function ai_hits(string $text, array $block): array {
  $hits = [];
  foreach (array_unique($block) as $w) {
    if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/iu', $text)) { $hits[] = $w; }
  }
  return $hits;
}

/* what Claude is told every time */
$SYSTEM = <<<'TXT'
You write SEO text for TM Perfume House, an Indian fragrance brand from Surat, Gujarat. Customers order on WhatsApp, and there is a physical store in Vesu, Surat.
The business is under a trademark complaint, so these rules are strict and override anything in the request:
1. Never write the name of any other brand, designer house, or branded perfume, in any part of the answer (title, description, address, text, image text). If the request is about a search that is another brand's name or perfume name, answer with exactly one line starting "DO NOT TARGET:" and a short reason, then one line starting "SAFE ANGLE:" with a brand-free topic instead (scent family, occasion, season, price, lasting hours).
2. Never write: inspired by, replica, clone, dupe, identical, same as the original, 100% match, smells exactly like. Describe the scent instead (notes, mood, occasion, how long it lasts, projection).
3. All wording is original. Do not copy competitors.
4. Use only facts given in the request or listed above. Never invent prices, reviews, ratings, awards, stock, discounts or percentages. Mention the fragrance oil percentage only if the request states it.
5. Plain, honest, helpful wording for Indian shoppers. English unless the request asks for Hindi, Hinglish or Gujarati.
Answer in exactly this layout, one label per line start, nothing before or after:
TITLE: Google title, 60 characters or fewer
DESCRIPTION: Google description, 155 characters or fewer
URL: page address handle, lowercase words joined by hyphens, no brand names
TEXT: the page or article text if the request asks for text (use short paragraphs); otherwise write "not requested"
CHECK: one short line only if something in this text should be confirmed with your lawyer; otherwise write "none"
TXT;

/* ── usage counter (cost cap) ── */
function ai_usage_get(string $file): int {
  $d = @json_decode((string)@file_get_contents($file), true);
  return is_array($d) ? (int)($d[date('Y-m-d')] ?? 0) : 0;
}
function ai_usage_add(string $file): int {
  $dir = dirname($file);
  if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
  $fp = @fopen($file, 'c+');
  if (!$fp) { return 0; }
  flock($fp, LOCK_EX);
  $d = json_decode((string)stream_get_contents($fp), true);
  $d = is_array($d) ? $d : [];
  $today = date('Y-m-d');
  $d = array_slice($d, -30, 30, true);          // keep the last 30 days only
  $d[$today] = (int)($d[$today] ?? 0) + 1;
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($d));
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
  return (int)$d[$today];
}

/* ── the call to Claude (server side only) ── */
function ai_call(string $url, string $key, string $model, string $system, string $user): array {
  $body = json_encode(['model' => $model, 'max_tokens' => 1800, 'system' => $system,
    'messages' => [['role' => 'user', 'content' => $user]]], JSON_UNESCAPED_UNICODE);
  $hdr = ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
  $raw = false; $code = 0; $err = '';
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $hdr,
      CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $hdr), 'content' => $body,
      'timeout' => 90, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    foreach (($http_response_header ?? []) as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; } }
    if ($raw === false) { $err = 'no connection'; }
  }
  if ($raw === false || $raw === '') { return ['ok' => false, 'error' => 'Could not reach Claude (' . ($err ?: 'no answer') . '). Try again in a minute.']; }
  $j = json_decode((string)$raw, true);
  if ($code !== 200 || !is_array($j)) {
    $msg = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
    if ($code === 401 || $code === 403) { return ['ok' => false, 'error' => 'Claude rejected the API key. Check the line in config.php: no spaces, no quotes inside the key, and the key is active in the Anthropic Console.']; }
    if ($code === 429) { return ['ok' => false, 'error' => 'Claude is rate limited right now, or the Console spend limit is reached. Wait a minute, or check Billing in the Anthropic Console.']; }
    if ($code === 400 && stripos($msg, 'credit') !== false) { return ['ok' => false, 'error' => 'The Anthropic account has no credit left. Add credit in the Console under Billing.']; }
    if ($code >= 500) { return ['ok' => false, 'error' => 'Claude is busy right now (error ' . $code . '). Try again in a minute.']; }
    return ['ok' => false, 'error' => 'Claude returned an error (' . $code . ')' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 160) : '') . '.'];
  }
  $text = '';
  foreach (($j['content'] ?? []) as $blk) { if (($blk['type'] ?? '') === 'text') { $text .= (string)($blk['text'] ?? ''); } }
  $text = trim($text);
  if ($text === '') { return ['ok' => false, 'error' => 'Claude sent an empty answer. Press Ask Claude again.']; }
  return ['ok' => true, 'text' => $text];
}

/* ── POST: ask ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  $out = function (array $a, int $code = 200) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) { $out(['ok' => false, 'error' => 'Page expired. Reload the page and try again.'], 403); }
  if ($API_KEY === '') { $out(['ok' => false, 'error' => 'No API key yet. Add the anthropic_key line to config.php (steps are on this page).'], 200); }
  $req = trim((string)($_POST['request'] ?? ''));
  if (mb_strlen($req) < 10) { $out(['ok' => false, 'error' => 'Paste the request text first (from the card\'s Copy request button).']); }
  if (mb_strlen($req) > 4000) { $out(['ok' => false, 'error' => 'The request is too long (over 4000 characters). Shorten it.']); }
  $used = ai_usage_get($USAGE);
  if ($used >= $DAILY_MAX) { $out(['ok' => false, 'error' => "Daily limit reached ($used of $DAILY_MAX). It resets at midnight, or raise ai_daily_max in config.php."]); }
  $r = ai_call($API_URL, $API_KEY, $MODEL, $SYSTEM, $req);
  if (!$r['ok']) { $out($r); }
  $used = ai_usage_add($USAGE);
  $hits = ai_hits($r['text'], $BLOCK);
  $out(['ok' => true, 'text' => $r['text'], 'blocked' => $hits, 'used' => $used, 'max' => $DAILY_MAX]);
}

/* ── page ── */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$prefill = mb_substr((string)($_GET['q'] ?? ''), 0, 4000);
$used = ai_usage_get($USAGE);
$self = basename($_SERVER['SCRIPT_NAME'] ?? 'seo_ai4.php');
$dash = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($_COOKIE['tm_dash'] ?? 'report.php')) ?: 'report.php';
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ask Claude — SEO</title>
<style>
:root{--bg:#fdf8f1;--ink:#241c15;--mut:#8a8176;--or:#e8761c;--gr:#1b7a3d;--rd:#c0392b;--card:#fff;--bd:#eadfce}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:16px}
.wrap{max-width:860px;margin:0 auto}h1{font-size:22px;margin:0 0 4px}.sub{color:var(--mut);margin:0 0 14px}
.top{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}.top a{color:var(--or);font-weight:700;text-decoration:none}
.meter{margin-left:auto;font-size:13px;color:var(--mut)}
.box{background:var(--card);border:1.5px solid var(--bd);border-radius:14px;padding:14px;margin-bottom:12px}
textarea{width:100%;min-height:150px;border:1.5px solid #e4d6bd;border-radius:10px;padding:10px;font:inherit;background:#fffdf7;resize:vertical}
button{border:0;border-radius:10px;padding:10px 16px;font:inherit;font-weight:700;cursor:pointer;background:var(--or);color:#fff}
button.o{background:#fff;color:var(--ink);border:1.5px solid var(--ink);padding:5px 10px;font-size:13px}
button:disabled{opacity:.55;cursor:wait}
.row{display:flex;gap:10px;align-items:center;margin-top:10px;flex-wrap:wrap}
.lab{font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mut);display:flex;gap:8px;align-items:center;margin-bottom:6px}
.lab .sp{flex:1}.cnt{font-weight:700;text-transform:none;letter-spacing:0}.ok{color:var(--gr)}.bad{color:var(--rd)}
.val{white-space:pre-wrap;word-break:break-word;background:#fffdf7;border:1.5px solid #e4d6bd;border-radius:8px;padding:8px 10px}
.warn{background:#fdecea;border:1.5px solid var(--rd);color:#7a1d14;border-radius:10px;padding:10px 12px;margin-bottom:10px}
.err{background:#fff3cd;border:1.5px dashed var(--or);border-radius:10px;padding:10px 12px;margin-bottom:10px}
.setup li{margin:4px 0}code{background:#faf3e6;border-radius:6px;padding:1px 6px;font-size:13px;word-break:break-all}
.hide{display:none}
</style></head><body><div class="wrap">
<div class="top"><a href="<?= h($dash) ?>">← Dashboard</a><span class="meter" id="meter">Used today: <?= $used ?> of <?= $DAILY_MAX ?></span></div>
<h1>Ask Claude</h1>
<p class="sub">Paste the request from an SEO card. Claude writes the Google title, description, page address and text. Nothing is published.</p>

<?php if ($API_KEY === ''): ?>
<div class="box setup"><b>One-time setup: add your API key</b>
<ol>
<li>Open the Anthropic Console, go to <b>API keys</b>, press <b>Create key</b>, and copy it. Also set a monthly spend limit there.</li>
<li>In Hostinger File Manager open <code>public_html/analytics/config.php</code> and click Edit.</li>
<li>Add this line just before the closing <code>];</code>, with your key between the quotes:<br><code>'anthropic_key' =&gt; 'PASTE_YOUR_KEY_HERE',</code></li>
<li>Save, then reload this page. This box disappears.</li>
</ol>
<div style="color:var(--mut)">Do not paste the key into any chat. It only goes into config.php.</div></div>
<?php endif; ?>

<div class="box">
<div class="lab">Request from the SEO card</div>
<textarea id="req" placeholder="Paste the text from the Copy request button here"><?= h($prefill) ?></textarea>
<div class="row"><button id="go" type="button">Ask Claude</button><span id="msg" class="sub" style="margin:0"></span></div>
</div>

<div id="err" class="err hide"></div>
<div id="warn" class="warn hide"></div>
<div id="res" class="hide">
<div class="box" id="c_TITLE"><div class="lab">Google title <span class="sp"></span><span class="cnt" id="n_TITLE"></span><button class="o" data-copy="TITLE">Copy</button></div><div class="val" id="v_TITLE"></div></div>
<div class="box" id="c_DESCRIPTION"><div class="lab">Google description <span class="sp"></span><span class="cnt" id="n_DESCRIPTION"></span><button class="o" data-copy="DESCRIPTION">Copy</button></div><div class="val" id="v_DESCRIPTION"></div></div>
<div class="box" id="c_URL"><div class="lab">Page address (handle) <span class="sp"></span><button class="o" data-copy="URL">Copy</button></div><div class="val" id="v_URL"></div></div>
<div class="box" id="c_TEXT"><div class="lab">Page / article text <span class="sp"></span><button class="o" data-copy="TEXT">Copy</button></div><div class="val" id="v_TEXT"></div></div>
<div class="box" id="c_CHECK"><div class="lab">Check with your lawyer</div><div class="val" id="v_CHECK"></div></div>
<div class="box hide" id="c_RAW"><div class="lab">Full answer <span class="sp"></span><button class="o" data-copy="RAW">Copy</button></div><div class="val" id="v_RAW"></div></div>
<p class="sub">Check the text before you paste it into Shopify. After pasting, press Mark done on the card.</p>
</div>

<script>
(function(){
  var CSRF = <?= json_encode($CSRF) ?>, MAXD = <?= (int)$DAILY_MAX ?>;
  var $ = function(i){ return document.getElementById(i); }, go = $('go'), RAW = '';
  var KEYS = ['TITLE','DESCRIPTION','URL','TEXT','CHECK'];
  function show(id, on){ $(id).classList[on ? 'remove' : 'add']('hide'); }
  function fail(t){ var e = $('err'); e.textContent = t; show('err', true); show('res', false); show('warn', false); }
  function parse(t){
    var out = {}, cur = null, lines = String(t).split(/\r?\n/);
    lines.forEach(function(l){
      var m = /^\s*(TITLE|DESCRIPTION|URL|TEXT|CHECK|DO NOT TARGET|SAFE ANGLE)\s*:\s*(.*)$/i.exec(l);
      if (m) { cur = m[1].toUpperCase(); out[cur] = m[2]; } else if (cur) { out[cur] += '\n' + l; }
    });
    Object.keys(out).forEach(function(k){ out[k] = out[k].trim(); });
    return out;
  }
  function count(id, n, max){ var e = $('n_' + id); e.textContent = n + ' / ' + max; e.className = 'cnt ' + (n <= max ? 'ok' : 'bad'); }
  function render(text, blocked){
    RAW = text;
    var p = parse(text), hasFmt = KEYS.some(function(k){ return p[k] !== undefined; });
    var dnt = p['DO NOT TARGET'] !== undefined;
    $('v_RAW').textContent = text;
    show('c_RAW', !hasFmt || dnt);
    KEYS.forEach(function(k){ var v = p[k] || ''; $('v_' + k).textContent = v; show('c_' + k, hasFmt && !dnt && v !== '' && !(k === 'CHECK' && /^none\.?$/i.test(v)) && !(k === 'TEXT' && /^not requested\.?$/i.test(v))); });
    if (hasFmt && !dnt) { count('TITLE', (p.TITLE || '').length, 60); count('DESCRIPTION', (p.DESCRIPTION || '').length, 155); }
    var w = $('warn');
    if (blocked && blocked.length) { w.textContent = 'Do not publish this yet. It contains words that must not be used: ' + blocked.join(', ') + '. Press Ask Claude again, or edit the text.'; show('warn', true); }
    else if (dnt) { w.textContent = 'Claude says do not target this search: ' + p['DO NOT TARGET'] + (p['SAFE ANGLE'] ? '  Safe angle: ' + p['SAFE ANGLE'] : ''); show('warn', true); }
    else { show('warn', false); }
    show('err', false); show('res', true);
  }
  go.addEventListener('click', function(){
    var r = $('req').value.trim();
    if (r.length < 10) { fail('Paste the request text first.'); return; }
    go.disabled = true; $('msg').textContent = 'Claude is writing... about 20 seconds.';
    var fd = new FormData(); fd.append('csrf', CSRF); fd.append('request', r);
    fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function(x){ return x.json(); })
      .then(function(j){
        if (!j || !j.ok) { fail((j && j.error) || 'Something went wrong. Try again.'); return; }
        render(j.text, j.blocked);
        $('meter').textContent = 'Used today: ' + j.used + ' of ' + j.max;
      })
      .catch(function(){ fail('Could not reach this page. Check your internet and try again.'); })
      .then(function(){ go.disabled = false; $('msg').textContent = ''; });
  });
  document.addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('[data-copy]') : null;
    if (!b) return;
    var k = b.getAttribute('data-copy'), t = k === 'RAW' ? RAW : ($('v_' + k).textContent || '');
    var done = function(){ var o = b.textContent; b.textContent = 'Copied'; setTimeout(function(){ b.textContent = o; }, 1200); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(done, function(){}); }
    else { var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); try { document.execCommand('copy'); done(); } catch (er) {} document.body.removeChild(a); }
  });
})();
</script>
</div></body></html>
