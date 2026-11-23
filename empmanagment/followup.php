<?php
/**
 * followup.php — "📌 Follow-up": the daily Excel sheet of every section, inside the HRMS.
 * A SECTION (Shop Wholesale, Surat Store, … as many as the company needs) holds TOPICS (Sales, New displays reached, New perfumes added …).
 * Every topic gets ONE update a day from the person it is given to. Claude sits in the middle: it reads the updates and tells the owner what moved, what is stuck and who wrote nothing.
 * Included from api.php (one line) and answers only the actions that start with "fup.".   PHP 7.4 safe.
 *
 *   fup.board        GET  ?section=ID&days=7   → my sections, the topics of one section, the updates of the last days, the checks, Claude's last note
 *   fup.mine         GET                       → the topics I must update today (My Day card + menu badge)
 *   fup.badge        GET                       → {show, manager, due}
 *   fup.people       GET                       → employees and departments (for the "who" boxes; managers only)
 *   fup.section_save POST {id?, name, owner_emp, owner_dept}      fup.section_del {id}
 *   fup.topic_save   POST {id?, section_id, title, kind: progress|deadline, due_date, assignee_emp, source, note}
 *   fup.topic_done   POST {id, done}           fup.topic_del {id}        fup.topic_move {id, dir: -1|1}
 *   fup.update_save  POST {topic_id, day, text, value?, pct?}
 *   fup.claude       POST {section_id}         → Claude reads the last 7 days and writes the note (saved per section per day)
 *
 * Who may do what
 *   Admin / HR                     → everything (sections, topics, who it is given to, every update).
 *   Section owner (one person, or everybody of one department) → sees the whole section and writes the update of every topic in it.
 *   The person a topic is given to → sees and updates that topic only.
 * Topic kinds: progress (runs every day, no end) · deadline (has a last date; shows days left / late).
 * Data source: a topic can take its number by itself from the HRMS (store sales, wholesale orders …); the person still writes the words.
 * Own tables: fu_sections, fu_topics, fu_updates, fu_digest. Nothing else is written.
 */
