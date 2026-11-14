<?php
/**
 * cron_funnel.php — counts the customer funnel (wa_cfun) from the command line: people with a FAILED message first, then everybody else. Command line only.
 *
 *   flock -n /tmp/wa_funnel.lock nice -n 15 php /home/fourpirates-staging/htdocs/staging.fourpirates.space/empmanagment/cron_funnel.php
 *
 * One call counts for about 100 seconds and says {"done":1} when every number is counted. Run it again while done is 0. It reads the engine key from the database itself,
 * like cron_engine.php, and calls the action wa.cron_funnel on this server. The normal hourly update keeps the numbers current afterwards.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$t0 = microtime(true); @set_time_limit(170);
if (!is_file(__DIR__ . '/config.php')) { fwrite(STDERR, "config.php not found\n"); exit(2); }
require __DIR__ . '/config.php';
try {
    $pdo = new PDO('mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $q = $pdo->prepare('SELECT setting_value FROM hrms_settings WHERE setting_key = ?'); $q->execute(['wa_cron_key']); $key = (string)$q->fetchColumn();
} catch (Throwable $e) { fwrite(STDERR, 'database: ' . $e->getMessage() . "\n"); exit(3); }
if ($key === '') { fwrite(STDERR, "the engine key is not made yet\n"); exit(3); }
$host = 'staging.fourpirates.space';
$ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 160, 'ignore_errors' => true, 'header' => "Host: $host\r\nUser-Agent: wa-funnel\r\n"],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
$body = @file_get_contents('https://127.0.0.1/empmanagment/api.php?action=wa.cron_funnel&key=' . urlencode($key), false, $ctx);
$code = 0; foreach (($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
echo date('Y-m-d H:i:s') . ' funnel ' . ($body === false ? 'no answer' : "HTTP $code") . ' in ' . round(microtime(true) - $t0, 1) . 's ' . ($body !== false ? substr(preg_replace('/\s+/', ' ', $body), 0, 240) : '') . "\n";
exit($body === false || $code >= 400 ? 1 : 0);
