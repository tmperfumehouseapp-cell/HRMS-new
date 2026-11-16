<?php
/**
 * wq_auto.php — AUTO ASSIGN (Sales → Team performance). Loaded by wa_more.php. PHP 7.4 safe.
 *
 * New queries are given to salespeople by themselves, by these rules (the pure maths is in wq_auto_rules.php, tested by tests/waa_test.php):
 *   • only salespeople (Sales department) who punched in; a button per person: ON at punch-in, Admin / Sales Head can turn it OFF (no queries) or ON (no other rule applies)
 *   • RATIO: company average ratio of the business day (10 am – 10 am); a person more than 5 points below it (after 6 fresh queries) is switched OFF for FRESH queries by itself,
 *     and stays OFF until Admin / Sales Head turn the button ON
 *   • SPEED: twice as slow as the team to answer = half the queries
 *   • TARGET: fresh queries in proportion to the day's target
 *   • CAMPAIGN queries: equal for everybody, 2× for people below the band (the button never stops campaign queries; only Admin / Sales Head OFF or no punch-in does)
 *   • OVERNIGHT (12 am – 10 am): anybody may pick from Requests; a person below the band at most 5
 * Numbers (queries, orders, campaign queries / orders, target, punch-in) are Sales → Live's own day rows (cp_day_rows) — the same ones Team performance shows.
 * Actions: wq.aa_get (the table), wq.aa_set (the button).
 */
require_once __DIR__ . '/wq_auto_rules.php';

function waa_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS waa_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, phone VARCHAR(12) NOT NULL, emp_id INT NOT NULL, kind VARCHAR(8) NOT NULL, why VARCHAR(160) NOT NULL DEFAULT '', KEY k_at (at, kind, emp_id), KEY k_phone (phone, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); return $ok = true; }
    catch (Throwable $e) { error_log('waa_tables: ' . $e->getMessage()); return $ok = false; }
}
/** The master switch. OFF until the owner starts it (setting waa_off = 0): the table and the buttons work, but nothing is given out and no overnight limit applies. */
function waa_engine_on(PDO $pdo): bool { return (string)setting_get($pdo, 'waa_off', '1') === '0' && (string)setting_get($pdo, 'waa_on_day', '') === waa_day($pdo); }   // started for THIS business day (10 am → 10 am): it must be started again every morning
function waa_dt(int $t): string { return date('Y-m-d H:i:s', $t); }
function waa_day(PDO $pdo): string { return function_exists('biz_today') ? (string)biz_today($pdo) : date('Y-m-d'); }
/** Start of the business day (10 am by the panel's day timing). */
function waa_day_start(PDO $pdo): int
{
    if (function_exists('wq_day_start')) { try { return (int)wq_day_start($pdo, waa_day($pdo)); } catch (Throwable $e) { } }
    return (int)strtotime(date('G') >= 10 ? date('Y-m-d 10:00:00') : date('Y-m-d 10:00:00', time() - 86400));
}

