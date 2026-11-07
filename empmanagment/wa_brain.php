<?php
/**
 * wa_brain.php — the other half of the shared brain for WhatsApp. Loaded by wa_more.php (after wa_follow.php).
 *
 *   Learning & Improvement page (learning.html):
 *     learn.gaps        GET    what the bot noticed by itself: new situations, questions it was unsure about, things it blocked
 *     learn.gap_act     POST   {kind: situation|unsure, id, act: board|ignore}   put it on the Teach board / dismiss it
 *     learn.history     GET    what changed (with Undo where possible)
 *     learn.undo        POST   {id}
 *     learn.results     GET    tiles, which step works, funnel, weekly insights
 *     learn.insight_act POST   {id, act: ask|later}
 *   WhatsApp → Follow-ups screen:
 *     fu.panel          GET    mode, 7-day tiles, "coming up", funnel, quality guard
 *     fu.ladder         GET    the after-24-hours template ladder (steps × versions A/B/C with Meta status and reply %)
 *     fu.ladder_draft   POST   draft the templates from the plans   fu.ladder_send POST {id?}  send drafts to Meta   fu.ladder_edit POST {id, body}   fu.ladder_del POST {id}
 *   Products (wa_prod.php, loaded above): wa.prod_search / prod_suggest / prod_attached / prod_send / prod_status / prod_sync / prod_occ_list / prod_occ_toggle.
 *   Engine helpers: fu_guard (quality guard), fu_ladder_pick, fu_housekeeping (daily situation scan, weekly insights).
 */
require_once __DIR__ . '/wa_follow.php';
if (is_file(__DIR__ . '/wa_prod.php')) require_once __DIR__ . '/wa_prod.php';   // product knowledge (Find a perfume, photos, daily Shopify refresh)

if (!function_exists('fu_guard')) {

function fu_ladder_table(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    if (function_exists('wa_tpl_tables')) wa_tpl_tables($pdo);
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_fu_ladder (id INT AUTO_INCREMENT PRIMARY KEY, rung TINYINT NOT NULL, ver CHAR(1) NOT NULL DEFAULT 'A', tpl_id INT NOT NULL DEFAULT 0, draft MEDIUMTEXT NULL, status VARCHAR(8) NOT NULL DEFAULT 'draft', created_at DATETIME NULL, KEY k_rung (rung)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); $ok = true; }
    catch (PDOException $e) { $ok = false; }
    return $ok;
}
function fu_rung(float $h): int { return $h <= 48 ? 1 : ($h <= 96 ? 2 : ($h <= 240 ? 3 : 4)); }
function fu_rung_info(): array { return [1 => ['Day 2', 'about 24–48 h after our last message'], 2 => ['Day 3–4', 'about 3–4 days'], 3 => ['Day 5–10', 'about a week'], 4 => ['Last call', 'after 10 days']]; }

// ---------------------------------------------------------------------------------------------------------------------- numbers
/** Sent follow-ups of the last $days days with "did they reply" and "did they order afterwards". */
function fu_events(PDO $pdo, int $fromDays, int $toDays = 0): array
{
    $a = date('Y-m-d H:i:s', time() - $fromDays * 86400); $b = date('Y-m-d H:i:s', time() - $toDays * 86400); $om = function_exists('wa_order_map') ? wa_order_map($pdo) : [];
    $q = $pdo->prepare("SELECT id, phone, sid, step, channel, ladder_id, in_id, sent_at FROM wa_fu_ev WHERE status = 'sent' AND sent_at >= ? AND sent_at < ? ORDER BY id DESC LIMIT 3000"); $q->execute([$a, $b]); $o = [];
    foreach ($q->fetchAll() as $e) { $rep = fu_replied($pdo, (string)$e['phone'], (string)$e['sent_at']); $ord = false; $m = $om[(string)$e['phone']] ?? null;
        if ($m && !empty($m['last']) && strtotime((string)$m['last']) > strtotime((string)$e['sent_at'])) $ord = true;
        $o[] = $e + ['replied' => $rep, 'ordered' => $ord]; }
    return $o;
}
function fu_pct(int $a, int $b): ?int { return $b > 0 ? (int)round($a * 100 / $b) : null; }
function fu_funnel(PDO $pdo, int $days = 14): array
{
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE status = 'open' AND optout = 0 AND last_in_at IS NOT NULL AND last_out_at IS NOT NULL AND last_out_at >= last_in_at AND last_in_at >= ?"); $q->execute([$since]); $quiet = (int)$q->fetchColumn();
    $ev = fu_events($pdo, $days); $sent = []; $rep = []; $ord = []; foreach ($ev as $e) { $sent[$e['phone']] = 1; if ($e['replied']) $rep[$e['phone']] = 1; if ($e['ordered']) $ord[$e['phone']] = 1; }
    return [['label' => 'Went quiet', 'n' => max($quiet, count($sent))], ['label' => 'Got a follow-up', 'n' => count($sent)], ['label' => 'Replied', 'n' => count($rep)], ['label' => 'Ordered', 'n' => count($ord)]];
}

