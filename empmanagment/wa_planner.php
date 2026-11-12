<?php
/**
 * wa_planner.php — 🗓 CAMPAIGN PLANNER (Campaigns → "Campaign Planner"). Admin / HR.
 *   The day before: add many CSV files (Name, UserNumber), pick a message for each, press Schedule. Every file becomes a normal campaign that starts by itself
 *   at its time (first start time + position × gap). A customer who is in two files gets the message only from the EARLIER file.
 *   Files and choices are kept here (wa_pl_item, setting wa_pl_cfg) so the plan survives closing the page. The campaigns themselves are made by the screen with the
 *   normal wa.camp_save + wa.camp_start (so every check of a normal campaign applies); wa.pl_mark links the item to its campaign.
 *   Actions: wa.pl_get · wa.pl_add · wa.pl_update · wa.pl_cfg · wa.pl_move · wa.pl_del · wa.pl_prep · wa.pl_mark. PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wpl_tables')) {
function wpl_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_pl_item (id INT AUTO_INCREMENT PRIMARY KEY, file_name VARCHAR(160) NOT NULL DEFAULT '', phones MEDIUMTEXT NULL, total_n INT NOT NULL DEFAULT 0, valid_n INT NOT NULL DEFAULT 0, tpl_id INT NOT NULL DEFAULT 0, pos INT NOT NULL DEFAULT 0, camp_id INT NOT NULL DEFAULT 0, status VARCHAR(12) NOT NULL DEFAULT 'draft', start_at DATETIME NULL, created_at DATETIME NULL, KEY k_status (status, pos)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { if (!$pdo->query("SHOW COLUMNS FROM wa_pl_item LIKE 'media_lib_id'")->fetch()) $pdo->exec("ALTER TABLE wa_pl_item ADD COLUMN media_lib_id INT NOT NULL DEFAULT 0"); } catch (PDOException $e) { }
        foreach (['aud_type' => "VARCHAR(60) NOT NULL DEFAULT ''", 'aud_from' => 'DATE NULL', 'aud_to' => 'DATE NULL'] as $col => $def) { try { if (!$pdo->query("SHOW COLUMNS FROM wa_pl_item LIKE '$col'")->fetch()) $pdo->exec("ALTER TABLE wa_pl_item ADD COLUMN $col $def"); } catch (PDOException $e) { } }
        $ok = true;
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
function wpl_cfg(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'wa_pl_cfg', ''), true); if (!is_array($j)) $j = [];
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($j['date'] ?? '')) ? (string)$j['date'] : date('Y-m-d', time() + 86400); if ($date < date('Y-m-d')) $date = date('Y-m-d', time() + 86400);
    $start = preg_match('/^\d{1,2}:\d{2}$/', (string)($j['start'] ?? '')) ? (string)$j['start'] : '10:00';
    return ['date' => $date, 'start' => $start, 'gap' => max(1, min(720, (int)($j['gap'] ?? 60))), 'number_id' => (int)($j['number_id'] ?? 0), 'budget' => max(0, (int)($j['budget'] ?? 50000)), 'rate' => max(10, min(1500, (int)($j['rate'] ?? 120))), 'xf_min' => max(0, min(9, (int)($j['xf_min'] ?? 0))), 'xf_days' => in_array((int)($j['xf_days'] ?? 7), [3, 7, 14, 30], true) ? (int)$j['xf_days'] : 7];
}
function wpl_start_of(array $cfg, int $index): string { $t = strtotime($cfg['date'] . ' ' . $cfg['start'] . ':00'); return date('Y-m-d H:i:s', $t + $index * $cfg['gap'] * 60); }
}

$__wpl = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wpl, 'wa.pl_') === 0) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!wa_is_admin($pdo, $auth)) json_response(['error' => 'Only the owner / admin can use the Campaign Planner.'], 403);
    if (!wpl_tables($pdo)) json_response(['error' => 'Planner storage is not available.'], 500);
    switch ($__wpl) {
        case 'wa.pl_get': {
            $cfg = wpl_cfg($pdo); $rows = $pdo->query("SELECT id, file_name, phones, total_n, valid_n, tpl_id, media_lib_id, pos, camp_id, status, start_at, aud_type, aud_from, aud_to FROM wa_pl_item WHERE status IN ('draft','scheduled') ORDER BY (status = 'scheduled'), pos, id")->fetchAll();
            $seen = []; $seenF = []; $items = []; $draftIdx = 0;
            foreach ($rows as $r) {
                $ph = array_values(array_filter(explode("\n", (string)$r['phones']), fn($x) => $x !== '')); $dup = 0; foreach ($ph as $p0) { if (isset($seen[$p0])) $dup++; else $seen[$p0] = 1; }
                $fz = 0; if ($cfg['xf_min'] > 0 && function_exists('wa_camp_msg_counts')) { $mine0 = []; foreach ($ph as $p1) if (!isset($seenF[$p1])) { $seenF[$p1] = 1; $mine0[] = $p1; } $cF = wa_camp_msg_counts($pdo, $mine0, $cfg['xf_days']); foreach ($mine0 as $p1) if (($cF[$p1] ?? 0) >= $cfg['xf_min']) $fz++; }
                $it = ['id' => (int)$r['id'], 'aud_type' => (string)($r['aud_type'] ?? ''), 'aud_from' => (string)($r['aud_from'] ?? ''), 'aud_to' => (string)($r['aud_to'] ?? ''), 'freq' => $fz, 'file' => (string)$r['file_name'], 'total' => (int)$r['total_n'], 'valid' => (int)$r['valid_n'], 'dup' => $dup, 'send' => count($ph) - $dup - $fz, 'tpl_id' => (int)$r['tpl_id'], 'media_id' => (int)$r['media_lib_id'], 'status' => (string)$r['status'], 'camp_id' => (int)$r['camp_id']];
                if ($r['status'] === 'scheduled') { $it['start_at'] = (string)$r['start_at']; $c = null; if ((int)$r['camp_id'] > 0) { try { $c = wa_camp_row($pdo, (int)$r['camp_id']); } catch (Throwable $e) { } } if ($c) { $cn = wa_camp_counts($pdo, (int)$c['id']); $it['camp_status'] = (string)$c['status']; $it['sent'] = (int)$cn['sent']; $it['failed'] = (int)$cn['failed']; $it['camp_total'] = (int)$cn['total']; } else $it['camp_status'] = 'gone'; }
                else { $it['start_at'] = wpl_start_of($cfg, $draftIdx); $draftIdx++; }
                $items[] = $it;
            }
            $R = function_exists('wab_rates') ? wab_rates($pdo) : ['marketing' => 1.09, 'utility' => 0.15]; $room = 0; try { $caps = wa_number_caps($pdo, wa_camp_health($pdo)); $room = (int)($caps['_port']['left'] ?? 0); } catch (Throwable $e) { }
            $nums = []; try { foreach ($pdo->query("SELECT id, label FROM wa_numbers ORDER BY id")->fetchAll() as $n) $nums[] = ['id' => (int)$n['id'], 'label' => (string)$n['label']]; } catch (Throwable $e) { }
            json_response(['ok' => true, 'now' => date('Y-m-d H:i:s'), 'today' => date('Y-m-d'), 'cfg' => $cfg, 'items' => $items, 'price' => (float)$R['marketing'], 'price_u' => (float)$R['utility'], 'room' => $room, 'numbers' => $nums]);
        }
        case 'wa.pl_add': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $name = mb_substr(trim((string)($in['name'] ?? 'file.csv')), 0, 160); $text = (string)($in['text'] ?? '');
            if ($text === '' || strlen($text) > 6000000) json_response(['error' => 'The file is empty or too big.'], 422);
            $total = max(0, count(preg_split('/\r?\n/', trim($text))) - 1); $ph = wa_paste_phones($text); if (!$ph) json_response(['error' => 'No valid phone numbers found in ' . $name . '.'], 422);
            $pos = (int)$pdo->query("SELECT COALESCE(MAX(pos), 0) + 1 FROM wa_pl_item WHERE status = 'draft'")->fetchColumn();
            $pdo->prepare("INSERT INTO wa_pl_item (file_name, phones, total_n, valid_n, pos, created_at) VALUES (?, ?, ?, ?, ?, ?)")->execute([$name, implode("\n", $ph), $total, count($ph), $pos, date('Y-m-d H:i:s')]);
            json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'valid' => count($ph)]);
        }
        case 'wa.pl_update': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $id = (int)($in['id'] ?? 0);
            if (array_key_exists('tpl_id', $in)) $pdo->prepare("UPDATE wa_pl_item SET tpl_id = ? WHERE id = ? AND status = 'draft'")->execute([max(0, (int)$in['tpl_id']), $id]);
            if (array_key_exists('media_lib_id', $in)) $pdo->prepare("UPDATE wa_pl_item SET media_lib_id = ? WHERE id = ? AND status = 'draft'")->execute([max(0, (int)$in['media_lib_id']), $id]);
            if (array_key_exists('aud_type', $in)) { $t0 = trim(mb_substr(preg_replace('/\s+/u', ' ', (string)$in['aud_type']), 0, 60)); if ($t0 !== '' && function_exists('wa_aud_canon')) { try { $t0 = wa_aud_canon($pdo, $t0); } catch (Throwable $e) { } } $pdo->prepare("UPDATE wa_pl_item SET aud_type = ? WHERE id = ? AND status = 'draft'")->execute([$t0, $id]); }
            foreach (['aud_from', 'aud_to'] as $k0) if (array_key_exists($k0, $in)) { $v0 = (string)$in[$k0]; $v0 = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v0) && strtotime($v0) ? $v0 : null; $pdo->prepare("UPDATE wa_pl_item SET $k0 = ? WHERE id = ? AND status = 'draft'")->execute([$v0, $id]); }
            json_response(['ok' => true]);
        }
        case 'wa.pl_cfg': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $x = (array)($in['cfg'] ?? []);
            setting_set($pdo, 'wa_pl_cfg', json_encode(['date' => (string)($x['date'] ?? ''), 'start' => (string)($x['start'] ?? '10:00'), 'gap' => (int)($x['gap'] ?? 60), 'number_id' => (int)($x['number_id'] ?? 0), 'budget' => (int)($x['budget'] ?? 0), 'rate' => (int)($x['rate'] ?? 120), 'xf_min' => (int)($x['xf_min'] ?? 0), 'xf_days' => (int)($x['xf_days'] ?? 7)])); json_response(['ok' => true, 'cfg' => wpl_cfg($pdo)]);
        }
        case 'wa.pl_move': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $id = (int)($in['id'] ?? 0); $dir = (int)($in['dir'] ?? 0) < 0 ? -1 : 1;
            $all = $pdo->query("SELECT id FROM wa_pl_item WHERE status = 'draft' ORDER BY pos, id")->fetchAll(PDO::FETCH_COLUMN); $ids = array_map('intval', $all); $i = array_search($id, $ids, true);
            if ($i !== false && isset($ids[$i + $dir])) { $t = $ids[$i]; $ids[$i] = $ids[$i + $dir]; $ids[$i + $dir] = $t; $u = $pdo->prepare("UPDATE wa_pl_item SET pos = ? WHERE id = ?"); foreach ($ids as $k => $v) $u->execute([$k + 1, $v]); }
            json_response(['ok' => true]);
        }
        case 'wa.pl_del': { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $pdo->prepare("DELETE FROM wa_pl_item WHERE id = ? AND status = 'draft'")->execute([(int)($in['id'] ?? 0)]); json_response(['ok' => true]); }
        case 'wa.pl_prep': {   // what the screen needs to make ONE campaign: its customers (without the ones already in an earlier file) and its start time
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $id = (int)($in['id'] ?? 0); $cfg = wpl_cfg($pdo);
            $rows = $pdo->query("SELECT id, file_name, phones, tpl_id, media_lib_id, aud_type, aud_from, aud_to FROM wa_pl_item WHERE status = 'draft' ORDER BY pos, id")->fetchAll(); $seen = []; $idx = 0;
            // customers already given to an EARLIER campaign of the same plan day (scheduled a moment ago) are not given again
            try { $sq = $pdo->prepare("SELECT phones FROM wa_pl_item WHERE status = 'scheduled' AND start_at IS NOT NULL AND DATE(start_at) = ?"); $sq->execute([$cfg['date']]); foreach ($sq->fetchAll(PDO::FETCH_COLUMN) as $pp) foreach (explode("\n", (string)$pp) as $p0) if ($p0 !== '') $seen[$p0] = 1; } catch (Throwable $e) { }
            foreach ($rows as $r) {
                $ph = array_values(array_filter(explode("\n", (string)$r['phones']), fn($x) => $x !== '')); $mine = []; foreach ($ph as $p0) if (!isset($seen[$p0])) { $seen[$p0] = 1; $mine[] = $p0; }
                if ((int)$r['id'] === $id && $cfg['xf_min'] > 0 && $mine && function_exists('wa_camp_msg_counts')) { $cF = wa_camp_msg_counts($pdo, $mine, $cfg['xf_days']); $mine = array_values(array_filter($mine, fn($p9) => ($cF[$p9] ?? 0) < $cfg['xf_min'])); }
                if ((int)$r['id'] === $id) { if ((int)$r['tpl_id'] <= 0) json_response(['error' => 'Pick a message for ' . $r['file_name'] . ' first.'], 422); if (!$mine) json_response(['error' => $r['file_name'] . ': every customer is already in an earlier file.'], 422);
                    json_response(['ok' => true, 'aud_type' => (string)($r['aud_type'] ?? ''), 'aud_from' => (string)($r['aud_from'] ?? ''), 'aud_to' => (string)($r['aud_to'] ?? ''), 'name' => preg_replace('/\.csv$/i', '', (string)$r['file_name']) . ' | ' . date('d M', strtotime($cfg['date'])), 'tpl_id' => (int)$r['tpl_id'], 'media_lib_id' => (int)$r['media_lib_id'], 'paste' => implode("\n", $mine), 'n' => count($mine), 'start_at' => wpl_start_of($cfg, isset($in['index']) ? max(0, (int)$in['index']) : $idx), 'number_id' => $cfg['number_id'], 'rate' => $cfg['rate']]); }
                $idx++;
            }
            json_response(['error' => 'File not found.'], 404);
        }
        case 'wa.pl_restore': {   // a scheduled campaign was cancelled: its file goes back into the plan (with its message), ready to be planned again
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $np = (int)$pdo->query("SELECT COALESCE(MAX(pos), 0) + 1 FROM wa_pl_item WHERE status = 'draft'")->fetchColumn();
            $pdo->prepare("UPDATE wa_pl_item SET status = 'draft', camp_id = 0, start_at = NULL, pos = ? WHERE id = ? AND status = 'scheduled'")->execute([$np, (int)($in['id'] ?? 0)]); json_response(['ok' => true]);
        }
        case 'wa.pl_mark': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $pdo->prepare("UPDATE wa_pl_item SET status = 'scheduled', camp_id = ?, start_at = ? WHERE id = ?")->execute([(int)($in['camp_id'] ?? 0), (string)($in['start_at'] ?? ''), (int)($in['id'] ?? 0)]); json_response(['ok' => true]);
        }
    }
}
