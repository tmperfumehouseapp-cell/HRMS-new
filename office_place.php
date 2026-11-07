<?php
/**
 * office_place.php — "Register a place" (Pantry first) for the Offices section. Loaded by office.php (which check.php loads for of. / dp. actions).
 *
 * A PLACE is a zone of an office (of_zones). The owner registers it step by step on a phone and can stop and continue later:
 *   1 name  2 cleaning photos (reference photo + "what must be visible: 2 handlers, 2 pressers, 1 beaker")  3 counting items with a MINIMUM  4 daily refills
 *   5 one-time refills  6 to fix now  7 to buy new  8 must-have equipment (Present / Missing / Broken)  9 owner + switch ON.   Only ONE cleaning photo is needed to switch ON.
 * Switching ON makes TWO kinds of duties in the Boss Engine, exactly like office.php's of_sync does for cleaning:
 *   "Clean: <place> · <office>"            one photo duty per cleaning photo (lib_key of:<zone>:p<point>), with the reference photo and the must-have list in the photo prompt
 *   "Count and refill: <place> · <office>"  ONE duty (lib_key ofc:<zone>), proof = the count form (served by of.pl_form, answered with of.pl_check_submit)
 * Below-minimum counts become needs on the existing shopping list (dp_su_needs, quantity = fill-to or minimum minus the count), equipment Missing becomes a need,
 * equipment Broken becomes a To-fix job (dp_fx_jobs). Nobody is fined: the duties carry penalty 0; three penalty RULES are only proposed (amount 0, off).
 * A place may have its own owner (+ ordered backups): a seat <section>.pl<zone> inside the office's Boss Engine section. No owner = the office cleaners, as today.
 * An office / supplies place without a Boss Engine section gets one (with its 3 seats) the first time a place is registered or switched on - never twice.
 * Tables: of_pl_places, of_pl_log, of_pl_checks. Columns added: of_points.ref_photo / must_json, dp_su_items.zone_id / pgroup / kind / min_qty / fill_to / refill / name_hi / last_count / last_count_at / equip_state.
 * Actions: of.pl_* (see /work/wa/P_api.md). PHP 7.4 safe. No network. The AI (Claude) is optional; every AI step has a rule-based fallback and only PROPOSES.
 */
if (!isset($pdo, $action) || !function_exists('json_response')) { return; }
if (defined('OFP_LOADED')) { return; }
if (!function_exists('bd_inbox') || !function_exists('of_ensure')) { return; }
define('OFP_LOADED', 1);

/* ============================== schema (idempotent, re-runnable) ============================== */
function ofp_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    $flag = sys_get_temp_dir() . '/hrms_ofp_v1_' . substr(md5(__DIR__), 0, 8);
    $probe = function () use ($pdo) { $pdo->query("SELECT 1 FROM of_pl_places LIMIT 1"); $pdo->query("SELECT 1 FROM of_pl_log LIMIT 1"); $pdo->query("SELECT 1 FROM of_pl_checks LIMIT 1"); $pdo->query("SELECT ref_photo, must_json FROM of_points LIMIT 1"); $pdo->query("SELECT zone_id, pgroup, kind, min_qty, fill_to, refill, name_hi, last_count, last_count_at, equip_state, pl_orig FROM dp_su_items LIMIT 1"); };
    $have = is_file($flag);
    if ($have) { try { $probe(); return; } catch (Throwable $e) { $have = false; } }   // fast path: one cheap check, no other table is touched on a normal request
    if (function_exists('of_ensure')) { of_ensure($pdo); }
    if (function_exists('dp_ensure')) { try { dp_ensure($pdo); } catch (Throwable $e) { error_log('ofp dp_ensure: ' . $e->getMessage()); } }
    if (function_exists('dp_su_ensure')) { try { dp_su_ensure($pdo); } catch (Throwable $e) { error_log('ofp dp_su_ensure: ' . $e->getMessage()); } }
    if (function_exists('dp_fx_ensure')) { try { dp_fx_ensure($pdo); } catch (Throwable $e) { error_log('ofp dp_fx_ensure: ' . $e->getMessage()); } }
    $E = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec("CREATE TABLE IF NOT EXISTS of_pl_places (
        zone_id INT NOT NULL PRIMARY KEY, office_id INT NOT NULL, state VARCHAR(6) NOT NULL DEFAULT 'draft', step TINYINT NOT NULL DEFAULT 1, steps_done VARCHAR(30) NOT NULL DEFAULT '',
        draft_json MEDIUMTEXT NULL, check_by CHAR(5) NOT NULL DEFAULT '12:00', photo_time CHAR(5) NOT NULL DEFAULT '11:00', owner_seat_id INT NULL,
        created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL, switched_on_at DATETIME NULL, INDEX (office_id, state), INDEX (state)
    )$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS of_pl_log (
        id INT AUTO_INCREMENT PRIMARY KEY, zone_id INT NOT NULL, item_id INT NOT NULL, day DATE NOT NULL, kind VARCHAR(6) NOT NULL, val DECIMAL(10,2) NULL, state VARCHAR(8) NULL,
        emp_id INT NULL, task_id INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL, UNIQUE KEY u_item_day (item_id, day), INDEX (zone_id, day)
    )$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS of_pl_checks (
        id INT AUTO_INCREMENT PRIMARY KEY, zone_id INT NOT NULL, day DATE NOT NULL, task_id INT NULL, emp_id INT NULL, status VARCHAR(8) NOT NULL DEFAULT 'partial',
        answered INT NOT NULL DEFAULT 0, required INT NOT NULL DEFAULT 0, summary_json TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL, UNIQUE KEY u_zone_day (zone_id, day)
    )$E");
    $addCol = function ($table, $col, $def) use ($pdo) { try { $q = $pdo->query("SHOW COLUMNS FROM $table LIKE '$col'"); if (!$q->fetch()) $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def"); } catch (Throwable $e) { error_log('ofp_ensure ' . $table . '.' . $col . ': ' . $e->getMessage()); } };
    foreach (['ref_photo' => 'VARCHAR(160) NULL', 'must_json' => 'TEXT NULL'] as $c => $d) $addCol('of_points', $c, $d);
    foreach (['zone_id' => 'INT NULL', 'pgroup' => 'VARCHAR(6) NULL', 'kind' => "VARCHAR(10) NOT NULL DEFAULT 'consumable'", 'min_qty' => 'DECIMAL(10,2) NULL', 'fill_to' => 'DECIMAL(10,2) NULL', 'refill' => "VARCHAR(6) NOT NULL DEFAULT 'none'",
        'name_hi' => 'VARCHAR(120) NULL', 'last_count' => 'DECIMAL(10,2) NULL', 'last_count_at' => 'DATETIME NULL', 'equip_state' => 'VARCHAR(8) NULL', 'pl_orig' => 'VARCHAR(200) NULL'] as $c => $d) $addCol('dp_su_items', $c, $d);
    try { $q = $pdo->query("SHOW INDEX FROM dp_su_items WHERE Key_name = 'ix_zone'"); if (!$q->fetch()) $pdo->exec("ALTER TABLE dp_su_items ADD INDEX ix_zone (zone_id)"); } catch (Throwable $e) { error_log('ofp_ensure index: ' . $e->getMessage()); }
    ofp_seed_rules($pdo);
    try { $probe(); @file_put_contents($flag, '1'); } catch (Throwable $e) { error_log('ofp_ensure: not ready yet (' . $e->getMessage() . ')'); }   // the flag is only written when everything is really there
}
/** three penalty RULES are only PROPOSED: amount 0 and OFF. Nothing uses them until the owner sets an amount and switches one on in Boss > Penalties. */
function ofp_seed_rules(PDO $pdo) {
    static $d = false; if ($d) return; $d = true;
    try {
        $rows = [['place_count_missed', 'Place count and refill not done', 'supplies', 'supplies.buyer', 'Check time of the place'], ['place_refill_missed', 'Daily refill not done', 'supplies', 'supplies.buyer', 'Check time of the place'], ['place_not_bought', 'Place supply not bought on its day', 'supplies', 'supplies.buyer', 'Next check']];
        $n = 100;
        foreach ($rows as $r) { $n++; $pdo->prepare("INSERT IGNORE INTO dp_rules (rkey, title, topic, amount, per, decide, seat_code, when_text, mode, grace_min, active, sort_no, updated_at) VALUES (?,?,?,0,'miss',1,?,?,'approve',0,0,?,?)")->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $n, bd_now()]); }
    } catch (Throwable $e) { /* dp_rules is not there: nothing to propose */ }
}
/** the amount of a PROPOSED rule, only when the owner switched it on (active) and set an amount. Default 0. */
function ofp_rule_amount(PDO $pdo, $rkey) {
    try { $st = $pdo->prepare("SELECT amount, active FROM dp_rules WHERE rkey = ?"); $st->execute([$rkey]); $r = $st->fetch(); return ($r && (int)$r['active'] === 1 && (float)$r['amount'] > 0) ? (float)$r['amount'] : 0.0; } catch (Throwable $e) { return 0.0; }
}

/** true once the place tables / columns exist (used by dept_work.php before it reads the new columns) */
function ofp_ready() { global $pdo; static $ok = null; if ($ok !== null) return $ok; try { ofp_ensure($pdo); $pdo->query("SELECT zone_id, name_hi FROM dp_su_items LIMIT 1"); $ok = true; } catch (Throwable $e) { $ok = false; } return $ok; }
/* ============================== small helpers ============================== */
function ofp_time($s, $d) { $s = trim((string)$s); return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s) ? $s : $d; }
function ofp_lang(PDO $pdo, $emp) { $v = $emp ? of_cfg('lang:' . (int)$emp, 'both') : 'both'; return in_array($v, ['both', 'en', 'hi'], true) ? $v : 'both'; }
function ofp_zone(PDO $pdo, $zid) { $st = $pdo->prepare("SELECT * FROM of_zones WHERE id = ?"); $st->execute([(int)$zid]); return $st->fetch() ?: null; }
function ofp_place(PDO $pdo, $zid) { $st = $pdo->prepare("SELECT * FROM of_pl_places WHERE zone_id = ?"); $st->execute([(int)$zid]); return $st->fetch() ?: null; }
function ofp_office(PDO $pdo, $id) { return function_exists('of_office') ? of_office($pdo, $id) : null; }
function ofp_is_admin(array $auth) { return in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true); }
function ofp_num($v) { return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); }
function ofp_trim($s, $n) { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$s)), 0, $n); }
function ofp_label_of($zone, $office) { return $zone['name'] . ' · ' . $office['name']; }
/** The Store's own cleaning places, ready to be created as DRAFTS (nothing goes live until the owner adds the reference photos and presses ON in the wizard).
 *  One place with 16 photo spots in walking order (outside to inside), and one closing place (21:00) with a photo and the stock count. Count minimums are examples: the owner edits them in step 3. */
function ofp_store_defs() {
    $pts = [
        ['Outside doormat: straight and clean, no litter', 'બહારની પગલૂછણી: સીધી અને સાફ, કચરો નથી'],
        ['Shoe stand outside: tidy, shoes in order', 'બૂટ-સ્ટેન્ડ: વ્યવસ્થિત'],
        ['Outside chairs and table: wiped, chairs straight', 'બહારની ચેર અને ટેબલ: લૂછેલાં, ચેર સીધી'],
        ['Front glass: spotless, no marks or fingerprints', 'ફ્રન્ટ ગ્લાસ: ડાઘ અને આંગળીના નિશાન નથી'],
        ['Cash counter: clear and clean', 'કેશ કાઉન્ટર: ખાલી અને સાફ'],
        ['Cash counter drawers: all closed', 'કેશ કાઉન્ટરના ડ્રોઅર: બધા બંધ'],
        ['Perfume shelf: bottles straight, no dust', 'પરફ્યુમ સેલ્ફ: બોટલ સીધી, ધૂળ નથી'],
        ['Middle table: wiped, items tidy', 'વચ્ચેનું ટેબલ: લૂછેલું, સામાન વ્યવસ્થિત'],
        ['Board above the bar: dust free and straight', 'બાર ઉપરનું બોર્ડ: ધૂળ વગરનું અને સીધું'],
        ['Coffee counter: wiped, nothing spilled', 'કોફી કાઉન્ટર: લૂછેલું, ઢોળાયેલું નથી'],
        ['Coffee machine: clean outside, drip tray empty', 'કોફી મશીન: બહારથી સાફ, ડ્રિપ ટ્રે ખાલી'],
        ['Basin: clean and empty', 'બેઝિન: સાફ અને ખાલી'],
        ['Cup stand: cups arranged and clean', 'કપ સ્ટેન્ડ: કપ ગોઠવેલા અને સાફ'],
        ['Coffee grinder: clean outside, no powder spilled', 'કોફી ગ્રાઇન્ડર: બહારથી સાફ, પાવડર ઢોળાયેલો નથી'],
        ['Ice machine: clean outside and inside', 'આઇસ મશીન: બહારથી અને અંદરથી સાફ'],
        ['Pantry drawers: all closed', 'પેન્ટ્રીના ડ્રોઅર: બધા બંધ'],
    ];
    return [
        ['name' => 'Shop cleaning, outside to inside', 'name_hi' => 'દુકાનની સફાઈ: બહારથી અંદર', 'photo_time' => '09:30', 'check_by' => '10:30', 'points' => $pts, 'counts' => []],
        ['name' => 'Closing: coffee bar and stock', 'name_hi' => 'બંધ કરતાં: કોફી બાર અને સ્ટોક', 'photo_time' => '21:00', 'check_by' => '21:30',
         'points' => [['Coffee bar at closing: counter wiped, machine, grinder and ice machine clean, basin empty, nothing left out', 'બંધ કરતાં કોફી બાર: કાઉન્ટર લૂછેલું, મશીન, ગ્રાઇન્ડર, આઇસ મશીન સાફ, બેઝિન ખાલી']],
         'counts' => [['name' => 'Coffee', 'name_hi' => 'કોફી', 'unit' => 'packet', 'min' => 3], ['name' => 'Milk', 'name_hi' => 'દૂધ', 'unit' => 'litre', 'min' => 6], ['name' => 'Tonic', 'name_hi' => 'ટોનિક', 'unit' => 'bottle', 'min' => 12]]],
    ];
}
function ofp_json($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

/* ============================== the must-have list: "2 handlers, 2 pressers, 1 beaker" ============================== */
function ofp_number_words() {
    return ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'ek' => 1, 'do' => 2, 'teen' => 3, 'char' => 4, 'chaar' => 4, 'paanch' => 5, 'panch' => 5, 'chhe' => 6, 'che' => 6, 'saat' => 7, 'aath' => 8, 'nau' => 9, 'das' => 10,
        'एक' => 1, 'दो' => 2, 'तीन' => 3, 'चार' => 4, 'पांच' => 5, 'पाँच' => 5, 'छह' => 6, 'छः' => 6, 'छे' => 6, 'सात' => 7, 'आठ' => 8, 'नौ' => 9, 'दस' => 10];
}
/** text -> [['name'=>'handlers','qty'=>2,'given'=>true], ...]. Commas, ; | & and new lines, Hindi digits and words, 2x / x2 / 2handlers, odd spacing. No number = qty 1, given false. */
function ofp_parse_must($text) {
    $t = (string)$text;
    $t = strtr($t, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9', '﹐' => ',', '，' => ',', '،' => ',', '、' => ',', '；' => ';', '।' => ',']);
    $parts = preg_split('/[,;|\r\n]+|\s+(?:and|aur)\s+|\s*&\s*|\s+और\s+/iu', $t, -1, PREG_SPLIT_NO_EMPTY);
    $words = ofp_number_words(); $out = []; $idx = [];
    foreach ($parts as $p) {
        $p = trim(preg_replace('/\s+/u', ' ', $p));
        $p = preg_replace('/^[\-\*\x{2022}\.]+\s*/u', '', $p);
        $p = preg_replace('/^\d{1,2}[\)\.]\s+/u', '', $p);
        $p = trim($p, " \t.:;-");
        if ($p === '') continue;
        $qty = null; $name = $p; $given = false;
        if (preg_match('/^(\d{1,4})\s*(?:[x×*]\s*)?(.+)$/iu', $p, $m) && trim($m[2]) !== '') { $qty = (int)$m[1]; $name = $m[2]; $given = true; }
        elseif (preg_match('/^(.+?)\s*(?:[x×*]|[-:–=])\s*(\d{1,4})$/iu', $p, $m)) { $qty = (int)$m[2]; $name = $m[1]; $given = true; }
        elseif (preg_match('/^([^\d]*[^\d\s])\s+(\d{1,4})$/u', $p, $m)) { $qty = (int)$m[2]; $name = $m[1]; $given = true; }   // "handlers 2"
        elseif (preg_match('/^(\S+)\s+(.+)$/u', $p, $m) && isset($words[mb_strtolower($m[1])])) { $qty = $words[mb_strtolower($m[1])]; $name = $m[2]; $given = true; }
        $name = trim(preg_replace('/^(?:x|×)\s+/iu', '', trim($name, " \t.:;-,")));
        $name = mb_substr($name, 0, 80);
        if ($name === '') continue;
        if ($qty === null) $qty = 1;
        if ($qty < 1) continue;
        $qty = min(999, $qty);
        $k = mb_strtolower($name);
        if (isset($idx[$k])) { $out[$idx[$k]]['qty'] = min(999, $out[$idx[$k]]['qty'] + $qty); $out[$idx[$k]]['given'] = $out[$idx[$k]]['given'] || $given; continue; }
        $idx[$k] = count($out); $out[] = ['name' => $name, 'qty' => $qty, 'given' => $given];
    }
    return array_slice($out, 0, 40);
}
function ofp_must_text(array $must) { $p = []; foreach ($must as $m) $p[] = (int)$m['qty'] . ' ' . $m['name']; return implode(', ', $p); }
function ofp_must_of($pt) { $m = json_decode((string)($pt['must_json'] ?? ''), true); return is_array($m) ? $m : []; }

/* ============================== Boss Engine section for an office / place (created once, never twice) ============================== */
/** Makes sure the office has a Boss Engine section with its 3 seats. Returns ['id'=>, 'created'=>bool, 'seats'=>[codes made], 'code'=>]. Safe to call any number of times. */
function ofp_section_ensure(PDO $pdo, array $office) {
    $lock = 1; try { $lock = (int)$pdo->query("SELECT GET_LOCK('ofp_section', 8)")->fetchColumn(); } catch (Throwable $e) {}
    $res = ['id' => 0, 'created' => false, 'seats' => [], 'code' => ''];
    try {
        $o = ofp_office($pdo, (int)$office['id']); if (!$o) { throw new Exception('office gone'); }
        $sid = (int)$o['section_id']; $row = null;
        if ($sid > 0) { $st = $pdo->prepare("SELECT * FROM bd_sections WHERE id = ?"); $st->execute([$sid]); $row = $st->fetch() ?: null; }
        if (!$row) {
            $base = 'of_' . bd_slug($o['name']); $code = $base; $n = 1;
            while (true) {
                $st = $pdo->prepare("SELECT s.id FROM bd_sections s WHERE s.code = ?"); $st->execute([$code]); $ex = (int)$st->fetchColumn();
                if (!$ex) break;
                $busy = $pdo->prepare("SELECT 1 FROM of_offices WHERE section_id = ? AND id != ?"); $busy->execute([$ex, (int)$o['id']]);
                if (!$busy->fetchColumn()) { $row = ['id' => $ex, 'code' => $code]; break; }   // an orphan section of the same name: take it over instead of a copy
                $code = $base . (++$n);
            }
            if (!$row) {
                $pdo->prepare("INSERT INTO bd_sections (code, name, about, pages, source, state, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$code, mb_substr($o['name'], 0, 120), 'Office cleaning and upkeep', '[]', 'office', 'ready', bd_now()]);
                $row = ['id' => (int)$pdo->lastInsertId(), 'code' => $code]; $res['created'] = true;
                bd_log('section', $row['id'], 'section made automatically for the place(s) of ' . $o['name'], 'Places');
            }
            $pdo->prepare("UPDATE of_offices SET section_id = ? WHERE id = ?")->execute([(int)$row['id'], (int)$o['id']]);
        }
        $res['id'] = (int)$row['id']; $res['code'] = (string)$row['code'];
        $have = []; $q = $pdo->prepare("SELECT kind FROM bd_seats WHERE section_id = ? AND active = 1"); $q->execute([$res['id']]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $k) $have[$k] = 1;
        if (empty($have['worker']) || empty($have['checker']) || empty($have['section_boss'])) {
            bd_make_seats($pdo, $res['id'], $res['code'], (string)($row['name'] ?? $o['name']));
            // a seat with the right code that belongs to a section that no longer exists is taken over (bd_make_seats cannot make a second one with the same code)
            foreach (['boss', 'checker', 'worker'] as $suf) { $pdo->prepare("UPDATE bd_seats SET section_id = ?, active = 1 WHERE code = ? AND (section_id IS NULL OR section_id NOT IN (SELECT id FROM bd_sections))")->execute([$res['id'], $res['code'] . '.' . $suf]); }
            $q->execute([$res['id']]); $after = []; foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $k) $after[$k] = 1;
            foreach (['section_boss' => 'boss', 'checker' => 'checker', 'worker' => 'worker'] as $kind => $suffix) if (empty($have[$kind]) && !empty($after[$kind])) $res['seats'][] = $res['code'] . '.' . $suffix;
        }
    } catch (Throwable $e) { error_log('ofp_section_ensure: ' . $e->getMessage()); }
    if ($lock) { try { $pdo->query("SELECT RELEASE_LOCK('ofp_section')"); } catch (Throwable $e) {} }
    return $res;
}
/** A Supplies place that is not an office in Offices (store, RJD Hub...) gets an office (and so a section) made for it, once. Returns the office row or null. */
function ofp_office_for_supply(PDO $pdo, $code, $by) {
    if (!function_exists('dp_su_place')) return null;
    $p = dp_su_place($pdo, (string)$code); if (!$p) return null;
    if (!empty($p['office_id'])) { $o = ofp_office($pdo, (int)$p['office_id']); if ($o) return $o; }
    $norm = function_exists('dp_su_norm') ? 'dp_su_norm' : function ($s) { return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s)); };
    foreach ($pdo->query("SELECT * FROM of_offices")->fetchAll() as $o) { if ($norm($o['name']) === $norm($p['name'])) { $pdo->prepare("UPDATE dp_su_places SET office_id = ? WHERE id = ?")->execute([(int)$o['id'], (int)$p['id']]); return $o; } }
    $lock = 1; try { $lock = (int)$pdo->query("SELECT GET_LOCK('ofp_office', 8)")->fetchColumn(); } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT office_id FROM dp_su_places WHERE id = ?"); $st->execute([(int)$p['id']]); $oid = (int)$st->fetchColumn();   // another request may have made it while we waited
        if ($oid && ($o = ofp_office($pdo, $oid))) return $o;
        $base = 'of_' . bd_slug($p['name']); $code2 = $base; $n = 1;
        while ($pdo->query("SELECT 1 FROM of_offices WHERE code = " . $pdo->quote($code2))->fetchColumn() || $pdo->query("SELECT 1 FROM bd_sections WHERE code = " . $pdo->quote($code2))->fetchColumn()) $code2 = $base . (++$n);
        $pdo->prepare("INSERT INTO of_offices (code, name, address, section_id, created_by, created_at) VALUES (?,?,?,?,?,?)")->execute([$code2, mb_substr($p['name'], 0, 120), null, 0, $by, bd_now()]);
        $oid = (int)$pdo->lastInsertId();
        $o = ofp_office($pdo, $oid); $GLOBALS['__ofp_sec_made'] = ofp_section_ensure($pdo, $o);
        $pdo->prepare("UPDATE dp_su_places SET office_id = ? WHERE id = ?")->execute([$oid, (int)$p['id']]);
        bd_log('office', $oid, 'office made from the supplies place ' . $p['name'], $by);
        return ofp_office($pdo, $oid);
    } finally { if ($lock) { try { $pdo->query("SELECT RELEASE_LOCK('ofp_office')"); } catch (Throwable $e) {} } }
}