/** Everybody in the Sales department who still works here. */
function waa_sales(PDO $pdo): array
{
    try { return array_map('intval', $pdo->query("SELECT e.employee_id FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.status != 'ex' AND d.department_name LIKE '%sales%'")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { return []; }
}
/** Who is at work: punched in and not out (leave / holiday / week-off marks take a person out unless they punched in anyway; a work-from-home mark counts). [emp => 1] */
function waa_present(PDO $pdo): array
{
    $o = []; try { foreach ($pdo->query("SELECT DISTINCT employee_id FROM attendance WHERE punch_in IS NOT NULL AND punch_out IS NULL AND punch_in >= '" . waa_dt(time() - 20 * 3600) . "'")->fetchAll(PDO::FETCH_COLUMN) as $e) $o[(int)$e] = 1; } catch (Throwable $e) { }
    $day = waa_day($pdo); $wm = json_decode((string)setting_get($pdo, 'ct_wm_' . $day, ''), true); if (!is_array($wm)) $wm = [];
    $mk = []; if (function_exists('day_marks_between')) { try { $mk = day_marks_between($pdo, $day, $day); } catch (Throwable $e) { } }
    foreach (waa_sales($pdo) as $e) {
        if (isset($o[$e])) continue; $w = (string)($wm[$e] ?? $wm[(string)$e] ?? ''); $m = (string)($mk[$day . '|' . $e] ?? '');
        if ($w === 'off' || in_array($m, ['leave', 'holiday', 'week_off'], true)) continue;
        if ($m === 'wfh' || $w === 'on') $o[$e] = 1;
    }
    return $o;
}
/** Sales → Live's day rows of today's business day, kept 45 seconds. [emp => [q, qo, cq, co, t, ar, …]] */
function waa_cp(PDO $pdo, bool $fresh = false): array
{
    $day = waa_day($pdo);
    if (!$fresh) { $j = json_decode((string)setting_get($pdo, 'waa_cp', ''), true); if (is_array($j) && ($j['day'] ?? '') === $day && (int)($j['at'] ?? 0) > time() - 45) return (array)$j['rows']; }
    if (!function_exists('cp_day_rows') && is_file(__DIR__ . '/ct_people.php')) { $GLOBALS['cp_funcs_only'] = true; try { require_once __DIR__ . '/ct_people.php'; } catch (Throwable $e) { } unset($GLOBALS['cp_funcs_only']); }
    $rows = []; if (function_exists('cp_day_rows')) { try { $rows = (array)cp_day_rows($pdo, $day); } catch (Throwable $e) { error_log('waa_cp: ' . $e->getMessage()); } }
    if ($rows) setting_set($pdo, 'waa_cp', json_encode(['day' => $day, 'at' => time(), 'rows' => $rows]));
    return $rows;
}
/** Typical first-reply minutes per person (last 7 days), kept 30 minutes. [emp => minutes] */
function waa_speed(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'waa_sp', ''), true); if (is_array($j) && (int)($j['at'] ?? 0) > time() - 1800) return (array)$j['m'];
    $m = []; try { if (function_exists('wq_pace_get')) foreach ((array)(wq_pace_get($pdo, 7)['people'] ?? []) as $p) { $v = $p['pace']['median'] ?? null; if ($v !== null && (float)$v > 0) $m[(int)$p['emp_id']] = (float)$v; } } catch (Throwable $e) { }
    setting_set($pdo, 'waa_sp', json_encode(['at' => time(), 'm' => $m])); return $m;
}
function waa_median(array $v): ?float { $v = array_values(array_filter($v, function ($x) { return $x > 0; })); if (!$v) return null; sort($v); $n = count($v); return $n % 2 ? (float)$v[($n - 1) / 2] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2; }

/** The buttons chosen today: ['day' => …, 'e' => [emp => ['m' => 'on'|'off'|'', 'by' => id, 'at' => time, 'ao' => 0|1, 'ao_at' => time]]]. A new business day starts clean (the button turns ON again at punch-in). */
function waa_state(PDO $pdo): array
{
    $day = waa_day($pdo); $j = json_decode((string)setting_get($pdo, 'waa_st', ''), true);
    return (is_array($j) && ($j['day'] ?? '') === $day) ? ['day' => $day, 'e' => (array)($j['e'] ?? [])] : ['day' => $day, 'e' => []];
}
function waa_state_put(PDO $pdo, array $st): void { setting_set($pdo, 'waa_st', json_encode($st, JSON_UNESCAPED_UNICODE)); }

/** Queries given by this engine since the business day started: [emp => ['fresh' => n, 'camp' => n]]. */
function waa_tally(PDO $pdo): array
{
    $o = []; try { $q = $pdo->prepare("SELECT emp_id, kind, COUNT(*) c FROM waa_log WHERE at >= ? GROUP BY emp_id, kind"); $q->execute([waa_dt(waa_day_start($pdo))]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['emp_id']][(string)$r['kind']] = (int)$r['c']; } catch (Throwable $e) { }
    return $o;
}

