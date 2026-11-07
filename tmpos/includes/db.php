<?php
// ── DB CONFIG ───────────────────────────────────────────────
// The POS now runs inside the HRMS database. It reads the HRMS login from empmanagment/config.php (same
// DB_HOST / DB_NAME / DB_USER / DB_PASS the HRMS uses), so there is nothing to set up and no password in git.
// All POS tables are stored there as pos_<name> (see includes/pos_pdo.php).
$__hrmsCfg = __DIR__ . '/../../empmanagment/config.php';
if (is_file($__hrmsCfg)) require_once $__hrmsCfg;
unset($__hrmsCfg);
if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS') || DB_NAME === '' || DB_NAME === 'your_db_name') {
    http_response_code(503);
    die('POS setup needed: empmanagment/config.php must define DB_HOST, DB_NAME, DB_USER and DB_PASS (the HRMS database).');
}
require_once __DIR__ . '/pos_pdo.php';
// ────────────────────────────────────────────────────────────

date_default_timezone_set('Asia/Kolkata');

// Connect with patience: the shared server sometimes refuses a connection for a second or two when it is overloaded
// (many people and pages at the same moment). The HRMS itself (api.php) tries 5 times over about 4 seconds; the POS now does the same,
// so the counter and the login page recover instead of showing "Server is busy". A little random wait keeps requests from retrying in step.
$pdo = null; $dbError = '';
$dbWait = [150000, 300000, 600000, 1200000, 2000000];   // short first retries, longer later (about 4 s in the worst case)
for ($try = 1; $try <= 6 && $pdo === null; $try++) {
    try {
        $pdo = new PosPDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 3,
                PDO::ATTR_PERSISTENT => defined('DB_PERSISTENT') && DB_PERSISTENT,   // same optional setting as the HRMS api.php (empmanagment/config.php)
            ]
        );
        if (defined('DB_PERSISTENT') && DB_PERSISTENT) { try { if ($pdo->inTransaction()) $pdo->rollBack(); $pdo->query('SELECT RELEASE_ALL_LOCKS()')->fetchAll(); } catch (Throwable $e) { } }
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
        if ($try < 6) usleep($dbWait[$try - 1] + mt_rand(0, 100000));
    }
}
if ($pdo === null) {
    @error_log('[TMPOS] database connection failed: ' . preg_replace('/password=\S+/i', 'password=***', $dbError));
    http_response_code(503);
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $wantsJson = (strpos($script, '/api/') !== false) || (substr($script, -8) === '_api.php')
        || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    if ($wantsJson) {
        header('Content-Type: application/json');
        die(json_encode(['ok' => false, 'error' => 'Server is busy — please try again in a few seconds.']));
    }
    header('Retry-After: 5');
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
      . '<meta http-equiv="refresh" content="5"><title>Please wait…</title></head>'
      . '<body style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#eef0f3;color:#14161a;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">'
      . '<div style="background:#fff;border-radius:20px;padding:34px 38px;text-align:center;max-width:340px;box-shadow:0 8px 24px -12px rgba(20,22,26,.18)">'
      . '<div style="font-size:34px">⏳</div><h2 style="margin:10px 0 6px;font-size:20px">Server is busy</h2>'
      . '<p style="color:#6b7280;font-size:14px;line-height:1.5">Reconnecting automatically… this page will reload in a few seconds.</p></div></body></html>');
}

// Make MySQL use IST so CURRENT_TIMESTAMP / created_at match PHP — never fatal
try { $pdo->exec("SET time_zone = '+05:30'"); } catch (Throwable $e) { /* ignore if not permitted */ }

// Get a setting value from settings table
function get_setting(string $key): string {
    global $pdo;
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $st = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
    $st->execute([$key]);
    $val = $st->fetchColumn();
    return $cache[$key] = ($val === false ? '' : $val);
}

// Update a setting
function set_setting(string $key, string $value): void {
    global $pdo;
    $st = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $st->execute([$key, $value]);
}

// JSON response helper for API endpoints
function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}