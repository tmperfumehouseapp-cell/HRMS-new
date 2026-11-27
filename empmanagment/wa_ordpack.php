<?php
/* Order pack (owner 25 Nov 2026): many MARKETING messages, each ONE message with 2-3 points together
 * (season / secret / price / offer / gift / extra ML / crazy discount / deadline), blank-line spacing, emojis, quick-reply buttons.
 * Names tmph_ord_NN. Made once (by name), sent to Meta through the normal template path. Switch off: wa_ordpack = 0. */
if (!function_exists('wa_ordpack_defs')) {
    function wa_ordpack_parts(): array
    {
        $open = [
            "Hello {{1}} ❄️\n\nSardi + shaadi ka season shuru — perfume ki sabse zyada demand abhi hai 🔥",
            "Hi {{1}} 🎉\n\nShaadi ka mausam aa gaya! Ek khaas *secret deal* aapke liye taiyar he 👀",
            "Hello {{1}} 🔓\n\nAapke liye kuch *unlock* hua he... andar kya he, dekh ke maza aa jayega 😍",
            "Hi {{1}} 🎁\n\nNew Year + wedding season — gift dene ka sabse achha time, aur gift ke saath surprise bhi 🤫",
            "Hello {{1}} 🌸\n\nWinter ki khushboo aa gayi: oud, musk, amber 🔥 Aur uske saath ek crazy offer bhi!",
            "Hi {{1}} 👋\n\nEk chhoti si baat... aaj hamne *dhamakedaar* kuch rakha he, sirf aapke liye ✨",
            "Hello {{1}} 🧥\n\nThand badh rahi he aur khushboo ka maza bhi! Aaj ek *reward* aapke naam unlock hua he 🏆",
            "Hi {{1}} ❄️\n\nSardi aur shaadi ka season he — aaj ka *crazy price* dekhiye 💥",
            "Hello {{1}} 🎊\n\nExcitement ka time! Aapke liye ek *surprise reward* aur extra ML ki offer taiyar he 🎁",
            "Hi {{1}} 🌙\n\nWinter evening special: lambi chalne wali khushboo + ek secret discount jo sirf aaj ke liye he 🤫",
        ];
        $mid = [
            "🎁 8 × 20ml sirf ₹1,899\n💥 Saath me extra gift FREE",
            "🔥 10 × 20ml sirf ₹2,099\n🎁 Upar se surprise add-on",
            "✨ 6 × 50ml sirf ₹2,499\n💥 Extra 20ml bhi saath me",
            "🎁 7 × 50ml sirf ₹2,899\n🔓 Secret gift unlock hoga",
            "🔥 8 × 50ml sirf ₹3,299\n🎁 Extra discount alag se",
            "💥 3 × 50ml ₹2,100 + 1 × 20ml FREE\n✨ Gift packing bhi saath me",
            "🎁 5 × 20ml sirf ₹1,700\n🔥 Flat 5% OFF",
            "💥 Flat ₹2,250 me 4 × 50ml + 2 × 100ml\n🎁 Extra ML bhi milega",
            "🏆 Reward: 3 × 50ml ₹2,140 par 1 × 20ml FREE\n💥 Extra ML ka seedha fayda",
            "💥 CRAZY PRICE: 8 × 20ml sirf ₹1,899\n✨ Gift packing bhi saath me",
            "🎁 Flat 5% OFF\n✨ Saath me gift packing, dhamaka deal",   /* owner 25 Nov: the "prepaid par extra 2%" part is gone from these three lines (that discount does not exist any more) */
        ];
        $close = [
            "⏰ Offer aaj raat 11:59 PM tak!\n\nAbhi order karoge to hi milega 👇",
            "⏰ Sirf aaj ke liye — kal ye nahi milega!\n\nAbhi order karne ke liye tap kijiye 👇",
            "🚚 Jaldi order karoge to jaldi delivery bhi!\n\nNiche tap kijiye 👇",
            "Stock kam he, deal aaj raat tak 🔥\n\nOrder pakka karne ke liye tap kijiye 👇",
            "🏃 Jo pehle order karega, usi ko ye reward milega!\n\nAbhi order karne ke liye tap kijiye 👇",
            "⏰ Aaj raat 11:59 PM ke baad ye price wapas normal!\n\nAbhi order karke bachat kijiye 👇",
        ];
        $btn = [['Order Now', 'Send Details', 'Not Now'], ['Yes, Order', 'Show Offer'], ['I Want It', 'Send Details', 'Not Now']];   /* buttons in English (owner 25 Nov) */
        return [$open, $mid, $close, $btn];
    }
    function wa_ordpack_defs(): array
    {
        [$open, $mid, $close, $btn] = wa_ordpack_parts();
        $o = []; $seen = []; $O = count($open); $M = count($mid); $C = count($close);
        for ($n = 1; $n <= 90 && count($o) < 40; $n++) {
            $body = $open[($n * 3) % $O] . "\n\n" . $mid[($n * 5) % $M] . "\n\n" . $close[($n * 7) % $C]; if (isset($seen[$body])) continue; $seen[$body] = 1;
            $o[sprintf('tmph_ord_%02d', count($o) + 1)] = ['body' => $body, 'buttons' => $btn[$n % 3]];
        }
        return $o;
    }
    /** DAILY LEARNING (owner 25 Nov 2026: "see which messages get the replies, and change the daily messages by that"): once a day, after 06:00 IST, the reply rate of every order-pack / style message sent by the Cold funnel
     *  (replies per send, with a small prior) is credited to its parts (opening, offer lines, closing). The best parts are mixed into up to 6 NEW messages (a part that nobody has tried yet is also tried: 1 in 3),
     *  made through the normal path; messages with 600+ sends and less than half the median reply rate are taken out of the Cold list (setting wa_ordl_off). Report in setting wa_ordl_rep. Off: wa_ordl = 0. */
    function wa_ordpack_learn(PDO $pdo): void
    {
        if ((string)setting_get($pdo, 'wa_ordl', '1') === '0') return; $day = date('Y-m-d'); if ((string)setting_get($pdo, 'wa_ordl_day', '') === $day || (int)date('G') < 6) return;
        if (!function_exists('wcd_tpl_stats') && is_file(__DIR__ . '/wa_cold.php')) { try { require_once __DIR__ . '/wa_cold.php'; } catch (Throwable $e) { return; } } if (!function_exists('wcd_tpl_stats')) return;
        setting_set($pdo, 'wa_ordl_day', $day); [$open, $mid, $close, $btn] = wa_ordpack_parts(); $st = wcd_tpl_stats($pdo);
        $rows = $pdo->query("SELECT id, name, body FROM wa_templates WHERE category = 'MARKETING' AND name LIKE 'tmph\\_ord\\_%'")->fetchAll(PDO::FETCH_NUM); $rates = []; $sc = ['o' => [], 'm' => [], 'c' => []]; $cn = ['o' => [], 'm' => [], 'c' => []]; $per = [];
        foreach ($rows as $r) { $s = $st[(int)$r[0]] ?? null; if (!$s || (int)$s['sent'] < 200) continue; $rate = ((int)$s['replied'] + 0.5) / ((int)$s['sent'] + 50); $per[(int)$r[0]] = [$rate, (int)$s['sent'], (string)$r[1]]; $rates[] = $rate; $b = (string)$r[2];
            foreach ($open as $i => $x) if (strpos($b, $x) === 0) { $sc['o'][$i] = ($sc['o'][$i] ?? 0) + $rate; $cn['o'][$i] = ($cn['o'][$i] ?? 0) + 1; }
            foreach ($mid as $i => $x) if (strpos($b, $x) !== false) { $sc['m'][$i] = ($sc['m'][$i] ?? 0) + $rate; $cn['m'][$i] = ($cn['m'][$i] ?? 0) + 1; }
            foreach ($close as $i => $x) if (substr($b, -strlen($x)) === $x) { $sc['c'][$i] = ($sc['c'][$i] ?? 0) + $rate; $cn['c'][$i] = ($cn['c'][$i] ?? 0) + 1; } }
        $best = function (array $arr, array $cnt, int $total) { $a = []; for ($i = 0; $i < $total; $i++) $a[$i] = isset($cnt[$i]) ? $arr[$i] / $cnt[$i] : -1; arsort($a); return array_keys($a); };
        /* owner 27 Nov 2026: "take out the Cold messages that bring few replies". The daily check looked only at the tmph_ord_ messages; now EVERY marketing message the Cold funnel sends (style, combo, daily, Black Friday) is judged the same way: 600+ sent and a reply rate under half of the middle one = taken out */
        $perAll = []; $ratesAll = []; try { $ids = array_map('intval', array_keys($st)); if ($ids) foreach ($pdo->query("SELECT id FROM wa_templates WHERE category = 'MARKETING' AND id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_COLUMN) as $tid) { $s = $st[(int)$tid]; if ((int)$s['sent'] < 200) continue; $rt = ((int)$s['replied'] + 0.5) / ((int)$s['sent'] + 50); $perAll[(int)$tid] = [$rt, (int)$s['sent']]; $ratesAll[] = $rt; } } catch (Throwable $e) { }
        if (count($rates) >= 3) { sort($rates); $med = $rates[intdiv(count($rates), 2)]; } else $med = 0;
        if (count($ratesAll) >= 3) { sort($ratesAll); $medA = $ratesAll[intdiv(count($ratesAll), 2)]; $off = []; foreach ($perAll as $id => $p) if ($p[1] >= 600 && $p[0] < $medA * 0.5) $off[] = $id; setting_set($pdo, 'wa_ordl_off', json_encode($off)); if ($off && function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Cold funnel: ' . count($off) . ' marketing messages with few replies are taken out (of ' . count($perAll) . ' judged)'); } else $off = [];
        $bo = $best($sc['o'], $cn['o'], count($open)); $bm = $best($sc['m'], $cn['m'], count($mid)); $bc = $best($sc['c'], $cn['c'], count($close)); $new = []; $seed = (int)date('z') * 7;
        if (count($rates) >= 3) for ($k = 0; $k < 6; $k++) { $ex = ($k % 3 === 2);   /* every third new message tries parts nobody tested */
            $i = $ex ? ($seed + $k * 3) % count($open) : $bo[$k % 2]; $j = $ex ? ($seed + $k * 5) % count($mid) : $bm[($k + intdiv($k, 2)) % 3]; $c = $ex ? ($seed + $k * 7) % count($close) : $bc[$k % 2];
            $new[] = ['name' => 'tmph_ord_d' . date('ymd') . '_' . ($k + 1), 'body' => $open[$i] . "\n\n" . $mid[$j] . "\n\n" . $close[$c], 'buttons' => $btn[($i + $j) % 3]]; }
        $old = json_decode((string)setting_get($pdo, 'wa_ordl_new', '[]'), true); $keep = array_slice(array_merge((array)$old, $new), -60); setting_set($pdo, 'wa_ordl_new', json_encode($keep, JSON_UNESCAPED_UNICODE));
        $top = []; foreach ($per as $p) $top[] = $p; usort($top, function ($a, $b) { return $b[0] <=> $a[0]; });
        setting_set($pdo, 'wa_ordl_rep', json_encode(['day' => $day, 'tested' => count($per), 'median' => round($med * 100, 2), 'top' => array_slice(array_map(function ($p) { return [$p[2], round($p[0] * 100, 2), $p[1]]; }, $top), 0, 5), 'off' => count($off), 'new' => count($new), 'best_open' => $bo[0] ?? null, 'best_mid' => $bm[0] ?? null, 'best_close' => $bc[0] ?? null]));
    }
    /** HAND-WRITTEN replacements (owner 25 Nov 2026: "the 36 failed ones, write them by hand"): the dull messages of the Lineup / its map that the twin engine could not replace. Each gets a new exciting MARKETING message; the pair is
     *  registered as 'made', so the normal replace step swaps it in as soon as Meta approved it. Once per old message. Names tmph_hm_<old id>. */
    function wa_hand_defs(): array
    {
        $b = function (array $l) { return array_map(function ($t) { return ['type' => 'QUICK_REPLY', 'text' => $t]; }, $l); };
        return [
            3196 => ['src' => 'lmap:3196', 'vars' => 0, 'buttons' => $b(['Show me offers', 'Not now']), 'body' => "Hello Sir / Ma'am 🙏\n\nTM Perfume House me aaj sardi aur shaadi ka season special chal raha he ❄️💍\n\n🎁 Naye perfumes + combo offers aapke liye taiyaar\n\nDekhne ke liye button dabaiye 👇"],
            4728 => ['src' => 'lmap:4728', 'vars' => 0, 'buttons' => $b(['I Want To Order', 'Show Offer']), 'body' => "Hello! 👋\n\nSardi ka season aur aapke liye ek crazy combo ready he 🔥\n\n🎁 Extra ML + special price\n\nAbhi order karoge to hi milega 👇"],
            4934 => ['src' => 'line:ln_reorder_4', 'vars' => 1, 'buttons' => $b(['Reorder Now']), 'body' => "Hi {{1}} ji 😊\n\nAapka perfume khatam hone wala hai kya ? 👀\n\nSardi me wahi khushbu dobara, aur saath me ek khaas reorder surprise 🎁\n\nNeeche tap kijiye 👇"],
            4911 => ['src' => 'line:ln_general_4', 'vars' => 1, 'buttons' => $b(['Suggest Perfumes']), 'body' => "Hi {{1}} 😊\n\nSardi aur shaadi ka season he, aapke liye 2-3 perfume ka crazy combo chun ke rakha he 🔥\n\nWarm gehri khushbu ya light fragrance ? Tap kijiye 👇🎁"],
            4940 => ['src' => 'line:ln_all_10', 'vars' => 1, 'buttons' => $b(['Order Now']), 'body' => "Hi {{1}} 👋\n\nAaj ka special: extra ML aur flat discount aapke order par 🎁🔥\n\nCOD bhi chalu he, aur offer sirf aaj ke liye ⏰\n\nOrder karne ke liye tap kijiye 👇"],
            3916 => ['src' => 'line:ln_brand_4', 'vars' => 1, 'buttons' => $b(['20 ml Combo', '50 ml Combo']), 'body' => "Hi {{1}} ji 🌸\n\nPehli baar le rahe hain ? 20 ml se shuru karna sabse smart rahega, aur starter combo par extra bachat 💥\n\nKaunsa size dekhna chahenge ? 👇"],
        ];
    }
    function wa_hand_tick(PDO $pdo): int
    {
        if ((string)setting_get($pdo, 'wa_hand', '1') === '0' || !function_exists('wa_rs_state') || !function_exists('wa_rs_save') || !function_exists('wa_tpl_clean')) return 0;
        $st = wa_rs_state($pdo); $made = 0; $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 12, 'made' => 0];
        foreach (wa_hand_defs() as $old => $d) {
            $p = $st['pairs'][$old] ?? null; if ($p && !in_array((string)($p['st'] ?? ''), ['fail', 'making'], true)) continue; if ($made >= 2) break;
            $name = 'tmph_hm_' . $old; $ex = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? LIMIT 1"); $ex->execute([$name]); $id = (int)$ex->fetchColumn();
            if ($id <= 0) {
                $in = ['name' => $name, 'language' => 'en', 'category' => 'MARKETING', 'body' => $d['body'], 'footer' => 'TM Perfume House', 'buttons' => $d['buttons'], 'folder' => 'Offers']; if ($d['vars'] > 0) $in['examples'] = ['body' => ['Rahul'], 'labels' => ['Name']];
                [$t, $err] = wa_tpl_clean($in); if ($err !== '') { if (function_exists('wa_log')) wa_log($pdo, 'template', false, 'Hand-made ' . $name . ': ' . $err); continue; }
                $id = (int)wa_tpl_insert($pdo, $t, 0, 'Hand-written replacement (owner, 25 Nov 2026) of #' . $old, 'Hand-made'); $r = $id > 0 ? wa_tpl_row($pdo, $id) : null; if (!$r) continue;
                foreach ($wabas as $w) { if (function_exists('wa_tpl_try_create')) wa_tpl_try_create($pdo, $r, $w, $budget); }
            }
            $st['pairs'][$old] = ['new' => $id, 'src' => $d['src'], 'st' => 'made', 'at' => time(), 'hand' => 1]; $made++;
        }
        if ($made) wa_rs_save($pdo, $st);
        return $made;
    }
    /** THE "WILL NEVER REACH" LIST (owner 25 Nov 2026: "the numbers no message can reach go to a separate list; the goal of 100% is for everybody else"): a phone whose messages failed with 131026 (not on WhatsApp / cannot receive) at least twice in
     *  7 days is kept in wa_undeliv (phone, code, times, first / last seen), refreshed once an hour. Nothing is blocked by it yet: it is the list the 100% target is counted without. */
    function wa_undeliv_tick(PDO $pdo): int
    {
        if ((int)setting_get($pdo, 'wa_undeliv_at', '0') > time() - 3600) return 0; setting_set($pdo, 'wa_undeliv_at', (string)time());
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_undeliv (phone VARCHAR(20) NOT NULL PRIMARY KEY, code INT NOT NULL DEFAULT 0, n INT NOT NULL DEFAULT 0, first_at DATETIME NOT NULL, last_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $q = $pdo->prepare("SELECT phone, err_code, COUNT(*) n, MIN(ts), MAX(ts) FROM wa_messages WHERE id > (SELECT MAX(id) - 600000 FROM wa_messages) AND dir = 'out' AND mtype = 'template' AND status = 'failed' AND err_code = 131026 AND ts >= ? GROUP BY phone, err_code HAVING n >= 2 LIMIT 5000"); $q->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]);
            $ins = $pdo->prepare("INSERT INTO wa_undeliv (phone, code, n, first_at, last_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE n = VALUES(n), last_at = VALUES(last_at)"); $k = 0;
            foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) { $ins->execute([(string)$r[0], (int)$r[1], (int)$r[2], (string)$r[3], (string)$r[4]]); $k++; }
            return $k; } catch (Throwable $e) { return 0; }
    }
    /** ORDER LEDGER (owner 25 Nov 2026: "every order must be attached to a campaign; 100% tracking"): every order of the last 15 days (website orders of shop_orders and the orders typed in HRMS: manual_orders, every panel / channel) gets ONE row in
     *  wa_order_attr: the module and the message (template) of the LAST WhatsApp message we sent to that phone in the 72 hours before the order (Module A, B, C, Cold, Lineup, After-Order), or 'none' (no message of ours in those 72 hours)
     *  or 'no phone'. Nothing is skipped: the ledger holds one row per order. 40 orders per round, newest first (so a new order is attached within minutes, the old ones fill in behind). It records only; it sends and changes nothing. Off: wa_ordattr = 0. */
    function wa_ordattr_tick(PDO $pdo): int
    {
        if ((string)setting_get($pdo, 'wa_ordattr', '1') === '0') return 0; if ((int)setting_get($pdo, 'wa_ordattr_at', '0') > time() - 60) return 0; setting_set($pdo, 'wa_ordattr_at', (string)time());
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_order_attr (okey VARCHAR(48) NOT NULL PRIMARY KEY, src VARCHAR(24) NOT NULL DEFAULT '', phone VARCHAR(20) NOT NULL DEFAULT '', order_at DATETIME NOT NULL, total DECIMAL(12,2) NOT NULL DEFAULT 0, module VARCHAR(10) NOT NULL DEFAULT '', tpl_id INT NOT NULL DEFAULT 0, msg_at DATETIME NULL, gap_min INT NOT NULL DEFAULT 0, noted_at DATETIME NOT NULL, KEY k_at (order_at), KEY k_mod (module)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) { return 0; }
        $from = date('Y-m-d 00:00:00', time() - 15 * 86400); $cand = [];
        try { $q = $pdo->prepare("SELECT CONCAT('w', shopify_id, '_', store_key), 'web', phone, created_at, total FROM shop_orders WHERE created_at >= ? ORDER BY created_at DESC LIMIT 700"); $q->execute([$from]); foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) $cand[(string)$r[0]] = $r; } catch (Throwable $e) { }
        try { $q = $pdo->prepare("SELECT CONCAT('m', manual_order_id), CONCAT('panel:', COALESCE(NULLIF(channel, ''), 'HRMS')), phone, order_date, total FROM manual_orders WHERE order_date >= ? ORDER BY order_date DESC LIMIT 700"); $q->execute([$from]); foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) $cand[(string)$r[0]] = $r; } catch (Throwable $e) { }
        if (!$cand) return 0; $have = []; try { foreach (array_chunk(array_keys($cand), 400) as $ch) { $x = $pdo->prepare("SELECT okey FROM wa_order_attr WHERE okey IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $x->execute($ch); foreach ($x->fetchAll(PDO::FETCH_COLUMN) as $k) $have[(string)$k] = 1; } } catch (Throwable $e) { return 0; }
        $todo = array_values(array_filter($cand, function ($r) use ($have) { return !isset($have[(string)$r[0]]); })); usort($todo, function ($a, $b) { return strcmp((string)$b[3], (string)$a[3]); }); $todo = array_slice($todo, 0, 40); if (!$todo) return 0;
        $LT = ['A' => "SELECT i.sent_at, c.tpl_id FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE i.id > (SELECT MAX(id) - 2000000 FROM wa_camp_items) AND i.phone IN (?, ?) AND i.status IN ('sent','delivered','read') AND i.sent_at BETWEEN ? AND ? ORDER BY i.sent_at DESC LIMIT 1",
            'A-rescue' => "SELECT a.at, a.tpl_id FROM wa_arsc a WHERE a.phone IN (?, ?) AND a.ok = 1 AND a.at BETWEEN ? AND ? ORDER BY a.at DESC LIMIT 1",   /* owner 27 Nov 2026: the Module A rescue utility is a message of ours too (8 of 20 such orders were counted as 'no message'); its orders are kept apart from Module A's own */
            'B' => "SELECT l.at, l.tpl_id FROM wa_ru_log l WHERE l.id > (SELECT MAX(id) - 600000 FROM wa_ru_log) AND l.phone IN (?, ?) AND l.ok = 1 AND l.kind <> 'cnev' AND l.at BETWEEN ? AND ? ORDER BY l.at DESC LIMIT 1",
            'C' => "SELECT l.at, l.tpl_id FROM wa_ru_log l WHERE l.id > (SELECT MAX(id) - 600000 FROM wa_ru_log) AND l.phone IN (?, ?) AND l.ok = 1 AND l.kind = 'cnev' AND l.at BETWEEN ? AND ? ORDER BY l.at DESC LIMIT 1",
            'Cold' => "SELECT l.at, l.tpl_id FROM wa_cold_log l WHERE l.id > (SELECT MAX(id) - 700000 FROM wa_cold_log) AND l.phone IN (?, ?) AND l.ok = 1 AND l.at BETWEEN ? AND ? ORDER BY l.at DESC LIMIT 1",
            'Lineup' => "SELECT l.sent_at, l.tpl_id FROM wa_line_plan l WHERE l.id > (SELECT MAX(id) - 2000000 FROM wa_line_plan) AND l.phone IN (?, ?) AND l.status = 'sent' AND l.sent_at BETWEEN ? AND ? ORDER BY l.sent_at DESC LIMIT 1",
            'After' => "SELECT l.sent_at, l.tpl_id FROM wa_aft_plan l WHERE l.id > (SELECT MAX(id) - 1000000 FROM wa_aft_plan) AND l.phone IN (?, ?) AND l.status = 'sent' AND l.sent_at BETWEEN ? AND ? ORDER BY l.sent_at DESC LIMIT 1"];
        $ps = []; foreach ($LT as $k => $sql) $ps[$k] = $pdo->prepare($sql); $ins = $pdo->prepare("INSERT IGNORE INTO wa_order_attr (okey, src, phone, order_at, total, module, tpl_id, msg_at, gap_min, noted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"); $n = 0; $t0 = microtime(true);
        try { $pdo->exec("SET SESSION MAX_EXECUTION_TIME = 6000"); } catch (Throwable $e) { }
        foreach ($todo as $o) { if (microtime(true) - $t0 > 25) break; $p = substr(preg_replace('/\D+/', '', (string)$o[2]), -10); $to = (string)$o[3];
            if (strlen($p) < 10) { $ins->execute([(string)$o[0], (string)$o[1], mb_substr((string)$o[2], 0, 20), $to, (float)$o[4], 'no phone', 0, null, 0, date('Y-m-d H:i:s')]); $n++; continue; }
            $fr = date('Y-m-d H:i:s', strtotime($to) - 72 * 3600); $best = ''; $bt = ''; $btpl = 0; $fail = false;
            foreach ($ps as $k => $st) { try { $st->execute([$p, '91' . $p, $fr, $to]); $r = $st->fetch(PDO::FETCH_NUM); } catch (Throwable $e) { $fail = true; continue; } if ($r && $r[0] && (string)$r[0] > $bt) { $bt = (string)$r[0]; $best = $k; $btpl = (int)$r[1]; } }
            if ($fail && $best === '') continue;   // a query failed and nothing was found: try again next round (never a wrong 'none')
            $ins->execute([(string)$o[0], (string)$o[1], $p, $to, (float)$o[4], $best !== '' ? $best : 'none', $btpl, $bt !== '' ? $bt : null, $bt !== '' ? (int)round((strtotime($to) - strtotime($bt)) / 60) : 0, date('Y-m-d H:i:s')]); $n++; }
        try { $pdo->exec("SET SESSION MAX_EXECUTION_TIME = 0"); } catch (Throwable $e) { }
        return $n;
    }
    /** COLD PACK 2 (owner 25 Nov 2026: "apply the changes you suggested"): the patterns that brought orders from customers who were not coming anyway, in 12 new MARKETING messages for the Cold funnel: the owner's own PROVEN offers (160 ml at Rs 1,999 in five combos, flat Rs 2,099, 3 x 50 ml Rs 2,140 + FREE 20 ml, 8 x 20 ml Rs 1,899, 5 x 50 ml Rs 2,750), each with a one-tap order button,
     *  and four that START with a question (men / women / unisex, for me / a gift, which size) because the question message of the Cold funnel brought orders. No prepaid 2% (it is gone). Names tmph_cp_NN. */
    function wa_cp_defs(): array
    {
        $o = [
            'tmph_cp_01' => ["Hello {{1}} 🥳\n\n*160 ML @ JUST ₹1,999* 🔥\n\nApni marzi ka combo banao 🤩\n\n8 × 20ml  ·  3 × 50ml + 1 × 10ml  ·  1 × 100ml + 3 × 20ml\n\nSab combo ek hi price ₹1,999 💥\n\nOrder karne ke liye tap kijiye 👇", ['BOOK MY COMBO', 'Show more combos']],
            'tmph_cp_02' => ["Hi {{1}} 🎁\n\nEk combo, flat ₹2,099 💥\n\n4 × 50ml  ya  2 × 100ml  ya  10 × 20ml\n\nApne pasand ke perfumes mix karo ✨\n\nAbhi order karne ke liye tap kijiye 👇", ['Order Now', 'Send Details']],
            'tmph_cp_03' => ["Hello {{1}} 🔥\n\n3 × 50ml sirf ₹2,140 🎁\n\nSaath me 1 × 20ml FREE\n\nSardi ke perfumes ki khaas offer ❄️\n\nOrder Now 👇", ['Order Now', 'Show Offer']],
            'tmph_cp_04' => ["Hi {{1}} ❄️\n\n8 × 20ml sirf ₹1,899 💥\n\nSardi + shaadi season ke liye 8 alag khushbu\n\nTap karke order kijiye 👇", ['Order Now', 'Send Details']],
            'tmph_cp_05' => ["Hello {{1}} 🎉\n\n5 × 50ml sirf ₹2,750 💥\n\nApni pasand ke 5 perfumes chuniye\n\nShaadi aur winter ke liye perfect 💍❄️\n\nOrder Now 👇", ['Order Now', 'Show Combos']],
            'tmph_cp_06' => ["Hi {{1}} 🎁\n\nGift dene ka season aa gaya! 3 × 50ml ₹2,140 me 1 × 20ml FREE 🔥\n\nShaadi, birthday ya New Year — sab ke liye perfect ✨\n\nAbhi order kijiye 👇", ['Order Now', 'Send Details']],
            'tmph_cp_07' => ["Hello {{1}} 🤩\n\n160 ML sirf ₹1,999 me! 5 combo me se koi ek chuniye 💥\n\nAaj hi order karoge to hi ye price 🔥\n\nTap kijiye 👇", ['BOOK MY COMBO', 'Show more combos']],
            'tmph_cp_08' => ["Hi {{1}} 💍\n\nShaadi season special 🎊\n\nFlat ₹2,099 me ek combo: 4 × 50ml ya 2 × 100ml ya 10 × 20ml\n\nAapki pasand, aapka mix ✨ Order Now 👇", ['Order Now', 'Send Details']],
            'tmph_cp_09' => ["Hi {{1}} 👋\n\nEk chhota sa sawal 🤔 Aapko kaunsi khushbu pasand hai?\n\nMen, women ya unisex — batayiye, aapke liye ek khaas combo ready he 🎁💥\n\nNiche tap kijiye 👇", ['Men', 'Women', 'Unisex']],
            'tmph_cp_10' => ["Hello {{1}} ❄️\n\nSardi ka season he! Aap apne liye dhundh rahe hain ya gift ke liye? 🎁\n\nBatayiye, hum 3 best combo dikhayenge — sirf ₹1,999 se 🔥", ['For me', 'As a gift']],
            'tmph_cp_11' => ["Hi {{1}} 🌸\n\nAapko kaunsa size chahiye? 20ml, 50ml ya 100ml?\n\nHar size par ek crazy combo ready he 💥 Tap kijiye 👇", ['20ML', '50ML', '100ML']],
            'tmph_cp_12' => ["Hello {{1}} 🎊\n\nShaadi ka season — kisi ke liye perfume chahiye? 💍\n\nBatayiye kiske liye, hum best combo bata denge 🔥", ['Men', 'Women', 'Gift set']],
            /* owner 27 Nov 2026: "rebuild the message patterns by what brings orders". The messages that sold most to new customers in 15 days open with a PRICE AS A QUESTION ("1×50ML perfume sirf ₹400 ka? Kya? Haan sirji!": 24 orders) and let him choose his own combo ("160ML @ ₹1999": 15 orders). Six more in that shape, with the same proven prices as tmph_cp_01-08 (no new price). */
            'tmph_cp_13' => ["Hello {{1}} 😳\n\n*1 × 50ml perfume sirf ₹550 ka ?*\n\nKya ? 👀\n\nHaan ji ! 5 × 50ml ka combo sirf ₹2,750 🔥\n\nApni pasand ke 5 perfumes chuniye ✨\n\nOrder ke liye tap kijiye 👇", ['Order Now', 'Send Details']],
            'tmph_cp_14' => ["Hi {{1}} 🤔\n\n*20ml perfume ₹250 se bhi kam me ?*\n\nKya sach me ? 👀\n\nHaan ji ! 8 × 20ml (160 ML) sirf ₹1,999 💥\n\n8 alag khushbu, aapki pasand ki 🌸\n\nTap kijiye 👇", ['BOOK MY COMBO', 'Show more combos']],
            'tmph_cp_15' => ["Hello {{1}} 👋\n\nEk sawal 🤔 160 ML perfume ke liye aap kitna dete ho ?\n\nHamare yahan *sirf ₹1,999* 😍\n\nAur combo aapki marzi ka: 8 × 20ml  ·  3 × 50ml + 1 × 10ml  ·  1 × 100ml + 3 × 20ml\n\nKaunsa size chahiye ? 👇", ['20ML', '50ML', '100ML']],
            'tmph_cp_16' => ["Hi {{1}} 🎁\n\n*3 × 50ml liya to 1 × 20ml FREE ?* 😳\n\nHaan ji ! 3 × 50ml sirf ₹2,140 aur saath me 1 × 20ml FREE 🔥\n\nApne liye chahiye ya gift ke liye ? 👇", ['For me', 'As a gift']],
            'tmph_cp_17' => ["Hello {{1}} 💥\n\n*4 × 50ml ya 2 × 100ml — dono ek hi price me ?*\n\nHaan ji, flat ₹2,099 😍\n\n10 × 20ml bhi isi price me ✨\n\nAapko kaunsi khushbu chahiye ? 👇", ['Men', 'Women', 'Unisex']],
            'tmph_cp_18' => ["Hi {{1}} 🙋‍♂️ TM Perfume House se bol rahe hain.\n\nEk chhota sa sawal 🤔 20ml, 50ml ya 100ml — aap kaunsa size use karte ho ?\n\nHar size par ek khaas combo ready he, 160 ML sirf ₹1,999 se 🔥\n\nApna size dabaiye 👇", ['20ML', '50ML', '100ML']],
        ];
        $r = []; foreach ($o as $k => $v) $r[$k] = ['body' => $v[0], 'buttons' => $v[1]]; return $r;
    }
    /** 🖤 BLACK FRIDAY PACK (owner 26 Nov 2026: "Black Friday is on — make proper messages and add them to the module"): MARKETING messages in the owner's style (name, emoji, blank-line spacing, curiosity,
     *  ONE choice by button) that say Black Friday by name. tmph_bf_11-16 carry the owner's own Black Friday offer; tmph_bf_01-10 have NO price, percentage, quantity or deadline (written before the offer was
     *  given): the button tap brings the price, as for the other packs. Made at Meta only inside Black Friday week (wa_bf_on) and sent by the Cold funnel only inside it (wcd_style_ids). Names tmph_bf_NN; the buttons are ones the tap handler already knows. */
    function wa_bf_defs(): array
    {
        $o = [
            /* 🖤 THE OWNER'S BLACK FRIDAY OFFER (26 Nov 2026, his own words): any 3 x 100 ml for Rs 2,999 + 2 x 20 ml FREE, limited time (till 11:59 PM), limited stock, first come first served. These six go to Meta first. */
            'tmph_bf_11' => ["Hello {{1}} 👋\n\n🖤 *BLACK FRIDAY SALE IS LIVE!* 🔥\n\n🛍️ Get ANY 3 × 100ml Perfumes\n💰 Only ₹2,999/-\n\n🎁 PLUS 2 × 20ml Perfumes ABSOLUTELY FREE!\n\n⏳ Limited time — only till 11:59 PM\n📦 Limited stock, first come, first served!", ['Order Now', 'Send Details']],
            'tmph_bf_12' => ["Hello {{1}} 🖤\n\n*BLACK FRIDAY SALE* shuru ho gayi he 🔥\n\n🛍️ Koi bhi 3 × 100ml perfumes sirf ₹2,999 me\n\n🎁 Saath me 2 × 20ml perfumes bilkul FREE!\n\n⏳ Sirf aaj raat 11:59 PM tak\n\nAbhi order karne ke liye tap kijiye 👇", ['Order Now', 'Send Details']],
            'tmph_bf_13' => ["Hi {{1}} 🤩\n\nBlack Friday ka sabse bada dhamaka 🖤💥\n\nApni pasand ke 3 × 100ml perfumes — sirf ₹2,999\n\n🎁 Aur 2 × 20ml FREE, hamari taraf se!\n\n📦 Stock limited he, jo pehle order karega usi ko milega 🏃\n\nTap kijiye 👇", ['BOOK MY COMBO', 'Show more combos']],
            'tmph_bf_14' => ["Hello {{1}} 🎁\n\n300 ML perfume + 40 ML FREE 😍\n\n🖤 Black Friday deal: ANY 3 × 100ml sirf ₹2,999, saath me 2 × 20ml FREE\n\n⏳ Aaj raat 11:59 PM ke baad ye deal nahi milegi\n\nOrder pakka karne ke liye tap kijiye 👇", ['Yes, Order', 'Show Offer']],
            'tmph_bf_15' => ["Hi {{1}} 🖤\n\nEk chhoti si baat batani thi...\n\nBlack Friday par 3 × 100ml perfumes ₹2,999 me mil rahe hain, aur 2 × 20ml FREE 🎁🔥\n\nAapko kaunsi khushbu chahiye ? 👇", ['Men', 'Women', 'Unisex']],
            'tmph_bf_16' => ["Hello {{1}} 💍\n\nShaadi ka season + Black Friday 🖤🎊\n\nApne liye ya gift ke liye: ANY 3 × 100ml sirf ₹2,999 + 2 × 20ml FREE 🎁\n\n⏳ Limited time, limited stock!\n\nAbhi order kijiye 👇", ['For me', 'As a gift']],
            'tmph_bf_01' => ["Hello {{1}} 🖤\n\n*BLACK FRIDAY* shuru ho gaya he TM Perfume House par 🔥\n\nAapke liye ek *special Black Friday deal* unlock hui he 🔓\n\nApna size chuniye aur dekhiye 👇", ['20ML', '50ML', '100ML']],
            'tmph_bf_02' => ["Hi {{1}} 🛍️\n\nBlack Friday week chal raha he... aur hamne perfumes par ek *surprise deal* rakhi he 🎁\n\n*Wo kya he ?* 🤔\n\nJaanne ke liye button dabaiye 👇", ['Yes, Order', 'Show Offer']],
            'tmph_bf_03' => ["Hello {{1}} 🔥\n\n*Black Friday Combo* ready he 🖤\n\nApni pasand ke perfumes mix karke apna combo banaiye ✨\n\nCombo dekhne ke liye tap kijiye 👇", ['BOOK MY COMBO', 'Show more combos']],
            'tmph_bf_04' => ["Hi {{1}} 🎁\n\nBlack Friday par gift lene ka achha mauka he 🖤\n\nShaadi, birthday ya New Year — kiske liye perfume chahiye ?\n\nBatayiye, hum Black Friday combo dikhayenge 👇", ['For me', 'As a gift']],
            'tmph_bf_05' => ["Hello {{1}} ❄️\n\nBlack Friday + sardi ka season 🖤\n\nGehri, lambi chalne wali khushboo — oud, musk, amber 😍\n\nAapko kaunsi pasand he ? Batayiye, Black Friday deal ke saath dikhate hain 👇", ['Men', 'Women', 'Unisex']],
            'tmph_bf_06' => ["Hi {{1}} 👋\n\nEk chhoti si baat batani thi...\n\nBlack Friday ke liye aapka *special price* ready he 🖤🔥\n\nKaunsa size chahiye ? 👇", ['20ML', '50ML', '100ML']],
            'tmph_bf_07' => ["Hello {{1}} 💍\n\nShaadi ka season aur Black Friday ek saath 🖤🎊\n\nApne liye ya gift ke liye — Black Friday combo taiyaar he ✨\n\nOrder karne ke liye tap kijiye 👇", ['Order Now', 'Send Details']],
            'tmph_bf_08' => ["Hi {{1}} 🤩\n\n*BLACK FRIDAY DEAL* aapke naam unlock ho gayi he 🔓🖤\n\nPerfumes aur combos — apni pasand se chuniye 🎁\n\nDekhna chahenge ? 👇", ['Yes, Order', 'Show Offer']],
            'tmph_bf_09' => ["Hello {{1}} 🖤\n\nBlack Friday week me TM Perfume House ke best-seller perfumes par khaas deal he 🔥\n\nAap apne liye dekhna chahenge ya kisi ko gift dene ke liye ? 👇", ['For me', 'As a gift']],
            'tmph_bf_10' => ["Hi {{1}} ⚡\n\nBlack Friday chal raha he 🖤\n\nAapka pasandida perfume abhi Black Friday deal me mil sakta he 😍\n\nApna size chuniye aur dekhiye 👇", ['20ML', '50ML', '100ML']],
        ];
        $r = []; foreach ($o as $k => $v) $r[$k] = ['body' => $v[0], 'buttons' => $v[1]]; return $r;
    }
    function wa_ordpack_tick(PDO $pdo): array
    {
        $res = ['made' => 0, 'failed' => []];
        if ((string)setting_get($pdo, 'wa_ordpack', '1') === '0') return $res;
        if ((int)setting_get($pdo, 'wa_ordpack_at', '0') > time() - 120) return $res; setting_set($pdo, 'wa_ordpack_at', (string)time());
        foreach (['wa_tpl_clean', 'wa_tpl_insert', 'wa_tpl_row'] as $f) if (!function_exists($f)) return $res;
        $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 12, 'made' => 0];
        try { if ((string)setting_get($pdo, 'wa_arsc_l25_2511', '') !== '1') { setting_set($pdo, 'wa_arsc_l25_2511', '1'); setting_set($pdo, 'wa_arsc_level', '2500'); if (function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Module A rescue: starts at 2,500 an hour (was 1,000; the Lineup rescue runs ~5,000 with 3 failures in 5,000); the hourly step keeps checking the failures (owner 25 Nov)'); } } catch (Throwable $e) { }
        try { if ((string)setting_get($pdo, 'wa_ru_b2500_2511', '') !== '1') { setting_set($pdo, 'wa_ru_b2500_2511', '1'); setting_set($pdo, 'wa_ru_ramp_base', '1200'); setting_set($pdo, 'wa_ru_ramp_step', '200'); setting_set($pdo, 'wa_ru_ramp_max', '2500'); if ((int)setting_get($pdo, 'wa_ru_hourly', '0') < 1200) setting_set($pdo, 'wa_ru_hourly', '1200'); if (function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Utility follow-up (Module B): restart level 1,200 an hour (was 500), steps of 200 up to 2,500 (owner 25 Nov)'); } } catch (Throwable $e) { }
        try { if ((string)setting_get($pdo, 'wa_ru_unbrake_2611b', '') !== '1') { setting_set($pdo, 'wa_ru_unbrake_2611b', '1'); setting_set($pdo, 'wa_ru_pause_until', '0'); setting_set($pdo, 'wa_ru_ramp_hold', '0'); setting_set($pdo, 'wa_ru_freeze_n', '0'); setting_set($pdo, 'wa_ru_brake_since', (string)time()); if (function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Utility follow-up (Module B): the brake is released once (owner 26 Nov): it was caused by marketing-category pool messages, which Module B no longer sends; the failure brake counts only from now on'); } } catch (Throwable $e) { }
        try { if ((string)setting_get($pdo, 'wa_ru_2000_2611', '') !== '1') { setting_set($pdo, 'wa_ru_2000_2611', '1'); setting_set($pdo, 'wa_ru_ramp_base', '2000'); setting_set($pdo, 'wa_ru_ramp_max', '2000'); setting_set($pdo, 'wa_ru_hourly', '2000'); setting_set($pdo, 'wa_ru_freeze_n', '0'); if (function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Utility follow-up (Module B): 2,000 an hour (owner 26 Nov 2026)'); } } catch (Throwable $e) { }
        try { if ((string)setting_get($pdo, 'wa_cap20b_2511', '') !== '1') { setting_set($pdo, 'wa_cap20b_2511', '1'); if ((int)setting_get($pdo, 'wa_rc_level', '0') >= 1000) setting_set($pdo, 'wa_rc_level', '1200'); } } catch (Throwable $e) { }   // Module C now runs at 1,200 an hour (owner 25 Nov)
        try { if ((string)setting_get($pdo, 'wa_cap20_2511', '') !== '1') { setting_set($pdo, 'wa_cap20_2511', '1'); setting_set($pdo, 'wcd_cap', '48000'); setting_set($pdo, 'wcd_mkt_cap_day', '24000'); setting_set($pdo, 'wa_aft_cap', '12000'); if (function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Caps +20% (owner 25 Nov 2026): Cold 48,000 a day (marketing 24,000), After-Order 12,000 a day, Module C top step 1,200 an hour'); } } catch (Throwable $e) { }
        try { if ((string)setting_get($pdo, 'wa_ordl_all_2711', '') !== '1') { setting_set($pdo, 'wa_ordl_all_2711', '1'); setting_set($pdo, 'wa_ordl_day', ''); } } catch (Throwable $e) { }   // the wider check runs once today (owner 27 Nov)
        try { if ((string)setting_get($pdo, 'wa_unpause_fb_2711', '') !== '1') { setting_set($pdo, 'wa_unpause_fb_2711', '1'); $pp = json_decode((string)setting_get($pdo, 'wa_ru_tpl_pause', ''), true); if (is_array($pp)) { $ids = $pdo->query("SELECT id FROM wa_templates WHERE name LIKE 'tmph\\_fu\\_open\\_fb\\_%' OR name LIKE 'tmph\\_fu\\_order\\_fb\\_%'")->fetchAll(PDO::FETCH_COLUMN); $n = 0; foreach ($ids as $i) if (isset($pp[(int)$i])) { unset($pp[(int)$i]); $n++; } if ($n) { setting_set($pdo, 'wa_ru_tpl_pause', json_encode($pp)); if (function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Rescue: ' . $n . ' new utility messages were paused by mistake (a customer-level "bot paused" answer was read as Meta pausing the message); they are in use again'); } } } } catch (Throwable $e) { }
        try { if ((string)setting_get($pdo, 'wa_ru_unrest_2711', '') !== '1') { setting_set($pdo, 'wa_ru_unrest_2711', '1'); setting_set($pdo, 'wa_ru_rest', '{}'); if (function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Utility follow-up (Module B): the numbers\' 2-hour rest is lifted once (owner 27 Nov): it was caused by refusals at sending and by marketing last-resort messages, which no longer count against a number'); } } catch (Throwable $e) { }
        /* owner 27 Nov 2026 ("the main account is at Meta's limit of messages: remove the old unnecessary ones"): ONCE, on the main account only, the engine-made follow-up messages (names tmph_fu_, util_perfume_, tmph_new_, tmph_ord_, tmph_style_, tmph_util_) older than 3 days that are in no setting / map, were sent by no module in 30 days and are in no campaign of 30 days are deleted at Meta (the dry run of 17:25 counted 30). At most 40; the message stays in the library and on the other accounts; every name is logged. */
        try { if ((string)setting_get($pdo, 'wa_tpl_free_2711b', '') !== '1' && function_exists('wa_api') && function_exists('wa_cfg_val') && (string)wa_cfg_val('WA_WABA_ID') === '1100782895886296') {   /* only the account that answered with the limit */ setting_set($pdo, 'wa_tpl_free_2711b', '1'); $full = (string)wa_cfg_val('WA_WABA_ID'); $maps = ''; foreach (['wl_map', 'wl_tpls', 'wcd_tpls', 'wcd_tpls_m', 'wa_aft_photo_tpls', 'wa_aft_tpls', 'wa_rs_state', 'wa_needphoto', 'wl_extra'] as $__k) $maps .= ':' . (string)setting_get($pdo, $__k, '');
                $d30 = date('Y-m-d H:i:s', time() - 30 * 86400); $usedIds = []; $okQ = true;
                foreach (["SELECT DISTINCT tpl_id FROM wa_cold_log WHERE at >= '$d30'", "SELECT DISTINCT tpl_id FROM wa_ru_log WHERE at >= '$d30'", "SELECT DISTINCT tpl_id FROM wa_line_plan WHERE sent_at >= '$d30'", "SELECT DISTINCT tpl_id FROM wa_aft_plan WHERE sent_at >= '$d30'", "SELECT DISTINCT tpl_id FROM wa_arsc WHERE at >= '$d30'", "SELECT DISTINCT tpl_id FROM wa_campaigns WHERE created_at >= '$d30' OR status IN ('running','draft','scheduled','paused')", "SELECT DISTINCT tpl_used FROM wa_camp_items WHERE id > (SELECT MAX(id) - 3000000 FROM wa_camp_items) AND tpl_used > 0"] as $__q) { try { foreach ($pdo->query($__q)->fetchAll(PDO::FETCH_COLUMN) as $__t) $usedIds[(int)$__t] = 1; } catch (Throwable $e) { $okQ = false; } }
                if ($okQ) { $q = $pdo->prepare("SELECT t.id, t.name FROM wa_templates t JOIN wa_tpl_waba w ON w.tpl_id = t.id AND w.waba_id = ? AND UPPER(w.status) IN ('APPROVED','PAUSED','DISABLED') WHERE t.created_at < ? AND t.name REGEXP '^(tmph_fu_|tmph_new_|tmph_ord_|tmph_style_|util_perfume_|tmph_util_)'"); $q->execute([$full, date('Y-m-d H:i:s', time() - 3 * 86400)]); $gone = []; $ghost = []; $why1 = ''; $bad = 0;
                    foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) { if (count($gone) >= 40) break; $id = (int)$r[0]; if (preg_match('/[^0-9]' . $id . '[^0-9]/', $maps . ':') || isset($usedIds[$id])) continue;
                        try { $x = wa_api('DELETE', '/' . $full . '/message_templates?name=' . rawurlencode((string)$r[1])); $hc = (int)($x[0] ?? 0); $em = is_array($x[1] ?? null) ? (string)(($x[1]['error']['message'] ?? '') . ' ' . ($x[1]['error']['error_user_msg'] ?? '') . ' #' . ($x[1]['error']['code'] ?? '') . '/' . ($x[1]['error']['error_subcode'] ?? '')) : '';
                            if ($hc >= 200 && $hc < 300) { $pdo->prepare("DELETE FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?")->execute([$id, $full]); $gone[] = (string)$r[1]; }
                            elseif (preg_match('/does not exist|not found|doesn.t exist|#100\/2593002|\b2593002\b/i', $em)) { $pdo->prepare("UPDATE wa_tpl_waba SET status = 'GONE', reason = 'not on this account at Meta any more (27 Nov check)' WHERE tpl_id = ? AND waba_id = ?")->execute([$id, $full]); $ghost[] = (string)$r[1]; }   /* our list said "approved" but Meta does not have it: the row is corrected, nothing to delete */
                            else { $bad++; if ($why1 === '') $why1 = 'HTTP ' . $hc . ' ' . mb_substr($em, 0, 160); if ($bad >= 3 && !$gone && !$ghost) break; } } catch (Throwable $e) { $bad++; if ($why1 === '') $why1 = mb_substr($e->getMessage(), 0, 120); } }
                    setting_set($pdo, 'wa_tpl_free_2711_res', json_encode(['at' => date('Y-m-d H:i:s'), 'deleted' => $gone, 'were not at Meta any more' => $ghost, 'not deleted' => $bad, 'first answer of Meta' => $why1], JSON_UNESCAPED_UNICODE)); if (function_exists('wa_log')) wa_log($pdo, 'template', true, 'Main account: ' . count($gone) . ' old unused follow-up messages deleted at Meta to make room (' . implode(', ', array_slice($gone, 0, 40)) . ')' . ($bad ? ' — ' . $bad . ' could not be deleted' : '')); }
                else setting_set($pdo, 'wa_tpl_free_2711_res', json_encode(['at' => date('Y-m-d H:i:s'), 'note' => 'a usage query failed: nothing was deleted'])); } } catch (Throwable $e) { }
        /* owner 27 Nov 2026: WhatsApp Manager shows "6000 out of 6000" templates on the main account while our library knows about 2,300 of them. READ-ONLY scan (nothing is deleted): the account's templates are read from Meta page by page (4 pages a round) and counted: known to our library or not, by status, by category and by the start of the name, with a few example names. The result is kept in wa_meta_scan for the checkup. */
        try { $sc = json_decode((string)setting_get($pdo, 'wa_meta_scan', ''), true); if (!is_array($sc)) $sc = ['after' => '', 'pages' => 0, 'done' => 0, 'total' => 0, 'known' => 0, 'unknown' => 0, 'unk_status' => [], 'unk_cat' => [], 'unk_prefix' => [], 'known_status' => [], 'samples' => [], 'err' => ''];
            if (empty($sc['done']) && (int)$sc['pages'] < 40 && function_exists('wa_api') && function_exists('wa_cfg_val') && (string)wa_cfg_val('WA_WABA_ID') === '1100782895886296' && (int)setting_get($pdo, 'wa_meta_scan_at', '0') < time() - 100) { setting_set($pdo, 'wa_meta_scan_at', (string)time()); $full = '1100782895886296'; $t9 = microtime(true);
                for ($pg = 0; $pg < 4 && microtime(true) - $t9 < 14; $pg++) { $x = wa_api('GET', '/' . $full . '/message_templates?fields=name,status,category&limit=250' . ((string)$sc['after'] !== '' ? '&after=' . rawurlencode((string)$sc['after']) : '')); $hc = (int)($x[0] ?? 0); $j = is_array($x[1] ?? null) ? $x[1] : [];
                    if ($hc < 200 || $hc >= 300 || !isset($j['data'])) { $sc['err'] = 'HTTP ' . $hc . ' ' . mb_substr((string)($j['error']['message'] ?? ''), 0, 140); break; }
                    $names = []; foreach ((array)$j['data'] as $t) $names[(string)($t['name'] ?? '')] = [(string)($t['status'] ?? ''), (string)($t['category'] ?? '')]; $kn = [];
                    if ($names) { $q = $pdo->prepare("SELECT DISTINCT name FROM wa_templates WHERE name IN (" . implode(',', array_fill(0, count($names), '?')) . ")"); $q->execute(array_keys($names)); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $n1) $kn[(string)$n1] = 1; }
                    foreach ($names as $n1 => $v) { $sc['total']++; if (isset($kn[$n1])) { $sc['known']++; $sc['known_status'][$v[0]] = (int)($sc['known_status'][$v[0]] ?? 0) + 1; continue; } $sc['unknown']++; $sc['unk_status'][$v[0]] = (int)($sc['unk_status'][$v[0]] ?? 0) + 1; $sc['unk_cat'][$v[1]] = (int)($sc['unk_cat'][$v[1]] ?? 0) + 1; $pf = preg_match('/^([a-z]+(?:_[a-z]+)?)/i', $n1, $mm) ? strtolower($mm[1]) : '?'; $sc['unk_prefix'][$pf] = (int)($sc['unk_prefix'][$pf] ?? 0) + 1; if (count($sc['samples']) < 30 && mt_rand(1, 40) === 1) $sc['samples'][] = $n1; }
                    $sc['pages']++; $sc['after'] = (string)($j['paging']['cursors']['after'] ?? ''); if (empty($j['paging']['next']) || $sc['after'] === '') { $sc['done'] = 1; break; } }
                arsort($sc['unk_prefix']); $sc['unk_prefix'] = array_slice($sc['unk_prefix'], 0, 60, true); $sc['at'] = date('Y-m-d H:i:s'); setting_set($pdo, 'wa_meta_scan', json_encode($sc, JSON_UNESCAPED_UNICODE)); } } catch (Throwable $e) { try { setting_set($pdo, 'wa_meta_scan_err', mb_substr($e->getMessage(), 0, 160)); } catch (Throwable $e9) { } }
        try { wa_ordpack_learn($pdo); } catch (Throwable $e) { }
        try { wa_hand_tick($pdo); } catch (Throwable $e) { }
        try { wa_undeliv_tick($pdo); } catch (Throwable $e) { }
        /* once: the orders since 25 Nov whose LAST message of ours was the Module A rescue utility are moved to 'A-rescue' (they sit under 'none' or 'A' now) */
        try { if ((string)setting_get($pdo, 'wa_ordattr_arsc_2711', '') !== '1') { setting_set($pdo, 'wa_ordattr_arsc_2711', '1'); $rows = $pdo->query("SELECT okey, phone, order_at, msg_at FROM wa_order_attr WHERE order_at >= '2026-11-25 00:00:00' AND module IN ('none', 'A') LIMIT 3000")->fetchAll(PDO::FETCH_NUM); $fq = $pdo->prepare("SELECT a.at, a.tpl_id FROM wa_arsc a WHERE a.phone IN (?, ?) AND a.ok = 1 AND a.at BETWEEN ? AND ? ORDER BY a.at DESC LIMIT 1"); $up = $pdo->prepare("UPDATE wa_order_attr SET module = 'A-rescue', tpl_id = ?, msg_at = ?, gap_min = ? WHERE okey = ?"); $mv = 0;
                foreach ($rows as $r) { $p = substr(preg_replace('/\D+/', '', (string)$r[1]), -10); if (strlen($p) < 10) continue; $to = (string)$r[2]; $fq->execute([$p, '91' . $p, date('Y-m-d H:i:s', strtotime($to) - 72 * 3600), $to]); $x = $fq->fetch(PDO::FETCH_NUM); if (!$x || (string)$x[0] === '' || ((string)$r[3] !== '' && (string)$x[0] <= (string)$r[3])) continue; $up->execute([(int)$x[1], (string)$x[0], (int)round((strtotime($to) - strtotime((string)$x[0])) / 60), (string)$r[0]]); $mv++; }
                if (function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Order ledger: ' . $mv . ' orders since 25 Nov came after the Module A rescue utility; they are counted apart now (A-rescue)'); } } catch (Throwable $e) { }
        try { wa_ordattr_tick($pdo); } catch (Throwable $e) { }
        $ex = json_decode((string)setting_get($pdo, 'wa_ordl_new', '[]'), true); if (!function_exists('wa_bf_on') && is_file(__DIR__ . '/wa_style.php')) { try { require_once __DIR__ . '/wa_style.php'; } catch (Throwable $e) { } }
        $all = (function_exists('wa_bf_on') && wa_bf_on() ? wa_bf_defs() : []) + wa_ordpack_defs() + wa_cp_defs();   /* 🖤 in Black Friday week its pack is made first (5 messages a round) */
        foreach ((array)$ex as $x) if (!empty($x['name']) && !empty($x['body'])) $all[(string)$x['name']] = ['body' => (string)$x['body'], 'buttons' => (array)($x['buttons'] ?? ['Order Now', 'Send Details', 'Not Now'])];
        foreach ($all as $name => $m) {
            if ($res['made'] >= 5) break;
            try { $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? OR body = ? LIMIT 1"); $q->execute([$name, $m['body']]); if ($q->fetchColumn()) continue; } catch (Throwable $e) { continue; }
            $qr = array_map(function ($t) { return ['type' => 'QUICK_REPLY', 'text' => $t]; }, $m['buttons']);
            [$t, $err] = wa_tpl_clean(['name' => $name, 'language' => 'en', 'category' => 'MARKETING', 'body' => $m['body'], 'footer' => 'TM Perfume House', 'buttons' => $qr, 'examples' => ['body' => ['Rahul'], 'labels' => ['Name']], 'folder' => 'Offers']);
            if ($err !== '') { $res['failed'][] = $name . ': ' . $err; continue; }
            $id = wa_tpl_insert($pdo, $t, 0, 'Order pack (owner, 25 Nov 2026): season + price + offer + deadline in one message.', 'Order pack'); $r = wa_tpl_row($pdo, $id); if (!$r) { $res['failed'][] = $name . ': could not save'; continue; }
            $res['made']++;
            foreach ($wabas as $w) { if (function_exists('wa_tpl_try_create')) { [$ok, $why] = wa_tpl_try_create($pdo, $r, $w, $budget); if (!$ok) $res['failed'][] = $name . ' → ' . $why; } }
        }
        if ($res['made'] && function_exists('wa_log')) wa_log($pdo, 'template', !$res['failed'], 'Order pack: ' . $res['made'] . ' made' . ($res['failed'] ? ' — ' . $res['failed'][0] : ''));
        return $res;
    }
}
