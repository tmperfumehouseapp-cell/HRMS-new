<?php
/* Dispatch: the report (any range / time window / basis), the Shiprocket link and the "what is wrong" checks.
   Loaded by dept_dispatch.php (functions only, no routing). PHP 7.4-safe.

   Shiprocket: the HRMS logs in with an "API user" (email + password made in Shiprocket), reads the orders of the last days and copies the
   AWB (tracking number), courier and status onto the matching parcel. Credentials come from config.php
   (define('SHIPROCKET_EMAIL','...'); define('SHIPROCKET_PASSWORD','...');) or from the box on the Dispatch page (Admin / HR only).
   Nothing here deletes anything. A parcel only moves forward (no tracking -> tracking -> dispatched -> delivered); RTO / cancelled are taken as told. */

if (!defined('DP_DSP_WIN')) define('DP_DSP_WIN', 60);
if (!defined('DP_DSP_NOID_DAYS')) define('DP_DSP_NOID_DAYS', 10);   // a parcel with no tracking ID that is older than this is NOT counted as a tracking-ID problem (the ID can still be added later)

/* ============================== Shiprocket ============================== */
function dp_dsp_sr_cfg() {
    $e = trim((string)tmph_cfg('SHIPROCKET_EMAIL', '')); $p = (string)tmph_cfg('SHIPROCKET_PASSWORD', ''); $src = ($e !== '' && $p !== '') ? 'config' : '';
    if ($src === '') { $e = trim((string)dp_get('sr_email', '')); $p = trim((string)dp_get('sr_pass', '')); if ($e !== '' && $p !== '') $src = 'page'; }
    return ['email' => $e, 'pass' => $p, 'source' => $src, 'ok' => $src !== '', 'base' => rtrim((string)tmph_cfg('SHIPROCKET_BASE', 'https://apiv2.shiprocket.in'), '/')];
}
function dp_dsp_sr_http($method, $url, array $headers, $body = null) {
    if (!function_exists('curl_init')) return [0, null, 'PHP curl is not switched on on this server.'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers)]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = $out === false ? curl_error($ch) : ''; curl_close($ch);
    $j = ($out !== false && $out !== '') ? json_decode($out, true) : null;
    return [$code, is_array($j) ? $j : null, $err];
}
/** returns [token|null, error text] */
function dp_dsp_sr_token($force = false) {
    $c = dp_dsp_sr_cfg(); if (!$c['ok']) return [null, 'Shiprocket email and password are not saved.'];
    // 🔑 Shiprocket's newer API users get an "API key" by email instead of a password. When what was saved is itself a ready access token (a JWT: three parts joined by dots, starting "eyJ"),
    // it is used as it is — there is nothing to log in with, and sending it as a password only gets "Invalid email and password combination".
    if (preg_match('/^eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $c['pass'])) return [$c['pass'], ''];
    $tok = (string)dp_get('sr_token', ''); $exp = (int)dp_get('sr_token_exp', '0'); $sig = (string)dp_get('sr_token_for', '');
    if (!$force && $tok !== '' && $exp > time() + 300 && $sig === md5($c['email'] . '|' . $c['pass'])) return [$tok, ''];
    // 🛑 Shiprocket BLOCKS an account after too many wrong logins. A refused login used to be tried again on every sync (every few minutes) and on every page, which is exactly what got the
    // account blocked. Now a refused login is remembered for 30 minutes for THIS email + password: nothing is sent to Shiprocket until then, or until a new email / password is saved.
    $__sig = md5($c['email'] . '|' . $c['pass']); $__bl = explode('|', (string)dp_get('sr_login_hold', ''), 3);
    if (!$force && count($__bl) === 3 && $__bl[0] === $__sig && (int)$__bl[1] > time()) return [null, $__bl[2] . ' The HRMS is not trying again for ' . max(1, (int)ceil(((int)$__bl[1] - time()) / 60)) . ' min, so the account is not blocked again; save the correct API email and password to try at once.'];
    list($code, $j, $err) = dp_dsp_sr_http('POST', $c['base'] . '/v1/external/auth/login', [], ['email' => $c['email'], 'password' => $c['pass']]);
    if ($err !== '') return [null, 'Could not reach Shiprocket: ' . $err];
    if ($code === 200 && is_array($j) && !empty($j['token'])) { dp_put('sr_token', $j['token']); dp_put('sr_token_exp', (string)(time() + 8 * 86400)); dp_put('sr_token_for', md5($c['email'] . '|' . $c['pass'])); return [(string)$j['token'], '']; }
    $msg = is_array($j) ? (string)($j['message'] ?? '') : '';
    if ($code === 200 && is_array($j) && !empty($j['token'])) dp_put('sr_login_hold', '');
    if ($code === 403 || $code === 401 || $code === 422) { $__pl = strlen($c['pass']); $__shape = $__pl . ' characters, ' . (preg_match('/^[0-9a-f]+$/i', $c['pass']) ? 'only 0-9 and a-f (looks like an API key, not a typed password)' : (preg_match('/^[A-Za-z0-9]+$/', $c['pass']) ? 'letters and digits' : (strpos($c['pass'], '.') !== false ? 'has dots in it' : 'letters, digits and symbols')));   // the SHAPE of what was saved, never the value: it tells a password from a key
            $__m = 'Shiprocket refused the email / password' . ($msg !== '' ? ' (' . mb_substr($msg, 0, 120) . ')' : '') . '. Login tried: ' . $c['email'] . ' with a secret of ' . $__shape . '. Use the API user email and password from Shiprocket, not your normal login.'; dp_put('sr_login_hold', $__sig . '|' . (time() + 1800) . '|' . str_replace('|', '/', $__m)); return [null, $__m]; }
    return [null, 'Shiprocket login failed (HTTP ' . $code . ')' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 120) : '') . '.'];
}
/** GET on the Shiprocket API. Returns [json|null, error text] */
function dp_dsp_sr_get($path, array $params) {
    $c = dp_dsp_sr_cfg();
    for ($try = 0; $try < 2; $try++) {
        list($tok, $e) = dp_dsp_sr_token($try > 0); if (!$tok) return [null, $e];
        list($code, $j, $err) = dp_dsp_sr_http('GET', $c['base'] . $path . ($params ? '?' . http_build_query($params) : ''), ['Authorization: Bearer ' . $tok]);
        if ($err !== '') return [null, 'Could not reach Shiprocket: ' . $err];
        if ($code === 401 && $try === 0) continue;
        if ($code === 200 && is_array($j)) return [$j, ''];
        if ($code === 429) return [null, 'Shiprocket says too many requests. It will try again in a few minutes.'];
        return [null, 'Shiprocket answered HTTP ' . $code . (is_array($j) && !empty($j['message']) ? ': ' . mb_substr((string)$j['message'], 0, 120) : '') . '.'];
    }
    return [null, 'Shiprocket did not accept the login token.'];
}
/** Shiprocket order status -> our status. "Ready to ship" / "pickup scheduled" are NOT dispatched yet. */
function dp_dsp_sr_map($s, $hasAwb) {
    $s = strtolower(trim((string)$s)); if ($s === '') return $hasAwb ? 'tracked' : 'placed';
    if (strpos($s, 'cancel') !== false) return 'cancelled';
    if (strpos($s, 'rto') !== false || strpos($s, 'return') !== false) return 'rto';
    if (strpos($s, 'deliver') !== false && strpos($s, 'out for') === false && strpos($s, 'undeliver') === false && strpos($s, 'not deliver') === false) return 'delivered';
    foreach (['picked up', 'in transit', 'shipped', 'out for delivery', 'reached', 'destination', 'undelivered', 'delayed', 'misrouted', 'handover', 'in-transit', 'untrace', 'lost', 'damag'] as $w) if (strpos($s, $w) !== false) return 'transit';
    return $hasAwb ? 'tracked' : 'placed';
}
function dp_dsp_sr_pick(array $o) {
    $sh = $o['shipments'] ?? null; if (is_array($sh) && isset($sh['awb'])) $sh = [$sh]; if (!is_array($sh)) $sh = [];
    $s0 = $sh ? (is_array(reset($sh)) ? reset($sh) : []) : [];
    $awb = ''; foreach ([$s0['awb'] ?? '', $s0['awb_code'] ?? '', $o['awb_code'] ?? '', $o['awb'] ?? ''] as $v) { if (trim((string)$v) !== '') { $awb = trim((string)$v); break; } }
    $cour = ''; foreach ([$s0['courier'] ?? '', $s0['courier_name'] ?? '', $o['courier_name'] ?? '', $o['courier'] ?? ''] as $v) { if (is_string($v) && trim($v) !== '') { $cour = trim($v); break; } }
    $st = (string)($o['status'] ?? ''); if ($st === '' && isset($s0['status'])) $st = (string)$s0['status'];
    return [(string)($o['channel_order_id'] ?? ''), $awb, $cour, $st];
}
/** last 10 digits of a phone number ('' when there are fewer) - used to tell two orders with the same number apart */
function dp_dsp_tail($s) { $d = preg_replace('/\D+/', '', (string)$s); return strlen($d) >= 10 ? substr($d, -10) : ''; }
/** the parcels created between two dates, indexed by order number (and by its digits as a second chance) */
function dp_dsp_sr_index(PDO $pdo, $fromDate, $toDate) {
    $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE created_at >= ? AND created_at < ?"); $q->execute([$fromDate, $toDate]);
    $ix = ['n' => [], 'd' => []];
    foreach ($q->fetchAll() as $p) { $n = dp_dsp_norm_order($p['order_name']); $ix['n'][$n][] = $p; $d = preg_replace('/\D+/', '', $n); if ($d !== '') $ix['d'][$d][] = $p; }
    return $ix;
}
/** the ONE parcel a Shiprocket order belongs to, or null. Same number in two stores -> the customer phone decides. */
function dp_dsp_sr_find(array $ix, array $o, $name) {
    $n = dp_dsp_norm_order($name); $rows = $ix['n'][$n] ?? [];
    if (!$rows) { $d = preg_replace('/\D+/', '', $n); if (strlen($d) >= 3 && count($ix['d'][$d] ?? []) === 1) $rows = $ix['d'][$d]; }   // "TM-1042" in Shiprocket, "#1042" here
    if (count($rows) > 1) { $t = dp_dsp_tail($o['customer_phone'] ?? ($o['billing_phone'] ?? '')); if ($t !== '') { $f = array_values(array_filter($rows, function ($p) use ($t) { return dp_dsp_tail($p['phone'] ?? '') === $t; })); if (count($f) === 1) $rows = $f; } }
    return count($rows) === 1 ? $rows[0] : null;
}
/** copy one Shiprocket order (AWB, courier, status) onto its parcel. Returns what changed ('' = nothing). */
function dp_dsp_sr_apply(PDO $pdo, array $p, array $o) {
    list($name, $awb, $cour, $st) = dp_dsp_sr_pick($o);
    $new = dp_dsp_sr_map($st, $awb !== '');
    if ($awb !== '' && !dp_dsp_valid_track($awb)) $awb = '';
    $did = dp_dsp_apply($pdo, $p, $awb, $cour, $new, 'Shiprocket', false);
    if ($st !== '' && $st !== (string)($p['sr_status'] ?? '')) { $pdo->prepare("UPDATE dp_dsp_parcels SET sr_status = ?, sr_at = ? WHERE id = ?")->execute([mb_substr($st, 0, 40), bd_now(), $p['id']]); if ($did === '') $did = 'status text'; }
    return $did;
}
/** read ONE page of Shiprocket orders for a date window and apply it. Returns [orders on the page, error, total pages]. $r counts seen / updated / same / unmatched. */
function dp_dsp_sr_page(PDO $pdo, $from, $to, $page, array $ix, array &$r) {
    list($j, $e) = dp_dsp_sr_get('/v1/external/orders', ['page' => $page, 'per_page' => 100, 'from' => $from, 'to' => $to, 'sort' => 'DESC']);
    if (!$j) return [0, $e, 0];
    $list = isset($j['data']) && is_array($j['data']) ? $j['data'] : [];
    foreach ($list as $o) {
        if (!is_array($o)) continue; $r['seen']++;
        $name = dp_dsp_sr_pick($o)[0]; $p = dp_dsp_sr_find($ix, $o, $name);
        if (!$p) { $r['unmatched']++; if (count($r['examples']) < 8) $r['examples'][] = $name; continue; }
        $did = dp_dsp_sr_apply($pdo, $p, $o);
        if ($did !== '') $r['updated']++; else $r['same']++;
    }
    return [count($list), '', (int)($j['meta']['pagination']['total_pages'] ?? 0)];
}
function dp_dsp_sr_blank() { return ['ok' => false, 'seen' => 0, 'updated' => 0, 'same' => 0, 'unmatched' => 0, 'examples' => [], 'error' => '', 'pages' => 0]; }
/** read the newest Shiprocket orders of the last $days days (at most $maxPages x 100 orders) and copy AWB / courier / status onto our parcels.
 *  This is the quick read, every 10 minutes (same beat as the Shopify order read). The deep read that has no order limit is dp_dsp_reconcile(). */
function dp_dsp_sr_sync(PDO $pdo, $days = 30, $maxPages = 40) {
    $r = dp_dsp_sr_blank();
    $c = dp_dsp_sr_cfg(); if (!$c['ok']) { $r['error'] = 'Shiprocket email and password are not saved.'; return $r; }
    $days = max(1, min(90, (int)$days)); $from = date('Y-m-d', time() - $days * 86400); $to = date('Y-m-d', time() + 86400);
    $ix = dp_dsp_sr_index($pdo, date('Y-m-d', time() - ($days + 3) * 86400), date('Y-m-d', time() + 2 * 86400));
    $page = 1; $per = 100;
    while ($page <= $maxPages) {
        list($n, $e, $tp) = dp_dsp_sr_page($pdo, $from, $to, $page, $ix, $r);
        if ($e !== '') { $r['error'] = $e; break; }
        $r['pages'] = $page;
        if ($n < $per || ($tp && $page >= $tp)) break;
        $page++;
    }
    $r['ok'] = $r['error'] === '';
    dp_dsp_sr_note($r);
    return $r;
}
/** remember what the last Shiprocket read saw (the page shows it) */
function dp_dsp_sr_note(array $r) {
    dp_put('sr_last_ts', (string)time()); dp_put('sr_last_err', $r['error']);
    dp_put('sr_last_msg', $r['ok'] ? $r['seen'] . ' Shiprocket orders read, ' . $r['updated'] . ' parcels updated, ' . $r['unmatched'] . ' not found here.' : '');
    dp_put('sr_last_unmatched', $r['unmatched'] . '|' . implode(', ', $r['examples'])); dp_put('sr_last_seen', (string)$r['seen']);
    if ($r['ok'] && $r['updated'] > 0) bd_log('dispatch', 'shiprocket', $r['updated'] . ' updated from Shiprocket (' . $r['seen'] . ' read)', 'Shiprocket');
}

/* ============================== couriers (name + tracking-page link) ============================== */
/** The courier list the tracking person picks from. The link has {ID} in it where the tracking ID goes. Admin / HR edit the list. */
function dp_dsp_couriers(PDO $pdo, $activeOnly = false) {
    try { $rows = $pdo->query("SELECT id, name, link, active, sort_no FROM dp_dsp_couriers" . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY sort_no, name")->fetchAll(); }
    catch (Throwable $e) { $rows = []; }
    return array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'link' => (string)$r['link'], 'active' => (int)$r['active']]; }, $rows);
}
function dp_dsp_courier_map(PDO $pdo) { $m = []; foreach (dp_dsp_couriers($pdo) as $c) $m[strtolower($c['name'])] = $c['link']; return $m; }
function dp_dsp_link_make($pattern, $track) {
    $pattern = trim((string)$pattern); $track = trim((string)$track);
    if ($pattern === '' || $track === '' || strpos($pattern, '{ID}') === false) return '';
    return str_replace('{ID}', rawurlencode($track), $pattern);
}
function dp_dsp_link_for(PDO $pdo, $courier, $track) { $m = dp_dsp_courier_map($pdo); return dp_dsp_link_make($m[strtolower(trim((string)$courier))] ?? '', $track); }
function dp_dsp_link_ok($u) { return (bool)preg_match('~^https?://[^\s<>"\']{4,290}$~i', (string)$u); }

