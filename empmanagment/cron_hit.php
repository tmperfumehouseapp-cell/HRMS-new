<?php
/**
 * cron_hit.php — runs one of the HRMS background jobs from the server cron, with no key to copy. Command line only.
 *
 *   php cron_hit.php lib.cron      the Teach bot / Chat library study pass: reads old chats and calls, rewrites the WhatsApp guidebook (every 5–15 minutes)
 *   php cron_hit.php orders.cron   (orders job)      php cron_hit.php mail.cron   (mail job)
 *
 * It reads API_KEY from config.php itself, then calls the job once on this server. Use it with flock so a run that is still going blocks the next one:
 *   flock -n /tmp/hrms_lib.lock php /home/fourpirates-staging/htdocs/staging.fourpirates.space/empmanagment/cron_hit.php lib.cron >/dev/null 2>&1
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$action = (string)($argv[1] ?? ''); if (!in_array($action, ['lib.cron', 'orders.cron', 'mail.cron'], true)) { fwrite(STDERR, "usage: php cron_hit.php lib.cron|orders.cron|mail.cron\n"); exit(2); }
$t0 = microtime(true); @set_time_limit(320);
if (!is_file(__DIR__ . '/config.php')) { fwrite(STDERR, "config.php not found\n"); exit(2); }
require __DIR__ . '/config.php';
if (!defined('API_KEY') || (string)API_KEY === '') { fwrite(STDERR, "API_KEY is not set in config.php\n"); exit(3); }
$host = 'staging.fourpirates.space';
$ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 300, 'ignore_errors' => true, 'header' => "Host: $host\r\nUser-Agent: hrms-cron\r\n"],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
$body = @file_get_contents('https://127.0.0.1/empmanagment/api.php?action=' . urlencode($action) . '&key=' . urlencode((string)API_KEY), false, $ctx);
$code = 0; foreach (($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
echo date('Y-m-d H:i:s') . " $action " . ($body === false ? 'no answer' : "HTTP $code") . ' in ' . round(microtime(true) - $t0, 1) . 's ' . ($body !== false ? substr(preg_replace('/\s+/', ' ', $body), 0, 200) : '') . "\n";
exit($body === false || $code >= 400 ? 1 : 0);
