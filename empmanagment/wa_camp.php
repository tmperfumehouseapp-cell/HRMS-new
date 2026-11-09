<?php
/**
 * wa_camp.php — follow-ups, campaigns (smart multi-number sender) and reports of the WhatsApp console. Loaded by wa_more.php.
 *
 *   wa.fu_list / wa.fu_save / wa.fu_del / wa.fu_preset     follow-up steps (after 2 h, 24 h, 48 h, 30 d, 60 d … of silence), each sends one approved pre-made message
 *   wa.camp_list / wa.camp_get / wa.camp_preview / wa.camp_save / wa.camp_start / wa.camp_pause / wa.camp_resume / wa.camp_cancel / wa.camp_del / wa.camp_tick
 *   wa.camp_suggest        GET  "Claude suggests" audiences (rule based: tags, orders, when they last wrote) with a customer count each
 *   wa.camp_best_time      GET  at which hours customers write to us (last 30 days) + the best hours
 *   wa.camp_people         GET  {id, f, q, page, per}  who got what: one row per customer with status, reason, tries, exact wording  (+ counts per filter tab)
 *   wa.camp_people_csv     GET  {id, f}  the same list as a CSV file
 *   wa.piece_*             the message pieces library (wa_pieces.php)
 *   wa.reports                                             replies, reply speed per person, numbers, campaigns, cost
 * All of these are Admin / HR only. The engine itself runs from wa.cron (Hostinger cron, every minute).
 *
 * How the smart sender picks a number for each customer:
 *   1. a number that is switched off, not connected, Low quality or without room today is never used;
 *   2. if the customer wrote to number X recently and has not ignored what X sent since, keep X;
 *   3. a number whose last N messages (default 2) the customer ignored is left out for that customer; another number is used;
 *   4. among the rest, the one with the most room left today;   5. a customer who ignored every number is skipped.
 * Room today = our slow ramp-up (250 → 500 → 1,000 … per number, by days since its first template) and Meta's daily limit, which is shared by all numbers.
 *
 * When it sends:  any hour of the day or night — the owner decides (no quiet hours; settings key wa_camp_window = "8-21" can bring them back);  each customer gets the message at their own usual hour
 * (needs 5+ messages from them, else the campaign's default hour, 7 pm) unless the campaign says "everyone at the same time".
 * Retries: a message Meta could not deliver is sent again (same words) 3 hours later, then the next day at the customer's hour — 3 tries in all, 2 for "not on WhatsApp";
 * never for opted-out customers, spam blocks (the campaign pauses) or Meta's marketing limit (skipped). Meta charges only for delivered messages.
 * What goes into the variables: see wa_camp_vars_norm() (sources: name, asked_perfume, last_ordered, city, days_since, fixed, piece, coupon) and wa_pieces.php.
 */
if (!function_exists('wa_camp_tick')) {

require_once __DIR__ . '/wa_pieces.php';   // the message pieces library (also answers wa.piece_* actions)
require_once __DIR__ . '/wa_mix.php';      // marketing + utility mix, one panel for all numbers, families, auto-submit

if (!function_exists('wa_http_multi')) {
    /** Several message sends at once. Each request: ['path' => '/PHONEID/messages', 'json' => [...]]. Returns [[code, json], …] in the same order. Replaceable in tests. */
    function wa_http_multi(array $reqs): array
    {
        $tok = wa_cfg_val('WA_TOKEN'); $mh = curl_multi_init(); $hs = [];
        foreach ($reqs as $i => $r) {
            $ch = curl_init('https://graph.facebook.com/' . wa_graph() . $r['path']);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($r['json'], JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json']]);
            curl_multi_add_handle($mh, $ch); $hs[$i] = $ch;
        }
        do { $st = curl_multi_exec($mh, $act); } while ($st === CURLM_CALL_MULTI_PERFORM);
        while ($act && $st === CURLM_OK) { if (curl_multi_select($mh, 1.0) === -1) usleep(100000); do { $st = curl_multi_exec($mh, $act); } while ($st === CURLM_CALL_MULTI_PERFORM); }
        $out = [];
        foreach ($hs as $i => $ch) {
            $raw = curl_multi_getcontent($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $out[$i] = ($raw === null || $raw === '') ? [0, ['error' => ['message' => 'No answer from Meta: ' . curl_error($ch)]]] : [$code, json_decode((string)$raw, true)];
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
        }
        curl_multi_close($mh); return $out;
    }
}

// ---------------------------------------------------------------------------------------------------------------- templates & numbers
/** The template as it can be sent: a row with its approvals. Returns null when it cannot be sent (missing, not approved anywhere, or — unless $media — a photo / video / PDF / location on top).
 *  $media = true: a campaign may use such a template; $libId = the campaign's chosen photo / video / PDF from the media library (it replaces the template's own header file). */
function wa_camp_tpl(PDO $pdo, int $id, bool $media = false, int $libId = 0): ?array
{
    if (!function_exists('wa_tpl_tables') || !wa_tpl_tables($pdo)) return null;
    $t = wa_tpl_row($pdo, $id); if (!$t) return null;
    $hf = strtoupper((string)$t['header_format']);
    if (!in_array($hf, ['', 'TEXT'], true)) {
        if (!$media || !function_exists('wa_tpl_send_components') || !function_exists('wa_tpl_sendable')) return null;   // until the template module can build such a message: as before
        if (in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && $libId > 0) $t['header_lib_id'] = $libId;
        if (!wa_tpl_sendable($t)) return null;
    }
    $q = $pdo->prepare("SELECT waba_id FROM wa_tpl_waba WHERE tpl_id = ? AND status = 'APPROVED'"); $q->execute([$id]); $t['ok_wabas'] = array_column($q->fetchAll(), 'waba_id');
    $t['nvars'] = count(wa_tpl_vars((string)$t['body'])); return $t['ok_wabas'] ? $t : null;
}
/** Number health WITHOUT asking Meta: the Campaign panel must open fast. The background engine (wa_camp_tick) keeps the answer fresh. Asks Meta only if nothing was ever saved. */
function wa_camp_health(PDO $pdo): array
{
    try { $cache = json_decode((string)setting_get($pdo, 'wa_health', ''), true); } catch (Throwable $e) { $cache = null; }
    $nums = $pdo->query("SELECT * FROM wa_numbers ORDER BY id")->fetchAll(); $out = [];
    if (is_array($cache)) {
        foreach ($nums as $n) {
            $id = (int)$n['id']; $h = $cache[$id] ?? null; if (!is_array($h)) { $out = []; break; }
            $h['label'] = (string)$n['label'] !== '' ? (string)$n['label'] : (($h['verified_name'] ?? '') ?: (($h['display'] ?? '') ?: 'Number ' . $id)); $h['id'] = $id; $out[] = $h;
        }
        if ($out || !$nums) return $out;
    }
    if (!function_exists('wa_health_all') && is_file(__DIR__ . '/wa_console.php')) require_once __DIR__ . '/wa_console.php';   // number health lives in wa_console.php (not loaded on every route)
    return wa_health_all($pdo, false);
}
/** The numbers that may send this template right now, with the room each has today. */
function wa_camp_ctx(PDO $pdo, array $t, int $switch, array $only = []): array
{
    if (!function_exists('wa_health_all') && is_file(__DIR__ . '/wa_console.php')) require_once __DIR__ . '/wa_console.php';   // number health lives in wa_console.php (not loaded on every route)
    $health = wa_health_all($pdo, false); $caps = wa_number_caps($pdo, $health); $nums = []; $only = array_map('intval', $only);
    $st = []; foreach ($health as $h) $st[(int)$h['id']] = (string)($h['status'] ?? '');
    foreach ($pdo->query("SELECT * FROM wa_numbers ORDER BY id")->fetchAll() as $n) {
        $id = (int)$n['id']; $c = $caps[$id] ?? null; if (!$c || $c['left'] <= 0 || !in_array(wa_waba_of($n), $t['ok_wabas'], true)) continue;
        if ($only && !in_array($id, $only, true)) continue;                     // the campaign chose its numbers ("Send from")
        if (($st[$id] ?? '') !== '' && $st[$id] !== 'CONNECTED') continue;      // pending / not connected at Meta: never used
        $nums[$id] = ['id' => $id, 'phone_id' => (string)$n['phone_id'], 'cap' => $c['cap'], 'used' => $c['used'], 'left' => $c['left']];
        if (!empty($t['by_waba'][wa_waba_of($n)])) $nums[$id]['tm'] = $t['by_waba'][wa_waba_of($n)];   // the version of the message approved on this number's WhatsApp account (the first one, or an alternative)
    }
    $p = $caps['_port'] ?? ['left' => 0]; $st = 0.05; try { $sv = setting_get($pdo, 'wa_camp_sticky', ''); if ($sv !== '' && is_numeric($sv)) $st = max(0.0, min(1.0, (float)$sv)); } catch (Throwable $e) { }
    return ['nums' => $nums, 'port_left' => (int)$p['left'], 'port' => $p, 'switch' => max(1, $switch), 'sticky' => $st];
}
/** 📈 Campaigns only: each number's room this hour (its own speed minus the campaign people it messaged in the last 60 minutes). Returns [numberId => left] or null (no hourly speed). */
function wa_camp_hour_room(array $port): ?array
{
    if (empty($port['auto']['nums'])) return null; $o = []; foreach ($port['auto']['nums'] as $nid => $n) $o[(int)$nid] = (int)$n['left_hour']; return $o;
}
/** Why there is no room, in plain words with the numbers, and that it carries on by itself. */
function wa_room_why(array $p): string
{
    $max = (int)floor((int)($p['tier'] ?? 0) * 0.9); $au = $p['auto'] ?? null;
    if (!empty($p['hour_full']) && $au && $max - (int)($p['used'] ?? 0) > 0)
        return 'Waiting for the next hour: every number used its hourly speed (' . implode(' · ', array_map(fn($n) => $n['label'] . ' ' . number_format($n['used_hour']) . ' of ' . number_format($n['cap']), $au['nums'])) . '). It carries on by itself.';
    return 'Waiting for room: Meta lets you message ' . number_format($max) . ' new people in 24 hours' . (!empty($p['assumed']) ? ' (Meta did not tell your limit, 250 assumed)' : '') . ' and ' . number_format((int)($p['used'] ?? 0)) . ' got one already. It carries on by itself as the 24 hours pass.';
}
/** Which number sends to this customer. Returns [numberId, whyNot]. */
function wa_pick_number(PDO $pdo, array $c, array &$ctx): array
{
    if ($ctx['port_left'] <= 0) return [0, wa_room_why($ctx['port'] ?? [])];
    $el = array_filter($ctx['nums'], fn($n) => $n['left'] > 0); if (!$el) return [0, 'no number has room right now'];
    $ign = []; $q = $pdo->prepare("SELECT number_id, COUNT(*) AS n FROM wa_messages WHERE phone = ? AND dir = 'out' AND mtype = 'template' AND status <> 'failed' AND ts > ? GROUP BY number_id");   // a message that never arrived cannot have been "ignored"
    $q->execute([$c['phone'], $c['last_in_at'] ?: '1970-01-01 00:00:00']); foreach ($q->fetchAll() as $r) $ign[(int)$r['number_id']] = (int)$r['n'];
    if (!empty($ctx['w'])) {   // the owner's share per number: the number furthest below its share sends next
        $cand = array_filter($el, fn($n) => isset($ctx['w'][$n['id']]) && ($ign[$n['id']] ?? 0) < $ctx['switch']); if (!$cand) $cand = array_filter($el, fn($n) => isset($ctx['w'][$n['id']]));
        if ($cand) { $tw = array_sum(array_intersect_key($ctx['w'], $cand)); $tot = array_sum(array_intersect_key($ctx['wsent'] ?? [], $cand)) + 1; $best = 0; $bd = -INF;
            foreach ($cand as $n) { $d = $ctx['w'][$n['id']] / max(0.0001, $tw) * $tot - ($ctx['wsent'][$n['id']] ?? 0); if ($d > $bd) { $bd = $d; $best = (int)$n['id']; } }
            return [$best, '']; }
    }
    $last = (int)$c['last_number_id'];
    $rt = fn(array $n) => $n['used'] / max(1, $n['cap']); $minR = min(array_map($rt, $el)); $tol = (float)($ctx['sticky'] ?? 0.05);   // "stay on the number they wrote to" is soft: that number may be at most 5% of its day ahead of the freest one, so no number sits idle while another fills up
    if ($last && isset($el[$last]) && $c['last_in_at'] && strtotime((string)$c['last_in_at']) > time() - 30 * 86400 && ($ign[$last] ?? 0) < $ctx['switch']) return [$last, ''];   // they wrote to this one: ALWAYS stay on it while it has room today (owner's rule) — the other number only after they ignored it, or when it is full / Low quality
    $cand = array_filter($el, fn($n) => ($ign[$n['id']] ?? 0) < $ctx['switch']); if (!$cand) return [0, 'ignored every number'];
    uasort($cand, fn($a, $b) => ($a['used'] / max(1, $a['cap'])) <=> ($b['used'] / max(1, $b['cap'])));
    return [(int)reset($cand)['id'], ''];
}
function wa_first_name(string $name): string
{
    $w = preg_split('/\s+/u', trim($name))[0] ?? ''; $w = trim(preg_replace('/[^\p{L}\p{N}\'.\-]/u', '', $w) ?? ''); if (mb_strlen($w) < 2) return 'ji';
    return mb_convert_case(mb_substr($w, 0, 30), MB_CASE_TITLE, 'UTF-8');
}
function wa_tpl_vars_for(array $t, string $name, array $consts): array
{
    $v = [wa_first_name($name)]; foreach (array_values($consts) as $x) $v[] = mb_substr(trim(str_replace(["\n", "\t"], ' ', (string)$x)), 0, 300); return array_slice($v, 0, max(1, $t['nvars']));
}
function wa_tpl_request(array $t, array $num, string $to, array $vars): array
{
    $comp = []; if ($t['nvars']) $comp[] = ['type' => 'body', 'parameters' => array_map(fn($x) => ['type' => 'text', 'text' => $x !== '' ? $x : 'ji'], array_slice($vars, 0, $t['nvars']))];
    if (wa_tpl_vars((string)$t['header_text'])) array_unshift($comp, ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => $vars[0] ?? 'ji']]]);
    $p = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => $t['name'], 'language' => ['code' => $t['language']]]]; if ($comp) $p['template']['components'] = $comp;
    return ['path' => '/' . $num['phone_id'] . '/messages', 'json' => $p];
}
function wa_tpl_shown(array $t, array $vars): string
{
    return trim(($t['header_text'] !== '' ? wa_tpl_render((string)$t['header_text'], [$vars[0] ?? '']) . "\n" : '') . wa_tpl_render((string)$t['body'], $vars) . ($t['footer'] !== '' ? "\n" . $t['footer'] : ''));
}
/** The Meta request for one customer of a campaign. Uses the template module's wa_tpl_send_components() when it exists (photo / video / PDF / location on top, buttons with a value, offer timer);
 *  otherwise — or for a plain text message if that function refuses — the classic wa_tpl_request(). Returns [ok, request|errorText]. */
function wa_camp_request(PDO $pdo, array $t, array $num, string $to, array $vars, array $extra): array
{
    $plain = in_array(strtoupper((string)$t['header_format']), ['', 'TEXT'], true) && empty($t['buttons']);
    if (function_exists('wa_tpl_send_components')) {
        try { $r = wa_tpl_send_components($pdo, $t, $vars, (string)$num['phone_id'], $extra); } catch (Throwable $e) { $r = ['ok' => false, 'error' => $e->getMessage()]; }
        // ok with components = null is VALID: a message with no photo, no variables and a plain quick-reply button needs no extra parts at all.
        // (Before, null was treated as a failure with an empty reason — every person of such a campaign was marked "failed, no reason" without anything reaching Meta.)
        if (is_array($r) && !empty($r['ok'])) {
            $p = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => $t['name'], 'language' => ['code' => $t['language']]]]; if (!empty($r['components']) && is_array($r['components'])) $p['template']['components'] = $r['components'];
            return [true, ['path' => '/' . $num['phone_id'] . '/messages', 'json' => $p]];
        }
        if (!$plain) { $e = trim((string)($r['error'] ?? '')); return [false, 'HRMS could not build the message (not sent to Meta): ' . ($e !== '' ? $e : 'unknown reason — report it')]; }
    }
    return [true, wa_tpl_request($t, $num, $to, $vars)];
}
function wa_diag_row_tpl(PDO $pdo, int $id): ?array { try { $q = $pdo->prepare("SELECT * FROM wa_templates WHERE id = ?"); $q->execute([$id]); $r = $q->fetch(); return $r ?: null; } catch (Throwable $e) { return null; } }
/** Makes sure a customer row exists (a campaign can reach people who never wrote to us). */
function wa_ensure_contact(PDO $pdo, string $key, string $name): array
{
    $c = wa_contact($pdo, $key); if ($c) return $c;
    $pdo->prepare("INSERT INTO wa_contacts (phone, wa_id, name, first_seen, state, status, source) VALUES (?, ?, ?, ?, 'unassigned', 'open', '')")->execute([$key, '91' . $key, mb_substr($name, 0, 120), date('Y-m-d H:i:s')]);
    return wa_contact($pdo, $key);
}
function wa_log_template_msg(PDO $pdo, string $wamid, string $key, int $numId, string $shown, string $who): void
{
    try { $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, status, who, emp_id, ts) VALUES (?, ?, ?, 'out', 'template', ?, 'sent', ?, 0, ?)")->execute([$wamid, $key, $numId, $shown, $who, date('Y-m-d H:i:s')]); } catch (PDOException $e) { }
}
/** One template to one customer (used by follow-ups). Returns [ok, wamid|error, errorCode]. */
function wa_send_tpl_one(PDO $pdo, array $c, array $t, array $num, array $vars, string $who): array
{
    if (in_array($who, ['flow', 'followup'], true) && function_exists('wa_auto_muted') && wa_auto_muted($pdo, (string)$c['phone'])) return [false, 'Order completed — automatic messages are off until tomorrow night.', 0];
    $to = (string)($c['wa_id'] ?: '91' . $c['phone']);
    if (!in_array(strtoupper((string)($t['header_format'] ?? '')), ['', 'TEXT'], true)) {   // a photo / video / PDF on top needs its own parts
        [$okR, $rq] = wa_camp_request($pdo, $t, $num, $to, $vars, []); if (!$okR) return [false, (string)$rq, 0]; $req = $rq;
    } else $req = wa_tpl_request($t, $num, $to, $vars);
    [$code, $d] = wa_api('POST', $req['path'], $req['json']); $wamid = (string)($d['messages'][0]['id'] ?? '');
    if ($code < 200 || $code >= 300 || $wamid === '') return [false, wa_err($d), (int)($d['error']['code'] ?? 0)];
    $hfm = strtoupper((string)($t['header_format'] ?? '')); $mk = ['IMAGE' => '🖼 Photo on top', 'VIDEO' => '🎥 Video on top', 'DOCUMENT' => '📄 PDF on top'][$hfm] ?? '';   // the chat shows text only: say that the photo / video / PDF went with it
    wa_log_template_msg($pdo, $wamid, (string)$c['phone'], (int)$num['id'], ($mk !== '' ? '[' . $mk . ']' . "\n" : '') . wa_tpl_shown($t, $vars), $who); return [true, $wamid, 0];
}