/* ============================== untraceable parcels: one owner, a remark every day, a history ============================== */
if (!defined('DP_DSP_UNTR_CUT')) define('DP_DSP_UNTR_CUT', 18);        // the remark of a day is due by 6 PM
if (!defined('DP_DSP_UNTR_REMIND')) define('DP_DSP_UNTR_REMIND', 17);  // the reminder goes at 5 PM
/** what the owner can write each day */
function dp_dsp_untr_daily_actions() {
    return ['contacted' => 'Courier contacted', 'pickup' => 'Pickup re-requested', 'informed' => 'Customer informed', 'waiting' => 'Waiting for courier'];
}
/** the three ways a case ends (the daily remark then stops) */
function dp_dsp_untr_end_actions() {
    return ['found' => 'Found / delivered', 'reshipped' => 'Reshipped', 'refunded' => 'Refunded'];
}
function dp_dsp_untr_actions() { return dp_dsp_untr_daily_actions() + dp_dsp_untr_end_actions(); }
/** the last $n days that count (Sundays do not), oldest first, ending at $endDate */
function dp_dsp_untr_days($endDate, $n) {
    $o = []; $t = strtotime($endDate); $g = 0;
    while (count($o) < $n && $g++ < 40) { if ((int)date('N', $t) !== 7) array_unshift($o, date('Y-m-d', $t)); $t = strtotime('-1 day', $t); }
    return $o;
}
/** who gets the untraceable parcels that nobody was picked for: the owner, else the backup (the first one who is not away) */
function dp_dsp_untr_default_owner(PDO $pdo, $date) {
    foreach (['dispatch.untraceable', 'dispatch.untraceable_backup'] as $s) { foreach (dp_holders($pdo, $s) as $h) { if (bd_away($pdo, $h, $date) === '') return (int)$h; } }
    foreach (['dispatch.untraceable', 'dispatch.untraceable_backup'] as $s) { $o = dp_owner_for($pdo, $s, $date); if ($o) return (int)$o; }
    return 0;
}
/** the SQL that finds the untraceable parcels that are still open (no ended case) */
function dp_dsp_untr_open_sql() {
    return "p.status IN ('tracked','transit') AND COALESCE(p.sr_status, '') REGEXP 'untrace' AND (c.ended_at IS NULL)";
}
function dp_dsp_untr_assign(PDO $pdo, $pid, $emp, $by) {
    $pdo->prepare("INSERT IGNORE INTO dp_dsp_untr_case (parcel_id) VALUES (?)")->execute([(int)$pid]);
    $pdo->prepare("UPDATE dp_dsp_untr_case SET owner_emp = ?, owner_by = ?, owner_at = ? WHERE parcel_id = ?")->execute([$emp ? (int)$emp : null, mb_substr($by, 0, 80), date('Y-m-d H:i:s'), (int)$pid]);
}
function dp_dsp_untr_end(PDO $pdo, $pid, $how, $by) {
    $pdo->prepare("INSERT IGNORE INTO dp_dsp_untr_case (parcel_id) VALUES (?)")->execute([(int)$pid]);
    $pdo->prepare("UPDATE dp_dsp_untr_case SET ended_at = ?, ended_how = ?, ended_by = ? WHERE parcel_id = ?")->execute([date('Y-m-d H:i:s'), $how, mb_substr($by, 0, 80), (int)$pid]);
}
/** what the page shows for each untraceable parcel: owner, today's remark, the dots of the last 7 days and the whole history. Returns [parcel_id => info] */
function dp_dsp_untr_info(PDO $pdo, array $parcels, $now) {
    $out = []; $ids = array_values(array_filter(array_map(function ($r) { return (int)$r['id']; }, $parcels))); if (!$ids) return $out;
    $in = implode(',', $ids); $today = date('Y-m-d', $now); $past = $now >= strtotime($today . ' ' . DP_DSP_UNTR_CUT . ':00:00');
    $rows = []; $cases = []; $pens = [];
    try {
        foreach ($pdo->query("SELECT u.*, COALESCE(u.for_date, DATE(u.created_at)) fd FROM dp_dsp_untr u WHERE u.parcel_id IN ($in) ORDER BY u.id DESC")->fetchAll() as $r) $rows[(int)$r['parcel_id']][] = $r;
        foreach ($pdo->query("SELECT * FROM dp_dsp_untr_case WHERE parcel_id IN ($in)")->fetchAll() as $r) $cases[(int)$r['parcel_id']] = $r;
        foreach ($pdo->query("SELECT m.parcel_id, m.day, i.pen_state, i.pen_amount, i.qty FROM dp_dsp_untr_miss m LEFT JOIN dp_items i ON i.id = m.item_id WHERE m.parcel_id IN ($in)")->fetchAll() as $r) $pens[(int)$r['parcel_id']][(string)$r['day']] = $r;
    } catch (Throwable $e) {}
    $dflt = dp_dsp_untr_default_owner($pdo, $today); $L = dp_dsp_untr_actions(); $dots7 = dp_dsp_untr_days($today, 7); $tl = dp_dsp_untr_days($today, 14);
    foreach ($parcels as $p) {
        $id = (int)$p['id']; $mine = $rows[$id] ?? []; $case = $cases[$id] ?? null;
        $since = date('Y-m-d', strtotime((string)($p['sr_at'] ?: $p['created_at']))); $byDay = [];
        foreach ($mine as $r) $byDay[(string)$r['fd']][] = $r;
        $dots = [];
        foreach ($dots7 as $d) {
            if (isset($byDay[$d])) $s = 'ok';
            elseif ($d <= $since) $s = 'na';
            elseif ($d < $today || ($d === $today && $past)) $s = 'miss';
            else $s = 'todo';
            $dots[] = ['d' => $d, 'dow' => date('D', strtotime($d)), 's' => $s];
        }
        $hist = [];
        foreach (array_slice($mine, 0, 40) as $r) {
            $hist[] = ['k' => in_array($r['action'], ['found', 'reshipped', 'refunded'], true) ? 'end' : 'remark', 'date' => (string)$r['fd'], 'time' => date('g:i A', strtotime((string)$r['created_at'])), 'sort' => (string)$r['fd'] . ' ' . date('H:i:s', strtotime((string)$r['created_at'])),
                'action' => (string)$r['action'], 'label' => $L[$r['action']] ?? (string)$r['action'], 'note' => (string)$r['note'], 'by' => (string)$r['by_user'], 'check' => !empty($r['check_date']) ? (string)$r['check_date'] : ''];
        }
        $nMiss = 0;
        foreach ($tl as $d) {
            if (isset($byDay[$d]) || $d <= $since) continue;
            if (!($d < $today || ($d === $today && $past))) continue;
            $pn = $pens[$id][$d] ?? null; $t = '';
            if ($pn && $pn['pen_state']) {
                $each = (int)$pn['qty'] > 0 ? (float)$pn['pen_amount'] / (int)$pn['qty'] : (float)$pn['pen_amount']; $amt = $each > 0 ? dp_inr($each) : 'Penalty';
                $t = ['pending' => 'Penalty ' . $amt . ' waits for the boss\'s yes in Boss', 'applied' => 'Penalty ' . $amt . ' charged', 'shadow' => 'Penalty ' . $amt . ' recorded (test mode, nobody charged yet)', 'waived' => 'Penalty waived by the boss', 'capped' => 'Penalty limit reached'][$pn['pen_state']] ?? '';
            }
            $hist[] = ['k' => 'miss', 'date' => $d, 'time' => '', 'sort' => $d . ' 00:00:00', 'action' => '', 'label' => '', 'note' => '', 'by' => '', 'check' => '', 'pen' => $t]; $nMiss++;
        }
        usort($hist, function ($a, $b) { return strcmp($b['sort'], $a['sort']); });
        foreach ($hist as &$h) { unset($h['sort']); $h['dow'] = date('D', strtotime($h['date'])); } unset($h);
        $td = $byDay[$today][0] ?? null; $own = $case && $case['owner_emp'] ? (int)$case['owner_emp'] : $dflt;
        $out[$id] = ['since' => $since, 'days' => max(1, (int)floor((strtotime($today) - strtotime($since)) / 86400)), 'owner_id' => $own, 'owner' => $own ? bd_emp_name($pdo, $own) : '', 'assigned' => (bool)($case && $case['owner_emp']),
            'today' => $td ? ['label' => $L[$td['action']] ?? (string)$td['action'], 'action' => (string)$td['action'], 'note' => (string)$td['note'], 'time' => date('g:i A', strtotime((string)$td['created_at'])), 'by' => (string)$td['by_user']] : null,
            'dots' => $dots, 'miss' => $nMiss, 'hist' => $hist];
    }
    return $out;
}
/** untraceable cases that were ended (found / reshipped / refunded): [parcel_id => [how, label, by, at]] */
function dp_dsp_untr_ended(PDO $pdo, array $ids) {
    $o = []; $ids = array_values(array_filter(array_map('intval', $ids))); if (!$ids) return $o; $L = dp_dsp_untr_end_actions();
    try { foreach ($pdo->query("SELECT parcel_id, ended_how, ended_by, ended_at FROM dp_dsp_untr_case WHERE ended_at IS NOT NULL AND parcel_id IN (" . implode(',', $ids) . ")")->fetchAll() as $r) $o[(int)$r['parcel_id']] = ['how' => (string)$r['ended_how'], 'label' => $L[$r['ended_how']] ?? (string)$r['ended_how'], 'by' => (string)$r['ended_by'], 'at' => substr((string)$r['ended_at'], 0, 16)]; } catch (Throwable $e) {}
    return $o;
}
/** the last closed day before $today: how many remarks were due and written, which parcels missed, how many penalties still wait. $owner 0 = everybody */
function dp_dsp_untr_yesterday(PDO $pdo, $today, $owner) {
    try {
        $ow = $owner ? " AND owner_emp = " . (int)$owner : "";
        $st = $pdo->prepare("SELECT MAX(day) FROM dp_dsp_untr_day WHERE day < ?$ow"); $st->execute([$today]); $yd = (string)$st->fetchColumn(); if ($yd === '') return null;
        $st = $pdo->prepare("SELECT COALESCE(SUM(total),0) t, COALESCE(SUM(written),0) w FROM dp_dsp_untr_day WHERE day = ?$ow"); $st->execute([$yd]); $x = $st->fetch();
        $st = $pdo->prepare("SELECT p.order_name, i.pen_state FROM dp_dsp_untr_miss m JOIN dp_dsp_parcels p ON p.id = m.parcel_id LEFT JOIN dp_items i ON i.id = m.item_id WHERE m.day = ?" . ($owner ? " AND m.owner_emp = " . (int)$owner : "") . " ORDER BY p.order_name"); $st->execute([$yd]); $ms = $st->fetchAll();
        $pend = 0; foreach ($ms as $m) { if ($m['pen_state'] === 'pending') $pend++; }
        return ['date' => $yd, 'dow' => date('D', strtotime($yd)), 'total' => (int)$x['t'], 'written' => (int)$x['w'], 'missed' => array_map(function ($m) { return (string)$m['order_name']; }, array_slice($ms, 0, 6)), 'n_missed' => count($ms), 'pen_pending' => $pend];
    } catch (Throwable $e) { return null; }
}
/** numbers and names for the band, the Boss page and the My Day line */
function dp_dsp_untr_meta(PDO $pdo, $now) {
    $today = date('Y-m-d', $now); $ids = function ($s) use ($pdo) { return dp_holders($pdo, $s); };
    $names = function ($a) use ($pdo) { return array_values(array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, $a)); };
    $own = $ids('dispatch.untraceable'); $bak = $ids('dispatch.untraceable_backup'); $dflt = dp_dsp_untr_default_owner($pdo, $today);
    $r = dp_rule($pdo, 'dsp_untr_remark'); $on = $r && (int)$r['active'] === 1 && (float)$r['amount'] > 0;
    $y = dp_dsp_untr_yesterday($pdo, $today, 0);
    return ['cut' => date('g A', strtotime(DP_DSP_UNTR_CUT . ':00')), 'owner' => $names($own), 'owner_ids' => array_map('intval', $own), 'backup' => $names($bak), 'backup_ids' => array_map('intval', $bak), 'default_id' => $dflt, 'default' => $dflt ? bd_emp_name($pdo, $dflt) : '',
        'rule_on' => (bool)$on, 'amount' => $on ? (float)$r['amount'] : 0, 'amount_text' => $on ? dp_inr((float)$r['amount']) : '', 'yesterday' => $y, 'daily' => dp_dsp_untr_daily_actions(), 'end' => dp_dsp_untr_end_actions()];
}
/** the open untraceable parcels grouped by the person who must write the remark today: [emp => [rows]] */
function dp_dsp_untr_owner_groups(PDO $pdo, $now) {
    $today = date('Y-m-d', $now); $dflt = dp_dsp_untr_default_owner($pdo, $today); $g = [];
    try {
        $st = $pdo->prepare("SELECT p.id, p.order_name, p.sr_at, p.created_at, COALESCE(c.owner_emp, 0) own, EXISTS(SELECT 1 FROM dp_dsp_untr u WHERE u.parcel_id = p.id AND COALESCE(u.for_date, DATE(u.created_at)) = ?) has
            FROM dp_dsp_parcels p LEFT JOIN dp_dsp_untr_case c ON c.parcel_id = p.id WHERE " . dp_dsp_untr_open_sql() . " ORDER BY p.created_at");
        $st->execute([$today]);
        foreach ($st->fetchAll() as $r) { $o = (int)$r['own'] ?: $dflt; $g[$o][] = $r; }
    } catch (Throwable $e) {}
    return $g;
}
/**
 * Closes the days whose 6 PM has passed (never a day before the feature ran, never a Sunday): for each day it keeps how many remarks were due and written, and
 * the parcels that got none. When the Boss rule "dsp_untr_remark" is on, the owner gets ONE penalty item for the day (the rule's amount for each parcel),
 * but only for days that ended after the rule was switched on. Returns how many parcels missed a remark.
 */
function dp_dsp_untr_close_days(PDO $pdo, $now) {
    $missed = 0; if (!dp_live($pdo, 'dispatch')) return $missed;
    $today = date('Y-m-d', $now); $last = (string)dp_get('untr_done_day', '');
    if ($last === '') { $last = date('Y-m-d', strtotime($today . ' -1 day')); dp_put('untr_done_day', $last); }
    $rule = dp_rule($pdo, 'dsp_untr_remark'); $on = $rule && (int)$rule['active'] === 1 && (float)$rule['amount'] > 0; $act = $rule ? (strtotime((string)$rule['updated_at']) ?: $now) : $now;
    $grace = $rule ? (int)floor(((int)$rule['grace_min']) / 1440) : 0;
    for ($k = 0; $k < 7; $k++) {
        $d = date('Y-m-d', strtotime($last . ' +1 day'));
        if ($d > $today || strtotime($d . ' ' . DP_DSP_UNTR_CUT . ':00:00') > $now) break;
        if ((int)date('N', strtotime($d)) !== 7) {
            $st = $pdo->prepare("SELECT p.id, p.order_name, p.sr_at, p.created_at, COALESCE(c.owner_emp, 0) own, EXISTS(SELECT 1 FROM dp_dsp_untr u WHERE u.parcel_id = p.id AND COALESCE(u.for_date, DATE(u.created_at)) = ?) has
                FROM dp_dsp_parcels p LEFT JOIN dp_dsp_untr_case c ON c.parcel_id = p.id
                WHERE p.status IN ('tracked','transit') AND COALESCE(p.sr_status, '') REGEXP 'untrace' AND (c.ended_at IS NULL OR DATE(c.ended_at) > ?) AND DATE(COALESCE(p.sr_at, p.created_at)) < ?");
            $st->execute([$d, $d, $d]); $by = []; $dflt = dp_dsp_untr_default_owner($pdo, $d);
            foreach ($st->fetchAll() as $r) { $o = (int)$r['own'] ?: $dflt; $by[$o][] = $r; }
            foreach ($by as $o => $list) {
                $miss = array_values(array_filter($list, function ($r) { return !(int)$r['has']; }));
                $pdo->prepare("INSERT IGNORE INTO dp_dsp_untr_day (day, owner_emp, total, written) VALUES (?,?,?,?)")->execute([$d, (int)$o, count($list), count($list) - count($miss)]);
                foreach ($miss as $r) $pdo->prepare("INSERT IGNORE INTO dp_dsp_untr_miss (day, parcel_id, owner_emp) VALUES (?,?,?)")->execute([$d, (int)$r['id'], (int)$o]);
                $missed += count($miss);
                if (!$on || !$miss || strtotime($d . ' ' . DP_DSP_UNTR_CUT . ':00:00') < $act) continue;
                $cutDay = date('Y-m-d', strtotime($d . ' -' . $grace . ' day'));   // grace days: a parcel is excused for its first days
                $fine = array_values(array_filter($miss, function ($r) use ($cutDay) { return date('Y-m-d', strtotime((string)($r['sr_at'] ?: $r['created_at']))) < $cutDay; }));
                if (!$fine) continue;
                $nm = implode(', ', array_map(function ($r) { return '#' . ltrim((string)$r['order_name'], '#'); }, array_slice($fine, 0, 8))) . (count($fine) > 8 ? ' +' . (count($fine) - 8) . ' more' : '');
                $iid = dp_item_make($pdo, 'dispatch', 'dsp_untr_remark', 'dsp:um:' . str_replace('-', '', $d) . ':' . (int)$o, 'dispatch.untraceable', 'No remark on ' . count($fine) . ' untraceable parcel' . (count($fine) === 1 ? '' : 's') . ' (' . date('D j M', strtotime($d)) . ')', $nm,
                    $d, $d . ' ' . DP_DSP_UNTR_CUT . ':00:00', ['ids' => array_map(function ($r) { return (int)$r['id']; }, $fine), 'day' => $d], (int)$o, count($fine));
                foreach ($fine as $r) $pdo->prepare("UPDATE dp_dsp_untr_miss SET item_id = ? WHERE day = ? AND parcel_id = ?")->execute([$iid, $d, (int)$r['id']]);
                dp_miss($pdo, $iid, 'dsp_untr_remark', count($fine), 'no remark by ' . date('g A', strtotime(DP_DSP_UNTR_CUT . ':00')));
            }
        }
        dp_put('untr_done_day', $d); $last = $d;
    }
    return $missed;
}
/** the My Day job of the owner ("3 of 7 remarked"), and the 5 PM reminder. Returns the number of jobs written. */
function dp_dsp_untr_job(PDO $pdo, $now) {
    $n = 0; $today = date('Y-m-d', $now); if (!dp_live($pdo, 'dispatch') || (int)date('N', $now) === 7) return $n;
    try {
        $meta = dp_dsp_untr_meta($pdo, $now); $dflt = $meta['default_id']; $groups = dp_dsp_untr_owner_groups($pdo, $now); $seen = [];
        foreach ($groups as $o => $list) {
            if (!$o) continue; $total = count($list); $done = 0; $oldest = 0;
            $y = dp_dsp_untr_yesterday($pdo, $today, (int)$o); $yline = '';
            if ($y && $y['total'] > 0) $yline = 'Yesterday (' . $y['dow'] . '): ' . $y['written'] . ' of ' . $y['total'] . ' remarks written' . ($y['n_missed'] ? ' · ' . $y['n_missed'] . ' missed (' . implode(', ', array_map(function ($o2) { return '#' . ltrim($o2, '#'); }, array_slice($y['missed'], 0, 3))) . ')' : '')
                . ($y['pen_pending'] ? ' · penalty ' . dp_inr($meta['amount'] * $y['pen_pending']) . ' waiting for the boss' : '');
            foreach ($list as $r) { if ((int)$r['has']) $done++; $oldest = max($oldest, (int)floor(($now - strtotime((string)($r['sr_at'] ?: $r['created_at']))) / 86400)); }
            $rem = $total - $done; $ref = 'dsp:untr:job:' . str_replace('-', '', $today) . ':' . (int)$o; $seen[$ref] = 1;
            $title = 'Write today\'s remark on untraceable parcels';
            $detail = 'Oldest untraceable ' . max(1, $oldest) . ' day' . (max(1, $oldest) === 1 ? '' : 's') . ($meta['rule_on'] ? ' · ' . $meta['amount_text'] . ' for each parcel missed' : '');
            $pl = bd_json(['page' => 'dept_dispatch.html?focus=untraceable', 'progress' => ['done' => $done, 'total' => $total, 'label' => 'remarked'], 'btn' => 'Open the parcel list', 'note' => $yline]);
            $st = $pdo->prepare("SELECT * FROM dp_items WHERE ref_key = ?"); $st->execute([$ref]); $it = $st->fetch();
            if ($rem > 0) {
                if (!$it) { $id = dp_item_make($pdo, 'dispatch', null, $ref, ((int)$o === (int)$dflt ? 'dispatch.untraceable' : 'self'), $title, $detail, $today, $today . ' ' . DP_DSP_UNTR_CUT . ':00:00', [], (int)$o, $rem); $pdo->prepare("UPDATE dp_items SET payload = ? WHERE id = ?")->execute([$pl, $id]); }
                else $pdo->prepare("UPDATE dp_items SET detail = ?, payload = ?, qty = ?, due_at = ?, status = 'open', done_at = NULL WHERE id = ?")->execute([mb_substr($detail, 0, 400), $pl, max(1, $rem), $today . ' ' . DP_DSP_UNTR_CUT . ':00:00', $it['id']]);
                $n++;
            } elseif ($it && $it['status'] === 'open') {
                $pdo->prepare("UPDATE dp_items SET status = 'done', done_at = ?, detail = ?, payload = ? WHERE id = ?")->execute([bd_now(), 'All ' . $total . ' remarks written', $pl, $it['id']]);
            }
            // the 5 PM reminder, once a day for each owner
            if ($rem > 0 && (int)date('G', $now) >= DP_DSP_UNTR_REMIND && $now < strtotime($today . ' ' . DP_DSP_UNTR_CUT . ':00:00')) {
                $k = 'untr_rem_' . str_replace('-', '', $today) . '_' . (int)$o;
                if ((string)dp_get($k, '') === '') { dp_put($k, '1'); bd_push($pdo, (int)$o, $rem . ' untraceable parcel' . ($rem === 1 ? '' : 's') . ' still need today\'s remark', 'Write it by ' . date('g A', strtotime(DP_DSP_UNTR_CUT . ':00')) . '.' . ($meta['rule_on'] ? ' ' . $meta['amount_text'] . ' for each parcel missed.' : '')); }
            }
        }
        // a job whose parcels are all gone (found, ended) closes itself
        $q = $pdo->prepare("SELECT id, ref_key FROM dp_items WHERE kind = 'dispatch' AND rkey IS NULL AND status = 'open' AND ref_key LIKE ?"); $q->execute(['dsp:untr:job:' . str_replace('-', '', $today) . ':%']);
        foreach ($q->fetchAll() as $r) { if (!isset($seen[$r['ref_key']])) $pdo->prepare("UPDATE dp_items SET status = 'done', done_at = ? WHERE id = ?")->execute([bd_now(), $r['id']]); }
    } catch (Throwable $e) { error_log('Untraceable job: ' . $e->getMessage()); }
    return $n;
}
/** one note a day in the Boss inbox only when nobody is chosen as the owner; it closes itself when somebody is. Returns the number of open untraceable parcels. */
function dp_dsp_untr_note(PDO $pdo, $now) {
    $n = 0;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM dp_dsp_parcels p LEFT JOIN dp_dsp_untr_case c ON c.parcel_id = p.id WHERE " . dp_dsp_untr_open_sql())->fetchColumn();
        if ($n > 0 && !dp_dsp_untr_default_owner($pdo, date('Y-m-d', $now))) {
            if (dp_live($pdo, 'dispatch')) bd_inbox('dp_note', 'Choose the Untraceable owner (Dispatch)', $n . ' parcel' . ($n === 1 ? ' is' : 's are') . ' untraceable and nobody is chosen to write the daily remark. Choose the person in Boss > People & seats (or on the Dispatch page, group 1).', [], null, null, 'dsp:untr:' . date('Ymd', $now));
        } else {
            $u = $pdo->prepare("UPDATE bd_inbox SET status = 'dismissed', resolved_at = ?, resolved_by = 'Dispatch check' WHERE kind = 'dp_note' AND status = 'open' AND ref_key LIKE 'dsp:untr:%'"); $u->execute([date('Y-m-d H:i:s', $now)]);
        }
    } catch (Throwable $e) {}
    return $n;
}
/** everything the tick does for untraceable parcels */
function dp_dsp_untr_tick(PDO $pdo, $now) {
    $r = ['open' => 0, 'missed' => 0, 'jobs' => 0];
    try { $r['open'] = dp_dsp_untr_note($pdo, $now); } catch (Throwable $e) {}
    try { $r['missed'] = dp_dsp_untr_close_days($pdo, $now); } catch (Throwable $e) { error_log('Untraceable days: ' . $e->getMessage()); }
    try { $r['jobs'] = dp_dsp_untr_job($pdo, $now); } catch (Throwable $e) {}
    return $r;
}

/* ============================== the report ============================== */
function dp_dsp_rep_filters(array $g) {
    $today = date('Y-m-d'); $by = in_array(($g['by'] ?? ''), ['created', 'tracked', 'transit'], true) ? $g['by'] : 'created';
    $ok = function ($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; };
    if (!empty($g['from']) && $ok($g['from'])) { $from = $g['from']; $to = (!empty($g['to']) && $ok($g['to'])) ? $g['to'] : $today; }
    else { $days = max(1, min(DP_DSP_WIN, (int)($g['days'] ?? 25))); $from = date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days')); $to = $today; }
    if ($to < $from) { $t = $to; $to = $from; $from = $t; }
    if ($from < date('Y-m-d', strtotime($today . ' -' . (DP_DSP_WIN + 40) . ' days'))) $from = date('Y-m-d', strtotime($today . ' -' . (DP_DSP_WIN + 40) . ' days'));
    $hm = function ($v, $d) { $v = trim((string)$v); return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $v) ? str_pad($v, 5, '0', STR_PAD_LEFT) : $d; };
    return ['by' => $by, 'from' => $from, 'to' => $to, 'tfrom' => $hm($g['tfrom'] ?? '', ''), 'tto' => $hm($g['tto'] ?? '', ''),
        'store' => preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($g['store'] ?? '')), 'q' => trim((string)($g['q'] ?? ''))];
}
/** The "Done" tick on a parcel (who checked it, when). The two columns are added the first time they are needed. */
function dp_dsp_done_col(PDO $pdo) {
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->query("SELECT done_at, done_by, remit_at, remit_by FROM dp_dsp_parcels LIMIT 0"); return $ok = true; } catch (Throwable $e) {}
    foreach (["ALTER TABLE dp_dsp_parcels ADD COLUMN done_at DATETIME NULL", "ALTER TABLE dp_dsp_parcels ADD COLUMN done_by VARCHAR(80) NULL", "ALTER TABLE dp_dsp_parcels ADD COLUMN remit_at DATETIME NULL", "ALTER TABLE dp_dsp_parcels ADD COLUMN remit_by VARCHAR(80) NULL"] as $q) { try { $pdo->exec($q); } catch (Throwable $e) {} }
    try { $pdo->query("SELECT done_at, done_by, remit_at, remit_by FROM dp_dsp_parcels LIMIT 0"); return $ok = true; } catch (Throwable $e) { return $ok = false; }
}
/** The COD money still to come (Shopify "outstanding") of a parcel's order, as a sub-select. NULL = unknown (old order / no table). */
function dp_dsp_out_sql(PDO $pdo) {
    static $sql = null; if ($sql !== null) return $sql;
    try {
        $pdo->query("SELECT outstanding FROM shop_orders LIMIT 0");
        // the two tables can have different text collations (utf8mb4_unicode_ci vs uca1400_ai_ci): say explicitly which one to compare with
        $col = 'utf8mb4_unicode_ci';
        try { $c = $pdo->query("SHOW FULL COLUMNS FROM shop_orders LIKE 'store_key'")->fetch(); if ($c && !empty($c['Collation']) && preg_match('/^[a-z0-9_]+$/i', (string)$c['Collation'])) $col = (string)$c['Collation']; } catch (Throwable $e) {}
        $try = "(SELECT so.outstanding FROM shop_orders so WHERE so.store_key = (dp_dsp_parcels.store_key COLLATE $col) AND so.shopify_id = dp_dsp_parcels.shopify_id LIMIT 1)";
        $pdo->query("SELECT $try FROM dp_dsp_parcels LIMIT 1")->fetchAll();   // prove it works on this server; otherwise the report simply runs without the COD amount
        return $sql = $try;
    } catch (Throwable $e) { return $sql = "NULL"; }
}
/** The payment gateway id (KWIK...) of a parcel's order, as a sub-select. NULL = not read yet, '' = the order has none. */
function dp_dsp_kwik_sql(PDO $pdo) {
    static $sql = null; if ($sql !== null) return $sql;
    try {
        $pdo->query("SELECT pay_ref FROM shop_orders LIMIT 0");
        $col = 'utf8mb4_unicode_ci';
        try { $c = $pdo->query("SHOW FULL COLUMNS FROM shop_orders LIKE 'store_key'")->fetch(); if ($c && !empty($c['Collation']) && preg_match('/^[a-z0-9_]+$/i', (string)$c['Collation'])) $col = (string)$c['Collation']; } catch (Throwable $e) {}
        $try = "(SELECT so.pay_ref FROM shop_orders so WHERE so.store_key = (dp_dsp_parcels.store_key COLLATE $col) AND so.shopify_id = dp_dsp_parcels.shopify_id LIMIT 1)";
        $pdo->query("SELECT $try FROM dp_dsp_parcels LIMIT 1")->fetchAll();
        return $sql = $try;
    } catch (Throwable $e) { return $sql = "NULL"; }
}
/**
 * The group of a parcel, in the order the Dispatch team works them:
 *  1 untraceable (the courier cannot find it) · 2 no tracking ID · 3 tracking ID given, courier has not picked it up · 4 in transit · 5 not delivered / RTO ·
 *  6 delivered, COD money not received · 7 remittance arrived (or prepaid) · 8 cancelled, or an untraceable case that was ended (found, reshipped, refunded) · 9 waiting for Admin (a tracking ID typed by hand, not approved yet; shown between 2 and 3)
 *  10 old, no tracking ID (ordered more than DP_DSP_NOID_DAYS days ago): kept in the list at the very bottom, never counted as a problem, no penalty, no Boss job
 */
