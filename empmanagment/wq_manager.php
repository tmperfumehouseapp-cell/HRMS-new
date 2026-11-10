<?php
/**
 * 🧭 TEAM MANAGER (Autobot): once in a while Claude looks at EVERY salesperson together — order ratio (queries and campaigns), speed, who is at work,
 * pending / overdue complaints, load — and decides who sees the new orange / red chats first, normally, late, or not at all, and writes one short line
 * for the salesperson's card and one line of reason for the admin.
 *
 * The maths is the judge of "clearly behind" (no fixed number of queries): a person is behind only when even the optimistic end of their ratio
 * (Wilson upper bound, 90 %) is below the team's ratio. Few queries = wide range = never "sure". Claude can only go as far as the maths allows.
 *
 * Guard rails (in code, Claude cannot pass them):
 *   · late / hold only when the maths says "clearly behind"
 *   · hold ("no new chats") is a SUGGESTION until the admin presses Approve
 *   · an overdue complaint always shows the complaint line (the Needs-you-now rule that holds new chats back is separate and always on)
 *   · mode off | suggest (default: decisions are stored and shown to the admin, nothing changes for anybody) | apply (the engine uses the levels)
 *   · the admin can set any level by hand; Claude does not overwrite a manual level
 *   · a chat is never left lying: after the wave time every present person sees it (wq_needs)
 *
 *   wq.mgr_get      GET   admin → the table; a salesperson → their own card (only in mode apply)
 *   wq.mgr_run      POST  admin → ask Claude now
 *   wq.mgr_cfg      POST  admin → {mode}
 *   wq.mgr_approve  POST  admin → {emp_id, approve: 0|1}
 *   wq.mgr_set      POST  admin → {emp_id, level: first|normal|late|hold|auto}
 * Stored in wq_meta: mgr:<emp> (json), mgr:at (last run). PHP 7.4 safe.
 */
