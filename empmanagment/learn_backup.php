<?php
/**
 * learn_backup.php — Sales → Learn & Setup → 🛟 Backups: a copy of everything the bot has learned, saved every 24 hours.
 * Loaded by api.php (keep this file in the SAME folder as api.php):
 *   - for  lb.*  actions (the Backups page), and
 *   - from any normal request when the last backup is older than 24 hours (the copy is made AFTER the page has been answered, so nothing waits).
 *
 *   lb.list      GET                      the saved copies (newest first) + when the next one is due
 *   lb.run       POST                     make a copy now
 *   lb.download  GET  {name}              download one copy (a .json.gz file you can keep anywhere)
 *   lb.restore   POST {name}              put a copy back (a safety copy of the current state is made first)
 *   lb.del       POST {name}              delete one copy
 *
 * What is copied: the guide book (every version), taught lessons, facts, feedback, the company brain (facts, rules, summaries, voice, examples, replaced notes), what the bot learned from chats, follow-up rules,
 * the photo list (names, sections, your confirmations — the picture files themselves stay in wa_files) and the learning settings.
 * Passwords / keys are never copied. Copies live OUTSIDE the website folder (private/learn_backups, next to the site root) so a visitor cannot download them. The newest 30 daily copies are kept. Admin download still goes through lb.download.
 * Only Admin / HR / control tower can open the page.
 */
