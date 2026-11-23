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
        if ((string)setting_get($pdo, 'fup_schema', '') !== '5') {   // added later: Claude's check of each update, a unit for typed numbers, a longer note for Claude
            foreach (["ALTER TABLE fu_updates ADD COLUMN ai_verdict VARCHAR(12) NOT NULL DEFAULT ''", "ALTER TABLE fu_updates ADD COLUMN ai_note VARCHAR(300) NOT NULL DEFAULT ''", "ALTER TABLE fu_updates ADD COLUMN ai_q VARCHAR(300) NOT NULL DEFAULT ''",
                "ALTER TABLE fu_topics ADD COLUMN unit VARCHAR(12) NOT NULL DEFAULT ''", "ALTER TABLE fu_topics ADD COLUMN `lag` TINYINT NOT NULL DEFAULT 0", "ALTER TABLE fu_topics ADD COLUMN metrics VARCHAR(400) NOT NULL DEFAULT ''", "ALTER TABLE fu_topics ADD COLUMN need_text TINYINT NOT NULL DEFAULT 0", "ALTER TABLE fu_updates ADD COLUMN vals TEXT NULL", "ALTER TABLE fu_topics ADD COLUMN `fields` TEXT NULL", "ALTER TABLE fu_updates ADD COLUMN fvals TEXT NULL", "ALTER TABLE fu_topics MODIFY note VARCHAR(1000) NOT NULL DEFAULT ''"] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { } }
            $pdo->query("SELECT ai_verdict, ai_note, ai_q FROM fu_updates LIMIT 1"); $pdo->query("SELECT unit, `lag`, metrics, need_text FROM fu_topics LIMIT 1"); $pdo->query("SELECT vals, fvals FROM fu_updates LIMIT 1"); $pdo->query("SELECT `fields` FROM fu_topics LIMIT 1");   // really there? (else the flag is not set and it is tried again)
            setting_set($pdo, 'fup_schema', '5');
        }
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

/**
 * ONLINE SALES of one business day, read from the places the HRMS already works them out (nothing is counted a second time here):
 *   orders / sales / queries / query orders / campaign orders / prepaid  → the Live sales day rows (cp_day_rows = ct_snapshot, the same numbers as Sales → Live and the Team board)
 *   ad spend → the Orders page ad-spend boxes (roas_day_spend)      ROAS → the typed ROAS of the day, else collection ÷ ad spend (roas_day_coll)
 *   commission → the commission engine (cm_paid_day, Total Budgeting)
 * Returns null when the sales module is not there; a single number is null when it is not recorded (it is then simply not shown — never guessed).
 */
