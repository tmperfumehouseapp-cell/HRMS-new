<?php
// sso.php — signs an HRMS admin into the POS automatically (no second login).
// The HRMS Store → POS page POSTs the HRMS login token here. The token is checked with the same APP_SECRET the HRMS
// uses (signature + expiry), and the person's role is re-read from the HRMS users table: only an HRMS Admin gets in.
// The POS session then runs as the matching POS user (same username), else the first active POS admin.
require __DIR__ . '/includes/db.php';     // also loads empmanagment/config.php (DB_*, APP_SECRET)
require __DIR__ . '/includes/auth.php';

function sso_fail(int $code, string $msg): void {
    http_response_code($code);
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:Arial,sans-serif;padding:28px;color:#14161a"><h3>POS</h3><p>' . htmlspecialchars($msg) . '</p><p>Open it from the HRMS: <b>Store → POS</b>.</p></body>';
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sso_fail(405, 'Open the POS from the HRMS.');
if (!defined('APP_SECRET') || APP_SECRET === '') sso_fail(503, 'HRMS secret is not configured (empmanagment/config.php).');

// 1) HRMS token: same format as empmanagment/api.php verify_token()
$tok = (string)($_POST['t'] ?? '');
if (strpos($tok, '.') === false) sso_fail(403, 'Your HRMS login was not received. Please log in to the HRMS again.');
[$b64, $sig] = explode('.', $tok, 2);
if (!hash_equals(hash_hmac('sha256', $b64, APP_SECRET), $sig)) sso_fail(403, 'Your HRMS login is not valid. Please log in to the HRMS again.');
$payload = json_decode((string)base64_decode(strtr($b64, '-_', '+/')), true);
if (!is_array($payload) || !isset($payload['exp']) || $payload['exp'] < time()) sso_fail(403, 'Your HRMS login has expired. Please log in to the HRMS again.');

// 2) role comes from the HRMS users table (not from the token). What it may open comes from the HRMS Roles page:
//    Admin                              → the whole POS (as before)
//    a role ticked "Dispatch: store restock" (dispatch_restock) → Restock only: create/send orders, record dispatch
//    a role ticked "Store: POS" (store_pos)                      → the store side of the POS (billing, inventory, returns, cash, expenses)
$uid = (int)($payload['user_id'] ?? 0);
$hrmsUser = null; $perms = [];
if ($uid > 0) {
    try {
        $raw = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $st = $raw->prepare('SELECT username, role FROM users WHERE user_id = ? LIMIT 1'); $st->execute([$uid]);
        $hrmsUser = $st->fetch() ?: null;
    } catch (Throwable $e) { sso_fail(503, 'Cannot check your HRMS account right now. Try again in a moment.'); }
    if ($hrmsUser && strcasecmp(trim((string)$hrmsUser['role']), 'Admin') !== 0) {   // Admin never needs this lookup
        try { $rs = $raw->prepare('SELECT permissions FROM roles WHERE role_name = ? LIMIT 1'); $rs->execute([(string)$hrmsUser['role']]); $perms = json_decode((string)$rs->fetchColumn(), true) ?: []; } catch (Throwable $e) { $perms = []; }
    }
}
if (!$hrmsUser) sso_fail(403, 'This HRMS login no longer exists. Please log in to the HRMS again.');
$isAdmin = strcasecmp(trim((string)$hrmsUser['role']), 'Admin') === 0;
$isDisp  = !$isAdmin && in_array('dispatch_restock', $perms, true);
$isStore = !$isAdmin && !$isDisp && in_array('store_pos', $perms, true);
if (!$isAdmin && !$isDisp && !$isStore) sso_fail(403, 'Your HRMS role is not allowed to open the POS. Ask Admin to tick it on the Roles page.');

// 3) the POS user this session runs as ($pdo rewrites users -> pos_users)
$st = $pdo->prepare('SELECT * FROM users WHERE username = ? AND status = 1 LIMIT 1'); $st->execute([(string)$hrmsUser['username']]);
$pu = $st->fetch();
if (!$pu && $isStore) {   // first time a store person opens the POS: make their POS user (never admin; no password, they only enter through the HRMS)
    $pdo->prepare("INSERT INTO users (name, username, password, role) VALUES (?,?,?,'user')")->execute([(string)$hrmsUser['username'], (string)$hrmsUser['username'], password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)]);
    $st->execute([(string)$hrmsUser['username']]); $pu = $st->fetch();
}
if ($isAdmin && !$pu) { $pu = $pdo->query("SELECT * FROM users WHERE role = 'admin' AND status = 1 ORDER BY id LIMIT 1")->fetch(); }
if ($isDisp) { $pu = ['id' => $pu ? (int)$pu['id'] : 0, 'name' => (string)$hrmsUser['username'] . ' (dispatch)', 'role' => 'user', 'store_name' => '']; }
if (!$pu) sso_fail(503, 'The POS has no active admin user yet (run migrate_old_pos.php first).');

session_regenerate_id(true);
$_SESSION['user'] = ['id' => (int)$pu['id'], 'name' => $pu['name'], 'role' => $isStore ? 'user' : $pu['role'], 'store' => $pu['store_name'] ?? ''];
if ($isDisp) $_SESSION['user']['dispatch'] = true;   // dispatch people can open only Restock (see includes/auth.php)

// optional landing page (only a plain POS page name)
$go = (string)($_POST['go'] ?? '');
if (!preg_match('/^[a-z_]+\.php$/', $go) || !is_file(__DIR__ . '/' . $go) || $go === 'sso.php') $go = $isDisp ? 'restock_dispatch.php' : 'restock.php';
if ($isDisp && !in_array($go, ['restock.php', 'restock_dispatch.php'], true)) $go = 'restock_dispatch.php';
header('Location: ' . $go, true, 303);
exit;
