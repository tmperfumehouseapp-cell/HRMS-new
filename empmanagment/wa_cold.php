<?php
/** wa_cold.php — the COLD FUNNEL (owner 22 Nov): customers who were DELIVERED our messages (from 1 Nov) but never wrote back.
 *  Three funnels, one customer in one of them at a time:  cold (this file)  ->  30-day Lineup (he wrote)  ->  After-Order (he bought).
 *  Who enters: a delivered template message since wcd_from (default 2026-11-01), the customer never wrote (no inbound message ever, wa_contacts.last_in_at empty),
 *    not opted out, not active in the Lineup / After-Order. Found by walking wa_messages by id (a pointer, 8000 ids a minute) - no heavy scans.
 *  What goes: only UTILITY messages (the Lineup's utility twins and the shop's approved utility pool), never the same message twice to one customer, at most one a day, 09:00-21:00 (03-09 is universal quiet).
 *  When: gaps in days after message 1..6 = 3, 4, 7, 7, 9, 9 (read but silent), the same doubled from the 3rd message for customers who never opened one; then every 7 days up to 90 days after entering, then done.
 *  Leaves: wrote (the Lineup takes him), ordered (After-Order takes him), opted out, 90 days.
 *  Mode (setting wcd_mode): 'live' (default, owner 22 Nov) = send · 'dry' = enrol and count, send nothing · 'off'. Daily cap wcd_cap (default 15000 since 23 Nov).
 *  The LEDGER = wa_cold_cust (one row a customer: entered, segment, sent, last sent, status) + wa_cold_log (one row a message). The summary joins them with receipts, replies, Lineup rows and shop orders.
 *  Functions only (PHP 7.4 safe); called from the Lineup tick and by diag_ask.php. */
