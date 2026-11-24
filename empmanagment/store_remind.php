<?php
/**
 * Store → Messages → "Customer reminders": the owner's own list of customers to message BY HAND (WhatsApp Web opens with the
 * message already written, the cashier presses Send and then "Done"). Nothing is sent by the server.
 *
 * Who is on the list (every customer who was billed with a phone number, shopped in the new billing):
 *   finished   the perfume bought last time must be finished by now        (days since the bill >= 110% of the time the ml should last)
 *   running    it is about to finish                                       (80% of that time)
 *   thank      bought yesterday                                            (thank-you, one day after)
 *   missing    nothing bought for 60+ days
 *   hello      "kem cho" - at most every 15 days, for everybody else
 * One message per customer per 7 days, never to a customer who is stopped, the day's list is cut at "limit".
 * Birthdays stay in the "To send today" card of the same page (that module is not touched).
 * Tables: st_rm_log (what was sent / skipped), st_rm_stop (do not message). Settings: st_cs_settings key 'rm'.
 */

function sr_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_rm_log (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(20) NOT NULL, name VARCHAR(120) NOT NULL DEFAULT '', kind VARCHAR(10) NOT NULL, day DATE NOT NULL, status VARCHAR(5) NOT NULL DEFAULT 'sent', text VARCHAR(1200) NOT NULL DEFAULT '', at DATETIME NOT NULL, by_name VARCHAR(80) NOT NULL DEFAULT '', KEY idx_rm_phone (phone, day), KEY idx_rm_day (day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_rm_stop (phone VARCHAR(20) NOT NULL PRIMARY KEY, at DATETIME NOT NULL, by_name VARCHAR(80) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_cs_settings (skey VARCHAR(30) NOT NULL PRIMARY KEY, svalue MEDIUMTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $ok = true;
    } catch (Throwable $e) { error_log('sr_tables: ' . $e->getMessage()); return $ok = false; }
}

function sr_kinds(): array
{
    return ['finished' => ['🔁', 'Perfume must be finished'], 'running' => ['⏳', 'Perfume is about to finish'], 'thank' => ['🙏', 'Thank you (bought yesterday)'], 'missing' => ['👋', 'Not seen for a long time'], 'hello' => ['💬', 'Kem cho']];
}
function sr_msgs_default(): array
{
    return [
        'finished' => "નમસ્તે {name} 🙏 તમે {perfume} {ml} ml લીધું હતું, હવે પૂરું થઈ ગયું હશે 😊 એ જ કે બીજું નવું જોઈએ તો અહીં મેસેજ કરજો, અમે તૈયાર રાખીશું.",
        'running' => "નમસ્તે {name} 🙏 તમે {perfume} {ml} ml લીધું હતું, હવે પૂરું થવા આવ્યું હશે. ફરી જોઈએ તો અહીં મેસેજ કરજો 😊",
        'thank' => "નમસ્તે {name} 🙏 ગઈ કાલે {perfume} લીધું તે બદલ આભાર! કેવું લાગ્યું? કંઈ પણ પૂછવું હોય તો અહીં મેસેજ કરજો 😊",
        'missing' => "નમસ્તે {name} 🙏 ઘણા દિવસથી મળ્યા નથી, કેમ છો? નવું જોવા સ્ટોર પર આવજો 😊",
        'hello' => "નમસ્તે {name} 🙏 TM Perfume House, સુરત તરફથી કેમ છો? કંઈ જોઈએ તો અહીં મેસેજ કરજો 😊",
    ];
}
function sr_cfg(PDO $pdo): array
{
    $d = ['limit' => 40, 'rate' => 0.6, 'missing_days' => 60, 'hello_gap' => 15, 'msgs' => sr_msgs_default()];
    try { $v = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = 'rm'")->fetchColumn(); $j = $v ? json_decode($v, true) : null; if (is_array($j)) { foreach (['limit', 'rate', 'missing_days', 'hello_gap'] as $k) if (isset($j[$k])) $d[$k] = $j[$k]; if (is_array($j['msgs'] ?? null)) foreach ($j['msgs'] as $k => $t) if (isset($d['msgs'][$k]) && trim((string)$t) !== '') $d['msgs'][$k] = (string)$t; } } catch (Throwable $e) { }
    $d['limit'] = max(5, min(300, (int)$d['limit'])); $d['rate'] = max(0.1, min(5.0, (float)$d['rate'])); $d['missing_days'] = max(14, min(365, (int)$d['missing_days'])); $d['hello_gap'] = max(7, min(90, (int)$d['hello_gap']));
    return $d;
}
function sr_cfg_save(PDO $pdo, array $in): array
{
    $c = sr_cfg($pdo); foreach (['limit', 'rate', 'missing_days', 'hello_gap'] as $k) if (isset($in[$k])) $c[$k] = $in[$k];
    if (is_array($in['msgs'] ?? null)) foreach ($in['msgs'] as $k => $t) if (isset($c['msgs'][$k])) { $t = trim(mb_substr((string)$t, 0, 900)); if ($t !== '') $c['msgs'][$k] = $t; }
    $c['limit'] = max(5, min(300, (int)$c['limit'])); $c['rate'] = max(0.1, min(5.0, (float)$c['rate'])); $c['missing_days'] = max(14, min(365, (int)$c['missing_days'])); $c['hello_gap'] = max(7, min(90, (int)$c['hello_gap']));
    $pdo->prepare("INSERT INTO st_cs_settings (skey, svalue) VALUES ('rm', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([json_encode($c, JSON_UNESCAPED_UNICODE)]);
    return $c;
}
function sr_phone(string $p): string { $d = preg_replace('/\D+/', '', $p); if (strlen($d) === 10) $d = '91' . $d; return $d; }
function sr_short_title(string $t): string { $t = trim(preg_replace('/\s+/', ' ', $t)); $p = preg_split('/\s+[-–—]\s+/u', $t); $t = trim($p[0] ?? $t); return mb_substr($t, 0, 60); }
function sr_text(string $tpl, string $name, string $perfume, int $ml): string
{
    $first = trim($name) !== '' ? explode(' ', trim($name))[0] : 'ગ્રાહક';
    return trim(strtr($tpl, ['{name}' => $first, '{perfume}' => $perfume !== '' ? $perfume : 'પરફ્યુમ', '{ml}' => (string)$ml]));
}

/** The list for one day: [rows, more, counts]. $today = 'Y-m-d' (the store's day). */
function sr_queue(PDO $pdo, string $today): array
{
    $cfg = sr_cfg($pdo); $t0 = strtotime($today);
    $stop = []; foreach ($pdo->query("SELECT phone FROM st_rm_stop")->fetchAll(PDO::FETCH_COLUMN) as $p) $stop[sr_phone((string)$p)] = 1;
    // every customer with a phone: the LAST bill, the number of bills, who the buyer was
    $last = [];
    $q = $pdo->query("SELECT p.phone, p.name, p.person_no, b.bill_id, b.biz_date FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id AND b.status = 'ok' WHERE p.phone <> '' AND p.declined = 0 ORDER BY b.biz_date, b.bill_id, p.person_no");
    foreach ($q->fetchAll() as $r) {
        $ph = sr_phone((string)$r['phone']); if (strlen($ph) < 11) continue;
        $nm = trim((string)$r['name']); $prev = $last[$ph] ?? null;
        $last[$ph] = ['phone' => $ph, 'name' => $nm !== '' ? $nm : ($prev['name'] ?? ''), 'bill_id' => (int)$r['bill_id'], 'day' => (string)$r['biz_date'], 'no' => (int)$r['person_no'], 'n' => ($prev['n'] ?? 0) + 1];
    }
    // what the last bill had (perfume with the most ml, total ml)
    $items = []; $ids = array_values(array_unique(array_map(fn($x) => $x['bill_id'], $last)));
    foreach (array_chunk($ids, 500) as $ch) {
        $in = implode(',', array_map('intval', $ch)); if ($in === '') continue;
        $q = $pdo->query("SELECT i.bill_id, i.ml, COALESCE(pr.title, '') title FROM st_bill_items i LEFT JOIN st_bottles bt ON bt.bottle_id = i.bottle_id LEFT JOIN st_products pr ON pr.product_id = bt.product_id WHERE i.kind = 'sale' AND i.bill_id IN ($in)");
        foreach ($q->fetchAll() as $r) { $b = (int)$r['bill_id']; $items[$b]['ml'] = ($items[$b]['ml'] ?? 0) + (int)$r['ml']; $items[$b]['by'][(string)$r['title']] = ($items[$b]['by'][(string)$r['title']] ?? 0) + (int)$r['ml']; }
    }
    // what was already done: any message in the last 7 days blocks; "hello" has its own gap; today's rows are shown as done
    $recent = []; $helloAt = []; $sentToday = []; $skipToday = [];
    $from = date('Y-m-d', strtotime('-' . max(7, $cfg['hello_gap']) . ' day', $t0));
    $st = $pdo->prepare("SELECT phone, kind, day, status, name, text FROM st_rm_log WHERE day >= ?"); $st->execute([$from]);
    foreach ($st->fetchAll() as $r) {
        $ph = sr_phone((string)$r['phone']); $d = (string)$r['day'];
        if ($r['status'] === 'sent') { if ($d >= date('Y-m-d', strtotime('-6 day', $t0))) $recent[$ph] = 1; if ($r['kind'] === 'hello') $helloAt[$ph] = max($helloAt[$ph] ?? '', $d); if ($d === $today) $sentToday[$ph] = ['kind' => (string)$r['kind'], 'name' => (string)$r['name'], 'text' => (string)$r['text']]; }
        elseif ($d === $today) $skipToday[$ph] = 1;
    }
    $prio = ['finished' => 1, 'running' => 2, 'thank' => 3, 'missing' => 4, 'hello' => 5]; $cand = [];
    foreach ($last as $ph => $c) {
        if (isset($stop[$ph]) || isset($recent[$ph]) || isset($skipToday[$ph]) || isset($sentToday[$ph])) continue;
        $days = (int)floor(($t0 - strtotime($c['day'])) / 86400); if ($days < 0) continue;
        $it = $items[$c['bill_id']] ?? ['ml' => 0, 'by' => []]; $ml = (int)$it['ml']; $perfume = ''; if (!empty($it['by'])) { arsort($it['by']); $perfume = sr_short_title((string)array_key_first($it['by'])); }
        $buyer = $c['no'] === 1; $kind = ''; $why = '';
        if ($buyer && $ml > 0) {
            $dur = max(10, (int)round($ml / $cfg['rate'])); $run = (int)round(0.8 * $dur); $fin = (int)round(1.1 * $dur);
            if ($days >= $fin && $days <= $fin + 30) { $kind = 'finished'; $why = ($perfume !== '' ? $perfume . ' ' : '') . $ml . ' ml · ' . $days . ' days ago'; }
            elseif ($days >= $run && $days < $fin) { $kind = 'running'; $why = ($perfume !== '' ? $perfume . ' ' : '') . $ml . ' ml · ' . $days . ' days ago'; }
        }
        if ($kind === '' && $buyer && $days === 1) { $kind = 'thank'; $why = 'bought yesterday' . ($perfume !== '' ? ': ' . $perfume : ''); }
        if ($kind === '' && $days >= $cfg['missing_days'] && $days <= 365) { $kind = 'missing'; $why = 'last bill ' . $days . ' days ago'; }
        if ($kind === '' && $days >= 3 && (!isset($helloAt[$ph]) || $helloAt[$ph] <= date('Y-m-d', strtotime('-' . $cfg['hello_gap'] . ' day', $t0)))) { $kind = 'hello'; $why = 'last bill ' . $days . ' days ago'; }
        if ($kind === '') continue;
        $text = sr_text($cfg['msgs'][$kind], $c['name'], $perfume, $ml);
        $cand[] = ['phone' => $ph, 'name' => $c['name'], 'kind' => $kind, 'why' => $why, 'text' => $text, 'url' => 'https://web.whatsapp.com/send?phone=' . $ph . '&text=' . rawurlencode($text), 'status' => 'pending', 'p' => $prio[$kind], 'days' => $days];
    }
    usort($cand, fn($a, $b) => [$a['p'], $b['days']] <=> [$b['p'], $a['days']]);
    $doneN = count($sentToday); $room = max(0, $cfg['limit'] - $doneN); $more = max(0, count($cand) - $room); $cand = array_slice($cand, 0, $room);
    foreach ($cand as &$c) unset($c['p'], $c['days']); unset($c);
    $done = []; foreach ($sentToday as $ph => $s) $done[] = ['phone' => $ph, 'name' => $s['name'], 'kind' => $s['kind'], 'why' => '', 'text' => $s['text'], 'url' => '', 'status' => 'sent'];
    $m = $pdo->prepare("SELECT COUNT(*) FROM st_rm_log WHERE status = 'sent' AND day >= ?"); $m->execute([date('Y-m-01', $t0)]); $month = (int)$m->fetchColumn();
    return ['rows' => array_merge($cand, $done), 'more' => $more, 'counts' => ['pending' => count($cand), 'sent_today' => $doneN, 'month' => $month, 'stopped' => count($stop), 'customers' => count($last)], 'cfg' => $cfg];
}

/** The st_rm.* actions. */
function sr_handle(PDO $pdo, string $action): void
{
    $auth = require_auth(); $who = (string)($auth['full_name'] ?? $auth['username'] ?? '');
    if (!sr_tables($pdo)) json_response(['error' => 'Could not prepare the reminders.'], 500);
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if ($action === 'st_rm.list') json_response(sr_queue($pdo, st_day()) + ['day' => st_day(), 'kinds' => sr_kinds()]);
    if (!$post) json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); $ph = sr_phone((string)($in['phone'] ?? '')); if (strlen($ph) < 11) json_response(['error' => 'Wrong phone number.'], 422);
    if ($action === 'st_rm.done') {
        $kind = (string)($in['kind'] ?? ''); if (!isset(sr_kinds()[$kind])) json_response(['error' => 'Wrong kind.'], 422);
        $pdo->prepare("INSERT INTO st_rm_log (phone, name, kind, day, status, text, at, by_name) VALUES (?,?,?,?, 'sent', ?, ?, ?)")->execute([$ph, mb_substr((string)($in['name'] ?? ''), 0, 120), $kind, st_day(), mb_substr((string)($in['text'] ?? ''), 0, 1200), date('Y-m-d H:i:s'), mb_substr($who, 0, 80)]);
        json_response(['ok' => true]);
    }
    if ($action === 'st_rm.skip') { $pdo->prepare("INSERT INTO st_rm_log (phone, name, kind, day, status, text, at, by_name) VALUES (?,?,?,?, 'skip', '', ?, ?)")->execute([$ph, mb_substr((string)($in['name'] ?? ''), 0, 120), isset(sr_kinds()[(string)($in['kind'] ?? '')]) ? (string)$in['kind'] : 'hello', st_day(), date('Y-m-d H:i:s'), mb_substr($who, 0, 80)]); json_response(['ok' => true]); }
    if ($action === 'st_rm.stop') { $pdo->prepare("INSERT INTO st_rm_stop (phone, at, by_name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE at = VALUES(at)")->execute([$ph, date('Y-m-d H:i:s'), mb_substr($who, 0, 80)]); json_response(['ok' => true]); }
    json_response(['error' => 'Unknown action.'], 404);
}
function sr_handle_cfg(PDO $pdo, string $action): void
{
    $auth = require_auth(); if (!sr_tables($pdo)) json_response(['error' => 'Could not prepare the reminders.'], 500);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['cfg' => sr_cfg($pdo), 'defaults' => sr_msgs_default(), 'kinds' => sr_kinds()]);
    $in = json_input(); json_response(['ok' => true, 'cfg' => sr_cfg_save($pdo, $in)]);
}
