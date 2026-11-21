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
/** v4: the INCOMING channels (Prepaid: Flipkart, Razorpay, PayU...; COD later). Each channel can be linked to one receipt line of the day sheet; the money received on that line is what the channel shows. Own table fn_in_channels. */
function fn_ensure4(PDO $pdo): bool
{
    $flag = sys_get_temp_dir() . '/hrms_fn_tables_v4_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) return true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fn_in_channels (id INT AUTO_INCREMENT PRIMARY KEY, section VARCHAR(8) NOT NULL, name VARCHAR(80) NOT NULL, head_id INT NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1, sort_no INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, KEY idx_fn_ic (section, active, sort_no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!(int)$pdo->query("SELECT COUNT(*) FROM fn_in_channels")->fetchColumn()) {
            $heads = []; foreach ($pdo->query("SELECT id, name FROM fn_heads WHERE side = 'in'")->fetchAll() as $h) $heads[mb_strtolower(trim((string)$h['name']))] = (int)$h['id'];
            $ins = $pdo->prepare("INSERT INTO fn_in_channels (section, name, head_id, sort_no, created_at) VALUES ('prepaid', ?, ?, ?, ?)"); $now = date('Y-m-d H:i:s');
            foreach ([['Flipkart', 'flipkart hdfc'], ['Razorpay', 'razorpay'], ['PayU', 'payu idfc'], ['Snapmint', 'snapmint'], ['ICICI Merchant', 'icici merchant'], ['Myntra', 'myntra'], ['Easebuzz', 'easebuzz']] as $i => $c) $ins->execute([$c[0], $heads[$c[1]] ?? 0, ($i + 1) * 10, $now]);
        }
        @file_put_contents($flag, '1'); return true;
    } catch (Throwable $e) { error_log('fn_ensure4: ' . $e->getMessage()); return false; }
}
/** v5: UTR files of the Incoming channels. fn_in_uploads = one file per channel and day (gross, fees, net, the entry it created); fn_in_utr = the UTR numbers of a saved file (so the same file is never counted twice). */
function fn_ensure5(PDO $pdo): bool
{
    $flag = sys_get_temp_dir() . '/hrms_fn_tables_v5_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) return true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fn_in_uploads (id INT AUTO_INCREMENT PRIMARY KEY, channel_id INT NOT NULL, day DATE NOT NULL, file_name VARCHAR(200) NOT NULL DEFAULT '', file_hash CHAR(32) NOT NULL DEFAULT '', rows_n INT NOT NULL DEFAULT 0, gross DECIMAL(14,2) NOT NULL DEFAULT 0, fee DECIMAL(14,2) NOT NULL DEFAULT 0, net DECIMAL(14,2) NOT NULL DEFAULT 0, account_id INT NOT NULL DEFAULT 0, entry_id INT NOT NULL DEFAULT 0, status VARCHAR(8) NOT NULL DEFAULT 'preview', head_row INT NOT NULL DEFAULT 0, rows_json MEDIUMTEXT NULL, void_reason VARCHAR(200) NOT NULL DEFAULT '', created_by VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY idx_fn_up (channel_id, status, day), KEY idx_fn_up_e (entry_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fn_in_utr (id INT AUTO_INCREMENT PRIMARY KEY, upload_id INT NOT NULL, channel_id INT NOT NULL, utr VARCHAR(80) NOT NULL, KEY idx_fn_utr (channel_id, utr), KEY idx_fn_utr_u (upload_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @file_put_contents($flag, '1'); return true;
    } catch (Throwable $e) { error_log('fn_ensure5: ' . $e->getMessage()); return false; }
}

