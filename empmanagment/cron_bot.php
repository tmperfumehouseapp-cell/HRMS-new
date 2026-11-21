<?php
/**
 * cron_bot.php — the BOT HEARTBEAT (run it every minute from CloudPanel → Cron Jobs). Command line only.
 *
 *   flock -n /tmp/hrms_bot.lock php /home/fourpirates-staging/htdocs/staging.fourpirates.space/empmanagment/cron_bot.php >/dev/null 2>&1
 *
 * For 58 seconds it fires FIVE short "bot tick" requests (wa.bot_tick) every second and does not wait for them. Each tick claims a free slot (MySQL lock) and answers
 * a waiting customer; when all five slots are busy the tick returns at once, so no web-server worker is ever held for long. A customer message is therefore
 * picked up within about a second; the AI call itself is the only remaining time. `flock -n` stops two copies running at once. While this script runs (setting wag_tick_at
 * is fresh) the long bot workers started by the sending job stay off.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
@set_time_limit(75); $t0 = microtime(true);
if (!is_file(__DIR__ . '/config.php')) { fwrite(STDERR, "config.php not found\n"); exit(2); }
require __DIR__ . '/config.php';
try {
    $pdo = new PDO('mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $q = $pdo->prepare('SELECT setting_value FROM hrms_settings WHERE setting_key = ?'); $q->execute(['wa_cron_key']); $key = (string)$q->fetchColumn();
} catch (Throwable $e) { fwrite(STDERR, 'database: ' . $e->getMessage() . "\n"); exit(3); }
if ($key === '') { fwrite(STDERR, "the engine key is not made yet (run cron_send.php once)\n"); exit(3); }
$host = 'staging.fourpirates.space'; $n = 0; $fail = 0;
$ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
$req = "GET /empmanagment/api.php?action=wa.bot_tick&key=" . urlencode($key) . " HTTP/1.1\r\nHost: $host\r\nUser-Agent: wa-bot-heartbeat\r\nConnection: close\r\n\r\n";
while (microtime(true) - $t0 < 58) {
    for ($i = 0; $i < 5; $i++) { $fp = @stream_socket_client('ssl://127.0.0.1:443', $en, $es, 2, STREAM_CLIENT_CONNECT, $ctx); if (!$fp) { $fail++; continue; } @fwrite($fp, $req); @fclose($fp); $n++; }
    usleep(1000000);
}
echo date('Y-m-d H:i:s') . " bot heartbeat: $n ticks fired, $fail failed to connect in " . round(microtime(true) - $t0, 1) . "s\n";
exit(0);
