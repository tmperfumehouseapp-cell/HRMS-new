<?php
/**
 * sys_load.php — "Server load" (Admin → Server load): what the server is busy with right now and over the last hour, in plain words, with what to do about it.
 *
 *   The recorder (sysload_record, called at the end of EVERY api.php request) appends one short line per request to a file of the current hour in the temp folder:
 *       minute-of-epoch <tab> action <tab> wall ms <tab> CPU ms
 *   No database write, so measuring adds no load worth naming. Files older than 2 days are removed when the page is opened.
 *   sysload.view (Admin only) reads those lines and adds the live picture: CPU %, load, memory, the busiest programs, what the database is running, the engine's steps.
 */
if (!function_exists('sysload_dir')) {

function sysload_dir(): string { return rtrim(sys_get_temp_dir(), '/') . '/hrms_perf'; }

/** One line per request. Never throws; a failure to write is ignored. */
function sysload_record(string $action, float $t0, ?array $ru0): void
{
    try {
        if ($action === '' || strpos($action, 'sysload.') === 0) return;
        $wall = (int)round((microtime(true) - $t0) * 1000); $cpu = 0;
        if ($ru0 && function_exists('getrusage')) { $r = getrusage(); $cpu = (int)round((($r['ru_utime.tv_sec'] - $ru0['ru_utime.tv_sec']) + ($r['ru_stime.tv_sec'] - $ru0['ru_stime.tv_sec'])) * 1000 + (($r['ru_utime.tv_usec'] - $ru0['ru_utime.tv_usec']) + ($r['ru_stime.tv_usec'] - $ru0['ru_stime.tv_usec'])) / 1000); }
        $d = sysload_dir(); if (!is_dir($d)) @mkdir($d, 0700, true);
        @file_put_contents($d . '/' . date('YmdH') . '.log', intdiv(time(), 60) . "\t" . preg_replace('/[^A-Za-z0-9_.]/', '', substr($action, 0, 48)) . "\t" . $wall . "\t" . max(0, $cpu) . "\n", FILE_APPEND | LOCK_EX);
        $mk = $d . '/minute.mark'; if (time() - (int)@filemtime($mk) >= 55) { @touch($mk); sysload_minute($d); }      // once a minute, by whichever request ends first
    } catch (Throwable $e) { }
}

/** CPU counters of the whole server and of the database program, straight from the system (the same counters a hosting panel reads). */
function sysload_counters(): ?array
{
    $l = @file('/proc/stat'); if (!$l) return null; $p = preg_split('/\s+/', trim($l[0])); array_shift($p); $p = array_map('floatval', $p);
    $db = null;
    foreach ((array)@glob('/proc/[0-9]*/comm') as $f) {
        $c = trim((string)@file_get_contents($f)); if ($c !== 'mysqld' && $c !== 'mariadbd' && $c !== 'mysqld_safe') continue;
        $st = @file_get_contents(dirname($f) . '/stat'); if ($st === false) continue; $r = strrpos($st, ')'); if ($r === false) continue;
        $x = explode(' ', trim(substr($st, $r + 1))); $db = ($db ?? 0) + (float)($x[11] ?? 0) + (float)($x[12] ?? 0);      // utime + stime, in the same ticks as /proc/stat
    }
    // 'steal' = time this server wanted the CPU but the hosting's machine gave it to another customer. It is NOT this server's own work, and the hosting panel does not count it:
    // top showed 43.3 us + 4.2 sy + 2.4 si = 49.9% used and 8.3% steal, where the panel said 48% and this page (which had counted steal as work) said 59.5%.
    return ['t' => time(), 'all' => array_sum(array_slice($p, 0, 8)), 'idle' => ($p[3] ?? 0) + ($p[4] ?? 0), 'steal' => ($p[7] ?? 0), 'db' => $db];
}

/** The once-a-minute snapshot: one line of CPU counters, and what the database has spent on each kind of query so far (one light read). The page works out "the last N minutes" from two snapshots. */
function sysload_minute(string $d): void
{
    try {
        $c = sysload_counters(); if ($c) @file_put_contents($d . '/cpu.log', json_encode($c) . "\n", FILE_APPEND | LOCK_EX);
        if (@filesize($d . '/cpu.log') > 400000) { $ls = @file($d . '/cpu.log'); if ($ls) @file_put_contents($d . '/cpu.log', implode('', array_slice($ls, -400)), LOCK_EX); }
        $pdo = $GLOBALS['pdo'] ?? null; if (!($pdo instanceof PDO)) return;
        $rows = [];
        foreach ($pdo->query("SELECT /*+ MAX_EXECUTION_TIME(3000) */ DIGEST d, COUNT_STAR c, ROUND(SUM_TIMER_WAIT / 1e9) ms, SUM_ROWS_EXAMINED r, LEFT(DIGEST_TEXT, 320) q FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME = DATABASE() ORDER BY SUM_TIMER_WAIT DESC LIMIT 150")->fetchAll(PDO::FETCH_ASSOC) as $x) $rows[(string)$x['d']] = [(float)$x['ms'], (float)$x['c'], (float)$x['r'], (string)$x['q']];
        if ($rows) { if (!is_dir($d . '/dig')) @mkdir($d . '/dig', 0700, true); @file_put_contents($d . '/dig/' . intdiv(time(), 60) . '.json', json_encode($rows), LOCK_EX); }
        foreach ((array)@glob($d . '/dig/*.json') as $f) if (time() - (int)@filemtime($f) > 200 * 60) @unlink($f);
    } catch (Throwable $e) { }
    sysload_looks($d);
}

/** Many database users may not read the database's own statistics (this server's cannot). So, once a minute, the database is LOOKED AT 25 times over 2.5 seconds
 *  ("what are you running now?") and each kind of query seen is counted. Over an hour that is 1,500 looks: a query seen in 30% of them keeps about 0.3 of a CPU core busy.
 *  The answer of the request that does this was already sent, so nobody waits for it. */
function sysload_looks(string $d): void
{
    try {
        $pdo = $GLOBALS['pdo'] ?? null; if (!($pdo instanceof PDO)) return;
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        $me = (int)$pdo->query("SELECT CONNECTION_ID()")->fetchColumn(); $seen = []; $looks = 0; $run = 0;
        for ($i = 0; $i < 25; $i++) {
            foreach ($pdo->query("SHOW FULL PROCESSLIST")->fetchAll(PDO::FETCH_ASSOC) as $x) {
                if ((int)$x['Id'] === $me || (string)$x['Command'] !== 'Query' || trim((string)$x['Info']) === '') continue;
                $q = preg_replace('/\s+/', ' ', (string)$x['Info']); $q = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $q); $q = preg_replace('/\b\d+(\.\d+)?\b/', 'N', $q); $q = preg_replace('/\((?:\s*[?N]\s*,)+\s*[?N]\s*\)/', '(…)', $q);
                $k = mb_substr($q, 0, 300); if (!isset($seen[$k])) $seen[$k] = [0, 0]; $seen[$k][0]++; if ((int)$x['Time'] > $seen[$k][1]) $seen[$k][1] = (int)$x['Time']; $run++;
            }
            $looks++; usleep(100000);
        }
        if (!is_dir($d . '/ql')) @mkdir($d . '/ql', 0700, true);
        @file_put_contents($d . '/ql/' . intdiv(time(), 60) . '.json', json_encode(['looks' => $looks, 'run' => $run, 'q' => $seen]), LOCK_EX);
        foreach ((array)@glob($d . '/ql/*.json') as $f) if (time() - (int)@filemtime($f) > 200 * 60) @unlink($f);
    } catch (Throwable $e) { }
}