/* ============================== the supplies place (dp_su_places) of an office ============================== */
function ofp_supply_place(PDO $pdo, array $office) {
    $st = $pdo->prepare("SELECT * FROM dp_su_places WHERE office_id = ? ORDER BY id LIMIT 1"); $st->execute([(int)$office['id']]); $p = $st->fetch();
    if ($p) return $p;
    if (function_exists('dp_su_sync_places')) { dp_su_sync_places($pdo); $st->execute([(int)$office['id']]); $p = $st->fetch(); if ($p) return $p; }
    $code = function_exists('dp_su_slug') ? dp_su_slug($office['name']) : 'place-' . (int)$office['id']; $n = 1; $base = $code;
    while ($pdo->query("SELECT 1 FROM dp_su_places WHERE code = " . $pdo->quote($code))->fetchColumn()) $code = $base . '-' . (++$n);
    $pdo->prepare("INSERT INTO dp_su_places (code, name, office_id, sort_no, created_at) VALUES (?,?,?,?,?)")->execute([$code, $office['name'], (int)$office['id'], 100 + (int)$office['id'], bd_now()]);
    $st->execute([(int)$office['id']]); return $st->fetch();
}

/* ============================== items ============================== */
function ofp_group(array $i) {
    if (!empty($i['pgroup'])) return (string)$i['pgroup'];
    if (($i['kind'] ?? '') === 'equipment') return 'equip';
    if (($i['refill'] ?? '') === 'daily') return 'daily';
    if (($i['refill'] ?? '') === 'once') return 'once';
    return isset($i['min_qty']) && $i['min_qty'] !== null ? 'count' : 'buy';
}
function ofp_item_view(array $i) {
    $legacy = ($i['source'] ?? '') !== 'place' && ($i['source'] ?? '') !== 'new';
    return ['id' => (int)$i['id'], 'name' => $i['name'], 'name_hi' => (string)($i['name_hi'] ?? ''), 'unit' => $i['unit'], 'group' => ofp_group($i), 'kind' => (string)($i['kind'] ?? 'consumable'), 'mode' => $i['mode'] === 'count' ? 'count' : ($i['mode'] === 'tap' ? 'tap' : $i['mode']),
        'min' => isset($i['min_qty']) && $i['min_qty'] !== null ? (float)$i['min_qty'] : null, 'fill_to' => isset($i['fill_to']) && $i['fill_to'] !== null ? (float)$i['fill_to'] : null, 'qty' => (float)$i['qty'], 'refill' => (string)($i['refill'] ?? 'none'),
        'status' => $i['status'], 'linked_legacy' => $legacy && $i['mode'] !== 'count', 'last_count' => isset($i['last_count']) && $i['last_count'] !== null ? (float)$i['last_count'] : null, 'last_count_at' => $i['last_count_at'] ?? null, 'equip_state' => $i['equip_state'] ?? null];
}
function ofp_zone_items(PDO $pdo, $zid) {
    $st = $pdo->prepare("SELECT * FROM dp_su_items WHERE zone_id = ? AND status = 'active' ORDER BY id"); $st->execute([(int)$zid]);
    $g = ['count' => [], 'daily' => [], 'once' => [], 'equip' => [], 'buy' => []];
    foreach ($st->fetchAll() as $i) $g[ofp_group($i)][] = $i;
    return $g;
}
/** numeric mode = counted against the minimum. Everything else (old items) = tap Ok / Low / Empty. */
function ofp_is_numeric(array $i) { return $i['mode'] === 'count' && isset($i['min_qty']) && $i['min_qty'] !== null; }
/** a group of the wizard (count | daily | once | equip | buy): the sent list is the FULL list of that group. Returns [linked[], error|null]. */
function ofp_items_save(PDO $pdo, array $supplyPlace, array $zone, $group, array $list, $by) {
    $linked = []; $keep = []; $converted = [];
    $now = bd_now();
    foreach ($list as $e) {
        if (!is_array($e)) continue;
        $name = ofp_trim($e['name'] ?? '', 100); if ($name === '') continue;
        $unit = ofp_trim($e['unit'] ?? '', 20) ?: 'pc'; $hi = ofp_trim($e['name_hi'] ?? '', 120);
        $min = (isset($e['min']) && $e['min'] !== '' && $e['min'] !== null) ? round((float)$e['min'], 2) : null; $fill = (isset($e['fill_to']) && $e['fill_to'] !== '' && $e['fill_to'] !== null) ? round((float)$e['fill_to'], 2) : null;
        $qty = (isset($e['qty']) && (float)$e['qty'] > 0) ? round((float)$e['qty'], 2) : 1;
        if ($min !== null && ($min < 0 || $min > 100000)) return [$linked, $name . ': the minimum must be 0 or more.'];
        if ($fill !== null && ($fill < 0 || $fill > 100000)) return [$linked, $name . ': the fill-to number is not sensible.'];
        if ($fill !== null && $min !== null && $fill < $min) $fill = $min;
        $wantMode = (string)($e['mode'] ?? '');
        if ($group === 'count' && $min === null && $wantMode !== 'tap') return [$linked, $name . ': give a minimum (or choose tap mode).'];
        $id = (int)($e['id'] ?? 0); $row = null;
        if ($id) { $st = $pdo->prepare("SELECT * FROM dp_su_items WHERE id = ? AND place_id = ?"); $st->execute([$id, (int)$supplyPlace['id']]); $row = $st->fetch() ?: null; }
        if (!$row) { $st = $pdo->prepare("SELECT * FROM dp_su_items WHERE place_id = ? AND name = ?"); $st->execute([(int)$supplyPlace['id'], $name]); $row = $st->fetch() ?: null; }
        if ($row && $row['zone_id'] !== null && (int)$row['zone_id'] !== (int)$zone['id'] && (int)$row['zone_id'] !== 0) return [$linked, '"' . $name . '" already belongs to another place of this office. Use another name.'];
        $kind = $group === 'equip' ? 'equipment' : 'consumable';
        $refill = $group === 'daily' ? 'daily' : ($group === 'once' ? 'once' : 'none');
        if ($row && (int)$row['zone_id'] !== (int)$zone['id'] && !in_array($row['source'], ['place', 'new'], true)) {   // an OLD item of this office
            // a never-used placeholder (seeded default, no checks / purchases / needs) is simply adopted; an item in use is only LINKED and keeps how it behaves unless "mode":"count" is sent
            $fresh = ofp_item_untouched($pdo, $row); $mode = $row['mode'];
            if ($fresh) $mode = ($group === 'once' && ($min === null || $wantMode === 'tap')) ? 'learn' : (($group === 'count' && $wantMode === 'tap') ? 'learn' : 'count');
            elseif ($row['mode'] !== 'count' && $wantMode === 'count' && in_array($group, ['count', 'once'], true) && $min !== null) { $mode = 'count'; $converted[] = $row['name']; }
            if (!$fresh && $mode !== 'count') $linked[] = ['name' => $row['name'], 'mode' => $row['mode']];
            $isNum = $mode === 'count' && in_array($group, ['count', 'once'], true) && $min !== null;
            if ($mode !== $row['mode'] || $fresh) $pdo->prepare("UPDATE dp_su_items SET pl_orig = ? WHERE id = ? AND pl_orig IS NULL")->execute([ofp_json(['mode' => $row['mode'], 'unit' => $row['unit'], 'qty' => (float)$row['qty'], 'cycle_days' => $row['cycle_days'] ? (int)$row['cycle_days'] : null]), (int)$row['id']]);   // so an unlink can give the item back as it was
            $pdo->prepare("UPDATE dp_su_items SET zone_id = ?, pgroup = ?, kind = ?, refill = ?, name_hi = COALESCE(NULLIF(?, ''), name_hi), mode = ?, min_qty = ?, fill_to = ?" . ($fresh ? ", unit = ?, qty = ?" : "") . " WHERE id = ?")
                ->execute(array_merge([(int)$zone['id'], $group, $kind, $refill, $hi, $mode, $isNum ? $min : null, $isNum ? $fill : null], $fresh ? [$unit, $qty] : [], [(int)$row['id']]));
            $iid = (int)$row['id'];
        } elseif ($row) {   // already this place's own item
            $mode = $row['mode'];
            if ($wantMode === 'count' && $min !== null && in_array($group, ['count', 'once'], true)) { if ($mode !== 'count') $converted[] = $row['name']; $mode = 'count'; }
            elseif ($wantMode === 'tap' && $row['source'] === 'place') $mode = 'learn';
            if ($group === 'daily' || $group === 'equip' || $group === 'buy') { if ($row['source'] === 'place' || $row['source'] === 'new') $mode = 'count'; }
            $isNum = $mode === 'count';
            $pdo->prepare("UPDATE dp_su_items SET zone_id = ?, pgroup = ?, kind = ?, refill = ?, unit = ?, name_hi = ?, qty = ?, mode = ?, min_qty = ?, fill_to = ?, status = 'active' WHERE id = ?")
                ->execute([(int)$zone['id'], $group, $kind, $refill, $unit, $hi !== '' ? $hi : null, $qty, $mode, ($isNum && in_array($group, ['count', 'once'], true)) ? $min : null, ($isNum && in_array($group, ['count', 'once'], true)) ? $fill : null, (int)$row['id']]);
            $iid = (int)$row['id'];
        } else {
            $numeric = ($group === 'count' || $group === 'once') ? ($min !== null && $wantMode !== 'tap') : true;
            $mode = $numeric ? 'count' : 'learn';
            $pdo->prepare("INSERT INTO dp_su_items (place_id, name, unit, mode, qty, status, source, created_at, zone_id, pgroup, kind, min_qty, fill_to, refill, name_hi) VALUES (?,?,?,?,?, 'active', ?,?,?,?,?,?,?,?,?)")
                ->execute([(int)$supplyPlace['id'], $name, $unit, $mode, $qty, $group === 'buy' ? 'new' : 'place', $now, (int)$zone['id'], $group, $kind, ($numeric && in_array($group, ['count', 'once'], true)) ? $min : null, ($numeric && in_array($group, ['count', 'once'], true)) ? $fill : null, $refill, $hi !== '' ? $hi : null]);
            $iid = (int)$pdo->lastInsertId();
        }
        $keep[$iid] = 1;
    }
    // items of this group that are not in the list any more: an old (linked) item is only unlinked; our own item is switched off
    $st = $pdo->prepare("SELECT * FROM dp_su_items WHERE zone_id = ? AND status = 'active'"); $st->execute([(int)$zone['id']]);
    foreach ($st->fetchAll() as $i) {
        if (isset($keep[(int)$i['id']]) || ofp_group($i) !== $group) continue;
        ofp_unlink_item($pdo, $i);
    }
    $GLOBALS['__ofp_converted'] = array_merge($GLOBALS['__ofp_converted'] ?? [], $converted);
    return [$linked, null];
}
/** a place lets go of an item: our own item is switched off, an old item gets back what it was (mode, unit, quantity) and loses the place fields */
function ofp_unlink_item(PDO $pdo, array $i) {
    if ($i['source'] === 'place' || $i['source'] === 'new') { $pdo->prepare("UPDATE dp_su_items SET status = 'off', zone_id = NULL WHERE id = ?")->execute([(int)$i['id']]); if (function_exists('dp_su_close_needs')) dp_su_close_needs($pdo, (int)$i['id'], 'cancelled'); return; }
    $o = json_decode((string)($i['pl_orig'] ?? ''), true); $mode = is_array($o) && !empty($o['mode']) ? $o['mode'] : $i['mode'];
    if ($mode === 'count') $mode = 'learn';
    $pdo->prepare("UPDATE dp_su_items SET zone_id = NULL, pgroup = NULL, kind = 'consumable', refill = 'none', min_qty = NULL, fill_to = NULL, equip_state = NULL, mode = ?, unit = ?, qty = ?, cycle_days = ?, pl_orig = NULL WHERE id = ?")
        ->execute([$mode, is_array($o) && isset($o['unit']) ? $o['unit'] : $i['unit'], is_array($o) && isset($o['qty']) ? $o['qty'] : $i['qty'], is_array($o) && array_key_exists('cycle_days', $o) ? $o['cycle_days'] : $i['cycle_days'], (int)$i['id']]);
}
/** a seeded placeholder nobody ever used: no checks, purchases, needs or purchase date */
function ofp_item_untouched(PDO $pdo, array $row) {
    if (($row['source'] ?? '') !== 'default' || !empty($row['last_bought'])) return false;
    foreach (['SELECT 1 FROM dp_su_checks WHERE item_id = ? LIMIT 1', 'SELECT 1 FROM dp_su_bought_items WHERE item_id = ? LIMIT 1', 'SELECT 1 FROM dp_su_needs WHERE item_id = ? LIMIT 1'] as $q) { $st = $pdo->prepare($q); $st->execute([(int)$row['id']]); if ($st->fetchColumn()) return false; }
    return true;
}

/* ============================== needs (the existing shopping list) ============================== */
function ofp_rank($r) { return ['empty' => 4, 'low' => 3, 'min' => 3, 'missing' => 3, 'cycle' => 2, 'new' => 2, 'daily' => 1][$r] ?? 0; }
/** one open need per item. Same reason again (a resubmit) = the quantity is updated, never a second row. */
function ofp_need_upsert(PDO $pdo, array $item, $reason, $qty, $now) {
    $qty = max(0.01, round((float)$qty, 2));
    $st = $pdo->prepare("SELECT * FROM dp_su_needs WHERE item_id = ? AND status = 'open' ORDER BY id LIMIT 1"); $st->execute([(int)$item['id']]); $n = $st->fetch();
    if ($n) {
        if ($n['reason'] === $reason) { $pdo->prepare("UPDATE dp_su_needs SET qty = ? WHERE id = ?")->execute([$qty, (int)$n['id']]); return (int)$n['id']; }
        if (ofp_rank($reason) > ofp_rank($n['reason'])) { $pdo->prepare("UPDATE IGNORE dp_su_needs SET reason = ?, qty = GREATEST(qty, ?), needed_by = LEAST(needed_by, ?) WHERE id = ?")->execute([$reason, $qty, function_exists('dp_su_due') ? dp_su_due($reason, $now) : bd_now(), (int)$n['id']]); }
        else $pdo->prepare("UPDATE dp_su_needs SET qty = GREATEST(qty, ?) WHERE id = ?")->execute([$qty, (int)$n['id']]);
        return (int)$n['id'];
    }
    $due = function_exists('dp_su_due') ? dp_su_due($reason, $now) : date('Y-m-d H:i:s', $now + 86400); $day = date('Y-m-d', $now);
    $ins = $pdo->prepare("INSERT IGNORE INTO dp_su_needs (item_id, place_id, need_date, reason, qty, needed_by, created_at) VALUES (?,?,?,?,?,?,?)");
    $ins->execute([(int)$item['id'], (int)$item['place_id'], $day, $reason, $qty, $due, bd_now()]); $id = (int)$pdo->lastInsertId();
    if (!$id) {   // the same item, day and reason was closed earlier today: open it again
        $pdo->prepare("UPDATE dp_su_needs SET status = 'open', qty = ?, needed_by = ?, penalised = 0, dp_item_id = NULL, bought_id = NULL, closed_at = NULL, assigned_emp = NULL, assigned_due = NULL WHERE item_id = ? AND need_date = ? AND reason = ?")->execute([$qty, $due, (int)$item['id'], $day, $reason]);
        $q = $pdo->prepare("SELECT id FROM dp_su_needs WHERE item_id = ? AND need_date = ? AND reason = ?"); $q->execute([(int)$item['id'], $day, $reason]); $id = (int)$q->fetchColumn();
    }
    return $id;
}
function ofp_need_cancel(PDO $pdo, $itemId, array $reasons) {
    if (!$reasons) return 0; $in = implode(',', array_map(function ($r) use ($pdo) { return $pdo->quote($r); }, $reasons));
    $st = $pdo->prepare("UPDATE dp_su_needs SET status = 'cancelled', closed_at = ? WHERE item_id = ? AND status = 'open' AND reason IN ($in)"); $st->execute([bd_now(), (int)$itemId]); return $st->rowCount();
}
/** quantity to buy when the count is below the minimum: (fill-to or minimum) - count */
function ofp_need_qty(array $item, $count) {
    $min = (float)$item['min_qty']; $fill = isset($item['fill_to']) && $item['fill_to'] !== null && (float)$item['fill_to'] > $min ? (float)$item['fill_to'] : $min;
    return round($fill - (float)$count, 2);
}

