<?php
/**
 * wa_tpl.php — Pre-made messages (WhatsApp templates) for the HRMS. Loaded by wa.php (no change to api.php).
 *
 * ONE template = ONE row here, but it is created in EVERY WhatsApp account you own, so it works on all numbers.
 * The screens show ONE status (Waiting / Approved / Rejected); per-account detail only appears when something is wrong.
 *
 *   wa.tpl_list        GET   all pre-made messages + one status each (+ folders)                       [any login]
 *   wa.tpl_sync        POST  pull the templates and their status from Meta (every account)             [Admin / HR / sales head]
 *   wa.tpl_understand  POST  {purpose, fix?, draft?}  Claude says how it understood the wish + fills the whole form   [Admin / HR / sales head]
 *   wa.tpl_check       POST  {draft}  check a draft against Meta's rules → {ok, problem, warnings}      [Admin / HR / sales head]
 *   wa.tpl_utility     POST  {draft}  a Utility-style rewrite of a Marketing draft (better chance)      [Admin / HR / sales head]
 *   wa.tpl_submit      POST  {draft, also_utility?, utility_draft?}  create it in every account at Meta [Admin / HR / sales head]
 *   wa.tpl_save        POST  {draft, id?}  save a draft in HRMS only (not sent to Meta yet)             [Admin / HR / sales head]
 *   wa.tpl_push        POST  {id}  create it in the accounts where it is missing (also sends a saved draft)  [Admin / HR / sales head]
 *   wa.tpl_attach      POST  {id, lib_id}  choose the library photo / video / PDF that goes on top when it is sent   [Admin / HR / sales head]
 *   wa.tpl_folder      POST  {id, folder}  put a message in a folder                                    [Admin / HR / sales head]
 *   wa.tpl_delete      POST  {id}  remove it from HRMS and from Meta                                    [Admin / HR / sales head]
 *   wa.tpl_star        POST  {id}  favourite on / off                                                   [any login]
 *   wa.tpl_suggest     POST  {phone}  for ONE chat: how Claude understood it + the best approved template, or a draft of a new one   [chat owner / admin]
 *   wa.tpl_send        POST  {phone, id, vars, header_var?, extra?}  send a template to a customer (works after the 24-hour window)    [chat owner / admin]
 * The webhook also calls wa_tpl_status_event() when Meta approves / rejects a template.
 *
 * For the campaign engine (other files may call):
 *   wa_tpl_send_components(PDO, array $template, array $vars, string $phoneId, array $extra = []): ['ok', 'components', 'error']
 *   wa_tpl_sendable(array $template, ?PDO $pdo = null): bool      wa_tpl_needs(array $template): array      wa_tpl_chat_ready(array $template, ?PDO $pdo = null): bool
 */
