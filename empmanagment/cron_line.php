<?php
/**
 * cron_line.php — ONE Lineup SENDING round, run on the command line (NOT through the web server). cron_bot.php starts several of them every minute
 * (owner 21 Nov: "finish the day's plan before the next day's steps are added"). Every copy takes one shard of the due customers (MySQL lock) and sends for
 * about 50 seconds; the web-server workers (40, all busy with the bot) are not used at all. Same code as the sending job (wa.cron_send), same blocks, same
 * shard locks: two copies never send to the same customer. Test: php cron_line.php test. PHP 7.4 safe.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
@set_time_limit(80); @ini_set('display_errors', '0');
if (!is_file(__DIR__ . '/config.php')) exit(2);
require __DIR__ . '/config.php';
try {
    $pdo0 = new PDO('mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $q = $pdo0->prepare('SELECT setting_value FROM hrms_settings WHERE setting_key = ?'); $q->execute(['wa_cron_key']); $key = (string)$q->fetchColumn(); $pdo0 = null;
} catch (Throwable $e) { exit(3); }
if ($key === '') exit(3);
// the same call as the web job, but made here: api.php answers into the output buffer and we throw it away (a short line stays for the test)
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['HTTP_HOST'] = 'staging.fourpirates.space'; $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true); $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['SCRIPT_NAME'] = '/empmanagment/api.php';
$_GET = ['action' => 'wa.cron_send', 'key' => $key, 'cli' => '1']; $_REQUEST = $_GET;
chdir(__DIR__); ob_start();
register_shutdown_function(function () use ($argv) { $o = (string)@ob_get_clean(); if (($argv[1] ?? '') === 'test') echo date('Y-m-d H:i:s') . ' cron_line: ' . substr(preg_replace('/\s+/', ' ', $o), 0, 600) . "\n"; });
require __DIR__ . '/api.php';
