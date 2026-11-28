<?php
/**
 * Store → Display: the live card for the screen in the store (orders, bottles, WhatsApp community, Google reviews and rating).
 *  - the screen page (store_display_tv.html) has NO login: it reads st_disp.feed with a secret key in its link. The feed gives only totals, never a name.
 *  - orders and bottles come from the bills (the same count as Store → Since the start); the community number is the number the owner typed + every customer who joined from a bill after that;
 *    Google reviews and rating are typed by the owner.
 * Settings: st_cs_settings key 'disp'.
 */

function sd_cfg(PDO $pdo, bool $make = true): array
{
    $d = ['key' => '', 'period' => '2m', 'community_base' => 0, 'community_from' => '', 'google_reviews' => 0, 'rating' => '', 'cta' => 'Order it now', 'url' => 'tmperfumehouse.com', 'sound' => 1, 'title' => 'TM Perfume House', 'place' => 'Vesu, Surat', 'theme' => 'brand'];
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS st_cs_settings (skey VARCHAR(30) NOT NULL PRIMARY KEY, svalue MEDIUMTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); $v = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = 'disp'")->fetchColumn(); $j = $v ? json_decode($v, true) : null; if (is_array($j)) foreach ($d as $k => $_) if (array_key_exists($k, $j)) $d[$k] = $j[$k]; } catch (Throwable $e) { }
    if ($make && !preg_match('/^[a-f0-9]{24}$/', (string)$d['key'])) { $d['key'] = bin2hex(random_bytes(12)); sd_cfg_put($pdo, $d); }
    return $d;
}
function sd_cfg_put(PDO $pdo, array $c): void { $pdo->prepare("INSERT INTO st_cs_settings (skey, svalue) VALUES ('disp', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([json_encode($c, JSON_UNESCAPED_UNICODE)]); }
function sd_period_from(string $p): ?string
{
    $t = strtotime(function_exists('st_day') ? st_day() : date('Y-m-d'));
    if ($p === 'month') return date('Y-m-01', $t);
    if ($p === 'all') return null;
    return date('Y-m-01', strtotime('-1 month', strtotime(date('Y-m-01', $t))));      // this month and the month before
}
function sd_label(string $p): string { return $p === 'month' ? 'This month' : ($p === 'all' ? 'Since the start' : 'Last 2 months'); }

/** the numbers of the screen; kept for 5 seconds so a screen asking again and again does not make the server count again and again */
function sd_numbers(PDO $pdo, array $c): array
{
    $cache = sys_get_temp_dir() . '/hrms_disp_' . substr(md5(__DIR__ . $c['key'] . $c['period'] . '|' . sd_lastbill($pdo)), 0, 10);      // a new bill = new numbers at once (not a 5 s old copy)
    if (is_file($cache) && time() - (int)filemtime($cache) < 5) { $j = json_decode((string)@file_get_contents($cache), true); if (is_array($j)) return $j; }
    require_once __DIR__ . '/store_since.php';
    $r = ss_report($pdo, sd_period_from((string)$c['period']), null);
    $comm = (int)$c['community_base'];
    try { if ($c['community_from'] !== '') { $q = $pdo->prepare("SELECT COUNT(DISTINCT person_id) FROM st_bill_events WHERE kind = 'community' AND at >= ?"); $q->execute([(string)$c['community_from']]); $comm += (int)$q->fetchColumn(); } } catch (Throwable $e) { }
    $out = ['orders' => (int)$r['total']['orders'], 'bottles' => (int)$r['total']['bottles'], 'community' => $comm];
    @file_put_contents($cache, json_encode($out));
    return $out;
}

/** PUBLIC (no login): the screen asks with its key */
/** ⚡ The newest bill number: one tiny query. The TV asks this every 2 s and loads the full card the moment it changes. */
function sd_lastbill(PDO $pdo): int { try { return (int)$pdo->query("SELECT MAX(bill_id) FROM st_bills WHERE status = 'ok'")->fetchColumn(); } catch (Throwable $e) { return 0; } }
function sd_key_ok(array $c, string $k): bool { return preg_match('/^[a-f0-9]{24}$/', (string)$c['key']) && (hash_equals((string)$c['key'], $k) || (strlen($k) === 8 && hash_equals(substr((string)$c['key'], 0, 8), $k))); }
/** PUBLIC (secret key in the TV link): {b: newest bill id}. Nothing else. */
function sd_ping(PDO $pdo): void
{
    $c = sd_cfg($pdo, false); if (!sd_key_ok($c, (string)($_GET['k'] ?? ''))) json_response(['error' => 'This screen link is not valid any more.'], 403);
    header('Cache-Control: no-store'); json_response(['b' => sd_lastbill($pdo)]);
}
function sd_feed(PDO $pdo): void
{
    $c = sd_cfg($pdo, false); $k = (string)($_GET['k'] ?? '');
    if (!preg_match('/^[a-f0-9]{24}$/', (string)$c['key']) || !(hash_equals((string)$c['key'], $k) || (strlen($k) === 8 && hash_equals(substr((string)$c['key'], 0, 8), $k)))) json_response(['error' => 'This screen link is not valid any more.'], 403);
    $n = sd_numbers($pdo, $c);
    json_response(['thanks' => sd_thanks($pdo), 'orders' => $n['orders'], 'bottles' => $n['bottles'], 'community' => $n['community'], 'reviews' => (int)$c['google_reviews'], 'rating' => (string)$c['rating'], 'period' => sd_label((string)$c['period']),
        'cta' => (string)$c['cta'], 'url' => (string)$c['url'], 'sound' => !empty($c['sound']) ? 1 : 0, 'title' => (string)$c['title'], 'place' => (string)$c['place'], 'theme' => sd_theme((string)($c['theme'] ?? ''))]);
}

/** 🙏 The newest bill of the last 3 minutes, for the "Thank you, Riya!" on the TV: ONLY the first name (the whole shop sees the TV — never the surname or the phone),
 *  and the visit number for a returning customer. No name, or the customer said no to giving details = "our customer". [] when there is no new bill. */
function sd_thanks(PDO $pdo): array
{
    try {
        $q = $pdo->prepare("SELECT bill_id, customer_name FROM st_bills WHERE status = 'ok' AND created_at >= ? ORDER BY bill_id DESC LIMIT 1");      // the bill's time is written by PHP: compare with PHP's clock (not MySQL's, which may be UTC)
        $q->execute([date('Y-m-d H:i:s', time() - 600)]); $b = $q->fetch();
        if (!$b) return [];
        $name = (string)$b['customer_name']; $visit = 1;
        try { $p = $pdo->prepare("SELECT name, declined, visit_no FROM st_bill_people WHERE bill_id = ? ORDER BY person_no LIMIT 1"); $p->execute([(int)$b['bill_id']]); $r = $p->fetch();
            if ($r) { $name = !empty($r['declined']) ? '' : ((string)$r['name'] !== '' ? (string)$r['name'] : $name); $visit = max(1, (int)$r['visit_no']); } } catch (Throwable $e) { }
        $first = trim((string)preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{M}\s.\']/u', ' ', $name)))[0]);
        if (mb_strlen($first) < 2 || mb_strlen($first) > 14 || preg_match('/^(mr|mrs|ms|dr|sir|madam|customer|walk|na|n\/a)\.?$/i', $first)) $first = '';
        if ($first !== '') $first = mb_strtoupper(mb_substr($first, 0, 1)) . mb_strtolower(mb_substr($first, 1));
        return ['id' => (int)$b['bill_id'], 'name' => $first, 'visit' => $visit];
    } catch (Throwable $e) { return []; }
}