function dp_dsp_grp_sql(PDO $pdo) {
    $out = dp_dsp_out_sql($pdo); $oldCut = date('Y-m-d H:i:s', time() - DP_DSP_NOID_DAYS * 86400);
    return "CASE WHEN status = 'cancelled' THEN 8
        WHEN status IN ('tracked','transit') AND COALESCE(sr_status, '') REGEXP 'untrace' AND EXISTS (SELECT 1 FROM dp_dsp_untr_case uc WHERE uc.parcel_id = dp_dsp_parcels.id AND uc.ended_at IS NOT NULL) THEN 8
        WHEN status IN ('tracked','transit') AND COALESCE(sr_status, '') REGEXP 'untrace' THEN 1
        WHEN status = 'placed' AND pend_track IS NOT NULL THEN 9
        WHEN status = 'placed' AND created_at < '$oldCut' THEN 10
        WHEN status = 'placed' THEN 2
        WHEN status = 'rto' OR (status IN ('tracked','transit') AND COALESCE(sr_status, '') REGEXP 'undeliver|ndr|attempt|return|rto|lost|damag|refus|hold') THEN 5
        WHEN status = 'tracked' THEN 3
        WHEN status = 'transit' THEN 4
        WHEN status = 'delivered' AND remit_at IS NULL AND ($out IS NULL OR $out > 0) THEN 6 ELSE 7 END";
}
function dp_dsp_rep_col($by) { return ['created' => 'created_at', 'tracked' => 'tracked_at', 'transit' => 'transit_at'][$by]; }
function dp_dsp_rep_where(array $f, array &$par) {
    $col = dp_dsp_rep_col($f['by']);
    $w = ["$col >= ?", "$col < ?"]; $par[] = $f['from'] . ' 00:00:00'; $par[] = date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00';
    if ($f['tfrom'] !== '' || $f['tto'] !== '') {
        $a = $f['tfrom'] !== '' ? $f['tfrom'] : '00:00'; $b = ($f['tto'] !== '' ? $f['tto'] : '23:59') . ':59'; $a .= ':00';
        if ($a <= $b) { $w[] = "TIME($col) BETWEEN ? AND ?"; $par[] = $a; $par[] = $b; } else { $w[] = "(TIME($col) >= ? OR TIME($col) <= ?)"; $par[] = $a; $par[] = $b; }
    }
    if ($f['store'] !== '') { $w[] = "store_key = ?"; $par[] = $f['store']; }
    if ($f['q'] !== '') { $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], ltrim($f['q'], '#')) . '%'; $w[] = "(order_name LIKE ? OR tracking_no LIKE ? OR customer LIKE ? OR phone LIKE ? OR pend_track LIKE ?)"; array_push($par, $l, $l, $l, $l, $l); }
    return implode(' AND ', $w);
}
/**
 * 🧾 The Order card (Dispatch → tap a tracking number): everything HRMS knows about one parcel's order, in one answer.
 * Reads only what is already stored — order_facts (payment, salesperson, commission, Finance), the Manual order (wmo_order: payment
 * screenshot, UTR Claude read, address, items), complaints (cx_cases), the WhatsApp chat (wa_contacts) and the parcel's own times.
 * Shopify and Manual (WM-) orders alike. The payment screenshot itself comes from dp.dsp_card_shot (only when asked).
 */
