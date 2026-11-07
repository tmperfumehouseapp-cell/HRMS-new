<?php
/**
 * dept_dispatch.php — "Parcels and tracking" department (loaded by dept.php; actions that start with "dp.dsp_").
 *
 * Two promises are watched for every order:
 *   1. a tracking number within 48 hours of the order time   (rule "tracking_48h", seat dispatch.tracking)
 *   2. the parcel in transit by the end of day 4              (rule "transit_day4", seat dispatch.lead)
 * Sundays are not counted. Cancelled, RTO and delivered orders are left out.
 *
 * Where the data comes from:
 *   - orders:           the shop_orders cache of the HRMS (last 14 days), cancelled orders are taken from order_cancels
 *   - tracking numbers: typed by the tracking person, pasted in bulk, or pushed by a courier script to dp.dsp_feed (?key = tick key)
 * Untraceable parcels: one owner (seat dispatch.untraceable, backup dispatch.untraceable_backup) writes a remark on every one, every day by 6 PM (rule dsp_untr_remark).
 * Nothing is penalised, warned or put in My Day until the topic "Parcels and tracking" is made live in Boss > Questions.
 * PHP 7.4 safe. Own tables: dp_dsp_parcels.
 */

/* ============================== clock maths (pure functions, unit-tested) ============================== */

/** end of the 48 working hours: 48 h from the order time, Sundays not counted (a Sunday adds 24 h). Returns a unix time. */
/** THE two limits. One setting for the whole system: the Boss task "A tracking ID within N hours" and this page both read and write these. */
function dp_dsp_track_hours() { $h = function_exists('dp_get') ? (int)dp_get('dsp_track_hours', '48') : 48; return $h >= 12 && $h <= 240 ? $h : 48; }
/** the courier pickup wait: hours a parcel with a tracking ID may wait for the courier's first scan (12..240, default 48) */
function dp_dsp_pick_hours() { $h = function_exists('dp_get') ? (int)dp_get('dsp_pick_hours', '48') : 48; return $h >= 12 && $h <= 240 ? $h : 48; }
function dp_dsp_transit_days() { $d = function_exists('dp_get') ? (int)dp_get('dsp_transit_days', '4') : 4; return $d >= 2 && $d <= 10 ? $d : 4; }
function dp_dsp_due48($createdTs, $hours = null) {
    if ($hours === null) $hours = dp_dsp_track_hours();
    $t = (int)$createdTs; $left = (int)$hours * 3600; $guard = 0;
    while ($guard++ < 40) {
        $mid = strtotime(date('Y-m-d', $t) . ' +1 day');
        if ((int)date('N', $t) === 7) { $t = $mid; continue; }
        $avail = $mid - $t;
        if ($avail >= $left) return $t + $left;
        $left -= $avail; $t = $mid;
    }
    return $t;
}
/** the calendar date (Y-m-d) of day N of a parcel. Day 1 = order date; Sundays are not days (an order placed on a Sunday starts on Monday). */
function dp_dsp_day_n($createdTs, $n = 4) {
    $d = date('Y-m-d', (int)$createdTs);
    if ((int)date('N', strtotime($d)) === 7) $d = date('Y-m-d', strtotime($d . ' +1 day'));
    $count = 1;
    while ($count < $n) { $d = date('Y-m-d', strtotime($d . ' +1 day')); if ((int)date('N', strtotime($d)) !== 7) $count++; }
    return $d;
}
/** the in-transit deadline: midnight at the end of the last day (day 4 unless the limit was changed). */
function dp_dsp_due4($createdTs, $days = null) { if ($days === null) $days = dp_dsp_transit_days(); return strtotime(dp_dsp_day_n($createdTs, (int)$days) . ' +1 day'); }
/** "9 h left" / "late by 1 d 3 h" */
function dp_dsp_left_label($due, $now) {
    $d = (int)$due - (int)$now; $late = $d < 0; $s = abs($d);
    $days = (int)floor($s / 86400); $h = (int)floor(($s % 86400) / 3600); $m = (int)floor(($s % 3600) / 60);
    $txt = $days > 0 ? $days . ' d ' . $h . ' h' : ($h > 0 ? $h . ' h' . ($h < 4 ? ' ' . $m . ' m' : '') : max(1, $m) . ' m');
    return $late ? 'late by ' . $txt : $txt . ' left';
}
function dp_dsp_map_status($s) {
    $s = strtolower(trim((string)$s)); if ($s === '') return '';
    if (strpos($s, 'rto') !== false || strpos($s, 'return') !== false) return 'rto';
    if (strpos($s, 'cancel') !== false) return 'cancelled';
    if (strpos($s, 'deliver') !== false && strpos($s, 'out for') === false && strpos($s, 'undeliver') === false && strpos($s, 'not deliver') === false) return 'delivered';
    if (strpos($s, 'transit') !== false || strpos($s, 'ship') !== false || strpos($s, 'dispatch') !== false || strpos($s, 'out for') !== false || strpos($s, 'picked') !== false) return 'transit';
    if (in_array($s, ['transit', 'tracked', 'placed', 'delivered', 'rto'], true)) return $s;
    return 'tracked';
}
function dp_dsp_norm_order($s) { return strtolower(ltrim(trim((string)$s), '#')); }

if (!isset($pdo, $action) || !defined('DP_LOADED')) { return; }
if (is_file(__DIR__ . '/dept_dispatch_more.php')) require_once __DIR__ . '/dept_dispatch_more.php';   // report, Shiprocket, problems
if (is_file(__DIR__ . '/dept_dispatch_live.php')) require_once __DIR__ . '/dept_dispatch_live.php';   // live tracking: webhook, scan timeline, 5-minute safety net
if (is_file(__DIR__ . '/order_facts.php')) require_once __DIR__ . '/order_facts.php';   // one row per order: payment, who took it, commission, Shiprocket, Finance
if (is_file(__DIR__ . '/wa_orders.php')) require_once __DIR__ . '/wa_orders.php';   // "Needs attention" cases (delivery problems from WhatsApp chats and from the courier status)
if (is_file(__DIR__ . '/order_hub.php')) require_once __DIR__ . '/order_hub.php';   // the big Order + Customer popup and the Customers list (order_hub.php)

