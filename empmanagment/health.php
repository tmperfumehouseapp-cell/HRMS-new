<?php
/*
 * health.php — two small read-only feeds. Keep this file in the SAME folder as api.php (public_html/empmanagment/).
 *
 *   needs.counts   the numbers behind the menu badges and the bell: customer WhatsApp messages waiting for this
 *                  login, WhatsApp chats nobody has taken (Admin / HR / Control Tower), shop visits waiting for approval,
 *                  and (Finance only) bank lines nobody has explained and COD / prepaid money past its date.
 *   health.get     the System health page (Admin only): server, database, files, the menu version and
 *                  when each automatic job last ran.
 *
 * Nothing here writes to the database. api.php loads this file only for needs. and health. actions.
 */
if (!isset($pdo, $action) || !function_exists('json_response') || !function_exists('require_auth')) { http_response_code(403); exit; }
if (strpos((string)$action, 'needs.') !== 0 && strpos((string)$action, 'health.') !== 0) { return; }

function hl_table(PDO $pdo, string $t): bool
{
    try { $q = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"); $q->execute([$t]); return (bool)$q->fetchColumn(); }
    catch (Throwable $e) { return false; }
}
function hl_age(int $ts): array
{
    if ($ts <= 0) return ['at' => '', 'mins' => null];
    return ['at' => date('Y-m-d H:i:s', $ts), 'mins' => (int)max(0, round((time() - $ts) / 60))];
}

$authHl = require_auth();

if ($action === 'needs.counts') {
    $out = ['wa_mine' => 0, 'wa_unassigned' => 0, 'visits_waiting' => 0, 'fin_review' => 0, 'fin_overdue' => 0];
    $emp = (int)($authHl['employee_id'] ?? 0);
    $role = (string)($authHl['role'] ?? '');
    $adm = false;
    try { $adm = (function_exists('convo_is_admin') && convo_is_admin($authHl)) || (function_exists('ct_admin_ok') && ct_admin_ok($pdo, $authHl)) || in_array($role, ['Admin', 'HR'], true); } catch (Throwable $e) { $adm = in_array($role, ['Admin', 'HR'], true); }
    try {
        if (hl_table($pdo, 'wa_contacts') && hl_table($pdo, 'wa_messages')) {
            if ($emp > 0) {   // customer messages not yet opened, in open chats given to this person
                $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages m JOIN wa_contacts c ON c.phone = m.phone WHERE m.dir = 'in' AND m.id > c.seen_msg_id AND c.assigned_emp = ? AND c.status = 'open' AND c.optout = 0");
                $q->execute([$emp]); $out['wa_mine'] = (int)$q->fetchColumn();
            }
            if ($adm) {       // chats a customer wrote in and nobody has taken yet
                $out['wa_unassigned'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts WHERE assigned_emp = 0 AND status = 'open' AND optout = 0 AND last_in_at IS NOT NULL")->fetchColumn();
            }
        }
    } catch (Throwable $e) { /* WhatsApp is not set up yet: the counts stay 0 */ }
    try {
        if (function_exists('user_can') && user_can($authHl, 'visit_approval') && hl_table($pdo, 'retail_visit_plan')) {
            if (function_exists('ensure_retail_visit_approval_columns')) ensure_retail_visit_approval_columns($pdo);
            $out['visits_waiting'] = (int)$pdo->query("SELECT COUNT(*) FROM retail_visit_plan WHERE status = 'done' AND approval = 'waiting'")->fetchColumn();
        }
    } catch (Throwable $e) { /* the visit plan is not set up yet */ }
    json_response($out);
}

if ($action === 'health.get') {
    require_role(['Admin'], $authHl);
    $dir = __DIR__;
    $r = ['now' => date('Y-m-d H:i:s'), 'build' => defined('HRMS_BUILD') ? HRMS_BUILD : '', 'layout_min' => defined('LAYOUT_MIN') ? LAYOUT_MIN : ''];

    // server
    $r['server'] = [
        'php' => PHP_VERSION, 'timezone' => date_default_timezone_get(), 'memory_limit' => (string)ini_get('memory_limit'),
        'upload_max_filesize' => (string)ini_get('upload_max_filesize'), 'post_max_size' => (string)ini_get('post_max_size'), 'max_execution_time' => (string)ini_get('max_execution_time'),
        'ext' => [], 'disk_free_mb' => @disk_free_space($dir) !== false ? (int)round(disk_free_space($dir) / 1048576) : null,
    ];
    foreach (['pdo_mysql', 'curl', 'gd', 'mbstring', 'fileinfo', 'zip', 'openssl'] as $e) $r['server']['ext'][$e] = extension_loaded($e);

    // database
    $db = ['ok' => false, 'name' => '', 'tables' => 0, 'size_mb' => 0, 'biggest' => []];
    try {
        $db['name'] = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        $q = $pdo->query("SELECT TABLE_NAME AS t, TABLE_ROWS AS n, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1048576, 1) AS mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC");
        $all = $q->fetchAll();
        $db['ok'] = true; $db['tables'] = count($all);
        foreach ($all as $t) $db['size_mb'] += (float)$t['mb'];
        $db['size_mb'] = round($db['size_mb'], 1);
        foreach (array_slice($all, 0, 8) as $t) $db['biggest'][] = ['table' => $t['t'], 'rows' => (int)$t['n'], 'mb' => (float)$t['mb']];
    } catch (Throwable $e) { $db['error'] = 'Could not read the database list.'; }
    $r['db'] = $db;

    // files api.php expects next to itself
    $expect = [
        'api.php' => 'the backend', 'config.php' => 'passwords and keys', 'boss.php' => 'Boss Engine', 'check.php' => 'Claude checks', 'office.php' => 'Offices', 'dept.php' => 'Departments',
        'flyers.php' => 'Flyers', 'health.php' => 'this page', 'leader_pay.php' => 'Leaders pay', 'leader_roas.php' => 'Leaders ROAS', 'ct_people.php' => 'Control Tower people',
        'wa.php' => 'WhatsApp', 'wa_more.php' => 'WhatsApp (numbers, contacts, campaigns)', 'finance_in.php' => 'Finance', 'wa_photos.php' => 'Photos', 'learn_backup.php' => 'Learning backups', 'wa_hist.php' => 'Old-chat reader', 'brain.php' => 'Company brain (Claude)',
    ];
    $files = [];
    foreach ($expect as $f => $why) $files[] = ['file' => $f, 'what' => $why, 'ok' => is_file($dir . '/' . $f)];
    $r['files'] = $files;
    $up = $dir . '/uploads';
    $r['folders'] = [
        ['name' => 'uploads', 'exists' => is_dir($up), 'writable' => is_dir($up) && is_writable($up)],
        ['name' => 'learn_backups', 'exists' => is_dir($dir . '/learn_backups'), 'writable' => is_dir($dir . '/learn_backups') && is_writable($dir . '/learn_backups')],
    ];

    // the menu file the browsers load, and the version it reports
    $lay = ['found' => false, 'build' => '', 'ok' => false];
    foreach ([dirname($dir) . '/assets/js/layout.js', $dir . '/assets/js/layout.js'] as $p) {
        if (is_file($p)) { $lay['found'] = true; $txt = (string)@file_get_contents($p, false, null, 0, 4000); if (preg_match("/LAYOUT_BUILD\s*=\s*'([^']+)'/", $txt, $m)) $lay['build'] = $m[1]; break; }
    }
    $lay['ok'] = $lay['build'] !== '' && $r['layout_min'] !== '' && strcmp($lay['build'], $r['layout_min']) >= 0;
    $r['layout'] = $lay;

    // automatic jobs: there is no cron, so each one runs when someone opens a page
    $tmp = sys_get_temp_dir();
    $jobs = [
        ['Phone reminders', 'hrms_push_tick', 10], ['Automatic penalties (late / short hours)', 'hrms_penalty_pass', 10],
        ['Control Tower pass', 'hrms_ct_tick', 15], ['Shop payment reminders', 'hrms_retail_due_pass', 30],
        ['Punch-in proof cleanup', 'hrms_proof_cleanup_42h', 3000], ['Attendance photo cleanup', 'hrms_att_photo_cleanup', 3000],
    ];
    $out = [];
    foreach ($jobs as $j) { $ts = (int)@filemtime($tmp . '/' . $j[1]); $a = hl_age($ts); $out[] = ['job' => $j[0], 'at' => $a['at'], 'mins' => $a['mins'], 'limit' => $j[2]]; }
    $lb = (int)@filemtime($dir . '/learn_backups/.last'); $a = hl_age($lb);
    $out[] = ['job' => 'Daily copy of everything the bot learned', 'at' => $a['at'], 'mins' => $a['mins'], 'limit' => 1560];
    try {
        if (hl_table($pdo, 'bd_settings')) {
            $bd = []; foreach ($pdo->query("SELECT skey, svalue FROM bd_settings WHERE skey IN ('last_tick', 'last_scan', 'last_digest', 'mode')")->fetchAll() as $x) $bd[$x['skey']] = (string)$x['svalue'];
            foreach ([['Boss Engine tick', 'last_tick', 15], ['Boss Engine page scan', 'last_scan', 3000], ['Boss Engine hourly digest', 'last_digest', 180]] as $j) {
                $ts = !empty($bd[$j[1]]) ? (int)strtotime($bd[$j[1]]) : 0; $a = hl_age($ts); $out[] = ['job' => $j[0], 'at' => $a['at'], 'mins' => $a['mins'], 'limit' => $j[2]];
            }
            $r['boss_mode'] = $bd['mode'] ?? '';
        }
    } catch (Throwable $e) { /* the Boss Engine is not installed */ }
    $r['jobs'] = $out;

    // things that are unsafe to leave on
    $warn = [];
    if (is_file($dir . '/boss_install.php') || is_file(dirname($dir) . '/boss_install.php')) $warn[] = 'An installer file (boss_install.php) is still on the server: delete it.';
    foreach (['hrms2_install.php', 'hrms3_install.php', 'ws_install.php', 'flyers_patch.php'] as $f) if (is_file($dir . '/' . $f) || is_file(dirname($dir) . '/' . $f)) $warn[] = 'An installer file (' . $f . ') is still on the server: delete it.';
    try { $adm = $pdo->prepare("SELECT 1 FROM users WHERE username = 'admin' LIMIT 1"); $adm->execute(); if (!$adm->fetchColumn()) $warn[] = 'There is no user named "admin". The setup_admin action would create one with a known password if called: keep an admin user, or ask for that action to be locked.'; } catch (Throwable $e) {}
    $r['warnings'] = $warn;
    json_response($r);
}

json_response(['error' => 'Unknown action.'], 404);
