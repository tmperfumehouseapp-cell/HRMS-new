<?php
/**
 * office.php — Offices & cleaning for the HRMS (loaded together with check.php; api.php hook line is the same).
 *
 * An OFFICE is its own place with its own people - not a part of the Boss Engine. The Boss Engine only WATCHES it:
 *   office  -> zones (Claude drafts them from a video/photos, a person approves) -> checkpoints (what ONE photo must show)
 *   zone    -> a standing duty in the Boss Engine (cleaner seat + ordered backups, checker seat). Daily tasks come from that; absence uses the fallback chain.
 *   photo   -> Claude judges it at once (boss.php). Every result lands here as a "check". Repeated remarks are MERGED into one issue; 3 different days = a loop.
 *   issue   -> a person can turn it into a maintenance ticket.
 * Tables: of_*. Needs boss.php (+ check.php for the Daily Summary). PHP 7.4 safe.
 */
if (!isset($pdo, $action) || !function_exists('json_response')) { return; }
if (defined('OF_LOADED')) { return; }
if (!is_file(__DIR__ . '/boss.php')) { return; }
define('OF_LOADED', 1);
require_once __DIR__ . '/boss.php';
if (!function_exists('bd_inbox')) { return; }
if (is_file(__DIR__ . '/office_place.php')) { require_once __DIR__ . '/office_place.php'; }   // Register a place (Pantry first): counts, refills, equipment, own owner (of.pl_* actions)

