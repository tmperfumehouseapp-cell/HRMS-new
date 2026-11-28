<?php
/**
 * Store → WhatsApp → 🧵 Lineup: messages that go BY THEMSELVES to every Store customer, day by day after their LAST purchase (Day 2 … Day 120),
 * from the Store's own number. Customers who bought in the last 120 days (setting) are taken in too, each at the step that fits their day.
 *
 * Rules
 *  - One ladder per customer, counted from the last bill; a new purchase starts the ladder again from Day 0.
 *  - Only the step that is due NOW goes (the newest step whose day has come and whose next step has not): a customer who bought 22 days ago gets Day 20, not Day 2…20.
 *  - Each step goes once per purchase; the language of the customer (Gujarati / Hindi / English) when that template exists, else the Gujarati one.
 *  - Never: STOP customers, a customer whose chat is waiting for the team ("Help"), more than one Store message (campaign or lineup) to one customer in 20 hours.
 *  - Inside the send hours and the daily cap of Store campaigns (Campaigns → Settings). Master switch: OFF until the owner turns it on.
 *  - Replies: a button text → an answer of its own (free text inside the 24-hour window), a "help" button pauses the ladder and hands the chat to the team, "stop" ends it.
 * Tables: st_wa_ln_cust (one row per customer), st_wa_ln_log (one row per message). Settings: st_cs_settings 'swline'.
 * Actions (st_wa.ln_*): ln_get, ln_save, ln_plan, ln_report, ln_cust, ln_resume.
 */