require_once __DIR__ . '/wa_mix.php';   // marketing + utility mix, families, auto-submit (wa_tpl_avail, wa_tpl_autosubmit_tick, wa_mix_*)
if (!function_exists('wa_tpl_tables')) {

function wa_tpl_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_templates (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, language VARCHAR(10) NOT NULL DEFAULT 'en', category VARCHAR(16) NOT NULL DEFAULT 'MARKETING',
            header_format VARCHAR(10) NOT NULL DEFAULT '', header_text VARCHAR(80) NOT NULL DEFAULT '', body TEXT NULL, footer VARCHAR(80) NOT NULL DEFAULT '', buttons TEXT NULL,
            examples TEXT NULL, purpose VARCHAR(600) NOT NULL DEFAULT '', understood VARCHAR(900) NOT NULL DEFAULT '', star TINYINT NOT NULL DEFAULT 0, used INT NOT NULL DEFAULT 0,
            source VARCHAR(8) NOT NULL DEFAULT 'hrms', created_by INT NOT NULL DEFAULT 0, created_at DATETIME NULL, UNIQUE KEY uq_tpl (name, language)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_tpl_waba (
            tpl_id INT NOT NULL, waba_id VARCHAR(30) NOT NULL, meta_id VARCHAR(30) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'PENDING', reason VARCHAR(300) NOT NULL DEFAULT '', updated_at DATETIME NULL,
            PRIMARY KEY (tpl_id, waba_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // columns added later — added once, only when missing
        foreach (['header_lib_id' => 'INT NOT NULL DEFAULT 0', 'folder' => "VARCHAR(30) NOT NULL DEFAULT ''", 'lto' => 'TEXT NULL', 'extra' => 'TEXT NULL',
                  'relabelled_at' => 'DATETIME NULL', 'category_now' => "VARCHAR(16) NOT NULL DEFAULT ''", 'family_id' => 'INT NOT NULL DEFAULT 0', 'version' => 'INT NOT NULL DEFAULT 1'] as $col => $def) {
            try { $pdo->query("SELECT $col FROM wa_templates LIMIT 0"); }
            catch (PDOException $e) { $pdo->exec("ALTER TABLE wa_templates ADD COLUMN $col $def"); }
        }
        // what Meta says per account: the category it really uses, the quality rating, and the back-off of a submission that failed for a passing reason
        foreach (['category' => "VARCHAR(16) NOT NULL DEFAULT ''", 'quality' => "VARCHAR(10) NOT NULL DEFAULT ''", 'tries' => 'TINYINT NOT NULL DEFAULT 0', 'next_try' => 'DATETIME NULL'] as $col => $def) {
            try { $pdo->query("SELECT $col FROM wa_tpl_waba LIMIT 0"); } catch (PDOException $e) { try { $pdo->exec("ALTER TABLE wa_tpl_waba ADD COLUMN $col $def"); } catch (PDOException $e2) { } }
        }
        try { wa_mix_tables($pdo); } catch (Throwable $e) { }
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}

/** Every WhatsApp account (WABA) we own: from the numbers list and config.php. */
function wa_wabas(PDO $pdo): array
{
    $o = []; $d = wa_cfg_val('WA_WABA_ID'); if ($d !== '') $o[$d] = true;
    foreach ($pdo->query("SELECT waba_id FROM wa_numbers")->fetchAll() as $r) { $w = trim((string)$r['waba_id']); if ($w !== '') $o[$w] = true; }
    return array_keys($o);
}

function wa_tpl_vars(string $text): array { preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $m); $n = array_values(array_unique(array_map('intval', $m[1]))); sort($n); return $n; }
function wa_tpl_render(string $text, array $vars): string
{
    return preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', fn($m) => (string)($vars[(int)$m[1] - 1] ?? ''), $text) ?? $text;
}
function wa_tpl_name(string $s): string
{
    $s = strtolower(trim($s)); $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? ''; $s = trim($s, '_'); return substr($s !== '' ? $s : 'message', 0, 60);
}
/** An array from an array or a JSON string (rows come both ways). */
function wa_tpl_arr($x): array
{
    if (is_array($x)) return $x;
    if (is_string($x) && $x !== '') { $d = json_decode($x, true); if (is_array($d)) return $d; }
    return [];
}
/** The AI call (replaceable in tests). Returns [ok, answer|null, whyNot]. */
if (!function_exists('wa_tpl_model')) {
    function wa_tpl_model(string $sys, string $ctx, array $schema, int $max = 2500): array
    {
        if (!function_exists('convo_ai')) return [false, null, 'The AI is not available.'];
        try { $r = convo_ai($sys, [['text' => $ctx]], $schema, false, $max, 'claude'); return [(bool)($r[0] ?? false), $r[1] ?? null, (string)($r[2] ?? '')]; }
        catch (Throwable $e) { return [false, null, 'The AI could not answer.']; }
    }
}
/** The media library code (wa_media.php + wa_console.php) is loaded after this file, so load it when we need it. */
function wa_tpl_need_media(): void
{
    if (!function_exists('wa_media_upload') && is_file(__DIR__ . '/wa_console.php')) require_once __DIR__ . '/wa_console.php';
    if (!function_exists('wa_lib_media_id') && is_file(__DIR__ . '/wa_media.php')) require_once __DIR__ . '/wa_media.php';
}
function wa_tpl_lib_row(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null; wa_tpl_need_media();
    try { return function_exists('wa_lib_row') ? wa_lib_row($pdo, $id) : null; } catch (PDOException $e) { return null; }
}

// ------------------------------------------------------------------------------------------------------------------------ words Meta treats as promotion
function wa_tpl_promo_regexes(): array
{
    return ['/\b\d{1,3}\s?%(\s*off)?/iu', '/\bflat\s+\d+/iu', '/\bup\s?to\b/iu', '/\boffers?\b/iu', '/\bdiscounts?\b/iu', '/\bsale\b/iu', '/\bbuy\s+now\b/iu', '/\bbuy\s+\d+/iu', '/\bshop\s+now\b/iu',
        '/\border\s+now\b/iu', '/\bgrab\b/iu', '/\bhurry\b/iu', '/\blimited(\s+(time|stock|period))?\b/iu', '/\blast\s+chance\b/iu', "/\\bdon['’]?t\\s+miss\\b/iu", '/\bonly\s+today\b/iu', '/\btoday\s+only\b/iu',
        '/\bexclusive\b/iu', '/\bdeals?\b/iu', '/\bfree\b/iu', '/\bcashback\b/iu', '/\bbest\s+price\b/iu', '/\blowest\s+price\b/iu', '/\bcheap(est)?\b/iu', '/\bcoupons?\b/iu', '/\bpromo(\s?code)?\b/iu',
        '/\b(diwali|festive|festival|holi|eid|navratri)\s+(special|offer|sale)\b/iu', '/\bbumper\b/iu', '/\bdhamaka\b/iu', '/\bsasta\b/iu', '/\bbachat\b/iu', '/\bchh?ut\b/iu', '/\bmauka\b/iu'];
}
/** The promotional words found in a text (as written there, no repeats). */
function wa_tpl_promo_hits(string $text): array
{
    $o = []; foreach (wa_tpl_promo_regexes() as $re) { if (preg_match_all($re, $text, $m)) foreach ($m[0] as $x) { $k = mb_strtolower(trim($x)); if ($k !== '' && !isset($o[$k])) $o[$k] = trim($x); } }
    return array_values($o);
}

// ------------------------------------------------------------------------------------------------------------------------ the Meta components
/** The Meta "components" array from our fields. */
function wa_tpl_components(array $t): array
{
    $c = []; $ex = wa_tpl_arr($t['examples'] ?? []); $lto = wa_tpl_arr($t['lto'] ?? []); $hf = (string)($t['header_format'] ?? ''); $ht = (string)($t['header_text'] ?? ''); $handle = (string)($t['header_handle'] ?? '');
    if ($hf === 'TEXT' && $ht !== '') {
        $h = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $ht];
        if (wa_tpl_vars($ht)) $h['example'] = ['header_text' => [(string)(($ex['header'] ?? '') !== '' ? $ex['header'] : 'Rakesh')]];
        $c[] = $h;
    } elseif (in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
        $h = ['type' => 'HEADER', 'format' => $hf]; if ($handle !== '') $h['example'] = ['header_handle' => [$handle]]; $c[] = $h;
    } elseif ($hf === 'LOCATION') $c[] = ['type' => 'HEADER', 'format' => 'LOCATION'];
    if (trim((string)($lto['text'] ?? '')) !== '') $c[] = ['type' => 'limited_time_offer', 'limited_time_offer' => ['text' => mb_substr(trim((string)$lto['text']), 0, 16), 'has_expiration' => true]];
    $b = ['type' => 'BODY', 'text' => (string)$t['body']];
    $nv = count(wa_tpl_vars((string)$t['body']));
    if ($nv) { $e = array_map('strval', array_slice(array_values((array)($ex['body'] ?? [])), 0, $nv)); while (count($e) < $nv) $e[] = count($e) === 0 ? 'Rakesh' : 'Sample'; $b['example'] = ['body_text' => [$e]]; }
    $c[] = $b;
    if ((string)($t['footer'] ?? '') !== '') $c[] = ['type' => 'FOOTER', 'text' => (string)$t['footer']];
    $bt = [];
    foreach (wa_tpl_arr($t['buttons'] ?? []) as $x) {
        if (!is_array($x)) continue;
        $ty = strtoupper((string)($x['type'] ?? 'QUICK_REPLY')); $tx = mb_substr(trim((string)($x['text'] ?? '')), 0, 25);
        if ($ty === 'COPY_CODE') { $code = trim((string)($x['example'] ?? '')); if ($code !== '') $bt[] = ['type' => 'COPY_CODE', 'example' => $code]; continue; }
        if ($tx === '') continue;
        if ($ty === 'URL' && !empty($x['url'])) {
            $u = ['type' => 'URL', 'text' => $tx, 'url' => (string)$x['url']];
            if (preg_match('/\{\{\s*1\s*\}\}/', (string)$x['url'])) { $u['url'] = preg_replace('/\{\{\s*1\s*\}\}/', '{{1}}', (string)$x['url']); $u['example'] = [str_replace('{{1}}', (string)(($x['example'] ?? '') !== '' ? $x['example'] : 'sample'), $u['url'])]; }
            $bt[] = $u;
        } elseif ($ty === 'PHONE_NUMBER' && !empty($x['phone'])) $bt[] = ['type' => 'PHONE_NUMBER', 'text' => $tx, 'phone_number' => '+' . preg_replace('/\D+/', '', (string)$x['phone'])];
        elseif ($ty === 'QUICK_REPLY' || $ty === '') $bt[] = ['type' => 'QUICK_REPLY', 'text' => $tx];
        // button kinds this screen cannot build (Flow, Catalog …) are not sent back to Meta
    }
    if ($bt) $c[] = ['type' => 'BUTTONS', 'buttons' => array_slice($bt, 0, 10)];
    return $c;
}

/** 'TEXT' | 'IMAGE' | 'VIDEO' | 'DOCUMENT' | 'LOCATION' | 'LTO' */
function wa_tpl_type(array $t): string
{
    $lto = wa_tpl_arr($t['lto'] ?? []); if (trim((string)($lto['text'] ?? '')) !== '') return 'LTO';
    $f = (string)($t['header_format'] ?? ''); return $f === '' ? 'TEXT' : $f;
}
function wa_tpl_media_kind(string $fmt): string { return ['IMAGE' => 'image', 'VIDEO' => 'video', 'DOCUMENT' => 'document'][$fmt] ?? ''; }

/** Quick replies first, every kind keeps the order the person gave (Meta wants each kind of button together). */
function wa_tpl_stable_qr_first(array $b): array
{
    $q = []; $o = []; foreach ($b as $x) { if (($x['type'] ?? '') === 'QUICK_REPLY') $q[] = $x; else $o[] = $x; } return array_merge($q, $o);
}
/** Checks the buttons. Returns [cleanButtonsInMetaOrder, errorOrEmpty]. */
function wa_tpl_buttons_clean(array $raw, bool $lto = false): array
{
    $out = []; $cnt = ['URL' => 0, 'PHONE_NUMBER' => 0, 'COPY_CODE' => 0, 'QUICK_REPLY' => 0];
    foreach ($raw as $b) {
        if (!is_array($b)) continue;
        $ty = strtoupper(trim((string)($b['type'] ?? ''))); if ($ty === '') $ty = 'QUICK_REPLY';
        if (!isset($cnt[$ty])) continue;   // a kind this screen cannot build
        $tx = mb_substr(trim((string)($b['text'] ?? '')), 0, 25);
        if ($ty === 'QUICK_REPLY') { if ($tx === '') continue; $out[] = ['type' => 'QUICK_REPLY', 'text' => $tx]; $cnt[$ty]++; continue; }
        if ($ty === 'URL') {
            $u = trim((string)($b['url'] ?? ''));
            if ($tx === '') return [[], 'A website button needs a name (up to 25 letters).'];
            if (!preg_match('#^https?://[^\s{}]+(\{\{\s*1\s*\}\})?$#i', $u) || mb_strlen($u) > 2000) return [[], 'The website link must start with https:// and may have {{1}} only at the very end.'];
            $isVar = (bool)preg_match('/\{\{\s*1\s*\}\}$/', $u); $e = '';
            if ($isVar) { $u = preg_replace('/\{\{\s*1\s*\}\}$/', '{{1}}', $u); $e = trim((string)($b['example'] ?? '')); if ($e === '') return [[], 'Give an example ending for the web link variable (for example: diwali).']; }
            $out[] = ['type' => 'URL', 'text' => $tx, 'url' => $u, 'example' => mb_substr($e, 0, 100)]; $cnt[$ty]++; continue;
        }
        if ($ty === 'PHONE_NUMBER') {
            $p = preg_replace('/\D+/', '', (string)($b['phone'] ?? '')); if (strlen($p) === 10) $p = '91' . $p; if (strlen($p) === 11 && $p[0] === '0') $p = '91' . substr($p, 1);
            if ($tx === '') return [[], 'A call button needs a name (up to 25 letters).'];
            if (strlen($p) < 8 || strlen($p) > 15) return [[], 'The call number looks wrong — write it with the country code, like 919876543210.'];
            $out[] = ['type' => 'PHONE_NUMBER', 'text' => $tx, 'phone' => $p]; $cnt[$ty]++; continue;
        }
        if ($ty === 'COPY_CODE') {
            $e = trim((string)($b['example'] ?? '')); if ($e === '' || !preg_match('/^[A-Za-z0-9]{1,15}$/', $e)) return [[], 'The copy-code button needs an example code: letters and numbers only, up to 15 (for example DIWALI20).'];
            $out[] = ['type' => 'COPY_CODE', 'text' => 'Copy code', 'example' => $e]; $cnt[$ty]++; continue;
        }
    }
    if ($cnt['URL'] > 2) return [[], 'Meta allows at most 2 website buttons.'];
    if ($cnt['PHONE_NUMBER'] > 1) return [[], 'Meta allows only 1 call button.'];
    if ($cnt['COPY_CODE'] > 1) return [[], 'Meta allows only 1 copy-code button.'];
    if (count($out) > 10) return [[], 'Meta allows at most 10 buttons.'];
    if ($lto && ($cnt['QUICK_REPLY'] || $cnt['PHONE_NUMBER'])) return [[], 'A message with an offer timer can only have Website link and Copy code buttons.'];
    return [wa_tpl_stable_qr_first($out), ''];
}

function wa_tpl_folder_clean($s): string
{
    $s = preg_replace('/[\x00-\x1f<>"\']+/u', '', (string)$s) ?? ''; $s = trim(preg_replace('/\s+/u', ' ', $s) ?? ''); return mb_substr($s, 0, 30);
}
/** A folder to suggest from the category and the words. */
function wa_tpl_folder_guess(array $t): string
{
    $txt = mb_strtolower((string)($t['name'] ?? '') . ' ' . (string)($t['body'] ?? '') . ' ' . (string)($t['purpose'] ?? ''));
    if (!empty(wa_tpl_arr($t['lto'] ?? [])['text'])) return 'Offers';
    if (strtoupper((string)($t['category'] ?? '')) === 'UTILITY' || preg_match('/\b(order|shipped|dispatch|delivery|delivered|tracking|invoice|payment|confirm)/u', $txt)) return 'Order updates';
    if (preg_match('/\b(remind|reminder|later|pending|waiting|still interested|follow|cart|reorder|refill|yaad)/u', $txt)) return 'Reminders';
    if (preg_match('/(offer|discount|sale|%|coupon|diwali|festival|deal|code|special|combo|gift)/u', $txt)) return 'Offers';
    return '';
}

/** Checks a draft before it is sent to Meta. Returns [cleanDraft, errorOrEmpty]. $draft = true lets a photo / video / PDF on top be still missing (a saved draft). */
function wa_tpl_clean(array $d, bool $draft = false): array
{
    $ex = is_array($d['examples'] ?? null) ? $d['examples'] : [];
    $t = ['name' => wa_tpl_name((string)($d['name'] ?? '')), 'language' => preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', (string)($d['language'] ?? 'en')) ? (string)($d['language'] ?? 'en') : 'en',
          'category' => in_array(strtoupper((string)($d['category'] ?? '')), ['MARKETING', 'UTILITY'], true) ? strtoupper((string)$d['category']) : 'MARKETING',
          'header_format' => '', 'header_text' => '', 'header_lib_id' => 0, 'body' => trim((string)($d['body'] ?? '')), 'footer' => mb_substr(trim((string)($d['footer'] ?? '')), 0, 60),
          'buttons' => [], 'lto' => [], 'folder' => wa_tpl_folder_clean($d['folder'] ?? ''), 'extra' => [],
          'examples' => ['body' => array_map('strval', array_values((array)($ex['body'] ?? $d['variable_examples'] ?? []))), 'header' => (string)($ex['header'] ?? ''),
                         'labels' => array_map(fn($x) => mb_substr(trim((string)$x), 0, 20), array_values((array)($ex['labels'] ?? $d['variable_labels'] ?? [])))],
          'warnings' => []];
    // ---- what goes on top
    $hf = strtoupper(trim((string)($d['header_format'] ?? ''))); $ht = trim((string)($d['header_text'] ?? ''));
    if (!in_array($hf, ['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT', 'LOCATION'], true)) $hf = '';
    if ($hf === '' && $ht !== '') $hf = 'TEXT';
    if ($hf === 'TEXT' && $ht === '') $hf = '';
    $lt = is_array($d['lto'] ?? null) ? $d['lto'] : []; $ltoText = mb_substr(trim((string)($lt['text'] ?? '')), 0, 16); $isLto = $ltoText !== '';
    if ($isLto) {
        if (!in_array($hf, ['IMAGE', 'VIDEO'], true)) return [$t, 'A message with an offer timer needs a photo or a video on top.'];
        $t['lto'] = ['text' => $ltoText, 'has_expiration' => true, 'hours' => max(1, min(720, (int)($lt['hours'] ?? 48) ?: 48))];
        $t['footer'] = '';   // Meta does not take a footer together with an offer timer
    }
    $t['header_format'] = $hf;
    if ($hf === 'TEXT') {
        $t['header_text'] = mb_substr($ht, 0, 60); $hv = wa_tpl_vars($t['header_text']);
        if (count($hv) > 1 || ($hv && $hv[0] !== 1)) return [$t, 'The heading can have only one variable, {{1}}.'];
    } elseif (in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
        $t['header_lib_id'] = max(0, (int)($d['header_lib_id'] ?? 0));
        if ($t['header_lib_id'] === 0 && !$draft) return [$t, 'Pick the ' . ['IMAGE' => 'photo', 'VIDEO' => 'video', 'DOCUMENT' => 'PDF'][$hf] . ' that goes on top (from the media library, or upload one).'];
    } elseif ($hf === 'LOCATION') {
        $lc = is_array($d['extra']['location'] ?? null) ? $d['extra']['location'] : []; $la = trim((string)($lc['lat'] ?? '')); $lo = trim((string)($lc['lng'] ?? ''));
        if (($la !== '' && (!is_numeric($la) || abs((float)$la) > 90)) || ($lo !== '' && (!is_numeric($lo) || abs((float)$lo) > 180))) return [$t, 'The shop location numbers look wrong. Latitude is between -90 and 90, longitude between -180 and 180.'];
        $t['extra']['location'] = ['lat' => $la, 'lng' => $lo, 'name' => mb_substr(trim((string)($lc['name'] ?? '')), 0, 100), 'address' => mb_substr(trim((string)($lc['address'] ?? '')), 0, 200)];
    }
    // ---- message
    if ($t['body'] === '') return [$t, 'The message text is empty.'];
    $max = $isLto ? 600 : 1024;
    if (mb_strlen($t['body']) > $max) return [$t, 'The message is too long (max ' . $max . ' characters' . ($isLto ? ' when it has an offer timer' : '') . ').'];
    $v = wa_tpl_vars($t['body']); foreach ($v as $i => $n) if ($n !== $i + 1) return [$t, 'Variables must be {{1}}, {{2}}, {{3}} in order.'];
    if ($v && (preg_match('/^\s*\{\{/', $t['body']) || preg_match('/\}\}\s*$/', $t['body']))) return [$t, 'Meta does not allow a variable at the very start or the very end of the message — add a word before / after it.'];
    if (preg_match('/\{\{\s*\d+\s*\}\}\s*\{\{\s*\d+\s*\}\}/', $t['body'])) return [$t, 'Two variables cannot sit next to each other — put a word between them.'];
    if (count($t['examples']['body']) < count($v)) return [$t, 'Give an example value for each variable.'];
    foreach (array_slice($t['examples']['body'], 0, count($v)) as $x) if (trim($x) === '') return [$t, 'Give an example value for each variable.'];
    if (wa_tpl_vars($t['footer'])) return [$t, 'The footer cannot have a variable.'];
    [$bt, $berr] = wa_tpl_buttons_clean((array)($d['buttons'] ?? []), $isLto); if ($berr !== '') return [$t, $berr];
    $t['buttons'] = $bt;
    $t['warnings'] = wa_tpl_warnings($t);
    return [$t, ''];
}

/** Things that do not stop the message but make Meta more likely to say no. */
function wa_tpl_warnings(array $t): array
{
    $w = []; $body = (string)($t['body'] ?? ''); $nv = count(wa_tpl_vars($body));
    $words = count(preg_split('/\s+/u', trim(preg_replace('/\{\{\s*\d+\s*\}\}/', ' ', $body)) ?? '', -1, PREG_SPLIT_NO_EMPTY));
    if ($nv > 0 && $nv * 3 > $words) $w[] = 'Too many variables for this much text (' . $nv . ' variable' . ($nv === 1 ? '' : 's') . ' and only ' . $words . ' other words). Meta can reject this — write a few more words around them, or use fewer variables.';
    if (strtoupper((string)($t['category'] ?? '')) === 'UTILITY') {
        $hits = wa_tpl_promo_hits($body . ' ' . (string)($t['header_text'] ?? '') . ' ' . (string)($t['footer'] ?? '')); foreach (wa_tpl_arr($t['buttons'] ?? []) as $b) if (is_array($b)) $hits = array_merge($hits, wa_tpl_promo_hits((string)($b['text'] ?? '')));
        if ($hits) $w[] = 'This is marked Utility but has promotional words (' . implode(', ', array_slice(array_values(array_unique($hits)), 0, 5)) . '). Meta will probably move it to Marketing.';
    }
    $letters = preg_match_all('/\p{L}/u', $body); $upper = preg_match_all('/\p{Lu}/u', $body);
    if ($letters >= 30 && $upper / max(1, $letters) > 0.6) $w[] = 'Too many CAPITAL letters — Meta can see it as shouting. Use normal sentences.';
    return $w;
}

function wa_tpl_row(PDO $pdo, int $id): ?array
{
    $q = $pdo->prepare("SELECT * FROM wa_templates WHERE id = ?"); $q->execute([$id]); $r = $q->fetch(); if (!$r) return null;
    $r['buttons'] = json_decode((string)$r['buttons'], true) ?: []; $r['examples'] = json_decode((string)$r['examples'], true) ?: [];
    $r['lto'] = wa_tpl_arr($r['lto'] ?? ''); $r['extra'] = wa_tpl_arr($r['extra'] ?? ''); $r['header_lib_id'] = (int)($r['header_lib_id'] ?? 0);
    return $r;
}

/** One status for a template across all accounts: approved (everywhere) / pending / rejected / partial (missing somewhere). */
function wa_tpl_status(PDO $pdo, int $id, array $wabas): array
{
    $q = $pdo->prepare("SELECT waba_id, status, reason FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([$id]); $by = []; foreach ($q->fetchAll() as $r) $by[$r['waba_id']] = $r;
    $st = []; $reason = ''; $missing = 0;
    foreach ($wabas as $w) { if (!isset($by[$w])) { $missing++; continue; } $st[] = strtoupper($by[$w]['status']); if ($by[$w]['reason'] !== '' && $by[$w]['reason'] !== 'NONE' && !$reason) $reason = $by[$w]['reason']; }
    if (!$st) return ['status' => 'missing', 'reason' => '', 'missing' => $missing, 'ok_n' => 0, 'total' => count($wabas)];
    $okN = count(array_keys($st, 'APPROVED', true));
    // approved on EVERY account = approved · approved on SOME (others still waiting / rejected / not sent) = partial: usable now from the numbers of the approved accounts
    if ($okN > 0 && $okN === count($st) && !$missing) $s = 'approved'; elseif ($okN > 0) $s = 'partial';
    elseif (in_array('REJECTED', $st, true)) $s = 'rejected'; elseif (in_array('PAUSED', $st, true) || in_array('DISABLED', $st, true)) $s = 'paused'; else $s = 'pending';
    return ['status' => $s, 'reason' => $reason, 'missing' => $missing, 'ok_n' => $okN, 'total' => count($wabas)];
}

// ------------------------------------------------------------------------------------------------------------------------ sending
/** What a template needs when it is sent (besides the body variables). */
function wa_tpl_needs(array $t): array
{
    $btn = wa_tpl_arr($t['buttons'] ?? []);
    $has = function (string $ty, ?callable $f = null) use ($btn) { foreach ($btn as $b) if (is_array($b) && strtoupper((string)($b['type'] ?? '')) === $ty && (!$f || $f($b))) return true; return false; };
    $hf = (string)($t['header_format'] ?? '');
    return ['body_vars' => count(wa_tpl_vars((string)($t['body'] ?? ''))), 'header_var' => $hf === 'TEXT' && (bool)wa_tpl_vars((string)($t['header_text'] ?? '')),
        'coupon' => $has('COPY_CODE'), 'url_suffix' => $has('URL', fn($b) => (bool)preg_match('/\{\{\s*1\s*\}\}/', (string)($b['url'] ?? ''))),
        'location' => $hf === 'LOCATION', 'expires' => wa_tpl_type($t) === 'LTO', 'media' => in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)];
}
/** True when everything the message needs (a photo / video / PDF on top, only button kinds we can fill) exists. */
function wa_tpl_sendable(array $t, ?PDO $pdo = null): bool
{
    $ex = wa_tpl_arr($t['extra'] ?? []); if (!empty($ex['unsupported'])) return false;
    foreach (wa_tpl_arr($t['buttons'] ?? []) as $b) if (is_array($b) && !in_array(strtoupper((string)($b['type'] ?? 'QUICK_REPLY')), ['QUICK_REPLY', 'URL', 'PHONE_NUMBER', 'COPY_CODE'], true)) return false;
    $hf = (string)($t['header_format'] ?? '');
    if (!in_array($hf, ['', 'TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT', 'LOCATION'], true)) return false;
    if (wa_tpl_media_kind($hf) !== '') {
        $lid = (int)($t['header_lib_id'] ?? 0); if ($lid <= 0) return false;
        if ($pdo) { $r = wa_tpl_lib_row($pdo, $lid); if (!$r || (string)$r['kind'] !== wa_tpl_media_kind($hf)) return false; }
    }
    if (wa_tpl_type($t) === 'LTO' && !in_array($hf, ['IMAGE', 'VIDEO'], true)) return false;
    return true;
}
/** True when a salesperson can send it from a chat without typing anything but the body variables (the defaults fill the rest). */
function wa_tpl_chat_ready(array $t, ?PDO $pdo = null): bool
{
    if (!wa_tpl_sendable($t, $pdo)) return false; $n = wa_tpl_needs($t);
    if ($n['url_suffix']) return false;
    if ($n['location']) { $l = wa_tpl_arr(wa_tpl_arr($t['extra'] ?? [])['location'] ?? []); if (!is_numeric($l['lat'] ?? '') || !is_numeric($l['lng'] ?? '')) return false; }
    return true;
}
function wa_tpl_param(string $s, int $max = 1000): string
{
    $s = preg_replace('/[\r\n\t]+/u', ' ', $s) ?? $s; $s = preg_replace('/ {2,}/', ' ', $s) ?? $s; return trim(mb_substr($s, 0, $max));
}
/**
 * The "components" of a send request for one template and one customer.
 *   $vars  = the body variables in order ($vars[0] = {{1}}, usually the first name)
 *   $extra = coupon (copy-code button), url_suffix (web link with {{1}}), header_var (heading variable, default $vars[0]),
 *            lat / lng / name / address (location on top), expires_ms (offer timer end, unix milliseconds; default now + the hours set in the message),
 *            qr_payload (string for every quick reply, or [buttonIndex => string]), fresh_media (true = upload the library file to Meta again)
 * Returns ['ok' => bool, 'components' => array|null, 'error' => string]. Missing values give an error, never a half-built message.
 */
function wa_tpl_send_components(PDO $pdo, array $t, array $vars, string $phoneId, array $extra = []): array
{
    $fail = fn(string $m) => ['ok' => false, 'components' => null, 'error' => $m];
    $t['buttons'] = wa_tpl_arr($t['buttons'] ?? []); $t['lto'] = wa_tpl_arr($t['lto'] ?? []); $t['extra'] = wa_tpl_arr($t['extra'] ?? []);
    $hf = (string)($t['header_format'] ?? ''); $nm = ['IMAGE' => 'photo', 'VIDEO' => 'video', 'DOCUMENT' => 'PDF'];
    if (!wa_tpl_sendable($t)) return $fail(wa_tpl_media_kind($hf) !== '' && (int)($t['header_lib_id'] ?? 0) <= 0
        ? 'This message has a ' . $nm[$hf] . ' on top, but none is chosen yet. Pick it in Pre-made messages.' : 'This kind of message cannot be sent from here yet.');
    $comp = []; $ty = wa_tpl_type($t);
    // ---- header
    if ($hf === 'TEXT' && wa_tpl_vars((string)$t['header_text'])) {
        $hv = wa_tpl_param((string)(($extra['header_var'] ?? '') !== '' ? $extra['header_var'] : ($vars[0] ?? ''))); if ($hv === '') return $fail('The heading variable has no value.');
        $comp[] = ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => $hv]]];
    } elseif (wa_tpl_media_kind($hf) !== '') {
        $k = wa_tpl_media_kind($hf); $row = wa_tpl_lib_row($pdo, (int)$t['header_lib_id']);
        if (!$row) return $fail('The ' . $nm[$hf] . ' chosen for this message is no longer in the media library. Pick another one.');
        if ((string)$row['kind'] !== $k) return $fail('The file chosen for the top of this message is not a ' . $nm[$hf] . '.');
        [$mid, $e] = wa_lib_media_id($pdo, $row, $phoneId, !empty($extra['fresh_media'])); if ($mid === '') return $fail($e !== '' ? $e : 'The file could not be sent to Meta.');
        $o = ['id' => $mid]; if ($k === 'document') $o['filename'] = (string)$row['filename'];
        $comp[] = ['type' => 'header', 'parameters' => [['type' => $k, $k => $o]]];
    } elseif ($hf === 'LOCATION') {
        $dl = wa_tpl_arr($t['extra']['location'] ?? []);
        $la = (string)(($extra['lat'] ?? '') !== '' ? $extra['lat'] : ($dl['lat'] ?? '')); $lo = (string)(($extra['lng'] ?? '') !== '' ? $extra['lng'] : ($dl['lng'] ?? ''));
        if (!is_numeric($la) || !is_numeric($lo) || abs((float)$la) > 90 || abs((float)$lo) > 180) return $fail('This message shows a location, but no valid latitude / longitude is set.');
        $comp[] = ['type' => 'header', 'parameters' => [['type' => 'location', 'location' => ['latitude' => (string)(float)$la, 'longitude' => (string)(float)$lo,
            'name' => wa_tpl_param((string)(($extra['name'] ?? '') !== '' ? $extra['name'] : (($dl['name'] ?? '') !== '' ? $dl['name'] : 'TM Perfume House')), 100), 'address' => wa_tpl_param((string)(($extra['address'] ?? '') !== '' ? $extra['address'] : (($dl['address'] ?? '') !== '' ? $dl['address'] : 'Surat')), 200)]]]];
    }
    // ---- offer timer
    if ($ty === 'LTO') {
        $ms = isset($extra['expires_ms']) ? (int)$extra['expires_ms'] : (time() + max(1, (int)($t['lto']['hours'] ?? 48)) * 3600) * 1000;
        if ($ms <= time() * 1000) return $fail('The offer timer end is already in the past.');
        $comp[] = ['type' => 'limited_time_offer', 'parameters' => [['type' => 'limited_time_offer', 'limited_time_offer' => ['expiration_time_ms' => $ms]]]];
    }
    // ---- body
    $need = count(wa_tpl_vars((string)$t['body']));
    if ($need) {
        $ps = []; for ($i = 0; $i < $need; $i++) { $x = wa_tpl_param((string)($vars[$i] ?? '')); if ($x === '') return $fail('The value for {{' . ($i + 1) . '}} is missing.'); $ps[] = ['type' => 'text', 'text' => $x]; }
        $comp[] = ['type' => 'body', 'parameters' => $ps];
    }
    // ---- buttons (the index is the place of the button in the template)
    foreach (array_values($t['buttons']) as $i => $b) {
        if (!is_array($b)) continue; $bty = strtoupper((string)($b['type'] ?? 'QUICK_REPLY'));
        if ($bty === 'URL' && preg_match('/\{\{\s*1\s*\}\}/', (string)($b['url'] ?? ''))) {
            $s = wa_tpl_param((string)($extra['url_suffix'] ?? ''), 200); if ($s === '') return $fail('The web link button needs its ending (the part that replaces {{1}}).');
            $comp[] = ['type' => 'button', 'sub_type' => 'url', 'index' => (string)$i, 'parameters' => [['type' => 'text', 'text' => $s]]];
        } elseif ($bty === 'COPY_CODE') {
            $code = trim((string)(($extra['coupon'] ?? '') !== '' ? $extra['coupon'] : ($b['example'] ?? ''))); if (!preg_match('/^[A-Za-z0-9]{1,15}$/', $code)) return $fail('The coupon code must be letters and numbers only, up to 15.');
            $comp[] = ['type' => 'button', 'sub_type' => 'copy_code', 'index' => (string)$i, 'parameters' => [['type' => 'coupon_code', 'coupon_code' => $code]]];
        } elseif ($bty === 'QUICK_REPLY' && isset($extra['qr_payload'])) {
            $p = is_array($extra['qr_payload']) ? ($extra['qr_payload'][$i] ?? '') : $extra['qr_payload']; $p = mb_substr(trim((string)$p), 0, 128);
            if ($p !== '') $comp[] = ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => (string)$i, 'parameters' => [['type' => 'payload', 'payload' => $p]]];
        }
    }
    return ['ok' => true, 'components' => $comp ?: null, 'error' => ''];
}
/** The text shown in the chat for a sent template. */
function wa_tpl_shown_text(array $t, array $vars, string $headerVar = ''): string
{
    $pre = ['IMAGE' => "[Photo]\n", 'VIDEO' => "[Video]\n", 'DOCUMENT' => "[PDF]\n", 'LOCATION' => "[Location]\n"][(string)($t['header_format'] ?? '')] ?? '';
    $ht = (string)($t['header_text'] ?? ''); $h = ($t['header_format'] ?? '') === 'TEXT' && $ht !== '' ? wa_tpl_render($ht, [$headerVar !== '' ? $headerVar : ($vars[0] ?? '')]) . "\n" : '';
    return trim($pre . $h . wa_tpl_render((string)$t['body'], $vars) . ((string)($t['footer'] ?? '') !== '' ? "\n" . $t['footer'] : ''));
}

