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
require_once __DIR__ . '/tk_lib.php';   // 🎯 ticket goal: the Ticket column, the ratio relief and the optional hold

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
function waa_cp(PDO $pdo, bool $fresh = false, int $maxAge = 45): array
{
    $day = waa_day($pdo);
    if (!$fresh) { $j = json_decode((string)setting_get($pdo, 'waa_cp', ''), true); if (is_array($j) && ($j['day'] ?? '') === $day && (int)($j['at'] ?? 0) > time() - $maxAge) return (array)$j['rows']; }
    if (!function_exists('cp_day_rows') && is_file(__DIR__ . '/ct_people.php')) { $GLOBALS['cp_funcs_only'] = true; try { require_once __DIR__ . '/ct_people.php'; } catch (Throwable $e) { } unset($GLOBALS['cp_funcs_only']); }
    $rows = []; if (function_exists('cp_day_rows')) { try { $rows = (array)cp_day_rows($pdo, $day); } catch (Throwable $e) { error_log('waa_cp: ' . $e->getMessage()); } }
    if ($rows) setting_set($pdo, 'waa_cp', json_encode(['day' => $day, 'at' => time(), 'rows' => $rows]));
    return $rows;
}
/** A query was just given: the cached day rows count it at once, so the Team performance table moves without waiting for Live to be recounted. */
function waa_cp_bump(PDO $pdo, int $emp, bool $camp): void
{
    try { $j = json_decode((string)setting_get($pdo, 'waa_cp', ''), true); if (!is_array($j) || empty($j['rows'])) return; $k = $camp ? 'cq' : 'q'; $e = (string)$emp;
        if (!isset($j['rows'][$e])) $j['rows'][$e] = ['off' => 0, 'n' => 0, 's' => 0, 't' => null, 'qo' => 0, 'co' => 0, 'q' => 0, 'cq' => 0, 'ar' => null]; $j['rows'][$e][$k] = (int)($j['rows'][$e][$k] ?? 0) + 1; $j['rows'][$e]['off'] = 0;
        setting_set($pdo, 'waa_cp', json_encode($j)); } catch (Throwable $e2) { }
}
/**
 * Sales → Live → Give queries keeps typed counts (per person, per hour) until the WhatsApp Sales cut-over date. A query Auto assign gives is written there too, so the Query tab, the hour strip,
 * the Overnight tab (same hourly log) and the Campaign tab (campaign_queries, kind 'new') all show it without anybody typing. From the cut-over date WhatsApp Sales counts the queries itself, so nothing is written.
 */
function waa_typed(PDO $pdo, int $emp, bool $camp): void
{
    try {
        if (!function_exists('qb_apply')) return; $day = waa_day($pdo);
        if (function_exists('qa_cut')) { $cut = qa_cut($pdo); if ($cut !== '' && $cut <= $day) return; }
        if ($camp) { if (function_exists('ensure_cq_table') && ensure_cq_table($pdo)) $pdo->prepare("INSERT INTO campaign_queries (employee_id, cq_date, kind, n, updated_at) VALUES (?, ?, 'new', 1, NOW()) ON DUPLICATE KEY UPDATE n = n + 1, updated_at = NOW()")->execute([$emp, $day]); }
        else qb_apply($pdo, $emp, $day, 1, null);   // stamped with the time it was given: it lands in this clock hour
    } catch (Throwable $e) { error_log('waa_typed: ' . $e->getMessage()); }
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
    $o = []; try { $q = $pdo->prepare("SELECT emp_id, kind, COUNT(*) c, MAX(at) m FROM waa_log WHERE at >= ? GROUP BY emp_id, kind"); $q->execute([waa_dt(waa_day_start($pdo))]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $o[(int)$r['emp_id']][(string)$r['kind']] = (int)$r['c']; $o[(int)$r['emp_id']]['last_' . (string)$r['kind']] = (int)strtotime((string)$r['m']); } } catch (Throwable $e) { }
    return $o;
}

