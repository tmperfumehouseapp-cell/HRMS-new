<?php
/**
 * wa_style.php — the message STYLE the owner's customers like (owner 24 Nov 2026, from the "surprise deal" and "ml wise" messages that bring replies):
 * a warm, personal Hinglish line with emoji, a curiosity hook ("Wo kya he? ... button dabaiye"), one clear choice by button (20ML / 50ML / 100ML), short.
 * This file makes 5 MARKETING messages in that style once (wa_style_v1) and sends them to Meta; the owner picks them for a campaign (Module A). The utility modules (Module B / C, rescue, Cold, After-Order care)
 * get the same warm Hinglish + buttons through the utility pool (wa_util_pool_defs 'open_hi_*'), but NEVER the deal words ("surprise deal", "unlock", "discount", "free gift"): Meta would move a utility message with those words to marketing.
 * Called once from the Module C round (wa_modc.php). PHP 7.4 safe.
 */
if (!function_exists('wa_style_pack')) {
    /** The season the messages should fit (owner 24 Nov 2026: "consider the new season: gift orders, season add-ons"). Used in the text Claude gets; it never creates a price, a date or a promise. */
    function wa_style_season(?int $ts = null): string
    {
        $m = (int)date('n', $ts ?? time()); $d = (int)date('j', $ts ?? time());
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
}
