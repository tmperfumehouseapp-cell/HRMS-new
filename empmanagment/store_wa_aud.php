<?php
/**
 * Store → WhatsApp → Contacts: every Store customer (from the bills) as one audience, with ready segments for campaigns and the Lineup.
 * Nothing is typed in by hand: name, number, visits, money spent, perfumes, birthday, language and STOP all come from the bills and the Store WhatsApp.
 *
 * Segments (each customer can be in several, they change by themselves every day; the numbers are set in the "Rules" box):
 *  - relationship (RFM): new, one-time not back, repeat, loyal, VIP, at risk, sleeping
 *  - time: refill due, birthday this month
 *  - taste and price: oud / attar / floral / fresh / sweet / musk (from the perfume names), gift buyers (2+ perfumes in one bill), price band of the average bill
 *  - WhatsApp: replied, read but no reply, never read, STOP (STOP customers are never in a sending segment)
 * Tags: the same tags as in Chats (st_wa_tag).
 * Actions (st_wa.aud_*): aud_list, aud_cfg_save, aud_tag.
 */

if (!function_exists('sr_short_title') && is_file(__DIR__ . '/store_remind.php')) require_once __DIR__ . '/store_remind.php';
function swa_cfg_defaults(): array { return ['new_days' => 30, 'one_lo' => 30, 'one_hi' => 90, 'loyal' => 3, 'vip' => 25000, 'risk' => 60, 'sleep' => 120, 'refill_lo' => 25, 'refill_hi' => 40, 'low' => 500, 'high' => 1500]; }
function swa_cfg(PDO $pdo): array
{
    $d = swa_cfg_defaults();
    try { $v = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = 'swaud'")->fetchColumn(); $j = $v ? json_decode((string)$v, true) : null; if (is_array($j)) foreach ($d as $k => $x) if (isset($j[$k]) && is_numeric($j[$k])) $d[$k] = max(0, (int)$j[$k]); } catch (Throwable $e) { }
    return $d;
}

/** Segment ids, names and groups, in the order they are shown. */
function swa_segs(array $c): array
{
    return [
        ['all', 'All contacts', 'all', 'Everyone who has a number on a bill (STOP included)'],
        ['new', '🆕 New', 'rel', 'First purchase in the last ' . $c['new_days'] . ' days'],
        ['one', '1️⃣ Came once, not back', 'rel', 'One purchase, ' . $c['one_lo'] . '–' . $c['one_hi'] . ' days ago'],
        ['repeat', '🔁 Repeat', 'rel', '2 purchases'],
        ['loyal', '💚 Loyal', 'rel', $c['loyal'] . '+ purchases'],
        ['vip', '👑 VIP', 'rel', 'Spent ₹' . number_format($c['vip']) . '+ in total'],
        ['risk', '⚠️ At risk', 'rel', '2+ purchases, nothing for ' . $c['risk'] . '–' . $c['sleep'] . ' days'],
        ['sleep', '😴 Sleeping', 'rel', 'Nothing for ' . $c['sleep'] . '+ days'],
        ['refill', '⏳ Refill due', 'time', 'Last purchase ' . $c['refill_lo'] . '–' . $c['refill_hi'] . ' days ago'],
        ['bday', '🎂 Birthday this month', 'time', 'Birthday saved on a bill, in this month'],
        ['oud', '🪵 Oud lovers', 'taste', 'Bought an oud perfume'], ['attar', '🌿 Attar', 'taste', 'Bought an attar'], ['floral', '🌸 Floral', 'taste', 'Rose, jasmine, floral …'],
        ['fresh', '🌊 Fresh', 'taste', 'Aqua, citrus, fresh, blue …'], ['sweet', '🍬 Sweet', 'taste', 'Vanilla, amber, sweet …'], ['musk', '🤍 Musk', 'taste', 'White musk, musk …'],
        ['gift', '🎁 Gift buyers', 'taste', '2+ perfumes in one bill'],
        ['p_low', '💰 Up to ₹' . $c['low'], 'price', 'Average bill up to ₹' . $c['low']], ['p_mid', '💰 ₹' . $c['low'] . '–' . $c['high'], 'price', 'Average bill ₹' . $c['low'] . '–' . $c['high']], ['p_high', '💰 ₹' . $c['high'] . '+', 'price', 'Average bill above ₹' . $c['high']],
        ['replied', '💬 Replied on WhatsApp', 'wa', 'Wrote to the Store number at least once'], ['readonly', '👀 Reads, no reply', 'wa', 'Read a message, never wrote'], ['unread', '📭 Never read', 'wa', 'Got messages, never read one'],
        ['stop', '🚫 STOP', 'wa', 'Asked not to be messaged - never sent to'],
    ];
}
function swa_taste(string $title): array
{
    $t = mb_strtolower($title); $o = [];
    foreach (['oud' => '/\boud|oudh|agar/u', 'attar' => '/attar|ittar|itr\b/u', 'floral' => '/rose|jasmin|mogra|floral|lily|lavender|gulab|flower/u', 'fresh' => '/aqua|citrus|fresh|blue|ocean|marine|lemon|mint|sport|cool/u', 'sweet' => '/vanill|amber|sweet|candy|caramel|chocolate|honey|gourmand/u', 'musk' => '/musk|kasturi/u'] as $k => $re) if (preg_match($re, $t)) $o[] = $k;
    return $o;
}

/** Every customer with a number: [phone => row]. Built from the bills (person 1 pays; everybody on a bill counts as a visit). */
function swa_build(PDO $pdo, array $c): array
{
    $P = []; $billOf = [];
    $q = $pdo->query("SELECT p.phone, p.name, p.person_no, p.bday, b.bill_id, b.biz_date, b.total, b.bottles FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id AND b.status = 'ok' WHERE p.phone <> '' AND p.declined = 0 ORDER BY b.biz_date, b.bill_id");
    foreach ($q->fetchAll() as $r) {
        $ph = substr(preg_replace('/\D/', '', (string)$r['phone']), -10); if (strlen($ph) !== 10) continue;
        $x = $P[$ph] ?? ['phone' => $ph, 'name' => '', 'first' => (string)$r['biz_date'], 'last' => '', 'visits' => 0, 'spent' => 0.0, 'paid_bills' => 0, 'bday' => '', 'bills' => [], 'gift' => false, 'perfumes' => [], 'taste' => [], 'tags' => [], 'lang' => '', 'stop' => false, 'wa' => ''];
        $nm = trim((string)$r['name']); if ($nm !== '') $x['name'] = $nm; if (trim((string)($r['bday'] ?? '')) !== '') $x['bday'] = (string)$r['bday'];
        if (!in_array((int)$r['bill_id'], $x['bills'], true)) { $x['bills'][] = (int)$r['bill_id']; $x['visits']++; $x['last'] = (string)$r['biz_date']; }
        if ((int)$r['person_no'] === 1) { $x['spent'] += (float)$r['total']; $x['paid_bills']++; if ((int)$r['bottles'] >= 2) $x['gift'] = true; $billOf[(int)$r['bill_id']] = $ph; }
        $P[$ph] = $x;
    }
    // perfumes bought (per paying customer)
    $ids = array_keys($billOf);
    foreach (array_chunk($ids, 800) as $ch) { $in = implode(',', array_map('intval', $ch)); if ($in === '') continue;
        try { foreach ($pdo->query("SELECT i.bill_id, COALESCE(pr.title, '') title FROM st_bill_items i LEFT JOIN st_bottles bt ON bt.bottle_id = i.bottle_id LEFT JOIN st_products pr ON pr.product_id = bt.product_id WHERE i.kind = 'sale' AND i.bill_id IN ($in)")->fetchAll() as $r) {
            $ph = $billOf[(int)$r['bill_id']] ?? ''; $t = trim((string)$r['title']); $full = $t; $t = function_exists('sr_short_title') ? sr_short_title($t) : $t; if ($ph === '' || $t === '') continue; $P[$ph]['perfumes'][$t] = ($P[$ph]['perfumes'][$t] ?? 0) + 1; foreach (swa_taste($full) as $k) $P[$ph]['taste'][$k] = 1; } } catch (Throwable $e) { }
    }
    // tags, language, STOP, WhatsApp behaviour
    try { foreach ($pdo->query("SELECT phone, tag FROM st_wa_tag")->fetchAll() as $r) { $ph = substr((string)$r['phone'], -10); if (isset($P[$ph])) $P[$ph]['tags'][] = (string)$r['tag']; } } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT phone, lang FROM st_wa_lang")->fetchAll() as $r) { $ph = substr((string)$r['phone'], -10); if (isset($P[$ph])) $P[$ph]['lang'] = (string)$r['lang']; } } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT phone FROM st_rm_stop")->fetchAll(PDO::FETCH_COLUMN) as $p) { $ph = substr(preg_replace('/\D/', '', (string)$p), -10); if (isset($P[$ph])) $P[$ph]['stop'] = true; } } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT phone FROM st_wa_chat WHERE optout = 1")->fetchAll(PDO::FETCH_COLUMN) as $p) { $ph = substr((string)$p, -10); if (isset($P[$ph])) $P[$ph]['stop'] = true; } } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT phone, SUM(dir = 'in') ins, SUM(dir = 'out') outs, SUM(dir = 'out' AND status = 'read') rd FROM st_wa_msg GROUP BY phone")->fetchAll() as $r) { $ph = substr((string)$r['phone'], -10); if (!isset($P[$ph])) continue; $P[$ph]['wa'] = (int)$r['ins'] > 0 ? 'replied' : ((int)$r['rd'] > 0 ? 'readonly' : ((int)$r['outs'] > 0 ? 'unread' : '')); } } catch (Throwable $e) { }
    $today = strtotime(date('Y-m-d')); $mon = date('m');
    foreach ($P as $ph => &$x) {
        $d = (int)floor(($today - strtotime($x['last'])) / 86400); $x['days'] = $d; $x['avg'] = $x['paid_bills'] > 0 ? round($x['spent'] / $x['paid_bills']) : 0; $s = [];
        $df = (int)floor(($today - strtotime($x['first'])) / 86400);
        if ($x['visits'] === 1 && $df < $c['new_days']) $s[] = 'new';
        if ($x['visits'] === 1 && $d >= $c['one_lo'] && $d <= $c['one_hi']) $s[] = 'one';
        if ($x['visits'] === 2) $s[] = 'repeat';
        if ($x['visits'] >= $c['loyal']) $s[] = 'loyal';
        if ($x['spent'] >= $c['vip']) $s[] = 'vip';
        if ($x['visits'] >= 2 && $d >= $c['risk'] && $d < $c['sleep']) $s[] = 'risk';
        if ($d >= $c['sleep']) $s[] = 'sleep';
        if ($d >= $c['refill_lo'] && $d <= $c['refill_hi']) $s[] = 'refill';
        if ($x['bday'] !== '' && substr($x['bday'], 0, 2) === $mon) $s[] = 'bday';
        foreach (array_keys($x['taste']) as $k) $s[] = $k; if ($x['gift']) $s[] = 'gift';
        if ($x['paid_bills'] > 0) $s[] = $x['avg'] <= $c['low'] ? 'p_low' : ($x['avg'] <= $c['high'] ? 'p_mid' : 'p_high');
        if ($x['wa'] !== '') $s[] = $x['wa']; if ($x['stop']) $s[] = 'stop';
        $x['segs'] = $s; arsort($x['perfumes']); $x['top'] = array_slice(array_keys($x['perfumes']), 0, 3); unset($x['bills'], $x['perfumes'], $x['taste']);
    }
    unset($x);
    return $P;
}

