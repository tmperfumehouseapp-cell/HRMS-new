<?php
/**
 * wa_adleads.php — WhatsApp → Queries & reports → 📣 Ad leads (action wa.g_adleads, Admin / HR; loaded by wa_growth.php).
 * One day, one line per customer who came from a Meta click-to-WhatsApp ad: the time, the WhatsApp number they wrote to,
 * the ad, new or returning, and the FIRST message they wrote that day — so nobody has to open hundreds of chats to see the leads.
 *
 * Where it reads: wa_queries (a query the HRMS opened with src = 'query', i.e. the message carried an ad referral or the customer first came from an ad),
 * wa_contacts (name, ad) and wa_messages (first incoming message of the day).
 * ⚡ Light on the server: wa_queries is read through its opened_at index for one day only, and the first messages come in a few grouped reads
 * through the (phone, id) index. It never scans all chats, writes nothing, and runs only when the page is opened or refreshed.
 */
/** employee id → name, for a handful of ids (one read). */
function wag_adleads_names(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); if (!$ids) return [];
    try { $q = $pdo->prepare("SELECT employee_id, full_name FROM employees WHERE employee_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"); $q->execute($ids);
        $o = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['employee_id']] = (string)$r['full_name']; return $o; } catch (Throwable $e) { return []; }
}

/** The ad's ready-made first message ("Hello! Can I get more info on this?") — the owner's rule for a click-to-WhatsApp ad lead. */
function wag_adleads_pre(string $t): bool { return (bool)preg_match('/more\s+info(rmation)?\s+(on|about)\s+this/iu', $t); }