if (!function_exists('wcd_tick')) {
    function wcd_tables(PDO $pdo): bool
    {
        static $ok = null; if ($ok !== null) return $ok;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS wa_cold_cust (phone VARCHAR(12) NOT NULL PRIMARY KEY, entered_at DATETIME NOT NULL, seg VARCHAR(8) NOT NULL DEFAULT 'unread', n_prior INT NOT NULL DEFAULT 0, n_sent INT NOT NULL DEFAULT 0, last_sent_at DATETIME NULL, next_due DATETIME NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'active', why VARCHAR(80) NOT NULL DEFAULT '', left_at DATETIME NULL, KEY k_due (status, next_due), KEY k_st (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS wa_cold_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, at DATETIME NOT NULL, tpl_id INT NOT NULL DEFAULT 0, ok TINYINT NOT NULL DEFAULT 0, why VARCHAR(160) NOT NULL DEFAULT '', wamid VARCHAR(80) NOT NULL DEFAULT '', step INT NOT NULL DEFAULT 0, KEY k_ph (phone), KEY k_at (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return $ok = true;
        } catch (Throwable $e) { return $ok = false; }
    }
    function wcd_k10($p): string { return substr(preg_replace('/\D+/', '', (string)$p), -10); }
    function wcd_set(PDO $pdo, string $k, string $d = ''): string { $v = setting_get($pdo, $k, $d); return $v === '' ? $d : (string)$v; }
    /** Days to wait after the n-th message (n = messages sent so far by this funnel), by segment. */
    function wcd_gap(int $n, string $seg): int
    {
        $g = [1 => 3, 2 => 4, 3 => 7, 4 => 7, 5 => 9, 6 => 9]; $d = $g[$n] ?? 7;
        if ($seg !== 'read' && $n >= 2) $d *= 2;   /* never opened one: half as often */
        return $d;
    }
    /** The first message id on or after a date (binary search on the primary key: no scan). */
    function wcd_first_id(PDO $pdo, string $from): int
    {
        $max = (int)$pdo->query("SELECT MAX(id) FROM wa_messages")->fetchColumn(); $lo = 1; $hi = $max; $q = $pdo->prepare("SELECT ts FROM wa_messages WHERE id >= ? ORDER BY id LIMIT 1");
        for ($i = 0; $i < 40 && $lo < $hi; $i++) { $mid = intdiv($lo + $hi, 2); $q->execute([$mid]); $ts = (string)$q->fetchColumn(); if ($ts !== '' && $ts >= $from) $hi = $mid; else $lo = $mid + 1; }
        return max(0, $lo - 1);
    }
    /** Enrol the next 8000 message ids (only ids whose message is 6+ hours old, so its delivery status has settled). [looked at phones, entered]. */
    function wcd_enrol(PDO $pdo, int $now, float $budget = 15.0): array
    {
        $t0 = microtime(true); $o = [0, 0]; $ptr = (int)wcd_set($pdo, 'wcd_ptr', '0');
        if ($ptr <= 0) { $ptr = wcd_first_id($pdo, wcd_set($pdo, 'wcd_from', '2026-11-01') . ' 00:00:00'); setting_set($pdo, 'wcd_ptr', (string)$ptr); }
        $hi = $ptr + 8000; $q = $pdo->prepare("SELECT ts FROM wa_messages WHERE id >= ? ORDER BY id LIMIT 1"); $q->execute([$hi]); $tsHi = (string)$q->fetchColumn();
        if ($tsHi === '' || (int)strtotime($tsHi) > $now - 6 * 3600) { $m = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM wa_messages WHERE ts <= '" . date('Y-m-d H:i:s', $now - 6 * 3600) . "' AND id > " . $ptr . " LIMIT 1"); $hi = (int)$m->fetchColumn(); if ($hi <= $ptr) return $o; $hi = min($hi, $ptr + 8000); }
        $st = $pdo->prepare("SELECT phone, MAX(status = 'read') rd, MAX(ts) lt, COUNT(*) n FROM wa_messages WHERE id > ? AND id <= ? AND dir = 'out' AND mtype = 'template' AND status IN ('delivered', 'read') GROUP BY phone"); $st->execute([$ptr, $hi]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); $by = [];
        foreach ($rows as $r) { $k = wcd_k10($r['phone']); if (strlen($k) === 10) $by[$k] = $r; }
        $o[0] = count($by); $keys = array_keys($by);
        foreach (array_chunk($keys, 400) as $ch) {
            if (microtime(true) - $t0 > $budget) return $o;   /* the pointer stays: this chunk is looked at again */
            $in = implode(',', array_fill(0, count($ch), '?')); $skip = [];
            foreach (["SELECT phone FROM wa_cold_cust WHERE phone IN ($in)", "SELECT DISTINCT phone FROM wa_messages WHERE dir = 'in' AND phone IN ($in)", "SELECT phone FROM wa_line_cust WHERE status = 'active' AND phone IN ($in)", "SELECT phone FROM wa_aft_cust WHERE status = 'active' AND phone IN ($in)", "SELECT phone FROM wa_contacts WHERE (optout = 1 OR camp_optout = 1 OR last_in_at IS NOT NULL) AND phone IN ($in)"] as $sql) {
                try { $x = $pdo->prepare($sql); $x->execute($ch); foreach ($x->fetchAll(PDO::FETCH_COLUMN) as $p) $skip[(string)$p] = 1; } catch (Throwable $e) { /* a table that does not exist yet: nothing to skip */ }
            }
            $ins = $pdo->prepare("INSERT IGNORE INTO wa_cold_cust (phone, entered_at, seg, n_prior, n_sent, next_due, status, why) VALUES (?, ?, ?, ?, 0, ?, 'active', '')");
            foreach ($ch as $k) { if (isset($skip[$k])) continue; $r = $by[$k]; $lt = (int)strtotime((string)$r['lt']); $due = max($now, $lt + 3 * 86400);
                try { $ins->execute([$k, date('Y-m-d H:i:s', $now), !empty($r['rd']) ? 'read' : 'unread', (int)$r['n'], date('Y-m-d H:i:s', $due)]); $o[1] += (int)$ins->rowCount(); } catch (Throwable $e) { } }
        }
        setting_set($pdo, 'wcd_ptr', (string)$hi);
        return $o;
    }
    /** Customers who left: wrote / ordered / opted out / 90 days. Cheap batch pass on the active rows that are due or old. */
    function wcd_orders(PDO $pdo, string $from): array
    {
        static $cache = []; $ck = $from . '|' . intdiv(time(), 120); if (isset($cache[$ck])) return $cache[$ck]; $cache = []; $o = [];
        try { foreach ($pdo->query("SELECT phone, created_at, total FROM shop_orders WHERE created_at >= '" . $from . "' AND phone IS NOT NULL AND phone <> '' AND financial_status NOT IN ('voided', 'refunded')")->fetchAll(PDO::FETCH_NUM) as $r) { $k = wcd_k10($r[0]); if (strlen($k) === 10) $o[$k][] = [(int)strtotime((string)$r[1]), (int)$r[2]]; } } catch (Throwable $e) { }
        return $cache[$ck] = $o;
    }
    /** THE COLD FUNNEL'S OWN MESSAGES (owner 22 Nov: "write new messages, decide marketing or utility yourself"): eight UTILITY wordings that ask ONE easy question and carry a quick-reply button, so a customer who never wrote answers with one tap
     *  (a tap is a reply: the Lineup takes him). Utility = ₹0.15 and no marketing frequency cap (131049). They refer to the earlier message / his own requirement (Meta keeps those utility; a pure offer would be re-labelled marketing).
     *  Made once, sent to every WhatsApp account of the shop (what Meta's weekly creation limit refuses is queued and goes later); used only while Meta keeps them UTILITY and approved. */
    function wcd_pack(): array
    {
        return [
            'cd_ask1' => ['body' => "Namaste {{1}}, TM Perfume House se pichhle message ke baad aapse baat nahi ho payi. Aapko kaunsa perfume ya kitni bottle chahiye? Bas yahin reply kijiye, hum price aur availability bata denge.", 'btn' => ['Price bhejiye']],
            'cd_ask2' => ['body' => "Hi {{1}}, aapki perfume requirement ke liye hum yahin available hain. Aap kis size mein dekhna chahte hain - 20 ml, 50 ml ya 100 ml? Ek reply kijiye, hum details bhej denge.", 'btn' => ['50 ml dikhaiye', '100 ml dikhaiye']],
            'cd_ask3' => ['body' => "Namaste {{1}} ji, TM Perfume House ki taraf se ek chhota sa sawal: aapko men, women ya unisex mein se kaunsi khushbu chahiye? Bata dijiye, hum aapke liye suggestions bhej denge.", 'btn' => ['Men', 'Women', 'Unisex']],
            'cd_ask4' => ['body' => "Namaste {{1}}, kya aapko hamare pichhle message ke perfumes ya price ke baare mein koi sawal hai? Yahin reply kijiye, hum turant madad karenge.", 'btn' => ['Haan, sawal hai']],
            'cd_ask5' => ['body' => "Hi {{1}}, agar aap kisi ke liye gift perfume dekh rahe hain to bata dijiye kiske liye aur budget kitna hai. Hum usi hisaab se options bhej denge.", 'btn' => ['Gift ke liye']],
            'cd_ask6' => ['body' => "Namaste {{1}} ji, aapka order banane ke liye bas perfume ka naam aur bottle count chahiye. Reply karke bata dijiye, baaki hum sambhal lenge.", 'btn' => ['Order banana hai']],
            'cd_ask7' => ['body' => "Namaste {{1}}, perfume ki quality, khushbu ya sample ke baare mein kuch janna ho to yahin poochh sakte hain. Hum details aur real customer ratings bhej denge.", 'btn' => ['Details bhejiye']],
            'cd_ask8' => ['body' => "Hi {{1}}, aapke pichhle message ke baare mein dobara jud rahe hain. Kya aap abhi perfume lene ke baare mein soch rahe hain? Haan ya baad mein, ek reply de dijiye.", 'btn' => ['Haan, dikhaiye', 'Baad mein']],
        ];
    }
    function wcd_ids(PDO $pdo, bool $mk = false): array { $j = json_decode(wcd_set($pdo, $mk ? 'wcd_tpls_m' : 'wcd_tpls', ''), true); return is_array($j) ? array_map('intval', $j) : []; }   /* $mk = the MARKETING copies of the same eight questions (owner 23 Nov: the fallback for a customer to whom no utility message can go) */
    /** Make the pack at Meta (once; the missing ones are tried again every 6 hours). [made, sent to Meta accounts, failures]. */
    function wcd_make(PDO $pdo, string $cat = 'UTILITY'): array
    {
        $res = ['made' => 0, 'sent' => 0, 'failed' => []];
        foreach (['wa_tpl_clean', 'wa_tpl_insert', 'wa_tpl_row', 'wa_wabas'] as $f) if (!function_exists($f)) { $res['failed'][] = 'template module missing'; return $res; }
        $mkc = $cat !== 'UTILITY'; $ids = wcd_ids($pdo, $mkc); $wabas = wa_wabas($pdo); $budget = ['left' => 12, 'made' => 0];
        foreach (wcd_pack() as $k0 => $m) { $k = $mkc ? str_replace('cd_ask', 'cd_askm', $k0) : $k0;
            if (!empty($ids[$k])) continue;
            $btn = []; foreach ($m['btn'] as $b) $btn[] = ['type' => 'QUICK_REPLY', 'text' => $b];
            [$t, $err] = wa_tpl_clean(['name' => $k, 'language' => 'en', 'category' => $mkc ? 'MARKETING' : 'UTILITY', 'body' => $m['body'], 'footer' => 'TM Perfume House', 'buttons' => $btn, 'examples' => ['body' => ['Rahul'], 'labels' => ['Name']], 'folder' => 'Cold']);
            if ($err !== '') { $res['failed'][] = $k . ': ' . $err; continue; }
            $id = wa_tpl_insert($pdo, $t, 0, 'Cold funnel: asks one easy question of a customer who got our message and never wrote; one tap answers.', 'Utility follow-up to a customer we messaged.');
            $r = wa_tpl_row($pdo, $id); if (!$r) { $res['failed'][] = $k . ': could not save'; continue; }
            $ids[$k] = (int)$id; setting_set($pdo, $mkc ? 'wcd_tpls_m' : 'wcd_tpls', json_encode($ids)); $res['made']++;
            foreach ($wabas as $w) { if (function_exists('wa_tpl_try_create')) [$ok, $why] = wa_tpl_try_create($pdo, $r, $w, $budget); elseif (function_exists('wa_tpl_create_at')) [$ok, $why] = wa_tpl_create_at($pdo, $r, $w); else { $ok = false; $why = 'no submit function'; } if ($ok) $res['sent']++; else $res['failed'][] = $k . ' -> ' . mb_substr((string)$why, 0, 60); }
        }
        return $res;
    }
    /** Own messages that Meta keeps as UTILITY, best first by what the ranking says. [[id, key]]. */
    function wcd_own(PDO $pdo): array
    {
        $ids = wcd_ids($pdo); if (!$ids) return []; $o = [];
        try { $q = $pdo->query("SELECT id, UPPER(COALESCE(NULLIF(category_now, ''), category)) c FROM wa_templates WHERE id IN (" . implode(',', array_map('intval', array_values($ids))) . ")"); $cat = $q->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) { return []; }
        $ap = []; try { foreach ($pdo->query("SELECT DISTINCT tpl_id FROM wa_tpl_waba WHERE UPPER(status) = 'APPROVED' AND tpl_id IN (" . implode(',', array_map('intval', array_values($ids))) . ")")->fetchAll(PDO::FETCH_COLUMN) as $t) $ap[(int)$t] = 1; } catch (Throwable $e) { }   /* Meta must have approved it on at least one account (the weekly creation limit may still hold the others back) */
        foreach ($ids as $k => $id) if (($cat[$id] ?? '') === 'UTILITY' && isset($ap[(int)$id])) $o[] = [(int)$id, (string)$k];
        return $o;
    }
    /** The MARKETING copies of the eight questions that Meta approved on at least one account (fallback only, Rs 1.09 each). [[id, key]]. */
    function wcd_own_m(PDO $pdo): array
    {
        $ids = wcd_ids($pdo, true); if (!$ids) return []; $o = [];
        $ap = []; try { foreach ($pdo->query("SELECT DISTINCT tpl_id FROM wa_tpl_waba WHERE UPPER(status) = 'APPROVED' AND tpl_id IN (" . implode(',', array_map('intval', array_values($ids))) . ")")->fetchAll(PDO::FETCH_COLUMN) as $t) $ap[(int)$t] = 1; } catch (Throwable $e) { }
        foreach ($ids as $k => $id) if (isset($ap[(int)$id])) $o[] = [(int)$id, (string)$k];
        return $o;
    }
    /** The marketing fallback for one customer: the approved marketing copies he did not get yet, best reply rate first. */
    function wcd_templates_m(PDO $pdo, string $ph): array
    {
        $tw = wcd_own_m($pdo); if (!$tw) return [];
        $got = []; try { $q = $pdo->prepare("SELECT tpl_id FROM wa_cold_log WHERE phone = ? AND ok = 1"); $q->execute([$ph]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) $got[(int)$t] = 1; } catch (Throwable $e) { }
        $o = []; foreach ($tw as $r) if (!isset($got[(int)$r[0]])) $o[] = $r;
        $st = wcd_tpl_stats($pdo); if (count($o) > 1 && $st) usort($o, function ($a, $b) use ($st) { $sa = $st[(int)$a[0]] ?? ['sent' => 0, 'replied' => 0]; $sb = $st[(int)$b[0]] ?? ['sent' => 0, 'replied' => 0]; return (($sb['replied'] + 0.5) / ($sb['sent'] + 50)) <=> (($sa['replied'] + 0.5) / ($sa['sent'] + 50)); });
        return $o;
    }
    /** WHAT WORKS (owner 22 Nov: "change every day by what brings the best replies"): per template [sent, replied, orders]. A reply is credited to the LAST Cold message sent to him before he wrote. Cached 5 minutes. */
    function wcd_tpl_stats(PDO $pdo): array
    {
        $c = json_decode(wcd_set($pdo, 'wcd_tpl_stats', ''), true); if (is_array($c) && (int)($c['t'] ?? 0) > time() - 300) return (array)($c['s'] ?? []);
        $s = [];
        try { foreach ($pdo->query("SELECT tpl_id, COUNT(*) FROM wa_cold_log WHERE ok = 1 GROUP BY tpl_id")->fetchAll(PDO::FETCH_NUM) as $r) $s[(int)$r[0]] = ['sent' => (int)$r[1], 'replied' => 0, 'orders' => 0];
            $q = $pdo->prepare("SELECT tpl_id FROM wa_cold_log WHERE phone = ? AND ok = 1 AND at <= ? ORDER BY id DESC LIMIT 1"); $ord = wcd_orders($pdo, wcd_set($pdo, 'wcd_from', '2026-11-01') . ' 00:00:00');
            foreach ($pdo->query("SELECT phone, left_at, entered_at FROM wa_cold_cust WHERE status = 'replied' AND left_at IS NOT NULL ORDER BY left_at DESC LIMIT 3000")->fetchAll(PDO::FETCH_NUM) as $r) { $q->execute([(string)$r[0], (string)$r[1]]); $t = (int)$q->fetchColumn(); if ($t > 0 && isset($s[$t])) { $s[$t]['replied']++; foreach ($ord[(string)$r[0]] ?? [] as $oo) if ($oo[0] >= (int)strtotime((string)$r[2])) { $s[$t]['orders']++; break; } } } } catch (Throwable $e) { }
        try { setting_set($pdo, 'wcd_tpl_stats', json_encode(['t' => time(), 's' => $s])); } catch (Throwable $e) { }
        return $s;
    }
    /** The utility messages usable for him, never one already sent to him by this funnel. [[template id, key]]. */
    function wcd_templates(PDO $pdo, string $ph): array
    {
        $tw = wcd_own($pdo); if (function_exists('wln_twins')) { try { foreach (wln_twins($pdo, $ph, 'general', 1, 0) as $__t) $tw[] = $__t; } catch (Throwable $e) { } } if (!$tw) return [];   /* his own question messages first, the Lineup's utility pool as the back-up */
        $got = []; try { $q = $pdo->prepare("SELECT tpl_id FROM wa_cold_log WHERE phone = ? AND ok = 1"); $q->execute([$ph]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) $got[(int)$t] = 1; } catch (Throwable $e) { }
        $o = []; foreach ($tw as $r) if (!isset($got[(int)$r[0]])) $o[] = $r;
        $st = wcd_tpl_stats($pdo); if (count($o) > 1 && $st) usort($o, function ($a, $b) use ($st) { $sa = $st[(int)$a[0]] ?? ['sent' => 0, 'replied' => 0]; $sb = $st[(int)$b[0]] ?? ['sent' => 0, 'replied' => 0]; return (($sb['replied'] + 0.5) / ($sb['sent'] + 50)) <=> (($sa['replied'] + 0.5) / ($sa['sent'] + 50)); });   /* the message with the best reply rate goes first (a new one starts at a fair guess and is judged after its first sends) */
        return $o;
    }
    /** The minute job. [entered, sent, waiting, left]. */
    function wcd_tick(PDO $pdo, int $budget = 30): array
    {
        $o = ['entered' => 0, 'sent' => 0, 'waiting' => 0, 'left' => 0]; $mode = wcd_set($pdo, 'wcd_mode', 'live'); if ($mode === 'off' || !wcd_tables($pdo)) return $o;
        if (!function_exists('wa_lock') || !wa_lock($pdo, 'wcdtick', 55)) return $o; $now = time(); $t0 = microtime(true); $o['mode'] = $mode;
        try { setting_set($pdo, 'wcd_tick_at', date('Y-m-d H:i:s', $now)); } catch (Throwable $e) { }
        try { $en = [0, 0]; for ($__i = 0; $__i < 8 && microtime(true) - $t0 < 10.0; $__i++) { $__e = wcd_enrol($pdo, $now, 10.0); $en[0] += $__e[0]; $en[1] += $__e[1]; if ($__e[0] === 0) break; } $o['entered'] = $en[1]; setting_set($pdo, 'wcd_enrol_res', json_encode(['at' => date('H:i:s'), 'looked' => $en[0], 'entered' => $en[1], 'ptr' => (int)wcd_set($pdo, 'wcd_ptr', '0')])); } catch (Throwable $e) { if (function_exists('wa_log')) wa_log($pdo, 'engine', false, 'cold enrol: ' . $e->getMessage()); }
        /* owner 22 Nov: the Cold funnel's own messages: made once, the missing ones again every 6 hours (Meta's weekly creation limit) */
        try { if ((string)setting_get($pdo, 'wcd_mkt_fb', '1') === '1' && $now - (int)wcd_set($pdo, 'wcd_make_at_m', '0') >= 3600 && count(wcd_ids($pdo, true)) < count(wcd_pack())) { setting_set($pdo, 'wcd_make_at_m', (string)$now); $mk2 = wcd_make($pdo, 'MARKETING'); setting_set($pdo, 'wcd_make_res_m', json_encode(['at' => date('m-d H:i'), 'made' => $mk2['made'], 'sent' => $mk2['sent'], 'failed' => array_slice($mk2['failed'], 0, 4)])); } } catch (Throwable $e) { if (function_exists('wa_log')) wa_log($pdo, 'engine', false, 'cold make marketing: ' . $e->getMessage()); }   /* owner, 23 Nov: Meta's weekly limit stops NEW utility messages; the same eight questions are made as MARKETING (no such limit) for the customers to whom no utility message can go */
        try { if ($now - (int)wcd_set($pdo, 'wcd_make_at', '0') >= 21600 && count(wcd_ids($pdo)) < count(wcd_pack())) { setting_set($pdo, 'wcd_make_at', (string)$now); $mk = wcd_make($pdo); setting_set($pdo, 'wcd_make_res', json_encode(['at' => date('m-d H:i'), 'made' => $mk['made'], 'sent' => $mk['sent'], 'failed' => array_slice($mk['failed'], 0, 4)])); } } catch (Throwable $e) { if (function_exists('wa_log')) wa_log($pdo, 'engine', false, 'cold make: ' . $e->getMessage()); }
        /* owner 22 Nov: the moment a Cold customer writes he LEAVES the Cold funnel and counts as a Lineup customer (the Lineup enrols everybody who writes); the ledger marks him at once, not at his next due date */
        try { $mv = $pdo->prepare("UPDATE wa_cold_cust c JOIN wa_contacts k ON k.phone = c.phone SET c.status = 'replied', c.why = CONCAT('wrote at ', SUBSTR(k.last_in_at, 1, 16)), c.left_at = k.last_in_at WHERE c.status = 'active' AND k.last_in_at IS NOT NULL AND k.last_in_at >= c.entered_at"); $mv->execute(); $o['moved'] = (int)$mv->rowCount(); } catch (Throwable $e) { }
        if ($mode !== 'live') return $o;
        if (function_exists('wa_quiet_hours') && wa_quiet_hours()) { $o['why'] = 'quiet hours'; return $o; } $h = (int)date('G', $now); if ($h < 9 || $h >= 21) { $o['why'] = 'outside 09-21'; return $o; }
        if (setting_get($pdo, 'wcd_cap_v', '') !== '2') { setting_set($pdo, 'wcd_cap', '15000'); setting_set($pdo, 'wcd_cap_v', '2'); }   // owner, 23 Nov: the daily cap goes from 5,000 to 15,000 (utility only, Rs 0.15 each); later changes on the card stay
        $cap = max(0, (int)wcd_set($pdo, 'wcd_cap', '15000')); $q = $pdo->prepare("SELECT COUNT(*) FROM wa_cold_log WHERE ok = 1 AND at >= ?"); $q->execute([date('Y-m-d 00:00:00', $now)]); $today = (int)$q->fetchColumn(); if ($today >= $cap) { $o['why'] = 'daily cap'; return $o; }
        $ord = wcd_orders($pdo, wcd_set($pdo, 'wcd_from', '2026-11-01') . ' 00:00:00');
        $badc = json_decode(wcd_set($pdo, 'wcd_bad', '{}'), true); if (!is_array($badc)) $badc = []; foreach ($badc as $__k => $__v) if ((int)$__v < $now - 900) unset($badc[$__k]);   /* messages that failed 'not approved / paused' are skipped for 10 minutes, remembered between the minute runs */
        $rows = $pdo->prepare("SELECT phone, entered_at, seg, n_sent, last_sent_at FROM wa_cold_cust WHERE status = 'active' AND next_due <= ? ORDER BY (seg = 'read') DESC, next_due LIMIT 80"); $rows->execute([date('Y-m-d H:i:s', $now)]);
        $lv = $pdo->prepare("UPDATE wa_cold_cust SET status = ?, why = ?, left_at = ? WHERE phone = ?"); $push = $pdo->prepare("UPDATE wa_cold_cust SET next_due = ? WHERE phone = ?"); $done = $pdo->prepare("UPDATE wa_cold_cust SET n_sent = n_sent + 1, last_sent_at = ?, next_due = ? WHERE phone = ?"); $lg = $pdo->prepare("INSERT INTO wa_cold_log (phone, at, tpl_id, ok, why, wamid, step) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($o['sent'] >= 40 || $today + $o['sent'] >= $cap || microtime(true) - $t0 > $budget) break; $ph = (string)$r['phone']; $ent = (int)strtotime((string)$r['entered_at']); $n = (int)$r['n_sent'];
            $c = function_exists('wa_contact') ? wa_contact($pdo, $ph) : null; if (!$c) { $lv->execute(['cancelled', 'no contact', date('Y-m-d H:i:s'), $ph]); $o['left']++; continue; }
            if (!empty($c['optout']) || !empty($c['camp_optout'])) { $lv->execute(['optout', 'opted out', date('Y-m-d H:i:s'), $ph]); $o['left']++; continue; }
            if (!empty($c['last_in_at'])) { $lv->execute(['replied', 'wrote at ' . substr((string)$c['last_in_at'], 0, 16), date('Y-m-d H:i:s'), $ph]); $o['left']++; continue; }   /* the Lineup takes him by itself (it enrols on his message) */
            $bought = false; foreach ($ord[$ph] ?? [] as $oo) if ($oo[0] >= $ent - 3 * 86400) { $bought = true; break; } if ($bought) { $lv->execute(['ordered', 'ordered', date('Y-m-d H:i:s'), $ph]); $o['left']++; continue; }   /* After-Order takes him by itself */
            if ($now - $ent > 90 * 86400) { $lv->execute(['done', '90 days', date('Y-m-d H:i:s'), $ph]); $o['left']++; continue; }
            try { $x = $pdo->prepare("SELECT 1 FROM wa_line_cust WHERE phone = ? AND status = 'active' UNION SELECT 1 FROM wa_aft_cust WHERE phone = ? AND status = 'active' LIMIT 1"); $x->execute([$ph, $ph]); if ($x->fetchColumn()) { $push->execute([date('Y-m-d H:i:s', $now + 2 * 86400), $ph]); $o['waiting']++; continue; } } catch (Throwable $e) { }   /* he is in another funnel now: wait */
            if (!empty($r['last_sent_at']) && (int)strtotime((string)$r['last_sent_at']) > $now - 20 * 3600) { $push->execute([date('Y-m-d H:i:s', (int)strtotime((string)$r['last_sent_at']) + 20 * 3600), $ph]); $o['waiting']++; continue; }
            if ((!empty($c['last_out_at']) && (int)strtotime((string)$c['last_out_at']) > $now - 20 * 3600) || (int)($c['cx_id'] ?? 0) > 0) { $push->execute([date('Y-m-d H:i:s', $now + 20 * 3600 + (int)substr($ph, -3)), $ph]); $o['waiting']++; continue; }   /* we wrote to him today (any funnel): one message a day at most */
            $tw = wcd_templates($pdo, $ph); $__mk = (string)setting_get($pdo, 'wcd_mkt_fb', '1') === '1' ? wcd_templates_m($pdo, $ph) : []; if (!$tw && !$__mk) { $push->execute([date('Y-m-d H:i:s', $now + 86400), $ph]); $lg->execute([$ph, date('Y-m-d H:i:s'), 0, 0, 'no utility message left', '', $n + 1]); $o['waiting']++; continue; }
            $sent = false; $why = ''; $tid = 0; $wm = '';
            $__bad = &$badc; $__tried = 0; foreach (array_merge($tw, $__mk) as $t) {   /* utility first; the marketing copies only when no utility message of his goes (owner 23 Nov) */ $tid = (int)$t[0]; $__bk = $tid . '|' . (int)($c['last_number_id'] ?? 0); if (isset($__bad[$__bk]) && $__bad[$__bk] > $now - 600) continue; if (++$__tried > 12) break; $res = function_exists('wln_send_any') ? wln_send_any($pdo, $c, $tid) : [false, 'no sender']; if (!empty($res[0])) { $sent = true; $wm = (string)($res[3] ?? ''); break; } $why = (string)($res[1] ?? ''); $__bad[$__bk] = $now; }   /* bad = this message on THIS customer's number (another customer's number may have it approved) */   /* a message that no number of his account has approved is skipped for 10 minutes: the next utility message goes instead */
            if ($sent) { $ts = date('Y-m-d H:i:s'); $lg->execute([$ph, $ts, $tid, 1, '', $wm, $n + 1]); $done->execute([$ts, date('Y-m-d H:i:s', $now + wcd_gap($n + 1, (string)$r['seg']) * 86400 + (int)substr($ph, -3) * 20), $ph]); $o['sent']++; if (function_exists('wa_log') && $o['sent'] === 1) { /* one line a round is enough */ } }
            else { $lg->execute([$ph, date('Y-m-d H:i:s'), $tid, 0, mb_substr($why, 0, 150), '', $n + 1]); $push->execute([date('Y-m-d H:i:s', $now + 1800), $ph]); $o['waiting']++; try { setting_set($pdo, 'wcd_dbg', json_encode(['at' => date('H:i:s'), 'tid' => $tid, 'tried' => $__tried, 'why' => mb_substr($why, 0, 160)])); } catch (Throwable $e) { } if ($__tried >= 12) break; }
        }
        try { setting_set($pdo, 'wcd_bad', json_encode($badc)); } catch (Throwable $e) { }
        try { setting_set($pdo, 'wcd_last_res', json_encode(['at' => date('H:i:s'), 'sent' => $o['sent'], 'waiting' => $o['waiting'], 'left' => $o['left'], 'today' => $today + $o['sent']])); } catch (Throwable $e) { }
        return $o;
    }
    /** The ledger as numbers (the diag line and later the card): enrolled, status, sent / delivered / read / failed, replies, Lineup entered vs lost, orders, revenue, cost. */
    function wcd_summary(PDO $pdo, int $from = 0): array
    {
        $o = []; if (!wcd_tables($pdo)) return $o; $f = date('Y-m-d H:i:s', $from ?: strtotime(date('Y-m-d 00:00:00')));
        try { $o['status'] = $pdo->query("SELECT status, COUNT(*) FROM wa_cold_cust GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) { $o['status'] = ['ERR' => 1]; }
        try { $o['seg'] = $pdo->query("SELECT seg, COUNT(*) FROM wa_cold_cust GROUP BY seg")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) { }
        try { $o['entered_today'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_cold_cust WHERE entered_at >= '" . $f . "'")->fetchColumn(); } catch (Throwable $e) { }
        try { $o['due_now'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_cold_cust WHERE status = 'active' AND next_due <= '" . date('Y-m-d H:i:s') . "'")->fetchColumn(); } catch (Throwable $e) { }
        try { $o['sent'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_cold_log WHERE ok = 1 AND at >= '" . $f . "'")->fetchColumn(); $o['sent_total'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_cold_log WHERE ok = 1")->fetchColumn(); } catch (Throwable $e) { }
        try { $r = $pdo->prepare("SELECT m.status, COUNT(*) FROM wa_cold_log l JOIN wa_messages m ON (m.wamid COLLATE utf8mb4_unicode_ci) = (l.wamid COLLATE utf8mb4_unicode_ci) WHERE l.ok = 1 AND l.at >= ? GROUP BY m.status"); $r->execute([$f]); $o['receipts'] = $r->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) { }
        try { $o['replied'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_cold_cust WHERE status = 'replied'")->fetchColumn(); $o['in_lineup'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_cold_cust c JOIN wa_line_cust l ON l.phone = c.phone WHERE c.status = 'replied'")->fetchColumn(); $o['lost'] = $o['replied'] - $o['in_lineup']; } catch (Throwable $e) { }
        try { $ord = wcd_orders($pdo, wcd_set($pdo, 'wcd_from', '2026-11-01') . ' 00:00:00'); $n = 0; $rev = 0; foreach ($pdo->query("SELECT phone, entered_at FROM wa_cold_cust WHERE n_sent > 0")->fetchAll(PDO::FETCH_NUM) as $c) foreach ($ord[(string)$c[0]] ?? [] as $oo) if ($oo[0] >= (int)strtotime((string)$c[1])) { $n++; $rev += $oo[1]; break; } $o['orders'] = $n; $o['revenue'] = $rev; } catch (Throwable $e) { }
        $o['cost'] = round(((int)($o['sent_total'] ?? 0) - wcd_mk_count($pdo, 0)) * 0.15 + wcd_mk_count($pdo, 0) * 1.09, 2); $o['marketing_sent'] = wcd_mk_count($pdo, 0);
        return $o;
    }

    /** How many Cold messages since $from went as the MARKETING copies (Rs 1.09 each instead of Rs 0.15). */
    function wcd_mk_count(PDO $pdo, int $from): int
    {
        try { $ids = array_values(wcd_ids($pdo, true)); if (!$ids) return 0; $q = $pdo->prepare("SELECT COUNT(*) FROM wa_cold_log WHERE ok = 1 AND at >= ? AND tpl_id IN (" . implode(',', array_map('intval', $ids)) . ")"); $q->execute([$from > 0 ? date('Y-m-d H:i:s', $from) : '2000-01-01 00:00:00']); return (int)$q->fetchColumn(); } catch (Throwable $e) { return 0; }
    }
    /** The card (Campaigns screen, above the 30-Day Lineup): controls + today / all numbers like the Lineup card. Cached 90 s. */
    function wcd_numbers(PDO $pdo, int $from): array
    {
        $f = date('Y-m-d H:i:s', $from); $o = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'replied' => 0, 'orders' => 0, 'revenue' => 0, 'cost' => 0.0];
        try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_cold_log WHERE ok = 1 AND at >= ?"); $q->execute([$f]); $o['sent'] = (int)$q->fetchColumn(); } catch (Throwable $e) { }
        try { $r = $pdo->prepare("SELECT m.status, COUNT(*) FROM wa_cold_log l JOIN wa_messages m ON (m.wamid COLLATE utf8mb4_unicode_ci) = (l.wamid COLLATE utf8mb4_unicode_ci) WHERE l.ok = 1 AND l.at >= ? GROUP BY m.status"); $r->execute([$f]); $rc = $r->fetchAll(PDO::FETCH_KEY_PAIR); $o['read'] = (int)($rc['read'] ?? 0); $o['delivered'] = (int)($rc['delivered'] ?? 0) + $o['read']; $o['failed'] = (int)($rc['failed'] ?? 0); } catch (Throwable $e) { }
        try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_cold_cust WHERE status = 'replied' AND left_at >= ?"); $q->execute([$f]); $o['replied'] = (int)$q->fetchColumn(); } catch (Throwable $e) { }
        try { $ord = wcd_orders($pdo, wcd_set($pdo, 'wcd_from', '2026-11-01') . ' 00:00:00'); $q = $pdo->query("SELECT phone, entered_at FROM wa_cold_cust WHERE n_sent > 0"); foreach ($q->fetchAll(PDO::FETCH_NUM) as $c) foreach ($ord[(string)$c[0]] ?? [] as $oo) if ($oo[0] >= (int)strtotime((string)$c[1]) && $oo[0] >= $from) { $o['orders']++; $o['revenue'] += $oo[1]; break; } } catch (Throwable $e) { }
        $__mkn = wcd_mk_count($pdo, $from); $o['marketing_sent'] = $__mkn; $o['cost'] = round(max(0, $o['sent'] - $o['failed'] - $__mkn) * 0.15 + $__mkn * 1.09, 2);
        return $o;
    }
    function wcd_card(PDO $pdo): array
    {
        $now = time(); $o = ['mode' => wcd_set($pdo, 'wcd_mode', 'live'), 'cap' => (int)wcd_set($pdo, 'wcd_cap', '15000'), 'from' => wcd_set($pdo, 'wcd_from', '2026-11-01'), 'quiet' => function_exists('wa_quiet_hours') && wa_quiet_hours(), 'tick_at' => wcd_set($pdo, 'wcd_tick_at', ''), 'enrol' => json_decode(wcd_set($pdo, 'wcd_enrol_res', '{}'), true) ?: [], 'dbg' => wcd_set($pdo, 'wcd_dbg', '')];
        if (!wcd_tables($pdo)) { $o['error'] = 'storage'; return $o; }
        $c = json_decode(wcd_set($pdo, 'wcd_card_cache', ''), true);
        if (!is_array($c) || (int)($c['t'] ?? 0) < $now - 90) {
            $c = ['t' => $now, 'today' => wcd_numbers($pdo, (int)strtotime(date('Y-m-d 00:00:00'))), 'all' => wcd_numbers($pdo, (int)strtotime(wcd_set($pdo, 'wcd_from', '2026-11-01') . ' 00:00:00')), 'sum' => wcd_summary($pdo)];
            try { $c['days'] = $pdo->query("SELECT DATE(next_due) d, COUNT(*) n FROM wa_cold_cust WHERE status = 'active' AND next_due >= '" . date('Y-m-d 00:00:00') . "' AND next_due < '" . date('Y-m-d 00:00:00', $now + 10 * 86400) . "' GROUP BY d ORDER BY d")->fetchAll(PDO::FETCH_KEY_PAIR); $c['fails'] = $pdo->query("SELECT LEFT(why, 60) w, COUNT(*) n FROM wa_cold_log WHERE ok = 0 AND at >= '" . date('Y-m-d 00:00:00') . "' GROUP BY w ORDER BY n DESC LIMIT 5")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) { }
            try { setting_set($pdo, 'wcd_card_cache', json_encode($c)); } catch (Throwable $e) { }
        }
        return $o + ['today' => $c['today'] ?? [], 'all' => $c['all'] ?? [], 'sum' => $c['sum'] ?? [], 'days' => $c['days'] ?? [], 'fails' => $c['fails'] ?? [], 'tpl' => wcd_tpl_stats($pdo), 'sent_today' => (int)($c['today']['sent'] ?? 0)];
    }
}