/** Plain names for the actions that matter most; anything else shows its own name. [name, what causes it] */
function sysload_names(): array
{
    return [
        'wa.camp_fast_tick' => ['Campaigns · "Send fast" sender', 'An open Campaigns page with a campaign on Send fast asks the server for sending rounds one after another.'],
        'wa.camp_tick' => ['Campaigns page · sending round', 'An open Campaigns page asks for a sending round every 30 seconds.'],
        'wa.dq_worker' => ['Campaigns · extra senders', 'An open Campaigns page runs extra sending rounds while delivery is good.'],
        'wa.cron' => ['WhatsApp engine (server job, every minute)', 'Sends campaigns and follow-ups, assigns chats, tags customers.'],
        'wa.cron_send' => ['Campaign sending job (server job, every minute)', 'Sends campaign messages.'],
        'wa.webhook' => ['WhatsApp messages and delivery receipts arriving', 'Meta calls this for every message a customer sends and for every sent / delivered / read receipt of a campaign.'],
        'wq.req_list' => ['Requests list refreshing', 'Every open sales chat screen re-reads the Requests list every few seconds.'],
        'wa.g_sig' => ['Chat screens checking for news', 'Every open WhatsApp chat screen asks "anything new?" every few seconds.'],
        'wa.g_poll' => ['Chat screens checking for news', 'Every open WhatsApp chat screen asks "anything new?" every few seconds.'],
        'wa.g_inbox' => ['Chat list refreshing', 'Every open WhatsApp chat list re-reads itself.'],
        'wa.chat' => ['Opening a chat', 'A person opened a customer chat.'],
        'wa.media' => ['Photos / videos / voice notes loading', 'Chat media being shown.'],
        'shopify.orders' => ['Orders page loading orders', 'The Orders page (and My Sales) reading the orders of its dates.'],
        'reminders.tick' => ['Phone reminders + Control Tower alerts', 'Runs by itself once a minute.'],
        'bd.tick' => ['Boss engine (server job)', 'Duties, reminders, complaints and inbox checks.'],
        'dp.tick' => ['Dispatch engine (server job)', 'Shiprocket and Shopify parcel sync, tracking warnings.'],
        'chk.tick' => ['Checks (server job)', 'Document, data and office checks.'],
        'orders.cron' => ['Shopify order sync (server job)', 'Keeps the local copy of the orders fresh.'],
        'lib.cron' => ['Chat library study pass (server job)', 'Reads old chats and rewrites the guidebook (uses AI).'],
        'mail.cron' => ['E-mail queue (server job)', 'Sends waiting e-mails.'],
        'bd.b2_cm_view' => ['Commission page', 'The Commission page working out a day.'],
        'bd.b2_cm_recount' => ['Commission page · Day by day', 'Works out every person for 7 to 31 days.'],
        'commission.paid' => ['Commission figures for the Orders sheet', 'The Orders page asking what each order pays.'],
        'my.commission' => ['Salesperson\'s own commission', 'My Sales working out the month.'],
        'me.flags' => ['Menu and badges', 'Every page asks this once when it opens.'],
    ];
}

