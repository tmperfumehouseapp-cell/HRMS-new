<?php
/**
 * ⚡ Speed of the WhatsApp screens: every 4th call of a WhatsApp action is timed (action, milliseconds) so the slowest calls can be seen, plus a health check of the server
 * (Opcache, APCu, load, database) and the "live" switch.
 *   wq.speed       GET   admin   → slowest actions (last 24 h), server health, live switch
 *   wq.speed_cfg   POST  admin   → {live: 0|1}
 *   wa.g_wait      GET   sig     → holds the call up to 8 s and answers the moment "my chat list" changes (only when the live switch is on; otherwise answers at once with live=0)
 * PHP 7.4 safe.
 */
if (!function_exists('wsp_tables')) {

function wsp_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS wq_speed (id BIGINT AUTO_INCREMENT PRIMARY KEY, action VARCHAR(40) NOT NULL, ms INT NOT NULL, at DATETIME NOT NULL, KEY k_at (at), KEY k_act (action, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); return $ok = true; }
    catch (Throwable $e) { return $ok = false; }
}
/** Start timing the current request (called once, at the top of the WhatsApp router). */
function wsp_boot(PDO $pdo, string $action): void
{
    if (!preg_match('/^(wa|wq|fu|wj|jn|learn|wch|wl|wmo)\.[a-z0-9_]+$/', $action) || $action === 'wa.g_wait' || $action === 'wa.webhook' || mt_rand(1, 4) !== 1) return;
    $t0 = microtime(true);
    register_shutdown_function(function () use ($pdo, $action, $t0) {
        try { if (!wsp_tables($pdo)) return; $ms = (int)round((microtime(true) - $t0) * 1000);
            $pdo->prepare("INSERT INTO wq_speed (action, ms, at) VALUES (?, ?, ?)")->execute([$action, $ms, date('Y-m-d H:i:s')]);
            if (mt_rand(1, 200) === 1) $pdo->exec("DELETE FROM wq_speed WHERE at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
        } catch (Throwable $e) { }
    });
}
function wsp_report(PDO $pdo): array
{
    $rows = []; $total = 0;
    try { foreach ($pdo->query("SELECT action, COUNT(*) n, ROUND(AVG(ms)) avg_ms, MAX(ms) max_ms, SUM(ms > 1500) slow FROM wq_speed WHERE at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY action ORDER BY AVG(ms) * COUNT(*) DESC LIMIT 14")->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[] = ['action' => (string)$r['action'], 'n' => (int)$r['n'], 'avg' => (int)$r['avg_ms'], 'max' => (int)$r['max_ms'], 'slow' => (int)$r['slow']]; $total += (int)$r['n']; } } catch (Throwable $e) { }
    $h = []; $oc = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
    $h[] = ['k' => 'Opcache', 'v' => $oc && !empty($oc['opcache_enabled']) ? 'on' : 'OFF', 'ok' => $oc && !empty($oc['opcache_enabled']), 'tip' => 'Off = PHP re-reads every file on every call. Turn it on in the PHP settings of the site.'];
    $h[] = ['k' => 'APCu', 'v' => function_exists('apcu_fetch') && ini_get('apc.enabled') ? 'on' : 'off', 'ok' => function_exists('apcu_fetch') && (bool)ini_get('apc.enabled'), 'tip' => 'Optional: lets the chat list keep its chips for a few seconds.'];
    $cores = 0; try { $ci = @file_get_contents('/proc/cpuinfo'); if ($ci) $cores = (int)preg_match_all('/^processor\s*:/m', $ci); } catch (Throwable $e) { }
    $ld = function_exists('sys_getloadavg') ? @sys_getloadavg() : null; if ($ld) $h[] = ['k' => 'Server load (1 / 5 / 15 min)' . ($cores ? ' · ' . $cores . ' CPU cores' : ''), 'v' => round($ld[0], 2) . ' / ' . round($ld[1], 2) . ' / ' . round($ld[2], 2), 'ok' => $ld[0] < max(1.0, $cores * 0.85), 'tip' => 'Load near or above the number of CPU cores = the server is overloaded' . ($cores ? '' : ' (CPU cores unknown)') . '.'];
    try { $th = $pdo->query("SHOW GLOBAL STATUS LIKE 'Threads_running'")->fetch(PDO::FETCH_NUM); if ($th) $h[] = ['k' => 'Database queries running now', 'v' => (string)$th[1], 'ok' => (int)$th[1] < 8, 'tip' => 'Many at once = the database is busy.']; } catch (Throwable $e) { }
    try { $sl = $pdo->query("SHOW VARIABLES LIKE 'slow_query_log'")->fetch(PDO::FETCH_NUM); if ($sl) $h[] = ['k' => 'MySQL slow-query log', 'v' => (string)$sl[1], 'ok' => strtoupper((string)$sl[1]) === 'ON', 'tip' => 'Turn it on to see exactly which query is slow.']; } catch (Throwable $e) { }
    try { $mm = $pdo->query("SELECT COUNT(*) FROM wa_messages")->fetchColumn(); $h[] = ['k' => 'Messages stored', 'v' => number_format((int)$mm, 0, '.', ','), 'ok' => true, 'tip' => '']; } catch (Throwable $e) { }
    $h[] = ['k' => 'PHP', 'v' => PHP_VERSION, 'ok' => true, 'tip' => ''];
    return ['rows' => $rows, 'samples' => $total, 'health' => $h, 'live' => (string)setting_get($pdo, 'wa_live', '0') === '1', 'learn' => function_exists('wlr_status') ? wlr_status($pdo) : null];
}

}

$__wsp = (string)($action ?? ($_GET['action'] ?? ''));
if (isset($pdo) && $pdo instanceof PDO) wsp_boot($pdo, $__wsp);   // time this call
if (in_array($__wsp, ['wq.speed', 'wq.speed_cfg', 'wa.g_wait'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth();
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    if ($__wsp === 'wa.g_wait') {
        $live = (string)setting_get($pdo, 'wa_live', '0') === '1'; $sig = (string)($_GET['sig'] ?? ''); $me = (int)($auth['employee_id'] ?? 0);
        if (!$live || $me <= 0 || $sig === '' || !function_exists('wag_sig')) json_response(['live' => 0, 'sig' => '']);
        $t0 = microtime(true); $cur = $sig;
        while (microtime(true) - $t0 < 8) { $cur = wag_sig($pdo, $me); if ($cur !== $sig) break; usleep(1000000); }
        json_response(['live' => 1, 'sig' => $cur, 'changed' => $cur !== $sig]);
    }
    if (!wa_is_admin($pdo, $auth)) json_response(['error' => 'Only Admin, HR or the control tower can do this.'], 403);
    if ($__wsp === 'wq.speed') { if ($isPost) json_response(['error' => 'Method not allowed.'], 405); wsp_tables($pdo); json_response(['ok' => true] + wsp_report($pdo)); }
    if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); if (array_key_exists('live', $in)) setting_set($pdo, 'wa_live', !empty($in['live']) && (string)$in['live'] !== '0' ? '1' : '0');
    json_response(['ok' => true, 'message' => 'Saved.', 'live' => (string)setting_get($pdo, 'wa_live', '0') === '1']);
}
