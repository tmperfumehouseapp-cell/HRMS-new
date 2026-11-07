<?php
// Let the HRMS (fourpirates.space) show this POS inside a page, and nobody else.
header_remove('X-Frame-Options');
header("Content-Security-Policy: frame-ancestors 'self' https://fourpirates.space https://www.fourpirates.space");

if (session_status() === PHP_SESSION_NONE) {
    // The POS runs inside an HRMS page (another domain), so the login cookie must be
    // SameSite=None + Secure + Partitioned. Partitioned is what keeps it working in Incognito
    // and in browsers that block third-party cookies. It also works when the POS is opened directly.
    // New cookie name on purpose: it ignores any older cookie left in the browser.
    session_name('TMPOS_P');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 12,   // 12-hour shift
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None',
    ]);
    // Runs just before the headers go out, so it also covers the new cookie created by
    // session_regenerate_id() at login (this was what broke the earlier version).
    header_register_callback(function () {
        $keep = [];
        foreach (headers_list() as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) $keep[] = trim(substr($h, 11));
        }
        if (!$keep) return;
        header_remove('Set-Cookie');
        foreach ($keep as $v) {
            if (stripos($v, 'TMPOS_P=') === 0 && stripos($v, 'partitioned') === false) $v .= '; Partitioned';
            header('Set-Cookie: ' . $v, false);
        }
    });
    session_start();
}

// Require any logged-in user. For pages: redirects to login.
// For APIs: pass $api = true to return JSON 401 instead.
function require_login(bool $api = false): array {
    // A dispatch person (HRMS role ticked 'Dispatch · Store orders only', or ticked in Restock → People) can open ONLY the Restock pages.
    if (!empty($_SESSION['user']['dispatch'])) {
        $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (!in_array($self, ['restock_dispatch.php', 'restock.php', 'restock_api.php', 'logout.php', 'login.php', 'sso.php'], true)) {
            if ($api) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Dispatch access only']); exit; }
            header('Location: restock_dispatch.php'); exit;
        }
    }
    if (empty($_SESSION['user'])) {
        if ($api) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Not logged in']);
            exit;
        }
        header('Location: login.php');
        exit;
    }
    return $_SESSION['user'];
}

// Require admin role
function require_admin(bool $api = false): array {
    $u = require_login($api);
    if ($u['role'] !== 'admin') {
        if ($api) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }
        header('Location: index.php');
        exit;
    }
    return $u;
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}