function dp_dsp_card(PDO $pdo, array $p): array
{
    $c = ['order' => (string)$p['order_name'], 'store' => (string)$p['store_key'], 'shopify_id' => (string)$p['shopify_id'], 'manual' => (bool)preg_match('/^WM-\d+$/i', (string)$p['order_name'])];
    $h = function ($a, $b) { $x = strtotime((string)$a); $y = strtotime((string)$b); return ($x && $y && $y >= $x) ? round(($y - $x) / 3600, 1) : null; };
    $c['times'] = ['ordered' => substr((string)$p['created_at'], 0, 16), 'tracked' => substr((string)($p['tracked_at'] ?? ''), 0, 16), 'tracked_by' => (string)($p['tracked_by'] ?? ''), 'transit' => substr((string)($p['transit_at'] ?? ''), 0, 16),
        'delivered' => substr((string)($p['delivered_at'] ?? ''), 0, 16), 'remit' => substr((string)($p['remit_at'] ?? ''), 0, 16), 'h_track' => $h($p['created_at'], $p['tracked_at'] ?? ''), 'h_deliver' => $h($p['created_at'], $p['delivered_at'] ?? '')];
    // 💳 order + payment + salesperson + commission + Finance: the order_facts copy (filled in the background), else the order itself
    $f = function_exists('of_get') ? of_get($pdo, (string)$p['order_name']) : null;
    if (!$f) { try { $q = $pdo->prepare("SELECT total, outstanding, financial_status, tags, ml_total AS ml, pay_ref, created_at FROM shop_orders WHERE store_key = ? AND shopify_id = ?"); $q->execute([$p['store_key'], $p['shopify_id']]); $f = $q->fetch() ?: null; } catch (Throwable $e) { $f = null; } }
    $num = function ($v) { return $v === null || $v === '' ? null : round((float)$v, 2); };
    $c['pay'] = $f ? ['total' => $num($f['total'] ?? null), 'outstanding' => $num($f['outstanding'] ?? null), 'status' => (string)($f['financial_status'] ?? ''), 'ref' => (string)($f['pay_ref'] ?? ''), 'gateway' => (string)($f['gateway'] ?? ''),
        'paid_amt' => $num($f['paid_amt'] ?? null), 'paid_on' => (string)($f['paid_on'] ?? ''), 'channel' => (string)($f['fin_channel'] ?? ''), 'closed' => (string)($f['closed_kind'] ?? ''), 'refund' => (string)($f['refund_status'] ?? ''),
        'tx' => array_slice((array)($f['pay'] ?? []), 0, 4)] : null;
    $c['ml'] = $f && ($f['ml'] ?? null) !== null ? (int)$f['ml'] : null; $c['tags'] = $f ? (string)($f['tags'] ?? '') : ''; $c['code'] = $f ? (string)($f['order_code'] ?? '') : '';
    $c['com'] = $f && ($f['com_at'] ?? null) ? ['amt' => $num($f['com_amt']), 'pct' => $num($f['com_pct']), 'share' => $num($f['share_pct'] ?? null), 'day' => (string)($f['com_day'] ?? '')] : null;
    $who = dp_dsp_who($pdo, [$p])[(int)$p['id']] ?? [];
    $c['sp'] = (string)($who['sp'] ?? '') ?: (string)($f['emp_name'] ?? ''); $c['sp_type'] = (string)($who['sp_type'] ?? '') ?: (string)($f['order_type'] ?? ''); $c['chat'] = (string)($who['chat'] ?? '');
    // 💬 the WhatsApp chat
    $c['wa'] = null;
    if ($c['chat'] !== '') { try { $q = $pdo->prepare("SELECT name, source, assigned_emp, last_in_at FROM wa_contacts WHERE phone = ?"); $q->execute([$c['chat']]); $w = $q->fetch();
        if ($w) { $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $c['wa'] = ['name' => (string)$w['name'], 'source' => (string)$w['source'], 'holder' => (int)$w['assigned_emp'] ? ($nm[(int)$w['assigned_emp']] ?? '') : '', 'last_in' => substr((string)$w['last_in_at'], 0, 16)]; } } catch (Throwable $e) { } }
    // ⚠ every complaint of this order or this customer (open first)
    $c['cx'] = [];
    try {
        $on = strtolower(ltrim((string)$p['order_name'], '#'));
        $q = $pdo->prepare("SELECT id, problem, status, owner_emp, raised_at, resolved_at, order_name FROM cx_cases WHERE LOWER(TRIM(LEADING '#' FROM order_name)) = ?" . ($c['chat'] !== '' ? " OR phone = ?" : "") . " ORDER BY status IN ('open','reopened') DESC, id DESC LIMIT 6");
        $q->execute($c['chat'] !== '' ? [$on, $c['chat']] : [$on]); $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
        foreach ($q->fetchAll() as $x) $c['cx'][] = ['id' => (int)$x['id'], 'code' => 'C-' . str_pad((string)(int)$x['id'], 4, '0', STR_PAD_LEFT), 'label' => function_exists('cx_problems') ? ((cx_problems()[(string)$x['problem']][0] ?? '') ?: (string)$x['problem']) : (string)$x['problem'],
            'status' => (string)$x['status'], 'owner' => (int)$x['owner_emp'] ? ($nm[(int)$x['owner_emp']] ?? '') : '', 'raised' => substr((string)$x['raised_at'], 0, 16), 'this_order' => strtolower(ltrim((string)$x['order_name'], '#')) === $on];
    } catch (Throwable $e) { }
    // 🧾 a Manual order: how it was paid (screenshot + what Claude read), items, address, who made it
    $c['wm'] = null;
    if ($c['manual']) {
        try { $q = $pdo->prepare("SELECT * FROM wmo_order WHERE order_no = ? LIMIT 1"); $q->execute([(string)$p['order_name']]); $r = $q->fetch();
            if ($r) { $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $cu = json_decode((string)($r['cust_json'] ?? ''), true) ?: []; $rd = json_decode((string)($r['shot_json'] ?? ''), true) ?: [];
                $c['wm'] = ['pay' => (string)$r['pay'], 'total' => (int)$r['total_p'] / 100, 'paid' => (int)$r['paid_p'] / 100, 'cod' => (int)$r['cod_p'] / 100, 'utr' => (string)$r['utr'], 'shot' => (string)($r['shot_mime'] ?? '') !== '',
                    'read' => array_intersect_key($rd, array_flip(['amount', 'utr', 'date', 'status', 'payer'])), 'by' => $nm[(int)$r['emp_id']] ?? '', 'note' => (string)$r['note'], 'sr' => trim((string)($r['sr_order_id'] ?? '') . ' ' . (string)($r['sr_note'] ?? '')),
                    'made' => substr((string)$r['created_at'], 0, 16), 'sent' => substr((string)($r['sent_at'] ?? ''), 0, 16), 'opened' => substr((string)($r['opened_at'] ?? ''), 0, 16), 'confirmed' => substr((string)($r['confirmed_at'] ?? ''), 0, 16),
                    'lines' => array_map(function ($l) { return ['name' => trim((string)($l['short'] ?? '') . ' ' . (empty($l['chain']) ? (string)($l['label'] ?? '') : '')), 'qty' => (int)($l['qty'] ?? 1), 'total' => (int)($l['total_p'] ?? 0) / 100, 'chain' => !empty($l['chain'])]; }, json_decode((string)$r['lines_json'], true) ?: []),
                    'cust' => array_intersect_key($cu, array_flip(['name', 'phone', 'phone2', 'addr1', 'addr2', 'landmark', 'pin', 'city', 'state']))]; } } catch (Throwable $e) { }
    }
    return $c;
}
/**
 * 👤 Who sold each parcel's order and ⚠ whether its customer has an open complaint — for Shopify and Manual (WM-) orders alike.
 * Salesperson: the order's allocation (Order allocation page), else the salesperson holding the customer's WhatsApp chat.
 * Complaint: an open / reopened ticket for this order number or this customer's number. One query each for the whole list.
 * Returns [parcel id => ['sp' => name, 'sp_type' => query|campaign|'', 'chat' => 10-digit phone, 'cx' => [id, code, label] | null]].
 */
function dp_dsp_who(PDO $pdo, array $list): array
{
    $out = []; if (!$list) return $out;
    $p10 = function ($p) { $d = preg_replace('/\D+/', '', (string)$p) ?? ''; return strlen($d) >= 10 ? substr($d, -10) : ''; };
    $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
    $alloc = []; $chatEmp = []; $cxP = []; $cxO = [];
    $ids = []; $phones = []; $ords = [];
    foreach ($list as $r) { $ids[] = [(string)$r['store_key'], (string)$r['shopify_id']]; $ph = $p10($r['phone'] ?? ''); if ($ph !== '') $phones[$ph] = 1; $on = strtolower(ltrim((string)$r['order_name'], '#')); if ($on !== '') $ords[$on] = 1; }
    try {
        $w = implode(' OR ', array_fill(0, count($ids), '(source = ? AND order_id = ?)')); $par = []; foreach ($ids as $x) { $par[] = $x[0]; $par[] = $x[1]; }
        $q = $pdo->prepare("SELECT source, order_id, employee_id, order_type FROM order_allocations WHERE is_direct = 0 AND ($w)"); $q->execute($par);
        foreach ($q->fetchAll() as $a) $alloc[$a['source'] . '|' . $a['order_id']] = $a;
    } catch (Throwable $e) { }
    if ($phones) {
        $in = implode(',', array_fill(0, count($phones), '?'));
        try { $q = $pdo->prepare("SELECT phone, assigned_emp FROM wa_contacts WHERE phone IN ($in)"); $q->execute(array_keys($phones)); foreach ($q->fetchAll() as $c) $chatEmp[(string)$c['phone']] = (int)$c['assigned_emp']; } catch (Throwable $e) { }
    }
    try {
        $par = array_keys($phones); $w = $par ? 'phone IN (' . implode(',', array_fill(0, count($par), '?')) . ')' : '0';
        if ($ords) { $w .= ' OR LOWER(TRIM(LEADING \'#\' FROM order_name)) IN (' . implode(',', array_fill(0, count($ords), '?')) . ')'; $par = array_merge($par, array_keys($ords)); }
        $q = $pdo->prepare("SELECT id, phone, order_name, problem, status FROM cx_cases WHERE status IN ('open','reopened') AND ($w) ORDER BY id DESC"); $q->execute($par);
        foreach ($q->fetchAll() as $c) {
            $cx = ['id' => (int)$c['id'], 'code' => 'C-' . str_pad((string)(int)$c['id'], 4, '0', STR_PAD_LEFT), 'label' => function_exists('cx_problems') ? ((cx_problems()[(string)$c['problem']][0] ?? '') ?: ucfirst((string)$c['problem'])) : ucfirst((string)$c['problem']), 'status' => (string)$c['status']];
            if ((string)$c['phone'] !== '' && !isset($cxP[(string)$c['phone']])) $cxP[(string)$c['phone']] = $cx;
            $on = strtolower(ltrim((string)$c['order_name'], '#')); if ($on !== '' && !isset($cxO[$on])) $cxO[$on] = $cx;
        }
    } catch (Throwable $e) { }   // no complaint tables on this install
    foreach ($list as $r) {
        $a = $alloc[$r['store_key'] . '|' . $r['shopify_id']] ?? null; $ph = $p10($r['phone'] ?? ''); $on = strtolower(ltrim((string)$r['order_name'], '#'));
        $emp = $a ? (int)$a['employee_id'] : (int)($chatEmp[$ph] ?? 0);
        $out[(int)$r['id']] = ['sp' => $emp ? ($names[$emp] ?? ('#' . $emp)) : '', 'sp_type' => $a ? (string)$a['order_type'] : '', 'chat' => $ph, 'cx' => $cxO[$on] ?? ($ph !== '' ? ($cxP[$ph] ?? null) : null)];
    }
    return $out;
}
function dp_dsp_rep_row(array $r, $now, array $cm = [], array $un = []) {
    $c = strtotime($r['created_at']); $grp = (int)($r['grp'] ?? 0);
    $open = in_array($grp, [1, 2, 3, 4, 5, 9], true);
    $pend = (string)($r['pend_track'] ?? '') !== '';
    $late48 = $r['status'] === 'placed' && !$pend && strtotime($r['due48']) <= $now;
    $late4 = in_array($r['status'], ['placed', 'tracked'], true) && strtotime($r['due4']) <= $now;
    $wait = ($grp === 3 && $r['tracked_at']) ? round(($now - strtotime($r['tracked_at'])) / 3600, 1) : null;
    $late = $late48 ? '48h' : (($wait !== null && $wait >= dp_dsp_pick_hours()) ? 'wait48' : ($late4 ? 'day4' : ''));
    $link = trim((string)($r['track_link'] ?? ''));
    if ($link === '' && (string)$r['tracking_no'] !== '') $link = dp_dsp_link_make($cm[strtolower((string)$r['courier'])] ?? '', $r['tracking_no']);
    $plink = '';
    if ($pend) { $plink = trim((string)($r['pend_link'] ?? '')); if ($plink === '') $plink = dp_dsp_link_make($cm[strtolower((string)$r['pend_courier'])] ?? '', $r['pend_track']); }
    $u = $un[(int)$r['id']] ?? null;
    return ['id' => (int)$r['id'], 'order' => (string)$r['order_name'], 'store' => (string)$r['store_key'], 'customer' => (string)$r['customer'], 'phone' => (string)$r['phone'],
        'created' => date('Y-m-d H:i', $c), 'tracking_no' => (string)$r['tracking_no'], 'courier' => (string)$r['courier'], 'status' => (string)$r['status'], 'sr_status' => (string)($r['sr_status'] ?? ''),
        'sr_at' => !empty($r['sr_at']) ? substr((string)$r['sr_at'], 0, 16) : '',
        'live_at' => !empty($r['live_at']) ? substr((string)$r['live_at'], 0, 16) : '', 'live_src' => (string)($r['live_src'] ?? ''), 'etd' => !empty($r['etd']) ? (string)$r['etd'] : '',
        'tracked_at' => $r['tracked_at'] ? substr((string)$r['tracked_at'], 0, 16) : '', 'tracked_by' => (string)$r['tracked_by'], 'transit_at' => $r['transit_at'] ? substr((string)$r['transit_at'], 0, 16) : '',
        'delivered_at' => !empty($r['delivered_at']) ? substr((string)$r['delivered_at'], 0, 16) : '',
        'done_at' => !empty($r['done_at']) ? substr((string)$r['done_at'], 0, 16) : '', 'done_by' => (string)($r['done_by'] ?? ''),
        'grp' => $grp, 'cod' => (!isset($r['cod_out']) || $r['cod_out'] === null) ? null : (float)$r['cod_out'], 'remit_at' => !empty($r['remit_at']) ? substr((string)$r['remit_at'], 0, 16) : '', 'remit_by' => (string)($r['remit_by'] ?? ''),
        'age_h' => $open ? round(($now - $c) / 3600, 1) : null, 'track_h' => $r['tracked_at'] ? round((strtotime($r['tracked_at']) - $c) / 3600, 1) : null, 'wait_h' => $wait,
        'kwik' => (string)($r['pay_ref'] ?? ''),
        'late' => $late, 'link' => $link, 'manual' => !empty($r['manual']) ? 1 : 0,
        'added_by' => !empty($r['manual']) ? (string)($r['pend_by'] ?? '') : '', 'added_at' => (!empty($r['manual']) && !empty($r['pend_at'])) ? substr((string)$r['pend_at'], 0, 16) : '',
        'pend' => $pend ? ['track' => (string)$r['pend_track'], 'courier' => (string)$r['pend_courier'], 'link' => $plink, 'by' => (string)$r['pend_by'], 'at' => substr((string)$r['pend_at'], 0, 16)] : null,
        'u' => $u];
}
function dp_dsp_report(PDO $pdo, array $g, $now) {
    dp_dsp_done_col($pdo);
    $f = dp_dsp_rep_filters($g); $nowS = date('Y-m-d H:i:s', $now); $col = dp_dsp_rep_col($f['by']);
    $par = []; $where = dp_dsp_rep_where($f, $par);
    $GRP = dp_dsp_grp_sql($pdo); $OUT = dp_dsp_out_sql($pdo); $KW = dp_dsp_kwik_sql($pdo);
    $sums = "COUNT(*) total, SUM(status='placed') placed, SUM(status='tracked') tracked, SUM(status='transit') transit, SUM(status='delivered') delivered, SUM(status='rto') rto, SUM(status='cancelled') cancelled,
        SUM((status='placed' AND pend_track IS NULL AND due48 <= ?) OR (status IN ('placed','tracked') AND due4 <= ?)) late";
    $st = $pdo->prepare("SELECT $sums, SUM(status='tracked' AND (courier IS NULL OR courier = '')) no_courier, SUM(done_at IS NOT NULL) done_n, SUM(tracked_at IS NOT NULL) has_track, SUM(tracked_at IS NOT NULL AND tracked_at <= due48) in48,
        AVG(CASE WHEN tracked_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, tracked_at) END) avg_min FROM dp_dsp_parcels WHERE $where");
    $st->execute(array_merge([$nowS, $nowS], $par)); $c = $st->fetch();
    $counts = []; foreach (['total', 'placed', 'tracked', 'transit', 'delivered', 'rto', 'cancelled', 'late', 'no_courier', 'has_track', 'in48'] as $k) $counts[$k] = (int)$c[$k];
    $counts['done'] = (int)$c['done_n'];
    $st = $pdo->prepare("SELECT grp, COUNT(*) n FROM (SELECT ($GRP) grp FROM dp_dsp_parcels WHERE $where) t GROUP BY grp"); $st->execute($par);
    for ($i = 1; $i <= 10; $i++) $counts['g' . $i] = 0; foreach ($st->fetchAll() as $r) $counts['g' . (int)$r['grp']] = (int)$r['n'];
    // groups 1-3: how bad it is (untraceable parcels without today's remark, how many have waited over 48 h, how long the oldest "no ID" has waited) and what Shiprocket says about group 3
    $counts['g1_noact'] = 0; $counts['g3_over'] = 0; $counts['g2_old_h'] = null;
    try {
        $st = $pdo->prepare("SELECT SUM(grp = 1 AND NOT EXISTS (SELECT 1 FROM dp_dsp_untr u WHERE u.parcel_id = t.id AND COALESCE(u.for_date, DATE(u.created_at)) = ?)) na, SUM(grp = 3 AND tracked_at IS NOT NULL AND tracked_at <= ?) over48, MIN(CASE WHEN grp = 2 THEN created_at END) oldest
            FROM (SELECT id, tracked_at, created_at, ($GRP) grp FROM dp_dsp_parcels WHERE $where) t");
        $st->execute(array_merge([date('Y-m-d', $now), date('Y-m-d H:i:s', $now - dp_dsp_pick_hours() * 3600)], $par)); $x = $st->fetch();
        $counts['g1_noact'] = (int)$x['na']; $counts['g3_over'] = (int)$x['over48']; $counts['g2_old_h'] = $x['oldest'] ? round(($now - strtotime($x['oldest'])) / 3600, 1) : null;
    } catch (Throwable $e) {}
    $g3sr = [];
    if ($counts['g3']) {
        $st = $pdo->prepare("SELECT s, COUNT(*) n FROM (SELECT COALESCE(NULLIF(sr_status, ''), '(no status yet)') s, ($GRP) grp FROM dp_dsp_parcels WHERE $where) t WHERE grp = 3 GROUP BY s ORDER BY n DESC LIMIT 6"); $st->execute($par);
        foreach ($st->fetchAll() as $r) $g3sr[] = ['s' => (string)$r['s'], 'n' => (int)$r['n']];
    }
    // the strip of days (its own window: sdays, or sfrom..sto), on the same basis and store, whatever day is tapped
    $okd = function ($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; };
    $s2 = date('Y-m-d', $now); $s1 = date('Y-m-d', strtotime($s2 . ' -' . (max(1, min(60, (int)($g['sdays'] ?? 25))) - 1) . ' days'));
    if (!empty($g['sfrom']) && $okd($g['sfrom'])) { $s1 = $g['sfrom']; $s2 = (!empty($g['sto']) && $okd($g['sto'])) ? $g['sto'] : $s2; if ($s2 < $s1) { $t = $s1; $s1 = $s2; $s2 = $t; } if ((strtotime($s2) - strtotime($s1)) > 60 * 86400) $s1 = date('Y-m-d', strtotime($s2 . ' -60 days')); }
    $strip = []; $d = $s2; $guard = 0; while ($d >= $s1 && $guard++ < 62) { $strip[$d] = ['date' => $d, 'dow' => date('D', strtotime($d)), 'total' => 0, 'g1' => 0, 'g2' => 0, 'g9' => 0, 'done' => 0]; $d = date('Y-m-d', strtotime($d . ' -1 day')); }
    try {
        $sp = [$s1 . ' 00:00:00', date('Y-m-d', strtotime($s2 . ' +1 day')) . ' 00:00:00']; $sw = "$col >= ? AND $col < ?"; if ($f['store'] !== '') { $sw .= " AND store_key = ?"; $sp[] = $f['store']; }
        $st = $pdo->prepare("SELECT d, COUNT(*) n, SUM(grp = 1) g1, SUM(grp = 2) g2, SUM(grp = 9) g9, SUM(done_at IS NOT NULL) dn FROM (SELECT DATE($col) d, ($GRP) grp, done_at FROM dp_dsp_parcels WHERE $sw) t GROUP BY d"); $st->execute($sp);
        foreach ($st->fetchAll() as $r) if (isset($strip[$r['d']])) $strip[$r['d']] = ['date' => $r['d'], 'dow' => date('D', strtotime($r['d'])), 'total' => (int)$r['n'], 'g1' => (int)$r['g1'], 'g2' => (int)$r['g2'], 'g9' => (int)$r['g9'], 'done' => (int)$r['dn']];
    } catch (Throwable $e) {}
    $counts['avg_track_h'] = $c['avg_min'] === null ? null : round((float)$c['avg_min'] / 60, 1);
    $counts['in48_pct'] = $counts['has_track'] ? (int)round(100 * $counts['in48'] / $counts['has_track']) : null;
    // per day of the chosen range (kept for Claude's chat context)
    $st = $pdo->prepare("SELECT DATE($col) d, $sums FROM dp_dsp_parcels WHERE $where GROUP BY DATE($col) ORDER BY d DESC LIMIT 100");
    $st->execute(array_merge([$nowS, $nowS], $par)); $days = [];
    foreach ($st->fetchAll() as $r) { $days[] = ['date' => $r['d'], 'dow' => date('D', strtotime($r['d'])), 'total' => (int)$r['total'], 'placed' => (int)$r['placed'], 'tracked' => (int)$r['tracked'], 'transit' => (int)$r['transit'], 'delivered' => (int)$r['delivered'], 'rto' => (int)$r['rto'], 'cancelled' => (int)$r['cancelled'], 'late' => (int)$r['late']]; }
    // who assigned the tracking numbers
    $st = $pdo->prepare("SELECT tracked_by, COUNT(*) n, AVG(TIMESTAMPDIFF(MINUTE, created_at, tracked_at)) m FROM dp_dsp_parcels WHERE $where AND tracked_by IS NOT NULL AND tracked_by != '' GROUP BY tracked_by ORDER BY n DESC LIMIT 10");
    $st->execute($par); $people = []; foreach ($st->fetchAll() as $r) $people[] = ['name' => $r['tracked_by'], 'n' => (int)$r['n'], 'avg_h' => $r['m'] === null ? null : round((float)$r['m'] / 60, 1)];
    $dn = (string)($g['done'] ?? ''); $doneSql = $dn === 'yes' ? " AND done_at IS NOT NULL" : ($dn === 'no' ? " AND done_at IS NULL" : '');
    $cm = dp_dsp_courier_map($pdo);
    $mk = function (array $list) use ($pdo, $now, $cm) {
        $un = dp_dsp_untr_info($pdo, array_values(array_filter($list, function ($r) { return (int)$r['grp'] === 1; })), $now);
        $ce = dp_dsp_untr_ended($pdo, array_map(function ($r) { return (int)$r['id']; }, array_filter($list, function ($r) { return (int)$r['grp'] === 8 && in_array($r['status'], ['tracked', 'transit'], true); })));
        $who = dp_dsp_who($pdo, $list);   // 👤 salesperson + ⚠ open complaint of each order
        return array_map(function ($r) use ($now, $cm, $un, $ce, $who) { $x = dp_dsp_rep_row($r, $now, $cm, $un); if (isset($ce[(int)$r['id']])) $x['case_end'] = $ce[(int)$r['id']]; return $x + ($who[(int)$r['id']] ?? []); }, $list);
    };
    $ordG = "(CASE WHEN grp IN (6,7,8,10) THEN -UNIX_TIMESTAMP(created_at) WHEN grp = 3 THEN UNIX_TIMESTAMP(COALESCE(tracked_at, created_at)) WHEN grp = 9 THEN UNIX_TIMESTAMP(COALESCE(pend_at, created_at)) ELSE UNIX_TIMESTAMP(created_at) END), id";
    $rows = []; $total = 0; $sf = (string)($g['status'] ?? 'all'); $per = 100; $page = 1; $groups = null;
    $gset = []; foreach (explode(',', (string)($g['gset'] ?? '')) as $x) { $x = (int)$x; if ($x >= 1 && $x <= 10) $gset[$x] = $x; }
    if (isset($g['gset'])) {   // the Dispatch page: rows per group (gset=none = the numbers only)
        // one list per group (the groups the page has open), newest/oldest as the group needs, first $gl rows of each
        $gl = max(5, min(3000, (int)($g['gl'] ?? 25))); $groups = [];
        foreach ($gset as $gi) {
            $st = $pdo->prepare("SELECT * FROM (SELECT dp_dsp_parcels.*, ($GRP) AS grp, ($OUT) AS cod_out, ($KW) AS pay_ref FROM dp_dsp_parcels WHERE $where$doneSql) t WHERE grp = ? ORDER BY $ordG LIMIT " . (int)$gl);
            $st->execute(array_merge($par, [$gi]));
            $groups[(string)$gi] = ['n' => $counts['g' . $gi], 'rows' => $mk($st->fetchAll())];
        }
        $total = $counts['total']; $sf = 'all'; $per = $gl;
    } else {
        $rp = $par; $rw = $where . $doneSql;
        if (in_array($sf, ['placed', 'tracked', 'transit', 'delivered', 'rto', 'cancelled'], true)) { $rw .= " AND status = ?"; $rp[] = $sf; }
        elseif (preg_match('/^g(10|[1-9])$/', $sf)) { $rw .= " AND ($GRP) = ?"; $rp[] = (int)substr($sf, 1); }
        elseif ($sf === 'late') { $rw .= " AND ((status='placed' AND pend_track IS NULL AND due48 <= ?) OR (status IN ('placed','tracked') AND due4 <= ?))"; $rp[] = $nowS; $rp[] = $nowS; }
        elseif ($sf === 'nocourier') { $rw .= " AND status = 'tracked' AND (courier IS NULL OR courier = '')"; }
        else $sf = 'all';
        $per = !empty($g['export']) ? 20000 : max(20, min(1500, (int)($g['per'] ?? 100))); $page = max(1, (int)($g['page'] ?? 1));
        $st = $pdo->prepare("SELECT COUNT(*) FROM dp_dsp_parcels WHERE $rw"); $st->execute($rp); $total = (int)$st->fetchColumn();
        $dir = (($g['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
        $ord = (($g['sort'] ?? 'priority') === 'date') ? "$col $dir, id $dir" : "(CASE WHEN grp = 9 THEN 2.5 ELSE grp END), $ordG";
        $st = $pdo->prepare("SELECT * FROM (SELECT dp_dsp_parcels.*, ($GRP) AS grp, ($OUT) AS cod_out, ($KW) AS pay_ref FROM dp_dsp_parcels WHERE $rw) t ORDER BY $ord LIMIT " . (int)$per . " OFFSET " . (int)(($page - 1) * $per)); $st->execute($rp);
        $rows = $mk($st->fetchAll());
    }
    $stores = []; try { foreach ($pdo->query("SELECT DISTINCT store_key FROM dp_dsp_parcels ORDER BY store_key")->fetchAll() as $r) $stores[] = $r['store_key']; } catch (Throwable $e) {}
    // plain sentences for Claude's chat context
    $trig = [];
    foreach ($days as $d) { if ($d['placed'] > 0 && count($trig) < 8) $trig[] = ['sev' => $d['late'] > 0 ? 'bad' : 'warn', 'text' => $d['dow'] . ' ' . date('j M', strtotime($d['date'])) . ': ' . $d['placed'] . ' tracking ID' . ($d['placed'] === 1 ? ' is' : 's are') . ' not assigned' . ($d['late'] ? ' (' . $d['late'] . ' already late)' : ''), 'date' => $d['date']]; }
    if ($counts['tracked'] > 0) $trig[] = ['sev' => 'warn', 'text' => $counts['tracked'] . ' parcel' . ($counts['tracked'] === 1 ? ' has' : 's have') . ' a tracking number but ' . ($counts['tracked'] === 1 ? 'is' : 'are') . ' not dispatched yet', 'date' => ''];
    if ($counts['no_courier'] > 0) $trig[] = ['sev' => 'info', 'text' => $counts['no_courier'] . ' tracked parcel' . ($counts['no_courier'] === 1 ? ' has' : 's have') . ' no courier name', 'date' => ''];
    $hold = ['tracking' => array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, dp_holders($pdo, 'dispatch.tracking')), 'lead' => array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, dp_holders($pdo, 'dispatch.lead'))];
    return ['limits' => ['hours' => dp_dsp_track_hours(), 'days' => dp_dsp_transit_days(), 'pick' => dp_dsp_pick_hours()], 'filters' => $f, 'status' => $sf, 'counts' => $counts, 'g3_sr' => $g3sr, 'strip' => array_values($strip), 'days' => $days, 'people' => $people, 'triggers' => $trig, 'rows' => $rows, 'groups' => $groups, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / max(1, $per))), 'per' => $per, 'stores' => $stores,
        'holders' => $hold, 'window_days' => DP_DSP_WIN, 'couriers' => dp_dsp_couriers($pdo, true), 'untr' => dp_dsp_untr_meta($pdo, $now)];
}

