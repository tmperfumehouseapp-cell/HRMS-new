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
    /** The ladder (v3, owner 21 Nov: "200 messages in a year, and the same every following year"): 4 care messages in weeks 1-4 (days 7, 14, 21, 28), then one message about every 1.7 days - 196 more in the first year (step 200 = day 357) - and the same pace for every following year.
     *  The line of every step after the care weeks is made by code (wa_aft_line_dyn); the template group rotates. */
    function wa_aft_step(int $k): array
    {
        static $off = ['combo', 'reorder', 'valued', 'gift', 'best', 'newsim', 'also']; static $con = [['trust', 'long'], ['testimonial', 'loved'], ['new', 'newsim'], ['size', 'small'], ['season', 'valued']];
        if ($k <= 4) return [[7, 'enjoy', 'care1'], [14, 'doubt', 'care2'], [21, 'checkin', 'care3'], [28, 'enjoy', 'care4']][max(0, $k - 1)];
        $day = 28 + (int)round(($k - 4) * 329 / 196); $i = $k - 5;   // owner 21 Nov: of every 200 messages 100 are OFFERS (the chart's prices), the other 100 are trust / customers' ratings / new arrivals / small sizes / the season
        if ($i % 2 === 0) return [$day, $off[intdiv($i, 2) % count($off)], 'dyn:offer'];
        $c = $con[intdiv($i - 1, 2) % count($con)]; return [$day, $c[1], 'dyn:' . $c[0]];
    }
    /** The first $n steps (200 = the first year). */
    function wa_aft_steps(int $n = 200): array { $s = []; for ($k = 1; $k <= $n; $k++) $s[$k] = wa_aft_step($k); return $s; }
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
            foreach (['kind' => "VARCHAR(12) NOT NULL DEFAULT ''", 'sz' => 'INT NOT NULL DEFAULT 0', 'lh' => "VARCHAR(16) NOT NULL DEFAULT ''"] as $col => $def) { try { $pdo->query("SELECT $col FROM wa_aft_plan LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE wa_aft_plan ADD COLUMN $col $def"); } catch (Throwable $e2) { } } }   // what kind of message / which size went (the learning counts replies per kind and size)
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
    /** 🧭 The customer's own ordering behaviour (owner, 21 Nov: "consider every pattern you can find"): from his Shopify orders - how many, the average order, the ml of the last order, the usual number of days between two orders (his cycle) and how long ago the last one was. */
    function wa_aft_behavior(PDO $pdo, string $ph, int $now): array
    {
        static $cache = []; if (isset($cache[$ph])) return $cache[$ph]; if (count($cache) > 300) $cache = [];
        $b = ['n' => 0, 'ml' => 0, 'aov' => 0, 'cycle' => 0, 'since' => 0];
        try { $q = $pdo->prepare("SELECT created_at, total, ml_total FROM shop_orders WHERE phone LIKE ? AND financial_status NOT IN ('voided', 'refunded') ORDER BY created_at DESC LIMIT 10"); $q->execute(['%' . $ph]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            $b['n'] = count($rows); if ($rows) { $tot = 0; $ts = []; foreach ($rows as $r) { $tot += (float)$r['total']; $ts[] = (int)strtotime((string)$r['created_at']); if (!$b['ml'] && (int)($r['ml_total'] ?? 0) > 0) $b['ml'] = (int)$r['ml_total']; } $b['aov'] = (int)round($tot / count($rows)); $b['since'] = (int)floor(($now - $ts[0]) / 86400);
                if (count($ts) >= 2) { $gaps = []; for ($i = 0; $i + 1 < count($ts); $i++) $gaps[] = ($ts[$i] - $ts[$i + 1]) / 86400; $avg = array_sum($gaps) / count($gaps); if ($avg >= 15 && $avg <= 400) $b['cycle'] = (int)round($avg); } } } catch (Throwable $e) { }
        return $cache[$ph] = $b;
    }
    /** An hour of the day with clearly fewer replies than the best hour is skipped (the message waits for a better hour, at most 12 h). */
    function wa_aft_hour_ok(PDO $pdo, int $hour): bool
    {
        static $L = null; if ($L === null) { $x = json_decode((string)setting_get($pdo, 'wa_aft_learn', ''), true); $L = []; $best = 0.0; foreach ((array)($x['hr'] ?? []) as $h => $v) { if ((int)$v['n'] >= 200) { $r = (int)$v['r'] / max(1, (int)$v['n']); $L[(int)$h] = $r; $best = max($best, $r); } } $L['best'] = $best; }
        if (!isset($L[$hour]) || $L['best'] <= 0) return true; return $L[$hour] >= 0.5 * $L['best'];
    }
    /** 🧠 The learning (owner, 21 Nov: "act on the pattern that got the most replies, and the season"): every 6 hours the replies (the customer wrote within 24 h after our message) are counted per message KIND and per offer SIZE for the last 30 days. Stored in the setting wa_aft_learn. */
    function wa_aft_learn(PDO $pdo, int $now): array
    {
        $o = ['at' => date('Y-m-d H:i:s', $now), 'kind' => [], 'sz' => []];
        try { foreach (['kind', 'sz'] as $col) { $q = $pdo->prepare("SELECT p.$col k, COUNT(*) n, SUM(EXISTS (SELECT 1 FROM wa_messages m WHERE (m.phone COLLATE utf8mb4_unicode_ci) = (p.phone COLLATE utf8mb4_unicode_ci) AND m.dir = 'in' AND m.ts > p.sent_at AND m.ts <= DATE_ADD(p.sent_at, INTERVAL 24 HOUR))) r FROM wa_aft_plan p WHERE p.status = 'sent' AND p.sent_at >= ? AND p.$col <> '' AND p.$col <> '0' GROUP BY p.$col");
                $q->execute([date('Y-m-d H:i:s', $now - 30 * 86400)]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $o[$col][(string)$r['k']] = ['n' => (int)$r['n'], 'r' => (int)$r['r']]; }
            $q = $pdo->prepare("SELECT HOUR(p.sent_at) k, COUNT(*) n, SUM(EXISTS (SELECT 1 FROM wa_messages m WHERE (m.phone COLLATE utf8mb4_unicode_ci) = (p.phone COLLATE utf8mb4_unicode_ci) AND m.dir = 'in' AND m.ts > p.sent_at AND m.ts <= DATE_ADD(p.sent_at, INTERVAL 24 HOUR))) r FROM wa_aft_plan p WHERE p.status = 'sent' AND p.sent_at >= ? GROUP BY HOUR(p.sent_at)"); $q->execute([date('Y-m-d H:i:s', $now - 30 * 86400)]); $o['hr'] = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $o['hr'][(string)$r['k']] = ['n' => (int)$r['n'], 'r' => (int)$r['r']];
            setting_set($pdo, 'wa_aft_learn', json_encode($o)); } catch (Throwable $e) { }
        return $o;
    }
    /** weight of a kind / size: 1 + 4 x reply rate once 200+ messages are measured (a measured winner gets up to ~5x the share of a loser; nothing is ever dropped completely). */
    function wa_aft_weight(PDO $pdo, string $col, string $k): float
    {
        static $L = null; if ($L === null) { $L = json_decode((string)setting_get($pdo, 'wa_aft_learn', ''), true); if (!is_array($L)) $L = []; }
        $x = $L[$col][$k] ?? null; if (!$x || (int)$x['n'] < 200) return 1.0; return 1.0 + 4.0 * ((int)$x['r'] / max(1, (int)$x['n']));
    }
    function wa_aft_pick(PDO $pdo, string $col, array $options, int $seed): string
    {
        $w = []; $t = 0.0; foreach ($options as $k) { $x = wa_aft_weight($pdo, $col, (string)$k); $w[(string)$k] = $x; $t += $x; } $r = ($seed % 1000) / 1000.0 * $t; foreach ($w as $k => $x) { if ($r < $x) return (string)$k; $r -= $x; }
        return (string)end($options);
    }
    /** 🎉 FESTIVALS (owner, 21 Nov: "when a festival comes, change the messages from time to time"): the greeting of the weekly offer follows the calendar. Fixed-date festivals are exact; the festivals that move every year (Holi, Raksha Bandhan, Navratri, Diwali, Eid) are covered by their month's greeting
     *  until their date is put in the setting wa_aft_festivals = [{"from":"MM-DD","to":"MM-DD","text":"..."}] (it wins over everything below). No offer is invented: only the greeting changes. */
    function wa_aft_festival(PDO $pdo, int $now): string
    {
        static $own = null; if ($own === null) { $own = json_decode((string)setting_get($pdo, 'wa_aft_festivals', ''), true); if (!is_array($own)) $own = []; }
        $md = date('m-d', $now); $list = array_merge($own, [['from' => '01-01', 'to' => '01-03', 'text' => 'Happy New Year'], ['from' => '01-12', 'to' => '01-15', 'text' => 'Uttarayan / Makar Sankranti ki shubhkamnayein'], ['from' => '01-24', 'to' => '01-26', 'text' => 'Republic Day'], ['from' => '02-09', 'to' => '02-14', 'text' => 'Valentine week'],
            ['from' => '03-06', 'to' => '03-08', 'text' => "Women's Day ke liye gifting"], ['from' => '05-07', 'to' => '05-14', 'text' => "Mother's Day ke liye gifting"], ['from' => '06-14', 'to' => '06-21', 'text' => "Father's Day ke liye gifting"], ['from' => '08-12', 'to' => '08-15', 'text' => 'Independence Day'], ['from' => '08-01', 'to' => '08-07', 'text' => 'Friendship Day ke liye gifting'],
            ['from' => '12-18', 'to' => '12-25', 'text' => 'Christmas ki taiyari'], ['from' => '12-27', 'to' => '12-31', 'text' => 'New Year party ke liye']]);
        foreach ($list as $f) { $a = (string)($f['from'] ?? ''); $b = (string)($f['to'] ?? ''); if ($a !== '' && $b !== '' && $md >= $a && $md <= $b) return (string)($f['text'] ?? ''); }
        return '';
    }
    /** The line ({{3}}) of a step after the care weeks. kind: offer = the shop's chart price of a size / quantity that rotates per customer and step (100 of every 200 messages); trust = facts about us; testimonial = a perfume customers rated best (the catalogue's own rating);
     *  new = the newest perfumes of the catalogue; size = a small size to try (chart price); season = the season's greeting. Nothing is invented: prices from the chart, ratings and names from the catalogue. */
    function wa_aft_line_dyn(PDO $pdo, int $step, string $phone, int $now, string $kind = 'offer', array $b = []): string
    {
        static $combos = null, $pr = [], $top = null, $new = null;
        $h = (int)substr(preg_replace('/\D/', '', $phone), -4); $m = (int)date('n', $now);
        $season = [1 => 'Naye saal ki shuruaat', 2 => 'Valentine ka mahina', 3 => 'Holi ke rang', 4 => 'Garmi ke fresh fragrances', 5 => 'Garmi mein fresh rehne ke liye', 6 => 'Garmi ka mausam', 7 => 'Barsaat ka mausam', 8 => 'Raksha Bandhan / gifting ka mausam', 9 => 'Tyohaaron ka mausam', 10 => 'Navratri / Dussehra ke din', 11 => 'Diwali aur shaadi ka mausam', 12 => 'Winter aur shaadi season'][$m];
        $fs = wa_aft_festival($pdo, $now); if ($fs !== '') $season = $fs;   // 🎉 a festival's greeting wins over the month's
        if ($combos === null) { $combos = []; try { $j = json_decode((string)setting_get($pdo, 'ml_slab_chart', ''), true); if (is_array($j) && !empty($j['rows'])) { $ix = function_exists('wag_chart_cols') ? wag_chart_cols(array_values(array_map('floatval', (array)$j['cols'])))[0] : 0; foreach ((array)$j['rows'] as $r) { $p = (int)($r['p'][$ix] ?? 0); if ($p > 0) $pr[(int)($r['ml'] ?? 0)] = $p; }
                foreach ([20, 30, 50, 100] as $sz) foreach ([2, 3, 4, 5, 6, 8, 10] as $q) if (isset($pr[$q * $sz])) $combos[] = [$q, $sz, $pr[$q * $sz]]; } } catch (Throwable $e) { } }
        $GLOBALS['wa_aft_last'] = ['kind' => $kind, 'sz' => 0];
        if ($kind === 'trust') { $t = ['7,000+ Google reviews, 380+ fragrances aur 92+ brands - ye sab aapke bharose se mumkin hua. Thank you! 🙏', 'Hamare perfume 30% oil ke hain aur der tak tikte hain. Kaunsi khushbu chahiye, bataiye.', 'Koi bhi sawal ya issue ho to bas yahin reply kijiye, hum turant madad karenge.', 'Sahi khushbu chunna mushkil lage to bas bataiye kiske liye chahiye - hum best options suggest karenge.']; return $t[($step + $h) % count($t)]; }
        if ($kind === 'testimonial') { if ($top === null) { $top = []; try { $q = $pdo->query("SELECT name, rating, rating_n FROM wa_prod WHERE active = 1 AND kind = 'perfume' AND rating >= 4.5 AND rating_n >= 20 ORDER BY rating_n DESC LIMIT 40"); $top = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : []; } catch (Throwable $e) { } }
            if ($top) { $x = $top[($step + $h) % count($top)]; return 'Customers ka favourite: ' . trim((string)$x['name']) . ' - ' . rtrim(rtrim(number_format((float)$x['rating'], 1), '0'), '.') . '/5 (' . (int)$x['rating_n'] . ' reviews). Try karna ho to reply kijiye.'; } return 'Hamare customers ke reviews bahut achhe hain. Aapki pasand ki khushbu bataiye, hum suggest karenge.'; }
        if ($kind === 'new') { if ($new === null) { $new = []; try { $q = $pdo->query("SELECT name FROM wa_prod WHERE active = 1 AND kind = 'perfume' AND name <> '' ORDER BY shopify_id DESC LIMIT 12"); $new = $q ? $q->fetchAll(PDO::FETCH_COLUMN) : []; } catch (Throwable $e) { } }
            if (count($new) >= 3) { $o = ($step + $h) % max(1, count($new) - 4); $names = array_slice($new, $o, 5); return 'Naye aaye hain: ' . implode(', ', array_map('trim', $names)) . '. Details ke liye reply kijiye.'; } return 'Hamare paas naye fragrances aaye hain. Details ke liye reply kijiye.'; }
        if ($kind === 'size') { $p20 = $pr[20] ?? 0; $p50 = $pr[50] ?? 0; return $p20 > 0 ? 'Naya fragrance try karna hai? 20 ml sirf Rs ' . $p20 . ' mein shuru kijiye. Reply kijiye.' : ($p50 > 0 ? 'Naya fragrance try karna hai? 50 ml Rs ' . $p50 . '. Reply kijiye.' : 'Chhoti size mein naya fragrance try kijiye. Reply kijiye.'); }
        if ($kind === 'season' || !$combos) return $season . ': kaisi khushbu chahiye, bataiye - hum best option suggest karenge.';
        $phrase = ['sirf', 'bas', 'special value:', 'is hafte ka option:']; $szs = []; foreach ($combos as $cc) $szs[$cc[1]] = 1; $psz = (int)wa_aft_pick($pdo, 'sz', array_keys($szs), $step * 37 + $h); $sub = array_values(array_filter($combos, function ($cc) use ($psz) { return $cc[1] === $psz; })); if (!$sub) $sub = $combos; $c = $sub[($step * 5 + $h) % count($sub)];
        if (!empty($b['ml']) && ($step + $h) % 3 !== 0) { $up = null; foreach ($combos as $cc) { $tm = $cc[0] * $cc[1]; if ($tm > (int)$b['ml'] && $tm <= (int)$b['ml'] * 2 && ($up === null || $tm < $up[0] * $up[1] || ($tm === $up[0] * $up[1] && ($step + $h) % 2 === 0))) $up = $cc; } if ($up) $c = $up; }   // 🧭 two of three offers: the NEXT size above what he bought last (his own order's ml)
        if (!empty($b['aov']) && (int)$b['aov'] >= 2700 && ($step + $h) % 4 === 1) { $big = null; foreach ($combos as $cc) if ($cc[2] >= (int)$b['aov'] && ($big === null || $cc[2] < $big[2])) $big = $cc; if ($big) $c = $big; }   // a big-ticket customer sees a combo at least as big as his usual order $GLOBALS['wa_aft_last'] = ['kind' => 'offer', 'sz' => (int)$c[1]];
        $GLOBALS['wa_aft_last'] = ['kind' => 'offer', 'sz' => (int)$c[1]];
        if (!empty($b['reorder']) && (int)($b['since'] ?? 0) > 0) return 'Pichle order ko ' . (int)$b['since'] . ' din ho gaye - perfume khatam hone wala hoga? ' . $c[0] . ' x ' . $c[1] . ' ml Rs ' . $c[2] . '. Dobara order ke liye reply kijiye.';   // 🧭 his own reorder cycle
        return $season . ': ' . $c[0] . ' x ' . $c[1] . ' ml ' . $phrase[($step + $h) % count($phrase)] . ' Rs ' . $c[2] . '. Reply kijiye, hum order bana denge.';
    }
    /** 💬 The customers behind the After-Order Replies number (same row shape as wl_repliers): they wrote within 2 days after an After-Order message sent since $from; "Stop" / "Not now" and business auto-replies are left out. [rows, more, count] */
    function wa_aft_repliers(PDO $pdo, int $from, int $limit = 600): array
    {
        $f = date('Y-m-d H:i:s', $from); $q = $pdo->prepare("SELECT phone, sent_at FROM wa_aft_plan WHERE status = 'sent' AND sent_at >= ?"); $q->execute([$f]); $byP = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $byP[(string)$r['phone']][] = (int)strtotime((string)$r['sent_at']);
        $last = []; foreach (array_chunk(array_keys($byP), 400) as $ch) {
            $x = $pdo->prepare("SELECT phone, body, mtype, ts FROM wa_messages WHERE dir = 'in' AND ts >= ? AND phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ") ORDER BY id"); $x->execute(array_merge([$f], $ch));
            foreach ($x->fetchAll(PDO::FETCH_ASSOC) as $r) { if (function_exists('wl_is_decline') && wl_is_decline((string)$r['body'])) continue; $t = (int)strtotime((string)$r['ts']); foreach ($byP[(string)$r['phone']] ?? [] as $st0) if ($t > $st0 && $t <= $st0 + 2 * 86400) { $last[(string)$r['phone']] = ['t' => $t, 'b' => (string)$r['body'], 'm' => (string)$r['mtype']]; break; } }
        }
        if (function_exists('wa_autoreply_phones') && $last) { try { foreach (wa_autoreply_phones($pdo, array_keys($last)) as $__p => $__x) unset($last[$__p]); } catch (Throwable $e) { } }
        $info = []; foreach (array_chunk(array_keys($last), 400) as $ch) { $c = $pdo->prepare("SELECT phone, name, assigned_emp, last_in_at, last_out_at, status FROM wa_contacts WHERE phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $c->execute($ch); foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) $info[(string)$r['phone']] = $r; }
        $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $rows = [];
        foreach ($last as $ph => $l) { $c = $info[$ph] ?? []; $answered = !empty($c['last_out_at']) && (string)$c['last_out_at'] >= (string)($c['last_in_at'] ?? ''); $emp = (int)($c['assigned_emp'] ?? 0);
            $rows[] = ['phone' => $ph, 'name' => (string)($c['name'] ?? ''), 'text' => mb_substr(($l['b'] !== '' ? $l['b'] : '[' . $l['m'] . ']'), 0, 90), 'at' => date('Y-m-d H:i:s', $l['t']), 'wait' => !$answered, 'emp' => $emp, 'holder' => $emp > 0 ? (string)($nm[$emp] ?? '') : '', 'closed' => ((string)($c['status'] ?? '') === 'closed')]; }
        usort($rows, function ($a, $b) { return [(int)$b['wait'], $b['at']] <=> [(int)$a['wait'], $a['at']]; });
        return [array_slice($rows, 0, $limit), count($rows) > $limit, count($rows)];
    }
    /** 📊 What the After-Order messages brought since $from (owner 22 Nov: "like the 30-day plan - replies, spend, orders"): sent, delivered, read, replies (the customer wrote within 2 days after a message), orders + revenue (a shop order within 7 days after a message), cost (utility / marketing rate by the template's category). */
    function wa_aft_summary(PDO $pdo, int $from): array
    {
        $o = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'replied' => 0, 'orders' => 0, 'buyers' => 0, 'revenue' => 0, 'utility' => 0, 'plain' => 0, 'cost' => 0.0, 'rate_u' => 0.15, 'rate_m' => 1.09];
        try { $tc = function_exists('wa_team_cfg') ? wa_team_cfg($pdo) : null; if (is_array($tc)) { $o['rate_u'] = (float)$tc['rate_utility']; $o['rate_m'] = (float)$tc['rate_marketing']; }
            $f = date('Y-m-d H:i:s', $from); $q = $pdo->prepare("SELECT phone, tpl_id, sent_at, wamid, status FROM wa_aft_plan WHERE status IN ('sent','failed') AND sent_at >= ?"); $q->execute([$f]); $rows = $q->fetchAll(PDO::FETCH_ASSOC); $sent = []; $tids = [];
            foreach ($rows as $r) if ((int)$r['tpl_id'] > 0) $tids[(int)$r['tpl_id']] = 1; $cat = [];
            if ($tids) foreach ($pdo->query("SELECT id, category, relabelled_at FROM wa_templates WHERE id IN (" . implode(',', array_map('intval', array_keys($tids))) . ")")->fetchAll(PDO::FETCH_ASSOC) as $r) $cat[(int)$r['id']] = (strtoupper((string)$r['category']) === 'UTILITY' && empty($r['relabelled_at']));
            foreach ($rows as $r) { if ($r['status'] === 'failed') { $o['failed']++; continue; } $o['sent']++; if (!empty($cat[(int)$r['tpl_id']])) $o['utility']++; else $o['plain']++; $sent[] = $r; }
            $o['cost'] = round($o['utility'] * $o['rate_u'] + $o['plain'] * $o['rate_m'], 2);
            $wm = array_values(array_filter(array_column($sent, 'wamid'))); $st = [];
            foreach (array_chunk($wm, 400) as $ch) { $x = $pdo->prepare("SELECT wamid, status FROM wa_messages WHERE wamid IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $x->execute($ch); foreach ($x->fetchAll(PDO::FETCH_ASSOC) as $r) $st[(string)$r['wamid']] = (string)$r['status']; }
            foreach ($sent as $r) { $x = $st[(string)$r['wamid']] ?? ''; if ($x === 'delivered' || $x === 'read') $o['delivered']++; if ($x === 'read') $o['read']++; }
            $byP = []; $byK = []; foreach ($sent as $r) { $byP[(string)$r['phone']][] = (int)strtotime((string)$r['sent_at']); $byK[wa_aft_k10($r['phone'])][] = (int)strtotime((string)$r['sent_at']); }   // orders are matched by the LAST 10 DIGITS (the shop's phone has no 91): matched by the full number they never met, so After-Order orders always showed 0 (owner 22 Nov)
            if ($byP) { $rep = [];
                foreach (array_chunk(array_keys($byP), 400) as $ch) { $x = $pdo->prepare("SELECT phone, ts, body FROM wa_messages WHERE dir = 'in' AND ts >= ? AND phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $x->execute(array_merge([$f], $ch));
                    foreach ($x->fetchAll(PDO::FETCH_ASSOC) as $r) { if (function_exists('wl_is_decline') && wl_is_decline((string)$r['body'])) continue; $t = (int)strtotime((string)$r['ts']); foreach ($byP[(string)$r['phone']] ?? [] as $st0) if ($t > $st0 && $t <= $st0 + 2 * 86400) { $rep[(string)$r['phone']] = 1; break; } } }
                if ($rep && function_exists('wa_autoreply_phones')) { try { foreach (wa_autoreply_phones($pdo, array_keys($rep)) as $__p => $__x) unset($rep[$__p]); } catch (Throwable $e) { } }   // "Stop" / "Not now" and another business's auto-reply are not replies
                $o['replied'] = count($rep);
                $ord = []; $x = $pdo->prepare("SELECT phone, created_at, total FROM shop_orders WHERE COALESCE(tags, '') NOT LIKE '%wa-rx%' AND created_at >= ? AND phone IS NOT NULL AND phone != '' LIMIT 200000"); $x->execute([$f]);
                foreach ($x->fetchAll(PDO::FETCH_ASSOC) as $r) { $k = function_exists('wa_key') ? wa_key($r['phone']) : substr(preg_replace('/\D+/', '', (string)$r['phone']), -10); if ($k !== '' && isset($byK[$k])) $ord[$k][] = [(int)strtotime((string)$r['created_at']), (float)$r['total']]; }
                $buy = []; foreach ($ord as $k => $list) foreach ($list as $ot) foreach ($byK[$k] as $st0) if ($st0 < $ot[0] && $ot[0] <= $st0 + 7 * 86400) { $o['orders']++; $o['revenue'] += (int)round($ot[1]); $buy[$k] = 1; break; }
                $o['buyers'] = count($buy); }
        } catch (Throwable $e) { $o['error'] = substr($e->getMessage(), 0, 80); }
        return $o;
    }
    /** The screen's numbers (Campaigns -> Lineup, "After-Order plan" under the 30-day plan). */
    function wa_aft_overview(PDO $pdo): array
    {
        wa_aft_tables($pdo); $now = time(); $o = ['on' => wa_aft_set($pdo, 'wa_aft_on', '1') !== '0', 'cap' => (int)wa_aft_set($pdo, 'wa_aft_cap', '3000'), 'spread' => (int)wa_aft_set($pdo, 'wa_aft_spread', '5'), 'ver' => wa_aft_set($pdo, 'wa_aft_ver', '1'), 'quiet' => function_exists('wa_quiet_hours') && wa_quiet_hours()];
        try { $o['cust'] = $pdo->query("SELECT status, COUNT(*) FROM wa_aft_cust GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR); $o['plan'] = $pdo->query("SELECT status, COUNT(*) FROM wa_aft_plan GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
            $q = $pdo->prepare("SELECT COUNT(*) FROM wa_aft_plan WHERE status = 'sent' AND sent_at >= ?"); $q->execute([date('Y-m-d 00:00:00')]); $o['sent_today'] = (int)$q->fetchColumn();
            $q = $pdo->prepare("SELECT COUNT(*) FROM wa_aft_plan p JOIN wa_aft_cust c ON c.phone = p.phone WHERE p.status = 'planned' AND p.due_at <= ? AND c.status = 'active'"); $q->execute([date('Y-m-d H:i:s', $now)]); $o['due_now'] = (int)$q->fetchColumn();
            $q = $pdo->prepare("SELECT DATE(due_at) d, COUNT(*) n FROM wa_aft_plan WHERE status = 'planned' AND due_at >= ? AND due_at < ? GROUP BY d ORDER BY d"); $q->execute([date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', $now + 15 * 86400)]); $o['days'] = $q->fetchAll(PDO::FETCH_KEY_PAIR);
            $o['learn'] = json_decode((string)setting_get($pdo, 'wa_aft_learn', ''), true) ?: []; $o['failed'] = $pdo->query("SELECT SUBSTR(why, 1, 70) w, COUNT(*) n FROM wa_aft_plan WHERE status = 'failed' GROUP BY w ORDER BY n DESC LIMIT 5")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) { $o['error'] = $e->getMessage(); }
        try { $c = json_decode((string)setting_get($pdo, 'wa_aft_sum', ''), true); if (!is_array($c) || (int)($c['t'] ?? 0) < $now - 120) { $c = ['t' => $now, 'today' => wa_aft_summary($pdo, (int)strtotime(date('Y-m-d 00:00:00'))), 'all' => wa_aft_summary($pdo, $now - 30 * 86400)]; setting_set($pdo, 'wa_aft_sum', json_encode($c)); } $o['sum'] = $c; } catch (Throwable $e) { }   // cached 2 minutes: the screen asks often
        $st = []; foreach (wa_aft_steps(40) as $k => $v) $st[] = ['step' => $k, 'day' => $v[0], 'group' => $v[1], 'kind' => strpos($v[2], 'dyn') === 0 ? substr($v[2], 4) : 'care', 'line' => strpos($v[2], 'dyn') === 0 ? wa_aft_line_dyn($pdo, $k, '0000', $now, substr($v[2], 4)) : wa_aft_line($pdo, $v[2])]; $o['steps'] = $st;
        return $o;
    }
    /** 📷 PHOTO templates (owner, 21 Nov: "sometimes add photos - get the photo templates approved"): made ONCE from photos of the media library (39 = perfume bottles, 37 = gift hamper), category MARKETING, two blanks (name, the line), sent to every WhatsApp account of the shop.
     *  When Meta approves them, about every 6th message goes out with the photo; until then (or if one fails) the normal message goes. */
    function wa_aft_photo_make(PDO $pdo): array
    {
        $res = ['made' => 0, 'failed' => []]; foreach (['wa_tpl_clean', 'wa_tpl_insert', 'wa_tpl_row'] as $f) if (!function_exists($f)) return ['made' => 0, 'failed' => ['template module not loaded']];
        $ids = json_decode((string)setting_get($pdo, 'wa_aft_photo_tpls', ''), true); if (!is_array($ids)) $ids = []; $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 12, 'made' => 0];
        foreach (['bottles' => 39, 'gift' => 37] as $k => $lib) {
            if (!empty($ids[$k])) continue; if (function_exists('wa_lib_row') && !wa_lib_row($pdo, $lib)) { $res['failed'][] = $k . ': photo ' . $lib . ' not in the library'; continue; }
            [$t, $err] = wa_tpl_clean(['name' => 'tmph_after_photo_' . $k, 'language' => 'en', 'category' => 'MARKETING', 'header_format' => 'IMAGE', 'header_lib_id' => $lib, 'body' => 'Hi {{1}} ✨ From TM Perfume House: {{2}} Reply here and we will take care of the rest 🙏', 'footer' => 'TM Perfume House',
                'examples' => ['body' => ['Rahul', '5 x 50 ml sirf Rs 2940 mein. Order ke liye reply kijiye.'], 'labels' => ['Name', 'Message']], 'folder' => 'Lineup']);
            if ($err !== '') { $res['failed'][] = $k . ': ' . $err; continue; }
            $id = wa_tpl_insert($pdo, $t, 0, 'After-Order plan: a message with a photo (about every 6th message).', 'Marketing message with a photo to a customer who ordered.'); $r = wa_tpl_row($pdo, $id); if (!$r) { $res['failed'][] = $k . ': could not save'; continue; }
            $ids[$k] = (int)$id; setting_set($pdo, 'wa_aft_photo_tpls', json_encode($ids)); $res['made']++;
            foreach ($wabas as $w) { if (function_exists('wa_tpl_try_create')) { [$ok, $why] = wa_tpl_try_create($pdo, $r, $w, $budget); } elseif (function_exists('wa_tpl_create_at')) { [$ok, $why] = wa_tpl_create_at($pdo, $r, $w); } else { $ok = false; $why = 'no submit function'; } if (!$ok) $res['failed'][] = $k . ' -> ' . $why; }
        }
        return $res;
    }
    function wa_aft_k10($phone): string { return substr(preg_replace('/\D+/', '', (string)$phone), -10); }
    /** Write the ladder of one customer for the next 120 days (the minute job tops it up later, so a customer never has a year of rows): steps already sent are never written again; past steps are dropped except the newest one, which goes out spread over the next days (never a burst). */
    function wa_aft_plan(PDO $pdo, string $phone, int $anchor, int $now, int $spreadDays, int $horizon = 120): int
    {
        $pdo->prepare("DELETE FROM wa_aft_plan WHERE phone = ? AND status = 'planned'")->execute([$phone]); $ms = $pdo->prepare("SELECT COALESCE(MAX(step), 0) FROM wa_aft_plan WHERE phone = ? AND status = 'sent'"); $ms->execute([$phone]); $from = (int)$ms->fetchColumn() + 1;
        $ins = $pdo->prepare("INSERT INTO wa_aft_plan (phone, step, due_at, status) VALUES (?, ?, ?, 'planned')"); $n = 0; $lastPast = 0;
        for ($k = $from; $k < $from + 1000; $k++) { $d = $anchor + wa_aft_step($k)[0] * 86400; if ($d > $now) break; $lastPast = $k; }
        for ($k = $from; $k < $from + 1000; $k++) {
            $due = $anchor + wa_aft_step($k)[0] * 86400; if ($due > $now + $horizon * 86400) break;
            if ($due <= $now) { if ($k !== $lastPast) continue; $due = $now + 600 + (int)(((int)substr($phone, -6)) % max(1, $spreadDays * 86400 - 600)); }
            $ins->execute([$phone, $k, date('Y-m-d H:i:s', $due)]); $n++; }
        return $n;
    }
    /** Keeps every active customer's rows 60-120 days ahead (also the following years). */
    function wa_aft_topup(PDO $pdo, int $now): int
    {
        $n = 0; try { $q = $pdo->prepare("SELECT c.phone, c.anchor_at, MAX(p.step) ms FROM wa_aft_cust c JOIN wa_aft_plan p ON p.phone = c.phone WHERE c.status = 'active' GROUP BY c.phone, c.anchor_at HAVING MAX(p.due_at) < ? LIMIT 400"); $q->execute([date('Y-m-d H:i:s', $now + 60 * 86400)]);
            $ins = $pdo->prepare("INSERT INTO wa_aft_plan (phone, step, due_at, status) VALUES (?, ?, ?, 'planned')");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $anchor = (int)strtotime((string)$r['anchor_at']); for ($k = (int)$r['ms'] + 1; $k < (int)$r['ms'] + 200; $k++) { $due = $anchor + wa_aft_step($k)[0] * 86400; if ($due > $now + 120 * 86400) break; $ins->execute([(string)$r['phone'], $k, date('Y-m-d H:i:s', $due)]); $n++; } } } catch (Throwable $e) { }
        return $n;
    }
    /** Customers whose order was DELIVERED (owner, 21 Nov: the plan starts after DELIVERY, never after the order or its confirmation; the order / tracking / delivery confirmations are the Orders module's own and are left alone).
     *  The last 31 days are looked at every 30 minutes; a customer joins the day his parcel shows delivered; last month's customers are spread over wa_aft_spread days. */
    function wa_aft_enrol(PDO $pdo, int $now): int
    {
        if (!wa_aft_tables($pdo)) return 0; $n = 0; $win = max(31, min(800, (int)wa_aft_set($pdo, 'wa_aft_window', '400')));   // every buyer of the last wa_aft_window days (owner 21 Nov: all past buyers, not only last month's); the older the buyer the longer his first message is spread (newest buyers first)
        try {
            $q = $pdo->prepare("SELECT o.order_name, o.created_at, o.total, o.phone, (SELECT p.delivered_at FROM dp_dsp_parcels p WHERE p.order_name = o.order_name AND p.delivered_at IS NOT NULL ORDER BY p.id DESC LIMIT 1) dl FROM shop_orders o WHERE o.created_at >= ? AND o.created_at <= ? AND o.financial_status NOT IN ('voided', 'refunded') AND (RIGHT(REPLACE(REPLACE(REPLACE(o.phone, '+', ''), ' ', ''), '-', ''), 10) COLLATE utf8mb4_unicode_ci) NOT IN (SELECT phone COLLATE utf8mb4_unicode_ci FROM wa_aft_cust WHERE status = 'active') HAVING dl IS NOT NULL OR created_at < ? ORDER BY o.created_at DESC LIMIT 3000");
            $q->execute([date('Y-m-d H:i:s', $now - $win * 86400), date('Y-m-d H:i:s', $now - 86400), date('Y-m-d H:i:s', $now - 21 * 86400)]); $seen = [];
            $ex = $pdo->prepare("SELECT order_at FROM wa_aft_cust WHERE phone = ?");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ph = wa_aft_k10($r['phone']); if (strlen($ph) !== 10 || isset($seen[$ph])) continue; $seen[$ph] = 1; $oat = (int)strtotime((string)$r['created_at']); $anchor = !empty($r['dl']) ? (int)strtotime((string)$r['dl']) : $oat + 7 * 86400; if ($anchor <= 0 || $anchor > $now) continue;   // an old order without a parcel record counts as delivered a week after the order
                $ex->execute([$ph]); $e = $ex->fetchColumn(); if ($e !== false && (int)strtotime((string)$e) >= $oat) continue;   // already on the ladder of this (or a newer) order
                $c = function_exists('wa_contact') ? wa_contact($pdo, $ph) : null; if (!$c || (int)($c['optout'] ?? 0) === 1 || (int)($c['camp_optout'] ?? 0) === 1) continue;
                $age = (int)(($now - $anchor) / 86400); $spread = $age <= 31 ? 2 : ($age <= 90 ? 3 : ($age <= 180 ? 4 : 6));   // owner 22 Nov: only 56 went out in the first hours (spread 5 / 12 / 25 / 40 days): now 2 / 3 / 4 / 6 days
                $pdo->prepare("REPLACE INTO wa_aft_cust (phone, order_name, order_at, anchor_at, status, why, total, created_at) VALUES (?, ?, ?, ?, 'active', 'v3', ?, ?)")->execute([$ph, (string)$r['order_name'], date('Y-m-d H:i:s', $oat), date('Y-m-d H:i:s', $anchor), (int)$r['total'], date('Y-m-d H:i:s', $now)]);
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
        $nm = function_exists('wd_name') ? wd_name($c) : 'ji'; $vars = $nv === 2 ? [$nm, $line] : array_slice([$nm, 'your TM perfume', $line], 0, max(1, $nv));   // the photo templates have two blanks: name and the line
        [$ok, $res] = wa_send_tpl_one($pdo, $c, $t, $ctx['nums'][$nid], $nv ? $vars : [], 'followup');
        return $ok ? [true, (string)$res] : [false, (string)$res];
    }
    /** 🛟 A message of the After-Order plan whose delivery receipt says FAILED for a marketing-only reason is sent again as a utility twin (the 30-day Lineup's twins: the shop's utility messages and the eight rescue wordings). One rescue per message, never if he wrote since / opted out. */
    function wa_aft_rescue(PDO $pdo, int $now, int $cap = 60): int
    {
        if (!function_exists('wln_twins') || !function_exists('wln_send_any') || !function_exists('wln_mkt_codes')) return 0;
        $in = implode(',', wln_mkt_codes()); $n = 0; $t0 = microtime(true); $rows = [];
        try { if (setting_get($pdo, 'wa_aft_rescue_reopen', '') !== date('Y-m-d', $now)) { setting_set($pdo, 'wa_aft_rescue_reopen', date('Y-m-d', $now)); $pdo->prepare("UPDATE wa_aft_plan SET why = '' WHERE status = 'sent' AND why LIKE 'rescue: no utility message%' AND sent_at >= ?")->execute([date('Y-m-d H:i:s', $now - 36 * 3600)]); } } catch (Throwable $e) { }
        try { $f = $pdo->prepare("SELECT wamid, err_code FROM wa_messages WHERE dir = 'out' AND mtype = 'template' AND ts >= ? AND status = 'failed' AND err_code IN ($in) ORDER BY id DESC LIMIT 600"); $f->execute([date('Y-m-d H:i:s', $now - 36 * 3600)]); $bad = []; foreach ($f->fetchAll() as $m) $bad[(string)$m['wamid']] = (int)$m['err_code'];
            foreach (array_chunk(array_keys($bad), 300) as $ch) { $x = $pdo->prepare("SELECT id, phone, step, tpl_id, sent_at, wamid FROM wa_aft_plan WHERE status = 'sent' AND why = '' AND wamid IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $x->execute($ch); foreach ($x->fetchAll() as $r) { $r['err_code'] = $bad[(string)$r['wamid']]; $rows[] = $r; if (count($rows) >= $cap) break 2; } } } catch (Throwable $e) { return 0; }
        $mark = $pdo->prepare("UPDATE wa_aft_plan SET why = ? WHERE id = ?"); $ins = $pdo->prepare("INSERT INTO wa_aft_plan (phone, step, due_at, status, tpl_id, sent_at, wamid, why) VALUES (?, ?, ?, 'sent', ?, ?, ?, ?)");
        foreach ($rows as $r) {
            if (microtime(true) - $t0 > 12) break; $ph = (string)$r['phone']; $c = function_exists('wa_contact') ? wa_contact($pdo, $ph) : null; if (!$c) { $mark->execute(['rescue: no contact', $r['id']]); continue; }
            if (!empty($c['optout']) || !empty($c['camp_optout'])) { $mark->execute(['rescue: opted out', $r['id']]); continue; }
            if (!empty($c['last_in_at']) && (int)strtotime((string)$c['last_in_at']) > (int)strtotime((string)$r['sent_at'])) { $mark->execute(['rescue not needed: he wrote', $r['id']]); continue; }
            $tw = wln_twins($pdo, $ph, 'all', 0, (int)$r['tpl_id']); if (!$tw) { $mark->execute(['rescue: no utility message left', $r['id']]); continue; }
            foreach ($tw as [$tid, $key]) { $res = wln_send_any($pdo, $c, $tid); if ($res[0]) { $ts = date('Y-m-d H:i:s'); $ins->execute([$ph, (int)$r['step'], $ts, $tid, $ts, (string)($res[3] ?? ''), 'utility rescue of #' . $r['id'] . ' (' . $r['err_code'] . ')']); $mark->execute(['rescued (' . $r['err_code'] . ') as utility #' . $tid, $r['id']]); $n++; continue 2; } }
            $mark->execute(['rescue: no utility message could go', $r['id']]);
        }
        return $n;
    }
    /** The minute job: enrol (every 30 min) and send what is due (daily cap, 03:00-09:00 quiet). */
    function wa_aft_tick(PDO $pdo, int $budget = 40): array
    {
        $o = ['entered' => 0, 'sent' => 0, 'waiting' => 0]; if (wa_aft_set($pdo, 'wa_aft_on', '1') === '0' || !wa_aft_tables($pdo)) return $o;
        if (function_exists('wa_quiet_hours') && wa_quiet_hours()) { $o['why'] = 'quiet hours'; return $o; }
        if (!function_exists('wa_lock') || !wa_lock($pdo, 'wlaft', 55)) return $o; $now = time(); $t0 = microtime(true); setting_set($pdo, 'wa_aft_tick_at', date('Y-m-d H:i:s', $now));
        if (wa_aft_set($pdo, 'wa_aft_ver', '1') !== '3') {   // one time: the earlier ladders become the v3 one (about 200 messages a year) (nobody has been sent a message yet); 600 customers per minute
            try { $mq = $pdo->query("SELECT phone, anchor_at FROM wa_aft_cust WHERE status = 'active' AND why <> 'v3' LIMIT 600")->fetchAll(PDO::FETCH_ASSOC); $sp = max(1, (int)wa_aft_set($pdo, 'wa_aft_spread', '5'));
                foreach ($mq as $mr) { wa_aft_plan($pdo, (string)$mr['phone'], (int)strtotime((string)$mr['anchor_at']), $now, $sp); $pdo->prepare("UPDATE wa_aft_cust SET why = 'v3' WHERE phone = ?")->execute([(string)$mr['phone']]); }
                if (!$mq) setting_set($pdo, 'wa_aft_ver', '3'); } catch (Throwable $e) { }
            return $o; }
        if (wa_aft_set($pdo, 'wa_aft_photo_made', '0') !== '1') { setting_set($pdo, 'wa_aft_photo_made', '1'); try { $pm = wa_aft_photo_make($pdo); setting_set($pdo, 'wa_aft_photo_log', json_encode($pm)); if (!empty($pm['failed']) && $pm['made'] === 0) setting_set($pdo, 'wa_aft_photo_made', '0'); } catch (Throwable $e) { setting_set($pdo, 'wa_aft_photo_made', '0'); } }
        if ($now - (int)wa_aft_set($pdo, 'wa_aft_learn_at', '0') >= 21600) { setting_set($pdo, 'wa_aft_learn_at', (string)$now); wa_aft_learn($pdo, $now); }
        if ($now - (int)wa_aft_set($pdo, 'wa_aft_top_at', '0') >= 3600) {   // the rows are kept 60-120 days ahead: looking once an hour is plenty (it was every 10 minutes, a 15 s query)
            setting_set($pdo, 'wa_aft_top_at', (string)$now); wa_aft_topup($pdo, $now); }
        if ($now - (int)wa_aft_set($pdo, 'wa_aft_enrol_at', '0') >= 1800) { setting_set($pdo, 'wa_aft_enrol_at', (string)$now); $o['entered'] = wa_aft_enrol($pdo, $now); }
        // 🛟 owner 22 Nov: a message that failed for a marketing-only reason (131049 / 131050 / 130472) is sent again at once as a UTILITY twin (the same rescue as the 30-day Lineup)
        try { $o['rescued'] = wa_aft_rescue($pdo, $now); } catch (Throwable $e) { }
        // one time: the older buyers' first message goes out within 4 days (it was spread over 12-40 days: 56 messages in the first hours)
        try { if (wa_aft_set($pdo, 'wa_aft_respread', '') !== '1') { setting_set($pdo, 'wa_aft_respread', '1'); $pdo->prepare("UPDATE wa_aft_plan p JOIN (SELECT phone, MIN(id) mid FROM wa_aft_plan WHERE status = 'planned' GROUP BY phone) m ON m.mid = p.id JOIN wa_aft_cust c ON c.phone = p.phone SET p.due_at = DATE_ADD(?, INTERVAL (600 + CAST(RIGHT(p.phone, 6) AS UNSIGNED) % 345000) SECOND) WHERE p.status = 'planned' AND p.due_at > ? AND c.status = 'active' AND c.anchor_at < ? AND NOT EXISTS (SELECT 1 FROM wa_aft_plan s WHERE s.phone = p.phone AND s.status = 'sent')")->execute([date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + 4 * 86400), date('Y-m-d H:i:s', $now - 31 * 86400)]); } } catch (Throwable $e) { }
        if (wa_aft_set($pdo, 'wa_aft_cap_v', '') !== '2') { setting_set($pdo, 'wa_aft_cap', '1500'); setting_set($pdo, 'wa_aft_cap_v', '2'); }   /* owner, 23 Nov: After-Order cost Rs 2,346 for 1 order (Rs 1,960) on 3,012 messages: the daily cap goes 3,000 -> 1,500 until orders show; later changes on the card stay */
        if (wa_aft_set($pdo, 'wa_aft_cap_v', '') !== '3') { setting_set($pdo, 'wa_aft_cap', '10000'); setting_set($pdo, 'wa_aft_cap_v', '3'); }   /* owner, 24 Nov 2026: After-Order daily cap 10,000 */
        $cap = max(0, (int)wa_aft_set($pdo, 'wa_aft_cap', '3000')); $q = $pdo->prepare("SELECT COUNT(*) FROM wa_aft_plan WHERE status = 'sent' AND sent_at >= ?"); $q->execute([date('Y-m-d 00:00:00')]); $today = (int)$q->fetchColumn(); if ($today >= $cap) { $o['why'] = 'daily cap'; return $o; }
        $rows = $pdo->prepare("SELECT p.id, p.phone, p.step, c.order_at, c.anchor_at FROM wa_aft_plan p JOIN wa_aft_cust c ON c.phone = p.phone WHERE p.status = 'planned' AND p.due_at <= ? AND c.status = 'active' ORDER BY p.due_at LIMIT 150")   /* owner 24 Nov 2026: 150 looked at per round (60 sent at most): many due rows are only moved on (live chat, 30 h gap), so 60 looked-at gave ~450 messages an hour */; $rows->execute([date('Y-m-d H:i:s', $now)]);
        $upd = $pdo->prepare("UPDATE wa_aft_plan SET status = ?, why = ?, sent_at = ?, tpl_id = ?, wamid = ? WHERE id = ?"); $push = $pdo->prepare("UPDATE wa_aft_plan SET due_at = ? WHERE id = ?"); $grp = wa_aft_groups();
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($o['sent'] >= max(25, min(80, (int)wa_aft_set($pdo, 'wa_aft_tick_n', '60'))) || $today + $o['sent'] >= $cap || microtime(true) - $t0 > $budget) break; $ph = (string)$r['phone']; $id = (int)$r['id'];
            $c = function_exists('wa_contact') ? wa_contact($pdo, $ph) : null; if (!$c || (int)($c['optout'] ?? 0) === 1 || (int)($c['camp_optout'] ?? 0) === 1) { $upd->execute(['cancelled', 'opted out / no contact', null, 0, '', $id]); continue; }
            try { $no = $pdo->prepare("SELECT COUNT(*) FROM shop_orders WHERE phone LIKE ? AND created_at > ?"); $no->execute(['%' . $ph, (string)$r['order_at']]); if ((int)$no->fetchColumn() > 0) { $pdo->prepare("UPDATE wa_aft_cust SET status = 'done', why = 'new order: his ladder restarts' WHERE phone = ?")->execute([$ph]); $pdo->prepare("UPDATE wa_aft_plan SET status = 'cancelled', why = 'new order' WHERE phone = ? AND status = 'planned'")->execute([$ph]); continue; } } catch (Throwable $e) { }
            // 🔗 LINK WITH THE 30-DAY LINEUP (owner, 21 Nov): the inquiry plan goes first. While the customer is on the Lineup (a new inquiry / a reply) this ladder waits; when the Lineup ends (30 days, or he ordered) it goes on from where it is. Rows that fell due during the wait are not sent one after the other: only the newest one goes, and two messages are at least 30 h apart.
            try { $lq = $pdo->prepare("SELECT 1 FROM wa_line_plan p JOIN wa_line_cust c ON c.phone = p.phone WHERE p.phone = ? AND p.status = 'planned' AND c.status = 'active' LIMIT 1"); $lq->execute([$ph]); if ($lq->fetchColumn()) { try { setting_set($pdo, 'wa_aft_lineup_n', (string)((int)wa_aft_set($pdo, 'wa_aft_lineup_n', '0') + 1)); } catch (Throwable $e) { } $push->execute([date('Y-m-d H:i:s', $now + 2 * 86400 + (int)substr($ph, -3)), $id]); $o['waiting']++; continue; } } catch (Throwable $e) { }
            try { $mq = $pdo->prepare("SELECT COALESCE(MAX(step), 0) FROM wa_aft_plan WHERE phone = ? AND status = 'planned' AND due_at <= ?"); $mq->execute([$ph, date('Y-m-d H:i:s', $now)]); if ((int)$mq->fetchColumn() > (int)$r['step']) { $upd->execute(['skipped', 'a newer step of his ladder is due: only that one goes', null, 0, '', $id]); continue; }
                $gq = $pdo->prepare("SELECT MAX(sent_at) FROM wa_aft_plan WHERE phone = ? AND status = 'sent'"); $gq->execute([$ph]); $lastS = (string)$gq->fetchColumn(); if ($lastS !== '' && (int)strtotime($lastS) > $now - 30 * 3600) { $push->execute([date('Y-m-d H:i:s', (int)strtotime($lastS) + 30 * 3600 + (int)substr($ph, -2) * 20), $id]); $o['waiting']++; continue; } } catch (Throwable $e) { }
            $busy = (!empty($c['last_in_at']) && (int)strtotime((string)$c['last_in_at']) > $now - 86400) || (!empty($c['last_out_at']) && (int)strtotime((string)$c['last_out_at']) > $now - 6 * 3600);   // he wrote in the last 24 h (a live chat), or we wrote in the last 6 h   // a live chat: never over his conversation, try tomorrow
            if ($busy || (int)($c['cx_id'] ?? 0) > 0) { try { setting_set($pdo, 'wa_aft_busy_n', (string)((int)wa_aft_set($pdo, 'wa_aft_busy_n', '0') + 1)); } catch (Throwable $e) { } $push->execute([date('Y-m-d H:i:s', $now + 86400 + (int)substr($ph, -4)), $id]); $o['waiting']++; continue; }
            $st = wa_aft_step((int)$r['step']); if (!$st) { $upd->execute(['cancelled', 'unknown step', null, 0, '', $id]); continue; }
            $ids = $grp[$st[1]] ?? []; if (!$ids) { $upd->execute(['cancelled', 'no template', null, 0, '', $id]); continue; }
            try { $sq = $pdo->prepare("SELECT COUNT(*) FROM wa_aft_plan WHERE phone = ? AND status = 'sent' AND sent_at > COALESCE((SELECT MAX(m.ts) FROM wa_messages m WHERE (m.phone COLLATE utf8mb4_unicode_ci) = (? COLLATE utf8mb4_unicode_ci) AND m.dir = 'in'), '2000-01-01')"); $sq->execute([$ph, $ph]); if ((int)$sq->fetchColumn() >= 6 && (int)$r['step'] % 3 !== 0) { $upd->execute(['skipped', 'quiet customer: 6 messages without an answer, only every 3rd goes', null, 0, '', $id]); continue; } } catch (Throwable $e) { }   // 🧭 behaviour: a customer who never answers gets a third of the messages (less cost, fewer blocks)
            if (!wa_aft_hour_ok($pdo, (int)date('G', $now)) && !(int)($GLOBALS['wa_aft_pushed'][$id] ?? 0)) { $nh = 0; for ($i = 1; $i <= 12; $i++) { $hh = (int)date('G', $now + $i * 3600); if (wa_aft_hour_ok($pdo, $hh)) { $nh = $i; break; } } if ($nh > 0) { $push->execute([date('Y-m-d H:i:s', (int)(floor(($now + $nh * 3600) / 3600) * 3600) + (int)substr($ph, -3) * 3), $id]); $o['waiting']++; continue; } }   // 🧭 an hour with clearly fewer replies: the message waits for a better hour
            $kind = strpos($st[2], 'dyn') === 0 ? substr($st[2], 4) : 'care';
            $beh = wa_aft_behavior($pdo, $ph, $now); $isRe = false;
            if (($kind === 'offer' || $kind !== 'care') && $beh['since'] >= 45) { try { $fq = $pdo->prepare("SELECT COUNT(*) FROM wa_aft_plan WHERE phone = ? AND status = 'sent'"); $fq->execute([$ph]); if ((int)$fq->fetchColumn() === 0) { $kind = 'offer'; $isRe = true; } } catch (Throwable $e) { } }   // 🔁 the FIRST message to an older buyer (45+ days since his last order) is always the reorder message: "N days since your last order ...", with the size above his last order
            if ($kind === 'offer' && $beh['cycle'] > 0 && $beh['since'] >= $beh['cycle'] - 7 && $beh['since'] <= $beh['cycle'] + 10) { try { $rq = $pdo->prepare("SELECT COUNT(*) FROM wa_aft_plan WHERE phone = ? AND kind = 'reorder' AND sent_at >= ?"); $rq->execute([$ph, date('Y-m-d H:i:s', $now - 25 * 86400)]); $isRe = $isRe || (int)$rq->fetchColumn() === 0; } catch (Throwable $e) { } }
            if ($kind !== 'offer' && $kind !== 'care') { $kind = wa_aft_pick($pdo, 'kind', ['trust', 'testimonial', 'new', 'size', 'season'], (int)$r['step'] * 53 + (int)substr($ph, -4)); $ids = $grp[['trust' => 'long', 'testimonial' => 'loved', 'new' => 'newsim', 'size' => 'small', 'season' => 'valued'][$kind]] ?? $ids; }   // 🧠 the content kinds with more replies get more of the share
            $tid = (int)$ids[(int)substr($ph, -3) % count($ids)]; $GLOBALS['wa_aft_last'] = ['kind' => $kind, 'sz' => 0]; $lh = ''; $sentH = []; try { $hq = $pdo->prepare("SELECT lh FROM wa_aft_plan WHERE phone = ? AND status = 'sent' AND lh <> ''"); $hq->execute([$ph]); $sentH = array_flip($hq->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { }
            // 🔁 the same message (template + line) never goes to the same customer twice: another variant is tried (8 times)
            $ln = ''; for ($try = 0; $try < 8; $try++) { $ln = $kind === 'care' ? wa_aft_line($pdo, $st[2]) : wa_aft_line_dyn($pdo, (int)$r['step'] + $try * 11, $ph, $now, $kind, $beh + ['reorder' => $isRe ? 1 : 0]); $lh = substr(md5($tid . '|' . $ln), 0, 12); if (!isset($sentH[$lh]) || $kind === 'care') break; if ($try === 3 && !empty($ids)) $tid = (int)$ids[((int)substr($ph, -3) + 1) % count($ids)]; } $lk = $GLOBALS['wa_aft_last'] ?? ['kind' => $kind, 'sz' => 0]; if ($isRe) { $lk['kind'] = 'reorder'; $ids = $grp['reorder'] ?? $ids; $tid = (int)$ids[(int)substr($ph, -3) % count($ids)]; } $ok = false; $why = '';
            if ($kind !== 'care' && (int)$r['step'] % 6 === 3) { $pt = json_decode((string)setting_get($pdo, 'wa_aft_photo_tpls', ''), true); $ptid = is_array($pt) ? (int)($pt[$kind === 'season' ? 'gift' : 'bottles'] ?? 0) : 0; if ($ptid > 0) { [$ok, $why] = wa_aft_send($pdo, $c, $ptid, $ln); if ($ok) $tid = $ptid; } }   // 📷 about every 6th message goes with a photo once Meta approved the photo template
            if (!$ok) [$ok, $why] = wa_aft_send($pdo, $c, $tid, $ln);
            if (!$ok && strpos((string)$why, 'wait') === 0) { foreach (array_slice($ids, 0, 8) as $__alt) { if ((int)$__alt === $tid) continue; [$__ok2, $__why2] = wa_aft_send($pdo, $c, (int)$__alt, $ln); if ($__ok2) { $ok = true; $why = $__why2; $tid = (int)$__alt; break; } } }   // this template cannot go (no number of his account has it approved): another of the same group goes instead of waiting 30 minutes
            if ($ok) { $upd->execute(['sent', '', date('Y-m-d H:i:s'), $tid, $why, $id]); try { $pdo->prepare("UPDATE wa_aft_plan SET kind = ?, sz = ?, lh = ? WHERE id = ?")->execute([(string)$lk['kind'], (int)$lk['sz'], $lh, $id]); } catch (Throwable $e) { } $o['sent']++; try { $pdo->prepare("INSERT INTO wa_line_log (phone, at, what) VALUES (?, ?, ?)")->execute([$ph, date('Y-m-d H:i:s'), 'after-order step ' . (int)$r['step']]); } catch (Throwable $e) { } }
            elseif (strpos($why, 'wait') === 0) { try { setting_set($pdo, 'wa_aft_dbg', json_encode(['at' => date('H:i:s'), 'tid' => $tid, 'step' => (int)$r['step'], 'why' => mb_substr((string)$why, 0, 160), 'busy_skips' => (int)($o['waiting'] ?? 0)])); } catch (Throwable $e) { } $push->execute([date('Y-m-d H:i:s', $now + 1800), $id]); $o['waiting']++; if (strpos($why, 'no number') !== false) break; }
            else { try { setting_set($pdo, 'wa_aft_dbg', json_encode(['at' => date('H:i:s'), 'tid' => $tid, 'step' => (int)$r['step'], 'fail' => mb_substr((string)$why, 0, 160)])); } catch (Throwable $e) { } $upd->execute(['failed', mb_substr($why, 0, 150), null, $tid, '', $id]); }
        }
        return $o;
    }
}
