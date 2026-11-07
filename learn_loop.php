<?php
/**
 * learn_loop.php - the nightly review loop.
 *
 *   all day      every Claude answer is remembered (assistant, Boss chat, what you approved / refused); the Teach bot (lib_feedback)
 *                and WhatsApp (wa_learn) already keep their own corrections - they are READ here, not copied.
 *   at night     a GitHub job (.github/workflows/learn-review.yml) asks ll.export for the last 24 hours (masked), Claude Code reads it,
 *                and posts what it learned with ll.suggest.
 *   next morning the suggestions wait in Brain -> Suggestions. Nothing changes until the Admin taps Approve:
 *                  note    -> becomes a Brain fact (customer or company shelf)
 *                  example -> becomes a correction in the Teach bot's feedback (the next guidebook draft learns from it)
 *                  bug     -> becomes a request on the Changes page
 *
 * ll.export / ll.suggest are called by GitHub with a long secret in the header X-Learn-Key (config.php: define('LEARN_LOOP_KEY', '...')).
 * Without that line they refuse everything. Admin actions: ll.sug_list, ll.sug_act, ll.mark.
 * PHP 7.4 safe. Loaded by api.php for ll. actions, and on demand by the logging helpers.
 */
if (!function_exists('ll_key')) {

function ll_key() { return defined('LEARN_LOOP_KEY') ? trim((string)LEARN_LOOP_KEY) : ''; }

function ll_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_log (id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, source VARCHAR(12) NOT NULL, who VARCHAR(80) NOT NULL DEFAULT '', q TEXT NULL, a MEDIUMTEXT NULL,
            outcome VARCHAR(10) NOT NULL DEFAULT 'none', meta VARCHAR(255) NOT NULL DEFAULT '', KEY k_at (at), KEY k_src (source, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_suggest (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL, kind VARCHAR(8) NOT NULL, scope VARCHAR(10) NOT NULL DEFAULT 'customer', area VARCHAR(16) NOT NULL DEFAULT '',
            text TEXT NOT NULL, evidence TEXT NULL, status VARCHAR(8) NOT NULL DEFAULT 'pending', decided_by VARCHAR(80) NOT NULL DEFAULT '', decided_at DATETIME NULL, h CHAR(32) NOT NULL DEFAULT '',
            UNIQUE KEY uq_h (h), KEY k_st (status, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('learn_loop tables: ' . $e->getMessage()); }
}

/** phone numbers, emails and long ids never leave the server */
function ll_mask($t) {
    $t = (string)$t;
    $t = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $t);
    $t = preg_replace('/\+?\d[\d\s\-]{6,}\d/', '[number]', $t);
    return mb_substr($t, 0, 1500);
}

/** remember one exchange. Never throws. */
function ll_log($pdo, $source, $who, $q, $a, $outcome = 'none', $meta = '') {
    try {
        if (!($pdo instanceof PDO)) return 0; ll_ensure($pdo);
        $pdo->prepare("INSERT INTO ai_log (at, source, who, q, a, outcome, meta) VALUES (NOW(),?,?,?,?,?,?)")
            ->execute([mb_substr((string)$source, 0, 12), mb_substr((string)$who, 0, 80), mb_substr((string)$q, 0, 3000), mb_substr((string)$a, 0, 8000), mb_substr((string)$outcome, 0, 10), mb_substr((string)$meta, 0, 255)]);
        $n = (int)$pdo->lastInsertId();
        if (mt_rand(1, 200) === 1) { $pdo->exec("DELETE FROM ai_log WHERE at < DATE_SUB(NOW(), INTERVAL 45 DAY)"); }   // old rows go after 45 days
        return $n;
    } catch (Throwable $e) { return 0; }
}

function ll_auth_key() {
    $k = ll_key(); $got = (string)($_SERVER['HTTP_X_LEARN_KEY'] ?? '');
    if ($k === '' || strlen($k) < 20) json_response(['error' => 'The review loop is switched off (no LEARN_LOOP_KEY in config.php).'], 403);
    if (!hash_equals($k, $got)) json_response(['error' => 'Wrong key.'], 403);
}

function ll_admin($auth) { if (!in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)) json_response(['error' => 'Only Admin or HR.'], 403); }

/** the last N hours, masked, from every place a correction or an answer is kept */
function ll_export(PDO $pdo, $hours) {
    ll_ensure($pdo); $hours = max(1, min(72, (int)$hours)); $items = []; $counts = [];
    $since = date('Y-m-d H:i:s', time() - $hours * 3600);
    try {
        $st = $pdo->prepare("SELECT at, source, q, a, outcome, meta FROM ai_log WHERE at >= ? ORDER BY id DESC LIMIT 250"); $st->execute([$since]);
        foreach ($st->fetchAll() as $r) { $items[] = ['src' => $r['source'], 'when' => $r['at'], 'asked' => ll_mask($r['q']), 'answered' => ll_mask($r['a']), 'outcome' => $r['outcome'], 'note' => ll_mask($r['meta'])]; }
    } catch (Throwable $e) {}
    try {   // Teach bot: thumbs and "what it should have said"
        $st = $pdo->prepare("SELECT created_at, rating, tag, customer, reply, better FROM lib_feedback WHERE created_at >= ? ORDER BY id DESC LIMIT 150"); $st->execute([$since]);
        foreach ($st->fetchAll() as $r) { $items[] = ['src' => 'teach_bot', 'when' => $r['created_at'], 'asked' => ll_mask($r['customer']), 'answered' => ll_mask($r['reply']), 'outcome' => ((int)$r['rating'] > 0 ? 'accepted' : 'rejected'), 'fix' => ll_mask($r['better']), 'note' => (string)$r['tag']]; }
    } catch (Throwable $e) {}
    try {   // WhatsApp: what the bot suggested against what the person really sent
        $st = $pdo->prepare("SELECT created_at, kind, customer, sugg, sent FROM wa_learn WHERE created_at >= ? AND kind IN ('edited','own') ORDER BY id DESC LIMIT 150"); $st->execute([$since]);
        foreach ($st->fetchAll() as $r) { $items[] = ['src' => 'whatsapp', 'when' => $r['created_at'], 'asked' => ll_mask($r['customer']), 'answered' => ll_mask($r['sugg']), 'outcome' => $r['kind'] === 'edited' ? 'edited' : 'replaced', 'fix' => ll_mask($r['sent'])]; }
        $c = $pdo->prepare("SELECT kind, COUNT(*) n FROM wa_learn WHERE created_at >= ? GROUP BY kind"); $c->execute([$since]); foreach ($c->fetchAll() as $r) $counts['whatsapp_' . $r['kind']] = (int)$r['n'];
    } catch (Throwable $e) {}
    $known = [];
    try {   // what the bot already knows (customer and company shelves only - never internal), so the review does not repeat it
        if (function_exists('brain_facts')) { foreach (['customer', 'company'] as $sc) foreach (array_slice((array)brain_facts($pdo, $sc), 0, 120) as $f) $known[] = ['scope' => $sc, 'text' => ll_mask(is_array($f) ? ($f['fact'] ?? '') : (string)$f)]; }
    } catch (Throwable $e) {}
    return ['ok' => true, 'since' => $since, 'hours' => $hours, 'counts' => $counts, 'items' => $items, 'known_notes' => $known];
}

}   // end of function definitions