/* ============================== To fix jobs ============================== */
function ofp_fix_create(PDO $pdo, array $office, array $zone, $title, $detail, $photo, $srcKey, $emp, $by) {
    if (!function_exists('dp_fx_ensure')) return 0;
    dp_fx_ensure($pdo);
    $st = $pdo->prepare("SELECT id FROM dp_fx_jobs WHERE src_key = ?"); $st->execute([$srcKey]); $ex = (int)$st->fetchColumn(); if ($ex) return $ex;
    $pdo->prepare("INSERT INTO dp_fx_jobs (office_id, zone_id, title, detail, source, src_key, reported_by_emp, reported_by, before_photo, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([(int)$office['id'], (int)$zone['id'], mb_substr($title, 0, 200), $detail !== '' ? mb_substr($detail, 0, 400) : null, 'report', mb_substr($srcKey, 0, 40), $emp ?: null, $emp ? bd_emp_name($pdo, $emp) : $by, $photo, 'reported', bd_now()]);
    $id = (int)$pdo->lastInsertId();
    bd_log('repairs', 'fx' . $id, 'problem reported (place): ' . $title . ' (' . $zone['name'] . ' · ' . $office['name'] . ')', $by);
    return $id;
}

/* ============================== owner seat of a place ============================== */
function ofp_place_seat_id(PDO $pdo, array $pl) { return (int)($pl['owner_seat_id'] ?? 0); }
function ofp_owner_ids(PDO $pdo, array $pl) { $s = ofp_place_seat_id($pdo, $pl); return $s ? bd_holders($pdo, $s) : []; }
function ofp_section_row(PDO $pdo, $sid) { $st = $pdo->prepare("SELECT * FROM bd_sections WHERE id = ?"); $st->execute([(int)$sid]); return $st->fetch() ?: null; }
/** the dedicated seat of the place inside the office's section (made on first use). Kind 'worker' with its own code, like the Supplies buyer seats: the office's own cleaner seat stays the section's worker seat. */
function ofp_seat_ensure(PDO $pdo, array $office, array $zone, array $pl) {
    $sec = ofp_section_ensure($pdo, $office); if (!$sec['id']) return 0;
    $seatId = ofp_place_seat_id($pdo, $pl);
    if ($seatId && bd_seat_row($pdo, $seatId)) return $seatId;
    $code = $sec['code'] . '.pl' . (int)$zone['id'];
    $id = bd_seat_id($pdo, $code);
    if (!$id) { $pdo->prepare("INSERT INTO bd_seats (section_id, code, title, kind, created_at) VALUES (?,?,?,?,?)")->execute([(int)$sec['id'], $code, mb_substr('Place owner: ' . $zone['name'] . ' · ' . $office['name'], 0, 120), 'worker', bd_now()]); $id = (int)$pdo->lastInsertId(); }
    else $pdo->prepare("UPDATE bd_seats SET active = 1 WHERE id = ?")->execute([$id]);
    $pdo->prepare("UPDATE of_pl_places SET owner_seat_id = ? WHERE zone_id = ?")->execute([$id, (int)$zone['id']]);
    return $id;
}
function ofp_owner_view(PDO $pdo, array $office, array $pl) {
    $ids = ofp_owner_ids($pdo, $pl); $cleaners = [];
    $sid = (int)$office['section_id']; $ws = $sid ? bd_section_seat($pdo, $sid, 'worker') : 0;
    $ch = $ws ? bd_holders($pdo, $ws) : []; foreach ($ch as $e) $cleaners[] = bd_emp_name($pdo, $e);
    $seat = ofp_place_seat_id($pdo, $pl); $sr = $seat ? bd_seat_row($pdo, $seat) : null;
    return ['seat_id' => $seat ?: null, 'code' => $sr ? $sr['code'] : null, 'emp_ids' => $ids, 'names' => array_map(function ($e) use ($pdo) { return bd_emp_name($pdo, $e); }, $ids), 'using' => $ids ? 'place' : ($ch ? 'office' : 'none'), 'office_cleaners' => $cleaners];
}
/** put people on the place (first = owner, rest = ordered backups). [] = no place owner: the duties go back to the office cleaners. */
function ofp_set_owner(PDO $pdo, array $office, array $zone, array $emps, $by) {
    $pl = ofp_place($pdo, $zone['id']); if (!$pl) return [false, 'This place is not registered.'];
    $emps = array_values(array_unique(array_filter(array_map('intval', $emps))));
    foreach ($emps as $e) { if (!bd_emp_active(bd_emp($pdo, $e))) return [false, 'One of those people does not work here now.']; }
    if ($emps) { $seat = ofp_seat_ensure($pdo, $office, $zone, $pl); if (!$seat) return [false, 'The office has no Boss Engine section.']; bd_set_holders($pdo, $seat, $emps); bd_log('seat', $seat, 'place owner of ' . $zone['name'] . ': ' . implode(',', $emps), $by); }
    else { $seat = ofp_place_seat_id($pdo, $pl); if ($seat) { bd_set_holders($pdo, $seat, []); bd_log('seat', $seat, 'place owner of ' . $zone['name'] . ' removed', $by); } }
    $pl = ofp_place($pdo, $zone['id']);
    $moved = ofp_sync_zone($pdo, $office, ofp_zone($pdo, $zone['id']), $pl, $by, true);
    if ($emps && function_exists('bd_push')) foreach ($emps as $e) bd_push($pdo, $e, 'You now do the daily check', $zone['name'] . ' · ' . $office['name'] . '. It is in My Day.');
    ofp_cq_forget($pdo, $zone['id']);
    return [true, $emps ? bd_emp_name($pdo, $emps[0]) . ' does the daily check for ' . $zone['name'] . ' · ' . $office['name'] . (count($emps) > 1 ? ', then ' . implode(', ', array_map(function ($e) use ($pdo) { return bd_emp_name($pdo, $e); }, array_slice($emps, 1))) . ' as backup' : '') . '.' : 'No place owner. The office cleaners do ' . $zone['name'] . ' again.'];
}
/** Claude's suggestion for who should own the place: here today, few tasks, in the office's team. Never assigns. */
function ofp_suggest_owners(PDO $pdo, array $office, $limit = 3) {
    $emps = bd_employees($pdo); $today = bd_today();
    $load = []; foreach ($pdo->query("SELECT owner_emp, COUNT(*) c FROM bd_tasks WHERE task_date = '" . $today . "' AND status IN ('open','done') AND owner_emp IS NOT NULL GROUP BY owner_emp")->fetchAll() as $r) $load[(int)$r['owner_emp']] = (int)$r['c'];
    $present = []; try { foreach ($pdo->query("SELECT employee_id FROM attendance WHERE punch_date = '" . $today . "' AND punch_in IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $e) $present[(int)$e] = 1; } catch (Throwable $e) {}
    $team = []; $sid = (int)$office['section_id'];
    if ($sid) { foreach (['worker', 'checker'] as $k) { $seat = bd_section_seat($pdo, $sid, $k); if ($seat) foreach (bd_holders($pdo, $seat) as $e) $team[(int)$e] = 1; } }
    $skip = []; foreach ($emps as $e) { if (preg_match('/owner|director|chairman|\bceo\b/i', (string)($e['designation'] ?? ''))) $skip[(int)$e['id']] = 1; }
    $c = [];
    foreach ($emps as $e) {
        $id = (int)$e['id']; if (isset($skip[$id])) continue;
        $score = (isset($present[$id]) ? 10 : 0) - 1.5 * ($load[$id] ?? 0) + (isset($team[$id]) ? 4 : 0);
        $c[] = ['id' => $id, 'name' => $e['name'], 'score' => $score, 'load' => $load[$id] ?? 0, 'present' => isset($present[$id]), 'team' => isset($team[$id])];
    }
    usort($c, function ($a, $b) { return $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']); });
    $out = [];
    foreach ($c as $x) {
        if (count($out) >= $limit) break;
        if (bd_away($pdo, $x['id'], $today) !== '') continue;
        $why = ($x['present'] ? 'here today' : 'not punched in yet') . ' · ' . ($x['load'] === 0 ? 'no task so far' : $x['load'] . ' task' . ($x['load'] === 1 ? '' : 's') . ' so far') . ($x['team'] ? ' · in this office\'s team' : '');
        $out[] = ['id' => $x['id'], 'name' => $x['name'], 'why' => $why];
    }
    return $out;
}

/* ============================== Boss Engine duties (the of_sync way) ============================== */
function ofp_photo_prompt(array $zone, array $pt) {
    $must = ofp_must_of($pt); $label = trim((string)$pt['text']);
    $p = [['text' => $label !== '' ? $label : $zone['name'], 'must' => $must]];
    return function_exists('of_prompt') ? of_prompt($zone, $p) : mb_substr('Zone: ' . $zone['name'] . '. The photo must clearly show: ' . $label . '.', 0, 480);
}
function ofp_has_items(PDO $pdo, $zid) { $st = $pdo->prepare("SELECT COUNT(*) FROM dp_su_items WHERE zone_id = ? AND status = 'active' AND pgroup IN ('count','daily','once','equip')"); $st->execute([(int)$zid]); return (int)$st->fetchColumn() > 0; }
/**
 * Make the Boss Engine duties match this place: one photo duty per cleaning photo + ONE count/refill duty. Paused when the place is off, retired when it is gone.
 * $repoint = also move today's open tasks to the (new) owner. Returns the number of live duties.
 */
function ofp_sync_zone(PDO $pdo, array $o, $zone, array $pl, $by = 'Places', $repoint = false) {
    if (!$zone) return 0;
    $o = ofp_office($pdo, (int)$o['id']) ?: $o;
    $sid = (int)$o['section_id']; $on = $pl['state'] === 'on' && $zone['status'] === 'approved';
    $worker = bd_section_seat($pdo, $sid, 'worker'); $checker = bd_section_seat($pdo, $sid, 'checker');
    $placeSeat = ofp_place_seat_id($pdo, $pl); $seat = ($placeSeat && bd_holders($pdo, $placeSeat)) ? $placeSeat : $worker;
    $zid = (int)$zone['id']; $live = 0; $dutyIds = [];
    $have = $pdo->prepare("SELECT id, lib_key FROM bd_duties WHERE (lib_key LIKE ? OR lib_key = ? OR lib_key LIKE ?) AND status != 'retired'"); $have->execute(['of:' . $zid . ':%', 'ofc:' . $zid, 'ofp:' . $zid . ':%']);
    $byKey = []; foreach ($have->fetchAll() as $h) $byKey[$h['lib_key']] = (int)$h['id'];
    $pts = $pdo->prepare("SELECT * FROM of_points WHERE zone_id = ? ORDER BY sort_no, id"); $pts->execute([$zid]); $pts = $pts->fetchAll();
    $time = ofp_time($pl['photo_time'], '11:00'); $multi = count($pts) > 1; $want = [];
    foreach ($pts as $pt) {
        $key = 'of:' . $zid . ':p' . (int)$pt['id']; $want[$key] = 1;
        $title = 'Clean: ' . $zone['name'] . ' · ' . $o['name'] . ($multi ? ' — ' . mb_substr((string)$pt['text'], 0, 40) : '');
        $c = bd_clean_duty(['title' => $title, 'why' => 'Place cleaning: ' . $zone['name'] . ' · ' . $o['name'], 'freq' => 'daily', 'due_time' => $time, 'proof' => 'photo', 'photo_prompt' => ofp_photo_prompt($zone, $pt), 'pen_amount' => 0]);
        $did = bd_save_duty($pdo, $sid, $seat, $c, 'office', 'live', $by, $byKey[$key] ?? 0);
        $pdo->prepare("UPDATE bd_duties SET lib_key = ?, status = ?, checker_seat_id = ?, grace_min = 30, ref_photo = ?, adapter = NULL WHERE id = ?")->execute([$key, $on ? 'live' : 'paused', $checker ?: null, $pt['ref_photo'] ?: null, $did]);
        $dutyIds[] = $did; if ($on) $live++;
    }
    // the count and refill duty: only when there is something to count
    $ckey = 'ofc:' . $zid; $wantCount = ofp_has_items($pdo, $zid); $want[$ckey] = 1;
    $c = bd_clean_duty(['title' => 'Count and refill: ' . $zone['name'] . ' · ' . $o['name'], 'why' => 'Daily count, refills and equipment of ' . $zone['name'] . ' · ' . $o['name'], 'freq' => 'daily', 'due_time' => ofp_time($pl['check_by'], '12:00'), 'proof' => 'auto', 'pen_amount' => ofp_rule_amount($pdo, 'place_count_missed')]);
    if (isset($byKey[$ckey]) || $wantCount) {
        $did = bd_save_duty($pdo, $sid, $seat, $c, 'office', 'live', $by, $byKey[$ckey] ?? 0);
        $pdo->prepare("UPDATE bd_duties SET lib_key = ?, status = ?, checker_seat_id = ?, grace_min = 30, adapter = 'place_check', ref_photo = NULL, photo_prompt = NULL, pen_remark = ? WHERE id = ?")->execute([$ckey, ($on && $wantCount) ? 'live' : 'paused', $checker ?: null, ofp_rule_amount($pdo, 'place_count_missed') > 0 ? 'Daily count and refill not done.' : null, $did]);
        $dutyIds[] = $did; if ($on && $wantCount) $live++;
    }
    // old-style duties of this zone (of:<zone>:<n>) are replaced by the photo duties; photo duties of removed photos are retired
    foreach ($byKey as $k => $did) {
        if (isset($want[$k])) continue;
        if (preg_match('/^of:\d+:p\d+$/', $k)) $pdo->prepare("UPDATE bd_duties SET status = 'retired' WHERE id = ?")->execute([$did]);
        elseif (preg_match('/^of:\d+:\d+$/', $k)) $pdo->prepare("UPDATE bd_duties SET status = 'paused' WHERE id = ?")->execute([$did]);
    }
    if ($repoint && $dutyIds) ofp_repoint_today($pdo, $dutyIds);
    return $live;
}
/** today's open, untouched tasks of these duties go to the person the duty resolves to now (owner changed). */
function ofp_repoint_today(PDO $pdo, array $dutyIds) {
    $today = bd_today(); $n = 0;
    foreach ($dutyIds as $did) {
        $d = $pdo->prepare("SELECT * FROM bd_duties WHERE id = ?"); $d->execute([(int)$did]); $d = $d->fetch(); if (!$d) continue;
        $t = $pdo->prepare("SELECT * FROM bd_tasks WHERE duty_id = ? AND task_date = ? AND status IN ('open','unowned') AND proof_path IS NULL"); $t->execute([(int)$did, $today]);
        foreach ($t->fetchAll() as $task) {
            $r = bd_resolve($pdo, $d, $today); $hit = $r ? $r[0] : null; if (!$hit) continue;
            $new = (int)$hit[1]; if ($new === (int)$task['owner_emp']) continue;
            $pdo->prepare("UPDATE bd_tasks SET owner_emp = ?, orig_emp = ?, cover_why = ?, status = ?, reminded = 0 WHERE id = ?")->execute([$new ?: null, $task['owner_emp'] ?: $task['orig_emp'], mb_substr((string)$hit[3], 0, 120) ?: null, $new ? 'open' : 'unowned', (int)$task['id']]); $n++;
        }
    }
    return $n;
}
/** office.php's of_sync asks: is this zone run by the place wizard (switched on or off before)? Then of_sync leaves it alone and calls ofp_sync_zone through us. */
function ofp_zone_handled(PDO $pdo, $zid) { $pl = ofp_place($pdo, $zid); return $pl && in_array($pl['state'], ['on', 'off'], true); }
function ofp_of_sync_zone(PDO $pdo, array $o, array $z, $by) { $pl = ofp_place($pdo, $z['id']); if (!$pl) return 0; return ofp_sync_zone($pdo, $o, $z, $pl, $by); }

/* ============================== views ============================== */
function ofp_counts(PDO $pdo, $zid) {
    $g = ofp_zone_items($pdo, $zid);
    $p = $pdo->prepare("SELECT COUNT(*) FROM of_points WHERE zone_id = ?"); $p->execute([(int)$zid]);
    $f = 0; try { $q = $pdo->prepare("SELECT COUNT(*) FROM dp_fx_jobs WHERE zone_id = ? AND src_key LIKE ? AND status IN ('reported','given','doing')"); $q->execute([(int)$zid, 'plfix:' . (int)$zid . ':%']); $f = (int)$q->fetchColumn(); } catch (Throwable $e) {}
    $b = 0; try { $q = $pdo->prepare("SELECT COUNT(*) FROM dp_su_needs n JOIN dp_su_items i ON i.id = n.item_id WHERE i.zone_id = ? AND n.reason = 'new' AND n.status = 'open'"); $q->execute([(int)$zid]); $b = (int)$q->fetchColumn(); } catch (Throwable $e) {}
    return ['photos' => (int)$p->fetchColumn(), 'counting' => count($g['count']), 'daily' => count($g['daily']), 'once' => count($g['once']), 'equipment' => count($g['equip']), 'fix' => $f, 'buy' => $b];
}
function ofp_today_block(PDO $pdo, array $o, array $zone, array $pl) {
    $day = bd_today(); $zid = (int)$zone['id'];
    $st = $pdo->prepare("SELECT t.id, t.status, t.owner_emp, t.due_at, d.lib_key, d.title FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE t.task_date = ? AND t.status != 'excused' AND (d.lib_key LIKE ? OR d.lib_key = ?)");
    $st->execute([$day, 'of:' . $zid . ':p%', 'ofc:' . $zid]);
    $photos = []; $check = null; $lab = []; $pq = $pdo->prepare("SELECT id, text FROM of_points WHERE zone_id = ?"); $pq->execute([$zid]); foreach ($pq->fetchAll() as $pt) $lab[(int)$pt['id']] = (string)$pt['text'];
    foreach ($st->fetchAll() as $t) {
        if ($t['lib_key'] === 'ofc:' . $zid) $check = ['task_id' => (int)$t['id'], 'status' => $t['status'], 'owner_id' => $t['owner_emp'] ? (int)$t['owner_emp'] : null, 'owner' => $t['owner_emp'] ? bd_emp_name($pdo, $t['owner_emp']) : '', 'due_at' => $t['due_at']];
        else $photos[] = ['task_id' => (int)$t['id'], 'status' => $t['status'], 'label' => $lab[(int)substr($t['lib_key'], strrpos($t['lib_key'], ':p') + 2)] ?? $t['title'], 'owner' => $t['owner_emp'] ? bd_emp_name($pdo, $t['owner_emp']) : ''];
    }
    $ck = $pdo->prepare("SELECT * FROM of_pl_checks WHERE zone_id = ? AND day = ?"); $ck->execute([$zid, $day]); $ck = $ck->fetch();
    $below = []; $bad = [];
    $rows = $pdo->prepare("SELECT l.item_id, l.val, l.state, l.kind, i.name, i.min_qty FROM of_pl_log l JOIN dp_su_items i ON i.id = l.item_id WHERE l.zone_id = ? AND l.day = ?"); $rows->execute([$zid, $day]);
    foreach ($rows->fetchAll() as $r) {
        if ($r['kind'] === 'count' && $r['min_qty'] !== null && (float)$r['val'] < (float)$r['min_qty']) $below[] = ['item_id' => (int)$r['item_id'], 'name' => $r['name'], 'count' => (float)$r['val'], 'min' => (float)$r['min_qty']];
        if ($r['kind'] === 'equip' && in_array($r['state'], ['missing', 'broken'], true)) $bad[] = ['item_id' => (int)$r['item_id'], 'name' => $r['name'], 'state' => $r['state']];
    }
    $nq = $pdo->prepare("SELECT COUNT(*) FROM dp_su_needs n JOIN dp_su_items i ON i.id = n.item_id WHERE i.zone_id = ? AND n.status = 'open'"); $nq->execute([$zid]);
    return ['day' => $day, 'photo_tasks' => $photos, 'check_task' => $check, 'submitted' => $ck && $ck['status'] === 'done', 'partial' => $ck && $ck['status'] === 'partial', 'below_min' => $below, 'equipment_bad' => $bad, 'open_needs' => (int)$nq->fetchColumn()];
}
function ofp_place_view(PDO $pdo, array $o, array $zone, array $pl, $withToday = true) {
    $done = array_values(array_filter(explode(',', (string)$pl['steps_done']), 'strlen'));
    $owner = ofp_owner_view($pdo, $o, $pl); $c = ofp_counts($pdo, $zone['id']); $warn = [];
    if ($pl['state'] === 'on' && $owner['using'] === 'none') $warn[] = 'Nobody does the daily check yet.';
    if ($pl['state'] === 'on' && !$c['photos']) $warn[] = 'No cleaning photo.';
    $v = ['zone_id' => (int)$zone['id'], 'office_id' => (int)$o['id'], 'office' => $o['name'], 'name' => $zone['name'], 'name_hi' => (string)($zone['name_hi'] ?? ''), 'state' => $pl['state'], 'step' => (int)$pl['step'], 'steps_done' => array_map('intval', $done),
        'check_by' => $pl['check_by'], 'photo_time' => $pl['photo_time'], 'switched_on_at' => $pl['switched_on_at'], 'owner' => $owner, 'counts' => $c, 'has_owner' => $owner['using'] !== 'none', 'warnings' => $warn];
    if ($withToday && $pl['state'] === 'on') $v['today'] = ofp_today_block($pdo, $o, $zone, $pl);
    return $v;
}
function ofp_photos_view(PDO $pdo, array $zone) {
    $st = $pdo->prepare("SELECT * FROM of_points WHERE zone_id = ? ORDER BY sort_no, id"); $st->execute([(int)$zone['id']]); $out = [];
    $d = $pdo->prepare("SELECT id, lib_key FROM bd_duties WHERE lib_key LIKE ? AND status != 'retired'"); $d->execute(['of:' . (int)$zone['id'] . ':p%']); $dm = []; foreach ($d->fetchAll() as $r) $dm[$r['lib_key']] = (int)$r['id'];
    foreach ($st->fetchAll() as $p) {
        $must = ofp_must_of($p);
        $out[] = ['point_id' => (int)$p['id'], 'label' => $p['text'], 'label_hi' => (string)($p['text_hi'] ?? ''), 'must_text' => ofp_must_text($must), 'must' => $must, 'has_ref' => !empty($p['ref_photo']), 'ref_url' => 'api.php?action=of.pl_photo_get&point_id=' . (int)$p['id'], 'sort' => (int)$p['sort_no'], 'duty_id' => $dm['of:' . (int)$zone['id'] . ':p' . (int)$p['id']] ?? null];
    }
    return $out;
}

/* ============================== uploads ============================== */
function ofp_upload($field, $required, $why) {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? 4) === 4 || empty($_FILES[$field]['tmp_name'])) { if ($required) json_response(['error' => $why], 422); return null; }
    if ((int)$_FILES[$field]['error'] !== 0) json_response(['error' => 'The picture could not be uploaded. Try a smaller picture.'], 422);
    return $_FILES[$field]['tmp_name'];
}
function ofp_save_ref($tmp) { list($fn, $err) = bd_save_photo_file($tmp, 0); if (!$fn) json_response(['error' => $err], 422); $new = 'ofref_' . substr($fn, -22); @rename(bd_dir() . $fn, bd_dir() . $new); return is_file(bd_dir() . $new) ? $new : $fn; }

/* ============================== Claude: proposals (AI optional, rules as fallback) ============================== */
function ofp_rule_proposals($text) {
    $t = mb_strtolower((string)$text); $o = ['counts' => [], 'daily' => [], 'once' => [], 'equipment' => [], 'must' => []];
    if (preg_match('/pantry|kitchen|tea|coffee|cafeteria|पैंट्री|रसोई/u', $t)) {
        $o['counts'] = [['name' => 'Tea', 'unit' => 'pack', 'min' => 1, 'fill_to' => 3], ['name' => 'Coffee', 'unit' => 'pack', 'min' => 1, 'fill_to' => 3], ['name' => 'Sugar', 'unit' => 'kg', 'min' => 1, 'fill_to' => 3], ['name' => 'Paper cups', 'unit' => 'pack', 'min' => 1, 'fill_to' => 4]];
        $o['daily'] = [['name' => 'Milk', 'unit' => 'L'], ['name' => 'Ice', 'unit' => 'kg'], ['name' => 'Drinking water', 'unit' => 'can']];
        $o['once'] = [['name' => 'Hand wash', 'unit' => 'bottle', 'min' => 1, 'fill_to' => 2], ['name' => 'Dish soap', 'unit' => 'bottle', 'min' => 1, 'fill_to' => 2]];
        $o['equipment'] = [['name' => 'Coffee machine'], ['name' => 'Water dispenser'], ['name' => 'Fridge'], ['name' => 'Kettle']];
        $o['must'] = [['label' => 'Pantry counter and sink', 'text' => '1 coffee machine, 1 kettle, 1 dustbin']];
    } elseif (preg_match('/washroom|toilet|bathroom|restroom|वॉशरूम/u', $t)) {
        $o['counts'] = [['name' => 'Tissue paper', 'unit' => 'pack', 'min' => 2, 'fill_to' => 6], ['name' => 'Toilet cleaner', 'unit' => 'bottle', 'min' => 1, 'fill_to' => 2]];
        $o['once'] = [['name' => 'Hand wash', 'unit' => 'bottle', 'min' => 1, 'fill_to' => 2], ['name' => 'Air freshener', 'unit' => 'pc', 'min' => 1, 'fill_to' => 2]];
        $o['must'] = [['label' => 'Washroom', 'text' => '1 dustbin, 1 hand wash, 1 mirror']];
    } elseif (preg_match('/reception|lobby|counter|entrance/u', $t)) {
        $o['counts'] = [['name' => 'Visitor pens', 'unit' => 'pc', 'min' => 2, 'fill_to' => 6]];
        $o['equipment'] = [['name' => 'Water dispenser']];
        $o['must'] = [['label' => 'Reception desk', 'text' => '1 desk, 1 chair']];
    }
    return $o;
}
function ofp_clean_proposals($p) {
    $o = ['counts' => [], 'daily' => [], 'once' => [], 'equipment' => [], 'must' => []]; if (!is_array($p)) return $o;
    foreach (['counts', 'daily', 'once'] as $g) foreach (array_slice((array)($p[$g] ?? []), 0, 25) as $e) {
        if (!is_array($e)) continue; $n = ofp_trim($e['name'] ?? '', 100); if ($n === '') continue;
        $r = ['name' => $n, 'name_hi' => ofp_trim($e['name_hi'] ?? '', 120), 'unit' => ofp_trim($e['unit'] ?? '', 20) ?: 'pc'];
        if (isset($e['min']) && $e['min'] !== '' && $e['min'] !== null) $r['min'] = max(0, min(100000, round((float)$e['min'], 2)));
        if (isset($e['fill_to']) && $e['fill_to'] !== '' && $e['fill_to'] !== null) $r['fill_to'] = max(0, min(100000, round((float)$e['fill_to'], 2)));
        if ($g === 'counts' && !isset($r['min'])) $r['min'] = 1;
        $o[$g][] = $r;
    }
    foreach (array_slice((array)($p['equipment'] ?? []), 0, 25) as $e) { $n = ofp_trim(is_array($e) ? ($e['name'] ?? '') : $e, 100); if ($n !== '') $o['equipment'][] = ['name' => $n, 'name_hi' => is_array($e) ? ofp_trim($e['name_hi'] ?? '', 120) : '']; }
    foreach (array_slice((array)($p['must'] ?? []), 0, 12) as $e) {
        if (!is_array($e)) continue; $txt = ofp_trim($e['text'] ?? '', 400); $lab = ofp_trim($e['label'] ?? '', 120); if ($txt === '' && $lab === '') continue;
        $o['must'][] = ['label' => $lab, 'text' => ofp_must_text(ofp_parse_must($txt))];
    }
    return $o;
}
/** [ai, proposals] — frames are data: URLs from the phone. The AI only PROPOSES; the rule-based list is used when the AI is not there or fails. */
function ofp_suggest(PDO $pdo, array $o, array $zone, array $frames, $notes) {
    $rules = ofp_rule_proposals($zone['name'] . ' ' . $notes);
    if (!function_exists('convo_ai') || !$frames) return ['rules', $rules];
    $parts = [['text' => 'PLACE: ' . $zone['name'] . ' in the office ' . $o['name'] . "\nNOTES FROM THE OWNER: " . ($notes !== '' ? $notes : '(none)') . "\n\nPictures of the place:"]]; $n = 0;
    foreach ($frames as $f) {
        if (!is_string($f) || !preg_match('~^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$~', $f, $m)) continue; if (strlen($m[2]) > 1800000) continue;
        $n++; $parts[] = ['text' => "Picture $n:"]; $parts[] = ['inlineData' => ['mimeType' => 'image/' . $m[1], 'data' => $m[2]]]; if ($n >= 8) break;
    }
    if (!$n) return ['rules', $rules];
    $sys = "You help the owner of a small perfume company in Surat register ONE place of an office (e.g. the pantry). From the pictures propose: counting items that run out (name, unit, a sensible MINIMUM and a fill-to number), things refilled every day (milk, ice, water), things bought only when low (hand wash, dish soap), must-have equipment (coffee machine, fridge), and for each picture what must be visible with counts (e.g. '2 handlers, 1 beaker'). "
        . "Write names in simple English and simple Hindi (Devanagari). Do not invent things you cannot see unless they are normal for such a place (mark nothing as certain). Never mention people.";
    $item = ['type' => 'OBJECT', 'properties' => ['name' => ['type' => 'STRING'], 'name_hi' => ['type' => 'STRING'], 'unit' => ['type' => 'STRING'], 'min' => ['type' => 'NUMBER'], 'fill_to' => ['type' => 'NUMBER']], 'required' => ['name']];
    $schema = ['type' => 'OBJECT', 'properties' => ['counts' => ['type' => 'ARRAY', 'items' => $item], 'daily' => ['type' => 'ARRAY', 'items' => $item], 'once' => ['type' => 'ARRAY', 'items' => $item],
        'equipment' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['name' => ['type' => 'STRING'], 'name_hi' => ['type' => 'STRING']], 'required' => ['name']]],
        'must' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['label' => ['type' => 'STRING'], 'text' => ['type' => 'STRING']], 'required' => ['text']]]]];
    try { $r = convo_ai($sys, $parts, $schema, false, 3000); } catch (Throwable $e) { return ['rules', $rules]; }
    if (empty($r[0]) || !is_array($r[1])) return ['rules', $rules];
    $p = ofp_clean_proposals($r[1]);
    if (!$p['counts'] && !$p['daily'] && !$p['once'] && !$p['equipment'] && !$p['must']) return ['rules', $rules];
    return ['claude', $p];
}
/** of.draft (office.php) asks the AI for zones; these hooks make it propose counting items etc. per zone too (proposals only, kept in of_settings) */
function ofp_draft_schema_extra(array $schema) {
    $item = ['type' => 'OBJECT', 'properties' => ['name' => ['type' => 'STRING'], 'name_hi' => ['type' => 'STRING'], 'unit' => ['type' => 'STRING'], 'min' => ['type' => 'NUMBER'], 'fill_to' => ['type' => 'NUMBER']], 'required' => ['name']];
    if (isset($schema['properties']['zones']['items']['properties'])) {
        $schema['properties']['zones']['items']['properties']['counts'] = ['type' => 'ARRAY', 'items' => $item];
        $schema['properties']['zones']['items']['properties']['daily'] = ['type' => 'ARRAY', 'items' => $item];
        $schema['properties']['zones']['items']['properties']['equipment'] = ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['name' => ['type' => 'STRING'], 'name_hi' => ['type' => 'STRING']], 'required' => ['name']]];
        $schema['properties']['zones']['items']['properties']['must'] = ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['label' => ['type' => 'STRING'], 'text' => ['type' => 'STRING']], 'required' => ['text']]];
    }
    return $schema;
}
function ofp_draft_sys() { return ' ALSO, for every zone, propose (as proposals only, the owner decides): counting items that run out with a sensible minimum and fill-to number (counts), things refilled every day (daily), must-have equipment (equipment), and what each photo must show with counts such as "2 handlers, 1 beaker" (must).'; }
function ofp_draft_save(PDO $pdo, $zoneId, array $z) {
    $GLOBALS['__ofp_draft_summary'] = $GLOBALS['__ofp_draft_summary'] ?? [];
    $p = ofp_clean_proposals($z);
    if (!$p['counts'] && !$p['daily'] && !$p['equipment'] && !$p['must']) $p = ofp_rule_proposals((string)($z['name'] ?? ''));
    if (!$p['counts'] && !$p['daily'] && !$p['once'] && !$p['equipment'] && !$p['must']) return;
    try { of_put('plprop:' . (int)$zoneId, ofp_json($p)); } catch (Throwable $e) { return; }
    $GLOBALS['__ofp_draft_summary'][] = ['zone_id' => (int)$zoneId, 'name' => (string)($z['name'] ?? ''), 'proposals' => $p];
}
function ofp_draft_summary() { return $GLOBALS['__ofp_draft_summary'] ?? []; }