/** An office may be a STORE (kind = 'store'): the same cleaning, pantry and Boss Engine system, opened from Store > Cleaning & Pantry too. Added once, never touches data. */
function of_kind_col(PDO $pdo) {
    $flag = sys_get_temp_dir() . '/hrms_of_kind_v2_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) return;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM of_offices LIKE 'kind'")->fetch()) $pdo->exec("ALTER TABLE of_offices ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT 'office'");
        // the store is the Surat store (the only one): rename the plain "Store" office and its Boss Engine section, once
        $pdo->exec("UPDATE of_offices SET name = 'Surat store' WHERE kind = 'store' AND name = 'Store'");
        $pdo->exec("UPDATE bd_sections s JOIN of_offices o ON o.section_id = s.id SET s.name = 'Surat store' WHERE o.kind = 'store' AND o.name = 'Surat store' AND s.name = 'Store'");
        @file_put_contents($flag, '1');
    } catch (Throwable $e) { error_log('of_kind_col: ' . $e->getMessage()); }
}
function of_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true; if (function_exists('schema_ok') && schema_ok($pdo, __FUNCTION__)) return;
    try { $pdo->query("SELECT 1 FROM of_offices LIMIT 1"); $pdo->query("SELECT 1 FROM of_issue_seen LIMIT 1"); of_kind_col($pdo); return; } catch (Throwable $e) {}
    $E = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec("CREATE TABLE IF NOT EXISTS of_settings (skey VARCHAR(40) PRIMARY KEY, svalue TEXT NULL)$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS of_offices (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL, address VARCHAR(250) NULL, section_id INT NOT NULL, active TINYINT NOT NULL DEFAULT 1, created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL)$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS of_zones (id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL, name VARCHAR(120) NOT NULL, name_hi VARCHAR(160) NULL, times VARCHAR(60) NOT NULL DEFAULT '11:00', status VARCHAR(10) NOT NULL DEFAULT 'proposed', source VARCHAR(8) NOT NULL DEFAULT 'manual', note VARCHAR(250) NULL, sort_no INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, INDEX (office_id, status))$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS of_points (id INT AUTO_INCREMENT PRIMARY KEY, zone_id INT NOT NULL, text VARCHAR(200) NOT NULL, text_hi VARCHAR(240) NULL, sort_no INT NOT NULL DEFAULT 0, INDEX (zone_id))$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS of_checks (id INT AUTO_INCREMENT PRIMARY KEY, photo_id INT NOT NULL UNIQUE, task_id INT NOT NULL, duty_id INT NOT NULL, zone_id INT NOT NULL, office_id INT NOT NULL, emp_id INT NULL, ai_verdict VARCHAR(8) NOT NULL, ai_reason VARCHAR(255) NULL, review TINYINT NOT NULL DEFAULT 0, human VARCHAR(8) NULL, human_by VARCHAR(80) NULL, decided_at DATETIME NULL, created_at DATETIME NOT NULL, INDEX (office_id, created_at), INDEX (review, human))$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS of_issues (id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL, zone_id INT NOT NULL, remark VARCHAR(255) NOT NULL, hits INT NOT NULL DEFAULT 1, days INT NOT NULL DEFAULT 1, last_day DATE NOT NULL, status VARCHAR(8) NOT NULL DEFAULT 'open', ticket_id INT NULL, passes INT NOT NULL DEFAULT 0, first_at DATETIME NOT NULL, last_at DATETIME NOT NULL, INDEX (office_id, status))$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS of_issue_seen (photo_id INT PRIMARY KEY)$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS of_tickets (id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NOT NULL, zone_id INT NULL, issue_id INT NULL, title VARCHAR(200) NOT NULL, detail VARCHAR(400) NULL, status VARCHAR(8) NOT NULL DEFAULT 'open', assigned_to VARCHAR(100) NULL, created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL, done_at DATETIME NULL, INDEX (office_id, status))$E");
    of_kind_col($pdo);
    if (function_exists('schema_set')) schema_set($pdo, __FUNCTION__, ['of_settings', 'of_offices', 'of_zones', 'of_points', 'of_checks', 'of_issues', 'of_issue_seen', 'of_tickets']);
}
function of_cfg($k, $d = '') { global $pdo; try { $st = $pdo->prepare("SELECT svalue FROM of_settings WHERE skey = ?"); $st->execute([$k]); $v = $st->fetchColumn(); return $v === false ? $d : (string)$v; } catch (Throwable $e) { return $d; } }
function of_put($k, $v) { global $pdo; $pdo->prepare("INSERT INTO of_settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([$k, (string)$v]); }

/* ============================== office <-> Boss Engine plumbing ============================== */
function of_office(PDO $pdo, $id) { $st = $pdo->prepare("SELECT * FROM of_offices WHERE id = ?"); $st->execute([(int)$id]); return $st->fetch() ?: null; }
function of_seats(PDO $pdo, array $o) {
    $sid = (int)$o['section_id'];
    $mk = function ($kind, $label) use ($pdo, $sid) { $seat = bd_section_seat($pdo, $sid, $kind); return ['seat_id' => $seat, 'label' => $label, 'holders' => $seat ? bd_holders($pdo, $seat) : []]; };
    return ['cleaner' => $mk('worker', 'Cleaners (first person, then backups)'), 'checker' => $mk('checker', 'Checker (looks at what Claude is unsure about)'), 'main' => $mk('section_boss', 'Main checker (decides, gets the loops)')];
}
function of_prompt(array $zone, array $points) {
    $p = []; foreach ($points as $x) { $t = $x['text']; if (!empty($x['must']) && is_array($x['must'])) { $m = []; foreach ($x['must'] as $y) $m[] = (int)$y['qty'] . ' ' . $y['name']; $t .= ' - count them, it must show: ' . mb_substr(implode(', ', $m), 0, 300); } $p[] = $t; }   // places: a must-have list with counts (office_place.php)
    $s = 'Zone: ' . $zone['name'] . '. The photo must clearly show: ' . implode('; ', $p) . '.';
    return mb_substr($s, 0, 480);
}
function of_times($csv) {
    $o = []; foreach (explode(',', (string)$csv) as $t) { $t = trim($t); if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) $o[] = $t; }
    $o = array_values(array_unique($o)); sort($o); return $o ?: ['11:00'];
}
/** make the Boss Engine duties match the approved zones of this office (one duty per zone per time of day). */
function of_sync(PDO $pdo, array $o, $by = 'Offices') {
    $sid = (int)$o['section_id'];
    $worker = bd_section_seat($pdo, $sid, 'worker'); $checker = bd_section_seat($pdo, $sid, 'checker');
    $zones = $pdo->prepare("SELECT * FROM of_zones WHERE office_id = ?"); $zones->execute([(int)$o['id']]);
    $live = 0;
    foreach ($zones->fetchAll() as $z) {
        if (function_exists('ofp_zone_handled') && ofp_zone_handled($pdo, (int)$z['id'])) { $live += (int)ofp_of_sync_zone($pdo, $o, $z, $by); continue; }   // a place run by the Register-a-place wizard makes its own duties (office_place.php)
        $pts = $pdo->prepare("SELECT text FROM of_points WHERE zone_id = ? ORDER BY sort_no, id"); $pts->execute([(int)$z['id']]); $pts = $pts->fetchAll();
        $times = of_times($z['times']);
        $want = $z['status'] === 'approved' && $pts;
        $have = $pdo->prepare("SELECT id, lib_key FROM bd_duties WHERE lib_key LIKE ?"); $have->execute(['of:' . (int)$z['id'] . ':%']);
        $byKey = []; foreach ($have->fetchAll() as $h) $byKey[$h['lib_key']] = (int)$h['id'];
        foreach ($times as $i => $t) {
            $key = 'of:' . (int)$z['id'] . ':' . $i;
            $title = 'Clean: ' . $z['name'] . ' · ' . $o['name'] . (count($times) > 1 ? ' (' . $t . ')' : '');
            $c = bd_clean_duty(['title' => $title, 'why' => 'Office cleaning: ' . $o['name'], 'freq' => 'daily', 'due_time' => $t, 'proof' => 'photo', 'photo_prompt' => of_prompt($z, $pts), 'pen_amount' => 0]);
            if (isset($byKey[$key])) {
                $did = bd_save_duty($pdo, $sid, $worker, $c, 'office', 'live', $by, $byKey[$key]);
                $pdo->prepare("UPDATE bd_duties SET status = ?, checker_seat_id = ?, grace_min = 30 WHERE id = ?")->execute([$want ? 'live' : 'paused', $checker ?: null, $did]);
            } elseif ($want) {
                $did = bd_save_duty($pdo, $sid, $worker, $c, 'office', 'live', $by);
                $pdo->prepare("UPDATE bd_duties SET lib_key = ?, checker_seat_id = ?, grace_min = 30 WHERE id = ?")->execute([$key, $checker ?: null, $did]);
            }
            if ($want) $live++;
        }
        foreach ($byKey as $k => $did) { $n = (int)substr($k, strrpos($k, ':') + 1); if ($n >= count($times)) $pdo->prepare("UPDATE bd_duties SET status = 'paused' WHERE id = ?")->execute([$did]); }
    }
    return $live;
}

/* ============================== results coming back from the Boss Engine ============================== */
/** every final photo of a cleaning duty becomes a check; every failed photo feeds the issue counter */
function of_pull(PDO $pdo) {
    $mode = of_cfg('judge', 'shadow');
    $rows = $pdo->query("SELECT p.id, p.task_id, p.duty_id, p.emp_id, p.verdict, p.reason, p.created_at, d.lib_key FROM bd_photos p JOIN bd_duties d ON d.id = p.duty_id WHERE d.lib_key LIKE 'of:%' AND NOT EXISTS (SELECT 1 FROM of_checks c WHERE c.photo_id = p.id) AND NOT EXISTS (SELECT 1 FROM of_issue_seen s WHERE s.photo_id = p.id) ORDER BY p.id LIMIT 300")->fetchAll();
    $n = 0;
    foreach ($rows as $r) {
        $zid = (int)explode(':', $r['lib_key'])[1];
        $z = $pdo->prepare("SELECT z.*, o.id AS oid FROM of_zones z JOIN of_offices o ON o.id = z.office_id WHERE z.id = ?"); $z->execute([$zid]); $z = $z->fetch(); if (!$z) { $pdo->prepare("INSERT IGNORE INTO of_issue_seen (photo_id) VALUES (?)")->execute([(int)$r['id']]); continue; }
        $day = substr($r['created_at'], 0, 10);
        if ($r['verdict'] === 'fail') {
            of_issue_hit($pdo, (int)$z['oid'], $zid, (string)$r['reason'], $day, $r['created_at']);
        } else {
            $ai = $r['verdict'] === 'pass' ? 'clear' : 'unsure';
            $review = ($mode === 'shadow' || $ai !== 'clear') ? 1 : 0;
            $pdo->prepare("INSERT IGNORE INTO of_checks (photo_id, task_id, duty_id, zone_id, office_id, emp_id, ai_verdict, ai_reason, review, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([(int)$r['id'], (int)$r['task_id'], (int)$r['duty_id'], $zid, (int)$z['oid'], $r['emp_id'] ?: null, $ai, mb_substr((string)$r['reason'], 0, 250), $review, $r['created_at']]);
            if ($ai === 'clear') of_issue_pass($pdo, $zid);
        }
        $pdo->prepare("INSERT IGNORE INTO of_issue_seen (photo_id) VALUES (?)")->execute([(int)$r['id']]); $n++;
    }
    // decisions made in the Boss Inbox count here too
    $pdo->exec("UPDATE of_checks c JOIN bd_tasks t ON t.id = c.task_id SET c.human = IF(t.check_state = 'ok', 'ok', 'redo'), c.human_by = 'Boss inbox', c.decided_at = IFNULL(c.decided_at, NOW()) WHERE c.human IS NULL AND t.check_state IN ('ok','rejected')");
    return $n;
}
function of_issue_hit(PDO $pdo, $officeId, $zoneId, $remark, $day, $at) {
    $remark = trim($remark) !== '' ? mb_substr(trim($remark), 0, 250) : 'Not clean enough';
    $st = $pdo->prepare("SELECT * FROM of_issues WHERE zone_id = ? AND status IN ('open','ticket') ORDER BY id DESC"); $st->execute([$zoneId]);
    foreach ($st->fetchAll() as $i) {
        if (bd_sim($i['remark'], $remark) >= 0.45 || mb_strtolower($i['remark']) === mb_strtolower($remark)) {
            $newDay = $i['last_day'] !== $day;
            $pdo->prepare("UPDATE of_issues SET hits = hits + 1, days = days + ?, last_day = ?, last_at = ?, passes = 0 WHERE id = ?")->execute([$newDay ? 1 : 0, $day, $at, $i['id']]);
            return (int)$i['id'];
        }
    }
    $pdo->prepare("INSERT INTO of_issues (office_id, zone_id, remark, hits, days, last_day, first_at, last_at) VALUES (?,?,?,1,1,?,?,?)")->execute([$officeId, $zoneId, $remark, $day, $at, $at]);
    return (int)$pdo->lastInsertId();
}
function of_issue_pass(PDO $pdo, $zoneId) {
    $pdo->prepare("UPDATE of_issues SET passes = passes + 1 WHERE zone_id = ? AND status = 'open'")->execute([$zoneId]);
    $pdo->prepare("UPDATE of_issues SET status = 'fixed' WHERE zone_id = ? AND status = 'open' AND passes >= 2")->execute([$zoneId]);
}

/** called from check.php's nightly run */
function of_check_offices(PDO $pdo, array &$seen) {
    of_ensure($pdo); of_pull($pdo);
    $loops = 0; $stale = 0;
    foreach ($pdo->query("SELECT i.*, o.name oname, o.section_id, z.name zname FROM of_issues i JOIN of_offices o ON o.id = i.office_id JOIN of_zones z ON z.id = i.zone_id WHERE i.status IN ('open','ticket') AND i.days >= 3")->fetchAll() as $i) {
        $loops++;
        $sugg = $i['status'] === 'ticket' ? 'A maintenance ticket is open - follow it up.' : 'The cleaner cannot fix this by cleaning. Make a maintenance ticket (repair, replace, deep clean) or change the checklist.';
        $f = 'ofz:' . $i['id'];
        ck_see($pdo, $seen, 'of_zone', $f, null, (int)$i['section_id'], 'bad', $i['oname'] . ' · ' . $i['zname'] . ': "' . $i['remark'] . '" on ' . $i['days'] . ' different days', 'Seen ' . $i['hits'] . ' times since ' . substr($i['first_at'], 0, 10) . '.', $sugg);
        bd_inbox('loop', 'Keeps coming back: ' . $i['oname'] . ' · ' . $i['zname'], '"' . $i['remark'] . '" on ' . $i['days'] . ' different days. ' . $sugg, ['link' => 'office_care.html?id=' . (int)$i['office_id']], (int)$i['section_id'], null, 'loop:' . $f);
    }
    // an approved zone nobody photographed for 3 days although its duty is live
    foreach ($pdo->query("SELECT z.id, z.name, o.name oname, o.section_id FROM of_zones z JOIN of_offices o ON o.id = z.office_id WHERE z.status = 'approved' AND o.active = 1 AND EXISTS (SELECT 1 FROM bd_duties d WHERE d.lib_key LIKE CONCAT('of:', z.id, ':%') AND d.status = 'live' AND d.created_at < NOW() - INTERVAL 3 DAY) AND NOT EXISTS (SELECT 1 FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE d.lib_key LIKE CONCAT('of:', z.id, ':%') AND t.status = 'done' AND t.task_date >= CURDATE() - INTERVAL 3 DAY)")->fetchAll() as $z) {
        $stale++;
        ck_see($pdo, $seen, 'of_stale', 'ofs:' . $z['id'], null, (int)$z['section_id'], 'warn', $z['oname'] . ' · ' . $z['zname'] . ': not cleaned for 3 days', 'No finished cleaning task in the last 3 days.', 'Check that the cleaner seat has a person and that the person has a login and phone.');
    }
    // spot check material is picked by check.php; here we only make sure old, undecided "review" items do not pile up
    return ['loops' => $loops, 'stale' => $stale];
}
function of_summary(PDO $pdo, array $w) {
    of_ensure($pdo); of_pull($pdo);
    $out = [];
    $scope = ($w['admin'] || $w['top']) ? '1=1' : ($w['sections'] ? 'o.section_id IN (' . bd_in($w['sections']) . ')' : '1=0');
    foreach ($pdo->query("SELECT o.* FROM of_offices o WHERE o.active = 1 AND $scope ORDER BY o.name")->fetchAll() as $o) {
        $t = $pdo->prepare("SELECT t.status, COUNT(*) c FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE d.section_id = ? AND d.lib_key LIKE 'of:%' AND t.task_date = CURDATE() GROUP BY t.status"); $t->execute([(int)$o['section_id']]);
        $s = ['done' => 0, 'open' => 0, 'missed' => 0, 'unowned' => 0]; foreach ($t->fetchAll() as $r) if (isset($s[$r['status']])) $s[$r['status']] = (int)$r['c'];
        $c = $pdo->prepare("SELECT SUM(ai_verdict = 'clear' AND created_at >= NOW() - INTERVAL 24 HOUR) clr, SUM(ai_verdict = 'unsure' AND created_at >= NOW() - INTERVAL 24 HOUR) uns, SUM(review = 1 AND human IS NULL) rev FROM of_checks WHERE office_id = ?"); $c->execute([(int)$o['id']]); $c = $c->fetch();
        $i = $pdo->prepare("SELECT COUNT(*) a, SUM(days >= 3) l FROM of_issues WHERE office_id = ? AND status IN ('open','ticket')"); $i->execute([(int)$o['id']]); $i = $i->fetch();
        $out[] = ['id' => (int)$o['id'], 'name' => $o['name'], 'today' => $s, 'ai_clear' => (int)$c['clr'], 'ai_unsure' => (int)$c['uns'], 'to_review' => (int)$c['rev'], 'issues' => (int)$i['a'], 'loops' => (int)$i['l']];
    }
    return $out;
}
function of_spot_info(PDO $pdo, $checkId) {
    $st = $pdo->prepare("SELECT c.task_id, c.ai_reason, c.emp_id, z.name zone, o.name office FROM of_checks c JOIN of_zones z ON z.id = c.zone_id JOIN of_offices o ON o.id = c.office_id WHERE c.id = ?"); $st->execute([(int)$checkId]); $r = $st->fetch();
    if (!$r) return [];
    return ['task_id' => (int)$r['task_id'], 'reason' => $r['ai_reason'], 'who' => bd_emp_name($pdo, $r['emp_id']), 'where' => $r['office'] . ' · ' . $r['zone']];
}

/* ============================== Claude drafts the zones from a video / photos ============================== */
function of_ai_draft(PDO $pdo, array $o, array $frames, $notes) {
    if (!function_exists('convo_ai')) return [false, 'The AI helper is not available on this server.'];
    $parts = [['text' => "OFFICE: " . $o['name'] . ($o['address'] ? " (" . $o['address'] . ")" : '') . "\nNOTES FROM THE OWNER: " . ($notes !== '' ? $notes : '(none)') . "\n\nFrames from the walk-through video, in order. Each image is one moment of the walk:"]];
    $n = 0;
    foreach ($frames as $f) {
        if (!is_string($f) || !preg_match('~^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$~', $f, $m)) continue;
        if (strlen($m[2]) > 1800000) continue;
        $n++; $parts[] = ['text' => "Frame $n:"]; $parts[] = ['inlineData' => ['mimeType' => 'image/' . $m[1], 'data' => $m[2]]];
        if ($n >= 12) break;
    }
    if ($n === 0) return [false, 'No usable pictures arrived. Choose the video again, or add photos.'];
    $sys = "You are the facility supervisor for a small perfume company in Surat, India. From the pictures of ONE office/shop, decide the cleaning ZONES a cleaner must do and photograph every day. "
        . "A zone is a physical area (e.g. entrance and floor, reception desk, washroom, pantry, display shelves). For each zone give 3 to 8 CHECKPOINTS - things that can be judged from ONE photo of that zone (e.g. 'floor swept, no dust or paper', 'dustbin emptied', 'glass without fingerprints'). "
        . "Write every zone name and checkpoint in simple English AND simple Hindi (Devanagari) - short words a cleaner understands. Suggest how many times a day it needs cleaning (1 to 3) and sensible times in 24h HH:MM. "
        . "Do NOT invent areas you cannot see. In not_seen list the areas an office like this normally has but are NOT visible in the pictures (so a human adds them). In questions list up to 3 things you need to know (who cleans, opening time, any special surface). Never mention people's faces.";
    $schema = ['type' => 'OBJECT', 'properties' => [
        'zones' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['name' => ['type' => 'STRING'], 'name_hi' => ['type' => 'STRING'], 'times' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'note' => ['type' => 'STRING'],
            'points' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['en' => ['type' => 'STRING'], 'hi' => ['type' => 'STRING']], 'required' => ['en']]]], 'required' => ['name', 'points']]],
        'not_seen' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'questions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'summary' => ['type' => 'STRING']], 'required' => ['zones']];
    if (function_exists('ofp_draft_schema_extra')) { $schema = ofp_draft_schema_extra($schema); $sys .= ofp_draft_sys(); }   // also propose counting items, minimums, equipment, must-have lists (proposals only)
    try { $r = convo_ai($sys, $parts, $schema, false, 3500); } catch (Throwable $e) { return [false, 'The AI check failed: ' . $e->getMessage()]; }
    if (empty($r[0]) || !is_array($r[1]) || empty($r[1]['zones'])) return [false, 'Claude could not read the pictures' . (!empty($r[2]) ? ' (' . $r[2] . ')' : '') . '. Try clearer frames or add the zones by hand.'];
    return [true, $r[1]];
}

