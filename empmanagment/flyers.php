<?php
/**
 * flyers.php — Flyer tracker for the HRMS (loaded by api.php for every action that starts with "fl.").
 * Employees take a photo of each car they put a flyer on; the photo carries the GPS place. The manager sees dots + coverage
 * squares on a map, gets "go here next" areas, and assigns an area to any employee.
 *
 * Put this file in the SAME folder as api.php. It creates its own tables the first time it runs (fl_drops, fl_assign, fl_settings).
 * PHP 7.4 safe. No change to any other table.
 */
if (!isset($pdo, $action) || !function_exists('json_response')) { return; }

const FL_CELL = 0.004;   // one coverage square = 0.004 degrees (about 440 m x 410 m in Surat)

/* ---------- helpers ---------- */
function fl_cell($lat, $lng) { return (int)floor($lat / FL_CELL) . '_' . (int)floor($lng / FL_CELL); }
function fl_cell_center($k) { $p = explode('_', $k); return [((int)$p[0] + 0.5) * FL_CELL, ((int)$p[1] + 0.5) * FL_CELL]; }
function fl_hav($la1, $lo1, $la2, $lo2) {
    $R = 6371000; $dLa = deg2rad($la2 - $la1); $dLo = deg2rad($lo2 - $lo1);
    $x = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo / 2) ** 2;
    return $R * 2 * atan2(sqrt($x), sqrt(1 - $x));
}
function fl_valid_sql($a = '') { $p = $a ? $a . '.' : ''; return "({$p}flag IS NULL OR {$p}flag_ok = 1)"; }
function fl_day_start($d) { return $d . ' 00:00:00'; }
function fl_dir() { return __DIR__ . '/uploads/flyers/'; }