// ---------------------------------------------------------------------------------------------------------------------- the quality guard (protects the WhatsApp numbers)
/** ok | slow | stop. slow = fewer follow-ups per minute; stop = nothing is sent by itself. Looks at Meta's quality rating and at how many customers opted out after a follow-up. */
function fu_guard(PDO $pdo): array
{
    $c = json_decode((string)setting_get($pdo, 'wa_fu_guard', ''), true); if (is_array($c) && time() - (int)($c['t'] ?? 0) < 300) return $c;
    $since = date('Y-m-d H:i:s', time() - 7 * 86400); $r = ['state' => 'ok', 'note' => 'Healthy — no change needed.', 'sent' => 0, 'optouts' => 0, 'optout_pct' => 0.0, 'failed' => 0, 'quality' => 'UNKNOWN', 't' => time()];
    try {
        $r['sent'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_fu_ev WHERE status = 'sent' AND sent_at >= '$since'")->fetchColumn(); $r['failed'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_fu_ev WHERE status = 'failed' AND sent_at >= '$since'")->fetchColumn();
        $r['optouts'] = (int)$pdo->query("SELECT COUNT(DISTINCT c.phone) FROM wa_contacts c JOIN wa_fu_ev e ON e.phone = c.phone WHERE c.optout = 1 AND e.status = 'sent' AND e.sent_at >= '$since'")->fetchColumn();
        $people = (int)$pdo->query("SELECT COUNT(DISTINCT phone) FROM wa_fu_ev WHERE status = 'sent' AND sent_at >= '$since'")->fetchColumn(); $r['optout_pct'] = $people >= 15 ? round($r['optouts'] * 100 / $people, 1) : 0.0;
        if (function_exists('wa_health_all')) { $rank = ['UNKNOWN' => 0, 'GREEN' => 1, 'YELLOW' => 2, 'RED' => 3]; $worst = 'UNKNOWN'; foreach (wa_health_all($pdo, false) as $h) if (($rank[$h['quality'] ?? 'UNKNOWN'] ?? 0) > $rank[$worst]) $worst = $h['quality']; $r['quality'] = $worst; }
    } catch (Throwable $e) { }
    if ($r['quality'] === 'RED' || $r['optout_pct'] >= 6) { $r['state'] = 'stop'; $r['note'] = $r['quality'] === 'RED' ? 'A number\'s quality rating is RED — nothing is sent by itself until it recovers.' : 'Too many customers opted out after a follow-up (' . $r['optout_pct'] . '%) — automatic sending is paused.'; }
    elseif ($r['quality'] === 'YELLOW' || $r['optout_pct'] >= 3 || ($r['failed'] >= 10 && $r['failed'] > $r['sent'] / 4)) { $r['state'] = 'slow'; $r['note'] = $r['quality'] === 'YELLOW' ? 'A number\'s quality rating is YELLOW — follow-ups are sent more slowly.' : ($r['optout_pct'] >= 3 ? 'Opt-outs after follow-ups are rising (' . $r['optout_pct'] . '%) — follow-ups are sent more slowly.' : 'Many sends fail — follow-ups are slowed down.'); }
    setting_set($pdo, 'wa_fu_guard', json_encode($r)); return $r;
}

