<?php
/**
 * finance_new.php - "Finance New": a Finance built from scratch, kept completely apart from the original Finance (finance_in.php, tables fi_*).   (actions "fn.*", loaded by api.php)
 *
 * Rules for this file
 *   - Own tables start with fn_ . Never read or write the fi_* tables from here unless a task says so; the original Finance keeps working as it is.
 *   - Permission: the "Finance" tick of the role (api.php action_permission: 'fn.' => 'finance').
 *   - Add each new action as a  case 'fn.something': { ... }  below. PHP 7.4 safe.
 *
 * SUMMARY = the DAILY REPORT (the same layout as the owner's Excel day sheet)
 *   RECEIPT side:  OPENING (all banks / cash boxes at the start of the day) + every receipt head of the day = TOTAL
 *   PAYMENTS side: every payment head of the day = Total Expenses; then the CLOSING BALANCE of each bank / cash box; TOTAL = expenses + closing balances (= the receipt side TOTAL)
 *   Every entry is: a day, a side (in / out), a head (what it is: "PayU IDFC", "Salary"...), the bank / cash box it went through, an amount. Nothing is ever deleted: a wrong entry is VOIDED with a reason.
 *   A bank's balance counts from its opening-balance day: opening balance + money in - money out. Entries dated before that day are ignored for the balance.
 * Tables: fn_accounts (banks / cash boxes with the opening balance), fn_heads (the receipt and payment lines of the sheet), fn_entries.
 */
if (!isset($pdo, $action) || !function_exists('json_response') || !function_exists('require_auth')) { http_response_code(403); exit; }
if (strpos((string)$action, 'fn.') !== 0) { return; }