/* ============================== Claude asks (claude_q.php): who does the check, what must be visible ============================== */
function ofp_cq_forget(PDO $pdo, $zid) { /* the question vanishes on the next scan by itself; this just closes it at once */ try { $pdo->prepare("UPDATE cq_q SET status = 'gone', answered_at = ? WHERE status = 'open' AND sig IN (?, ?)")->execute([bd_now(), 'ofp:owner:' . (int)$zid, 'ofp:must:' . (int)$zid]); } catch (Throwable $e) {} }
function ofp_cq_questions(PDO $pdo, array &$live) {
    ofp_ensure($pdo);
    $rows = $pdo->query("SELECT p.*, z.name zname, z.status zstatus FROM of_pl_places p JOIN of_zones z ON z.id = p.zone_id WHERE p.state = 'on' ORDER BY p.zone_id LIMIT 60")->fetchAll();
    foreach ($rows as $pl) {
        $o = ofp_office($pdo, (int)$pl['office_id']); if (!$o) continue; $zid = (int)$pl['zone_id']; $label = $pl['zname'] . ' · ' . $o['name'];
        $sec = $o['section_id'] ? ofp_section_row($pdo, $o['section_id']) : null; $code = $sec ? (string)$sec['code'] : null;
        $ov = ofp_owner_view($pdo, $o, $pl);
        if ($ov['using'] === 'none') {
            $sug = ofp_suggest_owners($pdo, $o, 3); $opts = [];
            foreach ($sug as $s) $opts[] = ['id' => (string)$s['id'], 'label' => $s['name'], 'hint' => $s['why']];
            $opts[] = ['id' => 'other', 'label' => 'Someone else…', 'input' => 'person'];
            cq_ask($pdo, $live, 'ofp:owner:' . $zid, ['kind' => 'gap', 'subject' => $label, 'section_code' => $code, 'series' => 'place', 'seq' => 1, 'input' => 'choice',
                'title' => 'Who does the daily check for ' . $label . '?', 'body' => $sug ? 'My suggestion: ' . $sug[0]['name'] . ' (' . $sug[0]['why'] . '). Nothing changes until you answer.' : 'Nobody is chosen yet, so the office cleaners get the tasks.', 'options' => $opts, 'payload' => ['zone_id' => $zid, 'kind' => 'place_owner']], true);
        }
        $m = $pdo->prepare("SELECT COUNT(*) c, SUM(must_json IS NOT NULL AND must_json != '' AND must_json != '[]') m FROM of_points WHERE zone_id = ?"); $m->execute([$zid]); $m = $m->fetch();
        if ((int)$m['c'] > 0 && (int)$m['m'] === 0) {
            cq_ask($pdo, $live, 'ofp:must:' . $zid, ['kind' => 'gap', 'subject' => $label, 'section_code' => $code, 'series' => 'place', 'seq' => 2, 'input' => 'text',
                'title' => 'What must be visible in the photo of ' . $label . '?', 'body' => 'Write it like: 2 handlers, 2 pressers, 1 beaker. I will tell the photo check to count them. Nothing changes until you answer.', 'payload' => ['zone_id' => $zid, 'kind' => 'place_must']], true);
        }
    }
    // a place question whose gap has closed (owner chosen, list written, place switched off or deleted) goes away by itself
    foreach ($pdo->query("SELECT id, sig FROM cq_q WHERE status = 'open' AND sig LIKE 'ofp:%'")->fetchAll() as $r) { if (!isset($live[$r['sig']])) $pdo->prepare("UPDATE cq_q SET status = 'gone', answered_at = ? WHERE id = ?")->execute([bd_now(), (int)$r['id']]); }
}
/** an answer in the Claude-asks panel (claude_q.php cq_apply hands every 'ofp:' question to us). Returns [message, answer]. */
function ofp_cq_apply(PDO $pdo, array $w, array $q, $choice, $text, $by) {
    ofp_ensure($pdo);
    $pl = json_decode((string)$q['payload'], true) ?: []; $zid = (int)($pl['zone_id'] ?? 0); $z = $zid ? ofp_zone($pdo, $zid) : null; $p = $zid ? ofp_place($pdo, $zid) : null;
    if (!$z || !$p) return ['That place is gone.', 'gone'];
    $o = ofp_office($pdo, (int)$p['office_id']); if (!$o) return ['That office is gone.', 'gone'];
    if (strpos((string)$q['sig'], 'ofp:must:') === 0) {
        $must = ofp_parse_must((string)$text); if (!$must) json_response(['error' => 'Write what must be visible, for example: 2 handlers, 2 pressers, 1 beaker.'], 422);
        $pt = $pdo->prepare("SELECT id FROM of_points WHERE zone_id = ? ORDER BY sort_no, id LIMIT 1"); $pt->execute([$zid]); $pid = (int)$pt->fetchColumn();
        if ($pid) { $pdo->prepare("UPDATE of_points SET must_json = ? WHERE id = ?")->execute([ofp_json($must), $pid]); ofp_sync_zone($pdo, $o, $z, ofp_place($pdo, $zid), $by); }
        return ['Saved. The photo check now counts: ' . ofp_must_text($must) . '.', (string)$text];
    }
    if (!ofp_is_admin(['role' => (string)($w['role'] ?? '')]) && empty($w['admin'])) json_response(['error' => 'Only Admin or HR can choose the owner of a place.'], 403);
    if ($choice === 'office') { list($ok, $msg) = ofp_set_owner($pdo, $o, $z, [], $by); return [$msg, 'office']; }
    $e = (int)($choice === 'other' ? $text : $choice); if (!$e) json_response(['error' => 'Choose who does the daily check.'], 422);
    list($ok, $msg) = ofp_set_owner($pdo, $o, $z, [$e], $by); if (!$ok) json_response(['error' => $msg], 422);
    return [$msg, (string)$e];
}