// ---------------------------------------------------------------------------------------------------------------- follow-ups
/** Customers whose last message is older than a step's time, who got an answer from us, did not write again and did not order since: one template each. */
function wa_fu_scan(PDO $pdo): array
{
    if (function_exists('fu_mode') && fu_mode($pdo) !== 'off') return ['sent' => 0, 'skipped' => 0, 'note' => 'the new follow-up plans are on'];   // one follow-up engine at a time
    $rules = $pdo->query("SELECT * FROM wa_fu_rules WHERE active = 1 AND tpl_id > 0 ORDER BY after_min DESC")->fetchAll(); if (!$rules) return ['sent' => 0, 'skipped' => 0];
    $use = []; foreach ($rules as $r) { $t = wa_camp_tpl($pdo, (int)$r['tpl_id']); if ($t && $t['nvars'] <= 1) $use[] = ['r' => $r, 't' => $t]; } if (!$use) return ['sent' => 0, 'skipped' => 0];
    $minMin = min(array_map(fn($u) => (int)$u['r']['after_min'], $use)); $now = time(); $sent = 0; $skipped = 0; $om = wa_order_map($pdo);
    $st = $pdo->prepare("SELECT * FROM wa_contacts WHERE status = 'open' AND optout = 0 AND last_in_at IS NOT NULL AND last_out_at IS NOT NULL AND last_out_at >= last_in_at AND last_in_at <= ? AND last_in_at >= ? ORDER BY last_in_at DESC LIMIT 500");
    $st->execute([date('Y-m-d H:i:s', $now - $minMin * 60), date('Y-m-d H:i:s', $now - 130 * 86400)]);
    $done = $pdo->prepare("SELECT rule_id FROM wa_fu_log WHERE phone = ? AND in_at = ?"); $log = $pdo->prepare("INSERT INTO wa_fu_log (phone, rule_id, in_at, sent_at, status) VALUES (?, ?, ?, ?, ?)");
    $cfg = wa_team_cfg($pdo); $ctxs = [];
    foreach ($st->fetchAll() as $c) {
        if ($sent >= 40) break;
        $sil = ($now - strtotime((string)$c['last_in_at'])) / 60; $dn = []; $done->execute([$c['phone'], $c['last_in_at']]); foreach ($done->fetchAll() as $x) $dn[(int)$x['rule_id']] = true;
        $due = array_values(array_filter($use, fn($u) => $sil >= (int)$u['r']['after_min'] && empty($dn[(int)$u['r']['id']]) && ($u['r']['tag'] === '' || $u['r']['tag'] === $c['tag'])));
        if (!$due) continue;
        $o = $om[(string)$c['phone']] ?? null;
        if ($o && $o['last'] && strtotime($o['last']) > strtotime((string)$c['last_in_at'])) { foreach ($due as $u) $log->execute([$c['phone'], $u['r']['id'], $c['last_in_at'], date('Y-m-d H:i:s'), 'skipped']); $skipped++; continue; }   // already ordered
        $pick = $due[0]; foreach (array_slice($due, 1) as $u) $log->execute([$c['phone'], $u['r']['id'], $c['last_in_at'], date('Y-m-d H:i:s'), 'skipped']);   // a later step is due: the earlier ones are not sent late
        $tid = (int)$pick['t']['id']; if (!isset($ctxs[$tid])) $ctxs[$tid] = wa_camp_ctx($pdo, $pick['t'], 2);
        [$nid, $why] = wa_pick_number($pdo, $c, $ctxs[$tid]);
        if ($nid === 0) { if ($why === 'ignored every number') { $log->execute([$c['phone'], $pick['r']['id'], $c['last_in_at'], date('Y-m-d H:i:s'), 'skipped']); $skipped++; } continue; }   // no room now → try again at the next scan
        $num = $ctxs[$tid]['nums'][$nid]; $vars = wa_tpl_vars_for($pick['t'], (string)$c['name'], []);
        [$ok, $res, $ec] = wa_send_tpl_one($pdo, $c, $pick['t'], $num, $vars, 'followup');
        if ($ok) { $ctxs[$tid]['nums'][$nid]['left']--; $ctxs[$tid]['nums'][$nid]['used']++; $ctxs[$tid]['port_left']--; $log->execute([$c['phone'], $pick['r']['id'], $c['last_in_at'], date('Y-m-d H:i:s'), 'sent']); $sent++; }
        else { $log->execute([$c['phone'], $pick['r']['id'], $c['last_in_at'], date('Y-m-d H:i:s'), 'failed']); wa_log($pdo, 'followup', false, $c['phone'] . ' · ' . $res); if (in_array($ec, [131042, 131048, 132000, 132001, 132005, 132007, 132012, 132015, 132016], true)) break; }
    }
    return ['sent' => $sent, 'skipped' => $skipped];
}

// ---------------------------------------------------------------------------------------------------------------- campaigns: time rules
/** "Now" for the campaign engine (the real clock; a test may define WA_CAMP_NOW). */
function wa_camp_now(): int { return defined('WA_CAMP_NOW') ? (int)WA_CAMP_NOW : time(); }
/** The hours in which campaigns may send: [first hour, hour when it stops] — default 0 → 24: any hour, the owner sends whenever they want. Setting wa_camp_window = "8-21" would bring quiet hours back. */
function wa_camp_window(PDO $pdo): array
{
    $o = 0; $c = 24;
    try { if (preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', trim((string)setting_get($pdo, 'wa_camp_window', '')), $m) && (int)$m[1] < (int)$m[2] && (int)$m[2] <= 24) { $o = (int)$m[1]; $c = (int)$m[2]; } } catch (Throwable $e) { }
    return [$o, $c];
}
function wa_camp_in_window(PDO $pdo, int $ts): bool { [$o, $c] = wa_camp_window($pdo); $h = (int)date('G', $ts); return $h >= $o && $h < $c; }
/** The next moment inside the sending window (the same time when it is already inside). */
function wa_camp_clamp(PDO $pdo, int $ts): int
{
    [$o, $c] = wa_camp_window($pdo); $h = (int)date('G', $ts); if ($h >= $o && $h < $c) return $ts;
    return $h < $o ? mktime($o, 0, 0, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts)) : mktime($o, 0, 0, (int)date('n', $ts), (int)date('j', $ts) + 1, (int)date('Y', $ts));
}
/** The next time $hour o'clock comes at or after $base — a few minutes past the hour (spread by phone number so thousands of people do not all go out in one second), inside the window. */
function wa_camp_send_at(PDO $pdo, int $base, int $hour, string $phone): int
{
    [$o, $c] = wa_camp_window($pdo); $hour = max($o, min($c - 1, $hour)); $min = abs((int)crc32($phone)) % 50;
    $t = mktime($hour, $min, 0, (int)date('n', $base), (int)date('j', $base), (int)date('Y', $base));
    return $t < $base ? mktime($hour, $min, 0, (int)date('n', $base), (int)date('j', $base) + 1, (int)date('Y', $base)) : $t;
}
/** The usual hour at which each customer writes to us: phone → 0..23. Only customers with 5 or more messages in the last 180 days. */
function wa_camp_user_hours(PDO $pdo, array $phones): array
{
    $per = []; $from = date('Y-m-d H:i:s', wa_camp_now() - 180 * 86400);
    foreach (array_chunk(array_values(array_unique($phones)), 400) as $ch) {
        $q = $pdo->prepare("SELECT phone, HOUR(ts) AS h, COUNT(*) AS n FROM wa_messages WHERE dir = 'in' AND ts >= ? AND phone IN (" . wa_in($ch) . ") GROUP BY phone, HOUR(ts)"); $q->execute(array_merge([$from], $ch));
        foreach ($q->fetchAll() as $r) $per[(string)$r['phone']][(int)$r['h']] = (int)$r['n'];
    }
    $out = []; foreach ($per as $ph => $hs) { if (array_sum($hs) < 5) continue; arsort($hs); $max = max($hs); $best = array_keys(array_filter($hs, fn($n) => $n === $max)); sort($best); $out[$ph] = (int)$best[0]; }
    return $out;
}
/** How pushy a campaign is: days before the same customer may get another campaign message, and the speed multiplier. */
function wa_camp_push(string $p): array
{
    return ['careful' => ['freq' => 5, 'mult' => 1], 'normal' => ['freq' => 3, 'mult' => 1], 'aggressive' => ['freq' => 2, 'mult' => 2], 'very' => ['freq' => 1, 'mult' => 3]][$p] ?? ['freq' => 3, 'mult' => 1];
}

// ---------------------------------------------------------------------------------------------------------------- campaigns: what goes into the variables
function wa_camp_srcs(): array { return ['name', 'asked_perfume', 'last_ordered', 'city', 'days_since', 'fixed', 'piece', 'coupon']; }
function wa_camp_spec($x): array
{
    $x = is_array($x) ? $x : ['src' => 'fixed', 'val' => (string)$x]; $src = in_array((string)($x['src'] ?? ''), wa_camp_srcs(), true) ? (string)$x['src'] : 'fixed';
    $val = (string)($x['val'] ?? ''); $val = $src === 'coupon' ? trim(mb_substr(str_replace("\r", '', $val), 0, 1500)) : wa_camp_clean_var($val, 300);
    $slot = (string)($x['slot'] ?? ''); if ($src === 'piece' && !isset(wa_piece_slots()[$slot])) $slot = ''; if ($src !== 'piece') $slot = '';
    return ['src' => $src, 'val' => $val, 'slot' => $slot];
}
/** The variable settings of a campaign as exactly one setting per variable {{1}} … {{n}}. New format: a list of {src, val, slot}. Old campaigns stored only plain texts for {{2}}, {{3}} … ({{1}} was always the first name). */
function wa_camp_vars_norm($vars, int $n): array
{
    $vars = is_array($vars) ? array_values($vars) : []; $new = false; foreach ($vars as $x) if (is_array($x)) $new = true; $out = [];
    if (!$new) { $out[] = ['src' => 'name', 'val' => '', 'slot' => '']; foreach ($vars as $x) $out[] = wa_camp_spec((string)$x); }
    else foreach ($vars as $x) $out[] = wa_camp_spec($x);
    while (count($out) < $n) $out[] = ['src' => count($out) === 0 ? 'name' : 'fixed', 'val' => '', 'slot' => ''];
    return array_slice($out, 0, max(0, $n));
}
/** '' when every variable is filled properly, else a plain sentence for the owner. */
function wa_camp_vars_check(PDO $pdo, array $specs): string
{
    foreach ($specs as $i => $s) {
        $k = '{{' . ($i + 1) . '}}';
        if ($s['src'] === 'fixed' && $s['val'] === '') return 'Fill the text for ' . $k . '.';
        if ($s['src'] === 'coupon' && $s['val'] === '') return 'Type the coupon code for ' . $k . '.';
        if ($s['src'] === 'piece') {
            if ($s['slot'] === '') return 'Choose which kind of line ' . $k . ' takes from your message pieces.';
            if (empty(wa_piece_all($pdo)[$s['slot']])) return 'You chose a line from “' . wa_piece_slots()[$s['slot']] . '” for ' . $k . ', but no line of that kind is switched on. Open “Message pieces”, add lines and switch them on.';
        }
    }
    return '';
}
/** What the customer is, for choosing a fitting piece: buyer · new · lapsed (a customer can be two). */
function wa_camp_classes(PDO $pdo, array $ct): array
{
    $c = []; $now = wa_camp_now(); $om = null; try { $om = wa_order_map($pdo)[(string)$ct['phone']] ?? null; } catch (Throwable $e) { }
    $buyer = $om && (int)$om['n'] > 0; if ($buyer) $c[] = 'buyer'; $in = !empty($ct['last_in_at']) ? (int)strtotime((string)$ct['last_in_at']) : 0;
    if (!$buyer && ($in === 0 || empty($ct['first_seen']) || strtotime((string)$ct['first_seen']) > $now - 14 * 86400)) $c[] = 'new';
    if ($in === 0 || $in < $now - 30 * 86400) $c[] = 'lapsed';
    return $c;
}
/** The variables for ONE customer. $used collects which pieces were picked (for the use counter). Returns a list of n clean texts (never empty, no new lines, ≤ 300 letters). */
function wa_camp_resolve_vars(PDO $pdo, int $n, array $specs, array $ct, string $itemName, int $itemId, array &$used): array
{
    $out = []; $classes = null; $phone = (string)$ct['phone'];
    for ($i = 0; $i < $n; $i++) {
        $s = $specs[$i] ?? ['src' => $i === 0 ? 'name' : 'fixed', 'val' => '', 'slot' => '']; $fb = (string)$s['val']; $v = '';
        switch ($s['src']) {
            case 'name': $v = wa_first_name($itemName !== '' ? $itemName : (string)$ct['name']); break;
            case 'asked_perfume': try { $v = wa_camp_lazy('wa_prod_last_asked', 'wa_prod.php') ? (string)wa_prod_last_asked($pdo, $phone) : ''; } catch (Throwable $e) { $v = ''; } $v = wa_camp_clean_var($v) !== '' ? $v : ($fb !== '' ? $fb : 'Best Sellers'); break;
            case 'last_ordered': try { $v = wa_camp_lazy('wa_prod_last_ordered', 'wa_prod.php') ? (string)wa_prod_last_ordered($pdo, $phone) : ''; } catch (Throwable $e) { $v = ''; } $v = wa_camp_clean_var($v) !== '' ? $v : ($fb !== '' ? $fb : 'your favourite perfume'); break;
            case 'city': $v = isset($ct['city']) ? (string)$ct['city'] : ''; $v = wa_camp_clean_var($v) !== '' ? $v : ($fb !== '' ? $fb : 'your city'); break;
            case 'days_since': $in = !empty($ct['last_in_at']) ? (int)strtotime((string)$ct['last_in_at']) : 0; $v = $in > 0 ? (string)max(1, (int)floor((wa_camp_now() - $in) / 86400)) : ($fb !== '' ? $fb : 'a few'); break;
            case 'coupon': $list = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $fb) ?: []), fn($x) => $x !== '')); $v = $list ? $list[$itemId > 0 ? $itemId % count($list) : random_int(0, count($list) - 1)] : ''; break;
            case 'piece': if ($classes === null) $classes = wa_camp_classes($pdo, $ct); $p = wa_piece_pick($pdo, (string)$s['slot'], $classes); if ($p) { $v = $p['text']; $used[$p['id']] = ($used[$p['id']] ?? 0) + 1; } break;
            default: $v = $fb;
        }
        $v = wa_camp_clean_var($v, 300); $out[] = $v !== '' ? $v : ($s['src'] === 'name' ? 'ji' : '-');
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------------------------- campaigns
function wa_camp_row(PDO $pdo, int $id): ?array
{
    $q = $pdo->prepare("SELECT * FROM wa_campaigns WHERE id = ?"); $q->execute([$id]); $r = $q->fetch(); if (!$r) return null;
    $r['audience'] = json_decode((string)$r['audience'], true) ?: []; $r['vars'] = json_decode((string)$r['vars'], true) ?: []; $r['extra'] = json_decode((string)($r['extra'] ?? ''), true) ?: []; return $r;
}
function wa_paste_phones(string $text): array
{
    $o = []; foreach (preg_split('/\r?\n/', $text) as $line) { if (preg_match_all('/\+?\d[\d\s\-()]{8,}\d/', $line, $m)) foreach ($m[0] as $ph) { $k = function_exists('wa_imp_phone') ? wa_imp_phone($ph) : wa_key($ph); if ($k !== '' && wa_valid_phone($k)) $o[$k] = true; } }
    return array_keys($o);
}
/** Some modules are loaded after this file (wa_more.php loads them in order): fetch one when its function is needed and not there yet. */
function wa_camp_lazy(string $fn, string $file): bool
{
    if (function_exists($fn)) return true; $p = __DIR__ . '/' . $file;
    if (is_file($p)) { try { require_once $p; } catch (Throwable $e) { } }
    return function_exists($fn);
}
/** The filters that wa_aud_apply (a typed list) does not know: several tags (any of), last wrote X–Y days ago, ordered since they wrote. */
function wa_camp_extra_filter(array $rows, array $f): array
{
    $gts = array_values(array_filter(array_map('strval', is_array($f['gtags'] ?? null) ? $f['gtags'] : []), fn($x) => $x !== '')); $wf = $f['wrote_from'] ?? ''; $wt = $f['wrote_to'] ?? ''; $os = (string)($f['ords'] ?? ''); $now = time(); $out = [];
    foreach ($rows as $r) {
        $in = !empty($r['last_in_at']) ? (int)strtotime((string)$r['last_in_at']) : 0;
        if ($gts && wa_camp_lazy('wag_row_tags', 'wa_growth.php') && !array_intersect($gts, wag_row_tags($r))) continue;
        if (is_numeric($wf) && !($in > 0 && $in <= $now - (int)$wf * 86400)) continue;
        if (is_numeric($wt) && !($in > 0 && $in > $now - ((int)$wt + 1) * 86400)) continue;
        if ($os === 'no' && !($in > 0 && (empty($r['last_order']) || strtotime((string)$r['last_order']) < strtotime(date('Y-m-d', $in))))) continue;
        if ($os === 'yes' && !($in > 0 && !empty($r['last_order']) && strtotime((string)$r['last_order']) >= strtotime(date('Y-m-d', $in)))) continue;
        $out[] = $r;
    }
    return $out;
}
/** The people a campaign would message: [rowsToSend, stats]. Opted-out customers and anyone messaged by a campaign in the last N days are left out. */
function wa_camp_audience(PDO $pdo, array $aud, int $freq): array
{
    $freq = max(0, (int)setting_get($pdo, 'wa_camp_gap_days', '0'));   // no "already got a campaign lately" rule: the owner sends to whoever they pick (setting wa_camp_gap_days > 0 would bring it back)
    $src = in_array($aud['src'] ?? '', ['contacts', 'all', 'paste'], true) ? $aud['src'] : 'contacts';
    $f = (array)($aud['f'] ?? []); if (!empty($f['gtag']) && empty($f['gtags'])) { $f['gtags'] = [(string)$f['gtag']]; unset($f['gtag']); }   // old campaigns had one tag
    $cfBad = false;
    if (is_array($aud['cf'] ?? null) && $aud['cf'] && !function_exists('wcs_clean') && is_file(__DIR__ . '/wa_cust.php')) { $action = ''; try { require_once __DIR__ . '/wa_cust.php'; } catch (Throwable $e) { } }   // needs $pdo in scope; $action '' = its router stays quiet
    if (is_array($aud['cf'] ?? null) && $aud['cf'] && !function_exists('wcs_clean')) $cfBad = true;   // never fall back to "everyone" when the filters cannot be read
    if (is_array($aud['cf'] ?? null) && function_exists('wcs_clean')) {   // wa_cust.php is loaded AFTER this file (wa_more.php) — loaded above when needed; before this fix the panel's date / tags were ignored = everybody   // the Customers-style filter panel of the "Who" step (date range, tags in / out, ranges) — on top of a Claude group or an older campaign's filters
        $cf = wcs_clean($aud['cf']); if (!empty($cf['gtags']) && !empty($f['gtags'])) { $cf['gtags_b'] = $cf['gtags']; unset($cf['gtags']); } $f = array_merge($f, $cf);
    }
    if (($f['st'] ?? '') === 'optout') unset($f['st']);   // "Opted out" is not a campaign audience (they are always left out below); an old saved choice means everyone
    if ($src === 'paste') { $ph = wa_paste_phones((string)($aud['paste'] ?? '')); $matchedIt = $ph ? wa_camp_extra_filter(wa_aud_apply(array_values(wa_aud_rows($pdo, 'paste', $ph)), $f), $f) : []; }   // an empty list is an empty audience (never "everybody")
    elseif ($cfBad) $matchedIt = [];
    else $matchedIt = wa_aud_each($pdo, $src, $f);   // one SQL query, streamed 2,000 at a time
    $st = ['matched' => 0, 'optout' => 0, 'recent' => 0, 'invalid' => 0, 'send' => 0];
    $recent = []; $q = $pdo->prepare("SELECT DISTINCT phone FROM wa_messages WHERE dir = 'out' AND mtype = 'template' AND who = 'campaign' AND status <> 'failed' AND ts >= ?"); $q->execute([date('Y-m-d H:i:s', wa_camp_now() - max(0, $freq) * 86400)]); foreach ($q->fetchAll() as $r) $recent[(string)$r['phone']] = true;
    $send = [];
    foreach ($matchedIt as $r) {
        $st['matched']++;
        if (!wa_valid_phone($r['phone'])) { $st['invalid']++; continue; } if ($r['optout']) { $st['optout']++; continue; } if ($freq > 0 && isset($recent[$r['phone']])) { $st['recent']++; continue; } $send[] = ['phone' => $r['phone'], 'name' => $r['name']];   // only what sending needs — keeps memory small for big audiences
    }
    $st['send'] = count($send); return [$send, $st];
}
function wa_camp_counts(PDO $pdo, int $id): array
{
    $o = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'sending' => 0, 'retry' => 0];
    $q = $pdo->prepare("SELECT status, COUNT(*) AS n FROM wa_camp_items WHERE camp_id = ? GROUP BY status"); $q->execute([$id]); foreach ($q->fetchAll() as $r) $o[(string)$r['status']] = (int)$r['n'];
    $o['total'] = array_sum($o);
    // 🛑 held by Meta (131049: the customer got too many marketing messages from businesses — Meta's decision, not charged): part of "skipped", shown on its own
    $o['held'] = 0; $o['held_r'] = 0; try { $q = $pdo->prepare("SELECT status, COUNT(*) AS n FROM wa_camp_items WHERE camp_id = ? AND status IN ('skipped', 'retry') AND err_code = 131049 GROUP BY status"); $q->execute([$id]); foreach ($q->fetchAll() as $r) { $o['held'] += (int)$r['n']; if ($r['status'] === 'retry') $o['held_r'] = (int)$r['n']; } } catch (Throwable $e) { }
    return $o;
}
function wa_camp_delivery(PDO $pdo, int $id): array
{
    $o = ['delivered' => 0, 'read' => 0]; $q = $pdo->prepare("SELECT m.status AS s, COUNT(*) AS n FROM wa_camp_items i JOIN wa_messages m ON m.wamid = i.wamid WHERE i.camp_id = ? AND i.wamid != '' GROUP BY m.status"); $q->execute([$id]);
    foreach ($q->fetchAll() as $r) { if ($r['s'] === 'delivered') $o['delivered'] += (int)$r['n']; if ($r['s'] === 'read') { $o['delivered'] += (int)$r['n']; $o['read'] += (int)$r['n']; } }
    // 💬 replied: customers who wrote back within 2 days of their campaign message (the same rule as the report card)
    $o['replied'] = 0; try { $q = $pdo->prepare("SELECT COUNT(DISTINCT i.phone) FROM wa_camp_items i JOIN wa_messages m ON m.phone = i.phone AND m.dir = 'in' AND m.ts > i.sent_at AND m.ts <= DATE_ADD(i.sent_at, INTERVAL 2 DAY) WHERE i.camp_id = ? AND i.status = 'sent'"); $q->execute([$id]); $o['replied'] = (int)$q->fetchColumn(); } catch (Throwable $e) { }
    return $o;
}
function wa_camp_public(PDO $pdo, array $c, bool $deep = false, ?array $cfg = null): array
{
    $cn = wa_camp_counts($pdo, (int)$c['id']); $d = wa_camp_delivery($pdo, (int)$c['id']); $t = null; $lib = null;
    if ((int)$c['tpl_id']) { try { $t = wa_tpl_row($pdo, (int)$c['tpl_id']); } catch (Throwable $e) { } }
    if ((int)($c['media_lib_id'] ?? 0) && function_exists('wa_lib_row')) { try { $lib = wa_lib_row($pdo, (int)$c['media_lib_id']); } catch (Throwable $e) { } }
    $o = ['id' => (int)$c['id'], 'name' => $c['name'], 'status' => $c['status'], 'tpl_id' => (int)$c['tpl_id'], 'tpl_name' => $t ? $t['name'] : '', 'tpl_body' => $t ? $t['body'] : '', 'tpl_category' => $t ? $t['category'] : '', 'counts' => $cn + $d, 'rate' => (int)$c['rate'],
        'win_end' => $c['win_end'], 'start_at' => $c['start_at'], 'created_at' => $c['created_at'], 'started_at' => $c['started_at'], 'finished_at' => $c['finished_at'], 'note' => $c['note'], 'freq_days' => (int)$c['freq_days'], 'switch_after' => (int)$c['switch_after'],
        'audience' => $c['audience'], 'vars' => $c['vars'], 'media_lib_id' => (int)($c['media_lib_id'] ?? 0), 'media_folder' => (string)($c['media_folder'] ?? ''), 'media_title' => $lib ? (string)$lib['title'] : '', 'media_kind' => $lib ? (string)$lib['kind'] : '',
        'push' => (string)($c['push'] ?? 'normal'), 'retry' => (int)($c['retry'] ?? 1) === 1, 'own_hour' => (int)($c['own_hour'] ?? 0) === 1, 'def_hour' => (int)($c['def_hour'] ?? 19), 'extra' => $c['extra'] ?? []];
    try { $o['mix'] = wa_mix_report($pdo, $c, $cfg ?? ['rate_marketing' => 0, 'rate_utility' => 0], false); } catch (Throwable $e) { $o['mix'] = ['on' => false, 'u_status' => 'none']; }   // light: the twin and its state; the numbers and variant counts come with wa.camp_get
    if ($deep) {
        $q = $pdo->prepare("SELECT err, COUNT(*) AS n FROM wa_camp_items WHERE camp_id = ? AND err != '' AND status IN ('failed', 'skipped', 'retry') GROUP BY err ORDER BY n DESC LIMIT 6"); $q->execute([(int)$c['id']]); $o['errors'] = $q->fetchAll();
        $q = $pdo->prepare("SELECT number_id, COUNT(*) AS n FROM wa_camp_items WHERE camp_id = ? AND status = 'sent' GROUP BY number_id"); $q->execute([(int)$c['id']]); $nm = [];
        foreach ($pdo->query("SELECT id, label, display FROM wa_numbers")->fetchAll() as $n) $nm[(int)$n['id']] = $n['label'] !== '' ? $n['label'] : $n['display'];
        $o['by_number'] = array_map(fn($r) => ['number' => $nm[(int)$r['number_id']] ?? ('Number ' . $r['number_id']), 'sent' => (int)$r['n']], $q->fetchAll());
    }
    return $o;
}
function wa_camp_pause(PDO $pdo, int $id, string $why): void { $pdo->prepare("UPDATE wa_campaigns SET status = 'paused', note = ? WHERE id = ?")->execute([mb_substr($why, 0, 200), $id]); wa_log($pdo, 'campaign', false, '#' . $id . ' paused: ' . $why); }