// ---------------------------------------------------------------------------------------------------------------------- the template ladder
/** The approved template to send for a step that falls after the 24-hour window. The version that gets more replies is used more often; a new version gets a fair chance first. */
function fu_ladder_pick(PDO $pdo, float $afterH): ?array
{
    if (!fu_ladder_table($pdo)) return null; $rung = fu_rung($afterH);
    $q = $pdo->prepare("SELECT * FROM wa_fu_ladder WHERE rung = ? AND tpl_id > 0 AND status != 'off'"); $q->execute([$rung]); $cand = [];
    foreach ($q->fetchAll() as $r) { $t = function_exists('wa_camp_tpl') ? wa_camp_tpl($pdo, (int)$r['tpl_id']) : null; if (!$t || $t['nvars'] > 1) continue; $st = fu_ladder_stats($pdo, (int)$r['id']); $cand[] = ['r' => $r, 't' => $t, 'st' => $st]; }
    if (!$cand) return null;
    usort($cand, fn($a, $b) => $a['st']['sent'] <=> $b['st']['sent']); $fresh = $cand[0]; if ($fresh['st']['sent'] < 20) return ['t' => $fresh['t'], 'ladder_id' => (int)$fresh['r']['id']];      // give every version 20 sends first
    usort($cand, fn($a, $b) => ($b['st']['pct'] ?? 0) <=> ($a['st']['pct'] ?? 0)); $pick = mt_rand(1, 100) <= 75 ? $cand[0] : $cand[array_rand($cand)];
    return ['t' => $pick['t'], 'ladder_id' => (int)$pick['r']['id']];
}
/** What would go out after 24 hours for this step (for the preview in the chat): the best approved version, with the first name filled in. null = no approved template yet. */
function fu_ladder_peek(PDO $pdo, float $afterH, string $name): ?array
{
    if (!fu_ladder_table($pdo)) return null; $q = $pdo->prepare("SELECT * FROM wa_fu_ladder WHERE rung = ? AND tpl_id > 0 AND status != 'off' ORDER BY id"); $q->execute([fu_rung($afterH)]); $best = null;
    foreach ($q->fetchAll() as $r) { $t = function_exists('wa_camp_tpl') ? wa_camp_tpl($pdo, (int)$r['tpl_id']) : null; if (!$t || $t['nvars'] > 1) continue; $st = fu_ladder_stats($pdo, (int)$r['id']); if ($best === null || ($st['pct'] ?? 0) > ($best['pct'] ?? 0)) $best = ['t' => $t, 'pct' => $st['pct'], 'ver' => $r['ver']]; }
    return $best ? ['text' => wa_tpl_shown($best['t'], wa_tpl_vars_for($best['t'], $name, [])), 'name' => (string)$best['t']['name'], 'ver' => (string)$best['ver']] : null;
}
function fu_ladder_stats(PDO $pdo, int $lid): array
{
    $q = $pdo->prepare("SELECT phone, sent_at FROM wa_fu_ev WHERE ladder_id = ? AND status = 'sent' AND sent_at >= ?"); $q->execute([$lid, date('Y-m-d H:i:s', time() - 60 * 86400)]); $sent = 0; $rep = 0;
    foreach ($q->fetchAll() as $e) { $sent++; if (fu_replied($pdo, (string)$e['phone'], (string)$e['sent_at'])) $rep++; }
    return ['sent' => $sent, 'replied' => $rep, 'pct' => fu_pct($rep, $sent)];
}
function fu_ladder_view(PDO $pdo): array
{
    fu_ladder_table($pdo); $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $rows = $pdo->query("SELECT * FROM wa_fu_ladder ORDER BY rung, ver, id")->fetchAll(); $by = [];
    foreach ($rows as $r) {
        $d = json_decode((string)$r['draft'], true) ?: []; $name = (string)($d['name'] ?? ''); $body = (string)($d['body'] ?? ''); $status = 'draft'; $reason = '';
        if ((int)$r['tpl_id'] > 0 && function_exists('wa_tpl_row')) { $t = wa_tpl_row($pdo, (int)$r['tpl_id']); if ($t) { $name = (string)$t['name']; $body = (string)$t['body']; $s = wa_tpl_status($pdo, (int)$t['id'], $wabas); $status = $s['status']; $reason = $s['reason']; } else $status = 'missing'; }
        $by[(int)$r['rung']][] = ['id' => (int)$r['id'], 'ver' => (string)$r['ver'], 'name' => $name, 'body' => $body, 'status' => $status, 'reason' => $reason, 'off' => $r['status'] === 'off'] + fu_ladder_stats($pdo, (int)$r['id']);
    }
    $o = []; foreach (fu_rung_info() as $n => [$label, $when]) $o[] = ['rung' => $n, 'label' => $label, 'when' => $when, 'versions' => $by[$n] ?? []];
    $best = null; foreach ($o as $g) foreach ($g['versions'] as $v) if ($v['sent'] >= 20 && ($best === null || ($v['pct'] ?? 0) > ($best['pct'] ?? 0))) $best = $v + ['rung_label' => $g['label']];
    return ['rungs' => $o, 'insight' => $best ? 'Best so far: version ' . $best['ver'] . ' of “' . $best['rung_label'] . '” gets ' . $best['pct'] . '% replies.' : '', 'accounts' => count($wabas), 'guard' => fu_guard($pdo)];
}
/** AI drafts the ladder from the plans the owner saved: for every rung, versions A and B (and C when there is room). */
function fu_ladder_draft(PDO $pdo, int $perRung = 2): array
{
    if (!function_exists('convo_ai')) return [false, 'The AI is not available.', 0]; fu_ladder_table($pdo);
    $__brainScope = function_exists('brain_scope_cur') ? new BrainScope('customer', 'whatsapp', ['all_customer_facts' => true]) : null;   // the templates are customer messages
    $P = fu_plans_get($pdo); $ideas = [1 => [], 2 => [], 3 => [], 4 => []];
    foreach ($P['situations'] as $s) if ($s['stage'] === 'saved' && $s['on'] && $s['kind'] === 'nudge') foreach ($s['steps'] as $x) if ($x['after_h'] > 24) $ideas[fu_rung((float)$x['after_h'])][] = '(' . $s['title'] . ') ' . str_replace('{name}', '{{1}}', $x['text']);
    $have = []; foreach ($pdo->query("SELECT rung, COUNT(*) AS n FROM wa_fu_ladder GROUP BY rung")->fetchAll() as $r) $have[(int)$r['rung']] = (int)$r['n'];
    $facts = ''; if (function_exists('teach_facts_prompt')) [$facts] = teach_facts_prompt($pdo, []); if (function_exists('teach_limits_text')) $facts .= "\n" . teach_limits_text($pdo);
    $made = 0; $err = '';
    foreach (fu_rung_info() as $n => [$label, $when]) {
        $want = $perRung - ($have[$n] ?? 0); if ($want <= 0) continue;
        $sch = ['type' => 'OBJECT', 'properties' => ['templates' => ['type' => 'ARRAY', 'items' => wa_tpl_draft_schema()]], 'required' => ['templates']];
        $sys = "You write pre-made WhatsApp messages (templates that Meta must approve) that a perfume shop (TM Perfume House, Surat) sends to a customer who went quiet MORE than 24 hours ago. This one is the \"$label\" message ($when). Write $want DIFFERENT versions (A, B, ...) with a different angle each (for example: a soft question / a useful reminder / a small reason to reply). "
            . "Each version may use {{1}} for the customer's first name ONLY (at most one variable, never at the very start or the very end). Never invent prices, offers or discounts; no percentages; the later the step, the softer and shorter. category MARKETING. " . WA_TPL_RULES;
        $ctx = $facts . "\nTHE OWNER'S OWN PLAN IDEAS FOR THIS STEP:\n" . ($ideas[$n] ? '- ' . implode("\n- ", array_slice($ideas[$n], 0, 6)) : '(none — use your best judgement)');
        try { [$ok, $v, $why] = convo_ai($sys, [['text' => $ctx]], $sch, false, 3000, 'claude'); } catch (Throwable $e) { $ok = false; $why = $e->getMessage(); }
        if (!$ok || !is_array($v)) { $err = (string)($why ?: 'The AI could not write them.'); continue; }
        $vers = ['A', 'B', 'C', 'D']; $vi = $have[$n] ?? 0;
        foreach (array_slice((array)($v['templates'] ?? []), 0, $want) as $d) {
            if (!is_array($d)) continue; $d['name'] = 'fu_r' . $n . '_' . strtolower($vers[$vi % 4]) . '_' . date('md') . mt_rand(10, 99); $d['category'] = 'MARKETING';
            if (count(wa_tpl_vars((string)($d['body'] ?? ''))) > 1) continue; if (!isset($d['variable_examples']) || !$d['variable_examples']) $d['variable_examples'] = ['Rahul'];
            [$t, $e] = wa_tpl_clean($d); if ($e !== '') continue; $t['why'] = mb_substr((string)($d['why'] ?? ''), 0, 300);
            $pdo->prepare("INSERT INTO wa_fu_ladder (rung, ver, tpl_id, draft, status, created_at) VALUES (?, ?, 0, ?, 'draft', ?)")->execute([$n, $vers[$vi % 4], json_encode($t, JSON_UNESCAPED_UNICODE), fu_now()]); $vi++; $made++;
        }
    }
    return [$made > 0, $made > 0 ? '' : ($err ?: 'Nothing new to draft — every step already has its versions.'), $made];
}
/** Send ONE draft to Meta (every account). Returns [ok, error]. */
function fu_ladder_submit(PDO $pdo, array $row, int $me): array
{
    $d = json_decode((string)$row['draft'], true); if (!is_array($d)) return [false, 'No draft.']; [$t, $err] = wa_tpl_clean($d); if ($err !== '') return [false, $err];
    $wabas = wa_wabas($pdo); if (!$wabas) return [false, 'No WhatsApp Business Account ID yet — add one in WhatsApp → Setup.'];
    $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = ?"); $q->execute([$t['name'], $t['language']]); if ($q->fetchColumn()) $t['name'] = substr($t['name'], 0, 52) . '_' . mt_rand(100, 999);
    $pdo->prepare("INSERT INTO wa_templates (name, language, category, header_format, header_text, body, footer, buttons, examples, purpose, understood, source, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'hrms', ?, ?)")
        ->execute([$t['name'], $t['language'], $t['category'], $t['header_format'], $t['header_text'], $t['body'], $t['footer'], json_encode($t['buttons'], JSON_UNESCAPED_UNICODE), json_encode($t['examples'], JSON_UNESCAPED_UNICODE), 'Follow-up ladder · rung ' . $row['rung'], mb_substr((string)($d['why'] ?? ''), 0, 600), $me, fu_now()]);
    $t['id'] = (int)$pdo->lastInsertId(); $fails = []; foreach ($wabas as $w) { [$ok, $why] = wa_tpl_create_at($pdo, $t, $w); if (!$ok) $fails[] = $why; }
    $pdo->prepare("UPDATE wa_fu_ladder SET tpl_id = ?, status = 'sent' WHERE id = ?")->execute([$t['id'], (int)$row['id']]);
    wa_log($pdo, 'template', !$fails, 'Follow-up ladder: sent ' . $t['name'] . ' to Meta' . ($fails ? ' — ' . $fails[0] : ''));
    return [count($fails) < count($wabas), $fails ? (string)$fails[0] : ''];
}

