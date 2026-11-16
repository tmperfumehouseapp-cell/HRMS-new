<?php
/* 🧾 Shiprocket API log. Every request the HRMS makes to Shiprocket is written here (time, method, address, what was sent, the HTTP answer code, how long it took, what came back),
 * so that when Shiprocket support asks "send us your logs" there is something exact to copy. Passwords and login tokens are NEVER stored: they are replaced by "***".
 * Kept for 14 days. Read on Dispatch > Shiprocket box > "Shiprocket logs". Logging can never break a real call: every step is inside try/catch. */
if (!function_exists('sr_log_add')) {
    function sr_log_pdo() { $p = $GLOBALS['pdo'] ?? null; return $p instanceof PDO ? $p : null; }
    function sr_log_table(PDO $pdo): bool
    {
        static $ok = null; if ($ok !== null) return $ok;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sr_api_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, method VARCHAR(8) NOT NULL DEFAULT '', path VARCHAR(255) NOT NULL DEFAULT '', http INT NOT NULL DEFAULT 0,
                ms INT NOT NULL DEFAULT 0, src VARCHAR(12) NOT NULL DEFAULT '', req TEXT NULL, res TEXT NULL, err VARCHAR(255) NOT NULL DEFAULT '', KEY k_at (at), KEY k_http (http, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return $ok = true;
        } catch (Throwable $e) { return $ok = false; }
    }
    /** hide secrets inside a request / answer before it is stored */
    function sr_log_mask($v)
    {
        if (is_array($v)) { foreach ($v as $k => $x) { $v[$k] = (is_string($k) && preg_match('/^(password|token|access_token|refresh_token|api_key|secret)$/i', $k)) ? '***' : sr_log_mask($x); } return $v; }
        return $v;
    }
    function sr_log_add(string $method, string $url, $body, int $http, string $err, $out, int $ms, string $src = ''): void
    {
        try {
            $pdo = sr_log_pdo(); if (!$pdo || !sr_log_table($pdo)) return;
            $u = parse_url($url); $path = ($u['path'] ?? '') . (isset($u['query']) ? '?' . $u['query'] : '');
            $req = $body === null ? '' : json_encode(sr_log_mask($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $res = ''; if (is_string($out) && $out !== '') { $j = json_decode($out, true); $res = is_array($j) ? json_encode(sr_log_mask($j), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $out; }
            $pdo->prepare("INSERT INTO sr_api_log (at, method, path, http, ms, src, req, res, err) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([date('Y-m-d H:i:s'), substr($method, 0, 8), mb_substr($path, 0, 250), $http, $ms, substr($src, 0, 12), mb_substr((string)$req, 0, 3000), mb_substr((string)$res, 0, 3000), mb_substr($err, 0, 250)]);
            if (mt_rand(1, 200) === 1) $pdo->exec("DELETE FROM sr_api_log WHERE at < '" . date('Y-m-d H:i:s', time() - 14 * 86400) . "' LIMIT 5000");   // keep 14 days
        } catch (Throwable $e) { }
    }
    /** rows for the page / the text file. $f: errors (bool), q (text in the address / request / answer), limit */
    function sr_log_rows(PDO $pdo, array $f): array
    {
        if (!sr_log_table($pdo)) return [];
        $w = ['1=1']; $a = [];
        if (!empty($f['errors'])) $w[] = '(http < 200 OR http >= 300)';
        $q = trim((string)($f['q'] ?? '')); if ($q !== '') { $w[] = '(path LIKE ? OR req LIKE ? OR res LIKE ?)'; $a[] = "%$q%"; $a[] = "%$q%"; $a[] = "%$q%"; }
        $lim = max(10, min(2000, (int)($f['limit'] ?? 200)));
        $st = $pdo->prepare("SELECT id, at, method, path, http, ms, src, req, res, err FROM sr_api_log WHERE " . implode(' AND ', $w) . " ORDER BY id DESC LIMIT $lim"); $st->execute($a);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