/** Everything the table and the engine need, in one place. */
function waa_view(PDO $pdo, bool $fresh = false): array
{
    $cp = waa_cp($pdo, $fresh); $sales = waa_sales($pdo); $pres = waa_present($pdo); $st = waa_state($pdo); $sp = waa_speed($pdo);
    $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
    $tq = 0; $tqo = 0; foreach ($sales as $e) { $x = $cp[$e] ?? null; if ($x && empty($x['off'])) { $tq += (int)$x['q']; $tqo += (int)$x['qo']; } }
    $team = $tq > 0 ? $tqo / $tq : 0.0; $teamSpeed = waa_median(array_values(array_filter(array_map(function ($e) use ($sp, $pres) { return isset($pres[$e]) ? ($sp[$e] ?? null) : null; }, $sales))));
    $tg = []; foreach ($sales as $e) if (isset($pres[$e]) && (float)($cp[$e]['t'] ?? 0) > 0) $tg[] = (float)$cp[$e]['t']; $avgT = $tg ? array_sum($tg) / count($tg) : 0.0;
    $people = [];
    foreach ($sales as $e) {
        $x = $cp[$e] ?? []; $s = $st['e'][$e] ?? $st['e'][(string)$e] ?? []; $q = (int)($x['q'] ?? 0); $qo = (int)($x['qo'] ?? 0);
        $p = ['emp_id' => $e, 'name' => (string)($names[$e] ?? ('#' . $e)), 'present' => isset($pres[$e]), 'm' => (string)($s['m'] ?? ''), 'ao' => !empty($s['ao']) ? 1 : 0, 't' => (float)($x['t'] ?? 0), 'q' => $q, 'qo' => $qo, 'cq' => (int)($x['cq'] ?? 0), 'co' => (int)($x['co'] ?? 0),
              'ratio' => $q > 0 ? round(100 * $qo / $q, 1) : null, 'punch' => isset($x['ar']) && $x['ar'] !== null ? (string)$x['ar'] : '', 'speed' => $sp[$e] ?? null, 'team_speed' => $teamSpeed];
        $p['below'] = waa_below($q, $qo, $team) ? 1 : 0; [$on, $why] = waa_button($p); $p['on'] = $on; $p['why'] = $why;
        $people[$e] = $p;
    }
    return ['day' => $st['day'], 'team_ratio' => round(100 * $team, 1), 'floor' => round(100 * waa_floor($team), 1), 'team_speed' => $teamSpeed, 'avg_target' => $avgT, 'people' => $people, 'state' => $st];
}