function fup_online_day(PDO $pdo, string $day): ?array
{
    static $memo = []; if (array_key_exists($day, $memo)) return $memo[$day];
    try {
        if (!function_exists('cp_day_rows') && is_file(__DIR__ . '/ct_people.php')) { $GLOBALS['cp_funcs_only'] = true; try { require_once __DIR__ . '/ct_people.php'; } catch (Throwable $e) { } unset($GLOBALS['cp_funcs_only']); }
        if (!function_exists('cp_day_rows')) return $memo[$day] = null;
        $today = fup_today($pdo); $isToday = $day === $today; $rows = null;
        if ($isToday) $rows = function_exists('hrms_memo') ? hrms_memo('fup_on_' . $day, 180, function () use ($pdo, $day) { return (array)cp_day_rows($pdo, $day); }) : (array)cp_day_rows($pdo, $day);
        else {   // closed days are kept the same way the Team board keeps them (setting cp_d_<day>): 15 minutes for the last 3 days, 12 hours for older ones
            $ttl = $day >= date('Y-m-d', strtotime($today . ' -3 day')) ? 900 : 43200; $c = json_decode((string)setting_get($pdo, 'cp_d_' . $day, ''), true);
            if (is_array($c) && !empty($c['rows']) && time() - (int)($c['at'] ?? 0) < $ttl) $rows = $c['rows'];
            else { $rows = (array)cp_day_rows($pdo, $day); if ($rows) setting_set($pdo, 'cp_d_' . $day, json_encode(['at' => time(), 'rows' => $rows])); }
        }
        $n = 0; $s = 0; $q = 0; $qo = 0; $co = 0; $pn = 0;
        foreach ((array)$rows as $x) { if (!empty($x['off'])) continue; $n += (int)$x['n']; $s += (int)$x['s']; $q += (int)$x['q']; $qo += (int)$x['qo']; $co += (int)$x['co']; $pn += (int)($x['pn'] ?? 0); }
        $o = ['sales' => (float)$s, 'orders' => (float)$n, 'queries' => (float)$q, 'qorders' => (float)$qo, 'corders' => (float)$co, 'conv' => $q > 0 ? round($qo * 100 / $q, 1) : null, 'ticket' => $n > 0 ? (float)round($s / $n) : null, 'prepaid' => $n > 0 ? round($pn * 100 / $n, 1) : null, 'adspend' => null, 'roas' => null, 'commission' => null];
        try { if (function_exists('roas_day_spend')) { $sp = roas_day_spend($pdo, $day); if ($sp !== null) $o['adspend'] = (float)$sp; } } catch (Throwable $e) { }
        try {
            $man = function_exists('roas_manual_get') ? roas_manual_get($pdo, $day) : null;
            if ($man !== null) $o['roas'] = round((float)$man, 2);
            elseif ($o['adspend'] !== null && $o['adspend'] > 0) { $coll = (float)$s; if (function_exists('roas_day_coll')) { $r = roas_day_coll($pdo, $day, $isToday, function_exists('get_bearer_token') ? get_bearer_token() : null, (float)$s); $coll = (float)$r[0]; } $o['roas'] = round($coll / $o['adspend'], 2); }
        } catch (Throwable $e) { }
        try { if (function_exists('cm_engine_load') && cm_engine_load($pdo) && function_exists('cm_paid_day')) { $cp = cm_paid_day($pdo, $day); if (is_array($cp) && empty($cp['error'])) { $t = 0.0; foreach ((array)($cp['emps'] ?? []) as $e) $t += (float)($e['total'] ?? 0); $o['commission'] = round($t, 2); } } } catch (Throwable $e) { }
        return $memo[$day] = $o;
    } catch (Throwable $e) { error_log('fup_online_day: ' . $e->getMessage()); return $memo[$day] = null; }
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

/** Once more: the review-link and the Cleaning & Pantry numbers of the Surat Store (the owner's second pick). Same rules as fup_seed_store_topics. */
function fup_seed_store_topics2(PDO $pdo): void
{
    try {
        if ((string)setting_get($pdo, 'fup_store_topics_v2', '') === '1') return;
        $q = $pdo->query("SELECT id FROM fu_sections WHERE LOWER(name) = 'surat store' ORDER BY id LIMIT 1"); $sid = (int)$q->fetchColumn(); if ($sid <= 0) return;
        setting_set($pdo, 'fup_store_topics_v2', '1');
        $have = []; foreach ($pdo->query("SELECT source FROM fu_topics WHERE section_id = " . $sid . " AND active = 1")->fetchAll(PDO::FETCH_COLUMN) as $src) $have[(string)$src] = 1;
        $n = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM fu_topics WHERE section_id = " . $sid)->fetchColumn();
        $ins = $pdo->prepare("INSERT INTO fu_topics (section_id, title, kind, due_date, assignee_emp, source, note, status, sort_no, active, created_by, created_at) VALUES (?, ?, 'progress', NULL, 0, ?, '', 'open', ?, 1, 0, ?)");
        foreach ([['Customers who opened the review link', 'store_review_link'], ['Cleaning & Pantry checks done', 'store_clean']] as $t) {
            if (isset($have[$t[1]])) continue; $ins->execute([$sid, $t[0], $t[1], ++$n, fup_now()]);
        }
    } catch (Throwable $e) { error_log('fup_seed_store_topics2: ' . $e->getMessage()); }
}

/** Once more (the owner's correction): the review topic counts the reviews that came in, and the Cleaning topic counts the pantry PHOTOS uploaded. The two topics made by fup_seed_store_topics2 are changed to these. */
function fup_seed_store_topics3(PDO $pdo): void
{
    try {
        if ((string)setting_get($pdo, 'fup_store_topics_v3', '') === '1') return;
        $q = $pdo->query("SELECT id FROM fu_sections WHERE LOWER(name) = 'surat store' ORDER BY id LIMIT 1"); $sid = (int)$q->fetchColumn(); if ($sid <= 0) return;
        if ((string)setting_get($pdo, 'fup_store_topics_v2', '') !== '1') return;      // the earlier two are made first (same request)
        setting_set($pdo, 'fup_store_topics_v3', '1');
        $u = $pdo->prepare("UPDATE fu_topics SET title = ?, source = ? WHERE section_id = ? AND source = ?");
        $u->execute(['Reviews received (daily)', 'store_reviews', $sid, 'store_review_link']);
        $u->execute(['Pantry photos uploaded (daily)', 'store_pantry_photos', $sid, 'store_clean']);
    } catch (Throwable $e) { error_log('fup_seed_store_topics3: ' . $e->getMessage()); }
}

/** Once more: a topic with no number of its own: what was uploaded today in the Story and in the Community (the person writes it; nothing in the HRMS knows it). */
function fup_seed_store_topics4(PDO $pdo): void
{
    try {
        if ((string)setting_get($pdo, 'fup_store_topics_v4', '') === '1') return;
        $q = $pdo->query("SELECT id FROM fu_sections WHERE LOWER(name) = 'surat store' ORDER BY id LIMIT 1"); $sid = (int)$q->fetchColumn(); if ($sid <= 0) return;
        setting_set($pdo, 'fup_store_topics_v4', '1');
        $title = 'Story & Community: what was uploaded today';
        $x = $pdo->prepare("SELECT id FROM fu_topics WHERE section_id = ? AND title = ? AND active = 1"); $x->execute([$sid, $title]); if ($x->fetchColumn()) return;
        $n = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM fu_topics WHERE section_id = " . $sid)->fetchColumn();
        $pdo->prepare("INSERT INTO fu_topics (section_id, title, kind, due_date, assignee_emp, source, note, need_text, status, sort_no, active, created_by, created_at) VALUES (?, ?, 'progress', NULL, 0, '', ?, 1, 'open', ?, 1, 0, ?)")
            ->execute([$sid, $title, 'Write what you put on the Story today and what you uploaded in the Community (for example: 2 stories - new arrivals, 1 community post - offer).', $n + 1, fup_now()]);
    } catch (Throwable $e) { error_log('fup_seed_store_topics4: ' . $e->getMessage()); }
}

/** The numbers a topic can take by itself. key => [label, unit, fn(pdo, day) → number|null]. Every one is read-only and safe when its table is missing. */
function fup_sources(): array
{
    $one = function (string $sql) { return function (PDO $pdo, string $day) use ($sql) { try { $st = $pdo->prepare($sql); $st->execute(array_fill(0, substr_count($sql, '?'), $day)); $v = $st->fetchColumn(); return $v === false || $v === null ? 0.0 : (float)$v; } catch (Throwable $e) { return null; } }; };
    $on = function (string $k) { return function (PDO $pdo, string $day) use ($k) { $d = fup_online_day($pdo, $day); return $d === null ? null : $d[$k]; }; }; $O = 'Online sales'; $op = 'sales.html';
    // "as it stands now" numbers (open complaints, parcels older than 4 days): only today's row can know them, an older day is left as it was
    $now = function (string $sql) { return function (PDO $pdo, string $day) use ($sql) { if ($day !== fup_today($pdo)) return null; try { $v = $pdo->query($sql)->fetchColumn(); return $v === false || $v === null ? 0.0 : (float)$v; } catch (Throwable $e) { return null; } }; };
    $C = 'Complaints'; $cp = 'complaints.html'; $D = 'Dispatch'; $dp = 'dept_dispatch.html'; $openP = "status IN ('placed', 'tracked', 'transit')";
    $W = 'Shop wholesale'; $S = 'Surat store'; $wp = 'sales.html#retail/shops'; $sp = 'store_dashboard.html'; $live = "status NOT IN ('cancelled', 'canceled')";
    // key => [label, unit, reader, group (which part of the HRMS it is connected to), page to open, words in a topic's name that point to it]
    return [
        'on_sales'      => ['Total sales (₹)', '₹', $on('sales'), $O, $op, 'sale sales sell total વેચાણ સેલ'],
        'on_orders'     => ['Orders', 'orders', $on('orders'), $O, $op, 'order orders'],
        'on_queries'    => ['Queries', 'queries', $on('queries'), $O, $op, 'query queries'],
        'on_qorders'    => ['Query orders', 'orders', $on('qorders'), $O, $op, ''],
        'on_corders'    => ['Campaign orders', 'orders', $on('corders'), $O, $op, 'campaign'],
        'on_conv'       => ['Query → order %', '%', $on('conv'), $O, $op, 'conversion ratio'],
        'on_ticket'     => ['Ticket size (₹)', '₹', $on('ticket'), $O, $op, 'ticket'],
        'on_prepaid'    => ['Prepaid orders %', '%', $on('prepaid'), $O, $op, 'prepaid'],
        'on_adspend'    => ['Ad spend (₹)', '₹', $on('adspend'), $O, 'orders.html', 'ad ads spend spent'],
        'on_roas'       => ['ROAS', 'x', $on('roas'), $O, 'detailed_report.html', 'roas'],
        'on_commission' => ['Commission (₹)', '₹', $on('commission'), $O, 'total_budgeting.html', 'commission'],
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
        'ws_new_shops'     => ['New shops added', 'shops', $one("SELECT COUNT(*) FROM retail_shops WHERE DATE(created_at) = ?"), $W, $wp, 'shops દુકાન'],
        'ws_displays'      => ['New displays placed', 'displays', $one("SELECT COUNT(*) FROM retail_demo_display WHERE disp_date = ?"), $W, $wp, 'display displays ડિસ્પ્લે'],
        'ws_display_value' => ['Value of displays placed (₹)', '₹', $one("SELECT SUM(total) FROM retail_demo_display WHERE disp_date = ?"), $W, $wp, ''],
        'ws_stock_alerts'  => ['Stock alerts (low or out)', 'alerts', $one("SELECT COUNT(*) FROM retail_stock_alerts WHERE DATE(created_at) = ?"), $W, 'sales.html#retail/stock', 'stock low inventory'],
        'cx_new'           => ['New complaints of the day', 'complaints', $one("SELECT COUNT(*) FROM cx_cases WHERE DATE(raised_at) = ?"), $C, $cp, 'complaint complaints ફરિયાદ'],
        'cx_resolved'      => ['Complaints solved that day', 'complaints', $one("SELECT COUNT(*) FROM cx_cases WHERE DATE(resolved_at) = ?"), $C, $cp, 'solved resolved'],
        'cx_open'          => ['Complaints still open (now)', 'complaints', $now("SELECT COUNT(*) FROM cx_cases WHERE resolved_at IS NULL AND closed_at IS NULL"), $C, $cp, 'open pending'],
        'cx_leak'          => ['· of the day: leak / damaged', 'complaints', $one("SELECT COUNT(*) FROM cx_cases WHERE DATE(raised_at) = ? AND problem = 'leak'"), $C, $cp, 'leak damage damaged'],
        'cx_wrong'         => ['· of the day: wrong item', 'complaints', $one("SELECT COUNT(*) FROM cx_cases WHERE DATE(raised_at) = ? AND problem = 'wrong'"), $C, $cp, 'wrong'],
        'cx_notdispatched' => ['· of the day: parcel not dispatched', 'complaints', $one("SELECT COUNT(*) FROM cx_cases WHERE DATE(raised_at) = ? AND problem = 'notdispatched'"), $C, $cp, ''],
        'cx_notdelivered'  => ['· of the day: not delivered', 'complaints', $one("SELECT COUNT(*) FROM cx_cases WHERE DATE(raised_at) = ? AND problem = 'notdelivered'"), $C, $cp, ''],
        'cx_refund'        => ['Refund given that day (₹)', '₹', $one("SELECT SUM(refund_amt) FROM cx_cases WHERE DATE(resolved_at) = ?"), $C, $cp, 'refund'],
        'dsp_new'          => ['Parcels booked that day', 'parcels', $one("SELECT COUNT(*) FROM dp_dsp_parcels WHERE DATE(created_at) = ? AND status <> 'cancelled'"), $D, $dp, 'parcel parcels dispatch પાર્સલ'],
        'dsp_delivered'    => ['Parcels delivered that day', 'parcels', $one("SELECT COUNT(*) FROM dp_dsp_parcels WHERE DATE(delivered_at) = ?"), $D, $dp, 'delivered'],
        'dsp_old4'         => ['Parcels older than 4 days, not delivered (now)', 'parcels', $now("SELECT COUNT(*) FROM dp_dsp_parcels WHERE $openP AND created_at < '" . date('Y-m-d H:i:s', time() - 4 * 86400) . "'"), $D, $dp, 'old late pending 4'],
        'dsp_old7'         => ['Parcels older than 7 days, not delivered (now)', 'parcels', $now("SELECT COUNT(*) FROM dp_dsp_parcels WHERE $openP AND created_at < '" . date('Y-m-d H:i:s', time() - 7 * 86400) . "'"), $D, $dp, ''],
        'dsp_notrack'      => ['Parcels without tracking after 48 hours (now)', 'parcels', $now("SELECT COUNT(*) FROM dp_dsp_parcels WHERE status = 'placed' AND due48 < '" . date('Y-m-d H:i:s') . "'"), $D, $dp, 'tracking'],
        'dsp_rto'          => ['Parcels returned (RTO) that day', 'parcels', $one("SELECT COUNT(*) FROM dp_dsp_parcels WHERE status = 'rto' AND DATE(sr_at) = ?"), $D, $dp, 'rto return'],
        'store_sales'      => ['Sales of the day (₹)', '₹', $one("SELECT SUM(total) FROM st_bills WHERE biz_date = ? AND status = 'ok'"), $S, $sp, 'sale sales sell વેચાણ સેલ'],
        'store_bills'      => ['Bills of the day', 'bills', $one("SELECT COUNT(*) FROM st_bills WHERE biz_date = ? AND status = 'ok'"), $S, $sp, 'bill bills customers'],
        'store_bottles'    => ['Bottles sold', 'bottles', $one("SELECT SUM(bottles) FROM st_bills WHERE biz_date = ? AND status = 'ok'"), $S, $sp, 'bottle bottles qty'],
        'store_flyers'     => ['Flyer drops done (photos)', 'drops', $one("SELECT COUNT(*) FROM fl_drops WHERE DATE(created_at) = ? AND (flag IS NULL OR flag = '' OR flag_ok = 1)"), $S, 'flyers.html', 'flyer flyers pamphlet ફ્લાયર'],
        'store_community'  => ['Customers added to the community (screenshot on the bill)', 'added', $one("SELECT COUNT(*) FROM st_bill_proofs WHERE kind = 'community' AND DATE(created_at) = ?"), $S, 'store_billing.html', 'community group કમ્યુનિટી ગ્રુપ add added'],
        'store_numbers'    => ['Customer numbers saved (screenshot on the bill)', 'numbers', $one("SELECT COUNT(*) FROM st_bill_proofs WHERE kind = 'contact' AND DATE(created_at) = ?"), $S, 'store_billing.html', 'number numbers save saved contact contacts નંબર સેવ'],
        'store_comm_link'  => ['Customers who joined the community from their bill link', 'customers', $one("SELECT COUNT(DISTINCT person_id) FROM st_bill_events WHERE kind = 'community' AND DATE(at) = ?"), $S, 'store_billing.html', ''],
        'store_contact_link' => ['Customers who saved our number from their bill link', 'customers', $one("SELECT COUNT(DISTINCT person_id) FROM st_bill_events WHERE kind = 'contact' AND DATE(at) = ?"), $S, 'store_billing.html', ''],
        'store_review_link' => ['Customers who opened the review link on their bill', 'customers', $one("SELECT COUNT(DISTINCT person_id) FROM st_bill_events WHERE kind = 'review' AND DATE(at) = ?"), $S, 'store_billing.html', 'review reviews rating રિવ્યૂ'],
        'store_clean'      => ['Cleaning & Pantry checks done', 'checks', $one("SELECT COUNT(*) FROM of_pl_checks WHERE day = ? AND status = 'done'"), $S, 'store_cleaning.html', 'clean cleaning pantry check checks cafeteria ક્લિનિંગ પેન્ટ્રી'],
        'store_reviews'    => ['Google reviews received', 'reviews', $one("SELECT COUNT(*) FROM sx_items WHERE channel = 'review_google' AND DATE(first_in_at) = ?"), $S, 'social_inbox.html', 'review reviews rating રિવ્યૂ'],
        'store_pantry_photos' => ['Cleaning & Pantry photos uploaded (store places)', 'photos', $one("SELECT COUNT(*) FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id JOIN of_zones z ON z.id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(d.lib_key, ':', 2), ':', -1) AS UNSIGNED) JOIN of_offices o ON o.id = z.office_id WHERE t.task_date = ? AND t.status = 'done' AND d.lib_key LIKE 'of:%:p%' AND o.name LIKE '%store%'"), $S, 'store_cleaning.html', 'pantry photo photos cleaning cafeteria ફોટો પેન્ટ્રી ક્લિનિંગ'],
        'store_discount'   => ['Discount given (₹)', '₹', $one("SELECT SUM(discount) FROM st_bills WHERE biz_date = ? AND status = 'ok'"), $S, $sp, 'discount'],
    ];
}
/** The unit shown next to a topic's number: the connected source's own, else the one typed on the topic. */
function fup_unit(array $t): string { $s = fup_sources(); return isset($s[(string)$t['source']]) ? (string)$s[(string)$t['source']][1] : (string)($t['unit'] ?? ''); }
/** Every number this topic takes by itself, in order: its main number first, then the extra ones. */
function fup_topic_metrics(array $t): array
{
    $all = fup_sources(); $o = []; foreach (array_merge([(string)$t['source']], explode(',', (string)($t['metrics'] ?? ''))) as $k) { $k = trim($k); if ($k !== '' && isset($all[$k]) && !in_array($k, $o, true)) $o[] = $k; }
    return $o;
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
    foreach (['txt', 'val', 'pct', 'auto', 'emp_id', 'emp_name', 'updated_at', 'ai_verdict', 'ai_note', 'ai_q', 'vals', 'fvals'] as $k) if (array_key_exists($k, $f)) { $set[] = $k . ' = ?'; $arg[] = $f[$k]; }
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
    foreach ($topics as $t) {   // the extra numbers of a topic (a day-wise sales topic has ten of them): kept together on the day's row
        if ((string)$t['status'] === 'done' || trim((string)($t['metrics'] ?? '')) === '') continue;
        foreach ([$today, $yest] as $d) fup_fetch_extra($pdo, $t, $d);
    }
}
/** Read the extra numbers of one topic for one day and keep them on that day's row (only when something changed). */
function fup_fetch_extra(PDO $pdo, array $t, string $day): void
{
    try {
        $vals = []; foreach (array_slice(fup_topic_metrics($t), (string)$t['source'] !== '' ? 1 : 0) as $k) { $v = fup_source_val($pdo, $k, $day); if ($v !== null) $vals[$k] = $v; }
        if (!$vals) return;
        $q = $pdo->prepare("SELECT vals FROM fu_updates WHERE topic_id = ? AND day = ?"); $q->execute([(int)$t['id'], $day]); $r = $q->fetch(); $j = json_encode($vals);
        if ($r && (string)$r['vals'] === $j) return;
        fup_put($pdo, (int)$t['id'], $day, ['vals' => $j]);
    } catch (Throwable $e) { }
}
/** [topic_id => [day => row]] for the given topics and days. */
function fup_updates(PDO $pdo, array $topicIds, string $from, string $to): array
{
    $topicIds = array_values(array_filter(array_map('intval', $topicIds))); if (!$topicIds) return [];
    $q = $pdo->prepare("SELECT * FROM fu_updates WHERE topic_id IN (" . implode(',', $topicIds) . ") AND day BETWEEN ? AND ?"); $q->execute([$from, $to]); $out = [];
    foreach ($q->fetchAll() as $r) $out[(int)$r['topic_id']][(string)$r['day']] = ['text' => (string)$r['txt'], 'val' => $r['val'] === null ? null : (float)$r['val'], 'pct' => $r['pct'] === null ? null : (int)$r['pct'], 'auto' => (int)$r['auto'] === 1, 'by' => (string)$r['emp_name'], 'at' => (string)$r['updated_at'], 'ai' => (string)($r['ai_verdict'] ?? ''), 'ai_note' => (string)($r['ai_note'] ?? ''), 'q' => (string)($r['ai_q'] ?? ''), 'vals' => (isset($r['vals']) && is_array($vj = json_decode((string)$r['vals'], true))) ? $vj : (object)[], 'f' => (isset($r['fvals']) && is_array($fj = json_decode((string)$r['fvals'], true))) ? $fj : (object)[]];
    return $out;
}
/** The day whose update is DUE for this topic right now: today, or (a topic "about the day before", like yesterday's sale, filled the next morning) yesterday. */
function fup_due_day(array $t, string $today): string { return !empty($t['lag']) ? date('Y-m-d', strtotime($today . ' -1 day')) : $today; }
/** The first day this topic can be asked for: the day it was made (a topic about the day before: the day before that, since yesterday is exactly what it is for). */
function fup_start_day(array $t): string { $c = substr((string)$t['created_at'], 0, 10); return ($c !== '' && !empty($t['lag'])) ? date('Y-m-d', strtotime($c . ' -1 day')) : $c; }
/* ============================== the owner's own fields on a topic ==============================
 * A topic can carry up to 20 fields the person fills every day: a number, a few words, yes / no, a link, one of a list, or a file (photo, PDF, sheet).
 * fu_topics.fields = [{k, label, type: num|text|yesno|link|choice|file, opts: [...], req: 0|1}]      fu_updates.fvals = {k: value}   (a file: {f: stored name, n: its own name}) */
function fup_field_types(): array { return ['num' => 'Number', 'text' => 'Words', 'yesno' => 'Yes / No', 'link' => 'Link', 'choice' => 'Choose one', 'file' => 'File / photo']; }
function fup_fields(array $t): array { $j = json_decode((string)($t['fields'] ?? ''), true); return is_array($j) ? array_values(array_filter($j, function ($f) { return is_array($f) && isset($f['k'], $f['label'], $f['type']); })) : []; }
/** Clean the field list the page (or Claude) sends. Existing keys are kept so old values stay attached to their field. */
function fup_fields_clean($in, array $old = []): array
{
    $types = fup_field_types(); $out = []; $used = []; $max = 0;
    foreach ($old as $f) if (preg_match('/^f(\d+)$/', (string)$f['k'], $m)) $max = max($max, (int)$m[1]);
    foreach ((array)$in as $f) {
        if (!is_array($f) || count($out) >= 20) continue; $label = trim(mb_substr((string)($f['label'] ?? ''), 0, 80)); if ($label === '') continue;
        $type = isset($types[(string)($f['type'] ?? '')]) ? (string)$f['type'] : 'text'; $k = (string)($f['k'] ?? '');
        if (!preg_match('/^f\d+$/', $k) || isset($used[$k])) $k = 'f' . (++$max); elseif (preg_match('/^f(\d+)$/', $k, $m)) $max = max($max, (int)$m[1]);
        $used[$k] = 1; $opts = [];
        if ($type === 'choice') { $raw = is_array($f['opts'] ?? null) ? $f['opts'] : preg_split('/[,\n]+/u', (string)($f['opts'] ?? '')); foreach ($raw as $o) { $o = trim(mb_substr((string)$o, 0, 40)); if ($o !== '' && !in_array($o, $opts, true) && count($opts) < 20) $opts[] = $o; } if (count($opts) < 2) $type = 'text'; }
        $out[] = ['k' => $k, 'label' => $label, 'type' => $type, 'opts' => $type === 'choice' ? $opts : [], 'req' => !empty($f['req']) ? 1 : 0];
    }
    return $out;
}
/** Is a field's value there? */
function fup_fval_set($v): bool { return is_array($v) ? !empty($v['f']) : trim((string)$v) !== ''; }
/** The filled fields of a day's row as words, for Claude and for the checks: "Post link: https://… · Website changed: yes". */
function fup_fvals_text(array $t, ?array $u): string
{
    $b = []; $fv = (array)($u['f'] ?? []);
    foreach (fup_fields($t) as $f) { $v = $fv[$f['k']] ?? null; $b[] = $f['label'] . ': ' . (!fup_fval_set($v) ? '(not filled' . ($f['req'] ? ', REQUIRED' : '') . ')' : (is_array($v) ? 'file attached (' . ($v['n'] ?? 'file') . ')' : mb_substr((string)$v, 0, 300))); }
    return implode(' · ', $b);
}
/** The required fields that are still empty on a row (labels). */
function fup_fields_missing(array $t, ?array $u): array
{
    $o = []; $fv = (array)($u['f'] ?? []); foreach (fup_fields($t) as $f) if ($f['req'] && !fup_fval_set($fv[$f['k']] ?? null)) $o[] = $f['label']; return $o;
}
function fup_file_dir(): string { return __DIR__ . '/uploads/followup/'; }
function fup_has_text(?array $u): bool { return $u !== null && trim((string)$u['text']) !== ''; }
/** Is the day covered? Words written, or (a connected topic) the number came from the HRMS by itself: nobody has to type what the system already knows. */
function fup_covered(?array $u, array $t): bool
{
    $req = array_filter(fup_fields($t), function ($f) { return !empty($f['req']); });
    if ($req) {   // a topic with required fields: every one filled, no open question from Claude, and the words too when the topic asks for them
        if ($u === null || fup_fields_missing($t, ['f' => (array)($u['f'] ?? [])]) || ($u['q'] ?? '') !== '') return false;
        return empty($t['need_text']) || fup_has_text($u);
    }
    return (fup_has_text($u) && ($u['q'] ?? '') === '') || (empty($t['need_text']) && (string)$t['source'] !== '' && $u !== null && $u['val'] !== null);
}

/**
 * The checks that need no AI (always shown): no update today, silent for days, deadline close / late, the same words copied again.
 * Returns a list of [level red|amber, topic_id, text, type late|due|nobody|empty|copy|ai, who, days].
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
            if ($left < 0) $out[] = ['red', $id, $t['title'] . ': deadline passed ' . (-$left) . ' day' . ($left === -1 ? '' : 's') . ' ago', 'late', $who, -$left];
            elseif ($left <= 2) $out[] = ['amber', $id, $t['title'] . ': deadline ' . ($left === 0 ? 'is today' : 'in ' . $left . ' day' . ($left === 1 ? '' : 's')), 'due', $who, $left];
        }
        if ((int)$t['assignee_emp'] <= 0 && ((string)$t['source'] === '' || !empty($t['need_text']))) { $out[] = ['amber', $id, $t['title'] . ': not given to anyone yet', 'nobody', '', 0]; continue; }
        $dd = fup_due_day($t, $today); $dn = $dd === $today ? 'today' : 'yesterday'; $created = fup_start_day($t);
        if ($created !== '' && $dd < $created) continue;   // the topic did not exist on the day that is due: nothing can be missing yet
        $ut = $u[$dd] ?? null;
        if (fup_has_text($ut) && ($ut['q'] ?? '') !== '' && !fup_covered($ut, $t)) { $out[] = ['amber', $id, $t['title'] . ': the update is not clear and Claude\'s question is not answered (' . $who . ')', 'empty', $who, 1]; continue; }
        if (fup_has_text($ut) && in_array((string)($ut['ai'] ?? ''), ['vague', 'no_progress', 'mismatch'], true) && (string)($ut['ai_note'] ?? '') !== '') $out[] = [(string)$ut['ai'] === 'mismatch' ? 'red' : 'amber', $id, $t['title'] . ': Claude — ' . $ut['ai_note'] . ' (' . $who . ')', 'ai', $who, 0];
        $miss = $ut ? fup_fields_missing($t, $ut) : [];
        if ($miss && (fup_has_text($ut) || array_filter((array)($ut['f'] ?? []), 'fup_fval_set'))) { $out[] = ['amber', $id, $t['title'] . ': not complete, still to fill: ' . implode(', ', array_slice($miss, 0, 4)) . ' (' . $who . ')', 'empty', $who, 1]; continue; }
        if (!fup_covered($ut, $t)) {
            $since = $last !== '' ? $last : ($created !== '' ? $created : $dd);
            $gap = (int)round((strtotime($dd) - strtotime($since)) / 86400);
            if ($gap >= 2) $out[] = ['red', $id, $t['title'] . ': no update for ' . $gap . ' days (' . $who . ')', 'empty', $who, $gap];
            else $out[] = ['amber', $id, $t['title'] . ': no update for ' . $dn . ' yet (' . $who . ')', 'empty', $who, max(1, $gap)];
        } else {
            $y = date('Y-m-d', strtotime($dd . ' -1 day')); $a = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)($u[$dd]['text'] ?? ''))));
            if (fup_has_text($u[$y] ?? null) && $a === mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$u[$y]['text']))) && mb_strlen($a) > 3) $out[] = ['amber', $id, $t['title'] . ': the newest update is the same words as the day before (' . $who . ')', 'copy', $who, 0];
        }
    }
    usort($out, function ($a, $b) { return ($a[0] === 'red' ? 0 : 1) <=> ($b[0] === 'red' ? 0 : 1); });
    return $out;
}

/**
 * The top of the Follow-up page: across EVERY section this person owns, the deadlines that were missed and the topics nobody filled today.
 * Returns ['late' => [...], 'empty' => [...], 'by_section' => [id => count]]; each row = [section_id, section, topic_id, topic, who, days].
 */
function fup_overview(PDO $pdo, array $secs, array $w, string $today): array
{
    $out = ['late' => [], 'empty' => [], 'by_section' => []]; $from = date('Y-m-d', strtotime($today . ' -31 day'));
    foreach ($secs as $sid => $sec) {
        if (empty($sec['own'])) continue;
        $topics = array_values(array_filter(fup_topics($pdo, $sec, $w), function ($t) { return (string)$t['status'] !== 'done'; })); if (!$topics) continue;
        fup_autofetch($pdo, $topics, $today);
        $ups = fup_updates($pdo, array_column($topics, 'id'), $from, $today); $names = fup_names($pdo, array_column($topics, 'assignee_emp')); $title = []; foreach ($topics as $t) $title[(int)$t['id']] = (string)$t['title'];
        foreach (fup_checks($topics, $ups, $names, $today) as $c) {
            $k = $c[3] === 'late' ? 'late' : (($c[3] === 'empty' || $c[3] === 'nobody') ? 'empty' : ''); if ($k === '') continue;
            $out[$k][] = ['section_id' => (int)$sid, 'section' => (string)$sec['name'], 'topic_id' => (int)$c[1], 'topic' => $title[(int)$c[1]] ?? '', 'who' => $c[3] === 'nobody' ? '' : (string)$c[4], 'days' => (int)$c[5]];
            $out['by_section'][(int)$sid] = ($out['by_section'][(int)$sid] ?? 0) + 1;
        }
    }
    usort($out['late'], function ($a, $b) { return $b['days'] <=> $a['days']; }); usort($out['empty'], function ($a, $b) { return $b['days'] <=> $a['days']; });
    return $out;
}

/** The automatic numbers of one day's row for a screen: [[label, unit, value], …] (main number first). */
function fup_nums(array $t, ?array $u): array
{
    if (!$u) return []; $all = fup_sources(); $o = []; $src = (string)$t['source'];
    if ($src !== '' && isset($all[$src]) && $u['val'] !== null) $o[] = [$all[$src][0], $all[$src][1], (float)$u['val']];
    foreach ((array)($u['vals'] ?? []) as $k => $v) if (isset($all[$k])) $o[] = [$all[$k][0], $all[$k][1], (float)$v];
    return $o;
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
        $ups = fup_updates($pdo, array_column($rows, 'id'), date('Y-m-d', strtotime($today . ' -2 day')), $today); $src = fup_sources();
        foreach ($rows as $t) {
            $dd = fup_due_day($t, $today); $created = fup_start_day($t);
            $u = $ups[(int)$t['id']][$dd] ?? null; $y = $ups[(int)$t['id']][date('Y-m-d', strtotime($dd . ' -1 day'))] ?? null; $done = fup_covered($u, $t) || ($created !== '' && $dd < $created); if (!$done) $due++;
            $out[] = ['id' => (int)$t['id'], 'section_id' => (int)$s['id'], 'section' => (string)$s['name'], 'title' => (string)$t['title'], 'kind' => (string)$t['kind'], 'due_date' => $t['due_date'] ? (string)$t['due_date'] : null,
                'days_left' => ((string)$t['kind'] === 'deadline' && fup_is_day((string)$t['due_date'])) ? (int)round((strtotime((string)$t['due_date']) - strtotime($today)) / 86400) : null,
                'note' => (string)$t['note'], 'source' => (string)$t['source'], 'unit' => fup_unit($t), 'today' => $u, 'yesterday' => $y, 'done_today' => $done, 'day' => $dd, 'lag' => !empty($t['lag']), 'need_text' => !empty($t['need_text']), 'nums' => fup_nums($t, $u), 'fields' => count(fup_fields($t)), 'missing' => fup_fields_missing($t, $u ?: ['f' => []])];
        }
    }
    return ['today' => $today, 'topics' => $out, 'due' => $due];
}
/** Punch-out lock: the follow-up topics this person has not filled today, as [title, due] rows (the same shape the lock uses for jobs). Never throws. */
function fup_po_left(PDO $pdo, int $emp): array
{
    $o = []; if ($emp <= 0) return $o;
    try {
        if (!fup_tables($pdo)) return $o;
        $m = fup_mine($pdo, fup_who($pdo, ['employee_id' => $emp, 'role' => 'Employee', 'username' => '']));
        foreach ($m['topics'] as $t) if (!$t['done_today']) $o[] = ['title' => 'Follow-up update: ' . $t['title'] . ' (' . $t['section'] . ')' . (!empty($t['today']['q']) ? ' — answer Claude\'s question' : ''), 'due' => ''];
    } catch (Throwable $e) { error_log('fup_po_left: ' . $e->getMessage()); }
    return $o;
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

/** The numbers of one day's row as words: "Total sales (₹) 941000 · Queries 941 …" (the main number and the extra ones). */
function fup_vals_text(array $t, ?array $u): string
{
    if (!$u) return ''; $all = fup_sources(); $b = []; $src = (string)$t['source'];
    if ($u['val'] !== null) $b[] = ($src !== '' && isset($all[$src]) ? $all[$src][0] : 'number') . ' ' . rtrim(rtrim(number_format((float)$u['val'], 2, '.', ''), '0'), '.') . ($src === '' && fup_unit($t) !== '' ? ' ' . fup_unit($t) : '') . (!empty($u['auto']) ? ' (from the system)' : ' (typed)');
    foreach ((array)($u['vals'] ?? []) as $k => $v) if (isset($all[$k])) $b[] = $all[$k][0] . ' ' . rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') . ' (from the system)';
    return implode(' · ', $b);
}
/** What Claude is given: the section's topics with the last 7 days, as plain lines. */
function fup_claude_material(array $sec, array $topics, array $ups, array $names, array $days, array $checks): string
{
    $src = fup_sources(); $L = ['SECTION: ' . $sec['name'], 'TODAY: ' . $days[0], ''];
    foreach ($topics as $t) {
        $id = (int)$t['id']; $unit = fup_unit($t);
        $created = fup_start_day($t); $dd = fup_due_day($t, $days[0]);
        $L[] = 'TOPIC: ' . $t['title'] . ($created !== '' ? ' | started on: ' . $created : '') . (!empty($t['lag']) ? ' | this topic is about the day BEFORE and is filled the next day: the newest row that is due is ' . $dd : '') . ' | type: ' . ((string)$t['kind'] === 'deadline' ? 'deadline ' . ($t['due_date'] ?: 'no date') : 'runs every day') . ' | given to: ' . ((int)$t['assignee_emp'] > 0 ? ($names[(int)$t['assignee_emp']] ?? 'someone') : 'nobody') . ((string)$t['status'] === 'done' ? ' | FINISHED' : '') . ((string)$t['note'] !== '' ? ' | what is wanted: ' . $t['note'] : '');
        foreach (array_reverse($days) as $d) {
            $u = $ups[$id][$d] ?? null; $bits = [];
            $vt = fup_vals_text($t, $u); if ($vt !== '') $bits[] = $vt;
            if ($u && $u['pct'] !== null) $bits[] = $u['pct'] . '% done';
            if ($u && fup_fields($t) && (array)($u['f'] ?? [])) $bits[] = fup_fvals_text($t, $u);
            if (fup_has_text($u)) $bits[] = '"' . mb_substr(trim(preg_replace('/\s+/u', ' ', $u['text'])), 0, 400) . '"';
            $L[] = '  ' . $d . ': ' . ($bits ? implode(' · ', $bits) : (($created !== '' && $d < $created) ? '(the topic did not exist yet)' : ($d > $dd ? '(not due yet)' : '(nothing written)')));
        }
        $L[] = '';
    }
    if ($checks) { $L[] = 'AUTOMATIC CHECKS:'; foreach ($checks as $c) $L[] = '- ' . $c[2]; }
    return implode("\n", $L);
}

/**
 * Claude reads ONE update the moment it is saved. Returns [verdict, note, question]:
 *   verdict  clear | vague | no_progress | mismatch | '' (Claude is off or gave nothing: the update is simply kept)
 *   note     one short line for the owner          question  one short question to the person when the update is vague ('' when $final)
 */
function fup_ai_check(PDO $pdo, array $t, array $sec, string $day, string $text, $val, $pct, bool $final, string $fields = ''): array
{
    if ((trim($text) === '' && $fields === '') || !function_exists('brain_ai_json') || (function_exists('brain_ai_on') && !brain_ai_on())) return ['', '', ''];
    $unit = fup_unit($t); $from = date('Y-m-d', strtotime($day . ' -4 day')); $prev = fup_updates($pdo, [(int)$t['id']], $from, $day)[(int)$t['id']] ?? [];
    $L = ['SECTION: ' . $sec['name'], 'TOPIC: ' . $t['title'], 'TYPE: ' . ((string)$t['kind'] === 'deadline' ? 'has a last date: ' . ($t['due_date'] ?: 'no date') : 'runs every day'), 'DAY OF THIS UPDATE: ' . $day];
    if (trim((string)$t['note']) !== '') $L[] = 'WHAT THE OWNER WANTS IN THIS UPDATE: ' . $t['note'];
    $nums = []; foreach (fup_topic_metrics($t) as $k) { $sv = fup_source_val($pdo, $k, $day); if ($sv !== null) $nums[] = fup_sources()[$k][0] . ' ' . $sv; }
    if ($nums) $L[] = 'NUMBERS FROM THE SYSTEM FOR THIS DAY (true, the person does not type them; the update should EXPLAIN them, not repeat them): ' . implode(' · ', $nums);
    $L[] = 'EARLIER DAYS:'; $had = false;
    foreach ($prev as $d => $u) { if ($d >= $day) continue; $b = []; $vt = fup_vals_text($t, $u); if ($vt !== '') $b[] = $vt; if ($u['pct'] !== null) $b[] = $u['pct'] . '%'; if (fup_has_text($u)) $b[] = '"' . mb_substr($u['text'], 0, 300) . '"'; if ($b) { $L[] = '  ' . $d . ': ' . implode(' · ', $b); $had = true; } }
    if (!$had) $L[] = '  (nothing written before)';
    $L[] = 'THE UPDATE TO CHECK: "' . (trim($text) !== '' ? mb_substr($text, 0, 1500) : '(no words written: judge the fields)') . '"' . ($val !== null && $val !== '' ? ' · number typed: ' . $val . ' ' . $unit : '') . ($pct !== null && $pct !== '' ? ' · ' . $pct . '% done' : '');
    if ($fields !== '') $L[] = 'FIELDS FILLED WITH THIS UPDATE: ' . $fields;
    $sys = "You check ONE daily follow-up update written by a staff member of a perfume company, for the owner. The update may be in Gujarati, Hindi or English. "
        . "Judge only from what is given; never invent a fact. Answer with JSON: {\"verdict\": \"clear\" | \"vague\" | \"no_progress\" | \"mismatch\", \"note\": \"...\", \"question\": \"...\"}. "
        . "clear = it says concretely what happened (a count, a name, a result, or a real reason). vague = words like 'work going on', 'done', 'ok', 'will do' with nothing concrete, or it leaves out what the owner wants. "
        . "no_progress = it clearly says the same as the earlier days with nothing new. mismatch = it clearly contradicts the number from the system or the earlier days. "
        . "A short update is fine when it is concrete: do not ask for more than the topic needs. note = one short plain-English line for the owner (max 20 words; for 'clear' a 3 to 6 word gist). "
        . "question = ONLY for 'vague': ONE short, exact question to the person, in the same language and script the update is written in (max 18 words). Otherwise an empty string.";
    $old = function_exists('ai_feat') ? ai_feat('followup') : '';
    try { $j = brain_ai_json('internal', 'followup', $sys, implode("\n", $L), 300, ['ctx' => false, 'rules' => false, 'model' => 'claude-haiku-5-5', 'timeout' => 25]); } catch (Throwable $e) { $j = null; }
    if (function_exists('ai_feat')) ai_feat((string)$old);
    if (!is_array($j) || !in_array((string)($j['verdict'] ?? ''), ['clear', 'vague', 'no_progress', 'mismatch'], true)) return ['', '', ''];
    $v = (string)$j['verdict']; $q = ($v === 'vague' && !$final) ? trim(mb_substr((string)($j['question'] ?? ''), 0, 300)) : '';
    return [$v, trim(mb_substr((string)($j['note'] ?? ''), 0, 300)), $q];
}

/** Claude's written note of one section for today (reads the last 7 days), saved in fu_digest. Returns [ok, text or the reason]. */
function fup_digest_make(PDO $pdo, array $sec, array $w, string $today): array
{
    if (!function_exists('brain_ai_text')) return [false, 'Claude is not set up on this server.'];
    $days = fup_days($today, 7); $topics = fup_topics($pdo, $sec, $w); if (!$topics) return [false, 'Add topics first.'];
    fup_autofetch($pdo, $topics, $today); $ups = fup_updates($pdo, array_column($topics, 'id'), end($days), $today); $names = fup_names($pdo, array_column($topics, 'assignee_emp'));
    $mat = fup_claude_material($sec, $topics, $ups, $names, $days, fup_checks($topics, $ups, $names, $today));
    $sys = "You check the daily follow-up sheet of a perfume company (TM Perfume House) for the owner. He reads on a phone. Use plain, short, simple English; keep names and numbers exactly as written. "
        . "The updates may be written in Gujarati, Hindi or English: understand them, do not translate names. Use ONLY what is in the sheet: never invent a number, a person or a reason. If something is not written, say it is not written. "
        . "A day marked '(the topic did not exist yet)' or '(not due yet)' is NOT a missing update: never count it, never mention it, never ask about it. If a topic has only one day of data, say so in one line and do not call it stuck. "
        . "Write these parts, each with a heading line and short lines under it (leave a part out completely when it has nothing real to say; never write a heading just to say there is nothing): "
        . "MOVING (what really went forward, with the numbers and the change against the earlier days) · STUCK (same words repeated, no real progress, numbers falling) · NO UPDATE (who wrote nothing today, and for how many days) · "
        . "DEADLINES (close or passed) · ASK TODAY (at most 5 exact questions the owner should ask, each with the person's name; ask only what the sheet really leaves open, and if the owner's own description of the topic names something that the update did not give, ask for exactly that). No greeting, no closing line, no markdown symbols like ** or #.";
    $old = function_exists('ai_feat') ? ai_feat('followup') : '';
    $txt = (string)brain_ai_text('internal', 'followup', $sys, $mat, 900, ['ctx' => false]);
    if (function_exists('ai_feat')) ai_feat((string)$old);
    if (trim($txt) === '') return [false, function_exists('brain_last_error') && brain_last_error() !== '' ? brain_last_error() : 'Claude gave no answer. Try again in a minute.'];
    $sid = (int)$sec['id']; $q = $pdo->prepare("SELECT 1 FROM fu_digest WHERE section_id = ? AND day = ?"); $q->execute([$sid, $today]);
    if ($q->fetchColumn()) $pdo->prepare("UPDATE fu_digest SET txt = ?, made_at = ? WHERE section_id = ? AND day = ?")->execute([$txt, fup_now(), $sid, $today]);
    else $pdo->prepare("INSERT INTO fu_digest (section_id, day, txt, made_at) VALUES (?, ?, ?, ?)")->execute([$sid, $today, $txt, fup_now()]);
    return [true, $txt];
}

/**
 * Every evening, by itself (from whichever request comes in after the hour; api.php calls this): Claude writes the note of every section, and the Admin / HR get ONE phone
 * notification with the day's count (deadlines missed, topics not filled). Hour = setting fup_digest_hour (default 20 = 8 pm). Once a day. $force = run now (tests).
 */
function fup_daily_pass(PDO $pdo, bool $force = false): array
{
    $day = date('Y-m-d'); $hour = max(0, min(23, (int)setting_get($pdo, 'fup_digest_hour', 20)));
    if (!$force) {
        if ((int)date('G') < $hour) return ['skipped' => 'before the hour'];
        if ((string)setting_get($pdo, 'fup_digest_day', '') === $day) return ['skipped' => 'done today'];
        $lock = sys_get_temp_dir() . '/hrms_fup_daily_' . substr(md5(__DIR__), 0, 8); if (is_file($lock) && time() - (int)filemtime($lock) < 600) return ['skipped' => 'running']; @touch($lock);
    }
    if (!fup_tables($pdo)) return ['error' => 'tables'];
    setting_set($pdo, 'fup_digest_day', $day);
    if (!$force && function_exists('hrms_release')) hrms_release();   // the person whose request started this does not wait for Claude
    $w = ['emp' => 0, 'dept' => 0, 'manager' => true, 'name' => 'Follow-up']; $secs = fup_my_sections($pdo, $w); $today = fup_today($pdo); $made = 0;
    $ov = fup_overview($pdo, $secs, $w, $today);
    $nTopics = 0; try { $nTopics = (int)$pdo->query("SELECT COUNT(*) FROM fu_topics t JOIN fu_sections s ON s.id = t.section_id WHERE t.active = 1 AND s.active = 1 AND t.status = 'open'")->fetchColumn(); } catch (Throwable $e) { }
    $sent = 0;
    if ($nTopics > 0 && function_exists('push_send_many')) {
        try {
            $ids = []; foreach ($pdo->query("SELECT DISTINCT employee_id FROM users WHERE role IN ('Admin', 'HR') AND employee_id IS NOT NULL AND employee_id > 0")->fetchAll() as $r) $ids[] = (int)$r['employee_id'];
            $l = count($ov['late']); $e = count($ov['empty']);
            $body = ($l || $e) ? ($l ? $l . ' deadline' . ($l === 1 ? '' : 's') . ' missed' : 'No deadline missed') . ' · ' . ($e ? $e . ' topic' . ($e === 1 ? '' : 's') . ' not filled today' : 'every topic filled') . '.' : 'All clear: no deadline missed, every topic filled.';
            if ($ids) $sent = (int)push_send_many($pdo, $ids, ['title' => 'Follow-up · today\'s report', 'body' => $body . ' Open Follow-up for Claude\'s notes.', 'url' => 'followup.html', 'tag' => 'fup-daily-' . $day]);
        } catch (Throwable $e) { error_log('fup_daily_pass push: ' . $e->getMessage()); }
    }
    // the notes come after the notification: they can take a minute, the count must not wait for them
    foreach ($secs as $sec) { try { $r = fup_digest_make($pdo, $sec, $w, $today); if ($r[0]) $made++; } catch (Throwable $e) { error_log('fup_daily_pass: ' . $e->getMessage()); } }
    return ['day' => $day, 'sections' => count($secs), 'notes' => $made, 'late' => count($ov['late']), 'empty' => count($ov['empty']), 'sent' => $sent];
}

}   // function_exists