// ---------------------------------------------------------------------------------------------------------------------- the Follow-ups screen
function fu_panel_view(PDO $pdo): array
{
    fu_tables($pdo); $now = time(); $mode = fu_mode($pdo); $ev7 = fu_events($pdo, 7); $ev14 = fu_events($pdo, 14, 7); $P = fu_plans_get($pdo);
    $rep7 = count(array_filter($ev7, fn($e) => $e['replied'])); $rep14 = count(array_filter($ev14, fn($e) => $e['replied']));
    $p7 = fu_pct($rep7, count($ev7)); $p14 = fu_pct($rep14, count($ev14)); $ord = []; foreach ($ev7 as $e) if ($e['ordered']) $ord[$e['phone']] = 1;
    $days = []; for ($i = 6; $i >= 0; $i--) { $d = date('Y-m-d', $now - $i * 86400); $days[$d] = ['d' => date('D', $now - $i * 86400), 'sent' => 0, 'replied' => 0]; }
    foreach ($ev7 as $e) { $d = substr((string)$e['sent_at'], 0, 10); if (isset($days[$d])) { $days[$d]['sent']++; if ($e['replied']) $days[$d]['replied']++; } }
    $names = []; foreach ($P['situations'] as $s) $names[$s['id']] = $s;
    $coming = []; $q = $pdo->prepare("SELECT s.*, c.name, c.last_in_at, c.state AS cstate FROM wa_fu_state s JOIN wa_contacts c ON c.phone = s.phone WHERE s.status IN ('plan','ready','tpl') AND s.due_at IS NOT NULL AND s.due_at <= ? ORDER BY s.due_at LIMIT 40");
    $q->execute([date('Y-m-d H:i:s', $now + 48 * 3600)]);
    foreach ($q->fetchAll() as $r) { $sit = $names[$r['sid']] ?? null; if (!$sit) continue; $due = strtotime((string)$r['due_at']); $in = $r['last_in_at'] ? strtotime((string)$r['last_in_at']) : 0; $idea = (string)($sit['steps'][(int)$r['step']]['text'] ?? '');
        $coming[] = ['phone' => (string)$r['phone'], 'name' => (string)$r['name'], 'in_min' => (int)round(($due - $now) / 60), 'sid' => (string)$r['sid'], 'title' => $sit['title'], 'step' => (int)$r['step'] + 1, 'total' => count($sit['steps']), 'temp' => (string)$r['temp'], 'why' => (string)$r['why'],
            'text' => (string)($r['draft'] ?: str_replace('{name}', fu_first_name((string)$r['name']), $idea)), 'ready' => trim((string)$r['draft']) !== '', 'channel' => $due > $in + 86400 ? 'tpl' : 'free', 'human' => (string)$r['cstate'] === 'human']; }
    $saved = array_values(array_filter($P['situations'], fn($s) => $s['stage'] === 'saved' && $s['on'] && $s['kind'] === 'nudge'));
    $st = $pdo->query("SELECT status, COUNT(*) AS n FROM wa_fu_state GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $ladder = []; if (fu_ladder_table($pdo)) { $lr = $pdo->query("SELECT rung, SUM(tpl_id > 0) AS n FROM wa_fu_ladder GROUP BY rung")->fetchAll(PDO::FETCH_KEY_PAIR); $ladder = ['rungs_with_templates' => count(array_filter($lr)), 'rungs' => 4]; }
    return ['mode' => $mode, 'tiles' => ['sent' => count($ev7), 'reply_pct' => $p7, 'reply_delta' => ($p7 !== null && $p14 !== null) ? $p7 - $p14 : null, 'orders' => count($ord), 'waiting' => (int)($st['plan'] ?? 0) + (int)($st['ready'] ?? 0) + (int)($st['tpl'] ?? 0)],
        'days' => array_values($days), 'coming' => $coming, 'funnel' => fu_funnel($pdo, 14), 'guard' => fu_guard($pdo), 'limits' => fu_limit_lines($pdo), 'plans' => ['saved' => count($saved), 'total' => count(array_filter($P['situations'], fn($s) => $s['kind'] === 'nudge'))],
        'ladder' => $ladder, 'engine' => ['cron_at' => (string)setting_get($pdo, 'wa_cron_at', ''), 'fresh' => (bool)((int)strtotime((string)setting_get($pdo, 'wa_cron_at', '')) > $now - 300)]];
}

// ---------------------------------------------------------------------------------------------------------------------- gaps, insights (the bot notices things by itself)
function learn_board_pub(PDO $pdo, array $b): array
{
    $today = date('Y-m-d'); $o = [];
    foreach ($b['topics'] as $t) { $lm = teach_limits_clean($t['limits'] ?? []); $o[] = ['id' => $t['id'], 'title' => $t['title'], 'open' => array_values((array)$t['open']), 'rules' => array_values((array)($t['rules'] ?? [])), 'replaces' => array_values((array)($t['replaces'] ?? [])), 'understood' => (string)($t['understood'] ?? ''), 'seen' => (string)($t['seen'] ?? ''), 'evidence' => (int)($t['evidence'] ?? 0), 'round' => count((array)($t['hist'] ?? [])),
        'stage' => count((array)$t['open']) ? 'ask' : 'review', 'concept' => array_values((array)($t['concept'] ?? [])), 'limits' => $lm, 'from' => (string)($t['from'] ?? ''), 'to' => (string)($t['to'] ?? ''), 'src' => (string)($t['src'] ?? 'said')]; }
    $g = []; foreach (teach_limits_get($pdo)['groups'] as $x) { if (!is_array($x)) continue; $to = (string)($x['to'] ?? ''); $fr = (string)($x['from'] ?? '');
        $g[] = ['gid' => (string)$x['gid'], 'title' => (string)($x['title'] ?? ''), 'from' => $fr, 'to' => $to, 'lines' => teach_limits_lines($x), 'state' => ($to !== '' && $to < $today) ? 'ended' : (($fr !== '' && $fr > $today) ? 'upcoming' : 'active'), 'soon' => $to !== '' && $to >= $today && $to <= date('Y-m-d', strtotime('+1 day'))]; }
    return ['topics' => $o, 'done' => $b['done'], 'note' => $b['note'], 'groups' => $g, 'today' => $today];
}
function learn_board_load(PDO $pdo): array
{
    $q = $pdo->prepare("SELECT body FROM lib_facts WHERE k = 'ivboard'"); $q->execute(); $j = json_decode((string)$q->fetchColumn(), true); if (!is_array($j)) $j = [];
    return ['topics' => array_values((array)($j['topics'] ?? [])), 'done' => (int)($j['done'] ?? 0), 'note' => (string)($j['note'] ?? '')];
}
/** Put a new topic on the Teach board (same shape the "Teach by talking" AI makes). Returns the topic id, or '' when the board is full. */
function learn_board_topic(PDO $pdo, string $title, array $questions, string $seen): string
{
    $B = learn_board_load($pdo);
    $id = 't' . substr(md5(uniqid('', true)), 0, 6);
    $B['topics'][] = ['id' => $id, 'title' => mb_substr($title, 0, 60), 'open' => array_values(array_slice($questions, 0, 6)), 'rules' => [], 'replaces' => [], 'hist' => [], 'understood' => '', 'seen' => mb_substr($seen, 0, 500), 'evidence' => 0, 'concept' => [], 'limits' => ['rows' => [], 'gen' => []], 'from' => '', 'to' => '', 'src' => 'wa'];
    teach_facts_set($pdo, 'ivboard', json_encode($B, JSON_UNESCAPED_UNICODE)); return $id;
}
/** Once a day: what does the team keep answering with their OWN words (no suggestion existed) that no situation or lesson covers? */
function learn_scan(PDO $pdo): int
{
    if (!function_exists('convo_ai') || !learn_hist_tables($pdo) || !function_exists('wa_learn_tables') || !wa_learn_tables($pdo)) return 0;
    if (time() - (int)strtotime((string)setting_get($pdo, 'wa_gap_scan_at', '2000-01-01')) < 20 * 3600) return 0; setting_set($pdo, 'wa_gap_scan_at', fu_now());
    $q = $pdo->prepare("SELECT customer, sent FROM wa_learn WHERE kind = 'own' AND created_at >= ? AND CHAR_LENGTH(customer) >= 6 ORDER BY id DESC LIMIT 60"); $q->execute([date('Y-m-d H:i:s', time() - 14 * 86400)]); $rows = $q->fetchAll();
    if (count($rows) < 6) return 0;
    $known = []; foreach (fu_plans_get($pdo)['situations'] as $s) $known[] = $s['title'];
    $list = ''; foreach ($rows as $i => $r) $list .= ($i + 1) . '. Customer: ' . mb_substr(str_replace("\n", ' ', (string)$r['customer']), 0, 160) . ' | Team: ' . mb_substr(str_replace("\n", ' ', (string)$r['sent']), 0, 160) . "\n";
    $sch = ['type' => 'OBJECT', 'properties' => ['situations' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['title' => ['type' => 'STRING'], 'why' => ['type' => 'STRING'], 'n_chats' => ['type' => 'INTEGER'], 'suggest' => ['type' => 'STRING']], 'required' => ['title', 'why', 'n_chats', 'suggest']]]], 'required' => ['situations']];
    try { [$ok, $v] = convo_ai("Below are chats of a perfume shop where the team wrote their OWN reply (the bot had no suggestion). Find at most 2 RECURRING situations (3 or more chats) that look like they should become a rule for the bot. Do not repeat these known situations: " . implode('; ', $known) . ". title = max 6 words. why = one plain English sentence with what the customers say / want. n_chats = how many of the listed chats fit. suggest = one short sentence idea for what the bot should do. If nothing recurs, return an empty list.",
        [['text' => $list]], $sch, false, 1200, 'claude'); } catch (Throwable $e) { return 0; }
    if (!$ok || !is_array($v)) return 0; $n = 0;
    foreach (array_slice((array)($v['situations'] ?? []), 0, 2) as $x) { if (!is_array($x) || (int)($x['n_chats'] ?? 0) < 3 || trim((string)($x['title'] ?? '')) === '') continue;
        $key = substr(md5(mb_strtolower(trim((string)$x['title']))), 0, 20); $c = $pdo->prepare("SELECT id FROM wa_gap WHERE kind = 'sit' AND gkey = ?"); $c->execute([$key]); if ($c->fetchColumn()) continue;
        $pdo->prepare("INSERT INTO wa_gap (kind, gkey, title, why, body, suggest, n, status, at) VALUES ('sit', ?, ?, ?, '', ?, ?, 'new', ?)")->execute([$key, mb_substr(trim((string)$x['title']), 0, 100), mb_substr((string)$x['why'], 0, 400), mb_substr((string)$x['suggest'], 0, 240), min(count($rows), (int)$x['n_chats']), fu_now()]); $n++; }
    return $n;
}
/** Once a week: plain-English observations from the numbers, each one a question for the owner. */
function learn_insights(PDO $pdo): int
{
    if (!learn_hist_tables($pdo)) return 0; if (time() - (int)strtotime((string)setting_get($pdo, 'wa_ins_at', '2000-01-01')) < 6 * 86400) return 0;
    $ev = fu_events($pdo, 30); if (count($ev) < 20) return 0; setting_set($pdo, 'wa_ins_at', fu_now()); $out = []; $P = fu_plans_get($pdo); $title = []; foreach ($P['situations'] as $s) $title[$s['id']] = $s['title'];
    $by = []; foreach ($ev as $e) { $k = $e['sid'] . '|' . $e['step']; $by[$k]['s'] = ($by[$k]['s'] ?? 0) + 1; if ($e['replied']) $by[$k]['r'] = ($by[$k]['r'] ?? 0) + 1; $by[$k]['sid'] = $e['sid']; $by[$k]['step'] = (int)$e['step'] + 1; }
    foreach ($by as $b) { if ($b['s'] < 12) continue; $pct = fu_pct((int)($b['r'] ?? 0), $b['s']); $t = $title[$b['sid']] ?? 'a situation';
        if ($pct !== null && $pct <= 4) $out[] = ['text' => '“' . $t . '” — follow-up ' . $b['step'] . ' got only ' . $pct . '% replies (' . $b['s'] . ' sent). Should the wording change, or should the bot stop before this step?', 'q' => 'Follow-up ' . $b['step'] . ' of “' . $t . '” gets almost no replies (' . $pct . '%). What should the bot say instead, or should it stop earlier?'];
        if ($pct !== null && $pct >= 25) $out[] = ['text' => '“' . $t . '” — follow-up ' . $b['step'] . ' works well: ' . $pct . '% replied. Should the bot use the same idea in other situations?', 'q' => 'Follow-up ' . $b['step'] . ' of “' . $t . '” gets ' . $pct . '% replies. Should the same idea be used in the other situations too?']; }
    $hours = array_fill(0, 24, ['s' => 0, 'r' => 0]); foreach ($ev as $e) { $h = (int)date('G', strtotime((string)$e['sent_at'])); $hours[$h]['s']++; if ($e['replied']) $hours[$h]['r']++; }
    $bestH = null; $bestP = 0; foreach ($hours as $h => $x) if ($x['s'] >= 8) { $p = $x['r'] / $x['s']; if ($p > $bestP) { $bestP = $p; $bestH = $h; } }
    if ($bestH !== null && $bestP >= 0.2) $out[] = ['text' => 'Follow-ups sent around ' . date('g a', mktime($bestH)) . ' get the most replies (' . round($bestP * 100) . '%). Should the bot prefer that time?', 'q' => 'Customers reply most to follow-ups sent around ' . date('g a', mktime($bestH)) . ' (' . round($bestP * 100) . '%). Should the bot prefer that time of day?'];
    $n = 0; foreach (array_slice($out, 0, 3) as $x) { $key = substr(md5($x['text']), 0, 20); $c = $pdo->prepare("SELECT id FROM wa_gap WHERE kind = 'ins' AND gkey = ?"); $c->execute([$key]); if ($c->fetchColumn()) continue;
        $pdo->prepare("INSERT INTO wa_gap (kind, gkey, title, why, body, suggest, n, status, at) VALUES ('ins', ?, '', '', ?, ?, 1, 'new', ?)")->execute([$key, mb_substr($x['text'], 0, 600), mb_substr($x['q'], 0, 240), fu_now()]); $n++; }
    return $n;
}
/** Called from the cron after the engine. */
function fu_housekeeping(PDO $pdo): array
{
    $o = []; try { $o['scan'] = learn_scan($pdo); } catch (Throwable $e) { $o['scan'] = 'error'; } try { $o['insights'] = learn_insights($pdo); } catch (Throwable $e) { $o['insights'] = 'error'; }
    if (function_exists('wa_prod_cron')) { try { $o['products'] = wa_prod_cron($pdo); } catch (Throwable $e) { $o['products'] = 'error'; } }   // 06:30 IST Shopify refresh (runs after the response)
    try { $pdo->exec("DELETE FROM wa_fu_state WHERE updated_at < '" . date('Y-m-d H:i:s', time() - 45 * 86400) . "'"); } catch (Throwable $e) { } return $o;
}

}   // function_exists guard