/* ============================== endpoints ============================== */
try {
if (strpos((string)$action, 'of.') === 0) {
    bd_ensure($pdo); of_ensure($pdo);
    if (strpos((string)$action, 'of.pl_') === 0 && function_exists('ofp_dispatch')) { ofp_dispatch($pdo, (string)$action); }   // Register a place: its own router and its own permissions (employees do the daily check)
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $auth = require_auth();
    $w = bd_need_manager($pdo, $auth);
    $by = (string)($auth['username'] ?? 'user');
    $in = $isPost ? json_input() : [];
    $scopeOf = function () use ($w) { return ($w['admin'] || $w['top']) ? '1=1' : ($w['sections'] ? 'section_id IN (' . bd_in($w['sections']) . ')' : '1=0'); };
    $needOffice = function ($id) use ($pdo, $w) { $o = of_office($pdo, $id); if (!$o) json_response(['error' => 'Office not found.'], 404); if (!bd_can_section($w, $o['section_id'])) json_response(['error' => 'This office belongs to another team.'], 403); return $o; };

    switch ($action) {

    case 'of.list': {
        of_pull($pdo);
        $o = []; $sum = []; foreach (of_summary($pdo, $w) as $s) $sum[$s['id']] = $s;
        foreach ($pdo->query("SELECT * FROM of_offices WHERE active = 1 AND " . $scopeOf() . " ORDER BY name")->fetchAll() as $r) {
            $z = $pdo->prepare("SELECT status, COUNT(*) c FROM of_zones WHERE office_id = ? GROUP BY status"); $z->execute([(int)$r['id']]); $zc = ['approved' => 0, 'proposed' => 0, 'off' => 0]; foreach ($z->fetchAll() as $x) $zc[$x['status']] = (int)$x['c'];
            $seats = of_seats($pdo, $r); $names = function ($ids) use ($pdo) { return array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, $ids); };
            $o[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'kind' => (string)($r['kind'] ?? 'office'), 'address' => $r['address'], 'zones' => $zc, 'cleaners' => $names($seats['cleaner']['holders']), 'checker' => $names($seats['checker']['holders']), 'main' => $names($seats['main']['holders']), 'today' => $sum[(int)$r['id']] ?? null];
        }
        json_response(['offices' => $o, 'admin' => $w['admin'], 'judge' => of_cfg('judge', 'shadow')]);
    }

    case 'of.get': {
        $off = $needOffice((int)($_GET['id'] ?? 0)); of_pull($pdo);
        $zones = [];
        $zq = $pdo->prepare("SELECT * FROM of_zones WHERE office_id = ? ORDER BY sort_no, id"); $zq->execute([(int)$off['id']]);
        foreach ($zq->fetchAll() as $z) {
            $pq = $pdo->prepare("SELECT id, text, text_hi FROM of_points WHERE zone_id = ? ORDER BY sort_no, id"); $pq->execute([(int)$z['id']]);
            $tq = $pdo->prepare("SELECT t.status, t.owner_emp FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE d.lib_key LIKE ? AND t.task_date = CURDATE() AND t.status != 'excused'"); $tq->execute(['of:' . (int)$z['id'] . ':%']);
            $tasks = $tq->fetchAll(); $done = 0; $open = 0; $missed = 0; $who = '';
            foreach ($tasks as $t) { if ($t['status'] === 'done') $done++; elseif ($t['status'] === 'missed') $missed++; else $open++; if (!$who && $t['owner_emp']) $who = bd_emp_name($pdo, $t['owner_emp']); }
            $lq = $pdo->prepare("SELECT ai_verdict, created_at FROM of_checks WHERE zone_id = ? ORDER BY id DESC LIMIT 1"); $lq->execute([(int)$z['id']]); $last = $lq->fetch();
            $zones[] = ['id' => (int)$z['id'], 'name' => $z['name'], 'name_hi' => $z['name_hi'], 'times' => of_times($z['times']), 'status' => $z['status'], 'source' => $z['source'], 'note' => $z['note'], 'points' => $pq->fetchAll(), 'today' => ['done' => $done, 'open' => $open, 'missed' => $missed, 'who' => $who], 'last' => $last ?: null];
        }
        $cq = $pdo->prepare("SELECT c.*, z.name zname FROM of_checks c JOIN of_zones z ON z.id = c.zone_id WHERE c.office_id = ? AND c.review = 1 AND c.human IS NULL ORDER BY c.id DESC LIMIT 30"); $cq->execute([(int)$off['id']]);
        $checks = []; foreach ($cq->fetchAll() as $c) $checks[] = ['id' => (int)$c['id'], 'task_id' => (int)$c['task_id'], 'zone' => $c['zname'], 'who' => bd_emp_name($pdo, $c['emp_id']), 'ai' => $c['ai_verdict'], 'reason' => $c['ai_reason'], 'at' => $c['created_at']];
        $iq = $pdo->prepare("SELECT i.*, z.name zname FROM of_issues i JOIN of_zones z ON z.id = i.zone_id WHERE i.office_id = ? AND i.status IN ('open','ticket') ORDER BY i.days DESC, i.hits DESC LIMIT 30"); $iq->execute([(int)$off['id']]);
        $issues = []; foreach ($iq->fetchAll() as $i) $issues[] = ['id' => (int)$i['id'], 'zone' => $i['zname'], 'remark' => $i['remark'], 'hits' => (int)$i['hits'], 'days' => (int)$i['days'], 'loop' => (int)$i['days'] >= 3, 'status' => $i['status'], 'ticket_id' => $i['ticket_id'] ? (int)$i['ticket_id'] : null, 'since' => substr($i['first_at'], 0, 10)];
        $tq2 = $pdo->prepare("SELECT t.*, z.name zname FROM of_tickets t LEFT JOIN of_zones z ON z.id = t.zone_id WHERE t.office_id = ? AND (t.status != 'done' OR t.done_at >= NOW() - INTERVAL 14 DAY) ORDER BY FIELD(t.status,'open','doing','done'), t.id DESC LIMIT 30"); $tq2->execute([(int)$off['id']]);
        $emps = bd_employees($pdo); $load = []; foreach ($pdo->query("SELECT owner_emp, COUNT(*) c FROM bd_tasks WHERE task_date = CURDATE() AND status IN ('open','done') AND owner_emp IS NOT NULL GROUP BY owner_emp")->fetchAll() as $r) $load[(int)$r['owner_emp']] = (int)$r['c'];
        foreach ($emps as &$e) $e['load'] = $load[(int)$e['id']] ?? 0; unset($e);
        json_response(['office' => ['id' => (int)$off['id'], 'name' => $off['name'], 'kind' => (string)($off['kind'] ?? 'office'), 'address' => $off['address'], 'section_id' => (int)$off['section_id']], 'seats' => of_seats($pdo, $off), 'zones' => $zones, 'checks' => $checks, 'issues' => $issues, 'tickets' => $tq2->fetchAll(), 'employees' => $emps, 'admin' => $w['admin'], 'judge' => of_cfg('judge', 'shadow'), 'mode' => bd_mode()]);
    }

    case 'of.save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        $name = trim((string)($in['name'] ?? '')); if ($name === '') json_response(['error' => 'Give the office a name.'], 422);
        $addr = mb_substr(trim((string)($in['address'] ?? '')), 0, 250); $id = (int)($in['id'] ?? 0);
        $kind = (($in['kind'] ?? '') === 'store') ? 'store' : 'office';
        if (!$id && $kind === 'store') { $ex = $pdo->query("SELECT id FROM of_offices WHERE kind = 'store' AND active = 1 ORDER BY id LIMIT 1")->fetchColumn(); if ($ex) json_response(['ok' => true, 'id' => (int)$ex, 'existing' => true]); }   // ONE store: asking again opens the same one
        if ($id) { $o = of_office($pdo, $id); if (!$o) json_response(['error' => 'Not found.'], 404); $pdo->prepare("UPDATE of_offices SET name = ?, address = ?, active = ? WHERE id = ?")->execute([mb_substr($name, 0, 120), $addr, isset($in['active']) ? (int)(bool)$in['active'] : 1, $id]); $pdo->prepare("UPDATE bd_sections SET name = ? WHERE id = ?")->execute([mb_substr($name, 0, 120), (int)$o['section_id']]); json_response(['ok' => true, 'id' => $id]); }
        $code = 'of_' . bd_slug($name); $base = $code; $n = 1; while ($pdo->query("SELECT 1 FROM bd_sections WHERE code = " . $pdo->quote($code))->fetchColumn()) $code = $base . (++$n);
        $pdo->prepare("INSERT INTO bd_sections (code, name, about, pages, source, state, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$code, mb_substr($name, 0, 120), $kind === 'store' ? 'Store cleaning and pantry' : 'Office cleaning and upkeep', '[]', 'office', 'ready', bd_now()]);
        $sid = (int)$pdo->lastInsertId(); bd_make_seats($pdo, $sid, $code, $name);
        $pdo->prepare("INSERT INTO of_offices (code, name, address, section_id, kind, created_by, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$code, mb_substr($name, 0, 120), $addr, $sid, $kind, $by, bd_now()]);
        $oid = (int)$pdo->lastInsertId(); bd_log('office', $oid, 'created office ' . $name, $by);
        json_response(['ok' => true, 'id' => $oid]);
    }

    case 'of.seats_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        $o = $needOffice((int)($in['office_id'] ?? 0)); $s = of_seats($pdo, $o);
        foreach (['cleaner', 'checker', 'main'] as $k) if (array_key_exists($k, $in) && $s[$k]['seat_id']) bd_set_holders($pdo, $s[$k]['seat_id'], (array)$in[$k]);
        bd_log('office', $o['id'], 'people changed', $by);
        $mv = bd_reassign($pdo, bd_today());
        json_response(['ok' => true, 'message' => 'Saved. ' . ($mv ? $mv . ' of today\'s tasks moved to the new people.' : 'Tomorrow\'s tasks follow this order.')]);
    }

    case 'of.draft': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        $o = $needOffice((int)($in['office_id'] ?? 0));
        list($ok, $d) = of_ai_draft($pdo, $o, (array)($in['frames'] ?? []), mb_substr(trim((string)($in['notes'] ?? '')), 0, 600));
        if (!$ok) json_response(['error' => $d], 422);
        $made = 0; $skipped = 0;
        $have = $pdo->prepare("SELECT name FROM of_zones WHERE office_id = ?"); $have->execute([(int)$o['id']]); $names = $have->fetchAll(PDO::FETCH_COLUMN);
        $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_no),0) FROM of_zones WHERE office_id = " . (int)$o['id'])->fetchColumn();
        foreach ((array)$d['zones'] as $z) {
            $nm = mb_substr(trim((string)($z['name'] ?? '')), 0, 120); if ($nm === '') continue;
            $dup = false; foreach ($names as $x) if (bd_sim($x, $nm) >= 0.6 || mb_strtolower($x) === mb_strtolower($nm)) $dup = true;
            if ($dup) { $skipped++; continue; }
            $times = []; foreach ((array)($z['times'] ?? []) as $t) if (is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($t))) $times[] = trim($t);
            $pdo->prepare("INSERT INTO of_zones (office_id, name, name_hi, times, status, source, note, sort_no, created_at) VALUES (?,?,?,?, 'proposed', 'video', ?,?,?)")->execute([(int)$o['id'], $nm, mb_substr(trim((string)($z['name_hi'] ?? '')), 0, 160) ?: null, implode(',', array_slice($times, 0, 3)) ?: '11:00', mb_substr(trim((string)($z['note'] ?? '')), 0, 250) ?: null, ++$maxSort, bd_now()]);
            $zid = (int)$pdo->lastInsertId(); $i = 0;
            foreach (array_slice((array)($z['points'] ?? []), 0, 10) as $p) {
                $en = mb_substr(trim((string)($p['en'] ?? '')), 0, 200); if ($en === '') continue;
                $pdo->prepare("INSERT INTO of_points (zone_id, text, text_hi, sort_no) VALUES (?,?,?,?)")->execute([$zid, $en, mb_substr(trim((string)($p['hi'] ?? '')), 0, 240) ?: null, ++$i]);
            }
            if (function_exists('ofp_draft_save')) { try { ofp_draft_save($pdo, $zid, (array)$z); } catch (Throwable $e) { error_log('place proposals: ' . $e->getMessage()); } }
            $names[] = $nm; $made++;
        }
        bd_log('office', $o['id'], 'Claude drafted ' . $made . ' zones from the walk-through', $by);
        json_response(['ok' => true, 'made' => $made, 'skipped' => $skipped, 'not_seen' => array_values(array_filter(array_map('strval', (array)($d['not_seen'] ?? [])))), 'questions' => array_values(array_filter(array_map('strval', (array)($d['questions'] ?? [])))), 'summary' => (string)($d['summary'] ?? '')] + (function_exists('ofp_draft_summary') ? ['place_proposals' => ofp_draft_summary()] : []));
    }

    case 'of.zone_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        $o = $needOffice((int)($in['office_id'] ?? 0)); $z = (array)($in['zone'] ?? []);
        $name = mb_substr(trim((string)($z['name'] ?? '')), 0, 120); if ($name === '') json_response(['error' => 'Give the zone a name.'], 422);
        $times = implode(',', array_slice(of_times(implode(',', (array)($z['times'] ?? ['11:00']))), 0, 3));
        $pts = []; foreach ((array)($z['points'] ?? []) as $p) { $t = mb_substr(trim(is_array($p) ? (string)($p['text'] ?? '') : (string)$p), 0, 200); if ($t !== '') $pts[] = [$t, is_array($p) ? mb_substr(trim((string)($p['text_hi'] ?? '')), 0, 240) : '']; }
        $id = (int)($z['id'] ?? 0);
        if ($id) { $chk = $pdo->prepare("SELECT 1 FROM of_zones WHERE id = ? AND office_id = ?"); $chk->execute([$id, (int)$o['id']]); if (!$chk->fetchColumn()) json_response(['error' => 'Zone not found.'], 404);
            $pdo->prepare("UPDATE of_zones SET name = ?, name_hi = ?, times = ?, note = ? WHERE id = ?")->execute([$name, mb_substr(trim((string)($z['name_hi'] ?? '')), 0, 160) ?: null, $times, mb_substr(trim((string)($z['note'] ?? '')), 0, 250) ?: null, $id]); }
        else { $pdo->prepare("INSERT INTO of_zones (office_id, name, name_hi, times, status, source, note, sort_no, created_at) VALUES (?,?,?,?, 'proposed', 'manual', ?, 99, ?)")->execute([(int)$o['id'], $name, mb_substr(trim((string)($z['name_hi'] ?? '')), 0, 160) ?: null, $times, mb_substr(trim((string)($z['note'] ?? '')), 0, 250) ?: null, bd_now()]); $id = (int)$pdo->lastInsertId(); }
        if (!(function_exists('ofp_zone_save_points') && ofp_zone_save_points($pdo, $id, $pts))) {   // a registered place keeps its reference photos and must-have lists (office_place.php)
            $pdo->prepare("DELETE FROM of_points WHERE zone_id = ?")->execute([$id]); $i = 0;
            foreach ($pts as $p) $pdo->prepare("INSERT INTO of_points (zone_id, text, text_hi, sort_no) VALUES (?,?,?,?)")->execute([$id, $p[0], $p[1] !== '' ? $p[1] : null, ++$i]);
        }
        of_sync($pdo, $o, $by);
        json_response(['ok' => true, 'id' => $id]);
    }

    case 'of.zone_status': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        $o = $needOffice((int)($in['office_id'] ?? 0)); $act = (string)($in['act'] ?? '');
        $ids = array_map('intval', (array)($in['ids'] ?? [(int)($in['id'] ?? 0)])); $n = 0; $noPts = [];
        foreach ($ids as $zid) {
            $chk = $pdo->prepare("SELECT name FROM of_zones WHERE id = ? AND office_id = ?"); $chk->execute([$zid, (int)$o['id']]); $zname = $chk->fetchColumn(); if ($zname === false) continue;
            if ($act === 'approve') { $c = $pdo->prepare("SELECT COUNT(*) FROM of_points WHERE zone_id = ?"); $c->execute([$zid]); if (!(int)$c->fetchColumn()) { $noPts[] = (string)$zname; continue; } $pdo->prepare("UPDATE of_zones SET status = 'approved' WHERE id = ?")->execute([$zid]); $n++; }
            elseif ($act === 'off') { $pdo->prepare("UPDATE of_zones SET status = 'off' WHERE id = ?")->execute([$zid]); $n++; }
            elseif ($act === 'delete') { of_sync($pdo, $o, $by); $pdo->prepare("UPDATE of_zones SET status = 'off' WHERE id = ?")->execute([$zid]); of_sync($pdo, $o, $by); $pdo->prepare("DELETE FROM of_points WHERE zone_id = ?")->execute([$zid]); $pdo->prepare("DELETE FROM of_zones WHERE id = ?")->execute([$zid]); if (function_exists('ofp_zone_deleted')) ofp_zone_deleted($pdo, $zid, $by); $n++; }
        }
        // a zone with no checkpoints cannot go live (Claude has nothing to check against): say so, never a silent "0 updated"
        if ($act === 'approve' && $noPts && !$n) json_response(['error' => 'Not approved: ' . implode(', ', array_slice($noPts, 0, 5)) . ' ' . (count($noPts) === 1 ? 'has' : 'have') . ' no checkpoints. Press Edit and add at least one checkpoint (what must be clean), then approve.'], 422);
        try { $live = of_sync($pdo, $o, $by); }
        catch (Throwable $e) { error_log('of.zone_status sync: ' . $e->getMessage()); json_response(['error' => 'The zone was approved but its daily cleaning task could not be made: ' . mb_substr($e->getMessage(), 0, 220)], 500); }
        $seats = of_seats($pdo, $o); $warn = $noPts ? ' Not approved (no checkpoints): ' . implode(', ', array_slice($noPts, 0, 5)) . '.' : '';
        if ($live && !$seats['cleaner']['holders']) $warn .= ' Nobody is on the cleaner seat yet - choose the cleaners below or the tasks stay unowned.';
        json_response(['ok' => true, 'message' => $n . ' zone' . ($n === 1 ? '' : 's') . ' updated. ' . $live . ' cleaning task' . ($live === 1 ? '' : 's') . ' a day now run.' . $warn . (bd_mode() === 'shadow' ? ' (The Boss Engine is in test mode: staff do not see them until you switch it on.)' : '')]);
    }

    case 'of.check_act': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $id = (int)($in['id'] ?? 0); $act = (string)($in['act'] ?? '');
        $c = $pdo->prepare("SELECT * FROM of_checks WHERE id = ?"); $c->execute([$id]); $c = $c->fetch(); if (!$c) json_response(['error' => 'Not found.'], 404);
        $o = $needOffice((int)$c['office_id']);
        if ($c['human'] !== null) json_response(['error' => 'Already decided.'], 409);
        if ($c['emp_id'] && (int)$c['emp_id'] === (int)$w['emp'] && !$w['admin']) json_response(['error' => 'You cannot check your own work.'], 403);
        if ($act === 'ok') { $pdo->prepare("UPDATE bd_tasks SET check_state = 'ok', checked_by = ? WHERE id = ?")->execute([$w['emp'] ?: null, (int)$c['task_id']]); $pdo->prepare("UPDATE of_checks SET human = 'ok', human_by = ?, decided_at = ? WHERE id = ?")->execute([$by, bd_now(), $id]); json_response(['ok' => true, 'message' => 'Accepted.']); }
        if ($act === 'redo') {
            $pdo->prepare("UPDATE bd_tasks SET check_state = 'rejected', status = 'open', attempts = 0, proof_path = NULL, checked_by = ? WHERE id = ?")->execute([$w['emp'] ?: null, (int)$c['task_id']]);
            $pdo->prepare("UPDATE of_checks SET human = 'redo', human_by = ?, decided_at = ? WHERE id = ?")->execute([$by, bd_now(), $id]);
            $t = bd_find_task_for($pdo, (int)$c['task_id']); if ($t && $t['owner_id']) bd_push($pdo, (int)$t['owner_id'], 'Cleaning not accepted', '"' . $t['title'] . '" needs a new photo.');
            if ($c['ai_verdict'] === 'clear') { $seen = []; if (function_exists('ck_see')) ck_see($pdo, $seen, 'spot_miss', 'ofmiss:' . $id, null, (int)$o['section_id'], 'warn', 'Checker sent back something Claude had cleared (' . $o['name'] . ')', 'Check #' . $id, 'Tighten that zone\'s checkpoints.'); }
            json_response(['ok' => true, 'message' => 'Sent back. The cleaner must take a new photo.']);
        }
        json_response(['error' => 'Choose ok or redo.'], 422);
    }

    case 'of.issue_act': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $id = (int)($in['id'] ?? 0); $act = (string)($in['act'] ?? '');
        $i = $pdo->prepare("SELECT i.*, z.name zname FROM of_issues i JOIN of_zones z ON z.id = i.zone_id WHERE i.id = ?"); $i->execute([$id]); $i = $i->fetch(); if (!$i) json_response(['error' => 'Not found.'], 404);
        $o = $needOffice((int)$i['office_id']);
        if ($act === 'ticket') {
            $pdo->prepare("INSERT INTO of_tickets (office_id, zone_id, issue_id, title, detail, created_by, created_at) VALUES (?,?,?,?,?,?,?)")->execute([(int)$o['id'], (int)$i['zone_id'], $id, mb_substr(trim((string)($in['title'] ?? '')) ?: ($i['zname'] . ': ' . $i['remark']), 0, 200), mb_substr(trim((string)($in['detail'] ?? '')) ?: ('Came back on ' . $i['days'] . ' different days (' . $i['hits'] . ' times).'), 0, 400), $by, bd_now()]);
            $tid = (int)$pdo->lastInsertId(); $pdo->prepare("UPDATE of_issues SET status = 'ticket', ticket_id = ? WHERE id = ?")->execute([$tid, $id]);
            json_response(['ok' => true, 'message' => 'Maintenance ticket made.']);
        }
        if ($act === 'fixed') { $pdo->prepare("UPDATE of_issues SET status = 'fixed' WHERE id = ?")->execute([$id]); json_response(['ok' => true, 'message' => 'Marked fixed.']); }
        json_response(['error' => 'Unknown action.'], 422);
    }
    case 'of.ticket_act': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $id = (int)($in['id'] ?? 0); $act = (string)($in['act'] ?? '');
        $t = $pdo->prepare("SELECT * FROM of_tickets WHERE id = ?"); $t->execute([$id]); $t = $t->fetch(); if (!$t) json_response(['error' => 'Not found.'], 404);
        $needOffice((int)$t['office_id']);
        if ($act === 'doing') { $pdo->prepare("UPDATE of_tickets SET status = 'doing', assigned_to = ? WHERE id = ?")->execute([mb_substr(trim((string)($in['assigned_to'] ?? '')), 0, 100) ?: null, $id]); }
        elseif ($act === 'done') { $pdo->prepare("UPDATE of_tickets SET status = 'done', done_at = ? WHERE id = ?")->execute([bd_now(), $id]); if ($t['issue_id']) $pdo->prepare("UPDATE of_issues SET status = 'fixed' WHERE id = ?")->execute([(int)$t['issue_id']]); }
        else json_response(['error' => 'Unknown action.'], 422);
        json_response(['ok' => true, 'message' => 'Updated.']);
    }

    case 'of.settings': {
        bd_need_admin($auth);
        if ($isPost) { $j = (string)($in['judge'] ?? ''); if (in_array($j, ['shadow', 'live'], true)) of_put('judge', $j); json_response(['ok' => true, 'message' => $j === 'live' ? 'Claude\'s verdict is final. A person only sees what Claude is unsure about, plus the 5% sample.' : 'The checker looks at every cleaning until you trust Claude.']); }
        json_response(['judge' => of_cfg('judge', 'shadow')]);
    }
    }
}
} catch (PDOException $e) {
    json_response(['error' => 'Database problem in Offices: ' . mb_substr($e->getMessage(), 0, 200)], 500);
}
