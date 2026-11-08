<?php
/**
 * cust_bday.php — 🎂 one birthday per phone number (day + month, never the year), collected when an ORDER is made: the manual-order customer link,
 * the order-allocation screen, and later your own PHP website. The store keeps its own copy on each bill (st_bill_people.bday); the birthday engine
 * (store_lib.php st_birthdays / st_bday_run) reads both, so every birthday gets the same gift code, the same WhatsApp message and the same list.
 * Loaded by wa_more.php, wmo.php and store_lib.php (only functions here). PHP 7.4 safe.
 */
if (!function_exists('cbd_set')) {

function cbd_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cust_bday (phone VARCHAR(12) NOT NULL PRIMARY KEY, md CHAR(5) NOT NULL, name VARCHAR(120) NOT NULL DEFAULT '', src VARCHAR(16) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, KEY k_md (md)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->exec("ALTER TABLE cust_bday ADD COLUMN emp INT NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* already there */ }
        $ok = true;
    } catch (Throwable $e) { error_log('cbd_tables: ' . $e->getMessage()); $ok = false; }
    return $ok;
}
/** day + month → "MM-DD" (29 Feb is allowed), or null when it is not a real date. */
function cbd_md($day, $month): ?string
{
    $d = (int)$day; $m = (int)$month; if ($m < 1 || $m > 12 || $d < 1 || $d > 31 || !checkdate($m, $d, 2000)) return null;
    return sprintf('%02d-%02d', $m, $d);
}
/** "14/12", "14-12", "14.12" → "12-14" (MM-DD) or null. */
function cbd_parse(string $s): ?string
{
    return preg_match('/^\s*(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*$/', $s, $m) ? cbd_md($m[1], $m[2]) : null;
}
/** Saves (or corrects) the birthday of a phone number. The newest answer wins. Returns true when saved. */
function cbd_set(PDO $pdo, string $phone, string $name, ?string $md, string $src, int $emp = 0): bool
{
    $ph = substr(preg_replace('/\D+/', '', $phone), -10); if (strlen($ph) !== 10 || $md === null || !preg_match('/^\d\d-\d\d$/', $md) || !cbd_tables($pdo)) return false;
    $now = date('Y-m-d H:i:s');
    try { $pdo->prepare("INSERT INTO cust_bday (phone, md, name, src, created_at, updated_at, emp) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE md = VALUES(md), name = IF(VALUES(name) <> '', VALUES(name), name), src = VALUES(src), updated_at = VALUES(updated_at), emp = VALUES(emp)")->execute([$ph, $md, mb_substr($name, 0, 120), mb_substr($src, 0, 16), $now, $now, $emp]); return true; }
    catch (Throwable $e) { error_log('cbd_set: ' . $e->getMessage()); return false; }
}
/** Rows for the birthday engine: [phone, name, bday (MM-DD)] of every birthday collected at ordering. */
function cbd_all(PDO $pdo): array
{
    if (!cbd_tables($pdo)) return [];
    try { return $pdo->query("SELECT phone, name, md AS bday, updated_at AS last FROM cust_bday")->fetchAll(); } catch (Throwable $e) { return []; }
}

/** Every minute (wa_ord_autosend): a birthday code that went through the automatic messages is marked sent / failed on the store's list. */
function wa_bday_sync(PDO $pdo): void
{
    try { $pdo->exec("UPDATE st_bday_vouchers v JOIN wa_ord_sent s ON s.parcel_id = -v.voucher_id AND s.status = 'birthday' SET v.wa_status = IF(s.state = 'sent', 'sent', IF(s.state IN ('failed','skipped'), 'failed', v.wa_status)), v.wa_note = IF(s.state = 'sent', 'sent by the automatic message', LEFT(s.err, 200)), v.wa_at = COALESCE(s.sent_at, v.wa_at) WHERE v.wa_status = 'queued'"); }
    catch (Throwable $e) { /* the store tables are not on this install */ }
}

/** Birthdays received between two moments (Y-m-d H:i:s): the customer told us by WhatsApp, or the team saved it (button, order screen). */
function cbd_count(PDO $pdo, string $from, string $to): array
{
    $o = ['total' => 0, 'by_customer' => 0, 'by_team' => 0];
    try { if (!cbd_tables($pdo)) return $o; $q = $pdo->prepare("SELECT COUNT(*) n, SUM(src = 'whatsapp') c FROM cust_bday WHERE updated_at >= ? AND updated_at <= ?"); $q->execute([$from, $to]); $r = $q->fetch(); $o['total'] = (int)$r['n']; $o['by_customer'] = (int)$r['c']; $o['by_team'] = $o['total'] - $o['by_customer']; } catch (Throwable $e) { }
    return $o;
}
/** The saved birthday (MM-DD) of a phone number, or ''. */
function cbd_get(PDO $pdo, string $phone): string
{
    $ph = substr(preg_replace('/\D+/', '', $phone), -10); if (strlen($ph) !== 10) return '';
    try { if (!cbd_tables($pdo)) return ''; $q = $pdo->prepare("SELECT md FROM cust_bday WHERE phone = ?"); $q->execute([$ph]); return (string)$q->fetchColumn(); } catch (Throwable $e) { return ''; }
}
/** Has this phone number a birthday saved (told at ordering, or on a store bill since the feature started)? */
function cbd_has(PDO $pdo, string $phone): bool
{
    $ph = substr(preg_replace('/\D+/', '', $phone), -10); if (strlen($ph) !== 10) return false;
    try { if (cbd_tables($pdo)) { $q = $pdo->prepare("SELECT 1 FROM cust_bday WHERE phone = ?"); $q->execute([$ph]); if ($q->fetchColumn()) return true; } } catch (Throwable $e) { }
    try { if (function_exists('st_bday_start')) { $q = $pdo->prepare("SELECT 1 FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id AND b.status = 'ok' WHERE p.phone = ? AND p.bday <> '' AND p.declined = 0 AND b.created_at >= ? LIMIT 1"); $q->execute([$ph, st_bday_start($pdo)]); if ($q->fetchColumn()) return true; } } catch (Throwable $e) { }
    return false;
}
/** A customer's own words → "MM-DD": "14/12", "14-12", "14.12.1990", "14 dec", "14th December", "dec 14". The year is ignored. null = not a birthday. */
function cbd_parse_free(string $t): ?string
{
    $t = mb_strtolower(trim($t)); if ($t === '' || mb_strlen($t) > 40) return null;
    $t = preg_replace('/(\d)(st|nd|rd|th)\b/u', '$1', $t);
    $mon = ['jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4, 'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7, 'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9, 'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12];
    if (preg_match('/(?<!\d)(\d{1,2})\s*[\/\-.]\s*(\d{1,2})(?:\s*[\/\-.]\s*\d{2,4})?(?!\d)/u', $t, $m)) { $d = (int)$m[1]; $mo = (int)$m[2]; if ($mo > 12 && $d <= 12) { $x = $d; $d = $mo; $mo = $x; } return cbd_md($d, $mo); }
    if (preg_match('/(?<!\d)(\d{1,2})\s*(?:of\s+)?([a-z]{3,9})\b/u', $t, $m) && isset($mon[$m[2]])) return cbd_md($m[1], $mon[$m[2]]);
    if (preg_match('/\b([a-z]{3,9})\s+(\d{1,2})(?!\d)/u', $t, $m) && isset($mon[$m[1]])) return cbd_md($m[2], $mon[$m[1]]);
    return null;
}
/** A customer message: the answer to our birthday question (sent in the last 7 days)? Saves it and thanks them. Returns true when it was one. */
function wa_bday_hook(PDO $pdo, string $key, string $text): bool
{
    $md = cbd_parse_free($text); if ($md === null) return false;
    try {
        if (!function_exists('wo_tables') || !wo_tables($pdo) || !cbd_tables($pdo)) return false;
        $q = $pdo->prepare("SELECT 1 FROM wa_ord_sent WHERE phone = ? AND status = 'bday_ask' AND state = 'sent' AND sent_at >= ? LIMIT 1"); $q->execute([$key, wo_dt(wo_now() - 7 * 86400)]); if (!$q->fetchColumn()) return false;
        $c = function_exists('wa_contact') ? wa_contact($pdo, $key) : null; if (!$c || (int)($c['optout'] ?? 0) === 1) return false;
        if (!cbd_set($pdo, $key, (string)($c['name'] ?? ''), $md, 'whatsapp')) return false;
        $when = DateTime::createFromFormat('Y-m-d', '2000-' . $md); $nm = function_exists('wo_first_name') ? wo_first_name((string)($c['name'] ?? '')) : '';
        if (function_exists('wa_cxself_say')) wa_cxself_say($pdo, $c, 'Thank you' . ($nm !== '' ? ' ' . $nm : '') . '! 🎂 We have saved your birthday (' . ($when ? $when->format('j F') : $md) . '). We will send you a FREE 50 ml perfume gift on the day 💖');
        if (function_exists('wa_log')) wa_log($pdo, 'orders', true, 'birthday ' . $md . ' saved from the customer\'s reply');
        return true;
    } catch (Throwable $e) { error_log('wa_bday_hook: ' . $e->getMessage()); return false; }
}
/** True for 3 minutes after a customer's birthday reply was saved (the bot does not answer the same message). */
function wa_bday_recent(PDO $pdo, string $key): bool
{
    try { if (!cbd_tables($pdo)) return false; $q = $pdo->prepare("SELECT 1 FROM cust_bday WHERE phone = ? AND src = 'whatsapp' AND updated_at >= ? LIMIT 1"); $q->execute([substr($key, -10), date('Y-m-d H:i:s', time() - 180)]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return false; }
}
/** Queues ONE birthday question per customer, 1 hour after an order (never before the switch was turned on; never to someone who has a birthday, an open complaint, or was asked before). Called by wa_ord_autosend. */
function wa_bdayask_queue(PDO $pdo, int $nowTs, array $cfg, string $sk = ''): array
{
    $res = ['queued' => 0];
    if (empty($cfg['statuses']['bday_ask']) || !cbd_tables($pdo)) return $res;
    $since = (int)wo_get($pdo, 'wa_bdayask_since', '0'); if ($since <= 0) { $since = $nowTs; wo_put($pdo, 'wa_bdayask_since', (string)$since); }
    if (wo_tpl_for($pdo, 'bday_ask') === null) return $res;   // the template is not approved yet
    $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE created_at >= ? AND created_at <= ? AND phone IS NOT NULL AND phone <> ''$sk ORDER BY id DESC LIMIT 200"); $bm = wo_timing($pdo, 'bday_ask'); $q->execute([wo_dt(max($since, $nowTs - 5 * 86400)), wo_dt($nowTs - $bm * 60)]);   // the delay (default 60 minutes) is set in Automation → Birthdays
    foreach ($q->fetchAll() as $p) {
        if (in_array(wo_class($p, $nowTs), ['cancelled', 'rto', 'lost', 'damaged'], true)) continue;
        if (function_exists('wo_is_cx_parcel') && wo_is_cx_parcel($pdo, $p)) continue;
        $key = wo_key($p['phone']); if ($key === '' || (function_exists('wa_valid_phone') && !wa_valid_phone($key))) continue;
        if (cbd_has($pdo, $key)) continue;
        $a = $pdo->prepare("SELECT 1 FROM wa_ord_sent WHERE phone = ? AND status = 'bday_ask' LIMIT 1"); $a->execute([$key]); if ($a->fetchColumn()) continue;   // asked once, ever
        if (function_exists('wr_blocked_open_case') && wr_blocked_open_case($pdo, $key, (string)$p['order_name'])) continue;
        $ins = $pdo->prepare("INSERT IGNORE INTO wa_ord_sent (parcel_id, phone, order_name, status, tpl_name, state, created_at, due_at) VALUES (?,?,?,?,?, 'queued', ?, ?)");
        $ins->execute([(int)$p['id'], $key, (string)$p['order_name'], 'bday_ask', wo_send_defs()['bday_ask'][0], wo_dt($nowTs), wo_dt(wo_next_open($nowTs))]); if ($ins->rowCount() > 0) $res['queued']++;
    }
    return $res;
}

}   // function_exists guard