/** the looks of the TV card (store_display_tv.html has the colours and the moving effects of each) */
function sd_theme(string $t): string { return in_array($t, ['brand', 'classic', 'gold', 'sunset', 'rose', 'emerald'], true) ? $t : 'brand'; }
function sd_manager(): array
{
    $auth = require_auth();
    if (!in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) && !user_can($auth, 'rs_manage')) json_response(['error' => 'Only the store manager can change the display.'], 403);
    return $auth;
}
/** pairing: the TV shows a 6-digit code, the owner types it in Store -> Display, and the TV then picks up its secret key by itself (no typing of the long link) */
function sd_pair_load(PDO $pdo): array
{
    try { $v = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = 'disp_pair'")->fetchColumn(); $j = $v ? json_decode($v, true) : []; } catch (Throwable $e) { $j = []; }
    $j = is_array($j) ? $j : []; foreach ($j as $c => $x) if (!is_array($x) || (int)($x['at'] ?? 0) < time() - 1800) unset($j[$c]);
    return $j;
}
function sd_pair_put(PDO $pdo, array $j): void { $pdo->prepare("INSERT INTO st_cs_settings (skey, svalue) VALUES ('disp_pair', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([json_encode($j)]); }
/** PUBLIC: the TV asks "has anyone typed my code yet?" Gives the key once, only for a code the owner has typed. */
function sd_pair_poll(PDO $pdo): void
{
    $c = (string)($_GET['c'] ?? ''); if (!preg_match('/^\d{6}$/', $c)) json_response(['wait' => 1]);
    $j = sd_pair_load($pdo); if (!isset($j[$c])) json_response(['wait' => 1]);
    $key = (string)$j[$c]['key']; unset($j[$c]); sd_pair_put($pdo, $j); json_response(['key' => $key]);
}
function sd_handle(PDO $pdo, string $action): void
{
    sd_manager(); $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $c = sd_cfg($pdo);
    if ($action === 'st_disp.get') { $c['numbers'] = sd_numbers($pdo, $c); json_response($c); }
    if (!$post) json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input();
    if ($action === 'st_disp.newkey') { $c['key'] = bin2hex(random_bytes(12)); sd_cfg_put($pdo, $c); json_response(['ok' => true, 'key' => $c['key']]); }
    if ($action === 'st_disp.link') {
        $code = preg_replace('/\D/', '', (string)($in['code'] ?? '')); if (strlen($code) !== 6) json_response(['error' => 'Type the 6 numbers shown on the TV.'], 400);
        $j = sd_pair_load($pdo); $j[$code] = ['key' => $c['key'], 'at' => time()]; sd_pair_put($pdo, $j); json_response(['ok' => true]);
    }
    if ($action === 'st_disp.save') {
        $p = (string)($in['period'] ?? $c['period']); if (!in_array($p, ['2m', 'month', 'all'], true)) $p = '2m';
        $base = max(0, min(10000000, (int)($in['community_base'] ?? $c['community_base'])));
        if ($base !== (int)$c['community_base']) { $c['community_from'] = date('Y-m-d H:i:s'); }      // the typed number is "members now": joins from a bill count from this moment
        $c['community_base'] = $base; $c['period'] = $p;
        $c['google_reviews'] = max(0, min(10000000, (int)($in['google_reviews'] ?? $c['google_reviews'])));
        $r = trim((string)($in['rating'] ?? $c['rating'])); $c['rating'] = ($r !== '' && is_numeric($r)) ? (string)max(0, min(5, round((float)$r, 1))) : '';
        foreach (['cta' => 40, 'url' => 60, 'title' => 40, 'place' => 40] as $k => $mx) if (isset($in[$k])) { $v = trim(mb_substr((string)$in[$k], 0, $mx)); if ($v !== '') $c[$k] = $v; }
        $c['sound'] = !empty($in['sound']) ? 1 : 0; if (isset($in['theme'])) $c['theme'] = sd_theme((string)$in['theme']);
        sd_cfg_put($pdo, $c); json_response(['ok' => true]);
    }
    json_response(['error' => 'Unknown action.'], 404);
}
