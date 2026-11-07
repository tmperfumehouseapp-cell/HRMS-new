<?php
// help_lib.php — the engine of the Help page ("system doctor").
// Read-only watcher: it measures things, stores the numbers, and explains them. It never changes your system.
date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/help_cfg.php';

const HLP_VERSION = '1.0';
const HLP_RETENTION_DAYS = 8;

/* ───────────────────────── storage (own SQLite file: keeps working even when MySQL is down) ───────────────────────── */
function hlp_dir(): string {
    $d = __DIR__ . '/help_data';
    if (!is_dir($d)) { @mkdir($d, 0755, true); }
    if (is_dir($d) && !file_exists($d . '/.htaccess')) {
        @file_put_contents($d . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        @file_put_contents($d . '/index.html', '');
    }
    return $d;
}
function hlp_store(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('PHP extension pdo_sqlite is missing (ask Hostinger support to enable it).');
    $pdo = new PDO('sqlite:' . hlp_dir() . '/help_' . substr(md5(HELP_KEY . 'tmph'), 0, 12) . '.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=4000; PRAGMA synchronous=NORMAL;');
    $pdo->exec("CREATE TABLE IF NOT EXISTS hlp_checks (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, kind TEXT NOT NULL, name TEXT NOT NULL, ok INTEGER NOT NULL, ms INTEGER NOT NULL DEFAULT 0, detail TEXT)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS hlp_checks_k ON hlp_checks(kind, ts)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS hlp_checks_n ON hlp_checks(name, ts)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hlp_kv (k TEXT PRIMARY KEY, v TEXT, ts INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hlp_incidents (id INTEGER PRIMARY KEY AUTOINCREMENT, started INTEGER, ended INTEGER, area TEXT, title TEXT, detail TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hlp_files (path TEXT PRIMARY KEY, mtime INTEGER, size INTEGER, first_seen INTEGER, changed_at INTEGER, state TEXT, note TEXT)");
    return $pdo;
}
function hlp_log(string $kind, string $name, bool $ok, int $ms = 0, string $detail = '', ?int $ts = null): void {
    try {
        hlp_store()->prepare('INSERT INTO hlp_checks (ts,kind,name,ok,ms,detail) VALUES (?,?,?,?,?,?)')
            ->execute([$ts ?? time(), $kind, $name, $ok ? 1 : 0, $ms, mb_substr($detail, 0, 240)]);
    } catch (Throwable $e) { /* never break the checker */ }
}
function hlp_kv_get(string $k, $default = null) {
    try {
        $s = hlp_store()->prepare('SELECT v FROM hlp_kv WHERE k=?'); $s->execute([$k]); $v = $s->fetchColumn();
        return $v === false ? $default : json_decode($v, true);
    } catch (Throwable $e) { return $default; }
}
function hlp_kv_set(string $k, $v): void {
    try { hlp_store()->prepare('INSERT INTO hlp_kv (k,v,ts) VALUES (?,?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, ts=excluded.ts')->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE), time()]); }
    catch (Throwable $e) {}
}

/* ───────────────────────── small helpers ───────────────────────── */
function hlp_http(string $url, array $o = []): array {
    $t0 = microtime(true);
    $ch = curl_init($url);
    $h = $o['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $o['timeout'] ?? 8, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => !empty($o['follow']), CURLOPT_MAXREDIRS => 3, CURLOPT_HTTPHEADER => $h, CURLOPT_USERAGENT => 'TMPH-HelpDoctor/' . HLP_VERSION,
        CURLOPT_NOBODY => !empty($o['head']),
    ]);
    if (isset($o['post'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $o['post']); }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);
    return ['code' => $code, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'body' => is_string($body) ? substr($body, 0, 6000) : '', 'err' => $err];
}
function hlp_median(array $a): ?float { if (!$a) return null; sort($a); $n = count($a); return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2; }
function hlp_pct(array $a, float $p): ?float { if (!$a) return null; sort($a); return $a[min(count($a) - 1, (int)floor($p * (count($a) - 1)))]; }
function hlp_clamp($v, $lo = 0, $hi = 100) { return max($lo, min($hi, $v)); }
function hlp_interp(float $x, array $pts): float { // pts = [[x,score],...] ascending x
    if ($x <= $pts[0][0]) return $pts[0][1];
    for ($i = 1; $i < count($pts); $i++) if ($x <= $pts[$i][0]) { [$x0, $y0] = $pts[$i - 1]; [$x1, $y1] = $pts[$i]; return $y0 + ($y1 - $y0) * (($x - $x0) / ($x1 - $x0)); }
    return $pts[count($pts) - 1][1];
}
function hlp_grade(?float $s): string { if ($s === null) return '–'; return $s >= 90 ? 'A' : ($s >= 75 ? 'B' : ($s >= 60 ? 'C' : ($s >= 40 ? 'D' : 'F'))); }
function hlp_root(): string { return rtrim(HELP_WEB_ROOT !== '' ? HELP_WEB_ROOT : dirname(__DIR__), '/'); }
function hlp_api_url(): string { return rtrim(HELP_BASE, '/') . HELP_API_PATH; }