/** Everything the table and the engine need, in one place. */
function waa_view(PDO $pdo, bool $fresh = false, int $maxAge = 45): array
{
    $cp = waa_cp($pdo, $fresh, $maxAge); $sales = waa_sales($pdo); $pres = waa_present($pdo); $st = waa_state($pdo); $sp = waa_speed($pdo);
    $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
    $tq = 0; $tqo = 0; foreach ($sales as $e) { $x = $cp[$e] ?? null; if ($x && empty($x['off'])) { $tq += (int)$x['q']; $tqo += (int)$x['qo']; } }
    $team = $tq > 0 ? $tqo / $tq : 0.0; $teamSpeed = waa_median(array_values(array_filter(array_map(function ($e) use ($sp, $pres) { return isset($pres[$e]) ? ($sp[$e] ?? null) : null; }, $sales))));
    $tg = []; foreach ($sales as $e) if (isset($pres[$e]) && (float)($cp[$e]['t'] ?? 0) > 0) $tg[] = (float)$cp[$e]['t']; $avgT = $tg ? array_sum($tg) / count($tg) : 0.0;
    $wt = []; try { if ($sales) { $wq = $pdo->prepare("SELECT assigned_emp, COUNT(*) c FROM wa_contacts WHERE assigned_emp IN (" . implode(',', array_map('intval', $sales)) . ") AND status = 'open' AND last_in_at >= ? AND (last_out_at IS NULL OR last_out_at < last_in_at) GROUP BY assigned_emp"); $wq->execute([waa_dt(time() - 86400)]); foreach ($wq->fetchAll(PDO::FETCH_ASSOC) as $r) $wt[(int)$r['assigned_emp']] = (int)$r['c']; } } catch (Throwable $e) { }   // customers each person still has to answer (a green person with 10+ waiting is skipped for the next query)
    $tc = tk_cfg($pdo); $people = [];
    foreach ($sales as $e) {
        $x = $cp[$e] ?? []; $s = $st['e'][$e] ?? $st['e'][(string)$e] ?? []; $q = (int)($x['q'] ?? 0); $qo = (int)($x['qo'] ?? 0);
        $p = ['emp_id' => $e, 'name' => (string)($names[$e] ?? ('#' . $e)), 'present' => isset($pres[$e]), 'm' => (string)($s['m'] ?? ''), 'ao' => !empty($s['ao']) ? 1 : 0, 't' => (float)($x['t'] ?? 0), 'q' => $q, 'qo' => $qo, 'cq' => (int)($x['cq'] ?? 0), 'co' => (int)($x['co'] ?? 0),
              'ratio' => $q > 0 ? round(100 * $qo / $q, 1) : null, 'punch' => isset($x['ar']) && $x['ar'] !== null ? (string)$x['ar'] : '', 'speed' => $sp[$e] ?? null, 'team_speed' => $teamSpeed];
        $tkv = (int)(($x['ctk'] ?? 0) > 0 ? $x['ctk'] : ($x['tk'] ?? 0)); $no = (int)($x['n'] ?? 0); $p['ticket'] = $tkv; $p['orders'] = $no;
        $rel = ($tc['on'] && $tc['goal'] > 0 && $tkv >= $tc['goal'] && $no >= $tc['min']) ? $tc['relief'] / 100 : 0.0;   // a ticket at the goal gets a little room on the ratio (bigger bundles can mean fewer orders)
        $p['below'] = waa_below($q, $qo, $team - $rel) ? 1 : 0; $p['tier'] = waa_tier($q, $qo, $team); $p['waiting'] = (int)($wt[$e] ?? 0);
        $p['tkcand'] = ($tc['on'] && $tc['hold'] && tk_hold((float)$tkv, $no, $p['waiting'], $tc['hold_below'], $tc['hold_orders'], $tc['hold_wait'])) ? 1 : 0; $p['tkhold'] = 0; if ($p['m'] === 'on') $p['tkcand'] = 0;   // the hold is decided below, once we know how many people reach the goal; Admin / Sales Head ON is never held
        $p['sw'] = (int)($s['sw'] ?? 0); $p['sh'] = (int)($s['sh'] ?? 0); $p['slow'] = ($p['m'] !== 'on' && $p['waiting'] >= (int)$tc['hold_wait'] && tk_slow($p['speed'], $p['team_speed'], 2.0)) ? 1 : 0; $p['shold'] = ($p['m'] !== 'on' && $p['sh'] > time()) ? 1 : 0;   // 🐢 slow replies: warned first, then new queries pause for 45 minutes
        [$on, $why] = waa_button($p); $p['on'] = $on; $p['why'] = $why;
        $people[$e] = $p;
    }
    // 🎯 the hold needs company: only when at least tk_hold_share % of the people at work reach the ticket goal (if everybody's ticket is low, queries keep going to everybody)
    $wk = array_filter($people, function ($p) use ($tc) { return $p['present'] && $p['orders'] >= $tc['min']; }); $ach = count(array_filter($wk, function ($p) use ($tc) { return $tc['goal'] > 0 && $p['ticket'] >= $tc['goal']; }));
    if ($tc['on'] && $tc['hold'] && $ach >= 3 && tk_hold_share_ok($ach, count($wk), $tc['hold_share'])) foreach ($people as $e => &$p) { if (!empty($p['tkcand'])) $p['tkhold'] = 1; } unset($p);
    return ['day' => $st['day'], 'team_ratio' => round(100 * $team, 1), 'floor' => round(100 * waa_floor($team), 1), 'team_speed' => $teamSpeed, 'avg_target' => $avgT, 'people' => $people, 'state' => $st];
}