/** Billing month by month (last 12): customers, new customers, repeat customers, bills, money. */
function swa_months(PDO $pdo): array
{
    $first = []; $o = [];
    try {
        foreach ($pdo->query("SELECT RIGHT(p.phone, 10) ph, MIN(b.biz_date) d FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id AND b.status = 'ok' WHERE p.phone <> '' AND p.declined = 0 GROUP BY RIGHT(p.phone, 10)")->fetchAll() as $r) $first[(string)$r['ph']] = substr((string)$r['d'], 0, 7);
        $from = date('Y-m-01', strtotime('-11 months'));
        $q = $pdo->prepare("SELECT DATE_FORMAT(b.biz_date, '%Y-%m') m, RIGHT(p.phone, 10) ph, b.bill_id, b.total, p.person_no FROM st_bills b LEFT JOIN st_bill_people p ON p.bill_id = b.bill_id AND p.declined = 0 AND p.phone <> '' WHERE b.status = 'ok' AND b.biz_date >= ?"); $q->execute([$from]);
        $bills = []; $cust = [];
        foreach ($q->fetchAll() as $r) { $m = (string)$r['m']; if (!isset($bills[$m][(int)$r['bill_id']])) { $bills[$m][(int)$r['bill_id']] = (float)$r['total']; } if ((string)$r['ph'] !== '') $cust[$m][(string)$r['ph']] = 1; }
        for ($i = 11; $i >= 0; $i--) { $m = date('Y-m', strtotime(date('Y-m-01') . " -$i months")); $cs = array_keys($cust[$m] ?? []); $new = 0; foreach ($cs as $ph) if (($first[$ph] ?? '') === $m) $new++;
            $o[] = ['month' => $m, 'label' => date('M y', strtotime($m . '-01')), 'bills' => count($bills[$m] ?? []), 'money' => round(array_sum($bills[$m] ?? [])), 'customers' => count($cs), 'new' => $new, 'repeat' => count($cs) - $new]; }
    } catch (Throwable $e) { error_log('swa_months: ' . $e->getMessage()); }
    return $o;
}