/* ───────────────────────── find database login (config.php / api.php) ───────────────────────── */
function hlp_scan_creds(string $txt): array {
    $sets = []; $matches = [];
    $keymap = function ($k) {
        $k = strtolower(preg_replace('/[^a-z]/i', '', $k));
        if (in_array($k, ['dbhost', 'host', 'databasehost'])) return 'host';
        if (in_array($k, ['dbname', 'dbdatabase', 'dbname', 'database', 'databasename'])) return 'name';
        if (in_array($k, ['dbuser', 'dbusername', 'username', 'user', 'databaseuser'])) return 'user';
        if (in_array($k, ['dbpass', 'dbpassword', 'password', 'pass', 'databasepassword'])) return 'pass';
        return null;
    };
    $pats = [
        '/define\(\s*[\'"](DB_[A-Z_]+)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/i',
        '/\$([A-Za-z_]*db[A-Za-z_]*|dbname|host)\s*=\s*[\'"]([^\'"]*)[\'"]/i',
        '/[\'"]([A-Za-z_]*(?:host|dbname|database|username|user|password|pass)[A-Za-z_]*)[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/i',
        '/const\s+(DB_[A-Z_]+)\s*=\s*[\'"]([^\'"]*)[\'"]/i',
    ];
    foreach ($pats as $p) if (preg_match_all($p, $txt, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($m as $x) { $k = $keymap($x[1][0]); if ($k) $matches[] = ['o' => $x[0][1], 'k' => $k, 'v' => $x[2][0]]; }
    }
    if (preg_match_all('/mysql:host=([^;\'"]+);\s*dbname=([^;\'"]+)/i', $txt, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($m as $x) { $matches[] = ['o' => $x[0][1], 'k' => 'host', 'v' => $x[1][0]]; $matches[] = ['o' => $x[0][1] + 1, 'k' => 'name', 'v' => $x[2][0]]; }
    }
    usort($matches, fn($a, $b) => $a['o'] <=> $b['o']);
    $cur = null; $last = -9999;
    foreach ($matches as $x) {
        if ($cur === null || $x['o'] - $last > 700) { if ($cur) $sets[] = $cur; $cur = []; }
        if (!isset($cur[$x['k']])) $cur[$x['k']] = $x['v'];
        $last = $x['o'];
    }
    if ($cur) $sets[] = $cur;
    return array_values(array_filter($sets, fn($s) => !empty($s['name']) && !empty($s['user']) && isset($s['pass'])));
}
function hlp_db_creds(): ?array {
    static $c = false;
    if ($c !== false) return $c;
    if (HELP_DB_NAME !== '' && HELP_DB_USER !== '') return $c = ['host' => HELP_DB_HOST ?: 'localhost', 'name' => HELP_DB_NAME, 'user' => HELP_DB_USER, 'pass' => HELP_DB_PASS, 'src' => 'help_cfg.php'];
    $cached = hlp_kv_get('db_creds_src');
    foreach (['config.php', 'api.php', 'db.php', 'includes/db.php'] as $f) {
        $p = __DIR__ . '/' . $f;
        if (!is_file($p) || filesize($p) > 4000000) continue;
        $sets = hlp_scan_creds((string)@file_get_contents($p));
        if (!$sets) continue;
        usort($sets, function ($a, $b) { $sa = stripos($a['name'], 'emp_management') !== false ? 0 : 1; $sb = stripos($b['name'], 'emp_management') !== false ? 0 : 1; return $sa <=> $sb; });
        $s = $sets[0];
        return $c = ['host' => $s['host'] ?? 'localhost', 'name' => $s['name'], 'user' => $s['user'], 'pass' => $s['pass'], 'src' => $f];
    }
    return $c = null;
}
function hlp_mysql(int $timeout = 4): PDO {
    $c = hlp_db_creds();
    if (!$c) throw new RuntimeException('Help can\'t find the database login (type it in help_cfg.php)');
    $h = trim((string)$c['host']);
    // Hostinger: "localhost" means a socket that some PHP contexts may not use ("Operation not permitted"); your HRMS uses 127.0.0.1, so try that first.
    $hosts = ($h === '' || strtolower($h) === 'localhost') ? ['127.0.0.1', 'localhost'] : [$h];
    $ok = hlp_kv_get('db_host_ok'); if ($ok && in_array($ok, $hosts, true)) $hosts = array_values(array_unique(array_merge([$ok], $hosts)));
    $err = null;
    foreach ($hosts as $host) {
        try {
            $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $c['name'] . ';charset=utf8mb4', $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => $timeout, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            if ($ok !== $host) hlp_kv_set('db_host_ok', $host);
            return $pdo;
        } catch (Throwable $e) { $err = $e; }
    }
    throw $err;
}

/* ───────────────────────── the checks ───────────────────────── */
function hlp_check_db(): array {
    $t0 = microtime(true); $info = [];
    try {
        // Same patience as the HRMS itself (api.php retries the database too): up to 4 tries with a short wait before calling it a failure.
        $pdo = null; $lastE = null;
        for ($try = 0; $try < 4 && !$pdo; $try++) {
            if ($try) usleep($try * 400000 + mt_rand(0, 150000));
            try { $pdo = hlp_mysql(); } catch (Throwable $e1) { $lastE = $e1; }
        }
        if (!$pdo) throw $lastE;
        $pdo->query('SELECT 1')->fetchColumn();
        $ms = (int)round((microtime(true) - $t0) * 1000);
        try {
            foreach ($pdo->query("SHOW STATUS WHERE Variable_name IN ('Threads_connected','Max_used_connections','Aborted_connects','Uptime','Threads_running')") as $r) $info[strtolower($r['Variable_name'])] = (int)$r['Value'];
            foreach ($pdo->query("SHOW VARIABLES WHERE Variable_name IN ('max_connections','max_user_connections')") as $r) $info[strtolower($r['Variable_name'])] = (int)$r['Value'];
            $q = $pdo->query("SELECT COUNT(*) c, ROUND(SUM(data_length+index_length)/1048576,1) mb FROM information_schema.TABLES WHERE table_schema=DATABASE()")->fetch();
            $info['tables'] = (int)$q['c']; $info['size_mb'] = (float)$q['mb'];
            $info['name'] = hlp_db_creds()['name'];
        } catch (Throwable $e) {}
        hlp_log('db', 'connect', true, $ms, '');
        hlp_kv_set('db_info', $info + ['ms' => $ms, 'at' => time()]);
        // freshness watches
        $fresh = [];
        foreach (HELP_FRESHNESS as $w) {
            try {
                $table = (string)$w[1];
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) continue;
                $u = false;
                // InnoDB leaves information_schema UPDATE_TIME hours behind real writes, so a table that is filling looks stale.
                // The 4th item of a watch is a timestamp column. shop_orders uses synced_at. The allowed age (3rd item) is unchanged.
                $col = isset($w[3]) ? (string)$w[3] : ($table === 'shop_orders' ? 'synced_at' : '');
                if ($col !== '' && preg_match('/^[A-Za-z0-9_]+$/', $col)) {
                    try { $u = $pdo->query("SELECT MAX(`$col`) FROM `$table`")->fetchColumn(); } catch (Throwable $e2) { $u = false; }
                }
                if (!$u) {
                    $s = $pdo->prepare("SELECT UPDATE_TIME FROM information_schema.TABLES WHERE table_schema=DATABASE() AND table_name=?");
                    $s->execute([$table]); $u = $s->fetchColumn();
                }
                if ($u) $fresh[] = ['label' => $w[0], 'table' => $table, 'age_min' => (int)round((time() - strtotime((string)$u)) / 60), 'max' => (int)$w[2]];
            } catch (Throwable $e) {}
        }
        hlp_kv_set('freshness', $fresh);
        return ['ok' => true, 'ms' => $ms];
    } catch (Throwable $e) {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        hlp_log('db', 'connect', false, $ms, $e->getMessage());
        return ['ok' => false, 'ms' => $ms, 'err' => $e->getMessage()];
    }
}
function hlp_backend_probe(): array { // a harmless request with a fake login: the backend must reach its database just to say "no"
    $r = hlp_http(hlp_api_url() . '?action=me.flags&_t=' . time(), ['headers' => ['Authorization: Bearer help-check-not-a-real-token'], 'timeout' => 8]);
    $json = (strlen($r['body']) && $r['body'][0] === '{');
    $dbfail = stripos($r['body'], 'Database connection failed') !== false || stripos($r['body'], '2002') !== false;
    $ok = !$r['err'] && in_array($r['code'], [200, 400, 401, 403], true) && $json && !$dbfail;
    $detail = $r['err'] ?: ($dbfail ? 'Database connection failed (2002)' : 'HTTP ' . $r['code']);
    return ['ok' => $ok, 'ms' => $r['ms'], 'detail' => $detail, 'code' => $r['code']];
}
function hlp_check_backend(): array {
    $r = hlp_backend_probe();
    hlp_log('backend', 'api.php', $r['ok'], $r['ms'], $r['detail']);
    return $r;
}
function hlp_check_burst(int $n = 8): array {
    $url = hlp_api_url() . '?action=me.flags&_t=' . time(); $mh = curl_multi_init(); $hs = [];
    for ($i = 0; $i < $n; $i++) {
        $ch = curl_init($url . $i);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['Authorization: Bearer help-check-not-a-real-token'], CURLOPT_USERAGENT => 'TMPH-HelpDoctor/' . HLP_VERSION]);
        curl_multi_add_handle($mh, $ch); $hs[] = $ch;
    }
    $t0 = microtime(true); do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 0.5); } while ($run && $st === CURLM_OK && microtime(true) - $t0 < 14);
    $okn = 0;
    foreach ($hs as $ch) {
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $body = (string)curl_multi_getcontent($ch);
        $ok = in_array($code, [200, 400, 401, 403], true) && ($body !== '' && $body[0] === '{') && stripos($body, 'Database connection failed') === false;
        $ms = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        hlp_log('burst', 'parallel-' . $n, $ok, $ms, $ok ? '' : ('HTTP ' . $code . ' ' . substr(preg_replace('/\s+/', ' ', $body), 0, 80)));
        $okn += $ok ? 1 : 0; curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return ['ok' => $okn, 'n' => $n];
}
function hlp_check_pos(): array {
    // Same address style the page sweep uses (HELP_BASE) so a www / non-www redirect or an old address in help_cfg.php can't make the POS look down.
    $urls = [rtrim(HELP_BASE, '/') . '/tmpos/login.php'];
    if (defined('HELP_POS_URL') && HELP_POS_URL && !in_array(HELP_POS_URL, $urls, true)) $urls[] = HELP_POS_URL;
    $urls[] = 'https://fourpirates.space/tmpos/login.php';
    $ok = false; $r = ['ms' => 0, 'err' => '', 'code' => 0, 'body' => '']; $why = '';
    foreach (array_unique($urls) as $u) {
        $r = hlp_http($u, ['timeout' => 8, 'follow' => true]);
        $busy = stripos($r['body'], 'Server is busy') !== false || stripos($r['body'], 'Database connection failed') !== false;
        $ok = !$r['err'] && $r['code'] === 200 && !$busy && strlen($r['body']) > 300;
        $why = $r['err'] ?: ($busy ? 'POS says server busy' : 'HTTP ' . $r['code'] . ($r['code'] === 200 ? ' but only ' . strlen($r['body']) . ' bytes' : ''));
        if ($ok) break;
        $why .= ' @ ' . preg_replace('~^https?://~', '', $u);
    }
    hlp_log('pos', 'login page', $ok, $r['ms'], $why);
    return ['ok' => $ok, 'ms' => $r['ms'], 'detail' => $why];
}
/** Reads your menu file (assets/js/layout.js) so Help always knows every page, even new ones. */
function hlp_pages(): array {
    $f = hlp_root() . '/assets/js/layout.js'; $pages = [];
    $js = is_file($f) ? (string)@file_get_contents($f) : '';
    if ($js !== '' && preg_match_all('/\[\s*[\'"]([a-z_0-9]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([a-zA-Z_0-9\-]+\.html)[\'"]/', $js, $tabs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        // workspace headers (key:'x', label:'Y') — a tab belongs to the nearest header above it
        $heads = [];
        if (preg_match_all('/key:\s*[\'"]([a-z_0-9]+)[\'"]\s*,\s*label:\s*[\'"]([^\'"]+)[\'"]/', $js, $hm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($hm as $h) $heads[] = [$h[0][1], $h[1][0], $h[2][0]];
        }
        foreach ($tabs as $t) {
            $off = $t[0][1]; $ws = ''; $grp = 'HRMS';
            foreach ($heads as $h) { if ($h[0] < $off) { $ws = $h[1]; $grp = $h[2]; } else break; }
            $file = $t[3][0];
            if (!isset($pages[$file])) $pages[$file] = ['ws' => $ws, 'tab' => $t[1][0], 'label' => $t[2][0], 'file' => $file, 'group' => $grp];
        }
    }
    if (!isset($pages['sales']) && is_file(hlp_root() . '/sales.html')) $pages['sales'] = ['ws' => 'sales', 'tab' => '', 'label' => 'Sales', 'file' => 'sales.html', 'group' => 'Sales'];
    return array_values($pages);
}
function hlp_check_pages(): array {
    $res = []; $bad = 0;
    foreach (hlp_pages() as $p) {
        $r = hlp_http(rtrim(HELP_BASE, '/') . '/' . $p['file'], ['timeout' => 8]);
        $err = stripos($r['body'], 'Fatal error') !== false || stripos($r['body'], 'Parse error') !== false;
        $ok = !$r['err'] && $r['code'] === 200 && strlen($r['body']) > 150 && !$err;
        hlp_log('page', $p['file'], $ok, $r['ms'], $r['err'] ?: ($err ? 'PHP error text in page' : 'HTTP ' . $r['code']));
        $res[$p['file']] = ['ok' => $ok, 'ms' => $r['ms']]; if (!$ok) $bad++;
        usleep(120000);
    }
    return ['n' => count($res), 'bad' => $bad];
}
function hlp_check_ssl(): void {
    $out = [];
    foreach (HELP_SSL_HOSTS as $h) {
        $days = null;
        try {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
            $s = @stream_socket_client('ssl://' . $h . ':443', $en, $es, 6, STREAM_CLIENT_CONNECT, $ctx);
            if ($s) { $p = stream_context_get_params($s); $c = openssl_x509_parse($p['options']['ssl']['peer_certificate']); $days = (int)floor(($c['validTo_time_t'] - time()) / 86400); fclose($s); }
        } catch (Throwable $e) {}
        $out[$h] = $days;
        hlp_log('ssl', $h, $days !== null && $days > 7, 0, $days === null ? 'could not read certificate' : $days . ' days left');
    }
    hlp_kv_set('ssl', $out);
}
function hlp_check_server(): array {
    $root = hlp_root(); $free = @disk_free_space($root); $total = @disk_total_space($root); $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
    $tmp = hlp_dir() . '/.w' . mt_rand(); $w = @file_put_contents($tmp, 'x') !== false; if ($w) @unlink($tmp);
    $s = [
        'php' => PHP_VERSION, 'memory' => ini_get('memory_limit'), 'tz' => date_default_timezone_get(), 'time' => date('c'),
        'disk_free_pct' => ($free && $total) ? round($free / $total * 100, 1) : null,
        'disk_free_gb' => $free ? round($free / 1073741824, 1) : null,
        'load1' => $load ? round($load[0], 2) : null,
        'writable' => $w, 'ext' => [],
        'maintenance' => is_file($root . '/MAINTENANCE_ON') || is_file(dirname($root) . '/MAINTENANCE_ON'),
    ];
    foreach (['pdo_mysql', 'curl', 'gd', 'zip', 'mbstring', 'openssl', 'fileinfo', 'pdo_sqlite'] as $e) $s['ext'][$e] = extension_loaded($e);
    hlp_kv_set('server', $s);
    return $s;
}
/** Counts error lines per hour in the server's error_log files. */
function hlp_check_errorlog(): void {
    $hours = array_fill(0, 24, 0); $dbfail = 0; $fatal = 0; $found = false; $now = time();
    foreach ([hlp_root() . '/error_log', hlp_root() . '/empmanagment/error_log', __DIR__ . '/error_log', dirname(hlp_root()) . '/error_log'] as $f) {
        if (!is_file($f) || !is_readable($f)) continue;
        $found = true; $sz = filesize($f); $fh = fopen($f, 'r'); if (!$fh) continue;
        if ($sz > 600000) fseek($fh, -600000, SEEK_END);
        $txt = stream_get_contents($fh); fclose($fh);
        foreach (explode("\n", $txt) as $line) {
            if (!preg_match('/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([A-Za-z\/_+\-]+))?\]/', $line, $m)) continue;
            $t = strtotime($m[1] . ' ' . ($m[2] ?? '')); if (!$t || $now - $t > 86400 || $t > $now + 60) continue;
            $hours[23 - (int)floor(($now - $t) / 3600)]++;
            if (stripos($line, 'Fatal') !== false) $fatal++;
            if (stripos($line, 'connection') !== false || stripos($line, '2002') !== false) $dbfail++;
        }
    }
    hlp_kv_set('errorlog', ['found' => $found, 'hours' => $hours, 'total' => array_sum($hours), 'fatal' => $fatal, 'dbfail' => $dbfail, 'at' => $now]);
}
/** Warnings about files that should not be on a live website. */
function hlp_check_security(): void {
    $root = hlp_root(); $warn = [];
    $deny = ['/^.*install.*\.php$/i' => ['Installer file', 50], '/^(phpinfo|info|test|db_test|debug|adminer|shell)\.php$/i' => ['Test/debug file', 15], '/\.(sql|bak|old|orig|zip|tar|gz|rar)$/i' => ['Backup or data file', 15], '/^\.env$/i' => ['.env file', 20]];
    $stack = [[$root, 0]]; $seen = 0;
    while ($stack && $seen < 4000) {
        [$dir, $d] = array_pop($stack);
        foreach (@scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f; $seen++;
            if (is_dir($p)) { if ($d < 2 && !in_array($f, ['uploads', 'node_modules', 'vendor', 'help_data', 'cache', 'icons', 'assets'], true)) $stack[] = [$p, $d + 1]; continue; }
            foreach ($deny as $re => $info) if (preg_match($re, $f)) { $warn[] = ['file' => ltrim(substr($p, strlen($root)), '/'), 'what' => $info[0], 'pts' => $info[1], 'age_days' => (int)floor((time() - filemtime($p)) / 86400)]; break; }
        }
    }
    // A backup/data file only counts as a problem if the website would really hand it out. (PHP files are never requested: opening an installer would run it.)
    $keep = []; $checked = 0;
    foreach ($warn as $w) {
        $ext = strtolower(pathinfo($w['file'], PATHINFO_EXTENSION));
        if (in_array($ext, ['php', 'phtml', 'php5', 'php7', 'phar'], true)) { $w['exposed'] = null; $keep[] = $w; continue; }
        $ck = 'expo_' . md5($w['file']); $c = hlp_kv_get($ck, null);
        if (is_array($c) && time() - (int)$c['at'] < 3600 && empty($GLOBALS['hlp_force_security'])) $exposed = (bool)$c['v'];   // 'Recheck this' asks the website again
        elseif ($checked++ < 25) {
            $url = rtrim(HELP_BASE, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $w['file'])));
            $r = hlp_http($url, ['timeout' => 6, 'headers' => ['Range: bytes=0-0']]);
            $exposed = $r['err'] !== '' || in_array($r['code'], [200, 206], true);   // unknown (timeout) counts as exposed until proven blocked
            hlp_kv_set($ck, ['v' => $exposed, 'at' => time()]);
        } else $exposed = true;
        if ($exposed) { $w['exposed'] = true; $keep[] = $w; }
    }
    $warn = $keep;
    hlp_kv_set('security', ['warn' => $warn, 'at' => time()]);
}
/** Notices NEW and CHANGED files (your uploads) and checks that they are not broken. */
function hlp_check_files(): void {
    $root = hlp_root(); $pdo = hlp_store(); $now = time();
    $first = (int)$pdo->query('SELECT COUNT(*) FROM hlp_files')->fetchColumn() === 0;
    $exts = ['php', 'html', 'js', 'css', 'json']; $files = []; $stack = [[$root, 0]];
    while ($stack && count($files) < 7000) {
        [$dir, $d] = array_pop($stack);
        foreach (@scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            if (is_dir($p)) { if ($d < 4 && !in_array($f, ['uploads', 'node_modules', 'vendor', 'help_data', 'cache', 'icons', 'invoices', 'images', 'img', 'fonts'], true) && $f[0] !== '.') $stack[] = [$p, $d + 1]; continue; }
            if (!in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts, true)) continue;
            $files[ltrim(substr($p, strlen($root)), '/')] = [@filemtime($p) ?: 0, @filesize($p) ?: 0, $p];
        }
    }
    $known = []; foreach ($pdo->query('SELECT path,mtime,first_seen,changed_at,state,note FROM hlp_files') as $r) $known[$r['path']] = $r;
    $ins = $pdo->prepare('INSERT OR REPLACE INTO hlp_files (path,mtime,size,first_seen,changed_at,state,note) VALUES (?,?,?,?,?,?,?)');
    $pdo->beginTransaction();
    foreach ($files as $rel => [$mt, $sz, $abs]) {
        $k = $known[$rel] ?? null;
        if (!$k) { $state = $first ? 'base' : 'new'; $ins->execute([$rel, $mt, $sz, $first ? $mt : $now, $first ? $mt : $now, $state, '']); $k = ['state' => $state]; if ($first) continue; }
        elseif ((int)$k['mtime'] !== $mt) { $state = 'changed'; $ins->execute([$rel, $mt, $sz, $k['first_seen'], $now, $state, '']); $k['state'] = $state; }
        else continue;
        // check the file that just appeared / changed
        $note = 'OK';
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION)); $src = $sz < 3000000 ? (string)@file_get_contents($abs) : '';
        if ($ext === 'php' && $src !== '') { try { token_get_all($src, TOKEN_PARSE); } catch (Throwable $e) { $note = 'BROKEN: ' . mb_substr($e->getMessage(), 0, 120); } }
        elseif ($ext === 'json' && $src !== '') { json_decode($src); if (json_last_error()) $note = 'BROKEN: not valid JSON'; }
        elseif ($sz === 0) $note = 'EMPTY file';
        $pdo->prepare('UPDATE hlp_files SET note=? WHERE path=?')->execute([$note, $rel]);
    }
    $pdo->commit();
    $gone = array_diff(array_keys($known), array_keys($files)); if ($gone) { $d = $pdo->prepare('DELETE FROM hlp_files WHERE path=?'); foreach ($gone as $g) $d->execute([$g]); }
    hlp_kv_set('files_scan', ['at' => $now, 'count' => count($files)]);
}