/** One engine pass: (1) the ratio rule switches people off, (2) waiting queries are given out. Light: reads Live's cached rows. */
function waa_tick(PDO $pdo): array
{
    try { if ((int)setting_get($pdo, 'waa_seen_at', '0') < time() - 30) setting_set($pdo, 'waa_seen_at', (string)time()); } catch (Throwable $e) { }   // proof for the check workflow: the engine reached this function
    if (!waa_tables($pdo)) return ['error' => 'storage'];
    if (!waa_engine_on($pdo)) return ['off' => 1];
    $now = time(); if ((int)setting_get($pdo, 'waa_run_at', '0') > $now - 20) return ['skipped' => 'recent'];
    $got = 0; try { $got = (int)$pdo->query("SELECT GET_LOCK('waa_run', 0)")->fetchColumn(); } catch (Throwable $e) { } if ($got !== 1) return ['skipped' => 'busy'];
    $res = ['given' => 0, 'switched_off' => 0]; $dbg = ['scanned' => 0, 'no_contact' => 0, 'held' => 0, 'not_open' => 0, 'optout_cx' => 0, 'old' => 0, 'human' => 0, 'biz' => 0, 'nobody' => 0, 'give_error' => '', 'fresh_ok' => 0, 'camp_ok' => 0, 'present' => 0];
    try {
        setting_set($pdo, 'waa_run_at', (string)$now);
        $V = waa_view($pdo); $st = $V['state']; $P = &$V['people'];
        // (1) the ratio rule: below the band → OFF by itself, until Admin / Sales Head turn it ON
        foreach ($P as $e => &$p) { if ($p['present'] && $p['m'] === '' && $p['below'] && !$p['ao']) { $st['e'][$e] = array_merge((array)($st['e'][$e] ?? []), ['m' => '', 'ao' => 1, 'ao_at' => $now]); $p['ao'] = 1; [$p['on'], $p['why']] = waa_button($p); $res['switched_off']++; } } unset($p);
        if ($res['switched_off']) waa_state_put($pdo, $st);
        // (2) waiting queries: nobody holds them, the customer wrote in the last 24 h, no person answered yet
        $since = waa_dt($now - 86400 + 600); $st0 = $pdo->prepare("SELECT id, phone, src, opened_at FROM wa_queries WHERE emp_id = 0 AND src IN ('query', 'campaign') AND opened_at >= ? AND opened_at <= ? ORDER BY id DESC LIMIT 250");   // NEWEST first: the oldest ones are mostly closed / answered / past the 24 h window, and 60 of them used to fill the whole list so nothing newer was ever looked at $st0->execute([$since, waa_dt($now - 10)]); $qs = $st0->fetchAll(PDO::FETCH_ASSOC);
        if ($qs) {
            $phs = array_values(array_unique(array_map(function ($r) { return (string)$r['phone']; }, $qs))); $C = [];
            $cq = $pdo->prepare("SELECT phone, name, assigned_emp, last_in_at, status, optout, cx_id, tag FROM wa_contacts WHERE phone IN (" . implode(',', array_fill(0, count($phs), '?')) . ")"); $cq->execute($phs); foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $r) $C[(string)$r['phone']] = $r;
            $humanSql = function_exists('wq_human_sql') ? wq_human_sql('h', 'c', 'last_in_at') : "h.dir = 'out' AND h.emp_id > 0";
            $hq = $pdo->prepare("SELECT 1 FROM wa_contacts c JOIN wa_messages h ON h.phone = c.phone WHERE c.phone = ? AND $humanSql LIMIT 1"); $bq = $pdo->prepare("SELECT 1 FROM wa_ctags WHERE phone = ? AND tag = 'business_autoreply' LIMIT 1");
            $T = waa_tally($pdo); $avgT = (float)$V['avg_target'];
            foreach ($P as $p0) { if ($p0['present']) $dbg['present']++; if ($p0['on']) $dbg['fresh_ok']++; if ($p0['present'] && $p0['m'] !== 'off') $dbg['camp_ok']++; }
            foreach ($qs as $r) {
                if ($res['given'] >= 40) break;   // at most 40 a pass (one pass a minute)
                $dbg['scanned']++; $ph = (string)$r['phone']; $c = $C[$ph] ?? null; if (!$c) { $dbg['no_contact']++; continue; } if ((int)$c['assigned_emp'] > 0) { $dbg['held']++; continue; } if ((string)$c['status'] !== 'open') { $dbg['not_open']++; continue; } if ((int)$c['optout'] === 1 || (int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint') { $dbg['optout_cx']++; continue; }
                if (!$c['last_in_at'] || strtotime((string)$c['last_in_at']) < $now - 86400 + 600) { $dbg['old']++; continue; }
                $hq->execute([$ph]); if ($hq->fetchColumn()) { $dbg['human']++; continue; } $bq->execute([$ph]); if ($bq->fetchColumn()) { $dbg['biz']++; continue; }
                $camp = (string)$r['src'] === 'campaign'; $cand = [];
                foreach ($P as $e => $p) {
                    if ($camp) { if (!$p['present'] || $p['m'] === 'off') continue; $w = waa_weight_camp($p); $cnt = (int)($T[$e]['camp'] ?? 0); }
                    else { if (!$p['on']) continue; $w = waa_weight_fresh($p, $avgT); $cnt = max((int)$p['q'], (int)($T[$e]['fresh'] ?? 0)); }
                    $cand[$e] = ['w' => $w, 'cnt' => $cnt];
                }
                $to = waa_pick($cand, $camp ? 0 : WAA_MIN_Q); if ($to <= 0) { $dbg['nobody']++; continue; }
                try {
                    wa_assign_to($pdo, $ph, $to, 0, 'auto');
                    $pdo->prepare("INSERT INTO waa_log (at, phone, emp_id, kind, why) VALUES (?, ?, ?, ?, ?)")->execute([waa_dt($now), $ph, $to, $camp ? 'camp' : 'fresh', mb_substr(($camp ? 'campaign query' : 'fresh query') . ' · weight ' . round($cand[$to]['w']) . ' · so far ' . $cand[$to]['cnt'], 0, 160)]);
                    $T[$to][$camp ? 'camp' : 'fresh'] = (int)($T[$to][$camp ? 'camp' : 'fresh'] ?? 0) + 1; if (!$camp) $P[$to]['q']++; $res['given']++;
                } catch (Throwable $e) { error_log('waa give: ' . $e->getMessage()); $dbg['give_error'] = mb_substr($e->getMessage(), 0, 160); }
            }
        }
    } catch (Throwable $e) { error_log('waa_tick: ' . $e->getMessage()); $res['error'] = mb_substr($e->getMessage(), 0, 160); }
    try { setting_set($pdo, 'waa_last', json_encode(['at' => time(), 'res' => $res, 'dbg' => $dbg])); } catch (Throwable $e) { }   // what the last pass saw (for the check workflow)
    finally { try { $pdo->query("SELECT RELEASE_LOCK('waa_run')"); } catch (Throwable $e) { } }
    return $res;
}

/** Overnight (12 am – 10 am): a person below the band may take at most 5 chats from Requests. '' = may take, else the reason. Admin / Sales Head ON = no limit. */
function waa_pick_block(PDO $pdo, int $emp): string
{
    try {
        if (!waa_night((int)date('G')) || !waa_engine_on($pdo) || !in_array($emp, waa_sales($pdo), true) || !waa_tables($pdo)) return '';
        $V = waa_view($pdo); $p = $V['people'][$emp] ?? null; if (!$p) return '';
        $below = (bool)($p['below'] || $p['ao']); if (!$below || $p['m'] === 'on') return '';
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_assign_log WHERE to_emp = ? AND by_emp = ? AND how = 'request' AND at >= ?"); $q->execute([$emp, $emp, date('Y-m-d 00:00:00')]);
        return waa_night_block(true, false, (int)$q->fetchColumn()) ? 'Your ratio is below the team band, so overnight you can take at most ' . WAA_NIGHT_MAX . ' chats from Requests. Ask your Sales Head if you need more.' : '';
    } catch (Throwable $e) { return ''; }
}

// ---------------------------------------------------------------------------------------------------------------- actions
$__waa = (string)($action ?? ($_GET['action'] ?? ''));
if ($__waa === 'wq.aa_get' || $__waa === 'wq.aa_set' || $__waa === 'wq.aa_engine') {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    if (!waa_tables($pdo)) json_response(['error' => 'Storage for Auto assign is not available.'], 500);
    $adm = function_exists('wa_is_admin') ? wa_is_admin($pdo, $auth) : in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
    if (!$adm && function_exists('wa_boss_ok') && wa_boss_ok($pdo, $auth)) $adm = true;   // Sales Heads too
    if (!$adm) json_response(['error' => 'Only Admin, HR or a Sales Head can open Auto assign.'], 403);
    if ($__waa === 'wq.aa_engine') {   // Start / Stop the whole engine
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $in = json_input(); setting_set($pdo, 'waa_off', !empty($in['on']) ? '0' : '1'); setting_set($pdo, 'waa_on_day', !empty($in['on']) ? waa_day($pdo) : ''); setting_set($pdo, 'waa_run_at', '0');
        if (function_exists('wa_log')) { $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; wa_log($pdo, 'engine', true, 'auto assign engine ' . (!empty($in['on']) ? 'STARTED' : 'STOPPED') . ' by ' . ($nm[$me] ?? $me)); }
    }
    if ($__waa === 'wq.aa_set') {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $in = json_input(); $emp = (int)($in['emp_id'] ?? 0); $on = !empty($in['on']);
        if (!in_array($emp, waa_sales($pdo), true)) json_response(['error' => 'This person is not in the Sales department.'], 422);
        $st = waa_state($pdo); $st['e'][$emp] = ['m' => $on ? 'on' : 'off', 'by' => $me, 'at' => time(), 'ao' => 0];
        waa_state_put($pdo, $st); setting_set($pdo, 'waa_run_at', '0');
        if (function_exists('wa_log')) { $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; wa_log($pdo, 'engine', true, 'auto assign: ' . ($nm[$emp] ?? $emp) . ' turned ' . ($on ? 'ON' : 'OFF') . ' by ' . ($nm[$me] ?? $me)); }
    }
    $V = waa_view($pdo, $__waa === 'wq.aa_set'); $T = waa_tally($pdo); $rows = [];
    foreach ($V['people'] as $e => $p) { $why = ['not_in' => 'Not punched in', 'admin_off' => 'Turned off by Admin / Sales Head', 'admin_on' => 'ON by Admin / Sales Head — no rule applies', 'ratio' => 'Auto OFF: ratio ' . ($p['ratio'] === null ? '–' : $p['ratio'] . '%') . ' is below ' . $V['floor'] . '% · campaign queries only', 'on' => ''][$p['why']] ?? '';
        $rows[] = ['emp_id' => $e, 'name' => $p['name'], 'on' => $p['on'], 'present' => $p['present'], 'why' => $why, 'note' => $p['present'] && $p['why'] === 'on' ? 'Punched in' . ($p['punch'] !== '' ? ' ' . $p['punch'] : '') : '', 'target' => $p['t'], 'q' => $p['q'], 'o' => $p['qo'], 'ratio' => $p['ratio'], 'cq' => $p['cq'], 'co' => $p['co'], 'given_fresh' => (int)($T[$e]['fresh'] ?? 0), 'given_camp' => (int)($T[$e]['camp'] ?? 0), 'slow' => ($p['speed'] && $p['team_speed'] && $p['speed'] > $p['team_speed'] && $p['m'] !== 'on') ? round($p['team_speed'] / $p['speed'], 2) : 1, 'below' => $p['below'] || $p['ao']]; }
    usort($rows, function ($a, $b) { return [$b['present'] ? 1 : 0, $a['name']] <=> [$a['present'] ? 1 : 0, $b['name']]; });
    json_response(['ok' => true, 'engine' => waa_engine_on($pdo), 'day' => $V['day'], 'team_ratio' => $V['team_ratio'], 'floor' => $V['floor'], 'team_speed' => $V['team_speed'], 'rows' => $rows]);
}
