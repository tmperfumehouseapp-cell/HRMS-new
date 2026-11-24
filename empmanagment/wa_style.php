<?php
/**
 * wa_style.php — the message STYLE the owner's customers like (owner 24 Nov 2026, from the "surprise deal" and "ml wise" messages that bring replies):
 * a warm, personal Hinglish line with emoji, a curiosity hook ("Wo kya he? ... button dabaiye"), one clear choice by button (20ML / 50ML / 100ML), short.
 * This file makes 5 MARKETING messages in that style once (wa_style_v1) and sends them to Meta; the owner picks them for a campaign (Module A). The utility modules (Module B / C, rescue, Cold, After-Order care)
 * get the same warm Hinglish + buttons through the utility pool (wa_util_pool_defs 'open_hi_*'), but NEVER the deal words ("surprise deal", "unlock", "discount", "free gift"): Meta would move a utility message with those words to marketing.
 * Called once from the Module C round (wa_modc.php). PHP 7.4 safe.
 */
if (!function_exists('wa_style_pack')) {
    function wa_style_pack(): array
    {
        $qr = function (array $l) { return array_map(function ($t) { return ['type' => 'QUICK_REPLY', 'text' => $t]; }, $l); };
        return [
            'tmph_style_surprise' => ['body' => "Hello {{1}} 🙋‍♂️ TM Perfume House se bol rahe hain.\n\nAaj perfumes par hamne ek bahot achhi si *surprise deal* rakhi he... 🎁\n\n*Wo kya he ?* 🤔\nJan ne ke liye abhi button dabaiye !!", 'buttons' => $qr(['Show my deal', 'Not now'])],
            'tmph_style_mlwise' => ['body' => "Hello {{1}}! 👋\n\nAapke liye kuch special unlock hua hai 👀🔥\n\n🎁 EXCLUSIVE ML-WISE DEAL\n✨ Special Discount\n🎁 FREE Gift\n💥 Surprise Combo\n\n👇 Apni ML size par click kijiye aur dekhiye aapke liye kya unlock hua hai! 🔓😍", 'buttons' => $qr(['20ML Combo', '50ML Combo', '100ML Combo'])],
            'tmph_style_choose' => ['body' => "Hi {{1}} 🌸 ek chhoti si baat batani thi...\n\nAapke liye aaj ek *surprise combo* ready he 🎁💥\n\nKaunsa size chahiye ? 👇", 'buttons' => $qr(['20ML', '50ML', '100ML'])],
            'tmph_style_reorder' => ['body' => "Hi {{1}} 😊 aapka perfume kaisa laga ?\n\nAgar pasand aaya ho to aapke liye *special reorder deal* ready he 🔓🎁\n\nDekhna chahenge ?", 'buttons' => $qr(['Haan, dikhao', 'Abhi nahi'])],
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
