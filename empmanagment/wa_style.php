<?php
/**
 * wa_style.php — the message STYLE the owner's customers like (owner 24 Nov 2026, from the "surprise deal" and "ml wise" messages that bring replies):
 * a warm, personal Hinglish line with emoji, a curiosity hook ("Wo kya he? ... button dabaiye"), one clear choice by button (20ML / 50ML / 100ML), short.
 * This file makes 5 MARKETING messages in that style once (wa_style_v1) and sends them to Meta; the owner picks them for a campaign (Module A). The utility modules (Module B / C, rescue, Cold, After-Order care)
 * get the same warm Hinglish + buttons through the utility pool (wa_util_pool_defs 'open_hi_*'), but NEVER the deal words ("surprise deal", "unlock", "discount", "free gift"): Meta would move a utility message with those words to marketing.
 * Called once from the Module C round (wa_modc.php). PHP 7.4 safe.
 */
if (!function_exists('wa_style_dull')) {
    /** Basic customer-care wording the owner does not want in ANY module (25 Nov 2026: "thank you for being a customer / customer care team / registered customer / here to help" gets replies but no orders). Returns the reason or ''. */
    function wa_style_dull(string $text): string
    {
        $t = mb_strtolower($text);
        foreach (['customer care', 'thank you for being', 'thanks for being', 'registered customer', 'here to help', 'happy to help', 'we are here to', 'help with your order', 'help with an order', 'how can we help', 'how are you enjoying', 'just checking in', 'checking in', 'check-in', 'any doubt', 'koi sawal', 'koi doubt', 'help karne', 'hum help', 'madad karne', 'yahin available', 'yahin hain', 'requirement ke liye', 'pichhle message', 'chhota sa message', 'we are available', 'always available', 'feel free', 'let us know', 'reach out', 'contact us'] as $w) if (mb_strpos($t, $w) !== false) return 'too basic: ' . $w;
        return '';
    }
}
if (!function_exists('wa_style_pack')) {
    /** The season the messages should fit (owner 24 Nov 2026: "consider the new season: gift orders, season add-ons"). Used in the text Claude gets; it never creates a price, a date or a promise. */
    /** 🖤 BLACK FRIDAY week (owner 26 Nov 2026: "Black Friday is on and the season messages only say winter / wedding — how was it missed?"). It was missed because the season was written by the MONTH only
     *  (November = winter + wedding) and the festival calendar had no Black Friday. The week: the Monday before Black Friday (the day after the 4th Thursday of November) to Cyber Monday. [from, to, the Friday]. */
    function wa_bf_window(?int $ts = null): array
    {
        $y = (int)date('Y', $ts ?? time()); $bf = (int)strtotime('fourth thursday of november ' . $y) + 86400;
        return [date('Y-m-d', $bf - 4 * 86400), date('Y-m-d', $bf + 3 * 86400), date('Y-m-d', $bf)];
    }
    function wa_bf_on(?int $ts = null): bool { [$a, $b] = wa_bf_window($ts); $d = date('Y-m-d', $ts ?? time()); return $d >= $a && $d <= $b; }
    function wa_style_season(?int $ts = null): string
    {
        $m = (int)date('n', $ts ?? time()); $d = (int)date('j', $ts ?? time());
        if (wa_bf_on($ts)) return 'BLACK FRIDAY week is on NOW (until Cyber Monday, ' . date('j M', strtotime(wa_bf_window($ts)[1])) . '): it is the first hook of every MARKETING message this week - say "Black Friday" by name (Black Friday deal / Black Friday combo / Black Friday special); never invent a price, a percentage, a quantity or a deadline for it. It is also winter and the wedding (shaadi) season in India, with Christmas / New Year gifting coming: warm, deep, long-lasting fragrances (oud, musk, amber, woody) and gift orders fit now. In a UTILITY message do NOT write "Black Friday" or any sale word (Meta turns it into marketing): use winter / wedding / gift there.';
        if ($m === 11 || ($m === 12 && $d <= 20)) return 'Winter is starting and it is the wedding (shaadi) season in India; Christmas / New Year gifting is coming: warm, deep, long-lasting fragrances (oud, musk, amber, woody) suit the cold; perfumes make a good gift for weddings, family and friends; a gift order or a gift add-on fits now.';
        if ($m === 12 || $m === 1) return 'It is winter, the Christmas / New Year / wedding-season gifting time; warm, deep fragrances suit the cold; gift orders and a gift add-on fit now.';
        if ($m === 2) return 'Winter is ending; Valentine\'s Day gifting; fresh-floral and sweet fragrances for gifts for a partner.';
        if ($m >= 3 && $m <= 6) return 'Summer: light, fresh, citrus, aqua and floral fragrances that last in the heat; sprays and small sizes for daily wear; gifts for the wedding season.';
        if ($m >= 7 && $m <= 9) return 'Monsoon: fresh, earthy and light fragrances; festival gifting (Raksha Bandhan, Ganesh Chaturthi) is near.';
        return 'Festival season (Navratri, Diwali): gift orders and gift add-ons fit now; rich, festive fragrances.';
    }
    function wa_style_pack(): array
    {
        $qr = function (array $l) { return array_map(function ($t) { return ['type' => 'QUICK_REPLY', 'text' => $t]; }, $l); };
        return [
            'tmph_style_surprise' => ['body' => "Hello {{1}} 🙋‍♂️ TM Perfume House se bol rahe hain.\n\nAaj perfumes par hamne ek bahot achhi si *surprise deal* rakhi he... 🎁\n\n*Wo kya he ?* 🤔\nJan ne ke liye abhi button dabaiye !!", 'buttons' => $qr(['Show my deal', 'Not now'])],
            'tmph_style_mlwise' => ['body' => "Hello {{1}}! 👋\n\nAapke liye kuch special unlock hua hai 👀🔥\n\n🎁 EXCLUSIVE ML-WISE DEAL\n✨ Special Discount\n🎁 FREE Gift\n💥 Surprise Combo\n\n👇 Apni ML size par click kijiye aur dekhiye aapke liye kya unlock hua hai! 🔓😍", 'buttons' => $qr(['20ML Combo', '50ML Combo', '100ML Combo'])],
            'tmph_style_choose' => ['body' => "Hi {{1}} 🌸 ek chhoti si baat batani thi...\n\nAapke liye aaj ek *surprise combo* ready he 🎁💥\n\nKaunsa size chahiye ? 👇", 'buttons' => $qr(['20ML', '50ML', '100ML'])],
            'tmph_style_reorder' => ['body' => "Hi {{1}} 😊 aapka perfume kaisa laga ?\n\nAgar pasand aaya ho to aapke liye *special reorder deal* ready he 🔓🎁\n\nDekhna chahenge ?", 'buttons' => $qr(['Haan, dikhao', 'Abhi nahi'])],
            'tmph_style_launch' => ['body' => "Hello {{1}} 🎉\n\nNaye perfumes launch hue hain aur ek *dhamakedaar offer* bhi aaya hai! 🔥\n\nOrder par aapko milega *add-on* aur extra discount bhi 🎁\n\nDekhne ke liye button dabaiye 👇", 'buttons' => $qr(['Naye Perfumes', 'Offer Dikhao'])],
            'tmph_style_fun' => ['body' => "Hi {{1}}! 👋 Hamne naye perfumes launch kiye hain 🌸\n\nEk dhamakedaar cheez aayi hai, aapko bahut maza aane wala hai 😍\n\nOrder par add-on ke saath extra discount bhi karwa denge 🎁\n\nNiche tap kijiye 👇", 'buttons' => $qr(['Dekhein', 'Abhi nahi'])],
            'tmph_style_addon' => ['body' => "Hello {{1}} ✨\n\nAaj order par kuch na kuch *add-on* zaroor milega 🎁 Aur extra discount bhi karwa denge!\n\nNaya offer aur naye perfumes dekhne ke liye button dabaiye 👇", 'buttons' => $qr(['Naya Offer Dekhein', 'Abhi nahi'])],
            'tmph_style_winter' => ['body' => "Hello {{1}} ❄️\n\nSardi shuru ho gayi he aur naye *winter perfumes* aa gaye hain 🔥\n\nGehri, lambi chalne wali khushboo — oud, musk, amber 😍\n\nOrder par kuch na kuch add-on zaroor milega 🎁 Dekhne ke liye button dabaiye 👇", 'buttons' => $qr(['Winter Perfumes', 'Abhi nahi'])],
            'tmph_style_gift' => ['body' => "Hi {{1}}! 🎁\n\nShaadi aur New Year ka season he — perfume sabse achha gift hota he ✨\n\nGift order par special add-on aur extra discount bhi karwa denge 🔥\n\nGift ke liye perfume dekhne ke liye tap kijiye 👇", 'buttons' => $qr(['Gift Perfumes', 'Abhi nahi'])],
            'tmph_style_mystery' => ['body' => "Hello {{1}} 🎁\n\nEk *mystery box* tayyar he aapke liye... 👀\n\nAndar kya he ? Bas ek tap me pata chalega!", 'buttons' => $qr(['Mystery Box Kholo', 'Abhi nahi'])],
            'tmph_style_refill' => ['body' => "Hi {{1}} 🤔\n\nAapki purani khushboo khatam hone wali hogi na ?\n\nNaye perfumes aur ek chhoti si surprise aapka intezar kar rahi he ✨\n\nDekhna chahenge ?", 'buttons' => $qr(['Haan, dikhao', 'Baad me'])],
            'tmph_style_sender' => ['body' => "Hi {{1}}! 👋\n\nMain TM Perfume House team se bol raha hoon 🌸\n\nAaj aapke liye kuch special chhupa ke rakha he... 🎁\n\nKaunsa size chahiye ? 👇", 'buttons' => $qr(['20ML', '50ML', '100ML'])],
            'tmph_style_wedding' => ['body' => "Hello {{1}} 💍\n\nShaadi ka season he! Perfume gift karne ka sabse sundar tareeka ✨\n\nGift ke liye ek special surprise taiyar he 🎁\n\nDekhne ke liye tap kijiye 👇", 'buttons' => $qr(['Gift Surprise', 'Abhi nahi'])],
            'tmph_style_newyear' => ['body' => "Hi {{1}} 🎉\n\nNew Year aa raha he! Naye saal ke liye naya perfume aur ek dhamakedaar surprise 🔥\n\nApni ML size chuniye 👇", 'buttons' => $qr(['20ML', '50ML', '100ML'])],
            'tmph_style_winter2' => ['body' => "Hello {{1}} ❄️\n\nThand me gehri khushboo ka maza hi alag he 😍\n\nAapke liye ek winter surprise unlock hua he 🔓\n\nNiche tap kijiye 👇", 'buttons' => $qr(['Unlock Karo'])],
            'tmph_style_asked' => ['body' => "Hello {{1}} 👋 aapne perfume ke bare me poocha tha na ?\n\nAaj ek *exclusive deal* mili he 🎁🔥 Apni ML size choose kijiye 👇", 'buttons' => $qr(['20ML', '50ML', '100ML'])],
        ];
    }
    /** Makes the five messages (once) and sends them to Meta. Returns [made, failed[]]. */
    function wa_style_make(PDO $pdo): array
    {
        $res = ['made' => 0, 'failed' => []];
        foreach (['wa_tpl_clean', 'wa_tpl_insert', 'wa_tpl_row'] as $f) if (!function_exists($f)) { $res['failed'][] = 'template module not loaded'; return $res; }
        $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 12, 'made' => 0];
        foreach (wa_style_pack() as $name => $m) {
            try { $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? LIMIT 1"); $q->execute([$name]); if ($q->fetchColumn()) continue; } catch (Throwable $e) { }
            [$t, $err] = wa_tpl_clean(['name' => $name, 'language' => 'en', 'category' => 'MARKETING', 'body' => $m['body'], 'footer' => 'TM Perfume House', 'buttons' => $m['buttons'], 'examples' => ['body' => ['Rahul'], 'labels' => ['Name']], 'folder' => 'Offers']);
            if ($err !== '') { $res['failed'][] = $name . ': ' . $err; continue; }
            $id = wa_tpl_insert($pdo, $t, 0, 'Message style the customers like (owner, 24 Nov 2026): warm Hinglish, curiosity, one choice by button.', 'Style message'); $r = wa_tpl_row($pdo, $id); if (!$r) { $res['failed'][] = $name . ': could not save'; continue; }
            $res['made']++;
            foreach ($wabas as $w) { if (function_exists('wa_tpl_try_create')) { [$ok, $why] = wa_tpl_try_create($pdo, $r, $w, $budget); if (!$ok) $res['failed'][] = $name . ' → ' . $why; } }
        }
        if (function_exists('wa_log')) wa_log($pdo, 'template', !$res['failed'], 'Style messages (owner, 24 Nov 2026): ' . $res['made'] . ' made' . ($res['failed'] ? ' — ' . $res['failed'][0] : ''));
        return $res;
    }

    /** DAILY: Claude writes NEW messages in the owner's style (owner 24 Nov 2026: "from now on make new messages yourself every day"): up to 4 MARKETING and 3 UTILITY a day, one per round, sent to Meta.
     *  Marketing: excitement, surprise, one choice by button, the season; no price, no date, no quantity (only 20 / 50 / 100 ML). Utility: warm curiosity, an order step by button, NO deal words, no claim of an enquiry or an order (kind "open": it joins the standard pool).
     *  Each is checked (variable rules, length, an emoji, no number, no deal word in utility), spaced with blank lines, and made once; names tmph_new_YYMMDD_n. State: setting wa_style_daily {day, m, u, last}. */
    function wa_style_daily_tick(PDO $pdo): array
    {
        $o = ['made' => 0, 'note' => ''];
        try {
            if ((string)setting_get($pdo, 'wa_style_daily_on', '1') === '0') { $o['note'] = 'off'; return $o; }
            foreach (['wa_tpl_clean', 'wa_tpl_insert', 'wa_tpl_row', 'brain_prepare_system', 'brain_claude_call'] as $f) if (!function_exists($f)) { $o['note'] = 'module not loaded'; return $o; }
            $st = json_decode((string)setting_get($pdo, 'wa_style_daily', ''), true); $st = is_array($st) ? $st : []; $today = date('Y-m-d'); if (($st['day'] ?? '') !== $today) $st = ['day' => $today, 'm' => 0, 'u' => 0, 'last' => 0];
            $nm = (int)setting_get($pdo, 'wa_style_daily_m', '4'); $nu = (int)setting_get($pdo, 'wa_style_daily_u', '3');
            if ((int)$st['m'] >= $nm && (int)$st['u'] >= $nu) { $o['note'] = 'done today'; return $o; }
            if ((int)$st['last'] > time() - 600) { $o['note'] = 'waiting'; return $o; }   // one in 10 minutes
            $cat = ((int)$st['m'] <= (int)$st['u'] && (int)$st['m'] < $nm) || (int)$st['u'] >= $nu ? 'MARKETING' : 'UTILITY';
            $st['last'] = time(); setting_set($pdo, 'wa_style_daily', json_encode($st));
            if (!function_exists('wa_space_body') && is_file(__DIR__ . '/wa_space.php')) { try { require_once __DIR__ . '/wa_space.php'; } catch (Throwable $e) { } }
            if (!function_exists('wa_rs_style_text') && is_file(__DIR__ . '/wa_restyle.php')) { try { require_once __DIR__ . '/wa_restyle.php'; } catch (Throwable $e) { } }
            $have = []; try { foreach ($pdo->query("SELECT body FROM wa_templates WHERE name LIKE 'tmph_new_%' OR name LIKE 'tmph_style_%' ORDER BY id DESC LIMIT 14")->fetchAll(PDO::FETCH_COLUMN) as $b) $have[] = mb_substr(str_replace("\n", ' / ', (string)$b), 0, 140); } catch (Throwable $e) { }
            $sys = "You write ONE new WhatsApp message for TM Perfume House (perfume shop, Surat, India), a brand-new idea in the owner's style, not a copy of the earlier ones. " . (function_exists('wa_rs_style_text') ? wa_rs_style_text() : '')
                . "Category " . $cat . ". Use exactly one variable {{1}} (the customer's first name), in the first line but never as the very first or the very last word. 2-5 short paragraphs with ONE blank line between them, 2-4 emoji, NEVER a basic customer-care message (no \"thank you for being a customer\", \"customer care team\", \"registered customer\", \"here to help\", \"how are you enjoying\", \"any doubt\", \"just checking in\" - owner 25 Nov: they get replies but no orders); every message carries excitement, surprise or a curiosity hook about the next perfume / size / combo. EXCITEMENT and a small SURPRISE or mystery. NO price, NO rupee sign, NO date, NO quantity and no promise of a specific discount; the only numbers allowed are the sizes 20 / 50 / 100 ML. "
                . ($cat === 'MARKETING' ? "MARKETING: you may use words like surprise, unlock, exclusive, new perfumes, season, gift, add-on, dhamakedaar. Season now: " . (function_exists('wa_style_season') ? wa_style_season() : '') . " " : "UTILITY: warm and curious but NO offer, discount, sale, free, deal, limited, hurry, launch, add-on, dhamaka, coupon; no claim that the customer asked about or ordered anything; one friendly question about how we can help, ending in an order or help step by button. ")
                . "Give 1-3 quick-reply button texts (max 20 characters each; for marketing for example '20ML', '50ML', '100ML' or 'Show my surprise'). Do not repeat an idea from these recent messages:\n" . implode("\n", $have) . "\nAnswer only JSON: {\"body\":\"...\",\"buttons\":[\"...\"],\"name\":\"short_snake_case_idea\"}.";
            $sys2 = brain_prepare_system('internal', 'whatsapp', $sys . ' Reply with JSON only, no other text.', ['ctx' => false, 'purpose' => 'internal:whatsapp']);
            $rc = brain_claude_call($sys2, "Write today's new " . strtolower($cat) . " message.", 1500, ['ctx' => false, 'purpose' => 'internal:whatsapp']); $j = null;
            if (!($rc[0] ?? false)) { $st['last'] = time() - 480; setting_set($pdo, 'wa_style_daily', json_encode($st)); $o['note'] = 'Claude: ' . mb_substr((string)($rc[2] ?? ''), 0, 80); return $o; }   // busy with live chats: try again in 2 minutes
            if (preg_match('/\{.*\}/s', (string)($rc[1] ?? ''), $mm)) $j = json_decode($mm[0], true); if (!is_array($j)) { $o['note'] = 'no JSON'; return $o; }
            $body = function_exists('wa_space_body') ? wa_space_body(trim((string)($j['body'] ?? ''))) : trim((string)($j['body'] ?? '')); $why = '';
            preg_match_all('/\{\{(\d+)\}\}/', $body, $vm); if (array_values(array_unique(array_map('intval', $vm[1]))) !== [1]) $why = 'variables';
            elseif (preg_match('/^\s*\{\{1\}\}/u', $body) || preg_match('/\{\{1\}\}\s*$/u', $body)) $why = 'variable at the start or end';
            elseif (mb_strlen($body) < 40 || mb_strlen($body) > 700) $why = 'length';
            elseif (!preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $body)) $why = 'no emoji';
            elseif (($__dl = wa_style_dull($body)) !== '') $why = $__dl;   /* owner 25 Nov: no basic customer-care messages in any module */
            elseif (preg_match('/₹|\brs\.?\s*\d|\d{4}/iu', $body)) $why = 'a price / date / number';
            elseif ($cat === 'UTILITY') { $low = mb_strtolower($body); foreach (array_merge(function_exists('wa_util_bad_words') ? wa_util_bad_words() : [], ['launch', 'add-on', 'addon', 'dhamaka', 'dhamake', 'unlock', 'exclusive', 'surprise deal']) as $w) if ($w !== '' && mb_strpos($low, $w) !== false) { $why = 'deal word: ' . trim($w); break; } }
            if ($why !== '') { $o['note'] = 'rejected: ' . $why; return $o; }
            $btn = []; foreach (array_slice((array)($j['buttons'] ?? []), 0, 3) as $b) { $t = mb_substr(trim((string)$b), 0, 20); if ($t !== '') $btn[] = ['type' => 'QUICK_REPLY', 'text' => $t]; } if (!$btn) { $o['note'] = 'no button'; return $o; }
            $n = (int)$st[$cat === 'MARKETING' ? 'm' : 'u'] + 1; $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower((string)($j['name'] ?? 'idea'))); $name = 'tmph_new_' . date('ymd') . '_' . ($cat === 'MARKETING' ? 'm' : 'u') . $n . '_' . substr(trim($slug, '_'), 0, 14);
            [$t, $err] = wa_tpl_clean(['name' => $name, 'language' => 'en', 'category' => $cat, 'body' => $body, 'footer' => 'TM Perfume House', 'buttons' => $btn, 'examples' => ['body' => ['Rahul'], 'labels' => ['Name']], 'folder' => $cat === 'MARKETING' ? 'Offers' : 'utility']);
            if ($err !== '') { $o['note'] = 'template check: ' . mb_substr($err, 0, 80); return $o; }
            $t['extra'] = $cat === 'UTILITY' ? ['util_pool' => 'open_new_' . date('ymd') . '_' . $n, 'util_kind' => 'open'] : ['daily_new' => date('Y-m-d')];
            $id = wa_tpl_insert($pdo, $t, 0, 'New message written by Claude for the day (owner, 24 Nov 2026): excitement, surprise, one choice by button.', 'Daily new message'); $row = wa_tpl_row($pdo, $id); if (!$row) { $o['note'] = 'could not save'; return $o; }
            $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 8, 'made' => 0]; foreach ($wabas as $w) { if (function_exists('wa_tpl_try_create')) { try { wa_tpl_try_create($pdo, $row, $w, $budget); } catch (Throwable $e) { } } }
            $st[$cat === 'MARKETING' ? 'm' : 'u'] = $n; setting_set($pdo, 'wa_style_daily', json_encode($st)); $o['made'] = 1; $o['note'] = $cat . ' ' . $name;
            if (function_exists('wa_log')) wa_log($pdo, 'template', true, 'Daily new message made (' . strtolower($cat) . '): ' . $name);
        } catch (Throwable $e) { $o['note'] = 'error: ' . mb_substr($e->getMessage(), 0, 100); }
        return $o;
    }
}