function fl_cfg(PDO $pdo) {
    $v = [];
    try { foreach ($pdo->query("SELECT skey, svalue FROM fl_settings")->fetchAll() as $r) $v[$r['skey']] = $r['svalue']; } catch (Throwable $e) {}
    $g = function ($k, $d) use ($v) { return (isset($v[$k]) && $v[$k] !== '') ? $v[$k] : $d; };
    return [
        'lat'    => (float)$g('center_lat', '21.1420'),     // centre of the area you want to cover (change it on the Flyer Map page)
        'lng'    => (float)$g('center_lng', '72.7780'),
        'radius' => max(1, (float)$g('radius_km', '6')),    // km around the centre
        'goal'   => max(1, (int)$g('cell_goal', '15')),     // flyers that make one square "covered"
        'daily'  => max(1, (int)$g('daily_target', '40')),
    ];
}
function fl_set(PDO $pdo, $k, $v) { $pdo->prepare("INSERT INTO fl_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([$k, (string)$v]); }

function fl_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    $have = true;   // one cheap probe per request: if the tables are missing (first use, restored database) they are created
    try { $pdo->query("SELECT 1 FROM fl_settings LIMIT 1"); $pdo->query("SELECT 1 FROM fl_assign LIMIT 1"); $pdo->query("SELECT 1 FROM fl_drops LIMIT 1"); } catch (Throwable $e) { $have = false; }
    if (!$have) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fl_drops (
            id INT AUTO_INCREMENT PRIMARY KEY,
            emp_id INT NOT NULL, emp_name VARCHAR(120) NOT NULL,
            lat DECIMAL(10,7) NOT NULL, lng DECIMAL(10,7) NOT NULL, accuracy FLOAT NULL,
            photo VARCHAR(160) NOT NULL, phash CHAR(64) NULL,
            flag VARCHAR(20) NULL, flag_ok TINYINT NOT NULL DEFAULT 0,
            cell VARCHAR(24) NOT NULL, assign_id INT NULL, via VARCHAR(10) NOT NULL DEFAULT 'live',
            created_at DATETIME NOT NULL,
            INDEX (created_at), INDEX (emp_id, created_at), INDEX (cell)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fl_assign (
            id INT AUTO_INCREMENT PRIMARY KEY,
            emp_id INT NOT NULL, emp_name VARCHAR(120) NOT NULL, assign_date DATE NOT NULL,
            area_name VARCHAR(120) NOT NULL, lat DECIMAL(10,7) NOT NULL DEFAULT 0, lng DECIMAL(10,7) NOT NULL DEFAULT 0,
            radius_m INT NOT NULL DEFAULT 900, target INT NOT NULL DEFAULT 40, note VARCHAR(255) NULL,
            created_by VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL,
            INDEX (assign_date), INDEX (emp_id, assign_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fl_settings (skey VARCHAR(40) PRIMARY KEY, svalue VARCHAR(200) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $d = fl_dir();
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (is_dir($d) && !is_file($d . '.htaccess')) {
        @file_put_contents($d . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        @file_put_contents($d . 'index.html', '');
    }
}

function fl_ahash($path) {
    if (!function_exists('imagecreatefromstring')) return null;
    $data = @file_get_contents($path); if ($data === false) return null;
    $im = @imagecreatefromstring($data); if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im);
    $t = imagecreatetruecolor(8, 8);
    imagecopyresampled($t, $im, 0, 0, 0, 0, 8, 8, $w, max(1, (int)($h * 0.86)));   // top 86% only: ignore the stamp bar at the bottom
    $vals = []; $sum = 0;
    for ($y = 0; $y < 8; $y++) for ($x = 0; $x < 8; $x++) {
        $c = imagecolorat($t, $x, $y);
        $g = ((($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255)) / 3; $vals[] = $g; $sum += $g;
    }
    $avg = $sum / 64; $b = '';
    foreach ($vals as $v) $b .= ($v >= $avg ? '1' : '0');
    imagedestroy($im); imagedestroy($t);
    return $b;
}
function fl_hamming($a, $b) {
    if (!$a || !$b || strlen($a) != 64 || strlen($b) != 64) return 99;
    $d = 0; for ($i = 0; $i < 64; $i++) if ($a[$i] !== $b[$i]) $d++; return $d;
}
function fl_thumb($src, $dst) {
    if (!function_exists('imagecreatefromstring')) return;
    $data = @file_get_contents($src); if ($data === false) return;
    $im = @imagecreatefromstring($data); if (!$im) return;
    $w = imagesx($im); $h = imagesy($im); $tw = 240; $th = max(1, (int)round($h * $tw / max(1, $w)));
    $t = imagecreatetruecolor($tw, $th);
    imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    @imagejpeg($t, $dst, 72); imagedestroy($im); imagedestroy($t);
}

/** who is calling: [emp_id (negative = admin login without an employee card), display name] */
function fl_who(PDO $pdo, array $auth) {
    $eid = (int)($auth['employee_id'] ?? 0);
    if ($eid > 0) {
        try { $st = $pdo->prepare("SELECT full_name FROM employees WHERE employee_id = ?"); $st->execute([$eid]); $n = (string)$st->fetchColumn(); if ($n !== '') return [$eid, $n]; } catch (Throwable $e) {}
        return [$eid, (string)($auth['username'] ?? 'Employee')];
    }
    return [-(int)($auth['user_id'] ?? 0), (string)($auth['username'] ?? 'Admin')];
}
function fl_is_mgr(array $auth) { return (($auth['role'] ?? '') === 'Admin') || user_can($auth, 'retail_store'); }

/** all grid squares inside the service area (+ any square that has flyers), with counts */
function fl_cells(PDO $pdo, array $cfg) {
    $cells = [];
    $dLat = $cfg['radius'] / 111.0; $dLng = $cfg['radius'] / (111.0 * cos(deg2rad($cfg['lat'])));
    for ($i = (int)floor(($cfg['lat'] - $dLat) / FL_CELL); $i <= (int)floor(($cfg['lat'] + $dLat) / FL_CELL); $i++) {
        for ($j = (int)floor(($cfg['lng'] - $dLng) / FL_CELL); $j <= (int)floor(($cfg['lng'] + $dLng) / FL_CELL); $j++) {
            $k = $i . '_' . $j; $c = fl_cell_center($k);
            $d = fl_hav($cfg['lat'], $cfg['lng'], $c[0], $c[1]);
            if ($d <= $cfg['radius'] * 1000) $cells[$k] = ['k' => $k, 'lat' => $c[0], 'lng' => $c[1], 'cnt' => 0, 'last' => null, 'in' => 1, 'dist' => $d / 1000];
        }
    }
    foreach ($pdo->query("SELECT cell, COUNT(*) c, MAX(created_at) l FROM fl_drops WHERE " . fl_valid_sql() . " GROUP BY cell")->fetchAll() as $r) {
        $k = $r['cell'];
        if (!isset($cells[$k])) { $c = fl_cell_center($k); $cells[$k] = ['k' => $k, 'lat' => $c[0], 'lng' => $c[1], 'cnt' => 0, 'last' => null, 'in' => 0, 'dist' => fl_hav($cfg['lat'], $cfg['lng'], $c[0], $c[1]) / 1000]; }
        $cells[$k]['cnt'] = (int)$r['c']; $cells[$k]['last'] = $r['l'];
    }
    return $cells;
}

/** "go here next": groups of neighbouring squares that need flyers, sized for about $target flyers */
function fl_suggest(PDO $pdo, array $cfg, $n = 3, $target = 40) {
    $cells = fl_cells($pdo, $cfg);
    $st = $pdo->prepare("SELECT lat, lng, radius_m FROM fl_assign WHERE assign_date >= ? AND radius_m > 0");
    $st->execute([date('Y-m-d')]); $assigned = $st->fetchAll();
    $cand = [];
    foreach ($cells as $k => $c) {
        if (!$c['in']) continue;
        $need = 0;
        if ($c['cnt'] < $cfg['goal']) $need = $cfg['goal'] - $c['cnt'];
        elseif ($c['last'] && (time() - strtotime($c['last'])) > 45 * 86400) $need = (int)ceil($cfg['goal'] / 2);   // covered long ago -> refresh
        if ($need <= 0) continue;
        foreach ($assigned as $a) { if (fl_hav((float)$a['lat'], (float)$a['lng'], $c['lat'], $c['lng']) <= (float)$a['radius_m'] + 200) continue 2; }
        $c['need'] = $need; $cand[$k] = $c;
    }
    $out = [];
    for ($s = 0; $s < $n && $cand; $s++) {
        $seed = null; $best = -1e9;
        foreach ($cand as $c) { $sc = $c['need'] / $cfg['goal'] * 100 - $c['dist'] * 4; if ($sc > $best) { $best = $sc; $seed = $c; } }
        $near = [];
        foreach ($cand as $c) { $d = fl_hav($seed['lat'], $seed['lng'], $c['lat'], $c['lng']); if ($d <= 1500) $near[] = [$d, $c]; }
        usort($near, function ($a, $b) { return $a[0] <=> $b[0]; });
        $grp = []; $sum = 0;
        foreach ($near as $pair) { if ($sum >= $target || count($grp) >= 10) break; $grp[] = $pair[1]; $sum += $pair[1]['need']; }
        $wl = 0; $wl2 = 0; $wn = 0;
        foreach ($grp as $c) { $wl += $c['lat'] * $c['need']; $wl2 += $c['lng'] * $c['need']; $wn += $c['need']; unset($cand[$c['k']]); }
        $cl = $wl / $wn; $cn = $wl2 / $wn; $rad = 350;
        foreach ($grp as $c) $rad = max($rad, fl_hav($cl, $cn, $c['lat'], $c['lng']) + 260);
        $out[] = ['lat' => round($cl, 6), 'lng' => round($cn, 6), 'need' => min($wn, $target * 2), 'flyers' => min($wn, $target),
                  'squares' => count($grp), 'radius_m' => (int)round($rad), 'dist_km' => round(fl_hav($cfg['lat'], $cfg['lng'], $cl, $cn) / 1000, 1),
                  'cells' => array_column($grp, 'k')];
    }
    return $out;
}

/* ---------- actions ---------- */
/** Should this person see a "Flyers" item in their menu? Used by fl.badge and by bd.menu_ctx. */
function fl_badge_data(PDO $pdo, array $auth): array
{
    fl_ensure($pdo); $today = date('Y-m-d'); $who = fl_who($pdo, $auth);
    $st = $pdo->prepare("SELECT COUNT(*) FROM fl_assign WHERE emp_id = ? AND assign_date >= ?"); $st->execute([$who[0], $today]);
    $has = (int)$st->fetchColumn() > 0;
    if (!$has) { $st = $pdo->prepare("SELECT COUNT(*) FROM fl_drops WHERE emp_id = ? AND created_at >= ?"); $st->execute([$who[0], fl_day_start(date('Y-m-d', strtotime('-30 days')))]); $has = (int)$st->fetchColumn() > 0; }
    $mgr = fl_is_mgr($auth);
    return ['show' => ($has || $mgr), 'manager' => $mgr];
}

if (strpos((string)$action, 'fl.') !== 0) { return; }

try {
    fl_ensure($pdo);
    $today = date('Y-m-d');

    switch ($action) {

    /* ===== any logged-in person ===== */
    case 'fl.badge': {          // should this person see a "Flyers" item in their menu?
        $auth = require_auth();
        json_response(fl_badge_data($pdo, $auth));
        break;
    }

    case 'fl.today': {
        $auth = require_auth(); $who = fl_who($pdo, $auth); $mgr = fl_is_mgr($auth); $cfg = fl_cfg($pdo); $eid = $who[0];
        $c = $pdo->prepare("SELECT COUNT(*) FROM fl_drops WHERE emp_id = ? AND created_at >= ? AND " . fl_valid_sql()); $c->execute([$eid, fl_day_start($today)]);
        $f = $pdo->prepare("SELECT COUNT(*) FROM fl_drops WHERE emp_id = ? AND created_at >= ? AND flag IS NOT NULL AND flag_ok = 0"); $f->execute([$eid, fl_day_start($today)]);
        $as = $pdo->prepare("SELECT id, area_name, lat, lng, radius_m, target, note FROM fl_assign WHERE emp_id = ? AND assign_date = ? ORDER BY id DESC LIMIT 1"); $as->execute([$eid, $today]); $a = $as->fetch() ?: null;
        $l = $pdo->prepare("SELECT id, created_at, flag, flag_ok FROM fl_drops WHERE emp_id = ? ORDER BY id DESC LIMIT 8"); $l->execute([$eid]);
        $d = $pdo->prepare("SELECT lat, lng FROM fl_drops WHERE emp_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 300"); $d->execute([$eid, fl_day_start($today)]);
        $nx = $pdo->prepare("SELECT area_name, assign_date, target FROM fl_assign WHERE emp_id = ? AND assign_date > ? ORDER BY assign_date LIMIT 3"); $nx->execute([$eid, $today]);
        $dots = []; foreach ($d->fetchAll() as $r) $dots[] = [(float)$r['lat'], (float)$r['lng']];
        $lastRows = []; foreach ($l->fetchAll() as $r) $lastRows[] = ['id' => (int)$r['id'], 'created_at' => $r['created_at'], 'flag' => $r['flag'], 'flag_ok' => (int)$r['flag_ok']];
        if ($a) { $a['id'] = (int)$a['id']; $a['lat'] = (float)$a['lat']; $a['lng'] = (float)$a['lng']; $a['radius_m'] = (int)$a['radius_m']; $a['target'] = (int)$a['target']; }
        json_response(['name' => $who[1], 'today' => (int)$c->fetchColumn(), 'flagged' => (int)$f->fetchColumn(), 'target' => (int)($a ? $a['target'] : $cfg['daily']),
                       'assign' => $a, 'can_drop' => ($a !== null || $mgr), 'is_manager' => $mgr, 'upcoming' => $nx->fetchAll(), 'last' => $lastRows, 'dots' => $dots, 'center' => [$cfg['lat'], $cfg['lng']]]);
        break;
    }

    case 'fl.save': {
        $auth = require_auth(); $who = fl_who($pdo, $auth); $mgr = fl_is_mgr($auth); $cfg = fl_cfg($pdo); $eid = $who[0];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Bad request'], 405);
        $as = $pdo->prepare("SELECT id, target FROM fl_assign WHERE emp_id = ? AND assign_date = ? ORDER BY id DESC LIMIT 1"); $as->execute([$eid, $today]); $a = $as->fetch();
        if (!$a && !$mgr) json_response(['error' => 'No flyer area is assigned to you today. Ask your manager.'], 403);
        $lat = $_POST['lat'] ?? ''; $lng = $_POST['lng'] ?? ''; $acc = is_numeric($_POST['accuracy'] ?? '') ? (float)$_POST['accuracy'] : null;
        $via = (($_POST['via'] ?? 'live') === 'file') ? 'file' : 'live';
        if (!is_numeric($lat) || !is_numeric($lng) || ((float)$lat == 0 && (float)$lng == 0) || abs((float)$lat) > 90 || abs((float)$lng) > 180)
            json_response(['error' => 'Location not captured. Switch GPS on and try again.'], 400);
        if (empty($_FILES['photo']['tmp_name'])) json_response(['error' => 'Photo is required. Take a photo of the car.'], 400);
        $lat = (float)$lat; $lng = (float)$lng;
        $saved = store_save_photo($_FILES['photo'], $eid > 0 ? $eid : 0, 'flyers', 'fl_');
        if (empty($saved[0])) json_response(['error' => $saved[1] ?: 'Could not save the photo.'], 400);
        $rel = $saved[0]; $full = __DIR__ . '/' . $rel; $fn = basename($rel);
        fl_thumb($full, fl_dir() . 'th_' . $fn);
        $ph = fl_ahash($full);

        $flag = null;
        $rows = $pdo->prepare("SELECT phash FROM fl_drops WHERE emp_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 500");
        $rows->execute([$eid, fl_day_start(date('Y-m-d', strtotime('-30 days')))]);
        foreach ($rows->fetchAll() as $r) { if (fl_hamming($ph, $r['phash']) <= 3) { $flag = 'duplicate'; break; } }
        if (!$flag) {
            $lp = $pdo->prepare("SELECT lat, lng, created_at FROM fl_drops WHERE emp_id = ? ORDER BY id DESC LIMIT 1"); $lp->execute([$eid]); $l = $lp->fetch();
            if ($l) { $dt = max(1, time() - strtotime($l['created_at'])); $dist = fl_hav((float)$l['lat'], (float)$l['lng'], $lat, $lng);
                      if ($dist > 800 && $dist / $dt * 3.6 > 120) $flag = 'teleport'; }
        }
        if (!$flag && $acc !== null && $acc > 100) $flag = 'low_gps';
        if (!$flag && fl_hav($cfg['lat'], $cfg['lng'], $lat, $lng) > 40000) $flag = 'far';

        $pdo->prepare("INSERT INTO fl_drops (emp_id, emp_name, lat, lng, accuracy, photo, phash, flag, cell, assign_id, via, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$eid, $who[1], $lat, $lng, $acc, $rel, $ph, $flag, fl_cell($lat, $lng), $a ? (int)$a['id'] : null, $via, date('Y-m-d H:i:s')]);
        $id = (int)$pdo->lastInsertId();
        $c = $pdo->prepare("SELECT COUNT(*) FROM fl_drops WHERE emp_id = ? AND created_at >= ? AND " . fl_valid_sql()); $c->execute([$eid, fl_day_start($today)]);
        $msgs = ['duplicate' => 'Same photo as an earlier one. Not counted. Take a new photo of a different car.',
                 'teleport' => 'Location jumped too far too fast. Not counted until the manager checks it.',
                 'low_gps' => 'GPS was weak. Saved, the manager will check it.', 'far' => 'This is far outside the flyer area. Saved, the manager will check it.'];
        json_response(['id' => $id, 'flag' => $flag, 'msg' => $flag ? $msgs[$flag] : '', 'today' => (int)$c->fetchColumn(), 'target' => (int)($a ? $a['target'] : $cfg['daily'])]);
        break;
    }

    case 'fl.photo': {          // photo / thumbnail: the person who took it, or a manager
        $auth = require_auth(); $who = fl_who($pdo, $auth);
        $st = $pdo->prepare("SELECT emp_id, photo FROM fl_drops WHERE id = ?"); $st->execute([(int)($_GET['id'] ?? 0)]); $r = $st->fetch();
        if (!$r || (!fl_is_mgr($auth) && (int)$r['emp_id'] !== (int)$who[0])) json_response(['error' => 'Not found'], 404);
        $fn = basename($r['photo']); $path = fl_dir() . $fn;
        if (!empty($_GET['t']) && is_file(fl_dir() . 'th_' . $fn)) $path = fl_dir() . 'th_' . $fn;
        if (!is_file($path)) $path = __DIR__ . '/' . ltrim((string)$r['photo'], '/');
        if (!is_file($path)) json_response(['error' => 'Photo file is missing'], 404);
        header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=86400'); header('Content-Length: ' . filesize($path));
        readfile($path); exit;
    }

    /* ===== manager only ===== */
    case 'fl.data': case 'fl.suggest': case 'fl.people': case 'fl.assigns': case 'fl.assign': case 'fl.assign_del':
    case 'fl.drops': case 'fl.approve': case 'fl.drop_del': case 'fl.settings': {
        $auth = require_auth();
        if (!fl_is_mgr($auth)) json_response(['error' => 'Only managers can open the Flyer Map.'], 403);
        $cfg = fl_cfg($pdo); $isPost = ($_SERVER['REQUEST_METHOD'] === 'POST'); $in = $isPost ? json_input() : [];

        if ($action === 'fl.data') {
            $re = '/^\d{4}-\d{2}-\d{2}$/';
            $from = preg_match($re, (string)($_GET['from'] ?? '')) ? $_GET['from'] : $today;
            $to   = preg_match($re, (string)($_GET['to'] ?? '')) ? $_GET['to'] : $today;
            $uid  = (int)($_GET['emp_id'] ?? 0);
            $q = "SELECT id, emp_id, lat, lng, flag, flag_ok, created_at FROM fl_drops WHERE created_at >= ? AND created_at < ?" . ($uid ? " AND emp_id = " . $uid : '') . " ORDER BY id DESC LIMIT 6000";
            $st = $pdo->prepare($q); $st->execute([fl_day_start($from), fl_day_start(date('Y-m-d', strtotime($to . ' +1 day')))]);
            $now = time(); $drops = []; $names = [];
            foreach ($st->fetchAll() as $r) $drops[] = [(int)$r['id'], (float)$r['lat'], (float)$r['lng'], (int)$r['emp_id'], (int)floor(($now - strtotime($r['created_at'])) / 86400), $r['flag'], (int)$r['flag_ok']];
            $cells = []; $tot = 0; $full = 0; $started = 0;
            foreach (fl_cells($pdo, $cfg) as $c) {
                if ($c['in']) { $tot++; if ($c['cnt'] >= $cfg['goal']) $full++; elseif ($c['cnt'] > 0) $started++; }
                if ($c['in'] || $c['cnt'] > 0) $cells[] = [$c['lat'], $c['lng'], $c['cnt'], $c['last'] ? (int)floor(($now - strtotime($c['last'])) / 86400) : null, $c['in']];
            }
            $one = function ($sql, $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); };
            $t0 = fl_day_start($today); $w0 = fl_day_start(date('Y-m-d', strtotime('-6 days')));
            $ps = $pdo->prepare("SELECT emp_id, emp_name, SUM(created_at >= ? AND " . fl_valid_sql() . ") today, SUM(created_at >= ? AND " . fl_valid_sql() . ") wk, COUNT(*) total, SUM(flag IS NOT NULL AND flag_ok = 0) flagged FROM fl_drops GROUP BY emp_id, emp_name ORDER BY today DESC, wk DESC");
            $ps->execute([$t0, $w0]); $people = $ps->fetchAll();
            $seen = []; foreach ($people as $p) $seen[(int)$p['emp_id']] = 1;
            $as = $pdo->prepare("SELECT DISTINCT emp_id, emp_name FROM fl_assign WHERE assign_date = ?"); $as->execute([$today]);
            foreach ($as->fetchAll() as $r) if (empty($seen[(int)$r['emp_id']])) $people[] = ['emp_id' => $r['emp_id'], 'emp_name' => $r['emp_name'], 'today' => 0, 'wk' => 0, 'total' => 0, 'flagged' => 0];
            json_response(['cfg' => $cfg, 'cell' => FL_CELL, 'drops' => $drops, 'cells' => $cells,
                'stats' => ['today' => $one("SELECT COUNT(*) FROM fl_drops WHERE created_at >= ? AND " . fl_valid_sql(), [$t0]),
                            'total' => $one("SELECT COUNT(*) FROM fl_drops WHERE " . fl_valid_sql()),
                            'flagged' => $one("SELECT COUNT(*) FROM fl_drops WHERE flag IS NOT NULL AND flag_ok = 0"),
                            'squares' => $tot, 'full' => $full, 'started' => $started],
                'people' => $people]);
        }
        if ($action === 'fl.suggest') json_response(['items' => fl_suggest($pdo, $cfg, 3, (int)($_GET['target'] ?? $cfg['daily']))]);

        if ($action === 'fl.people') {   // every active employee, for the Assign list
            $rows = $pdo->query("SELECT e.employee_id, e.full_name, e.employee_code, d.department_name FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.status != 'ex' ORDER BY e.full_name ASC")->fetchAll();
            $out = []; foreach ($rows as $r) $out[] = ['id' => (int)$r['employee_id'], 'name' => (string)$r['full_name'], 'code' => (string)$r['employee_code'], 'dept' => (string)($r['department_name'] ?? '')];
            json_response(['people' => $out]);
        }
        if ($action === 'fl.assigns') {
            $st = $pdo->prepare("SELECT a.id, a.emp_id, a.emp_name, a.assign_date, a.area_name, a.lat, a.lng, a.radius_m, a.target, a.note,
                    (SELECT COUNT(*) FROM fl_drops d WHERE d.assign_id = a.id AND " . fl_valid_sql('d') . ") done
                    FROM fl_assign a WHERE a.assign_date >= ? ORDER BY a.assign_date DESC, a.id DESC LIMIT 40");
            $st->execute([date('Y-m-d', strtotime('-2 days'))]);
            json_response(['items' => $st->fetchAll()]);
        }
        if ($action === 'fl.assign') {
            if (!$isPost) json_response(['error' => 'Bad request'], 405);
            // one or many people: emp_ids = [..]  (emp_id = one person still works)
            $ids = (isset($in['emp_ids']) && is_array($in['emp_ids'])) ? $in['emp_ids'] : [($in['emp_id'] ?? 0)];
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            $date = (string)($in['date'] ?? '');
            if (!$ids || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_response(['error' => 'Pick at least one employee and a date.'], 400);
            if (count($ids) > 300) json_response(['error' => 'Pick at most 300 people at a time.'], 400);
            if ($date < $today) json_response(['error' => 'Pick today or a later date.'], 400);
            $anywhere = !empty($in['anywhere']);
            if (!$anywhere && (!is_numeric($in['lat'] ?? '') || !is_numeric($in['lng'] ?? ''))) json_response(['error' => 'Pick an area on the map.'], 400);
            $name = trim((string)($in['area_name'] ?? '')); if ($name === '') $name = $anywhere ? 'Anywhere' : 'Area near ' . round((float)$in['lat'], 4) . ', ' . round((float)$in['lng'], 4);
            $find = $pdo->prepare("SELECT employee_id, full_name FROM employees WHERE employee_id = ? AND status != 'ex'");
            $del = $pdo->prepare("DELETE FROM fl_assign WHERE emp_id = ? AND assign_date = ?");
            $ins = $pdo->prepare("INSERT INTO fl_assign (emp_id, emp_name, assign_date, area_name, lat, lng, radius_m, target, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $saved = 0; $skipped = 0;
            foreach ($ids as $eid) {
                $find->execute([$eid]); $e = $find->fetch(); if (!$e) { $skipped++; continue; }
                $del->execute([(int)$e['employee_id'], $date]);   // one job per person per day: assigning again replaces it
                $ins->execute([(int)$e['employee_id'], (string)$e['full_name'], $date, mb_substr($name, 0, 120), $anywhere ? 0 : (float)$in['lat'], $anywhere ? 0 : (float)$in['lng'],
                               $anywhere ? 0 : max(200, min(5000, (int)($in['radius_m'] ?? 900))), max(1, min(1000, (int)($in['target'] ?? 40))),
                               mb_substr(trim((string)($in['note'] ?? '')), 0, 250), (string)($auth['username'] ?? ''), date('Y-m-d H:i:s')]);
                $saved++;
            }
            if (!$saved) json_response(['error' => 'Pick an employee and a date.'], 400);
            json_response(['ok' => true, 'saved' => $saved, 'skipped' => $skipped]);
        }
        if ($action === 'fl.assign_del') { $pdo->prepare("DELETE FROM fl_assign WHERE id = ?")->execute([(int)($in['id'] ?? 0)]); json_response(['ok' => true]); }

        if ($action === 'fl.drops') {   // photo review list
            $only = (($_GET['f'] ?? 'flagged') === 'all') ? '' : "AND flag IS NOT NULL AND flag_ok = 0";
            $uid = (int)($_GET['emp_id'] ?? 0); $off = max(0, (int)($_GET['offset'] ?? 0));
            $st = $pdo->query("SELECT id, emp_name, lat, lng, accuracy, flag, flag_ok, via, created_at FROM fl_drops WHERE 1 $only" . ($uid ? " AND emp_id = $uid" : '') . " ORDER BY id DESC LIMIT 48 OFFSET $off");
            json_response(['items' => $st->fetchAll()]);
        }
        if ($action === 'fl.approve') { $pdo->prepare("UPDATE fl_drops SET flag_ok = 1 WHERE id = ?")->execute([(int)($in['id'] ?? 0)]); json_response(['ok' => true]); }
        if ($action === 'fl.drop_del') {
            $id = (int)($in['id'] ?? 0);
            $r = $pdo->prepare("SELECT photo FROM fl_drops WHERE id = ?"); $r->execute([$id]); $p = $r->fetchColumn();
            if ($p) { $fn = basename($p); @unlink(fl_dir() . $fn); @unlink(fl_dir() . 'th_' . $fn); }
            $pdo->prepare("DELETE FROM fl_drops WHERE id = ?")->execute([$id]); json_response(['ok' => true]);
        }
        if ($action === 'fl.settings') {
            if (!$isPost) json_response(['cfg' => $cfg]);
            $lat = (float)($in['lat'] ?? 0); $lng = (float)($in['lng'] ?? 0);
            if ($lat < 5 || $lat > 40 || $lng < 60 || $lng > 100) json_response(['error' => 'Centre must be inside India.'], 400);
            fl_set($pdo, 'center_lat', round($lat, 6)); fl_set($pdo, 'center_lng', round($lng, 6));
            fl_set($pdo, 'radius_km', max(1, min(40, (float)($in['radius'] ?? 6))));
            fl_set($pdo, 'cell_goal', max(1, min(200, (int)($in['goal'] ?? 15))));
            fl_set($pdo, 'daily_target', max(1, min(1000, (int)($in['daily'] ?? 40))));
            json_response(['ok' => true]);
        }
        break;
    }
    }
} catch (PDOException $e) {
    json_response(['error' => 'Database problem in Flyers: ' . mb_substr($e->getMessage(), 0, 200)], 500);
}