/* ============================== "what is wrong" checks ============================== */
function dp_dsp_problems(PDO $pdo, array $w, $now) {
    $P = []; $nowS = date('Y-m-d H:i:s', $now);
    $add = function ($sev, $title, $why, $fix, $prompt = '', $link = '', $linkTxt = '') use (&$P) { $P[] = ['sev' => $sev, 'title' => $title, 'why' => $why, 'fix' => $fix, 'prompt' => $prompt, 'link' => $link, 'link_text' => $linkTxt]; };
    $c = dp_dsp_sr_cfg(); $lastTs = (int)dp_get('sr_last_ts', '0'); $lastErr = (string)dp_get('sr_last_err', '');
    if (!$c['ok']) {
        $add('bad', 'Shiprocket is not connected', 'Without it nobody can see which parcels Shiprocket has picked up, moved or delivered. Tracking numbers and statuses have to be typed by hand.',
            'Create an API user in Shiprocket (Settings > API, on older panels Settings > Company), then paste its email and password in the Shiprocket box on this page. Or add two lines to config.php: define(\'SHIPROCKET_EMAIL\', \'...\'); define(\'SHIPROCKET_PASSWORD\', \'...\');',
            "Connect Shiprocket to the HRMS Dispatch page. I have created a Shiprocket API user. Read my Shiprocket orders every 15 minutes, copy the AWB, courier and status onto each parcel on Boss > Departments > Parcels and tracking, and tell me when Shiprocket login fails.", '#shiprocket', 'Open the Shiprocket box');
    } elseif ($lastErr !== '') {
        $add('bad', 'Shiprocket sync is failing', $lastErr, 'Open the Shiprocket box on this page, press "Test connection". If the password was changed in Shiprocket, save the new one.',
            "The Shiprocket sync on the Dispatch page is failing with this message: \"" . $lastErr . "\". Please find the cause and fix it.", '#shiprocket', 'Open the Shiprocket box');
    } elseif ($lastTs === 0 || $now - $lastTs > 7200) {
        $add('warn', 'Shiprocket has not been read for ' . ($lastTs ? round(($now - $lastTs) / 3600, 1) . ' hours' : 'ever'), 'Statuses here may be old.', 'Press "Read Shiprocket now". If it keeps going stale, the cron job on Hostinger (Boss > Setup > Automatic run) is not running every few minutes.',
            "The Shiprocket read on the Dispatch page has not run for " . ($lastTs ? round(($now - $lastTs) / 3600, 1) . " hours" : "a long time") . ". Check that the tick cron job runs every 5 minutes and that dp_dispatch_tick calls dp_dsp_sr_sync.", '#shiprocket', 'Open the Shiprocket box');
    }
    $se = (string)dp_get('shop_last_err', ''); $sfe = (string)dp_get('sf_last_err', '');
    if ($se !== '' || $sfe !== '') $add('bad', 'The Shopify connection is failing', $se !== '' ? $se : $sfe, 'Press "Fix everything now" on this page: it renews the Shopify access token and reads again. If the same message comes back, the Shopify app in api.php (Client ID / Secret) needs to be checked.',
        "The HRMS cannot read Shopify. Message: \"" . ($se !== '' ? $se : $sfe) . "\". Please find the cause (token, app permission read_orders, store address) and fix it.", '#shiprocket', 'Open the sync box');
    $rl = (string)dp_get('sr_roll_err', ''); if ($rl !== '' && $lastErr === '') $add('warn', 'The background Shiprocket read hit a problem', $rl, 'It tries again by itself. If this stays, press "Fix everything now".');
    $um = explode('|', (string)dp_get('sr_last_unmatched', '0|'), 2); $unm = (int)$um[0]; $seen = (int)dp_get('sr_last_seen', '0');
    if ($c['ok'] && $lastErr === '' && $seen > 0 && $unm > 0 && $unm >= max(3, (int)round($seen * 0.1))) {
        $add('warn', $unm . ' of ' . $seen . ' Shiprocket orders do not match any order here', 'Order numbers look different in Shiprocket (for example examples: ' . trim($um[1] ?? '') . '). Those parcels get no tracking number from Shiprocket.',
            'Check how Shiprocket writes the order number compared with Shopify (prefix, store letters). Tell the builder the two forms.',
            "Shiprocket's channel_order_id looks like: " . trim($um[1] ?? '') . ". Our parcels use the Shopify order name (for example #1042). Please make the Shiprocket sync match them, including stores with a prefix.");
    }
    try { $pdo->query("SELECT 1 FROM shop_orders LIMIT 1"); $nw = (string)$pdo->query("SELECT MAX(created_at) FROM shop_orders")->fetchColumn();
        if ($nw === '' || strtotime($nw) < $now - 36 * 3600) $add('bad', 'New orders are not arriving', 'The newest order in the order cache is ' . ($nw ?: 'missing') . '. Parcels can only be listed for orders that arrive in the cache.', 'The order sync from Shopify has stopped. Ask the builder to check the sync cron job (Hostinger > Advanced > Cron jobs).', "The shop_orders cache has no order since " . ($nw ?: 'ever') . ". Please check the Shopify order sync cron and fix it.");
    } catch (Throwable $e) { $add('bad', 'No order cache on this server', 'The table shop_orders does not exist, so no parcels can be listed.', 'Open any page that loads orders once, or ask the builder to run the order sync.', "The shop_orders table is missing on the server. Please create it and run the first order sync."); }
    // the numbers of the new Dispatch page, over the last 25 days: untraceable, no ID, ID given but the courier has not picked it up, IDs waiting for Admin
    $G = dp_dsp_grp_sql($pdo); $win = date('Y-m-d', $now - 25 * 86400); $x = ['g1' => 0, 'g1na' => 0, 'g2' => 0, 'g3' => 0, 'g3o' => 0, 'g9' => 0, 'l48' => 0, 'old48' => null];
    try {
        $st = $pdo->prepare("SELECT SUM(grp = 1) g1, SUM(grp = 1 AND NOT EXISTS (SELECT 1 FROM dp_dsp_untr u WHERE u.parcel_id = t.id AND COALESCE(u.for_date, DATE(u.created_at)) = ?)) g1na, SUM(grp = 2) g2, SUM(grp = 3) g3, SUM(grp = 3 AND tracked_at IS NOT NULL AND tracked_at <= ?) g3o, SUM(grp = 9) g9,
            SUM(grp = 2 AND due48 <= ?) l48, MIN(CASE WHEN grp = 2 AND due48 <= ? THEN created_at END) old48 FROM (SELECT id, tracked_at, created_at, due48, ($G) grp FROM dp_dsp_parcels WHERE created_at >= ?) t");
        $st->execute([date('Y-m-d', $now), date('Y-m-d H:i:s', $now - dp_dsp_pick_hours() * 3600), $nowS, $nowS, $win]); $x = array_map(function ($v) { return $v === null ? 0 : $v; }, $st->fetch());
    } catch (Throwable $e) {}
    $n = (int)$x['g1na']; $dflt = dp_dsp_untr_default_owner($pdo, date('Y-m-d', $now)); $late6 = $now >= strtotime(date('Y-m-d', $now) . ' ' . DP_DSP_UNTR_CUT . ':00:00');
    if ($n > 0) $add($late6 ? 'bad' : 'warn', $n . ' untraceable parcel' . ($n === 1 ? ' has' : 's have') . ' no remark today', 'The courier cannot find ' . ($n === 1 ? 'it' : 'them') . '. ' . ($dflt ? bd_emp_name($pdo, $dflt) . ' must write what was done on each one, every day, by ' . date('g A', strtotime(DP_DSP_UNTR_CUT . ':00')) . '.' : 'Nobody is chosen to write the daily remark.'), $dflt ? 'Ask ' . bd_emp_name($pdo, $dflt) . ' to write today’s remark in group 1 of the table.' : 'Choose the Untraceable owner in Boss > People & seats.', '', '#table', 'Open group 1');
    if ((int)$x['g1'] > 0 && !$dflt) $add('bad', 'Nobody is chosen as the Untraceable owner', 'Nobody is told to follow the parcels the courier cannot find, and nobody can be penalised for not writing the daily remark.', 'Choose the owner (and a backup) in Boss > People & seats.', '', 'hub.html?w=boss&t=people', 'Choose in Boss');
    $n = (int)$x['l48'];
    if ($n > 0) $add('bad', $n . ' parcel' . ($n === 1 ? '' : 's') . ' still ha' . ($n === 1 ? 's' : 've') . ' no tracking ID after ' . dp_dsp_track_hours() . ' hours', 'The oldest was ordered on ' . date('D j M, g:i A', strtotime((string)$x['old48'])) . '. Customers are waiting.', 'Open group 2 in the table and add the tracking ID on each row. The tracking person should be told today.', '', '#table', 'Open group 2');
    $n = (int)$x['g3o'];
    if ($n > 0) $add('warn', $n . ' parcel' . ($n === 1 ? ' has' : 's have') . ' a tracking ID but the courier has not picked ' . ($n === 1 ? 'it' : 'them') . ' up for over ' . dp_dsp_pick_hours() . ' hours', 'The ID exists in Shiprocket, but no courier scan came. Either the parcel did not leave or the courier is late.', 'Open group 3: the Shiprocket status of each shows what is happening. Ask the Dispatch lead to chase the courier.', '', '#table', 'Open group 3');
    $n = (int)$x['g9'];
    if ($n > 0) $add('warn', $n . ' tracking ID' . ($n === 1 ? '' : 's') . ' typed by hand wait' . ($n === 1 ? 's' : '') . ' for Admin', 'Parcels sent outside Shiprocket are not in transit until Admin approves the ID.', 'Admin / HR: open the "Waiting for Admin" band and press Approve or Reject.', '', '#table', 'Open the band');
    $st = $pdo->query("SELECT COUNT(*) FROM dp_dsp_parcels WHERE status = 'tracked' AND (courier IS NULL OR courier = '')"); $n = (int)$st->fetchColumn();
    if ($n > 0) $add('info', $n . ' tracked parcel' . ($n === 1 ? ' has' : 's have') . ' no courier name', 'Nobody can tell which courier to chase.', 'Type the courier when you add the tracking number, or connect Shiprocket (it fills the courier).', '', '#table', 'Show them');
    $st = $pdo->prepare("SELECT COUNT(*) FROM dp_dsp_parcels WHERE status = 'placed' AND LOWER(COALESCE(shop_fulfil,'')) = 'fulfilled' AND created_at >= ?"); $st->execute([date('Y-m-d', $now - 30 * 86400)]); $n = (int)$st->fetchColumn();
    if ($n > 0) $add('warn', 'Shopify says ' . $n . ' order' . ($n === 1 ? ' is' : 's are') . ' fulfilled, but there is no tracking number here', 'These were probably sent, but nobody saved the tracking number in the HRMS.', 'Connect Shiprocket, or add the tracking IDs by hand in group 2 of the table.',
        "Shopify marks " . $n . " orders fulfilled while the Dispatch page has no tracking number for them. Please copy the Shopify fulfilment tracking number and company onto those parcels automatically.", '#table', 'Show them');
    if (!dp_holders($pdo, 'dispatch.tracking')) $add('bad', 'Nobody is chosen as the Tracking person', 'Nobody is told to add tracking numbers and nobody can be penalised for missing them.', 'Choose the person on Boss > Departments > Departments and seats.', '', 'dept_hub.html', 'Choose in Departments');
    if (!dp_holders($pdo, 'dispatch.lead')) $add('bad', 'Nobody is chosen as the Dispatch lead', 'Nobody is told when parcels are close to the day-4 limit.', 'Choose the person on Boss > Departments > Departments and seats.', '', 'dept_hub.html', 'Choose in Departments');
    if (!dp_live($pdo, 'dispatch')) { $qo = 0; try { $qo = (int)$pdo->query("SELECT COUNT(*) FROM dp_q WHERE topic = 'dispatch' AND state IN ('open','later')")->fetchColumn(); } catch (Throwable $e) {}
        $add('warn', 'Parcels and tracking is not live yet', 'Reminders, warnings and penalties for parcels are switched off until you make this department live.', $qo ? 'Answer the ' . $qo . ' question' . ($qo === 1 ? '' : 's') . ' Claude has about parcels, then press the make-live button.' : 'Open Boss > Questions and make Parcels and tracking live.', '', 'boss_questions.html', 'Open Questions'); }
    foreach (['tracking_48h' => 'No tracking number in ' . dp_dsp_track_hours() . ' hours', 'transit_day4' => 'Not in transit by day ' . dp_dsp_transit_days()] as $k => $nm) { $ru = dp_rule($pdo, $k); if ($ru && (float)$ru['amount'] <= 0) $add('info', 'Penalty "' . $nm . '" is ₹0', 'Nobody loses money for this yet.', 'Set an amount on Boss > Penalties if you want it to count.', '', 'boss_penalties.html', 'Open Penalties'); }
    $k = (string)bd_cfg_fresh('tick_key'); $lt = strtotime((string)bd_cfg_fresh('last_tick') ?: '2000-01-01');
    if ($k === '') $add('warn', 'The automatic run has no key yet', 'The cron job cannot start the reminders.', 'Make the tick key in Boss Engine > Setup & health.', '', 'boss_engine.html?tab=setup', 'Open Setup');
    elseif ($now - $lt > 1800) $add('warn', 'The automatic run has not run for ' . round(($now - $lt) / 3600, 1) . ' hours', 'Warnings and penalties only go out when it runs.', 'Check the cron job on Hostinger (Boss > Setup > Automatic run shows the address to call every 5 minutes).', "The Boss Engine tick has not run for " . round(($now - $lt) / 3600, 1) . " hours. Please check the Hostinger cron job that calls api.php?action=dp.tick.", 'boss_engine.html?tab=setup', 'Open Setup');
    $order = ['bad' => 0, 'warn' => 1, 'info' => 2]; usort($P, function ($a, $b) use ($order) { return $order[$a['sev']] <=> $order[$b['sev']]; });
    return $P;
}
function dp_dsp_sr_info() {
    $c = dp_dsp_sr_cfg(); $e = $c['email']; $mask = $e === '' ? '' : (strpos($e, '@') !== false ? substr($e, 0, 2) . '…' . substr($e, strpos($e, '@')) : substr($e, 0, 2) . '…');
    return ['connected' => $c['ok'], 'source' => $c['source'], 'email' => $mask, 'last_ts' => (int)dp_get('sr_last_ts', '0'), 'last_at' => (int)dp_get('sr_last_ts', '0') ? date('D j M, g:i A', (int)dp_get('sr_last_ts', '0')) : '',
        'last_msg' => (string)dp_get('sr_last_msg', ''), 'last_err' => (string)dp_get('sr_last_err', ''),
        'shop' => dp_dsp_health_line('shop'), 'sf' => dp_dsp_health_line('sf'), 'kwik' => dp_dsp_health_line('kwik') + ['stats' => dp_dsp_kwik_stats($GLOBALS['pdo'])], 'facts' => dp_dsp_health_line('of') + ['stats' => function_exists('of_stats') ? of_stats($GLOBALS['pdo']) : []], 'shop_ok' => dp_dsp_shop_ok(), 'roll_msg' => (string)dp_get('sr_roll_msg', ''), 'roll_err' => (string)dp_get('sr_roll_err', ''),
        'rec' => json_decode((string)dp_get('rec_last', ''), true) ?: null,
        'live' => function_exists('dp_live_stats') ? dp_live_stats() : null];
}
function dp_dsp_health_line($pfx) { $ts = (int)dp_get($pfx . '_last_ts', '0'); return ['ts' => $ts, 'at' => $ts ? date('D j M, g:i A', $ts) : '', 'msg' => (string)dp_get($pfx . '_last_msg', ''), 'err' => (string)dp_get($pfx . '_last_err', '')]; }
/** a short text for Claude when the boss is on the Dispatch page */
function dp_dsp_chat_context(PDO $pdo, $now) {
    $L = ["\nDISPATCH FOCUS (he is looking at the Parcels and tracking page):"];
    foreach ([['Today', 1], ['Last 7 days', 7], ['Last 30 days', 30]] as $x) {
        $r = dp_dsp_report($pdo, ['days' => $x[1], 'per' => 20, 'page' => 1], $now); $c = $r['counts'];
        $L[] = $x[0] . ': ' . $c['total'] . ' parcels (by order date) - no tracking ' . $c['placed'] . ', tracking assigned (not dispatched) ' . $c['tracked'] . ', dispatched/in transit ' . $c['transit'] . ', delivered ' . $c['delivered'] . ', RTO ' . $c['rto'] . ', cancelled ' . $c['cancelled'] . ', late ' . $c['late'] . ($c['avg_track_h'] !== null ? ', average ' . $c['avg_track_h'] . ' h to assign a number' : '');
        if ($x[1] === 7) foreach (array_slice($r['triggers'], 0, 5) as $t) $L[] = '  - ' . $t['text'];
    }
    $s = dp_dsp_sr_info(); $L[] = 'Shiprocket: ' . ($s['connected'] ? 'connected (' . $s['source'] . '), ' . ($s['last_msg'] ?: 'not read yet') . ($s['last_err'] ? ' ERROR: ' . $s['last_err'] : '') : 'NOT connected');
    $L[] = 'Problems found by the Dispatch check:'; foreach (dp_dsp_problems($pdo, ['manager' => true], $now) as $p) $L[] = '- [' . $p['sev'] . '] ' . $p['title'] . ' - ' . $p['why'];
    return implode("\n", $L);
}

/* ============================== the Boss job: "Add tracking IDs for parcels sent outside Shiprocket" ============================== */
if (!defined('DP_DSP_JOB_DAYS')) define('DP_DSP_JOB_DAYS', 25);
if (!defined('DP_DSP_G3_DAY_MIN')) define('DP_DSP_G3_DAY_MIN', 10);
/** The one job of the day for the Tracking person. It counts the parcels with no tracking ID, shows "2 of 6 added" and closes itself when none is left. Safe to call after every change. */
function dp_dsp_job_refresh(PDO $pdo, $now) {
    $today = date('Y-m-d', $now); $nowS = date('Y-m-d H:i:s', $now); $from = date('Y-m-d', $now - min(DP_DSP_JOB_DAYS, DP_DSP_NOID_DAYS) * 86400); $ref = 'dsp:track:' . $today;
    $title = 'Add tracking IDs for parcels sent outside Shiprocket';
    try {
        $pdo->prepare("UPDATE dp_dsp_parcels SET job_at = ? WHERE status = 'placed' AND pend_track IS NULL AND job_at IS NULL AND created_at >= ?")->execute([$nowS, $from]);
        $st = $pdo->prepare("SELECT COUNT(*), MIN(created_at) FROM dp_dsp_parcels WHERE status = 'placed' AND pend_track IS NULL AND created_at >= ?"); $st->execute([$from]); $r = $st->fetch(PDO::FETCH_NUM);
        $rem = (int)$r[0]; $oldest = $r[1];
        $st = $pdo->prepare("SELECT COUNT(*) FROM dp_dsp_parcels WHERE manual = 1 AND pend_at >= ? AND pend_at < ?"); $st->execute([$today . ' 00:00:00', date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00']); $added = (int)$st->fetchColumn();
        $total = $rem + $added;
        if (!dp_live($pdo, 'dispatch')) return ['rem' => $rem, 'added' => $added];   // nothing goes to My Day until the department is live
        $st = $pdo->prepare("SELECT * FROM dp_items WHERE ref_key = ?"); $st->execute([$ref]); $it = $st->fetch();
        $mk = function ($done, $tot) { return bd_json(['page' => 'dept_dispatch.html?focus=noid', 'progress' => ['done' => $done, 'total' => $tot], 'btn' => 'Open the parcel list']); };
        if ($rem > 0) {
            $age = $oldest ? max(0, (int)floor(($now - strtotime($oldest)) / 86400)) : 0;
            $detail = $added . ' of ' . $total . ' added' . ($age >= 1 ? ' · oldest waiting ' . $age . ' day' . ($age === 1 ? '' : 's') : '');
            $owner = dp_owner_for($pdo, 'dispatch.tracking', $today);
            if (!$it) { $id = dp_item_make($pdo, 'dispatch', null, $ref, 'dispatch.tracking', $title, $detail, $today, $today . ' 23:59:00', [], $owner ?: 0, $rem); $pdo->prepare("UPDATE dp_items SET payload = ? WHERE id = ?")->execute([$mk($added, $total), $id]); }
            else $pdo->prepare("UPDATE dp_items SET title = ?, detail = ?, payload = ?, qty = ?, owner_emp = ?, due_at = ?, status = 'open', done_at = NULL WHERE id = ?")->execute([$title, mb_substr($detail, 0, 400), $mk($added, $total), max(1, $rem), $owner ?: null, $today . ' 23:59:00', $it['id']]);
        } elseif ($it && $it['status'] === 'open') {
            $pdo->prepare("UPDATE dp_items SET status = 'done', done_at = ?, detail = ?, payload = ?, title = ? WHERE id = ?")->execute([bd_now(), 'All added: ' . $added . ' of ' . $total, $mk($added, max($added, $total)), $title, $it['id']]);
        }
        return ['rem' => $rem, 'added' => $added];
    } catch (Throwable $e) { error_log('Dispatch job: ' . $e->getMessage()); return ['rem' => 0, 'added' => 0]; }
}

/* ============================== penalties of the Dispatch page ============================== */
/** the problems that can be fined, with the rule key each one uses. The three "dsp_*" rules are made switched off by dp_dsp_ensure; the two older ones are the Boss defaults. */
function dp_dsp_pen_defs() {
    return [
        'noid_48h'  => ['rkey' => 'tracking_48h', 'title' => 'No tracking ID in ' . dp_dsp_track_hours() . ' h', 'seat' => 'dispatch.tracking', 'sort' => 10, 'when' => 'Order time + ' . dp_dsp_track_hours() . ' h'],
        'g3_48h'    => ['rkey' => 'dsp_g3_48h', 'title' => 'Tracking ID given but not in transit after ' . dp_dsp_pick_hours() . ' h', 'seat' => 'dispatch.lead', 'sort' => 26, 'when' => dp_dsp_pick_hours() . ' h after the tracking ID was given'],
        'day4'      => ['rkey' => 'transit_day4', 'title' => 'Not in transit by day ' . dp_dsp_transit_days(), 'seat' => 'dispatch.lead', 'sort' => 20, 'when' => 'Day ' . dp_dsp_transit_days() . ', 12 AM'],
        'untr_remark' => ['rkey' => 'dsp_untr_remark', 'title' => 'Untraceable parcel without today\'s remark', 'seat' => 'dispatch.untraceable', 'sort' => 28, 'when' => '6 PM every day (not Sunday), for each parcel'],
        'manual_24h' => ['rkey' => 'dsp_manual_24h', 'title' => 'Manual tracking ID not sent within 24 h of the Boss job', 'seat' => 'dispatch.tracking', 'sort' => 27, 'when' => '24 h after the Boss job appeared'],
    ];
}
/** make the three "dsp_*" rules (switched off, no amount) so they show in Boss > Penalties */
function dp_dsp_seed_rules(PDO $pdo) {
    try {
        foreach (dp_dsp_pen_defs() as $k => $d) {
            if (strpos($d['rkey'], 'dsp_') !== 0) continue;
            $pdo->prepare("INSERT IGNORE INTO dp_rules (rkey, title, topic, amount, per, decide, seat_code, when_text, mode, grace_min, active, sort_no, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$d['rkey'], $d['title'], 'dispatch', 0, 'parcel', 0, $d['seat'], $d['when'], 'approve', 0, 0, $d['sort'], bd_now()]);
        }
    } catch (Throwable $e) {}
}
/**
 * The tick part for the three new penalties. Each one works only when its rule is switched on in Boss, and only for problems that started AFTER it was switched on
 * (adding a penalty never fines the past). Sundays are skipped. Returns how many were raised.
 */
function dp_dsp_new_pens(PDO $pdo, $now) {
    $res = ['untr' => 0, 'g3' => 0, 'man' => 0]; if ((int)date('N', $now) === 7) return $res;
    $nowS = date('Y-m-d H:i:s', $now); $today = date('Y-m-d', $now); $G = dp_dsp_grp_sql($pdo); $win = date('Y-m-d', $now - 25 * 86400);
    $on = function ($rkey) use ($pdo) { $r = dp_rule($pdo, $rkey); return ($r && (int)$r['active'] === 1 && (float)$r['amount'] > 0) ? $r : null; };
    $names = function (array $rows) { return dp_dsp_names($rows, 8); };
    // 1. tracking ID given, courier never picked up, the pickup wait (+ grace)
    if ($rg = $on('dsp_g3_48h')) {
        $act = date('Y-m-d H:i:s', strtotime((string)$rg['updated_at']) ?: $now); $cut = date('Y-m-d H:i:s', $now - dp_dsp_pick_hours() * 3600 - (int)$rg['grace_min'] * 60);
        if ($rg['per'] === 'day') {
            // one fine a day while more than DP_DSP_G3_DAY_MIN parcels have waited over the pickup wait (from 10 AM, so the day's list is real)
            if ((int)date('G', $now) >= 10) {
                $q = $pdo->prepare("SELECT COUNT(*) FROM (SELECT tracked_at, ($G) grp FROM dp_dsp_parcels WHERE created_at >= ?) t WHERE grp = 3 AND tracked_at IS NOT NULL AND tracked_at <= ?"); $q->execute([$win, $cut]); $n = (int)$q->fetchColumn();
                if ($n > DP_DSP_G3_DAY_MIN && $now >= strtotime((string)$rg['updated_at'])) {
                    $iid = dp_item_make($pdo, 'dispatch', 'dsp_g3_48h', 'dsp:g3:' . date('Ymd', $now), 'dispatch.lead', $n . ' parcels with a tracking ID are not in transit after ' . dp_dsp_pick_hours() . ' h', 'More than ' . DP_DSP_G3_DAY_MIN . ' parcels waited over ' . dp_dsp_pick_hours() . ' hours for the courier today.', $today, $nowS, ['count' => $n], null, 1);
                    dp_miss($pdo, $iid, 'dsp_g3_48h', 1, $n . ' parcels waiting'); $res['g3'] = $n;
                }
            }
        } else {
            $q = $pdo->prepare("SELECT * FROM (SELECT dp_dsp_parcels.*, ($G) grp FROM dp_dsp_parcels WHERE created_at >= ? AND t_g3 = 0) t WHERE grp = 3 AND tracked_at IS NOT NULL AND tracked_at <= ? AND tracked_at >= ?");
            $q->execute([$win, $cut, $act]); $rows = $q->fetchAll();
            if ($rows) {
                $ids = array_map(function ($r) { return (int)$r['id']; }, $rows); $pdo->exec("UPDATE dp_dsp_parcels SET t_g3 = 1 WHERE id IN (" . implode(',', $ids) . ")");
                $iid = dp_item_make($pdo, 'dispatch', 'dsp_g3_48h', 'dsp:g3:' . date('Ymd-His', $now), 'dispatch.lead', 'Tracking ID given but not in transit after ' . dp_dsp_pick_hours() . ' h: ' . count($rows) . ' parcel' . (count($rows) === 1 ? '' : 's'), $names($rows), $today, $nowS, ['ids' => $ids], null, count($rows));
                dp_miss($pdo, $iid, 'dsp_g3_48h', count($rows), 'courier has not picked up in ' . dp_dsp_pick_hours() . ' h'); $res['g3'] = count($rows);
            }
        }
    }
    // 2. parcels still without an ID 24 h after they came into the Boss job
    if ($rm = $on('dsp_manual_24h')) {
        $act = date('Y-m-d H:i:s', strtotime((string)$rm['updated_at']) ?: $now); $cut = date('Y-m-d H:i:s', $now - 86400 - (int)$rm['grace_min'] * 60);
        $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status = 'placed' AND pend_track IS NULL AND t_man = 0 AND job_at IS NOT NULL AND job_at <= ? AND job_at >= ? AND created_at >= ?");
        $q->execute([$cut, $act, $win]); $rows = $q->fetchAll();
        if ($rows) {
            $ids = array_map(function ($r) { return (int)$r['id']; }, $rows); $pdo->exec("UPDATE dp_dsp_parcels SET t_man = 1 WHERE id IN (" . implode(',', $ids) . ")");
            $iid = dp_item_make($pdo, 'dispatch', 'dsp_manual_24h', 'dsp:man:' . date('Ymd-His', $now), 'dispatch.tracking', 'Tracking ID not sent within 24 h of the Boss job: ' . count($rows) . ' parcel' . (count($rows) === 1 ? '' : 's'), $names($rows), $today, $nowS, ['ids' => $ids], null, count($rows));
            dp_miss($pdo, $iid, 'dsp_manual_24h', count($rows), 'no tracking ID 24 h after the job'); $res['man'] = count($rows);
        }
    }
    return $res;
}

/** Notes the Dispatch tick put in the Boss inbox ("3 parcels close to the 48 h limit", "tracking ID waits for your OK") are only true for a few hours. Close them when they are old or the reason is gone. */
function dp_dsp_inbox_reconcile(PDO $pdo, $now) {
    $n = 0;
    try {
        $u = $pdo->prepare("UPDATE bd_inbox SET status = 'dismissed', resolved_at = ?, resolved_by = 'Dispatch check' WHERE kind = 'dp_note' AND status = 'open' AND ref_key LIKE ? AND created_at < ?");
        $u->execute([date('Y-m-d H:i:s', $now), 'dsp:w%', date('Y-m-d H:i:s', $now - 36 * 3600)]); $n += $u->rowCount();
        $left = (int)$pdo->query("SELECT COUNT(*) FROM dp_dsp_parcels WHERE status = 'placed' AND pend_track IS NOT NULL")->fetchColumn();
        if ($left === 0) { $u = $pdo->prepare("UPDATE bd_inbox SET status = 'dismissed', resolved_at = ?, resolved_by = 'Dispatch check' WHERE kind = 'dp_note' AND status = 'open' AND ref_key LIKE 'dsp:pend:%'"); $u->execute([date('Y-m-d H:i:s', $now)]); $n += $u->rowCount(); }
    } catch (Throwable $e) {}
    return $n;
}

/** Boss > "needs your attention" cases made from an old Dispatch check close by themselves when the check no longer finds the problem. Cases the boss already decided on (active) are never touched. Returns how many. */
function dp_dsp_cases_reconcile(PDO $pdo, $now) {
    $refs = [];
    foreach (dp_dsp_problems($pdo, ['manager' => true], $now) as $pb) { if (in_array($pb['sev'], ['bad', 'warn'], true)) $refs['dsp:' . md5(preg_replace('/\d+/', 'N', $pb['title']))] = 1; }
    $n = 0;
    try {
        foreach ($pdo->query("SELECT id, src_ref FROM be_cases WHERE src_ref LIKE 'dsp:%' AND status IN ('asking','teach','test','clash','plan')")->fetchAll() as $r) {
            if (isset($refs[$r['src_ref']])) continue;
            $pdo->prepare("UPDATE be_cases SET status = 'closed', closed_at = ?, updated_at = ?, remark = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now), 'Closed by Claude: the Dispatch check no longer finds this problem.', (int)$r['id']]);
            $n++;
        }
    } catch (Throwable $e) { /* no Boss Engine cases table on this server */ }
    return $n;
}

/* ============================== KEEPING THE HRMS IN STEP WITH SHOPIFY / SHIPROCKET ==============================
 * Two outside sources are mirrored, each with its own job:
 *   Shopify    : the orders (which parcels exist) and, once an order is fulfilled, its tracking number + courier.
 *   Shiprocket : the tracking number, courier and the movement (picked up / in transit / delivered / RTO).
 * dp_dsp_reconcile() is the "Fix everything now" button: it reads everything again, day by day, with no order limit, in small
 * steps (every call does a few seconds of work and says where it stopped, the page keeps calling until it says done).
 * dp_dsp_autoheal() does the same quietly from the cron tick, so nothing falls behind again.
 * Every failure is written to dp_state (shop_last_err, sf_last_err, sr_last_err) and shown on the Dispatch page. Nothing here deletes anything. */
function dp_dsp_shop_ok() { return function_exists('shopify_stores') && function_exists('shopify_get_token'); }
/** throw away the saved Shopify access tokens (the next call asks Shopify for a fresh one). A stale token is the usual cause of "invalid token". */
function dp_dsp_shop_reset_token() { foreach ((array)@glob(sys_get_temp_dir() . '/shopify_token_*.json') as $f) @unlink($f); }
/** GET on the Shopify Admin API. Returns [http code, json|null, curl error, Retry-After seconds] */
function dp_dsp_shop_get($domain, $token, $path, array $q) {
    if (!function_exists('curl_init')) return [0, null, 'PHP curl is not switched on on this server.', 0];
    $base = trim((string)tmph_cfg('SHOPIFY_API_BASE', ''));   // empty = the real shop address; only a test server sets it
    $ch = curl_init(($base !== '' ? rtrim($base, '/') : 'https://' . $domain) . $path . ($q ? '?' . http_build_query($q) : ''));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: ' . $token, 'Accept: application/json']]);
    $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); $err = $resp === false ? curl_error($ch) : ''; curl_close($ch);
    $retry = 0; $body = '';
    if ($resp !== false) { $h = substr($resp, 0, $hs); if (preg_match('/^Retry-After:\s*(\d+)/mi', $h, $m)) $retry = (int)$m[1]; $body = substr($resp, $hs); }
    $j = $body !== '' ? json_decode($body, true) : null;
    return [$code, is_array($j) ? $j : null, $err, $retry];
}
function dp_dsp_shop_say($code, $err, $label) {
    if ($err !== '') return $label . ': could not reach Shopify (' . $err . ').';
    if ($code === 401) return $label . ': Shopify did not accept the access token. The app must be installed on the store and the Client ID / Secret in api.php must be the current ones.';
    if ($code === 403) return $label . ': the Shopify app has no permission to read orders (it needs read_orders; read_all_orders for orders older than 60 days).';
    if ($code === 404) return $label . ': Shopify did not find the store (check the .myshopify.com address in api.php).';
    if ($code === 402) return $label . ': the Shopify store is on hold (payment).';
    if ($code === 429) return $label . ': Shopify says too many requests. It continues by itself in a few minutes.';
    return $label . ': Shopify answered HTTP ' . $code . '.';
}
function dp_dsp_shop_note($pfx, $ok, $msg, $err) { dp_put($pfx . '_last_ts', (string)time()); dp_put($pfx . '_last_msg', $ok ? $msg : ''); dp_put($pfx . '_last_err', $err); }

/** 1. copy the newest Shopify orders into shop_orders (then into parcels). A stale token is replaced once and the read repeated. */
function dp_dsp_shop_refresh(PDO $pdo, $now, $days = 2) {
    $r = ['ok' => false, 'n' => 0, 'error' => '', 'skipped' => ''];
    if (!dp_dsp_shop_ok() || !function_exists('orders_sync_range')) { $r['skipped'] = 'The Shopify order copy is not part of this server.'; return $r; }
    $from = date('Y-m-d', $now - max(1, (int)$days) * 86400); $to = date('Y-m-d', $now);
    for ($try = 0; $try < 2; $try++) {
        $errs = []; $n = 0;
        try { $n = (int)orders_sync_range($pdo, $from, $to, 12, $errs); } catch (Throwable $e) { $errs[] = 'Shopify order copy: ' . $e->getMessage(); }
        if (!$errs) { $r['ok'] = true; $r['n'] = $n; break; }
        $r['error'] = implode(' | ', array_slice(array_unique($errs), 0, 3));
        if ($try === 0 && preg_match('/token|permission|401|403/i', $r['error'])) { dp_dsp_shop_reset_token(); continue; }
        break;
    }
    $r['error'] = str_replace('returned HTTP 0.', 'could not be reached from this server (internet or firewall problem).', $r['error']);
    dp_dsp_shop_note('shop', $r['ok'], $r['n'] . ' orders read from Shopify.', $r['error']);
    return $r;
}

/** Shopify fulfilment status -> our status (only forward moves are applied by dp_dsp_apply) */
function dp_dsp_shop_map($ship) { $s = strtolower((string)$ship); if ($s === 'delivered') return 'delivered'; if (in_array($s, ['in_transit', 'out_for_delivery', 'attempted_delivery'], true)) return 'transit'; return 'tracked'; }
/** the tracking number, courier and movement written on a Shopify order's fulfilments, or null when there is none */
function dp_dsp_shop_pick(array $o) {
    $best = null;
    foreach ((array)($o['fulfillments'] ?? []) as $f) {
        if (!is_array($f) || strtolower((string)($f['status'] ?? '')) === 'cancelled') continue;
        $no = trim((string)($f['tracking_number'] ?? '')); if ($no === '' && !empty($f['tracking_numbers'][0])) $no = trim((string)$f['tracking_numbers'][0]);
        if ($no === '') continue;
        $best = ['awb' => $no, 'courier' => trim((string)($f['tracking_company'] ?? '')), 'ship' => (string)($f['shipment_status'] ?? '')];
    }
    return $best;
}
/** 2. ask Shopify, order by order, for the tracking number its fulfilments carry and copy it onto the parcels that have none.
 *  Works on a list of parcel rows. Returns ['found','updated','fulfilled_no_number','checked','error','retry']. */
function dp_dsp_shop_tracking(PDO $pdo, array $parcels) {
    $r = ['checked' => 0, 'found' => 0, 'updated' => 0, 'fulfilled_no_number' => 0, 'error' => '', 'retry' => 0];
    if (!$parcels) return $r;
    if (!dp_dsp_shop_ok()) { $r['error'] = 'The Shopify connection is not part of this server.'; return $r; }
    $stores = shopify_stores(); $by = [];
    foreach ($parcels as $p) { if (isset($stores[$p['store_key']])) $by[$p['store_key']][(string)$p['shopify_id']] = $p; }
    foreach ($by as $key => $list) {
        $store = $stores[$key]; $tok = shopify_get_token($key, $store);
        if (is_array($tok)) { $r['error'] = (string)($tok['error'] ?? 'Could not get a Shopify access token.'); return $r; }
        foreach (array_chunk(array_keys($list), 100) as $ids) {
            $q = ['ids' => implode(',', $ids), 'status' => 'any', 'limit' => 250, 'fields' => 'id,name,cancelled_at,fulfillment_status,fulfillments'];
            list($code, $j, $err, $retry) = dp_dsp_shop_get($store['domain'], $tok, '/admin/api/2024-10/orders.json', $q);
            if ($code === 401 || $code === 403) { dp_dsp_shop_reset_token(); $tok = shopify_get_token($key, $store); if (is_array($tok)) { $r['error'] = (string)($tok['error'] ?? 'No Shopify token.'); return $r; } list($code, $j, $err, $retry) = dp_dsp_shop_get($store['domain'], $tok, '/admin/api/2024-10/orders.json', $q); }
            if ($code !== 200 || !is_array($j)) { $r['error'] = dp_dsp_shop_say($code, $err, $store['label'] ?? $key); $r['retry'] = $retry; return $r; }
            foreach ((array)($j['orders'] ?? []) as $o) {
                if (!is_array($o) || !isset($list[(string)$o['id']])) continue;
                $p = $list[(string)$o['id']]; $r['checked']++;
                $fs = (string)($o['fulfillment_status'] ?? ''); $fs = $fs === '' ? 'unfulfilled' : $fs;
                if ($fs !== (string)($p['shop_fulfil'] ?? '')) $pdo->prepare("UPDATE dp_dsp_parcels SET shop_fulfil = ? WHERE id = ?")->execute([mb_substr($fs, 0, 30), $p['id']]);
                if (!empty($o['cancelled_at']) && in_array($p['status'], ['placed', 'tracked'], true)) { dp_dsp_apply($pdo, $p, '', '', 'cancelled', 'Shopify'); $r['updated']++; continue; }
                $t = dp_dsp_shop_pick($o);
                if (!$t) { if (strtolower($fs) === 'fulfilled') $r['fulfilled_no_number']++; continue; }
                $r['found']++;
                if (!dp_dsp_valid_track($t['awb'])) continue;
                if (dp_dsp_track_in_use($pdo, $t['awb'], (int)$p['id']) !== '') continue;   // the same number is on another parcel: leave it for a person
                if (dp_dsp_apply($pdo, $p, $t['awb'], $t['courier'], dp_dsp_shop_map($t['ship']), 'Shopify', false) !== '') $r['updated']++;
            }
        }
    }
    return $r;
}
/** parcels that still lack a tracking number or a courier name, oldest id first, after $afterId */
function dp_dsp_sf_candidates(PDO $pdo, $days, $afterId, $limit) {
    $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id > ? AND created_at >= ? AND status IN ('placed','tracked') AND pend_track IS NULL AND (tracking_no IS NULL OR tracking_no = '' OR courier IS NULL OR courier = '') ORDER BY id LIMIT " . (int)$limit);
    $q->execute([(int)$afterId, date('Y-m-d', time() - (int)$days * 86400)]); return $q->fetchAll();
}
function dp_dsp_sf_count(PDO $pdo, $days) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM dp_dsp_parcels WHERE created_at >= ? AND status IN ('placed','tracked') AND pend_track IS NULL AND (tracking_no IS NULL OR tracking_no = '' OR courier IS NULL OR courier = '')");
    $q->execute([date('Y-m-d', time() - (int)$days * 86400)]); return (int)$q->fetchColumn();
}
/** run the Shopify tracking read for up to $budget seconds. $after is the id it stopped at. Returns [finished, totals, error] */
function dp_dsp_sf_run(PDO $pdo, $days, &$after, $budget, array &$tot) {
    $t0 = microtime(true);
    while (true) {
        $rows = dp_dsp_sf_candidates($pdo, $days, $after, 100);
        if (!$rows) return [true, $tot, ''];
        $r = dp_dsp_shop_tracking($pdo, $rows);
        foreach (['checked', 'found', 'updated', 'fulfilled_no_number'] as $k) $tot[$k] = ($tot[$k] ?? 0) + $r[$k];
        if ($r['error'] !== '') return [false, $tot, $r['error']];
        $after = (int)end($rows)['id'];
        if (microtime(true) - $t0 > $budget) return [false, $tot, ''];
    }
}

/** the days on which parcels are still open (no final status), newest first. Today and yesterday are always in. */
function dp_dsp_open_days(PDO $pdo, $now, $span = 100) {
    $q = $pdo->prepare("SELECT DISTINCT DATE(created_at) d FROM dp_dsp_parcels WHERE created_at >= ? AND status IN ('placed','tracked','transit') ORDER BY d DESC");
    $q->execute([date('Y-m-d', $now - $span * 86400)]); $d = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
    foreach ([date('Y-m-d', $now), date('Y-m-d', $now - 86400)] as $x) if (!in_array($x, $d, true)) $d[] = $x;
    rsort($d); return $d;
}
/** read the Shiprocket orders of ONE day (every page). Returns [totals, error, finished]. Stops after $maxPages pages and says where ($page). */
function dp_dsp_sr_day(PDO $pdo, $day, &$page, $maxPages, array &$r) {
    $t = strtotime($day . ' 12:00:00');
    $ix = dp_dsp_sr_index($pdo, date('Y-m-d', $t - 2 * 86400), date('Y-m-d', $t + 3 * 86400));
    $to = date('Y-m-d', $t + 86400); $n = 0;
    while ($n < $maxPages) {
        list($cnt, $e, $tp) = dp_dsp_sr_page($pdo, $day, $to, $page, $ix, $r);
        if ($e !== '') return [$r, $e, false];
        $page++; $n++; $r['pages']++;
        if ($cnt < 100 || ($tp && $page > $tp)) { $page = 1; return [$r, '', true]; }
    }
    return [$r, '', false];
}
function dp_dsp_rec_counts(PDO $pdo) {
    $o = ['no_tracking' => 0, 'placed_all' => 0, 'tracked' => 0, 'transit' => 0, 'delivered' => 0];
    try {
        $o['no_tracking'] = (int)$pdo->query("SELECT COUNT(*) FROM dp_dsp_parcels WHERE status = 'placed' AND (tracking_no IS NULL OR tracking_no = '') AND (pend_track IS NULL OR pend_track = '')")->fetchColumn();
        foreach ($pdo->query("SELECT status, COUNT(*) c FROM dp_dsp_parcels GROUP BY status")->fetchAll() as $x) { if ($x['status'] === 'placed') $o['placed_all'] = (int)$x['c']; elseif (isset($o[$x['status']])) $o[$x['status']] = (int)$x['c']; }
    } catch (Throwable $e) {}
    return $o;
}

/** THE BUTTON "Fix everything now". Call it again and again (the page does) until 'done' is true. Safe to stop and start again.
 *  Order: newest Shopify orders -> Shiprocket, day by day (it knows nearly everything) -> Shopify tracking numbers, only for what is STILL without one. */
function dp_dsp_reconcile(PDO $pdo, $now, $restart = false, $budget = 12) {
    @set_time_limit(90); $t0 = microtime(true);
    $st = json_decode((string)dp_get('rec_state', ''), true);
    if ($restart || !is_array($st) || !empty($st['done'])) {
        $st = ['at' => date('Y-m-d H:i:s', $now), 'phase' => 'shop', 'done' => false, 'after' => 0, 'sf_total' => 0, 'sf_tot' => [], 'days' => null, 'di' => 0, 'page' => 1, 'sr' => dp_dsp_sr_blank(), 'err' => [], 'before' => dp_dsp_rec_counts($pdo), 'note' => []];
    }
    $sr = dp_dsp_sr_cfg();
    while (!$st['done'] && microtime(true) - $t0 < $budget) {
        if ($st['phase'] === 'shop') {
            $x = dp_dsp_shop_refresh($pdo, $now, 3);
            if ($x['skipped'] !== '') $st['note'][] = $x['skipped']; elseif (!$x['ok']) $st['err'][] = 'Shopify orders: ' . $x['error']; else $st['note'][] = $x['n'] . ' newest orders read from Shopify.';
            dp_dsp_sync($pdo, $now, true);
            $st['phase'] = 'sr';
        } elseif ($st['phase'] === 'sr') {
            if (!$sr['ok']) { $st['err'][] = 'Shiprocket: email and password are not saved.'; $st['phase'] = 'sf'; $st['sf_total'] = dp_dsp_sf_count($pdo, 60); continue; }
            if ($st['days'] === null) $st['days'] = dp_dsp_open_days($pdo, $now);
            if ($st['di'] >= count($st['days'])) { $st['phase'] = 'sf'; $st['sf_total'] = dp_dsp_sf_count($pdo, 60); continue; }
            $r = $st['sr']; $page = (int)$st['page'];
            list($r, $e, $fin) = dp_dsp_sr_day($pdo, $st['days'][$st['di']], $page, 6, $r); $st['sr'] = $r; $st['page'] = $page;
            if ($e !== '') { $st['err'][] = 'Shiprocket (' . $st['days'][$st['di']] . '): ' . $e; $st['sr']['error'] = $e; $st['phase'] = 'sf'; $st['sf_total'] = dp_dsp_sf_count($pdo, 60); continue; }   // say why; the Shopify step still runs
            if ($fin) { $st['di']++; $st['page'] = 1; }
        } elseif ($st['phase'] === 'sf') {
            if (!dp_dsp_shop_ok()) { $st['note'][] = 'Shopify tracking read skipped (no Shopify connection on this server).'; $st['phase'] = 'end'; continue; }
            $tot = $st['sf_tot']; list($fin, $tot, $err) = dp_dsp_sf_run($pdo, 60, $st['after'], max(2, $budget - (microtime(true) - $t0)), $tot); $st['sf_tot'] = $tot;
            if ($err !== '') { $st['err'][] = 'Shopify tracking: ' . $err; dp_dsp_shop_note('sf', false, '', $err); $st['phase'] = 'end'; continue; }
            if ($fin) { dp_dsp_shop_note('sf', true, ($tot['checked'] ?? 0) . ' orders checked, ' . ($tot['updated'] ?? 0) . ' parcels updated.', ''); $st['phase'] = 'end'; }
        } else {
            $st['after_counts'] = dp_dsp_rec_counts($pdo); $st['done'] = true; $st['phase'] = 'end';
            $srr = $st['sr']; $srr['ok'] = $srr['error'] === '' && $sr['ok']; $srr['examples'] = array_slice($srr['examples'], 0, 8);
            if ($sr['ok']) dp_dsp_sr_note($srr);
            $sum = ['at' => date('Y-m-d H:i:s', $now), 'ts' => time(), 'before' => $st['before'], 'after' => $st['after_counts'], 'sf' => $st['sf_tot'], 'sr_seen' => $srr['seen'], 'sr_updated' => $srr['updated'], 'sr_unmatched' => $srr['unmatched'], 'err' => array_slice($st['err'], 0, 6)];
            dp_put('rec_last', json_encode($sum, JSON_UNESCAPED_UNICODE)); bd_log('dispatch', 'reconcile', 'Fix everything: ' . $st['before']['no_tracking'] . ' → ' . $st['after_counts']['no_tracking'] . ' parcels without tracking', 'Dispatch');
        }
    }
    dp_put('rec_state', json_encode($st, JSON_UNESCAPED_UNICODE));
    $pct = 100; $nd = $st['days'] ? count($st['days']) : 0;
    if (!$st['done']) {
        if ($st['phase'] === 'shop') $pct = 2;
        elseif ($st['phase'] === 'sr') $pct = 3 + (int)round(67 * min(1, $nd ? $st['di'] / max(1, $nd) : 0));
        else $pct = 71 + (int)round(27 * min(1, ($st['sf_tot']['checked'] ?? 0) / max(1, $st['sf_total'])));
    }
    $label = ['shop' => 'Reading the newest orders from Shopify', 'sr' => 'Reading Shiprocket day by day' . ($nd && $st['di'] < $nd ? ' (' . $st['days'][$st['di']] . ')' : ''), 'sf' => 'Reading tracking numbers from Shopify for the parcels still without one', 'end' => 'Finishing'][$st['phase']] ?? '';
    return ['done' => (bool)$st['done'], 'pct' => $pct, 'phase' => $st['phase'], 'text' => $st['done'] ? 'Finished.' : $label . '…', 'before' => $st['before'], 'after' => $st['after_counts'] ?? null, 'sf' => $st['sf_tot'],
        'sr' => ['seen' => $st['sr']['seen'], 'updated' => $st['sr']['updated'], 'unmatched' => $st['sr']['unmatched'], 'days' => $nd, 'day_no' => $st['di']], 'errors' => array_values(array_unique($st['err'])), 'notes' => $st['note']];
}

/**
 * KWIK id (the GoKwik / PayU transaction id) for EVERY order, so a Finance settlement file matches its orders by itself.
 * Step A: orders copied before the id was read (pay_ref NULL): read the order note / attributes / tags again, newest first.
 * Step B: orders with no id on the order itself ('' and not yet tried): look in the order's payment transactions, once.
 * Returns ['a' => read, 'b' => read, 'found' => n, 'left_a' => n, 'left_b' => n, 'error' => '']. Nothing is deleted; an id already saved is never blanked.
 */
function dp_dsp_kwik_run(PDO $pdo, $budget = 8, $days = 400) {
    $r = ['a' => 0, 'b' => 0, 'found' => 0, 'left_a' => 0, 'left_b' => 0, 'error' => ''];
    if (!dp_dsp_shop_ok() || !function_exists('order_pay_refs')) { $r['error'] = 'The Shopify connection is not part of this server.'; return $r; }
    try { $pdo->exec("ALTER TABLE shop_orders ADD COLUMN pay_ref VARCHAR(200) NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE shop_orders ADD COLUMN pay_ref_tx TINYINT NULL"); } catch (Throwable $e) {}
    $t0 = microtime(true); $stores = shopify_stores(); $cut = date('Y-m-d', time() - (int)$days * 86400) . ' 00:00:00';
    $tok = []; $get = function ($key, $path, $q) use (&$tok, $stores) {
        if (!isset($stores[$key])) return [0, null, 'unknown store', 0];
        if (!isset($tok[$key])) { $t = shopify_get_token($key, $stores[$key]); if (is_array($t)) return [0, null, (string)($t['error'] ?? 'No Shopify token.'), 0]; $tok[$key] = $t; }
        $x = dp_dsp_shop_get($stores[$key]['domain'], $tok[$key], $path, $q);
        if ($x[0] === 401 || $x[0] === 403) { dp_dsp_shop_reset_token(); unset($tok[$key]); $t = shopify_get_token($key, $stores[$key]); if (is_array($t)) return [0, null, (string)($t['error'] ?? 'No Shopify token.'), 0]; $tok[$key] = $t; $x = dp_dsp_shop_get($stores[$key]['domain'], $tok[$key], $path, $q); }
        return $x;
    };
    $set = $pdo->prepare("UPDATE shop_orders SET pay_ref = ? WHERE store_key = ? AND shopify_id = ?");
    // step A
    while (microtime(true) - $t0 < $budget) {
        $q = $pdo->prepare("SELECT shopify_id, store_key FROM shop_orders WHERE pay_ref IS NULL AND created_at >= ? ORDER BY created_at DESC LIMIT 100"); $q->execute([$cut]); $rows = $q->fetchAll(); if (!$rows) break;
        $by = []; foreach ($rows as $x) $by[$x['store_key']][(string)$x['shopify_id']] = 1;
        foreach ($by as $key => $ids) {
            list($code, $j, $err, $retry) = $get($key, '/admin/api/2024-10/orders.json', ['ids' => implode(',', array_keys($ids)), 'status' => 'any', 'limit' => 250, 'fields' => 'id,note,note_attributes,tags,checkout_token']);
            if ($code !== 200 || !is_array($j)) { $r['error'] = dp_dsp_shop_say($code, $err, $stores[$key]['label'] ?? $key); return $r; }
            $seen = [];
            foreach ((array)($j['orders'] ?? []) as $o) { if (!is_array($o)) continue; $k = order_pay_refs($o); $set->execute([$k, $key, (string)$o['id']]); $seen[(string)$o['id']] = 1; $r['a']++; if ($k !== '') $r['found']++; }
            foreach (array_keys($ids) as $id) if (!isset($seen[$id])) $set->execute(['', $key, $id]);      // Shopify no longer returns it: do not ask again
        }
    }
    // step B
    while (microtime(true) - $t0 < $budget) {
        $q = $pdo->prepare("SELECT shopify_id, store_key FROM shop_orders WHERE pay_ref_tx IS NULL AND created_at >= ? AND (outstanding IS NULL OR outstanding < total) ORDER BY created_at DESC LIMIT 20"); $q->execute([$cut]); $rows = $q->fetchAll(); if (!$rows) break;
        foreach ($rows as $x) {
            if (microtime(true) - $t0 >= $budget) break 2;
            list($code, $j, $err, $retry) = $get($x['store_key'], '/admin/api/2024-10/orders/' . (int)$x['shopify_id'] . '/transactions.json', []);
            if ($code === 429) { $r['error'] = 'Shopify says too many requests; it continues by itself.'; return $r; }
            if ($code === 404) { $pdo->prepare("UPDATE shop_orders SET pay_ref_tx = 2 WHERE store_key = ? AND shopify_id = ?")->execute([$x['store_key'], $x['shopify_id']]); continue; }
            if ($code !== 200 || !is_array($j)) { $r['error'] = dp_dsp_shop_say($code, $err, $stores[$x['store_key']]['label'] ?? $x['store_key']); return $r; }
            $k = order_pay_refs(['note' => json_encode($j['transactions'] ?? [], JSON_UNESCAPED_UNICODE)]); $r['b']++;
            if (function_exists('of_save_pay')) of_save_pay($pdo, $x['store_key'], $x['shopify_id'], (array)($j['transactions'] ?? []));      // keep the gateway's own details on the order copy too
            if ($k !== '') { $r['found']++; $pdo->prepare("UPDATE shop_orders SET pay_ref = IF(pay_ref IS NULL OR pay_ref = '', ?, pay_ref), pay_ref_tx = 1 WHERE store_key = ? AND shopify_id = ?")->execute([$k, $x['store_key'], $x['shopify_id']]); }
            else $pdo->prepare("UPDATE shop_orders SET pay_ref_tx = 0 WHERE store_key = ? AND shopify_id = ?")->execute([$x['store_key'], $x['shopify_id']]);
            usleep(550000);      // Shopify allows about 2 calls a second
        }
    }
    try {
        $r['left_a'] = (int)$pdo->query("SELECT COUNT(*) FROM shop_orders WHERE pay_ref IS NULL AND created_at >= '" . $cut . "'")->fetchColumn();
        $r['left_b'] = (int)$pdo->query("SELECT COUNT(*) FROM shop_orders WHERE pay_ref_tx IS NULL AND created_at >= '" . $cut . "' AND (outstanding IS NULL OR outstanding < total)")->fetchColumn();
    } catch (Throwable $e) {}
    return $r;
}
/** the numbers behind the "KWIK ids" line on the Dispatch page */
function dp_dsp_kwik_stats(PDO $pdo) {
    try { $x = $pdo->query("SELECT COUNT(*) n, SUM(pay_ref IS NULL) unread, SUM(pay_ref <> '') have FROM shop_orders")->fetch(); return ['orders' => (int)$x['n'], 'unread' => (int)$x['unread'], 'have' => (int)$x['have']]; } catch (Throwable $e) { return ['orders' => 0, 'unread' => 0, 'have' => 0]; }
}

/** the quiet part of the cron tick, step 1 (before parcels are copied): bring the newest Shopify orders in every 10 minutes */
function dp_dsp_autoheal_orders(PDO $pdo, $now) {
    if ($now - (int)dp_get('shop_last_ts', '0') < 600) return null;
    $x = dp_dsp_shop_refresh($pdo, $now, 2); return $x['ok'] ? $x['n'] : ($x['skipped'] !== '' ? null : -1);
}
/** step 2 (after the copy): Shopify tracking numbers for the parcels that lack one (every 15 minutes) and ONE day of Shiprocket every 4 minutes, going round the open days */
function dp_dsp_autoheal(PDO $pdo, $now) {
    $out = [];
    $c = dp_dsp_sr_cfg();
    if ($c['ok'] && $now - (int)dp_get('sr_roll_ts', '0') >= 240) {
        dp_put('sr_roll_ts', (string)$now);
        $days = dp_dsp_open_days($pdo, $now, 60); $i = (int)dp_get('sr_roll_i', '0'); $day = $days[$i % max(1, count($days))]; $page = 1; $r = dp_dsp_sr_blank();
        list($r, $e, $fin) = dp_dsp_sr_day($pdo, $day, $page, 12, $r);
        dp_put('sr_roll_i', (string)(($i + 1) % max(1, count($days))));
        dp_put('sr_roll_err', $e);
        dp_put('sr_roll_msg', $day . ': ' . $r['seen'] . ' read, ' . $r['updated'] . ' updated');
        $out['roll'] = $e === '' ? $r['updated'] : -1;
    }
    if (dp_dsp_shop_ok() && $now - (int)dp_get('sf_last_ts', '0') >= 900) {
        $after = 0; $tot = []; list($fin, $tot, $err) = dp_dsp_sf_run($pdo, 30, $after, 8, $tot);
        dp_dsp_shop_note('sf', $err === '', ($tot['checked'] ?? 0) . ' orders checked, ' . ($tot['updated'] ?? 0) . ' parcels updated' . ($fin ? '.' : ' (more next time).'), $err);
        $out['sf'] = $err === '' ? ($tot['updated'] ?? 0) : -1;
    }
    if (dp_dsp_shop_ok() && $now - (int)dp_get('kwik_last_ts', '0') >= 240) {      // KWIK ids of every order (Finance matches its settlement files with them)
        dp_put('kwik_last_ts', (string)$now); $k = dp_dsp_kwik_run($pdo, 8);
        dp_dsp_shop_note('kwik', $k['error'] === '', $k['a'] . ' orders read, ' . $k['b'] . ' payment records checked, ' . $k['found'] . ' KWIK ids found · left: ' . $k['left_a'] . ' + ' . $k['left_b'], $k['error']);
        $out['kwik'] = $k['error'] === '' ? $k['found'] : -1;
    }
    if (function_exists('of_tick') && $now - (int)dp_get('of_last_ts', '0') >= 120) {      // order facts: one row per order with payment, who took it, commission, Shiprocket and Finance
        dp_put('of_last_ts', (string)$now); $o = of_tick($pdo, $now, 10.0);
        dp_dsp_shop_note('of', $o['error'] === '', $o['facts'] . ' orders refreshed, ' . $o['com_days'] . ' commission days', $o['error']);
        $out['facts'] = $o['facts'];
    }
    if (function_exists('ohub_tick')) { try { $h = ohub_tick($pdo, $now); if ($h['ran']) dp_dsp_shop_note('ohub', $h['error'] === '', $h['n'] . ' customers in the Customers list', $h['error']); } catch (Throwable $e) { } }   // Customers list (customer_facts), every 6 hours
    return $out;
}
