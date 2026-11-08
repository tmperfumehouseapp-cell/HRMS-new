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
function cbd_set(PDO $pdo, string $phone, string $name, ?string $md, string $src): bool
{
    $ph = substr(preg_replace('/\D+/', '', $phone), -10); if (strlen($ph) !== 10 || $md === null || !preg_match('/^\d\d-\d\d$/', $md) || !cbd_tables($pdo)) return false;
    $now = date('Y-m-d H:i:s');
    try { $pdo->prepare("INSERT INTO cust_bday (phone, md, name, src, created_at, updated_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE md = VALUES(md), name = IF(VALUES(name) <> '', VALUES(name), name), src = VALUES(src), updated_at = VALUES(updated_at)")->execute([$ph, $md, mb_substr($name, 0, 120), mb_substr($src, 0, 16), $now, $now]); return true; }
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

}   // function_exists guard
