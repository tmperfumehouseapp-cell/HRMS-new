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
    $read = function () { $l = @file('/proc/stat'); if (!$l) return null; $p = preg_split('/\s+/', trim($l[0])); array_shift($p); $p = array_map('floatval', $p); return ['idle' => ($p[3] ?? 0) + ($p[4] ?? 0), 'all' => array_sum($p)]; };
    $a = $read(); if (!$a) return ['pct' => null]; usleep(350000); $b = $read(); if (!$b || $b['all'] <= $a['all']) return ['pct' => null];
    return ['pct' => round(100 * (1 - ($b['idle'] - $a['idle']) / ($b['all'] - $a['all'])), 1)];
}
function sysload_cores(): int { $c = @file_get_contents('/proc/cpuinfo'); $n = $c !== false ? preg_match_all('/^processor\s*:/m', $c) : 0; return max(1, (int)$n); }

/** The whole picture for the page. $mins = how far back the request lines are read (15–180). */
function sysload_view(PDO $pdo, int $mins): array
{
    $cores = sysload_cores(); $now = time(); $from = intdiv($now, 60) - $mins;
    $ld = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
    $mem = null; $mi = @file_get_contents('/proc/meminfo'); if ($mi !== false && preg_match('/MemTotal:\s+(\d+)/', $mi, $a) && preg_match('/MemAvailable:\s+(\d+)/', $mi, $b)) $mem = ['total_mb' => (int)round($a[1] / 1024), 'free_mb' => (int)round($b[1] / 1024)];
    $out = ['now' => date('Y-m-d H:i:s'), 'mins' => $mins, 'cores' => $cores, 'cpu' => sysload_cpu_sample()['pct'], 'load' => $ld ? array_map(fn($v) => round((float)$v, 2), $ld) : null, 'mem' => $mem];

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
            'cpu_s' => round($a['cpu'] / 1000, 1), 'wall_s' => round($a['wall'] / 1000, 1), 'cpu_share' => $total['cpu'] > 0 ? round(100 * $a['cpu'] / $total['cpu'], 1) : 0, 'of_server' => $span ? round(100 * ($a['cpu'] / 1000) / ($span * $cores), 2) : 0];
    }
    usort($rows, fn($x, $y) => $y['cpu_s'] <=> $x['cpu_s']);
    $out['span_min'] = (int)round($span / 60); $out['requests'] = $total['n']; $out['php_of_server'] = $span ? round(100 * ($total['cpu'] / 1000) / ($span * $cores), 1) : null;
    $out['actions'] = array_slice($rows, 0, 40);
    $slow = $rows; usort($slow, fn($x, $y) => $y['avg_ms'] <=> $x['avg_ms']); $out['slow'] = array_slice(array_values(array_filter($slow, fn($r) => $r['calls'] >= 3 && $r['avg_ms'] >= 800)), 0, 12);

    // the database: what runs right now, and which kinds of query took the most time since it was started
    $run = []; $dig = [];
    try { $me = (int)$pdo->query("SELECT CONNECTION_ID()")->fetchColumn(); foreach ($pdo->query("SHOW FULL PROCESSLIST")->fetchAll(PDO::FETCH_ASSOC) as $x) { if ((int)$x['Id'] === $me || (string)$x['Command'] !== 'Query' || (string)$x['Info'] === '') continue; $run[] = ['secs' => (int)$x['Time'], 'q' => mb_substr(preg_replace('/\s+/', ' ', (string)$x['Info']), 0, 160)]; } usort($run, fn($a, $b) => $b['secs'] <=> $a['secs']); } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT COUNT_STAR c, ROUND(SUM_TIMER_WAIT / 1e12) s, ROUND(SUM_ROWS_EXAMINED / 1e6, 1) mr, LEFT(DIGEST_TEXT, 170) q FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME = DATABASE() ORDER BY SUM_TIMER_WAIT DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) as $x) $dig[] = ['secs' => (int)$x['s'], 'calls' => (int)$x['c'], 'mrows' => (float)$x['mr'], 'q' => (string)$x['q']]; } catch (Throwable $e) { }
    $out['db_running'] = array_slice($run, 0, 10); $out['db_top'] = $dig;

    // the WhatsApp engine: how long each of its parts took on its last run
    $eng = [];
    foreach (['wa_cron_steps' => 'Engine run', 'wa_tick_steps' => 'Engine · the small jobs'] as $k => $t) { try { $j = json_decode((string)setting_get($pdo, $k, ''), true); if (is_array($j) && !empty($j['s'])) { $s = []; foreach ($j['s'] as $n => $v) if (is_numeric($v) && (float)$v >= 0.5) $s[] = ['step' => (string)$n, 'secs' => round((float)$v, 1)]; usort($s, fn($a, $b) => $b['secs'] <=> $a['secs']); $eng[] = ['title' => $t, 'at' => (string)($j['at'] ?? ''), 'steps' => array_slice($s, 0, 10)]; } } catch (Throwable $e) { } }
    $out['engine'] = $eng;

    // what to do: plain advice from the numbers above
    $adv = [];
    if ($out['cpu'] !== null) $adv[] = ['lvl' => $out['cpu'] >= 80 ? 'bad' : ($out['cpu'] >= 50 ? 'warn' : 'ok'), 't' => 'The server is using ' . $out['cpu'] . '% of its ' . $cores . ' CPU core' . ($cores === 1 ? '' : 's') . ' right now.', 'd' => $out['cpu'] >= 80 ? 'This is high: pages will feel slow. Start with the first rows of "What uses the CPU" below.' : ($out['cpu'] >= 50 ? 'Busy but working. The first rows below show where to save.' : 'There is room to spare.')];
    if (!$span) $adv[] = ['lvl' => 'info', 't' => 'Measuring has just started.', 'd' => 'This page counts every request from now on. Open it again in 10 to 15 minutes to see which functions use the CPU.'];
    foreach (array_slice($rows, 0, 6) as $r) {
        if ($r['cpu_share'] < 8) continue;
        $what = '"' . $r['name'] . '" uses ' . $r['cpu_share'] . '% of everything PHP did in the last ' . $out['span_min'] . ' minutes (' . number_format($r['calls']) . ' calls, about ' . $r['per_min'] . ' a minute, ' . $r['avg_ms'] . ' ms each).';
        if ($r['action'] === 'wa.camp_fast_tick' || $r['action'] === 'wa.dq_worker' || $r['action'] === 'wa.camp_tick') $do = 'This comes from Campaigns pages left open in a browser. Close Campaigns tabs that nobody is watching: the server jobs send the campaigns by themselves every minute.';
        elseif ($r['per_min'] >= 40 && $r['avg_ms'] < 400) $do = 'Each call is quick, but there are very many: every open screen asks again and again. Fewer open tabs lowers it at once; to lower it for good, ask Claude to "make ' . $r['action'] . ' refresh less often".';
        elseif ($r['avg_ms'] >= 1500) $do = 'Each call is slow. Ask Claude to "make ' . $r['action'] . ' faster" and show this row.';
        elseif (in_array($r['action'], ['wa.cron', 'wa.cron_send', 'bd.tick', 'dp.tick', 'chk.tick', 'orders.cron', 'lib.cron', 'reminders.tick'], true)) $do = 'This is a server job that runs by itself. See "The WhatsApp engine" below for the part that takes the time, and show it to Claude.';
        else $do = 'Ask Claude to look at "' . $r['action'] . '" and show this row.';
        $adv[] = ['lvl' => $r['cpu_share'] >= 25 ? 'bad' : 'warn', 't' => $what, 'd' => $do . ($r['why'] !== '' ? ' (' . $r['why'] . ')' : '')];
    }
    foreach ($out['groups'] as $g) if ($g['name'] === 'Database (MySQL)' && $g['of_all'] >= 25) $adv[] = ['lvl' => 'warn', 't' => 'The database is using ' . $g['of_all'] . '% of the server by itself.', 'd' => 'See "The database" below: the first rows are the queries that cost the most. Show them to Claude.'];
    if ($out['db_running'] && $out['db_running'][0]['secs'] >= 20) $adv[] = ['lvl' => 'bad', 't' => 'A database query has been running for ' . $out['db_running'][0]['secs'] . ' seconds.', 'd' => 'A query this long keeps one CPU core busy by itself. It is the first row under "Running right now": show it to Claude.'];
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