/* ───────────────────────── scoring ───────────────────────── */
function hlp_rows(string $kind, int $since, ?string $name = null): array {
    $sql = 'SELECT ts,name,ok,ms,detail FROM hlp_checks WHERE kind=? AND ts>=?' . ($name ? ' AND name=?' : '') . ' ORDER BY ts';
    $s = hlp_store()->prepare($sql); $s->execute($name ? [$kind, $since, $name] : [$kind, $since]); return $s->fetchAll();
}
function hlp_stat(array $rows): array {
    $n = count($rows); if (!$n) return ['n' => 0, 'rate' => null, 'p50' => null, 'p95' => null];
    $ok = 0; $ms = []; foreach ($rows as $r) { $ok += (int)$r['ok']; if ($r['ok']) $ms[] = (int)$r['ms']; }
    return ['n' => $n, 'rate' => $ok / $n, 'p50' => hlp_median($ms), 'p95' => hlp_pct($ms, 0.95)];
}
function hlp_level(?float $rate, ?float $p50 = null, float $goodMs = 800): string {
    if ($rate === null) return 'unknown';
    if ($rate <= 0.0) return 'down';
    if ($rate < 0.90) return 'failing';
    if ($rate < 0.99 || ($p50 !== null && $p50 > $goodMs * 3)) return 'weak';
    return 'strong';
}
function hlp_rate_score(?float $r): ?float { return $r === null ? null : hlp_interp($r * 100, [[0, 0], [80, 20], [90, 40], [95, 60], [97, 75], [99, 90], [99.9, 100]]); }
function hlp_avg(array $a): ?float { $a = array_filter($a, fn($x) => $x !== null); return $a ? array_sum($a) / count($a) : null; }

