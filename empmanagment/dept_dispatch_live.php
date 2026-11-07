<?php
/* Dispatch: LIVE tracking.  Loaded by dept_dispatch.php (functions only; the routes are in dept_dispatch.php).  PHP 7.4-safe.

   Three ways a parcel gets live news, all ending in the same place (dp_live_apply):
     1. WEBHOOK  Shiprocket pushes every courier scan to  .../empmanagment/api.php?action=dp.dsp_hook  (header x-api-key = the token shown on the Dispatch page).
     2. ON TAP   when somebody opens the "Track" drawer, the HRMS asks Shiprocket for that one AWB (at most once a minute per parcel).
     3. SAFETY NET  every tick (5 minutes) the parcels in transit that have not been heard of for 20 minutes are asked again (40 per tick, oldest first).
   A parcel only moves forward (tracking -> in transit -> delivered); RTO / cancelled are taken as told - the same rule as the 15-minute order read.
   Every scan is kept in dp_dsp_events so the drawer can show the full journey. Nothing here deletes a parcel. */

if (!defined('DP_LIVE_LOADED')) define('DP_LIVE_LOADED', 1);

/* ============================== tables ============================== */
function dp_live_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    if ((string)dp_get('live_schema', '') === 'v1') return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_dsp_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, parcel_id INT NOT NULL, at DATETIME NOT NULL, label VARCHAR(60) NOT NULL DEFAULT '', activity VARCHAR(255) NOT NULL DEFAULT '',
            location VARCHAR(120) NOT NULL DEFAULT '', src VARCHAR(8) NOT NULL DEFAULT '', sig CHAR(32) NOT NULL, got_at DATETIME NOT NULL,
            UNIQUE KEY uq_ev (parcel_id, sig), INDEX idx_ev (parcel_id, at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('Dispatch live table: ' . $e->getMessage()); return; }
    foreach (["ALTER TABLE dp_dsp_parcels ADD COLUMN live_at DATETIME NULL", "ALTER TABLE dp_dsp_parcels ADD COLUMN live_src VARCHAR(8) NULL", "ALTER TABLE dp_dsp_parcels ADD COLUMN live_try DATETIME NULL",
              "ALTER TABLE dp_dsp_parcels ADD COLUMN etd DATE NULL", "ALTER TABLE dp_dsp_parcels ADD INDEX idx_dsp_live (status, live_try)"] as $a) { try { $pdo->exec($a); } catch (Throwable $e) {} }
    dp_put('live_schema', 'v1');
}