function fn_ensure(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    $flag = sys_get_temp_dir() . '/hrms_fn_tables_v1_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) return $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fn_accounts (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0, opening_date DATE NULL, active TINYINT NOT NULL DEFAULT 1, sort_no INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fn_heads (id INT AUTO_INCREMENT PRIMARY KEY, side VARCHAR(3) NOT NULL, name VARCHAR(80) NOT NULL, active TINYINT NOT NULL DEFAULT 1, sort_no INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, KEY idx_fn_h (side, active, sort_no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fn_entries (id INT AUTO_INCREMENT PRIMARY KEY, day DATE NOT NULL, side VARCHAR(3) NOT NULL, head_id INT NOT NULL, account_id INT NOT NULL, amount DECIMAL(14,2) NOT NULL, note VARCHAR(255) NOT NULL DEFAULT '', status VARCHAR(6) NOT NULL DEFAULT 'ok', void_reason VARCHAR(200) NOT NULL DEFAULT '', created_by VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY idx_fn_e_day (day, status), KEY idx_fn_e_acc (account_id, day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fn_edits (id INT AUTO_INCREMENT PRIMARY KEY, entry_id INT NOT NULL, old_json TEXT NOT NULL, edited_by VARCHAR(80) NOT NULL DEFAULT '', edited_at DATETIME NOT NULL, KEY idx_fn_ed (entry_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");      // what an entry looked like before each edit
        // first time only: the lines of the owner's day sheet, so the screen looks like it from the start (all can be renamed / hidden / added to)
        if (!(int)$pdo->query("SELECT COUNT(*) FROM fn_heads")->fetchColumn()) {
            $in = ['HDFC', 'ICICI', 'Razorpay', 'Rapidshyp COD', 'Snapmint', 'PayU IDFC', 'Other Income', 'Myntra', 'ShipRocket COD', 'Easebuzz', 'From Nova', 'Retail Shop', 'Salary Return', 'Nova Enterprise'];
            $out = ['FB Shout', 'AiSensy', 'VartaLabh', 'KwickChat', 'Shiprocket', 'Shopify', 'Refund', 'RJD Expense', 'Bank Charges', 'Salary', 'CC Bill', 'EMI', 'JD Personal', 'TM4U Chain - Krishna Asti', 'Office Expense', 'Nitya', 'Acumen', 'Milanbhai Upaad', 'Durgeshbhai Om Trading'];
            $ins = $pdo->prepare("INSERT INTO fn_heads (side, name, sort_no, created_at) VALUES (?,?,?,?)"); $now = date('Y-m-d H:i:s');
            foreach ($in as $i => $n) $ins->execute(['in', $n, ($i + 1) * 10, $now]);
            foreach ($out as $i => $n) $ins->execute(['out', $n, ($i + 1) * 10, $now]);
        }
        if (!(int)$pdo->query("SELECT COUNT(*) FROM fn_accounts")->fetchColumn()) {
            $ins = $pdo->prepare("INSERT INTO fn_accounts (name, sort_no, created_at) VALUES (?,?,?)"); $now = date('Y-m-d H:i:s');
            foreach (['ICICI', 'IDFC', 'BOB', 'HDFC', 'AXIS', 'Nova'] as $i => $n) $ins->execute([$n, ($i + 1) * 10, $now]);
        }
        @file_put_contents($flag, '1');
    } catch (Throwable $e) { error_log('fn_ensure: ' . $e->getMessage()); return $ok = false; }
    return $ok = true;
}
/** v2: receipt lines the owner asked for later. Adds only the ones that are missing (matched by name, any capitals), once. */
function fn_ensure2(PDO $pdo): bool
{
    $flag = sys_get_temp_dir() . '/hrms_fn_tables_v2_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) return true;
    try {
        $have = []; foreach ($pdo->query("SELECT name FROM fn_heads WHERE side = 'in'")->fetchAll(PDO::FETCH_COLUMN) as $n) $have[mb_strtolower(trim((string)$n))] = 1;
        $mx = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM fn_heads WHERE side = 'in'")->fetchColumn();
        $ins = $pdo->prepare("INSERT INTO fn_heads (side, name, sort_no, created_at) VALUES ('in', ?, ?, ?)");
        foreach (['Flipkart HDFC', 'ICICI Merchant', 'Axis'] as $n) { if (isset($have[mb_strtolower($n)])) continue; $mx += 10; $ins->execute([$n, $mx, date('Y-m-d H:i:s')]); }
        @file_put_contents($flag, '1'); return true;
    } catch (Throwable $e) { error_log('fn_ensure2: ' . $e->getMessage()); return false; }
}
/** v3: the last 4 digits of a bank account, and (once) the banks of the original Finance brought over. The original is only READ. A bank that is already here (same name, "Bank of Baroda" = "BOB", "Axis Bank" = "AXIS") only gets its last 4 digits; the others are added. */
function fn_ensure3(PDO $pdo): bool
{
    $flag = sys_get_temp_dir() . '/hrms_fn_tables_v3_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) return true;
    try {
        try { $pdo->query("SELECT last4 FROM fn_accounts LIMIT 1")->fetch(); } catch (Throwable $e) { $pdo->exec("ALTER TABLE fn_accounts ADD COLUMN last4 VARCHAR(8) NOT NULL DEFAULT ''"); }
        $norm = function ($n) { $n = mb_strtolower(trim((string)$n)); $n = preg_replace('/\bbank of baroda\b/', 'bob', $n); $n = preg_replace('/\b(bank|first|ltd|limited|current|savings|account)\b/', '', $n); return preg_replace('/[^a-z0-9]/', '', $n); };
        $orig = []; try { $orig = $pdo->query("SELECT * FROM fi_accounts ORDER BY id")->fetchAll(); } catch (Throwable $e) { $orig = []; }
        if ($orig) {
            $mine = $pdo->query("SELECT * FROM fn_accounts")->fetchAll(); $by = []; foreach ($mine as $m) $by[$norm($m['name'])] = $m;
            $mx = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM fn_accounts")->fetchColumn(); $now = date('Y-m-d H:i:s');
            foreach ($orig as $o) {
                $k = $norm($o['name']); if ($k === '') continue; $ob = round((float)($o['opening_balance'] ?? 0), 2); $od = (string)($o['opening_date'] ?? ''); $l4 = substr(preg_replace('/\D/', '', (string)($o['last4'] ?? '')), -4);
                if (isset($by[$k])) {
                    $m = $by[$k]; if ((string)$m['last4'] === '' && $l4 !== '') $pdo->prepare("UPDATE fn_accounts SET last4 = ? WHERE id = ?")->execute([$l4, (int)$m['id']]);
                    if ((string)($m['opening_date'] ?? '') === '' && $od !== '') $pdo->prepare("UPDATE fn_accounts SET opening_balance = ?, opening_date = ? WHERE id = ?")->execute([$ob, $od, (int)$m['id']]);
                } else {
                    $mx += 10; $pdo->prepare("INSERT INTO fn_accounts (name, opening_balance, opening_date, active, sort_no, created_at, last4) VALUES (?,?,?,?,?,?,?)")->execute([mb_substr(trim((string)$o['name']), 0, 80), $ob, $od !== '' ? $od : null, (int)($o['active'] ?? 1) ? 1 : 0, $mx, $now, $l4]);
                    $by[$k] = ['name' => $o['name']];
                }
            }
        }
        @file_put_contents($flag, '1'); return true;
    } catch (Throwable $e) { error_log('fn_ensure3: ' . $e->getMessage()); return false; }
}
function fn_p($x): int { return (int)round(((float)$x) * 100); }      // rupees -> paise: all sums are integer paise
function fn_m(int $p): float { return round($p / 100, 2); }
function fn_day($s): string { $s = trim((string)$s); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return ''; $t = strtotime($s); return ($t && date('Y-m-d', $t) === $s) ? $s : ''; }
function fn_today(PDO $pdo): string { return function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d'); }
function fn_who($auth): string { return (string)($auth['username'] ?? $auth['name'] ?? ('user ' . ($auth['user_id'] ?? ''))); }
function fn_err(string $m, int $c = 422) { json_response(['error' => $m], $c); }

/** Per bank / cash box for one day: opening (start of the day), money in, money out, closing. Money before the account's opening-balance day is ignored. */
function fn_balances(PDO $pdo, string $day): array
{
    $accs = $pdo->query("SELECT * FROM fn_accounts ORDER BY active DESC, sort_no, name")->fetchAll(); $out = [];
    foreach ($accs as $a) { $od = (string)($a['opening_date'] ?? ''); $od = $od !== '' ? $od : '1970-01-01'; $on = $day >= $od; $out[(int)$a['id']] = ['id' => (int)$a['id'], 'name' => $a['name'], 'active' => (int)$a['active'], 'od' => $od, 'before' => $on ? fn_p($a['opening_balance']) : 0, 'in' => 0, 'out' => 0]; }
    $q = $pdo->prepare("SELECT account_id, side, day, SUM(amount) a FROM fn_entries WHERE status = 'ok' AND day <= ? GROUP BY account_id, side, day"); $q->execute([$day]);
    foreach ($q->fetchAll() as $r) {
        $k = (int)$r['account_id']; if (!isset($out[$k]) || $r['day'] < $out[$k]['od']) continue; $p = fn_p($r['a']); $sign = $r['side'] === 'in' ? 1 : -1;
        if ($r['day'] < $day) $out[$k]['before'] += $sign * $p; elseif ($r['side'] === 'in') $out[$k]['in'] += $p; else $out[$k]['out'] += $p;
    }
    foreach ($out as $k => $b) { $out[$k]['close'] = $b['before'] + $b['in'] - $b['out']; }
    return $out;
}

if (!fn_ensure($pdo) || !fn_ensure2($pdo) || !fn_ensure3($pdo)) fn_err('Could not create the Finance New tables.', 500);
$auth = require_auth();

switch ($action) {
    // a check that the new section is wired up (page -> api -> this file)
    case 'fn.ping': {
        json_response(['ok' => true, 'section' => 'Finance New', 'who' => (string)($auth['username'] ?? $auth['name'] ?? ''), 'time' => date('Y-m-d H:i:s')]);
        break;
    }

    // THE DAILY REPORT of one day
    case 'fn.day': {
        $today = fn_today($pdo); $day = fn_day($_GET['date'] ?? '') ?: $today; if ($day > $today) $day = $today;
        $bal = fn_balances($pdo, $day); $openP = 0; $closeP = 0; foreach ($bal as $b) { $openP += $b['before']; $closeP += $b['close']; }
        $heads = $pdo->query("SELECT * FROM fn_heads ORDER BY side, sort_no, name")->fetchAll();
        $q = $pdo->prepare("SELECT head_id, SUM(amount) a, COUNT(*) n FROM fn_entries WHERE status = 'ok' AND day = ? GROUP BY head_id"); $q->execute([$day]); $sum = [];
        foreach ($q->fetchAll() as $r) $sum[(int)$r['head_id']] = [fn_p($r['a']), (int)$r['n']];
        $lines = ['in' => [], 'out' => []]; $tot = ['in' => 0, 'out' => 0];
        foreach ($heads as $h) {
            $s = $sum[(int)$h['id']] ?? [0, 0]; if (!(int)$h['active'] && $s[1] === 0) continue;
            $lines[$h['side']][] = ['id' => (int)$h['id'], 'name' => $h['name'], 'amount' => fn_m($s[0]), 'n' => $s[1], 'active' => (int)$h['active']]; $tot[$h['side']] += $s[0];
        }
        $q = $pdo->prepare("SELECT e.*, h.name AS head_name, a.name AS account_name FROM fn_entries e LEFT JOIN fn_heads h ON h.id = e.head_id LEFT JOIN fn_accounts a ON a.id = e.account_id WHERE e.day = ? ORDER BY e.id DESC"); $q->execute([$day]);
        $edN = []; try { $ids = array_map(fn($e) => (int)$e['id'], $q->fetchAll()); $q->execute([$day]); if ($ids) foreach ($pdo->query("SELECT entry_id, COUNT(*) n FROM fn_edits WHERE entry_id IN (" . implode(',', $ids) . ") GROUP BY entry_id")->fetchAll() as $x) $edN[(int)$x['entry_id']] = (int)$x['n']; } catch (Throwable $e) {}
        $entries = array_map(fn($e) => ['id' => (int)$e['id'], 'side' => $e['side'], 'head_id' => (int)$e['head_id'], 'account_id' => (int)$e['account_id'], 'day' => $e['day'], 'edited' => $edN[(int)$e['id']] ?? 0, 'head' => (string)$e['head_name'], 'account' => (string)$e['account_name'], 'amount' => (float)$e['amount'], 'note' => $e['note'], 'status' => $e['status'], 'void_reason' => $e['void_reason'], 'by' => $e['created_by'], 'at' => $e['created_at']], $q->fetchAll());
        $accounts = []; $closeAcc = 0; foreach ($bal as $b) { if (!$b['active'] && $b['close'] === 0 && $b['before'] === 0 && $b['in'] === 0 && $b['out'] === 0) continue; $accounts[] = ['id' => $b['id'], 'name' => $b['name'], 'opening' => fn_m($b['before']), 'in' => fn_m($b['in']), 'out' => fn_m($b['out']), 'closing' => fn_m($b['close']), 'active' => $b['active']]; $closeAcc += $b['close']; }
        json_response(['date' => $day, 'today' => $today, 'opening' => fn_m($openP), 'receipts' => ['lines' => $lines['in'], 'total_in' => fn_m($tot['in']), 'total' => fn_m($openP + $tot['in'])],
            'payments' => ['lines' => $lines['out'], 'total_expenses' => fn_m($tot['out'])], 'closing' => ['accounts' => $accounts, 'total' => fn_m($closeAcc), 'with_expenses' => fn_m($closeAcc + $tot['out'])], 'check' => fn_m($openP + $tot['in'] - $tot['out'] - $closeAcc), 'entries' => $entries]);
        break;
    }

    case 'fn.accounts': {
        $rows = $pdo->query("SELECT * FROM fn_accounts ORDER BY active DESC, sort_no, name")->fetchAll();
        json_response(['accounts' => array_map(fn($a) => ['id' => (int)$a['id'], 'name' => $a['name'], 'last4' => (string)($a['last4'] ?? ''), 'opening_balance' => round((float)$a['opening_balance'], 2), 'opening_date' => (string)($a['opening_date'] ?? ''), 'active' => (int)$a['active']], $rows)]);
        break;
    }
    case 'fn.account_save': {
        $in = json_input(); $id = (int)($in['id'] ?? 0); $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 80)); if (mb_strlen($name) < 2) fn_err('Give the bank / cash box a name.');
        $dup = $pdo->prepare("SELECT COUNT(*) FROM fn_accounts WHERE LOWER(name) = LOWER(?) AND id <> ?"); $dup->execute([$name, $id]); if ((int)$dup->fetchColumn()) fn_err('There is already an account with this name.');
        $ob = round((float)($in['opening_balance'] ?? 0), 2); if (abs($ob) > 100000000000) fn_err('That opening balance looks wrong.');
        $od = fn_day($in['opening_date'] ?? ''); if ($od === '' && $ob != 0) $od = fn_today($pdo);      // a balance without a day counts from today
        if ($od > fn_today($pdo)) fn_err('The opening balance day cannot be in the future.');
        $act = !isset($in['active']) || !empty($in['active']) ? 1 : 0; $l4 = substr(preg_replace('/\D/', '', (string)($in['last4'] ?? '')), -4);
        if ($id) {
            $pdo->prepare("UPDATE fn_accounts SET name = ?, opening_balance = ?, opening_date = ?, active = ? WHERE id = ?")->execute([$name, $ob, $od !== '' ? $od : null, $act, $id]);
            if (array_key_exists('last4', $in)) $pdo->prepare("UPDATE fn_accounts SET last4 = ? WHERE id = ?")->execute([$l4, $id]);      // an older screen that does not send it keeps what is saved
        }
        else { $mx = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM fn_accounts")->fetchColumn(); $pdo->prepare("INSERT INTO fn_accounts (name, opening_balance, opening_date, active, sort_no, created_at, last4) VALUES (?,?,?,1,?,?,?)")->execute([$name, $ob, $od !== '' ? $od : null, $mx + 10, date('Y-m-d H:i:s'), $l4]); $id = (int)$pdo->lastInsertId(); }
        json_response(['ok' => true, 'id' => $id]);
        break;
    }

    // the BANK ACCOUNTS tab: every bank / cash box with its opening balance, what came in and went out since, and the balance today
    case 'fn.banks': {
        $today = fn_today($pdo); $rows = $pdo->query("SELECT * FROM fn_accounts ORDER BY active DESC, sort_no, name")->fetchAll(); $acc = [];
        foreach ($rows as $a) { $od = (string)($a['opening_date'] ?? ''); $acc[(int)$a['id']] = ['a' => $a, 'od' => $od !== '' ? $od : '1970-01-01', 'in' => 0, 'out' => 0]; }
        $q = $pdo->prepare("SELECT account_id, side, day, SUM(amount) s FROM fn_entries WHERE status = 'ok' AND day <= ? GROUP BY account_id, side, day"); $q->execute([$today]);
        foreach ($q->fetchAll() as $r) { $k = (int)$r['account_id']; if (!isset($acc[$k]) || $r['day'] < $acc[$k]['od']) continue; $acc[$k][$r['side'] === 'in' ? 'in' : 'out'] += fn_p($r['s']); }
        $list = []; $tot = 0;
        foreach ($acc as $k => $x) { $a = $x['a']; $bal = fn_p($a['opening_balance']) + $x['in'] - $x['out']; if ((int)$a['active']) $tot += $bal;
            $list[] = ['id' => $k, 'name' => $a['name'], 'last4' => (string)($a['last4'] ?? ''), 'active' => (int)$a['active'], 'opening_balance' => round((float)$a['opening_balance'], 2), 'opening_date' => (string)($a['opening_date'] ?? ''), 'in' => fn_m($x['in']), 'out' => fn_m($x['out']), 'balance' => fn_m($bal)]; }
        json_response(['today' => $today, 'banks' => $list, 'total' => fn_m($tot)]);
        break;
    }

    case 'fn.heads': {
        $rows = $pdo->query("SELECT * FROM fn_heads ORDER BY side, active DESC, sort_no, name")->fetchAll();
        json_response(['heads' => array_map(fn($h) => ['id' => (int)$h['id'], 'side' => $h['side'], 'name' => $h['name'], 'active' => (int)$h['active']], $rows)]);
        break;
    }
    case 'fn.head_save': {
        $in = json_input(); $id = (int)($in['id'] ?? 0); $side = (string)($in['side'] ?? ''); $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 80)); if (mb_strlen($name) < 2) fn_err('Give the line a name.');
        if ($id) { $c = $pdo->prepare("SELECT side FROM fn_heads WHERE id = ?"); $c->execute([$id]); $side = (string)$c->fetchColumn(); }
        if (!in_array($side, ['in', 'out'], true)) fn_err('Choose Receipt or Payment.');
        $dup = $pdo->prepare("SELECT COUNT(*) FROM fn_heads WHERE side = ? AND LOWER(name) = LOWER(?) AND id <> ?"); $dup->execute([$side, $name, $id]); if ((int)$dup->fetchColumn()) fn_err('This line already exists.');
        $act = !isset($in['active']) || !empty($in['active']) ? 1 : 0;
        if ($id) { $pdo->prepare("UPDATE fn_heads SET name = ?, active = ? WHERE id = ?")->execute([$name, $act, $id]); }
        else { $mx = $pdo->prepare("SELECT COALESCE(MAX(sort_no), 0) FROM fn_heads WHERE side = ?"); $mx->execute([$side]); $pdo->prepare("INSERT INTO fn_heads (side, name, active, sort_no, created_at) VALUES (?,?,1,?,?)")->execute([$side, $name, (int)$mx->fetchColumn() + 10, date('Y-m-d H:i:s')]); $id = (int)$pdo->lastInsertId(); }
        json_response(['ok' => true, 'id' => $id]);
        break;
    }

    case 'fn.entry_save': {
        $in = json_input(); $today = fn_today($pdo); $day = fn_day($in['date'] ?? '') ?: $today; if ($day > $today) fn_err('The date is in the future.');
        $side = (string)($in['side'] ?? ''); if (!in_array($side, ['in', 'out'], true)) fn_err('Choose Receipt or Payment.');
        $amt = round((float)($in['amount'] ?? 0), 2); if ($amt <= 0) fn_err('Enter the amount.'); if ($amt > 1000000000) fn_err('That amount looks wrong.');
        $h = $pdo->prepare("SELECT id FROM fn_heads WHERE id = ? AND side = ? AND active = 1"); $h->execute([(int)($in['head_id'] ?? 0), $side]); if (!$h->fetchColumn()) fn_err('Choose the line (what this money is).');
        $a = $pdo->prepare("SELECT id FROM fn_accounts WHERE id = ? AND active = 1"); $a->execute([(int)($in['account_id'] ?? 0)]); if (!$a->fetchColumn()) fn_err('Choose the bank / cash box the money went through.');
        $note = trim(mb_substr((string)($in['note'] ?? ''), 0, 255));
        $pdo->prepare("INSERT INTO fn_entries (day, side, head_id, account_id, amount, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$day, $side, (int)$in['head_id'], (int)$in['account_id'], $amt, $note, fn_who($auth), date('Y-m-d H:i:s')]);
        json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;
    }
    // change an entry (amount, line, bank, day, note); what it was is kept in fn_edits
    case 'fn.entry_update': {
        $in = json_input(); $today = fn_today($pdo); $id = (int)($in['id'] ?? 0);
        $old = $pdo->prepare("SELECT * FROM fn_entries WHERE id = ? AND status = 'ok'"); $old->execute([$id]); $o = $old->fetch(); if (!$o) fn_err('Entry not found, or it was cancelled.', 404);
        $day = fn_day($in['date'] ?? ''); if ($day === '') fn_err('Choose the date.'); if ($day > $today) fn_err('The date is in the future.');
        $side = (string)($in['side'] ?? ''); if (!in_array($side, ['in', 'out'], true)) fn_err('Choose Receipt or Payment.');
        $amt = round((float)($in['amount'] ?? 0), 2); if ($amt <= 0) fn_err('Enter the amount.'); if ($amt > 1000000000) fn_err('That amount looks wrong.');
        $h = $pdo->prepare("SELECT id FROM fn_heads WHERE id = ? AND side = ?"); $h->execute([(int)($in['head_id'] ?? 0), $side]); if (!$h->fetchColumn()) fn_err('Choose the line (what this money is).');
        $a = $pdo->prepare("SELECT id FROM fn_accounts WHERE id = ?"); $a->execute([(int)($in['account_id'] ?? 0)]); if (!$a->fetchColumn()) fn_err('Choose the bank / cash box the money went through.');
        $note = trim(mb_substr((string)($in['note'] ?? ''), 0, 255));
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO fn_edits (entry_id, old_json, edited_by, edited_at) VALUES (?,?,?,?)")->execute([$id, json_encode(['day' => $o['day'], 'side' => $o['side'], 'head_id' => (int)$o['head_id'], 'account_id' => (int)$o['account_id'], 'amount' => (float)$o['amount'], 'note' => $o['note']]), fn_who($auth), date('Y-m-d H:i:s')]);
            $pdo->prepare("UPDATE fn_entries SET day = ?, side = ?, head_id = ?, account_id = ?, amount = ?, note = ? WHERE id = ?")->execute([$day, $side, (int)$in['head_id'], (int)$in['account_id'], $amt, $note, $id]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); error_log('fn.entry_update: ' . $e->getMessage()); fn_err('Could not save the change. Nothing was changed.', 500); }
        json_response(['ok' => true]);
        break;
    }
    case 'fn.entry_void': {
        $in = json_input(); $reason = trim(mb_substr((string)($in['reason'] ?? ''), 0, 200)); if (mb_strlen($reason) < 3) fn_err('Write why this entry is cancelled.');
        $u = $pdo->prepare("UPDATE fn_entries SET status = 'void', void_reason = ? WHERE id = ? AND status = 'ok'"); $u->execute([$reason, (int)($in['id'] ?? 0)]); if (!$u->rowCount()) fn_err('Entry not found, or already cancelled.', 404);
        json_response(['ok' => true]);
        break;
    }
    default: json_response(['error' => 'Unknown Finance New action.'], 404);
}
