<?php
/**
 * Visit this once (with the correct key) to bypass the maintenance page
 * while you keep working: /maintenance_bypass.php?key=YOUR_SECRET_KEY
 *
 * CHANGE THIS before using — pick your own long random string.
 */
define('BYPASS_KEY', 'change-this-to-your-own-secret-2026');

$key = $_GET['key'] ?? '';

if (!hash_equals(BYPASS_KEY, $key)) {
    http_response_code(403);
    echo 'Invalid key.';
    exit;
}

// 12-hour bypass cookie, checked by .htaccess (mod_rewrite reads HTTP_COOKIE
// as plain text, so the cookie value is the key itself — keep this file and
// the RewriteCond in .htaccess using the exact same BYPASS_KEY).
setcookie('hrms_bypass', BYPASS_KEY, [
    'expires' => time() + 12 * 3600,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

header('Location: /app/dashboard.html');
exit;