function swa_handle(PDO $pdo, string $action, int $me, bool $post): void
{
    $c = swa_cfg($pdo);
    if ($action === 'st_wa.aud_list') {
        $P = swa_build($pdo, $c); $segs = swa_segs($c); $cnt = ['all' => count($P)]; foreach ($P as $x) foreach ($x['segs'] as $s) $cnt[$s] = ($cnt[$s] ?? 0) + 1;
        $seg = (string)($_GET['seg'] ?? 'all'); $tag = trim((string)($_GET['tag'] ?? '')); $qq = mb_strtolower(trim((string)($_GET['q'] ?? ''))); $sort = (string)($_GET['sort'] ?? 'last');
        $rows = array_values(array_filter($P, fn($x) => ($seg === 'all' || in_array($seg, $x['segs'], true)) && ($tag === '' || in_array($tag, $x['tags'], true)) && ($qq === '' || strpos(mb_strtolower($x['name'] . ' ' . $x['phone'] . ' ' . implode(' ', $x['top']) . ' ' . implode(' ', $x['tags'])), $qq) !== false)));
        usort($rows, fn($a, $b) => $sort === 'spent' ? $b['spent'] <=> $a['spent'] : ($sort === 'visits' ? $b['visits'] <=> $a['visits'] : ($sort === 'name' ? strcmp($a['name'], $b['name']) : strcmp($b['last'], $a['last']))));
        $sendable = count(array_filter($rows, fn($x) => !$x['stop']));
        $tags = []; foreach ($P as $x) foreach ($x['tags'] as $t) $tags[$t] = ($tags[$t] ?? 0) + 1; ksort($tags);
        $pg = max(0, (int)($_GET['page'] ?? 0)); $ps = min(500, max(10, (int)($_GET['ps'] ?? 50)));
        if (!empty($_GET['phones'])) json_response(['phones' => array_values(array_map(fn($x) => $x['phone'], array_filter($rows, fn($x) => !$x['stop'])))]);      // for a campaign: every number that can be sent to
        $tot = ['contacts' => count($P), 'spent' => round(array_sum(array_column($P, 'spent'))), 'stop' => $cnt['stop'] ?? 0, 'repeat_share' => count($P) ? round(100 * count(array_filter($P, fn($x) => $x['visits'] >= 2)) / count($P)) : 0];
        json_response(['segs' => array_map(fn($s) => ['id' => $s[0], 'name' => $s[1], 'group' => $s[2], 'rule' => $s[3], 'n' => (int)($cnt[$s[0]] ?? 0)], $segs), 'tags' => $tags, 'cfg' => $c, 'totals' => $tot, 'months' => swa_months($pdo),
            'total' => count($rows), 'sendable' => $sendable, 'rows' => array_slice($rows, $pg * $ps, $ps)]);
    }
    if (!$post) json_response(['error' => 'Method not allowed.'], 405); $in = json_input();
    if ($action === 'st_wa.aud_cfg_save') {
        $d = swa_cfg_defaults(); $o = []; foreach ($d as $k => $x) $o[$k] = max(0, min(1000000, (int)($in[$k] ?? $c[$k])));
        $pdo->prepare("INSERT INTO st_cs_settings (skey, svalue) VALUES ('swaud', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([json_encode($o)]); json_response(['ok' => true, 'cfg' => $o]);
    }
    if ($action === 'st_wa.aud_tag') {      // add / remove one tag on many numbers at once
        $t = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['tag'] ?? ''))), 0, 30); if ($t === '') json_response(['error' => 'Write the tag.'], 422);
        $n = 0; foreach (array_slice((array)($in['phones'] ?? []), 0, 20000) as $p) { $p = substr(preg_replace('/\D/', '', (string)$p), -10); if (strlen($p) !== 10) continue;
            if (!empty($in['on'])) $pdo->prepare("INSERT IGNORE INTO st_wa_tag (phone, tag) VALUES (?, ?)")->execute([$p, $t]); else $pdo->prepare("DELETE FROM st_wa_tag WHERE phone = ? AND tag = ?")->execute([$p, $t]); $n++; }
        json_response(['ok' => true, 'n' => $n]);
    }
    json_response(['error' => 'Unknown action.'], 404);
}