function hlp_status(): array {
    $now = time(); $d1 = $now - 86400; $h1 = $now - 3600;
    $dbS = hlp_stat(hlp_rows('db', $d1)); $beS = hlp_stat(hlp_rows('backend', $d1)); $buS = hlp_stat(hlp_rows('burst', $d1)); $posS = hlp_stat(hlp_rows('pos', $now - 3 * 3600));   // POS is judged on the last 3 hours (old failures from before the fix no longer count)
    $buRecent = hlp_stat(hlp_rows('burst', $h1)); $beRecent = hlp_stat(hlp_rows('backend', $now - 1800));
    $dbInfo = hlp_kv_get('db_info', []); $server = hlp_kv_get('server', []); $sec = hlp_kv_get('security', ['warn' => []]); $elog = hlp_kv_get('errorlog', null);
    $ssl = hlp_kv_get('ssl', []); $fresh = hlp_kv_get('freshness', []); $ui = hlp_kv_get('ui_report', null);
    $lastCron = hlp_kv_get('last_cron', 0); $cronAge = $lastCron ? $now - $lastCron : null;

    // pages: latest sweep result per file
    $pageList = hlp_pages(); $pageRows = [];
    $latest = hlp_store()->prepare("SELECT name, ok, ms, detail, ts FROM hlp_checks WHERE kind='page' AND ts>=? ORDER BY ts"); $latest->execute([$now - 3 * 3600]);
    $byFile = []; foreach ($latest->fetchAll() as $r) $byFile[$r['name']] = $r;
    $uiByFile = []; if ($ui && !empty($ui['rows'])) foreach ($ui['rows'] as $r) $uiByFile[$r['file']] = $r;
    foreach ($pageList as $p) {
        $r = $byFile[$p['file']] ?? null; $u = $uiByFile[$p['file']] ?? null; $state = $r ? ($r['ok'] ? 'ok' : 'bad') : 'unknown'; $note = $r ? ($r['ok'] ? '' : $r['detail']) : 'not checked yet';
        if ($u && $state !== 'bad') { $state = $u['state'] === 'ok' ? 'ok' : ($u['state'] === 'bad' ? 'bad' : 'warn'); $note = implode(' · ', array_values(array_unique($u['issues'] ?? []))); }
        elseif ($r && $r['ok'] && $r['ms'] > 3000) { $state = 'warn'; $note = 'slow (' . round($r['ms'] / 1000, 1) . ' s)'; }
        $pageRows[] = $p + ['state' => $state, 'ms' => $r ? (int)$r['ms'] : null, 'note' => $note];
    }
    $pagesBad = count(array_filter($pageRows, fn($p) => $p['state'] === 'bad'));

    // services
    $connUse = null; $limit = $dbInfo['max_user_connections'] ?? 0; if (!$limit) $limit = $dbInfo['max_connections'] ?? 0;
    if (!empty($dbInfo['threads_connected']) && $limit) $connUse = $dbInfo['threads_connected'] / $limit;
    $dbLvl = hlp_level($dbS['rate'], $dbS['p50'], 150);
    if ($dbLvl === 'down' && $dbS['n'] < 3) $dbLvl = 'failing';   // too few checks yet to call it fully down
    $dbLast = array_slice(hlp_rows('db', $now - 600), -2);   // if the last checks all worked, an old failure no longer counts as an alarm
    if (count($dbLast) >= 2 && !count(array_filter($dbLast, fn($r) => !$r['ok'])) && in_array($dbLvl, ['down', 'failing'], true)) $dbLvl = 'weak';
    if ($dbLvl === 'strong' && $buRecent['rate'] !== null && $buRecent['rate'] < 0.99) $dbLvl = 'weak';
    $beLvl = hlp_level($beS['rate'] !== null ? min($beS['rate'], $buS['rate'] ?? 1) : null, $beS['p50'], 600);
    $posLvl = hlp_level($posS['rate'], $posS['p50'], 1500);
    // Something that has worked on its last 10 checks is no longer "failing" because of a failure hours ago: it is "weak" (history) until the 24 h window moves on.
    $recovered = function (array $rows): bool { $last = array_slice($rows, -10); return count($last) >= 10 && !count(array_filter($last, fn($r) => !$r['ok'])); };
    if (in_array($beLvl, ['failing', 'down'], true) && $recovered(hlp_rows('backend', $d1))) $beLvl = 'weak';
    $posLast = array_slice(hlp_rows('pos', $d1), -4);
    if (in_array($posLvl, ['failing', 'down'], true) && count($posLast) >= 3 && !count(array_filter($posLast, fn($r) => !$r['ok']))) $posLvl = 'strong';   // last few checks passed = working now

    // scores
    $rel = hlp_avg([hlp_rate_score($beS['rate']), hlp_rate_score($dbS['rate']), hlp_rate_score($posS['rate']), hlp_rate_score($buS['rate'])]);
    $pgStat = hlp_stat(hlp_rows('page', $now - 3 * 3600));
    $speedParts = [];
    if ($dbS['p50'] !== null) $speedParts[] = hlp_interp($dbS['p50'], [[0, 100], [50, 100], [150, 85], [400, 60], [1000, 30], [3000, 5]]);
    if ($beS['p50'] !== null) $speedParts[] = hlp_interp($beS['p50'], [[0, 100], [300, 100], [800, 75], [1500, 50], [3000, 25], [8000, 5]]);
    if ($beS['p95'] !== null) $speedParts[] = hlp_interp($beS['p95'], [[0, 100], [1000, 100], [2500, 70], [5000, 40], [10000, 10]]);
    if ($pgStat['p50'] !== null) $speedParts[] = hlp_interp($pgStat['p50'], [[0, 100], [400, 100], [1000, 80], [2500, 55], [5000, 25]]);
    if ($posS['p50'] !== null) $speedParts[] = hlp_interp($posS['p50'], [[0, 100], [600, 100], [1500, 75], [3000, 45], [8000, 10]]);
    $speed = hlp_avg($speedParts);
    $capParts = [];
    if ($connUse !== null) $capParts[] = hlp_interp($connUse * 100, [[0, 100], [30, 100], [50, 85], [70, 65], [85, 40], [100, 10]]);
    if ($buS['rate'] !== null) $capParts[] = hlp_rate_score($buS['rate']);
    if (isset($server['disk_free_pct']) && $server['disk_free_pct'] !== null) $capParts[] = hlp_interp((float)$server['disk_free_pct'], [[0, 0], [8, 30], [15, 70], [30, 100]]);
    if (isset($server['load1']) && $server['load1'] !== null) $capParts[] = hlp_interp((float)$server['load1'], [[0, 100], [2, 100], [6, 60], [12, 20], [30, 0]]);
    $capacity = hlp_avg($capParts);
    $uiScore = null; if ($ui && !empty($ui['rows'])) { $tot = count($ui['rows']); $clean = count(array_filter($ui['rows'], fn($r) => $r['state'] === 'ok')); $uiScore = $tot ? round($clean / $tot * 100, 1) : null; }
    $secScore = 100; foreach ($sec['warn'] as $w) $secScore -= $w['pts'] ?? 10;
    foreach ($ssl as $h => $d) if ($d !== null && $d < 14) $secScore -= 20;
    $secScore = max(0, $secScore);
    $parts = ['rel' => [$rel, 0.30], 'speed' => [$speed, 0.20], 'cap' => [$capacity, 0.20], 'ui' => [$uiScore, 0.15], 'sec' => [$secScore, 0.15]];
    $wsum = 0; $acc = 0; foreach ($parts as [$v, $w]) if ($v !== null) { $acc += $v * $w; $wsum += $w; }
    $health = ($wsum && $rel !== null) ? $acc / $wsum : null;
    // "Why not 95?" — how many points each part costs and what holds it down (shown under the health ring)
    $goal = ['target' => 95, 'health' => $health === null ? null : round($health, 1), 'drags' => []];
    if ($health !== null && $wsum) {
        $names = ['rel' => 'Reliability', 'speed' => 'Speed', 'cap' => 'Capacity', 'ui' => 'UI quality', 'sec' => 'Security'];
        $why = [
            'rel' => 'Share of checks that worked in 24 h: backend ' . ($beS['rate'] !== null ? round($beS['rate'] * 100, 1) . '%' : '–') . ', database ' . ($dbS['rate'] !== null ? round($dbS['rate'] * 100, 1) . '%' : '–') . ', parallel requests ' . ($buS['rate'] !== null ? round($buS['rate'] * 100, 1) . '%' : '–') . '. For 95 each needs to be about 99.5% or better. Old failures leave this 24-hour window slowly.',
            'cap' => 'Mostly the parallel-request test (' . ($buS['rate'] !== null ? round($buS['rate'] * 100, 1) . '%' : '–') . ' answered) and database connections in use' . ($connUse !== null ? ' (' . round($connUse * 100) . '%)' : '') . '. The shared database refuses some connections when many arrive together — ask Hostinger to raise the connections per user.',
            'speed' => 'Backend and page answer times.', 'ui' => 'Pages that are not phone-ready or look broken on the page check.', 'sec' => 'Warnings about files or certificates.',
        ];
        foreach ($parts as $k => [$v, $w]) { if ($v === null) continue; $cost = $w * (100 - $v) / $wsum; if ($cost >= 0.5) $goal['drags'][] = ['name' => $names[$k], 'score' => (int)round($v), 'cost' => round($cost, 1), 'why' => $why[$k]]; }
        usort($goal['drags'], fn($x, $y) => $y['cost'] <=> $x['cost']);
    }
    $yk = 'scores_' . date('Ymd', $now - 86400); $y = hlp_kv_get($yk, []);
    $today = ['health' => $health, 'speed' => $speed, 'rel' => $rel, 'cap' => $capacity, 'ui' => $uiScore, 'sec' => $secScore];
    hlp_kv_set('scores_' . date('Ymd', $now), $today);
    $mk = function ($key, $score, $note) use ($y) { $t = ($score !== null && isset($y[$key]) && $y[$key] !== null) ? (int)round($score - $y[$key]) : null; return ['score' => $score === null ? null : (int)round($score), 'grade' => hlp_grade($score), 'trend' => $t, 'note' => $note]; };

    // risk estimate (next hour)
    $fail30 = $beRecent['rate'] !== null ? 1 - $beRecent['rate'] : 0; $burstFail = $buRecent['rate'] !== null ? 1 - $buRecent['rate'] : 0; $cu = $connUse ?? 0;
    $dbRecent = hlp_stat(hlp_rows('db', $now - 1800)); $dbFail = $dbRecent['rate'] !== null ? 1 - $dbRecent['rate'] : 0;
    $slowFrac = ($beS['p95'] !== null && $beS['p95'] > 3000) ? 1 : 0;
    $riskPct = (int)round(hlp_clamp(100 * (0.30 * $fail30 + 0.25 * $burstFail + 0.25 * $dbFail + 0.15 * $cu + 0.05 * $slowFrac) + 4, 3, 95));
    $riskLabel = $riskPct < 15 ? 'Low' : ($riskPct < 40 ? 'Medium' : 'High');

    // last-60-minutes strip
    $strip = array_fill(0, 60, 'x'); $mins = [];
    foreach (['db', 'backend', 'pos'] as $k) foreach (hlp_rows($k, $now - 3600) as $r) { $i = 59 - (int)floor(($now - $r['ts']) / 60); if ($i < 0 || $i > 59) continue; $cur = $mins[$i] ?? 'g'; if (!$r['ok']) $cur = 'r'; elseif ($r['ms'] > 3000 && $cur !== 'r') $cur = 'a'; $mins[$i] = $cur; }
    foreach ($mins as $i => $v) $strip[$i] = $v;

    // functions (things we can really test from the server)
    $fn = [];
    $add = function ($name, $lvl, $note) use (&$fn) { $fn[] = ['name' => $name, 'level' => $lvl, 'note' => $note]; };
    $add('Website can save files', !empty($server['writable']) ? 'strong' : 'failing', !empty($server['writable']) ? 'Write test passed' : 'Cannot write files — uploads will fail');
    $ext = $server['ext'] ?? []; $missing = array_keys(array_filter($ext, fn($v) => !$v));
    $add('PHP parts (PDF, images, zip)', $missing ? 'weak' : 'strong', $missing ? 'Missing: ' . implode(', ', $missing) : 'All needed parts are on');
    foreach ($ssl as $h => $days) $add('Security certificate · ' . $h, $days === null ? 'weak' : ($days < 7 ? 'failing' : ($days < 21 ? 'weak' : 'strong')), $days === null ? 'Could not read it' : $days . ' days left');
    $add('Disk space', isset($server['disk_free_pct']) ? ($server['disk_free_pct'] < 8 ? 'failing' : ($server['disk_free_pct'] < 15 ? 'weak' : 'strong')) : 'unknown', isset($server['disk_free_gb']) ? $server['disk_free_gb'] . ' GB free (' . $server['disk_free_pct'] . '%)' : 'unknown');
    $add('Help checker (cron)', $cronAge === null ? 'down' : ($cronAge > 240 ? 'failing' : ($cronAge > 130 ? 'weak' : 'strong')), $cronAge === null ? 'Has not run yet — set the cron job' : 'Last run ' . ($cronAge < 90 ? $cronAge . ' s' : round($cronAge / 60) . ' min') . ' ago');
    foreach ($fresh as $f) $add('Data arriving · ' . $f['label'], $f['age_min'] > $f['max'] ? 'weak' : 'strong', 'Last written ' . ($f['age_min'] < 120 ? $f['age_min'] . ' min' : round($f['age_min'] / 60, 1) . ' h') . ' ago');
    $add('Maintenance mode', !empty($server['maintenance']) ? 'weak' : 'strong', !empty($server['maintenance']) ? 'ON — visitors see the maintenance page' : 'Off');

    // browser probes (API table)
    $apis = []; $stmt = hlp_store()->prepare("SELECT name, ok, ms, detail, ts FROM hlp_checks WHERE kind='api' AND ts>=? ORDER BY ts"); $stmt->execute([$d1]);
    $byName = []; foreach ($stmt->fetchAll() as $r) $byName[$r['name']][] = $r;
    foreach ($byName as $name => $rows) {
        // History recorded before: a 4xx JSON answer (not 401) means the backend answered — it is not a failure of the backend.
        foreach ($rows as $ri => $rw) if (!$rw['ok'] && preg_match('/^HTTP (4\d\d)\b/', (string)$rw['detail'], $hm) && $hm[1] !== '401' && stripos((string)$rw['detail'], 'Database connection failed') === false) $rows[$ri]['ok'] = 1;
        $st = hlp_stat($rows); $last30 = array_slice($rows, -30); $spark = array_map(fn($r) => $r['ok'] ? ($r['ms'] > 3000 ? 'a' : 'g') : 'r', $last30);
        $recent = array_slice($rows, -12); $rr = hlp_stat($recent);
        $risk = (int)round(hlp_clamp(100 * (0.7 * (1 - ($rr['rate'] ?? 1)) + 0.3 * (1 - $st['rate'])) + 3, 3, 95));
        $lastBad = null; foreach (array_reverse($rows) as $r) if (!$r['ok']) { $lastBad = $r['detail']; break; }
        $apis[] = ['name' => $name, 'level' => (function ($lvl) use ($rows, $recovered) { return in_array($lvl, ['failing', 'down'], true) && $recovered($rows) ? 'weak' : $lvl; })(hlp_level($st['rate'], $st['p50'], 600)), 'rate' => round($st['rate'] * 100, 1), 'p50' => $st['p50'] === null ? null : (int)$st['p50'], 'spark' => $spark, 'risk' => $risk, 'n' => $st['n'], 'why' => $lastBad, 'at' => end($rows)['ts']];
    }
    usort($apis, fn($a, $b) => ['failing' => 0, 'down' => 0, 'weak' => 1, 'strong' => 2, 'unknown' => 3][$a['level']] <=> ['failing' => 0, 'down' => 0, 'weak' => 1, 'strong' => 2, 'unknown' => 3][$b['level']] ?: $b['risk'] <=> $a['risk']);

    // counts
    $lv = [$dbLvl, $beLvl, $posLvl]; foreach ($pageRows as $p) $lv[] = $p['state'] === 'ok' ? 'strong' : ($p['state'] === 'warn' ? 'weak' : ($p['state'] === 'bad' ? 'failing' : 'unknown'));
    foreach ($apis as $a) $lv[] = $a['level']; foreach ($fn as $f) $lv[] = $f['level'];
    $counts = ['strong' => 0, 'weak' => 0, 'failing' => 0, 'down' => 0];
    foreach ($lv as $l) if (isset($counts[$l])) $counts[$l]++;
    if (count($sec['warn'])) $counts['failing']++;

    // files
    $files = hlp_store()->query("SELECT path,state,changed_at,note FROM hlp_files WHERE state IN ('new','changed') AND changed_at>=" . ($now - 86400) . " ORDER BY changed_at DESC LIMIT 30")->fetchAll();
    $incidents = hlp_store()->query("SELECT started,ended,area,title,detail FROM hlp_incidents WHERE started>=" . ($now - 7 * 86400) . " ORDER BY started DESC LIMIT 20")->fetchAll();

    $pageDoctor = hlp_page_doctor($ui, $pageList, $beS, $buS, $now);
    $st = [
        'version' => HLP_VERSION, 'now' => $now, 'cron_age' => $cronAge, 'db_creds' => hlp_db_creds() ? hlp_db_creds()['src'] : null,
        'scores' => [
            'health' => $mk('health', $health, ''),
            'speed' => $mk('speed', $speed, $speed === null ? 'No data yet' : 'Typical backend answer ' . ($beS['p50'] !== null ? (int)$beS['p50'] . ' ms' : '–') . ', slowest 5% ' . ($beS['p95'] !== null ? round($beS['p95'] / 1000, 1) . ' s' : '–')),
            'rel' => $mk('rel', $rel, $beS['rate'] === null ? 'No data yet' : round($beS['rate'] * 100, 1) . '% of backend checks worked in 24 h'),
            'cap' => $mk('cap', $capacity, $buS['rate'] === null ? 'No burst test yet' : (round($buS['rate'] * 100) . '% of parallel requests were answered')),
            'ui' => $mk('ui', $uiScore, $uiScore === null ? 'Run the UI check' : count(array_filter($ui['rows'], fn($r) => $r['state'] === 'ok')) . ' of ' . count($ui['rows']) . ' pages clean'),
            'sec' => $mk('sec', $secScore, count($sec['warn']) ? count($sec['warn']) . ' warning(s)' : 'No warnings'),
        ],
        'risk' => ['pct' => $riskPct, 'label' => $riskLabel],
        'counts' => $counts, 'strip' => $strip,
        'db' => ['level' => $dbLvl, 'rate' => $dbS['rate'], 'p50' => $dbS['p50'], 'info' => $dbInfo, 'use' => $connUse, 'limit' => $limit, 'burst_recent' => $buRecent['rate'], 'refused_24h' => count(array_filter(hlp_rows('burst', $d1), fn($r) => !$r['ok'])) + count(array_filter(hlp_rows('backend', $d1), fn($r) => !$r['ok'])), 'last_err' => (function () { $s = hlp_store()->query("SELECT detail FROM hlp_checks WHERE kind='db' AND ok=0 ORDER BY ts DESC LIMIT 1")->fetchColumn(); return $s ?: null; })()],
        'backend' => ['level' => $beLvl, 'rate' => $beS['rate'], 'p50' => $beS['p50'], 'p95' => $beS['p95'], 'burst_rate' => $buS['rate'], 'burst_n' => $buS['n']],
        'pos' => ['level' => $posLvl, 'rate' => $posS['rate'], 'p50' => $posS['p50']],
        'server' => $server, 'ssl' => $ssl, 'security' => $sec, 'errorlog' => $elog,
        'apis' => $apis, 'functions' => $fn, 'pages' => $pageRows, 'pages_bad' => $pagesBad,
        'page_doctor' => $pageDoctor, 'files' => $files, 'incidents' => $incidents, 'ui' => $ui ? ['at' => $ui['at'], 'width' => $ui['width'] ?? null, 'n' => count($ui['rows'])] : null,
    ];
    $hist = []; for ($i = 6; $i >= 0; $i--) { $h = hlp_kv_get('scores_' . date('Ymd', $now - $i * 86400), null); $hist[] = ['day' => date('D', $now - $i * 86400), 'health' => ($h && isset($h['health']) && $h['health'] !== null) ? (int)round($h['health']) : null]; }
    $st['history'] = $hist;
    $st['findings'] = hlp_findings($st);
    $st['goal'] = $goal;
    // ONE count: the chips at the top and the "What to do now" box below are both made from the same list, so they can never disagree.
    $fk = ['weak' => 0, 'failing' => 0]; foreach ($st['findings'] as $fx) if (isset($fk[$fx['kind']])) $fk[$fx['kind']]++;
    $st['counts']['weak'] = $fk['weak']; $st['counts']['failing'] = $fk['failing']; $st['counts']['down'] = 0;
    $st['readiness'] = hlp_readiness($st);
    $st['doctor'] = hlp_kv_get('doctor', null);
    return $st;
}
/* ───────────────────────── Page Doctor: per-page speed, chance of failing, phone/desktop check, how to improve ───────────────────────── */
function hlp_speed_grade(float $ms): string { return $ms <= 1500 ? 'A' : ($ms <= 2500 ? 'B' : ($ms <= 3500 ? 'C' : ($ms <= 5000 ? 'D' : 'F'))); }
function hlp_page_doctor(?array $ui, array $pages, array $beS, array $buS, int $now): ?array {
    if (!$ui || empty($ui['rows'])) return null;
    $q = 0.5 * (1 - ($buS['rate'] ?? 1)) + 0.5 * (1 - ($beS['rate'] ?? 1));   // chance that ONE request is refused right now
    $meta = []; foreach ($pages as $p) $meta[$p['file']] = $p;
    $sw = []; $st = hlp_store()->prepare("SELECT name, AVG(ok) a FROM hlp_checks WHERE kind='page' AND ts>=? GROUP BY name"); $st->execute([$now - 86400]);
    foreach ($st->fetchAll() as $r) $sw[$r['name']] = (float)$r['a'];
    $hist = []; $st = hlp_store()->prepare("SELECT name, ms FROM hlp_checks WHERE kind='pspeed' AND ts>=? ORDER BY ts"); $st->execute([$now - 7 * 86400]);
    foreach ($st->fetchAll() as $r) $hist[$r['name']][] = (int)$r['ms'];
    $rank = ['ok' => 0, 'warn' => 1, 'bad' => 2]; $rows = [];
    foreach ($ui['rows'] as $r) {
        if (!isset($r['d']) || empty($meta[$r['file']]) || $r['file'] === 'help.html') continue;
        $file = $r['file']; $m = $meta[$file]; $ms = (int)($r['speed_ms'] ?? 0); $calls = (int)($r['calls'] ?? 0); $slow = $r['slow'] ?? ['name' => '', 'ms' => 0];
        $worst = 'ok'; foreach (['d', 'm', 'r'] as $k) if ($rank[$r[$k]] > $rank[$worst]) $worst = $r[$k];
        $fp = 100 * (1 - pow(1 - $q, max(1, $calls)));
        $fp += 8 * min(3, (int)($r['failed_calls'] ?? 0)); if ($worst === 'bad') $fp += 15; if (($slow['ms'] ?? 0) > 3000) $fp += 5;
        if (isset($sw[$file]) && $sw[$file] < 1) $fp += 40 * (1 - $sw[$file]);
        $fail = (int)round(hlp_clamp($fp, 3, 95));
        $spS = hlp_interp($ms / 1000, [[0, 100], [1, 100], [2.5, 75], [4, 45], [7, 10], [15, 0]]);
        $uiS = ['ok' => 100, 'warn' => 60, 'bad' => 20][$worst]; $score = 0.40 * $spS + 0.35 * $uiS + 0.25 * (100 - $fail);
        // what I found + how to improve (plain English)
        $found = []; $tips = [];
        foreach (['m' => ['Phone', 'im'], 'r' => ['Responsive', 'ir'], 'd' => ['Desktop', 'id']] as $k => [$tag, $ik]) {
            foreach (array_slice((array)($r[$ik] ?? []), 0, 3) as $t) $found[] = ['tag' => $tag, 'level' => $r[$k] === 'bad' ? 'r' : 'a', 'text' => $t];
        }
        if ($ms > 2500) $found[] = ['tag' => 'Speed', 'level' => $ms > 3500 ? 'r' : 'a', 'text' => $calls . ' data requests open at once' . ($slow['name'] ? '; ' . $slow['name'] . ' alone takes ' . round($slow['ms'] / 1000, 1) . ' s' : '') . '.'];
        if ($fail >= 15) $found[] = ['tag' => 'Fail risk', 'level' => $fail >= 30 ? 'r' : 'a', 'text' => $fail . '% chance of a blank or error page at busy times' . ($calls > 0 ? ' — each of the ' . $calls . ' data requests can be refused by the database.' : '.')];
        if (!$found) $found[] = ['tag' => 'All good', 'level' => 'g', 'text' => 'Opens quickly and looks fine on phone, tablet and computer.'];
        if ($calls >= 15) $tips[] = ['t' => 'Combine the ' . $calls . ' data requests into 1–3', 's' => 'Fewer trips means faster opening and much less chance the database refuses one.'];
        if (($slow['ms'] ?? 0) > 1500) $tips[] = ['t' => 'Speed up ' . ($slow['name'] ?: 'the slowest request'), 's' => 'It takes ' . round($slow['ms'] / 1000, 1) . ' s. Ask Claude to load fewer rows or add a database index.'];
        if ($ms > 3000 && ($r['parts']['data'] ?? 0) > $ms * 0.4) $tips[] = ['t' => 'Load only the first 50 rows', 's' => 'Fetch the rest when scrolling. Usually saves 1–2 s.'];
        if ((int)($r['over390'] ?? 0) > 4) $tips[] = ['t' => 'Make ' . ($r['offender'] ?: 'the wide part') . ' fit the phone', 's' => 'Let it scroll sideways, or show each row as a card on small screens.'];
        elseif ((int)($r['over768'] ?? 0) > 4) $tips[] = ['t' => 'Fix the layout on tablet widths', 's' => 'Something is wider than a tablet screen' . ($r['offender'] ? ' (' . $r['offender'] . ')' : '') . '.'];
        if ((int)($r['tiny'] ?? 0) > 12) $tips[] = ['t' => 'Make buttons bigger on phone', 's' => 'Tap targets should be at least 40 px high so fingers hit them.'];
        if ((int)($r['small_text'] ?? 0) > 10) $tips[] = ['t' => 'Make small text bigger on phone', 's' => 'Use 13 px or more for anything people must read.'];
        if ((int)($r['broken_img'] ?? 0) > 0) $tips[] = ['t' => 'Fix the broken or empty images', 's' => 'An empty image address makes the browser send an extra useless request.'];
        if ($worst === 'bad' && !$tips) $tips[] = ['t' => 'Send this page to Claude', 's' => 'It is blank or shows an error — use the button below.'];
        if (!$tips) $tips[] = ['t' => 'Nothing to fix', 's' => 'This page is healthy. Re-check after your next change.'];
        $rows[] = [
            'file' => $file, 'label' => $m['label'], 'group' => $m['group'], 'speed_ms' => $ms, 'grade' => hlp_speed_grade($ms), 'calls' => $calls, 'failed_calls' => (int)($r['failed_calls'] ?? 0),
            'slow' => $slow, 'parts' => $r['parts'] ?? [], 'fail_pct' => $fail, 'd' => $r['d'], 'm' => $r['m'], 'r' => $r['r'], 'over390' => (int)($r['over390'] ?? 0),
            'found' => $found, 'tips' => array_slice($tips, 0, 4), 'hist' => array_slice($hist[$file] ?? [], -24), 'score' => (int)round($score), 'state' => $worst,
        ];
    }
    if (!$rows) return null;
    usort($rows, fn($a, $b) => $a['score'] <=> $b['score']);
    $n = count($rows); $avg = array_sum(array_column($rows, 'speed_ms')) / $n; $slowest = $rows;
    usort($slowest, fn($a, $b) => $b['speed_ms'] <=> $a['speed_ms']);
    $health = array_sum(array_column($rows, 'score')) / $n;
    return [
        'at' => $ui['at'], 'total' => $n,
        'summary' => [
            'slowest' => ['label' => $slowest[0]['label'], 'ms' => $slowest[0]['speed_ms']], 'avg_ms' => (int)round($avg),
            'not_phone' => count(array_filter($rows, fn($x) => $x['m'] !== 'ok' || $x['r'] !== 'ok')), 'likely_fail' => count(array_filter($rows, fn($x) => $x['fail_pct'] >= 30)),
            'slow' => count(array_filter($rows, fn($x) => $x['speed_ms'] > 3500)), 'needs_work' => count(array_filter($rows, fn($x) => $x['state'] !== 'ok' || $x['fail_pct'] >= 30 || $x['speed_ms'] > 3500)),
            'health' => (int)round($health), 'grade' => hlp_grade($health),
        ],
        'rows' => $rows,
    ];
}