// ---------------------------------------------------------------------------------------------------------------- campaigns: failures and retries
/** What to do with a failed message: ['final'|'retry'|'skip'|'pause', why]. $attempt = how many tries were made so far (the one that just failed included). */
function wa_camp_fail_plan(int $ec, int $attempt, bool $retryOn, int $maxTries = 3): array
{
    if ($ec === 131049) return $attempt < 2 ? ['held', ''] : ['skip', 'Held by Meta again on the extra try — stopped'];   // Meta's marketing limit for this customer: ONE more try 1 day later (owner's rule, 7 Nov), then stop
    if ($ec === 131048) return ['pause', 'Meta flagged these messages as spam (code 131048) — pause and check the message and the audience.'];
    if (in_array($ec, [131050, 131047, 131021, 130472, 131051, 100, 131008, 131009], true)) return ['final', 'Meta will not deliver this one — not retried'];   // the customer stopped marketing / 24-hour rule / own number / test group / type not supported / the request itself is wrong (retrying cannot help)
    if (!$retryOn) return ['final', 'Retry is off for this campaign'];
    if ($ec === 131026 && $attempt >= 2) return ['final', 'Not on WhatsApp / cannot receive'];   // "undeliverable": a second try only, then stop
    if ($attempt >= max(1, min(3, $maxTries))) return ['final', 'Failed after ' . max(1, min(3, $maxTries)) . ' ' . (max(1, min(3, $maxTries)) === 1 ? 'try' : 'tries')];   // the owner may lower it (extra.retries)
    return ['retry', ''];
}
/** 🛑 Held by Meta (131049): the one extra try goes 1 day later at the customer's own hour (Meta's marketing limit for a person has reset by then). */
function wa_camp_held_try(PDO $pdo, string $phone, int $defHour, int $nowTs): int
{
    $h = wa_camp_user_hours($pdo, [$phone])[$phone] ?? ($defHour ?: 19);
    return wa_camp_send_at($pdo, mktime(0, 0, 0, (int)date('n', $nowTs), (int)date('j', $nowTs) + 1, (int)date('Y', $nowTs)), $h, $phone);   // owner (7 Nov): 1 day, was 3
}
/** Once: people skipped by the two rules the owner removed (Meta held them lately · business number) in campaigns of the last 3 days go back in the queue. */
function wa_camp_rules_backfill(PDO $pdo, int $nowTs): void
{
    if ((string)setting_get($pdo, 'wa_skip_rules_v2', '') === '1') return; setting_set($pdo, 'wa_skip_rules_v2', '1');
    try {
        $q = $pdo->prepare("SELECT i.id, i.camp_id FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE i.status = 'skipped' AND (i.err = 'Meta limited marketing to this customer in the last 3 days' OR i.err LIKE 'A business (answered an earlier campaign%') AND c.status IN ('running', 'paused', 'done') AND COALESCE(c.started_at, c.created_at) >= ?");
        $q->execute([date('Y-m-d H:i:s', $nowTs - 3 * 86400)]); $re = [];
        foreach ($q->fetchAll() as $r) { $pdo->prepare("UPDATE wa_camp_items SET status = 'queued', err = '', send_at = NULL WHERE id = ?")->execute([$r['id']]); $re[(int)$r['camp_id']] = 1; }
        foreach (array_keys($re) as $cid) $pdo->prepare("UPDATE wa_campaigns SET status = 'running', finished_at = NULL, note = '' WHERE id = ? AND status = 'done'")->execute([$cid]);
    } catch (Throwable $e) { }
}
/** Once: people held by Meta in the last 3 days (before the extra try existed) get their one extra try too. */
function wa_camp_held_backfill(PDO $pdo, int $nowTs): void
{
    if ((string)setting_get($pdo, 'wa_held_retry_v1', '') === '1') return; setting_set($pdo, 'wa_held_retry_v1', '1');
    try {
        $q = $pdo->prepare("SELECT i.id, i.camp_id, i.phone, i.sent_at, c.def_hour FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE i.status = 'skipped' AND i.err_code = 131049 AND i.tries <= 1 AND i.sent_at >= ? AND c.status IN ('running', 'paused', 'done')");
        $q->execute([date('Y-m-d H:i:s', $nowTs - 3 * 86400)]); $re = [];
        foreach ($q->fetchAll() as $r) { $nt = wa_camp_held_try($pdo, (string)$r['phone'], (int)$r['def_hour'], (int)strtotime((string)$r['sent_at'])); $pdo->prepare("UPDATE wa_camp_items SET status = 'retry', next_try = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', max($nt, $nowTs)), $r['id']]); $re[(int)$r['camp_id']] = 1; }
        foreach (array_keys($re) as $cid) $pdo->prepare("UPDATE wa_campaigns SET status = 'running', finished_at = NULL, note = '' WHERE id = ? AND status = 'done'")->execute([$cid]);
    } catch (Throwable $e) { }
}
/** When the next try happens (a unix time): after the 1st try 3 hours later, after the 2nd the next day at the customer's own hour — always inside the sending window. */
function wa_camp_next_try(PDO $pdo, array $c, string $phone, int $attempt, int $nowTs): int
{
    if ($attempt <= 1) { $t = $nowTs + 3 * 3600; $c2 = wa_camp_clamp($pdo, $t); return $c2 === $t ? $t : $c2 + (abs((int)crc32($phone)) % 40) * 60; }
    $h = wa_camp_user_hours($pdo, [$phone])[$phone] ?? (int)($c['def_hour'] ?: 19);
    return wa_camp_send_at($pdo, mktime(0, 0, 0, (int)date('n', $nowTs), (int)date('j', $nowTs) + 1, (int)date('Y', $nowTs)), $h, $phone);
}
/** Messages that Meta accepted but could not deliver (the status webhook marked them failed): decide for each — try again later, give up, skip or pause the campaign. Returns how many were handled. */
function wa_camp_retry_scan(PDO $pdo, int $nowTs): int
{
    $rows = $pdo->query("SELECT i.id, i.camp_id, i.phone, i.tries, i.err, i.err_code, c.retry, c.def_hour, c.extra AS cx, c.status AS cs FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE i.status = 'sent' AND i.last_status = 'failed' AND c.status IN ('running', 'paused', 'done', 'scheduled') ORDER BY i.id LIMIT 500")->fetchAll();
    $n = 0; $reopen = []; $pause = [];
    foreach ($rows as $r) {
        $n++; $ec = (int)$r['err_code']; $attempt = max(1, (int)$r['tries']); $opt = $pdo->prepare("SELECT optout FROM wa_contacts WHERE phone = ?"); $opt->execute([$r['phone']]);
        if ((int)$opt->fetchColumn() === 1) { $pdo->prepare("UPDATE wa_camp_items SET status = 'skipped', err = 'Opted out' WHERE id = ?")->execute([$r['id']]); continue; }
        $cxr = json_decode((string)($r['cx'] ?? ''), true); [$plan, $why] = wa_camp_fail_plan($ec, $attempt, (int)$r['retry'] === 1, (int)(is_array($cxr) ? ($cxr['retries'] ?? 3) : 3));
        if ($plan === 'held') { $nt = wa_camp_held_try($pdo, (string)$r['phone'], (int)$r['def_hour'], $nowTs); $pdo->prepare("UPDATE wa_camp_items SET status = 'retry', next_try = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', $nt), $r['id']]); $reopen[(int)$r['camp_id']] = true; }
        elseif ($plan === 'skip') $pdo->prepare("UPDATE wa_camp_items SET status = 'skipped', err = ? WHERE id = ?")->execute([$why, $r['id']]);
        elseif ($plan === 'pause') { $pdo->prepare("UPDATE wa_camp_items SET status = 'failed' WHERE id = ?")->execute([$r['id']]); $pause[(int)$r['camp_id']] = $why; }
        elseif ($plan === 'final') $pdo->prepare("UPDATE wa_camp_items SET status = 'failed', err = ? WHERE id = ?")->execute([mb_substr($ec === 131026 ? $why : ($r['err'] !== '' ? $r['err'] : $why), 0, 190), $r['id']]);
        else { $nt = wa_camp_next_try($pdo, ['def_hour' => $r['def_hour']], (string)$r['phone'], $attempt, $nowTs); $pdo->prepare("UPDATE wa_camp_items SET status = 'retry', next_try = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', $nt), $r['id']]); $reopen[(int)$r['camp_id']] = true; }
    }
    foreach ($pause as $cid => $why) { $st = $pdo->prepare("SELECT status FROM wa_campaigns WHERE id = ?"); $st->execute([$cid]); if (in_array((string)$st->fetchColumn(), ['running', 'scheduled'], true)) wa_camp_pause($pdo, $cid, $why); }
    foreach ($reopen as $cid => $_) $pdo->prepare("UPDATE wa_campaigns SET status = 'running', finished_at = NULL, note = '' WHERE id = ? AND status = 'done'")->execute([$cid]);   // late failures reopen a finished campaign for its retries
    return $n;
}

/** The sender. Called by wa.cron every minute (and by the Campaigns page while it is open).
 *  Marketing + utility mix: when the campaign has extra.mix, wa_mix_plan() decides per number how much of the Utility twin may go to customers who asked or ordered in the last 30 days (wa_mix_take); everyone else gets the marketing message.
 *  Every number that has room and the message approved on its WhatsApp account sends (wa_pick_number spreads by room). */
/** Why a campaign's due people are not going out right now, in plain words (and when it continues) — '' when nothing holds it. */
function wa_camp_wait_reason(PDO $pdo, array $c): string
{
    if ($c['status'] === 'paused') return 'Paused' . (trim((string)($c['note'] ?? '')) !== '' ? ' — ' . $c['note'] : '') . '. Press Resume to send.';
    if ($c['status'] === 'scheduled') return 'Scheduled — it starts at ' . date('j M, g:i a', (int)strtotime((string)$c['start_at'])) . '.';
    if (!in_array($c['status'], ['running'], true)) return '';
    $nowTs = wa_camp_now();
    if (!wa_camp_in_window($pdo, $nowTs)) return 'Quiet hours — nothing is sent at night. It continues at ' . wa_camp_window($pdo)[0] . ' am by itself.';
    try {
        $caps = wa_number_caps($pdo, wa_camp_health($pdo)); $port = $caps['_port']; $left = 0; $why = [];
        foreach ($caps as $k => $x) if ($k !== '_port') { $left += $x['left']; if ($x['left'] <= 0) $why[] = $x['why'] !== '' ? $x['why'] : 'used ' . $x['used'] . ' of ' . $x['cap'] . ' today'; }
        if (!empty($port['auto']['nums']) && max(array_column($port['auto']['nums'], 'left_hour')) <= 0 && ($port['tier'] * 0.9 - $port['used']) > 0) {
            return 'This hour’s speed is used on every number (' . implode(' · ', array_map(fn($n) => $n['label'] . ' ' . number_format($n['used_hour']) . ' of ' . number_format($n['cap']), $port['auto']['nums'])) . '). It continues by itself as the hour rolls on — and each number’s speed doubles after an hour that goes well, up to ' . number_format($port['auto']['max']) . '.';
        }
        if ($port['left'] <= 0 || $left <= 0) {
            $q = $pdo->query("SELECT MIN(ts) FROM wa_messages WHERE dir = 'out' AND mtype = 'template' AND ts >= '" . date('Y-m-d H:i:s', $nowTs - 86400) . "'"); $first = (string)$q->fetchColumn();
            $free = $first !== '' ? strtotime($first) + 86400 : 0;
            return 'Waiting for room: Meta lets your numbers message only a limited number of new people in 24 hours' . ($port['left'] <= 0 ? ' (' . number_format($port['used']) . ' of about ' . number_format((int)floor($port['tier'] * 0.9)) . ' used)' : '') . ($why ? ' — ' . implode('; ', array_unique($why)) : '') . '. It sends by itself as room frees' . ($free > $nowTs ? ', starting about ' . date('j M, g:i a', $free) : '') . '.';
        }
    } catch (Throwable $e) { }
    $n = trim((string)($c['note'] ?? '')); if ($n !== '') return $n;
    $lt = !empty($c['last_tick']) ? (int)strtotime((string)$c['last_tick']) : 0;
    if ($lt > 0 && $lt < $nowTs - 600) return 'The sending engine has not run since ' . date('j M, g:i a', $lt) . ' — keep the Campaigns page open, or check the cron job (Team & routing → sending engine).';
    return '';
}
/** 🛡 The owner's safety rule for the utility share of ONE campaign: '' = fine, else why it must go 100% marketing now.
 *  4%+ of the utility sends failed (at least 25 sends that are 20+ minutes old), Meta blocked 2+ of them (131049 / 131048), Meta moved a used version to marketing,
 *  or a number that sends them dropped to Medium / Low quality. */
function wa_camp_util_guard(PDO $pdo, array $c): string
{
    $id = (int)$c['id']; $old = date('Y-m-d H:i:s', wa_camp_now() - 1200);
    try {
        $q = $pdo->prepare("SELECT i.status, i.last_status, i.err_code, i.tries, i.number_id, t.relabelled_at, t.category_now, t.name FROM wa_camp_items i LEFT JOIN wa_templates t ON t.id = i.tpl_used WHERE i.camp_id = ? AND i.variant = 'U' AND i.status IN ('sent', 'failed', 'retry', 'skipped') AND i.sent_at <= ?"); $q->execute([$id, $old]); $rows = $q->fetchAll();
    } catch (Throwable $e) { return ''; }
    $n = 0; $fail = 0; $block = 0; $nums = [];
    foreach ($rows as $r) {
        if (!empty($r['relabelled_at']) || strtoupper((string)$r['category_now']) === 'MARKETING') return 'Meta moved the utility message "' . str_replace('_', ' ', (string)$r['name']) . '" to marketing';
        if (in_array((int)$r['err_code'], [131049, 131048], true)) $block++;
        if ($r['status'] === 'skipped' && !in_array((int)$r['err_code'], [131049, 131048], true)) continue;
        $n++; if (in_array($r['status'], ['failed', 'retry', 'skipped'], true) || $r['last_status'] === 'failed') $fail++; $nums[(int)$r['number_id']] = true;
    }
    if ($block >= 2) return 'Meta blocked ' . $block . ' utility messages (spam limit)';
    if ($n >= 25 && $fail * 100 / $n >= 4) return round($fail * 100 / $n, 1) . '% of the utility messages failed (' . $fail . ' of ' . $n . ') — the limit is 4%';
    if ($nums) { foreach (wa_camp_health($pdo) as $h) if (isset($nums[(int)$h['id']]) && in_array(strtoupper((string)($h['quality'] ?? '')), ['YELLOW', 'RED'], true)) return 'the number ' . ((string)($h['label'] ?? '') ?: '#' . $h['id']) . ' dropped to ' . (strtoupper((string)$h['quality']) === 'RED' ? 'Low' : 'Medium') . ' quality'; }
    return '';
}
function wa_camp_tick(PDO $pdo): array
{
    if (!wa_lock($pdo, 'camp', 45)) return ['skipped' => true];
    $t0 = microtime(true); $nowTs = wa_camp_now(); $now = date('Y-m-d H:i:s', $nowTs); $res = [];
    try { wa_more_tables($pdo); } catch (Throwable $e) { }
    try { wa_health_all($pdo, false); } catch (Throwable $e) { }   // keeps the saved number health fresh in the background, so the Campaign panel never waits for Meta
    $pdo->prepare("UPDATE wa_camp_items SET status = 'queued' WHERE status = 'sending' AND sent_at < ?")->execute([date('Y-m-d H:i:s', $nowTs - 600)]);   // a run that died while sending
    $sp = ''; if (function_exists('wsm_tick') || wa_camp_lazy('wsm_tick', 'wa_smart.php')) { try { $res['smart'] = wsm_tick($pdo, $nowTs); if (function_exists('wsm_has_col') && wsm_has_col($pdo)) $sp = ' AND smart_plan = 0'; } catch (Throwable $e) { } }   // Smart plans: their audience is picked on the start day (wa_smart.php), so the generic start below skips them
    $pdo->prepare("UPDATE wa_campaigns SET status = 'running', started_at = COALESCE(started_at, ?) WHERE status = 'scheduled' AND start_at IS NOT NULL AND start_at <= ?" . $sp)->execute([$now, $now]);
    try { wa_camp_held_backfill($pdo, $nowTs); } catch (Throwable $e) { }
    try { wa_camp_rules_backfill($pdo, $nowTs); } catch (Throwable $e) { }
    try { wa_camp_retry_scan($pdo, $nowTs); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'campaign retries: ' . $e->getMessage()); }
    $inWin = wa_camp_in_window($pdo, $nowTs); $due = "((status = 'queued' AND (send_at IS NULL OR send_at <= '$now')) OR (status = 'retry' AND next_try <= '$now'))";
    // a campaign is FINISHED as soon as everyone has had their first try (owner, 7 Nov: not "Sending" for 3 days while Meta-held people wait for their one extra try).
    // The waiting retries stay in the queue: the moment one is due, the campaign is reopened for it and finishes again.
    try { $pdo->exec("UPDATE wa_campaigns SET status = 'running', finished_at = NULL WHERE status = 'done' AND id IN (SELECT camp_id FROM wa_camp_items WHERE status = 'retry' AND next_try <= '$now')"); } catch (Throwable $e) { }
    $runRows = $pdo->query("SELECT id FROM wa_campaigns WHERE status = 'running' ORDER BY COALESCE(last_tick, '1970-01-01 00:00:00'), id")->fetchAll();   // the campaign that waited longest goes first: an older big campaign can never starve a new one
    $slice = max(8.0, 42.0 / max(1, count($runRows)));   // every running campaign gets its own share of this round's time (before: the first one could use all of it)
    foreach ($runRows as $row) {
        $c = wa_camp_row($pdo, (int)$row['id']); if (!$c) continue; $id = (int)$c['id']; $cDead = microtime(true) + $slice;
        $rem = (int)$pdo->query("SELECT COUNT(*) FROM wa_camp_items WHERE camp_id = $id AND (status = 'queued' OR (status = 'retry' AND next_try <= '$now'))")->fetchColumn();
        if ($rem === 0) { if ((int)$pdo->query("SELECT COUNT(*) FROM wa_camp_items WHERE camp_id = $id AND status = 'sending'")->fetchColumn() === 0) $pdo->prepare("UPDATE wa_campaigns SET status = 'done', finished_at = ?, note = '' WHERE id = ?")->execute([$now, $id]); continue; }
        $t = wa_camp_tpl($pdo, (int)$c['tpl_id'], true, (int)$c['media_lib_id']);
        if (!$t) { foreach (wa_fam_members($pdo, wa_fam_root(wa_mix_trow($pdo, (int)$c['tpl_id']))) as $fm) if ($fm['line'] === 'M' && (int)$fm['id'] !== (int)$c['tpl_id'] && !$fm['relabelled_at'] && ($t = wa_camp_tpl($pdo, (int)$fm['id'], true, (int)$c['media_lib_id']))) break; }   // the first version is not available anywhere: an alternative version of the same message takes over
        if (!$t && !empty($c['extra']['smart_plan_id'])) { $pdo->prepare("UPDATE wa_campaigns SET note = ?, last_tick = ? WHERE id = ?")->execute(['Waiting for Meta approval — it starts sending by itself when the message is approved.', $now, $id]); $res[$id] = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'stop' => 'waiting for Meta approval']; continue; }   // a Smart plan waits, it is not paused
        if (!$t) { wa_camp_pause($pdo, $id, 'The pre-made message is not approved (or was deleted, or its photo / video is missing).'); continue; }
        $specs = wa_camp_vars_norm($c['vars'], (int)$t['nvars']); $bad = wa_camp_vars_check($pdo, $specs); if ($bad !== '') { wa_camp_pause($pdo, $id, $bad); continue; }
        if (!$inWin) { $pdo->prepare("UPDATE wa_campaigns SET note = ? WHERE id = ?")->execute(['Quiet hours — sending continues at ' . wa_camp_window($pdo)[0] . ' am.', $id]); $res[$id] = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'stop' => 'quiet hours']; continue; }
        $dueN = (int)$pdo->query("SELECT COUNT(*) FROM wa_camp_items WHERE camp_id = $id AND $due")->fetchColumn();
        if ($dueN === 0) { $pdo->prepare("UPDATE wa_campaigns SET last_tick = ?, note = '' WHERE id = ?")->execute([$now, $id]); $res[$id] = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'stop' => '']; continue; }
        $push = wa_camp_push((string)$c['push']); $rate = (int)$c['rate'] * $push['mult']; if ($c['win_end']) { $left = max(1, (strtotime((string)$c['win_end']) - $nowTs) / 60); $rate = (int)ceil($rem / $left); }
        if (!$c['win_end'] && (int)$c['own_hour'] !== 1 && !empty($c['started_at'])) { $tm = (int)setting_get($pdo, 'wa_camp_target_min', '6'); if ($tm > 0) { $leftMin = max(1.0, $tm - ($nowTs - strtotime((string)$c['started_at'])) / 60); $rate = max($rate, (int)ceil($rem / $leftMin)); } }   // "everyone together" campaigns aim to be finished about 6 minutes after the start (setting wa_camp_target_min; 0 = use the slow speed)
        $el = $c['last_tick'] ? min(5, max(0.5, ($nowTs - strtotime((string)$c['last_tick'])) / 60)) : 1; $quota = min(1500, max(1, (int)ceil($rate * $el)));
        // one panel for all numbers: the message (or an approved alternative version of it) must be approved on a number's WhatsApp account for that number to send
        $by = []; foreach ((array)$t['ok_wabas'] as $wb) $by[(string)$wb] = $t;
        foreach (wa_fam_m_alts($pdo, $t) as $alt) foreach ((array)$alt['ok_wabas'] as $wb) if (!isset($by[(string)$wb])) $by[(string)$wb] = $alt;
        $t['by_waba'] = $by; $t['ok_wabas'] = array_map('strval', array_keys($by));   // numeric ids become int keys: back to text
        $ctx = wa_camp_ctx($pdo, $t, (int)$c['switch_after'], array_map('intval', (array)($c['extra']['numbers'] ?? [])));
        $chr = wa_camp_hour_room($ctx['port'] ?? []); if ($chr !== null) { foreach ($ctx['nums'] as $nid0 => $n0) { $ctx['nums'][$nid0]['left'] = min((int)$n0['left'], $chr[(int)$nid0] ?? 0); if ($ctx['nums'][$nid0]['left'] <= 0) unset($ctx['nums'][$nid0]); } if (!$ctx['nums']) { $ctx['port']['hour_full'] = 1; $ctx['port_left'] = 0; } }   // 📈 each number's own hourly speed
        $sent = 0; $failed = 0; $skipped = 0; $stop = ''; $hard = false; $retryOn = (int)$c['retry'] === 1; $usedPieces = [];
        $mixOn = !empty($c['extra']['mix']) && function_exists('wa_util_mix_on') && wa_util_mix_on($pdo); $plan = null; $env = null;   // offers = marketing only (see wa_util_mix_on)
        if ($mixOn) { $env = wa_mix_env($pdo); $plan = wa_mix_plan($pdo, $c, $ctx['nums'], $rem, null, true, $env); }
        // the owner's own split for THIS campaign: a utility version + its share (only to people who asked or ordered in the last 30 days), and a share per number
        $uT = null; $uPct = max(0, min(100, (int)($c['extra']['u_pct'] ?? 0))); $uCnt = 0; $allCnt = 0; $wabaOf = [];
        $uTs = []; $isUtil = ($c['extra']['kind'] ?? '') === 'util'; $ordDays = max(0, min(365, (int)($c['extra']['ord_days'] ?? 60)));
        if ($isUtil) {   // 💸 utility follow-up campaign: only people who qualify get a message, the 6 standard follow-ups take turns
            $gw = wa_camp_util_guard($pdo, $c);
            if ($gw !== '') { wa_camp_pause($pdo, $id, 'Utility safety rule — ' . $gw); $res[$id] = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'stop' => 'utility safety rule']; continue; }
            $uTs = function_exists('wa_util_pool_usable') ? wa_util_pool_usable($pdo) : []; $uT = $uTs[0] ?? null;
            if (!$uT) { $pdo->prepare("UPDATE wa_campaigns SET note = ?, last_tick = ? WHERE id = ?")->execute(['Waiting for Meta to approve the utility follow-up messages — it starts by itself.', $now, $id]); $res[$id] = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'stop' => 'waiting for approval']; continue; }
            foreach ($pdo->query("SELECT id, waba_id FROM wa_numbers")->fetchAll() as $nr) $wabaOf[(int)$nr['id']] = wa_waba_of($nr);
        }
        elseif (!$mixOn && $uPct > 0 && ((int)($c['extra']['u_tpl'] ?? 0) > 0 || !empty($c['extra']['u_auto']))) {
            $gw = wa_camp_util_guard($pdo, $c);   // 🛡 the owner's safety rule: 4%+ utility failures, Meta blocks, a re-labelled version or a number's quality drop → this campaign goes 100% marketing
            if ($gw !== '') { $c['extra']['u_pct'] = 0; $c['extra']['u_off'] = ['at' => date('Y-m-d H:i:s', $nowTs), 'why' => $gw]; $pdo->prepare("UPDATE wa_campaigns SET extra = ?, note = ? WHERE id = ?")->execute([json_encode($c['extra'], JSON_UNESCAPED_UNICODE), 'Utility switched off automatically — ' . $gw . '. Sending marketing to everyone.', $id]); wa_log($pdo, 'campaign', false, '#' . $id . ' utility switched off: ' . $gw); $uPct = 0; }
            if ($uPct > 0 && (int)($c['extra']['u_tpl'] ?? 0) > 0) { $uRow = wa_diag_row_tpl($pdo, (int)$c['extra']['u_tpl']);
                if ($uRow && strtoupper((string)$uRow['category']) === 'UTILITY' && strtoupper((string)($uRow['category_now'] ?? '')) !== 'MARKETING' && empty($uRow['relabelled_at'])) { $u1 = wa_camp_tpl($pdo, (int)$uRow['id'], true, 0); if ($u1) { if (!is_array($u1['examples'] ?? null)) $u1['examples'] = json_decode((string)($uRow['examples'] ?? ''), true) ?: []; $uTs[(int)$u1['id']] = $u1; } } }
            if ($uPct > 0 && !empty($c['extra']['u_auto']) && function_exists('wa_util_usable')) foreach (wa_util_usable($pdo, (int)$c['tpl_id']) as $u1) $uTs[(int)$u1['id']] = $u1;
            $uTs = array_values($uTs); $uT = $uTs[0] ?? null;
            if ($uPct > 0 && !$uT) $pdo->prepare("UPDATE wa_campaigns SET note = ? WHERE id = ? AND status = 'running'")->execute(['No utility version is approved yet (or Meta moved them to marketing) — sending the marketing message to everyone until one is approved.', $id]);
            else { foreach ($pdo->query("SELECT variant, COUNT(*) AS n FROM wa_camp_items WHERE camp_id = $id AND status = 'sent' GROUP BY variant")->fetchAll() as $vr) { $allCnt += (int)$vr['n']; if ($vr['variant'] === 'U') $uCnt += (int)$vr['n']; }
                foreach ($pdo->query("SELECT id, waba_id FROM wa_numbers")->fetchAll() as $nr) $wabaOf[(int)$nr['id']] = wa_waba_of($nr); }
        }
        $wts = array_filter(array_map('floatval', (array)($c['extra']['num_w'] ?? [])), fn($x) => $x > 0);
        if ($wts) { $ctx['w'] = []; foreach ($wts as $nid0 => $w0) if (isset($ctx['nums'][(int)$nid0])) $ctx['w'][(int)$nid0] = $w0; $ctx['wsent'] = [];
            foreach ($pdo->query("SELECT number_id, COUNT(*) AS n FROM wa_camp_items WHERE camp_id = $id AND status = 'sent' GROUP BY number_id")->fetchAll() as $nr) $ctx['wsent'][(int)$nr['number_id']] = (int)$nr['n']; }
        $resolveM = function (array $tm, array $ct, array $it, int $iid) use ($pdo, $specs, &$usedPieces) { return wa_camp_resolve_vars($pdo, (int)$tm['nvars'], $specs, $ct, (string)($it['name'] ?: $ct['name']), $iid, $usedPieces); };
        while ($sent + $failed + $skipped < $quota && microtime(true) < $cDead && !$stop) {
            $take = min(40, $quota - ($sent + $failed + $skipped));
            $pick = $pdo->query("SELECT id, status FROM wa_camp_items WHERE camp_id = $id AND $due ORDER BY id LIMIT $take")->fetchAll(); if (!$pick) break;
            if ($mixOn) {   // per batch (not per person): who can carry the utility version right now - a number whose utility version was just approved joins at once, a re-labelled one drops out at once
                $plan = wa_mix_plan($pdo, $c, $ctx['nums'], $rem, $plan, false, $env); $ctx['u_nums'] = array_keys(array_filter($plan['per_number'], fn($p) => !empty($p['u']) && (int)$p['share'] > 0));
            }
            $was = []; foreach ($pick as $p) $was[(int)$p['id']] = (string)$p['status']; $in = implode(',', array_map('intval', array_keys($was)));
            $tok = 'c' . bin2hex(random_bytes(4));
            $pdo->prepare("UPDATE wa_camp_items SET status = 'sending', err = ?, sent_at = ? WHERE id IN ($in) AND status IN ('queued', 'retry')")->execute([$tok, $now]);
            $items = $pdo->prepare("SELECT * FROM wa_camp_items WHERE id IN ($in) AND status = 'sending' AND err = ?"); $items->execute([$tok]); $batch = []; $reqs = []; $rows = $items->fetchAll();
            try { wa_order_map($pdo)->prefetch(array_column($rows, 'phone')); } catch (Throwable $e) { }
            foreach ($rows as $it) {
                $iid = (int)$it['id']; $back = $was[$iid] ?? 'queued'; $isRetry = $back === 'retry'; $attempt = $isRetry ? (int)$it['tries'] + 1 : max(1, (int)$it['tries']);
                $ct = wa_ensure_contact($pdo, (string)$it['phone'], (string)$it['name']);
                if (function_exists('wa_auto_muted') && wa_auto_muted($pdo, (string)$it['phone'])) { $pdo->prepare("UPDATE wa_camp_items SET status = 'skipped', err = ? WHERE id = ?")->execute(['Just completed an order — no messages today and tomorrow', $iid]); $skipped++; continue; }   // ✅ order completed
                $gapDays = max(0, (int)setting_get($pdo, 'wa_camp_gap_days', '0'));   // off by default (owner's choice): a customer can get campaigns any day; setting wa_camp_gap_days = 7 brings back "one per 7 days"
                $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'out' AND mtype IN ('template', 'text') AND who = 'campaign' AND status <> 'failed' AND ts >= ?"); $q->execute([$it['phone'], date('Y-m-d H:i:s', $nowTs - max(0, $gapDays) * 86400)]);
                if ((int)$ct['optout'] === 1) { $pdo->prepare("UPDATE wa_camp_items SET status = 'skipped', err = 'Opted out' WHERE id = ?")->execute([$iid]); $skipped++; continue; }
                if (!$isRetry && $gapDays > 0 && (int)$q->fetchColumn() > 0) { $pdo->prepare("UPDATE wa_camp_items SET status = 'skipped', err = ? WHERE id = ?")->execute(['Already got a campaign message in the last ' . $gapDays . ' days', $iid]); $skipped++; continue; }
                // 3. no paid message where it is wasted (setting wa_camp_waste_filters, default on): just bought, ignored the last 2 campaigns, Meta capped them lately
                if (!$isRetry && (string)setting_get($pdo, 'wa_camp_waste_filters', '1') === '1') { $waste = '';
                    try { $ob = wa_order_map($pdo)[(string)$it['phone']] ?? null; if ($ob && !empty($ob['last']) && strtotime((string)$ob['last']) > $nowTs - 10 * 86400) $waste = 'Bought in the last 10 days'; } catch (Throwable $e) { }
                    if ($waste === '') { $lq = $pdo->prepare("SELECT ts FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'campaign' AND status IN ('sent', 'delivered', 'read') ORDER BY id DESC LIMIT 2"); $lq->execute([$it['phone']]); $l2 = $lq->fetchAll(PDO::FETCH_COLUMN);
                        if (count($l2) === 2) { $rq = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts > ?"); $rq->execute([$it['phone'], (string)end($l2)]); if ((int)$rq->fetchColumn() === 0) $waste = 'Ignored the last 2 campaigns'; } }
                    // (owner's rule, 5 Nov 2026) no skip for "Meta held them lately": Meta decides that itself, and a held person gets one more try 3 days later
                    // (owner's rule, 5 Nov 2026) business numbers (auto-reply) are NOT skipped any more — they get the campaign like everyone
                    if ($waste !== '') { $pdo->prepare("UPDATE wa_camp_items SET status = 'skipped', err = ? WHERE id = ?")->execute([$waste, $iid]); $skipped++; continue; }
                }
                $om = null; if ($mixOn) { try { $om = wa_order_map($pdo)[(string)$it['phone']] ?? null; } catch (Throwable $e) { } $ct['_u'] = !empty($ctx['u_nums']) && wa_mix_cheap_ok($pdo, $ct, $om, $nowTs); }
                [$nid, $why] = wa_pick_number($pdo, $ct, $ctx);
                if ($nid === 0) { $ig = $why === 'ignored every number'; $pdo->prepare("UPDATE wa_camp_items SET status = ?, err = ? WHERE id = ?")->execute([$ig ? 'skipped' : $back, $ig ? 'Ignored the messages of every number' : '', $iid]); if ($ig) $skipped++; else $stop = $why; continue; }
                $tUse = $ctx['nums'][$nid]['tm'] ?? $t; $tSent = $tUse; $variant = 'M'; $vars = null; $prevVar = (string)($it['variant'] ?? 'M') === 'U' ? 'U' : 'M';
                if ($mixOn && $prevVar === 'U' && $isRetry) {   // a retry keeps the utility version while it is still good on this number; if Meta re-labelled it meanwhile, the customer gets the marketing one
                    $pn = $plan['per_number'][$nid] ?? null;
                    if ($pn && !empty($pn['u'])) { $sv = $it['vars_json'] !== null && $it['vars_json'] !== '' ? json_decode((string)$it['vars_json'], true) : null; $uv = (is_array($sv) && (int)$it['tpl_used'] === (int)$pn['u_id']) ? $sv : wa_mix_u_vars($pdo, $pn['u'], $ct, $om, $nowTs); if (is_array($uv) && count($uv) >= count(wa_tpl_vars((string)$pn['u']['body']))) { $variant = 'U'; $tSent = $pn['u']; $vars = $uv; } }
                } elseif ($mixOn && !$isRetry) {
                    $tk = wa_mix_take($pdo, $plan, $nid, $ct, $om, $nowTs); if ($tk) { $variant = 'U'; $tSent = $tk['t']; $vars = $tk['vars']; }
                } elseif ($isUtil) {   // 💸 utility follow-up: a retry repeats the same words if that message is still usable; a new send picks the message that fits this person, or skips them
                    $sv = $it['vars_json'] !== null && $it['vars_json'] !== '' ? json_decode((string)$it['vars_json'], true) : null; $pk = null;
                    if ($isRetry && $prevVar === 'U' && is_array($sv)) foreach ($uTs as $u0) if ((int)$u0['id'] === (int)$it['tpl_used']) $pk = ['t' => $u0, 'vars' => $sv];
                    if (!$pk && !$isRetry) { $uRot = ($uRot ?? 0) + 1; $okU = array_values(array_filter($uTs, fn($u0) => in_array((string)($wabaOf[$nid] ?? ''), array_map('strval', (array)$u0['ok_wabas']), true))); $pk = $okU ? wa_util_pick($pdo, $okU, $ct, $ordDays, $nowTs, $uRot) : null; }
                    if (!$pk) { $pdo->prepare("UPDATE wa_camp_items SET status = 'skipped', err = ? WHERE id = ?")->execute([$isRetry ? 'The utility message is no longer usable' : 'Not for utility: no enquiry in the last 30 days and no order in the last ' . $ordDays . ' days', $iid]); $skipped++; continue; }
                    $variant = 'U'; $tSent = $pk['t']; $vars = $pk['vars'];
                } elseif ($uT && !$isRetry && $uCnt < ($allCnt + 1) * $uPct / 100 && ($uOk = array_values(array_filter($uTs, fn($u0) => in_array((string)($wabaOf[$nid] ?? ''), array_map('strval', (array)$u0['ok_wabas']), true))))) {   // below the utility share: this person gets a utility version (they take turns) if it is allowed for them
                    if ($om === null) { try { $om = wa_order_map($pdo)[(string)$it['phone']] ?? null; } catch (Throwable $e) { } }
                    $uRot = ($uRot ?? 0) + 1; $uPick = $uOk[$uRot % count($uOk)];
                    if (function_exists('wa_mix_cheap_ok') && wa_mix_cheap_ok($pdo, $ct, $om, $nowTs)) { $uv = wa_mix_u_vars($pdo, $uPick, $ct, $om, $nowTs); if (is_array($uv)) { $variant = 'U'; $tSent = $uPick; $vars = $uv; } }
                }
                if ($variant === 'M') {
                    $vars = $it['vars_json'] !== null && $it['vars_json'] !== '' ? json_decode((string)$it['vars_json'], true) : null;   // a retry (or a recovered item) sends exactly the same words
                    if ($prevVar === 'U' || ((int)$it['tpl_used'] > 0 && (int)$it['tpl_used'] !== (int)$tSent['id'] && $prevVar !== 'M')) $vars = null;
                    if (!is_array($vars) || count($vars) < $tSent['nvars']) $vars = $resolveM($tSent, $ct, $it, $iid);
                }
                if ((string)setting_get($pdo, 'wa_camp_free_window', '1') === '1' && !empty($ct['last_in_at']) && strtotime((string)$ct['last_in_at']) > $nowTs - 23 * 3600 && (int)$ct['last_number_id'] > 0
                    && in_array(strtoupper((string)($tSent['header_format'] ?? '')), ['', 'TEXT'], true) && empty($tSent['buttons']) && function_exists('wa_send_text')) {
                    $vjF = json_encode($vars, JSON_UNESCAPED_UNICODE); [$okF, $resF] = wa_send_text($pdo, $ct, wa_tpl_shown($tSent, (array)$vars), 'campaign', 0);   // inside the 24-hour window a chat message costs nothing
                    if ($okF) { $pdo->prepare("UPDATE wa_camp_items SET status = 'sent', number_id = ?, wamid = ?, err = '', err_code = 0, last_status = 'sent', tries = ?, vars_json = ?, next_try = NULL, sent_at = ?, variant = 'F', tpl_used = 0 WHERE id = ?")->execute([(int)$ct['last_number_id'], (string)$resF, $attempt, $vjF, date('Y-m-d H:i:s', wa_camp_now()), $iid]); $sent++; continue; }
                }
                $extra = (array)$c['extra']; if (!isset($extra['coupon']) || $extra['coupon'] === '') foreach ($specs as $k => $sp) if ($sp['src'] === 'coupon') { $extra['coupon'] = $vars[$k] ?? ''; break; }
                if ($variant === 'U') unset($extra['coupon']);
                if (!empty($extra['expires_hours'])) $extra['expires_ms'] = ($nowTs + (int)$extra['expires_hours'] * 3600) * 1000;
                [$okReq, $req] = wa_camp_request($pdo, $tSent, $ctx['nums'][$nid], (string)($ct['wa_id'] ?: '91' . $ct['phone']), $vars, $extra);
                if (!$okReq && $variant === 'U') {   // the utility message cannot be put together for this person: marketing instead
                    $variant = 'M'; $tSent = $tUse; $vars = $resolveM($tSent, $ct, $it, $iid); $extra = (array)$c['extra']; [$okReq, $req] = wa_camp_request($pdo, $tSent, $ctx['nums'][$nid], (string)($ct['wa_id'] ?: '91' . $ct['phone']), $vars, $extra);
                }
                $vj = json_encode($vars, JSON_UNESCAPED_UNICODE); $tuId = (int)$tSent['id'] === (int)$c['tpl_id'] ? 0 : (int)$tSent['id'];
                if (!$okReq) { $pdo->prepare("UPDATE wa_camp_items SET status = 'failed', number_id = ?, err = ?, vars_json = ?, tries = ?, variant = ?, tpl_used = ? WHERE id = ?")->execute([$nid, mb_substr(trim((string)$req) !== '' ? (string)$req : 'HRMS could not build the message (not sent to Meta)', 0, 190), $vj, $attempt, $variant, $tuId, $iid]); $failed++; continue; }
                $ctx['nums'][$nid]['left']--; $ctx['nums'][$nid]['used']++; $ctx['port_left']--; $reqs[] = $req; $batch[] = [$it, $nid, $vars, $attempt, $vj, $variant, $tSent, $tuId];
            }
            if ($reqs) {
                $out = wa_http_multi($reqs);
                foreach ($batch as $i => [$it, $nid, $vars, $attempt, $vj, $variant, $tSent, $tuId]) {
                    [$code, $d] = $out[$i] ?? [0, null]; $wamid = (string)($d['messages'][0]['id'] ?? ''); $ec = (int)($d['error']['code'] ?? 0);
                    if ($code >= 200 && $code < 300 && $wamid !== '') {
                        $pdo->prepare("UPDATE wa_camp_items SET status = 'sent', number_id = ?, wamid = ?, err = '', err_code = 0, last_status = 'sent', tries = ?, vars_json = ?, next_try = NULL, sent_at = ?, variant = ?, tpl_used = ? WHERE id = ?")->execute([$nid, $wamid, $attempt, $vj, date('Y-m-d H:i:s', wa_camp_now()), $variant, $tuId, $it['id']]);
                        wa_log_template_msg($pdo, $wamid, (string)$it['phone'], $nid, wa_tpl_shown($tSent, $vars), 'campaign'); $sent++; $allCnt++; if ($variant === 'U') $uCnt++; if (isset($ctx['wsent'])) $ctx['wsent'][$nid] = ($ctx['wsent'][$nid] ?? 0) + 1; continue;
                    }
                    $msg = mb_substr(wa_err(is_array($d) ? $d : null), 0, 190); $back = ($was[(int)$it['id']] ?? 'queued');
                    if (function_exists('wa_fail_raw')) wa_fail_raw($pdo, 'send', (string)$it['phone'], '', $ec, ['http' => $code, 'answer' => $d]);
                    if (in_array($ec, [130429, 131056], true)) { $pdo->prepare("UPDATE wa_camp_items SET status = ?, err = '' WHERE id = ?")->execute([$back, $it['id']]); $stop = 'Meta asked us to slow down'; continue; }
                    if (in_array($ec, [131042, 132000, 132001, 132005, 132007, 132012, 132015, 132016, 190, 131031], true) || $code === 401) {
                        $pdo->prepare("UPDATE wa_camp_items SET status = ?, err = '' WHERE id = ?")->execute([$back, $it['id']]);
if ($ec === 131042) { try { setting_set($pdo, 'wa_payblock_' . (int)$nid, date('Y-m-d H:i:s')); } catch (Throwable $e9) { } }   // this number: no campaign sends for 3 hours — the other number takes everything
                                                $stop = $ec === 131042 ? 'Meta says the WhatsApp account has no payment method — add one in Meta Business settings (code 131042).' : 'Meta stopped the sending: ' . $msg; $hard = true; continue;
                    }
                    [$plan_, $why] = wa_camp_fail_plan($ec, $attempt, $retryOn, (int)($c['extra']['retries'] ?? 3));
                    if ($plan_ === 'pause') { $pdo->prepare("UPDATE wa_camp_items SET status = ?, err = '' WHERE id = ?")->execute([$back, $it['id']]); $stop = $why; $hard = true; continue; }
                    if ($plan_ === 'skip') { $pdo->prepare("UPDATE wa_camp_items SET status = 'skipped', number_id = ?, err = ?, err_code = ?, vars_json = ?, tries = ?, variant = ?, tpl_used = ? WHERE id = ?")->execute([$nid, $why, $ec, $vj, $attempt, $variant, $tuId, $it['id']]); $skipped++; continue; }
                    if ($plan_ === 'retry') { $nt = wa_camp_next_try($pdo, $c, (string)$it['phone'], $attempt, wa_camp_now()); $pdo->prepare("UPDATE wa_camp_items SET status = 'retry', number_id = ?, err = ?, err_code = ?, vars_json = ?, tries = ?, next_try = ?, variant = ?, tpl_used = ? WHERE id = ?")->execute([$nid, $msg, $ec, $vj, $attempt, date('Y-m-d H:i:s', $nt), $variant, $tuId, $it['id']]); continue; }
                    $pdo->prepare("UPDATE wa_camp_items SET status = 'failed', number_id = ?, err = ?, err_code = ?, vars_json = ?, tries = ?, variant = ?, tpl_used = ? WHERE id = ?")->execute([$nid, $ec === 131026 ? 'Not on WhatsApp / cannot receive' : $msg, $ec, $vj, $attempt, $variant, $tuId, $it['id']]); $failed++;
                }
            }
        }
        if ($usedPieces) wa_piece_used($pdo, $usedPieces);
        if ($plan) wa_mix_save($pdo, $plan);
        $pdo->prepare("UPDATE wa_campaigns SET last_tick = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', $nowTs), $id]);
        if ($stop !== '' && !empty($hard)) wa_camp_pause($pdo, $id, $stop);
        elseif ($stop !== '') $pdo->prepare("UPDATE wa_campaigns SET note = ? WHERE id = ?")->execute([mb_substr($stop, 0, 200), $id]);
        else $pdo->prepare("UPDATE wa_campaigns SET note = '' WHERE id = ? AND status = 'running'")->execute([$id]);
        if ($stop === '' && (int)$pdo->query("SELECT COUNT(*) FROM wa_camp_items WHERE camp_id = $id AND (status IN ('queued', 'sending') OR (status = 'retry' AND next_try <= '" . date('Y-m-d H:i:s', $nowTs) . "'))")->fetchColumn() === 0) $pdo->prepare("UPDATE wa_campaigns SET status = 'done', finished_at = ?, note = '' WHERE id = ? AND status = 'running'")->execute([date('Y-m-d H:i:s', $nowTs), $id]);
        $hard = false; $res[$id] = ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'stop' => $stop];
        if ($plan) $res[$id]['mix'] = ['u_status' => $plan['u_status'], 'target' => $plan['target'], 'achieved' => $plan['achieved'], 'per_number' => array_map(fn($p) => ['share' => $p['share'], 'state' => $p['u_state'], 'u_given' => $p['u_given'], 'elig' => $p['elig']], $plan['per_number'])];
    }
    if (microtime(true) - $t0 < 30) { try { $res['auto_submit'] = wa_tpl_autosubmit_tick($pdo); } catch (Throwable $e) { wa_log($pdo, 'template', false, 'auto-submit: ' . $e->getMessage()); } }   // keeps every message submitted to every WhatsApp account, makes alternatives when there is trouble
    return $res;
}

/** 📱 For the preview: how many of these customers go to each number (same rules as wa_pick_number): wrote to a number in the last 30 days → that number, unless they
 *  ignored $switch+ messages from it since (→ the other number); never wrote → shared by room. */
function wa_camp_num_plan(PDO $pdo, array $phones, int $switch = 2): array
{
    $lab = []; foreach ($pdo->query("SELECT id, label, display FROM wa_numbers WHERE camp_ok = 1")->fetchAll() as $n) $lab[(int)$n['id']] = (string)($n['label'] ?: $n['display'] ?: 'Number ' . $n['id']);
    $o = ['numbers' => [], 'new' => 0, 'switched' => 0]; foreach ($lab as $id => $l) $o['numbers'][$id] = ['label' => $l, 'n' => 0];
    $cut = date('Y-m-d H:i:s', time() - 30 * 86400); $switch = max(1, $switch);
    foreach (array_chunk($phones, 500) as $ch) {
        if (!$ch) continue; $in = implode(',', array_fill(0, count($ch), '?'));
        $q = $pdo->prepare("SELECT phone, last_number_id, last_in_at FROM wa_contacts WHERE phone IN ($in)"); $q->execute($ch); $ct = []; foreach ($q->fetchAll() as $r) $ct[(string)$r['phone']] = $r;
        $ig = []; $q = $pdo->prepare("SELECT m.phone, m.number_id, COUNT(*) AS n FROM wa_messages m JOIN wa_contacts c ON c.phone = m.phone WHERE m.phone IN ($in) AND m.dir = 'out' AND m.mtype = 'template' AND m.status <> 'failed' AND m.ts > COALESCE(c.last_in_at, '1970-01-01') GROUP BY m.phone, m.number_id"); $q->execute($ch);
        foreach ($q->fetchAll() as $r) $ig[(string)$r['phone']][(int)$r['number_id']] = (int)$r['n'];
        foreach ($ch as $ph) {
            $c = $ct[(string)$ph] ?? null; $last = $c ? (int)$c['last_number_id'] : 0;
            if ($last && isset($lab[$last]) && $c['last_in_at'] && (string)$c['last_in_at'] >= $cut) { if (($ig[(string)$ph][$last] ?? 0) < $switch) { $o['numbers'][$last]['n']++; continue; } $o['switched']++; continue; }
            $o['new']++;
        }
    }
    $o['numbers'] = array_values($o['numbers']); return $o;
}
/** Sends per number of one campaign, split marketing / utility / free: [['label','m','u','f']]. */
function wa_camp_by_number(PDO $pdo, int $id): array
{
    $o = []; try { $lab = []; foreach ($pdo->query("SELECT id, label, display FROM wa_numbers")->fetchAll() as $n) $lab[(int)$n['id']] = (string)($n['label'] ?: $n['display'] ?: 'Number ' . $n['id']);
        $q = $pdo->prepare("SELECT number_id, COALESCE(NULLIF(variant, ''), 'M') AS v, COUNT(*) AS n FROM wa_camp_items WHERE camp_id = ? AND status = 'sent' GROUP BY number_id, v"); $q->execute([$id]);
        foreach ($q->fetchAll() as $r) { $k = (int)$r['number_id']; if (!isset($o[$k])) $o[$k] = ['label' => $lab[$k] ?? ('Number ' . $k), 'm' => 0, 'u' => 0, 'f' => 0]; $o[$k][$r['v'] === 'U' ? 'u' : ($r['v'] === 'F' ? 'f' : 'm')] += (int)$r['n']; } } catch (Throwable $e) { }
    return array_values($o);
}
/** What one campaign achieved: delivery, replies within 2 days of each message, orders within 7 days of the send, cost. */
function wa_camp_result(PDO $pdo, array $c, array $cfg, bool $withMix = false): array
{
    $id = (int)$c['id']; $cn = wa_camp_counts($pdo, $id); $d = wa_camp_delivery($pdo, $id); $t = null; if ((int)$c['tpl_id']) { try { $t = wa_tpl_row($pdo, (int)$c['tpl_id']); } catch (Throwable $e) { } }
    $end = $c['finished_at'] ?: date('Y-m-d H:i:s', wa_camp_now()); $ordTo = strtotime((string)$end) + 7 * 86400;
    $q = $pdo->prepare("SELECT COUNT(DISTINCT i.phone) FROM wa_camp_items i JOIN wa_messages m ON m.phone = i.phone AND m.dir = 'in' AND m.ts > i.sent_at AND m.ts <= DATE_ADD(i.sent_at, INTERVAL 2 DAY) WHERE i.camp_id = ? AND i.status = 'sent'"); $q->execute([$id]); $replied = (int)$q->fetchColumn();
    $sentAt = []; $q = $pdo->prepare("SELECT phone, sent_at FROM wa_camp_items WHERE camp_id = ? AND status = 'sent'"); $q->execute([$id]); foreach ($q->fetchAll() as $r) $sentAt[(string)$r['phone']] = strtotime((string)$r['sent_at']);
    $orders = 0; $rev = 0; $cust = [];
    if ($sentAt && $c['started_at']) {
        try {
            $q = $pdo->prepare("SELECT phone, created_at, total FROM shop_orders WHERE created_at >= ? AND phone IS NOT NULL AND phone != '' LIMIT 200000"); $q->execute([$c['started_at']]);
            foreach ($q->fetchAll() as $o) { $k = wa_key($o['phone']); $ts = strtotime((string)$o['created_at']); if (isset($sentAt[$k]) && $ts > $sentAt[$k] && $ts <= $ordTo) { $orders++; $rev += (int)round((float)$o['total']); $cust[$k] = true; } }
        } catch (PDOException $e) { }
    }
    $rate = $t && $t['category'] === 'UTILITY' && empty($t['relabelled_at']) ? (float)$cfg['rate_utility'] : (float)$cfg['rate_marketing'];
    $fq = $pdo->prepare("SELECT COUNT(*) FROM wa_camp_items WHERE camp_id = ? AND status = 'sent' AND variant = 'F'"); $fq->execute([$id]); $fSent = (int)$fq->fetchColumn();   // free chat sends (open 24-hour window)
    [$uSent, $uAsM] = wa_mix_u_counts($pdo, $id); $cost = round(($cn['sent'] - $uSent - $fSent) * $rate + ($uSent - $uAsM) * (float)$cfg['rate_utility'] + $uAsM * (float)$cfg['rate_marketing'], 2);   // a utility send counts as marketing when Meta re-labelled the message at or before the send
    $mix = null; if ($withMix) { try { $mix = wa_mix_report($pdo, $c, $cfg, true); } catch (Throwable $e) { $mix = null; } }
    return ['id' => $id, 'name' => $c['name'], 'status' => $c['status'], 'started_at' => $c['started_at'], 'total' => $cn['total'], 'sent' => $cn['sent'], 'failed' => $cn['failed'], 'skipped' => $cn['skipped'], 'queued' => $cn['queued'], 'retry' => $cn['retry'], 'delivered' => $d['delivered'], 'read' => $d['read'],
        'replied' => $replied, 'orders' => $orders, 'buyers' => count($cust), 'revenue' => $rev, 'cost' => $cost, 'cost_u' => round(($uSent - $uAsM) * (float)$cfg['rate_utility'], 2), 'cost_m' => round($cost - ($uSent - $uAsM) * (float)$cfg['rate_utility'], 2), 'u_as_m' => $uAsM, 'rate_u' => (float)$cfg['rate_utility'], 'rate_m' => (float)$cfg['rate_marketing'], 'u_off' => $c['extra']['u_off'] ?? null, 'u_pct' => (int)($c['extra']['u_pct'] ?? 0), 'by_number' => wa_camp_by_number($pdo, $id), 'sent_u' => $uSent, 'sent_m' => $cn['sent'] - $uSent - $fSent, 'sent_free' => $fSent, 'sent_paid' => $cn['sent'] - $fSent] + ($withMix ? ['mix' => $mix] : []);
}

// ---------------------------------------------------------------------------------------------------------------- campaigns: best time, suggestions
/** Messages customers wrote to us per hour of the day (last 30 days), kept for an hour. Returns ['hist' => [24 numbers], 'total', 'best' => [hours inside the window, best first]]. */
function wa_camp_hours(PDO $pdo, bool $fresh = false): array
{
    $c = null; try { $c = json_decode((string)setting_get($pdo, 'wa_camp_hours', ''), true); } catch (Throwable $e) { }
    if (!$fresh && is_array($c) && (int)($c['at'] ?? 0) > wa_camp_now() - 3600 && isset($c['hist'])) return $c;
    $hist = array_fill(0, 24, 0); $q = $pdo->prepare("SELECT HOUR(ts) AS h, COUNT(*) AS n FROM wa_messages WHERE dir = 'in' AND ts >= ? GROUP BY HOUR(ts)"); $q->execute([date('Y-m-d H:i:s', wa_camp_now() - 30 * 86400)]);
    foreach ($q->fetchAll() as $r) $hist[(int)$r['h']] = (int)$r['n'];
    [$o, $cl] = wa_camp_window($pdo); $in = []; for ($h = $o; $h < $cl; $h++) $in[$h] = $hist[$h]; arsort($in); $best = []; foreach ($in as $h => $n) { if ($n > 0 && count($best) < 3) $best[] = (int)$h; }
    $r = ['at' => wa_camp_now(), 'hist' => $hist, 'total' => array_sum($hist), 'best' => $best, 'window' => [$o, $cl], 'default_hour' => 19];
    try { setting_set($pdo, 'wa_camp_hours', json_encode($r)); } catch (Throwable $e) { }
    return $r;
}
/** "Claude suggests": audiences made from tags, orders and when they last wrote — plain rules (no AI call), each with the number of customers who could be messaged now. */
function wa_camp_suggest(PDO $pdo): array
{
    $c = null; try { $c = json_decode((string)setting_get($pdo, 'wa_camp_sugg', ''), true); } catch (Throwable $e) { }
    if (is_array($c) && (int)($c['at'] ?? 0) > wa_camp_now() - 600 && isset($c['items'])) return $c;
    $cnt = fn(array $f) => wa_aud_count($pdo, 'contacts', $f, true, 2); $items = [];
    $want = ['wants_buy', 'price_issue'];
    foreach ([[6, 8, '6 to 8 days ago'], [3, 10, '3 to 10 days ago'], [0, 29, 'in the last 30 days']] as [$a, $b, $lab]) {
        $f = ['gtags' => $want, 'wrote_from' => $a, 'wrote_to' => $b, 'ords' => 'no']; $n = $cnt($f);
        if ($n > 0) { $items[] = ['id' => 'wanted', 'kind' => 'best', 'title' => 'Wanted to buy, not ordered yet', 'count' => $n, 'text' => 'said they want to buy or asked the price, last wrote ' . $lab . ', and have not ordered. This group answers best.', 'audience' => ['src' => 'contacts', 'f' => $f]]; break; }
    }
    $f = ['dsl' => '60']; $n = $cnt($f); if ($n > 0) $items[] = ['id' => 'reorder', 'kind' => 'also', 'title' => 'Bought 30–60 days ago', 'count' => $n, 'text' => 'bought 30–60 days ago — time to reorder.', 'audience' => ['src' => 'contacts', 'f' => $f]];
    $f = ['gtags' => $want, 'wrote_from' => 30, 'wrote_to' => 120, 'ords' => 'no']; $n = $cnt($f); if ($n > 0) $items[] = ['id' => 'winback', 'kind' => 'also', 'title' => 'Wanted to buy 1–4 months ago', 'count' => $n, 'text' => 'wanted to buy 1–4 months ago and never ordered — win them back.', 'audience' => ['src' => 'contacts', 'f' => $f]];
    $f = ['gtags' => ['vip']]; $n = $cnt($f); if ($n > 0) $items[] = ['id' => 'vip', 'kind' => 'also', 'title' => 'Very frequent buyers', 'count' => $n, 'text' => 'are very frequent buyers — a thank-you or early access works well.', 'audience' => ['src' => 'contacts', 'f' => $f]];
    $f = ['gtags' => ['never_replied']]; $n = $cnt($f); if ($n > 0) $items[] = ['id' => 'silent', 'kind' => 'skip', 'title' => 'Never replied', 'count' => $n, 'text' => 'never replied (low chance — skip for now).', 'audience' => ['src' => 'contacts', 'f' => $f]];
    $r = ['at' => wa_camp_now(), 'items' => $items]; try { setting_set($pdo, 'wa_camp_sugg', json_encode($r)); } catch (Throwable $e) { }
    return $r;
}

// ---------------------------------------------------------------------------------------------------------------- campaigns: who got what
/** Short, plain-English reason for a Meta error code. */
function wa_camp_err_label(int $code, string $err = ''): string
{
    $m = [131026 => 'not on WhatsApp', 131049 => 'Meta limit for this customer', 131048 => 'spam block', 131050 => 'customer stopped marketing messages', 131047 => 'customer has not written in 24 hours', 131021 => 'same number as ours', 130472 => 'Meta test group',
          131000 => 'Meta had a problem', 131016 => 'Meta was busy', 131051 => 'message type not supported', 131052 => 'photo / video could not be downloaded', 131053 => 'photo / video could not be uploaded', 130429 => 'sent too fast', 131056 => 'too many messages to this customer',
          131042 => 'payment problem', 131031 => 'account locked', 132000 => 'wrong number of variables', 132001 => 'message not found', 132005 => 'text too long', 132007 => 'broke Meta’s message rules', 132012 => 'variable format wrong', 132015 => 'message paused by Meta', 132016 => 'message disabled by Meta'];
    if (isset($m[$code])) return $m[$code]; $e = trim($err); return $e !== '' ? mb_substr($e, 0, 90) : 'Meta gave no reason (see WhatsApp → Logs)';
}
/** The people of a campaign with their derived status (replied > read > delivered > sent; failed / retry / skipped / queued come from the item). */
function wa_camp_ds_sql(): string
{
    $rep = "EXISTS (SELECT 1 FROM wa_messages r WHERE r.phone = i.phone AND r.dir = 'in' AND r.ts > i.sent_at AND r.ts <= DATE_ADD(i.sent_at, INTERVAL 2 DAY))";
    return "SELECT i.*, m.status AS ms, COALESCE(m.err, '') AS merr, COALESCE(m.err_code, 0) AS mcode, CASE WHEN i.err_code = 131049 AND i.status IN ('skipped', 'retry') THEN 'held' WHEN i.status = 'skipped' THEN 'skipped' WHEN i.status = 'retry' THEN 'retry' WHEN i.status = 'failed' OR (i.status = 'sent' AND (i.last_status = 'failed' OR m.status = 'failed')) THEN 'failed' WHEN i.status IN ('queued', 'sending') THEN 'queued'
        WHEN $rep THEN 'replied' WHEN m.status = 'read' OR i.last_status = 'read' THEN 'read' WHEN m.status = 'delivered' OR i.last_status = 'delivered' THEN 'delivered' ELSE 'sent' END AS ds
        FROM wa_camp_items i LEFT JOIN wa_messages m ON m.wamid = i.wamid AND i.wamid <> '' WHERE i.camp_id = ?";
}
function wa_camp_ds_filter(string $f): string
{
    switch ($f) {
        case 'delivered': return "t.ds IN ('delivered', 'read', 'replied')";
        case 'read': return "t.ds IN ('read', 'replied')";
        case 'replied': case 'failed': case 'retry': case 'skipped': case 'queued': case 'sent': case 'held': return "t.ds = '" . $f . "'";
    }
    return '1 = 1';
}
function wa_camp_people_counts(PDO $pdo, int $id): array
{
    $q = $pdo->prepare("SELECT t.ds, COUNT(*) AS n FROM (" . wa_camp_ds_sql() . ") t GROUP BY t.ds"); $q->execute([$id]); $d = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'replied' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0, 'queued' => 0, 'held' => 0];
    foreach ($q->fetchAll() as $r) $d[(string)$r['ds']] = (int)$r['n']; $all = array_sum($d);
    return ['all' => $all, 'delivered' => $d['delivered'] + $d['read'] + $d['replied'], 'read' => $d['read'] + $d['replied'], 'replied' => $d['replied'], 'failed' => $d['failed'], 'retry' => $d['retry'], 'skipped' => $d['skipped'], 'queued' => $d['queued'], 'held' => $d['held']];
}
function wa_camp_people_reasons(PDO $pdo, int $id): array
{
    $q = $pdo->prepare("SELECT CASE WHEN t.err_code > 0 THEN t.err_code ELSE t.mcode END AS ec, COALESCE(NULLIF(t.err, ''), t.merr) AS et, COUNT(*) AS n FROM (" . wa_camp_ds_sql() . ") t WHERE t.ds IN ('failed', 'retry') GROUP BY ec, et ORDER BY n DESC LIMIT 60"); $q->execute([$id]); $by = [];
    foreach ($q->fetchAll() as $r) { $l = wa_camp_err_label((int)$r['ec'], (string)$r['et']); $by[$l] = ($by[$l] ?? 0) + (int)$r['n']; }
    arsort($by); $o = []; foreach (array_slice($by, 0, 8, true) as $l => $n) $o[] = ['text' => $l, 'n' => $n]; return $o;
}
/** One page of people (or every one when $per = 0 — for the CSV). Rows: phone, name, status, why, tries, next_try, send_at, sent_at, text. */
function wa_camp_people_rows(PDO $pdo, array $camp, ?array $tpl, string $f, string $qs, int $page, int $per, int $afterId = 0, int $limit = 0): array
{
    $id = (int)$camp['id']; $w = wa_camp_ds_filter($f); $p = [$id];
    if ($qs !== '') { $l = '%' . addcslashes($qs, '%_\\') . '%'; $w .= " AND (t.phone LIKE ? OR COALESCE(NULLIF(t.name, ''), c.name) LIKE ?)"; $p[] = $l; $p[] = $l; }
    if ($afterId > 0) { $w .= " AND t.id > ?"; $p[] = $afterId; }
    $lim = $limit > 0 ? "LIMIT " . (int)$limit : "LIMIT " . ((int)$per + 1) . " OFFSET " . (int)max(0, ($page - 1) * $per);   // one more than a page: tells whether there is a next page
    $s = $pdo->prepare("SELECT t.*, c.name AS cname FROM (" . wa_camp_ds_sql() . ") t LEFT JOIN wa_contacts c ON c.phone = t.phone WHERE $w ORDER BY t.id $lim"); $s->execute($p); $rows = $s->fetchAll(); $out = []; $tplBy = [];
    foreach ($rows as $r) {
        $tp = $tpl; $tu = (int)($r['tpl_used'] ?? 0); if ($tu > 0) { if (!isset($tplBy[$tu])) { try { $tplBy[$tu] = wa_tpl_row($pdo, $tu); } catch (Throwable $e) { $tplBy[$tu] = null; } } $tp = $tplBy[$tu] ?: $tpl; }
        $ds = (string)$r['ds']; $code = (int)$r['err_code'] ?: (int)($r['mcode'] ?? 0); if (trim((string)$r['err']) === '') $r['err'] = (string)($r['merr'] ?? '');   // Meta's own reason (saved on the message by the status webhook)
        $vars = $r['vars_json'] !== null && $r['vars_json'] !== '' ? json_decode((string)$r['vars_json'], true) : null; $why = '';   // fresh for every person (this sat inside the comment above, so a delivered row showed the reason of the row before it)
        if (($ds === 'failed' || $ds === 'retry') && $code === 0 && trim((string)$r['err']) === '') {   // no reason on the row: Meta's exact answer, kept by wa_fail_raw
            try { $fq = $pdo->prepare("SELECT code, payload FROM wa_fail_raw WHERE " . ((string)$r['wamid'] !== '' ? "wamid = ?" : "phone = ? AND at >= ?") . " ORDER BY id DESC LIMIT 1"); $fq->execute((string)$r['wamid'] !== '' ? [(string)$r['wamid']] : [(string)$r['phone'], (string)($r['sent_at'] ?: '2000-01-01')]);
                if ($fr = $fq->fetch()) { $code = (int)$fr['code']; $pj = json_decode((string)$fr['payload'], true); $e0 = (array)($pj['errors'][0] ?? ($pj['answer']['error'] ?? []));
                    $r['err'] = trim(implode(' — ', array_filter([(string)($e0['title'] ?? ''), (string)($e0['message'] ?? ''), (string)($e0['error_data']['details'] ?? '')]))); if (!$code) $code = (int)($e0['code'] ?? 0); $r['raw'] = mb_substr((string)$fr['payload'], 0, 1500); } } catch (Throwable $e) { }
            if ($code === 0 && trim((string)$r['err']) === '') {   // still nothing: what the webhook log says for this customer around that time
                try { $lq = $pdo->prepare("SELECT info FROM wa_log WHERE kind = 'status' AND ok = 0 AND info LIKE ? AND at >= ? ORDER BY id LIMIT 1"); $lq->execute([(string)$r['phone'] . '%', (string)($r['sent_at'] ?: '2000-01-01')]); $li = (string)$lq->fetchColumn();
                    if ($li !== '') { $r['err'] = mb_substr(preg_replace('/^\d+\s*·\s*/', '', $li), 0, 160); if (preg_match('/code (\d+)/', $li, $mm)) $code = (int)$mm[1]; } } catch (Throwable $e) { }
            }
        }
        if ($ds === 'failed') { $why = wa_camp_err_label($code, (string)$r['err']) . ($code ? ' (code ' . $code . ')' : ''); if (in_array($code, [131048, 131050, 131047, 131021, 130472, 131051, 100, 131008, 131009], true)) $why .= ' · not retried'; elseif ((int)$r['tries'] >= 3) $why .= ' · gave up after 3 tries'; }
        elseif ($ds === 'retry') $why = 'Failed: ' . wa_camp_err_label($code, (string)$r['err']) . ' · will try again';
        elseif ($ds === 'held') $why = (string)$r['status'] === 'retry' && $r['next_try'] ? 'Held by Meta (this customer got many marketing messages today, not charged) — one more try ' . date('j M, g:i a', strtotime((string)$r['next_try'])) : 'Held by Meta again on the extra try — stopped (not charged)';
        elseif ($ds === 'skipped') $why = (string)$r['err'];
        elseif ($ds === 'queued') { $sa = $r['send_at'] ? strtotime((string)$r['send_at']) : 0;
            $why = $sa && $sa < wa_camp_now() - 1800 ? 'Overdue — was due ' . date('j M, g:i a', $sa) . ' (the campaign is stopped or the background sender is not running)' : ($sa ? 'Waiting — goes out ' . date('j M, g:i a', $sa) : 'Waiting to be sent'); }
        elseif ($ds === 'replied') { $q = $pdo->prepare("SELECT body, mtype FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts > ? AND ts <= DATE_ADD(?, INTERVAL 2 DAY) ORDER BY id LIMIT 1"); $q->execute([$r['phone'], $r['sent_at'], $r['sent_at']]); $m = $q->fetch(); $b = $m ? trim((string)$m['body']) : ''; $why = $b !== '' ? 'Wrote back: “' . mb_substr($b, 0, 90) . '”' : 'Replied'; }
        $out[] = ['id' => (int)$r['id'], 'phone' => (string)$r['phone'], 'name' => trim((string)($r['name'] !== '' ? $r['name'] : $r['cname'])), 'status' => $ds, 'why' => $why, 'code' => $code, 'raw' => (string)($r['raw'] ?? ''), 'tries' => max(1, (int)$r['tries']), 'max_tries' => 3, 'next_try' => $r['next_try'], 'send_at' => $r['send_at'],
            'sent_at' => $ds === 'queued' ? null : $r['sent_at'], 'delivered_at' => null, 'text' => ($tp && is_array($vars)) ? wa_tpl_shown($tp, $vars) : '', 'chat' => true,
            'variant' => (string)($r['variant'] ?? 'M') === 'U' ? 'U' : 'M', 'version' => (string)($r['variant'] ?? 'M') === 'U' ? 'Utility' : 'Marketing', 'tpl_used' => (int)($r['tpl_used'] ?? 0)];
    }
    return $out;
}
}