if (!function_exists('lb_dir')) {

if (!defined('LB_KEEP')) define('LB_KEEP', 30);
if (!defined('LB_TABLES')) define('LB_TABLES', ['lib_guide', 'lib_feedback', 'lib_teach', 'lib_facts', 'lib_best', 'lib_replay', 'wa_learn', 'wa_learn_hist', 'wa_fu_rules', 'ph_photos', 'ph_names', 'ph_skip', 'ph_concepts', 'wa_media_lib',
    'sxl_facts', 'sxl_brain', 'sxl_events', 'sxl_asked', 'sxl_voice', 'sxl_topics', 'sxl_examples', 'brain_old', 'brain_kv']);   // the last nine = the company brain (what was taught to the customer and team Claude)
/** Settings rows that hold learning / bot set-up (never anything that looks like a key or password). */
function lb_setting_like(): array { return ['convo\_%', 'lib\_%', 'combo\_%', 'ml\_%', 'flash\_%', 'addon\_%', 'roas\_%', 'lr\_%', 'ct\_cfg', 'wa\_fu\_%', 'wa\_hot\_words', 'wa\_autosend%', 'wa\_sales\_%', 'wa\_team', 'wa\_reassign']; }
function lb_secret(string $k): bool { return (bool)preg_match('/key|pem|secret|token|pass|vapid|pub$/i', $k); }

function lb_htaccess(string $dir): void
{
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
}
function lb_dir(): string
{
    // Parent of the site folder is not served by nginx or Apache. The old folder next to api.php is public, and nginx ignores .htaccess.
    $private = dirname(__DIR__, 2) . '/private/learn_backups';
    $legacy = __DIR__ . '/learn_backups';
    if (!is_dir($private)) @mkdir($private, 0750, true);
    lb_htaccess($legacy);
    if (is_dir($private) && is_writable($private)) {
        if (is_dir($legacy)) {
            foreach (glob($legacy . '/learn_*') ?: [] as $f) {
                if (!is_file($f)) continue;
                $dest = $private . '/' . basename($f);
                if (!is_file($dest)) @rename($f, $dest);
            }
            if (is_file($legacy . '/.last') && !is_file($private . '/.last')) @rename($legacy . '/.last', $private . '/.last');
        }
        return $private;
    }
    if (!is_dir($legacy)) @mkdir($legacy, 0755, true);
    lb_htaccess($legacy);
    return $legacy;
}
function lb_can(PDO $pdo, array $auth): bool { return convo_is_admin($auth) || ct_admin_ok($pdo, $auth) || in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true); }
function lb_name_ok(string $n): bool { return (bool)preg_match('/^learn_\d{4}-\d{2}-\d{2}_\d{4}_(auto|manual|before)\.json(\.gz)?$/', $n); }
function lb_has_table(PDO $pdo, string $t): bool { try { $q = $pdo->prepare("SHOW TABLES LIKE ?"); $q->execute([$t]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return false; } }

/** Make one copy. Returns [name|'' , error, counts]. */
function lb_make(PDO $pdo, string $kind): array
{
    @set_time_limit(180);
    $dir = lb_dir(); if (!is_dir($dir) || !is_writable($dir)) return ['', 'The server cannot write in the learn_backups folder. Create it next to api.php and allow writing (permission 755).', []];
    $data = ['meta' => ['made' => date('Y-m-d H:i:s'), 'kind' => $kind, 'version' => 1], 'tables' => [], 'settings' => []]; $counts = [];
    foreach (LB_TABLES as $t) {
        if (!lb_has_table($pdo, $t)) continue;
        try {
            $cols = array_map(fn($c) => (string)$c['Field'], $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll());
            $rows = []; $st = $pdo->query("SELECT * FROM `$t`");
            while ($r = $st->fetch(PDO::FETCH_NUM)) $rows[] = $r;
            $data['tables'][$t] = ['cols' => $cols, 'rows' => $rows]; $counts[$t] = count($rows);
        } catch (Throwable $e) { error_log('lb_make ' . $t . ': ' . $e->getMessage()); }
    }
    try {
        $where = implode(' OR ', array_fill(0, count(lb_setting_like()), 'setting_key LIKE ?'));
        $st = $pdo->prepare("SELECT setting_key, setting_value FROM hrms_settings WHERE $where"); $st->execute(lb_setting_like());
        foreach ($st->fetchAll() as $r) if (!lb_secret((string)$r['setting_key'])) $data['settings'][(string)$r['setting_key']] = $r['setting_value'];
        $counts['settings'] = count($data['settings']);
    } catch (Throwable $e) {}
    $data['meta']['counts'] = $counts;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false || $json === '') return ['', 'Could not prepare the copy.', []];
    $gz = function_exists('gzencode');
    $name = 'learn_' . date('Y-m-d_Hi') . '_' . $kind . ($gz ? '.json.gz' : '.json');
    $i = 1; while (is_file($dir . '/' . $name)) { $name = 'learn_' . date('Y-m-d_Hi', time() + 60 * $i) . '_' . $kind . ($gz ? '.json.gz' : '.json'); $i++; }
    if (@file_put_contents($dir . '/' . $name, $gz ? gzencode($json, 6) : $json) === false) return ['', 'Could not save the copy on the server.', []];
    @file_put_contents($dir . '/' . $name . '.meta', json_encode(['made' => $data['meta']['made'], 'kind' => $kind, 'counts' => $counts]));
    @file_put_contents($dir . '/.last', (string)time()); @touch($dir . '/.last');
    lb_prune();
    return [$name, '', $counts];
}
/** Keep the newest LB_KEEP daily copies (manual copies and safety copies are kept longer: the newest 30 of each). */
function lb_prune(): void
{
    $dir = lb_dir(); $by = [];
    foreach (glob($dir . '/learn_*.json*') ?: [] as $f) { $n = basename($f); if (substr($n, -5) === '.meta' || !lb_name_ok($n)) continue; preg_match('/_(auto|manual|before)\.json/', $n, $m); $by[$m[1]][] = $n; }
    foreach ($by as $list) { rsort($list); foreach (array_slice($list, LB_KEEP) as $old) { @unlink($dir . '/' . $old); @unlink($dir . '/' . $old . '.meta'); } }
}
function lb_list(): array
{
    $dir = lb_dir(); $out = [];
    foreach (glob($dir . '/learn_*.json*') ?: [] as $f) {
        $n = basename($f); if (substr($n, -5) === '.meta' || !lb_name_ok($n)) continue;
        $m = json_decode((string)@file_get_contents($f . '.meta'), true); if (!is_array($m)) $m = [];
        preg_match('/^learn_(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})_(auto|manual|before)/', $n, $x);
        $out[] = ['name' => $n, 'size' => (int)filesize($f), 'made' => (string)($m['made'] ?? ($x ? $x[1] . ' ' . $x[2] . ':' . $x[3] . ':00' : '')), 'kind' => (string)($m['kind'] ?? ($x[4] ?? 'auto')), 'counts' => (array)($m['counts'] ?? [])];
    }
    usort($out, fn($a, $b) => strcmp($b['made'], $a['made']) ?: strcmp($b['name'], $a['name']));
    return $out;
}
/** Called from every normal request: when the last copy is older than 24 hours, make one after the response is out. */
function lb_schedule(PDO $pdo): void
{
    $dir = lb_dir(); $f = $dir . '/.last';
    if (is_file($f) && filemtime($f) > time() - 86400) return;
    @touch($f);        // claim it now, so two requests at the same moment do not both make a copy
    register_shutdown_function(function () use ($pdo) {
        try { if (function_exists('hrms_release')) hrms_release(); elseif (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); lb_make($pdo, 'auto'); } catch (Throwable $e) { error_log('lb auto: ' . $e->getMessage()); @unlink(lb_dir() . '/.last'); }
    });
}
function lb_read(string $name): ?array
{
    if (!lb_name_ok($name)) return null; $f = lb_dir() . '/' . $name; if (!is_file($f)) return null;
    $raw = (string)file_get_contents($f); if (substr($name, -3) === '.gz') { $raw = function_exists('gzdecode') ? (string)@gzdecode($raw) : ''; }
    $d = json_decode($raw, true); return is_array($d) && isset($d['tables']) ? $d : null;
}
/** Put a copy back: every table in the copy is emptied and refilled; settings are written back (other settings stay). Returns [ok, error, counts]. */
function lb_restore(PDO $pdo, array $d): array
{
    @set_time_limit(180); $counts = [];
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0"); $pdo->beginTransaction();
        foreach ((array)$d['tables'] as $t => $blk) {
            if (!in_array($t, LB_TABLES, true) || !lb_has_table($pdo, $t) || !is_array($blk) || empty($blk['cols'])) continue;
            $have = array_map(fn($c) => (string)$c['Field'], $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll());
            $use = []; foreach ((array)$blk['cols'] as $i => $c) if (in_array($c, $have, true)) $use[$i] = $c;
            if (!$use) continue;
            $pdo->exec("DELETE FROM `$t`");
            $ins = $pdo->prepare("INSERT INTO `$t` (" . implode(',', array_map(fn($c) => "`$c`", $use)) . ") VALUES (" . implode(',', array_fill(0, count($use), '?')) . ")");
            $n = 0; foreach ((array)$blk['rows'] as $row) { $vals = []; foreach ($use as $i => $c) $vals[] = $row[$i] ?? null; $ins->execute($vals); $n++; }
            $counts[$t] = $n;
        }
        $up = $pdo->prepare("INSERT INTO hrms_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"); $ns = 0;
        foreach ((array)($d['settings'] ?? []) as $k => $v) { if (lb_secret((string)$k)) continue; $up->execute([(string)$k, $v]); $ns++; }
        $counts['settings'] = $ns;
        $pdo->commit(); $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (Throwable $e2) {}
        return [false, 'Restore failed and nothing was changed: ' . mb_substr($e->getMessage(), 0, 200), []];
    }
    return [true, '', $counts];
}

}   // function definitions