/* ============================== tables ============================== */
function dp_dsp_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    $flag = sys_get_temp_dir() . '/hrms_dp_dsp_v4_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) { try { $pdo->query("SELECT pend_track, pend_courier, pend_link, pend_by, pend_at, track_link, manual, job_at, t_man, t_g3, t_un FROM dp_dsp_parcels LIMIT 0"); $pdo->query("SELECT 1 FROM dp_dsp_couriers LIMIT 0"); $pdo->query("SELECT for_date, by_emp FROM dp_dsp_untr LIMIT 0"); $pdo->query("SELECT 1 FROM dp_dsp_untr_case LIMIT 0"); $pdo->query("SELECT 1 FROM dp_dsp_untr_miss LIMIT 0"); $pdo->query("SELECT 1 FROM dp_dsp_untr_day LIMIT 0"); return; } catch (Throwable $e) {} }
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_dsp_parcels (
        id INT AUTO_INCREMENT PRIMARY KEY, store_key VARCHAR(20) NOT NULL, shopify_id BIGINT NOT NULL, order_name VARCHAR(40) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL, customer VARCHAR(120) NULL, phone VARCHAR(40) NULL,
        tracking_no VARCHAR(60) NULL, courier VARCHAR(40) NULL, status VARCHAR(10) NOT NULL DEFAULT 'placed',
        tracked_at DATETIME NULL, tracked_by VARCHAR(80) NULL, transit_at DATETIME NULL,
        due48 DATETIME NOT NULL, due4 DATETIME NOT NULL,
        t48 TINYINT NOT NULL DEFAULT 0, t4 TINYINT NOT NULL DEFAULT 0, warn36 TINYINT NOT NULL DEFAULT 0, warn44 TINYINT NOT NULL DEFAULT 0,
        warn_d3 TINYINT NOT NULL DEFAULT 0, warn_d4a TINYINT NOT NULL DEFAULT 0, warn_d4b TINYINT NOT NULL DEFAULT 0, shop_fulfil VARCHAR(30) NULL,
        synced_at DATETIME NULL,
        UNIQUE KEY uq_parcel (store_key, shopify_id), INDEX (status, due48), INDEX (status, due4), INDEX (order_name), INDEX (tracking_no), INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (["ALTER TABLE dp_dsp_parcels ADD COLUMN sr_status VARCHAR(40) NULL", "ALTER TABLE dp_dsp_parcels ADD COLUMN sr_at DATETIME NULL", "ALTER TABLE dp_dsp_parcels ADD COLUMN delivered_at DATETIME NULL",
              "ALTER TABLE dp_dsp_parcels ADD INDEX idx_dsp_tracked (tracked_at)", "ALTER TABLE dp_dsp_parcels ADD INDEX idx_dsp_transit (transit_at)",
              // v3: tracking IDs typed by hand (wait for Admin), the courier link, the Boss job time and the "already fined" flags of the three new penalties
              "ALTER TABLE dp_dsp_parcels ADD COLUMN pend_track VARCHAR(60) NULL", "ALTER TABLE dp_dsp_parcels ADD COLUMN pend_courier VARCHAR(40) NULL", "ALTER TABLE dp_dsp_parcels ADD COLUMN pend_link VARCHAR(300) NULL",
              "ALTER TABLE dp_dsp_parcels ADD COLUMN pend_by VARCHAR(80) NULL", "ALTER TABLE dp_dsp_parcels ADD COLUMN pend_at DATETIME NULL", "ALTER TABLE dp_dsp_parcels ADD COLUMN track_link VARCHAR(300) NULL",
              "ALTER TABLE dp_dsp_parcels ADD COLUMN manual TINYINT NOT NULL DEFAULT 0", "ALTER TABLE dp_dsp_parcels ADD COLUMN job_at DATETIME NULL",
              "ALTER TABLE dp_dsp_parcels ADD COLUMN t_man TINYINT NOT NULL DEFAULT 0", "ALTER TABLE dp_dsp_parcels ADD COLUMN t_g3 TINYINT NOT NULL DEFAULT 0", "ALTER TABLE dp_dsp_parcels ADD COLUMN t_un TINYINT NOT NULL DEFAULT 0",
              "ALTER TABLE dp_dsp_parcels ADD INDEX idx_dsp_pend (pend_at)"] as $__a) { try { $pdo->exec($__a); } catch (Throwable $e) {} }
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_dsp_couriers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(40) NOT NULL, link VARCHAR(300) NULL, active TINYINT NOT NULL DEFAULT 1, sort_no INT NOT NULL DEFAULT 0, UNIQUE KEY uq_courier (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_dsp_untr (id INT AUTO_INCREMENT PRIMARY KEY, parcel_id INT NOT NULL, action VARCHAR(20) NOT NULL, note VARCHAR(300) NULL, check_date DATE NULL, by_user VARCHAR(80) NULL, created_at DATETIME NOT NULL, INDEX (parcel_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // v4: a remark every day on every untraceable parcel (one row per remark, with the day it is for), who is responsible for each parcel, the days that were closed at 6 PM
    foreach (["ALTER TABLE dp_dsp_untr ADD COLUMN for_date DATE NULL", "ALTER TABLE dp_dsp_untr ADD COLUMN by_emp INT NULL", "ALTER TABLE dp_dsp_untr ADD INDEX idx_untr_day (parcel_id, for_date)"] as $__a) { try { $pdo->exec($__a); } catch (Throwable $e) {} }
    try { $pdo->exec("UPDATE dp_dsp_untr SET for_date = DATE(created_at) WHERE for_date IS NULL"); } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_dsp_untr_case (parcel_id INT NOT NULL PRIMARY KEY, owner_emp INT NULL, owner_by VARCHAR(80) NULL, owner_at DATETIME NULL, ended_at DATETIME NULL, ended_how VARCHAR(20) NULL, ended_by VARCHAR(80) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_dsp_untr_miss (day DATE NOT NULL, parcel_id INT NOT NULL, owner_emp INT NULL, item_id INT NULL, PRIMARY KEY (day, parcel_id), INDEX (parcel_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_dsp_untr_day (day DATE NOT NULL, owner_emp INT NOT NULL DEFAULT 0, total INT NOT NULL DEFAULT 0, written INT NOT NULL DEFAULT 0, PRIMARY KEY (day, owner_emp)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("UPDATE dp_items SET status = 'closed' WHERE rkey = 'dsp_untr_24h' AND status = 'open'"); $pdo->exec("DELETE FROM dp_rules WHERE rkey = 'dsp_untr_24h'"); } catch (Throwable $e) {}   // the old "untraceable with no action in 24 h" rule is replaced by "dsp_untr_remark"
    try { dp_seat_ensure($pdo, 'dispatch.untraceable', 'Untraceable owner', 'dispatch'); dp_seat_ensure($pdo, 'dispatch.untraceable_backup', 'Backup for the untraceable owner', 'dispatch'); } catch (Throwable $e) {}
    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM dp_dsp_couriers")->fetchColumn() === 0) {
            // only the two links I am sure of are filled in; Admin pastes the others in "Courier list" on the Dispatch page ({ID} = where the tracking ID goes)
            $n = 0; foreach ([['Tirupati', ''], ['India Post', ''], ['DTDC', ''], ['Shiprocket', 'https://shiprocket.co/tracking/{ID}'], ['Delhivery', 'https://www.delhivery.com/track/package/{ID}'], ['Bluedart', ''], ['Other', '']] as $c) { $pdo->prepare("INSERT IGNORE INTO dp_dsp_couriers (name, link, active, sort_no) VALUES (?,?,1,?)")->execute([$c[0], $c[1], ++$n]); }
        }
    } catch (Throwable $e) {}
    if (function_exists('dp_dsp_seed_rules')) dp_dsp_seed_rules($pdo);
    @file_put_contents($flag, '1');
}

/* ============================== sync from the HRMS order cache ============================== */
/** copy the last 14 days of shop_orders into dp_dsp_parcels. Returns [new, cancelled-marked] or -1 when there is no order cache. */
function dp_dsp_sync(PDO $pdo, $now, $force = false) {
    $last = (int)dp_get('dsp_last_sync_ts', '0');
    if (!$force && $now - $last < 170) return [0, 0];
    dp_put('dsp_last_sync_ts', (string)$now);
    $from = date('Y-m-d 00:00:00', $now - (defined('DP_DSP_WIN') ? DP_DSP_WIN : 14) * 86400);
    try { $st = $pdo->prepare("SELECT shopify_id, store_key, order_name, created_at, customer, phone, fulfillment_status FROM shop_orders WHERE created_at >= ?"); $st->execute([$from]); $orders = $st->fetchAll(); }
    catch (Throwable $e) { return [-1, 0]; }
    $cId = []; $cName = [];
    try { $q = $pdo->query("SELECT order_id, order_name FROM order_cancels WHERE cancelled_at >= '" . date('Y-m-d', $now - 40 * 86400) . "'"); foreach ($q->fetchAll() as $r) { $cId[(string)$r['order_id']] = 1; if ($r['order_name'] !== '') $cName[dp_dsp_norm_order($r['order_name'])] = 1; } } catch (Throwable $e) {}
    $have = [];
    foreach ($pdo->query("SELECT id, store_key, shopify_id, status, tracking_no FROM dp_dsp_parcels WHERE created_at >= '" . $from . "'")->fetchAll() as $r) $have[$r['store_key'] . '|' . $r['shopify_id']] = $r;
    $ins = $pdo->prepare("INSERT IGNORE INTO dp_dsp_parcels (store_key, shopify_id, order_name, created_at, customer, phone, status, due48, due4, shop_fulfil, synced_at, t48, t4, warn36, warn44, warn_d3, warn_d4a, warn_d4b) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $new = 0; $canc = 0; $nowS = date('Y-m-d H:i:s', $now);
    foreach ($orders as $o) {
        $k = $o['store_key'] . '|' . $o['shopify_id'];
        $isCanc = isset($cId[(string)$o['shopify_id']]) || ($o['order_name'] !== '' && isset($cName[dp_dsp_norm_order($o['order_name'])]));
        if (!isset($have[$k])) {
            $c = strtotime($o['created_at']); if (!$c) continue;
            $hist = $c < $now - 14 * 86400;   // older orders are history: never warned, never fined; Shopify "fulfilled" counts as dispatched
            $ins->execute([$o['store_key'], $o['shopify_id'], $o['order_name'], $o['created_at'], mb_substr((string)$o['customer'], 0, 120), mb_substr((string)$o['phone'], 0, 40), $isCanc ? 'cancelled' : ($hist && strtolower((string)$o['fulfillment_status']) === 'fulfilled' ? 'transit' : 'placed'), date('Y-m-d H:i:s', dp_dsp_due48($c)), date('Y-m-d H:i:s', dp_dsp_due4($c)), $o['fulfillment_status'], $nowS, $hist ? 2 : 0, $hist ? 2 : 0, $hist ? 1 : 0, $hist ? 1 : 0, $hist ? 1 : 0, $hist ? 1 : 0, $hist ? 1 : 0]);
            if ($ins->rowCount()) { $new++; if ($isCanc) $canc++; }
        } else {
            $r = $have[$k];
            if ($isCanc && !in_array($r['status'], ['cancelled', 'delivered', 'rto'], true)) { $pdo->prepare("UPDATE dp_dsp_parcels SET status = 'cancelled' WHERE id = ?")->execute([$r['id']]); $canc++; }
            elseif (!$isCanc && $r['status'] === 'cancelled') { $pdo->prepare("UPDATE dp_dsp_parcels SET status = ? WHERE id = ?")->execute([$r['tracking_no'] ? 'tracked' : 'placed', $r['id']]); }
        }
    }
    try { $pdo->prepare("DELETE FROM dp_dsp_parcels WHERE created_at < ?")->execute([date('Y-m-d', $now - 100 * 86400)]); } catch (Throwable $e) {}
    return [$new, $canc];
}

/* ============================== THE LIMITS: one setting, two doors ==============================
 * The Boss task ("A tracking ID within 72 hours") and the control on the Parcels page both call dp_dsp_set_limits().
 * It saves the limit, moves the deadline of every open parcel, renames the two penalty rules and re-words the matching Boss tasks,
 * so there is never an "old" and a "new" version of the same rule. The newest change wins. */
function dp_dsp_set_limits(PDO $pdo, $hours, $days, $by = '', $src = 'dispatch', $pick = null) {
    $oh = dp_dsp_track_hours(); $od = dp_dsp_transit_days(); $op = dp_dsp_pick_hours();
    $h = $hours === null || $hours === '' ? $oh : (int)(round(((int)$hours) / 6) * 6); $h = max(12, min(240, $h));
    $d = $days === null || $days === '' ? $od : max(2, min(10, (int)$days));
    $p = $pick === null || $pick === '' ? $op : (int)(round(((int)$pick) / 6) * 6); $p = max(12, min(240, $p));
    $out = ['changed' => false, 'hours' => $h, 'days' => $d, 'pick' => $p, 'old_hours' => $oh, 'old_days' => $od, 'old_pick' => $op, 'moved' => 0, 'forgiven' => 0, 'boss_items' => 0, 'text' => ''];
    if ($h === $oh && $d === $od && $p === $op) { $out['text'] = 'Dispatch already uses ' . $h . ' hours, day ' . $d . ' and a ' . $p . ' hour pickup wait.'; return $out; }
    $now = time(); $nowS = date('Y-m-d H:i:s', $now);
    dp_put('dsp_track_hours', (string)$h); dp_put('dsp_transit_days', (string)$d); dp_put('dsp_pick_hours', (string)$p);
    dp_put('dsp_limits_by', json_encode(['by' => (string)$by, 'src' => (string)$src, 'at' => $nowS, 'from_h' => $oh, 'from_d' => $od, 'from_p' => $op]));
    $out['changed'] = true;
    /* 1. every parcel of the last 14 days gets its new deadline. A parcel that was ALREADY fined keeps the deadline it was fined for.
          A parcel that is only now late because the limit got shorter is NOT fined: a changed rule counts from today. */
    $from = date('Y-m-d 00:00:00', $now - (defined('DP_DSP_WIN') ? DP_DSP_WIN : 14) * 86400);
    $st = $pdo->prepare("SELECT id, created_at, due48, due4, t48, t4, warn36, warn44, warn_d3, warn_d4a, warn_d4b FROM dp_dsp_parcels WHERE created_at >= ?"); $st->execute([$from]);
    $u = $pdo->prepare("UPDATE dp_dsp_parcels SET due48 = ?, due4 = ?, t48 = ?, t4 = ?, warn36 = ?, warn44 = ?, warn_d3 = ?, warn_d4a = ?, warn_d4b = ? WHERE id = ?");
    foreach ($st->fetchAll() as $r) {
        $c = strtotime($r['created_at']); if (!$c) continue;
        $n48 = (int)$r['t48'] === 1 ? $r['due48'] : date('Y-m-d H:i:s', dp_dsp_due48($c, $h));
        $n4 = (int)$r['t4'] === 1 ? $r['due4'] : date('Y-m-d H:i:s', dp_dsp_due4($c, $d));
        $t48 = (int)$r['t48']; $t4 = (int)$r['t4'];
        $w36 = (int)$r['warn36']; $w44 = (int)$r['warn44']; $wd3 = (int)$r['warn_d3']; $wa = (int)$r['warn_d4a']; $wb = (int)$r['warn_d4b'];
        if ($t48 === 0 && $n48 !== $r['due48']) {
            if (strtotime($n48) <= $now && strtotime($r['due48']) > $now) { $t48 = 2; $out['forgiven']++; $w36 = $w44 = 1; }
            elseif (strtotime($n48) > $now) { $w36 = $w44 = 0; }
        }
        if ($t4 === 0 && $n4 !== $r['due4']) {
            if (strtotime($n4) <= $now && strtotime($r['due4']) > $now) { $t4 = 2; $out['forgiven']++; $wd3 = $wa = $wb = 1; }
            elseif (strtotime($n4) > $now) { $wd3 = $wa = $wb = 0; }
        }
        if ($n48 !== $r['due48'] || $n4 !== $r['due4'] || $t48 !== (int)$r['t48'] || $t4 !== (int)$r['t4']) { $u->execute([$n48, $n4, $t48, $t4, $w36, $w44, $wd3, $wa, $wb, $r['id']]); $out['moved']++; }
    }
    /* 1b. the courier pickup wait: a parcel that only now waits longer than the new limit is NOT fined (a changed rule counts from today) */
    if ($p !== $op) {
        try {
            $q = $pdo->prepare("UPDATE dp_dsp_parcels SET t_g3 = 2 WHERE t_g3 = 0 AND tracked_at IS NOT NULL AND created_at >= ? AND tracked_at <= ? AND tracked_at > ?");
            $q->execute([$from, date('Y-m-d H:i:s', $now - $p * 3600), date('Y-m-d H:i:s', $now - $op * 3600)]); $out['forgiven'] += $q->rowCount();
        } catch (Throwable $e) {}
    }
    /* 2. the penalty rules say the same thing as the limit */
    try {
        $pdo->prepare("UPDATE dp_rules SET title = ?, when_text = ? WHERE rkey = 'tracking_48h'")->execute(['Tracking number not assigned in ' . $h . ' hours', 'Order time + ' . $h . ' h']);
        $pdo->prepare("UPDATE dp_rules SET title = ?, when_text = ? WHERE rkey = 'transit_day4'")->execute(['Parcel not in transit by day ' . $d, 'Day ' . $d . ', 12 AM']);
        $pdo->prepare("UPDATE dp_rules SET title = ?, when_text = ? WHERE rkey = 'dsp_g3_48h'")->execute(['Tracking ID given but not in transit after ' . $p . ' h', $p . ' h after the tracking ID was given']);
    } catch (Throwable $e) {}
    /* 3. the Boss tasks that watch the same thing: same hours, same wording, no second copy of the rule */
    try {
        foreach ([['tracking', $h, 'hours'], ['transit', $d, 'days'], ['pickup', $p, 'hours']] as $x) {
            $q = $pdo->prepare("SELECT id, adapter, title, why, detail FROM b2_items WHERE adapter REGEXP ? AND status NOT IN ('archived','done')"); $q->execute(['^' . $x[0] . '[0-9]+$']);
            foreach ($q->fetchAll() as $it) {
                $oldN = (int)substr($it['adapter'], strlen($x[0])); if ($oldN === $x[1]) continue;
                $re = '/\b' . $oldN . ' ' . ($x[2] === 'hours' ? 'hours?' : 'days?') . '\b/i'; $to = $x[1] . ' ' . $x[2];
                $f = function ($t) use ($re, $to) { return $t === null ? null : preg_replace($re, $to, (string)$t); };
                $pdo->prepare("UPDATE b2_items SET adapter = ?, title = ?, why = ?, detail = ?, updated_at = ? WHERE id = ?")->execute([$x[0] . $x[1], $f($it['title']), $f($it['why']), $f($it['detail']), $nowS, $it['id']]);
                $out['boss_items']++;
            }
        }
    } catch (Throwable $e) {}
    if (function_exists('bd_log')) { try { bd_log('dsp_limits', 'dispatch', 'tracking ' . $oh . ' h -> ' . $h . ' h, transit day ' . $od . ' -> ' . $d . ', pickup wait ' . $op . ' h -> ' . $p . ' h (' . $src . ')', $by ?: 'system'); } catch (Throwable $e) {} }
    $bits = [];
    if ($h !== $oh) $bits[] = 'tracking ID within ' . $h . ' hours (was ' . $oh . ')';
    if ($d !== $od) $bits[] = 'in transit by day ' . $d . ' (was ' . $od . ')';
    if ($p !== $op) $bits[] = 'courier pickup wait ' . $p . ' hours (was ' . $op . ')';
    $out['text'] = 'Dispatch now uses: ' . implode(' and ', $bits) . '. ' . $out['moved'] . ' open parcel' . ($out['moved'] === 1 ? ' got' : 's got') . ' a new deadline'
        . ($out['forgiven'] ? '; ' . $out['forgiven'] . ' that are late only because of the change will not be fined' : '') . ($out['boss_items'] ? '; ' . $out['boss_items'] . ' Boss task' . ($out['boss_items'] === 1 ? ' was' : 's were') . ' re-worded to match' : '') . '.';
    return $out;
}
/* ============================== changing a parcel ============================== */
function dp_dsp_rank($s) { $r = ['placed' => 0, 'tracked' => 1, 'transit' => 2, 'delivered' => 3]; return $r[$s] ?? -1; }
/** set tracking / courier / status. $allowDown lets a manager correct a status downwards. Returns a short word of what changed ('' = nothing). */
function dp_dsp_apply(PDO $pdo, array $p, $track, $courier, $status, $by, $allowDown = false) {
    $set = []; $par = []; $now = bd_now(); $did = [];
    $track = trim((string)$track);
    if ($track !== '' && $track !== (string)$p['tracking_no']) { $set[] = 'tracking_no = ?'; $par[] = $track; $did[] = 'tracking'; if (!empty($p['pend_track'])) { $set[] = 'pend_track = NULL'; } if (!$p['tracked_at']) { $set[] = 'tracked_at = ?'; $par[] = $now; $set[] = 'tracked_by = ?'; $par[] = mb_substr($by, 0, 80); } }
    if ($courier !== '' && $courier !== null && $courier !== (string)$p['courier']) { $set[] = 'courier = ?'; $par[] = mb_substr(trim((string)$courier), 0, 40); }
    $cur = $p['status']; $new = $cur;
    if (in_array($cur, ['rto', 'cancelled'], true) && !$allowDown) $status = '';
    $hasTrack = $track !== '' || (string)$p['tracking_no'] !== '';
    if ($status === 'rto' && $cur !== 'rto') $new = 'rto';
    elseif ($status === 'cancelled') $new = 'cancelled';
    elseif (in_array($status, ['tracked', 'transit', 'delivered', 'placed'], true)) {
        if ($allowDown || dp_dsp_rank($status) > dp_dsp_rank($cur)) $new = $status;
    }
    if ($new === $cur && $cur === 'placed' && $hasTrack) $new = 'tracked';
    if ($new === 'tracked' && !$hasTrack) $new = 'placed';
    if ($new !== $cur) {
        $set[] = 'status = ?'; $par[] = $new; $did[] = $new;
        if (in_array($new, ['transit', 'delivered'], true) && !$p['transit_at']) { $set[] = 'transit_at = ?'; $par[] = $now; }
        if ($new === 'delivered' && empty($p['delivered_at'])) { $set[] = 'delivered_at = ?'; $par[] = $now; }
        if ($new === 'tracked' && !$p['tracked_at']) { $set[] = 'tracked_at = ?'; $par[] = $now; $set[] = 'tracked_by = ?'; $par[] = mb_substr($by, 0, 80); }
        if (in_array($new, ['placed', 'tracked'], true) && $allowDown) { $set[] = 'transit_at = NULL'; }
    }
    if (!$set) return '';
    $par[] = $p['id'];
    $pdo->prepare("UPDATE dp_dsp_parcels SET " . implode(', ', $set) . " WHERE id = ?")->execute($par);
    return implode('+', $did) ?: 'courier';
}
function dp_dsp_find_by_name(PDO $pdo, $name, $store = '') {
    $n = dp_dsp_norm_order($name); if ($n === '') return [];
    $sql = "SELECT * FROM dp_dsp_parcels WHERE (LOWER(order_name) = ? OR LOWER(order_name) = ?)" . ($store !== '' ? " AND store_key = ?" : "") . " ORDER BY created_at DESC LIMIT 5";
    $st = $pdo->prepare($sql); $st->execute($store !== '' ? [$n, '#' . $n, $store] : [$n, '#' . $n]); return $st->fetchAll();
}
function dp_dsp_track_in_use(PDO $pdo, $track, $exceptId) {
    $st = $pdo->prepare("SELECT order_name FROM dp_dsp_parcels WHERE (tracking_no = ? OR pend_track = ?) AND id != ? LIMIT 1"); $st->execute([$track, $track, (int)$exceptId]); return (string)$st->fetchColumn();
}
function dp_dsp_valid_track($t) { return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_\/]{3,39}$/', $t); }

/* ============================== views ============================== */
function dp_dsp_row(array $r, $now) {
    $c = strtotime($r['created_at']); $d48 = strtotime($r['due48']); $d4 = strtotime($r['due4']);
    $open = in_array($r['status'], ['placed', 'tracked'], true);
    $needTrack = $r['status'] === 'placed';
    $late48 = $needTrack && $d48 <= $now; $late4 = $open && $d4 <= $now;
    $o = ['id' => (int)$r['id'], 'order' => $r['order_name'], 'store' => $r['store_key'], 'customer' => (string)$r['customer'], 'phone' => (string)$r['phone'],
        'created' => date('D j M, g:i A', $c), 'tracking_no' => (string)$r['tracking_no'], 'courier' => (string)$r['courier'], 'status' => $r['status'],
        'day_no' => $open ? max(1, min(9, dp_dsp_days_between($c, $now))) : null,
        'track_left' => $needTrack ? dp_dsp_left_label($d48, $now) : '', 'track_late' => $late48, 'track_hours' => $needTrack ? round(($d48 - $now) / 3600, 1) : null,
        'transit_left' => $open ? dp_dsp_left_label($d4, $now) : '', 'transit_late' => $late4, 'transit_due' => date('D j M', $d4 - 1) . ' 12 AM',
        'penalised48' => (int)$r['t48'] === 1, 'penalised4' => (int)$r['t4'] === 1];
    return $o;
}
/** which working day this parcel is on today (1..) */
function dp_dsp_days_between($createdTs, $nowTs) {
    $d = dp_dsp_day_n($createdTs, 1); $n = 1; $today = date('Y-m-d', $nowTs);
    while ($d < $today && $n < 20) { $d = date('Y-m-d', strtotime($d . ' +1 day')); if ((int)date('N', strtotime($d)) !== 7) $n++; }
    return $n;
}
function dp_dsp_filter_sql($tab, $now, array &$par) {
    $nowS = date('Y-m-d H:i:s', $now);
    if ($tab === 'need') return "status = 'placed'";
    if ($tab === 'transit') { $par[] = $nowS; return "status IN ('placed','tracked') AND due4 > ?"; }
    if ($tab === 'late') { $par[] = $nowS; $par[] = $nowS; return "((status = 'placed' AND due48 <= ?) OR (status IN ('placed','tracked') AND due4 <= ?))"; }
    return "status != 'cancelled'";
}
function dp_dsp_order_sql($tab) {
    if ($tab === 'need') return "due48 ASC, id ASC";
    if ($tab === 'transit' || $tab === 'late') return "due4 ASC, due48 ASC, id ASC";
    return "created_at DESC, id DESC";
}
function dp_dsp_counts(PDO $pdo, $now) {
    $n = date('Y-m-d H:i:s', $now);
    $st = $pdo->prepare("SELECT
        SUM(status = 'placed') need,
        SUM(status IN ('placed','tracked') AND due4 > ?) transit,
        SUM((status = 'placed' AND due48 <= ?) OR (status IN ('placed','tracked') AND due4 <= ?)) late,
        SUM(status != 'cancelled') allp,
        SUM(status = 'placed' AND due48 <= ?) l48,
        SUM(status = 'placed' AND due48 > ? AND due48 <= DATE_ADD(?, INTERVAL 12 HOUR)) h12,
        SUM(status = 'placed' AND due48 > DATE_ADD(?, INTERVAL 12 HOUR) AND due48 <= DATE_ADD(?, INTERVAL 24 HOUR)) h24,
        SUM(status = 'placed' AND due48 > DATE_ADD(?, INTERVAL 24 HOUR)) h48,
        SUM(status = 'tracked') tracked_wait,
        SUM(status IN ('placed','tracked') AND due4 > ? AND due4 <= DATE_ADD(?, INTERVAL 48 HOUR)) risk,
        SUM(status IN ('placed','tracked') AND due4 <= ?) l4
        FROM dp_dsp_parcels WHERE created_at >= ?");
    $st->execute([$n, $n, $n, $n, $n, $n, $n, $n, $n, $n, $n, $n, date('Y-m-d', $now - 14 * 86400)]);
    $r = $st->fetch();
    foreach ($r as $k => $v) $r[$k] = (int)$v;
    return $r;
}

/* ============================== My Day items + warnings + penalties (only when the topic is live) ============================== */
function dp_dsp_names(array $rows, $max = 6) {
    $n = array_map(function ($r) { return $r['order_name']; }, array_slice($rows, 0, $max));
    return implode(', ', $n) . (count($rows) > $max ? ' and ' . (count($rows) - $max) . ' more' : '');
}
/** the one "today" item of a role: made when there is work, refreshed every tick, done when the work is gone */
function dp_dsp_daily_item(PDO $pdo, $ref, $seat, $title, $detail, $count, $today, $dueAt) {
    $st = $pdo->prepare("SELECT * FROM dp_items WHERE ref_key = ?"); $st->execute([$ref]); $it = $st->fetch();
    $owner = dp_owner_for($pdo, $seat, $today);
    if ($count > 0) {
        if (!$it) { dp_item_make($pdo, 'dispatch', null, $ref, $seat, $title, $detail, $today, $dueAt, [], $owner ?: 0, $count); return; }
        $pdo->prepare("UPDATE dp_items SET title = ?, detail = ?, qty = ?, owner_emp = ?, due_at = ?, status = 'open', done_at = NULL WHERE id = ?")->execute([mb_substr($title, 0, 200), mb_substr($detail, 0, 400), max(1, $count), $owner ?: null, $dueAt, $it['id']]);
    } elseif ($it && $it['status'] === 'open') {
        $pdo->prepare("UPDATE dp_items SET status = 'done', done_at = ?, detail = ? WHERE id = ?")->execute([bd_now(), 'All done.', $it['id']]);
    }
}
function dp_dsp_push_holders(PDO $pdo, $seat, $title, $body) { foreach (dp_holders($pdo, $seat) as $e) bd_push($pdo, (int)$e, $title, $body); }

function dp_dispatch_tick(PDO $pdo, $now) {
    dp_dsp_ensure($pdo);
    $res = ['synced' => 0, 'canc' => 0];
    // bring the newest Shopify orders in first (every 10 minutes; a failure is remembered and shown on the Dispatch page)
    try { if (function_exists('dp_dsp_autoheal_orders')) { $x = dp_dsp_autoheal_orders($pdo, $now); if ($x !== null) $res['shop'] = $x; } } catch (Throwable $e) { error_log('Shopify order refresh: ' . $e->getMessage()); }
    list($new, $canc) = dp_dsp_sync($pdo, $now, false); $res['synced'] = $new; $res['canc'] = $canc;
    // Shiprocket: every 15 minutes when an API user is saved (a failure is remembered and shown on the page, it never stops the tick)
    try { if (function_exists('dp_dsp_sr_cfg') && dp_dsp_sr_cfg()['ok'] && $now - (int)dp_get('sr_last_ts', '0') >= 900) { $sr = dp_dsp_sr_sync($pdo, 15); $res['sr'] = $sr['ok'] ? $sr['updated'] : -1; } } catch (Throwable $e) { error_log('Shiprocket sync: ' . $e->getMessage()); }
    // Live tracking: ask Shiprocket again about parcels in transit that have gone quiet (40 per run, oldest first); the webhook does the instant part
    try { if (function_exists('dp_live_tick')) $res['live'] = dp_live_tick($pdo, $now); } catch (Throwable $e) { error_log('Dispatch live tick: ' . $e->getMessage()); }
    // "Needs attention": NDR / undelivered / RTO / lost / damaged, parcels past their deadline or courier date, tracking numbers nobody scanned (once per parcel and reason; wa_orders.php)
    try { if (function_exists('wa_ord_auto_scan')) $res['attention'] = wa_ord_auto_scan($pdo, $now); } catch (Throwable $e) { error_log('Needs attention scan: ' . $e->getMessage()); }
    // Shopify tracking numbers for parcels that have none + one day of Shiprocket per run, going round the open days
    try { if (function_exists('dp_dsp_autoheal')) $res += dp_dsp_autoheal($pdo, $now); } catch (Throwable $e) { error_log('Dispatch auto-heal: ' . $e->getMessage()); }
    try { if (function_exists('dp_dsp_inbox_reconcile')) $res['inbox_closed'] = dp_dsp_inbox_reconcile($pdo, $now); } catch (Throwable $e) {}
    // Boss cases made from an old Dispatch check (for example "Shiprocket is not connected") close by themselves when the check no longer finds the problem
    try { if (function_exists('dp_dsp_cases_reconcile')) $res['cases_closed'] = dp_dsp_cases_reconcile($pdo, $now); } catch (Throwable $e) { error_log('Dispatch reconcile: ' . $e->getMessage()); }
    if (!dp_live($pdo, 'dispatch')) { $res['live'] = false; return $res; }
    $res['live'] = true;
    $today = date('Y-m-d', $now); $nowS = date('Y-m-d H:i:s', $now);
    $st = $pdo->prepare("SELECT approved_at FROM dp_topics WHERE topic = 'dispatch'"); $st->execute(); $go = strtotime((string)$st->fetchColumn()) ?: 0;
    $sunday = (int)date('N', $now) === 7;
    $res['warn'] = 0; $res['pen48'] = 0; $res['pen4'] = 0;

    /* ---- 1. warnings ---- */
    if (!$sunday) {
        // 36 h and 44 h without a tracking number (second one also tells the boss)
        $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status = 'placed' AND pend_track IS NULL AND due48 > ? AND due48 <= DATE_ADD(?, INTERVAL 12 HOUR) AND warn36 = 0 AND created_at >= ? ORDER BY due48");
        $q->execute([$nowS, $nowS, date('Y-m-d', $now - 14 * 86400)]); $w36 = $q->fetchAll();
        $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status = 'placed' AND pend_track IS NULL AND due48 > ? AND due48 <= DATE_ADD(?, INTERVAL 4 HOUR) AND warn44 = 0 AND created_at >= ? ORDER BY due48");
        $q->execute([$nowS, $nowS, date('Y-m-d', $now - 14 * 86400)]); $w44 = $q->fetchAll();
        if ($w44) {
            $ids = array_map(function ($r) { return (int)$r['id']; }, array_merge($w36, $w44)); $ids = array_unique($ids);
            $pdo->exec("UPDATE dp_dsp_parcels SET warn36 = 1, warn44 = 1 WHERE id IN (" . implode(',', $ids) . ")");
            $left = dp_dsp_left_label(strtotime($w44[0]['due48']), $now);
            dp_dsp_push_holders($pdo, 'dispatch.tracking', 'Assign tracking number NOW', count($w44) . ' parcel' . (count($w44) === 1 ? '' : 's') . ' will be late within 4 hours (' . dp_dsp_names($w44, 4) . '). Oldest: ' . $left . '.');
            bd_inbox('dp_note', count($w44) . ' parcel' . (count($w44) === 1 ? '' : 's') . ' close to the ' . dp_dsp_track_hours() . ' h tracking limit', dp_dsp_names($w44, 8) . '. Oldest: ' . $left . '. The Dispatch tracking person has been warned.', [], null, null, 'dsp:w44:' . date('YmdHi', $now));
            $res['warn'] += count($w44);
        } elseif ($w36) {
            $ids = array_map(function ($r) { return (int)$r['id']; }, $w36);
            $pdo->exec("UPDATE dp_dsp_parcels SET warn36 = 1 WHERE id IN (" . implode(',', $ids) . ")");
            dp_dsp_push_holders($pdo, 'dispatch.tracking', 'Assign tracking numbers', count($w36) . ' parcel' . (count($w36) === 1 ? ' needs' : 's need') . ' a tracking number within 12 hours (' . dp_dsp_names($w36, 4) . ').');
            $res['warn'] += count($w36);
        }
        // day 3 morning: not in transit yet
        $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status IN ('placed','tracked') AND due4 > ? AND warn_d3 = 0 AND created_at >= ? ORDER BY due4");
        $q->execute([$nowS, date('Y-m-d', $now - 14 * 86400)]); $d3 = [];
        foreach ($q->fetchAll() as $r) { $d3d = dp_dsp_day_n(strtotime($r['created_at']), dp_dsp_transit_days() - 1); if ($d3d < $today || ($d3d === $today && $now >= strtotime($today . ' 10:00:00'))) $d3[] = $r; }
        if ($d3) {
            $pdo->exec("UPDATE dp_dsp_parcels SET warn_d3 = 1 WHERE id IN (" . implode(',', array_map(function ($r) { return (int)$r['id']; }, $d3)) . ")");
            dp_dsp_push_holders($pdo, 'dispatch.lead', 'Parcels not in transit yet', count($d3) . ' parcel' . (count($d3) === 1 ? ' is' : 's are') . ' on day ' . (dp_dsp_transit_days() - 1) . ' and not in transit (' . dp_dsp_names($d3, 4) . '). They must move by day ' . dp_dsp_transit_days() . '.');
            $res['warn'] += count($d3);
        }
        // day 4 at 12:00 and at 17:00 (also the boss)
        $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status IN ('placed','tracked') AND due4 > ? AND (warn_d4a = 0 OR warn_d4b = 0) AND created_at >= ? ORDER BY due48");
        $q->execute([$nowS, date('Y-m-d', $now - 14 * 86400)]); $a = []; $b = [];
        foreach ($q->fetchAll() as $r) {
            $d4 = dp_dsp_day_n(strtotime($r['created_at']), dp_dsp_transit_days()); if ($d4 !== $today) continue;
            if ($now >= strtotime($today . ' 17:00:00')) $b[] = $r; elseif ($now >= strtotime($today . ' 12:00:00') && (int)$r['warn_d4a'] === 0) $a[] = $r;
        }
        foreach ([['a', $a, 'Last day: parcels not in transit'], ['b', $b, 'Last hours: parcels not in transit']] as $x) {
            if (!$x[1]) continue; $col = $x[0] === 'a' ? 'warn_d4a' : 'warn_d4b';
            $set = $x[0] === 'b' ? 'warn_d4a = 1, warn_d4b = 1' : 'warn_d4a = 1';
            $pdo->exec("UPDATE dp_dsp_parcels SET $set WHERE id IN (" . implode(',', array_map(function ($r) { return (int)$r['id']; }, $x[1])) . ")");
            $txt = count($x[1]) . ' parcel' . (count($x[1]) === 1 ? '' : 's') . ' must be in transit before 12 AM tonight (' . dp_dsp_names($x[1], 5) . ').';
            dp_dsp_push_holders($pdo, 'dispatch.lead', $x[2], $txt);
            bd_inbox('dp_note', $x[2] . ' (' . count($x[1]) . ')', $txt . ' The Dispatch lead has been warned.', [], null, null, 'dsp:w4' . $x[0] . ':' . date('YmdHi', $now));
            $res['warn'] += count($x[1]);
        }
    }

    /* ---- 2. penalties: one item per rule per tick ---- */
    $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status = 'placed' AND pend_track IS NULL AND due48 <= ? AND t48 = 0 AND created_at >= ? ORDER BY due48");
    $q->execute([$nowS, date('Y-m-d H:i:s', $now - (defined('DP_DSP_NOID_DAYS') ? DP_DSP_NOID_DAYS : 10) * 86400)]);   // older than 10 days without an ID: not a tracking-ID problem $late = $q->fetchAll();
    if ($late) {
        $fresh = []; $old = [];
        foreach ($late as $r) { if (strtotime($r['due48']) >= $go) $fresh[] = $r; else $old[] = $r; }   // a deadline that passed BEFORE go-live is never penalised
        if ($old) $pdo->exec("UPDATE dp_dsp_parcels SET t48 = 2 WHERE id IN (" . implode(',', array_map(function ($r) { return (int)$r['id']; }, $old)) . ")");
        if ($fresh) {
            $ids = array_map(function ($r) { return (int)$r['id']; }, $fresh);
            $pdo->exec("UPDATE dp_dsp_parcels SET t48 = 1 WHERE id IN (" . implode(',', $ids) . ")");
            $iid = dp_item_make($pdo, 'dispatch', 'tracking_48h', 'dsp:t48:' . date('Ymd-His', $now), 'dispatch.tracking', 'No tracking number in ' . dp_dsp_track_hours() . ' hours: ' . count($fresh) . ' parcel' . (count($fresh) === 1 ? '' : 's'), dp_dsp_names($fresh, 8), $today, $nowS, ['ids' => $ids], null, count($fresh));
            dp_miss($pdo, $iid, 'tracking_48h', count($fresh), 'no tracking number in ' . dp_dsp_track_hours() . ' h');
            $res['pen48'] = count($fresh);
        }
    }
    $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status IN ('placed','tracked') AND due4 <= ? AND t4 = 0 AND (status = 'tracked' OR created_at >= ?) ORDER BY due4");
    $q->execute([$nowS, date('Y-m-d H:i:s', $now - (defined('DP_DSP_NOID_DAYS') ? DP_DSP_NOID_DAYS : 10) * 86400)]); $late = $q->fetchAll();
    if ($late) {
        $fresh = []; $old = [];
        foreach ($late as $r) { if (strtotime($r['due4']) >= $go) $fresh[] = $r; else $old[] = $r; }
        if ($old) $pdo->exec("UPDATE dp_dsp_parcels SET t4 = 2 WHERE id IN (" . implode(',', array_map(function ($r) { return (int)$r['id']; }, $old)) . ")");
        if ($fresh) {
            $ids = array_map(function ($r) { return (int)$r['id']; }, $fresh);
            $pdo->exec("UPDATE dp_dsp_parcels SET t4 = 1 WHERE id IN (" . implode(',', $ids) . ")");
            $iid = dp_item_make($pdo, 'dispatch', 'transit_day4', 'dsp:t4:' . date('Ymd-His', $now), 'dispatch.lead', 'Not in transit by day ' . dp_dsp_transit_days() . ': ' . count($fresh) . ' parcel' . (count($fresh) === 1 ? '' : 's'), dp_dsp_names($fresh, 8), $today, $nowS, ['ids' => $ids], null, count($fresh));
            dp_miss($pdo, $iid, 'transit_day4', count($fresh), 'not in transit by day ' . dp_dsp_transit_days());
            $res['pen4'] = count($fresh);
        }
    }

    // the three penalties made on the Dispatch page (each works only when it is switched on in Boss, and never for the past)
    try { if (function_exists('dp_dsp_untr_tick')) $res['untr'] = dp_dsp_untr_tick($pdo, $now); } catch (Throwable $e) {}   // owner, 6 PM close of each day, My Day job, 5 PM reminder
    try { if (function_exists('dp_dsp_new_pens')) $res['newpen'] = dp_dsp_new_pens($pdo, $now); } catch (Throwable $e) { error_log('Dispatch penalties: ' . $e->getMessage()); }

    /* ---- 3. My Day items ---- */
    $pdo->prepare("UPDATE dp_items SET status = 'closed' WHERE kind = 'dispatch' AND rkey IS NULL AND status = 'open' AND task_date < ?")->execute([$today]);
    if (function_exists('dp_dsp_job_refresh')) $res['job'] = dp_dsp_job_refresh($pdo, $now);   // "Add tracking IDs for parcels sent outside Shiprocket": 2 of 6 added, closes itself
    $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status IN ('placed','tracked') AND due4 > ? AND due4 <= DATE_ADD(?, INTERVAL 48 HOUR) AND created_at >= ? ORDER BY due4"); $q->execute([$nowS, $nowS, date('Y-m-d', $now - 14 * 86400)]); $risk = $q->fetchAll();
    $nR = count($risk);
    $detail = $nR ? $nR . ' parcel' . ($nR === 1 ? ' is' : 's are') . ' not in transit yet, the first must move by ' . date('D', strtotime($risk[0]['due4']) - 1) . ' 12 AM' : 'Nothing is close to the day-' . dp_dsp_transit_days() . ' limit.';
    dp_dsp_daily_item($pdo, 'dsp:transit:' . $today, 'dispatch.lead', 'Get parcels in transit', $detail, $nR, $today, $today . ' 23:59:00');
    return $res;
}

/* ============================== endpoints ============================== */
if (strpos((string)$action, 'dp.dsp_') === 0) {
    dp_ensure($pdo); dp_dsp_ensure($pdo);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    /* the courier feed: a script / webhook pushes tracking numbers and statuses. Protected by the tick key, no login. */
    if ($action === 'dp.dsp_feed') {
        $key = (string)($_GET['key'] ?? '');
        if ($key === '' || !hash_equals((string)bd_cfg_fresh('tick_key'), $key)) json_response(['error' => 'Wrong or missing key.'], 403);
        if (!$isPost) json_response(['error' => 'Send a POST with a JSON list: [{"order_name":"#1001","tracking_no":"X123","courier":"Delhivery","status":"in transit"}].'], 405);
        $in = json_input(); $list = isset($in['items']) && is_array($in['items']) ? $in['items'] : $in;
        if (!$list || !isset($list[0]) || !is_array($list[0])) json_response(['error' => 'Send a list of orders.'], 422);
        $ok = 0; $same = 0; $miss = [];
        foreach (array_slice($list, 0, 1000) as $x) {
            $name = (string)($x['order_name'] ?? ($x['order'] ?? '')); $rows = dp_dsp_find_by_name($pdo, $name, (string)($x['store_key'] ?? ''));
            if (!$rows) { $miss[] = $name; continue; }
            $p = $rows[0];
            $t = trim((string)($x['tracking_no'] ?? ($x['awb'] ?? ''))); if ($t !== '' && !dp_dsp_valid_track($t)) { $miss[] = $name . ' (bad tracking number)'; continue; }
            $r = dp_dsp_apply($pdo, $p, $t, (string)($x['courier'] ?? ''), dp_dsp_map_status((string)($x['status'] ?? '')), 'courier feed', false);
            if ($r === '') $same++; else $ok++;
        }
        bd_log('dispatch', 'feed', $ok . ' updated, ' . $same . ' unchanged, ' . count($miss) . ' not found', 'courier feed');
        json_response(['ok' => true, 'updated' => $ok, 'unchanged' => $same, 'not_found' => array_slice($miss, 0, 50)]);
    }

    /* Shiprocket's live webhook: no login, protected by the token in the x-api-key header (shown to Admin on the Dispatch page). */
    if ($action === 'dp.dsp_hook' && function_exists('dp_live_hook')) dp_live_hook($pdo);

    $auth = require_auth();
    $w = bd_who($pdo, $auth);
    $by = (string)($auth['username'] ?? 'user');
    $roleTrack = dp_can_seat($pdo, $auth, 'dispatch.tracking'); $roleLead = dp_can_seat($pdo, $auth, 'dispatch.lead');
    $roleUntr = dp_can_seat($pdo, $auth, 'dispatch.untraceable') || dp_can_seat($pdo, $auth, 'dispatch.untraceable_backup');
    if (!$roleUntr && !empty($w['emp'])) { try { $q = $pdo->prepare("SELECT 1 FROM dp_dsp_untr_case WHERE owner_emp = ? AND ended_at IS NULL LIMIT 1"); $q->execute([(int)$w['emp']]); $roleUntr = (bool)$q->fetchColumn(); } catch (Throwable $e) {} }
    $seatUntr = dp_can_seat($pdo, $auth, 'dispatch.untraceable') || dp_can_seat($pdo, $auth, 'dispatch.untraceable_backup');
    $byName = !empty($w['emp']) ? bd_emp_name($pdo, (int)$w['emp']) : $by;
    // may this login write on this untraceable parcel? bosses, the owner and the backup, and the person this parcel was handed to
    $mayUntr = function ($pid) use ($pdo, $w, $seatUntr) {
        if ($w['manager'] || $seatUntr) return true;
        $q = $pdo->prepare("SELECT owner_emp FROM dp_dsp_untr_case WHERE parcel_id = ?"); $q->execute([(int)$pid]);
        if (!empty($w['emp']) && (int)$q->fetchColumn() === (int)$w['emp']) return true;
        json_response(['error' => 'This parcel is with somebody else. Ask the Untraceable owner to hand it to you.'], 403);
    };
    if (!$w['manager'] && !$roleTrack && !$roleLead && !$roleUntr) json_response(['error' => 'This page is for the people who hold a Dispatch seat, the untraceable owner, and for bosses.'], 403);
    $in = $isPost ? json_input() : [];
    $now = time();
    dp_tick_after($pdo);

    switch ($action) {

    case 'dp.dsp_live': {   // one parcel's journey: stored scans, refreshed from Shiprocket when older than a minute
        if (!function_exists('dp_live_view')) json_response(['error' => 'Live tracking is not installed (dept_dispatch_live.php is missing).'], 500);
        $lid = (int)($_GET['id'] ?? ($in['id'] ?? 0)); if ($lid <= 0) json_response(['error' => 'Missing parcel.'], 422);
        $view = dp_live_view($pdo, $lid, !empty($_GET['force']) || !empty($in['force']), $now);
        if (empty($_GET['force']) && empty($in['force']) && function_exists('dp_dsp_card')) { try { $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $q->execute([$lid]); $pr = $q->fetch(); if ($pr) $view['card'] = dp_dsp_card($pdo, $pr); } catch (Throwable $e) { } }   // 🧾 the Order card (not on the 30-second refresh)
        json_response($view);
    }
    case 'dp.dsp_card_shot': {   // 🧾 the payment screenshot of a Manual order (only when the card asks for it)
        $lid = (int)($_GET['id'] ?? 0); $q = $pdo->prepare("SELECT order_name FROM dp_dsp_parcels WHERE id = ?"); $q->execute([$lid]); $on = (string)$q->fetchColumn();
        if ($on === '' || !preg_match('/^WM-\d+$/i', $on)) json_response(['error' => 'Not a manual order.'], 404);
        try { $q = $pdo->prepare("SELECT shot_mime, shot_b64 FROM wmo_order WHERE order_no = ? LIMIT 1"); $q->execute([$on]); $r = $q->fetch(); } catch (Throwable $e) { $r = null; }
        if (!$r || (string)$r['shot_mime'] === '' || (string)$r['shot_b64'] === '') json_response(['error' => 'No payment screenshot was saved for this order.'], 404);
        json_response(['ok' => true, 'mime' => (string)$r['shot_mime'], 'b64' => (string)$r['shot_b64']]);
    }
    case 'dp.dsp_hub': {   // the grand popup: order card + timeline + customer card + who is waiting (order_hub.php)
        if (!function_exists('ohub_view')) json_response(['error' => 'order_hub.php is missing on this server.'], 500);
        $r = ohub_view($pdo, ['id' => $_GET['id'] ?? ($in['id'] ?? 0), 'case' => $_GET['case'] ?? ($in['case'] ?? 0), 'phone' => $_GET['phone'] ?? ($in['phone'] ?? ''), 'order' => $_GET['order'] ?? ($in['order'] ?? ''),
            'store' => $_GET['store'] ?? ($in['store'] ?? ''), 'sid' => $_GET['sid'] ?? ($in['sid'] ?? ''), 'bill' => $_GET['bill'] ?? ($in['bill'] ?? 0)]);
        if (empty($r['ok'])) json_response(['error' => $r['error'] ?? 'Not found.'], 404);
        json_response($r);
    }
    case 'dp.dsp_pay': {   // payment details of one order straight from the gateway (GoKwik etc., via Shopify): every transaction id
        if (!function_exists('ohub_pay_live')) json_response(['error' => 'order_hub.php is missing on this server.'], 500);
        $store = (string)($_GET['store'] ?? ''); $sid = (string)($_GET['sid'] ?? '');
        if (($store === '' || $sid === '') && !empty($_GET['id'])) { $q = $pdo->prepare("SELECT store_key, shopify_id FROM dp_dsp_parcels WHERE id = ?"); $q->execute([(int)$_GET['id']]); $r = $q->fetch(); if ($r) { $store = (string)$r['store_key']; $sid = (string)$r['shopify_id']; } }
        $r = ohub_pay_live($pdo, $store, $sid); if (empty($r['ok'])) json_response(['error' => $r['error'] ?? 'Could not read the payment.'], 502);
        json_response($r);
    }
    case 'dp.dsp_cust_list': {   // the Customers list: one row per person (last 10 digits of the phone)
        if (!function_exists('ohub_list')) json_response(['error' => 'order_hub.php is missing on this server.'], 500);
        json_response(ohub_list($pdo, $_GET));
    }
    case 'dp.dsp_cust_rebuild': {   // read every order again and rebuild the Customers list
        if (!function_exists('ohub_rebuild')) json_response(['error' => 'order_hub.php is missing on this server.'], 500);
        $n = ohub_rebuild($pdo); dp_put('ohub_last_ts', (string)time()); json_response(['ok' => true, 'people' => $n]);
    }
    case 'dp.order_facts': {   // everything we know about one order (by order number, Shopify id, AWB or KWIK id)
        if (!function_exists('of_get')) json_response(['error' => 'order_facts.php is missing.'], 500);
        $r = of_get($pdo, (string)($_GET['q'] ?? ($in['q'] ?? ''))); if (!$r) json_response(['error' => 'No order found for this. It may not be copied yet (the background job fills it in).'], 404);
        json_response(['order' => $r]);
    }
    case 'dp.dsp_live_info': {   // the webhook address + token: Admin only
        if (empty($w['admin'])) json_response(['error' => 'Only Admin can see the webhook token.'], 403);
        json_response(dp_live_info($pdo));
    }

    case 'dp.dsp_limits': {   // read: everybody on the page. Change: the boss only. The same setting the Boss task changes.
        if ($isPost) {
            if (!$w['manager']) json_response(['error' => 'Only the boss can change the limits.'], 403);
            $r = dp_dsp_set_limits($pdo, $in['hours'] ?? null, $in['days'] ?? null, $by, 'Parcels page', $in['pick'] ?? null);
            json_response(['ok' => true, 'message' => $r['text']] + $r);
        }
        $lastBy = json_decode((string)dp_get('dsp_limits_by', ''), true);
        json_response(['ok' => true, 'hours' => dp_dsp_track_hours(), 'days' => dp_dsp_transit_days(), 'pick' => dp_dsp_pick_hours(), 'last' => is_array($lastBy) ? $lastBy : null, 'can_edit' => (bool)$w['manager']]);
    }

    case 'dp.dsp_overview': {
        $c = dp_dsp_counts($pdo, $now);
        $live = dp_live($pdo, 'dispatch'); $ts = dp_topic_state($pdo, 'dispatch');
        $qopen = 0; try { $q = $pdo->query("SELECT COUNT(*) FROM dp_q WHERE topic = 'dispatch' AND state IN ('open','later')"); $qopen = (int)$q->fetchColumn(); } catch (Throwable $e) {}
        list($m1, $m2) = dp_month_range();
        $own = !$w['manager'];
        $pw = "kind = 'dispatch' AND rkey IS NOT NULL AND task_date BETWEEN ? AND ?" . ($own ? " AND owner_emp = " . (int)$w['emp'] : "");
        $st = $pdo->prepare("SELECT pen_state, COUNT(*) c, COALESCE(SUM(qty),0) parcels, COALESCE(SUM(pen_amount),0) a FROM dp_items WHERE $pw GROUP BY pen_state"); $st->execute([$m1, $m2]);
        $pen = ['applied' => 0, 'pending' => 0, 'shadow' => 0, 'parcels' => 0];
        foreach ($st->fetchAll() as $r) { if (isset($pen[$r['pen_state']])) $pen[$r['pen_state']] += (float)$r['a']; if (in_array($r['pen_state'], ['applied', 'pending', 'shadow'], true)) $pen['parcels'] += (int)$r['parcels']; }
        $recent = [];
        $st = $pdo->prepare("SELECT * FROM dp_items WHERE $pw ORDER BY id DESC LIMIT 8"); $st->execute([date('Y-m-d', strtotime('-60 days')), $m2]);
        foreach ($st->fetchAll() as $r) $recent[] = ['title' => $r['title'], 'detail' => $r['detail'], 'date' => $r['task_date'], 'qty' => (int)$r['qty'], 'amount' => (float)$r['pen_amount'], 'state' => $r['pen_state'], 'owner' => $r['owner_emp'] ? bd_emp_name($pdo, $r['owner_emp']) : 'nobody', 'rule' => $r['rkey']];
        $rules = []; foreach (['tracking_48h', 'transit_day4'] as $k) { $r = dp_rule($pdo, $k); if ($r) $rules[$k] = ['amount' => (float)$r['amount'], 'mode' => $r['mode'], 'active' => (int)$r['active']]; }
        $out = ['live' => $live, 'topic_state' => $ts, 'questions_open' => $qopen, 'mode' => bd_mode(),
            'role' => ['manager' => (bool)$w['manager'], 'tracking' => (bool)$roleTrack, 'lead' => (bool)$roleLead],
            'counts' => $c, 'bar' => ['late' => $c['l48'], 'h12' => $c['h12'], 'h24' => $c['h24'], 'h48' => $c['h48']],
            'penalties' => $pen, 'recent' => $recent, 'rules' => $rules, 'limits' => ['hours' => dp_dsp_track_hours(), 'days' => dp_dsp_transit_days(), 'pick' => dp_dsp_pick_hours()], 'own_penalties' => $own, 'now' => date('D j M, g:i A', $now),
            'last_sync' => (int)dp_get('dsp_last_sync_ts', '0') ? date('g:i A', (int)dp_get('dsp_last_sync_ts', '0')) : '', 'has_orders' => true,
            'holders' => ['tracking' => array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, dp_holders($pdo, 'dispatch.tracking')), 'lead' => array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, dp_holders($pdo, 'dispatch.lead'))]];
        try { $pdo->query("SELECT 1 FROM shop_orders LIMIT 1"); } catch (Throwable $e) { $out['has_orders'] = false; }
        if ($w['manager']) { $k = (string)bd_cfg_fresh('tick_key'); $out['feed'] = ['path' => 'api.php?action=dp.dsp_feed&key=' . ($k !== '' ? substr($k, 0, 3) . '…' : '(set a tick key in Boss > Setup)'), 'has_key' => $k !== '']; }
        json_response($out);
    }

    case 'dp.dsp_list': {
        $tab = (string)($_GET['tab'] ?? 'need'); if (!in_array($tab, ['need', 'transit', 'late', 'all'], true)) $tab = 'need';
        $qq = trim((string)($_GET['q'] ?? '')); $page = max(1, (int)($_GET['page'] ?? 1)); $per = 30;
        $par = []; $where = dp_dsp_filter_sql($tab, $now, $par) . " AND created_at >= ?"; $par[] = date('Y-m-d', $now - 14 * 86400);
        if ($qq !== '') { $where .= " AND (order_name LIKE ? OR tracking_no LIKE ? OR customer LIKE ? OR phone LIKE ?)"; $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], ltrim($qq, '#')) . '%'; array_push($par, $l, $l, $l, $l); }
        $st = $pdo->prepare("SELECT COUNT(*) FROM dp_dsp_parcels WHERE $where"); $st->execute($par); $total = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE $where ORDER BY " . dp_dsp_order_sql($tab) . " LIMIT " . (int)$per . " OFFSET " . (int)(($page - 1) * $per)); $st->execute($par);
        $rows = array_map(function ($r) use ($now) { return dp_dsp_row($r, $now); }, $st->fetchAll());
        json_response(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per)), 'tab' => $tab, 'counts' => dp_dsp_counts($pdo, $now)]);
    }

    case 'dp.dsp_set_tracking': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        dp_need_seat($pdo, $auth, 'dispatch.tracking', 'tracking numbers');
        $st = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]); $p = $st->fetch(); if (!$p) json_response(['error' => 'Parcel not found.'], 404);
        if (in_array($p['status'], ['cancelled', 'rto', 'delivered'], true)) json_response(['error' => 'This order is ' . $p['status'] . '. No tracking number is needed.'], 409);
        $t = trim((string)($in['tracking_no'] ?? ''));
        if ($t === '') {
            if (empty($in['clear']) || !$w['manager']) json_response(['error' => 'Type the tracking number.'], 422);
            $pdo->prepare("UPDATE dp_dsp_parcels SET tracking_no = NULL, courier = NULL, status = 'placed', tracked_at = NULL, tracked_by = NULL, transit_at = NULL WHERE id = ?")->execute([$p['id']]);
            bd_log('dispatch', $p['order_name'], 'tracking number removed', $by); json_response(['ok' => true, 'message' => 'Tracking removed.']);
        }
        if (!dp_dsp_valid_track($t)) json_response(['error' => 'That does not look like a tracking number (4 to 40 letters or digits, no spaces).'], 422);
        $used = dp_dsp_track_in_use($pdo, $t, $p['id']); if ($used !== '') json_response(['error' => 'This tracking number is already on order ' . $used . '.'], 409);
        dp_dsp_apply($pdo, $p, $t, (string)($in['courier'] ?? ''), 'tracked', $by, false);
        bd_log('dispatch', $p['order_name'], 'tracking ' . $t, $by);
        json_response(['ok' => true, 'message' => 'Saved for ' . $p['order_name'] . '.']);
    }

    case 'dp.dsp_bulk': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        dp_need_seat($pdo, $auth, 'dispatch.tracking', 'tracking numbers');
        $lines = preg_split('/\r\n|\r|\n/', (string)($in['text'] ?? '')); $lines = array_values(array_filter(array_map('trim', $lines), 'strlen'));
        if (!$lines) json_response(['error' => 'Paste one line per parcel: ORDER  TRACKING  COURIER.'], 422);
        if (count($lines) > 500) json_response(['error' => 'Paste at most 500 lines at a time.'], 422);
        $ok = 0; $errs = []; $seen = [];
        foreach ($lines as $i => $ln) {
            $tok = preg_split('/[\s,;|]+/', $ln); if (count($tok) < 2) { $errs[] = ['line' => $i + 1, 'text' => $ln, 'why' => 'Needs the order and the tracking number.']; continue; }
            $name = $tok[0]; $t = $tok[1]; $courier = implode(' ', array_slice($tok, 2));
            if (!dp_dsp_valid_track($t)) { $errs[] = ['line' => $i + 1, 'text' => $ln, 'why' => 'Not a tracking number.']; continue; }
            $rows = dp_dsp_find_by_name($pdo, $name);
            if (!$rows) { $errs[] = ['line' => $i + 1, 'text' => $ln, 'why' => 'Order not found in the last 14 days.']; continue; }
            if (count($rows) > 1 && dp_dsp_norm_order($rows[0]['order_name']) === dp_dsp_norm_order($rows[1]['order_name'])) { $errs[] = ['line' => $i + 1, 'text' => $ln, 'why' => 'This order number exists in more than one store.']; continue; }
            $p = $rows[0];
            if (in_array($p['status'], ['cancelled', 'rto', 'delivered'], true)) { $errs[] = ['line' => $i + 1, 'text' => $ln, 'why' => 'Order is ' . $p['status'] . '.']; continue; }
            $used = dp_dsp_track_in_use($pdo, $t, $p['id']); if ($used !== '' || isset($seen[$t])) { $errs[] = ['line' => $i + 1, 'text' => $ln, 'why' => 'Tracking number already used' . ($used !== '' ? ' on ' . $used : ' above') . '.']; continue; }
            $seen[$t] = 1; dp_dsp_apply($pdo, $p, $t, $courier, 'tracked', $by, false); $ok++;
        }
        bd_log('dispatch', 'bulk', $ok . ' tracking numbers pasted, ' . count($errs) . ' rejected', $by);
        json_response(['ok' => true, 'saved' => $ok, 'errors' => array_slice($errs, 0, 60), 'message' => $ok . ' saved' . ($errs ? ', ' . count($errs) . ' need a look.' : '.')]);
    }

    case 'dp.dsp_status': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        if (!$roleTrack && !$roleLead && !$w['manager']) json_response(['error' => 'Only the Dispatch seats can change this.'], 403);
        $s = (string)($in['status'] ?? ''); if (!in_array($s, ['tracked', 'transit', 'delivered', 'rto'], true)) json_response(['error' => 'Choose in transit, delivered or RTO.'], 422);
        $ids = isset($in['ids']) && is_array($in['ids']) ? array_map('intval', $in['ids']) : [(int)($in['id'] ?? 0)]; $ids = array_slice(array_values(array_filter($ids)), 0, 200);
        if (!$ids) json_response(['error' => 'Choose a parcel.'], 422);
        if ($s === 'tracked' && !$w['manager']) json_response(['error' => 'Only a boss can move a parcel back.'], 403);
        $ok = 0; $skip = [];
        foreach ($ids as $id) {
            $st = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $st->execute([$id]); $p = $st->fetch(); if (!$p) continue;
            if (in_array($p['status'], ['cancelled'], true)) { $skip[] = $p['order_name'] . ' (cancelled)'; continue; }
            if (in_array($s, ['transit', 'delivered'], true) && trim((string)$p['tracking_no']) === '') { $skip[] = $p['order_name'] . ' (no tracking number yet)'; continue; }
            if (dp_dsp_apply($pdo, $p, '', '', $s, $by, $w['manager']) !== '') $ok++;
        }
        bd_log('dispatch', 'status', $ok . ' parcels set to ' . $s, $by);
        json_response(['ok' => true, 'changed' => $ok, 'skipped' => $skip, 'message' => $ok . ' parcel' . ($ok === 1 ? '' : 's') . ' updated' . ($skip ? ', ' . count($skip) . ' skipped.' : '.')]);
    }

    case 'dp.dsp_done': {   // the "Done" button on the report: tick / un-tick parcels (who + when are kept)
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        if (!$roleTrack && !$roleLead && !$w['manager']) json_response(['error' => 'Only the Dispatch seats can tick parcels as done.'], 403);
        if (!dp_dsp_done_col($pdo)) json_response(['error' => 'The Done column could not be created. Check database permissions.'], 500);
        $ids = isset($in['ids']) && is_array($in['ids']) ? array_map('intval', $in['ids']) : [(int)($in['id'] ?? 0)]; $ids = array_slice(array_values(array_filter($ids)), 0, 300);
        if (!$ids) json_response(['error' => 'Choose a parcel.'], 422);
        $on = !isset($in['done']) || !empty($in['done']);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        if ($on) { $u = $pdo->prepare("UPDATE dp_dsp_parcels SET done_at = ?, done_by = ? WHERE id IN ($ph) AND done_at IS NULL"); $u->execute(array_merge([date('Y-m-d H:i:s', $now), mb_substr((string)$by, 0, 80)], $ids)); }
        else { $u = $pdo->prepare("UPDATE dp_dsp_parcels SET done_at = NULL, done_by = NULL WHERE id IN ($ph)"); $u->execute($ids); }
        $n = $u->rowCount(); bd_log('dispatch', 'done', $n . ' parcel(s) ' . ($on ? 'ticked as done' : 'un-ticked'), $by);
        json_response(['ok' => true, 'changed' => $n, 'done' => $on, 'by' => (string)$by, 'at' => date('Y-m-d H:i', $now)]);
    }

    case 'dp.dsp_remit': {   // "Money received": tick / un-tick the COD remittance of delivered parcels (who + when are kept)
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        if (!$roleTrack && !$roleLead && !$w['manager']) json_response(['error' => 'Only the Dispatch seats can tick remittance.'], 403);
        if (!dp_dsp_done_col($pdo)) json_response(['error' => 'The Remittance column could not be created. Check database permissions.'], 500);
        $ids = isset($in['ids']) && is_array($in['ids']) ? array_map('intval', $in['ids']) : [(int)($in['id'] ?? 0)]; $ids = array_slice(array_values(array_filter($ids)), 0, 300);
        if (!$ids) json_response(['error' => 'Choose a parcel.'], 422);
        $on = !isset($in['remit']) || !empty($in['remit']);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        if ($on) { $u = $pdo->prepare("UPDATE dp_dsp_parcels SET remit_at = ?, remit_by = ? WHERE id IN ($ph) AND status = 'delivered' AND remit_at IS NULL"); $u->execute(array_merge([date('Y-m-d H:i:s', $now), mb_substr((string)$by, 0, 80)], $ids)); }
        else { $u = $pdo->prepare("UPDATE dp_dsp_parcels SET remit_at = NULL, remit_by = NULL WHERE id IN ($ph)"); $u->execute($ids); }
        $n = $u->rowCount(); bd_log('dispatch', 'remit', $n . ' parcel(s) ' . ($on ? 'remittance received' : 'remittance un-ticked'), $by);
        json_response(['ok' => true, 'changed' => $n, 'remit' => $on, 'by' => (string)$by, 'at' => date('Y-m-d H:i', $now)]);
    }

    /* ---------- couriers: the list the tracking person picks from, with the tracking-page link of each ({ID} = the tracking ID) ---------- */
    case 'dp.dsp_couriers': {
        $all = !empty($_GET['all']) && !empty($w['admin']);
        json_response(['ok' => true, 'couriers' => dp_dsp_couriers($pdo, !$all), 'is_admin' => !empty($w['admin'])]);
    }
    case 'dp.dsp_courier_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        bd_need_admin($auth);
        $id = (int)($in['id'] ?? 0);
        if (!empty($in['remove'])) {
            if (!$id) json_response(['error' => 'Choose a courier.'], 422);
            $pdo->prepare("DELETE FROM dp_dsp_couriers WHERE id = ?")->execute([$id]); bd_log('dispatch', 'courier', 'courier ' . $id . ' removed', $by);
            json_response(['ok' => true, 'couriers' => dp_dsp_couriers($pdo, false), 'message' => 'Removed. Parcels that already used it keep the name.']);
        }
        $name = trim((string)($in['name'] ?? '')); $link = trim((string)($in['link'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) json_response(['error' => 'Give the courier a name (2 to 40 letters).'], 422);
        if ($link !== '') {
            if (strpos($link, '{ID}') === false) json_response(['error' => 'Put {ID} in the link where the tracking ID goes, for example https://courier.example/track?no={ID}'], 422);
            if (!dp_dsp_link_ok(str_replace('{ID}', 'X123', $link))) json_response(['error' => 'The link must start with http:// or https://'], 422);
        }
        $active = array_key_exists('active', $in) ? (empty($in['active']) ? 0 : 1) : 1;
        $dup = $pdo->prepare("SELECT id FROM dp_dsp_couriers WHERE LOWER(name) = LOWER(?) AND id != ?"); $dup->execute([$name, $id]); if ($dup->fetchColumn()) json_response(['error' => 'A courier with this name is already in the list.'], 409);
        if ($id) $pdo->prepare("UPDATE dp_dsp_couriers SET name = ?, link = ?, active = ? WHERE id = ?")->execute([$name, $link !== '' ? $link : null, $active, $id]);
        else $pdo->prepare("INSERT INTO dp_dsp_couriers (name, link, active, sort_no) VALUES (?,?,?,?)")->execute([$name, $link !== '' ? $link : null, $active, 50 + (int)$pdo->query("SELECT COUNT(*) FROM dp_dsp_couriers")->fetchColumn()]);
        bd_log('dispatch', 'courier', 'courier "' . $name . '" saved', $by);
        json_response(['ok' => true, 'couriers' => dp_dsp_couriers($pdo, false), 'message' => 'Saved.']);
    }

    /* ---------- a tracking ID typed by hand for a parcel sent outside Shiprocket ---------- */
    case 'dp.dsp_manual_add': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        if (!$roleTrack && !$roleLead && !$w['manager']) json_response(['error' => 'Only the Dispatch seats can add tracking IDs.'], 403);
        $st = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]); $p = $st->fetch(); if (!$p) json_response(['error' => 'Parcel not found.'], 404);
        $isAdm = !empty($w['admin']);
        if ($p['status'] !== 'placed') json_response(['error' => 'This order already has a tracking ID, or it is ' . ($p['status'] === 'tracked' || $p['status'] === 'transit' ? 'with the courier' : $p['status']) . '.'], 409);
        if (!empty($p['pend_track']) && !$isAdm) json_response(['error' => 'A tracking ID for this order already waits for Admin.'], 409);
        $t = trim((string)($in['tracking_no'] ?? '')); if ($t === '') json_response(['error' => 'Type the tracking ID.'], 422);
        if (!dp_dsp_valid_track($t)) json_response(['error' => 'That does not look like a tracking ID (4 to 40 letters or digits, no spaces).'], 422);
        $used = dp_dsp_track_in_use($pdo, $t, $p['id']); if ($used !== '') json_response(['error' => 'This tracking ID is already on order ' . $used . '.'], 409);
        $courier = trim((string)($in['courier'] ?? '')); if (mb_strlen($courier) < 2) json_response(['error' => 'Choose the courier.'], 422); $courier = mb_substr($courier, 0, 40);
        $link = trim((string)($in['link'] ?? ''));
        if ($link === '') $link = dp_dsp_link_for($pdo, $courier, $t); elseif (!dp_dsp_link_ok($link)) json_response(['error' => 'The courier link must start with http:// or https://'], 422);
        $nowS = date('Y-m-d H:i:s', $now);
        if ($isAdm) {
            $pdo->prepare("UPDATE dp_dsp_parcels SET tracking_no = ?, courier = ?, track_link = ?, status = 'transit', tracked_at = ?, tracked_by = ?, transit_at = ?, pend_track = NULL, pend_courier = ?, pend_link = ?, pend_by = ?, pend_at = ?, manual = 1 WHERE id = ? AND status = 'placed'")
                ->execute([$t, $courier, $link !== '' ? $link : null, $nowS, mb_substr($by, 0, 80), $nowS, $courier, $link !== '' ? $link : null, mb_substr($by, 0, 80), $nowS, $p['id']]);
            bd_log('dispatch', $p['order_name'], 'tracking ID ' . $t . ' (' . $courier . ') added and approved by hand', $by); $msg = 'Added and approved. ' . $p['order_name'] . ' is now in transit.';
        } else {
            $pdo->prepare("UPDATE dp_dsp_parcels SET pend_track = ?, pend_courier = ?, pend_link = ?, pend_by = ?, pend_at = ?, manual = 1 WHERE id = ? AND status = 'placed'")
                ->execute([$t, $courier, $link !== '' ? $link : null, mb_substr($by, 0, 80), $nowS, $p['id']]);
            bd_log('dispatch', $p['order_name'], 'tracking ID ' . $t . ' (' . $courier . ') sent to Admin', $by);
            bd_inbox('dp_note', 'Tracking ID waits for your OK (Dispatch)', $p['order_name'] . ': ' . $courier . ' ' . $t . ' added by ' . $by . '. Open Parcels and tracking, "Waiting for Admin", and press Approve.', [], null, null, 'dsp:pend:' . date('Ymd-H', $now));
            $msg = 'Sent to Admin. ' . $p['order_name'] . ' moves to In transit when Admin approves.';
        }
        $job = dp_dsp_job_refresh($pdo, $now);
        json_response(['ok' => true, 'approved' => $isAdm, 'message' => $msg, 'job' => $job]);
    }
    case 'dp.dsp_manual_decide': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        bd_need_admin($auth);
        $dec = (string)($in['decision'] ?? ''); if (!in_array($dec, ['approve', 'reject'], true)) json_response(['error' => 'Choose approve or reject.'], 422);
        $ids = isset($in['ids']) && is_array($in['ids']) ? array_map('intval', $in['ids']) : [(int)($in['id'] ?? 0)]; $ids = array_slice(array_values(array_filter($ids)), 0, 200);
        if (!$ids) json_response(['error' => 'Choose a parcel.'], 422);
        $reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 200); $ok = 0; $skip = 0; $nowS = date('Y-m-d H:i:s', $now);
        foreach ($ids as $id) {
            $st = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $st->execute([$id]); $p = $st->fetch();
            if (!$p || $p['status'] !== 'placed' || (string)$p['pend_track'] === '') { $skip++; continue; }
            if ($dec === 'approve') {
                $pdo->prepare("UPDATE dp_dsp_parcels SET tracking_no = ?, courier = ?, track_link = ?, status = 'transit', tracked_at = COALESCE(pend_at, ?), tracked_by = COALESCE(pend_by, ?), transit_at = ?, pend_track = NULL, manual = 1 WHERE id = ? AND status = 'placed' AND pend_track IS NOT NULL")
                    ->execute([$p['pend_track'], $p['pend_courier'], $p['pend_link'], $nowS, mb_substr($by, 0, 80), $nowS, $p['id']]);
                bd_log('dispatch', $p['order_name'], 'tracking ID ' . $p['pend_track'] . ' approved', $by);
            } else {
                $pdo->prepare("UPDATE dp_dsp_parcels SET pend_track = NULL, pend_courier = NULL, pend_link = NULL, pend_by = NULL, pend_at = NULL, manual = 0 WHERE id = ? AND pend_track IS NOT NULL")->execute([$p['id']]);
                bd_log('dispatch', $p['order_name'], 'tracking ID ' . $p['pend_track'] . ' rejected' . ($reason !== '' ? ': ' . $reason : ''), $by);
                dp_dsp_push_holders($pdo, 'dispatch.tracking', 'Tracking ID rejected', $p['order_name'] . ': Admin rejected ' . $p['pend_track'] . ($reason !== '' ? ' (' . $reason . ')' : '') . '. Please add the right one.');
            }
            $ok++;
        }
        $job = dp_dsp_job_refresh($pdo, $now);
        json_response(['ok' => true, 'changed' => $ok, 'skipped' => $skip, 'message' => $ok . ' tracking ID' . ($ok === 1 ? '' : 's') . ($dec === 'approve' ? ' approved. The parcel' . ($ok === 1 ? ' is' : 's are') . ' now in transit.' : ' rejected. The Tracking person was told.'), 'job' => $job]);
    }

    /* ---------- untraceable parcels: the daily remark, the end of a case, who is responsible ---------- */
    case 'dp.dsp_untr_people': {   // the people the owner can hand a parcel to
        if (!$w['manager'] && !$seatUntr) json_response(['error' => 'Only the untraceable owner, the backup and bosses can do this.'], 403);
        $o = []; foreach (bd_employees($pdo) as $e) $o[] = ['id' => (int)$e['id'], 'name' => (string)$e['name']];
        json_response(['ok' => true, 'people' => $o]);
    }
    case 'dp.dsp_untr_action': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $L = dp_dsp_untr_daily_actions(); $act = (string)($in['action'] ?? ''); if (!isset($L[$act])) json_response(['error' => 'Choose what was done today.'], 422);
        $st = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]); $p = $st->fetch(); if (!$p) json_response(['error' => 'Parcel not found.'], 404);
        $mayUntr($p['id']);
        if (!preg_match('/untrace/i', (string)$p['sr_status']) || !in_array($p['status'], ['tracked', 'transit'], true)) json_response(['error' => 'This parcel is not untraceable any more (Shiprocket status: ' . ((string)$p['sr_status'] !== '' ? $p['sr_status'] : 'none') . ').'], 409);
        $cs = $pdo->prepare("SELECT ended_at FROM dp_dsp_untr_case WHERE parcel_id = ?"); $cs->execute([$p['id']]); if ($cs->fetchColumn()) json_response(['error' => 'This case is already ended.'], 409);
        $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 300); if (mb_strlen($note) < 3) json_response(['error' => 'Write the remark: what was done or what you heard.'], 422);
        $chk = trim((string)($in['check_date'] ?? '')); if ($chk !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $chk)) json_response(['error' => 'The check date is not a date.'], 422);
        if ($chk !== '' && $chk < date('Y-m-d', $now)) json_response(['error' => 'The check date cannot be in the past.'], 422);
        $pdo->prepare("INSERT INTO dp_dsp_untr (parcel_id, action, note, check_date, by_user, by_emp, for_date, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$p['id'], $act, $note, $chk !== '' ? $chk : null, mb_substr($byName, 0, 80), !empty($w['emp']) ? (int)$w['emp'] : null, date('Y-m-d', $now), date('Y-m-d H:i:s', $now)]);
        bd_log('dispatch', $p['order_name'], 'untraceable remark: ' . $L[$act] . ' - ' . $note, $by);
        $job = dp_dsp_untr_job($pdo, $now);
        json_response(['ok' => true, 'message' => 'Today’s remark saved for ' . $p['order_name'] . '.']);
    }
    case 'dp.dsp_untr_end': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $L = dp_dsp_untr_end_actions(); $how = (string)($in['how'] ?? ''); if (!isset($L[$how])) json_response(['error' => 'Choose how the case ended: found, reshipped or refunded.'], 422);
        $st = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]); $p = $st->fetch(); if (!$p) json_response(['error' => 'Parcel not found.'], 404);
        $mayUntr($p['id']);
        $cs = $pdo->prepare("SELECT ended_at FROM dp_dsp_untr_case WHERE parcel_id = ?"); $cs->execute([$p['id']]); if ($cs->fetchColumn()) json_response(['error' => 'This case is already ended.'], 409);
        $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 300);
        $pdo->prepare("INSERT INTO dp_dsp_untr (parcel_id, action, note, check_date, by_user, by_emp, for_date, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$p['id'], $how, $note !== '' ? $note : null, null, mb_substr($byName, 0, 80), !empty($w['emp']) ? (int)$w['emp'] : null, date('Y-m-d', $now), date('Y-m-d H:i:s', $now)]);
        dp_dsp_untr_end($pdo, $p['id'], $how, $byName);
        bd_log('dispatch', $p['order_name'], 'untraceable case ended: ' . $L[$how] . ($note !== '' ? ' - ' . $note : ''), $by);
        $job = dp_dsp_untr_job($pdo, $now);
        json_response(['ok' => true, 'message' => $p['order_name'] . ': ' . $L[$how] . '. The daily remark stops for this parcel.']);
    }
    case 'dp.dsp_untr_assign': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        if (!$w['manager'] && !$seatUntr) json_response(['error' => 'Only the untraceable owner, the backup and bosses can hand a parcel to somebody else.'], 403);
        $st = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]); $p = $st->fetch(); if (!$p) json_response(['error' => 'Parcel not found.'], 404);
        $emp = (int)($in['emp_id'] ?? 0);
        if ($emp) { $e = bd_emp($pdo, $emp); if (!$e || !bd_emp_active($e)) json_response(['error' => 'That person is not active.'], 422); }
        dp_dsp_untr_assign($pdo, $p['id'], $emp, $byName);
        if ($emp) bd_push($pdo, $emp, 'Untraceable parcel ' . $p['order_name'] . ' is yours', 'Write a remark on it every day by ' . date('g A', strtotime(DP_DSP_UNTR_CUT . ':00')) . '. Open Parcels and tracking, group 1.');
        bd_log('dispatch', $p['order_name'], 'untraceable parcel given to ' . ($emp ? bd_emp_name($pdo, $emp) : 'the owner seat'), $by);
        $job = dp_dsp_untr_job($pdo, $now);
        json_response(['ok' => true, 'message' => $p['order_name'] . ' is now ' . ($emp ? 'with ' . bd_emp_name($pdo, $emp) . '.' : 'with the Untraceable owner again.')]);
    }

    case 'dp.dsp_report': {
        $g = $_GET; $g['export'] = !empty($_GET['export']);
        json_response(['ok' => true] + dp_dsp_report($pdo, $g, $now) + ['now' => date('D j M, g:i A', $now), 'role' => ['manager' => (bool)$w['manager'], 'tracking' => (bool)$roleTrack, 'lead' => (bool)$roleLead, 'admin' => !empty($w['admin']), 'untr' => (bool)$roleUntr, 'untr_seat' => (bool)$seatUntr, 'emp' => (int)($w['emp'] ?? 0)], 'live' => dp_live($pdo, 'dispatch'), 'mode' => bd_mode(), 'questions_open' => (function () use ($pdo) { try { return (int)$pdo->query("SELECT COUNT(*) FROM dp_q WHERE topic = 'dispatch' AND state IN ('open','later')")->fetchColumn(); } catch (Throwable $e) { return 0; } })(), 'last_sync' => (int)dp_get('dsp_last_sync_ts', '0') ? date('g:i A', (int)dp_get('dsp_last_sync_ts', '0')) : '']);
    }
    case 'dp.dsp_problems': {
        if (!$w['manager']) json_response(['error' => 'Only bosses see this.'], 403);
        json_response(['ok' => true, 'problems' => dp_dsp_problems($pdo, $w, $now), 'shiprocket' => dp_dsp_sr_info(), 'is_admin' => !empty($w['admin'])]);
    }
    case 'dp.dsp_sr_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        bd_need_admin($auth);
        $e = trim((string)($in['email'] ?? '')); $p = (string)($in['password'] ?? '');
        if (!empty($in['remove'])) { dp_put('sr_email', ''); dp_put('sr_pass', ''); dp_put('sr_token', ''); dp_put('sr_last_err', ''); bd_log('dispatch', 'shiprocket', 'Shiprocket login removed', $by); json_response(['ok' => true, 'message' => 'Shiprocket login removed.']); }
        if ($e === '' || strpos($e, '@') === false) json_response(['error' => 'Type the Shiprocket API user email.'], 422);
        if ($p === '' && $e !== (string)dp_get('sr_email', '')) json_response(['error' => 'Type the password too.'], 422);
        if (mb_strlen($e) > 120 || mb_strlen($p) > 120) json_response(['error' => 'That is too long.'], 422);
        dp_put('sr_email', $e); if ($p !== '') dp_put('sr_pass', $p); dp_put('sr_token', ''); dp_put('sr_last_err', '');
        bd_log('dispatch', 'shiprocket', 'Shiprocket login saved (' . $e . ')', $by);
        json_response(['ok' => true, 'message' => 'Saved. Press "Test connection".']);
    }
    case 'dp.dsp_sr_test': {
        if (!$w['manager']) json_response(['error' => 'Only bosses can do this.'], 403);
        $cfg = dp_dsp_sr_cfg(); if (!$cfg['ok']) json_response(['ok' => false, 'message' => 'Shiprocket email and password are not saved yet.']);
        list($tok, $err) = dp_dsp_sr_token(true); if (!$tok) { dp_put('sr_last_err', $err); json_response(['ok' => false, 'message' => $err]); }
        list($j, $err) = dp_dsp_sr_get('/v1/external/orders', ['page' => 1, 'per_page' => 1]); if (!$j) { dp_put('sr_last_err', $err); json_response(['ok' => false, 'message' => 'Login works, but reading orders failed: ' . $err]); }
        dp_put('sr_last_err', ''); $d = isset($j['data'][0]) && is_array($j['data'][0]) ? dp_dsp_sr_pick($j['data'][0]) : null;
        json_response(['ok' => true, 'message' => 'Connected. ' . ($d ? 'Latest order seen: ' . ($d[0] ?: '?') . ', status ' . ($d[3] ?: '?') . ($d[1] !== '' ? ', AWB ' . $d[1] : ', no AWB yet') . '.' : 'Shiprocket has no orders yet.')]);
    }
    case 'dp.dsp_sr_sync': {
        if (!$w['manager']) json_response(['error' => 'Only bosses can do this.'], 403);
        $r = dp_dsp_sr_sync($pdo, max(1, min(90, (int)($in['days'] ?? 30))));
        json_response(['ok' => $r['ok'], 'message' => $r['ok'] ? $r['seen'] . ' Shiprocket orders read, ' . $r['updated'] . ' parcels updated' . ($r['unmatched'] ? ', ' . $r['unmatched'] . ' not found here' . ($r['examples'] ? ' (e.g. ' . implode(', ', array_slice($r['examples'], 0, 3)) . ')' : '') : '') . '.' : $r['error'], 'result' => $r]);
    }

    case 'dp.dsp_reconcile': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        if (!$w['manager']) json_response(['error' => 'Only bosses can do this.'], 403);
        json_response(['ok' => true] + dp_dsp_reconcile($pdo, $now, !empty($in['restart']), max(1, min(25, (int)($in['budget'] ?? 12)))));
    }
    case 'dp.dsp_sync': {
        if (!$w['manager']) json_response(['error' => 'Only bosses can do this.'], 403);
        list($new, $canc) = dp_dsp_sync($pdo, $now, true);
        if ($new < 0) json_response(['error' => 'The order cache (shop_orders) was not found on this server.'], 409);
        json_response(['ok' => true, 'new' => $new, 'cancelled' => $canc, 'message' => $new . ' new order' . ($new === 1 ? '' : 's') . ' read.']);
    }

    default: json_response(['error' => 'Unknown action: ' . $action], 404);
    }
}