/** $kind: 'rule' = the ad's ready-made first message OR Meta's ad tag (default) · 'pre' · 'tag' · 'other' = the HRMS guessed an ad from other words · 'all'. */
function wag_adleads(PDO $pdo, string $day, int $numId, int $cap = 1500, string $kind = 'rule'): array
{
    if (!in_array($kind, ['rule', 'pre', 'tag', 'other', 'all'], true)) $kind = 'rule';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || strtotime($day) === false) $day = date('Y-m-d');
    $from = $day . ' 00:00:00'; $to = date('Y-m-d', strtotime($day . ' 12:00:00') + 86400) . ' 00:00:00';
    $st = $pdo->prepare("SELECT q.phone, q.opened_at, q.again, c.name, c.ad_id, c.ad_head, c.ref_text, c.assigned_emp FROM wa_queries q JOIN wa_contacts c ON c.phone = q.phone
        WHERE q.opened_at >= ? AND q.opened_at < ? AND q.src = 'query' ORDER BY q.opened_at DESC, q.id DESC LIMIT " . ($cap + 1));
    $st->execute([$from, $to]); $qs = $st->fetchAll(PDO::FETCH_ASSOC); $more = count($qs) > $cap; if ($more) array_pop($qs);
    $first = []; $phones = array_values(array_unique(array_map(fn($r) => (string)$r['phone'], $qs)));
    foreach (array_chunk($phones, 300) as $ch) {   // the first message each of them wrote that day
        $in = implode(',', array_fill(0, count($ch), '?'));
        $m = $pdo->prepare("SELECT m.phone, m.number_id, m.mtype, m.body, m.ts FROM wa_messages m JOIN (SELECT MIN(id) AS mid FROM wa_messages WHERE phone IN ($in) AND dir = 'in' AND ts >= ? AND ts < ? GROUP BY phone) x ON x.mid = m.id");
        $m->execute(array_merge($ch, [$from, $to])); foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) $first[(string)$r['phone']] = $r;
    }
    $nums = []; foreach ($pdo->query("SELECT id, label, display FROM wa_numbers ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $n) $nums[(int)$n['id']] = ['id' => (int)$n['id'], 'label' => trim((string)$n['label']) !== '' ? (string)$n['label'] : (string)$n['display'], 'n' => 0];
    $kinds = ['rule' => 0, 'pre' => 0, 'tag' => 0, 'other' => 0, 'all' => 0];
    $rows = []; $seen = []; $new = 0; $firstQ = []; foreach ($qs as $r) $firstQ[(string)$r['phone']] = (int)$r['again'];   // newest first, so what stays is the FIRST query of the day: new that day = new
    foreach ($qs as $r) {
        $ph = (string)$r['phone']; if (isset($seen[$ph])) continue; $seen[$ph] = 1;   // one line per customer (the newest query of the day)
        $f = $first[$ph] ?? null; $nid = $f ? (int)$f['number_id'] : 0;
        $txt = $f ? trim((string)$f['body']) : ''; $pre = wag_adleads_pre($txt); $tag = (string)$r['ad_id'] !== '';
        if ($f && $txt === '' && (string)$f['mtype'] !== 'text') $txt = '[' . (string)$f['mtype'] . ']';
        $is = ['all' => true, 'pre' => $pre, 'tag' => $tag, 'rule' => $pre || $tag, 'other' => !$pre && !$tag];
        if ($numId <= 0 || $nid === $numId) foreach ($is as $k => $v) if ($v) $kinds[$k]++;   // the kind chips count the chosen number
        if (!$is[$kind]) continue;
        if (isset($nums[$nid])) $nums[$nid]['n']++;   // the number chips count the chosen kind, whatever number is picked
        if ($numId > 0 && $nid !== $numId) continue;
        $isNew = ($firstQ[$ph] ?? 1) === 0; if ($isNew) $new++;
        $rows[] = ['phone' => $ph, 'name' => (string)$r['name'], 'at' => (string)($f['ts'] ?? $r['opened_at']), 'number' => isset($nums[$nid]) ? $nums[$nid]['label'] : '', 'new' => $isNew, 'pre' => $pre, 'tag' => $tag, 'holder_id' => (int)($r['assigned_emp'] ?? 0),
            'ad' => mb_substr((string)((string)$r['ad_head'] !== '' ? $r['ad_head'] : $r['ref_text']), 0, 90), 'ad_id' => (string)$r['ad_id'], 'first' => mb_substr($txt, 0, 300)];
    }
    $en = wag_adleads_names($pdo, array_column($rows, 'holder_id')); foreach ($rows as &$x) $x['holder'] = $en[$x['holder_id']] ?? ''; unset($x);   // who holds the chat
    return ['date' => $day, 'number' => $numId, 'rows' => $rows, 'total' => count($rows), 'new' => $new, 'all' => array_sum(array_column($nums, 'n')), 'kind' => $kind, 'kinds' => $kinds, 'numbers' => array_values($nums), 'more' => $more];
}

/**
 * 🩺 Diagnose — asks the separate click-to-WhatsApp tracking service (ctwa-roas) what IT saw, so the HRMS numbers can be checked against it.
 * The call is signed with WA_APP_SECRET (both sides already hold it; valid 5 minutes), has a 5 s limit and only reads.
 * Returns [data|null, error text].
 */
function wag_ctwa_get(string $path, string $argName, string $arg, ?string $base = null, ?string $secret = null): array
{
    if ($base === null) { require_once __DIR__ . '/wa_ctwa.php'; $u = wa_ctwa_url(); $base = $u === '' ? '' : preg_replace('~/webhooks/whatsapp/?$~', '', $u); }
    if ($secret === null) $secret = function_exists('wa_cfg_val') ? wa_cfg_val('WA_APP_SECRET') : '';
    if ($base === '' || $secret === '') return [null, 'The tracking service is switched off or WA_APP_SECRET is not set.'];
    if (!function_exists('curl_init')) return [null, 'This server cannot make the call (curl is missing).'];
    $ts = time(); $sig = hash_hmac('sha256', $ts . '|' . $path . '|' . $arg, $secret);
    $ch = curl_init($base . $path . '?' . http_build_query([$argName => $arg, 'ts' => $ts]));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['X-Ctwa-Sign: ' . $sig], CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($code === 404) return [null, 'The tracking service is running an older version (no Diagnose yet). Update it on the server: git pull, then pm2 restart ctwa.'];
    if ($code === 401) return [null, 'The tracking service refused the call: its WA_APP_SECRET is not the same as the HRMS one.'];
    if ($code < 200 || $code >= 300) return [null, 'The tracking service did not answer (HTTP ' . $code . ($err !== '' ? ', ' . $err : '') . ').'];
    $j = json_decode((string)$body, true); return is_array($j) ? [$j, ''] : [null, 'The tracking service sent an answer that could not be read.'];
}

/**
 * 🩺 What happened in each lead's chat and in the order lists, read when Diagnose is pressed (never for the plain list):
 *   orders of that customer from that day on (shop_orders holds Shopify AND manual orders) — where it was placed, who placed / got it, payment proof of a manual order,
 *   and the chat's own signs of a payment: photos / files the customer sent and words like "paid", "payment done", "UTR", "screenshot".
 * A photo is only COUNTED; nothing here reads the picture. Light: three grouped reads through indexes (created_at, (phone, status), (phone, id)); nothing is written.
 * Returns [phone => check], each check: orders[], photos, photo_at, says_paid, flag (shopify | manual | photo_no_order | paid_no_order | no_order).
 */
function wag_adleads_chat(PDO $pdo, array $phones, string $from): array
{
    $phones = array_values(array_unique(array_map('strval', $phones))); if (!$phones) return [];
    $set = array_flip($phones); $out = []; foreach ($phones as $p) $out[$p] = ['orders' => [], 'photos' => 0, 'photo_at' => '', 'says_paid' => false, 'flag' => 'no_order'];
    $to = date('Y-m-d H:i:s', strtotime($from) + 8 * 86400);
    // manual orders (wmo_order): who made it, how it is paid, proof
    $man = [];
    try { foreach (array_chunk($phones, 300) as $ch) {
        $q = $pdo->prepare("SELECT order_no, phone, emp_id, assign_emp, status, pay, total_p, paid_p, utr, shot_mime, created_at FROM wmo_order WHERE phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ") AND created_at >= ? AND status <> 'cancelled'");
        $q->execute(array_merge($ch, [$from])); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $man[(string)$r['order_no']] = $r; } } catch (Throwable $e) { }
    // every order of those days (Shopify + manual), matched on the last 10 digits
    $ords = []; $keys = [];
    try { $q = $pdo->prepare("SELECT shopify_id, store_key, order_name, created_at, phone, total, financial_status, outstanding FROM shop_orders WHERE created_at >= ? AND created_at < ? AND phone <> '' ORDER BY created_at LIMIT 30000"); $q->execute([$from, $to]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $k = substr(preg_replace('/\D+/', '', (string)$r['phone']), -10); if (!isset($set[$k])) continue; $fs = strtolower((string)$r['financial_status']); if ($fs === 'voided' || $fs === 'refunded') continue; $r['k'] = $k; $ords[] = $r; $keys[] = (string)$r['shopify_id']; } } catch (Throwable $e) { }
    $alloc = [];
    try { foreach (array_chunk(array_values(array_unique($keys)), 300) as $ch) { $q = $pdo->prepare("SELECT order_id, employee_id FROM order_allocations WHERE order_id IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $alloc[(string)$r['order_id']] = (int)$r['employee_id']; } } catch (Throwable $e) { }
    $ids = []; foreach ($alloc as $e) $ids[] = $e; foreach ($man as $m) { $ids[] = (int)$m['emp_id']; $ids[] = (int)$m['assign_emp']; } $nm = wag_adleads_names($pdo, $ids);
    $usedMan = [];
    foreach ($ords as $r) {
        $name = ltrim((string)$r['order_name'], '#'); $m = $man[$name] ?? ($man['#' . $name] ?? null); if ($m) $usedMan[(string)$m['order_no']] = 1;
        $emp = $alloc[(string)$r['shopify_id']] ?? 0;
        $out[$r['k']]['orders'][] = ['name' => (string)$r['order_name'], 'at' => (string)$r['created_at'], 'total' => (float)$r['total'], 'status' => (string)$r['financial_status'], 'where' => $m ? 'manual' : 'shopify',
            'given_to' => $nm[$emp] ?? '', 'placed_by' => $m ? ($nm[(int)$m['emp_id']] ?? '') : '', 'pay' => $m ? (string)$m['pay'] : '', 'paid' => $m ? round((int)$m['paid_p'] / 100, 2) : null, 'utr' => $m ? (string)$m['utr'] : '', 'proof' => $m ? (string)$m['shot_mime'] !== '' : null, 'final' => true];
    }
    foreach ($man as $no => $m) {   // a manual order that is not in the order list yet (the customer has not finished the link)
        if (isset($usedMan[$no]) || !isset($out[(string)$m['phone']])) continue;
        $out[(string)$m['phone']]['orders'][] = ['name' => (string)$no, 'at' => (string)$m['created_at'], 'total' => round((int)$m['total_p'] / 100, 2), 'status' => (string)$m['status'], 'where' => 'manual', 'given_to' => $nm[(int)$m['assign_emp']] ?? '',
            'placed_by' => $nm[(int)$m['emp_id']] ?? '', 'pay' => (string)$m['pay'], 'paid' => round((int)$m['paid_p'] / 100, 2), 'utr' => (string)$m['utr'], 'proof' => (string)$m['shot_mime'] !== '', 'final' => (string)$m['status'] === 'confirmed'];
    }
    // the chat's own signs of a payment
    try { foreach (array_chunk($phones, 300) as $ch) {
        $q = $pdo->prepare("SELECT phone, SUM(CASE WHEN mtype IN ('image', 'document') THEN 1 ELSE 0 END) AS ph, MAX(CASE WHEN mtype IN ('image', 'document') THEN ts END) AS pat,
            SUM(CASE WHEN mtype = 'text' AND (body LIKE '%paid%' OR body LIKE '%payment%' OR body LIKE '%pay kar%' OR body LIKE '%pay kiya%' OR body LIKE '%utr%' OR body LIKE '%screenshot%' OR body LIKE '%transfer%' OR body LIKE '%bhej diya%') THEN 1 ELSE 0 END) AS pw
            FROM wa_messages WHERE phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ") AND dir = 'in' AND ts >= ? AND ts < ? GROUP BY phone");
        $q->execute(array_merge($ch, [$from, $to]));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $p = (string)$r['phone']; if (!isset($out[$p])) continue; $out[$p]['photos'] = (int)$r['ph']; $out[$p]['photo_at'] = (string)($r['pat'] ?? ''); $out[$p]['says_paid'] = (int)$r['pw'] > 0; } } } catch (Throwable $e) { }
    foreach ($out as &$c) {
        $w = array_column($c['orders'], 'where');
        $c['flag'] = in_array('manual', $w, true) ? 'manual' : ($w ? 'shopify' : ($c['photos'] > 0 ? 'photo_no_order' : ($c['says_paid'] ? 'paid_no_order' : 'no_order')));
    } unset($c);
    return $out;
}

/** One day, both sides: the HRMS ad leads by the owner's rule (ready-made message or Meta's tag) with what happened in each chat, and what the tracking service saw. */
function wag_adcheck(PDO $pdo, string $day, ?array $svcTest = null, int $numId = 0): array
{
    $h = wag_adleads($pdo, $day, $numId, 1500, 'all'); $by = []; $newRule = 0; $rule = [];
    foreach ($h['rows'] as $r) { if (!($r['pre'] || $r['tag'])) continue; $rule[] = $r; if ($r['new']) $newRule++; if (!$r['tag']) continue; $k = $r['ad_id']; if (!isset($by[$k])) $by[$k] = ['ad_id' => $k, 'title' => $r['ad'], 'n' => 0, 'orders' => 0, 'amt' => 0.0]; $by[$k]['n']++; }   // per ad: only customers who carry Meta's ad tag
    $chat = wag_adleads_chat($pdo, array_column($rule, 'phone'), $h['date'] . ' 00:00:00');
    $noTag = ['n' => 0, 'orders' => 0, 'amt' => 0.0];
    $sum = ['leads' => count($rule), 'shopify' => 0, 'manual' => 0, 'photo_no_order' => 0, 'paid_no_order' => 0, 'no_order' => 0, 'shopify_amt' => 0.0, 'manual_amt' => 0.0]; $staff = [];
    foreach ($rule as $r) {
        $c = $chat[$r['phone']] ?? null; if (!$c) continue; $sum[$c['flag']]++;
        $ak = $r['tag'] ? $r['ad_id'] : ''; if ($ak === '') { $noTag['n']++; }   // orders per ad (customers with Meta's tag), the rest under 'no ad tag'
        foreach ($c['orders'] as $o) { if ($ak !== '' && isset($by[$ak])) { $by[$ak]['orders']++; $by[$ak]['amt'] += $o['total']; } else { $noTag['orders']++; $noTag['amt'] += $o['total']; } }
        foreach ($c['orders'] as $o) {
            $sum[$o['where'] . '_amt'] += $o['total']; $who = $o['where'] === 'manual' && $o['placed_by'] !== '' ? $o['placed_by'] : ($o['given_to'] !== '' ? $o['given_to'] : ($r['holder'] !== '' ? $r['holder'] : 'Nobody'));
            if (!isset($staff[$who])) $staff[$who] = ['name' => $who, 'shopify' => 0, 'manual' => 0, 'shopify_amt' => 0.0, 'manual_amt' => 0.0, 'no_proof' => 0];
            $staff[$who][$o['where']]++; $staff[$who][$o['where'] . '_amt'] += $o['total']; if ($o['where'] === 'manual' && $o['proof'] === false) $staff[$who]['no_proof']++;
        }
    }
    $staff = array_values($staff); usort($staff, fn($a, $b) => ($b['manual'] <=> $a['manual']) ?: ($b['shopify'] <=> $a['shopify']));
    [$svc, $err] = $svcTest ?? wag_ctwa_get('/diag', 'date', $h['date']);
    return ['date' => $h['date'], 'hrms' => ['total' => $h['kinds']['rule'], 'new' => $newRule, 'pre' => $h['kinds']['pre'], 'tag' => $h['kinds']['tag'], 'other' => $h['kinds']['other'], 'by_ad' => array_values($by), 'no_tag' => $noTag, 'more' => $h['more']], 'number' => $numId, 'chat' => $chat, 'sum' => $sum, 'staff' => $staff, 'svc' => $svc, 'svc_error' => $err, 'now' => date('Y-m-d H:i:s')];
}

/** One customer: the HRMS queries of this chat and, from the tracking service, the ad clicks and the Shopify orders of this number (matched? told to Meta?). */
function wag_adcheck_one(PDO $pdo, string $phone, ?array $svcTest = null): array
{
    $ph = preg_replace('/\D+/', '', $phone); if (strlen($ph) > 10) $ph = substr($ph, -10); if (strlen($ph) < 10) return ['error' => 'Not a phone number.'];
    $q = $pdo->prepare("SELECT opened_at, src, again FROM wa_queries WHERE phone = ? ORDER BY id DESC LIMIT 5"); $q->execute([$ph]);
    $c = $pdo->prepare("SELECT name, ad_id, ad_head, ref_text FROM wa_contacts WHERE phone = ?"); $c->execute([$ph]); $cr = $c->fetch(PDO::FETCH_ASSOC) ?: [];
    [$svc, $err] = $svcTest ?? wag_ctwa_get('/diag/phone', 'phone', $ph);
    return ['phone' => $ph, 'name' => (string)($cr['name'] ?? ''), 'ad' => (string)(($cr['ad_head'] ?? '') !== '' ? $cr['ad_head'] : ($cr['ref_text'] ?? '')), 'ad_id' => (string)($cr['ad_id'] ?? ''),
        'queries' => $q->fetchAll(PDO::FETCH_ASSOC), 'svc' => $svc, 'svc_error' => $err];
}