$__lb = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__lb, 'lb.') !== 0) return;
$authLb = require_auth();
if (!lb_can($pdo, $authLb)) json_response(['error' => 'Only Admin / HR can open the backups.'], 403);
$postLb = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($__lb === 'lb.list') {
    $last = lb_dir() . '/.last'; $ts = is_file($last) ? filemtime($last) : 0;
    json_response(['backups' => lb_list(), 'last' => $ts ? date('Y-m-d H:i:s', $ts) : null, 'next' => $ts ? date('Y-m-d H:i:s', $ts + 86400) : null, 'keep' => LB_KEEP, 'now' => date('Y-m-d H:i:s'),
        'gz' => function_exists('gzencode'), 'writable' => is_writable(lb_dir())]);
}
if ($__lb === 'lb.download') {
    $n = (string)($_GET['name'] ?? ''); if (!lb_name_ok($n) || !is_file(lb_dir() . '/' . $n)) json_response(['error' => 'Copy not found.'], 404);
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="' . $n . '"'); header('Content-Length: ' . filesize(lb_dir() . '/' . $n)); readfile(lb_dir() . '/' . $n); exit;
}
if (!$postLb) json_response(['error' => 'Method not allowed.'], 405);
if ($__lb === 'lb.run') {
    [$n, $e, $c] = lb_make($pdo, 'manual'); if ($n === '') json_response(['error' => $e], 500);
    json_response(['ok' => true, 'name' => $n, 'counts' => $c]);
}
if ($__lb === 'lb.restore') {
    $n = (string)(json_input()['name'] ?? ''); $d = lb_read($n); if (!$d) json_response(['error' => 'Copy not found or unreadable.'], 404);
    [$sn, $se] = lb_make($pdo, 'before'); if ($sn === '') json_response(['error' => 'Could not make the safety copy first, so nothing was restored. ' . $se], 500);
    [$ok, $e, $c] = lb_restore($pdo, $d); if (!$ok) json_response(['error' => $e], 500);
    json_response(['ok' => true, 'counts' => $c, 'safety' => $sn]);
}
if ($__lb === 'lb.del') {
    $n = (string)(json_input()['name'] ?? ''); if (!lb_name_ok($n) || !is_file(lb_dir() . '/' . $n)) json_response(['error' => 'Copy not found.'], 404);
    @unlink(lb_dir() . '/' . $n); @unlink(lb_dir() . '/' . $n . '.meta'); json_response(['ok' => true]);
}
json_response(['error' => 'Unknown action.'], 404);