// ---------------------------------------------------------------------------------------------------------------------- router
$__wbn = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wbn, ['learn.gaps', 'learn.gap_act', 'learn.history', 'learn.undo', 'learn.results', 'learn.insight_act', 'fu.panel', 'fu.ladder', 'fu.ladder_draft', 'fu.ladder_send', 'fu.ladder_edit', 'fu.ladder_del'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    if (strpos($__wbn, 'learn.') === 0 && function_exists('lib_guard')) lib_guard($pdo, $auth); else wa_guard_admin($pdo, $auth);
    if (!wa_tables($pdo) || !fu_tables($pdo) || !learn_hist_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    fu_ladder_table($pdo); $in = $isPost ? json_input() : $_GET;
    switch ($__wbn) {
        case 'learn.history': json_response(['items' => learn_hist_list($pdo)]);
        case 'learn.undo': { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); [$ok, $e] = learn_hist_undo($pdo, (int)($in['id'] ?? 0)); if (!$ok) json_response(['error' => $e], 422); json_response(['ok' => true]); }
        case 'learn.gaps': {
            $o = ['situations' => [], 'unsure' => [], 'blocked' => []];
            foreach ($pdo->query("SELECT * FROM wa_gap WHERE kind = 'sit' AND status = 'new' ORDER BY id DESC LIMIT 6")->fetchAll() as $r) $o['situations'][] = ['id' => (string)$r['id'], 'title' => (string)$r['title'], 'why' => (string)$r['why'], 'n_chats' => (int)$r['n'], 'suggest' => (string)$r['suggest']];
            foreach ($pdo->query("SELECT * FROM wa_gap WHERE kind = 'unsure' AND status = 'new' ORDER BY n DESC, id DESC LIMIT 12")->fetchAll() as $r) $o['unsure'][] = ['id' => (string)$r['id'], 'text' => (string)$r['body'], 'count' => (int)$r['n']];
            foreach ($pdo->query("SELECT * FROM wa_gap WHERE kind = 'block' AND at >= '" . date('Y-m-d H:i:s', time() - 14 * 86400) . "' ORDER BY at DESC LIMIT 8")->fetchAll() as $r) $o['blocked'][] = ['id' => (string)$r['id'], 'why' => (string)$r['why'], 'at' => (string)$r['at']];
            json_response($o); }
        case 'learn.gap_act': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $kind = (string)($in['kind'] ?? '') === 'unsure' ? 'unsure' : 'sit'; $q = $pdo->prepare("SELECT * FROM wa_gap WHERE id = ? AND kind = ?"); $q->execute([(int)($in['id'] ?? 0), $kind]); $g = $q->fetch();
            if (!$g) json_response(['error' => 'Not found.'], 404);
            if ((string)($in['act'] ?? '') === 'ignore') { $pdo->prepare("UPDATE wa_gap SET status = 'ignored' WHERE id = ?")->execute([$g['id']]); json_response(['ok' => true]); }
            if ($kind === 'sit') $tid = learn_board_topic($pdo, (string)$g['title'], ['What should the bot do when this happens: ' . $g['why'] . '?', 'Does it need a special price, an offer or a limit?', 'Should the bot follow up in this situation — and after how long?'], (string)$g['why'] . ($g['suggest'] !== '' ? ' (Idea: ' . $g['suggest'] . ')' : ''));
            else $tid = learn_board_topic($pdo, 'Customers ask: ' . mb_substr((string)$g['body'], 0, 36), ['How should the bot answer: “' . mb_substr((string)$g['body'], 0, 200) . '”?', 'Is there a fact (price, size, policy) it should know to answer this?'], 'The bot was unsure about this ' . (int)$g['n'] . ' time(s).');
            if ($tid === '') json_response(['error' => 'The Teach board is full (12 topics) — finish one with “Got it” first.'], 422);
            $pdo->prepare("UPDATE wa_gap SET status = 'board' WHERE id = ?")->execute([$g['id']]); learn_hist_add($pdo, 'Added “' . ($kind === 'sit' ? $g['title'] : mb_substr((string)$g['body'], 0, 40)) . '” to the Teach board');
            json_response(['ok' => true, 'touched' => [$tid]] + learn_board_pub($pdo, learn_board_load($pdo))); }
        case 'learn.insight_act': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $q = $pdo->prepare("SELECT * FROM wa_gap WHERE id = ? AND kind = 'ins'"); $q->execute([(int)($in['id'] ?? 0)]); $g = $q->fetch(); if (!$g) json_response(['error' => 'Not found.'], 404);
            if ((string)($in['act'] ?? '') !== 'ask') { $pdo->prepare("UPDATE wa_gap SET status = 'later', at = ? WHERE id = ?")->execute([fu_now(), $g['id']]); json_response(['ok' => true]); }
            $tid = learn_board_topic($pdo, 'Follow-up insight', [(string)$g['suggest']], (string)$g['body']); if ($tid === '') json_response(['error' => 'The Teach board is full (12 topics) — finish one with “Got it” first.'], 422);
            $pdo->prepare("UPDATE wa_gap SET status = 'board' WHERE id = ?")->execute([$g['id']]); learn_hist_add($pdo, 'Turned a weekly insight into a question on the Teach board');
            json_response(['ok' => true, 'touched' => [$tid]] + learn_board_pub($pdo, learn_board_load($pdo))); }
        case 'learn.results': {
            $t = ['used_pct' => null, 'used_delta' => null, 'fu_reply_pct' => null, 'fu_delta' => null, 'fu_orders' => 0];
            if (function_exists('wa_learn_tables') && wa_learn_tables($pdo)) {
                $used = function (int $from, int $to) use ($pdo) { $q = $pdo->prepare("SELECT SUM(kind = 'used') AS u, COUNT(*) AS n FROM wa_learn WHERE created_at >= ? AND created_at < ?"); $q->execute([date('Y-m-d H:i:s', time() - $from * 86400), date('Y-m-d H:i:s', time() - $to * 86400)]); $r = $q->fetch(); return [(int)$r['u'], (int)$r['n']]; };
                [$u30, $n30] = $used(30, 0); [$u7, $n7] = $used(7, 0); [$u14, $n14] = $used(14, 7); $t['used_pct'] = fu_pct($u30, $n30);
                if ($n7 >= 5 && $n14 >= 5) $t['used_delta'] = fu_pct($u7, $n7) - fu_pct($u14, $n14);
            }
            $e30 = fu_events($pdo, 30); $e7 = fu_events($pdo, 7); $e14 = fu_events($pdo, 14, 7); $r30 = count(array_filter($e30, fn($e) => $e['replied']));
            $t['fu_reply_pct'] = fu_pct($r30, count($e30)); $a = fu_pct(count(array_filter($e7, fn($e) => $e['replied'])), count($e7)); $b = fu_pct(count(array_filter($e14, fn($e) => $e['replied'])), count($e14)); if ($a !== null && $b !== null && count($e7) >= 5 && count($e14) >= 5) $t['fu_delta'] = $a - $b;
            $ord = []; foreach ($e7 as $e) if ($e['ordered']) $ord[$e['phone']] = 1; $t['fu_orders'] = count($ord);
            $st = []; foreach ($e30 as $e) { $n = (int)$e['step'] + 1; $st[$n]['s'] = ($st[$n]['s'] ?? 0) + 1; if ($e['replied']) $st[$n]['r'] = ($st[$n]['r'] ?? 0) + 1; } ksort($st); $steps = []; foreach ($st as $n => $x) $steps[] = ['n' => $n, 'pct' => (int)fu_pct((int)($x['r'] ?? 0), $x['s'])];
            $ins = []; foreach ($pdo->query("SELECT id, body FROM wa_gap WHERE kind = 'ins' AND (status = 'new' OR (status = 'later' AND at < '" . date('Y-m-d H:i:s', time() - 7 * 86400) . "')) ORDER BY id DESC LIMIT 3")->fetchAll() as $r) $ins[] = ['id' => (string)$r['id'], 'text' => (string)$r['body']];
            json_response(['tiles' => $t, 'steps' => $steps, 'funnel' => fu_funnel($pdo, 14), 'insights' => $ins]); }
        case 'fu.panel': json_response(fu_panel_view($pdo));
        case 'fu.ladder': json_response(fu_ladder_view($pdo));
        case 'fu.ladder_draft': { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); ignore_user_abort(true); @set_time_limit(150); [$ok, $e, $n] = fu_ladder_draft($pdo, 2); if (!$ok) json_response(['error' => $e], 422); learn_hist_add($pdo, 'Drafted ' . $n . ' follow-up templates for after 24 hours'); json_response(['ok' => true, 'made' => $n] + fu_ladder_view($pdo)); }
        case 'fu.ladder_edit': { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $q = $pdo->prepare("SELECT * FROM wa_fu_ladder WHERE id = ?"); $q->execute([(int)($in['id'] ?? 0)]); $r = $q->fetch(); if (!$r) json_response(['error' => 'Not found.'], 404);
            if ((int)$r['tpl_id'] > 0) json_response(['error' => 'This one is already at Meta — a sent message cannot be edited. Delete it and draft another.'], 422);
            $d = json_decode((string)$r['draft'], true) ?: []; $d['body'] = trim((string)($in['body'] ?? '')); [$t, $e] = wa_tpl_clean($d + ['variable_examples' => ['Rahul']]); if ($e !== '') json_response(['error' => $e], 422); if (count(wa_tpl_vars($t['body'])) > 1) json_response(['error' => 'Use {{1}} (the first name) only — one variable at most.'], 422);
            $t['why'] = (string)($d['why'] ?? ''); $pdo->prepare("UPDATE wa_fu_ladder SET draft = ? WHERE id = ?")->execute([json_encode($t, JSON_UNESCAPED_UNICODE), $r['id']]); json_response(['ok' => true] + fu_ladder_view($pdo)); }
        case 'fu.ladder_del': { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $pdo->prepare("UPDATE wa_fu_ladder SET status = 'off' WHERE id = ? AND tpl_id > 0")->execute([(int)($in['id'] ?? 0)]); $pdo->prepare("DELETE FROM wa_fu_ladder WHERE id = ? AND tpl_id = 0")->execute([(int)($in['id'] ?? 0)]); json_response(['ok' => true] + fu_ladder_view($pdo)); }
        case 'fu.ladder_send': { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); ignore_user_abort(true); @set_time_limit(150); $id = (int)($in['id'] ?? 0);
            $rows = $id ? $pdo->query("SELECT * FROM wa_fu_ladder WHERE id = $id AND tpl_id = 0")->fetchAll() : $pdo->query("SELECT * FROM wa_fu_ladder WHERE tpl_id = 0 ORDER BY rung, ver")->fetchAll(); if (!$rows) json_response(['error' => 'No drafts to send.'], 422);
            $n = 0; $fails = []; foreach ($rows as $r) { [$ok, $e] = fu_ladder_submit($pdo, $r, $me); if ($ok) $n++; else $fails[] = $e; }
            learn_hist_add($pdo, 'Sent ' . $n . ' follow-up template(s) to Meta for approval'); json_response(['ok' => $n > 0, 'sent' => $n, 'errors' => $fails] + fu_ladder_view($pdo)); }
    }
}

// the chat-level follow-up actions (fu.chat, fu.send_now …) live in wa_follow.php but need the helpers above, so they are answered from here
if (!defined('FU_ROUTE_NOW')) { define('FU_ROUTE_NOW', 1); include __DIR__ . '/wa_follow.php'; }