/* ============================== reading an Excel / CSV file (xlsx has no library here: it is a zip of XML) ============================== */
function fn_read_sheet(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        if (!class_exists('ZipArchive')) throw new Exception('This server cannot open .xlsx files. Save the sheet as CSV and upload that.');
        $z = new ZipArchive(); if ($z->open($path) !== true) throw new Exception('That .xlsx file could not be opened.');
        $shared = []; $ss = $z->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) { $x = @simplexml_load_string($ss); if ($x) foreach ($x->si as $si) { $t = ''; if (isset($si->t)) $t = (string)$si->t; else foreach ($si->r as $r) $t .= (string)$r->t; $shared[] = $t; } }
        $sheetName = 'xl/worksheets/sheet1.xml'; if ($z->locateName($sheetName) === false) { for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) { $sheetName = $n; break; } } }
        $xml = $z->getFromName($sheetName); $z->close(); if ($xml === false) throw new Exception('No sheet found in that file.');
        $x = @simplexml_load_string($xml); if (!$x) throw new Exception('The sheet could not be read.');
        $rows = [];
        foreach ($x->sheetData->row as $row) {
            $r = []; $max = -1;
            foreach ($row->c as $c) {
                $ref = (string)$c['r']; $col = 0; if (preg_match('/^([A-Z]+)/', $ref, $m)) { foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64); $col--; }
                $t = (string)$c['t']; $v = isset($c->v) ? (string)$c->v : '';
                if ($t === 's') $v = $shared[(int)$v] ?? ''; elseif ($t === 'inlineStr') $v = (string)($c->is->t ?? '');
                $r[$col] = trim($v); $max = max($max, $col);
            }
            $line = []; for ($i = 0; $i <= $max; $i++) $line[] = $r[$i] ?? '';
            if (count(array_filter($line, fn($c) => $c !== '')) > 0) $rows[] = $line;
            if (count($rows) >= 20000) break;
        }
        return $rows;
    }
    $raw = (string)file_get_contents($path); $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); $lines = preg_split('/\r\n|\n|\r/', $raw); $first = '';
    foreach ($lines as $l) { if (trim($l) !== '') { $first = $l; break; } }
    $best = ','; $bc = -1; foreach ([',', ';', "\t", '|'] as $d) { $c = substr_count($first, $d); if ($c > $bc) { $bc = $c; $best = $d; } }
    $rows = [];
    foreach ($lines as $l) { if (trim($l) === '') continue; $rows[] = array_map('trim', str_getcsv($l, $best)); if (count($rows) >= 20000) break; }
    return $rows;
}
function fn_num($v): float { $s = trim((string)$v); $neg = (strpos($s, '(') !== false && strpos($s, ')') !== false) || preg_match('/^-/', $s); $s = preg_replace('/[^0-9.]/', '', str_replace(',', '', $s)); if ($s === '' || $s === '.') return 0.0; return $neg ? -(float)$s : (float)$s; }
/** The header row = the first of the top 15 rows with 3+ filled cells that are mostly words. */
function fn_header_row(array $rows): int
{
    foreach (array_slice($rows, 0, 15, true) as $i => $r) {
        $f = array_filter($r, fn($c) => trim((string)$c) !== ''); if (count($f) < 3) continue;
        $words = array_filter($f, fn($c) => !preg_match('/^[\d.,\-\s₹%()]+$/u', (string)$c)); if (count($words) >= 0.6 * count($f)) return (int)$i;
    }
    return 0;
}
/** The PayU transaction / settlement report (headers like "Merchant UTR", "Net Amount", "Merchant Txn ID", "PayU ID", "Total Processing fees", "Total Service Tax"): the columns are known, nothing is guessed.
 *  UTR = Merchant UTR; gross = Amount; net = Net Amount; order = Merchant Txn ID (the KWIK... id on the order); charges = Total Processing fees + Total Service Tax (the totals only, the single fee / tax columns are parts of them);
 *  status / action = Status, Requested Action (failed rows are left out, refunds count as minus). Returns [] when the file is not a PayU report. */
