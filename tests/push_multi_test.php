<?php
// Parallel phone alerts: push_curl_multi() must read every answer exactly like the single push_deliver() and keep every key, also when only a few may be open at once. Uses a local fake push service (PHP built-in server, 0.4 s per answer).
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/api.php');
$a = strpos($src, 'function push_read_answer'); $b = strpos($src, '/** Phone entries the push service said are gone');
if ($a === false || $b === false) { echo "FAIL: push functions not found\n"; exit(1); }
$code = substr($src, $a, $b - $a);
$code = preg_replace('/\/\*\* Deliver one push\..*?\n}\n/s', '', $code, 1);   // push_deliver needs the database layer: not part of this test
eval($code);
$dir = sys_get_temp_dir() . '/pm_' . getmypid(); @mkdir($dir);
file_put_contents($dir . '/r.php', '<?php usleep(400000); $p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); if ($p === "/gone") { http_response_code(410); echo "gone"; } elseif ($p === "/err") { http_response_code(500); echo "<b>boom</b>"; } else { http_response_code(201); }');
$port = 18700 + getmypid() % 200;
putenv('PHP_CLI_SERVER_WORKERS=10');
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $dir . '/r.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50; $i++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($f) { fclose($f); break; } usleep(100000); }
$opts = [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_POSTFIELDS => 'x'];
$reqs = [];
for ($i = 0; $i < 12; $i++) $reqs["ok$i"] = ['endpoint' => "http://127.0.0.1:$port/ok", 'opts' => $opts];
$reqs['gone'] = ['endpoint' => "http://127.0.0.1:$port/gone", 'opts' => $opts];
$reqs['err'] = ['endpoint' => "http://127.0.0.1:$port/err", 'opts' => $opts];
$reqs['down'] = ['endpoint' => 'http://127.0.0.1:1/x', 'opts' => $opts];   // nothing listens here: a network error
$t = microtime(true); $r = push_curl_multi($reqs, 20); $el = microtime(true) - $t;
$bad = [];
if (count($r) !== count($reqs)) $bad[] = 'a key got lost: ' . count($r) . ' of ' . count($reqs);
for ($i = 0; $i < 12; $i++) if (empty($r["ok$i"]['ok']) || $r["ok$i"]['status'] !== 201) $bad[] = "ok$i not read as ok";
if (!empty($r['gone']['ok']) || empty($r['gone']['gone']) || $r['gone']['status'] !== 410) $bad[] = 'a 410 must be read as gone';
if (!empty($r['err']['ok']) || !empty($r['err']['gone']) || strpos($r['err']['error'], 'HTTP 500') === false) $bad[] = 'a 500 must be a plain failure';
if (!empty($r['down']['ok']) || strpos($r['down']['error'], 'Network:') !== 0) $bad[] = 'a closed port must be a network error';
// (speed is printed, not asserted: PHP's built-in test server answers only a few requests at a time, so its timing varies from run to run)
$t = microtime(true); $r2 = push_curl_multi(array_slice($reqs, 0, 9, true), 3); $el2 = microtime(true) - $t;   // 9 pushes, only 3 open at a time: every one must still be answered
if (count($r2) !== 9) $bad[] = 'queue lost a key';
proc_terminate($proc); @unlink($dir . '/r.php'); @rmdir($dir);
if ($bad) { echo "FAIL: " . implode('; ', $bad) . "\n"; exit(1); }
echo "push multi ok (" . round($el, 2) . " s for 15 pushes, " . round($el2, 2) . " s for 9 at 3 at a time)\n";