if (!function_exists('wqm_mode')) {

function wqm_mode(PDO $pdo): string { $m = (string)setting_get($pdo, 'wqm_mode', 'suggest'); return in_array($m, ['off', 'suggest', 'apply'], true) ? $m : 'suggest'; }
function wqm_levels(): array { return ['first' => 'Sees first', 'normal' => 'Normal', 'late' => 'After the wave', 'hold' => 'No new chats']; }
function wqm_rec(PDO $pdo, int $emp): array { $j = json_decode(wq_meta_get($pdo, 'mgr:' . $emp, ''), true); return is_array($j) ? $j : []; }

/** Upper end of the 90 % range of a ratio (orders o out of q queries). */
function wqm_hi(int $o, int $q): float
{
    if ($q <= 0) return 1.0; $z = 1.645; $p = $o / $q; $d = 1 + $z * $z / $q; $c = $p + $z * $z / (2 * $q);
    $a = $z * sqrt(max(0.0, $p * (1 - $p) / $q + $z * $z / (4 * $q * $q)));
    return min(1.0, ($c + $a) / $d);
}

/** One row per salesperson with everything Claude and the guard rails need. */
function wqm_collect(PDO $pdo): array
{
    $rows = []; $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
    $sales = $pdo->query("SELECT e.employee_id FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.status != 'ex' AND d.department_name LIKE '%sales%'")->fetchAll(PDO::FETCH_COLUMN);
    $present = []; try { foreach ($pdo->query("SELECT DISTINCT employee_id FROM attendance WHERE punch_in IS NOT NULL AND punch_out IS NULL AND punch_in >= '" . date('Y-m-d H:i:s', time() - 20 * 3600) . "'")->fetchAll(PDO::FETCH_COLUMN) as $e) $present[(int)$e] = 1; } catch (Throwable $e) { }
    $lastAns = []; try { foreach ($pdo->query("SELECT emp_id, MAX(ts) t FROM wa_messages WHERE dir = 'out' AND emp_id > 0 AND ts >= '" . date('Y-m-d H:i:s', time() - 1800) . "' GROUP BY emp_id")->fetchAll(PDO::FETCH_ASSOC) as $r) $lastAns[(int)$r['emp_id']] = (int)round((time() - strtotime((string)$r['t'])) / 60); } catch (Throwable $e) { }
    $od = []; try { foreach ($pdo->query("SELECT owner_emp, COUNT(*) n FROM cx_cases WHERE status IN ('open','reopened') AND owner_emp > 0 AND ((due_at IS NOT NULL AND due_at < NOW()) OR (first_reply_at IS NULL AND reply_due IS NOT NULL AND reply_due < NOW())) GROUP BY owner_emp")->fetchAll(PDO::FETCH_ASSOC) as $r) $od[(int)$r['owner_emp']] = (int)$r['n']; } catch (Throwable $e) { }
    $open = []; try { foreach ($pdo->query("SELECT assigned_emp, COUNT(*) n FROM wa_contacts WHERE status = 'open' AND assigned_emp > 0 GROUP BY assigned_emp")->fetchAll(PDO::FETCH_ASSOC) as $r) $open[(int)$r['assigned_emp']] = (int)$r['n']; } catch (Throwable $e) { }
    $mon = []; $day = []; if (function_exists('wq_pace_get')) { try { foreach ((array)(wq_pace_get($pdo, 30)['people'] ?? []) as $p) $mon[(int)$p['emp_id']] = $p; foreach ((array)(wq_pace_get($pdo, 1)['people'] ?? []) as $p) $day[(int)$p['emp_id']] = $p; } catch (Throwable $e) { } }
    $bk = function_exists('wq_needs_backlog') ? wq_needs_backlog($pdo) : [];
    $tk = []; $tkRev = 0.0; $tkOrd = 0;   // ticket size = revenue per order of the queries each person held (30 days)
    try { foreach ($pdo->query("SELECT q.emp_id, SUM(s.orders) o, SUM(s.revenue) r FROM wq_qstat s JOIN wa_queries q ON q.id = s.query_id WHERE s.opened_at >= '" . date('Y-m-d H:i:s', time() - 30 * 86400) . "' AND q.emp_id > 0 AND s.orders > 0 GROUP BY q.emp_id")->fetchAll(PDO::FETCH_ASSOC) as $r) { $tk[(int)$r['emp_id']] = [(int)$r['o'], (float)$r['r']]; $tkOrd += (int)$r['o']; $tkRev += (float)$r['r']; } } catch (Throwable $e) { }
    $teamTk = $tkOrd > 0 ? round($tkRev / $tkOrd) : null;
    $tq = 0; $to = 0; foreach ($sales as $id) { $oe = $mon[(int)$id]['orders'] ?? null; if ($oe) { $tq += (int)($oe['queries'] ?? 0); $to += (int)($oe['orders'] ?? 0); } }
    $team = $tq > 0 ? $to / $tq : 0.0;
    foreach ($sales as $id) {
        $id = (int)$id; $oe = $mon[$id]['orders'] ?? []; $q = (int)($oe['queries'] ?? 0); $o = (int)($oe['orders'] ?? 0);
        $rows[$id] = ['emp_id' => $id, 'name' => (string)($names[$id] ?? ('#' . $id)), 'present' => !empty($present[$id]), 'answering_min_ago' => $lastAns[$id] ?? null,
            'queries_30d' => $q, 'orders_30d' => $o, 'ticket_30d' => (isset($tk[$id]) && $tk[$id][0] > 0) ? (int)round($tk[$id][1] / $tk[$id][0]) : null, 'team_ticket' => $teamTk, 'ratio_pct' => $q > 0 ? round(100 * $o / $q, 1) : null, 'team_ratio_pct' => round(100 * $team, 1),
            'sure_behind' => $q > 0 && $team > 0 && wqm_hi($o, $q) < $team,
            'speed_median_min_today' => isset($day[$id]['pace']['median']) ? $day[$id]['pace']['median'] : null, 'replies_today' => (int)($day[$id]['pace']['n'] ?? 0),
            'speed_median_min_30d' => isset($mon[$id]['pace']['median']) ? $mon[$id]['pace']['median'] : null,
            'overdue_complaints' => $od[$id] ?? 0, 'pending_answers' => (int)($bk[$id] ?? 0), 'open_chats' => $open[$id] ?? 0];
    }
    wqm_enrich($pdo, $rows, $mon);
    return $rows;
}

/** More of what the system already knows about each person (all optional: a missing module just leaves the field empty). */
function wqm_enrich(PDO $pdo, array &$rows, array $mon): void
{
    foreach ($rows as $id => &$r) {
        $v = $mon[$id]['verdict'] ?? null; $r['pace_verdict'] = is_array($v) ? (string)($v['label'] ?? $v['key'] ?? '') : null;
        $ls = $mon[$id]['loss'] ?? null; $r['orders_lost_to_slow_replies'] = is_array($ls) ? (int)($ls['orders'] ?? 0) : null;
        $r['rating_30d'] = null; $r['rating_weak_parts'] = []; $r['benchmark_outcome'] = null; $r['rescue_taken_30d'] = null; $r['rescue_win_pct'] = null;
        $r['late_replies_today'] = null; $r['late_avg_min_today'] = null; $r['meet_admin_alerts'] = 0; $r['penalties_30d'] = 0;
        $t = json_decode(wq_meta_get($pdo, 'mgr:tone:' . $id, ''), true); $r['tone_score'] = is_array($t) ? $t['score'] : null; $r['tone_note'] = is_array($t) ? (string)$t['note'] : '';
    }
    unset($r);
    try {   // the daily rating (10 parts, 30 days)
        if (function_exists('rating_days') && function_exists('rating_period')) {
            $days = rating_days($pdo, date('Y-m-d', time() - 29 * 86400), date('Y-m-d'));
            foreach ($rows as $id => &$r) { $rp = rating_period($days, $id); if (is_array($rp)) { $r['rating_30d'] = isset($rp['score']) ? round((float)$rp['score'], 1) : null; $pt = []; foreach ((array)($rp['parts'] ?? []) as $k => $x) if ($x !== null && is_numeric($x)) $pt[$k] = (float)$x; asort($pt); $r['rating_weak_parts'] = array_slice(array_keys($pt), 0, 2); } }
            unset($r);
        }
    } catch (Throwable $e) { }
    try { if (function_exists('ct_benchmarks')) foreach ((array)(ct_benchmarks($pdo)['rows'] ?? []) as $b) { $i = (int)($b['id'] ?? 0); if (isset($rows[$i])) $rows[$i]['benchmark_outcome'] = $b['outcome'] ?? null; } } catch (Throwable $e) { }
    try { if (function_exists('wq_rescue_stats')) foreach ((array)(wq_rescue_stats($pdo, 30)['people'] ?? []) as $b) { $i = (int)($b['emp_id'] ?? $b['id'] ?? 0); if (isset($rows[$i])) { $rows[$i]['rescue_taken_30d'] = (int)($b['taken'] ?? 0); $rows[$i]['rescue_win_pct'] = $b['win_pct'] ?? null; } } } catch (Throwable $e) { }
    try { if (function_exists('wa_late_summary')) foreach ((array)(wa_late_summary($pdo)['today'] ?? []) as $b) { $i = (int)($b['emp'] ?? 0); if (isset($rows[$i])) { $rows[$i]['late_replies_today'] = (int)($b['n'] ?? 0); $rows[$i]['late_avg_min_today'] = $b['avg'] ?? null; } } } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT employee_id, COUNT(*) n FROM ct_alerts WHERE meet_admin = 1 AND status = 'open' GROUP BY employee_id")->fetchAll(PDO::FETCH_ASSOC) as $b) if (isset($rows[(int)$b['employee_id']])) $rows[(int)$b['employee_id']]['meet_admin_alerts'] = (int)$b['n']; } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT employee_id, COUNT(*) n FROM employee_penalties WHERE status = 'applied' AND penalty_date >= '" . date('Y-m-d', time() - 30 * 86400) . "' GROUP BY employee_id")->fetchAll(PDO::FETCH_ASSOC) as $b) if (isset($rows[(int)$b['employee_id']])) $rows[(int)$b['employee_id']]['penalties_30d'] = (int)$b['n']; } catch (Throwable $e) { }
}