function hlp_readiness(array $s): array {
    $lv = fn($l) => in_array($l, ['strong'], true) ? 'g' : (in_array($l, ['weak', 'unknown'], true) ? 'a' : 'r');
    $r = [];
    $r[] = ['name' => 'Billing at the counter', 'state' => $lv($s['pos']['level']), 'note' => $s['pos']['p50'] ? 'POS loads in ' . round($s['pos']['p50'] / 1000, 1) . ' s' : 'No POS check yet'];
    $r[] = ['name' => 'HRMS pages and badges', 'state' => $lv($s['backend']['level']), 'note' => $s['backend']['rate'] === null ? 'No data yet' : round($s['backend']['rate'] * 100, 1) . '% of backend checks work'];
    $r[] = ['name' => 'Database', 'state' => $lv($s['db']['level']), 'note' => $s['db']['p50'] !== null ? 'Answers in ' . (int)$s['db']['p50'] . ' ms' : 'No data yet'];
    $r[] = ['name' => 'All pages open', 'state' => $s['pages_bad'] ? 'r' : ($s['counts']['weak'] ? 'a' : 'g'), 'note' => $s['pages_bad'] ? $s['pages_bad'] . ' page(s) do not open' : 'Every page file opens'];
    $r[] = ['name' => 'Many staff at once', 'state' => ($s['db']['burst_recent'] === null ? 'a' : ($s['db']['burst_recent'] >= 0.99 ? 'g' : ($s['db']['burst_recent'] >= 0.9 ? 'a' : 'r'))), 'note' => $s['db']['burst_recent'] === null ? 'No test yet' : round($s['db']['burst_recent'] * 100) . '% of parallel requests answered'];
    $r[] = ['name' => 'Security', 'state' => count($s['security']['warn']) ? 'r' : 'g', 'note' => count($s['security']['warn']) ? count($s['security']['warn']) . ' warning(s)' : 'No warnings'];
    return $r;
}
/** Plain-language problems with the exact fix, ready to paste to Claude. kind = failing | weak | warning */
function hlp_findings(array $s): array {
    $f = [];
    $add = function ($lvl, $title, $why, $fix, $ask = '', $kind = null, $attach = [], $where = '', $measured = '') use (&$f) {
        $f[] = ['level' => $lvl, 'kind' => $kind ?: ($lvl === 'red' ? 'failing' : ($lvl === 'amber' ? 'weak' : 'warning')), 'title' => $title, 'why' => $why, 'fix' => $fix,
                'ask' => $ask ?: ('How do I fix this: ' . $title . '?'), 'attach' => $attach, 'where' => $where, 'measured' => $measured];
    };
    $pdRows = []; foreach (($s['page_doctor']['rows'] ?? []) as $r) $pdRows[$r['file']] = $r;
    if ($s['cron_age'] === null || $s['cron_age'] > 240) $add('amber', 'The 24/7 checker (cron job) is not running', 'Help only checks while someone has this page open.', 'hPanel → Advanced → Cron Jobs → add the Help job and set it to every minute.', '', 'warning', [], 'hPanel → Advanced → Cron Jobs', 'Last run: ' . ($s['cron_age'] === null ? 'never' : round($s['cron_age'] / 60) . ' min ago'));
    foreach ($s['security']['warn'] as $w) $add('red', $w['what'] . ' on the server: ' . $w['file'], ($w['exposed'] === true ? 'Anyone who knows the address can download it right now (the website answered "OK").' : 'Files like this can be misused if someone finds them.'), 'hPanel → File Manager → public_html → ' . $w['file'] . ' → right-click → Delete. If it is a backup you want to keep, instead put a file named .htaccess containing the single line  Require all denied  in its folder.', '', 'failing', [], 'public_html/' . $w['file'], 'On the server for ' . ($w['age_days'] ?? '?') . ' days' . ($w['exposed'] === true ? ' · downloadable from the web' : ''));
    $dbf = $s['db']['last_err'];
    if ($s['db']['level'] === 'down' || $s['db']['level'] === 'failing') $add('red', 'The database is not answering', $dbf ?: 'Connections to MySQL are failing.', 'Open hPanel → Databases and check the database is running. If it keeps failing, contact Hostinger support with the time of the failure.', 'Why is the database not answering and what do I do?', 'failing', ['api.php'], 'empmanagment/api.php (database connection)', ($dbf ? 'Last error: ' . $dbf . ' · ' : '') . 'refused calls in 24 h: ' . $s['db']['refused_24h'] . ($s['db']['use'] !== null ? ' · connections used ' . round($s['db']['use'] * 100) . '%' : ''));
    if ($s['backend']['burst_rate'] !== null && $s['backend']['burst_rate'] < 0.99) $add($s['backend']['burst_rate'] < 0.9 ? 'red' : 'amber', 'Some requests are refused when many arrive together', round($s['backend']['burst_rate'] * 100) . '% of a parallel test was answered. This is why pages go blank at busy times.', 'Combine the page-load requests into one and slow down polling (Claude can write this), and move to the VPS.', 'Write the fix to combine the HRMS page-load requests', null, ['api.php', 'layout.js'], 'empmanagment/api.php and assets/js/layout.js', 'Parallel test answered: ' . round($s['backend']['burst_rate'] * 100) . '%');
    if ($s['backend']['rate'] !== null && $s['backend']['rate'] < 0.99 && $s['backend']['level'] !== 'strong') $add($s['backend']['rate'] < 0.9 ? 'red' : 'amber', 'The HRMS backend (api.php) fails sometimes', round($s['backend']['rate'] * 100, 1) . '% of checks worked in the last 24 hours.', 'Check the error log below. If it says "2002", the database is refusing connections at busy hours.', '', null, ['api.php'], 'empmanagment/api.php', 'Backend success rate 24 h: ' . round($s['backend']['rate'] * 100, 1) . '%' . ($s['db']['last_err'] ? ' · last database error: ' . $s['db']['last_err'] : ''));
    if ($s['db']['use'] !== null && $s['db']['use'] > 0.7) $add('amber', 'Database connections are nearly used up', round($s['db']['use'] * 100) . '% of the allowed connections are in use.', 'Fewer requests per page, or move to the VPS where the limit is much higher.', '', 'weak', [], 'Hostinger MySQL connection limit', 'Connections in use: ' . round($s['db']['use'] * 100) . '%');
    if ($s['pos']['level'] === 'down' || $s['pos']['level'] === 'failing') $add('red', 'The store POS is failing', 'The POS login page did not open properly.' . (($lp = array_slice(array_filter(hlp_rows('pos', time() - 3 * 3600), fn($r) => !$r['ok']), -1)) ? ' Last reason: ' . $lp[0]['detail'] . '.' : ''), 'Open fourpirates.space/tmpos in a new tab. If it shows "Server is busy", wait a minute. If it stays down, the shared server is overloaded.', '', 'failing', ['includes/db.php'], 'tmpos/includes/db.php (public_html of fourpirates.space)', 'POS check success 24 h: ' . ($s['pos']['rate'] === null ? '–' : round($s['pos']['rate'] * 100, 1) . '%'));
    foreach ($s['pages'] as $p) if ($p['state'] === 'bad') {
        $r = $pdRows[$p['file']] ?? null;
        $add('red', 'Page has a problem: ' . $p['label'], $p['note'] ?: 'The file is missing or has an error.', 'Open ' . $p['file'] . ' on your phone. If it is blank or shows an error, re-upload the file to public_html (File Manager) or send it to Claude.', 'Why does the ' . $p['label'] . ' page have a problem?', 'failing', [$p['file']], 'public_html/' . $p['file'], $r ? ('opens in ' . round($r['speed_ms'] / 1000, 1) . ' s, ' . $r['calls'] . ' data requests, ' . $r['fail_pct'] . '% chance of failing, desktop ' . $r['d'] . ', phone ' . $r['m'] . ', responsive ' . $r['r']) : ($p['note'] ?: ''));
    }
    foreach ($s['files'] as $x) if (stripos($x['note'], 'BROKEN') === 0) $add('red', 'Your last upload looks broken: ' . $x['path'], $x['note'], 'Re-upload the correct file ' . $x['path'] . '.', '', 'failing', [basename($x['path'])], 'public_html/' . $x['path'], $x['note']);
    foreach ($s['ssl'] as $h => $d) if ($d !== null && $d < 21) $add($d < 7 ? 'red' : 'amber', 'Security certificate for ' . $h . ' expires in ' . $d . ' days', 'The browser will show a scary warning when it expires.', 'hPanel → SSL → renew / reinstall the certificate for ' . $h . '.', '', $d < 7 ? 'failing' : 'warning', [], 'hPanel → SSL', $d . ' days left');
    if (isset($s['server']['disk_free_pct']) && $s['server']['disk_free_pct'] !== null && $s['server']['disk_free_pct'] < 12) $add('amber', 'Disk space is getting low', $s['server']['disk_free_pct'] . '% free.', 'Delete old backups, invoices and logs in File Manager.', '', 'warning', [], 'hPanel → File Manager', $s['server']['disk_free_pct'] . '% free (' . ($s['server']['disk_free_gb'] ?? '?') . ' GB)');
    if ($s['errorlog'] && !empty($s['errorlog']['found']) && $s['errorlog']['total'] > 200) $add('amber', 'Many errors in the server log', $s['errorlog']['total'] . ' error lines in 24 hours (' . $s['errorlog']['dbfail'] . ' about database connections).', 'Open hPanel → Logs → Error log and send the latest lines to Claude.', '', 'warning', [], 'hPanel → Logs → Error log', $s['errorlog']['total'] . ' lines in 24 h, ' . $s['errorlog']['fatal'] . ' fatal, ' . $s['errorlog']['dbfail'] . ' about connections');
    foreach ($s['functions'] as $x) if (strpos($x['name'], 'Data arriving') === 0 && $x['level'] !== 'strong') $add('amber', $x['name'] . ' looks stale', $x['note'], 'Check the sync job / webhook that feeds this data.', '', 'warning', [], 'The sync job / webhook', $x['note']);
    // page problems found by the Page Doctor (slow, not phone-ready, likely to fail) — pages that simply do not open are listed above
    $n = 0;
    foreach (($s['page_doctor']['rows'] ?? []) as $r) {
        if ($r['state'] === 'bad' || $n >= 10) continue;
        $slow = $r['speed_ms'] > 3500; $phone = $r['m'] !== 'ok' || $r['r'] !== 'ok'; $risky = $r['fail_pct'] >= 30;
        if (!$slow && !$phone && !$risky) continue;
        $n++;
        $title = $slow ? $r['label'] . ' page is slow: ' . round($r['speed_ms'] / 1000, 1) . ' s' : ($phone ? $r['label'] . ' page is not phone-ready' : $r['label'] . ' page may fail at busy times (' . $r['fail_pct'] . '%)');
        $why = implode(' · ', array_map(fn($x) => $x['text'], array_slice(array_values(array_filter($r['found'], fn($x) => $x['level'] !== 'g')), 0, 3)));
        $fix = implode('; ', array_map(fn($t) => $t['t'], array_slice($r['tips'], 0, 3))) . '.';
        $add('amber', $title, $why, $fix, 'How do I make the ' . $r['label'] . ' page faster and phone-friendly?', 'weak', [$r['file']], 'public_html/' . $r['file'],
            'opens in ' . round($r['speed_ms'] / 1000, 1) . ' s, ' . $r['calls'] . ' data requests (slowest ' . ($r['slow']['name'] ?: '–') . ' ' . round(($r['slow']['ms'] ?? 0) / 1000, 1) . ' s), ' . $r['fail_pct'] . '% chance of failing, desktop ' . $r['d'] . ', phone ' . $r['m'] . ', responsive ' . $r['r']);
    }
    // Everything else that is not "strong" gets its own line too, so nothing is counted at the top without being listed here.
    foreach (($s['apis'] ?? []) as $x) {
        if (!in_array($x['level'], ['weak', 'failing', 'down'], true)) continue;
        $bad = $x['level'] !== 'weak';
        $add($bad ? 'red' : 'amber', 'Backend call ' . $x['name'] . ($bad ? ' is failing' : ' failed earlier today'), ($x['why'] ?: 'Some checks of this call did not work.') . ' (' . $x['rate'] . '% worked in 24 h)',
            $bad ? 'Open the page that uses it; if it stays broken send the name ' . $x['name'] . ' to Claude.' : 'It is working now. This clears by itself as the 24-hour window moves on.', 'Why does ' . $x['name'] . ' fail sometimes?', $bad ? 'failing' : 'weak');
    }
    foreach (($s['functions'] ?? []) as $x) {
        if ($x['level'] === 'strong' || $x['level'] === 'unknown' || preg_match('/^(Data arriving|Disk space|Help checker|Security certificate)/', $x['name'])) continue;
        $bad = in_array($x['level'], ['failing', 'down'], true);
        $add($bad ? 'red' : 'amber', $x['name'], $x['note'], 'Check this in hPanel or ask Claude.', '', $bad ? 'failing' : 'weak');
    }
    $listed = []; foreach ($f as $x) foreach ($x['attach'] as $a) $listed[$a] = 1;
    foreach ($s['pages'] as $p) if ($p['state'] === 'warn' && empty($listed[$p['file']])) $add('amber', 'Page needs a look: ' . $p['label'], $p['note'] ?: 'Not quite right on phone or slow.', 'Run the page check, then open this page in the Page Doctor.', 'How do I improve the ' . $p['label'] . ' page?', 'weak', [$p['file']], 'public_html/' . $p['file']);
    foreach (['db' => 'Database', 'backend' => 'The HRMS backend (api.php)', 'pos' => 'The store POS'] as $k => $nm) {
        if (($s[$k]['level'] ?? '') === 'weak' && !array_filter($f, fn($x) => stripos($x['title'], $k === 'db' ? 'database' : ($k === 'pos' ? 'POS' : 'backend')) !== false && $x['kind'] !== 'warning')) {
            $add('amber', $nm . ' had some failures in the last 24 hours', 'It answers now, but not every check worked today.', 'Nothing to do now. It clears as the 24-hour window moves on.', '', 'weak');
        }
    }
    if ($s['ui'] === null) $add('green', 'Run the page check once', 'It opens every page on phone, tablet and computer widths and measures speed, so Page Doctor can show real numbers.', 'Press "Run page check" in the Page Doctor section (best when the shop is quiet).', '', 'warning', [], 'Help → Page Doctor', 'No page check has been run yet');
    $order = ['failing' => 0, 'weak' => 1, 'warning' => 2];
    usort($f, fn($a, $b) => $order[$a['kind']] <=> $order[$b['kind']]);
    return $f;
}

