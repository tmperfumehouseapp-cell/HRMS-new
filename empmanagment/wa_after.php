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
    /** step => [day after delivery, template group, line key] */
    function wa_aft_steps(): array
    {
        $rot = ['valued', 'liked', 'gift', 'loved', 'long', 'also', 'reorder', 'combo', 'newsim'];
        $s = [1 => [7, 'enjoy', 'care1'], 2 => [14, 'doubt', 'care2'], 3 => [21, 'checkin', 'care3'], 4 => [28, 'enjoy', 'care4'], 5 => [38, 'also', 'fresh'], 6 => [52, 'newsim', 'offer'],
            7 => [66, 'reorder', 'reorder'], 8 => [80, 'combo', 'offer'], 9 => [94, 'best', 'best'], 10 => [108, 'small', 'small']];
        $d = 122; for ($i = 0; $i < 9; $i++) { $s[11 + $i] = [$d, $rot[$i], 'love']; $d += 14; }
        $s[20] = [270, 'valued', 'love']; $s[21] = [300, 'reorder', 'reorder']; $s[22] = [330, 'liked', 'fresh']; $s[23] = [360, 'valued', 'love'];
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
            $tid = (int)$ids[(int)substr($ph, -3) % count($ids)]; [$ok, $why] = wa_aft_send($pdo, $c, $tid, wa_aft_line($pdo, $st[2]));
            if ($ok) { $upd->execute(['sent', '', date('Y-m-d H:i:s'), $tid, $why, $id]); $o['sent']++; try { $pdo->prepare("INSERT INTO wa_line_log (phone, at, what) VALUES (?, ?, ?)")->execute([$ph, date('Y-m-d H:i:s'), 'after-order step ' . (int)$r['step']]); } catch (Throwable $e) { } }
            elseif (strpos($why, 'wait') === 0) { $push->execute([date('Y-m-d H:i:s', $now + 1800), $id]); $o['waiting']++; if (strpos($why, 'no number') !== false) break; }
            else { $upd->execute(['failed', mb_substr($why, 0, 150), null, $tid, '', $id]); }
        }
        return $o;
    }
}