/** 🗣 Tone of the salesperson with customers: Claude reads each person's last replies (with the customer's message before each) in ONE call for the whole team, every 3 hours.
 *  Phone-number-like digits are masked before anything leaves the server. Stored in wq_meta mgr:tone:<emp> = {score 0-10, note, n, at}. */
function wqm_tone_refresh(PDO $pdo, array $rows, bool $force = false): int
{
    if (!function_exists('convo_ai')) return 0;
    $last = strtotime(wq_meta_get($pdo, 'mgr:tone_at', '')) ?: 0; if (!$force && $last > time() - 3 * 3600) return 0;
    wq_meta_put($pdo, 'mgr:tone_at', date('Y-m-d H:i:s'));
    $mask = function ($t) { return mb_substr(preg_replace('/\d{6,}/', '#', trim((string)$t)), 0, 280); };
    $team = []; $since = date('Y-m-d H:i:s', time() - 3 * 86400);
    foreach ($rows as $id => $r) {
        if ($r['replies_today'] <= 0 && !$r['present']) continue;
        try {
            $q = $pdo->prepare("SELECT id, phone, body FROM wa_messages WHERE dir = 'out' AND emp_id = ? AND mtype = 'text' AND CHAR_LENGTH(body) >= 3 AND ts >= ? ORDER BY id DESC LIMIT 10"); $q->execute([$id, $since]); $pairs = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) { $c = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' AND id < ? AND body <> '' ORDER BY id DESC LIMIT 1"); $c->execute([$m['phone'], $m['id']]); $cb = (string)$c->fetchColumn(); $pairs[] = ['customer' => $mask($cb), 'salesperson' => $mask($m['body'])]; }
            if (count($pairs) >= 3) $team[$id] = $pairs;
        } catch (Throwable $e) { }
    }
    if (!$team) return 0;
    $sys = "You rate the TONE and QUALITY of salespeople's WhatsApp replies for TM Perfume House (online perfume brand, India; messages are in Hinglish / Gujarati / English). For each salesperson you get their latest replies, each with the customer's message just before it. "
        . "Score 0-10: 10 = warm, polite, helpful, answers the question, guides the customer to the next step; 5 = ordinary / short; 0 = rude, cold, ignoring the question, pushy or careless. Judge only what you see; with little to go on stay near 5. "
        . "note = ONE short line in English for the admin saying what is good or what to fix (for example 'warm and helpful, but does not suggest a set' or 'very short cold replies'). Never quote a customer's number.";
    $txt = "SALESPEOPLE (json):\n" . json_encode($team, JSON_UNESCAPED_UNICODE);
    $schema = ['type' => 'OBJECT', 'properties' => ['people' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['emp_id' => ['type' => 'INTEGER'], 'score' => ['type' => 'NUMBER'], 'note' => ['type' => 'STRING']], 'required' => ['emp_id', 'score', 'note']]]], 'required' => ['people']];
    $f0 = function_exists('ai_feat') ? ai_feat('wq_manager_tone') : ''; [$ok, $v] = convo_ai($sys, [['text' => $txt]], $schema, false, 3000, 'claude'); if (function_exists('ai_feat')) ai_feat($f0);
    $n = 0; if ($ok && is_array($v)) foreach ((array)($v['people'] ?? []) as $p) { $i = (int)($p['emp_id'] ?? 0); if (!isset($team[$i])) continue; wq_meta_put($pdo, 'mgr:tone:' . $i, json_encode(['score' => max(0, min(10, round((float)$p['score'], 1))), 'note' => mb_substr(trim((string)$p['note']), 0, 200), 'n' => count($team[$i]), 'at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE)); $n++; }
    return $n;
}

function wqm_schema(): array
{
    $p = ['type' => 'OBJECT', 'properties' => ['emp_id' => ['type' => 'INTEGER'], 'level' => ['type' => 'STRING'], 'card' => ['type' => 'STRING'], 'reason' => ['type' => 'STRING'], 'meet_admin' => ['type' => 'BOOLEAN']], 'required' => ['emp_id', 'level', 'card', 'reason', 'meet_admin']];
    return ['type' => 'OBJECT', 'properties' => ['people' => ['type' => 'ARRAY', 'items' => $p], 'summary' => ['type' => 'STRING']], 'required' => ['people', 'summary']];
}

/** The rule-based answer used when Claude is not available, and as the base the guard rails check Claude against. */
function wqm_base(array $r): array
{
    $lvl = 'normal'; $card = ''; $why = '';
    if ($r['overdue_complaints'] > 0) { $card = 'તમારી ' . $r['overdue_complaints'] . ' ફરિયાદ મોડી છે. પહેલા તે ઉકેલો, પછી નવા ચૅટ મળશે.'; $why = 'Overdue complaints'; }
    elseif ($r['sure_behind']) { $lvl = 'late'; $card = 'તમારો રેશિયો ' . $r['ratio_pct'] . '% છે, ટીમનો ' . $r['team_ratio_pct'] . '%. ચૅટ ઉપાડીને ઓર્ડર પાડો, પછી તમને ચૅટ પહેલા દેખાશે.'; $why = 'Clearly behind the team ratio'; }
    elseif ($r['queries_30d'] === 0) { $card = 'શરૂઆત કરો: પહેલી ક્વેરી ઉપાડો અને ઝડપથી જવાબ આપો.'; $why = 'No queries yet — on trial'; }
    elseif ($r['ratio_pct'] !== null && $r['ratio_pct'] >= $r['team_ratio_pct']) { $lvl = 'first'; $card = 'સારું કામ! ચૅટ વધુ ઉપાડો અને ઓર્ડર ઝડપથી ક્લોઝ કરો.'; $why = 'Ratio at or above the team'; }
    return ['level' => $lvl, 'card' => $card, 'reason' => $why, 'meet_admin' => false];
}

/** Ask Claude (or use the rules), apply the guard rails, store. Returns the rows. */
function wqm_run(PDO $pdo, bool $force = false): array
{
    $rows = wqm_collect($pdo); $out = []; $ai = []; $summary = '';
    try { if (wqm_tone_refresh($pdo, $rows, $force)) $rows = wqm_collect($pdo); } catch (Throwable $e) { error_log('wqm_tone: ' . $e->getMessage()); }   // the tone reading first, so the decision below sees it
    $work = []; foreach ($rows as $id => $r) if ($r['present'] || $r['queries_30d'] > 0) $work[$id] = $r;
    if ($work && function_exists('convo_ai')) {
        try {
            $sys = "You are the sales team manager of TM Perfume House (online perfume brand, India). For every salesperson below decide how early they should see NEW customer chats that are interested (orange) or ready to pay (red), based on ALL their numbers together: order ratio compared with the team, ticket size (rupees per order) compared with the team, speed, who is at work, complaints, load, the system's daily rating (rating_30d out of 10 and its weakest parts), tone_score (0-10, from reading their recent replies) and tone_note, late replies, penalties, rescue results, open meet-admin alerts. "
                . "level: first = sees them first (good ratio, fast, present); normal; late = sees them only after the first minutes (only if sure_behind is true); hold = no new chats (only if sure_behind is true AND speed and behaviour are also weak; it is only a suggestion for the admin). "
                . "Never use a fixed number of queries: a person with few queries is NOT behind unless sure_behind is true. If the whole team's ratio is low nobody is behind. Someone who is not present gets level normal. "
                . "card = ONE short friendly line for the salesperson in simple Gujarati (Gujarati script), no blame, saying the ONE thing to do now (clear overdue complaints; take orders; speed up; if orders are good but ticket_30d is below team_ticket, ask for a bigger basket — sets / combos; or praise and 'take more chats, close orders fast'). summary must also name who has the best ratio and ticket and what the team as a whole should do today. reason = one line in English for the admin with the numbers. "
                . "meet_admin = true only when ratio, speed AND everything else (tone, rating, penalties) is weak for a person with enough queries to be sure. A low tone_score alone is a coaching line ('be warmer, answer the question, suggest the next step'), never a block. summary = 2-3 lines in English for the admin: who improved, who is stuck, who should meet the admin.";
            $txt = "TEAM RATIO " . (reset($work)['team_ratio_pct'] ?? 0) . "% (orders per query, 30 days)\nPEOPLE (json):\n" . json_encode(array_values($work), JSON_UNESCAPED_UNICODE);
            $f0 = function_exists('ai_feat') ? ai_feat('wq_manager') : ''; [$ok, $v] = convo_ai($sys, [['text' => $txt]], wqm_schema(), false, 4000, 'claude'); if (function_exists('ai_feat')) ai_feat($f0);
            if ($ok && is_array($v)) { foreach ((array)($v['people'] ?? []) as $p) if (is_array($p) && isset($work[(int)($p['emp_id'] ?? 0)])) $ai[(int)$p['emp_id']] = $p; $summary = trim(mb_substr((string)($v['summary'] ?? ''), 0, 600)); }
        } catch (Throwable $e) { error_log('wqm_run: ' . $e->getMessage()); }
    }
    foreach ($rows as $id => $r) {
        $old = wqm_rec($pdo, $id); $base = wqm_base($r); $a = $ai[$id] ?? null; $lvl = $base['level']; $card = $base['card']; $why = $base['reason']; $meet = false;
        if ($a) {
            $l = (string)($a['level'] ?? 'normal'); $lvl = isset(wqm_levels()[$l]) ? $l : 'normal'; $card = trim(mb_substr((string)($a['card'] ?? ''), 0, 220)) ?: $card; $why = trim(mb_substr((string)($a['reason'] ?? ''), 0, 240)) ?: $why; $meet = !empty($a['meet_admin']);
        }
        // guard rails
        if (in_array($lvl, ['late', 'hold'], true) && !$r['sure_behind']) $lvl = 'normal';
        if ($lvl === 'hold' && ($r['queries_30d'] < 1)) $lvl = 'late';
        if (!$r['present']) $lvl = 'normal';
        if ($r['overdue_complaints'] > 0) { $card = $base['card']; $why = $base['reason']; }
        if ($meet && !$r['sure_behind']) $meet = false;
        $manual = !empty($old['manual']) ? (string)$old['manual'] : '';
        $rec = ['level' => $manual !== '' ? $manual : $lvl, 'auto_level' => $lvl, 'manual' => $manual, 'card' => $card, 'reason' => $why, 'meet_admin' => $meet,
            'approved' => (!empty($old['approved']) && $old['auto_level'] === $lvl) ? 1 : 0, 'at' => date('Y-m-d H:i:s'), 'sig' => $r];
        $out[$id] = $rec; wq_meta_put($pdo, 'mgr:' . $id, json_encode($rec, JSON_UNESCAPED_UNICODE));
    }
    wq_meta_put($pdo, 'mgr:at', date('Y-m-d H:i:s')); if ($summary !== '') wq_meta_put($pdo, 'mgr:summary', $summary);
    return $out;
}

function wqm_tick(PDO $pdo): array
{
    if (wqm_mode($pdo) === 'off') return ['off' => true];
    $last = strtotime(wq_meta_get($pdo, 'mgr:at', '')) ?: 0; if ($last > time() - 600) return ['skipped' => 'recent'];
    wq_meta_put($pdo, 'mgr:at', date('Y-m-d H:i:s'));   // claimed first, so a slow AI call is not repeated by the next tick
    return ['ran' => count(wqm_run($pdo))];
}

/** Used by Needs-you-now (only in mode apply): is this person held back from the first minutes (late / hold)? */
function wqm_late(PDO $pdo, int $emp): bool
{
    static $memo = []; if (isset($memo[$emp])) return $memo[$emp];
    if (wqm_mode($pdo) !== 'apply') return $memo[$emp] = false;
    $r = wqm_rec($pdo, $emp); $l = (string)($r['level'] ?? 'normal'); if ($l === 'hold' && empty($r['approved']) && empty($r['manual'])) $l = 'late';
    return $memo[$emp] = in_array($l, ['late', 'hold'], true);
}
/** Approved "no new chats" (apply mode only). */
function wqm_hold(PDO $pdo, int $emp): bool
{
    if (wqm_mode($pdo) !== 'apply') return false; $r = wqm_rec($pdo, $emp);
    return (string)($r['level'] ?? '') === 'hold' && (!empty($r['approved']) || !empty($r['manual']));
}
/** First group: sees them with no wait (apply mode only). */
function wqm_first(PDO $pdo, int $emp): bool { return wqm_mode($pdo) === 'apply' && (string)(wqm_rec($pdo, $emp)['level'] ?? '') === 'first'; }

}

$__wqm = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wqm, ['wq.mgr_get', 'wq.mgr_run', 'wq.mgr_cfg', 'wq.mgr_approve', 'wq.mgr_set'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    if (!function_exists('wa_tables') || !wa_tables($pdo) || !function_exists('wq_tables') || !wq_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    $adm = wa_is_admin($pdo, $auth);
    if ($__wqm === 'wq.mgr_get') {
        if ($isPost) json_response(['error' => 'Method not allowed.'], 405); $mode = wqm_mode($pdo);
        if (!$adm) { $r = wqm_rec($pdo, $me); json_response(['ok' => true, 'mode' => $mode, 'card' => ($mode === 'apply' && !empty($r['card'])) ? ['text' => (string)$r['card'], 'level' => (string)$r['level']] : null]); }
        $rows = []; foreach (wqm_collect($pdo) as $id => $s) { $r = wqm_rec($pdo, $id); $rows[] = ['emp_id' => $id, 'sig' => $s, 'level' => (string)($r['level'] ?? ''), 'auto_level' => (string)($r['auto_level'] ?? ''), 'manual' => (string)($r['manual'] ?? ''), 'card' => (string)($r['card'] ?? ''), 'reason' => (string)($r['reason'] ?? ''), 'meet_admin' => !empty($r['meet_admin']), 'approved' => !empty($r['approved']), 'at' => (string)($r['at'] ?? '')]; }
        json_response(['ok' => true, 'mode' => $mode, 'levels' => wqm_levels(), 'rows' => $rows, 'summary' => wq_meta_get($pdo, 'mgr:summary', ''), 'ran_at' => wq_meta_get($pdo, 'mgr:at', '')]);
    }
    if (!$adm) json_response(['error' => 'Only Admin, HR or the control tower can do this.'], 403);
    if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input();
    if ($__wqm === 'wq.mgr_run') { @set_time_limit(120); $r = wqm_run($pdo, true); json_response(['ok' => true, 'count' => count($r)]); }
    if ($__wqm === 'wq.mgr_cfg') { $m = (string)($in['mode'] ?? ''); if (!in_array($m, ['off', 'suggest', 'apply'], true)) json_response(['error' => 'Mode must be off, suggest or apply.'], 422); setting_set($pdo, 'wqm_mode', $m); json_response(['ok' => true, 'mode' => $m]); }
    $emp = (int)($in['emp_id'] ?? 0); if ($emp <= 0) json_response(['error' => 'Choose a person.'], 422); $r = wqm_rec($pdo, $emp);
    if ($__wqm === 'wq.mgr_approve') { $r['approved'] = !empty($in['approve']) ? 1 : 0; wq_meta_put($pdo, 'mgr:' . $emp, json_encode($r, JSON_UNESCAPED_UNICODE)); json_response(['ok' => true]); }
    if ($__wqm === 'wq.mgr_set') {
        $l = (string)($in['level'] ?? 'auto'); if ($l !== 'auto' && !isset(wqm_levels()[$l])) json_response(['error' => 'Unknown level.'], 422);
        $r['manual'] = $l === 'auto' ? '' : $l; $r['level'] = $l === 'auto' ? (string)($r['auto_level'] ?? 'normal') : $l; wq_meta_put($pdo, 'mgr:' . $emp, json_encode($r, JSON_UNESCAPED_UNICODE)); json_response(['ok' => true]);
    }
}
