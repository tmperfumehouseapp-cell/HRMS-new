<?php
/**
 * cron_engine.php — the WhatsApp engine for the server cron (run it every minute from CloudPanel → Cron Jobs). Command line only.
 *
 *   flock -n /tmp/wa_engine.lock php /home/fourpirates-staging/htdocs/staging.fourpirates.space/empmanagment/cron_engine.php >/dev/null 2>&1
 *
 * It reads the engine key from the database itself (so no secret has to be copied anywhere), then calls the engine (action wa.cron) on this
 * server once. The engine sends campaigns and follow-ups and assigns chats, so no open page is needed any more. `flock -n` makes a run
 * that is still going block the next minute's start (no overlapping runs).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$t0 = microtime(true); @set_time_limit(170);
if (!is_file(__DIR__ . '/config.php')) { fwrite(STDERR, "config.php not found\n"); exit(2); }
require __DIR__ . '/config.php';
try {
    $pdo = new PDO('mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $q = $pdo->prepare('SELECT setting_value FROM hrms_settings WHERE setting_key = ?'); $q->execute(['wa_cron_key']); $key = (string)$q->fetchColumn();
    if ($key === '') { $key = bin2hex(random_bytes(12)); $pdo->prepare('INSERT INTO hrms_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute(['wa_cron_key', $key]); }
} catch (Throwable $e) { fwrite(STDERR, 'database: ' . $e->getMessage() . "\n"); exit(3); }
$host = 'staging.fourpirates.space';
$ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 160, 'ignore_errors' => true, 'header' => "Host: $host\r\nUser-Agent: wa-cron\r\n"],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
$body = @file_get_contents('https://127.0.0.1/empmanagment/api.php?action=wa.cron&key=' . urlencode($key), false, $ctx);
$code = 0; foreach (($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
echo date('Y-m-d H:i:s') . ' engine ' . ($body === false ? 'no answer' : "HTTP $code") . ' in ' . round(microtime(true) - $t0, 1) . 's ' . ($body !== false ? substr(preg_replace('/\s+/', ' ', $body), 0, 200) : '') . "\n";
exit($body === false || $code >= 400 ? 1 : 0);
