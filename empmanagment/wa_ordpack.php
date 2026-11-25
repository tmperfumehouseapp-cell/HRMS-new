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
        if (count($rates) >= 3) { sort($rates); $med = $rates[intdiv(count($rates), 2)]; $off = []; foreach ($per as $id => $p) if ($p[1] >= 600 && $p[0] < $med * 0.5) $off[] = $id; setting_set($pdo, 'wa_ordl_off', json_encode($off)); } else { $off = []; $med = 0; }
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
    function wa_ordpack_tick(PDO $pdo): array
    {
        $res = ['made' => 0, 'failed' => []];
        if ((string)setting_get($pdo, 'wa_ordpack', '1') === '0') return $res;
        if ((int)setting_get($pdo, 'wa_ordpack_at', '0') > time() - 120) return $res; setting_set($pdo, 'wa_ordpack_at', (string)time());
        foreach (['wa_tpl_clean', 'wa_tpl_insert', 'wa_tpl_row'] as $f) if (!function_exists($f)) return $res;
        $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 12, 'made' => 0];
        try { if ((string)setting_get($pdo, 'wa_arsc_l25_2511', '') !== '1') { setting_set($pdo, 'wa_arsc_l25_2511', '1'); setting_set($pdo, 'wa_arsc_level', '2500'); if (function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Module A rescue: starts at 2,500 an hour (was 1,000; the Lineup rescue runs ~5,000 with 3 failures in 5,000); the hourly step keeps checking the failures (owner 25 Nov)'); } } catch (Throwable $e) { }
        try { if ((string)setting_get($pdo, 'wa_ru_b2500_2511', '') !== '1') { setting_set($pdo, 'wa_ru_b2500_2511', '1'); setting_set($pdo, 'wa_ru_ramp_base', '1200'); setting_set($pdo, 'wa_ru_ramp_step', '200'); setting_set($pdo, 'wa_ru_ramp_max', '2500'); if ((int)setting_get($pdo, 'wa_ru_hourly', '0') < 1200) setting_set($pdo, 'wa_ru_hourly', '1200'); if (function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Utility follow-up (Module B): restart level 1,200 an hour (was 500), steps of 200 up to 2,500 (owner 25 Nov)'); } } catch (Throwable $e) { }
        try { if ((string)setting_get($pdo, 'wa_cap20b_2511', '') !== '1') { setting_set($pdo, 'wa_cap20b_2511', '1'); if ((int)setting_get($pdo, 'wa_rc_level', '0') >= 1000) setting_set($pdo, 'wa_rc_level', '1200'); } } catch (Throwable $e) { }   // Module C now runs at 1,200 an hour (owner 25 Nov)
        try { if ((string)setting_get($pdo, 'wa_cap20_2511', '') !== '1') { setting_set($pdo, 'wa_cap20_2511', '1'); setting_set($pdo, 'wcd_cap', '48000'); setting_set($pdo, 'wcd_mkt_cap_day', '24000'); setting_set($pdo, 'wa_aft_cap', '12000'); if (function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Caps +20% (owner 25 Nov 2026): Cold 48,000 a day (marketing 24,000), After-Order 12,000 a day, Module C top step 1,200 an hour'); } } catch (Throwable $e) { }
        try { wa_ordpack_learn($pdo); } catch (Throwable $e) { }
        try { wa_hand_tick($pdo); } catch (Throwable $e) { }
        try { wa_undeliv_tick($pdo); } catch (Throwable $e) { }
        try { wa_ordattr_tick($pdo); } catch (Throwable $e) { }
        $ex = json_decode((string)setting_get($pdo, 'wa_ordl_new', '[]'), true); $all = wa_ordpack_defs(); foreach ((array)$ex as $x) if (!empty($x['name']) && !empty($x['body'])) $all[(string)$x['name']] = ['body' => (string)$x['body'], 'buttons' => (array)($x['buttons'] ?? ['Order Now', 'Send Details', 'Not Now'])];
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