if (!function_exists('fup_tables')) {

function fup_now(): string { return date('Y-m-d H:i:s'); }
function fup_today(PDO $pdo): string { try { if (function_exists('biz_today')) return (string)biz_today($pdo); } catch (Throwable $e) { } return date('Y-m-d'); }
function fup_is_day($d): bool { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; }

function fup_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') return $ok = true;   // the test harness makes its own tables
        $pdo->exec("CREATE TABLE IF NOT EXISTS fu_sections (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, owner_emp INT NOT NULL DEFAULT 0, owner_dept INT NOT NULL DEFAULT 0, sort_no INT NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fu_topics (id INT AUTO_INCREMENT PRIMARY KEY, section_id INT NOT NULL, title VARCHAR(160) NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'progress', due_date DATE NULL, assignee_emp INT NOT NULL DEFAULT 0, source VARCHAR(30) NOT NULL DEFAULT '', note VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(8) NOT NULL DEFAULT 'open', done_at DATETIME NULL, sort_no INT NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1, created_by INT NOT NULL DEFAULT 0, created_at DATETIME NULL, KEY k_sec (section_id, active), KEY k_emp (assignee_emp, active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fu_updates (id INT AUTO_INCREMENT PRIMARY KEY, topic_id INT NOT NULL, day DATE NOT NULL, txt TEXT NULL, val DECIMAL(14,2) NULL, pct SMALLINT NULL, auto TINYINT NOT NULL DEFAULT 0, emp_id INT NOT NULL DEFAULT 0, emp_name VARCHAR(120) NOT NULL DEFAULT '', updated_at DATETIME NULL,
            UNIQUE KEY u_td (topic_id, day), KEY k_day (day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fu_digest (section_id INT NOT NULL, day DATE NOT NULL, txt MEDIUMTEXT NULL, made_at DATETIME NULL, PRIMARY KEY (section_id, day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $ok = true;
    } catch (Throwable $e) { error_log('fup_tables: ' . $e->getMessage()); return $ok = false; }
}
/** The two sections the owner asked for, made once. Deleting them later never brings them back. */
function fup_seed(PDO $pdo): void
{
    try {
        if ((string)setting_get($pdo, 'fup_seeded', '') === '1') return; setting_set($pdo, 'fup_seeded', '1');
        if ((int)$pdo->query("SELECT COUNT(*) FROM fu_sections")->fetchColumn() > 0) return;
        $i = 0; foreach (['Shop Wholesale', 'Surat Store'] as $n) $pdo->prepare("INSERT INTO fu_sections (name, sort_no, active, created_at) VALUES (?, ?, 1, ?)")->execute([$n, ++$i, fup_now()]);
    } catch (Throwable $e) { error_log('fup_seed: ' . $e->getMessage()); }
}

/** Once: the four numbers the owner follows for the Surat Store (collection, flyers, community adds, numbers saved) are made as topics that take their number by themselves. Deleting them later never brings them back. Nobody is assigned: the owner gives them to people. */
function fup_seed_store_topics(PDO $pdo): void
{
    try {
        if ((string)setting_get($pdo, 'fup_store_topics_v1', '') === '1') return;
        $q = $pdo->query("SELECT id FROM fu_sections WHERE LOWER(name) = 'surat store' ORDER BY id LIMIT 1"); $sid = (int)$q->fetchColumn(); if ($sid <= 0) return;
        setting_set($pdo, 'fup_store_topics_v1', '1');
        $have = []; foreach ($pdo->query("SELECT source FROM fu_topics WHERE section_id = " . $sid . " AND active = 1")->fetchAll(PDO::FETCH_COLUMN) as $src) $have[(string)$src] = 1;
        $n = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM fu_topics WHERE section_id = " . $sid)->fetchColumn();
        $ins = $pdo->prepare("INSERT INTO fu_topics (section_id, title, kind, due_date, assignee_emp, source, note, status, sort_no, active, created_by, created_at) VALUES (?, ?, 'progress', NULL, 0, ?, '', 'open', ?, 1, 0, ?)");
        foreach ([['Store collection (sales of the day)', 'store_sales'], ['Flyer drops done', 'store_flyers'], ['Customers added to the community', 'store_community'], ['Customer numbers saved', 'store_numbers']] as $t) {
            if (isset($have[$t[1]])) continue; $ins->execute([$sid, $t[0], $t[1], ++$n, fup_now()]);
        }
    } catch (Throwable $e) { error_log('fup_seed_store_topics: ' . $e->getMessage()); }
}

/** The numbers a topic can take by itself. key => [label, unit, fn(pdo, day) → number|null]. Every one is read-only and safe when its table is missing. */
function fup_sources(): array
{
    $one = function (string $sql) { return function (PDO $pdo, string $day) use ($sql) { try { $st = $pdo->prepare($sql); $st->execute(array_fill(0, substr_count($sql, '?'), $day)); $v = $st->fetchColumn(); return $v === false || $v === null ? 0.0 : (float)$v; } catch (Throwable $e) { return null; } }; };
    $W = 'Shop wholesale'; $S = 'Surat store'; $wp = 'sales.html#retail/shops'; $sp = 'store_dashboard.html'; $live = "status NOT IN ('cancelled', 'canceled')";
    // key => [label, unit, reader, group (which part of the HRMS it is connected to), page to open, words in a topic's name that point to it]
    return [
        'ws_sales'         => ['Orders of the day (₹)', '₹', $one("SELECT SUM(total) FROM retail_orders WHERE order_date = ? AND $live"), $W, $wp, 'sale sales sell order amount વેચાણ સેલ'],
        'ws_orders'        => ['Number of orders', 'orders', $one("SELECT COUNT(*) FROM retail_orders WHERE order_date = ? AND $live"), $W, $wp, 'orders count'],
        'ws_qty'           => ['Bottles ordered', 'bottles', $one("SELECT SUM(qty) FROM retail_orders WHERE order_date = ? AND $live"), $W, $wp, 'bottle bottles qty quantity pieces'],
        'ws_month_sales'   => ['Orders this month so far (₹)', '₹', $one("SELECT SUM(total) FROM retail_orders WHERE order_date BETWEEN DATE_FORMAT(?, '%Y-%m-01') AND ? AND $live"), $W, $wp, 'month monthly target'],
        'ws_collection'    => ['Payment collected (₹)', '₹', $one("SELECT SUM(amount) FROM retail_payments WHERE pay_date = ?"), $W, $wp, 'payment collection collected recovery પેમેન્ટ'],
        'ws_month_collect' => ['Payment collected this month so far (₹)', '₹', $one("SELECT SUM(amount) FROM retail_payments WHERE pay_date BETWEEN DATE_FORMAT(?, '%Y-%m-01') AND ?"), $W, $wp, ''],
        'ws_unpaid_orders' => ['Orders of the day still unpaid', 'orders', $one("SELECT COUNT(*) FROM retail_orders WHERE order_date = ? AND payment = 'pending' AND $live"), $W, $wp, 'unpaid pending due outstanding'],
        'ws_visits'        => ['Shop visits done', 'visits', $one("SELECT COUNT(*) FROM retail_visits WHERE DATE(visit_at) = ?"), $W, $wp, 'visit visits field'],
        'ws_visits_plan'   => ['Shop visits planned', 'visits', $one("SELECT COUNT(*) FROM retail_visit_plan WHERE plan_date = ?"), $W, $wp, 'plan planned'],
        'ws_visits_left'   => ['Planned visits not done', 'visits', $one("SELECT COUNT(*) FROM retail_visit_plan WHERE plan_date = ? AND status = 'pending'"), $W, $wp, 'missed left'],
        'ws_new_shops'     => ['New shops added', 'shops', $one("SELECT COUNT(*) FROM retail_shops WHERE DATE(created_at) = ?"), $W, $wp, 'shop shops દુકાન'],
        'ws_displays'      => ['New displays placed', 'displays', $one("SELECT COUNT(*) FROM retail_demo_display WHERE disp_date = ?"), $W, $wp, 'display displays ડિસ્પ્લે'],
        'ws_display_value' => ['Value of displays placed (₹)', '₹', $one("SELECT SUM(total) FROM retail_demo_display WHERE disp_date = ?"), $W, $wp, ''],
        'ws_stock_alerts'  => ['Stock alerts (low or out)', 'alerts', $one("SELECT COUNT(*) FROM retail_stock_alerts WHERE DATE(created_at) = ?"), $W, 'sales.html#retail/stock', 'stock low inventory'],
        'store_sales'      => ['Sales of the day (₹)', '₹', $one("SELECT SUM(total) FROM st_bills WHERE biz_date = ? AND status = 'ok'"), $S, $sp, 'sale sales sell વેચાણ સેલ'],
        'store_bills'      => ['Bills of the day', 'bills', $one("SELECT COUNT(*) FROM st_bills WHERE biz_date = ? AND status = 'ok'"), $S, $sp, 'bill bills customers'],
        'store_bottles'    => ['Bottles sold', 'bottles', $one("SELECT SUM(bottles) FROM st_bills WHERE biz_date = ? AND status = 'ok'"), $S, $sp, 'bottle bottles qty'],
        'store_flyers'     => ['Flyer drops done (photos)', 'drops', $one("SELECT COUNT(*) FROM fl_drops WHERE DATE(created_at) = ? AND (flag IS NULL OR flag = '' OR flag_ok = 1)"), $S, 'flyers.html', 'flyer flyers pamphlet ફ્લાયર'],
        'store_community'  => ['Customers added to the community (screenshot on the bill)', 'added', $one("SELECT COUNT(*) FROM st_bill_proofs WHERE kind = 'community' AND DATE(created_at) = ?"), $S, 'store_billing.html', 'community group કમ્યુનિટી ગ્રુપ add added'],
        'store_numbers'    => ['Customer numbers saved (screenshot on the bill)', 'numbers', $one("SELECT COUNT(*) FROM st_bill_proofs WHERE kind = 'contact' AND DATE(created_at) = ?"), $S, 'store_billing.html', 'number numbers save saved contact contacts નંબર સેવ'],
        'store_comm_link'  => ['Customers who joined the community from their bill link', 'customers', $one("SELECT COUNT(DISTINCT person_id) FROM st_bill_events WHERE kind = 'community' AND DATE(at) = ?"), $S, 'store_billing.html', ''],
        'store_contact_link' => ['Customers who saved our number from their bill link', 'customers', $one("SELECT COUNT(DISTINCT person_id) FROM st_bill_events WHERE kind = 'contact' AND DATE(at) = ?"), $S, 'store_billing.html', ''],
        'store_discount'   => ['Discount given (₹)', '₹', $one("SELECT SUM(discount) FROM st_bills WHERE biz_date = ? AND status = 'ok'"), $S, $sp, 'discount'],
    ];
}
function fup_source_val(PDO $pdo, string $key, string $day): ?float
{
    $s = fup_sources(); if (!isset($s[$key])) return null; $f = $s[$key][2]; $v = $f($pdo, $day); return $v === null ? null : round((float)$v, 2);
}

/** Who is asking: [emp, dept, manager, name]. */
function fup_who(PDO $pdo, array $auth): array
{
    $me = (int)($auth['employee_id'] ?? 0); $dept = 0; $name = (string)($auth['username'] ?? '');
    if ($me > 0) { try { $q = $pdo->prepare("SELECT department_id, full_name FROM employees WHERE employee_id = ?"); $q->execute([$me]); $r = $q->fetch(); if ($r) { $dept = (int)$r['department_id']; if (trim((string)$r['full_name']) !== '') $name = (string)$r['full_name']; } } catch (Throwable $e) { } }
    return ['emp' => $me, 'dept' => $dept, 'manager' => in_array(strtolower((string)($auth['role'] ?? '')), ['admin', 'hr'], true), 'name' => $name];
}
function fup_owns(array $sec, array $w): bool
{
    if ($w['manager']) return true; if ($w['emp'] <= 0) return false;
    return ((int)$sec['owner_emp'] > 0 && (int)$sec['owner_emp'] === $w['emp']) || ((int)$sec['owner_dept'] > 0 && (int)$sec['owner_dept'] === $w['dept']);
}
/** The sections this person may open: [id => row + 'own' (sees and writes everything in it)]. */
function fup_my_sections(PDO $pdo, array $w): array
{
    $out = []; $mine = [];
    if (!$w['manager'] && $w['emp'] > 0) { $q = $pdo->prepare("SELECT DISTINCT section_id FROM fu_topics WHERE active = 1 AND assignee_emp = ?"); $q->execute([$w['emp']]); foreach ($q->fetchAll() as $r) $mine[(int)$r['section_id']] = 1; }
    foreach ($pdo->query("SELECT * FROM fu_sections WHERE active = 1 ORDER BY sort_no, id")->fetchAll() as $s) {
        $own = fup_owns($s, $w); if (!$own && !isset($mine[(int)$s['id']])) continue;
        $s['own'] = $own; $out[(int)$s['id']] = $s;
    }
    return $out;
}
function fup_names(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); if (!$ids) return [];
    $out = []; try { foreach ($pdo->query("SELECT employee_id, full_name FROM employees WHERE employee_id IN (" . implode(',', $ids) . ")")->fetchAll() as $r) $out[(int)$r['employee_id']] = (string)$r['full_name']; } catch (Throwable $e) { }
    return $out;
}
function fup_dept_names(PDO $pdo): array
{
    $out = []; try { foreach ($pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll() as $r) $out[(int)$r['department_id']] = (string)$r['department_name']; } catch (Throwable $e) { }
    return $out;
}
/** The open topics of one section this person may see. */
function fup_topics(PDO $pdo, array $sec, array $w): array
{
    $q = $pdo->prepare("SELECT * FROM fu_topics WHERE section_id = ? AND active = 1 ORDER BY (status = 'done'), sort_no, id"); $q->execute([(int)$sec['id']]); $rows = $q->fetchAll();
    if (empty($sec['own'])) $rows = array_values(array_filter($rows, function ($t) use ($w) { return (int)$t['assignee_emp'] === $w['emp']; }));
    return $rows;
}
function fup_days(string $today, int $n): array { $d = []; for ($i = 0; $i < $n; $i++) $d[] = date('Y-m-d', strtotime($today . ' -' . $i . ' day')); return $d; }

/** Write / change one day's row of a topic without touching the fields that are not given (null = leave). */
function fup_put(PDO $pdo, int $topic, string $day, array $f): void
{
    $q = $pdo->prepare("SELECT id FROM fu_updates WHERE topic_id = ? AND day = ?"); $q->execute([$topic, $day]); $id = (int)$q->fetchColumn();
    if ($id <= 0) { $pdo->prepare("INSERT INTO fu_updates (topic_id, day, txt, auto, emp_id, emp_name, updated_at) VALUES (?, ?, '', 0, 0, '', ?)")->execute([$topic, $day, fup_now()]); $id = (int)$pdo->lastInsertId(); }
    $set = []; $arg = [];
    foreach (['txt', 'val', 'pct', 'auto', 'emp_id', 'emp_name', 'updated_at'] as $k) if (array_key_exists($k, $f)) { $set[] = $k . ' = ?'; $arg[] = $f[$k]; }
    if ($set) { $arg[] = $id; $pdo->prepare("UPDATE fu_updates SET " . implode(', ', $set) . " WHERE id = ?")->execute($arg); }
}
/** Topics with a data source: today's (and yesterday's) number is read from the HRMS and kept on the day's row. */
function fup_autofetch(PDO $pdo, array $topics, string $today): void
{
    $yest = date('Y-m-d', strtotime($today . ' -1 day'));
    foreach ($topics as $t) {
        if ((string)$t['source'] === '' || (string)$t['status'] === 'done') continue;
        foreach ([$today, $yest] as $d) {
            $v = fup_source_val($pdo, (string)$t['source'], $d); if ($v === null) continue;
            try {
                $q = $pdo->prepare("SELECT val, auto FROM fu_updates WHERE topic_id = ? AND day = ?"); $q->execute([(int)$t['id'], $d]); $r = $q->fetch();
                if ($r && $r['val'] !== null && (int)$r['auto'] === 0) continue;                       // a number a person typed is never overwritten
                if ($r && $r['val'] !== null && abs((float)$r['val'] - $v) < 0.005) continue;
                if (!$r && $v == 0.0 && $d !== $today) continue;
                fup_put($pdo, (int)$t['id'], $d, ['val' => $v, 'auto' => 1]);
            } catch (Throwable $e) { }
        }
    }
}
/** [topic_id => [day => row]] for the given topics and days. */
function fup_updates(PDO $pdo, array $topicIds, string $from, string $to): array
{
    $topicIds = array_values(array_filter(array_map('intval', $topicIds))); if (!$topicIds) return [];
    $q = $pdo->prepare("SELECT * FROM fu_updates WHERE topic_id IN (" . implode(',', $topicIds) . ") AND day BETWEEN ? AND ?"); $q->execute([$from, $to]); $out = [];
    foreach ($q->fetchAll() as $r) $out[(int)$r['topic_id']][(string)$r['day']] = ['text' => (string)$r['txt'], 'val' => $r['val'] === null ? null : (float)$r['val'], 'pct' => $r['pct'] === null ? null : (int)$r['pct'], 'auto' => (int)$r['auto'] === 1, 'by' => (string)$r['emp_name'], 'at' => (string)$r['updated_at']];
    return $out;
}
function fup_has_text(?array $u): bool { return $u !== null && trim((string)$u['text']) !== ''; }
/** Is the day covered? Words written, or (a connected topic) the number came from the HRMS by itself: nobody has to type what the system already knows. */
function fup_covered(?array $u, array $t): bool { return fup_has_text($u) || ((string)$t['source'] !== '' && $u !== null && $u['val'] !== null); }

/**
 * The checks that need no AI (always shown): no update today, silent for days, deadline close / late, the same words copied again.
 * Returns a list of [level red|amber|ok, topic_id, text].
 */
function fup_checks(array $topics, array $ups, array $names, string $today): array
{
    $out = [];
    foreach ($topics as $t) {
        if ((string)$t['status'] === 'done') continue;
        $id = (int)$t['id']; $u = $ups[$id] ?? []; $who = (int)$t['assignee_emp'] > 0 ? ($names[(int)$t['assignee_emp']] ?? 'the person') : 'not given to anyone';
        $last = ''; foreach ($u as $d => $x) if (fup_covered($x, $t) && $d > $last) $last = $d;
        if ((string)$t['kind'] === 'deadline' && fup_is_day((string)$t['due_date'])) {
            $left = (int)round((strtotime((string)$t['due_date']) - strtotime($today)) / 86400);
            if ($left < 0) $out[] = ['red', $id, $t['title'] . ': deadline passed ' . (-$left) . ' day' . ($left === -1 ? '' : 's') . ' ago'];
            elseif ($left <= 2) $out[] = ['amber', $id, $t['title'] . ': deadline ' . ($left === 0 ? 'is today' : 'in ' . $left . ' day' . ($left === 1 ? '' : 's'))];
        }
        if ((int)$t['assignee_emp'] <= 0 && (string)$t['source'] === '') { $out[] = ['amber', $id, $t['title'] . ': not given to anyone yet']; continue; }
        if (!fup_covered($u[$today] ?? null, $t)) {
            $created = substr((string)$t['created_at'], 0, 10); $since = $last !== '' ? $last : ($created !== '' ? $created : $today);
            $gap = (int)round((strtotime($today) - strtotime($since)) / 86400);
            if ($gap >= 2) $out[] = ['red', $id, $t['title'] . ': no update for ' . $gap . ' days (' . $who . ')'];
            else $out[] = ['amber', $id, $t['title'] . ': no update today yet (' . $who . ')'];
        } else {
            $y = date('Y-m-d', strtotime($today . ' -1 day')); $a = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$u[$today]['text'])));
            if (fup_has_text($u[$y] ?? null) && $a === mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$u[$y]['text']))) && mb_strlen($a) > 3) $out[] = ['amber', $id, $t['title'] . ': today\'s update is the same words as yesterday (' . $who . ')'];
        }
    }
    usort($out, function ($a, $b) { return ($a[0] === 'red' ? 0 : 1) <=> ($b[0] === 'red' ? 0 : 1); });
    return $out;
}

