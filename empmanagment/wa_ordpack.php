<?php
/* Order pack (owner 25 Nov 2026): many MARKETING messages, each ONE message with 2-3 points together
 * (season / secret / price / offer / gift / extra ML / crazy discount / deadline), blank-line spacing, emojis, quick-reply buttons.
 * Names tmph_ord_NN. Made once (by name), sent to Meta through the normal template path. Switch off: wa_ordpack = 0. */
if (!function_exists('wa_ordpack_defs')) {
    function wa_ordpack_defs(): array
    {
        $open = [
            "Hello {{1}} ❄️\n\nSardi + shaadi ka season shuru — perfume ki sabse zyada demand abhi hai 🔥",
            "Hi {{1}} 🎉\n\nShaadi ka mausam aa gaya! Ek khaas *secret deal* aapke liye taiyar he 👀",
            "Hello {{1}} 🔓\n\nAapke liye kuch *unlock* hua he... andar kya he, dekh ke maza aa jayega 😍",
            "Hi {{1}} 🎁\n\nNew Year + wedding season — gift dene ka sabse achha time, aur gift ke saath surprise bhi 🤫",
            "Hello {{1}} 🌸\n\nWinter ki khushboo aa gayi: oud, musk, amber 🔥 Aur uske saath ek crazy offer bhi!",
            "Hi {{1}} 👋\n\nEk chhoti si baat... aaj hamne *dhamakedaar* kuch rakha he, sirf aapke liye ✨",
            "Hello {{1}} 🧥\n\nThand badh rahi he aur khushboo ka maza bhi! Aaj ek *reward* aapke naam unlock hua he 🏆",
            "Hi {{1}} ❄️\n\nSardi ki raat, shaadi ki baraat — perfume ke bina adhoora! Aaj ka *crazy price dhamaka* dekhiye 💥",
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
            "🎁 5 × 20ml sirf ₹1,700\n🔥 Flat 5% OFF + prepaid par extra 2%",
            "💥 Flat ₹2,250 me 4 × 50ml + 2 × 100ml\n🎁 Extra ML bhi milega",
            "🏆 Reward: 3 × 50ml ₹2,140 par 1 × 20ml FREE\n💥 Extra ML ka seedha fayda",
            "💥 CRAZY PRICE: 8 × 20ml sirf ₹1,899\n🔥 Prepaid par extra 2% OFF alag se",
            "🎁 Flat 5% OFF + prepaid par extra 2%\n✨ Saath me gift packing, dhamaka deal",
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
        $o = []; $seen = []; $O = count($open); $M = count($mid); $C = count($close);
        for ($n = 1; $n <= 90 && count($o) < 40; $n++) {
            $body = $open[($n * 3) % $O] . "\n\n" . $mid[($n * 5) % $M] . "\n\n" . $close[($n * 7) % $C]; if (isset($seen[$body])) continue; $seen[$body] = 1;
            $o[sprintf('tmph_ord_%02d', count($o) + 1)] = ['body' => $body, 'buttons' => $btn[$n % 3]];
        }
        return $o;
    }
    function wa_ordpack_tick(PDO $pdo): array
    {
        $res = ['made' => 0, 'failed' => []];
        if ((string)setting_get($pdo, 'wa_ordpack', '1') === '0') return $res;
        if ((int)setting_get($pdo, 'wa_ordpack_at', '0') > time() - 120) return $res; setting_set($pdo, 'wa_ordpack_at', (string)time());
        foreach (['wa_tpl_clean', 'wa_tpl_insert', 'wa_tpl_row'] as $f) if (!function_exists($f)) return $res;
        $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 12, 'made' => 0];
        foreach (wa_ordpack_defs() as $name => $m) {
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