if (!isset($pdo) || !function_exists('json_response')) { return; }
$__llAct = (string)($action ?? '');
if (strpos($__llAct, 'll.') !== 0) { return; }

switch ($__llAct) {
    case 'll.export': {
        ll_auth_key();
        json_response(ll_export($pdo, (int)($_GET['hours'] ?? 24)));
    }
    case 'll.suggest': {
        ll_auth_key(); ll_ensure($pdo);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
        $in = json_input(); $n = 0;
        foreach (array_slice((array)($in['items'] ?? []), 0, 30) as $it) {
            if (!is_array($it)) continue;
            $kind = in_array(($it['kind'] ?? ''), ['note', 'example', 'bug'], true) ? $it['kind'] : ''; $text = trim(mb_substr((string)($it['text'] ?? ''), 0, 1200));
            if ($kind === '' || mb_strlen($text) < 8) continue;
            if ($kind === 'note' && !in_array(($it['scope'] ?? ''), ['customer', 'company'], true)) continue;   // the review may never write to the internal shelf (and never guesses a shelf)
            $scope = in_array(($it['scope'] ?? ''), ['customer', 'company'], true) ? $it['scope'] : 'customer';
            $h = md5(mb_strtolower(preg_replace('/\s+/', ' ', $kind . $scope . $text)));
            try { $pdo->prepare("INSERT INTO ai_suggest (created_at, kind, scope, area, text, evidence, h) VALUES (NOW(),?,?,?,?,?,?)")->execute([$kind, $scope, mb_substr((string)($it['area'] ?? ''), 0, 16), $text, mb_substr(ll_mask((string)($it['evidence'] ?? '')), 0, 1200), $h]); $n++; } catch (Throwable $e) { /* the same suggestion again: ignored */ }
        }
        json_response(['ok' => true, 'saved' => $n]);
    }
    case 'll.sug_list': {
        $auth = require_auth(); ll_admin($auth); ll_ensure($pdo);
        $rows = $pdo->query("SELECT id, created_at, kind, scope, area, text, evidence, status FROM ai_suggest WHERE status = 'pending' ORDER BY id DESC LIMIT 60")->fetchAll();
        $done = $pdo->query("SELECT id, created_at, kind, scope, text, status, decided_by, decided_at FROM ai_suggest WHERE status <> 'pending' ORDER BY decided_at DESC LIMIT 15")->fetchAll();
        json_response(['ok' => true, 'pending' => $rows, 'recent' => $done, 'on' => ll_key() !== '']);
    }
    case 'll.sug_act': {
        $auth = require_auth(); ll_admin($auth); ll_ensure($pdo);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
        $in = json_input(); $id = (int)($in['id'] ?? 0); $act = (string)($in['act'] ?? ''); $by = (string)($auth['username'] ?? 'admin');
        $q = $pdo->prepare("SELECT * FROM ai_suggest WHERE id = ? AND status = 'pending'"); $q->execute([$id]); $s = $q->fetch();
        if (!$s) json_response(['error' => 'That suggestion is already decided.'], 404);
        $text = trim((string)($in['text'] ?? '')); if ($text === '') $text = (string)$s['text'];
        $msg = 'Rejected.';
        if ($act === 'approve') {
            if ($s['kind'] === 'note') {
                if (!function_exists('brain_fact_add') && is_file(__DIR__ . '/brain.php')) require_once __DIR__ . '/brain.php';
                $fid = function_exists('brain_fact_add') ? brain_fact_add($pdo, $s['scope'], $s['area'], $text, $by, ['source' => 'review']) : 0;
                $msg = $fid ? 'Added to the Brain (' . $s['scope'] . ' shelf).' : 'The Brain already has this note.';
            } elseif ($s['kind'] === 'example') {
                $live = function_exists('lib_guide_row') ? lib_guide_row($pdo, 'live') : null;
                $ev = (string)$s['evidence'];
                $pdo->prepare("INSERT INTO lib_feedback (guide_version, rating, tag, customer, reply, better, stage, src, by_name, created_at) VALUES (?, -1, 'other', ?, '', ?, '', 'review', ?, NOW())")
                    ->execute([$live ? (int)$live['version'] : 0, mb_substr($ev, 0, 600), mb_substr($text, 0, 900), mb_substr($by, 0, 100)]);
                $msg = 'Saved as a correction. The next guidebook draft will learn from it.';
            } else {
                if (!function_exists('chg_create') && is_file(__DIR__ . '/changes.php')) require_once __DIR__ . '/changes.php';
                list($cok, $cmsg) = function_exists('chg_create') ? chg_create(mb_substr($text, 0, 100), $text . "\n\nEvidence from the nightly review:\n" . $s['evidence'], $by) : [false, 'Changes are not installed.', 0];
                if (!$cok) json_response(['error' => $cmsg], 422);
                $msg = $cmsg;
            }
            $pdo->prepare("UPDATE ai_suggest SET status='approved', decided_by=?, decided_at=NOW() WHERE id=?")->execute([$by, $id]);
        } else {
            $pdo->prepare("UPDATE ai_suggest SET status='rejected', decided_by=?, decided_at=NOW() WHERE id=?")->execute([$by, $id]);
        }
        json_response(['ok' => true, 'message' => $msg]);
    }
    case 'll.mark': {
        $auth = require_auth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
        $in = json_input(); $o = in_array(($in['outcome'] ?? ''), ['accepted', 'rejected'], true) ? $in['outcome'] : 'rejected';
        ll_log($pdo, 'action', (string)($auth['username'] ?? ''), (string)($in['label'] ?? ''), '', $o, mb_substr((string)($in['type'] ?? ''), 0, 40));
        json_response(['ok' => true]);
    }
}
json_response(['error' => 'Unknown action.'], 404);