/**
 * Gives ONE waiting query to a person, if it can be given. $r = its wa_queries row, $c = its contact row, $V = waa_view() (people are updated), $T = what the engine gave today.
 * Skips: already held · not open · opted out / complaint · older than 24 h · a person already answered · another business's auto-reply · nobody eligible. A per-customer lock stops the webhook and the engine pass giving the same chat twice.
 */
function waa_give_one(PDO $pdo, array $r, ?array $c, array &$V, array &$T, int $now, array &$dbg): bool
{
    $ph = (string)$r['phone'];
    if (!$c) { $dbg['no_contact']++; return false; } if ((int)$c['assigned_emp'] > 0) { $dbg['held']++; return false; } if ((string)$c['status'] !== 'open') { $dbg['not_open']++; return false; }
    if ((int)$c['optout'] === 1 || (int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint') { $dbg['optout_cx']++; return false; }
    if (!$c['last_in_at'] || strtotime((string)$c['last_in_at']) < $now - 86400 + 600) { $dbg['old']++; return false; }
    static $hq = null, $bq = null; if ($hq === null) { $humanSql = function_exists('wq_human_sql') ? wq_human_sql('h', 'c', 'last_in_at') : "h.dir = 'out' AND h.emp_id > 0"; $hq = $pdo->prepare("SELECT 1 FROM wa_contacts c JOIN wa_messages h ON h.phone = c.phone WHERE c.phone = ? AND $humanSql LIMIT 1"); $bq = $pdo->prepare("SELECT 1 FROM wa_ctags WHERE phone = ? AND tag = 'business_autoreply' LIMIT 1"); }
    $hq->execute([$ph]); if ($hq->fetchColumn()) { $dbg['human']++; return false; } $bq->execute([$ph]); if ($bq->fetchColumn()) { $dbg['biz']++; return false; }
    $camp = in_array((string)$r['src'], ['campaign', 'community'], true); $cand = [];   // a Community query is given like a Campaign query (owner, 17 Nov)
    $avgT = (float)$V['avg_target'];
    $vip = false; try { $tkc = tk_cfg($pdo); if ($tkc['on'] && $tkc['goal'] > 0) $vip = waa_is_vip($pdo, $ph, (float)$tkc['goal']); } catch (Throwable $e) { }   // big-order customer → a seller who reaches the ticket goal
    foreach ($V['people'] as $e => $p) {
        if ($camp) { if (!$p['present'] || $p['m'] === 'off') continue; $w = waa_weight_camp($p); $cnt = (int)($T[$e]['camp'] ?? 0); }
        else { if (!$p['on']) continue; $w = waa_weight_fresh($p, $avgT); $cnt = max((int)$p['q'], (int)($T[$e]['fresh'] ?? 0)); }
        $gap = max(0, (int)setting_get($pdo, 'waa_gap', '90')); $rest = !$camp && $gap > 0 && (int)($T[$e]['last_fresh'] ?? 0) > $now - $gap;   // given a fresh query in the last 90 s: one at a time
        $cand[$e] = ['w' => $w, 'cnt' => $cnt, 'tier' => (int)$p['tier'], 'full' => (int)$p['waiting'] >= WAA_MAX_WAIT || !empty($p['tkhold']) || !empty($p['shold']), 'rest' => $rest];
    }
    if ($vip) { $good = []; foreach ($cand as $e => $x) if (!$x['full'] && (float)($V['people'][$e]['ticket'] ?? 0) >= (float)$tkc['goal'] && (int)($V['people'][$e]['orders'] ?? 0) >= (int)$tkc['min']) $good[$e] = $x; if ($good) $cand = $good; else $vip = false; }
    $to = $camp ? waa_pick($cand, 0) : waa_pick_tiers($cand, WAA_MIN_Q); if ($to <= 0) { $dbg['nobody']++; return false; }
    $lk = 0; try { $lk = (int)$pdo->query("SELECT GET_LOCK(" . $pdo->quote('waa_p_' . $ph) . ", 0)")->fetchColumn(); } catch (Throwable $e) { } if ($lk !== 1) return false;
    try {
        $chk = $pdo->prepare("SELECT assigned_emp FROM wa_contacts WHERE phone = ?"); $chk->execute([$ph]); if ((int)$chk->fetchColumn() > 0) { $dbg['held']++; return false; }   // somebody just took it
        wa_assign_to($pdo, $ph, $to, 0, 'auto');
        $pdo->prepare("INSERT INTO waa_log (at, phone, emp_id, kind, why) VALUES (?, ?, ?, ?, ?)")->execute([waa_dt($now), $ph, $to, $camp ? 'camp' : 'fresh', mb_substr(($vip ? '⭐ big-order customer · ' : '') . ($camp ? 'campaign query' : 'fresh query') . ' · weight ' . round($cand[$to]['w']) . ' · so far ' . $cand[$to]['cnt'], 0, 160)]);
        $T[$to][$camp ? 'camp' : 'fresh'] = (int)($T[$to][$camp ? 'camp' : 'fresh'] ?? 0) + 1; if (!$camp) $T[$to]['last_fresh'] = $now; if (!$camp) $V['people'][$to]['q']++; else $V['people'][$to]['cq']++;
        waa_cp_bump($pdo, $to, $camp); waa_typed($pdo, $to, $camp); return true;
    } catch (Throwable $e) { error_log('waa give: ' . $e->getMessage()); $dbg['give_error'] = mb_substr($e->getMessage(), 0, 160); return false; }
    finally { try { $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote('waa_p_' . $ph) . ")"); } catch (Throwable $e) { } }
}
/** Customer whose average past order is at or above the ticket goal (own orders table, then customer facts). */
function waa_is_vip(PDO $pdo, string $phone, float $goal): bool
{
    static $m = []; if (isset($m[$phone])) return $m[$phone]; $n = 0; $sp = 0.0;
    try { $q = $pdo->prepare("SELECT n, spent FROM wa_cust WHERE phone = ? LIMIT 1"); $q->execute([$phone]); if ($r = $q->fetch(PDO::FETCH_ASSOC)) { $n = (int)$r['n']; $sp = (float)$r['spent']; } } catch (Throwable $e) { }
    try { $q = $pdo->prepare("SELECT orders_n, spent FROM customer_facts WHERE p10 = ? LIMIT 1"); $q->execute([substr($phone, -10)]); if ($r = $q->fetch(PDO::FETCH_ASSOC)) { if ((int)$r['orders_n'] > $n) { $n = (int)$r['orders_n']; $sp = (float)$r['spent']; } } } catch (Throwable $e) { }
    return $m[$phone] = tk_is_vip($n, $sp, $goal);
}
function waa_dbg0(): array { return ['scanned' => 0, 'no_contact' => 0, 'held' => 0, 'not_open' => 0, 'optout_cx' => 0, 'old' => 0, 'human' => 0, 'biz' => 0, 'nobody' => 0, 'give_error' => '', 'fresh_ok' => 0, 'camp_ok' => 0, 'present' => 0]; }

/** A customer message has just arrived (webhook): give its query right now, so it never waits for the engine. Never throws; reads the cached day rows only. */
function waa_give_now(PDO $pdo, string $phone): void
{
    try {
        if (!waa_tables($pdo) || !waa_engine_on($pdo)) return; $now = time();
        $q = $pdo->prepare("SELECT id, phone, src, opened_at, emp_id FROM wa_queries WHERE phone = ? ORDER BY id DESC LIMIT 1"); $q->execute([$phone]); $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r || !in_array((string)$r['src'], ['query', 'campaign', 'community', ''], true)) return;   // the chat itself decides (assigned / open / written in the last 24 h are checked in waa_give_one); an old query row or a stale owner on the row no longer blocks
        $c = $pdo->prepare("SELECT phone, name, assigned_emp, last_in_at, status, optout, cx_id, tag FROM wa_contacts WHERE phone = ?"); $c->execute([$phone]); $row = $c->fetch(PDO::FETCH_ASSOC) ?: null;
        $V = waa_view($pdo, false, 900); $T = waa_tally($pdo); $dbg = waa_dbg0();
        waa_give_one($pdo, $r, $row, $V, $T, $now, $dbg);
    } catch (Throwable $e) { error_log('waa_give_now: ' . $e->getMessage()); }
}

