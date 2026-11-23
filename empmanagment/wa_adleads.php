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
function wag_adleads(PDO $pdo, string $day, int $numId, int $cap = 1500): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || strtotime($day) === false) $day = date('Y-m-d');
    $from = $day . ' 00:00:00'; $to = date('Y-m-d', strtotime($day . ' 12:00:00') + 86400) . ' 00:00:00';
    $st = $pdo->prepare("SELECT q.phone, q.opened_at, q.again, c.name, c.ad_id, c.ad_head, c.ref_text FROM wa_queries q JOIN wa_contacts c ON c.phone = q.phone
        WHERE q.opened_at >= ? AND q.opened_at < ? AND q.src = 'query' ORDER BY q.opened_at DESC, q.id DESC LIMIT " . ($cap + 1));
    $st->execute([$from, $to]); $qs = $st->fetchAll(PDO::FETCH_ASSOC); $more = count($qs) > $cap; if ($more) array_pop($qs);
    $first = []; $phones = array_values(array_unique(array_map(fn($r) => (string)$r['phone'], $qs)));
    foreach (array_chunk($phones, 300) as $ch) {   // the first message each of them wrote that day
        $in = implode(',', array_fill(0, count($ch), '?'));
        $m = $pdo->prepare("SELECT m.phone, m.number_id, m.mtype, m.body, m.ts FROM wa_messages m JOIN (SELECT MIN(id) AS mid FROM wa_messages WHERE phone IN ($in) AND dir = 'in' AND ts >= ? AND ts < ? GROUP BY phone) x ON x.mid = m.id");
        $m->execute(array_merge($ch, [$from, $to])); foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) $first[(string)$r['phone']] = $r;
    }
    $nums = []; foreach ($pdo->query("SELECT id, label, display FROM wa_numbers ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $n) $nums[(int)$n['id']] = ['id' => (int)$n['id'], 'label' => trim((string)$n['label']) !== '' ? (string)$n['label'] : (string)$n['display'], 'n' => 0];
    $rows = []; $seen = []; $new = 0; $firstQ = []; foreach ($qs as $r) $firstQ[(string)$r['phone']] = (int)$r['again'];   // newest first, so what stays is the FIRST query of the day: new that day = new
    foreach ($qs as $r) {
        $ph = (string)$r['phone']; if (isset($seen[$ph])) continue; $seen[$ph] = 1;   // one line per customer (the newest query of the day)
        $f = $first[$ph] ?? null; $nid = $f ? (int)$f['number_id'] : 0; if (isset($nums[$nid])) $nums[$nid]['n']++;   // the chips count every number, whatever number is picked
        if ($numId > 0 && $nid !== $numId) continue;
        $txt = $f ? trim((string)$f['body']) : ''; if ($f && $txt === '' && (string)$f['mtype'] !== 'text') $txt = '[' . (string)$f['mtype'] . ']';
        $isNew = ($firstQ[$ph] ?? 1) === 0; if ($isNew) $new++;
        $rows[] = ['phone' => $ph, 'name' => (string)$r['name'], 'at' => (string)($f['ts'] ?? $r['opened_at']), 'number' => isset($nums[$nid]) ? $nums[$nid]['label'] : '', 'new' => $isNew,
            'ad' => mb_substr((string)((string)$r['ad_head'] !== '' ? $r['ad_head'] : $r['ref_text']), 0, 90), 'ad_id' => (string)$r['ad_id'], 'first' => mb_substr($txt, 0, 300)];
    }
    return ['date' => $day, 'number' => $numId, 'rows' => $rows, 'total' => count($rows), 'new' => $new, 'all' => count($seen), 'numbers' => array_values($nums), 'more' => $more];
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

/** One day, both sides: the HRMS ad leads (also per ad) and what the tracking service saw (clicks, matched orders, purchases sent to Meta). */
function wag_adcheck(PDO $pdo, string $day, ?array $svcTest = null): array
{
    $h = wag_adleads($pdo, $day, 0); $by = [];
    foreach ($h['rows'] as $r) { $k = $r['ad_id'] !== '' ? $r['ad_id'] : ''; if (!isset($by[$k])) $by[$k] = ['ad_id' => $k, 'title' => $r['ad'], 'n' => 0]; $by[$k]['n']++; }
    [$svc, $err] = $svcTest ?? wag_ctwa_get('/diag', 'date', $h['date']);
    return ['date' => $h['date'], 'hrms' => ['total' => $h['total'], 'new' => $h['new'], 'by_ad' => array_values($by), 'more' => $h['more']], 'svc' => $svc, 'svc_error' => $err, 'now' => date('Y-m-d H:i:s')];
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