/* ============================== Claude boss chat (dept_boss.php): vocabulary, context, the place_owner action ============================== */
function ofp_hq_words() { return '/pantry|refill|to buy|minimum|place owner|equipment|coffee machine|\bmilk\b|\bice\b|below min|daily check|count and refill|पैंट्री/iu'; }
function ofp_hq_places(PDO $pdo, array $w, $limit = 20) {
    ofp_ensure($pdo);
    $out = [];
    foreach ($pdo->query("SELECT p.*, z.name zname, z.name_hi zhi FROM of_pl_places p JOIN of_zones z ON z.id = p.zone_id WHERE p.state IN ('on','off') ORDER BY p.zone_id LIMIT " . (int)$limit)->fetchAll() as $pl) {
        $o = ofp_office($pdo, (int)$pl['office_id']); if (!$o) continue;
        if (!(!empty($w['admin']) || !empty($w['top']) || ($o['section_id'] && bd_can_section($w, (int)$o['section_id'])))) continue;
        $out[] = [$pl, $o, $pl['zname']];
    }
    return $out;
}
function ofp_hq_context(PDO $pdo, array $w) {
    $L = []; $day = bd_today();
    foreach (ofp_hq_places($pdo, $w) as $x) {
        list($pl, $o, $zn) = $x; $zone = ofp_zone($pdo, $pl['zone_id']); if (!$zone) continue;
        $own = ofp_owner_view($pdo, $o, $pl); $t = ofp_today_block($pdo, $o, $zone, $pl);
        $bm = array_map(function ($b) { return $b['name'] . ' ' . ofp_num($b['count']) . ' (min ' . ofp_num($b['min']) . ')'; }, $t['below_min']);
        $eq = array_map(function ($b) { return $b['name'] . ' ' . $b['state']; }, $t['equipment_bad']);
        $L[] = '- [place ' . $pl['zone_id'] . '] ' . $zn . ' · ' . $o['name'] . ' (' . $pl['state'] . '): owner ' . ($own['names'] ? implode(', ', $own['names']) : ($own['using'] === 'office' ? 'office cleaners' : 'NOBODY')) . '; today check ' . ($t['submitted'] ? 'done' : ($t['partial'] ? 'partly done' : 'not done')) . '; below minimum: ' . ($bm ? implode(', ', $bm) : 'none') . '; equipment problems: ' . ($eq ? implode(', ', $eq) : 'none') . '; open to buy: ' . $t['open_needs'];
    }
    return $L ? "\nPLACES (pantry, counts, refills, equipment; zone id in brackets):\n" . implode("\n", $L) : '';
}
function ofp_hq_prompt() {
    return ' PLACES: offices have registered places like the Pantry with counting items (minimum), daily refills, one-time refills, equipment (present/missing/broken), things to fix and to buy, and a PLACE OWNER who does the daily check. Use the PLACES data to answer "what is missing in the pantry today", "who owns the pantry", "what is below minimum". '
        . 'You may also propose {"type":"place_owner","args":{"zone_id":0,"emp_ids":[0]}} (first person = owner, the rest = backups in order; use a zone id from the PLACES list and ids from the people list). It only runs after he presses Yes.';
}
/** the place_owner action (dp_hq_clean_action asks us): a clean, safe proposal or null */
function ofp_hq_clean_action(PDO $pdo, array $w, array $a) {
    ofp_ensure($pdo);
    $g = is_array($a['args'] ?? null) ? $a['args'] : []; $zid = (int)($g['zone_id'] ?? 0); $ids = array_values(array_unique(array_filter(array_map('intval', (array)($g['emp_ids'] ?? [])))));
    if (!$zid) return null; $z = ofp_zone($pdo, $zid); $pl = $z ? ofp_place($pdo, $zid) : null; if (!$pl) return null; $o = ofp_office($pdo, (int)$pl['office_id']); if (!$o) return null;
    foreach ($ids as $i) { if (!bd_emp_active(bd_emp($pdo, $i))) return null; }
    $label = mb_substr(trim((string)($a['label'] ?? '')), 0, 200);
    return ['type' => 'place_owner', 'args' => ['zone_id' => $zid, 'emp_ids' => $ids], 'label' => $label ?: ($z['name'] . ' · ' . $o['name'] . ': ' . ($ids ? implode(', ', array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, $ids)) . ' does the daily check' : 'no place owner (office cleaners)')), 'admin' => true];
}
function ofp_hq_do_place_owner(PDO $pdo, array $args, $by) {
    $z = ofp_zone($pdo, (int)$args['zone_id']); $pl = $z ? ofp_place($pdo, $z['id']) : null; if (!$pl) json_response(['error' => 'That place is gone.'], 404);
    $o = ofp_office($pdo, (int)$pl['office_id']); list($ok, $msg) = ofp_set_owner($pdo, $o, $z, (array)$args['emp_ids'], $by);
    if (!$ok) json_response(['error' => $msg], 422);
    return $msg;
}
/** the boss chat when the AI is off (or failed): answers pantry / refill / to-buy / minimum / place-owner questions from today's data. Returns the chat reply array or null. */
function ofp_hq_rule_answer(PDO $pdo, array $w, $msg) {
    if (!preg_match(ofp_hq_words(), (string)$msg)) {
        $hit = false; foreach (ofp_hq_places($pdo, $w) as $x) { if (mb_stripos((string)$msg, (string)$x[2]) !== false) $hit = true; } if (!$hit) return null;
    }
    $places = ofp_hq_places($pdo, $w); if (!$places) return null;
    $m = mb_strtolower((string)$msg); $pick = [];
    foreach ($places as $x) { if (mb_stripos($m, mb_strtolower($x[2])) !== false || mb_stripos($m, mb_strtolower((string)($x[0]['zhi'] ?? 'zzzz'))) !== false) $pick[] = $x; }
    if (!$pick) $pick = $places;
    $lines = []; $actions = []; $gaps = []; $links = [['label' => 'Offices', 'page' => 'office_care.html']];
    foreach (array_slice($pick, 0, 4) as $x) {
        list($pl, $o, $zn) = $x; $zone = ofp_zone($pdo, $pl['zone_id']); if (!$zone) continue;
        $own = ofp_owner_view($pdo, $o, $pl); $t = ofp_today_block($pdo, $o, $zone, $pl); $label = $zn . ' · ' . $o['name'];
        $s = $label . ': ';
        if ($pl['state'] !== 'on') $s .= 'the place is switched off. ';
        elseif (!$t['submitted']) $s .= ($t['partial'] ? 'today\'s check is only partly done. ' : 'today\'s check is not done yet. ');
        $miss = [];
        foreach ($t['below_min'] as $b) $miss[] = $b['name'] . ' ' . ofp_num($b['count']) . ' (minimum ' . ofp_num($b['min']) . ')';
        foreach ($t['equipment_bad'] as $b) $miss[] = $b['name'] . ' ' . $b['state'];
        $needs = $pdo->prepare("SELECT i.name, i.unit, n.qty FROM dp_su_needs n JOIN dp_su_items i ON i.id = n.item_id WHERE i.zone_id = ? AND n.status = 'open' ORDER BY n.id LIMIT 8"); $needs->execute([(int)$zone['id']]);
        $nb = array_map(function ($r) { return $r['name'] . ' ' . ofp_num($r['qty']) . ' ' . $r['unit']; }, $needs->fetchAll());
        $s .= $miss ? 'missing or low: ' . implode('; ', $miss) . '. ' : 'nothing is missing in today\'s counts. ';
        if ($nb) $s .= 'To buy: ' . implode(', ', $nb) . '. ';
        $s .= $own['names'] ? 'Owner: ' . implode(', ', $own['names']) . '.' : ($own['using'] === 'office' ? 'The office cleaners do it (no place owner).' : 'Nobody owns it yet.');
        $lines[] = trim($s);
        if (!$own['names'] && $pl['state'] === 'on') {
            $sg = ofp_suggest_owners($pdo, $o, 1);
            if ($sg) { $actions[] = ['type' => 'place_owner', 'args' => ['zone_id' => (int)$zone['id'], 'emp_ids' => [(int)$sg[0]['id']]], 'label' => $label . ': ' . $sg[0]['name'] . ' does the daily check (' . $sg[0]['why'] . ')', 'admin' => true]; $gaps[] = $label . ' has no owner for the daily check.'; }
        }
    }
    return ['ai' => 'rules', 'reply' => mb_substr(implode("\n", $lines), 0, 1800), 'links' => $links, 'actions' => array_slice($actions, 0, 4), 'prompt' => '', 'gaps' => array_slice($gaps, 0, 6)];
}

/* ============================== the tick (called from dp_supplies_tick): keep the duties in step ============================== */
function ofp_tick(PDO $pdo, $now) {
    ofp_ensure($pdo); $n = 0;
    $rows = $pdo->query("SELECT p.* FROM of_pl_places p WHERE p.state IN ('on','off') ORDER BY p.zone_id LIMIT 200")->fetchAll();
    foreach ($rows as $pl) {
        $zone = ofp_zone($pdo, $pl['zone_id']); $o = ofp_office($pdo, (int)$pl['office_id']); if (!$zone || !$o) continue;
        $seat = ofp_place_seat_id($pdo, $pl); $hold = $seat ? count(bd_holders($pdo, $seat)) : 0;
        $sig = md5(implode('|', [$pl['state'], $zone['status'], $zone['name'], $pl['check_by'], $pl['photo_time'], (int)$o['section_id'], $hold > 0 ? 1 : 0, ofp_rule_amount($pdo, 'place_count_missed'), (int)ofp_has_items($pdo, $pl['zone_id']),
            (string)$pdo->query("SELECT COALESCE(MAX(id),0) FROM of_points WHERE zone_id = " . (int)$pl['zone_id'])->fetchColumn()]));
        if (of_cfg('plsig:' . $pl['zone_id'], '') === $sig) continue;
        ofp_section_ensure($pdo, $o); ofp_sync_zone($pdo, $o, $zone, $pl, 'Places', true); of_put('plsig:' . $pl['zone_id'], $sig); $n++;
    }
    return $n;
}

/* ============================== the daily check: form + submit ============================== */
/** find the count/refill task + zone from a task id. Returns [task row, zone, office, place] or json error. */
function ofp_task_ctx(PDO $pdo, $taskId) {
    $st = $pdo->prepare("SELECT t.*, d.lib_key, d.title FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE t.id = ?"); $st->execute([(int)$taskId]); $t = $st->fetch();
    if (!$t || strpos((string)$t['lib_key'], 'ofc:') !== 0) json_response(['error' => 'This is not a count and refill task.'], 404);
    $zid = (int)substr($t['lib_key'], 4); $z = ofp_zone($pdo, $zid); $pl = $z ? ofp_place($pdo, $zid) : null; $o = $pl ? ofp_office($pdo, (int)$pl['office_id']) : null;
    if (!$z || !$pl || !$o) json_response(['error' => 'That place is gone.'], 404);
    return [$t, $z, $o, $pl];
}
function ofp_log_map(PDO $pdo, $zid, $day) {
    $st = $pdo->prepare("SELECT * FROM of_pl_log WHERE zone_id = ? AND day = ?"); $st->execute([(int)$zid, $day]); $m = [];
    foreach ($st->fetchAll() as $r) $m[(int)$r['item_id']] = $r; return $m;
}
function ofp_form(PDO $pdo, array $t, array $z, array $o, array $pl, $lang) {
    $day = bd_today(); $g = ofp_zone_items($pdo, $z['id']); $log = ofp_log_map($pdo, $z['id'], $day);
    $count = []; $daily = []; $once = []; $eq = []; $req = 0; $ans = 0;
    foreach ($g['count'] as $i) {
        $l = $log[(int)$i['id']] ?? null;
        if (ofp_is_numeric($i)) { $v = $l && $l['val'] !== null ? (float)$l['val'] : null; $req++; if ($v !== null) $ans++;
            $count[] = ['item_id' => (int)$i['id'], 'name' => $i['name'], 'name_hi' => (string)($i['name_hi'] ?? ''), 'unit' => $i['unit'], 'min' => (float)$i['min_qty'], 'fill_to' => $i['fill_to'] !== null ? (float)$i['fill_to'] : null, 'last_count' => $i['last_count'] !== null ? (float)$i['last_count'] : null, 'value' => $v, 'below' => $v !== null && $v < (float)$i['min_qty']]; }
        else { $s = $l ? $l['state'] : null; $req++; if ($s) $ans++; $once[] = ['item_id' => (int)$i['id'], 'name' => $i['name'], 'name_hi' => (string)($i['name_hi'] ?? ''), 'unit' => $i['unit'], 'mode' => 'tap', 'min' => null, 'value' => null, 'state' => $s]; }
    }
    foreach ($g['daily'] as $i) { $l = $log[(int)$i['id']] ?? null; $s = $l ? $l['state'] : null; $req++; if ($s) $ans++; $daily[] = ['item_id' => (int)$i['id'], 'name' => $i['name'], 'name_hi' => (string)($i['name_hi'] ?? ''), 'unit' => $i['unit'], 'state' => $s]; }
    foreach ($g['once'] as $i) {
        $l = $log[(int)$i['id']] ?? null; $req++;
        if (ofp_is_numeric($i)) { $v = $l && $l['val'] !== null ? (float)$l['val'] : null; if ($v !== null) $ans++; $once[] = ['item_id' => (int)$i['id'], 'name' => $i['name'], 'name_hi' => (string)($i['name_hi'] ?? ''), 'unit' => $i['unit'], 'mode' => 'count', 'min' => (float)$i['min_qty'], 'value' => $v, 'state' => null]; }
        else { $s = $l ? $l['state'] : null; if ($s) $ans++; $once[] = ['item_id' => (int)$i['id'], 'name' => $i['name'], 'name_hi' => (string)($i['name_hi'] ?? ''), 'unit' => $i['unit'], 'mode' => 'tap', 'min' => null, 'value' => null, 'state' => $s]; }
    }
    foreach ($g['equip'] as $i) { $l = $log[(int)$i['id']] ?? null; $s = $l ? $l['state'] : null; $req++; if ($s) $ans++; $eq[] = ['item_id' => (int)$i['id'], 'name' => $i['name'], 'name_hi' => (string)($i['name_hi'] ?? ''), 'state' => $s, 'last_state' => $i['equip_state']]; }
    $photos = ofp_photo_cards($pdo, $z, $day);
    $ck = $pdo->prepare("SELECT status FROM of_pl_checks WHERE zone_id = ? AND day = ?"); $ck->execute([(int)$z['id'], $day]); $cs = $ck->fetchColumn();
    return ['place' => ['zone_id' => (int)$z['id'], 'name' => $z['name'], 'name_hi' => (string)($z['name_hi'] ?? ''), 'office' => $o['name']], 'task' => ['id' => (int)$t['id'], 'status' => $t['status'], 'due_at' => $t['due_at'], 'late' => $t['status'] === 'missed'], 'day' => $day, 'lang' => $lang,
        'photos' => $photos, 'count' => $count, 'daily' => $daily, 'once' => $once, 'equipment' => $eq, 'submitted' => $cs === 'done', 'partial' => $cs === 'partial', 'required' => $req, 'answered' => $ans];
}
function ofp_photo_cards(PDO $pdo, array $z, $day) {
    $pts = $pdo->prepare("SELECT * FROM of_points WHERE zone_id = ? ORDER BY sort_no, id"); $pts->execute([(int)$z['id']]); $out = [];
    $ts = $pdo->prepare("SELECT t.id, t.status, t.owner_emp, d.lib_key FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE t.task_date = ? AND t.status != 'excused' AND d.lib_key LIKE ?"); $ts->execute([$day, 'of:' . (int)$z['id'] . ':p%']);
    $tm = []; foreach ($ts->fetchAll() as $r) $tm[$r['lib_key']] = $r;
    foreach ($pts->fetchAll() as $p) {
        $tk = $tm['of:' . (int)$z['id'] . ':p' . (int)$p['id']] ?? null;
        $out[] = ['task_id' => $tk ? (int)$tk['id'] : null, 'point_id' => (int)$p['id'], 'label' => $p['text'], 'label_hi' => (string)($p['text_hi'] ?? ''), 'status' => $tk ? $tk['status'] : 'none', 'has_ref' => !empty($p['ref_photo']), 'ref_url' => 'api.php?action=of.pl_photo_get&point_id=' . (int)$p['id'], 'must' => ofp_must_of($p)];
    }
    return $out;
}
function ofp_may_open_task(array $auth, array $w, array $t) { return ofp_is_admin($auth) || ((int)$w['emp'] && (int)$t['owner_emp'] === (int)$w['emp']); }