/** The topics this person must write today: his own topics + every topic of a section he owns (not for managers: they only read). */
function fup_mine(PDO $pdo, array $w): array
{
    if ($w['emp'] <= 0) return ['today' => fup_today($pdo), 'topics' => [], 'due' => 0];
    $today = fup_today($pdo); $secs = $pdo->query("SELECT * FROM fu_sections WHERE active = 1 ORDER BY sort_no, id")->fetchAll(); $out = []; $due = 0;
    foreach ($secs as $s) {
        $own = ((int)$s['owner_emp'] > 0 && (int)$s['owner_emp'] === $w['emp']) || ((int)$s['owner_dept'] > 0 && (int)$s['owner_dept'] === $w['dept']);
        $q = $pdo->prepare("SELECT * FROM fu_topics WHERE section_id = ? AND active = 1 AND status = 'open' ORDER BY sort_no, id"); $q->execute([(int)$s['id']]);
        $rows = array_values(array_filter($q->fetchAll(), function ($t) use ($w, $own) { return (int)$t['assignee_emp'] === $w['emp'] || ($own && (int)$t['assignee_emp'] <= 0); }));
        if (!$rows) continue;
        fup_autofetch($pdo, $rows, $today);
        $ups = fup_updates($pdo, array_column($rows, 'id'), date('Y-m-d', strtotime($today . ' -1 day')), $today); $src = fup_sources();
        foreach ($rows as $t) {
            $u = $ups[(int)$t['id']][$today] ?? null; $y = $ups[(int)$t['id']][date('Y-m-d', strtotime($today . ' -1 day'))] ?? null; $done = fup_covered($u, $t); if (!$done) $due++;
            $out[] = ['id' => (int)$t['id'], 'section_id' => (int)$s['id'], 'section' => (string)$s['name'], 'title' => (string)$t['title'], 'kind' => (string)$t['kind'], 'due_date' => $t['due_date'] ? (string)$t['due_date'] : null,
                'days_left' => ((string)$t['kind'] === 'deadline' && fup_is_day((string)$t['due_date'])) ? (int)round((strtotime((string)$t['due_date']) - strtotime($today)) / 86400) : null,
                'note' => (string)$t['note'], 'source' => (string)$t['source'], 'unit' => isset($src[(string)$t['source']]) ? $src[(string)$t['source']][1] : '', 'today' => $u, 'yesterday' => $y, 'done_today' => $done];
        }
    }
    return ['today' => $today, 'topics' => $out, 'due' => $due];
}
function fup_badge_data(PDO $pdo, array $auth): array
{
    try {
        if (!fup_tables($pdo)) return ['show' => false, 'manager' => false, 'due' => 0];
        $w = fup_who($pdo, $auth); if ($w['manager']) return ['show' => true, 'manager' => true, 'due' => 0];
        $m = fup_mine($pdo, $w); $show = count($m['topics']) > 0;
        if (!$show && $w['emp'] > 0) { $q = $pdo->prepare("SELECT COUNT(*) FROM fu_sections WHERE active = 1 AND ((owner_emp > 0 AND owner_emp = ?) OR (owner_dept > 0 AND owner_dept = ?))"); $q->execute([$w['emp'], $w['dept']]); $show = (int)$q->fetchColumn() > 0; }
        return ['show' => $show, 'manager' => false, 'due' => (int)$m['due']];
    } catch (Throwable $e) { return ['show' => false, 'manager' => false, 'due' => 0]; }
}

