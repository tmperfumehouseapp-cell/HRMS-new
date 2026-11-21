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
$reqFu = str_replace("action=wa.bot_tick&", "action=wa.bot_tick&role=fuw&", $req);   // 🔁 TWO follow-up workers, each one long request (50 s) that loops follow-up rounds, started once at the start of this 58-second run   // one extra tick a second only for FOLLOW-UPS (own slots): customer answers are never held back by a follow-up round, and follow-ups no longer starve (owner, 21 Nov)
// The connections are KEPT OPEN for a few seconds (the follow-up worker for the whole run): a request that had to wait in the web server's queue was dropped when we closed the socket at once (nginx cancels a request whose client has gone) - the follow-up worker never started (wag_fuw_n = 0) and ticks were lost (heartbeat gaps up to 155 s).
$open = [];
$fire = function (string $rq, float $keep) use (&$open, &$n, &$fail, $ctx) { $fp = @stream_socket_client('ssl://127.0.0.1:443', $en, $es, 1, STREAM_CLIENT_CONNECT, $ctx); if (!$fp) { $fail++; return; } @fwrite($fp, $rq); @stream_set_blocking($fp, false); $open[] = [$fp, microtime(true) + $keep]; $n++; };
$fire($reqFu, 70.0);
// 🧵 LINEUP SENDERS (owner 21 Nov: the day's plan must be finished before the next day's steps come): the web server has only 40 workers and the bot keeps them busy, so the Lineup is sent
// by command-line copies (cron_line.php), no web worker needed. Each copy takes one shard and sends for ~50 s. Switch off with the setting wl_cli = 0; wl_cli_n = how many (default 8).
try {
    $__qs = $pdo->prepare('SELECT setting_value FROM hrms_settings WHERE setting_key = ?'); $__qs->execute(['wl_cli']); $__on = $__qs->fetchColumn(); $__qs->execute(['wl_cli_n']); $__nn = (int)$__qs->fetchColumn();
    if ($__on === '1' && is_file(__DIR__ . '/cron_line.php') && function_exists('exec')) { $__nn = $__nn > 0 ? min(8, $__nn) : 8; for ($__i = 0; $__i < $__nn; $__i++) { @exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/cron_line.php') . ' >/dev/null 2>&1 &'); usleep(150000); } }
} catch (Throwable $e) { }
while (microtime(true) - $t0 < 58) {
    for ($i = 0; $i < 3; $i++) { if (microtime(true) - $t0 >= 57) break 2; $fire($req, 4.0); }
    foreach ($open as $k => $o) { if ($o[1] < microtime(true)) { @fclose($o[0]); unset($open[$k]); } }
    usleep(1000000);
}
echo date('Y-m-d H:i:s') . " bot heartbeat: $n ticks fired, $fail failed to connect in " . round(microtime(true) - $t0, 1) . "s\n";
exit(0);