/** the submit. Returns the reply array. */
function ofp_submit(PDO $pdo, array $auth, array $w, array $t, array $z, array $o, array $pl, array $in, $by) {
    $day = bd_today(); $now = time(); $emp = (int)$w['emp'];
    $g = ofp_zone_items($pdo, $z['id']); $log = ofp_log_map($pdo, $z['id'], $day);
    $counts = is_array($in['counts'] ?? null) ? $in['counts'] : []; $once = is_array($in['once'] ?? null) ? $in['once'] : []; $refills = is_array($in['refills'] ?? null) ? $in['refills'] : []; $equip = is_array($in['equipment'] ?? null) ? $in['equipment'] : [];
    $byId = []; foreach ($g as $grp => $list) foreach ($list as $i) $byId[(int)$i['id']] = $i;
    foreach (array_merge(array_keys($counts), array_keys($once), array_keys($refills), array_keys($equip)) as $k) { if (!isset($byId[(int)$k]) || !in_array(ofp_group($byId[(int)$k]), ['count', 'daily', 'once', 'equip'], true)) json_response(['error' => 'One of the lines does not belong to ' . $z['name'] . '.'], 422); }
    // ---- validate everything first, so a bad number saves nothing
    $set = [];   // item_id => [kind, val, state]
    foreach ($counts + $once as $k => $v) {
        $i = $byId[(int)$k]; if (!in_array(ofp_group($i), ['count', 'once'], true)) json_response(['error' => $i['name'] . ' is not a counting item.'], 422);
        if (ofp_is_numeric($i)) {
            if (is_string($v)) $v = str_replace(',', '.', trim($v)); if ($v === '' || $v === null || !is_numeric($v) || (float)$v < 0 || (float)$v > 100000) json_response(['error' => 'Enter a sensible number for ' . $i['name'] . '.'], 422);
            $set[(int)$k] = ['count', round((float)$v, 2), null];
        } else {
            $s = strtolower(trim((string)$v)); if (!in_array($s, ['ok', 'low', 'empty'], true)) json_response(['error' => $i['name'] . ' must be Ok, Low or Empty.'], 422);
            $set[(int)$k] = ['tap', null, $s];
        }
    }
    foreach ($refills as $k => $v) { $i = $byId[(int)$k]; if (ofp_group($i) !== 'daily') json_response(['error' => $i['name'] . ' is not a daily refill.'], 422); $s = strtolower(trim((string)$v)); if (!in_array($s, ['done', 'none'], true)) json_response(['error' => $i['name'] . ': choose Done or None left.'], 422); $set[(int)$k] = ['refill', null, $s]; }
    foreach ($equip as $k => $v) { $i = $byId[(int)$k]; if (ofp_group($i) !== 'equip') json_response(['error' => $i['name'] . ' is not equipment.'], 422); $s = strtolower(trim((string)$v)); if (!in_array($s, ['present', 'missing', 'broken'], true)) json_response(['error' => $i['name'] . ' must be Present, Missing or Broken.'], 422); $set[(int)$k] = ['equip', null, $s]; }
    // ---- save the answers (a resubmit updates the same row) and apply the rules
    $needs = []; $fixes = []; $cancelled = 0;
    foreach ($set as $iid => $a) {
        $i = $byId[$iid]; list($kind, $val, $state) = $a;
        $pdo->prepare("INSERT INTO of_pl_log (zone_id, item_id, day, kind, val, state, emp_id, task_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE zone_id = VALUES(zone_id), kind = VALUES(kind), val = VALUES(val), state = VALUES(state), emp_id = VALUES(emp_id), task_id = VALUES(task_id), updated_at = VALUES(updated_at)")
            ->execute([(int)$z['id'], $iid, $day, $kind, $val, $state, $emp ?: null, (int)$t['id'], bd_now(), bd_now()]);
        if ($kind === 'count') {
            $pdo->prepare("UPDATE dp_su_items SET last_count = ?, last_count_at = ? WHERE id = ?")->execute([$val, bd_now(), $iid]);
            if ($val < (float)$i['min_qty']) { $q = ofp_need_qty($i, $val); ofp_need_upsert($pdo, $i, 'min', $q, $now); $needs[] = ['item_id' => $iid, 'name' => $i['name'], 'qty' => $q, 'reason' => 'min']; }
            else $cancelled += ofp_need_cancel($pdo, $iid, ['min']);
        } elseif ($kind === 'tap') {
            $pdo->prepare("INSERT INTO dp_su_checks (item_id, place_id, state, emp_id, checked_on, created_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE state = VALUES(state), emp_id = VALUES(emp_id), created_at = VALUES(created_at)")->execute([$iid, (int)$i['place_id'], $state, $emp ?: null, $day, bd_now()]);
            $pdo->prepare("UPDATE dp_su_items SET state = ?, state_at = ? WHERE id = ?")->execute([$state, bd_now(), $iid]);
            if ($state === 'ok') { $pdo->prepare("UPDATE dp_su_needs SET status = 'cancelled', closed_at = ? WHERE item_id = ? AND status = 'open' AND reason IN ('low','empty')")->execute([bd_now(), $iid]); }
            elseif (function_exists('dp_su_open_need')) { dp_su_open_need($pdo, $i, $state, $now); $needs[] = ['item_id' => $iid, 'name' => $i['name'], 'qty' => (float)$i['qty'], 'reason' => $state]; }
        } elseif ($kind === 'refill') {
            if ($state === 'none') { ofp_need_upsert($pdo, $i, 'empty', (float)$i['qty'], $now); $needs[] = ['item_id' => $iid, 'name' => $i['name'], 'qty' => (float)$i['qty'], 'reason' => 'empty']; }
            else $cancelled += ofp_need_cancel($pdo, $iid, ['empty']);
        } elseif ($kind === 'equip') {
            $pdo->prepare("UPDATE dp_su_items SET equip_state = ? WHERE id = ?")->execute([$state, $iid]);
            if ($state === 'missing') { ofp_need_upsert($pdo, $i, 'missing', 1, $now); $needs[] = ['item_id' => $iid, 'name' => $i['name'], 'qty' => 1.0, 'reason' => 'missing']; }
            else $cancelled += ofp_need_cancel($pdo, $iid, ['missing']);
            if ($state === 'broken') {
                $open = $pdo->prepare("SELECT id FROM dp_fx_jobs WHERE src_key LIKE ? AND status IN ('reported','given','doing') LIMIT 1"); $open->execute(['pleq:' . $iid . ':%']); $ex = (int)$open->fetchColumn();
                if (!$ex) {
                    $photo = null; $tmp = ofp_upload('equip_photo_' . $iid, false, '');
                    if ($tmp) { list($fn, $err) = dp_save_image($tmp, 'fx'); if ($fn) $photo = $fn; }
                    $jid = ofp_fix_create($pdo, $o, $z, $i['name'] . ' is broken (' . $z['name'] . ' · ' . $o['name'] . ')', 'Found broken in the daily check.', $photo, 'pleq:' . $iid . ':' . date('ymd', $now), $emp, $by);
                    if ($jid) $fixes[] = ['job_id' => $jid, 'title' => $i['name'] . ' is broken (' . $z['name'] . ' · ' . $o['name'] . ')'];
                }
            } else {   // working again: an open job that THIS check made is left for the checker to close, we only stop making new ones
            }
        }
        $log[$iid] = ['item_id' => $iid, 'kind' => $kind, 'val' => $val, 'state' => $state];
    }
    // ---- is everything answered?
    $required = 0; $missing = [];
    foreach ($g['count'] as $i) { $required++; if (!isset($log[(int)$i['id']])) $missing[] = ['kind' => 'count', 'item_id' => (int)$i['id'], 'name' => $i['name']]; }
    foreach ($g['daily'] as $i) { $required++; if (!isset($log[(int)$i['id']])) $missing[] = ['kind' => 'refill', 'item_id' => (int)$i['id'], 'name' => $i['name']]; }
    foreach ($g['once'] as $i) { $required++; if (!isset($log[(int)$i['id']])) $missing[] = ['kind' => 'once', 'item_id' => (int)$i['id'], 'name' => $i['name']]; }
    foreach ($g['equip'] as $i) { $required++; if (!isset($log[(int)$i['id']])) $missing[] = ['kind' => 'equipment', 'item_id' => (int)$i['id'], 'name' => $i['name']]; }
    $answered = $required - count($missing); $done = !$missing; $late = $t['status'] === 'missed';
    $summary = ['needs' => $needs, 'fixes' => $fixes, 'at' => bd_now()];
    $pdo->prepare("INSERT INTO of_pl_checks (zone_id, day, task_id, emp_id, status, answered, required, summary_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE task_id = VALUES(task_id), emp_id = VALUES(emp_id), status = VALUES(status), answered = VALUES(answered), required = VALUES(required), summary_json = VALUES(summary_json), updated_at = VALUES(updated_at)")
        ->execute([(int)$z['id'], $day, (int)$t['id'], $emp ?: null, $done ? 'done' : 'partial', $answered, $required, ofp_json($summary), bd_now(), bd_now()]);
    if ($done) {
        $problems = count($needs) + count($fixes);
        $pdo->prepare("UPDATE bd_tasks SET status = 'done', done_at = ?, proof_value = ?, proof_note = ? WHERE id = ?")->execute([bd_now(), $problems, $late ? 'done late' : 'count and refill form', (int)$t['id']]);
        bd_log('done', (int)$t['id'], $t['title'] . ($late ? ' (late)' : '') . ' - ' . count($needs) . ' to buy, ' . count($fixes) . ' to fix', 'employee');
    }
    if (function_exists('dp_su_sync_items') && $needs) { try { dp_su_sync_items($pdo, $now); } catch (Throwable $e) { error_log('ofp sync_items: ' . $e->getMessage()); } }
    $msg = $done ? 'Done. Thank you.' . ($needs ? ' ' . count($needs) . ' thing' . (count($needs) === 1 ? ' goes' : 's go') . ' to the buying list.' : '') . ($fixes ? ' ' . count($fixes) . ' to the repair list.' : '') : 'Saved. ' . count($missing) . ' line' . (count($missing) === 1 ? '' : 's') . ' still to answer.';
    return ['ok' => true, 'done' => $done, 'late' => $done && $late, 'answered' => $answered, 'required' => $required, 'missing' => $missing, 'needs' => $needs, 'fixes' => $fixes, 'message' => $msg];
}

/* ============================== switch on / off / delete ============================== */
function ofp_blockers(PDO $pdo, array $zone) {
    $b = []; $c = $pdo->prepare("SELECT COUNT(*) FROM of_points WHERE zone_id = ?"); $c->execute([(int)$zone['id']]);
    if (!(int)$c->fetchColumn()) $b[] = 'Add at least one cleaning photo.';
    return $b;
}
function ofp_switch_on(PDO $pdo, array $o, array $zone, $emps, $by) {
    $blockers = ofp_blockers($pdo, $zone); if ($blockers) json_response(['error' => $blockers[0]], 422);
    $sec = ofp_section_ensure($pdo, $o); $o = ofp_office($pdo, (int)$o['id']);
    $pdo->prepare("UPDATE of_zones SET status = 'approved' WHERE id = ?")->execute([(int)$zone['id']]);
    $pdo->prepare("UPDATE of_pl_places SET state = 'on', switched_on_at = COALESCE(switched_on_at, ?), updated_at = ? WHERE zone_id = ?")->execute([bd_now(), bd_now(), (int)$zone['id']]);
    $zone = ofp_zone($pdo, $zone['id']); $pl = ofp_place($pdo, $zone['id']); $seatName = null;
    if (is_array($emps) && $emps) { list($ok, $m) = ofp_set_owner($pdo, $o, $zone, $emps, $by); if (!$ok) json_response(['error' => $m], 422); $pl = ofp_place($pdo, $zone['id']); $seatName = $pl['owner_seat_id'] ? (bd_seat_row($pdo, (int)$pl['owner_seat_id'])['code'] ?? null) : null; }
    elseif ($pl['owner_seat_id']) { $sr = bd_seat_row($pdo, (int)$pl['owner_seat_id']); $seatName = $sr ? $sr['code'] : null; }
    $live = ofp_sync_zone($pdo, $o, $zone, $pl, $by, true);
    try { bd_generate($pdo, bd_today()); } catch (Throwable $e) { error_log('ofp bd_generate: ' . $e->getMessage()); }
    $pc = $pdo->prepare("SELECT COUNT(*) FROM bd_duties WHERE lib_key LIKE ? AND status = 'live'"); $pc->execute(['of:' . (int)$zone['id'] . ':p%']); $photoDuties = (int)$pc->fetchColumn();
    $cc = $pdo->prepare("SELECT COUNT(*) FROM bd_duties WHERE lib_key = ? AND status = 'live'"); $cc->execute(['ofc:' . (int)$zone['id']]); $countDuty = (int)$cc->fetchColumn();
    $owner = ofp_owner_view($pdo, $o, $pl); $warn = [];
    if ($owner['using'] === 'none') $warn[] = 'Nobody does the daily check yet - choose an owner or put people on the office cleaner seat.';
    if (bd_mode() === 'shadow') $warn[] = 'The Boss Engine is in test mode: staff do not see the tasks yet.';
    $says = [];
    $says[] = $sec['created'] ? 'made the Boss Engine section "' . $o['name'] . '" with its boss, checker and team seats' : 'used the existing Boss Engine section "' . $o['name'] . '"';
    if ($seatName && $owner['using'] === 'place') $says[] = 'made the seat "' . $seatName . '" for the place owner';
    $says[] = $photoDuties . ' photo duty' . ($photoDuties === 1 ? '' : ' duties') . ' "Clean: ' . $zone['name'] . ' · ' . $o['name'] . '"';
    if ($countDuty) $says[] = 'one duty "Count and refill: ' . $zone['name'] . ' · ' . $o['name'] . '" (' . $pl['check_by'] . ')';
    else $says[] = 'no count and refill duty yet (no counting items, refills or equipment saved)';
    $msg = ofp_label_of($zone, $o) . ' is ON. I ' . implode('; ', $says) . '. No penalty was made.';
    return ['ok' => true, 'place' => ofp_place_view($pdo, $o, $zone, $pl), 'created' => ['section' => ['id' => $sec['id'], 'created' => $sec['created'], 'seats' => $sec['seats']], 'seat' => $seatName, 'photo_duties' => $photoDuties, 'count_duty' => $countDuty], 'message' => $msg, 'warnings' => $warn];
}

/** a place is deleted: both kinds of duties are retired, today's open tasks are excused, its items are unlinked (ours switched off), the owner seat is closed. */
function ofp_remove_place(PDO $pdo, $zid, $by, $deleteZone) {
    $zid = (int)$zid; $pl = ofp_place($pdo, $zid); $z = ofp_zone($pdo, $zid);
    $pdo->prepare("UPDATE bd_duties SET status = 'retired' WHERE (lib_key LIKE ? OR lib_key = ?) AND status != 'retired'")->execute(['of:' . $zid . ':%', 'ofc:' . $zid]);
    $pdo->prepare("UPDATE bd_tasks SET status = 'excused' WHERE status IN ('open','unowned') AND task_date >= ? AND duty_id IN (SELECT id FROM bd_duties WHERE lib_key LIKE ? OR lib_key = ?)")->execute([bd_today(), 'of:' . $zid . ':%', 'ofc:' . $zid]);
    foreach ($pdo->query("SELECT * FROM dp_su_items WHERE zone_id = " . $zid)->fetchAll() as $i) ofp_unlink_item($pdo, $i);
    if ($pl) { $seat = ofp_place_seat_id($pdo, $pl); if ($seat) { bd_set_holders($pdo, $seat, []); $pdo->prepare("UPDATE bd_seats SET active = 0 WHERE id = ?")->execute([$seat]); } }
    $q = $pdo->prepare("SELECT ref_photo FROM of_points WHERE zone_id = ?"); $q->execute([$zid]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $f) if ($f) @unlink(bd_dir() . basename($f));
    if ($deleteZone) { $pdo->prepare("DELETE FROM of_points WHERE zone_id = ?")->execute([$zid]); $pdo->prepare("DELETE FROM of_zones WHERE id = ?")->execute([$zid]); }
    $pdo->prepare("DELETE FROM of_pl_places WHERE zone_id = ?")->execute([$zid]); $pdo->prepare("DELETE FROM of_pl_log WHERE zone_id = ?")->execute([$zid]); $pdo->prepare("DELETE FROM of_pl_checks WHERE zone_id = ?")->execute([$zid]);
    $pdo->prepare("DELETE FROM of_settings WHERE skey IN (?, ?)")->execute(['plprop:' . $zid, 'plsig:' . $zid]); ofp_cq_forget($pdo, $zid);
    bd_log('office', $zid, 'place deleted: ' . ($z ? $z['name'] : '#' . $zid), $by);
}
/** the old zone editor sends the whole checkpoint list again: for a place the points are MERGED by text so the reference photos and must-have lists stay. True = handled. */
function ofp_zone_save_points(PDO $pdo, $zid, array $pts) {
    if (!ofp_place($pdo, $zid)) return false;
    $st = $pdo->prepare("SELECT * FROM of_points WHERE zone_id = ? ORDER BY sort_no, id"); $st->execute([(int)$zid]); $have = $st->fetchAll(); $used = []; $i = 0;
    foreach ($pts as $p) {
        $hit = null; foreach ($have as $h) { if (!isset($used[$h['id']]) && mb_strtolower(trim($h['text'])) === mb_strtolower(trim($p[0]))) { $hit = $h; break; } }
        if ($hit) { $used[$hit['id']] = 1; $pdo->prepare("UPDATE of_points SET text_hi = ?, sort_no = ? WHERE id = ?")->execute([$p[1] !== '' ? $p[1] : $hit['text_hi'], ++$i, (int)$hit['id']]); }
        else $pdo->prepare("INSERT INTO of_points (zone_id, text, text_hi, sort_no) VALUES (?,?,?,?)")->execute([(int)$zid, $p[0], $p[1] !== '' ? $p[1] : null, ++$i]);
    }
    foreach ($have as $h) if (!isset($used[$h['id']])) { $pdo->prepare("DELETE FROM of_points WHERE id = ?")->execute([(int)$h['id']]); if (!empty($h['ref_photo'])) @unlink(bd_dir() . basename($h['ref_photo'])); }
    return true;
}
/** office.php deleted a zone (old screen): if it was a place, clean up after it */
function ofp_zone_deleted(PDO $pdo, $zid, $by) { if (ofp_place($pdo, $zid)) ofp_remove_place($pdo, $zid, $by, false); }