/** What Claude is given: the section's topics with the last 7 days, as plain lines. */
function fup_claude_material(array $sec, array $topics, array $ups, array $names, array $days, array $checks): string
{
    $src = fup_sources(); $L = ['SECTION: ' . $sec['name'], 'TODAY: ' . $days[0], ''];
    foreach ($topics as $t) {
        $id = (int)$t['id']; $unit = isset($src[(string)$t['source']]) ? $src[(string)$t['source']][1] : '';
        $L[] = 'TOPIC: ' . $t['title'] . ' | type: ' . ((string)$t['kind'] === 'deadline' ? 'deadline ' . ($t['due_date'] ?: 'no date') : 'runs every day') . ' | given to: ' . ((int)$t['assignee_emp'] > 0 ? ($names[(int)$t['assignee_emp']] ?? 'someone') : 'nobody') . ((string)$t['status'] === 'done' ? ' | FINISHED' : '') . ((string)$t['note'] !== '' ? ' | what is wanted: ' . $t['note'] : '');
        foreach (array_reverse($days) as $d) {
            $u = $ups[$id][$d] ?? null; $bits = [];
            if ($u && $u['val'] !== null) $bits[] = 'number ' . rtrim(rtrim(number_format($u['val'], 2, '.', ''), '0'), '.') . ($unit !== '' ? ' ' . $unit : '') . ($u['auto'] ? ' (from the system)' : '');
            if ($u && $u['pct'] !== null) $bits[] = $u['pct'] . '% done';
            if (fup_has_text($u)) $bits[] = '"' . mb_substr(trim(preg_replace('/\s+/u', ' ', $u['text'])), 0, 400) . '"';
            $L[] = '  ' . $d . ': ' . ($bits ? implode(' · ', $bits) : '(nothing written)');
        }
        $L[] = '';
    }
    if ($checks) { $L[] = 'AUTOMATIC CHECKS:'; foreach ($checks as $c) $L[] = '- ' . $c[2]; }
    return implode("\n", $L);
}

}   // function_exists

