<?php
/**
 * wa_after.php — THE AFTER-ORDER PLAN (owner, 21 Nov: "a second module under the 30-day plan: order plan / after-order plan, up to 365 days").
 * Every customer whose order was DELIVERED gets a ladder of messages: week 1-4 care (do you like it, any issue), weeks 5-8 a soft new-fragrance / offer, weeks 9-16 reorder and combos,
 * then every 2 weeks up to 8 months, then monthly up to day 360. 23 steps. A new order of the same customer restarts his ladder from that order.
 *  · Messages: the shop's own approved templates ({{1}} name, {{2}} "your TM perfume", {{3}} one short line of the step) - no new template has to be made. Offers use ONLY the shop's price chart (7% column).
 *  · Safe: universal quiet hours 03:00-09:00 IST, nobody who opted out / has a complaint / is in a live chat, a daily cap (setting wa_aft_cap, default 1500), last month's customers are spread over wa_aft_spread days (default 5).
 *  · The plan starts at DELIVERY (the parcel's delivered date), never at the order or its confirmation messages.
 *  · Switch off: setting wa_aft_on = 0.  Runs from the minute job (wa.cron_send). PHP 7.4 safe.
 */
if (!function_exists('wa_aft_days')) {
    /** step => [day after delivery, template group, line key]. v2 (owner, 21 Nov: messages on at least 200 days of the year, every week a different offer): 4 care steps in weeks 1-4, then ONE message EVERY WEEK up to day 357 (51 steps); the line of a weekly step is made by code (wa_aft_line_dyn). */
    function wa_aft_steps(): array
    {
        $rot = ['also', 'newsim', 'reorder', 'combo', 'best', 'small', 'valued', 'liked', 'gift', 'loved', 'long'];
        $s = [1 => [7, 'enjoy', 'care1'], 2 => [14, 'doubt', 'care2'], 3 => [21, 'checkin', 'care3'], 4 => [28, 'enjoy', 'care4']];
        for ($k = 5; $k <= 51; $k++) $s[$k] = [28 + 7 * ($k - 4), $rot[($k - 5) % count($rot)], 'dyn'];
        return $s;
    }
    function wa_aft_days(): array { $o = []; foreach (wa_aft_steps() as $k => $v) $o[$k] = $v[0]; return $o; }
    /** group => the shop's approved templates ({{1}} name, {{2}}, {{3}}) */
    function wa_aft_groups(): array
    {
        return ['enjoy' => [178, 180, 183], 'doubt' => [207, 208, 209], 'checkin' => [204, 205, 206], 'also' => [179, 182, 185, 196], 'newsim' => [237, 238, 239], 'reorder' => [181, 184, 186, 197],
            'combo' => [219, 220, 221], 'best' => [210, 211], 'small' => [229, 230, 231], 'valued' => [187, 188, 189, 190, 191, 192, 193, 194, 195, 198], 'liked' => [168, 170, 172], 'gift' => [232, 233, 234],
            'loved' => [225, 226, 227], 'long' => [212, 213, 214]];
    }
    function wa_aft_tables(PDO $pdo): bool
    {
        static $ok = null; if ($ok !== null) return $ok;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS wa_aft_cust (phone VARCHAR(12) NOT NULL PRIMARY KEY, order_name VARCHAR(40) NOT NULL DEFAULT '', order_at DATETIME NOT NULL, anchor_at DATETIME NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'active', why VARCHAR(80) NOT NULL DEFAULT '', total INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, KEY k_st (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS wa_aft_plan (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, step TINYINT NOT NULL, due_at DATETIME NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'planned', tpl_id INT NOT NULL DEFAULT 0, sent_at DATETIME NULL, wamid VARCHAR(80) NOT NULL DEFAULT '', why VARCHAR(160) NOT NULL DEFAULT '', KEY k_due (status, due_at), KEY k_ph (phone, step)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return $ok = true;
        } catch (Throwable $e) { return $ok = false; }
    }
    function wa_aft_set(PDO $pdo, string $k, string $d = ''): string { $v = setting_get($pdo, $k, $d); return $v === '' ? $d : (string)$v; }
    /** The one short line ({{3}}) of a step. Offers carry ONLY the price chart's 5 x 50 ml price (7% column). */
    function wa_aft_line(PDO $pdo, string $key): string
    {
        static $p250 = null; if ($p250 === null) { $p250 = 0; try { $j = json_decode((string)setting_get($pdo, 'ml_slab_chart', ''), true); if (is_array($j) && !empty($j['rows'])) { $ix = function_exists('wag_chart_cols') ? wag_chart_cols(array_values(array_map('floatval', (array)$j['cols'])))[0] : 0; foreach ((array)$j['rows'] as $r) if ((int)($r['ml'] ?? 0) === 250) $p250 = (int)($r['p'][$ix] ?? 0); } } catch (Throwable $e) { } }
        $L = ['care1' => 'Koi sawal ya issue ho to bas yahin reply kijiye, hum turant madad karenge 🙏', 'care2' => 'Khushbu, packing ya delivery mein kuch bhi ho to bata dijiye, hum solve karenge.', 'care3' => 'Aapko perfume kaisa laga? Ek line mein bata dijiye 😊',
            'care4' => 'Agar kuch theek na lage to reply kijiye, hum aapki poori madad karenge.', 'fresh' => 'Naye fragrances aaye hain - bataiye kaisi khushbu pasand hai, hum suggest karenge.', 'reorder' => 'Dobara order ke liye bas yahin reply kijiye, hum jaldi arrange kar denge.',
            'best' => 'Hamare best-sellers aapke liye ready hain.', 'small' => 'Naya fragrance chhoti size mein try karna chahein to batayiye.', 'love' => 'Aapki pasand ke naye options ke liye bas reply kijiye.'];
        if ($key === 'offer') return $p250 > 0 ? '5 x 50 ml sirf Rs ' . $p250 . ' mein. Order ke liye yahin reply kijiye.' : 'Best price ke liye yahin reply kijiye.';
        return $L[$key] ?? $L['love'];
    }
    /** The weekly line ({{3}}): a DIFFERENT offer every week - the size (20 / 30 / 50 / 100 ml) and the number of bottles rotate per customer and per week, the price is ALWAYS the shop's chart price (7% column) of that exact quantity, the season sets the greeting. No invented offer. */
    function wa_aft_line_dyn(PDO $pdo, int $step, string $phone, int $now): string
    {
        static $combos = null; if ($combos === null) { $combos = []; try { $j = json_decode((string)setting_get($pdo, 'ml_slab_chart', ''), true); if (is_array($j) && !empty($j['rows'])) { $ix = function_exists('wag_chart_cols') ? wag_chart_cols(array_values(array_map('floatval', (array)$j['cols'])))[0] : 0; $pr = []; foreach ((array)$j['rows'] as $r) { $p = (int)($r['p'][$ix] ?? 0); if ($p > 0) $pr[(int)($r['ml'] ?? 0)] = $p; }
                foreach ([20, 30, 50, 100] as $sz) foreach ([2, 3, 4, 5, 6, 8, 10] as $q) if (isset($pr[$q * $sz])) $combos[] = [$q, $sz, $pr[$q * $sz]]; } } catch (Throwable $e) { } }
        $m = (int)date('n', $now); $season = [1 => 'Naye saal ki shuruaat', 2 => 'Valentine ka mahina', 3 => 'Holi ke rang', 4 => 'Garmi ke fresh fragrances', 5 => 'Garmi mein fresh rehne ke liye', 6 => 'Garmi ka mausam', 7 => 'Barsaat ka mausam', 8 => 'Raksha Bandhan / gifting ka mausam', 9 => 'Tyohaaron ka mausam', 10 => 'Navratri / Dussehra ke din', 11 => 'Diwali aur shaadi ka mausam', 12 => 'Winter aur shaadi season'][$m];
        $phrase = ['sirf', 'bas', 'special value:', 'is hafte ka option:'];
        if (!$combos) return $season . ' - best price ke liye yahin reply kijiye.';
        $h = (int)substr(preg_replace('/\D/', '', $phone), -4); $c = $combos[($step * 5 + $h) % count($combos)]; $ph = $phrase[($step + $h) % count($phrase)];
        return $season . ': ' . $c[0] . ' x ' . $c[1] . ' ml ' . $ph . ' Rs ' . $c[2] . '. Reply kijiye, hum order bana denge.';
    }
    function wa_aft_k10($phone): string { return substr(preg_replace('/\D+/', '', (string)$phone), -10); }
    /** Write the ladder of one customer: past steps are dropped except the newest one, which goes out spread over the next days (never a burst). */
    function wa_aft_plan(PDO $pdo, string $phone, int $anchor, int $now, int $spreadDays): int
    {
        $pdo->prepare("DELETE FROM wa_aft_plan WHERE phone = ? AND status = 'planned'")->execute([$phone]); $sent = $pdo->prepare("SELECT step FROM wa_aft_plan WHERE phone = ? AND status = 'sent'"); $sent->execute([$phone]); $had = array_map('intval', $sent->fetchAll(PDO::FETCH_COLUMN));
        $ins = $pdo->prepare("INSERT INTO wa_aft_plan (phone, step, due_at, status) VALUES (?, ?, ?, 'planned')"); $n = 0; $lastPast = 0; $steps = wa_aft_steps();
        foreach ($steps as $k => $v) { if (in_array($k, $had, true)) continue; if ($anchor + $v[0] * 86400 <= $now) $lastPast = $k; }
        foreach ($steps as $k => $v) {
            if (in_array($k, $had, true)) continue; $due = $anchor + $v[0] * 86400;
            if ($due <= $now) { if ($k !== $lastPast) continue; $due = $now + 600 + (int)(((int)substr($phone, -6)) % max(1, $spreadDays * 86400 - 600)); }
            $ins->execute([$phone, $k, date('Y-m-d H:i:s', $due)]); $n++; }
        return $n;
    }
    /** Customers whose order was DELIVERED (owner, 21 Nov: the plan starts after DELIVERY, never after the order or its confirmation; the order / tracking / delivery confirmations are the Orders module's own and are left alone).
     *  The last 31 days are looked at every 30 minutes; a customer joins the day his parcel shows delivered; last month's customers are spread over wa_aft_spread days. */
    function wa_aft_enrol(PDO $pdo, int $now): int
    {
        if (!wa_aft_tables($pdo)) return 0; $spread = max(1, (int)wa_aft_set($pdo, 'wa_aft_spread', '5')); $n = 0;
        try {
            $q = $pdo->prepare("SELECT o.order_name, o.created_at, o.total, o.phone, (SELECT p.delivered_at FROM dp_dsp_parcels p WHERE p.order_name = o.order_name AND p.delivered_at IS NOT NULL ORDER BY p.id DESC LIMIT 1) dl FROM shop_orders o WHERE o.created_at >= ? AND o.created_at <= ? AND o.financial_status NOT IN ('voided', 'refunded') HAVING dl IS NOT NULL ORDER BY o.created_at DESC LIMIT 4000");
            $q->execute([date('Y-m-d H:i:s', $now - 31 * 86400), date('Y-m-d H:i:s', $now - 86400)]); $seen = [];
            $ex = $pdo->prepare("SELECT order_at FROM wa_aft_cust WHERE phone = ?");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ph = wa_aft_k10($r['phone']); if (strlen($ph) !== 10 || isset($seen[$ph])) continue; $seen[$ph] = 1; $oat = (int)strtotime((string)$r['created_at']); $anchor = (int)strtotime((string)$r['dl']); if ($anchor <= 0 || $anchor > $now) continue;
                $ex->execute([$ph]); $e = $ex->fetchColumn(); if ($e !== false && (int)strtotime((string)$e) >= $oat) continue;   // already on the ladder of this (or a newer) order
                $c = function_exists('wa_contact') ? wa_contact($pdo, $ph) : null; if (!$c || (int)($c['optout'] ?? 0) === 1 || (int)($c['camp_optout'] ?? 0) === 1) continue;
                $pdo->prepare("REPLACE INTO wa_aft_cust (phone, order_name, order_at, anchor_at, status, why, total, created_at) VALUES (?, ?, ?, ?, 'active', '', ?, ?)")->execute([$ph, (string)$r['order_name'], date('Y-m-d H:i:s', $oat), date('Y-m-d H:i:s', $anchor), (int)$r['total'], date('Y-m-d H:i:s', $now)]);
                if (wa_aft_plan($pdo, $ph, $anchor, $now, $spread) > 0) $n++; if ($n >= 1500) break; }
        } catch (Throwable $e) { if (function_exists('wa_log')) wa_log($pdo, 'engine', false, 'after-order enrol: ' . $e->getMessage()); }
        return $n;
    }
    /** One message: [ok, reason]. The shop's approved template, {{1}} name, {{2}} "your TM perfume", {{3}} the line of the step. */
    function wa_aft_send(PDO $pdo, array $c, int $tid, string $line): array
    {
        static $cache = []; $ck = $tid . '|' . intdiv(time(), 60); if (count($cache) > 40) $cache = [];
        if (!isset($cache[$ck])) { $t0 = function_exists('wa_camp_tpl') ? wa_camp_tpl($pdo, $tid, true, 0) : null; $cache[$ck] = $t0 ? [$t0, wa_camp_ctx($pdo, $t0, 2, [], 1000000)] : [null, null]; }
        [$t, $ctx] = $cache[$ck]; if (!$t) return [false, 'wait: template not approved'];
        $nv = (int)($t['nvars'] ?? 0); if ($nv > 3) return [false, 'This message has ' . $nv . ' blanks.'];
        if (empty($ctx['nums'])) return [false, 'wait: no number can send now'];
        $pref = (int)($c['last_number_id'] ?? 0); if ($pref > 0 && isset($ctx['nums'][$pref])) { $nid = $pref; $why = ''; } else [$nid, $why] = wa_pick_number($pdo, $c, $ctx);
        if (!$nid || !isset($ctx['nums'][$nid])) return [false, 'wait: no number (' . $why . ')'];
        $vars = array_slice([function_exists('wd_name') ? wd_name($c) : 'ji', 'your TM perfume', $line], 0, max(1, $nv));
        [$ok, $res] = wa_send_tpl_one($pdo, $c, $t, $ctx['nums'][$nid], $nv ? $vars : [], 'followup');
        return $ok ? [true, (string)$res] : [false, (string)$res];
    }
    /** The minute job: enrol (every 30 min) and send what is due (daily cap, 03:00-09:00 quiet). */
    function wa_aft_tick(PDO $pdo, int $budget = 40): array
    {
        $o = ['entered' => 0, 'sent' => 0, 'waiting' => 0]; if (wa_aft_set($pdo, 'wa_aft_on', '1') === '0' || !wa_aft_tables($pdo)) return $o;
        if (function_exists('wa_quiet_hours') && wa_quiet_hours()) { $o['why'] = 'quiet hours'; return $o; }
        if (!function_exists('wa_lock') || !wa_lock($pdo, 'wlaft', 55)) return $o; $now = time(); $t0 = microtime(true);
        if (wa_aft_set($pdo, 'wa_aft_ver', '1') !== '2') {   // one time: the first 23-step ladder becomes the weekly 51-step one (nobody has been sent a message yet); 600 customers per minute
            try { $mq = $pdo->query("SELECT phone, anchor_at FROM wa_aft_cust WHERE status = 'active' AND why <> 'v2' LIMIT 600")->fetchAll(PDO::FETCH_ASSOC); $sp = max(1, (int)wa_aft_set($pdo, 'wa_aft_spread', '5'));
                foreach ($mq as $mr) { wa_aft_plan($pdo, (string)$mr['phone'], (int)strtotime((string)$mr['anchor_at']), $now, $sp); $pdo->prepare("UPDATE wa_aft_cust SET why = 'v2' WHERE phone = ?")->execute([(string)$mr['phone']]); }
                if (!$mq) setting_set($pdo, 'wa_aft_ver', '2'); } catch (Throwable $e) { }
            return $o; }
        if ($now - (int)wa_aft_set($pdo, 'wa_aft_enrol_at', '0') >= 1800) { setting_set($pdo, 'wa_aft_enrol_at', (string)$now); $o['entered'] = wa_aft_enrol($pdo, $now); }
        $cap = max(0, (int)wa_aft_set($pdo, 'wa_aft_cap', '1500')); $q = $pdo->prepare("SELECT COUNT(*) FROM wa_aft_plan WHERE status = 'sent' AND sent_at >= ?"); $q->execute([date('Y-m-d 00:00:00')]); $today = (int)$q->fetchColumn(); if ($today >= $cap) { $o['why'] = 'daily cap'; return $o; }
        $rows = $pdo->prepare("SELECT p.id, p.phone, p.step, c.order_at, c.anchor_at FROM wa_aft_plan p JOIN wa_aft_cust c ON c.phone = p.phone WHERE p.status = 'planned' AND p.due_at <= ? AND c.status = 'active' ORDER BY p.due_at LIMIT 60"); $rows->execute([date('Y-m-d H:i:s', $now)]);
        $upd = $pdo->prepare("UPDATE wa_aft_plan SET status = ?, why = ?, sent_at = ?, tpl_id = ?, wamid = ? WHERE id = ?"); $push = $pdo->prepare("UPDATE wa_aft_plan SET due_at = ? WHERE id = ?"); $steps = wa_aft_steps(); $grp = wa_aft_groups();
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($o['sent'] >= 25 || $today + $o['sent'] >= $cap || microtime(true) - $t0 > $budget) break; $ph = (string)$r['phone']; $id = (int)$r['id'];
            $c = function_exists('wa_contact') ? wa_contact($pdo, $ph) : null; if (!$c || (int)($c['optout'] ?? 0) === 1 || (int)($c['camp_optout'] ?? 0) === 1) { $upd->execute(['cancelled', 'opted out / no contact', null, 0, '', $id]); continue; }
            try { $no = $pdo->prepare("SELECT COUNT(*) FROM shop_orders WHERE phone LIKE ? AND created_at > ?"); $no->execute(['%' . $ph, (string)$r['order_at']]); if ((int)$no->fetchColumn() > 0) { $pdo->prepare("UPDATE wa_aft_cust SET status = 'done', why = 'new order: his ladder restarts' WHERE phone = ?")->execute([$ph]); $pdo->prepare("UPDATE wa_aft_plan SET status = 'cancelled', why = 'new order' WHERE phone = ? AND status = 'planned'")->execute([$ph]); continue; } } catch (Throwable $e) { }
            $busy = false; foreach (['last_in_at', 'last_out_at'] as $f) if (!empty($c[$f]) && (int)strtotime((string)$c[$f]) > $now - 86400) $busy = true;   // a live chat: never over his conversation, try tomorrow
            if ($busy || (int)($c['cx_id'] ?? 0) > 0) { $push->execute([date('Y-m-d H:i:s', $now + 86400 + (int)substr($ph, -4)), $id]); $o['waiting']++; continue; }
            $st = $steps[(int)$r['step']] ?? null; if (!$st) { $upd->execute(['cancelled', 'unknown step', null, 0, '', $id]); continue; }
            $ids = $grp[$st[1]] ?? []; if (!$ids) { $upd->execute(['cancelled', 'no template', null, 0, '', $id]); continue; }
            $tid = (int)$ids[(int)substr($ph, -3) % count($ids)]; [$ok, $why] = wa_aft_send($pdo, $c, $tid, $st[2] === 'dyn' ? wa_aft_line_dyn($pdo, (int)$r['step'], $ph, $now) : wa_aft_line($pdo, $st[2]));
            if ($ok) { $upd->execute(['sent', '', date('Y-m-d H:i:s'), $tid, $why, $id]); $o['sent']++; try { $pdo->prepare("INSERT INTO wa_line_log (phone, at, what) VALUES (?, ?, ?)")->execute([$ph, date('Y-m-d H:i:s'), 'after-order step ' . (int)$r['step']]); } catch (Throwable $e) { } }
            elseif (strpos($why, 'wait') === 0) { $push->execute([date('Y-m-d H:i:s', $now + 1800), $id]); $o['waiting']++; if (strpos($why, 'no number') !== false) break; }
            else { $upd->execute(['failed', mb_substr($why, 0, 150), null, $tid, '', $id]); }
        }
        return $o;
    }
}