/* ============================== the router: of.pl_* ============================== */
/** office.php runs before dept.php is loaded (check.php order): load the Departments files once, with the globals they need, so dp_* helpers (images, supplies, repairs) exist for of.pl_* actions */
function ofp_load_dept() {
    if (function_exists('dp_su_ensure') && function_exists('dp_fx_ensure') && function_exists('dp_save_image')) return true;
    global $pdo, $action;
    if (!isset($pdo, $action) || !is_file(__DIR__ . '/dept.php')) return false;
    try { require_once __DIR__ . '/dept.php'; } catch (Throwable $e) { error_log('places: dept.php: ' . $e->getMessage()); }
    return function_exists('dp_su_ensure');
}
function ofp_dispatch(PDO $pdo, $action) {
    if (strpos((string)$action, 'of.pl_') !== 0) return;
    try {
        ofp_load_dept(); bd_ensure($pdo); ofp_ensure($pdo);
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $auth = require_auth(); $w = bd_who($pdo, $auth); $by = (string)($auth['username'] ?? 'user'); $emp = (int)$w['emp'];
        $isAdmin = ofp_is_admin($auth);
        $in = []; if ($isPost) { $in = !empty($_POST) ? $_POST : json_input(); if (!is_array($in)) $in = []; }
        $needPost = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
        $needAdmin = function () use ($isAdmin) { if (!$isAdmin) json_response(['error' => 'Only Admin or HR can do this.'], 403); };
        $needMgr = function () use ($w) { if (!$w['manager']) json_response(['error' => 'The Boss Engine management pages are for Admin, HR and section bosses.'], 403); };
        $officeFor = function ($id, $mgr = true) use ($pdo, $w) { $o = ofp_office($pdo, (int)$id); if (!$o) json_response(['error' => 'Office not found.'], 404); if ($mgr && !(!empty($w['admin']) || !empty($w['top']) || ((int)$o['section_id'] && bd_can_section($w, (int)$o['section_id'])))) json_response(['error' => 'This office belongs to another team.'], 403); return $o; };
        $placeFor = function ($zid, $mgr = true) use ($pdo, $officeFor) { $z = ofp_zone($pdo, (int)$zid); $pl = $z ? ofp_place($pdo, (int)$zid) : null; if (!$z || !$pl) json_response(['error' => 'Place not found.'], 404); $o = $officeFor($pl['office_id'], $mgr); return [$z, $pl, $o]; };

        switch ($action) {

        /* ---------------- employee: My Day ---------------- */
        case 'of.pl_my_day': {
            if (!$emp) json_response(['error' => 'This login is not linked to an employee.'], 400);
            $lang = ofp_lang($pdo, $emp); $mode = bd_mode(); $day = bd_today();
            if ($mode === 'shadow' && !$isAdmin) json_response(['mode' => $mode, 'lang' => $lang, 'places' => []]);
            $st = $pdo->prepare("SELECT t.id, t.status, t.due_at, d.lib_key, d.title FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE t.owner_emp = ? AND t.task_date = ? AND t.status != 'excused' AND (d.lib_key LIKE 'ofc:%' OR d.lib_key LIKE 'of:%:p%') ORDER BY t.due_at, t.id");
            $st->execute([$emp, $day]); $zs = [];
            foreach ($st->fetchAll() as $r) { $zid = (int)(strpos($r['lib_key'], 'ofc:') === 0 ? substr($r['lib_key'], 4) : explode(':', $r['lib_key'])[1]); $zs[$zid][] = $r; }
            $places = [];
            foreach ($zs as $zid => $rows) {
                $z = ofp_zone($pdo, $zid); $pl = $z ? ofp_place($pdo, $zid) : null; $o = $pl ? ofp_office($pdo, (int)$pl['office_id']) : null; if (!$z || !$pl || !$o) continue;
                $check = null; $hide = []; foreach ($rows as $r) { $hide[] = (int)$r['id']; if ($r['lib_key'] === 'ofc:' . $zid) $check = $r; }
                $cards = ofp_photo_cards($pdo, $z, $day); $cards = array_values(array_filter($cards, function ($c) use ($hide) { return $c['task_id'] && in_array($c['task_id'], $hide, true); }));
                $ck = $pdo->prepare("SELECT status, answered, required FROM of_pl_checks WHERE zone_id = ? AND day = ?"); $ck->execute([$zid, $day]); $ck = $ck->fetch();
                $g = ofp_zone_items($pdo, $zid);
                $state = 'todo'; if ($check) { if ($check['status'] === 'done') $state = 'done'; elseif ($check['status'] === 'missed') $state = 'late'; elseif ($ck && $ck['status'] === 'partial') $state = 'partial'; }
                $places[] = ['zone_id' => $zid, 'name' => $z['name'], 'name_hi' => (string)($z['name_hi'] ?? ''), 'office' => $o['name'], 'state' => $state, 'due_at' => $check ? $check['due_at'] : null, 'check_task_id' => $check ? (int)$check['id'] : null, 'photo_tasks' => $cards,
                    'summary' => ['counts' => count($g['count']), 'daily' => count($g['daily']), 'once' => count($g['once']), 'equipment' => count($g['equip']), 'answered' => $ck ? (int)$ck['answered'] : 0], 'hide_task_ids' => $hide];
            }
            json_response(['mode' => $mode, 'lang' => $lang, 'places' => $places]);
        }
        case 'of.pl_form': {
            if (!$emp && !$isAdmin) json_response(['error' => 'This login is not linked to an employee.'], 400);
            $tid = (int)($_GET['task_id'] ?? 0);
            if (!$tid && (int)($_GET['zone_id'] ?? 0)) { $s = $pdo->prepare("SELECT t.id FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE d.lib_key = ? AND t.task_date = ? AND t.status != 'excused' ORDER BY t.id LIMIT 1"); $s->execute(['ofc:' . (int)$_GET['zone_id'], bd_today()]); $tid = (int)$s->fetchColumn(); }
            if (!$tid) json_response(['error' => 'There is no count task for this place today.'], 404);
            list($t, $z, $o, $pl) = ofp_task_ctx($pdo, $tid);
            if (!ofp_may_open_task($auth, $w, $t)) json_response(['error' => 'This check belongs to ' . ($t['owner_emp'] ? bd_emp_name($pdo, $t['owner_emp']) : 'someone else') . '.'], 403);
            json_response(ofp_form($pdo, $t, $z, $o, $pl, ofp_lang($pdo, $emp)));
        }
        case 'of.pl_check_submit': {
            $needPost();
            if (!$emp && !$isAdmin) json_response(['error' => 'This login is not linked to an employee.'], 400);
            list($t, $z, $o, $pl) = ofp_task_ctx($pdo, (int)($in['task_id'] ?? 0));
            if (!ofp_may_open_task($auth, $w, $t)) json_response(['error' => 'This check belongs to ' . ($t['owner_emp'] ? bd_emp_name($pdo, $t['owner_emp']) : 'someone else') . '.'], 403);
            if (bd_mode() === 'shadow' && !$isAdmin) json_response(['error' => 'The Boss Engine is in test mode. Duties are not live yet.'], 409);
            if ($t['task_date'] !== bd_today()) json_response(['error' => 'Only today\'s check can be done.'], 409);
            if ($t['status'] === 'done') json_response(['error' => 'This check is already done.'], 409);
            if (!in_array($t['status'], ['open', 'missed'], true)) json_response(['error' => 'This check cannot be done now (' . $t['status'] . ').'], 409);
            json_response(ofp_submit($pdo, $auth, $w, $t, $z, $o, $pl, $in, $by));
        }
        case 'of.pl_lang': {
            $needPost(); if (!$emp) json_response(['error' => 'This login is not linked to an employee.'], 400);
            $l = (string)($in['lang'] ?? ''); if (!in_array($l, ['both', 'en', 'hi'], true)) json_response(['error' => 'Choose both, en or hi.'], 422);
            of_put('lang:' . $emp, $l); json_response(['ok' => true, 'lang' => $l]);
        }
        case 'of.pl_photo_get': {
            $pt = $pdo->prepare("SELECT * FROM of_points WHERE id = ?"); $pt->execute([(int)($_GET['point_id'] ?? 0)]); $pt = $pt->fetch();
            if (!$pt || empty($pt['ref_photo'])) json_response(['error' => 'No photo'], 404);
            $zid = (int)$pt['zone_id']; $ok = $isAdmin;
            if (!$ok) { $pl = ofp_place($pdo, $zid); if ($pl && $w['manager']) { $o = ofp_office($pdo, (int)$pl['office_id']); $ok = $o && (!empty($w['top']) || ((int)$o['section_id'] && bd_can_section($w, (int)$o['section_id']))); } }
            if (!$ok && $emp) { $q = $pdo->prepare("SELECT 1 FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE t.owner_emp = ? AND t.task_date >= ? AND (d.lib_key LIKE ? OR d.lib_key = ?) LIMIT 1"); $q->execute([$emp, date('Y-m-d', strtotime('-3 days')), 'of:' . $zid . ':p%', 'ofc:' . $zid]); $ok = (bool)$q->fetchColumn(); }
            if (!$ok) json_response(['error' => 'Not found'], 404);
            $f = bd_dir() . basename((string)$pt['ref_photo']); if (!is_file($f)) json_response(['error' => 'No photo'], 404);
            header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=3600'); header('Content-Length: ' . filesize($f)); readfile($f); exit;
        }
        }

        /* ---------------- managers from here ---------------- */
        $needMgr();
        switch ($action) {

        case 'of.pl_offices': {
            $offices = [];
            foreach ($pdo->query("SELECT * FROM of_offices WHERE active = 1 ORDER BY name")->fetchAll() as $o) {
                if (!(!empty($w['admin']) || !empty($w['top']) || ((int)$o['section_id'] && bd_can_section($w, (int)$o['section_id'])))) continue;
                $sec = (int)$o['section_id'] ? ofp_section_row($pdo, $o['section_id']) : null;
                $c = $pdo->prepare("SELECT COUNT(*) FROM of_pl_places WHERE office_id = ?"); $c->execute([(int)$o['id']]);
                $offices[] = ['office_id' => (int)$o['id'], 'name' => $o['name'], 'section_id' => $sec ? (int)$sec['id'] : null, 'has_section' => (bool)$sec, 'places' => (int)$c->fetchColumn()];
            }
            $sp = [];
            if ($isAdmin && function_exists('dp_su_ensure')) { foreach ($pdo->query("SELECT code, name, office_id FROM dp_su_places WHERE active = 1 AND (office_id IS NULL OR office_id = 0) ORDER BY sort_no, id")->fetchAll() as $p) $sp[] = ['code' => $p['code'], 'name' => $p['name'], 'office_id' => null]; }
            json_response(['offices' => $offices, 'supply_places' => $sp]);
        }
        case 'of.pl_list': {
            $oid = (int)($_GET['office_id'] ?? 0); $places = []; $office = null;
            if ($oid) { $o = $officeFor($oid); $office = ['id' => (int)$o['id'], 'name' => $o['name'], 'section_id' => (int)$o['section_id'] ?: null]; $rows = $pdo->prepare("SELECT * FROM of_pl_places WHERE office_id = ? ORDER BY zone_id LIMIT 60"); $rows->execute([$oid]); $rows = $rows->fetchAll(); }
            else $rows = $pdo->query("SELECT * FROM of_pl_places ORDER BY office_id, zone_id LIMIT 120")->fetchAll();
            foreach ($rows as $pl) {
                $o = ofp_office($pdo, (int)$pl['office_id']); $z = ofp_zone($pdo, $pl['zone_id']); if (!$o || !$z) continue;
                if (!$oid && !(!empty($w['admin']) || !empty($w['top']) || ((int)$o['section_id'] && bd_can_section($w, (int)$o['section_id'])))) continue;
                $places[] = ofp_place_view($pdo, $o, $z, $pl);
            }
            json_response(['places' => $places, 'office' => $office]);
        }
        case 'of.pl_get': {
            list($z, $pl, $o) = $placeFor($_GET['zone_id'] ?? 0);
            $g = ofp_zone_items($pdo, $z['id']);
            $view = function ($list) { return array_map('ofp_item_view', $list); };
            $fixes = []; try { $q = $pdo->prepare("SELECT id, title, status, before_photo FROM dp_fx_jobs WHERE zone_id = ? AND src_key LIKE ? ORDER BY id DESC LIMIT 30"); $q->execute([(int)$z['id'], 'plfix:' . (int)$z['id'] . ':%']); foreach ($q->fetchAll() as $r) $fixes[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'status' => $r['status'], 'has_photo' => !empty($r['before_photo'])]; } catch (Throwable $e) {}
            $buys = []; $q = $pdo->prepare("SELECT n.id nid, n.qty, n.status, i.id iid, i.name, i.name_hi, i.unit FROM dp_su_needs n JOIN dp_su_items i ON i.id = n.item_id WHERE i.zone_id = ? AND n.reason = 'new' AND n.status = 'open' ORDER BY n.id"); $q->execute([(int)$z['id']]);
            foreach ($q->fetchAll() as $r) $buys[] = ['need_id' => (int)$r['nid'], 'item_id' => (int)$r['iid'], 'title' => $r['name'], 'title_hi' => (string)($r['name_hi'] ?? ''), 'qty' => (float)$r['qty'], 'unit' => $r['unit'], 'status' => $r['status']];
            $draft = json_decode((string)$pl['draft_json'], true); $draft = is_array($draft) ? $draft : [];
            $load = []; foreach ($pdo->query("SELECT owner_emp, COUNT(*) c FROM bd_tasks WHERE task_date = '" . bd_today() . "' AND status IN ('open','done') AND owner_emp IS NOT NULL GROUP BY owner_emp")->fetchAll() as $r) $load[(int)$r['owner_emp']] = (int)$r['c'];
            $present = []; try { foreach ($pdo->query("SELECT employee_id FROM attendance WHERE punch_date = '" . bd_today() . "' AND punch_in IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $e) $present[(int)$e] = 1; } catch (Throwable $e) {}
            $emps = array_map(function ($e) use ($load, $present) { return ['id' => (int)$e['id'], 'name' => $e['name'], 'load' => $load[(int)$e['id']] ?? 0, 'present' => isset($present[(int)$e['id']])]; }, bd_employees($pdo));
            $props = json_decode(of_cfg('plprop:' . (int)$z['id'], ''), true); $qs = [];
            try { $cq = $pdo->prepare("SELECT sig, title FROM cq_q WHERE status = 'open' AND sig IN (?, ?)"); $cq->execute(['ofp:owner:' . (int)$z['id'], 'ofp:must:' . (int)$z['id']]); foreach ($cq->fetchAll() as $r) $qs[] = ['sig' => $r['sig'], 'title' => $r['title']]; } catch (Throwable $e) {}
            $bl = ofp_blockers($pdo, $z);
            json_response(['place' => ofp_place_view($pdo, $o, $z, $pl), 'photos' => ofp_photos_view($pdo, $z), 'counts' => $view($g['count']), 'daily' => $view($g['daily']), 'once' => $view($g['once']), 'equipment' => $view($g['equip']), 'fixes' => $fixes, 'buys' => $buys, 'draft' => $draft, 'employees' => $emps,
                'claude' => ['questions' => $qs, 'proposals' => is_array($props) ? $props : (object)[]], 'can_switch_on' => !$bl, 'switch_blockers' => $bl, 'admin' => $isAdmin, 'mode' => bd_mode(), 'lang' => ofp_lang($pdo, $emp)]);
        }
        case 'of.pl_today': {
            list($z, $pl, $o) = $placeFor($_GET['zone_id'] ?? 0); $day = bd_today();
            $ck = $pdo->prepare("SELECT * FROM of_pl_checks WHERE zone_id = ? AND day = ?"); $ck->execute([(int)$z['id'], $day]); $ck = $ck->fetch();
            $rows = []; $q = $pdo->prepare("SELECT l.*, i.name, i.min_qty, i.fill_to, i.pgroup, i.refill FROM of_pl_log l JOIN dp_su_items i ON i.id = l.item_id WHERE l.zone_id = ? AND l.day = ? ORDER BY l.id"); $q->execute([(int)$z['id'], $day]);
            foreach ($q->fetchAll() as $r) {
                $row = ['item_id' => (int)$r['item_id'], 'name' => $r['name'], 'group' => $r['kind'] === 'equip' ? 'equip' : ($r['kind'] === 'refill' ? 'daily' : 'count')];
                if ($r['kind'] === 'count') { $row['value'] = (float)$r['val']; $row['min'] = (float)$r['min_qty']; $row['below'] = (float)$r['val'] < (float)$r['min_qty']; if ($row['below']) $row['need_qty'] = ofp_need_qty($r, $r['val']); }
                else $row['state'] = $r['state'];
                $rows[] = $row;
            }
            $needs = []; $q = $pdo->prepare("SELECT n.id, n.qty, n.reason, n.status, i.name FROM dp_su_needs n JOIN dp_su_items i ON i.id = n.item_id WHERE i.zone_id = ? AND (n.status = 'open' OR n.need_date = ?) ORDER BY n.id DESC LIMIT 40"); $q->execute([(int)$z['id'], $day]);
            foreach ($q->fetchAll() as $r) $needs[] = ['need_id' => (int)$r['id'], 'item' => $r['name'], 'qty' => (float)$r['qty'], 'reason' => $r['reason'], 'status' => $r['status']];
            $fixes = []; try { $q = $pdo->prepare("SELECT id, title, status FROM dp_fx_jobs WHERE zone_id = ? AND status IN ('reported','given','doing') ORDER BY id DESC LIMIT 20"); $q->execute([(int)$z['id']]); foreach ($q->fetchAll() as $r) $fixes[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'status' => $r['status']]; } catch (Throwable $e) {}
            json_response(['place' => ofp_place_view($pdo, $o, $z, $pl), 'day' => $day, 'submitted' => $ck && $ck['status'] === 'done', 'by' => $ck && $ck['emp_id'] ? bd_emp_name($pdo, $ck['emp_id']) : null, 'at' => $ck ? $ck['updated_at'] : null, 'rows' => $rows, 'needs' => $needs, 'fixes' => $fixes]);
        }
        case 'of.pl_owner_info': {
            list($z, $pl, $o) = $placeFor($_GET['zone_id'] ?? 0);
            $q = false; try { $c = $pdo->prepare("SELECT 1 FROM cq_q WHERE status = 'open' AND sig = ?"); $c->execute(['ofp:owner:' . (int)$z['id']]); $q = (bool)$c->fetchColumn(); } catch (Throwable $e) {}
            json_response(['owner' => ofp_owner_view($pdo, $o, $pl), 'suggest' => ofp_suggest_owners($pdo, $o, 3), 'question_open' => $q]);
        }
        case 'of.pl_settings': {
            if ($isPost) { $needAdmin(); if (isset($in['check_by'])) of_put('pl_check_by', ofp_time($in['check_by'], '12:00')); if (isset($in['photo_time'])) of_put('pl_photo_time', ofp_time($in['photo_time'], '11:00')); }
            json_response(['check_by' => of_cfg('pl_check_by', '12:00'), 'photo_time' => of_cfg('pl_photo_time', '11:00')] + ($isPost ? ['ok' => true] : []));
        }
        }

        /* ---------------- Admin / HR only ---------------- */
        if (!in_array($action, ['of.pl_parse', 'of.pl_step', 'of.pl_photo', 'of.pl_photo_del', 'of.pl_fix_add', 'of.pl_suggest', 'of.pl_on', 'of.pl_off', 'of.pl_delete', 'of.pl_owner', 'of.pl_seed_store'], true)) json_response(['error' => 'Unknown action.'], 404);
        $needPost(); $needAdmin();

        switch ($action) {

        case 'of.pl_seed_store': {      // the Store's cleaning places as DRAFTS: nothing runs until the owner adds the reference photos and switches each place ON
            $o = $officeFor((int)($in['office_id'] ?? 0)); $made = []; $skipped = []; $errors = []; $removed = [];
            ofp_section_ensure($pdo, $o); $sp = ofp_supply_place($pdo, $o);
            // the first version of this setup made 4 draft places: drop those that were never switched on, so only the new two remain
            foreach (['Shop inside', 'Outside the shop', 'Coffee bar and pantry', 'Closing: coffee bar and stock'] as $old) {
                $q = $pdo->prepare("SELECT z.id FROM of_zones z JOIN of_pl_places pl ON pl.zone_id = z.id WHERE z.office_id = ? AND z.name = ? AND pl.state = 'draft' AND z.note = 'Registered as a place' AND NOT EXISTS (SELECT 1 FROM of_points p WHERE p.zone_id = z.id AND p.ref_photo IS NOT NULL)");
                $q->execute([(int)$o['id'], $old]); $oz = (int)$q->fetchColumn();
                if ($oz) { $pdo->prepare("DELETE FROM of_points WHERE zone_id = ?")->execute([$oz]); $pdo->prepare("DELETE FROM of_pl_places WHERE zone_id = ?")->execute([$oz]); try { $pdo->prepare("DELETE FROM dp_su_items WHERE zone_id = ? AND source = 'place'")->execute([$oz]); } catch (Throwable $e) { } $pdo->prepare("DELETE FROM of_zones WHERE id = ?")->execute([$oz]); $removed[] = $old; }
            }
            $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_no),0) FROM of_zones WHERE office_id = " . (int)$o['id'])->fetchColumn();
            foreach (ofp_store_defs() as $d) {
                $dup = $pdo->prepare("SELECT 1 FROM of_zones WHERE office_id = ? AND LOWER(name) = LOWER(?)"); $dup->execute([(int)$o['id'], $d['name']]);
                if ($dup->fetchColumn()) { $skipped[] = $d['name']; continue; }
                $pdo->prepare("INSERT INTO of_zones (office_id, name, name_hi, times, status, source, note, sort_no, created_at) VALUES (?,?,?,?, 'proposed', 'manual', ?,?,?)")->execute([(int)$o['id'], $d['name'], $d['name_hi'], $d['photo_time'], 'Registered as a place', ++$maxSort, bd_now()]);
                $zid = (int)$pdo->lastInsertId(); $steps = $d['counts'] ? '1,2,3' : '1,2';
                $pdo->prepare("INSERT INTO of_pl_places (zone_id, office_id, state, step, steps_done, check_by, photo_time, created_by, created_at, updated_at) VALUES (?,?, 'draft', ?, ?, ?,?,?,?,?)")->execute([$zid, (int)$o['id'], $d['counts'] ? 3 : 2, $steps, $d['check_by'], $d['photo_time'], $by, bd_now(), bd_now()]);
                $i = 0; foreach ($d['points'] as $pt) { $pdo->prepare("INSERT INTO of_points (zone_id, text, text_hi, sort_no, ref_photo, must_json) VALUES (?,?,?,?,NULL,?)")->execute([$zid, ofp_trim($pt[0], 200), ofp_trim($pt[1], 240), ++$i, ofp_json([])]); }
                if ($d['counts']) { $z = ofp_zone($pdo, $zid); list($l, $err) = ofp_items_save($pdo, $sp, $z, 'count', $d['counts'], $by); if ($err) $errors[] = $d['name'] . ': ' . $err; }
                $made[] = $d['name'];
            }
            json_response(['ok' => true, 'made' => $made, 'skipped' => $skipped, 'removed' => $removed, 'errors' => $errors, 'message' => count($made) . ' places made (not switched on).' . ($removed ? ' Old drafts removed: ' . implode(', ', $removed) . '.' : '') . ($skipped ? ' Already there: ' . implode(', ', $skipped) . '.' : '') . ($errors ? ' Problems: ' . implode(' | ', $errors) : '')]);
        }
        case 'of.pl_parse': {
            $must = ofp_parse_must((string)($in['text'] ?? '')); json_response(['must' => $must, 'count' => count($must)]);
        }
        case 'of.pl_step': {
            $GLOBALS['__ofp_converted'] = []; $GLOBALS['__ofp_sec_made'] = null; $secInfo = null; $supMade = false;
            $step = (int)($in['step'] ?? 0); if ($step < 1 || $step > 9) json_response(['error' => 'Step must be 1 to 9.'], 422);
            $data = is_array($in['data'] ?? null) ? $in['data'] : [];
            $zid = (int)($in['zone_id'] ?? 0);
            if (!$zid) {
                if ($step !== 1) json_response(['error' => 'Start with step 1: name the place.'], 422);
                $name = ofp_trim($data['name'] ?? '', 120); if ($name === '') json_response(['error' => 'Give the place a name.'], 422);
                if (!empty($in['supply_code']) || !empty($data['supply_code'])) { $sc = (string)(!empty($in['supply_code']) ? $in['supply_code'] : $data['supply_code']); $before = function_exists('dp_su_place') ? dp_su_place($pdo, $sc) : null; $o = ofp_office_for_supply($pdo, $sc, $by); if (!$o) json_response(['error' => 'Unknown supplies place.'], 404); $supMade = $before && empty($before['office_id']); }
                else { $o = $officeFor((int)($in['office_id'] ?? 0)); }
                $dup = $pdo->prepare("SELECT 1 FROM of_zones WHERE office_id = ? AND LOWER(name) = LOWER(?)"); $dup->execute([(int)$o['id'], $name]); if ($dup->fetchColumn()) json_response(['error' => 'This office already has a place called "' . $name . '".'], 409);
                $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_no),0) FROM of_zones WHERE office_id = " . (int)$o['id'])->fetchColumn();
                $pdo->prepare("INSERT INTO of_zones (office_id, name, name_hi, times, status, source, note, sort_no, created_at) VALUES (?,?,?,?, 'proposed', 'manual', ?,?,?)")->execute([(int)$o['id'], $name, ofp_trim($data['name_hi'] ?? '', 160) ?: null, ofp_time(of_cfg('pl_photo_time', '11:00'), '11:00'), 'Registered as a place', $maxSort + 1, bd_now()]);
                $zid = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO of_pl_places (zone_id, office_id, state, step, steps_done, check_by, photo_time, created_by, created_at, updated_at) VALUES (?,?, 'draft', 1, '', ?,?,?,?,?)")->execute([$zid, (int)$o['id'], ofp_time(of_cfg('pl_check_by', '12:00'), '12:00'), ofp_time(of_cfg('pl_photo_time', '11:00'), '11:00'), $by, bd_now(), bd_now()]);
                $secInfo = ofp_section_ensure($pdo, $o);   // the office gets its Boss Engine section the first time a place is registered
                if (!empty($supMade)) { if (!empty($GLOBALS['__ofp_sec_made'])) $secInfo = $GLOBALS['__ofp_sec_made']; $secInfo['office_made'] = true; }
            } else {
                $z0 = ofp_zone($pdo, $zid); if (!$z0) json_response(['error' => 'Place not found.'], 404);
                $pl0 = ofp_place($pdo, $zid);
                if (!$pl0) {   // adopt an old zone of Offices as a place (draft); its old duties keep running until the place is switched on
                    $o0 = $officeFor((int)$z0['office_id']);
                    $pdo->prepare("INSERT INTO of_pl_places (zone_id, office_id, state, step, steps_done, check_by, photo_time, created_by, created_at, updated_at) VALUES (?,?, 'draft', 1, '', ?,?,?,?,?)")->execute([$zid, (int)$o0['id'], ofp_time(of_cfg('pl_check_by', '12:00'), '12:00'), ofp_time(of_cfg('pl_photo_time', '11:00'), '11:00'), $by, bd_now(), bd_now()]);
                }
            }
            list($z, $pl, $o) = $placeFor($zid); $linked = []; $err = null; $sp = ofp_supply_place($pdo, $o);
            switch ($step) {
            case 1: {
                $name = ofp_trim($data['name'] ?? $z['name'], 120); if ($name === '') json_response(['error' => 'Give the place a name.'], 422);
                $dup = $pdo->prepare("SELECT 1 FROM of_zones WHERE office_id = ? AND LOWER(name) = LOWER(?) AND id != ?"); $dup->execute([(int)$o['id'], $name, $zid]); if ($dup->fetchColumn()) json_response(['error' => 'This office already has a place called "' . $name . '".'], 409);
                $pdo->prepare("UPDATE of_zones SET name = ?, name_hi = ? WHERE id = ?")->execute([$name, ofp_trim($data['name_hi'] ?? '', 160) ?: null, $zid]); break;
            }
            case 2: { if (isset($data['photo_time'])) $pdo->prepare("UPDATE of_pl_places SET photo_time = ? WHERE zone_id = ?")->execute([ofp_time($data['photo_time'], $pl['photo_time']), $zid]); break; }
            case 3: {
                if (array_key_exists('counts', $data)) { list($l, $err) = ofp_items_save($pdo, $sp, $z, 'count', (array)$data['counts'], $by); $linked = array_merge($linked, $l); }
                if (!$err && array_key_exists('equipment', $data)) { list($l, $err) = ofp_items_save($pdo, $sp, $z, 'equip', (array)$data['equipment'], $by); $linked = array_merge($linked, $l); }
                break;
            }
            case 4: { if (array_key_exists('daily', $data)) { list($l, $err) = ofp_items_save($pdo, $sp, $z, 'daily', (array)$data['daily'], $by); $linked = array_merge($linked, $l); } break; }
            case 5: { if (array_key_exists('once', $data)) { list($l, $err) = ofp_items_save($pdo, $sp, $z, 'once', (array)$data['once'], $by); $linked = array_merge($linked, $l); } break; }
            case 6: break;
            case 7: {
                if (array_key_exists('buy', $data)) {
                    $list = []; foreach ((array)$data['buy'] as $b) { if (!is_array($b)) continue; $title = ofp_trim($b['title'] ?? ($b['name'] ?? ''), 100); if ($title === '') continue; $list[] = ['name' => $title, 'name_hi' => $b['title_hi'] ?? '', 'unit' => $b['unit'] ?? 'pc', 'qty' => $b['qty'] ?? 1]; }
                    // buy-only items: our own 'buy' items not in the list are switched off (their open "new" need is cancelled)
                    list($l, $err) = ofp_items_save($pdo, $sp, $z, 'buy', $list, $by);
                    if (!$err) {
                        $keepIds = [];
                        foreach ($list as $b) {
                            $st = $pdo->prepare("SELECT * FROM dp_su_items WHERE place_id = ? AND name = ? AND zone_id = ?"); $st->execute([(int)$sp['id'], $b['name'], $zid]); $it = $st->fetch(); if (!$it) continue;
                            $qty = max(0.01, round((float)$b['qty'], 2)); ofp_need_upsert($pdo, $it, 'new', $qty, time()); $keepIds[] = (int)$it['id'];
                        }
                        $q = $pdo->prepare("SELECT n.id, n.item_id FROM dp_su_needs n JOIN dp_su_items i ON i.id = n.item_id WHERE i.zone_id = ? AND n.reason = 'new' AND n.status = 'open'"); $q->execute([$zid]);
                        foreach ($q->fetchAll() as $n) if (!in_array((int)$n['item_id'], $keepIds, true)) $pdo->prepare("UPDATE dp_su_needs SET status = 'cancelled', closed_at = ? WHERE id = ?")->execute([bd_now(), (int)$n['id']]);
                        if (function_exists('dp_su_sync_items')) { try { dp_su_sync_items($pdo, time()); } catch (Throwable $e) {} }
                    }
                }
                break;
            }
            case 8: { if (array_key_exists('equipment', $data)) { list($l, $err) = ofp_items_save($pdo, $sp, $z, 'equip', (array)$data['equipment'], $by); $linked = array_merge($linked, $l); } break; }
            case 9: {
                if (isset($data['check_by'])) $pdo->prepare("UPDATE of_pl_places SET check_by = ? WHERE zone_id = ?")->execute([ofp_time($data['check_by'], $pl['check_by']), $zid]);
                if (isset($data['photo_time'])) $pdo->prepare("UPDATE of_pl_places SET photo_time = ? WHERE zone_id = ?")->execute([ofp_time($data['photo_time'], $pl['photo_time']), $zid]);
                if (array_key_exists('owner_emp_ids', $data)) { list($ok, $m) = ofp_set_owner($pdo, $o, $z, (array)$data['owner_emp_ids'], $by); if (!$ok) $err = $m; }
                break;
            }
            }
            if ($err) json_response(['error' => $err], strpos($err, 'another place') !== false ? 409 : 422);
            $pl = ofp_place($pdo, $zid); $dr = json_decode((string)$pl['draft_json'], true); $dr = is_array($dr) ? $dr : []; unset($data['supply_code']); $dr[(string)$step] = $data;
            $done = array_values(array_filter(explode(',', (string)$pl['steps_done']), 'strlen')); if (!in_array((string)$step, $done, true)) $done[] = (string)$step; sort($done, SORT_NUMERIC);
            $pdo->prepare("UPDATE of_pl_places SET step = GREATEST(step, ?), steps_done = ?, draft_json = ?, updated_at = ? WHERE zone_id = ?")->execute([$step, implode(',', $done), ofp_json($dr), bd_now(), $zid]);
            $z = ofp_zone($pdo, $zid); $pl = ofp_place($pdo, $zid);
            if (in_array($pl['state'], ['on', 'off'], true)) ofp_sync_zone($pdo, $o, $z, $pl, $by, true);   // a place that already runs follows every change at once
            json_response(['ok' => true, 'zone_id' => $zid, 'step' => $step, 'place' => ofp_place_view($pdo, $o, $z, $pl), 'linked' => $linked, 'converted' => array_values(array_unique($GLOBALS['__ofp_converted'] ?? [])), 'message' => 'Saved.' . ($secInfo && ($secInfo['created'] || !empty($secInfo['office_made'])) ? ' ' . (!empty($secInfo['office_made']) ? 'I made the office "' . $o['name'] . '" in Offices from the Supplies place, and ' : 'I ') . ($secInfo['created'] ? 'made its Boss Engine section with the boss, checker and team seats because it had none.' : 'used its Boss Engine section.') : '')] + ($secInfo ? ['created' => ['section' => ['id' => $secInfo['id'], 'created' => $secInfo['created'], 'seats' => $secInfo['seats'], 'office_made' => !empty($secInfo['office_made'])]]] : []));
        }
        case 'of.pl_photo': {
            list($z, $pl, $o) = $placeFor($in['zone_id'] ?? 0); $pid = (int)($in['point_id'] ?? 0);
            $label = ofp_trim($in['label'] ?? '', 200); $must = ofp_parse_must((string)($in['must_text'] ?? ''));
            $row = null; if ($pid) { $s = $pdo->prepare("SELECT * FROM of_points WHERE id = ? AND zone_id = ?"); $s->execute([$pid, (int)$z['id']]); $row = $s->fetch(); if (!$row) json_response(['error' => 'Photo not found.'], 404); }
            $tmp = ofp_upload('photo', !$row, 'Add the reference photo: how this place should look when it is clean.');
            $ref = $tmp ? ofp_save_ref($tmp) : ($row ? $row['ref_photo'] : null);
            $sortNo = isset($in['sort']) ? (int)$in['sort'] : ($row ? (int)$row['sort_no'] : 1 + (int)$pdo->query("SELECT COALESCE(MAX(sort_no),0) FROM of_points WHERE zone_id = " . (int)$z['id'])->fetchColumn());
            if ($label === '') $label = $row ? $row['text'] : ('Photo ' . $sortNo);
            if ($row) { $pdo->prepare("UPDATE of_points SET text = ?, text_hi = ?, ref_photo = ?, must_json = ?, sort_no = ? WHERE id = ?")->execute([$label, ofp_trim($in['label_hi'] ?? ($row['text_hi'] ?? ''), 240) ?: null, $ref, ofp_json($must), $sortNo, $pid]); if ($tmp && !empty($row['ref_photo']) && $row['ref_photo'] !== $ref) @unlink(bd_dir() . basename($row['ref_photo'])); }
            else { $pdo->prepare("INSERT INTO of_points (zone_id, text, text_hi, sort_no, ref_photo, must_json) VALUES (?,?,?,?,?,?)")->execute([(int)$z['id'], $label, ofp_trim($in['label_hi'] ?? '', 240) ?: null, $sortNo, $ref, ofp_json($must)]); $pid = (int)$pdo->lastInsertId(); }
            $pl = ofp_place($pdo, $z['id']);
            $done = array_values(array_filter(explode(',', (string)$pl['steps_done']), 'strlen')); if (!in_array('2', $done, true)) { $done[] = '2'; sort($done, SORT_NUMERIC); }
            $pdo->prepare("UPDATE of_pl_places SET step = GREATEST(step, 2), steps_done = ?, updated_at = ? WHERE zone_id = ?")->execute([implode(',', $done), bd_now(), (int)$z['id']]);
            $pl = ofp_place($pdo, $z['id']);
            if (in_array($pl['state'], ['on', 'off'], true)) ofp_sync_zone($pdo, $o, ofp_zone($pdo, $z['id']), $pl, $by);
            $photos = ofp_photos_view($pdo, $z); $mine = null; foreach ($photos as $p) if ($p['point_id'] === $pid) $mine = $p;
            json_response(['ok' => true, 'photo' => $mine, 'parsed' => $must, 'place' => ofp_place_view($pdo, $o, $z, $pl, false)]);
        }
        case 'of.pl_photo_del': {
            $s = $pdo->prepare("SELECT * FROM of_points WHERE id = ?"); $s->execute([(int)($in['point_id'] ?? 0)]); $pt = $s->fetch(); if (!$pt) json_response(['error' => 'Photo not found.'], 404);
            list($z, $pl, $o) = $placeFor($pt['zone_id']);
            $c = $pdo->prepare("SELECT COUNT(*) FROM of_points WHERE zone_id = ?"); $c->execute([(int)$z['id']]);
            if ($pl['state'] === 'on' && (int)$c->fetchColumn() <= 1) json_response(['error' => 'A place that is ON needs at least one cleaning photo. Switch it off first.'], 409);
            $pdo->prepare("DELETE FROM of_points WHERE id = ?")->execute([(int)$pt['id']]);
            if (!empty($pt['ref_photo'])) @unlink(bd_dir() . basename($pt['ref_photo']));
            if (in_array($pl['state'], ['on', 'off'], true)) ofp_sync_zone($pdo, $o, $z, $pl, $by);
            json_response(['ok' => true]);
        }
        case 'of.pl_fix_add': {
            list($z, $pl, $o) = $placeFor($in['zone_id'] ?? 0);
            $title = ofp_trim($in['title'] ?? '', 200); if (mb_strlen($title) < 3) json_response(['error' => 'Write what is wrong, in a few words.'], 422);
            $tmp = ofp_upload('photo', true, 'Add a photo of the problem. It helps the person who fixes it.');
            list($fn, $err) = dp_save_image($tmp, 'fx'); if (!$fn) json_response(['error' => $err], 422);
            $jid = ofp_fix_create($pdo, $o, $z, $title, ofp_trim($in['detail'] ?? '', 400), $fn, 'plfix:' . (int)$z['id'] . ':' . bin2hex(random_bytes(4)), $emp, $by);
            $pl = ofp_place($pdo, $z['id']); $done = array_values(array_filter(explode(',', (string)$pl['steps_done']), 'strlen')); if (!in_array('6', $done, true)) { $done[] = '6'; sort($done, SORT_NUMERIC); }
            $pdo->prepare("UPDATE of_pl_places SET step = GREATEST(step, 6), steps_done = ?, updated_at = ? WHERE zone_id = ?")->execute([implode(',', $done), bd_now(), (int)$z['id']]);
            json_response(['ok' => true, 'job_id' => $jid]);
        }
        case 'of.pl_suggest': {
            list($z, $pl, $o) = $placeFor($in['zone_id'] ?? 0);
            list($ai, $p) = ofp_suggest($pdo, $o, $z, (array)($in['frames'] ?? []), ofp_trim($in['notes'] ?? '', 600));
            of_put('plprop:' . (int)$z['id'], ofp_json($p));
            json_response(['ok' => true, 'ai' => $ai, 'proposals' => $p, 'message' => $ai === 'claude' ? 'Claude looked at the pictures. These are only proposals: save the ones you want.' : 'The AI was not used (no pictures or it is not available). These are the usual things for such a place: save the ones you want.']);
        }
        case 'of.pl_on': {
            list($z, $pl, $o) = $placeFor($in['zone_id'] ?? 0);
            $emps = array_key_exists('owner_emp_ids', $in) ? (array)$in['owner_emp_ids'] : null;
            json_response(ofp_switch_on($pdo, $o, $z, $emps, $by));
        }
        case 'of.pl_off': {
            list($z, $pl, $o) = $placeFor($in['zone_id'] ?? 0);
            $pdo->prepare("UPDATE of_pl_places SET state = 'off', updated_at = ? WHERE zone_id = ?")->execute([bd_now(), (int)$z['id']]);
            $pl = ofp_place($pdo, $z['id']); ofp_sync_zone($pdo, $o, $z, $pl, $by);
            $pdo->prepare("UPDATE bd_tasks SET status = 'excused' WHERE status IN ('open','unowned') AND task_date >= ? AND duty_id IN (SELECT id FROM bd_duties WHERE status != 'live' AND (lib_key LIKE ? OR lib_key = ?))")->execute([bd_today(), 'of:' . (int)$z['id'] . ':p%', 'ofc:' . (int)$z['id']]);
            bd_log('office', (int)$z['id'], 'place switched off: ' . $z['name'], $by);
            json_response(['ok' => true, 'place' => ofp_place_view($pdo, $o, $z, $pl), 'message' => $z['name'] . ' · ' . $o['name'] . ' is OFF. Its duties are paused.']);
        }
        case 'of.pl_delete': {
            list($z, $pl, $o) = $placeFor($in['zone_id'] ?? 0);
            ofp_remove_place($pdo, (int)$z['id'], $by, true);
            json_response(['ok' => true, 'message' => $z['name'] . ' was removed. Its duties are retired; the buying and repair history stays.']);
        }
        case 'of.pl_owner': {
            list($z, $pl, $o) = $placeFor($in['zone_id'] ?? 0);
            list($ok, $msg) = ofp_set_owner($pdo, $o, $z, (array)($in['emp_ids'] ?? []), $by); if (!$ok) json_response(['error' => $msg], 422);
            $pl = ofp_place($pdo, $z['id']);
            json_response(['ok' => true, 'owner' => ofp_owner_view($pdo, $o, $pl), 'message' => $msg]);
        }
        }
    } catch (PDOException $e) {
        json_response(['error' => 'Database problem in Places: ' . mb_substr($e->getMessage(), 0, 200)], 500);
    }
}