/* ============================== actions ============================== */
if (isset($action, $pdo) && is_string($action) && strpos($action, 'fup.') === 0) {
    $auth = require_auth();
    if (!fup_tables($pdo)) json_response(['error' => 'Could not prepare the Follow-up tables.'], 500);
    fup_seed($pdo);
    fup_seed_store_topics($pdo);
    $w = fup_who($pdo, $auth); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $needMgr = function () use ($w, $isPost) { if (!$w['manager']) json_response(['error' => 'Only the Admin or HR can change this.'], 403); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $topicRow = function (int $id) use ($pdo) { $q = $pdo->prepare("SELECT * FROM fu_topics WHERE id = ? AND active = 1"); $q->execute([$id]); $t = $q->fetch(); if (!$t) json_response(['error' => 'This topic is not there any more.'], 404); return $t; };
    $secRow = function (int $id) use ($pdo) { $q = $pdo->prepare("SELECT * FROM fu_sections WHERE id = ? AND active = 1"); $q->execute([$id]); $s = $q->fetch(); if (!$s) json_response(['error' => 'This section is not there any more.'], 404); return $s; };

    switch ($action) {
        case 'fup.badge': json_response(fup_badge_data($pdo, $auth));
        case 'fup.mine': json_response(fup_mine($pdo, $w));

        case 'fup.people': {
            if (!$w['manager']) json_response(['error' => 'Not allowed.'], 403);
            $st = $pdo->query("SELECT employee_id AS id, full_name AS name, department_id AS dept FROM employees WHERE COALESCE(status, 'approved') <> 'ex' ORDER BY full_name");
            $ppl = array_map(function ($r) { return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'dept' => (int)$r['dept']]; }, $st->fetchAll());
            $dn = fup_dept_names($pdo); $depts = []; foreach ($dn as $i => $n) $depts[] = ['id' => $i, 'name' => $n];
            json_response(['people' => $ppl, 'depts' => $depts]);
        }

        case 'fup.board': {
            $secs = fup_my_sections($pdo, $w); $today = fup_today($pdo); $n = max(3, min(31, (int)($_GET['days'] ?? 7)));
            $srcList = []; foreach (fup_sources() as $k => $s) $srcList[] = ['key' => $k, 'label' => $s[0], 'unit' => $s[1], 'group' => $s[3], 'page' => $s[4], 'kw' => $s[5]];
            $dn = fup_dept_names($pdo); $ownerNames = fup_names($pdo, array_column($secs, 'owner_emp'));
            $list = []; foreach ($secs as $s) $list[] = ['id' => (int)$s['id'], 'name' => (string)$s['name'], 'owner_emp' => (int)$s['owner_emp'], 'owner_dept' => (int)$s['owner_dept'], 'own' => !empty($s['own']),
                'owner' => (int)$s['owner_emp'] > 0 ? ($ownerNames[(int)$s['owner_emp']] ?? '') : ((int)$s['owner_dept'] > 0 ? (($dn[(int)$s['owner_dept']] ?? 'Department') . ' department') : '')];
            $out = ['today' => $today, 'manager' => $w['manager'], 'me' => $w['emp'], 'sections' => $list, 'sources' => $srcList, 'section' => null, 'topics' => [], 'days' => fup_days($today, $n), 'checks' => [], 'digest' => null, 'ai' => function_exists('brain_ai_on') ? (bool)brain_ai_on() : false];
            if (!$secs) json_response($out);
            $sid = (int)($_GET['section'] ?? 0); if (!isset($secs[$sid])) $sid = (int)array_key_first($secs); $sec = $secs[$sid]; $out['section'] = $sid;
            $topics = fup_topics($pdo, $sec, $w); fup_autofetch($pdo, $topics, $today);
            $days = $out['days']; $ups = fup_updates($pdo, array_column($topics, 'id'), end($days), $today); $names = fup_names($pdo, array_column($topics, 'assignee_emp')); $src = fup_sources();
            foreach ($topics as $t) {
                $id = (int)$t['id']; $can = !empty($sec['own']) || (int)$t['assignee_emp'] === $w['emp'];
                $out['topics'][] = ['id' => $id, 'title' => (string)$t['title'], 'kind' => (string)$t['kind'], 'due_date' => $t['due_date'] ? (string)$t['due_date'] : null,
                    'days_left' => ((string)$t['kind'] === 'deadline' && fup_is_day((string)$t['due_date'])) ? (int)round((strtotime((string)$t['due_date']) - strtotime($today)) / 86400) : null,
                    'assignee_emp' => (int)$t['assignee_emp'], 'assignee' => (int)$t['assignee_emp'] > 0 ? ($names[(int)$t['assignee_emp']] ?? '') : '', 'source' => (string)$t['source'], 'unit' => isset($src[(string)$t['source']]) ? $src[(string)$t['source']][1] : '',
                    'note' => (string)$t['note'], 'status' => (string)$t['status'], 'can_write' => $can, 'updates' => (object)($ups[$id] ?? [])];
            }
            $ch = fup_checks($topics, $ups, $names, $today); foreach ($ch as $c) $out['checks'][] = ['level' => $c[0], 'topic_id' => $c[1], 'text' => $c[2]];
            if (!empty($sec['own'])) { $q = $pdo->prepare("SELECT day, txt, made_at FROM fu_digest WHERE section_id = ? ORDER BY day DESC LIMIT 1"); $q->execute([$sid]); $d = $q->fetch(); if ($d) $out['digest'] = ['day' => (string)$d['day'], 'text' => (string)$d['txt'], 'at' => (string)$d['made_at']]; }
            json_response($out);
        }

        case 'fup.section_save': {
            $needMgr(); $in = json_input(); $id = (int)($in['id'] ?? 0); $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 80)); if ($name === '') json_response(['error' => 'Write the name of the section.'], 422);
            $oe = max(0, (int)($in['owner_emp'] ?? 0)); $od = max(0, (int)($in['owner_dept'] ?? 0)); if ($oe > 0) $od = 0;   // one owner: a person OR a department
            if ($id > 0) { $secRow($id); $pdo->prepare("UPDATE fu_sections SET name = ?, owner_emp = ?, owner_dept = ? WHERE id = ?")->execute([$name, $oe, $od, $id]); }
            else { $sn = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) + 1 FROM fu_sections")->fetchColumn(); $pdo->prepare("INSERT INTO fu_sections (name, owner_emp, owner_dept, sort_no, active, created_at) VALUES (?, ?, ?, ?, 1, ?)")->execute([$name, $oe, $od, $sn, fup_now()]); $id = (int)$pdo->lastInsertId(); }
            json_response(['ok' => true, 'id' => $id]);
        }
        case 'fup.section_del': {   // hidden, never deleted: the updates stay in the table
            $needMgr(); $id = (int)(json_input()['id'] ?? 0); $secRow($id); $pdo->prepare("UPDATE fu_sections SET active = 0 WHERE id = ?")->execute([$id]); json_response(['ok' => true]);
        }

        case 'fup.topic_save': {
            $needMgr(); $in = json_input(); $id = (int)($in['id'] ?? 0); $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 160)); if ($title === '') json_response(['error' => 'Write the topic.'], 422);
            $kind = ($in['kind'] ?? '') === 'deadline' ? 'deadline' : 'progress'; $due = (string)($in['due_date'] ?? ''); $due = ($kind === 'deadline' && fup_is_day($due)) ? $due : null;
            if ($kind === 'deadline' && $due === null) json_response(['error' => 'Choose the last date of this topic.'], 422);
            $emp = max(0, (int)($in['assignee_emp'] ?? 0)); $source = (string)($in['source'] ?? ''); if (!isset(fup_sources()[$source])) $source = ''; $note = trim(mb_substr((string)($in['note'] ?? ''), 0, 255));
            $was = 0;
            if ($id > 0) { $t = $topicRow($id); $was = (int)$t['assignee_emp']; $pdo->prepare("UPDATE fu_topics SET title = ?, kind = ?, due_date = ?, assignee_emp = ?, source = ?, note = ? WHERE id = ?")->execute([$title, $kind, $due, $emp, $source, $note, $id]); $sid = (int)$t['section_id']; }
            else {
                $sid = (int)($in['section_id'] ?? 0); $secRow($sid); $q = $pdo->prepare("SELECT COALESCE(MAX(sort_no), 0) + 1 FROM fu_topics WHERE section_id = ?"); $q->execute([$sid]); $sn = (int)$q->fetchColumn();
                $pdo->prepare("INSERT INTO fu_topics (section_id, title, kind, due_date, assignee_emp, source, note, status, sort_no, active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?, 1, ?, ?)")->execute([$sid, $title, $kind, $due, $emp, $source, $note, $sn, $w['emp'], fup_now()]); $id = (int)$pdo->lastInsertId();
            }
            if ($emp > 0 && $emp !== $was && $emp !== $w['emp'] && function_exists('push_send_employee')) {   // the person hears about it on the phone
                try { $s = $secRow($sid); push_send_employee($pdo, $emp, ['title' => 'New follow-up topic for you', 'body' => $s['name'] . ' · ' . $title . ' — write one update every day.', 'url' => 'followup.html?s=' . $sid, 'tag' => 'fup-' . $id]); } catch (Throwable $e) { }
            }
            json_response(['ok' => true, 'id' => $id]);
        }
        case 'fup.topic_done': {
            $needMgr(); $in = json_input(); $t = $topicRow((int)($in['id'] ?? 0)); $on = !empty($in['done']);
            $pdo->prepare("UPDATE fu_topics SET status = ?, done_at = ? WHERE id = ?")->execute([$on ? 'done' : 'open', $on ? fup_now() : null, (int)$t['id']]); json_response(['ok' => true]);
        }
        case 'fup.topic_del': {   // hidden, never deleted
            $needMgr(); $t = $topicRow((int)(json_input()['id'] ?? 0)); $pdo->prepare("UPDATE fu_topics SET active = 0 WHERE id = ?")->execute([(int)$t['id']]); json_response(['ok' => true]);
        }
        case 'fup.topic_move': {
            $needMgr(); $in = json_input(); $t = $topicRow((int)($in['id'] ?? 0)); $dir = (int)($in['dir'] ?? 0) < 0 ? -1 : 1;
            $q = $pdo->prepare("SELECT id FROM fu_topics WHERE section_id = ? AND active = 1 ORDER BY sort_no, id"); $q->execute([(int)$t['section_id']]); $ids = array_map('intval', array_column($q->fetchAll(), 'id'));
            $i = array_search((int)$t['id'], $ids, true); $j = $i + $dir;
            if ($i !== false && $j >= 0 && $j < count($ids)) { $x = $ids[$i]; $ids[$i] = $ids[$j]; $ids[$j] = $x; $u = $pdo->prepare("UPDATE fu_topics SET sort_no = ? WHERE id = ?"); foreach ($ids as $k => $tid) $u->execute([$k + 1, $tid]); }
            json_response(['ok' => true]);
        }

        case 'fup.update_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $t = $topicRow((int)($in['topic_id'] ?? 0)); $sec = $secRow((int)$t['section_id']);
            if (!fup_owns($sec, $w) && !($w['emp'] > 0 && (int)$t['assignee_emp'] === $w['emp'])) json_response(['error' => 'This topic is not given to you.'], 403);
            $today = fup_today($pdo); $day = (string)($in['day'] ?? $today); if (!fup_is_day($day)) $day = $today;
            if ($day > $today) json_response(['error' => 'You cannot write an update for a day that has not come yet.'], 422);
            if (!$w['manager'] && $day < date('Y-m-d', strtotime($today . ' -1 day'))) json_response(['error' => 'Only today and yesterday can be written. Ask the Admin to change an older day.'], 422);
            $text = trim(mb_substr((string)($in['text'] ?? ''), 0, 2000)); $f = ['txt' => $text, 'emp_id' => $w['emp'], 'emp_name' => mb_substr($w['name'], 0, 120), 'updated_at' => fup_now()];
            if (array_key_exists('value', $in)) { $v = $in['value']; if ($v === '' || $v === null) { if ((string)$t['source'] === '') { $f['val'] = null; $f['auto'] = 0; } } elseif (!is_numeric($v)) json_response(['error' => 'The number must be a number.'], 422); else { $f['val'] = round((float)$v, 2); $f['auto'] = 0; } }
            if (array_key_exists('pct', $in)) { $p = $in['pct']; $f['pct'] = ($p === '' || $p === null) ? null : max(0, min(100, (int)$p)); }
            if ($text === '' && !isset($f['val']) && !isset($f['pct'])) json_response(['error' => 'Write the update.'], 422);
            fup_put($pdo, (int)$t['id'], $day, $f);
            json_response(['ok' => true]);
        }

        case 'fup.claude': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $secs = fup_my_sections($pdo, $w); $sid = (int)(json_input()['section_id'] ?? 0); if (!isset($secs[$sid]) || empty($secs[$sid]['own'])) json_response(['error' => 'Only the Admin, HR or the owner of this section can ask Claude.'], 403);
            if (!function_exists('brain_ai_text')) json_response(['error' => 'Claude is not set up on this server.'], 422);
            $sec = $secs[$sid]; $today = fup_today($pdo); $days = fup_days($today, 7); $topics = fup_topics($pdo, $sec, $w); if (!$topics) json_response(['error' => 'Add topics first.'], 422);
            fup_autofetch($pdo, $topics, $today); $ups = fup_updates($pdo, array_column($topics, 'id'), end($days), $today); $names = fup_names($pdo, array_column($topics, 'assignee_emp'));
            $mat = fup_claude_material($sec, $topics, $ups, $names, $days, fup_checks($topics, $ups, $names, $today));
            $sys = "You check the daily follow-up sheet of a perfume company (TM Perfume House) for the owner. He reads on a phone. Use plain, short, simple English; keep names and numbers exactly as written. "
                . "The updates may be written in Gujarati, Hindi or English: understand them, do not translate names. Use ONLY what is in the sheet: never invent a number, a person or a reason. If something is not written, say it is not written. "
                . "Write these parts, each with a heading line and short lines under it (leave a part out when it has nothing): "
                . "MOVING (what really went forward, with the numbers and the change against the earlier days) · STUCK (same words repeated, no real progress, numbers falling) · NO UPDATE (who wrote nothing today, and for how many days) · "
                . "DEADLINES (close or passed) · ASK TODAY (at most 5 exact questions the owner should ask, each with the person's name). No greeting, no closing line, no markdown symbols like ** or #.";
            $old = function_exists('ai_feat') ? ai_feat('followup') : null;
            $txt = (string)brain_ai_text('internal', 'followup', $sys, $mat, 900, ['ctx' => false]);
            if (function_exists('ai_feat') && $old !== null) ai_feat((string)$old);
            if (trim($txt) === '') json_response(['error' => function_exists('brain_last_error') && brain_last_error() !== '' ? brain_last_error() : 'Claude gave no answer. Try again in a minute.'], 502);
            $q = $pdo->prepare("SELECT 1 FROM fu_digest WHERE section_id = ? AND day = ?"); $q->execute([$sid, $today]);
            if ($q->fetchColumn()) $pdo->prepare("UPDATE fu_digest SET txt = ?, made_at = ? WHERE section_id = ? AND day = ?")->execute([$txt, fup_now(), $sid, $today]);
            else $pdo->prepare("INSERT INTO fu_digest (section_id, day, txt, made_at) VALUES (?, ?, ?, ?)")->execute([$sid, $today, $txt, fup_now()]);
            json_response(['ok' => true, 'digest' => ['day' => $today, 'text' => $txt, 'at' => fup_now()]]);
        }
    }
    json_response(['error' => 'Unknown Follow-up action.'], 404);
}