function fn_payu_cols(array $h): array
{
    $ix = function ($name) use ($h) { foreach ($h as $i => $t) if ($t === $name) return $i; return -1; };
    $utr = $ix('merchant utr'); $net = $ix('net amount'); $gross = $ix('amount'); $ord = $ix('merchant txn id'); if ($ord < 0) $ord = $ix('payu id');
    if ($utr < 0 || $net < 0 || $gross < 0 || ($ix('payu id') < 0 && $ix('merchant txn id') < 0)) return [];
    $fees = []; foreach (['total processing fees', 'total service tax'] as $n) { $i = $ix($n); if ($i >= 0) $fees[] = $i; }
    if (!$fees) foreach (['payment processing fee', 'additional service fee', 'additional service tax', 'convenience fee', 'convenience tax', 'priority settlement fee', 'priority settlement tax', 'additional charges', 'service tax'] as $n) { $i = $ix($n); if ($i >= 0) $fees[] = $i; }
    return ['utr' => $utr, 'gross' => $gross, 'net' => $net, 'order' => $ord, 'fees' => $fees, 'status' => $ix('status'), 'action' => $ix('requested action'), 'provider' => 'PayU'];
}
/** Guess the columns from the header words: UTR, gross, net, fee columns. The owner can correct every one of them on the screen. */
function fn_guess_cols(array $header): array
{
    $h = array_map(fn($c) => mb_strtolower(trim((string)$c)), $header); $out = ['utr' => -1, 'gross' => -1, 'net' => -1, 'order' => -1, 'fees' => []];
    $pu = fn_payu_cols($h); if ($pu) return $pu;
    foreach ([['/\butr\b/'], ['/bank.?ref/', '/\brrn\b/'], ['/settlement.?(id|utr|ref)|transfer.?(id|ref)|payout.?(id|ref)|reference.?(no|number|id)/']] as $tier) { foreach ($tier as $re) foreach ($h as $i => $t) if ($out['utr'] < 0 && preg_match($re, $t)) { $out['utr'] = $i; } if ($out['utr'] >= 0) break; }
    foreach ($h as $i => $t) { if (preg_match('/\bnet\b|net.?(amount|settle|payout|credit)/', $t) && !preg_match('/network/', $t)) { $out['net'] = $i; break; } }
    if ($out['net'] < 0) foreach ($h as $i => $t) { if (preg_match('/settle(d|ment)?.?amount|payout.?amount|amount.?credited|credited.?amount/', $t)) { $out['net'] = $i; break; } }
    foreach ([['/order.?(id|no|number|name)/'], ['/merchant.?(txn|ref|order)|client.?txn/'], ['/txn.?id|transaction.?id|txnid/']] as $tier) { foreach ($tier as $re) foreach ($h as $i => $t) if ($out['order'] < 0 && $i !== $out['utr'] && preg_match($re, $t)) { $out['order'] = $i; } if ($out['order'] >= 0) break; }
    foreach ($h as $i => $t) { if ($i === $out['net'] || $i === $out['utr']) continue; if (preg_match('/gross|^amount$|transaction.?amount|order.?amount|sale.?amount|collection|total.?amount/', $t)) { $out['gross'] = $i; break; } }
    foreach ($h as $i => $t) { if (in_array($i, [$out['net'], $out['utr'], $out['gross'], $out['order']], true)) continue; if (preg_match('/fee|commission|charge|mdr|\bgst\b|\btax\b|tds|deduction/', $t) && !preg_match('/taxable/', $t)) $out['fees'][] = $i; }
    return $out;
}
/** The money rows of a file for the chosen columns: [row index, utr, gross, fee, net, fee parts per column]. A row counts when it has a UTR (if a UTR column is chosen) and an amount; a totals line without a UTR is left out. net = the net column, else gross - fees. */
function fn_file_rows(array $rows, int $headRow, array $cols): array
{
    $u = isset($cols['utr']) ? (int)$cols['utr'] : -1; $g = isset($cols['gross']) ? (int)$cols['gross'] : -1; $n = isset($cols['net']) ? (int)$cols['net'] : -1; $fees = array_values(array_unique(array_map('intval', (array)($cols['fees'] ?? []))));
    $out = [];
    foreach ($rows as $i => $r) {
        if ($i <= $headRow) continue;
        $utr = $u >= 0 ? trim((string)($r[$u] ?? '')) : ''; if ($u >= 0 && ($utr === '' || preg_match('/^(grand )?total$/i', $utr))) continue;
        $st = isset($cols['status']) && (int)$cols['status'] >= 0 ? mb_strtolower(trim((string)($r[(int)$cols['status']] ?? ''))) : ''; if ($st !== '' && preg_match('/fail|cancel|pending|dropped|bounce|incomplete|initiat|declin|reject/', $st)) continue;      // a payment that did not go through is no money
        $ac = isset($cols['action']) && (int)$cols['action'] >= 0 ? mb_strtolower(trim((string)($r[(int)$cols['action']] ?? ''))) : ''; $sign = ($ac !== '' && preg_match('/refund|chargeback|reversal/', $ac)) ? -1 : 1;      // a refund takes money back
        $gv = $g >= 0 ? fn_num($r[$g] ?? '') : 0.0; $nv = $n >= 0 ? fn_num($r[$n] ?? '') : 0.0; $fv = 0.0; $parts = []; foreach ($fees as $fi) { $x = abs(fn_num($r[$fi] ?? '')); $fv += $x; $parts[$fi] = $x; }
        if ($g < 0 && $n < 0) continue; if ($gv == 0.0 && $nv == 0.0) continue;
        $net = $n >= 0 ? $nv : $gv - $fv; $gross = $g >= 0 ? $gv : ($n >= 0 ? $nv + $fv : 0.0);
        if ($sign < 0) { $net = -abs($net); $gross = -abs($gross); $fv = $gross - $net; foreach ($parts as $k => $v) $parts[$k] = 0.0; }
        $out[] = ['i' => $i, 'utr' => $utr, 'gross' => $gross, 'fee' => $fv, 'net' => $net, 'parts' => $parts];
    }
    return $out;
}
/** Add up a file for the chosen columns. */
function fn_calc_file(array $rows, int $headRow, array $cols): array
{
    $gP = 0; $fP = 0; $nP = 0; $cnt = 0; $utrs = [];
    foreach (fn_file_rows($rows, $headRow, $cols) as $r) { $gP += fn_p($r['gross']); $fP += fn_p($r['fee']); $nP += fn_p($r['net']); $cnt++; if ($r['utr'] !== '') $utrs[$r['utr']] = 1; }
    return ['rows' => $cnt, 'gross' => fn_m($gP), 'fee' => fn_m($fP), 'net' => fn_m($nP), 'utrs' => array_map('strval', array_keys($utrs))];
}
function fn_okey($s): string { return strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string)$s)); }
/** The PREPAID orders of one HRMS day (10 AM to 10 AM) from the Shopify copy: what the gateway should pay for. Read only. The prepaid part = total - the COD balance. */
function fn_prepaid_orders(PDO $pdo, string $day): array
{
    $out = [];
    try {
        $cancel = function_exists('order_cancels_map') ? order_cancels_map($pdo) : [];
        $q = $pdo->prepare("SELECT shopify_id, store_key, order_name, created_at, customer, phone, total, financial_status, outstanding, pay_ref FROM shop_orders WHERE COALESCE(tags, '') NOT LIKE '%wa-rx%' AND created_at >= ? AND created_at < ?");
        $q->execute([date('Y-m-d', strtotime($day . ' -1 day')) . ' 00:00:00', date('Y-m-d', strtotime($day . ' +2 day')) . ' 00:00:00']);
        foreach ($q->fetchAll() as $o) {
            $fs = strtolower((string)$o['financial_status']); if (in_array($fs, ['voided', 'refunded'], true) || isset($cancel[$o['store_key'] . '|' . $o['shopify_id']])) continue;
            $d = function_exists('biz_day_of') ? biz_day_of($pdo, strtotime((string)$o['created_at'])) : substr((string)$o['created_at'], 0, 10); if ($d !== $day) continue;
            $tot = fn_p($o['total']); $cod = $o['outstanding'] !== null ? fn_p($o['outstanding']) : (in_array($fs, ['paid', 'partially_refunded'], true) ? 0 : $tot); $cod = max(0, min($tot, $cod)); $pre = $tot - $cod; if ($pre <= 0) continue;
            $refs = array_values(array_filter(array_map('fn_okey', explode(',', (string)($o['pay_ref'] ?? '')))));
            $out[] = ['key' => fn_okey($o['order_name']), 'name' => (string)$o['order_name'], 'customer' => (string)$o['customer'], 'phone' => substr(preg_replace('/\D+/', '', (string)$o['phone']), -10), 'amount' => fn_m($pre), 'refs' => $refs, 'at' => (string)$o['created_at']];
        }
    } catch (Throwable $e) { /* no Shopify copy here */ }
    usort($out, fn($a, $b) => strcmp($a['at'], $b['at']));
    return $out;
}
/**
 * THE COMPARISON: the prepaid orders of a day against the money rows of a settlement file. A file row belongs to an order when a cell of the row (the chosen order column first, then every cell) is the order number or the payment transaction id (KWIK...) saved on the order.
 * Result: per order found / not found with gross, charges, net and UTR; the file rows that match no order; the charges split by column; the totals.
 */