function swl_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_wa_ln_cust (phone VARCHAR(12) NOT NULL PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', bill_id INT NOT NULL DEFAULT 0, bill_no VARCHAR(20) NOT NULL DEFAULT '', token VARCHAR(24) NOT NULL DEFAULT '',
            perfume VARCHAR(120) NOT NULL DEFAULT '', anchor DATE NOT NULL, status VARCHAR(8) NOT NULL DEFAULT 'active', why VARCHAR(120) NOT NULL DEFAULT '', updated_at DATETIME NULL, KEY k_st (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_wa_ln_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, bill_id INT NOT NULL, day SMALLINT NOT NULL, tpl_id INT NOT NULL DEFAULT 0, status VARCHAR(8) NOT NULL,
            wamid VARCHAR(120) NOT NULL DEFAULT '', err VARCHAR(220) NOT NULL DEFAULT '', at DATETIME NOT NULL, UNIQUE KEY u_step (phone, bill_id, day), KEY k_at (at), KEY k_w (wamid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $ok = true;
    } catch (Throwable $e) { error_log('swl_tables: ' . $e->getMessage()); return $ok = false; }
}

/** The ready ladder: the 15 Lineup templates (Templates → 🧵 Lineup) by day, and the answers to their buttons. */
function swl_defaults(): array
{
    if (!function_exists('swt_lineup_defs') && is_file(__DIR__ . '/store_wa_tpl.php')) require_once __DIR__ . '/store_wa_tpl.php';
    $steps = []; if (function_exists('swt_lineup_defs')) foreach (swt_lineup_defs() as $d) $steps[] = ['day' => (int)$d['day'], 'family' => preg_replace('/_gu$/', '', (string)$d['name']), 'on' => true, 'vars' => ['name', 'perfume']];
    $loc = "📍 TM Perfume House, Vesu, Surat\nરોજ સવારે 11 થી રાત્રે 11\nhttps://maps.google.com/?q=TM+Perfume+House+Vesu+Surat";
    $replies = [
        ['match' => 'ખૂબ ગમી', 'action' => 'text', 'text' => "Thank you {name}! 🙏 તમારો feedback અમારા માટે બહુ કિંમતી છે. ફરી મળીશું!", 'tag' => 'Happy'],
        ['match' => 'મદદ જોઈએ છે', 'action' => 'help', 'text' => "અમારી team તમને થોડી વારમાં contact કરશે 🙏 ઉતાવળ હોય તો 8469312467 પર call કરો.", 'tag' => ''],
        ['match' => 'જલ્દી ઊડી જાય છે', 'action' => 'help', 'text' => "જણાવવા બદલ thank you. અમારી team તમને સાચી રીત સમજાવવા call કરશે 🙏", 'tag' => ''],
        ['match' => 'Location મોકલો', 'action' => 'text', 'text' => $loc, 'tag' => ''],
        ['match' => 'હું આવીશ', 'action' => 'text', 'text' => "સરસ {name}! 😊 અમે તમારી રાહ જોઈશું.\n\n" . $loc, 'tag' => 'Will visit'],
        ['match' => 'Refill કરાવવું છે', 'action' => 'text', 'text' => "સરસ! Store પર આવો ત્યારે bottle સાથે લાવજો, અમે refill કરી આપીશું 😊\n\n" . $loc, 'tag' => 'Refill'],
        ['match' => 'નવું collection બતાવો', 'action' => 'help', 'text' => "જરૂર! અમારી team તમને નવું collection મોકલે છે 😊", 'tag' => 'Wants new'],
        ['match' => 'Gift options બતાવો', 'action' => 'help', 'text' => "જરૂર! તમારું budget જણાવશો? અમારી team gift options મોકલશે 🎁", 'tag' => 'Gift'],
        ['match' => 'Offer જાણવી છે', 'action' => 'help', 'text' => "અમારી team તમને offer ની details મોકલે છે 😊", 'tag' => 'Offer'],
        ['match' => 'હવે message નહીં', 'action' => 'stop', 'text' => "બરાબર, હવે અમે message નહીં મોકલીએ. Thank you 🙏", 'tag' => ''],
    ];
    return ['on' => false, 'backfill' => 120, 'steps' => $steps, 'replies' => $replies];
}
function swl_cfg(PDO $pdo): array
{
    $d = swl_defaults();
    try { $v = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = 'swline'")->fetchColumn(); $j = $v ? json_decode((string)$v, true) : null; if (is_array($j)) foreach (['on', 'backfill', 'steps', 'replies'] as $k) if (isset($j[$k])) $d[$k] = $j[$k]; } catch (Throwable $e) { }
    $d['on'] = !empty($d['on']); $d['backfill'] = max(0, min(365, (int)$d['backfill']));
    $d['steps'] = array_values(array_filter(array_map(fn($s) => ['day' => max(1, min(365, (int)($s['day'] ?? 0))), 'family' => (string)($s['family'] ?? ''), 'on' => !empty($s['on']), 'vars' => array_values(array_map('strval', (array)($s['vars'] ?? ['name', 'perfume'])))], (array)$d['steps']), fn($s) => $s['family'] !== ''));
    usort($d['steps'], fn($a, $b) => $a['day'] <=> $b['day']);
    return $d;
}

/** The approved template of a step for a language: family_lang (newest approved version), else Gujarati, else any approved one of the family. */
function swl_tpl(PDO $pdo, string $waba, string $family, string $lang): ?array
{
    static $memo = []; $k = $family . '|' . $lang; if (array_key_exists($k, $memo)) return $memo[$k];
    $q = $pdo->prepare("SELECT t.id, t.name, t.language FROM wa_templates t JOIN wa_tpl_waba w ON w.tpl_id = t.id AND w.waba_id = ? AND UPPER(w.status) = 'APPROVED' WHERE t.name LIKE ? ORDER BY t.id DESC"); $q->execute([$waba, $family . '%']);
    $best = null; $rank = 9;
    foreach ($q->fetchAll() as $r) { $n = (string)$r['name']; if (!preg_match('/^' . preg_quote($family, '/') . '_(gu|hi|en)(_v\d+)?$/', $n, $m)) continue; $rk = $m[1] === $lang ? 0 : ($m[1] === 'gu' ? 1 : 2); if ($rk < $rank) { $rank = $rk; $best = (int)$r['id']; } }
    return $memo[$k] = $best ? wa_tpl_row($pdo, $best) : null;
}

/** Takes in every customer who bought in the last N days (and moves the ladder to the newest purchase). */
function swl_enrol(PDO $pdo, int $days): int
{
    $n = 0; $from = date('Y-m-d', time() - $days * 86400);
    $q = $pdo->prepare("SELECT p.phone, p.name, p.token, b.bill_id, b.bill_no, b.biz_date FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id AND b.status = 'ok' WHERE p.phone <> '' AND p.declined = 0 AND b.biz_date >= ? ORDER BY b.biz_date, b.bill_id"); $q->execute([$from]);
    $last = []; foreach ($q->fetchAll() as $r) { $ph = substr(preg_replace('/\D/', '', (string)$r['phone']), -10); if (strlen($ph) === 10) $last[$ph] = $r; }
    if (!$last) return 0;
    $cur = []; foreach ($pdo->query("SELECT phone, bill_id FROM st_wa_ln_cust")->fetchAll() as $r) $cur[(string)$r['phone']] = (int)$r['bill_id'];
    $items = []; $ids = array_values(array_unique(array_map(fn($r) => (int)$r['bill_id'], $last)));
    foreach (array_chunk($ids, 800) as $ch) { $in = implode(',', array_map('intval', $ch)); try { foreach ($pdo->query("SELECT i.bill_id, COALESCE(pr.title, '') t FROM st_bill_items i LEFT JOIN st_bottles bt ON bt.bottle_id = i.bottle_id LEFT JOIN st_products pr ON pr.product_id = bt.product_id WHERE i.kind = 'sale' AND i.bill_id IN ($in)")->fetchAll() as $r) if (!isset($items[(int)$r['bill_id']]) && trim((string)$r['t']) !== '') $items[(int)$r['bill_id']] = function_exists('sr_short_title') ? sr_short_title((string)$r['t']) : (string)$r['t']; } catch (Throwable $e) { } }
    $ins = $pdo->prepare("INSERT INTO st_wa_ln_cust (phone, name, bill_id, bill_no, token, perfume, anchor, status, why, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', '', ?) ON DUPLICATE KEY UPDATE name = VALUES(name), bill_id = VALUES(bill_id), bill_no = VALUES(bill_no), token = VALUES(token), perfume = VALUES(perfume), anchor = VALUES(anchor), status = IF(status = 'stop', 'stop', 'active'), why = IF(status = 'stop', why, ''), updated_at = VALUES(updated_at)");
    foreach ($last as $ph => $r) { if (($cur[$ph] ?? 0) === (int)$r['bill_id']) continue; $ins->execute([$ph, mb_substr((string)$r['name'], 0, 120), (int)$r['bill_id'], (string)$r['bill_no'], (string)$r['token'], mb_substr((string)($items[(int)$r['bill_id']] ?? ''), 0, 120), (string)$r['biz_date'], date('Y-m-d H:i:s')]); $n++; }
    return $n;
}

/** Who gets which step now: [[cust row, step], …] (does not send). */
function swl_due(PDO $pdo, array $cfg, int $limit = 100000): array
{
    $steps = array_values(array_filter($cfg['steps'], fn($s) => $s['on'])); if (!$steps) return [];
    $done = []; foreach ($pdo->query("SELECT phone, bill_id, day FROM st_wa_ln_log WHERE at >= '" . date('Y-m-d', time() - 400 * 86400) . "'")->fetchAll() as $r) $done[$r['phone'] . '|' . $r['bill_id'] . '|' . $r['day']] = 1;
    $stop = []; try { foreach ($pdo->query("SELECT phone FROM st_rm_stop")->fetchAll(PDO::FETCH_COLUMN) as $p) $stop[substr(preg_replace('/\D/', '', (string)$p), -10)] = 1; } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT phone FROM st_wa_chat WHERE optout = 1")->fetchAll(PDO::FETCH_COLUMN) as $p) $stop[substr((string)$p, -10)] = 1; } catch (Throwable $e) { }
    $o = []; $today = strtotime(date('Y-m-d'));
    foreach ($pdo->query("SELECT * FROM st_wa_ln_cust WHERE status = 'active' ORDER BY anchor DESC")->fetchAll() as $c) {
        if (isset($stop[$c['phone']])) continue; $age = (int)floor(($today - strtotime((string)$c['anchor'])) / 86400);
        $due = null; foreach ($steps as $i => $s) { if ($s['day'] > $age) break; $next = $steps[$i + 1]['day'] ?? 100000; if ($age < $next) $due = $s; }
        if (!$due || isset($done[$c['phone'] . '|' . $c['bill_id'] . '|' . $due['day']])) continue;
        $o[] = [$c, $due, $age]; if (count($o) >= $limit) break;
    }
    return $o;
}

/** One sending round of the Lineup (called with the Store campaign round). Returns how many went. */
function swl_tick(PDO $pdo, array $num, array $ccfg, int &$left): int
{
    if (!swl_tables($pdo)) return 0; $cfg = swl_cfg($pdo); if (!$cfg['on'] || $left <= 0) return 0;
    $mark = sys_get_temp_dir() . '/hrms_st_ln_enrol'; if (!is_file($mark) || time() - (int)@filemtime($mark) > 600) { @touch($mark); swl_enrol($pdo, max(1, $cfg['backfill'])); }      // new bills come in every 10 minutes
    $waba = wa_waba_of($num); $sent = 0; $langs = []; try { foreach ($pdo->query("SELECT phone, lang FROM st_wa_lang")->fetchAll() as $r) $langs[(string)$r['phone']] = (string)$r['lang']; } catch (Throwable $e) { }
    $gap = date('Y-m-d H:i:s', time() - max(1, (int)($ccfg['any_gap_h'] ?? 20)) * 3600);
    $recent = $pdo->prepare("SELECT (SELECT COUNT(*) FROM st_wa_ln_log WHERE phone = ? AND status = 'sent' AND at >= ?) + (SELECT COUNT(*) FROM st_wa_camp_item WHERE phone = ? AND status = 'sent' AND sent_at >= ?)");
    $log = $pdo->prepare("INSERT IGNORE INTO st_wa_ln_log (phone, bill_id, day, tpl_id, status, wamid, err, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"); $t0 = microtime(true);
    foreach (swl_due($pdo, $cfg, $left * 4 + 20) as [$c, $s, $age]) {
        if ($left <= 0 || microtime(true) - $t0 > 30) break; $ph = (string)$c['phone'];
        try { $recent->execute([$ph, $gap, $ph, $gap]); if ((int)$recent->fetchColumn() > 0) continue; } catch (Throwable $e) { }      // a Store message already went in the last hours: next round / tomorrow
        $t = swl_tpl($pdo, $waba, $s['family'], $langs[$ph] ?? 'gu'); if (!$t) continue;      // the step's template is not approved yet: it waits
        $vals = swc_vals(['name' => (string)$c['name'], 'perfume' => (string)$c['perfume'], 'days' => $age, 'visits' => 1, 'spent' => 0, 'bill_no' => (string)$c['bill_no'], 'token' => (string)$c['token']], array_map(fn($k) => ['kind' => $k], array_pad($s['vars'], count(wa_tpl_vars((string)$t['body'])), 'name')));
        $vals = array_slice($vals, 0, count(wa_tpl_vars((string)$t['body'])));
        [$ok, $w] = swc_send($pdo, $t, $num, $ph, $vals);
        $log->execute([$ph, (int)$c['bill_id'], (int)$s['day'], (int)$t['id'], $ok ? 'sent' : 'failed', $ok ? $w : '', $ok ? '' : mb_substr($w, 0, 220), date('Y-m-d H:i:s')]);
        if ($ok) { $sent++; $left--; }
    }
    return $sent;
}

/** A customer wrote to the Store number: the reply rules of the Lineup (button texts). Called from sw_in. */
function swl_on_reply(PDO $pdo, string $phone, string $type, string $body): void
{
    if (!swl_tables($pdo) || !in_array($type, ['button', 'interactive', 'text'], true)) return; $cfg = swl_cfg($pdo); if (!$cfg['on']) return;
    $txt = mb_strtolower(trim($body)); if ($txt === '') return; $rule = null;
    foreach ($cfg['replies'] as $r) { $m = mb_strtolower(trim((string)($r['match'] ?? ''))); if ($m !== '' && $txt === $m) { $rule = $r; break; } }
    if (!$rule) return;
    $q = $pdo->prepare("SELECT name FROM st_wa_ln_cust WHERE phone = ?"); $q->execute([$phone]); $name = (string)$q->fetchColumn(); $first = trim($name) !== '' ? explode(' ', trim($name))[0] : '';
    $action = (string)($rule['action'] ?? 'text'); $text = trim(str_replace('{name}', $first, (string)($rule['text'] ?? ''))); $text = preg_replace('/\s+([!?.,])/u', '$1', $text);
    if ($action === 'stop') { $pdo->prepare("UPDATE st_wa_chat SET optout = 1 WHERE phone = ?")->execute([$phone]); $pdo->prepare("UPDATE st_wa_ln_cust SET status = 'stop', why = 'asked to stop' WHERE phone = ?")->execute([$phone]); }
    if ($action === 'help') { $pdo->prepare("UPDATE st_wa_ln_cust SET status = 'help', why = ? WHERE phone = ?")->execute([mb_substr('pressed: ' . $body, 0, 120), $phone]); try { $pdo->prepare("INSERT IGNORE INTO st_wa_tag (phone, tag) VALUES (?, 'Needs help')")->execute([$phone]); } catch (Throwable $e) { } }
    $tag = trim((string)($rule['tag'] ?? '')); if ($tag !== '') { try { $pdo->prepare("INSERT IGNORE INTO st_wa_tag (phone, tag) VALUES (?, ?)")->execute([$phone, mb_substr($tag, 0, 30)]); } catch (Throwable $e) { } }
    if ($text === '') return; $num = sw_number($pdo); if (!$num) return;      // the customer just wrote, so the 24-hour window is open: a plain text goes free
    [$code, $d] = wa_api('POST', '/' . $num['phone_id'] . '/messages', ['messaging_product' => 'whatsapp', 'to' => sw_to($phone), 'type' => 'text', 'text' => ['body' => $text, 'preview_url' => true]]); $wamid = (string)($d['messages'][0]['id'] ?? '');
    if ($code >= 200 && $code < 300 && $wamid !== '') { try { $pdo->prepare("INSERT INTO st_wa_msg (wamid, phone, dir, mtype, body, status, who, emp_id, ts) VALUES (?, ?, 'out', 'text', ?, 'sent', 'bot', 0, ?)")->execute([$wamid, $phone, $text, date('Y-m-d H:i:s')]); } catch (Throwable $e) { } }
}

function swl_handle(PDO $pdo, string $action, int $me, bool $post): void
{
    if (!swl_tables($pdo)) json_response(['error' => 'The Lineup tables could not be made.'], 500);
    require_once __DIR__ . '/store_wa_tpl.php'; require_once __DIR__ . '/store_wa_camp.php'; swc_tables($pdo);
    $num = sw_number($pdo); if (!$num) json_response(['error' => 'Choose the Store number in Setup first.'], 409); $waba = wa_waba_of($num); $cfg = swl_cfg($pdo);
    if ($action === 'st_wa.ln_get') {
        swl_enrol($pdo, max(1, $cfg['backfill'])); $fam = [];
        foreach ($cfg['steps'] as $s) { $row = ['day' => $s['day'], 'family' => $s['family'], 'on' => $s['on'], 'vars' => $s['vars'], 'langs' => []];
            foreach (['gu', 'hi', 'en'] as $lg) { $t = null; $q = $pdo->prepare("SELECT t.id, t.name, t.category, UPPER(COALESCE(w.status, '')) st FROM wa_templates t LEFT JOIN wa_tpl_waba w ON w.tpl_id = t.id AND w.waba_id = ? WHERE t.name LIKE ? ORDER BY t.id DESC"); $q->execute([$waba, $s['family'] . '_' . $lg . '%']);
                foreach ($q->fetchAll() as $r) { if (!preg_match('/^' . preg_quote($s['family'], '/') . '_' . $lg . '(_v\d+)?$/', (string)$r['name'])) continue; if (!$t || ($r['st'] === 'APPROVED' && $t['st'] !== 'APPROVED')) $t = $r; }
                $row['langs'][$lg] = $t ? ['name' => (string)$t['name'], 'status' => (string)$t['st'] ?: 'DRAFT', 'category' => (string)$t['category']] : null; }
            $fam[] = $row; }
        $cnt = ['active' => 0, 'help' => 0, 'stop' => 0]; foreach ($pdo->query("SELECT status, COUNT(*) n FROM st_wa_ln_cust GROUP BY status")->fetchAll() as $r) $cnt[(string)$r['status']] = (int)$r['n'];
        $q = $pdo->prepare("SELECT COUNT(*) FROM st_wa_ln_log WHERE status = 'sent' AND at >= ?"); $q->execute([date('Y-m-d') . ' 00:00:00']);
        json_response(['on' => $cfg['on'], 'backfill' => $cfg['backfill'], 'steps' => $fam, 'replies' => $cfg['replies'], 'kinds' => swc_kinds(), 'counts' => $cnt, 'sent_today' => (int)$q->fetchColumn(), 'camp' => swc_cfg($pdo)]);
    }
    if ($action === 'st_wa.ln_plan') {      // who would get which step now (nothing is sent)
        swl_enrol($pdo, max(1, $cfg['backfill'])); $due = swl_due($pdo, ['steps' => $cfg['steps']] + $cfg); $by = []; $rows = []; $langs = []; try { foreach ($pdo->query("SELECT phone, lang FROM st_wa_lang")->fetchAll() as $r) $langs[(string)$r['phone']] = (string)$r['lang']; } catch (Throwable $e) { }
        foreach ($due as [$c, $s, $age]) { $t = swl_tpl($pdo, $waba, $s['family'], $langs[$c['phone']] ?? 'gu'); $by[$s['day']] = ($by[$s['day']] ?? ['day' => $s['day'], 'n' => 0, 'ready' => (bool)$t, 'tpl' => $t ? (string)$t['name'] : '']); $by[$s['day']]['n']++;
            if (count($rows) < 200) $rows[] = ['phone' => (string)$c['phone'], 'name' => (string)$c['name'], 'day' => $s['day'], 'age' => $age, 'perfume' => (string)$c['perfume'], 'tpl' => $t ? (string)$t['name'] : '', 'ready' => (bool)$t]; }
        ksort($by); json_response(['total' => count($due), 'by_step' => array_values($by), 'rows' => $rows]);
    }
    if ($action === 'st_wa.ln_report') {
        $o = []; $from = date('Y-m-d H:i:s', time() - max(1, min(365, (int)($_GET['days'] ?? 30))) * 86400);
        $q = $pdo->prepare("SELECT l.day, COUNT(*) sent, COALESCE(SUM(m.status IN ('delivered','read')), 0) delivered, COALESCE(SUM(m.status = 'read'), 0) rd, COALESCE(SUM(m.status = 'failed'), 0) failed,
                COALESCE(SUM(EXISTS (SELECT 1 FROM st_wa_msg x WHERE x.phone = l.phone AND x.dir = 'in' AND x.ts >= l.at AND x.ts < DATE_ADD(l.at, INTERVAL 3 DAY))), 0) replied,
                COALESCE(SUM(EXISTS (SELECT 1 FROM st_bill_people bp JOIN st_bills b ON b.bill_id = bp.bill_id AND b.status = 'ok' WHERE RIGHT(bp.phone, 10) = l.phone AND b.created_at >= l.at AND b.created_at < DATE_ADD(l.at, INTERVAL 15 DAY))), 0) back
                FROM st_wa_ln_log l LEFT JOIN st_wa_msg m ON m.wamid = l.wamid AND l.wamid <> '' WHERE l.status = 'sent' AND l.at >= ? GROUP BY l.day ORDER BY l.day"); $q->execute([$from]);
        foreach ($q->fetchAll() as $r) $o[] = ['day' => (int)$r['day'], 'sent' => (int)$r['sent'], 'delivered' => (int)$r['delivered'], 'read' => (int)$r['rd'], 'failed' => (int)$r['failed'], 'replied' => (int)$r['replied'], 'back' => (int)$r['back']];
        $money = 0; try { $m = $pdo->prepare("SELECT COALESCE(SUM(b.total), 0) FROM st_bills b WHERE b.status = 'ok' AND EXISTS (SELECT 1 FROM st_bill_people bp JOIN st_wa_ln_log l ON l.phone = RIGHT(bp.phone, 10) AND l.status = 'sent' AND l.at >= ? WHERE bp.bill_id = b.bill_id AND bp.person_no = 1 AND b.created_at >= l.at AND b.created_at < DATE_ADD(l.at, INTERVAL 15 DAY))"); $m->execute([$from]); $money = (float)$m->fetchColumn(); } catch (Throwable $e) { }
        $help = $pdo->query("SELECT phone, name, why, updated_at FROM st_wa_ln_cust WHERE status = 'help' ORDER BY updated_at DESC LIMIT 100")->fetchAll();
        json_response(['steps' => $o, 'back_money' => round($money), 'help' => $help]);
    }
    if ($action === 'st_wa.ln_cust') {      // one customer's ladder
        $p = substr(preg_replace('/\D/', '', (string)($_GET['phone'] ?? '')), -10); $q = $pdo->prepare("SELECT * FROM st_wa_ln_cust WHERE phone = ?"); $q->execute([$p]); $c = $q->fetch(); if (!$c) json_response(['error' => 'This number is not in the Lineup (no Store bill in the last ' . $cfg['backfill'] . ' days).'], 404);
        $l = $pdo->prepare("SELECT l.day, l.status, l.err, l.at, t.name tpl, COALESCE(m.status, '') ms FROM st_wa_ln_log l LEFT JOIN wa_templates t ON t.id = l.tpl_id LEFT JOIN st_wa_msg m ON m.wamid = l.wamid AND l.wamid <> '' WHERE l.phone = ? AND l.bill_id = ? ORDER BY l.day"); $l->execute([$p, (int)$c['bill_id']]);
        $age = (int)floor((strtotime(date('Y-m-d')) - strtotime((string)$c['anchor'])) / 86400);
        json_response(['cust' => $c + ['age' => $age], 'log' => $l->fetchAll(), 'steps' => array_map(fn($s) => ['day' => $s['day'], 'on' => $s['on']], $cfg['steps'])]);
    }
    if (!$post) json_response(['error' => 'Method not allowed.'], 405); $in = json_input();
    if ($action === 'st_wa.ln_resume') { $p = substr(preg_replace('/\D/', '', (string)($in['phone'] ?? '')), -10); $pdo->prepare("UPDATE st_wa_ln_cust SET status = 'active', why = '' WHERE phone = ? AND status = 'help'")->execute([$p]); try { $pdo->prepare("DELETE FROM st_wa_tag WHERE phone = ? AND tag = 'Needs help'")->execute([$p]); } catch (Throwable $e) { } json_response(['ok' => true]); }
    if ($action === 'st_wa.ln_save') {
        $o = $cfg; if (isset($in['on'])) $o['on'] = !empty($in['on']); if (isset($in['backfill'])) $o['backfill'] = max(0, min(365, (int)$in['backfill']));
        if (isset($in['steps']) && is_array($in['steps'])) $o['steps'] = array_values(array_map(fn($s) => ['day' => max(1, min(365, (int)($s['day'] ?? 1))), 'family' => mb_substr((string)($s['family'] ?? ''), 0, 120), 'on' => !empty($s['on']), 'vars' => array_values(array_map(fn($k) => isset(swc_kinds()[(string)$k]) ? (string)$k : 'name', (array)($s['vars'] ?? ['name', 'perfume'])))], $in['steps']));
        if (isset($in['replies']) && is_array($in['replies'])) $o['replies'] = array_values(array_filter(array_map(fn($r) => ['match' => mb_substr(trim((string)($r['match'] ?? '')), 0, 40), 'action' => in_array((string)($r['action'] ?? ''), ['text', 'help', 'stop'], true) ? (string)$r['action'] : 'text', 'text' => mb_substr((string)($r['text'] ?? ''), 0, 1000), 'tag' => mb_substr(trim((string)($r['tag'] ?? '')), 0, 30)], $in['replies']), fn($r) => $r['match'] !== ''));
        $pdo->prepare("INSERT INTO st_cs_settings (skey, svalue) VALUES ('swline', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([json_encode($o, JSON_UNESCAPED_UNICODE)]);
        if ($o['on']) st_camp_next(0); json_response(['ok' => true]);
    }
    json_response(['error' => 'Unknown action.'], 404);
}