function sysload_cpu_sample(): array
{
    $read = function () { $l = @file('/proc/stat'); if (!$l) return null; $p = preg_split('/\s+/', trim($l[0])); array_shift($p); $p = array_map('floatval', $p); return ['idle' => ($p[3] ?? 0) + ($p[4] ?? 0), 'steal' => ($p[7] ?? 0), 'all' => array_sum(array_slice($p, 0, 8))]; };
    $a = $read(); if (!$a) return ['pct' => null]; usleep(350000); $b = $read(); if (!$b || $b['all'] <= $a['all']) return ['pct' => null];
    $da = $b['all'] - $a['all'];
    return ['pct' => round(100 * (1 - (($b['idle'] - $a['idle']) + ($b['steal'] - $a['steal'])) / $da), 1), 'steal' => round(100 * ($b['steal'] - $a['steal']) / $da, 1)];
}
function sysload_cores(): int { $c = @file_get_contents('/proc/cpuinfo'); $n = $c !== false ? preg_match_all('/^processor\s*:/m', $c) : 0; return max(1, (int)$n); }

/** The whole picture for the page. $mins = how far back the request lines are read (15–180). */
function sysload_view(PDO $pdo, int $mins): array
{
    $cores = sysload_cores(); $now = time(); $from = intdiv($now, 60) - $mins;
    $ld = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
    $mem = null; $mi = @file_get_contents('/proc/meminfo'); if ($mi !== false && preg_match('/MemTotal:\s+(\d+)/', $mi, $a) && preg_match('/MemAvailable:\s+(\d+)/', $mi, $b)) $mem = ['total_mb' => (int)round($a[1] / 1024), 'free_mb' => (int)round($b[1] / 1024)];
    $out = ['now' => date('Y-m-d H:i:s'), 'mins' => $mins, 'cores' => $cores, 'cpu' => ($__cs = sysload_cpu_sample())['pct'], 'steal_now' => $__cs['steal'] ?? null, 'load' => $ld ? array_map(fn($v) => round((float)$v, 2), $ld) : null, 'mem' => $mem];

    // CPU as an AVERAGE (what a hosting panel shows), from the once-a-minute snapshots: the last 1 / 5 / 15 minutes and the period chosen; and the database's own part of it
    $snaps = []; foreach ((array)@file(sysload_dir() . '/cpu.log') as $l) { $j = json_decode((string)$l, true); if (is_array($j) && isset($j['t'], $j['all'])) $snaps[] = $j; }
    $cur = sysload_counters(); if ($cur) $snaps[] = $cur;
    $avg = function (int $m) use ($snaps) {
        $n = count($snaps); if ($n < 2) return null; $b = $snaps[$n - 1]; $a = null;
        for ($i = $n - 2; $i >= 0; $i--) { if ($b['t'] - $snaps[$i]['t'] >= $m * 60 - 20) { $a = $snaps[$i]; break; } }
        if ($a === null) { $a = $snaps[0]; if ($b['t'] - $a['t'] < 45) return null; }
        $da = $b['all'] - $a['all']; if ($da <= 0) return null;
        $ds = (float)($b['steal'] ?? 0) - (float)($a['steal'] ?? 0); if (!isset($a['steal'])) $ds = 0;      // snapshots saved before steal was recorded
        return ['pct' => round(100 * (1 - (($b['idle'] - $a['idle']) + $ds) / $da), 1), 'steal' => isset($a['steal']) ? round(100 * $ds / $da, 1) : null, 'db' => ($a['db'] !== null && $b['db'] !== null && $b['db'] >= $a['db']) ? round(100 * ($b['db'] - $a['db']) / $da, 1) : null, 'mins' => (int)round(($b['t'] - $a['t']) / 60)];
    };
    $out['avg'] = ['m1' => $avg(1), 'm5' => $avg(5), 'm15' => $avg(15), 'win' => $avg($mins)];

    // the busiest programs right now (needs shell access; many hosts switch it off — then this part is simply left out)
    $procs = []; $groups = [];
    $can = function_exists('shell_exec') && stripos((string)ini_get('disable_functions'), 'shell_exec') === false;
    if ($can) {
        $ps = @shell_exec('ps -eo pcpu,pmem,etimes,comm,args --sort=-pcpu 2>/dev/null | head -60');
        foreach (array_slice(explode("\n", trim((string)$ps)), 1) as $l) {
            if (!preg_match('/^\s*([\d.]+)\s+([\d.]+)\s+(\d+)\s+(\S+)\s+(.*)$/', $l, $m)) continue;
            $g = stripos($m[4], 'php') !== false ? 'PHP (the HRMS pages and jobs)' : (stripos($m[4], 'mysql') !== false || stripos($m[4], 'maria') !== false ? 'Database (MySQL)' : (stripos($m[4], 'nginx') !== false ? 'Web server (nginx)' : 'Other programs'));
            $groups[$g] = ($groups[$g] ?? 0) + (float)$m[1];
            if (count($procs) < 8 && (float)$m[1] >= 1) $procs[] = ['cpu' => (float)$m[1], 'mem' => (float)$m[2], 'secs' => (int)$m[3], 'what' => mb_substr(preg_replace('/\s+/', ' ', preg_replace('#/home/\S+/htdocs/\S+?/#', '', $m[5])), 0, 90)];
        }
        arsort($groups);
    }
    $out['groups'] = array_map(fn($k, $v) => ['name' => $k, 'cpu' => round($v, 1), 'of_all' => round($v / $cores, 1)], array_keys($groups), $groups); $out['procs'] = $procs; $out['shell'] = $can;

    // the requests of the last $mins minutes, per action
    $agg = []; $total = ['n' => 0, 'wall' => 0, 'cpu' => 0]; $d = sysload_dir(); $first = null;
    foreach ((array)@glob($d . '/*.log') as $f) {
        $age = $now - (int)@filemtime($f); if ($age > 2 * 86400) { @unlink($f); continue; } if ($age > ($mins + 61) * 60) continue;
        $h = @fopen($f, 'r'); if (!$h) continue;
        while (($l = fgets($h)) !== false) {
            $p = explode("\t", rtrim($l)); if (count($p) < 4 || (int)$p[0] < $from) continue;
            if ($first === null || (int)$p[0] < $first) $first = (int)$p[0];
            $a = &$agg[$p[1]]; if ($a === null) $a = ['n' => 0, 'wall' => 0, 'cpu' => 0, 'max' => 0];
            $a['n']++; $a['wall'] += (int)$p[2]; $a['cpu'] += (int)$p[3]; if ((int)$p[2] > $a['max']) $a['max'] = (int)$p[2]; unset($a);
            $total['n']++; $total['wall'] += (int)$p[2]; $total['cpu'] += (int)$p[3];
        }
        fclose($h);
    }
    $span = $first === null ? 0 : max(60, ($now - $first * 60));      // seconds really covered (the recorder may be younger than the window asked for)
    $names = sysload_names(); $rows = [];
    foreach ($agg as $k => $a) {
        $nm = $names[$k] ?? null;
        $rows[] = ['action' => $k, 'name' => $nm ? $nm[0] : $k, 'why' => $nm ? $nm[1] : '', 'calls' => $a['n'], 'per_min' => $span ? round($a['n'] / ($span / 60), 1) : 0, 'avg_ms' => (int)round($a['wall'] / max(1, $a['n'])), 'max_ms' => $a['max'],
            'cpu_s' => round($a['cpu'] / 1000, 1), 'wall_s' => round($a['wall'] / 1000, 1), 'wait_s' => round(max(0, $a['wall'] - $a['cpu']) / 1000, 1), 'time_share' => $total['wall'] > 0 ? round(100 * $a['wall'] / $total['wall'], 1) : 0, 'cpu_share' => $total['cpu'] > 0 ? round(100 * $a['cpu'] / $total['cpu'], 1) : 0, 'of_server' => $span ? round(100 * ($a['cpu'] / 1000) / ($span * $cores), 2) : 0];
    }
    usort($rows, fn($x, $y) => $y['wall_s'] <=> $x['wall_s']);      // by the time they keep the server busy (their own work + waiting for the database)
    $out['span_min'] = (int)round($span / 60); $out['requests'] = $total['n']; $out['php_of_server'] = $span ? round(100 * ($total['cpu'] / 1000) / ($span * $cores), 1) : null;
    $out['actions'] = array_slice($rows, 0, 40);
    $slow = $rows; usort($slow, fn($x, $y) => $y['avg_ms'] <=> $x['avg_ms']); $out['slow'] = array_slice(array_values(array_filter($slow, fn($r) => $r['calls'] >= 3 && $r['avg_ms'] >= 800)), 0, 12);

    // the database: what runs right now, and which kinds of query took the most time since it was started
    $run = []; $dig = [];
    try { $me = (int)$pdo->query("SELECT CONNECTION_ID()")->fetchColumn(); foreach ($pdo->query("SHOW FULL PROCESSLIST")->fetchAll(PDO::FETCH_ASSOC) as $x) { if ((int)$x['Id'] === $me || (string)$x['Command'] !== 'Query' || (string)$x['Info'] === '') continue; $run[] = ['secs' => (int)$x['Time'], 'q' => mb_substr(preg_replace('/\s+/', ' ', (string)$x['Info']), 0, 160)]; } usort($run, fn($a, $b) => $b['secs'] <=> $a['secs']); } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT COUNT_STAR c, ROUND(SUM_TIMER_WAIT / 1e12) s, ROUND(SUM_ROWS_EXAMINED / 1e6, 1) mr, LEFT(DIGEST_TEXT, 170) q FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME = DATABASE() ORDER BY SUM_TIMER_WAIT DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) as $x) $dig[] = ['secs' => (int)$x['s'], 'calls' => (int)$x['c'], 'mrows' => (float)$x['mr'], 'q' => (string)$x['q']]; } catch (Throwable $e) { }
    $out['db_running'] = array_slice($run, 0, 10); $out['db_top'] = $dig;
    // what the database spent its time on IN THIS PERIOD: the newest snapshot minus the one from the start of the period
    $recent = []; $dbSpan = 0; $files = (array)@glob(sysload_dir() . '/dig/*.json'); sort($files);
    if (count($files) >= 2) {
        $newF = $files[count($files) - 1]; $newM = (int)basename($newF, '.json'); $oldF = null;
        foreach ($files as $f) { $m = (int)basename($f, '.json'); if ($m >= $newM - $mins && $m < $newM) { $oldF = $f; break; } }
        if ($oldF) {
            $A = json_decode((string)@file_get_contents($oldF), true) ?: []; $B = json_decode((string)@file_get_contents($newF), true) ?: []; $dbSpan = ($newM - (int)basename($oldF, '.json')) * 60; $sum = 0.0;
            foreach ($B as $k => $v) { $o = $A[$k] ?? [0, 0, 0]; $ms = $v[0] - $o[0]; $c = $v[1] - $o[1]; $r = $v[2] - $o[2]; if ($ms < 0 || $c < 0) { $ms = $v[0]; $c = $v[1]; $r = $v[2]; } if ($ms <= 0 || $c <= 0) continue; $recent[] = ['secs' => round($ms / 1000, 1), 'calls' => (int)$c, 'avg_ms' => (int)round($ms / $c), 'rows_each' => (int)round(max(0, $r) / $c), 'q' => (string)$v[3]]; $sum += $ms; }
            usort($recent, fn($x, $y) => $y['secs'] <=> $x['secs']);
            foreach ($recent as &$x) { $x['share'] = $sum > 0 ? round(100 * $x['secs'] * 1000 / $sum, 1) : 0; $x['cores'] = $dbSpan ? round($x['secs'] / $dbSpan, 2) : 0; } unset($x);
        }
    }
    $out['db_mode'] = 'stats';
    if (!$recent) {      // the database's own statistics are closed to this user: use the once-a-minute looks instead
        $looks = 0; $agg2 = []; $firstM = null; $nowM = intdiv($now, 60);
        foreach ((array)@glob(sysload_dir() . '/ql/*.json') as $f) { $m = (int)basename($f, '.json'); if ($m < $nowM - $mins) continue; $j = json_decode((string)@file_get_contents($f), true); if (!is_array($j)) continue; $looks += (int)($j['looks'] ?? 0); if ($firstM === null || $m < $firstM) $firstM = $m;
            foreach ((array)($j['q'] ?? []) as $k => $v) { if (!isset($agg2[$k])) $agg2[$k] = [0, 0]; $agg2[$k][0] += (int)$v[0]; if ((int)$v[1] > $agg2[$k][1]) $agg2[$k][1] = (int)$v[1]; } }
        $tot = array_sum(array_column($agg2, 0));
        foreach ($agg2 as $k => $v) $recent[] = ['q' => (string)$k, 'share' => $tot ? round(100 * $v[0] / $tot, 1) : 0, 'cores' => $looks ? round($v[0] / $looks, 2) : 0, 'seen' => $v[0], 'max_s' => $v[1], 'calls' => 0, 'avg_ms' => 0, 'rows_each' => 0, 'secs' => 0];
        usort($recent, fn($x, $y) => $y['seen'] <=> $x['seen']);
        $dbSpan = $firstM === null ? 0 : max(60, ($nowM - $firstM + 1) * 60); $out['db_mode'] = 'looks'; $out['db_looks'] = $looks; $out['db_busy_cores'] = $looks ? round($tot / $looks, 2) : null;
    }
    $out['db_recent'] = array_slice($recent, 0, 12); $out['db_span_min'] = (int)round($dbSpan / 60);

    // the WhatsApp engine: how long each of its parts took on its last run
    $eng = [];
    foreach (['wa_cron_steps' => 'Engine run', 'wa_tick_steps' => 'Engine · the small jobs'] as $k => $t) { try { $j = json_decode((string)setting_get($pdo, $k, ''), true); if (is_array($j) && !empty($j['s'])) { $s = []; foreach ($j['s'] as $n => $v) if (is_numeric($v) && (float)$v >= 0.5) $s[] = ['step' => (string)$n, 'secs' => round((float)$v, 1)]; usort($s, fn($a, $b) => $b['secs'] <=> $a['secs']); $eng[] = ['title' => $t, 'at' => (string)($j['at'] ?? ''), 'steps' => array_slice($s, 0, 10)]; } } catch (Throwable $e) { } }
    $out['engine'] = $eng;

    // what to do: plain advice from the numbers above. Every item carries a ready prompt to paste to Claude.
    $adv = []; $A5 = $out['avg']['m5'] ?? null; $AW = $out['avg']['win'] ?? $A5; $busy = $A5 ? $A5['pct'] : $out['cpu'];
    $ctx = 'Repository tmperfumehouseapp-cell/HRMS-new (the HRMS: PHP in empmanagment/, pages in the root). The server has ' . $cores . ' CPU cores; average CPU is ' . ($busy ?? '?') . '%' . ($AW && $AW['db'] !== null ? ', of which the MySQL database is ' . $AW['db'] . '% and the HRMS PHP code about ' . ($out['php_of_server'] ?? '?') . '%' : '') . ' (Admin → Server load, last ' . ($out['span_min'] ?: $mins) . ' minutes).';
    $rule = ' Do not change what any page shows or what is paid or sent; only make it cheaper. Tell me what you changed and how much it should save.';
    if ($busy !== null) $adv[] = ['lvl' => $busy >= 80 ? 'bad' : ($busy >= 50 ? 'warn' : 'ok'), 't' => 'The server is using ' . $busy . '% of its ' . $cores . ' CPU cores' . ($A5 ? ' (average of the last ' . max(1, $A5['mins']) . ' minutes)' : ' right now') . '.',
        'd' => ($busy >= 80 ? 'This is high: pages will feel slow.' : ($busy >= 50 ? 'Busy but working.' : 'There is room to spare.')) . ' This is the server\'s own work, the same thing your hosting panel shows.'];
    $stl = $A5['steal'] ?? ($out['steal_now'] ?? null);
    if ($stl !== null && $stl >= 3) $adv[] = ['lvl' => $stl >= 10 ? 'bad' : 'warn', 't' => 'The hosting is taking ' . $stl . '% of your CPU away ("steal").', 'd' => 'For that share of the time your server wanted to work but the hosting\'s machine was busy with other customers. It makes everything slower and nothing in the HRMS can change it. If it stays above 5%, write to Hostinger support: "My VPS shows ' . $stl . '% CPU steal time in top. Please move it to a less busy host."'];
    if (!$span) $adv[] = ['lvl' => 'info', 't' => 'Measuring has just started.', 'd' => 'This page counts every request from now on. Open it again in 10 to 15 minutes.'];
    if ($AW && $AW['db'] !== null && $AW['pct'] > 0) {
        $rest = max(0, round($AW['pct'] - $AW['db'] - (float)($out['php_of_server'] ?? 0), 1));
        $adv[] = ['lvl' => $AW['db'] >= 0.5 * $AW['pct'] ? 'bad' : 'info', 't' => 'Where the CPU goes: database ' . $AW['db'] . '% · HRMS code ' . ($out['php_of_server'] ?? '?') . '% · everything else ' . $rest . '% (of the whole server).',
            'd' => $AW['db'] >= 0.5 * $AW['pct'] ? 'Most of the load is the DATABASE answering queries, not the pages themselves. The queries under "The database · this period" are where to save: each has its own prompt.' : 'The load is spread; start with the first rows of the two tables below.'];
    }
    foreach (array_slice($out['db_recent'], 0, 4) as $i => $q) {
        if ($q['share'] < 6) continue;
        $looksMode = ($out['db_mode'] ?? '') === 'looks';
        if ($looksMode) { $adv[] = ['lvl' => $q['share'] >= 25 ? 'bad' : 'warn', 't' => 'Database query #' . ($i + 1) . ' is ' . $q['share'] . '% of everything the database was seen doing. It keeps about ' . $q['cores'] . ' of a CPU core busy' . ($q['max_s'] >= 2 ? ' and one run was seen taking ' . $q['max_s'] . ' seconds' : '') . '.',
            'd' => mb_substr($q['q'], 0, 140) . '…',
            'prompt' => $ctx . ' The database was looked at ' . number_format((int)($out['db_looks'] ?? 0)) . ' times in the last ' . $out['db_span_min'] . ' minutes. This query was running in ' . number_format($q['seen']) . ' of those looks: ' . $q['share'] . '% of all database work seen, about ' . $q['cores'] . ' of a CPU core all the time' . ($q['max_s'] >= 2 ? ', longest run seen ' . $q['max_s'] . ' s' : '') . '. Find where the HRMS runs it (numbers are shown as N, texts as ?), explain in plain words why it is expensive, and make it cheaper (a missing index, a smaller date range, reading it once and keeping the answer for a short time, or running it less often).' . $rule . ' The query: ' . $q['q']]; continue; }
        $adv[] = ['lvl' => $q['share'] >= 25 ? 'bad' : 'warn', 't' => 'Database query #' . ($i + 1) . ' takes ' . $q['share'] . '% of all database time (' . number_format($q['calls']) . ' runs, ' . $q['avg_ms'] . ' ms each, ' . number_format($q['rows_each']) . ' rows read per run). It keeps about ' . $q['cores'] . ' of a CPU core busy.',
            'd' => mb_substr($q['q'], 0, 140) . '…',
            'prompt' => $ctx . ' This one database query used ' . $q['share'] . '% of all database time in the last ' . $out['db_span_min'] . ' minutes: ' . number_format($q['calls']) . ' runs, ' . $q['avg_ms'] . ' ms each, about ' . number_format($q['rows_each']) . ' rows read per run. Find where the HRMS runs it, explain in plain words why it is expensive, and make it cheaper (a missing index, a smaller date range, reading it once and keeping the answer for a short time, or running it less often).' . $rule . ' The query: ' . $q['q']];
    }
    foreach (array_slice($rows, 0, 5) as $r) {
        if ($r['time_share'] < 8) continue;
        $many = $r['per_min'] >= 40 && $r['avg_ms'] < 400;
        $do = ($r['action'] === 'wa.camp_fast_tick' || $r['action'] === 'wa.dq_worker' || $r['action'] === 'wa.camp_tick') ? 'This comes from Campaigns pages left open in a browser. Close Campaigns tabs nobody is watching: the server jobs send the campaigns by themselves.'
            : ($many ? 'Each call is quick, but there are very many: every open screen asks again and again.' : ($r['avg_ms'] >= 1500 ? 'Each call is slow: most of that time it waits for the database (or for WhatsApp / Shopify to answer).' : 'It adds up over many calls.'));
        $adv[] = ['lvl' => $r['time_share'] >= 25 ? 'bad' : 'warn', 't' => '"' . $r['name'] . '" takes ' . $r['time_share'] . '% of all the time the HRMS spent in the last ' . $out['span_min'] . ' minutes (' . number_format($r['calls']) . ' calls, ' . $r['per_min'] . ' a minute, ' . ($r['avg_ms'] >= 1000 ? round($r['avg_ms'] / 1000, 1) . ' s' : $r['avg_ms'] . ' ms') . ' each).',
            'd' => $do . ($r['why'] !== '' ? ' (' . $r['why'] . ')' : ''),
            'prompt' => $ctx . ' The HRMS function "' . $r['action'] . '" (' . $r['name'] . ') took ' . $r['time_share'] . '% of all request time in the last ' . $out['span_min'] . ' minutes: ' . number_format($r['calls']) . ' calls (' . $r['per_min'] . ' a minute), ' . $r['avg_ms'] . ' ms each on average, slowest ' . $r['max_ms'] . ' ms, of which only ' . $r['cpu_s'] . ' s in total was PHP itself and ' . $r['wait_s'] . ' s was waiting (database or outside services). '
                . ($many ? 'It is called very often. Make the pages ask for it less often (slower refresh, stop asking when the tab is hidden, or keep the answer for a few seconds on the server) and make each call lighter.' : 'Find which database queries or outside calls inside it take the time and make them cheaper; if it is a background job, make it do less work per run or run the heavy part less often.') . $rule];
    }
    if ($out['db_running'] && $out['db_running'][0]['secs'] >= 20) $adv[] = ['lvl' => 'bad', 't' => 'A database query has been running for ' . $out['db_running'][0]['secs'] . ' seconds right now.', 'd' => mb_substr($out['db_running'][0]['q'], 0, 140) . '…',
        'prompt' => $ctx . ' This database query has been running for ' . $out['db_running'][0]['secs'] . ' seconds on the live server and keeps one CPU core busy. Find where the HRMS runs it and make it fast (index, smaller range, limit).' . $rule . ' The query: ' . $out['db_running'][0]['q']];
    // one prompt with the whole picture
    $full = $ctx . ' Bring the CPU down. The biggest costs right now:';
    foreach (array_slice($out['db_recent'], 0, 5) as $i => $q) $full .= "\n" . 'Database query ' . ($i + 1) . ': ' . $q['share'] . '% of database work, about ' . $q['cores'] . ' CPU core busy' . (($out['db_mode'] ?? '') === 'looks' ? ($q['max_s'] >= 2 ? ', longest run seen ' . $q['max_s'] . ' s' : '') : ', ' . number_format($q['calls']) . ' runs, ' . $q['avg_ms'] . ' ms each, ' . number_format($q['rows_each']) . ' rows per run') . ' — ' . mb_substr($q['q'], 0, 260);
    foreach ($out['engine'] as $e) foreach (array_slice($e['steps'], 0, 4) as $st) if ($st['secs'] >= 5) $full .= "\n" . 'WhatsApp engine part "' . $st['step'] . '" took ' . $st['secs'] . ' s on its last run (the engine runs every minute)';
    foreach (array_slice($rows, 0, 6) as $r) $full .= "\n" . 'Function ' . $r['action'] . ': ' . $r['time_share'] . '% of request time, ' . number_format($r['calls']) . ' calls (' . $r['per_min'] . '/min), ' . $r['avg_ms'] . ' ms each, PHP ' . $r['cpu_s'] . ' s, waiting ' . $r['wait_s'] . ' s';
    $full .= "\n" . 'Work through them from the most expensive, one at a time.' . $rule;
    $out['full_prompt'] = $full;
    $out['advice'] = $adv;
    return $out;
}

/** sysload.* actions (Admin only). api.php calls this for that prefix. */
function sysload_route(PDO $pdo, string $action): void
{
    $auth = require_auth();
    if (($auth['role'] ?? '') !== 'Admin') json_response(['error' => 'Only Admin can see the server load.'], 403);
    if ($action === 'sysload.view') { @set_time_limit(40); json_response(sysload_view($pdo, max(15, min(180, (int)($_GET['mins'] ?? 60))))); }
    json_response(['error' => 'Unknown action.'], 404);
}

}