function fn_recon(array $rows, int $headRow, array $cols, array $orders, string $ordersDay): array
{
    $recs = fn_file_rows($rows, $headRow, $cols); $header = $rows[$headRow] ?? []; $oc = isset($cols['order']) ? (int)$cols['order'] : -1;
    $byKey = []; $byRef = []; foreach ($orders as $i => $o) { if ($o['key'] !== '') $byKey[$o['key']] = $i; foreach ($o['refs'] as $r) $byRef[$r] = $i; }
    $hit = []; $loose = []; $feeCols = []; $mF = 0; $mG = 0; $mN = 0;
    foreach ($recs as $rec) {
        $cells = $rows[$rec['i']] ?? []; $try = []; if ($oc >= 0 && isset($cells[$oc])) $try[] = fn_okey($cells[$oc]); foreach ($cells as $ci => $c) { if ($ci !== $oc) $try[] = fn_okey($c); }
        $idx = null; foreach ($try as $t) { if (strlen($t) < 5) continue; if (isset($byKey[$t])) { $idx = $byKey[$t]; break; } if (isset($byRef[$t])) { $idx = $byRef[$t]; break; } }
        if ($idx === null) { $loose[] = $rec; continue; }
        if (!isset($hit[$idx])) $hit[$idx] = ['gross' => 0, 'fee' => 0, 'net' => 0, 'utr' => $rec['utr'], 'rows' => 0];
        $hit[$idx]['gross'] += fn_p($rec['gross']); $hit[$idx]['fee'] += fn_p($rec['fee']); $hit[$idx]['net'] += fn_p($rec['net']); $hit[$idx]['rows']++;
        $mG += fn_p($rec['gross']); $mF += fn_p($rec['fee']); $mN += fn_p($rec['net']); foreach ($rec['parts'] as $ci => $v) $feeCols[$ci] = ($feeCols[$ci] ?? 0) + fn_p($v);
    }
    $list = []; $expP = 0; $missP = 0; $missN = 0; $diff = 0;
    foreach ($orders as $i => $o) {
        $h = $hit[$i] ?? null; $expP += fn_p($o['amount']); $row = ['name' => $o['name'], 'customer' => $o['customer'], 'amount' => $o['amount'], 'found' => $h !== null, 'gross' => $h ? fn_m($h['gross']) : 0, 'fee' => $h ? fn_m($h['fee']) : 0, 'net' => $h ? fn_m($h['net']) : 0, 'utr' => $h ? $h['utr'] : ''];
        if ($h) { if (abs(fn_m($h['gross']) - $o['amount']) > 1.0) { $row['differs'] = true; $diff++; } } else { $missP += fn_p($o['amount']); $missN++; }
        $list[] = $row;
    }
    $parts = []; foreach ($feeCols as $ci => $p) $parts[] = ['name' => trim((string)($header[$ci] ?? ('Column ' . ($ci + 1)))), 'amount' => fn_m($p)];
    $lp = 0; $ln = 0; $lrows = []; foreach ($loose as $r) { $lp += fn_p($r['net']); $ln++; if (count($lrows) < 40) $lrows[] = ['utr' => $r['utr'], 'gross' => round($r['gross'], 2), 'fee' => round($r['fee'], 2), 'net' => round($r['net'], 2)]; }
    return ['orders_day' => $ordersDay, 'expected' => ['orders' => count($orders), 'amount' => fn_m($expP)], 'found' => ['orders' => count($hit), 'gross' => fn_m($mG), 'fee' => fn_m($mF), 'net' => fn_m($mN), 'differs' => $diff], 'missing' => ['orders' => $missN, 'amount' => fn_m($missP)],
        'charges' => $parts, 'other_rows' => ['rows' => $ln, 'net' => fn_m($lp), 'list' => $lrows], 'orders' => array_slice($list, 0, 600), 'truncated' => count($list) > 600];
}
/** UTRs of this file already saved for this channel by another (not cancelled) file. */
function fn_utr_dups(PDO $pdo, int $channelId, array $utrs, int $exceptUpload = 0): array
{
    if (!$utrs) return [];
    $found = [];
    foreach (array_chunk($utrs, 400) as $part) {
        $q = $pdo->prepare("SELECT DISTINCT t.utr, u.file_name, u.day FROM fn_in_utr t JOIN fn_in_uploads u ON u.id = t.upload_id AND u.status = 'ok' WHERE t.channel_id = ? AND t.upload_id <> ? AND t.utr IN (" . implode(',', array_fill(0, count($part), '?')) . ")");
        $q->execute(array_merge([$channelId, $exceptUpload], $part)); foreach ($q->fetchAll() as $r) $found[$r['utr']] = $r['file_name'] . ' (' . $r['day'] . ')';
    }
    return $found;
}
/** The receipt line of an Incoming channel: its linked line, else a line with the channel's name (found or created). Returns the head id. */
function fn_channel_head(PDO $pdo, int $channelId): int
{
    $c = $pdo->prepare("SELECT * FROM fn_in_channels WHERE id = ?"); $c->execute([$channelId]); $ch = $c->fetch(); if (!$ch) fn_err('Channel not found.', 404);
    $hid = (int)$ch['head_id'];
    if ($hid) { $h = $pdo->prepare("SELECT COUNT(*) FROM fn_heads WHERE id = ? AND side = 'in'"); $h->execute([$hid]); if (!(int)$h->fetchColumn()) $hid = 0; }
    if (!$hid) {
        $f = $pdo->prepare("SELECT id FROM fn_heads WHERE side = 'in' AND LOWER(name) = LOWER(?)"); $f->execute([$ch['name']]); $hid = (int)$f->fetchColumn();
        if (!$hid) { $mx = (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) FROM fn_heads WHERE side = 'in'")->fetchColumn(); $pdo->prepare("INSERT INTO fn_heads (side, name, sort_no, created_at) VALUES ('in', ?, ?, ?)")->execute([$ch['name'], $mx + 10, date('Y-m-d H:i:s')]); $hid = (int)$pdo->lastInsertId(); }
        $pdo->prepare("UPDATE fn_in_channels SET head_id = ? WHERE id = ?")->execute([$hid, (int)$ch['id']]);
    }
    return $hid;
}
/** Cancel a saved UTR file: its receipt is cancelled too and its UTRs are free again. */
function fn_void_upload(PDO $pdo, array $up, string $reason): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE fn_in_uploads SET status = 'void', void_reason = ? WHERE id = ?")->execute([$reason, (int)$up['id']]);
        $pdo->prepare("DELETE FROM fn_in_utr WHERE upload_id = ?")->execute([(int)$up['id']]);
        if ((int)$up['entry_id']) $pdo->prepare("UPDATE fn_entries SET status = 'void', void_reason = ? WHERE id = ? AND status = 'ok'")->execute([mb_substr('UTR file cancelled: ' . $reason, 0, 200), (int)$up['entry_id']]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); error_log('fn_void_upload: ' . $e->getMessage()); fn_err('Could not cancel the file.', 500); }
}
/** v6: a saved UTR file keeps what it was compared with: the day of the orders and the comparison itself. */
function fn_ensure6(PDO $pdo): bool
{
    $flag = sys_get_temp_dir() . '/hrms_fn_tables_v6_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) return true;
    try {
        foreach (['orders_day' => "DATE NULL", 'recon_json' => "MEDIUMTEXT NULL"] as $col => $def) { try { $pdo->query("SELECT $col FROM fn_in_uploads LIMIT 1")->fetch(); } catch (Throwable $e) { $pdo->exec("ALTER TABLE fn_in_uploads ADD COLUMN $col $def"); } }
        @file_put_contents($flag, '1'); return true;
    } catch (Throwable $e) { error_log('fn_ensure6: ' . $e->getMessage()); return false; }
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

if (!fn_ensure($pdo) || !fn_ensure2($pdo) || !fn_ensure3($pdo) || !fn_ensure4($pdo) || !fn_ensure5($pdo) || !fn_ensure6($pdo)) fn_err('Could not create the Finance New tables.', 500);
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

    // INCOMING: the channels of Prepaid / COD with the money received on the day and in the month (from the receipt line each channel is linked to)
    case 'fn.in_channels': {
        $today = fn_today($pdo); $day = fn_day($_GET['date'] ?? '') ?: $today; if ($day > $today) $day = $today; $m0 = substr($day, 0, 8) . '01';
        $q = $pdo->prepare("SELECT head_id, SUM(CASE WHEN day = ? THEN amount ELSE 0 END) d, SUM(CASE WHEN day = ? THEN 1 ELSE 0 END) dn, SUM(amount) m FROM fn_entries WHERE status = 'ok' AND side = 'in' AND day BETWEEN ? AND ? GROUP BY head_id"); $q->execute([$day, $day, $m0, $day]); $sum = [];
        foreach ($q->fetchAll() as $r) $sum[(int)$r['head_id']] = [fn_p($r['d']), (int)$r['dn'], fn_p($r['m'])];
        $up = []; try { $uq = $pdo->prepare("SELECT channel_id, SUM(CASE WHEN day = ? THEN gross ELSE 0 END) gd, SUM(CASE WHEN day = ? THEN fee ELSE 0 END) fd, SUM(gross) gm, SUM(fee) fm, COUNT(*) n FROM fn_in_uploads WHERE status = 'ok' AND day BETWEEN ? AND ? GROUP BY channel_id"); $uq->execute([$day, $day, $m0, $day]); foreach ($uq->fetchAll() as $x) $up[(int)$x['channel_id']] = $x; } catch (Throwable $e) {}
        $heads = []; foreach ($pdo->query("SELECT id, name, active FROM fn_heads WHERE side = 'in' ORDER BY sort_no, name")->fetchAll() as $h) $heads[(int)$h['id']] = ['id' => (int)$h['id'], 'name' => $h['name'], 'active' => (int)$h['active']];
        $out = ['prepaid' => [], 'cod' => []];
        foreach ($pdo->query("SELECT * FROM fn_in_channels ORDER BY active DESC, sort_no, name")->fetchAll() as $c) {
            $s = $sum[(int)$c['head_id']] ?? [0, 0, 0]; if (!isset($out[$c['section']])) continue;
            $out[$c['section']][] = ['id' => (int)$c['id'], 'name' => $c['name'], 'head_id' => (int)$c['head_id'], 'head' => $heads[(int)$c['head_id']]['name'] ?? '', 'active' => (int)$c['active'], 'day' => fn_m($s[0]), 'n' => $s[1], 'month' => fn_m($s[2]), 'gross_day' => round((float)($up[(int)$c['id']]['gd'] ?? 0), 2), 'fee_day' => round((float)($up[(int)$c['id']]['fd'] ?? 0), 2), 'gross_month' => round((float)($up[(int)$c['id']]['gm'] ?? 0), 2), 'fee_month' => round((float)($up[(int)$c['id']]['fm'] ?? 0), 2), 'files_month' => (int)($up[(int)$c['id']]['n'] ?? 0)];
        }
        json_response(['date' => $day, 'month_from' => $m0, 'channels' => $out, 'heads' => array_values($heads)]);
        break;
    }
    case 'fn.in_channel_save': {
        $in = json_input(); $id = (int)($in['id'] ?? 0); $sec = (string)($in['section'] ?? ''); $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 80)); if (mb_strlen($name) < 2) fn_err('Give the channel a name.');
        if ($id) { $c = $pdo->prepare("SELECT section FROM fn_in_channels WHERE id = ?"); $c->execute([$id]); $sec = (string)$c->fetchColumn(); }
        if (!in_array($sec, ['prepaid', 'cod'], true)) fn_err('Choose Prepaid or COD.');
        $dup = $pdo->prepare("SELECT COUNT(*) FROM fn_in_channels WHERE section = ? AND LOWER(name) = LOWER(?) AND id <> ?"); $dup->execute([$sec, $name, $id]); if ((int)$dup->fetchColumn()) fn_err('This channel already exists.');
        $hid = (int)($in['head_id'] ?? 0); if ($hid) { $h = $pdo->prepare("SELECT COUNT(*) FROM fn_heads WHERE id = ? AND side = 'in'"); $h->execute([$hid]); if (!(int)$h->fetchColumn()) fn_err('Choose a receipt line from the list.'); }
        $act = !isset($in['active']) || !empty($in['active']) ? 1 : 0;
        if ($id) { $pdo->prepare("UPDATE fn_in_channels SET name = ?, head_id = ?, active = ? WHERE id = ?")->execute([$name, $hid, $act, $id]); }
        else { $mx = $pdo->prepare("SELECT COALESCE(MAX(sort_no), 0) FROM fn_in_channels WHERE section = ?"); $mx->execute([$sec]); $pdo->prepare("INSERT INTO fn_in_channels (section, name, head_id, active, sort_no, created_at) VALUES (?,?,?,1,?,?)")->execute([$sec, $name, $hid, (int)$mx->fetchColumn() + 10, date('Y-m-d H:i:s')]); $id = (int)$pdo->lastInsertId(); }
        json_response(['ok' => true, 'id' => $id]);
        break;
    }

    // the receipt line of an Incoming channel: its linked line, else a line with the channel's name (found or created). An entry added in Incoming lands on that line, so the Receipt and Payment tab shows it by itself.
    case 'fn.in_channel_head': {
        $in = json_input(); json_response(['ok' => true, 'head_id' => fn_channel_head($pdo, (int)($in['id'] ?? 0))]);
        break;
    }
    // UTR FILE of a channel, step 1: read it and guess the columns (nothing is saved as money yet)
    case 'fn.in_upload_preview': {
        $cid = (int)($_POST['channel_id'] ?? 0); $c = $pdo->prepare("SELECT id FROM fn_in_channels WHERE id = ?"); $c->execute([$cid]); if (!$c->fetchColumn()) fn_err('Channel not found.', 404);
        $f = $_FILES['file'] ?? null; if (!$f || (int)($f['error'] ?? 1) !== UPLOAD_ERR_OK) fn_err('The file did not arrive. Try again.');
        if ((int)$f['size'] > 12 * 1024 * 1024) fn_err('The file is too big (max 12 MB).');
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)); if (!in_array($ext, ['xlsx', 'csv', 'txt', 'tsv'], true)) fn_err('Upload an Excel (.xlsx) or CSV file. An old .xls: open it and Save as .xlsx first.');
        try { $rows = fn_read_sheet((string)$f['tmp_name'], (string)$f['name']); } catch (Throwable $e) { fn_err($e->getMessage()); }
        if (count($rows) < 2) fn_err('The file has no data rows.');
        $hr = fn_header_row($rows); $header = array_map('strval', $rows[$hr] ?? []); $cols = fn_guess_cols($header); $calc = fn_calc_file($rows, $hr, $cols); $hash = md5_file((string)$f['tmp_name']);
        $dupFile = $pdo->prepare("SELECT file_name, day FROM fn_in_uploads WHERE channel_id = ? AND file_hash = ? AND status = 'ok' LIMIT 1"); $dupFile->execute([$cid, $hash]); $df = $dupFile->fetch();
        $pdo->prepare("INSERT INTO fn_in_uploads (channel_id, day, file_name, file_hash, head_row, rows_json, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$cid, fn_today($pdo), mb_substr((string)$f['name'], 0, 200), $hash, $hr, json_encode($rows, JSON_UNESCAPED_UNICODE), fn_who($auth), date('Y-m-d H:i:s')]);
        $uid = (int)$pdo->lastInsertId(); $dups = fn_utr_dups($pdo, $cid, $calc['utrs']);
        json_response(['upload_id' => $uid, 'file' => $f['name'], 'provider' => (string)($cols['provider'] ?? ''), 'rows' => count($rows) - $hr - 1, 'header' => $header, 'cols' => $cols, 'sample' => array_slice($rows, $hr + 1, 5), 'calc' => ['rows' => $calc['rows'], 'gross' => $calc['gross'], 'fee' => $calc['fee'], 'net' => $calc['net'], 'utrs' => count($calc['utrs']), 'dups' => count($dups), 'dup_first' => $dups ? array_values($dups)[0] : ''], 'same_file' => $df ? $df['file_name'] . ' (' . $df['day'] . ')' : '']);
        break;
    }
    // step 2 (as often as the owner changes a column): the totals for the chosen columns
    case 'fn.in_upload_calc': {
        $in = json_input(); $u = $pdo->prepare("SELECT * FROM fn_in_uploads WHERE id = ? AND status = 'preview'"); $u->execute([(int)($in['upload_id'] ?? 0)]); $up = $u->fetch(); if (!$up) fn_err('This upload was already saved, or not found.', 404);
        $rows = json_decode((string)$up['rows_json'], true) ?: []; $cols = (array)($in['cols'] ?? []); $calc = fn_calc_file($rows, (int)$up['head_row'], $cols); $dups = fn_utr_dups($pdo, (int)$up['channel_id'], $calc['utrs']);
        $od = fn_day($in['orders_day'] ?? ''); $recon = $od !== '' ? fn_recon($rows, (int)$up['head_row'], $cols, fn_prepaid_orders($pdo, $od), $od) : null;
        json_response(['rows' => $calc['rows'], 'gross' => $calc['gross'], 'fee' => $calc['fee'], 'net' => $calc['net'], 'utrs' => count($calc['utrs']), 'dups' => count($dups), 'dup_first' => $dups ? array_values($dups)[0] : '', 'recon' => $recon]);
        break;
    }
    // step 3: save it. The NET amount becomes a receipt on the channel's line (so the Receipt and Payment tab and the bank balance show it by themselves)
    case 'fn.in_upload_commit': {
        $in = json_input(); $today = fn_today($pdo); $u = $pdo->prepare("SELECT * FROM fn_in_uploads WHERE id = ? AND status = 'preview'"); $u->execute([(int)($in['upload_id'] ?? 0)]); $up = $u->fetch(); if (!$up) fn_err('This upload was already saved, or not found.', 404);
        $day = fn_day($in['date'] ?? ''); if ($day === '') fn_err('Choose the day this money came.'); if ($day > $today) fn_err('The date is in the future.');
        $a = $pdo->prepare("SELECT id FROM fn_accounts WHERE id = ? AND active = 1"); $a->execute([(int)($in['account_id'] ?? 0)]); if (!$a->fetchColumn()) fn_err('Choose the bank the money came into.');
        $rows = json_decode((string)$up['rows_json'], true) ?: []; $calc = fn_calc_file($rows, (int)$up['head_row'], (array)($in['cols'] ?? [])); $cid = (int)$up['channel_id'];
        if ($calc['rows'] < 1 || $calc['net'] <= 0) fn_err('No money found with these columns. Check the UTR and amount columns.');
        $dups = fn_utr_dups($pdo, $cid, $calc['utrs']); if ($dups) fn_err(count($dups) . ' UTR' . (count($dups) === 1 ? ' is' : 's are') . ' already saved from ' . array_values($dups)[0] . '. Cancel that file first if you want to upload this one.');
        $same = $pdo->prepare("SELECT file_name, day FROM fn_in_uploads WHERE channel_id = ? AND file_hash = ? AND status = 'ok' AND id <> ? LIMIT 1"); $same->execute([$cid, $up['file_hash'], (int)$up['id']]); if ($r0 = $same->fetch()) fn_err('This same file was already saved (' . $r0['file_name'] . ', ' . $r0['day'] . ').');
        $note = mb_substr('UTR file ' . $up['file_name'] . ' · ' . $calc['rows'] . ' rows · gross ' . number_format($calc['gross'], 2) . ' − charges ' . number_format($calc['fee'], 2), 0, 255);
        $od = fn_day($in['orders_day'] ?? ''); $recon = $od !== '' ? fn_recon($rows, (int)$up['head_row'], (array)($in['cols'] ?? []), fn_prepaid_orders($pdo, $od), $od) : null;
        $hid = fn_channel_head($pdo, $cid); $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO fn_entries (day, side, head_id, account_id, amount, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$day, 'in', $hid, (int)$in['account_id'], $calc['net'], $note, fn_who($auth), date('Y-m-d H:i:s')]); $eid = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE fn_in_uploads SET status = 'ok', day = ?, rows_n = ?, gross = ?, fee = ?, net = ?, account_id = ?, entry_id = ?, orders_day = ?, recon_json = ?, rows_json = NULL WHERE id = ?")->execute([$day, $calc['rows'], $calc['gross'], $calc['fee'], $calc['net'], (int)$in['account_id'], $eid, $od !== '' ? $od : null, $recon ? json_encode($recon, JSON_UNESCAPED_UNICODE) : null, (int)$up['id']]);
            $ins = $pdo->prepare("INSERT INTO fn_in_utr (upload_id, channel_id, utr) VALUES (?,?,?)"); foreach ($calc['utrs'] as $t) $ins->execute([(int)$up['id'], $cid, mb_substr($t, 0, 80)]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); error_log('fn.in_upload_commit: ' . $e->getMessage()); fn_err('Could not save the file. Nothing was changed.', 500); }
        json_response(['ok' => true, 'net' => $calc['net'], 'gross' => $calc['gross'], 'fee' => $calc['fee'], 'rows' => $calc['rows'], 'entry_id' => $eid]);
        break;
    }
    // the saved files of one channel
    case 'fn.in_uploads': {
        $cid = (int)($_GET['channel_id'] ?? 0);
        $q = $pdo->prepare("SELECT u.*, a.name AS account_name FROM fn_in_uploads u LEFT JOIN fn_accounts a ON a.id = u.account_id WHERE u.channel_id = ? AND u.status IN ('ok','void') ORDER BY u.day DESC, u.id DESC LIMIT 60"); $q->execute([$cid]);
        $list = array_map(fn($u) => ['id' => (int)$u['id'], 'day' => $u['day'], 'file' => $u['file_name'], 'rows' => (int)$u['rows_n'], 'gross' => (float)$u['gross'], 'fee' => (float)$u['fee'], 'net' => (float)$u['net'], 'account' => (string)$u['account_name'], 'status' => $u['status'], 'void_reason' => $u['void_reason'], 'by' => $u['created_by'], 'at' => $u['created_at'], 'orders_day' => (string)($u['orders_day'] ?? ''), 'has_recon' => (string)($u['recon_json'] ?? '') !== ''], $q->fetchAll());
        json_response(['uploads' => $list]);
        break;
    }
    // the prepaid orders of a day (before any file is uploaded): the list the settlement file will be compared with
    case 'fn.in_orders_day': {
        $d = fn_day($_GET['date'] ?? '') ?: date('Y-m-d', strtotime(fn_today($pdo) . ' -1 day')); $list = fn_prepaid_orders($pdo, $d); $tot = 0; foreach ($list as $o) $tot += fn_p($o['amount']);
        json_response(['orders_day' => $d, 'count' => count($list), 'amount' => fn_m($tot), 'orders' => array_map(fn($o) => ['name' => $o['name'], 'customer' => $o['customer'], 'amount' => $o['amount']], array_slice($list, 0, 600))]);
        break;
    }
    case 'fn.in_upload_recon': {
        $q = $pdo->prepare("SELECT file_name, day, net, recon_json FROM fn_in_uploads WHERE id = ?"); $q->execute([(int)($_GET['id'] ?? 0)]); $u = $q->fetch(); if (!$u || (string)$u['recon_json'] === '') fn_err('No comparison was saved for this file.', 404);
        json_response(['file' => $u['file_name'], 'day' => $u['day'], 'recon' => json_decode((string)$u['recon_json'], true)]);
        break;
    }
    case 'fn.in_upload_void': {
        $in = json_input(); $reason = trim(mb_substr((string)($in['reason'] ?? ''), 0, 200)); if (mb_strlen($reason) < 3) fn_err('Write why this file is cancelled.');
        $u = $pdo->prepare("SELECT * FROM fn_in_uploads WHERE id = ? AND status = 'ok'"); $u->execute([(int)($in['id'] ?? 0)]); $up = $u->fetch(); if (!$up) fn_err('File not found, or already cancelled.', 404);
        fn_void_upload($pdo, $up, $reason);
        json_response(['ok' => true]);
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
        $eid = (int)($in['id'] ?? 0); $own = $pdo->prepare("SELECT * FROM fn_in_uploads WHERE entry_id = ? AND status = 'ok'"); $own->execute([$eid]); if ($ou = $own->fetch()) { fn_void_upload($pdo, $ou, $reason); json_response(['ok' => true]); }      // the receipt of a UTR file: the whole file is cancelled
        $u = $pdo->prepare("UPDATE fn_entries SET status = 'void', void_reason = ? WHERE id = ? AND status = 'ok'"); $u->execute([$reason, $eid]); if (!$u->rowCount()) fn_err('Entry not found, or already cancelled.', 404);
        json_response(['ok' => true]);
        break;
    }
    default: json_response(['error' => 'Unknown Finance New action.'], 404);
}