/* ============================== small helpers ============================== */
/** Shiprocket writes times as "23 05 2023 11:43:52" (webhook) or "2023-05-23 11:43:52" (scans). Returns 'Y-m-d H:i:s' or ''. */
function dp_live_ts($s) {
    $s = trim((string)$s); if ($s === '') return '';
    if (preg_match('/^(\d{2})[ \-\/](\d{2})[ \-\/](\d{4})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1] . ' ' . $m[4] . ':' . $m[5] . ':' . (isset($m[6]) && $m[6] !== '' ? $m[6] : '00');
    $t = strtotime($s); return $t ? date('Y-m-d H:i:s', $t) : '';
}
/** scans as Shiprocket sends them -> [['at','label','activity','location']] */
function dp_live_scans($arr) {
    $out = []; if (!is_array($arr)) return $out;
    foreach ($arr as $s) {
        if (!is_array($s)) continue;
        $at = dp_live_ts($s['date'] ?? ($s['time'] ?? '')); if ($at === '') continue;
        $label = trim((string)($s['sr-status-label'] ?? ($s['sr_status_label'] ?? ($s['status'] ?? ''))));
        $out[] = ['at' => $at, 'label' => mb_substr($label, 0, 60), 'activity' => mb_substr(trim((string)($s['activity'] ?? ($s['remarks'] ?? ''))), 0, 255), 'location' => mb_substr(trim((string)($s['location'] ?? '')), 0, 120)];
    }
    return $out;
}
function dp_live_store_events(PDO $pdo, $pid, array $ev, $src) {
    if (!$ev) return 0;
    $st = $pdo->prepare("INSERT IGNORE INTO dp_dsp_events (parcel_id, at, label, activity, location, src, sig, got_at) VALUES (?,?,?,?,?,?,?,?)"); $n = 0; $now = bd_now();
    foreach (array_slice($ev, 0, 200) as $e) { $st->execute([(int)$pid, $e['at'], $e['label'], $e['activity'], $e['location'], $src, md5($e['at'] . '|' . $e['label'] . '|' . $e['activity']), $now]); $n += $st->rowCount(); }
    return $n;
}
function dp_live_key() {
    $k = (string)dp_get('hook_key', ''); if (strlen($k) >= 24) return $k;
    try { $k = bin2hex(random_bytes(16)); } catch (Throwable $e) { $k = md5(uniqid('', true) . mt_rand()) . substr(md5(mt_rand()), 0, 8); }
    dp_put('hook_key', $k); return $k;
}

/* ============================== one piece of live news -> the parcel ============================== */
/** $d = ['awb','courier','status','scans'=>[...],'etd'] . Returns what changed ('' = only the live time moved). */
function dp_live_apply(PDO $pdo, array $p, array $d, $src) {
    dp_live_ensure($pdo);
    $awb = trim((string)($d['awb'] ?? '')); if ($awb !== '' && !dp_dsp_valid_track($awb)) $awb = '';
    $scans = isset($d['scans']) && is_array($d['scans']) ? $d['scans'] : [];
    $st = trim((string)($d['status'] ?? ''));
    if ($st === '' && $scans) { $last = $scans[0]; foreach ($scans as $s) if ($s['at'] > $last['at']) $last = $s; $st = $last['label'] !== '' ? $last['label'] : $last['activity']; }
    $hasAwb = $awb !== '' || (string)$p['tracking_no'] !== '';
    $new = $st !== '' ? dp_dsp_sr_map($st, $hasAwb) : '';
    $did = dp_dsp_apply($pdo, $p, $awb, (string)($d['courier'] ?? ''), $new, 'Shiprocket live', false);
    $now = bd_now();
    if ($st !== '' && strcasecmp($st, (string)($p['sr_status'] ?? '')) !== 0) {
        $pdo->prepare("UPDATE dp_dsp_parcels SET sr_status = ?, sr_at = ? WHERE id = ?")->execute([mb_substr($st, 0, 40), $now, $p['id']]);
        if ($did === '') $did = 'status text';
    }
    dp_live_store_events($pdo, (int)$p['id'], $scans, $src);
    $etd = dp_live_ts($d['etd'] ?? ''); $etd = $etd !== '' ? substr($etd, 0, 10) : null;
    $pdo->prepare("UPDATE dp_dsp_parcels SET live_at = ?, live_src = ?, live_try = ?, etd = COALESCE(?, etd) WHERE id = ?")->execute([$now, $src, date('Y-m-d H:i:s', time() + 1200), $etd, $p['id']]);
    if (function_exists('wa_ord_after_apply')) { try { wa_ord_after_apply($pdo, (int)$p['id']); } catch (Throwable $e) { } }   // NDR / RTO / lost / damaged -> a Needs attention case (wa_orders.php)
    return $did;
}

/* ============================== 1. the webhook ============================== */
function dp_live_find(PDO $pdo, $awb, $orderId) {
    if ($awb !== '') {
        $st = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE tracking_no = ? OR pend_track = ? ORDER BY created_at DESC LIMIT 1"); $st->execute([$awb, $awb]); $r = $st->fetch();
        if ($r) return $r;
    }
    $o = trim((string)$orderId); $try = [];
    if ($o !== '') { $try[] = $o; if (strpos($o, '_') !== false) $try[] = substr($o, 0, strpos($o, '_')); }
    foreach ($try as $c) { $rows = dp_dsp_find_by_name($pdo, $c); if (count($rows) === 1) return $rows[0]; }
    return null;
}
/** The route. Shiprocket expects HTTP 200 and nothing else; a wrong token is 401. Never returns. */
function dp_live_hook(PDO $pdo) {
    dp_live_ensure($pdo);
    $got = (string)($_SERVER['HTTP_X_API_KEY'] ?? ''); if ($got === '') $got = (string)($_GET['key'] ?? '');
    if ($got === '' || !hash_equals(dp_live_key(), $got)) json_response(['error' => 'Wrong or missing token.'], 401);
    $j = json_decode((string)file_get_contents('php://input'), true);
    dp_put('hook_last_ts', (string)time());
    $day = date('Y-m-d'); if ((string)dp_get('hook_day', '') !== $day) { dp_put('hook_day', $day); dp_put('hook_today', '0'); }
    if (!is_array($j) || !$j) json_response(['ok' => true, 'note' => 'nothing to do']);
    $list = isset($j[0]) && is_array($j[0]) ? $j : [$j];
    $upd = 0; $same = 0; $miss = [];
    foreach (array_slice($list, 0, 50) as $x) {
        if (!is_array($x)) continue;
        $awb = trim((string)($x['awb'] ?? ($x['awb_code'] ?? ''))); $oid = (string)($x['order_id'] ?? ($x['channel_order_id'] ?? ''));
        $p = dp_live_find($pdo, $awb, $oid);
        if (!$p) { if (count($miss) < 10) $miss[] = ['awb' => $awb, 'order' => $oid, 'at' => date('j M g:i A')]; continue; }
        $st = (string)($x['current_status'] ?? ($x['shipment_status'] ?? ''));
        $did = dp_live_apply($pdo, $p, ['awb' => $awb, 'courier' => (string)($x['courier_name'] ?? ''), 'status' => $st, 'scans' => dp_live_scans($x['scans'] ?? []), 'etd' => (string)($x['etd'] ?? '')], 'hook');
        if ($did !== '') $upd++; else $same++;
    }
    dp_put('hook_today', (string)((int)dp_get('hook_today', '0') + $upd + $same)); dp_put('hook_total', (string)((int)dp_get('hook_total', '0') + $upd + $same));
    if ($miss) { $old = json_decode((string)dp_get('hook_miss', '[]'), true); $old = is_array($old) ? $old : []; dp_put('hook_miss', json_encode(array_slice(array_merge($miss, $old), 0, 10))); }
    if ($upd > 0) bd_log('dispatch', 'live', $upd . ' parcel' . ($upd === 1 ? '' : 's') . ' updated by Shiprocket (live)', 'Shiprocket');
    json_response(['ok' => true, 'updated' => $upd, 'unchanged' => $same, 'not_found' => count($miss)]);
}

/* ============================== 2. one AWB, asked of Shiprocket ============================== */
/** returns [ok, error text, what changed] */
function dp_live_fetch(PDO $pdo, array $p) {
    $awb = trim((string)$p['tracking_no']); if ($awb === '') return [false, 'This parcel has no tracking number yet.', ''];
    if (!dp_dsp_sr_cfg()['ok']) return [false, 'Shiprocket login is not saved (Dispatch page → Shiprocket box).', ''];
    list($j, $e) = dp_dsp_sr_get('/v1/external/courier/track/awb/' . rawurlencode($awb), []);
    if (!$j) return [false, $e, ''];
    $td = isset($j['tracking_data']) && is_array($j['tracking_data']) ? $j['tracking_data'] : [];
    $acts = dp_live_scans($td['shipment_track_activities'] ?? []);
    $tr = isset($td['shipment_track'][0]) && is_array($td['shipment_track'][0]) ? $td['shipment_track'][0] : [];
    if (!$acts && !$tr) return [false, trim((string)($td['error'] ?? '')) !== '' ? mb_substr((string)$td['error'], 0, 160) : 'Shiprocket has no scans for this tracking number yet (the courier has not scanned it, or it was not booked through Shiprocket).', ''];
    $did = dp_live_apply($pdo, $p, ['courier' => (string)($tr['courier_name'] ?? ''), 'status' => (string)($tr['current_status'] ?? ''), 'scans' => $acts, 'etd' => (string)($td['etd'] ?? ($tr['edd'] ?? ''))], 'api');
    return [true, '', $did];
}
/** the drawer's data: stored scans, refreshed from Shiprocket when older than a minute (or when forced) */
function dp_live_view(PDO $pdo, $id, $force, $now) {
    dp_live_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $st->execute([(int)$id]); $p = $st->fetch();
    if (!$p) json_response(['error' => 'Parcel not found.'], 404);
    $note = ''; $fetched = false;
    $age = !empty($p['live_at']) ? $now - strtotime((string)$p['live_at']) : 999999;
    if ((string)$p['tracking_no'] !== '' && ($force ? $age > 20 : $age > 60)) {
        list($ok, $err) = dp_live_fetch($pdo, $p);
        if ($ok) { $fetched = true; $st->execute([(int)$id]); $p = $st->fetch(); }
        else { $note = $err; $pdo->prepare("UPDATE dp_dsp_parcels SET live_try = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', $now + 60), $p['id']]); }
    }
    $q = $pdo->prepare("SELECT at, label, activity, location, src FROM dp_dsp_events WHERE parcel_id = ? ORDER BY at DESC, id DESC LIMIT 80"); $q->execute([(int)$id]);
    $ev = array_map(function ($r) { return ['at' => substr((string)$r['at'], 0, 16), 'label' => (string)$r['label'], 'activity' => (string)$r['activity'], 'location' => (string)$r['location'], 'src' => (string)$r['src']]; }, $q->fetchAll());
    $lk = trim((string)($p['track_link'] ?? '')); if ($lk === '' && (string)$p['tracking_no'] !== '') $lk = dp_dsp_link_for($pdo, (string)$p['courier'], (string)$p['tracking_no']);
    return ['ok' => true, 'fetched' => $fetched, 'note' => $note, 'events' => $ev,
        'p' => ['id' => (int)$p['id'], 'order' => (string)$p['order_name'], 'customer' => (string)$p['customer'], 'phone' => (string)$p['phone'], 'store' => (string)$p['store_key'], 'tracking_no' => (string)$p['tracking_no'],
            'courier' => (string)$p['courier'], 'status' => (string)$p['status'], 'sr_status' => (string)($p['sr_status'] ?? ''), 'created' => substr((string)$p['created_at'], 0, 16), 'link' => $lk,
            'etd' => !empty($p['etd']) ? (string)$p['etd'] : '', 'live_at' => !empty($p['live_at']) ? substr((string)$p['live_at'], 0, 16) : '', 'live_src' => (string)($p['live_src'] ?? ''), 'delivered_at' => !empty($p['delivered_at']) ? substr((string)$p['delivered_at'], 0, 16) : '']];
}

/* ============================== 3. the safety net (called by the 5-minute tick) ============================== */
function dp_live_tick(PDO $pdo, $now) {
    if (!function_exists('dp_dsp_sr_cfg') || !dp_dsp_sr_cfg()['ok']) return ['skipped' => 'no Shiprocket login'];
    dp_live_ensure($pdo);
    if ($now - (int)dp_get('live_tick_ts', '0') < 240) return ['skipped' => 'ran a moment ago'];
    dp_put('live_tick_ts', (string)$now);
    $nowS = date('Y-m-d H:i:s', $now);
    $st = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status IN ('tracked','transit') AND tracking_no IS NOT NULL AND tracking_no <> '' AND created_at >= ? AND (live_try IS NULL OR live_try <= ?)
        ORDER BY (live_try IS NULL) DESC, live_try ASC LIMIT 40");
    $st->execute([date('Y-m-d', $now - 45 * 86400), $nowS]); $rows = $st->fetchAll();
    $t0 = time(); $ok = 0; $bad = 0; $upd = 0; $err = '';
    foreach ($rows as $p) {
        if (time() - $t0 > 22) break;
        list($good, $e, $did) = dp_live_fetch($pdo, $p);
        if ($good) { $ok++; if ($did !== '') $upd++; }
        else {
            $bad++; $err = $e;
            $pdo->prepare("UPDATE dp_dsp_parcels SET live_try = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', $now + 6 * 3600), $p['id']]);   // not in Shiprocket / no scans: try again in 6 hours
            if (stripos($e, 'too many') !== false) { dp_put('live_tick_ts', (string)($now + 600)); break; }
        }
    }
    dp_put('live_last', json_encode(['ts' => $now, 'ok' => $ok, 'bad' => $bad, 'upd' => $upd, 'err' => mb_substr($err, 0, 140)]));
    if ((string)dp_get('live_clean_day', '') !== date('Y-m-d', $now)) { dp_put('live_clean_day', date('Y-m-d', $now)); try { $pdo->prepare("DELETE FROM dp_dsp_events WHERE at < ?")->execute([date('Y-m-d', $now - 100 * 86400)]); } catch (Throwable $e) {} }
    return ['asked' => $ok + $bad, 'ok' => $ok, 'updated' => $upd, 'no_scans' => $bad];
}

/* ============================== what the Dispatch page shows about it ============================== */
/** no secrets: safe for anybody on the page */
function dp_live_stats() {
    $ts = (int)dp_get('hook_last_ts', '0'); $lv = json_decode((string)dp_get('live_last', ''), true); $lv = is_array($lv) ? $lv : null;
    return ['hook_ts' => $ts, 'hook_at' => $ts ? date('D j M, g:i A', $ts) : '', 'hook_today' => (string)dp_get('hook_day', '') === date('Y-m-d') ? (int)dp_get('hook_today', '0') : 0, 'hook_total' => (int)dp_get('hook_total', '0'),
        'tick_at' => $lv ? date('D j M, g:i A', (int)$lv['ts']) : '', 'tick_ok' => $lv ? (int)$lv['ok'] : 0, 'tick_bad' => $lv ? (int)$lv['bad'] : 0, 'tick_err' => $lv ? (string)$lv['err'] : ''];
}
/** Admin only: the address and token to paste into Shiprocket */
function dp_live_info(PDO $pdo) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $url = ($https ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'your-site') . (string)preg_replace('~/[^/]*$~', '', (string)($_SERVER['SCRIPT_NAME'] ?? '/empmanagment/api.php')) . '/api.php?action=dp.dsp_hook';
    $miss = json_decode((string)dp_get('hook_miss', '[]'), true);
    return ['ok' => true, 'url' => $url, 'token' => dp_live_key(), 'url_ok' => !preg_match('/shiprocket|kartrocket|sr|kr/i', $url), 'unmatched' => is_array($miss) ? $miss : []] + dp_live_stats();
}
