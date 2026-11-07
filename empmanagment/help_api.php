<?php
// help_api.php — talks to the Help page. Every request must carry your Help key.
require_once __DIR__ . '/help_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function hlp_out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

try {
    // Who is asking? Your HRMS login (Admin only). The page sends the login token; we ask api.php "who is this?" and require role Admin.
    $key = (string)($_SERVER['HTTP_X_HELP_KEY'] ?? '');            // optional: the old key still works (for scripts), never asked from you
    $tok = trim((string)($_SERVER['HTTP_X_HELP_TOKEN'] ?? ''));
    $allowed = $key !== '' && hash_equals(HELP_KEY, $key);
    if (!$allowed) {
        if ($tok === '' || strlen($tok) > 600 || preg_match('/[^A-Za-z0-9._\-+\/=]/', $tok)) hlp_out(['ok' => false, 'error' => 'Please log in to the HRMS again.'], 403);
        $ck = 'auth_' . md5($tok); $okTill = (int)hlp_kv_get($ck, 0);
        if ($okTill < time()) {
            $r = hlp_http(hlp_api_url() . '?action=me.flags&_t=' . time(), ['headers' => ['Authorization: Bearer ' . $tok], 'timeout' => 8]);
            $j = json_decode($r['body'], true); $role = is_array($j) ? ($j['data']['role'] ?? $j['role'] ?? '') : '';
            if ($r['code'] !== 200 || strcasecmp((string)$role, 'Admin') !== 0) hlp_out(['ok' => false, 'error' => 'Only an Admin login can open Help.'], 403);
            hlp_kv_set($ck, time() + 120);
        }
    }

    $action = (string)($_GET['action'] ?? 'status');
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];

    switch ($action) {
        case 'status':
            // Opening / refreshing the page also keeps the checks alive: if the checker has not run for 75 s, run it right after answering.
            $lc = (int)hlp_kv_get('last_cron', 0); $stale = $lc === 0 || time() - $lc > 75;
            $out = json_encode(['ok' => true, 'status' => hlp_status(), 'refreshing' => $stale], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            header('Content-Length: ' . strlen($out)); header('Connection: close'); echo $out;
            if ($stale) {
                ignore_user_abort(true); @set_time_limit(170);
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
                else { while (ob_get_level()) @ob_end_flush(); flush(); }
                try { hlp_run(false); } catch (Throwable $e) { hlp_log('help', 'run', false, 0, $e->getMessage()); }
            }
            exit;

        case 'run': // "Check now" button — at most once every 20 seconds
            $last = (int)hlp_kv_get('last_manual_run', 0);
            if (time() - $last < 20) hlp_out(['ok' => true, 'note' => 'A check ran a moment ago', 'status' => hlp_status()]);
            hlp_kv_set('last_manual_run', time());
            @set_time_limit(150);
            $r = hlp_run(true);
            hlp_out(['ok' => true, 'run' => $r, 'status' => hlp_status()]);

        case 'recheck': // "Recheck this" on one problem card: run just that check again now and answer with its fresh result
            $what = (string)($body['what'] ?? $_GET['what'] ?? '');
            $lastRc = (int)hlp_kv_get('last_rc_' . $what, 0);
            if (time() - $lastRc < 4) hlp_out(['ok' => true, 'note' => 'Checked a moment ago', 'status' => hlp_status()]);
            hlp_kv_set('last_rc_' . $what, time());
            @set_time_limit(90);
            $res = null; $label = '';
            if ($what === 'pos') { $label = 'The POS entry page'; $r = hlp_check_pos(); $res = ['ok' => !empty($r['ok']), 'ms' => (int)($r['ms'] ?? 0), 'detail' => !empty($r['ok']) ? 'opened properly' : 'did not open properly (' . ($r['detail'] ?? '?') . ')'];
            } elseif ($what === 'db') { $label = 'The database'; $r = hlp_check_db(); $res = ['ok' => !empty($r['ok']), 'ms' => (int)($r['ms'] ?? 0), 'detail' => !empty($r['ok']) ? 'answered' : (string)($r['err'] ?? 'did not answer')];
            } elseif ($what === 'backend') { $label = 'The backend (api.php)'; $r = hlp_check_backend(); $res = ['ok' => !empty($r['ok']), 'ms' => (int)($r['ms'] ?? 0), 'detail' => !empty($r['ok']) ? 'answered correctly' : (string)($r['detail'] ?? 'did not answer')];
            } elseif ($what === 'burst') { $label = 'Many requests together'; $r = hlp_check_burst(8); $res = ['ok' => (int)$r['ok'] >= (int)$r['n'] - 0, 'ms' => 0, 'detail' => $r['ok'] . ' of ' . $r['n'] . ' answered'];
            } elseif ($what === 'security') { $label = 'Files on the server'; $GLOBALS['hlp_force_security'] = true; hlp_check_security(); $sec = hlp_kv_get('security', []); $n = count($sec['warn'] ?? []); $res = ['ok' => $n === 0, 'ms' => 0, 'detail' => $n === 0 ? 'no downloadable backup or test file found' : $n . ' file(s) can still be downloaded'];
            } else hlp_out(['ok' => false, 'error' => 'Unknown check.'], 422);
            hlp_out(['ok' => true, 'label' => $label, 'result' => $res, 'status' => hlp_status()]);

        case 'probe_report': // results of the API calls your browser made
            $items = array_slice((array)($body['items'] ?? []), 0, 40);
            foreach ($items as $it) {
                $name = substr(preg_replace('/[^A-Za-z0-9_.\-]/', '', (string)($it['name'] ?? '')), 0, 60);
                if ($name === '') continue;
                hlp_log('api', $name, !empty($it['ok']), (int)min(60000, max(0, (int)($it['ms'] ?? 0))), (string)($it['detail'] ?? ''));
            }
            hlp_out(['ok' => true]);

        case 'ui_report': // results of the "Run page check" button (speed + phone/tablet/desktop checks)
            $allowed = array_column(hlp_pages(), 'file'); $rows = [];
            $num = fn($v, $hi) => (int)max(0, min($hi, (int)$v));
            $st3 = fn($x) => in_array($x, ['ok', 'warn', 'bad'], true) ? $x : 'warn';
            $strs = fn($a) => array_map(fn($x) => mb_substr((string)$x, 0, 110), array_slice((array)$a, 0, 4));
            $rank = ['ok' => 0, 'warn' => 1, 'bad' => 2];
            foreach (array_slice((array)($body['rows'] ?? []), 0, 120) as $r) {
                $file = (string)($r['file'] ?? ''); if (!in_array($file, $allowed, true)) continue;
                $d = $st3($r['d'] ?? 'ok'); $m = $st3($r['m'] ?? 'ok'); $rr = $st3($r['r'] ?? 'ok'); $worst = 'ok';
                foreach ([$d, $m, $rr] as $x) if ($rank[$x] > $rank[$worst]) $worst = $x;
                $parts = []; foreach (['file', 'scripts', 'data', 'images', 'draw'] as $k) $parts[$k] = $num($r['parts'][$k] ?? 0, 120000);
                $row = [
                    'file' => $file, 'state' => $worst, 'd' => $d, 'm' => $m, 'r' => $rr,
                    'issues' => $strs(array_merge((array)($r['im'] ?? []), (array)($r['ir'] ?? []), (array)($r['id'] ?? []))),
                    'im' => $strs($r['im'] ?? []), 'ir' => $strs($r['ir'] ?? []), 'id' => $strs($r['id'] ?? []),
                    'speed_ms' => $num($r['speed_ms'] ?? 0, 120000), 'calls' => $num($r['calls'] ?? 0, 500), 'failed_calls' => $num($r['failed_calls'] ?? 0, 500),
                    'slow' => ['name' => substr(preg_replace('/[^A-Za-z0-9_.\-]/', '', (string)($r['slow']['name'] ?? '')), 0, 60), 'ms' => $num($r['slow']['ms'] ?? 0, 120000)],
                    'parts' => $parts, 'tiny' => $num($r['tiny'] ?? 0, 5000), 'small_text' => $num($r['small_text'] ?? 0, 5000), 'broken_img' => $num($r['broken_img'] ?? 0, 500),
                    'over390' => $num($r['over390'] ?? 0, 5000), 'over768' => $num($r['over768'] ?? 0, 5000),
                    'offender' => mb_substr(preg_replace('/[^A-Za-z0-9_.\-# ]/', '', (string)($r['offender'] ?? '')), 0, 40),
                ];
                $rows[$file] = $row;
                if ($row['speed_ms'] > 0) hlp_log('pspeed', $file, $row['failed_calls'] === 0, $row['speed_ms'], (string)$row['calls']);
            }
            $old = hlp_kv_get('ui_report', null);
            if (!empty($body['partial']) && is_array($old) && !empty($old['rows'])) { $merged = []; foreach ($old['rows'] as $o) $merged[$o['file']] = $o; foreach ($rows as $f => $r) $merged[$f] = $r; $rows = $merged; }
            hlp_kv_set('ui_report', ['at' => time(), 'width' => 390, 'rows' => array_values($rows)]);
            hlp_out(['ok' => true, 'saved' => count($rows)]);

        case 'features': // the Feature check: read-only scan of every page's buttons / links / server calls
            require_once __DIR__ . '/help_features.php';
            hlp_out(['ok' => true, 'scan' => hlpf_get(!empty($body['force'])), 'reqs' => hlpf_reqs()]);

        case 'features_last': // the last saved scan (no new scan) + the requirement list
            require_once __DIR__ . '/help_features.php';
            $c = hlp_kv_get('features_scan', null);
            hlp_out(['ok' => true, 'scan' => is_array($c) ? $c : null, 'reqs' => hlpf_reqs()]);

        case 'req_add': case 'req_set': case 'req_del': // new-requirement list kept by Help
            require_once __DIR__ . '/help_features.php';
            $list = hlpf_reqs();
            if ($action === 'req_add') {
                $t = trim(mb_substr((string)($body['text'] ?? ''), 0, 600)); if ($t === '') hlp_out(['ok' => false, 'error' => 'Write the requirement first.'], 422);
                $list[] = ['id' => substr(bin2hex(random_bytes(5)), 0, 10), 'text' => $t, 'status' => 'open', 'at' => time()];
            } else {
                $id = (string)($body['id'] ?? '');
                foreach ($list as $i => $r) if ($r['id'] === $id) {
                    if ($action === 'req_del') unset($list[$i]);
                    else $list[$i]['status'] = in_array(($body['status'] ?? ''), ['open', 'doing', 'done'], true) ? $body['status'] : 'open';
                }
            }
            hlpf_req_save(array_values($list));
            hlp_out(['ok' => true, 'reqs' => hlpf_reqs()]);

        case 'full_save': // the last Full check (one kept at a time): steps + final report + fix prompt
            $steps = []; foreach (array_slice((array)($body['steps'] ?? []), 0, 8) as $x) $steps[] = ['n' => mb_substr((string)($x['n'] ?? ''), 0, 120), 'st' => in_array(($x['st'] ?? ''), ['ok', 'warn', 'bad'], true) ? $x['st'] : 'warn', 'note' => mb_substr((string)($x['note'] ?? ''), 0, 400), 'list' => array_map(fn($v) => mb_substr((string)$v, 0, 200), array_slice((array)($x['list'] ?? []), 0, 15))];
            hlp_kv_set('full_last', ['at' => (int)($body['at'] ?? time()), 'steps' => $steps, 'report' => mb_substr((string)($body['report'] ?? ''), 0, 60000), 'prompt' => mb_substr((string)($body['prompt'] ?? ''), 0, 60000)]);
            hlp_out(['ok' => true]);

        case 'full_get':
            $l = hlp_kv_get('full_last', null);
            hlp_out(['ok' => true, 'last' => is_array($l) ? $l : null]);

        case 'ask':
            hlp_out(['ok' => true] + hlp_ask((string)($body['q'] ?? ''), hlp_status()));

        case 'report':
            hlp_out(['ok' => true, 'text' => hlp_report(hlp_status())]);

        case 'discover': // suggests read-only API calls worth watching
            $src = ''; $files = [__DIR__ . '/api.php'];
            foreach (['api', 'handlers', 'modules', 'actions', 'includes', 'routes'] as $sub) { $g = glob(__DIR__ . '/' . $sub . '/*.php') ?: []; foreach (array_slice($g, 0, 40) as $f) $files[] = $f; }
            foreach ($files as $f) if (is_file($f) && filesize($f) < 2500000) $src .= "\n" . (string)file_get_contents($f);
            $names = [];
            if (preg_match_all('/case\s+[\'"]([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)[\'"]\s*:/i', $src, $m)) foreach ($m[1] as $n) $names[$n] = 1;
            if (preg_match_all('/\$action\s*===?\s*[\'"]([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)[\'"]/i', $src, $m)) foreach ($m[1] as $n) $names[$n] = 1;
            if (preg_match_all('/[\'"]([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)[\'"]\s*=>\s*(?:function|\[|[\'"]|fn)/i', $src, $m)) foreach ($m[1] as $n) $names[$n] = 1;
            $all = array_keys($names); sort($all); $safe = [];
            $unsafeRe = '/(save|delete|remove|update|create|add|send|submit|approve|reject|set|upload|import|reset|login|logout|clear|mark|toggle|edit|cancel|assign|generate|sync|run|start|stop|pay|reply|post|publish|move|copy|restore|archive|close|open|punch|checkin|check_in|clock|import|export|password|token|otp|invite|notify|resend|bulk|merge|split|refund|issue|revoke|grant|lock|unlock|register|apply|accept|decline|confirm)/i';
            foreach ($all as $n) {
                if (preg_match($unsafeRe, $n)) continue;
                $last = substr(strrchr('.' . $n, '.'), 1);
                if (in_array($last, ['list', 'get', 'flags', 'summary', 'stats', 'count', 'counts', 'overview', 'today', 'status', 'feed', 'dashboard', 'badges', 'unread', 'me', 'recent', 'all', 'mine', 'lookup', 'options'], true) || strpos($last, 'list') === 0 || strpos($last, 'get') === 0) $safe[] = $n;
            }
            hlp_out(['ok' => true, 'all' => array_slice($all, 0, 300), 'safe' => array_slice($safe, 0, 80), 'scanned_files' => count($files)]);

        default:
            hlp_out(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    hlp_out(['ok' => false, 'error' => $e->getMessage()], 500);
}