// ------------------------------------------------------------------------------------------------------------------------ header sample for Meta (resumable upload)
/** Streams a file to Meta's upload session. Replaceable in tests. Returns [httpCode, decodedJson|null]. */
if (!function_exists('wa_tpl_upload_bytes')) {
    function wa_tpl_upload_bytes(string $url, array $headers, string $path): array
    {
        $fh = @fopen($path, 'rb'); if (!$fh) return [0, ['error' => ['message' => 'The saved file could not be opened.']]];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($path), CURLOPT_HTTPHEADER => $headers]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch); fclose($fh);
        if ($raw === false) return [0, ['error' => ['message' => 'Could not reach Meta: ' . $err]]];
        return [$code, json_decode((string)$raw, true)];
    }
}
/** Meta wants a sample file when a template has a photo / video / PDF on top. Returns [handle|'' , error]. */
function wa_tpl_header_handle(PDO $pdo, array $t, bool $fresh = false): array
{
    static $cache = [];
    $lid = (int)($t['header_lib_id'] ?? 0); if ($lid <= 0) return ['', 'Pick the photo / video / PDF that goes on top first.'];
    if (!$fresh && isset($cache[$lid])) return [$cache[$lid], ''];
    $app = function_exists('wa_app_id') ? wa_app_id($pdo) : wa_cfg_val('WA_APP_ID');   // config.php first, else found once from the token itself (saved)
    if ($app === '') return ['', 'WA_APP_ID is missing in config.php. Add the line  define(\'WA_APP_ID\', \'123456789012345\');  with your own number: open developers.facebook.com → My Apps → your WhatsApp app → App settings → Basic, and copy the "App ID" shown at the top.'];
    if (wa_cfg_val('WA_TOKEN') === '') return ['', 'WA_TOKEN is missing in config.php.'];
    $r = wa_tpl_lib_row($pdo, $lid); if (!$r) return ['', 'The photo / video / PDF chosen for this message is no longer in the media library.'];
    $path = wa_lib_dir() . '/' . basename((string)$r['path']); if (!is_file($path)) return ['', 'The saved file is missing on the server.'];
    $mime = (string)$r['mime']; $size = (int)filesize($path); $fn = (string)($r['filename'] ?: basename($path));
    $kind = (string)$r['kind']; if (($kind === 'video' && $mime !== 'video/mp4') || ($kind === 'document' && $mime !== 'application/pdf') || !in_array($kind, ['image', 'video', 'document'], true)) return ['', 'Meta takes only JPG / PNG photos, MP4 videos and PDF files on top of a pre-made message.'];
    [$c, $d] = wa_api('POST', '/' . $app . '/uploads?file_length=' . $size . '&file_type=' . rawurlencode($mime) . '&file_name=' . rawurlencode($fn));
    $sid = is_array($d) ? (string)($d['id'] ?? '') : ''; if ($c < 200 || $c >= 300 || $sid === '') return ['', 'Meta did not open an upload for the sample file: ' . wa_err($d)];
    [$c2, $d2] = wa_tpl_upload_bytes('https://graph.facebook.com/' . wa_graph() . '/' . $sid, ['Authorization: OAuth ' . wa_cfg_val('WA_TOKEN'), 'file_offset: 0'], $path);
    $h = is_array($d2) ? (string)($d2['h'] ?? '') : ''; if ($c2 < 200 || $c2 >= 300 || $h === '') return ['', 'Meta did not take the sample file: ' . wa_err($d2)];
    return [$cache[$lid] = $h, ''];
}
/** A saved draft's photo / video / PDF must exist and fit what Meta takes. Returns an error text or ''. */
function wa_tpl_media_check(PDO $pdo, array $t): string
{
    $k = wa_tpl_media_kind((string)($t['header_format'] ?? '')); if ($k === '') return '';
    $r = wa_tpl_lib_row($pdo, (int)($t['header_lib_id'] ?? 0)); $nm = ['image' => 'photo', 'video' => 'video', 'document' => 'PDF'][$k];
    if (!$r) return 'The ' . $nm . ' for the top is not in the media library. Pick it again.';
    if ((string)$r['kind'] !== $k) return 'The file picked for the top is not a ' . $nm . '.';
    if ($k === 'video' && (string)$r['mime'] !== 'video/mp4') return 'Meta takes only MP4 videos on top of a pre-made message.';
    if ($k === 'document' && (string)$r['mime'] !== 'application/pdf') return 'Meta takes only PDF files on top of a pre-made message.';
    return '';
}

// ------------------------------------------------------------------------------------------------------------------------ reading from / writing to Meta
/** Meta's components → our fields (used when pulling templates from Meta). */
function wa_tpl_parse_components(array $comps): array
{
    $o = ['header_format' => '', 'header_text' => '', 'body' => '', 'footer' => '', 'buttons' => [], 'lto' => [], 'ex_body' => [], 'ex_header' => '', 'unsupported' => false];
    foreach ($comps as $cp) {
        if (!is_array($cp)) continue; $ty = strtoupper((string)($cp['type'] ?? ''));
        if ($ty === 'HEADER') { $o['header_format'] = strtoupper((string)($cp['format'] ?? 'TEXT')); $o['header_text'] = (string)($cp['text'] ?? ''); $o['ex_header'] = (string)(($cp['example']['header_text'] ?? [''])[0] ?? ''); }
        elseif ($ty === 'BODY') { $o['body'] = (string)($cp['text'] ?? ''); $o['ex_body'] = array_map('strval', (array)(($cp['example']['body_text'] ?? [[]])[0] ?? [])); }
        elseif ($ty === 'FOOTER') $o['footer'] = (string)($cp['text'] ?? '');
        elseif ($ty === 'LIMITED_TIME_OFFER') { $l = is_array($cp['limited_time_offer'] ?? null) ? $cp['limited_time_offer'] : []; $o['lto'] = ['text' => mb_substr((string)($l['text'] ?? 'Offer'), 0, 16), 'has_expiration' => !empty($l['has_expiration']) || !array_key_exists('has_expiration', $l), 'hours' => 48]; }
        elseif ($ty === 'BUTTONS') {
            foreach ((array)($cp['buttons'] ?? []) as $b) {
                if (!is_array($b)) continue; $bt = strtoupper((string)($b['type'] ?? 'QUICK_REPLY')); $tx = (string)($b['text'] ?? ''); $ex = $b['example'] ?? '';
                if (is_array($ex)) $ex = (string)(reset($ex) ?: ''); $ex = (string)$ex;
                if ($bt === 'URL') {
                    $url = (string)($b['url'] ?? ''); $suffix = '';
                    if ($ex !== '' && ($p = strpos($url, '{{1}}')) !== false) { $pre = substr($url, 0, $p); $suffix = strpos($ex, $pre) === 0 ? substr($ex, strlen($pre)) : $ex; }
                    $o['buttons'][] = ['type' => 'URL', 'text' => $tx, 'url' => $url, 'example' => $suffix];
                } elseif ($bt === 'PHONE_NUMBER') $o['buttons'][] = ['type' => 'PHONE_NUMBER', 'text' => $tx, 'phone' => preg_replace('/\D+/', '', (string)($b['phone_number'] ?? ''))];
                elseif ($bt === 'COPY_CODE') $o['buttons'][] = ['type' => 'COPY_CODE', 'text' => 'Copy code', 'example' => $ex];
                elseif ($bt === 'QUICK_REPLY') $o['buttons'][] = ['type' => 'QUICK_REPLY', 'text' => $tx];
                else { $o['buttons'][] = ['type' => $bt, 'text' => $tx]; $o['unsupported'] = true; }
            }
        } elseif ($ty === 'CAROUSEL') $o['unsupported'] = true;
    }
    return $o;
}