// ---------------------------------------------------------------------------------------------------------------- router
$__wk = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wk, ['wa.fu_list', 'wa.fu_save', 'wa.fu_del', 'wa.fu_preset', 'wa.camp_list', 'wa.camp_get', 'wa.camp_preview', 'wa.camp_save', 'wa.camp_start', 'wa.camp_send_now', 'wa.camp_stop_all', 'wa.camp_pause', 'wa.camp_resume', 'wa.camp_cancel', 'wa.camp_del', 'wa.camp_tick', 'wa.camp_suggest', 'wa.camp_best_time', 'wa.camp_people', 'wa.camp_people_csv', 'wa.camp_thread', 'wa.reports', 'wa.mix_state', 'wa.camp_mix_set'], true) && !($__wk === 'wa.camp_suggest' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $auth = require_auth(); wa_guard_admin($pdo, $auth);
    if (!wa_tables($pdo) || !wa_more_tables($pdo) || !function_exists('wa_tpl_tables') || !wa_tpl_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    $me = (int)($auth['employee_id'] ?? 0); $cfg = wa_team_cfg($pdo);
    $engine = function () use ($pdo) { $c = (string)setting_get($pdo, 'wa_cron_at', ''); return ['cron_at' => $c, 'cron_ok' => $c !== '' && strtotime($c) > time() - 180]; };
    $tplList = function (bool $forCamp = false) use ($pdo, $isPost) {   // the approved messages; a campaign may also use messages with a photo / video / PDF on top once the template module can send them
        $wabas = wa_wabas($pdo); $o = []; $media = $forCamp && function_exists('wa_tpl_send_components') && function_exists('wa_tpl_sendable');
        foreach ($pdo->query("SELECT * FROM wa_templates ORDER BY star DESC, used DESC, id DESC")->fetchAll() as $r) { $r['buttons'] = json_decode((string)$r['buttons'], true) ?: []; $r['examples'] = json_decode((string)$r['examples'], true) ?: []; $p = wa_tpl_public($pdo, $r, $wabas);
            $hf = strtoupper((string)$r['header_format']); $special = !in_array($hf, ['', 'TEXT'], true);
            if (in_array($p['status'], ['approved', 'partial'], true) && (!$special || $media)) {
                $row = ['id' => $p['id'], 'name' => $p['name'], 'body' => $p['body'], 'vars' => $p['vars'], 'category' => $p['category'], 'status' => $p['status']];
                if ($forCamp) $row += ['header_format' => $hf === 'TEXT' ? '' : $hf, 'footer' => (string)$r['footer'], 'buttons' => $r['buttons'], 'folder' => (string)($r['folder'] ?? ''), 'examples' => array_values((array)($r['examples']['body'] ?? [])), 'language' => (string)$r['language'], 'purpose' => (string)$r['purpose']];
                $o[] = $row;
            }
        }
        if ($forCamp && $o) { try { $o = wa_mix_decorate($pdo, $o); } catch (Throwable $e) { } }   // + matrix (a chip per number), twins, utility_state, learned
        return $o;
    };
    switch ($__wk) {
        case 'wa.fu_list': {
            $rows = $pdo->query("SELECT * FROM wa_fu_rules ORDER BY after_min, id")->fetchAll(); $tp = $tplList(); $tn = []; foreach ($tp as $t) $tn[$t['id']] = $t;
            $out = []; foreach ($rows as $r) { $q = $pdo->prepare("SELECT status, COUNT(*) AS n FROM wa_fu_log WHERE rule_id = ? GROUP BY status"); $q->execute([$r['id']]); $c = ['sent' => 0, 'skipped' => 0, 'failed' => 0]; foreach ($q->fetchAll() as $x) $c[$x['status']] = (int)$x['n'];
                $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'after_min' => (int)$r['after_min'], 'tpl_id' => (int)$r['tpl_id'], 'active' => (int)$r['active'] === 1, 'tag' => $r['tag'], 'counts' => $c, 'tpl_ok' => isset($tn[(int)$r['tpl_id']]), 'tpl_name' => $tn[(int)$r['tpl_id']]['name'] ?? '']; }
            $names = employee_names_by_id($pdo); $recent = $pdo->query("SELECT l.phone, l.rule_id, l.sent_at, l.status, c.name FROM wa_fu_log l LEFT JOIN wa_contacts c ON c.phone = l.phone ORDER BY l.id DESC LIMIT 15")->fetchAll();
            json_response(['rules' => $out, 'templates' => array_values(array_filter($tp, fn($t) => $t['vars'] <= 1)), 'recent' => $recent, 'engine' => $engine(), 'tags' => WA_TAGS]);
        }
        case 'wa.fu_preset': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            if ((int)$pdo->query("SELECT COUNT(*) FROM wa_fu_rules")->fetchColumn() === 0) foreach ([['After 2 hours', 120], ['After 24 hours', 1440], ['After 48 hours', 2880], ['After 30 days', 43200], ['After 60 days', 86400]] as [$n, $m])
                $pdo->prepare("INSERT INTO wa_fu_rules (name, after_min, tpl_id, active, tag, created_at) VALUES (?, ?, 0, 0, '', ?)")->execute([$n, $m, date('Y-m-d H:i:s')]);
            json_response(['ok' => true]);
        }
        case 'wa.fu_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $id = (int)($in['id'] ?? 0); $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 60)); $min = (int)($in['after_min'] ?? 0); $tpl = (int)($in['tpl_id'] ?? 0); $act = !empty($in['active']) ? 1 : 0; $tag = isset(WA_TAGS[(string)($in['tag'] ?? '')]) ? (string)$in['tag'] : '';
            if ($min < 30 || $min > 130 * 1440) json_response(['error' => 'Choose a time between 30 minutes and 130 days.'], 422);
            if ($name === '') $name = 'After ' . ($min >= 1440 ? round($min / 1440, 1) . ' days' : round($min / 60, 1) . ' hours');
            if ($act) { $t = $tpl > 0 ? wa_camp_tpl($pdo, $tpl) : null; if (!$t) json_response(['error' => 'Pick an approved pre-made message first (without a photo on top).'], 422); if ($t['nvars'] > 1) json_response(['error' => 'A follow-up message can have only one variable — the customer’s name.'], 422); }
            if ($id > 0) $pdo->prepare("UPDATE wa_fu_rules SET name = ?, after_min = ?, tpl_id = ?, active = ?, tag = ? WHERE id = ?")->execute([$name, $min, $tpl, $act, $tag, $id]);
            else { $pdo->prepare("INSERT INTO wa_fu_rules (name, after_min, tpl_id, active, tag, created_at) VALUES (?, ?, ?, ?, ?, ?)")->execute([$name, $min, $tpl, $act, $tag, date('Y-m-d H:i:s')]); $id = (int)$pdo->lastInsertId(); }
            json_response(['ok' => true, 'id' => $id]);
        }
        case 'wa.fu_del': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $id = (int)(json_input()['id'] ?? 0); $pdo->prepare("DELETE FROM wa_fu_rules WHERE id = ?")->execute([$id]); json_response(['ok' => true]);
        }
        case 'wa.camp_list': {
            $hide = ''; try { $pdo->query("SELECT smart_plan FROM wa_campaigns LIMIT 0"); $hide = 'WHERE smart_plan = 0 '; } catch (PDOException $e) { }   // the campaigns of a Smart plan are shown on the Smart screens
            $rows = $pdo->query("SELECT * FROM wa_campaigns " . $hide . "ORDER BY id DESC LIMIT 40")->fetchAll(); $out = [];
            foreach ($rows as $r) { $r['audience'] = json_decode((string)$r['audience'], true) ?: []; $r['vars'] = json_decode((string)$r['vars'], true) ?: []; $r['extra'] = json_decode((string)($r['extra'] ?? ''), true) ?: []; $out[] = wa_camp_public($pdo, $r, false, $cfg); try { $__o = &$out[count($out) - 1]; if (in_array((string)$r['status'], ['running', 'paused'], true) && (int)($__o['counts']['sent'] ?? 0) + (int)($__o['counts']['held'] ?? 0) === 0) $__o['why'] = wa_camp_wait_reason($pdo, $r); unset($__o); } catch (Throwable $e) { } }
            $health = wa_camp_health($pdo); $caps = wa_number_caps($pdo, $health); $nums = [];
            $campOk = []; foreach ($pdo->query("SELECT id, camp_ok, display FROM wa_numbers")->fetchAll() as $r0) $campOk[(int)$r0['id']] = [(int)$r0['camp_ok'] === 1, (string)$r0['display']];
            foreach ($health as $h) { $c = $caps[(int)$h['id']] ?? null; if ($c) $nums[] = ['id' => (int)$h['id'], 'label' => $h['label'], 'display' => (string)($h['display'] ?? ($campOk[(int)$h['id']][1] ?? '')), 'quality' => $h['quality'], 'status' => (string)($h['status'] ?? ''), 'camp_ok' => $campOk[(int)$h['id']][0] ?? false, 'cap' => $c['cap'], 'used' => $c['used'], 'left' => $c['left'], 'why' => $c['why']]; }
            $pc = []; foreach ((function_exists('wa_piece_all') ? wa_piece_all($pdo, true) : []) as $slot => $l) $pc[$slot] = count($l);
            json_response(['campaigns' => $out, 'templates' => $tplList(true), 'numbers' => $nums, 'portfolio' => $caps['_port'] ?? null, 'engine' => $engine(), 'tags' => WA_TAGS, 'pieces' => $pc, 'window' => wa_camp_window($pdo), 'can_media' => function_exists('wa_tpl_send_components') && function_exists('wa_tpl_sendable')]);
        }
        case 'wa.camp_get': {
            $c = wa_camp_row($pdo, (int)($_GET['id'] ?? 0)); if (!$c) json_response(['error' => 'Campaign not found.'], 404);
            $o = wa_camp_public($pdo, $c, true, $cfg); $o['result'] = wa_camp_result($pdo, $c, $cfg, true); $o['mix'] = $o['result']['mix'] ?? $o['mix'] ?? null; json_response(['campaign' => $o, 'engine' => $engine()]);
        }
        case 'wa.camp_preview': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $aud = (array)($in['audience'] ?? []); $freq = max(0, min(60, (int)($in['freq_days'] ?? 3))); if (isset($in['push']) && in_array((string)$in['push'], ['careful', 'normal', 'aggressive', 'very'], true)) $freq = wa_camp_push((string)$in['push'])['freq'];
            [$send, $st] = wa_camp_audience($pdo, $aud, $freq); $t = (int)($in['tpl_id'] ?? 0) ? wa_camp_tpl($pdo, (int)$in['tpl_id'], true, (int)($in['media_lib_id'] ?? 0)) : null;
            $health = wa_camp_health($pdo); $caps = wa_number_caps($pdo, $health); $room = 0; foreach ($caps as $k => $c) if ($k !== '_port') $room += $c['left']; $port = $caps['_port']; $roomNow = min($room, $port['left']);
            $rate = $t && $t['category'] === 'UTILITY' ? $cfg['rate_utility'] : $cfg['rate_marketing']; $warn = [];
            if ($st['send'] > $roomNow) $warn[] = 'Room today is ' . number_format($roomNow) . ' messages (slow start + Meta’s daily limit' . ($port['assumed'] ? ' — Meta did not tell the limit, 250 assumed' : ' of ' . number_format($port['tier'])) . '). The rest continues automatically as room frees up — about ' . max(1, (int)ceil($st['send'] / max(1, $roomNow))) . ' day(s).';
            if (($aud['src'] ?? 'contacts') !== 'contacts') $warn[] = 'These people did not write to you first. Send only to customers who agreed to receive WhatsApp messages from you — Meta can block a number that gets reports.';
            $sample = array_map(fn($r) => ['name' => $r['name'], 'phone' => $r['phone']], array_slice($send, 0, 5));
            if (!wa_camp_in_window($pdo, wa_camp_now()) && empty($in['own_hour'])) $warn[] = 'Nothing is sent between ' . ((wa_camp_window($pdo)[1] - 12)) . ' pm and ' . wa_camp_window($pdo)[0] . ' am. If you start now, sending begins at ' . wa_camp_window($pdo)[0] . ' am.';
            $push = wa_camp_push((string)($in['push'] ?? 'normal')); $perMin = max(1, (int)($in['rate'] ?? 60) * $push['mult']);
            $mix = null; if ($t) { try { $want = !array_key_exists('mix', $in) || !empty($in['mix']); $mix = wa_mix_estimate($pdo, $send, (int)$t['id'], $cfg) + ['on' => $want]; } catch (Throwable $e) { $mix = null; } }
            // "each person at their best hour": when would they get it? (counted on up to 3,000 of them) — so the owner can choose "send now" instead
            $plan = null;
            if (!empty($in['own_hour']) && $send) {
                $base = max(wa_camp_now(), !empty($in['start_at']) ? (int)strtotime((string)$in['start_at']) : 0); $smp = array_slice($send, 0, 3000); $hrs = wa_camp_user_hours($pdo, array_column($smp, 'phone'));
                $b = ['soon' => 0, 'today' => 0, 'tomorrow' => 0, 'later' => 0]; $first = 0; $last = 0; $endToday = strtotime('tomorrow', $base);
                foreach ($smp as $r) { $ts = wa_camp_send_at($pdo, $base, (int)($hrs[$r['phone']] ?? 19), $r['phone']); $first = $first ? min($first, $ts) : $ts; $last = max($last, $ts);
                    if ($ts <= $base + 3600) $b['soon']++; elseif ($ts < $endToday) $b['today']++; elseif ($ts < $endToday + 86400) $b['tomorrow']++; else $b['later']++; }
                $k = count($send) / max(1, count($smp)); foreach ($b as $x => $n) $b[$x] = (int)round($n * $k);
                $plan = $b + ['first' => $first ? date('Y-m-d H:i:s', $first) : '', 'last' => $last ? date('Y-m-d H:i:s', $last) : ''];
            }
            $uSplit = null; if ((string)($in['kind'] ?? '') === 'util' && function_exists('wa_util_split')) { try { $uSplit = wa_util_split($pdo, array_column($send, 'phone'), (int)($in['ord_days'] ?? 60)); } catch (Throwable $e) { } }
            $uOk = null; if ((string)($in['kind'] ?? '') !== 'util' && function_exists('wa_util_split')) { try { $uOk = wa_util_split($pdo, array_slice(array_column($send, 'phone'), 0, 20000), 30); } catch (Throwable $e) { } }   // 💸 how many may get the utility version (wrote or ordered in the last 30 days)
            $byNum = []; try { $byNum = wa_camp_num_plan($pdo, array_slice(array_column($send, 'phone'), 0, 5000), (int)($in['switch_after'] ?? 2)); } catch (Throwable $e) { }   // 📱 which number would send to whom
            json_response(['util_split' => $uSplit, 'util_ok' => $uOk, 'rate_u' => (float)$cfg['rate_utility'], 'by_num' => $byNum, 'own_plan' => $plan, 'mix' => $mix, 'cost_with_mix' => $mix && !empty($mix['on']) ? round($st['send'] * $rate - $mix['est_saving'], 2) : null, 'stats' => $st, 'cost' => round($st['send'] * $rate, 2), 'rate' => $rate, 'room_today' => $roomNow, 'warnings' => $warn, 'sample' => $sample, 'per_minute' => min(1500, $perMin), 'minutes' => (int)ceil($st['send'] / min(1500, $perMin))]);
        }
        case 'wa.camp_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $id = (int)($in['id'] ?? 0); $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 80)); if ($name === '') json_response(['error' => 'Give the campaign a name.'], 422);
            $libId = max(0, (int)($in['media_lib_id'] ?? 0)); $isUtilC = (string)($in['kind'] ?? '') === 'util';
            if ($isUtilC) {   // 💸 utility follow-up campaign: the standard follow-up messages; at least one must be approved
                $pool = function_exists('wa_util_pool_usable') ? wa_util_pool_usable($pdo) : []; if (!$pool) json_response(['error' => 'None of the utility follow-up messages is approved by Meta yet. Press “Create the 6 messages” and wait for approval (minutes to a few hours).'], 422);
                usort($pool, fn($a, $b) => $b['nvars'] <=> $a['nvars']); $in['tpl_id'] = (int)$pool[0]['id']; $libId = 0; $in['vars'] = array_fill(0, (int)$pool[0]['nvars'], ['src' => 'name', 'val' => '', 'slot' => '']);
            }
            $t = wa_camp_tpl($pdo, (int)($in['tpl_id'] ?? 0), true, $libId);
            if (!$t) { $t0 = (int)($in['tpl_id'] ?? 0) ? wa_tpl_row($pdo, (int)$in['tpl_id']) : null; $hf0 = $t0 ? strtoupper((string)$t0['header_format']) : ''; json_response(['error' => $t0 && in_array($hf0, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && !$libId && empty($t0['header_lib_id']) ? 'This message has a photo, video or PDF on top. Choose the file to send in step 2 first.' : 'Pick an approved pre-made message that can be sent.'], 422); }
            $hf = strtoupper((string)$t['header_format']);
            if ($libId > 0) {   // the chosen file must exist and fit the header of the message
                $lib = function_exists('wa_lib_row') ? wa_lib_row($pdo, $libId) : null; if (!$lib) json_response(['error' => 'The chosen photo or video was not found in the library.'], 422);
                $want = ['IMAGE' => 'image', 'VIDEO' => 'video', 'DOCUMENT' => 'document'][$hf] ?? ''; if ($want !== '' && (string)$lib['kind'] !== $want) json_response(['error' => 'This message needs a ' . ['image' => 'photo', 'video' => 'video', 'document' => 'PDF'][$want] . ' on top, but you chose a ' . $lib['kind'] . '.'], 422);
                if ($want === '') $libId = 0;   // a message without a media header ignores a chosen file
            }
            $specs = wa_camp_vars_norm((array)($in['vars'] ?? []), (int)$t['nvars']); $bad = wa_camp_vars_check($pdo, $specs); if ($bad !== '') json_response(['error' => $bad], 422);
            $legacy = true; foreach ((array)($in['vars'] ?? []) as $x) if (is_array($x)) $legacy = false;
            $vars = $legacy ? array_slice(array_map(fn($x) => $x['val'], array_slice($specs, 1)), 0, 8) : $specs;   // an old-style request (plain texts) is stored the old way
            $ex = (array)($in['extra'] ?? []); $extra = []; foreach (['coupon', 'url_suffix', 'name', 'address'] as $k) if (!empty($ex[$k])) $extra[$k] = wa_camp_clean_var((string)$ex[$k], 200);
            foreach (['lat', 'lng'] as $k) if (isset($ex[$k]) && is_numeric($ex[$k])) $extra[$k] = (float)$ex[$k]; if (!empty($ex['expires_hours'])) $extra['expires_hours'] = max(1, min(720, (int)$ex['expires_hours'])); if (isset($ex['retries']) && (int)$ex['retries'] >= 1 && (int)$ex['retries'] < 3) $extra['retries'] = (int)$ex['retries'];
            if (!empty($ex['numbers']) && is_array($ex['numbers'])) {   // "Send from": only numbers that are CONNECTED at Meta and switched on for campaigns can be chosen
                $okN = []; foreach (wa_camp_health($pdo) as $h) if ((string)($h['status'] ?? '') === 'CONNECTED') $okN[(int)$h['id']] = true;
                foreach ($pdo->query("SELECT id, camp_ok FROM wa_numbers")->fetchAll() as $r0) if ((int)$r0['camp_ok'] !== 1) unset($okN[(int)$r0['id']]);
                $pick = array_values(array_filter(array_map('intval', $ex['numbers']), fn($x) => isset($okN[$x])));
                if (!$pick) json_response(['error' => 'None of the chosen numbers can send right now (pending / not connected / switched off for campaigns). Choose a connected number.'], 422);
                $extra['numbers'] = $pick;
                $w = []; foreach ((array)($ex['num_w'] ?? []) as $k => $v) if (in_array((int)$k, $pick, true) && (float)$v > 0) $w[(int)$k] = min(100, round((float)$v, 1)); if (count($w) >= 2) $extra['num_w'] = $w;
            }
            if ((int)($ex['u_pct'] ?? 0) > 0 && (!empty($ex['u_tpl']) || !empty($ex['u_auto']))) {   // the owner's utility share for this campaign, 5–80 %: all approved utility versions of the message (u_auto) or one chosen (u_tpl)
                if (!empty($ex['u_tpl'])) { $ur = wa_diag_row_tpl($pdo, (int)$ex['u_tpl']); if (!$ur || strtoupper((string)$ur['category']) !== 'UTILITY') json_response(['error' => 'The utility version must be a Utility message.'], 422); $extra['u_tpl'] = (int)$ur['id']; }
                if (!empty($ex['u_auto'])) $extra['u_auto'] = 1; $extra['u_pct'] = max(5, min(100, (int)$ex['u_pct']));   // the owner chooses 5–100 %
            }
            if (strtoupper((string)($t['category'] ?? '')) === 'MARKETING' && empty($extra['u_pct']) && empty($ex['u_off'])) { $extra['u_auto'] = 1; $extra['u_pct'] = 20; }
            if (!empty($ex['u_off'])) { unset($extra['u_auto'], $extra['u_tpl']); $extra['u_pct'] = 0; $extra['u_off_owner'] = 1; }   // 🔕 the owner turned utility OFF: the whole campaign goes as marketing
            if ($isUtilC) { unset($extra['u_auto'], $extra['u_tpl']); $extra['kind'] = 'util'; $extra['u_pct'] = 100; $extra['ord_days'] = in_array((int)($ex['ord_days'] ?? 60), [30, 60, 90, 120], true) ? (int)$ex['ord_days'] : 60; $extra['offer'] = mb_substr(trim((string)($ex['offer'] ?? '')), 0, 900); }   // always on (owner's rule): every marketing campaign mixes in utility, 20 % unless chosen   // how many tries a failed message gets (1-3; 3 = the normal way)
            $aud = (array)($in['audience'] ?? []); $aud['src'] = in_array($aud['src'] ?? '', ['contacts', 'all', 'paste'], true) ? $aud['src'] : 'contacts'; $aud['paste'] = mb_substr((string)($aud['paste'] ?? ''), 0, 400000);
            $rate = max(1, min(1500, (int)($in['rate'] ?? 60))); $win = trim((string)($in['win_end'] ?? '')); $win = $win !== '' && strtotime($win) ? date('Y-m-d H:i:s', strtotime($win)) : null; $start = trim((string)($in['start_at'] ?? '')); $start = $start !== '' && strtotime($start) ? date('Y-m-d H:i:s', strtotime($start)) : null;
            $push = in_array((string)($in['push'] ?? ''), ['careful', 'normal', 'aggressive', 'very'], true) ? (string)$in['push'] : 'normal';
            $freq = isset($in['push']) ? wa_camp_push($push)['freq'] : max(0, min(60, (int)($in['freq_days'] ?? 3))); $sw = max(1, min(5, (int)($in['switch_after'] ?? 2)));
            $retry = !empty($in['retry']) ? 1 : 0; $own = !empty($in['own_hour']) ? 1 : 0; $defH = max(0, min(23, (int)($in['def_hour'] ?? 19))); $folder = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($in['media_folder'] ?? ''))) ?: '';
            $prevEx = []; if ($id > 0) { $c = wa_camp_row($pdo, $id); if (!$c) json_response(['error' => 'Campaign not found.'], 404); if (!in_array($c['status'], ['draft', 'paused'], true)) json_response(['error' => 'Only a draft or a paused campaign can be changed.'], 422); $prevEx = is_array($c['extra'] ?? null) ? $c['extra'] : []; }
            $twinsNow = wa_mix_twins($pdo, (int)$t['id']);   // mix: on unless switched off (an old campaign that never had it stays as it was); tpl_u: the utility twin it is linked to (0 = Claude picks the best approved one)
            $extra['mix'] = array_key_exists('mix', $in) ? !empty($in['mix']) : (array_key_exists('mix', $prevEx) ? !empty($prevEx['mix']) : true); $tu = (int)($in['tpl_u'] ?? ($prevEx['tpl_u'] ?? 0)); $okTu = false; foreach ($twinsNow as $tw) if ((int)$tw['id'] === $tu) $okTu = true; $bt = wa_mix_best_twin($pdo, $twinsNow); $extra['tpl_u'] = $okTu ? $tu : ($bt ? (int)$bt['id'] : 0);
            $vals = [$name, (int)$t['id'], json_encode($aud, JSON_UNESCAPED_UNICODE), json_encode($vars, JSON_UNESCAPED_UNICODE), $rate, $win, $start, $freq, $sw, $libId, mb_substr($folder, 0, 40), $push, $retry, $own, $defH, json_encode($extra, JSON_UNESCAPED_UNICODE)];
            if ($id > 0) { $pdo->prepare("UPDATE wa_campaigns SET name = ?, tpl_id = ?, audience = ?, vars = ?, rate = ?, win_end = ?, start_at = ?, freq_days = ?, switch_after = ?, media_lib_id = ?, media_folder = ?, push = ?, retry = ?, own_hour = ?, def_hour = ?, extra = ? WHERE id = ?")->execute(array_merge($vals, [$id])); }
            else { $pdo->prepare("INSERT INTO wa_campaigns (name, tpl_id, audience, vars, rate, win_end, start_at, freq_days, switch_after, media_lib_id, media_folder, push, retry, own_hour, def_hour, extra, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)")->execute(array_merge($vals, [$me, date('Y-m-d H:i:s')])); $id = (int)$pdo->lastInsertId(); }
            json_response(['ok' => true, 'id' => $id]);
        }
        case 'wa.mix_state': {   // admin: why is a number at 40%? every state row + latest changes + settings + the plan of one campaign
            $o = wa_mix_dump($pdo, (int)($_GET['tpl_id'] ?? 0), (int)($_GET['number_id'] ?? 0));
            if ((int)($_GET['camp_id'] ?? 0) > 0) { $c = wa_camp_row($pdo, (int)$_GET['camp_id']); if ($c) $o['campaign'] = wa_mix_report($pdo, $c, $cfg, true); }
            $o['auto'] = function_exists('wa_tpl_auto_status') ? wa_tpl_auto_status($pdo) : null; json_response($o);
        }
        case 'wa.camp_mix_set': {   // the switch on a running / paused / draft campaign: {id, on}; takes effect on the next batch, nothing restarts
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $c = wa_camp_row($pdo, (int)($in['id'] ?? 0)); if (!$c) json_response(['error' => 'Campaign not found.'], 404);
            if (in_array($c['status'], ['done', 'cancelled'], true)) json_response(['error' => 'This campaign has finished.'], 422);
            $ex2 = is_array($c['extra'] ?? null) ? $c['extra'] : []; $ex2['mix'] = !empty($in['on']); $pdo->prepare("UPDATE wa_campaigns SET extra = ? WHERE id = ?")->execute([json_encode($ex2, JSON_UNESCAPED_UNICODE), $c['id']]); $c = wa_camp_row($pdo, (int)$c['id']); json_response(['ok' => true, 'mix' => wa_mix_report($pdo, $c, $cfg, true)]);
        }
        case 'wa.camp_start': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $c = wa_camp_row($pdo, (int)(json_input()['id'] ?? 0)); if (!$c) json_response(['error' => 'Campaign not found.'], 404);
            if ($c['status'] === 'paused') { $pdo->prepare("UPDATE wa_campaigns SET status = 'running', note = '' WHERE id = ?")->execute([$c['id']]); json_response(['ok' => true, 'queued' => 0]); }
            if ($c['status'] !== 'draft') json_response(['error' => 'This campaign was already started.'], 422);
            $t = wa_camp_tpl($pdo, (int)$c['tpl_id'], true, (int)$c['media_lib_id']); if (!$t) json_response(['error' => 'The pre-made message is not approved, or its photo / video is missing.'], 422);
            $bad = wa_camp_vars_check($pdo, wa_camp_vars_norm($c['vars'], (int)$t['nvars'])); if ($bad !== '') json_response(['error' => $bad], 422);
            $aud = $c['audience']; if (($aud['src'] ?? 'contacts') !== 'contacts' && empty(json_input()['consent'])) json_response(['error' => 'Tick the box to confirm these customers agreed to receive WhatsApp messages from you.'], 422);
            [$send, $st] = wa_camp_audience($pdo, $aud, (int)$c['freq_days']); if (!$send) json_response(['error' => 'Nobody is left to message with these filters.'], 422);
            ignore_user_abort(true); @set_time_limit(300); $future = $c['start_at'] && strtotime((string)$c['start_at']) > wa_camp_now(); $now = date('Y-m-d H:i:s', wa_camp_now());
            $own = (int)$c['own_hour'] === 1; $base = max(wa_camp_now(), $c['start_at'] ? (int)strtotime((string)$c['start_at']) : 0);   // each person's send time is the next time their usual hour comes round after the start
            $ins = $pdo->prepare("INSERT INTO wa_camp_items (camp_id, phone, name, send_at) VALUES (?, ?, ?, ?)");
            foreach (array_chunk($send, 300) as $ch) {
                $hrs = $own ? wa_camp_user_hours($pdo, array_column($ch, 'phone')) : []; $pdo->beginTransaction();
                try { foreach ($ch as $r) $ins->execute([(int)$c['id'], $r['phone'], mb_substr((string)$r['name'], 0, 120), $own ? date('Y-m-d H:i:s', wa_camp_send_at($pdo, $base, (int)($hrs[$r['phone']] ?? $c['def_hour']), $r['phone'])) : null]); $pdo->commit(); }
                catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            }
            $pdo->prepare("UPDATE wa_campaigns SET status = ?, started_at = ?, note = '' WHERE id = ?")->execute([$future ? 'scheduled' : 'running', $future ? null : $now, $c['id']]);
            wa_log($pdo, 'campaign', true, '#' . $c['id'] . ' ' . $c['name'] . ' started for ' . count($send) . ' customers.');
            $out = ['ok' => true, 'queued' => count($send), 'status' => $future ? 'scheduled' : 'running'];
            if (!$future) { wa_reply_early($out); try { ignore_user_abort(true); wa_camp_tick($pdo); } catch (Throwable $e) { wa_log($pdo, 'campaign', false, 'first round: ' . $e->getMessage()); } exit; }   // START = the first round goes out NOW, in this same request (it no longer waits for the cron or for the next 30-second tick of an open page)
            json_response($out);
        }
        case 'wa.camp_send_now': {   // ⚡ the waiting people go out now instead of at their best hour — everyone waiting, or one person
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $c = wa_camp_row($pdo, (int)($in['id'] ?? 0)); if (!$c) json_response(['error' => 'Campaign not found.'], 404);
            if (!in_array($c['status'], ['running', 'scheduled', 'paused'], true)) json_response(['error' => 'This campaign is not sending any more.'], 422);
            $now = date('Y-m-d H:i:s', wa_camp_now()); $ph = preg_replace('/\D+/', '', (string)($in['phone'] ?? '')); $ph = strlen($ph) >= 10 ? substr($ph, -10) : '';
            $u = $pdo->prepare("UPDATE wa_camp_items SET send_at = ? WHERE camp_id = ? AND status = 'queued' AND (send_at IS NULL OR send_at > ?)" . ($ph !== '' ? ' AND phone = ?' : ''));
            $u->execute(array_merge([$now, (int)$c['id'], $now], $ph !== '' ? [$ph] : [])); $n = $u->rowCount();
            if ($c['status'] === 'scheduled') $pdo->prepare("UPDATE wa_campaigns SET status = 'running', start_at = NULL, started_at = ?, note = '' WHERE id = ?")->execute([$now, $c['id']]);
            $night = !wa_camp_in_window($pdo, wa_camp_now());
            wa_log($pdo, 'campaign', true, '#' . $c['id'] . ' ' . $c['name'] . ': ' . $n . ' moved to send now');
            $dq = $pdo->prepare("SELECT COUNT(*) FROM wa_camp_items WHERE camp_id = ? AND status = 'queued' AND (send_at IS NULL OR send_at <= ?)"); $dq->execute([(int)$c['id'], $now]); $dueN = (int)$dq->fetchColumn();
            $c2 = wa_camp_row($pdo, (int)$c['id']) ?: $c; $wait = wa_camp_wait_reason($pdo, $c2);
            $out = ['ok' => true, 'moved' => $n, 'due' => $dueN, 'paused' => $c['status'] === 'paused', 'night' => $night, 'wait' => $wait,
                'note' => $wait !== '' ? $wait : ($dueN . ' are due now — the first round goes out right now, the rest at the campaign speed.')];
            if ($c['status'] !== 'paused' && $dueN > 0 && $wait === '') { wa_reply_early($out); try { ignore_user_abort(true); wa_camp_tick($pdo); } catch (Throwable $e) { wa_log($pdo, 'campaign', false, 'send now round: ' . $e->getMessage()); } exit; }   // SEND NOW = the round starts at once, not at the next cron / page tick
            json_response($out);
        }
        case 'wa.camp_stop_all': {   // ⏹ the owner stops EVERY campaign that is still running, scheduled or paused; their waiting people are dropped (nothing more is sent)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $now = date('Y-m-d H:i:s', wa_camp_now());
            $ids = array_map('intval', $pdo->query("SELECT id FROM wa_campaigns WHERE status IN ('running', 'scheduled', 'paused')")->fetchAll(PDO::FETCH_COLUMN)); $drop = 0;
            if ($ids) { $in0 = implode(',', $ids);
                $pdo->exec("UPDATE wa_campaigns SET status = 'cancelled', finished_at = '$now', note = 'Stopped by you (stop all)' WHERE id IN ($in0)");
                $drop = (int)$pdo->exec("UPDATE wa_camp_items SET status = 'skipped', err = 'Stopped by you' WHERE camp_id IN ($in0) AND status IN ('queued', 'retry')"); }
            wa_log($pdo, 'campaign', true, 'Stop all: ' . count($ids) . ' campaigns stopped, ' . $drop . ' waiting messages dropped');
            json_response(['ok' => true, 'stopped' => count($ids), 'dropped' => $drop]);
        }
        case 'wa.camp_pause': case 'wa.camp_resume': case 'wa.camp_cancel': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $c = wa_camp_row($pdo, (int)(json_input()['id'] ?? 0)); if (!$c) json_response(['error' => 'Campaign not found.'], 404);
            if ($__wk === 'wa.camp_pause') { if (!in_array($c['status'], ['running', 'scheduled'], true)) json_response(['error' => 'It is not running.'], 422); $pdo->prepare("UPDATE wa_campaigns SET status = 'paused', note = 'Paused by you' WHERE id = ?")->execute([$c['id']]); }
            elseif ($__wk === 'wa.camp_resume') { if ($c['status'] !== 'paused') json_response(['error' => 'It is not paused.'], 422); $pdo->prepare("UPDATE wa_campaigns SET status = 'running', note = '' WHERE id = ?")->execute([$c['id']]); }
            else { if (!in_array($c['status'], ['running', 'scheduled', 'paused'], true)) json_response(['error' => 'It cannot be stopped now.'], 422); $pdo->prepare("UPDATE wa_campaigns SET status = 'cancelled', finished_at = ?, note = 'Stopped by you' WHERE id = ?")->execute([date('Y-m-d H:i:s'), $c['id']]); $pdo->prepare("UPDATE wa_camp_items SET status = 'skipped', err = 'Campaign stopped' WHERE camp_id = ? AND status IN ('queued', 'retry')")->execute([$c['id']]); }
            json_response(['ok' => true]);
        }
        case 'wa.camp_del': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $c = wa_camp_row($pdo, (int)(json_input()['id'] ?? 0)); if (!$c) json_response(['error' => 'Campaign not found.'], 404);
            if (in_array($c['status'], ['running', 'scheduled'], true)) json_response(['error' => 'Stop it first.'], 422);
            $pdo->prepare("DELETE FROM wa_camp_items WHERE camp_id = ?")->execute([$c['id']]); $pdo->prepare("DELETE FROM wa_campaigns WHERE id = ?")->execute([$c['id']]); json_response(['ok' => true]);
        }
        case 'wa.camp_tick': {   // the Campaigns page calls this every 30 seconds while it is open — a fallback when the cron is not set up yet
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            ignore_user_abort(true); $r = wa_camp_tick($pdo); if (wa_lock($pdo, 'fu', 240)) { try { wa_fu_scan($pdo); } catch (Throwable $e) { } } json_response(['ok' => true, 'result' => $r]);
        }
        case 'wa.camp_suggest': {
            json_response(wa_camp_suggest($pdo) + ['freq_days' => 2]);
        }
        case 'wa.camp_best_time': {
            $h = wa_camp_hours($pdo, !empty($_GET['fresh'])); json_response(['hist' => $h['hist'], 'total' => $h['total'], 'best' => $h['best'], 'window' => $h['window'], 'default_hour' => $h['default_hour'], 'days' => 30]);
        }
        case 'wa.camp_people': {
            $c = wa_camp_row($pdo, (int)($_GET['id'] ?? 0)); if (!$c) json_response(['error' => 'Campaign not found.'], 404);
            $f = in_array((string)($_GET['f'] ?? 'all'), ['all', 'delivered', 'read', 'replied', 'failed', 'retry', 'skipped', 'queued', 'sent', 'held'], true) ? (string)($_GET['f'] ?? 'all') : 'all'; $page = max(1, (int)($_GET['page'] ?? 1)); $per = max(10, min(200, (int)($_GET['per'] ?? 50)));
            $tpl = null; try { $tpl = wa_tpl_row($pdo, (int)$c['tpl_id']); } catch (Throwable $e) { }
            $rows = wa_camp_people_rows($pdo, $c, $tpl, $f, trim((string)($_GET['q'] ?? '')), $page, $per); $more = count($rows) > $per; if ($more) array_pop($rows);
            $o = ['rows' => $rows, 'more' => $more, 'page' => $page, 'f' => $f];
            if ($page <= 1) { $o['counts'] = wa_camp_people_counts($pdo, (int)$c['id']); $o['reasons'] = wa_camp_people_reasons($pdo, (int)$c['id']); $o['wait'] = $o['counts']['queued'] > 0 ? wa_camp_wait_reason($pdo, $c) : ''; }
            json_response($o);
        }
        case 'wa.camp_thread': {   // the chat of ONE customer of this campaign: our campaign message, then what happened after it (their reply and ours)
            $c = wa_camp_row($pdo, (int)($_GET['id'] ?? 0)); if (!$c) json_response(['error' => 'Campaign not found.'], 404);
            $ph = preg_replace('/\D+/', '', (string)($_GET['phone'] ?? '')); if ($ph === '') json_response(['error' => 'Choose a customer.'], 422);
            $it = $pdo->prepare("SELECT sent_at FROM wa_camp_items WHERE camp_id = ? AND phone = ? ORDER BY id DESC LIMIT 1"); $it->execute([(int)$c['id'], $ph]); $sa = (string)$it->fetchColumn();
            if ($sa === '') json_response(['error' => 'This customer is not in this campaign.'], 404);
            $st = $pdo->prepare("SELECT id, ts, dir, who, mtype, body FROM wa_messages WHERE phone = ? AND ts >= DATE_SUB(?, INTERVAL 5 MINUTE) ORDER BY id LIMIT 80"); $st->execute([$ph, $sa]);
            $nm = ['image' => '📷 Photo', 'audio' => '🎤 Voice note', 'voice' => '🎤 Voice note', 'video' => '🎥 Video', 'document' => '📄 Document', 'sticker' => '🙂 Sticker', 'location' => '📍 Location', 'contacts' => '👤 Contact', 'template' => ''];
            $out = []; foreach ($st->fetchAll() as $m) { $ty = strtolower((string)$m['mtype']); $b = trim((string)$m['body']); $pre = $nm[$ty] ?? ''; $out[] = ['id' => (int)$m['id'], 'ts' => (string)$m['ts'], 'dir' => (string)$m['dir'], 'who' => (string)$m['who'], 'text' => $pre !== '' ? ($b !== '' && !preg_match('/^\[.*\]$/', $b) ? $pre . ' · ' . $b : $pre) : ($b !== '' ? $b : '[' . $ty . ']')]; }
            $nr = $pdo->prepare("SELECT name FROM wa_contacts WHERE phone = ?"); $nr->execute([$ph]);
            json_response(['ok' => true, 'phone' => $ph, 'name' => (string)$nr->fetchColumn(), 'sent_at' => $sa, 'messages' => $out]);
        }
        case 'wa.camp_people_csv': {
            $c = wa_camp_row($pdo, (int)($_GET['id'] ?? 0)); if (!$c) json_response(['error' => 'Campaign not found.'], 404);
            $f = in_array((string)($_GET['f'] ?? 'all'), ['all', 'delivered', 'read', 'replied', 'failed', 'retry', 'skipped', 'queued', 'sent', 'held'], true) ? (string)($_GET['f'] ?? 'all') : 'all';
            $tpl = null; try { $tpl = wa_tpl_row($pdo, (int)$c['tpl_id']); } catch (Throwable $e) { }
            $fn = 'campaign_' . $c['id'] . '_' . ($f === 'all' ? 'everyone' : $f) . '_' . date('Y-m-d') . '.csv';
            header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $fn . '"'); header('Cache-Control: no-store');
            $cell = function ($v) { $v = str_replace(["\r", "\n", "\t"], ' ', (string)$v); if ($v !== '' && strpos('=+-@', $v[0]) !== false) $v = "'" . $v; return '"' . str_replace('"', '""', $v) . '"'; };
            echo "\xEF\xBB\xBF" . implode(',', array_map($cell, ['Name', 'Phone', 'Status', 'Version', 'Why', 'Tries', 'Sent at', 'Next try', 'Message'])) . "\r\n"; $after = 0;
            $lab = ['sent' => 'Sent', 'delivered' => 'Delivered', 'read' => 'Read', 'replied' => 'Replied', 'failed' => 'Failed', 'retry' => 'Trying again', 'skipped' => 'Skipped', 'queued' => 'Waiting'];
            while (true) {
                $rows = wa_camp_people_rows($pdo, $c, $tpl, $f, '', 1, 0, $after, 2000); if (!$rows) break;
                foreach ($rows as $r) { $after = max($after, (int)$r['id']); echo implode(',', array_map($cell, [$r['name'], $r['phone'], $lab[$r['status']] ?? $r['status'], $r['version'], $r['why'], $r['tries'] . ' of 3', $r['sent_at'] ?? '', $r['next_try'] ?? '', $r['text']])) . "\r\n"; }
                if (count($rows) < 2000) break;
            }
            exit;
        }
        case 'wa.reports': {
            $days = in_array((int)($_GET['days'] ?? 30), [7, 30, 90], true) ? (int)$_GET['days'] : 30; $from = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
            $daily = []; for ($i = $days - 1; $i >= 0; $i--) $daily[date('Y-m-d', strtotime("-$i days"))] = ['in' => 0, 'out' => 0, 'tpl' => 0];
            $q = $pdo->prepare("SELECT SUBSTR(ts, 1, 10) AS d, dir, mtype, COUNT(*) AS n FROM wa_messages WHERE ts >= ? AND dir IN ('in', 'out') GROUP BY SUBSTR(ts, 1, 10), dir, mtype"); $q->execute([$from]);
            foreach ($q->fetchAll() as $r) { if (!isset($daily[$r['d']])) continue; if ($r['dir'] === 'in') $daily[$r['d']]['in'] += (int)$r['n']; elseif ($r['mtype'] === 'template') $daily[$r['d']]['tpl'] += (int)$r['n']; else $daily[$r['d']]['out'] += (int)$r['n']; }
            $dl = []; foreach ($daily as $d => $v) $dl[] = ['d' => $d] + $v;
            $nm = []; foreach ($pdo->query("SELECT id, label, display FROM wa_numbers")->fetchAll() as $n) $nm[(int)$n['id']] = ['number' => $n['label'] !== '' ? $n['label'] : $n['display'], 'in' => 0, 'out' => 0, 'tpl' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0];
            $q = $pdo->prepare("SELECT number_id, dir, mtype, status, COUNT(*) AS n FROM wa_messages WHERE ts >= ? AND dir IN ('in', 'out') GROUP BY number_id, dir, mtype, status"); $q->execute([$from]);
            foreach ($q->fetchAll() as $r) { $k = (int)$r['number_id']; if (!isset($nm[$k])) continue; $n = (int)$r['n']; if ($r['dir'] === 'in') { $nm[$k]['in'] += $n; continue; } if ($r['mtype'] === 'template') $nm[$k]['tpl'] += $n; else $nm[$k]['out'] += $n; if ($r['status'] === 'delivered' || $r['status'] === 'read') $nm[$k]['delivered'] += $n; if ($r['status'] === 'read') $nm[$k]['read'] += $n; if ($r['status'] === 'failed') $nm[$k]['failed'] += $n; }
            $names = employee_names_by_id($pdo); $stats = wa_person_stats($pdo, $days); $ppl = [];
            foreach (wa_people($pdo) as $p) { $s = $stats[$p['emp_id']] ?? null; $ppl[] = ['emp_id' => $p['emp_id'], 'name' => $p['name'], 'open' => $p['open'], 'replies' => $s['replies'] ?? 0, 'answered' => $s['answered'] ?? 0, 'median' => $s['median'] ?? null, 'avg' => $s['avg'] ?? null, 'fast' => $s['fast'] ?? null]; }
            $src = ['query' => 0, 'campaign' => 0, 'community' => 0]; $q = $pdo->prepare("SELECT source, COUNT(*) AS n FROM wa_contacts WHERE first_seen >= ? AND last_in_at IS NOT NULL GROUP BY source"); $q->execute([$from]); foreach ($q->fetchAll() as $r) if (isset($src[$r['source']])) $src[$r['source']] = (int)$r['n'];
            $w = $pdo->query("SELECT last_in_at FROM wa_contacts WHERE last_in_at IS NOT NULL AND (last_out_at IS NULL OR last_out_at < last_in_at) AND optout = 0 AND status = 'open'")->fetchAll(); $waitMin = array_map(fn($r) => (time() - strtotime((string)$r['last_in_at'])) / 60, $w);
            $camps = []; foreach ($pdo->query("SELECT * FROM wa_campaigns WHERE created_at >= '" . $from . "' ORDER BY id DESC LIMIT 12")->fetchAll() as $c) { $c['audience'] = []; $c['vars'] = []; $camps[] = wa_camp_result($pdo, $c, $cfg); }
            $tplN = array_sum(array_column($dl, 'tpl')); $fu = (int)$pdo->query("SELECT COUNT(*) FROM wa_fu_log WHERE status = 'sent' AND sent_at >= '" . $from . "'")->fetchColumn();
            json_response(['days' => $days, 'daily' => $dl, 'numbers' => array_values($nm), 'people' => $ppl, 'sources' => $src, 'waiting' => ['n' => count($w), 'avg_min' => $waitMin ? round(array_sum($waitMin) / count($waitMin)) : 0, 'max_min' => $waitMin ? (int)round(max($waitMin)) : 0],
                'campaigns' => $camps, 'cost' => ['templates' => $tplN, 'followups' => $fu, 'estimate' => round($tplN * (float)$cfg['rate_marketing'], 2), 'rate' => (float)$cfg['rate_marketing']], 'totals' => ['in' => array_sum(array_column($dl, 'in')), 'out' => array_sum(array_column($dl, 'out')), 'tpl' => $tplN]]);
        }
    }
}