/* ───────────────────────── incidents ───────────────────────── */
function hlp_update_incidents(): void {
    $now = time(); $pdo = hlp_store();
    foreach (['db' => 'Database', 'backend' => 'HRMS backend', 'pos' => 'Store POS'] as $kind => $label) {
        $rows = array_slice(hlp_rows($kind, $now - 600), -3); if (count($rows) < 2) continue;
        $bad = count(array_filter($rows, fn($r) => !$r['ok'])); $open = $pdo->prepare('SELECT id FROM hlp_incidents WHERE area=? AND ended IS NULL ORDER BY id DESC LIMIT 1'); $open->execute([$kind]); $oid = $open->fetchColumn();
        $last = end($rows);
        if ($bad >= 2 && !$oid) $pdo->prepare('INSERT INTO hlp_incidents (started,area,title,detail) VALUES (?,?,?,?)')->execute([$now - 60, $kind, $label . ' is failing', mb_substr((string)$last['detail'], 0, 200)]);
        elseif ($oid && $bad === 0) $pdo->prepare('UPDATE hlp_incidents SET ended=? WHERE id=?')->execute([$now, $oid]);
    }
}

/* ───────────────────────── the doctor (Claude) ───────────────────────── */
function hlp_claude_cfg(): array {
    static $c = null; if ($c) return $c;
    $key = HELP_CLAUDE_KEY; $model = HELP_CLAUDE_MODEL;
    if ($key === '' || $model === '') {
        $p = __DIR__ . '/config.php';
        if (is_file($p) && filesize($p) < 2000000) {
            $t = (string)file_get_contents($p);
            if ($key === '' && preg_match('/[\'"]ANTHROPIC_API_KEY[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $t, $m)) $key = $m[1];
            if ($key === '' && preg_match('/ANTHROPIC_API_KEY\s*=\s*[\'"]([^\'"]+)[\'"]/', $t, $m)) $key = $m[1];
            if ($model === '' && preg_match('/[\'"]TMPH_MODEL[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $t, $m)) $model = $m[1];
        }
    }
    return $c = ['key' => $key, 'model' => $model ?: 'claude-haiku-5-5'];
}
function hlp_claude(string $system, string $user, int $max = 400): ?string {
    $c = hlp_claude_cfg(); if ($c['key'] === '') return null;
    $day = 'claude_calls_' . date('Ymd'); $n = (int)hlp_kv_get($day, 0); if ($n >= HELP_DOCTOR_MAX_CALLS_PER_DAY) return null;
    hlp_kv_set($day, $n + 1);
    if (!function_exists('brain_claude_call') && is_file(__DIR__ . '/brain.php')) require_once __DIR__ . '/brain.php';
    if (!function_exists('brain_claude_call')) return null;
    if (!function_exists('tmph_claude_key') && !defined('ANTHROPIC_API_KEY') && !getenv('ANTHROPIC_API_KEY')) define('ANTHROPIC_API_KEY', $c['key']);   // the health cron runs without api.php: hand it the key it found in config.php
    list($ok, $txt, $why) = brain_claude_call($system, $user, $max, ['model' => $c['model'], 'timeout' => 25, 'purpose' => 'health']);   // the ONE Claude door (key, budget, usage count)
    if (!$ok) { hlp_kv_set('claude_last_err', ['at' => time(), 'code' => 0, 'msg' => substr((string)$why, 0, 200)]); return null; }
    return $txt;
}
function hlp_brief(array $s): array { // small, secret-free summary for Claude
    return [
        'time' => date('H:i'), 'scores' => array_map(fn($x) => $x['score'] . ' (' . $x['grade'] . ')', $s['scores']), 'risk_next_hour' => $s['risk'],
        'counts' => $s['counts'], 'database' => ['level' => $s['db']['level'], 'connections_used_pct' => $s['db']['use'] === null ? null : round($s['db']['use'] * 100), 'typical_ms' => $s['db']['p50']],
        'backend' => ['level' => $s['backend']['level'], 'success_pct' => $s['backend']['rate'] === null ? null : round($s['backend']['rate'] * 100, 1), 'burst_pct' => $s['backend']['burst_rate'] === null ? null : round($s['backend']['burst_rate'] * 100)],
        'pos' => $s['pos']['level'], 'pages_not_opening' => $s['pages_bad'],
        'findings' => array_map(fn($x) => $x['level'] . ': ' . $x['title'] . ' — ' . $x['why'], array_slice($s['findings'], 0, 8)),
        'worst_pages' => array_map(fn($p) => $p['label'] . ': ' . round($p['speed_ms'] / 1000, 1) . ' s, ' . $p['fail_pct'] . '% fail chance, phone ' . $p['m'], array_slice($s['page_doctor']['rows'] ?? [], 0, 3)),
        'new_or_changed_files' => array_map(fn($x) => $x['state'] . ' ' . $x['path'] . ' ' . $x['note'], array_slice($s['files'], 0, 6)),
        'failing_apis' => array_map(fn($a) => $a['name'] . ' ' . $a['rate'] . '%', array_slice(array_filter($s['apis'], fn($a) => $a['level'] !== 'strong'), 0, 6)),
    ];
}
function hlp_doctor_note(array $s, bool $force = false): void {
    $brief = hlp_brief($s); $hash = md5(json_encode([$brief['scores'], $brief['findings'], $brief['counts']])); $prev = hlp_kv_get('doctor', []);
    $reds = count(array_filter($s['findings'], fn($f) => $f['level'] === 'red')); $ambers = count(array_filter($s['findings'], fn($f) => $f['level'] === 'amber'));
    $rule = $reds ? "Needs you: {$reds} problem(s) to fix." : ($ambers ? 'Working, but weak in some places.' : 'Everything looks healthy.');
    $short = $rule; $by = 'rules';
    if (!$force && !empty($prev['hash']) && $prev['hash'] === $hash && time() - ($prev['at'] ?? 0) < 1800) return;
    $txt = hlp_claude(
        "You are Dr. Claude, the system doctor for the HRMS and store POS of TM Perfume House. The owner is not a programmer. Use ONLY the facts given. Reply with ONE short sentence (max 28 words) that says how the system is right now and what matters most. Plain English, no jargon, no markdown.",
        json_encode($brief, JSON_UNESCAPED_UNICODE), 120);
    if ($txt) { $short = trim(preg_replace('/\s+/', ' ', $txt)); $by = 'claude'; }
    hlp_kv_set('doctor', ['short' => $short, 'at' => time(), 'hash' => $hash, 'by' => $by, 'reds' => $reds, 'ambers' => $ambers]);
}
function hlp_ask(string $q, array $s): array {
    $q = trim(mb_substr($q, 0, 500)); if ($q === '') return ['answer' => 'Type a question.', 'by' => 'rules'];
    $n = (int)hlp_kv_get('ask_' . date('Ymd'), 0); if ($n >= 60) return ['answer' => 'The doctor answered 60 questions today — that is the daily limit.', 'by' => 'rules'];
    hlp_kv_set('ask_' . date('Ymd'), $n + 1);
    $txt = hlp_claude("You are Dr. Claude, the system doctor for the HRMS and store POS of TM Perfume House (hosted on Hostinger). The owner, Jaimin, is not a programmer. Answer in plain English in at most 6 short lines. Use ONLY the facts in the JSON; if the data does not show it, say you do not know and what to check. When asked how to fix something, give exact steps with where to click in hPanel / File Manager / phpMyAdmin. Never invent numbers.",
        "Question: " . $q . "\n\nCurrent system facts:\n" . json_encode(hlp_brief($s), JSON_UNESCAPED_UNICODE), 450);
    if ($txt) return ['answer' => trim($txt), 'by' => 'claude'];
    $lines = ["I can't reach Claude right now (no key found or the daily limit is used), so here is what the checks say:"];
    foreach (array_slice($s['findings'], 0, 4) as $f) $lines[] = '• ' . $f['title'] . ' — ' . $f['fix'];
    if (count($lines) === 1) $lines[] = 'No problems found.';
    return ['answer' => implode("\n", $lines), 'by' => 'rules'];
}
function hlp_report(array $s): string {
    $L = []; $L[] = 'HELP REPORT · ' . date('d M Y H:i') . ' · ' . HLP_VERSION;
    $L[] = 'Health ' . ($s['scores']['health']['score'] ?? '–') . ' · Speed ' . $s['scores']['speed']['grade'] . ' · Reliability ' . $s['scores']['rel']['grade'] . ' · Capacity ' . $s['scores']['cap']['grade'] . ' · UI ' . $s['scores']['ui']['grade'] . ' · Security ' . $s['scores']['sec']['grade'] . ' · Risk next hour ' . $s['risk']['label'] . ' ' . $s['risk']['pct'] . '%';
    $L[] = 'Database: ' . $s['db']['level'] . ($s['db']['use'] !== null ? ', connections ' . round($s['db']['use'] * 100) . '% used' : '') . ($s['db']['last_err'] ? ', last error: ' . $s['db']['last_err'] : '');
    $L[] = 'Backend: ' . $s['backend']['level'] . ($s['backend']['rate'] !== null ? ', ' . round($s['backend']['rate'] * 100, 1) . '% ok' : '') . ($s['backend']['burst_rate'] !== null ? ', parallel test ' . round($s['backend']['burst_rate'] * 100) . '% ok' : '');
    $L[] = 'POS: ' . $s['pos']['level'] . ' · Pages not opening: ' . $s['pages_bad'];
    foreach ($s['findings'] as $f) $L[] = strtoupper($f['level']) . ': ' . $f['title'] . ' — ' . $f['why'];
    foreach (array_slice($s['apis'], 0, 8) as $a) if ($a['level'] !== 'strong') $L[] = 'API ' . $a['name'] . ': ' . $a['level'] . ' ' . $a['rate'] . '%' . ($a['why'] ? ' (' . $a['why'] . ')' : '');
    foreach (array_slice($s['page_doctor']['rows'] ?? [], 0, 8) as $p) if ($p['state'] !== 'ok' || $p['fail_pct'] >= 30 || $p['speed_ms'] > 3500) $L[] = 'PAGE ' . $p['label'] . ' (' . $p['file'] . '): ' . round($p['speed_ms'] / 1000, 1) . ' s, ' . $p['calls'] . ' requests, ' . $p['fail_pct'] . '% fail chance, desktop ' . $p['d'] . ', phone ' . $p['m'] . ', responsive ' . $p['r'] . ' — ' . implode(' | ', array_map(fn($f) => $f['text'], array_slice($p['found'], 0, 3)));
    foreach ($s['files'] as $x) $L[] = 'File ' . $x['state'] . ': ' . $x['path'] . ' — ' . $x['note'];
    if (!empty($s['errorlog']['found'])) $L[] = 'Error log: ' . $s['errorlog']['total'] . ' lines in 24 h (' . $s['errorlog']['dbfail'] . ' database-connection)';
    return implode("\n", $L);
}

/* ───────────────────────── run everything that is due ───────────────────────── */
function hlp_run(bool $force = false): array {
    $lock = @fopen(hlp_dir() . '/run.lock', 'c'); if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) return ['skipped' => 'another run is in progress'];
    $t0 = microtime(true); $now = time(); $min = (int)date('i', $now); $did = [];
    $due5 = $force || $min % 5 === 0 || !hlp_kv_get('last_full', 0) || $now - (int)hlp_kv_get('last_full', 0) >= 290;
    $db = hlp_check_db(); $did[] = 'db'; hlp_check_backend(); $did[] = 'backend'; hlp_check_pos(); $did[] = 'pos';
    if ($due5) {
        hlp_check_burst(8); $did[] = 'burst'; hlp_check_server(); hlp_check_security(); hlp_check_errorlog();
        try { hlp_check_files(); } catch (Throwable $e) { hlp_log('help', 'files', false, 0, $e->getMessage()); }
        hlp_check_pages(); $did[] = 'pages';
        if ($force || !hlp_kv_get('last_ssl', 0) || $now - hlp_kv_get('last_ssl', 0) > 3600) { hlp_check_ssl(); hlp_kv_set('last_ssl', $now); }
        hlp_kv_set('last_full', $now);
    }
    hlp_update_incidents();
    hlp_kv_set('last_cron', time());
    if ($due5) { try { hlp_doctor_note(hlp_status(), $force); $did[] = 'doctor'; } catch (Throwable $e) {} }
    if ($min === 17 && (int)date('G', $now) === 3 && !hlp_kv_get('purged_' . date('Ymd'), 0)) {
        hlp_store()->prepare('DELETE FROM hlp_checks WHERE ts<?')->execute([$now - HLP_RETENTION_DAYS * 86400]);
        hlp_store()->prepare('DELETE FROM hlp_incidents WHERE started<?')->execute([$now - 30 * 86400]);
        hlp_kv_set('purged_' . date('Ymd'), 1);
    }
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    return ['ran' => $did, 'seconds' => round(microtime(true) - $t0, 1), 'db_ok' => $db['ok']];
}