/** Pulls every template of every account from Meta. */
/** Meta does not always tell HRMS when it approves / rejects (no template status webhook): while any message is still waiting, ask Meta ourselves —
 *  at most every $gap seconds. Returns the number of entries read, or -1 when nothing was due. */
function wa_tpl_autosync(PDO $pdo, int $gap = 300): int
{
    try { $w = (int)$pdo->query("SELECT COUNT(*) FROM wa_tpl_waba WHERE status IN ('PENDING', 'IN_APPEAL', 'ERROR', 'PENDING_DELETION')")->fetchColumn(); } catch (Throwable $e) { return -1; }
    if ($w === 0) return -1;
    $last = (int)setting_get($pdo, 'wa_tpl_autosync_at', '0'); if ($last > time() - $gap) return -1; setting_set($pdo, 'wa_tpl_autosync_at', (string)time());
    try { [$n, $err] = wa_tpl_sync($pdo); wa_log($pdo, 'template', !$err, 'Checked ' . $w . ' waiting message(s) with Meta by itself' . ($err ? ' — ' . implode('; ', $err) : '')); return (int)$n; } catch (Throwable $e) { return -1; }
}
function wa_tpl_sync(PDO $pdo): array
{
    $n = 0; $err = []; $now = date('Y-m-d H:i:s');
    foreach (wa_wabas($pdo) as $w) {
        $url = '/' . $w . '/message_templates?limit=100&fields=id,name,language,status,category,components,rejected_reason,quality_score'; $seen = [];
        for ($page = 0; $page < 10 && $url; $page++) {
            [$c, $d] = wa_api('GET', $url);
            if ($c < 200 || $c >= 300 || !is_array($d)) { $err[] = 'Account ' . $w . ': ' . wa_err($d); break; }
            foreach ((array)($d['data'] ?? []) as $t) {
                $name = (string)($t['name'] ?? ''); $lang = (string)($t['language'] ?? 'en'); if ($name === '') continue;
                $p = wa_tpl_parse_components((array)($t['components'] ?? [])); $cat = strtoupper((string)($t['category'] ?? 'MARKETING'));
                $btj = json_encode($p['buttons'], JSON_UNESCAPED_UNICODE); $ltoj = $p['lto'] ? json_encode($p['lto'], JSON_UNESCAPED_UNICODE) : null;
                $q = $pdo->prepare("SELECT id, examples, extra, category, relabelled_at FROM wa_templates WHERE name = ? AND language = ?"); $q->execute([$name, $lang]); $old = $q->fetch(); $id = $old ? (int)$old['id'] : 0;
                if (!$id) {
                    $ex = ['body' => $p['ex_body'], 'header' => $p['ex_header'], 'labels' => []]; $xt = $p['unsupported'] ? ['unsupported' => true] : [];
                    $pdo->prepare("INSERT INTO wa_templates (name, language, category, header_format, header_text, body, footer, buttons, lto, examples, extra, source, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'meta', ?)")
                        ->execute([$name, $lang, $cat, $p['header_format'], mb_substr($p['header_text'], 0, 80), $p['body'], mb_substr($p['footer'], 0, 80), $btj, $ltoj, json_encode($ex, JSON_UNESCAPED_UNICODE), $xt ? json_encode($xt) : null, $now]);
                    $id = (int)$pdo->lastInsertId();
                } else {
                    $ex = wa_tpl_arr($old['examples']); if (empty($ex['body']) && $p['ex_body']) { $ex['body'] = $p['ex_body']; } if (empty($ex['header']) && $p['ex_header'] !== '') $ex['header'] = $p['ex_header'];
                    $xt = wa_tpl_arr($old['extra']); if ($p['unsupported']) $xt['unsupported'] = true; else unset($xt['unsupported']);
                    $pdo->prepare("UPDATE wa_templates SET category = ?, header_format = ?, header_text = ?, body = ?, footer = ?, buttons = ?, lto = ?, examples = ?, extra = ? WHERE id = ?")
                        ->execute([$cat, $p['header_format'], mb_substr($p['header_text'], 0, 80), $p['body'], mb_substr($p['footer'], 0, 80), $btj, $ltoj, json_encode($ex, JSON_UNESCAPED_UNICODE), $xt ? json_encode($xt) : null, $id]);
                }
                $pdo->prepare("DELETE FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?")->execute([$id, $w]);
                $qs = strtoupper((string)(is_array($t['quality_score'] ?? null) ? ($t['quality_score']['score'] ?? '') : ($t['quality_score'] ?? ''))); if (!in_array($qs, ['GREEN', 'YELLOW', 'RED'], true)) $qs = '';
                $pdo->prepare("INSERT INTO wa_tpl_waba (tpl_id, waba_id, meta_id, status, reason, updated_at, category, quality) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$id, $w, (string)($t['id'] ?? ''), strtoupper((string)($t['status'] ?? 'PENDING')), mb_substr((string)($t['rejected_reason'] ?? ''), 0, 300), $now, $cat, $qs]);
                if ($old && strtoupper((string)$old['category']) === 'UTILITY' && $cat !== 'UTILITY' && $cat !== '') wa_tpl_flag_relabel($pdo, $id, $cat, 'sync');   // it was a Utility message and Meta now says something else
                $seen[$id] = true; $n++;
            }
            $next = (string)($d['paging']['next'] ?? ''); $url = $next !== '' ? preg_replace('#^https://graph\.facebook\.com/[^/]+#', '', $next) : '';
        }
    }
    return [$n, $err];
}

/** A reason that passes (network, Meta busy, rate limit): the submission is tried again later instead of being written off as rejected. */
function wa_tpl_transient(int $http, array $d, string $why): bool
{
    $code = (int)($d['error']['code'] ?? 0);
    return $http === 0 || $http === 429 || $http >= 500 || in_array($code, [1, 2, 4, 17, 32, 341, 613, 80004, 80008, 130429, 131000, 131016], true) || (bool)preg_match('/could not reach|no answer|timed? ?out|temporar|try again later|rate limit|too many/i', $why);
}
/** Creates a template (that is saved in wa_templates) in one account at Meta. Returns [ok, message].
 *  A failure for a passing reason is saved as status ERROR (with a back-off time, see wa_tpl_autosubmit_tick), a real "no" as REJECTED. Every attempt is logged in wa_tpl_subs. */
function wa_tpl_create_at(PDO $pdo, array $t, string $waba): array
{
    $now = date('Y-m-d H:i:s'); $media = wa_tpl_media_kind((string)($t['header_format'] ?? '')) !== ''; $why = ''; $http = 0; $d = [];
    for ($try = 0; $try < 2; $try++) {
        if ($media) {
            [$h, $he] = wa_tpl_header_handle($pdo, $t, $try > 0);
            if ($h === '') { $why = $he; break; }
            $t['header_handle'] = $h;
        }
        [$c, $d] = wa_api('POST', '/' . $waba . '/message_templates', ['name' => $t['name'], 'language' => $t['language'], 'category' => $t['category'], 'components' => wa_tpl_components($t)]); $http = (int)$c; $d = is_array($d) ? $d : [];
        if ($c >= 200 && $c < 300 && !empty($d['id'])) {
            $cat = strtoupper((string)($d['category'] ?? $t['category']));
            $pdo->prepare("DELETE FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?")->execute([$t['id'], $waba]);
            $pdo->prepare("INSERT INTO wa_tpl_waba (tpl_id, waba_id, meta_id, status, reason, updated_at, category) VALUES (?, ?, ?, ?, '', ?, ?)")->execute([$t['id'], $waba, (string)$d['id'], strtoupper((string)($d['status'] ?? 'PENDING')), $now, $cat]);
            wa_tpl_sublog($pdo, (int)$t['id'], $waba, true, '');
            if (strtoupper((string)$t['category']) === 'UTILITY' && $cat !== '' && $cat !== 'UTILITY') wa_tpl_flag_relabel($pdo, (int)$t['id'], $cat, 'birth');   // Meta made it Marketing straight away
            return [true, ''];
        }
        $why = trim((string)($d['error']['error_user_msg'] ?? '') . ' ' . (string)($d['error']['error_user_title'] ?? '')) ?: wa_err($d);
        if (!($media && $try === 0 && preg_match('/handle|sample|example|upload|expired|media/i', $why))) break;   // only a sample-file problem is tried once more with a fresh upload
    }
    $tries = 0; try { $o = $pdo->prepare("SELECT tries FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $o->execute([$t['id'], $waba]); $tries = (int)$o->fetchColumn(); } catch (PDOException $e) { }
    $pdo->prepare("DELETE FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?")->execute([$t['id'], $waba]);
    if (wa_tpl_transient($http, $d, $why)) {   // passing reason: ask again later (5 min, 10, 20 ... up to 6 h; 6 tries)
        $tries++; $wait = min(21600, 300 * (2 ** ($tries - 1)));
        $pdo->prepare("INSERT INTO wa_tpl_waba (tpl_id, waba_id, meta_id, status, reason, updated_at, tries, next_try) VALUES (?, ?, '', 'ERROR', ?, ?, ?, ?)")->execute([$t['id'], $waba, mb_substr($why, 0, 300), $now, $tries, date('Y-m-d H:i:s', (wa_mix_now()) + $wait)]);
    } else $pdo->prepare("INSERT INTO wa_tpl_waba (tpl_id, waba_id, meta_id, status, reason, updated_at) VALUES (?, ?, '', 'REJECTED', ?, ?)")->execute([$t['id'], $waba, mb_substr($why, 0, 300), $now]);
    wa_tpl_sublog($pdo, (int)$t['id'], $waba, false, $why);
    return [false, $why];
}
function wa_tpl_sublog(PDO $pdo, int $tplId, string $waba, bool $ok, string $note): void
{
    try { wa_mix_tables($pdo); $pdo->prepare("INSERT INTO wa_tpl_subs (at, tpl_id, waba_id, ok, note) VALUES (?, ?, ?, ?, ?)")->execute([date('Y-m-d H:i:s', wa_mix_now()), $tplId, $waba, $ok ? 1 : 0, mb_substr($note, 0, 220)]);
          $pdo->exec("DELETE FROM wa_tpl_subs WHERE id < (SELECT m FROM (SELECT MAX(id) - 5000 AS m FROM wa_tpl_subs) t)"); } catch (Throwable $e) { }
}
/**
 * Meta now treats this message as another category (a Utility message became Marketing). Marks it, stops its use as Utility at once and asks the engine for a stricter version.
 * Safe to call again and again (the second call changes nothing). Returns true when it was newly marked.
 */
function wa_tpl_flag_relabel(PDO $pdo, int $id, string $newCat, string $src): bool
{
    $q = $pdo->prepare("SELECT name, relabelled_at, category FROM wa_templates WHERE id = ?"); $q->execute([$id]); $r = $q->fetch(); if (!$r) return false; $newCat = strtoupper($newCat);
    if ($r['relabelled_at'] !== null && (string)$r['relabelled_at'] !== '') { if ((string)$r['category'] !== $newCat) $pdo->prepare("UPDATE wa_templates SET category_now = ?, category = ? WHERE id = ?")->execute([$newCat, $newCat, $id]); return false; }
    $pdo->prepare("UPDATE wa_templates SET relabelled_at = ?, category_now = ?, category = ? WHERE id = ? AND relabelled_at IS NULL")->execute([date('Y-m-d H:i:s', wa_mix_now()), $newCat, $newCat, $id]);
    wa_log($pdo, 'template', false, 'Meta re-labelled ' . $r['name'] . ' as ' . $newCat . ' (' . $src . ')');
    try { wa_mix_on_relabel($pdo, $id); } catch (Throwable $e) { }
    wa_tpl_after_event($pdo); return true;
}
/** After a Meta event that may need a new version: make it right after the webhook has answered Meta (never inside Meta's request); the minute tick also does it. */
function wa_tpl_after_event(PDO $pdo): void
{
    static $set = false; if ($set) return; $set = true;
    register_shutdown_function(function () use ($pdo) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        @ignore_user_abort(true); @set_time_limit(60);
        try { wa_tpl_autosubmit_tick($pdo, true); } catch (Throwable $e) { }
    });
}

/** Meta tells us a template was approved / rejected. */
function wa_tpl_status_event(PDO $pdo, array $v): void
{
    $mid = (string)($v['message_template_id'] ?? ''); $ev = strtoupper((string)($v['event'] ?? '')); if ($mid === '' || $ev === '') return;
    $reason = (string)($v['reason'] ?? ''); if ($reason === 'NONE') $reason = '';
    $pdo->prepare("UPDATE wa_tpl_waba SET status = ?, reason = ?, updated_at = ? WHERE meta_id = ?")->execute([$ev, mb_substr($reason, 0, 300), date('Y-m-d H:i:s'), $mid]);
    wa_log($pdo, 'template', $ev !== 'REJECTED', 'Template ' . (string)($v['message_template_name'] ?? $mid) . ' → ' . $ev . ($reason !== '' ? ' (' . $reason . ')' : ''));
    foreach (['new_category', 'message_template_category', 'category'] as $k) if (!empty($v[$k]) && is_string($v[$k])) { wa_tpl_category_event($pdo, ['message_template_id' => $mid, 'new_category' => $v[$k]]); break; }   // some payloads carry the category with the status
    if (in_array($ev, ['REJECTED', 'PAUSED', 'DISABLED', 'FLAGGED'], true)) wa_tpl_after_event($pdo);   // trouble: an alternative version is made
}
/** Meta's "template_category_update" event: {message_template_id, message_template_name, previous_category, new_category, (correct_category)}. A Utility message that becomes Marketing is marked at once. */
function wa_tpl_category_event(PDO $pdo, array $v): void
{
    $mid = (string)($v['message_template_id'] ?? ''); $new = strtoupper((string)($v['new_category'] ?? $v['correct_category'] ?? '')); if ($mid === '' || $new === '') return;
    $pdo->prepare("UPDATE wa_tpl_waba SET category = ?, updated_at = ? WHERE meta_id = ?")->execute([$new, date('Y-m-d H:i:s'), $mid]);
    $q = $pdo->prepare("SELECT tpl_id FROM wa_tpl_waba WHERE meta_id = ?"); $q->execute([$mid]); $id = (int)$q->fetchColumn();
    if (!$id && !empty($v['message_template_name'])) { $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? LIMIT 1"); $q->execute([(string)$v['message_template_name']]); $id = (int)$q->fetchColumn(); }
    if (!$id) return;
    $c = $pdo->prepare("SELECT category FROM wa_templates WHERE id = ?"); $c->execute([$id]); $was = strtoupper((string)$c->fetchColumn());
    if ($was === 'UTILITY' && $new !== 'UTILITY') wa_tpl_flag_relabel($pdo, $id, $new, 'Meta event');
}
/** Meta's "message_template_quality_update": {message_template_id, previous_quality_score, new_quality_score}. */
function wa_tpl_quality_event(PDO $pdo, array $v): void
{
    $mid = (string)($v['message_template_id'] ?? ''); $new = strtoupper((string)($v['new_quality_score'] ?? '')); if ($mid === '' || $new === '') return; if (!in_array($new, ['GREEN', 'YELLOW', 'RED'], true)) $new = '';
    $pdo->prepare("UPDATE wa_tpl_waba SET quality = ?, updated_at = ? WHERE meta_id = ?")->execute([$new, date('Y-m-d H:i:s'), $mid]);
    wa_log($pdo, 'template', $new !== 'RED', 'Template quality of ' . (string)($v['message_template_name'] ?? $mid) . ' → ' . ($new ?: 'unknown'));
    if (in_array($new, ['YELLOW', 'RED'], true)) wa_tpl_after_event($pdo);
}

// ------------------------------------------------------------------------------------------------------------------------ saving
/** Saves a clean draft as a new row (a free name is found when the name is taken). Returns the new id. */
function wa_tpl_insert(PDO $pdo, array $t, int $by, string $purpose = '', string $understood = ''): int
{
    $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = ?"); $q->execute([$t['name'], $t['language']]);
    if ($q->fetchColumn()) { $t['name'] = substr($t['name'], 0, 52) . '_' . date('md') . substr((string)mt_rand(100, 999), 0, 3); }
    $fol = $t['folder'] !== '' ? $t['folder'] : wa_tpl_folder_guess($t + ['purpose' => $purpose]);
    $pdo->prepare("INSERT INTO wa_templates (name, language, category, header_format, header_text, header_lib_id, body, footer, buttons, lto, examples, extra, folder, purpose, understood, source, created_by, created_at, family_id, version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'hrms', ?, ?, ?, ?)")
        ->execute([$t['name'], $t['language'], $t['category'], $t['header_format'], $t['header_text'], (int)$t['header_lib_id'], $t['body'], $t['footer'], json_encode($t['buttons'], JSON_UNESCAPED_UNICODE),
                   $t['lto'] ? json_encode($t['lto'], JSON_UNESCAPED_UNICODE) : null, json_encode($t['examples'], JSON_UNESCAPED_UNICODE), $t['extra'] ? json_encode($t['extra'], JSON_UNESCAPED_UNICODE) : null,
                   $fol, mb_substr($purpose, 0, 600), mb_substr($understood, 0, 900), $by, date('Y-m-d H:i:s'), (int)($t['family_id'] ?? 0), max(1, (int)($t['version'] ?? 1))]);
    $nid = (int)$pdo->lastInsertId(); if ($nid && (int)($t['family_id'] ?? 0) === 0) $pdo->prepare("UPDATE wa_templates SET family_id = ? WHERE id = ?")->execute([$nid, $nid]);   // a new message starts its own family
    return $nid;
}

/** System messages (complaint tickets, follow-up offers) carry 'auto' => true, so the people's "pre-made messages" pickers leave them out (the system sends them itself).
 *  Each complaint message keeps only its newest version and its newest approved one (text and photo separately); older versions are dropped from the list unless $all. */
function wa_tpl_mark_auto(array $out, bool $all = false): array
{
    $isAuto = function (string $n): bool { return (bool)preg_match('/^tmph_(cx_|cxp_|ticket_|complaint_|fu_)/', $n); };
    $grp = []; foreach ($out as $i => $t) { $n = (string)$t['name']; $out[$i]['auto'] = $isAuto($n); if (!$out[$i]['auto'] || strpos($n, 'tmph_fu_') === 0) continue;
        $base = preg_replace('/_v\d+$/', '', $n) . '|' . strtoupper((string)$t['header_format']); $v = preg_match('/_v(\d+)$/', $n, $m) ? (int)$m[1] : 1; $grp[$base][] = [$i, $v, in_array($t['status'], ['approved', 'partial'], true)]; }
    $drop = [];
    foreach ($grp as $L) { usort($L, function ($a, $b) { return $b[1] <=> $a[1]; }); $keep = [$L[0][0] => 1]; foreach ($L as $x) if ($x[2]) { $keep[$x[0]] = 1; break; }
        foreach ($L as $x) if (!isset($keep[$x[0]])) { $out[$x[0]]['old'] = true; $drop[$x[0]] = 1; } }
    if ($all) return $out;
    return array_values(array_filter($out, function ($t, $i) use ($drop) { return !isset($drop[$i]); }, ARRAY_FILTER_USE_BOTH));
}
function wa_tpl_public(PDO $pdo, array $r, array $wabas): array
{
    $s = wa_tpl_status($pdo, (int)$r['id'], $wabas);
    $b = (string)$r['body']; $r['lto'] = wa_tpl_arr($r['lto'] ?? ''); $r['extra'] = wa_tpl_arr($r['extra'] ?? ''); $ex = wa_tpl_arr($r['examples'] ?? ''); $r['buttons'] = wa_tpl_arr($r['buttons'] ?? '');
    $lid = (int)($r['header_lib_id'] ?? 0); $needs = wa_tpl_needs($r);
    return ['id' => (int)$r['id'], 'name' => $r['name'], 'language' => $r['language'], 'category' => $r['category'], 'header_format' => $r['header_format'], 'header_text' => $r['header_text'], 'body' => $b, 'footer' => $r['footer'],
        'buttons' => $r['buttons'], 'vars' => count(wa_tpl_vars($b)), 'header_var' => (bool)wa_tpl_vars((string)$r['header_text']), 'star' => (int)$r['star'] === 1, 'used' => (int)$r['used'], 'status' => $s['status'], 'reason' => $s['reason'], 'missing' => $s['missing'], 'ok_n' => $s['ok_n'] ?? 0, 'accounts' => $s['total'] ?? 0,
        'media_header' => in_array($r['header_format'], ['IMAGE', 'VIDEO', 'DOCUMENT'], true), 'purpose' => $r['purpose'], 'understood' => $r['understood'],
        'type' => wa_tpl_type($r), 'header_lib_id' => $lid, 'folder' => (string)($r['folder'] ?? ''), 'lto' => $r['lto'], 'location' => wa_tpl_arr($r['extra']['location'] ?? []),
        'var_examples' => array_values(array_map('strval', (array)($ex['body'] ?? []))), 'var_labels' => array_values(array_map('strval', (array)($ex['labels'] ?? []))), 'header_example' => (string)($ex['header'] ?? ''),
        'needs' => $needs, 'sendable' => wa_tpl_sendable($r), 'chat_ok' => wa_tpl_chat_ready($r), 'unsupported' => !empty($r['extra']['unsupported']), 'source' => (string)$r['source'],
        'draft' => $s['status'] === 'missing' && (string)$r['source'] === 'hrms', 'utility_of' => (int)($r['extra']['utility_of'] ?? 0), 'utility_id' => (int)($r['extra']['utility_id'] ?? 0)];
}

// ------------------------------------------------------------------------------------------------------------------------ Claude: the whole form from one sentence
/** The draft shape Claude must return (used by both "understand" and "suggest"). */
function wa_tpl_draft_schema(): array
{
    return ['type' => 'OBJECT', 'properties' => [
        'name' => ['type' => 'STRING'], 'category' => ['type' => 'STRING', 'enum' => ['MARKETING', 'UTILITY']], 'language' => ['type' => 'STRING', 'enum' => ['en', 'hi', 'gu']],
        'header_text' => ['type' => 'STRING'], 'body' => ['type' => 'STRING'], 'footer' => ['type' => 'STRING'],
        'buttons' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['text' => ['type' => 'STRING']], 'required' => ['text']]],
        'variable_examples' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'why' => ['type' => 'STRING']], 'required' => ['name', 'category', 'language', 'body', 'variable_examples', 'why']];
}
/** The richer shape used by the new form: what goes on top, the kind of every button, a label for every variable. */
function wa_tpl_draft_schema_full(): array
{
    $s = wa_tpl_draft_schema();
    $s['properties']['header_type'] = ['type' => 'STRING', 'enum' => ['NONE', 'IMAGE', 'VIDEO', 'DOCUMENT', 'LOCATION', 'LTO']];
    $s['properties']['offer_timer_text'] = ['type' => 'STRING'];
    $s['properties']['variable_labels'] = ['type' => 'ARRAY', 'items' => ['type' => 'STRING']];
    $s['properties']['buttons'] = ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['text' => ['type' => 'STRING'], 'type' => ['type' => 'STRING', 'enum' => ['QUICK_REPLY', 'URL', 'PHONE_NUMBER', 'COPY_CODE']], 'url' => ['type' => 'STRING'], 'example' => ['type' => 'STRING'], 'phone' => ['type' => 'STRING']], 'required' => ['text', 'type']]];
    return $s;
}
if (!defined('WA_TPL_RULES')) define('WA_TPL_RULES', "RULES for a WhatsApp template: write in HINGLISH (Hindi in English letters, like a friendly Surat perfume shop) with language code 'en', unless the owner clearly asks for Gujarati ('gu') or Hindi script ('hi'). Keep it short, warm, 1–3 emojis at most. Use {{1}} for the customer's first name where natural (never at the very start or end of the text), and add one example value per variable. Do NOT invent prices, discounts, dates or links — use only what the owner wrote; if a number is needed, leave it out or make it a variable. category = MARKETING for offers / reminders / re-engagement, UTILITY only for order / delivery / payment updates. Buttons: at most 3 short quick-reply buttons (max 25 characters), only when they help the customer answer. name = lowercase_with_underscores, 3–5 words. Never use words that make Meta reject it: no ALL CAPS shouting, no 'free money', no threats, no asking for card / OTP / password.");
if (!defined('WA_TPL_RULES_FORM')) define('WA_TPL_RULES_FORM', "FORM RULES: header_type = IMAGE when the owner mentions a photo / picture / poster / bottle photo, VIDEO for a video, DOCUMENT for a PDF / catalogue / price-list file, LOCATION for the shop address / map, LTO when he wants a countdown / offer timer / 'offer ends' (then also set offer_timer_text, at most 16 letters), otherwise NONE (he picks the actual file himself). Buttons may now be of type QUICK_REPLY, URL (url must start with https:// — use https://tmperfumehouse.com unless he gave a link; example = the ending of the link only if the link ends in {{1}}), PHONE_NUMBER (only when he gave the number), COPY_CODE (example = a sample code of letters and numbers, up to 15, only when a coupon is wanted; never write a % or an amount in the message, say 'your code'). At most 3 buttons unless he asks for more. variable_labels = one short label per variable: Name, Perfume, Coupon, Date or Other. Variables must be in order {{1}}, {{2}} … and have words around them: at least 3 other words per variable.");
/** Claude's answer → a draft in our shape (not yet checked). */
function wa_tpl_from_ai(array $v): array
{
    $d = $v; $ht = strtoupper((string)($v['header_type'] ?? 'NONE')); unset($d['header_type']);
    if ($ht === 'LTO') { $d['header_format'] = 'IMAGE'; $d['lto'] = ['text' => (string)($v['offer_timer_text'] ?? '') ?: 'Offer ends soon', 'hours' => 48]; }
    elseif (in_array($ht, ['IMAGE', 'VIDEO', 'DOCUMENT', 'LOCATION'], true)) $d['header_format'] = $ht;
    else $d['header_format'] = '';
    if ($d['header_format'] === '' && (string)($v['header_text'] ?? '') !== '') $d['header_format'] = 'TEXT';
    $d['examples'] = ['body' => (array)($v['variable_examples'] ?? []), 'header' => '', 'labels' => (array)($v['variable_labels'] ?? [])];
    // keep only the buttons that are valid (and allowed with an offer timer); a wrong guess must not blank the whole form
    $keep = []; foreach ((array)($v['buttons'] ?? []) as $b) { if (!is_array($b)) continue; [$one, $e] = wa_tpl_buttons_clean([$b], $ht === 'LTO'); if ($e === '' && $one) $keep[] = $b; }
    [$all, $e] = wa_tpl_buttons_clean($keep, $ht === 'LTO'); if ($e !== '') $keep = array_slice($keep, 0, 3);
    $d['buttons'] = $keep;
    return $d;
}