/* ============================== actions ============================== */
if (isset($action, $pdo) && is_string($action) && strpos($action, 'fup.') === 0) {
    if ($action === 'fup.file' && !empty($_GET['token']) && function_exists('verify_token')) {   // a file link opened in a new tab carries the login in its address (the same way the other file links of the HRMS do)
        $auth = verify_token((string)$_GET['token']); if (!$auth) json_response(['error' => 'Unauthorized. Please log in again.'], 401); if (function_exists('refresh_auth')) $auth = refresh_auth($auth);
    } else $auth = require_auth();
    if (!fup_tables($pdo)) json_response(['error' => 'Could not prepare the Follow-up tables.'], 500);
    fup_seed($pdo);
    fup_seed_store_topics($pdo);
    fup_seed_store_topics2($pdo);
    fup_seed_store_topics3($pdo);
    fup_seed_store_topics4($pdo);
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
            $out = ['today' => $today, 'manager' => $w['manager'], 'me' => $w['emp'], 'sections' => $list, 'sources' => $srcList, 'section' => null, 'topics' => [], 'days' => fup_days($today, $n), 'checks' => [], 'overview' => ['late' => [], 'empty' => [], 'by_section' => (object)[]], 'digest' => null, 'ai' => function_exists('brain_ai_on') ? (bool)brain_ai_on() : false];
            if (!$secs) json_response($out);
            $out['overview'] = fup_overview($pdo, $secs, $w, $today);
            $sid = (int)($_GET['section'] ?? 0); if (!isset($secs[$sid])) $sid = (int)array_key_first($secs); $sec = $secs[$sid]; $out['section'] = $sid;
            $topics = fup_topics($pdo, $sec, $w); fup_autofetch($pdo, $topics, $today);
            $days = $out['days']; $ups = fup_updates($pdo, array_column($topics, 'id'), end($days), $today); $names = fup_names($pdo, array_column($topics, 'assignee_emp')); $src = fup_sources();
            foreach ($topics as $t) {
                $id = (int)$t['id']; $can = !empty($sec['own']) || (int)$t['assignee_emp'] === $w['emp'];
                $out['topics'][] = ['id' => $id, 'title' => (string)$t['title'], 'kind' => (string)$t['kind'], 'due_date' => $t['due_date'] ? (string)$t['due_date'] : null,
                    'days_left' => ((string)$t['kind'] === 'deadline' && fup_is_day((string)$t['due_date'])) ? (int)round((strtotime((string)$t['due_date']) - strtotime($today)) / 86400) : null,
                    'assignee_emp' => (int)$t['assignee_emp'], 'assignee' => (int)$t['assignee_emp'] > 0 ? ($names[(int)$t['assignee_emp']] ?? '') : '', 'source' => (string)$t['source'], 'unit' => fup_unit($t), 'own_unit' => (string)($t['unit'] ?? ''), 'lag' => !empty($t['lag']), 'need_text' => !empty($t['need_text']), 'fields' => fup_fields($t), 'metrics' => fup_topic_metrics($t), 'due_day' => fup_due_day($t, $today), 'created' => fup_start_day($t),
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
            $emp = max(0, (int)($in['assignee_emp'] ?? 0)); $source = (string)($in['source'] ?? ''); if (!isset(fup_sources()[$source])) $source = '';
            $mx = ''; if (isset($in['metrics']) && is_array($in['metrics'])) { $ks = fup_topic_metrics(['source' => '', 'metrics' => implode(',', array_map('strval', $in['metrics']))]); $source = $ks ? $ks[0] : ''; $mx = implode(',', array_slice($ks, 1, 30)); }   // the first ticked number is the main one
            $unit = $source !== '' ? '' : trim(mb_substr((string)($in['unit'] ?? ''), 0, 12));
            $needText = !empty($in['need_text']) ? 1 : 0; $fieldsIn = array_key_exists('fields', $in) ? $in['fields'] : null; $note = trim(mb_substr((string)($in['note'] ?? ''), 0, 1000)); $lag = !empty($in['lag']) ? 1 : 0;
            $was = 0;
            if ($id > 0) { $t = $topicRow($id); $was = (int)$t['assignee_emp']; $pdo->prepare("UPDATE fu_topics SET title = ?, kind = ?, due_date = ?, assignee_emp = ?, source = ?, note = ?, unit = ?, `lag` = ?, metrics = ?, need_text = ? WHERE id = ?")->execute([$title, $kind, $due, $emp, $source, $note, $unit, $lag, $mx, $needText, $id]); $sid = (int)$t['section_id'];
                if ($fieldsIn !== null) $pdo->prepare("UPDATE fu_topics SET `fields` = ? WHERE id = ?")->execute([json_encode(fup_fields_clean($fieldsIn, fup_fields($t)), JSON_UNESCAPED_UNICODE), $id]); }
            else {
                $sid = (int)($in['section_id'] ?? 0); $secRow($sid); $q = $pdo->prepare("SELECT COALESCE(MAX(sort_no), 0) + 1 FROM fu_topics WHERE section_id = ?"); $q->execute([$sid]); $sn = (int)$q->fetchColumn();
                $pdo->prepare("INSERT INTO fu_topics (section_id, title, kind, due_date, assignee_emp, source, note, unit, `lag`, metrics, need_text, status, sort_no, active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, 1, ?, ?)")->execute([$sid, $title, $kind, $due, $emp, $source, $note, $unit, $lag, $mx, $needText, $sn, $w['emp'], fup_now()]); $id = (int)$pdo->lastInsertId();
                if ($fieldsIn !== null) $pdo->prepare("UPDATE fu_topics SET `fields` = ? WHERE id = ?")->execute([json_encode(fup_fields_clean($fieldsIn), JSON_UNESCAPED_UNICODE), $id]);
            }
            if ($source !== '') {   // the last 7 days are filled at once, so the sheet (and Claude) can compare from the first day
                try { $tt = $topicRow($id); $td = fup_today($pdo); for ($i = 0; $i < 7; $i++) { $d = date('Y-m-d', strtotime($td . ' -' . $i . ' day')); $v = fup_source_val($pdo, $source, $d); if ($v !== null) { $q0 = $pdo->prepare("SELECT val, auto FROM fu_updates WHERE topic_id = ? AND day = ?"); $q0->execute([$id, $d]); $r0 = $q0->fetch(); if ($r0 ? ($r0['val'] === null || (int)$r0['auto'] === 1) : ($v != 0.0 || $d === $td)) fup_put($pdo, $id, $d, ['val' => $v, 'auto' => 1]); } if ($mx !== '') fup_fetch_extra($pdo, $tt, $d); } } catch (Throwable $e) { error_log('fup backfill: ' . $e->getMessage()); }
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
            if (!$w['manager'] && $day < date('Y-m-d', strtotime($today . (!empty($t['lag']) ? ' -2 day' : ' -1 day')))) json_response(['error' => 'Only today and yesterday can be written. Ask the Admin to change an older day.'], 422);
            $text = trim(mb_substr((string)($in['text'] ?? ''), 0, 2000)); $f = ['txt' => $text, 'emp_id' => $w['emp'], 'emp_name' => mb_substr($w['name'], 0, 120), 'updated_at' => fup_now()];
            if (array_key_exists('value', $in)) { $v = $in['value']; if ($v === '' || $v === null) { if ((string)$t['source'] === '') { $f['val'] = null; $f['auto'] = 0; } } elseif (!is_numeric($v)) json_response(['error' => 'The number must be a number.'], 422); else { $f['val'] = round((float)$v, 2); $f['auto'] = 0; } }
            if (array_key_exists('pct', $in)) { $p = $in['pct']; $f['pct'] = ($p === '' || $p === null) ? null : max(0, min(100, (int)$p)); }
            $defs = fup_fields($t); $fvChanged = false; $fvNow = [];
            if ($defs) {
                $q1 = $pdo->prepare("SELECT fvals FROM fu_updates WHERE topic_id = ? AND day = ?"); $q1->execute([(int)$t['id'], $day]); $fvNow = json_decode((string)$q1->fetchColumn(), true); if (!is_array($fvNow)) $fvNow = [];
                foreach ($defs as $fd) {
                    $k = $fd['k']; if ($fd['type'] === 'file' || !is_array($in['fields'] ?? null) || !array_key_exists($k, $in['fields'])) continue;   // a file comes through fup.file_put
                    $v = trim((string)$in['fields'][$k]); $lb = '“' . $fd['label'] . '”';
                    if ($v !== '') {
                        if ($fd['type'] === 'num') { if (!is_numeric($v)) json_response(['error' => $lb . ' must be a number.'], 422); $v = (string)round((float)$v, 2); }
                        elseif ($fd['type'] === 'yesno') { $v = strtolower($v); if (!in_array($v, ['yes', 'no'], true)) json_response(['error' => 'Choose Yes or No for ' . $lb . '.'], 422); }
                        elseif ($fd['type'] === 'link') { if (!preg_match('#^https?://[^\s]+\.[^\s]+#i', $v)) json_response(['error' => $lb . ' must be a link that starts with https://'], 422); $v = mb_substr($v, 0, 500); }
                        elseif ($fd['type'] === 'choice') { if (!in_array($v, $fd['opts'], true)) json_response(['error' => 'Choose one of the list for ' . $lb . '.'], 422); }
                        else $v = mb_substr($v, 0, 500);
                    }
                    if ((string)($fvNow[$k] ?? '') !== $v || !array_key_exists($k, $fvNow)) { if ($v === '') unset($fvNow[$k]); else $fvNow[$k] = $v; $fvChanged = true; }
                }
                if ($fvChanged) $f['fvals'] = json_encode($fvNow, JSON_UNESCAPED_UNICODE);
            }
            if ($text === '' && !isset($f['val']) && !isset($f['pct']) && !$fvChanged && !array_filter($fvNow, 'fup_fval_set')) json_response(['error' => 'Write the update.'], 422);
            $q0 = $pdo->prepare("SELECT txt, ai_verdict FROM fu_updates WHERE topic_id = ? AND day = ?"); $q0->execute([(int)$t['id'], $day]); $was = $q0->fetch();
            fup_put($pdo, (int)$t['id'], $day, $f);
            // Claude reads the update now. A vague one gets ONE question back to the person (never to the Admin editing somebody's row, never twice): the day counts as filled when it is answered.
            $ai = null;
            $fvText = $defs ? fup_fvals_text($t, ['f' => $fvNow]) : '';
            if (($text !== '' || $fvChanged) && !($was && (string)$was['txt'] === $text && !$fvChanged && (string)$was['ai_verdict'] !== '' && empty($in['final']))) {
                $mine = $w['emp'] > 0 && ((int)$t['assignee_emp'] === $w['emp'] || !$w['manager']); $final = !empty($in['final']) || !$mine;
                $r = fup_ai_check($pdo, $t, $sec, $day, $text, $f['val'] ?? null, $f['pct'] ?? null, $final, $fvText);
                fup_put($pdo, (int)$t['id'], $day, ['ai_verdict' => $r[0], 'ai_note' => $r[1], 'ai_q' => $r[2]]);
                $ai = ['verdict' => $r[0], 'note' => $r[1], 'question' => $r[2]];
            }
            json_response(['ok' => true, 'ai' => $ai]);
        }

        case 'fup.file_put': {   // one file for one field of one day's row (multipart: topic_id, day, key, file)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $t = $topicRow((int)($_POST['topic_id'] ?? 0)); $sec = $secRow((int)$t['section_id']);
            if (!fup_owns($sec, $w) && !($w['emp'] > 0 && (int)$t['assignee_emp'] === $w['emp'])) json_response(['error' => 'This topic is not given to you.'], 403);
            $today = fup_today($pdo); $day = (string)($_POST['day'] ?? $today); if (!fup_is_day($day) || $day > $today) json_response(['error' => 'Choose a day up to today.'], 422);
            if (!$w['manager'] && $day < date('Y-m-d', strtotime($today . (!empty($t['lag']) ? ' -2 day' : ' -1 day')))) json_response(['error' => 'Only today and yesterday can be written.'], 422);
            $k = (string)($_POST['key'] ?? ''); $fd = null; foreach (fup_fields($t) as $x) if ($x['k'] === $k && $x['type'] === 'file') $fd = $x; if (!$fd) json_response(['error' => 'This field takes no file.'], 422);
            $up = $_FILES['file'] ?? null; if (!$up || (int)($up['error'] ?? 1) !== 0 || !is_file((string)$up['tmp_name'])) json_response(['error' => 'The file did not arrive. Try again.'], 422);
            if ((int)$up['size'] > 12 * 1024 * 1024) json_response(['error' => 'The file is bigger than 12 MB.'], 422);
            $ext = strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION)); $okExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'xlsx', 'xls', 'csv', 'doc', 'docx', 'txt', 'mp4'];
            if (!in_array($ext, $okExt, true)) json_response(['error' => 'This kind of file is not allowed. Use a photo, PDF, Excel, Word, text or MP4 file.'], 422);
            $dir = fup_file_dir(); if (!is_dir($dir)) @mkdir($dir, 0775, true); if (!is_dir($dir) || !is_writable($dir)) json_response(['error' => 'The server cannot save files in uploads/followup.'], 500);
            $name = 'fu_' . (int)$t['id'] . '_' . str_replace('-', '', $day) . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (!@move_uploaded_file((string)$up['tmp_name'], $dir . $name) && !@copy((string)$up['tmp_name'], $dir . $name)) json_response(['error' => 'Could not save the file on the server.'], 500);
            $q1 = $pdo->prepare("SELECT fvals FROM fu_updates WHERE topic_id = ? AND day = ?"); $q1->execute([(int)$t['id'], $day]); $fv = json_decode((string)$q1->fetchColumn(), true); if (!is_array($fv)) $fv = [];
            $fv[$k] = ['f' => $name, 'n' => mb_substr(basename((string)$up['name']), 0, 120)];
            fup_put($pdo, (int)$t['id'], $day, ['fvals' => json_encode($fv, JSON_UNESCAPED_UNICODE), 'emp_id' => $w['emp'], 'emp_name' => mb_substr($w['name'], 0, 120), 'updated_at' => fup_now()]);
            json_response(['ok' => true, 'file' => $fv[$k]]);
        }
        case 'fup.file': {   // open an attached file: only the people who may see the topic
            $t = $topicRow((int)($_GET['topic_id'] ?? 0)); $sec = $secRow((int)$t['section_id']);
            if (!fup_owns($sec, $w) && !($w['emp'] > 0 && (int)$t['assignee_emp'] === $w['emp'])) json_response(['error' => 'Not found.'], 404);
            $q1 = $pdo->prepare("SELECT fvals FROM fu_updates WHERE topic_id = ? AND day = ?"); $q1->execute([(int)$t['id'], (string)($_GET['day'] ?? '')]); $fv = json_decode((string)$q1->fetchColumn(), true);
            $v = is_array($fv) ? ($fv[(string)($_GET['key'] ?? '')] ?? null) : null; $fn = is_array($v) ? basename((string)($v['f'] ?? '')) : '';
            if ($fn === '' || !is_file(fup_file_dir() . $fn)) json_response(['error' => 'The file is not there.'], 404);
            $ct = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8'][strtolower(pathinfo($fn, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
            header('Content-Type: ' . $ct); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600'); header('Content-Length: ' . filesize(fup_file_dir() . $fn));
            header('Content-Disposition: ' . (strpos($ct, 'image/') === 0 || $ct === 'application/pdf' || $ct === 'video/mp4' ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)($v['n'] ?? $fn)) . '"');
            readfile(fup_file_dir() . $fn); exit;
        }
        case 'fup.suggest': {   // Claude proposes the set-up of a topic from its name and the owner's description; nothing is saved until the owner presses Save
            $needMgr(); $in = json_input(); $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 160)); $note = trim(mb_substr((string)($in['note'] ?? ''), 0, 1000)); if ($title === '') json_response(['error' => 'Write the topic first.'], 422);
            if (!function_exists('brain_ai_json') || (function_exists('brain_ai_on') && !brain_ai_on())) json_response(['error' => 'Claude is switched off on this server.'], 422);
            $cat = []; foreach (fup_sources() as $k => $x) $cat[] = $k . ' = ' . $x[3] . ': ' . $x[0];
            $sys = "You help the owner of a perfume company (online sales, a wholesale channel to shops, one store, a dispatch team, a complaints team, social media) set up ONE daily follow-up topic in his HRMS. "
                . "A topic has: (a) NUMBERS the HRMS reads by itself every day (choose only from the catalogue given; never invent a key), (b) FIELDS the person fills by hand, each of type num (a number), text (a few words), yesno, link (a URL), choice (one of a short list: give opts) or file (a photo, PDF or sheet), "
                . "(c) whether the person must ALSO write a summary in words, (d) whether the topic is about the day BEFORE (filled the next day, like yesterday's sale or yesterday's complaints). "
                . "Rules: prefer a number from the catalogue over a hand field whenever the catalogue has it. Make a hand field only for something the HRMS cannot know (a post link, review counts on Instagram / Google, 'website changed: yes/no', a reason, a screenshot). "
                . "Keep it small: at most 8 fields, short plain-English labels (max 5 words). Mark req true only for the fields without which the day's update is useless. "
                . "Answer with JSON: {\"metrics\": [catalogue keys], \"fields\": [{\"label\": \"...\", \"type\": \"...\", \"opts\": [\"...\"], \"req\": true|false}], \"need_text\": true|false, \"lag\": true|false, \"brief\": \"2-3 short sentences for the checker: what a good daily update of this topic must say\", \"why\": \"one short line for the owner on what you set up\"}.";
            $old = function_exists('ai_feat') ? ai_feat('followup') : '';
            try { $j = brain_ai_json('internal', 'followup', $sys, "SECTION: " . (string)($in['section'] ?? '') . "\nTOPIC: " . $title . "\nTHE OWNER'S OWN WORDS ABOUT IT: " . ($note !== '' ? $note : '(nothing written)') . "\n\nCATALOGUE OF NUMBERS THE HRMS CAN READ BY ITSELF:\n" . implode("\n", $cat), 1200, ['ctx' => false, 'rules' => false, 'timeout' => 45]); } catch (Throwable $e) { $j = null; }
            if (function_exists('ai_feat')) ai_feat((string)$old);
            if (!is_array($j)) json_response(['error' => function_exists('brain_last_error') && brain_last_error() !== '' ? brain_last_error() : 'Claude gave no answer. Try again in a minute.'], 502);
            $mx = fup_topic_metrics(['source' => '', 'metrics' => implode(',', array_map('strval', (array)($j['metrics'] ?? [])))]);
            json_response(['ok' => true, 'metrics' => $mx, 'fields' => fup_fields_clean((array)($j['fields'] ?? [])), 'need_text' => !empty($j['need_text']), 'lag' => !empty($j['lag']), 'brief' => trim(mb_substr((string)($j['brief'] ?? ''), 0, 1000)), 'why' => trim(mb_substr((string)($j['why'] ?? ''), 0, 300))]);
        }
        case 'fup.claude': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $secs = fup_my_sections($pdo, $w); $sid = (int)(json_input()['section_id'] ?? 0); if (!isset($secs[$sid]) || empty($secs[$sid]['own'])) json_response(['error' => 'Only the Admin, HR or the owner of this section can ask Claude.'], 403);
            $today = fup_today($pdo); $r = fup_digest_make($pdo, $secs[$sid], $w, $today); if (!$r[0]) json_response(['error' => $r[1]], 422); $txt = $r[1];
            json_response(['ok' => true, 'digest' => ['day' => $today, 'text' => $txt, 'at' => fup_now()]]);
        }
    }
    json_response(['error' => 'Unknown Follow-up action.'], 404);
}