/** A pass any page poll can start (answer first, then this): every 8 seconds at most, so a query nobody could take yet is given the moment somebody can. */
function waa_catchup(PDO $pdo): void { try { waa_tick($pdo, 8, 120); } catch (Throwable $e) { } }

/** One engine pass: (1) the ratio rule switches people off, (2) waiting queries are given out. $every = seconds between passes, $maxAge = how old the cached day rows may be. */
function waa_tick(PDO $pdo, int $every = 20, int $maxAge = 45): array
{
    try { if ((int)setting_get($pdo, 'waa_seen_at', '0') < time() - 30) setting_set($pdo, 'waa_seen_at', (string)time()); } catch (Throwable $e) { }   // proof for the check workflow: the engine reached this function
    if (!waa_tables($pdo)) return ['error' => 'storage'];
    if (!waa_engine_on($pdo)) return ['off' => 1];
    $now = time(); if ((int)setting_get($pdo, 'waa_run_at', '0') > $now - $every) return ['skipped' => 'recent'];
    $got = 0; try { $got = (int)$pdo->query("SELECT GET_LOCK('waa_run', 0)")->fetchColumn(); } catch (Throwable $e) { } if ($got !== 1) return ['skipped' => 'busy'];
    $res = ['given' => 0, 'switched_off' => 0]; $dbg = waa_dbg0();
    try {
        setting_set($pdo, 'waa_run_at', (string)$now);
        $V = waa_view($pdo, false, $maxAge); $st = $V['state'];
        // (1) the ratio rule: below the band → OFF by itself, until Admin / Sales Head turn it ON
        foreach ($V['people'] as $e => &$p) { if ($p['present'] && $p['m'] === '' && $p['below'] && !$p['ao']) { $st['e'][$e] = array_merge((array)($st['e'][$e] ?? []), ['m' => '', 'ao' => 1, 'ao_at' => $now]); $p['ao'] = 1; [$p['on'], $p['why']] = waa_button($p); $res['switched_off']++; } } unset($p);
        // (1b) slow repliers with a waiting list: first a warning, ten minutes later (still slow) new queries pause for 45 minutes; slow again after that → paused again
        $chg = false; foreach ($V['people'] as $e => &$p) { if (!$p['present'] || $p['m'] === 'on' || !empty($p['shold']) || empty($p['slow'])) continue; $x = (array)($st['e'][$e] ?? []);
            if (empty($x['sw'])) { $x['sw'] = $now; $p['sw'] = $now; } elseif ($now - (int)$x['sw'] >= 600) { $x['sh'] = $now + 2700; $x['sn'] = (int)($x['sn'] ?? 0) + 1; $p['sh'] = $x['sh']; $p['shold'] = 1; $res['slow_held'] = ($res['slow_held'] ?? 0) + 1; } else continue;
            $st['e'][$e] = array_merge((array)($st['e'][$e] ?? []), $x); $chg = true; } unset($p);
        if ($res['switched_off'] || $chg) waa_state_put($pdo, $st);
        foreach ($V['people'] as $p0) { if ($p0['present']) $dbg['present']++; if ($p0['on']) $dbg['fresh_ok']++; if ($p0['present'] && $p0['m'] !== 'off') $dbg['camp_ok']++; }
        // (2) waiting queries: nobody holds them, the customer wrote in the last 24 h, no person answered yet. NEWEST first (the oldest are mostly closed / answered / past the 24 h window).
        // 18 Nov (owner: "why are these queries not given?"): the check workflow showed 25 of the 60 newest open unassigned chats were skipped only because their query ROW was opened more than 24 h ago (a customer who keeps writing
        // inside 24 h stays in the same query), 3 because the row still named an old owner although the chat is unassigned, and the 250 scanned rows were mostly CLOSED chats. Now the list is the CHATS nobody holds that are OPEN and whose
        // customer wrote in the last 24 h (newest first), each with its latest query row — the same rules (complaint / opt-out / a person answered / another business's auto-reply) are still applied one by one.
        $since = waa_dt($now - 86400 + 600); $st0 = $pdo->prepare("SELECT q.id, q.phone, q.src, q.opened_at FROM wa_contacts c JOIN wa_queries q ON q.phone = c.phone AND q.id = (SELECT MAX(q2.id) FROM wa_queries q2 WHERE q2.phone = c.phone)
            WHERE c.assigned_emp = 0 AND c.status = 'open' AND c.optout = 0 AND c.cx_id = 0 AND c.last_in_at >= ? AND c.last_in_at <= ? AND q.src IN ('query', 'campaign', 'community', '') ORDER BY c.last_in_at DESC LIMIT 250");
        $st0->execute([$since, waa_dt($now - 2)]); $qs = $st0->fetchAll(PDO::FETCH_ASSOC);
        if ($qs) {
            $phs = array_values(array_unique(array_map(function ($r) { return (string)$r['phone']; }, $qs))); $C = [];
            $cq = $pdo->prepare("SELECT phone, name, assigned_emp, last_in_at, status, optout, cx_id, tag FROM wa_contacts WHERE phone IN (" . implode(',', array_fill(0, count($phs), '?')) . ")"); $cq->execute($phs); foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $r) $C[(string)$r['phone']] = $r;
            $T = waa_tally($pdo);
            foreach ($qs as $r) {
                if ($res['given'] >= 40) break;   // at most 40 a pass
                $dbg['scanned']++; if (waa_give_one($pdo, $r, $C[(string)$r['phone']] ?? null, $V, $T, $now, $dbg)) $res['given']++;
            }
        }
    } catch (Throwable $e) { error_log('waa_tick: ' . $e->getMessage()); $res['error'] = mb_substr($e->getMessage(), 0, 160); }
    try { setting_set($pdo, 'waa_last', json_encode(['at' => time(), 'res' => $res, 'dbg' => $dbg])); } catch (Throwable $e) { }   // what the last pass saw (for the check workflow)
    try { $pdo->query("SELECT RELEASE_LOCK('waa_run')"); } catch (Throwable $e) { }
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
    $V = waa_view($pdo, $__waa === 'wq.aa_set'); $T = waa_tally($pdo); $rows = []; $tcfg = tk_cfg($pdo);
    $H = []; try { $hs = date('Y-m-d H:00:00'); $hq = $pdo->prepare("SELECT emp_id, kind, COUNT(*) c FROM waa_log WHERE at >= ? AND at < ? GROUP BY emp_id, kind"); $hq->execute([$hs, date('Y-m-d H:00:00', strtotime($hs) + 3600)]); foreach ($hq->fetchAll(PDO::FETCH_ASSOC) as $r) $H[(int)$r['emp_id']][(string)$r['kind']] = (int)$r['c']; } catch (Throwable $e) { }   // given by Auto assign in this clock hour
    foreach ($V['people'] as $e => $p) { $why = ['not_in' => 'Not punched in', 'admin_off' => 'Turned off by Admin / Sales Head', 'admin_on' => 'ON by Admin / Sales Head — no rule applies', 'ratio' => 'Auto OFF: ratio ' . ($p['ratio'] === null ? '–' : $p['ratio'] . '%') . ' is below ' . $V['floor'] . '% · campaign queries only', 'on' => ''][$p['why']] ?? '';
        $rows[] = ['emp_id' => $e, 'name' => $p['name'], 'on' => $p['on'], 'present' => $p['present'], 'why' => $why, 'note' => $p['present'] && $p['why'] === 'on' ? 'Punched in' . ($p['punch'] !== '' ? ' ' . $p['punch'] : '') : '', 'target' => $p['t'], 'q' => $p['q'], 'o' => $p['qo'], 'ratio' => $p['ratio'], 'cq' => $p['cq'], 'co' => $p['co'], 'h_fresh' => (int)($H[$e]['fresh'] ?? 0), 'h_camp' => (int)($H[$e]['camp'] ?? 0), 'ticket' => (int)$p['ticket'], 'tk' => tk_status((float)$p['ticket'], (float)$tcfg['goal']), 'tkhold' => !empty($p['tkhold']), 'shold' => !empty($p['shold']), 'slow_warn' => !empty($p['slow']) && (int)$p['sw'] > 0 && empty($p['shold']), 'given_fresh' => (int)($T[$e]['fresh'] ?? 0), 'given_camp' => (int)($T[$e]['camp'] ?? 0), 'slow' => ($p['speed'] && $p['team_speed'] && $p['speed'] > $p['team_speed'] && $p['m'] !== 'on') ? round($p['team_speed'] / $p['speed'], 2) : 1, 'below' => $p['below'] || $p['ao']]; }
    usort($rows, function ($a, $b) { return [$b['present'] ? 1 : 0, $a['name']] <=> [$a['present'] ? 1 : 0, $b['name']]; });
    json_response(['ok' => true, 'tk_goal' => (int)$tcfg['goal'], 'tk_offer' => $tcfg['offer'], 'tk_cust' => $tcfg['cust'], 'tk_hold' => (bool)$tcfg['hold'], 'engine' => waa_engine_on($pdo), 'hour' => (int)date('G'), 'day' => $V['day'], 'team_ratio' => $V['team_ratio'], 'floor' => $V['floor'], 'team_speed' => $V['team_speed'], 'rows' => $rows]);
}