// ------------------------------------------------------------------------------------------------------------------------ the Utility version
function wa_tpl_utility_tips(): array
{
    return ['Send this only to people who really asked about a perfume lately — that is the customer action Meta looks for.', 'Keep prices and offers out of the message. Put the price list on your website and open it with the button.',
            'Use the real perfume name and date in the variables, never a general word like "offer".', 'Meta decides the category by itself and may still change it — this only gives a better chance.'];
}
/** A rule-based Utility rewrite of a Marketing draft (used when the AI cannot answer). Returns ['draft', 'changes', 'tips']. */
function wa_tpl_utility_rules(array $t, int $strict = 0): array
{
    $ex = (array)($t['examples']['body'] ?? []); $lab = (array)($t['examples']['labels'] ?? []);
    $pick = function (string $want, string $dflt) use ($lab, $ex) { foreach ($lab as $i => $l) if (stripos((string)$l, $want) !== false && isset($ex[$i]) && trim((string)$ex[$i]) !== '') return (string)$ex[$i]; return $dflt; };
    $name = $pick('name', (!$lab && isset($ex[0]) && trim((string)$ex[0]) !== '') ? (string)$ex[0] : 'Rakesh'); $perfume = $pick('perfume', 'Royal Oud'); $date = $pick('date', '12 Nov');
    $lang = (string)($t['language'] ?? 'en');
    if ($lang === 'hi') $body = 'नमस्ते {{1}}, आपने {{3}} को {{2}} के बारे में पूछा था। आपने जो जानकारी मांगी थी वह नीचे दिए बटन में है। कोई मदद चाहिए तो इस मैसेज का जवाब दें।';
    elseif ($lang === 'gu') $body = 'નમસ્તે {{1}}, તમે {{3}} ના રોજ {{2}} વિશે પૂછ્યું હતું. તમે માંગેલી વિગતો નીચેના બટનમાં છે. મદદ જોઈએ તો આ મેસેજનો જવાબ આપો.';
    else $body = 'Hi {{1}}, aapne {{3}} ko {{2}} ke baare mein poocha tha. Jo details aapne maangi thi woh neeche button mein hai. Koi madad chahiye to is message ka reply karein.';
    if ($strict >= 1) {   // stricter versions (after Meta re-labelled the one before): shorter, only about the customer's own request, one plain reply button, nothing else
        $S = ['en' => [1 => 'Hi {{1}}, aapki {{3}} ki request ke jawab mein: aapne {{2}} ke baare mein jaankari maangi thi. Jaankari chahiye to is message ka reply karein.', 2 => 'Hi {{1}}, yeh aapki {{3}} ki request {{2}} ka jawab hai. Aur jaankari ke liye reply karein.'],
              'hi' => [1 => 'नमस्ते {{1}}, आपकी {{3}} की रिक्वेस्ट के जवाब में: आपने {{2}} के बारे में जानकारी मांगी थी। जानकारी चाहिए तो इस मैसेज का जवाब दें।', 2 => 'नमस्ते {{1}}, यह आपकी {{3}} की रिक्वेस्ट {{2}} का जवाब है। और जानकारी के लिए जवाब दें।'],
              'gu' => [1 => 'નમસ્તે {{1}}, તમારી {{3}} ની વિનંતીના જવાબમાં: તમે {{2}} વિશે માહિતી માંગી હતી. માહિતી જોઈએ તો આ મેસેજનો જવાબ આપો.', 2 => 'નમસ્તે {{1}}, આ તમારી {{3}} ની વિનંતી {{2}} નો જવાબ છે. વધુ માહિતી માટે જવાબ આપો.']];
        $body = $S[isset($S[$lang]) ? $lang : 'en'][min(2, $strict)];
    }
    $btn = []; $changes = []; $why = 'Promotional wording — Meta only keeps a message as Utility when it answers something the customer did.';
    $orig = (string)($t['header_text'] ?? '') . "\n" . (string)$t['body'] . "\n" . (string)($t['footer'] ?? '');
    foreach (wa_tpl_arr($t['buttons'] ?? []) as $b) {
        if (!is_array($b)) continue; $ty = strtoupper((string)($b['type'] ?? 'QUICK_REPLY')); $tx = (string)($b['text'] ?? ''); $orig .= "\n" . $tx; $promo = wa_tpl_promo_hits($tx);
        if ($ty === 'COPY_CODE') { $changes[] = ['from' => 'Copy coupon button', 'to' => '(removed)', 'why' => 'A coupon is a promotion.']; continue; }
        if ($ty === 'URL') { $b['text'] = ($promo || $tx === '') ? 'View details' : $tx; if ($promo) $changes[] = ['from' => $tx, 'to' => $b['text'], 'why' => $why]; $btn[] = $b; }
        elseif ($ty === 'PHONE_NUMBER') $btn[] = $b;
        else { $b['text'] = $promo ? 'Send me details' : $tx; if ($promo) $changes[] = ['from' => $tx, 'to' => $b['text'], 'why' => $why]; $btn[] = $b; }
    }
    if ($strict >= 1) { $btn = [['type' => 'QUICK_REPLY', 'text' => 'Send me details']]; $changes[] = ['from' => 'Buttons and footer', 'to' => 'One plain reply button', 'why' => 'The version before was re-labelled as Marketing, so this one is stricter.']; }
    $hits = wa_tpl_promo_hits($orig); foreach (array_reverse(array_slice($hits, 0, 6)) as $h) array_unshift($changes, ['from' => $h, 'to' => '(removed)', 'why' => $why]);
    if (trim((string)(wa_tpl_arr($t['lto'] ?? [])['text'] ?? '')) !== '') $changes[] = ['from' => 'Offer timer: ' . wa_tpl_arr($t['lto'])['text'], 'to' => '(removed)', 'why' => 'A countdown is a promotion.'];
    $changes[] = ['from' => 'The whole message', 'to' => 'Tied to what the customer asked: the perfume ({{2}}) and the day ({{3}})', 'why' => 'Utility messages answer a customer action.'];
    $u = ['name' => substr((string)$t['name'], 0, 57) . '_u', 'language' => $lang, 'category' => 'UTILITY', 'header_format' => in_array((string)($t['header_format'] ?? ''), ['IMAGE', 'VIDEO', 'DOCUMENT', 'LOCATION'], true) ? (string)$t['header_format'] : '', 'header_text' => '', 'header_lib_id' => (int)($t['header_lib_id'] ?? 0), 'body' => $body,
        'footer' => ($strict < 1 && (string)($t['footer'] ?? '') !== '' && !wa_tpl_promo_hits((string)$t['footer'])) ? (string)$t['footer'] : '', 'buttons' => $btn, 'folder' => (string)($t['folder'] ?? ''), 'extra' => wa_tpl_arr($t['extra'] ?? []),
        'examples' => ['body' => [$name, $perfume, $date], 'header' => '', 'labels' => ['Name', 'Perfume', 'Date']]];
    if ($strict < 1 && (string)($t['header_format'] ?? '') === 'TEXT' && (string)$t['header_text'] !== '' && !wa_tpl_promo_hits((string)$t['header_text']) && !wa_tpl_vars((string)$t['header_text'])) { $u['header_format'] = 'TEXT'; $u['header_text'] = (string)$t['header_text']; }
    if ($strict >= 2) { $u['header_format'] = ''; $u['header_text'] = ''; $u['header_lib_id'] = 0; }   // the last tries: no photo on top either
    return ['draft' => $u, 'changes' => array_slice($changes, 0, 10), 'tips' => wa_tpl_utility_tips()];
}
/** The Utility version of a clean Marketing draft: Claude first, the rules when Claude cannot. Returns ['draft', 'changes', 'tips', 'source']. */
function wa_tpl_utility_build(array $t, int $strict = 0): array
{
    $rules = wa_tpl_utility_rules($t, $strict); $orig = (string)($t['header_text'] ?? '') . "\n" . (string)$t['body'] . "\n" . (string)($t['footer'] ?? '');
    $sch = ['type' => 'OBJECT', 'properties' => ['body' => ['type' => 'STRING'], 'footer' => ['type' => 'STRING'], 'quick_replies' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'variable_examples' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'variable_labels' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        'changes' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['from' => ['type' => 'STRING'], 'to' => ['type' => 'STRING'], 'why' => ['type' => 'STRING']], 'required' => ['from', 'to', 'why']]], 'tips' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['body', 'variable_examples', 'variable_labels', 'changes', 'tips']];
    $sys = "You rewrite ONE WhatsApp message template of a perfume shop (TM Perfume House, Surat) so that it has a better chance to be accepted by Meta as a UTILITY template instead of MARKETING. Utility = a message about something the customer did or asked for. Rewrite the SAME message: remove every promotional word (offer, discount, % off, sale, buy now, order now, limited, hurry, free, coupon, exclusive, deal, special …); tie it to a customer action ('you asked about {{2}} on {{3}}', 'as you requested'); move the details that change into variables ({{1}} first name, {{2}} perfume, {{3}} date); keep the same language and friendly Hinglish tone (Hindi in English letters) unless the original is Hindi / Gujarati script; keep it short; no prices or offers. Variables must be in order, have at least 3 other words per variable, and never sit at the very start or the very end of the text. Give one example and one label (Name, Perfume, Date, Order or Other) per variable. quick_replies = at most 2 short neutral button texts (max 25 letters) or none. 'changes' = 3 to 6 items {from: the exact original words that were changed or removed, to: what replaced them (or '(removed)'), why: one short plain-English reason}. 'tips' = 2 to 4 short tips for the staff. Customer-facing text must never contain real designer brand names. " . ($strict > 0 ? "STRICTNESS LEVEL " . $strict . " of 2: an earlier utility version of this message was re-labelled as MARKETING by Meta. Make this one even more plainly transactional: one or two short sentences that only answer the customer's own request ('you asked about {{2}} on {{3}}'), no emoji, no adjectives about the product, no invitation to buy or visit, at most one plain reply button. " : "") . WA_TPL_RULES;
    [$ok, $v] = wa_tpl_model($sys, "THE MARKETING MESSAGE (language code " . $t['language'] . "):\n" . json_encode(['header' => (string)($t['header_text'] ?? ''), 'body' => $t['body'], 'footer' => (string)($t['footer'] ?? ''), 'buttons' => array_map(fn($b) => (string)($b['text'] ?? ''), (array)($t['buttons'] ?? [])), 'variable_examples' => $t['examples']['body'] ?? [], 'variable_labels' => $t['examples']['labels'] ?? []], JSON_UNESCAPED_UNICODE), $sch, 2500);
    if ($ok && is_array($v) && trim((string)($v['body'] ?? '')) !== '') {
        $u = $rules['draft']; $u['body'] = trim((string)$v['body']); $u['examples'] = ['body' => array_map('strval', (array)($v['variable_examples'] ?? [])), 'header' => '', 'labels' => array_map('strval', (array)($v['variable_labels'] ?? []))];
        $u['footer'] = ((string)($t['footer'] ?? '') !== '' && !wa_tpl_promo_hits((string)$t['footer'])) ? (string)$t['footer'] : trim((string)($v['footer'] ?? '')); if (wa_tpl_promo_hits($u['footer'])) $u['footer'] = '';
        $keep = array_values(array_filter($rules['draft']['buttons'], fn($b) => ($b['type'] ?? '') !== 'QUICK_REPLY')); $qr = [];
        foreach (array_slice((array)($v['quick_replies'] ?? []), 0, 2) as $q) { $q = mb_substr(trim((string)$q), 0, 25); if ($q !== '' && !wa_tpl_promo_hits($q)) $qr[] = ['type' => 'QUICK_REPLY', 'text' => $q]; }
        if (!$qr) $qr = array_values(array_filter($rules['draft']['buttons'], fn($b) => ($b['type'] ?? '') === 'QUICK_REPLY'));
        $u['buttons'] = array_merge($qr, $keep);
        if ($strict >= 1) { $u['buttons'] = [['type' => 'QUICK_REPLY', 'text' => 'Send me details']]; $u['footer'] = ''; $u['header_text'] = ''; if ($strict >= 2) { $u['header_format'] = ''; $u['header_lib_id'] = 0; } elseif ($u['header_format'] === 'TEXT') $u['header_format'] = ''; }
        [$cu, $err] = wa_tpl_clean($u, true);
        if ($err === '' && !wa_tpl_promo_hits($cu['body'])) {
            $ch = []; foreach ((array)($v['changes'] ?? []) as $c) { if (!is_array($c)) continue; $f = trim((string)($c['from'] ?? '')); if ($f === '' || mb_stripos($orig, $f) === false) continue; $ch[] = ['from' => mb_substr($f, 0, 120), 'to' => mb_substr(trim((string)($c['to'] ?? '')) ?: '(removed)', 0, 160), 'why' => mb_substr(trim((string)($c['why'] ?? '')), 0, 200)]; }
            $seen = array_map(fn($c) => mb_strtolower($c['from']), $ch); foreach (wa_tpl_promo_hits($orig) as $h) if (!in_array(mb_strtolower($h), $seen, true) && count($ch) < 8) $ch[] = ['from' => $h, 'to' => '(removed)', 'why' => 'Promotional wording — Utility messages answer something the customer did.'];
            foreach ($rules['changes'] as $c) if (preg_match('/^(Copy coupon|Offer timer)/', $c['from']) && count($ch) < 10) $ch[] = $c;
            $tips = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 220), (array)($v['tips'] ?? [])))); if (!$tips) $tips = wa_tpl_utility_tips();
            $tips[] = 'Meta decides the category by itself and may still change it — this only gives a better chance.';
            return ['draft' => $cu, 'changes' => $ch, 'tips' => array_slice(array_values(array_unique($tips)), 0, 5), 'source' => 'claude'];
        }
    }
    [$cu, $err] = wa_tpl_clean($rules['draft'], true); if ($err === '') $rules['draft'] = $cu; $rules['source'] = 'rules'; $rules['strict'] = $strict; return $rules;
}

}   // function_exists guard

// ------------------------------------------------------------------------------------------------------------------------ router
$__wt = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wt, 'wa.tpl_') === 0 && $__wt !== 'wa.tpl_diagnose') {   // wa.tpl_diagnose is answered by wa_diag.php (loaded later)
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $auth = require_auth();
    if (!wa_tables($pdo) || !wa_tpl_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    $wabas = wa_wabas($pdo);
    $names = employee_names_by_id($pdo); $me = (int)($auth['employee_id'] ?? 0);
    switch ($__wt) {
        case 'wa.tpl_list': {
            $auto = null; register_shutdown_function(function () use ($pdo) { if (function_exists('hrms_release')) hrms_release(); @set_time_limit(90); try { wa_tpl_autosync($pdo, 300); } catch (Throwable $e) { } });   // ⚡ asking Meta about waiting templates happens AFTER the list is shown (every 5 min at most) — the list opens at once
            $rows = $pdo->query("SELECT * FROM wa_templates ORDER BY star DESC, used DESC, id DESC")->fetchAll();
            $out = []; foreach ($rows as $r) { $r['buttons'] = json_decode((string)$r['buttons'], true) ?: []; $r['examples'] = json_decode((string)$r['examples'], true) ?: []; $out[] = wa_tpl_public($pdo, $r, $wabas); }
            $out = wa_tpl_mark_auto($out, !empty($_GET['all']));   // 🎫 complaint / follow-up messages are sent by the system: marked 'auto', their old versions left out
            $cnt = ['approved' => 0, 'pending' => 0, 'rejected' => 0, 'other' => 0]; foreach ($out as $o) { if (isset($cnt[$o['status']])) $cnt[$o['status']]++; else $cnt['other']++; }
            $fol = []; foreach (['Offers', 'Reminders', 'Order updates'] as $f) $fol[$f] = 0; foreach ($out as $o) if ($o['folder'] !== '') $fol[$o['folder']] = ($fol[$o['folder']] ?? 0) + 1;
            $fl = []; foreach ($fol as $k => $n) $fl[] = ['name' => (string)$k, 'count' => $n];
            try { $out = wa_mix_decorate($pdo, $out); $auto = wa_tpl_auto_status($pdo); } catch (Throwable $e) { $auto = null; }
            json_response(['templates' => $out, 'counts' => $cnt, 'accounts' => count($wabas), 'can_edit' => wa_is_admin($pdo, $auth), 'folders' => $fl, 'app_id_set' => wa_cfg_val('WA_APP_ID') !== '', 'auto' => $auto]);
        }
        case 'wa.tpl_sync': {
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            if (!$wabas) json_response(['error' => 'No WhatsApp Business Account ID yet — add one in WhatsApp → Setup.'], 422);
            [$n, $err] = wa_tpl_sync($pdo); wa_log($pdo, 'template', !$err, 'Synced ' . $n . ' template entries from Meta' . ($err ? ' — ' . implode('; ', $err) : ''));
            json_response(['ok' => !$err, 'synced' => $n, 'errors' => $err]);
        }
        case 'wa.tpl_understand': {   // the owner says what he wants → Claude shows how he understood it and fills the whole form
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $purpose = trim(mb_substr((string)($in['purpose'] ?? ''), 0, 1200)); $fix = trim(mb_substr((string)($in['fix'] ?? ''), 0, 600)); $prev = is_array($in['draft'] ?? null) ? $in['draft'] : null;
            if ($purpose === '') json_response(['error' => 'Tell me what the message is for.'], 422);
            $ctx = "WHAT THE OWNER WANTS (may be English, Hindi or Gujarati):\n" . $purpose . "\n";
            if ($prev && $fix !== '') { $pv = $prev; unset($pv['warnings'], $pv['problem']); $ctx .= "\nMY PREVIOUS DRAFT:\n" . json_encode($pv, JSON_UNESCAPED_UNICODE) . "\nTHE OWNER SAYS IT IS NOT QUITE RIGHT, CHANGE THIS:\n" . $fix . "\n"; }
            $sch = wa_tpl_draft_schema_full(); $sch['properties']['understood'] = ['type' => 'STRING']; $sch['required'][] = 'understood';
            $sys = "You help the owner of a perfume shop make a new pre-made WhatsApp message (a template that Meta must approve). First write 'understood': 2–4 plain-English sentences saying exactly how you understood what he wants — who it is for, when it is sent, the goal, the category, the language, what goes on top (photo / video / PDF / location / offer timer / nothing) and what the message will say. Then write the draft. " . WA_TPL_RULES . ' ' . WA_TPL_RULES_FORM;
            ignore_user_abort(true);
            [$ok, $v, $why] = wa_tpl_model($sys, $ctx, $sch, 3000);
            if (!$ok || !is_array($v)) json_response(['error' => (string)($why ?: 'The AI could not write the message.')], 502);
            [$clean, $err] = wa_tpl_clean(wa_tpl_from_ai($v), true); $clean['understood'] = mb_substr((string)($v['understood'] ?? ''), 0, 900); $clean['why'] = mb_substr((string)($v['why'] ?? ''), 0, 400); $clean['purpose'] = $purpose;
            if ($clean['folder'] === '') $clean['folder'] = wa_tpl_folder_guess($clean + ['purpose' => $purpose]);
            json_response(['ok' => true, 'draft' => $clean, 'problem' => $err, 'warnings' => $clean['warnings']]);
        }
        case 'wa.tpl_check': {
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $d = json_input()['draft'] ?? []; if (!is_array($d)) json_response(['error' => 'No message to check.'], 422);
            [$t, $err] = wa_tpl_clean($d); if ($err === '') $err = wa_tpl_media_check($pdo, $t);
            json_response(['ok' => $err === '', 'problem' => $err, 'warnings' => $err === '' ? $t['warnings'] : wa_tpl_warnings($t), 'buttons' => $t['buttons'], 'app_id_set' => wa_cfg_val('WA_APP_ID') !== '', 'needs_app_id' => wa_tpl_media_kind((string)$t['header_format']) !== '']);
        }
        case 'wa.tpl_utility': {
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $d = json_input()['draft'] ?? []; if (!is_array($d)) json_response(['error' => 'No message to rewrite.'], 422);
            [$t, $err] = wa_tpl_clean($d, true); if ($err !== '') json_response(['error' => $err], 422);
            if ($t['category'] === 'UTILITY') json_response(['ok' => true, 'utility_draft' => null, 'changes' => [], 'tips' => [], 'chance' => 'same', 'message' => 'This message is already a Utility message.']);
            ignore_user_abort(true); $u = wa_tpl_utility_build($t, max(0, min(2, (int)(json_input()['strict'] ?? 0))));
            json_response(['ok' => true, 'utility_draft' => $u['draft'], 'changes' => $u['changes'], 'tips' => $u['tips'], 'chance' => 'better', 'source' => $u['source']]);
        }
        case 'wa.tpl_submit': {
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $d = $in['draft'] ?? []; if (!is_array($d)) json_response(['error' => 'No message to send.'], 422);
            [$t, $err] = wa_tpl_clean($d); if ($err === '') $err = wa_tpl_media_check($pdo, $t); if ($err !== '') json_response(['error' => $err], 422);
            if (!$wabas) json_response(['error' => 'No WhatsApp Business Account ID yet — add one in WhatsApp → Setup.'], 422);
            // the utility twin: asked for (also_utility) or made automatically for every marketing message (setting wa_tpl_auto_twin, opt out with no_utility). Claude writes it first, the rules when Claude cannot.
            $u = null; $uerr = ''; $explicit = !empty($in['also_utility']) && function_exists('wa_util_mix_on') && wa_util_mix_on($pdo); $autoU = array_key_exists('also_utility', $in) ? $explicit : (wa_mix_cfg($pdo, 'wa_tpl_auto_twin', 1) == 1 && function_exists('wa_util_mix_on') && wa_util_mix_on($pdo) && empty($in['no_utility']));
            if ($autoU && $t['category'] === 'MARKETING' && (int)(wa_tpl_arr($d['extra'] ?? [])['utility_of'] ?? 0) === 0) {
                ignore_user_abort(true); $ud = is_array($in['utility_draft'] ?? null) ? $in['utility_draft'] : wa_tpl_utility_build($t)['draft'];
                $ud['category'] = 'UTILITY'; $ud['language'] = $t['language']; if (empty($ud['name']) || wa_tpl_name((string)$ud['name']) === $t['name']) $ud['name'] = substr($t['name'], 0, 57) . '_u';
                [$u, $uerr] = wa_tpl_clean($ud); if ($uerr === '') $uerr = wa_tpl_media_check($pdo, $u);
                if ($uerr !== '') { if ($explicit) json_response(['error' => 'The Utility version has a problem: ' . $uerr], 422); $u = null; }
            }
            $t['id'] = wa_tpl_insert($pdo, $t, $me, (string)($d['purpose'] ?? ''), (string)($d['understood'] ?? ''));
            $q = $pdo->prepare("SELECT name FROM wa_templates WHERE id = ?"); $q->execute([$t['id']]); $t['name'] = (string)$q->fetchColumn(); $fails = []; $queued = 0; $bud = ['left' => 60];
            foreach ($wabas as $w) { [$ok, $why] = wa_tpl_try_create($pdo, $t, $w, $bud); if (!$ok) { if (preg_match('/limit|templates -|over the limit/', $why)) $queued++; else $fails[] = $why; } }
            wa_log($pdo, 'template', !$fails, 'Created ' . $t['name'] . ' in ' . count($wabas) . ' account(s)' . ($fails ? ' — ' . $fails[0] : ''));
            $res = ['ok' => !$fails || count($fails) + $queued < count($wabas) || $queued > 0, 'id' => $t['id'], 'name' => $t['name'], 'errors' => $fails, 'warnings' => $t['warnings'], 'queued' => $queued, 'accounts' => count($wabas)];
            if ($u) {
                if ($u['folder'] === '') $u['folder'] = $t['folder'];
                $u['family_id'] = $t['id']; $u['version'] = 1; $u['extra'] = array_merge(is_array($u['extra']) ? $u['extra'] : [], ['utility_of' => $t['id'], 'utility_v' => 1]);
                $u['id'] = wa_tpl_insert($pdo, $u, $me, (string)($d['purpose'] ?? ''), 'Utility version of ' . $t['name']);
                $q->execute([$u['id']]); $u['name'] = (string)$q->fetchColumn(); $ufails = []; $uq = 0; $urow = wa_tpl_row($pdo, $u['id']);
                foreach ($wabas as $w) { [$ok, $why] = wa_tpl_try_create($pdo, $urow, $w, $bud); if (!$ok) { if (preg_match('/limit|templates -|over the limit/', $why)) $uq++; else $ufails[] = $why; } }
                wa_mix_extra_set($pdo, (int)$t['id'], ['utility_id' => $u['id']]);
                wa_log($pdo, 'template', !$ufails, 'Created Utility version ' . $u['name'] . ($ufails ? ' — ' . $ufails[0] : ''));
                $res['utility'] = ['ok' => !$ufails || count($ufails) + $uq < count($wabas) || $uq > 0, 'id' => $u['id'], 'name' => $u['name'], 'errors' => $ufails, 'queued' => $uq];
            } elseif ($autoU && $t['category'] === 'MARKETING') { wa_mix_extra_set($pdo, (int)$t['id'], ['need_twin' => 1]); $res['utility'] = ['ok' => false, 'id' => 0, 'name' => '', 'errors' => $uerr !== '' ? [$uerr] : [], 'queued' => 0, 'later' => true]; }
            json_response($res);
        }
        case 'wa.tpl_save': {   // keep a draft in HRMS without sending it to Meta
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $d = $in['draft'] ?? []; if (!is_array($d)) json_response(['error' => 'No message to save.'], 422);
            [$t, $err] = wa_tpl_clean($d, true); if ($err !== '') json_response(['error' => $err], 422);
            $id = (int)($in['id'] ?? 0);
            if ($id > 0) {
                $r = wa_tpl_row($pdo, $id); if (!$r) json_response(['error' => 'Not found.'], 404);
                $q = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([$id]); if ((int)$q->fetchColumn() > 0 || $r['source'] !== 'hrms') json_response(['error' => 'This message was already sent to Meta — it cannot be changed. Make a copy instead.'], 422);
                $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = ? AND id <> ?"); $q->execute([$t['name'], $t['language'], $id]); if ($q->fetchColumn()) $t['name'] = substr($t['name'], 0, 52) . '_' . date('md') . substr((string)mt_rand(100, 999), 0, 3);
                $pdo->prepare("UPDATE wa_templates SET name = ?, language = ?, category = ?, header_format = ?, header_text = ?, header_lib_id = ?, body = ?, footer = ?, buttons = ?, lto = ?, examples = ?, extra = ?, folder = ?, purpose = ?, understood = ? WHERE id = ?")
                    ->execute([$t['name'], $t['language'], $t['category'], $t['header_format'], $t['header_text'], (int)$t['header_lib_id'], $t['body'], $t['footer'], json_encode($t['buttons'], JSON_UNESCAPED_UNICODE), $t['lto'] ? json_encode($t['lto'], JSON_UNESCAPED_UNICODE) : null,
                               json_encode($t['examples'], JSON_UNESCAPED_UNICODE), $t['extra'] ? json_encode($t['extra'], JSON_UNESCAPED_UNICODE) : null, $t['folder'] !== '' ? $t['folder'] : wa_tpl_folder_guess($t), mb_substr((string)($d['purpose'] ?? ''), 0, 600), mb_substr((string)($d['understood'] ?? ''), 0, 900), $id]);
            } else $id = wa_tpl_insert($pdo, $t, $me, (string)($d['purpose'] ?? ''), (string)($d['understood'] ?? ''));
            $q = $pdo->prepare("SELECT name FROM wa_templates WHERE id = ?"); $q->execute([$id]); $nm = (string)$q->fetchColumn(); $res = ['ok' => true, 'id' => $id, 'name' => $nm, 'warnings' => $t['warnings']];
            if ($t['category'] === 'MARKETING' && wa_mix_cfg($pdo, 'wa_tpl_auto_twin', 1) == 1 && function_exists('wa_util_mix_on') && wa_util_mix_on($pdo) && empty($in['no_utility'])) {   // a saved marketing draft gets its utility draft too (both go to Meta together when the draft is sent)
                try {
                    $mr = wa_tpl_row($pdo, $id); $oldU = (int)(wa_mix_extra($mr)['utility_id'] ?? 0);
                    if ($oldU) { $h = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_waba WHERE tpl_id = ?"); $h->execute([$oldU]); if ((int)$h->fetchColumn() === 0) { $pdo->prepare("DELETE FROM wa_templates WHERE id = ?")->execute([$oldU]); $oldU = 0; } }
                    if (!$oldU) {
                        ignore_user_abort(true); $ud = wa_tpl_utility_build($t)['draft']; $ud['category'] = 'UTILITY'; $ud['language'] = $t['language']; $ud['name'] = substr($nm, 0, 57) . '_u'; [$cu, $ue] = wa_tpl_clean($ud, true);
                        if ($ue === '') { $cu['family_id'] = $id; $cu['version'] = 1; $cu['extra'] = array_merge(is_array($cu['extra']) ? $cu['extra'] : [], ['utility_of' => $id, 'utility_v' => 1]); if ($cu['folder'] === '') $cu['folder'] = $t['folder']; $uid = wa_tpl_insert($pdo, $cu, $me, (string)($d['purpose'] ?? ''), 'Utility version of ' . $nm); wa_mix_extra_set($pdo, $id, ['utility_id' => $uid]); $res['utility'] = ['id' => $uid, 'draft' => true]; }
                    }
                } catch (Throwable $e) { }
            }
            json_response($res);
        }
        case 'wa.tpl_push': {
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $r = wa_tpl_row($pdo, (int)(json_input()['id'] ?? 0)); if (!$r) json_response(['error' => 'Not found.'], 404);
            if ($r['source'] === 'hrms') { [$cl, $err] = wa_tpl_clean($r, true); if ($err === '') $err = wa_tpl_media_check($pdo, $r); if ($err !== '') json_response(['error' => $err], 422); }
            elseif (wa_tpl_media_kind((string)$r['header_format']) !== '' && (int)$r['header_lib_id'] <= 0) json_response(['error' => 'This message has a photo / video / PDF on top. Pick the file for it first (Choose file), then copy it to the other account.'], 422);
            $q = $pdo->prepare("SELECT waba_id, status FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([$r['id']]); $have = []; foreach ($q->fetchAll() as $x) if ($x['status'] !== 'REJECTED') $have[$x['waba_id']] = true;
            $fails = []; $n = 0; $bud = ['left' => 60]; foreach ($wabas as $w) { if (isset($have[$w])) continue; [$ok, $why] = wa_tpl_try_create($pdo, $r, $w, $bud); if ($ok) $n++; else $fails[] = $why; }
            $uid = (int)(wa_mix_extra($r)['utility_id'] ?? 0);   // a saved utility draft goes along
            if ($uid && ($ur = wa_tpl_row($pdo, $uid)) && $ur['source'] === 'hrms') { $h = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_waba WHERE tpl_id = ?"); $h->execute([$uid]); if ((int)$h->fetchColumn() === 0) foreach ($wabas as $w) { [$ok2, $w2] = wa_tpl_try_create($pdo, $ur, $w, $bud); if ($ok2) $n++; } }
            json_response(['ok' => !$fails, 'created' => $n, 'errors' => $fails]);
        }
        case 'wa.tpl_attach': {   // which library photo / video / PDF goes on top when this message is sent
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $r = wa_tpl_row($pdo, (int)($in['id'] ?? 0)); if (!$r) json_response(['error' => 'Not found.'], 404);
            $k = wa_tpl_media_kind((string)$r['header_format']); if ($k === '') json_response(['error' => 'This message has no photo / video / PDF on top.'], 422);
            $lib = wa_tpl_lib_row($pdo, (int)($in['lib_id'] ?? 0)); if (!$lib) json_response(['error' => 'That file is not in the media library.'], 404);
            if ((string)$lib['kind'] !== $k) json_response(['error' => 'This message needs a ' . ['image' => 'photo', 'video' => 'video', 'document' => 'PDF'][$k] . ' on top, but that file is not one.'], 422);
            $pdo->prepare("UPDATE wa_templates SET header_lib_id = ? WHERE id = ?")->execute([(int)$lib['id'], (int)$r['id']]); json_response(['ok' => true]);
        }
        case 'wa.tpl_folder': {
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $id = (int)($in['id'] ?? 0); if (!wa_tpl_row($pdo, $id)) json_response(['error' => 'Not found.'], 404);
            $f = wa_tpl_folder_clean($in['folder'] ?? ''); $pdo->prepare("UPDATE wa_templates SET folder = ? WHERE id = ?")->execute([$f, $id]); json_response(['ok' => true, 'folder' => $f]);
        }
        case 'wa.tpl_delete': {
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $r = wa_tpl_row($pdo, (int)(json_input()['id'] ?? 0)); if (!$r) json_response(['error' => 'Not found.'], 404);
            foreach ($wabas as $w) wa_api('DELETE', '/' . $w . '/message_templates?name=' . rawurlencode($r['name']));
            $pdo->prepare("DELETE FROM wa_tpl_waba WHERE tpl_id = ?")->execute([$r['id']]); $pdo->prepare("DELETE FROM wa_templates WHERE id = ?")->execute([$r['id']]);
            json_response(['ok' => true]);
        }
        case 'wa.tpl_star': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $id = (int)(json_input()['id'] ?? 0); $pdo->prepare("UPDATE wa_templates SET star = 1 - star WHERE id = ?")->execute([$id]); json_response(['ok' => true]);
        }
        case 'wa.tpl_suggest': {   // for ONE chat: how Claude understood it → the best approved template, or a draft for a new one
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $key = wa_key(json_input()['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            if (!function_exists('convo_ai')) json_response(['error' => 'The AI is not available.'], 502);
            $st = $pdo->prepare("SELECT dir, body, ts, number_id FROM wa_messages WHERE phone = ? AND dir != 'sys' ORDER BY id DESC LIMIT 25"); $st->execute([$key]); $rows = array_reverse($st->fetchAll());
            $txt = ''; foreach ($rows as $r) $txt .= ($r['dir'] === 'in' ? 'CUSTOMER' : 'SHOP') . ' [' . substr((string)$r['ts'], 0, 10) . ']: ' . mb_substr((string)$r['body'], 0, 300) . "\n";
            // order history (last 10 digits of the phone against the Shopify order cache)
            $ord = ['n' => 0, 'last' => '', 'total' => 0];
            try { $q = $pdo->prepare("SELECT COUNT(*) n, MAX(created_at) last, COALESCE(SUM(total), 0) total FROM shop_orders WHERE SUBSTR(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), -10) = ?"); $q->execute([$key]); $o = $q->fetch(); if ($o) $ord = ['n' => (int)$o['n'], 'last' => (string)$o['last'], 'total' => (int)$o['total']]; } catch (PDOException $e) { }
            $inAt = $c['last_in_at'] ? (int)floor((time() - strtotime((string)$c['last_in_at'])) / 86400) : null;
            $avail = []; $rowsT = $pdo->query("SELECT * FROM wa_templates ORDER BY used DESC, id DESC")->fetchAll();
            foreach ($rowsT as $r) { $r['buttons'] = json_decode((string)$r['buttons'], true) ?: []; $p = wa_tpl_public($pdo, $r, $wabas); if (in_array($p['status'], ['approved', 'partial'], true) && !$p['media_header'] && $p['chat_ok']) $avail[$p['id']] = $p; }
            $list = ''; foreach (array_slice($avail, 0, 60, true) as $id => $p) $list .= '#' . $id . ' ' . $p['name'] . ' [' . $p['category'] . ']: ' . mb_substr(str_replace("\n", ' ', $p['body']), 0, 260) . "\n";
            $ctx = "CUSTOMER FACTS: orders so far " . $ord['n'] . ($ord['n'] ? ', last order ' . substr($ord['last'], 0, 10) . ', total Rs ' . $ord['total'] : '') . '; last customer message ' . ($inAt === null ? 'never' : $inAt . ' day(s) ago') . '; 24-hour window ' . (($inAt !== null && $inAt < 1) ? 'OPEN' : 'CLOSED') . '; came from ' . ($c['source'] === 'query' ? 'a Meta ad (' . $c['ref_text'] . ')' : 'organic') . ($c['optout'] ? '; OPTED OUT' : '') . "\n\nTHE CHAT:\n" . $txt . "\nAPPROVED PRE-MADE MESSAGES (pick by number):\n" . ($list !== '' ? $list : "(none yet)\n");
            $sch = ['type' => 'OBJECT', 'properties' => ['understood' => ['type' => 'STRING'], 'best_id' => ['type' => 'INTEGER'], 'best_reason' => ['type' => 'STRING'], 'need_new' => ['type' => 'BOOLEAN'], 'new_gap' => ['type' => 'STRING'], 'draft' => wa_tpl_draft_schema()], 'required' => ['understood', 'best_id', 'need_new']];
            $sys = "You help a salesperson at a perfume shop choose the right pre-made WhatsApp message for ONE customer. Write 'understood' = 2–3 plain-English sentences: what the customer wanted, how long ago, what they did before, and where the chat stands. Then choose best_id = the number of the ONE approved message that fits best, or 0 if none really fits (never force a poor fit). best_reason = one short sentence. If none fits well, set need_new = true, write new_gap = one sentence on what is missing, and write a 'draft' of a new message. Otherwise need_new = false and leave the draft out. " . WA_TPL_RULES;
            ignore_user_abort(true);
            try { [$ok, $v, $why] = convo_ai($sys, [['text' => $ctx]], $sch, false, 3000, 'claude'); } catch (Throwable $e) { json_response(['error' => 'The AI could not read this chat.'], 502); }
            if (!$ok || !is_array($v)) json_response(['error' => (string)($why ?: 'The AI could not read this chat.')], 502);
            $bid = (int)($v['best_id'] ?? 0); $best = $bid > 0 && isset($avail[$bid]) ? $avail[$bid] : null; $draft = null; $problem = '';
            if (($v['need_new'] ?? false) || !$best) { if (!empty($v['draft']) && is_array($v['draft'])) { [$draft, $problem] = wa_tpl_clean($v['draft']); $draft['why'] = mb_substr((string)($v['draft']['why'] ?? ''), 0, 400); $draft['purpose'] = mb_substr((string)($v['new_gap'] ?? ''), 0, 600); $draft['understood'] = mb_substr((string)($v['understood'] ?? ''), 0, 900); } }
            json_response(['ok' => true, 'understood' => mb_substr((string)($v['understood'] ?? ''), 0, 900), 'best' => $best, 'best_reason' => mb_substr((string)($v['best_reason'] ?? ''), 0, 300), 'gap' => mb_substr((string)($v['new_gap'] ?? ''), 0, 300), 'draft' => $draft, 'draft_problem' => $problem, 'can_create' => wa_is_admin($pdo, $auth)]);
        }
        case 'wa.tpl_send': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $key = wa_key($in['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            $cxOwn = function_exists('wa_cx_owner') && wa_cx_owner($pdo, $auth, $c);   // the complaint owner sends the after-24-hours message from the case
            if (!$cxOwn && !wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            if (!$cxOwn && function_exists('wa_cx_lock') && ($__cx = wa_cx_lock($pdo, $auth, $c)) !== '') json_response(['error' => $__cx, 'complaint' => (int)$c['cx_id']], 423);
            if ((int)$c['optout'] === 1) json_response(['error' => 'This customer opted out — do not message them.'], 422);
            $t = wa_tpl_row($pdo, (int)($in['id'] ?? 0)); if (!$t) json_response(['error' => 'Pre-made message not found.'], 404);
            if ((int)($c['camp_optout'] ?? 0) === 1 && !in_array(strtolower((string)($t['category'] ?? 'marketing')), ['utility', 'authentication'], true)) json_response(['error' => 'This customer asked to stop promotional messages. Chat with them normally, or send a Utility message.'], 422);
            $vars = array_map(fn($x) => mb_substr(trim((string)$x), 0, 300), (array)($in['vars'] ?? [])); $need = count(wa_tpl_vars((string)$t['body']));
            for ($i = 0; $i < $need; $i++) if (($vars[$i] ?? '') === '') json_response(['error' => 'Fill the variable {{' . ($i + 1) . '}} first.'], 422);
            $hvar = trim((string)($in['header_var'] ?? '')); if ($t['header_format'] === 'TEXT' && wa_tpl_vars((string)$t['header_text']) && $hvar === '') json_response(['error' => 'Fill the heading variable first.'], 422);
            if (!wa_tpl_sendable($t, $pdo)) json_response(['error' => wa_tpl_media_kind((string)$t['header_format']) !== '' ? 'This message has a ' . ['IMAGE' => 'photo', 'VIDEO' => 'video', 'DOCUMENT' => 'PDF'][$t['header_format']] . ' on top, but none is chosen yet. An admin must pick it in Pre-made messages.' : 'This kind of message cannot be sent from a chat yet.'], 422);
            // the number: the one the customer last used — if it has no approval for this message, any number that has
            $nums = $pdo->query("SELECT id, phone_id, waba_id FROM wa_numbers ORDER BY id")->fetchAll(); $ok = []; $q = $pdo->prepare("SELECT status FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?");
            foreach ($nums as $nn) { $w = wa_waba_of($nn); $q->execute([$t['id'], $w]); if (strtoupper((string)$q->fetchColumn()) === 'APPROVED') $ok[(int)$nn['id']] = $nn; }
            if (!$ok) json_response(['error' => 'Meta has not approved this message yet.'], 422);
            $use = $ok[(int)$c['last_number_id']] ?? reset($ok);
            $xin = is_array($in['extra'] ?? null) ? $in['extra'] : []; $extra = [];
            foreach (['coupon', 'url_suffix', 'name', 'address', 'lat', 'lng'] as $k) if (isset($xin[$k]) && trim((string)$xin[$k]) !== '') $extra[$k] = trim((string)$xin[$k]);
            if (isset($xin['expires_ms']) && (int)$xin['expires_ms'] > 0) $extra['expires_ms'] = (int)$xin['expires_ms'];
            if ($hvar !== '') $extra['header_var'] = $hvar;
            $build = function (array $ex) use ($pdo, $t, $c, $use, $vars, $need) {
                $b = wa_tpl_send_components($pdo, $t, array_slice($vars, 0, $need), (string)$use['phone_id'], $ex); if (!$b['ok']) return [$b, null];
                $p = ['messaging_product' => 'whatsapp', 'to' => (string)($c['wa_id'] ?: '91' . $c['phone']), 'type' => 'template', 'template' => ['name' => $t['name'], 'language' => ['code' => $t['language']]]];
                if ($b['components']) $p['template']['components'] = $b['components']; return [$b, $p];
            };
            [$b, $payload] = $build($extra); if (!$b['ok']) json_response(['error' => $b['error']], 422);
            [$code, $d] = wa_api('POST', '/' . $use['phone_id'] . '/messages', $payload); $wamid = (string)($d['messages'][0]['id'] ?? '');
            if (($code < 200 || $code >= 300 || $wamid === '') && wa_tpl_media_kind((string)$t['header_format']) !== '' && preg_match('/media|expired|not found|131053|131052/i', wa_err($d))) {   // Meta forgot the copy → upload again once
                [$b, $payload] = $build($extra + ['fresh_media' => true]); if ($b['ok']) { [$code, $d] = wa_api('POST', '/' . $use['phone_id'] . '/messages', $payload); $wamid = (string)($d['messages'][0]['id'] ?? ''); }
            }
            if ($code < 200 || $code >= 300 || $wamid === '') { $e = wa_err($d); wa_log($pdo, 'send', false, $key . ' · template ' . $t['name'] . ' · ' . $e); json_response(['error' => $e], 422); }
            $shown = wa_tpl_shown_text($t, $vars, $hvar); $now = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, status, who, emp_id, ts) VALUES (?, ?, ?, 'out', 'template', ?, 'sent', 'emp', ?, ?)")->execute([$wamid, $key, (int)$use['id'], $shown, $me, $now]);
            $pdo->prepare("UPDATE wa_contacts SET last_out_at = ?, state = 'human', status = 'open', closed_at = NULL WHERE phone = ?")->execute([$now, $key]);
            $pdo->prepare("UPDATE wa_templates SET used = used + 1 WHERE id = ?")->execute([$t['id']]);
            json_response(['ok' => true, 'wamid' => $wamid]);
        }
        case 'wa.tpl_auto': {   // the "Auto-approval" card: what was sent today, what waits, what was rejected, alternatives made, Meta's limits. POST {run:true} runs the engine now; POST {auto, twin_backfill} sets the switches.
            if ($isPost) {
                wa_guard_admin($pdo, $auth); $in = json_input(); $r = null;
                if (array_key_exists('auto', $in)) setting_set($pdo, 'wa_tpl_auto', !empty($in['auto']) ? '1' : '0');
                if (array_key_exists('twin_backfill', $in)) setting_set($pdo, 'wa_tpl_twin_backfill', !empty($in['twin_backfill']) ? '1' : '0');
                if (!empty($in['run'])) { ignore_user_abort(true); $r = wa_tpl_autosubmit_tick($pdo, true); }
                json_response(['ok' => true, 'run' => $r, 'auto' => wa_tpl_auto_status($pdo)]);
            }
            json_response(['auto' => wa_tpl_auto_status($pdo)]);
        }
        case 'wa.tpl_twin_all': {   // utility versions for the marketing messages that are already approved (made a few at a time by the engine, never more than Meta allows)
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $one = (int)(json_input()['id'] ?? 0);   // {id}: just this message, and make it now
            if ($one > 0) { $r1 = wa_tpl_row($pdo, $one); if (!$r1 || $r1['category'] !== 'MARKETING' || (int)(wa_mix_extra($r1)['utility_of'] ?? 0) > 0) json_response(['error' => 'Only a Marketing message gets a Utility version.'], 422);
                if (wa_mix_twins($pdo, $one)) json_response(['ok' => true, 'marked' => 0, 'exists' => true]);
                wa_mix_extra_set($pdo, $one, ['need_twin' => 1]); ignore_user_abort(true); $run = wa_tpl_autosubmit_tick($pdo, true); json_response(['ok' => true, 'marked' => 1, 'run' => $run]); }
            $have = []; foreach ($pdo->query("SELECT extra FROM wa_templates WHERE extra LIKE '%\"utility_of\"%'")->fetchAll() as $r) { $u = (int)(wa_mix_extra($r)['utility_of'] ?? 0); if ($u) $have[$u] = true; }
            $ap = []; foreach ($pdo->query("SELECT DISTINCT tpl_id FROM wa_tpl_waba WHERE UPPER(status) = 'APPROVED'")->fetchAll() as $r) $ap[(int)$r['tpl_id']] = true; $n = 0;
            foreach ($pdo->query("SELECT id, extra FROM wa_templates WHERE category = 'MARKETING' ORDER BY used DESC, id LIMIT 300")->fetchAll() as $r) { $id = (int)$r['id']; if (!isset($ap[$id]) || isset($have[$id]) || (int)(wa_mix_extra($r)['utility_of'] ?? 0) > 0) continue; wa_mix_extra_set($pdo, $id, ['need_twin' => 1]); $n++; }
            json_response(['ok' => true, 'marked' => $n]);
        }
        default: json_response(['error' => 'Unknown pre-made message action.'], 404);
    }
}
