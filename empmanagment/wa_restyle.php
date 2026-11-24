<?php
/**
 * wa_restyle.php — REPLACE THE OLD MESSAGES WITH THE NEW STYLE (owner 24 Nov 2026): "make a similar message in this style for every old message, and replace as they get approved".
 * The style (from the owner's "surprise deal" / "ml wise" messages that bring replies): warm Hinglish, the customer's name, emoji, a curiosity hook, ONE clear choice by button, short.
 * For every message the After-Order plan and the 30-day Lineup use (the shop's approved templates), Claude writes a TWIN with the same variables, the same category (utility stays utility: no deal words),
 * the same header / photo and the same number of buttons; the twin goes to Meta; when it is approved on every account where the old one is approved, the plan uses the twin instead of the old message.
 *  · State: setting wa_rs_state {pairs: {oldId: {new, src, at, st: made|replaced|fail, why}}}. wa_rs_report() gives the counts. Switch off: wa_rs_on = 0.
 *  · Two twins per minute at most, 400 attempts a day. Meta cannot edit an approved template, so the old one stays in the library (unused, its numbers kept for the comparison).
 * Called from the minute job after Module C (wa_rec.php). PHP 7.4 safe.
 */
if (!function_exists('wa_rs_tick')) {
    function wa_rs_state(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'wa_rs_state', ''), true); return is_array($j) && isset($j['pairs']) && is_array($j['pairs']) ? $j : ['pairs' => [], 'day' => '', 'n' => 0]; }
    function wa_rs_save(PDO $pdo, array $s): void { setting_set($pdo, 'wa_rs_state', json_encode($s)); }
    /** The shop's own approved messages the Lineup really sends (wln_existing_map in wa_line.php, overridden by the setting wl_map). */
    function wa_rs_line_map(PDO $pdo): array
    {
        $j = json_decode((string)setting_get($pdo, 'wl_map', ''), true); if (is_array($j) && $j) return array_map('intval', $j);
        $m = []; $by = ['trust' => [386, 336, 31, 3196], 'price' => [386, 2612, 32, 277], 'pending' => [60, 155, 153, 3196], 'size' => [386, 278, 2612, 32], 'reorder' => [288, 395, 277, 386], 'general' => [386, 336, 2612, 32]];
        foreach ($by as $t => $ids) foreach ($ids as $i => $id) $m['ln_' . $t . '_' . ($i + 1)] = $id;
        foreach ([388, 336, 277, 32] as $i => $id) $m['ln_campaign_' . ($i + 1)] = $id;
        $m['ln_all_5'] = 42; $m['ln_all_6'] = 23; $m['ln_all_7'] = 109; return $m;
    }
    /** old template id => where it is used ('aft:group' / 'lmap:id' / 'camp:id' / 'line:key'), the sources interleaved so every module gets its turn. */
    function wa_rs_targets(PDO $pdo): array
    {
        $aft = []; $lmap = []; $camp = []; $line = [];
        if (!function_exists('wa_aft_groups') && is_file(__DIR__ . '/wa_after.php')) { try { require_once __DIR__ . '/wa_after.php'; } catch (Throwable $e) { } }
        if (function_exists('wa_aft_groups')) { foreach (wa_aft_groups() as $g => $ids) foreach ($ids as $id) $aft[(int)$id] = 'aft:' . $g; }
        try { foreach (array_unique(array_values(wa_rs_line_map($pdo))) as $id) if ((int)$id > 0) $lmap[(int)$id] = 'lmap:' . (int)$id; } catch (Throwable $e) { }
        try { $q = $pdo->query("SELECT DISTINCT c.tpl_id FROM wa_campaigns c JOIN wa_templates t ON t.id = c.tpl_id WHERE c.created_at >= '" . date('Y-m-d H:i:s', time() - 14 * 86400) . "' AND t.category = 'MARKETING' AND (t.extra IS NULL OR t.extra NOT LIKE '%restyle_of%') AND t.name NOT LIKE 'tmph_style_%' ORDER BY c.id DESC LIMIT 40"); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) if ((int)$id > 0) $camp[(int)$id] = 'camp:' . (int)$id; } catch (Throwable $e) { }
        try { $lt = json_decode((string)setting_get($pdo, 'wl_tpls', ''), true); if (is_array($lt)) foreach ($lt as $k => $id) if ((int)$id > 0) $line[(int)$id] = 'line:' . $k; } catch (Throwable $e) { }
        $cold = []; $pool = [];   /* owner 25 Nov 2026: the Cold funnel's own questions and the utility pool of Module B / C get exciting twins too */
        try { if (!function_exists('wcd_ids') && is_file(__DIR__ . '/wa_cold.php')) require_once __DIR__ . '/wa_cold.php'; if (function_exists('wcd_ids')) foreach (wcd_ids($pdo) as $k => $id) if ((int)$id > 0) $cold[(int)$id] = 'cold:' . $k; } catch (Throwable $e) { }
        try { if (!function_exists('wa_util_pool_rows') && is_file(__DIR__ . '/wa_util.php')) require_once __DIR__ . '/wa_util.php'; if (function_exists('wa_util_pool_rows')) foreach (wa_util_pool_rows($pdo) as $k => $r) { if (strpos((string)$k, '_tw') !== false || (int)($r['id'] ?? 0) <= 0) continue; if (wa_rs_dull((string)($r['body'] ?? ''))) $pool[(int)$r['id']] = 'pool:' . $k; } } catch (Throwable $e) { }
        $o = []; $lists = [array_keys($aft), array_keys($lmap), array_keys($camp), array_keys($line), array_keys($pool), array_keys($cold)]; $srcs = [$aft, $lmap, $camp, $line, $pool, $cold]; $max = max(array_map('count', $lists));
        for ($i = 0; $i < $max; $i++) foreach ($lists as $n => $L) if (isset($L[$i]) && !isset($o[$L[$i]])) $o[$L[$i]] = $srcs[$n][$L[$i]];
        return $o;
    }
    /** After-Order's groups with the replaced messages swapped for their twins. */
    function wa_rs_map_groups(PDO $pdo, array $groups): array
    {
        $st = wa_rs_state($pdo); $m = []; foreach ($st['pairs'] as $old => $p) if (($p['st'] ?? '') === 'replaced' && (int)($p['new'] ?? 0) > 0) $m[(int)$old] = (int)$p['new'];
        if (!$m) return $groups; foreach ($groups as $g => $ids) $groups[$g] = array_map(function ($id) use ($m) { return $m[(int)$id] ?? $id; }, $ids);
        return $groups;
    }
    function wa_rs_style_text(): string
    {
        return "STYLE (the owner's customers love it and reply to it; SPACING: one blank line between every thought, never line after line; NEVER a basic customer-care message (no \"thank you for being a customer\", \"customer care team\", \"registered customer\", \"here to help\", \"how are you enjoying\", \"any doubt\", \"just checking in\" - owner 25 Nov: they get replies but no orders); every message carries excitement, surprise or a curiosity hook about the next perfume / size / combo. EXCITEMENT AND SURPRISE come first: every message should make the customer curious or excited (a surprise waiting, something unlocked, 'wo kya he ?', a small mystery, a happy tone with a clear reason to tap the button), never flat or formal; in UTILITY get the excitement from curiosity and warmth, not from deal words): warm, personal Hinglish (Hindi in Roman letters, the way a friendly shop person writes on WhatsApp), the customer's name, 2-4 emoji, a small curiosity hook or a friendly question, ONE clear choice by button, short (2-5 short lines), line breaks between thoughts. "
            . "Examples of the style (marketing): \"Hello {{1}} 🙋‍♂️ TM Perfume House se bol rahe hain.\\n\\nAaj perfumes par hamne ek bahot achhi si *surprise deal* rakhi he... 🎁\\n\\n*Wo kya he ?* 🤔\\nJan ne ke liye abhi button dabaiye !!\" and \"Hello {{1}}! 👋\\n\\nAapke liye kuch special unlock hua hai 👀🔥\\n\\n👇 Apni ML size par click kijiye aur dekhiye aapke liye kya unlock hua hai! 🔓😍\" with buttons 20ML / 50ML / 100ML. "
            . "More of the owner's own favourites (marketing): \"Hi {{1}}! 👋 Naye hafte ki shuruaat — aapke liye ek special offer unlock kiya hai. Ye offer sirf aaj (Monday) ke liye valid hai, kal se dobara nahi milega. Limited stock, first come first served. Aaj ka exclusive offer dekhne ke liye niche tap kijiye.\" (button: Share Exclusive Offer); \"⚡ Sunday ❌ Offer Day ✅ / Hi Sir! 👋 / Aaj humne sirf apne existing customers ke liye ek exclusive Sunday Offer unlock kiya hai. 🎁 / ⏳ Sirf aaj ke liye valid! Kal ye offer available nahi hoga. / 🔥 Limited Stock | First Come, First Served / Agar aap dekhna chahte hain ki aaj aapke liye kya special offer hai, to niche diye gaye option par tap kijiye. 👇\" (button: View Exclusive Offers); \"Bas ek chhota sa follow-up tha. Agar abhi tak perfume final nahi kiya hai, to bata dijiye. Main aapko best option suggest kar dunga. ✨ Aur agar aaj order confirm karte ho, to meri taraf se ek special surprise bhi rahega. Reply kar dijiye, main turant help karta hoon.\" (buttons: I Want to order / Already ordered); \"*1×50ML perfume sirf ₹400 ka? 🤨* *Kya?* *Haan sirji!* *Agar aap Tmph ke purane customer ho aur aapke paas purani Tmph bottle hai, to bas uski photo bhejo.* *5 old bottles = 5×50ML @ ₹2,000* *Bottle ki photo DM karo → deal unlock karo!*\" (button: I Want To Order). "
            . "More of the owner's words for MARKETING offers (use them where the old message is an offer or a new-arrival message; the owner says they are true): \"naye perfumes launch hue hain\", \"naya offer launch hua hai\", \"ek dhamakedaar cheez aayi hai, aapko bahut maza aane wala hai\", \"order par kuch na kuch add-on zaroor milega\", \"extra discount bhi karwa denge\". "
            . "For UTILITY use the same warmth and curiosity WITHOUT those words (no offer, launch, discount, add-on, dhamaka, free): for example \"aapke liye ek khaas update he 👀\", \"ek chhoti si madad\", \"aapka sawal humein yaad he\". "
            . "In MARKETING messages an urgency line (only today / limited stock) is part of the owner's style, but keep it only when the old message already is an offer; never invent a price, a quantity or a date. "
            . "THE MAIN GOAL (owner, 25 Nov 2026): the customer must WANT TO ORDER, in any way. Every message pushes toward an order: a season hook (winter / wedding / gift), curiosity or a reveal (\"tap to see\"), a clear choice by button, and - in MARKETING - the offer, the combo, the price or the deadline when the old message already has one. Replies alone are worthless; a message that only gets a reply and no order is a failure. For UTILITY (no deal words allowed by Meta) the desire comes from the season, the next perfume, the size choice and the reveal after the tap. "
            . "Examples (utility, no deal words): \"Hi {{1}} 😊 aapne {{3}} ko {{2}} ke bare me poocha tha... abhi bhi chahiye ? Neeche button dabaiye 👇\" and \"Hello {{1}} 🌸 TM Perfume House team yahan he. Koi sawal ho ya perfume chunne me help chahiye to bas ek button dabaiye 🙏\". ";
    }
    /** Too basic / not exciting (owner 25 Nov 2026): the customer-care wording, or no hook word at all (season, gift, surprise, offer, combo, curiosity, a new perfume). */
    function wa_rs_dull(string $b): bool
    {
        if (!function_exists('wa_style_dull') && is_file(__DIR__ . '/wa_style.php')) { try { require_once __DIR__ . '/wa_style.php'; } catch (Throwable $e) { } }
        if (function_exists('wa_style_dull') && wa_style_dull($b) !== '') return true;
        return !preg_match('/(naya|nayi|naye|nai |khaas|special|season|shaadi|wedding|winter|gift|surprise|dhamaka|intezaar|dekhna|dekhiye|dekhiyega|dekho|dikhao|pick|combo|offer|deal|launch|sirf|flat|deadline|aaj hi|abhi order|jaldi|reveal|maza)/iu', $b);
    }
    /** What "replaced" means for the Cold funnel's own messages and the utility pool: the Cold mapping points to the twin / the old pool message is retired (it is no longer picked). */
    function wa_rs_swap_pool(PDO $pdo, string $src, int $old, int $new): void
    {
        if (strpos($src, 'cold:') === 0) { $key = substr($src, 5); $j = json_decode((string)setting_get($pdo, 'wcd_tpls', ''), true); if (is_array($j) && isset($j[$key]) && (int)$j[$key] === $old) { $j[$key] = $new; setting_set($pdo, 'wcd_tpls', json_encode($j)); } }
        elseif (strpos($src, 'pool:') === 0 && function_exists('wa_mix_extra_set')) { wa_mix_extra_set($pdo, $old, ['retired' => 1]); }
    }
    /** Makes the twin of one old message; returns ['new' => id] or ['why' => text]. */
    function wa_rs_make_one(PDO $pdo, int $oldId): array
    {
        foreach (['wa_tpl_row', 'wa_tpl_clean', 'wa_tpl_insert', 'brain_ai_json'] as $f) if (!function_exists($f)) return ['why' => 'module not loaded: ' . $f];
        $o = wa_tpl_row($pdo, $oldId); if (!$o) return ['why' => 'old message not found'];
        if (!function_exists('wa_style_season') && is_file(__DIR__ . '/wa_style.php')) { try { require_once __DIR__ . '/wa_style.php'; } catch (Throwable $e) { } }
        $cat = strtoupper((string)($o['category'] ?? 'MARKETING')); $body = (string)$o['body']; preg_match_all('/\{\{(\d+)\}\}/', $body, $vm); $vars = array_values(array_unique(array_map('intval', $vm[1]))); sort($vars);
        $btn = (array)($o['buttons'] ?? []); $qrOnly = true; foreach ($btn as $b) if (strtoupper((string)($b['type'] ?? '')) !== 'QUICK_REPLY') $qrOnly = false;
        $sys = "You rewrite a WhatsApp message of TM Perfume House (perfume shop, Surat, India) in the owner's favourite style, keeping its MEANING and PURPOSE. " . wa_rs_style_text()
            . "Rules: use exactly these variables, each at least once: " . ($vars ? '{{' . implode('}}, {{', $vars) . '}}' : '(none)') . "; a variable never starts or ends the message and is never next to another variable; keep what each variable means (as in the old message). "
            . "Category " . $cat . ($cat === 'UTILITY' ? ": NO offer, discount, sale, free, deal, limited, hurry, coupon, price-as-a-deal words (Meta would turn it into marketing); only follow up on what the customer did, ask a simple question. Still design it as close to the owner's messages as those rules allow: an emoji heading line, short lines with line breaks, *bold* on the one important word, 2-4 emoji, a friendly Hinglish tone and one clear button label (for example 'Mujhe madad chahiye', 'Order status'); no urgency and no scarcity words." : ": offers and curiosity hooks are allowed, but keep every price / number the old message has (they are the shop's chart prices).") . " "
            . "SEASON NOW (use it lightly where it fits the old message, as a gift order or a season add-on idea; never invent a price, a date or a promise the old message does not have): " . (function_exists('wa_style_season') ? wa_style_season() : '') . " "
            . "Maximum 600 characters. " . ($btn && $qrOnly ? "Give exactly " . count($btn) . " button texts (quick replies, max 20 characters each, same meaning as the old buttons) in the same order as the old ones: " . json_encode(array_map(function ($b) { return (string)($b['text'] ?? ''); }, $btn), JSON_UNESCAPED_UNICODE) . ". " : "No buttons. ")
            . "Do not copy the old wording; do not invent facts, prices or promises that the old message does not have. Answer only JSON: {\"body\":\"...\",\"buttons\":[\"...\"]}.";
        $j = null; $err2 = '';
        if (function_exists('brain_prepare_system') && function_exists('brain_claude_call')) {
            try { $sys2 = brain_prepare_system('internal', 'whatsapp', $sys . ' Reply with JSON only, no other text.', ['ctx' => false, 'purpose' => 'internal:whatsapp']); $rc = brain_claude_call($sys2, "The old message:\n" . $body, 1800, ['ctx' => false, 'purpose' => 'internal:whatsapp']); $okc = $rc[0] ?? false; $tc = $rc[1] ?? ''; $ec = (string)($rc[2] ?? ($GLOBALS['brain_last_error'] ?? ''));
                if (!$okc) { $err2 = 'Claude: ' . mb_substr($ec !== '' ? $ec : (string)$tc, 0, 90); if (stripos($ec, 'busy') !== false) return ['why' => $err2, 'retry' => true, 'busy' => true]; } elseif (preg_match('/\{.*\}/s', (string)$tc, $mm)) { $j = json_decode($mm[0], true); if (!is_array($j)) $err2 = 'Claude: answer was not JSON'; } else $err2 = 'Claude: no JSON in the answer'; } catch (Throwable $e) { $err2 = 'Claude: ' . mb_substr($e->getMessage(), 0, 80); }
        } else { $j = brain_ai_json('internal', 'whatsapp', $sys, "The old message:\n" . $body, 1500, ['ctx' => false]); }
        if (!is_array($j) && $err2 !== '') return ['why' => $err2, 'retry' => true];
        if (!is_array($j) || trim((string)($j['body'] ?? '')) === '') return ['why' => 'Claude gave no answer', 'retry' => true];
        if (!function_exists('wa_space_body') && is_file(__DIR__ . '/wa_space.php')) { try { require_once __DIR__ . '/wa_space.php'; } catch (Throwable $e) { } }
        $nb = trim((string)$j['body']); if (function_exists('wa_space_body')) $nb = wa_space_body($nb); preg_match_all('/\{\{(\d+)\}\}/', $nb, $nm); $nv = array_values(array_unique(array_map('intval', $nm[1]))); sort($nv);
        if ($nv !== $vars) return ['why' => 'variables differ'];
        if (mb_strlen($nb) > 900 || mb_strlen($nb) < 15) return ['why' => 'length'];
        if (!function_exists('wa_style_dull') && is_file(__DIR__ . '/wa_style.php')) { try { require_once __DIR__ . '/wa_style.php'; } catch (Throwable $e) { } }
        if (function_exists('wa_style_dull') && ($__dl = wa_style_dull($nb)) !== '') return ['why' => $__dl];
        if (wa_rs_dull($nb)) return ['why' => 'not exciting enough (no season / surprise / offer / curiosity hook)'];   /* owner 25 Nov 2026: the customer must want to order */   /* owner 25 Nov 2026: a twin must not be a basic customer-care message */
        if (preg_match('/^\s*\{\{\d+\}\}/u', $nb) || preg_match('/\{\{\d+\}\}\s*$/u', $nb) || preg_match('/\{\{\d+\}\}\s*\{\{\d+\}\}/u', $nb)) return ['why' => 'variable at the start / end / next to another'];
        if ($cat === 'UTILITY') { $low = mb_strtolower($nb); foreach (wa_util_bad_words() as $w) if ($w !== '' && mb_strpos($low, $w) !== false) return ['why' => 'deal word: ' . trim($w)]; }
        $buttons = $btn; if ($btn && $qrOnly) { $tx = array_values(array_filter(array_map(function ($x) { return mb_substr(trim((string)$x), 0, 20); }, (array)($j['buttons'] ?? [])))); if (count($tx) !== count($btn)) return ['why' => 'buttons differ']; $buttons = array_map(function ($t) { return ['type' => 'QUICK_REPLY', 'text' => $t]; }, $tx); }
        $d = ['name' => 'rs' . $oldId . '_' . preg_replace('/[^a-z0-9_]/', '', strtolower(substr((string)$o['name'], 0, 22))), 'language' => (string)$o['language'], 'category' => $cat, 'body' => $nb, 'footer' => (string)($o['footer'] ?? ''), 'buttons' => $buttons,
            'examples' => is_array($o['examples'] ?? null) ? $o['examples'] : [], 'folder' => (string)($o['folder'] ?? '')];
        if (!empty($o['header_format'])) { $d['header_format'] = (string)$o['header_format']; $d['header_text'] = (string)($o['header_text'] ?? ''); $d['header_lib_id'] = (int)($o['header_lib_id'] ?? 0); }
        [$t, $err] = wa_tpl_clean($d); if ($err !== '') return ['why' => 'template check: ' . mb_substr($err, 0, 80)];
        $t['extra'] = ['restyle_of' => $oldId]; try { $__ox = function_exists('wa_mix_extra') ? wa_mix_extra($o) : []; if (!empty($__ox['util_pool'])) { $t['extra']['util_pool'] = preg_replace('/_tw.*$/', '', (string)$__ox['util_pool']) . '_tw' . substr(md5($oldId . microtime(true)), 0, 4); $t['extra']['util_kind'] = (string)($__ox['util_kind'] ?? 'open'); } } catch (Throwable $e) { }   /* a twin of a pool message joins the pool under its own key (Module B / C pick it once it is approved) */ if (!empty($o['family_id'])) $t['family_id'] = (int)$o['family_id'];
        $id = wa_tpl_insert($pdo, $t, 0, 'New style (owner, 24 Nov 2026) of message #' . $oldId . ': warm Hinglish, one choice by button.', 'Restyled twin'); $row = wa_tpl_row($pdo, $id); if (!$row) return ['why' => 'could not save'];
        $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 8, 'made' => 0];
        foreach ($wabas as $w) { if (function_exists('wa_tpl_try_create')) { try { wa_tpl_try_create($pdo, $row, $w, $budget); } catch (Throwable $e) { } } }
        return ['new' => (int)$id];
    }
    /** One round: makes up to 2 twins, and replaces the old messages whose twins are approved. */
    function wa_rs_tick(PDO $pdo): array
    {
        $o = ['made' => 0, 'replaced' => 0, 'note' => ''];
        try {
            if ((string)setting_get($pdo, 'wa_rs_on', '1') === '0') { $o['note'] = 'off'; return $o; }
            if (!function_exists('wa_lock') || !wa_lock($pdo, 'rs', 55)) { $o['note'] = 'busy'; return $o; }
            $st = wa_rs_state($pdo); $tg = wa_rs_targets($pdo);
            if (empty($st['retry_0']) ) { $st['retry_0'] = 1; foreach ($st['pairs'] as $k1 => $p1) if (($p1['st'] ?? '') === 'fail' && in_array((string)($p1['why'] ?? ''), ['Claude gave no answer', 'old message not approved'], true)) unset($st['pairs'][$k1]); $st['fails'] = []; $st['n'] = 0; }   // once: the 74 that failed because Claude gave no answer (and the Lineup messages that were not approved) are tried again
            foreach ($st['pairs'] as $k0 => $p0) if (($p0['st'] ?? '') === 'making' && (int)($p0['at'] ?? 0) < time() - 900) unset($st['pairs'][$k0]);   // a round that died halfway: try again after 15 minutes $today = date('Y-m-d'); if (($st['day'] ?? '') !== $today) { $st['day'] = $today; $st['n'] = 0; }
            // 1) replace: the twin is approved on every account where the old message is approved
            if ((int)setting_get($pdo, 'wa_rs_chk_at', '0') < time() - 240) { setting_set($pdo, 'wa_rs_chk_at', (string)time());
                foreach ($st['pairs'] as $old => &$p) {
                    if (($p['st'] ?? '') !== 'made') continue; $new = (int)($p['new'] ?? 0); if ($new <= 0) continue;
                    try { $tn = wa_camp_tpl($pdo, $new, true, 0); $to = wa_camp_tpl($pdo, (int)$old, true, 0); } catch (Throwable $e) { continue; }
                    $okN = $tn ? array_map('strval', (array)($tn['ok_wabas'] ?? [])) : []; $okO = $to ? array_map('strval', (array)($to['ok_wabas'] ?? [])) : [];
                    $row = wa_tpl_row($pdo, $new); if ($row && (!empty($row['relabelled_at']) || strtoupper((string)($row['category_now'] ?? '')) === 'MARKETING') && strtoupper((string)($row['category'] ?? '')) === 'UTILITY') { $p['st'] = 'fail'; $p['why'] = 'Meta moved it to marketing'; continue; }
                    if (!$okN || count(array_diff($okO, $okN)) > 0) continue;
                    $src = (string)($p['src'] ?? '');
                    if (strpos($src, 'camp:') === 0) continue;   // campaign messages: the twin is only made and approved; the owner chooses it for a campaign
                    if (strpos($src, 'lmap:') === 0) { $wm = wa_rs_line_map($pdo); foreach ($wm as $k2 => $id2) if ((int)$id2 === (int)$old) $wm[$k2] = $new; setting_set($pdo, 'wl_map', json_encode($wm)); }   // the Lineup's shop messages (one id can serve several steps)
                    if (strpos($src, 'line:') === 0) { $key = substr($src, 5); $ids = json_decode((string)setting_get($pdo, 'wl_tpls', ''), true); if (is_array($ids) && isset($ids[$key]) && (int)$ids[$key] === (int)$old) { $ids[$key] = $new; setting_set($pdo, 'wl_tpls', json_encode($ids)); } }
                    wa_rs_swap_pool($pdo, $src, (int)$old, $new);
                    $p['st'] = 'replaced'; $p['rep_at'] = time(); $o['replaced']++;
                } unset($p);
                // 1b) the SECOND version (owner 24 Nov: the first 31 twins were made before the excitement / surprise rule): when it is approved wherever the old message is, it takes the place of the first twin
                foreach ($st['pairs'] as $old => &$p) {
                    $v2 = (int)($p['v2'] ?? 0); if ($v2 <= 0 || empty($p['new'])) continue; $prev = (int)$p['new'];
                    try { $tn = wa_camp_tpl($pdo, $v2, true, 0); $to = wa_camp_tpl($pdo, (int)$old, true, 0); } catch (Throwable $e) { continue; }
                    $okN = $tn ? array_map('strval', (array)($tn['ok_wabas'] ?? [])) : []; $okO = $to ? array_map('strval', (array)($to['ok_wabas'] ?? [])) : [];
                    $row = wa_tpl_row($pdo, $v2); if ($row && (!empty($row['relabelled_at']) || strtoupper((string)($row['category_now'] ?? '')) === 'MARKETING') && strtoupper((string)($row['category'] ?? '')) === 'UTILITY') { unset($p['v2']); $p['v2fail'] = 1; continue; }
                    if (!$okN || count(array_diff($okO, $okN)) > 0) continue;
                    $src = (string)($p['src'] ?? '');
                    if (strpos($src, 'lmap:') === 0) { $wm = wa_rs_line_map($pdo); foreach ($wm as $k2 => $id2) if ((int)$id2 === $prev) $wm[$k2] = $v2; setting_set($pdo, 'wl_map', json_encode($wm)); }
                    if (strpos($src, 'line:') === 0) { $key = substr($src, 5); $ids = json_decode((string)setting_get($pdo, 'wl_tpls', ''), true); if (is_array($ids) && isset($ids[$key]) && (int)$ids[$key] === $prev) { $ids[$key] = $v2; setting_set($pdo, 'wl_tpls', json_encode($ids)); } }
                    if (strpos($src, 'cold:') === 0) { $j = json_decode((string)setting_get($pdo, 'wcd_tpls', ''), true); $key = substr($src, 5); if (is_array($j) && isset($j[$key]) && (int)$j[$key] === $prev) { $j[$key] = $v2; setting_set($pdo, 'wcd_tpls', json_encode($j)); } } elseif (strpos($src, 'pool:') === 0 && function_exists('wa_mix_extra_set')) { wa_mix_extra_set($pdo, $prev, ['retired' => 1]); }
                    $p['new'] = $v2; $p['v1'] = $prev; unset($p['v2']); $p['v2done'] = time(); $o['replaced']++;
                } unset($p);
            }
            // 2) make: two old messages without a twin
            foreach ($tg as $old => $src) {
                if ($o['made'] >= 2 || (int)$st['n'] >= 900) break; if (isset($st['pairs'][$old])) continue; if ((int)($st['next'][$old] ?? 0) > time()) continue;
                try { $to = wa_camp_tpl($pdo, (int)$old, true, 0); } catch (Throwable $e) { $to = null; } if (!$to || empty($to['ok_wabas'])) { $st['pairs'][$old] = ['src' => $src, 'st' => 'fail', 'why' => 'old message not approved', 'at' => time()]; continue; }
                $st['pairs'][$old] = ['src' => $src, 'st' => 'making', 'at' => time()]; wa_rs_save($pdo, $st);   // saved BEFORE the work: two overlapping rounds made two twins of the same message (the second one was refused by Meta: the name existed)
                $r = wa_rs_make_one($pdo, (int)$old); $st['n']++;
                if (!empty($r['new'])) { $st['pairs'][$old] = ['new' => (int)$r['new'], 'src' => $src, 'st' => 'made', 'at' => time()]; $o['made']++; wa_rs_save($pdo, $st); }
                else { unset($st['pairs'][$old]); if (!empty($r['busy'])) { $st['next'][$old] = time() + 120; $o['note'] = (string)$r['why']; $st['n'] = max(0, (int)$st['n'] - 1); break; }   // Claude is busy with live chats (they go first): not the message's fault, try again in 2 minutes, no strike
                    $f = (int)($st['fails'][$old] ?? 0) + 1; $st['fails'][$old] = $f; $st['next'][$old] = time() + (!empty($r['retry']) ? 600 : 3600);
                    if ($f >= (!empty($r['retry']) ? 30 : 3)) $st['pairs'][$old] = ['src' => $src, 'st' => 'fail', 'why' => (string)($r['why'] ?? '?'), 'at' => time()]; $o['note'] = (string)($r['why'] ?? ''); if (!empty($r['retry'])) break; }   // a Claude error (not the message's fault): retry in 10 minutes, up to 30 times; a round stops at the first one
            }
            $__nb = []; try { $__ids = array_values(array_filter(array_map(function ($pp) { return (int)($pp['new'] ?? 0); }, $st['pairs']))); if ($__ids) foreach ($pdo->query("SELECT id, body FROM wa_templates WHERE id IN (" . implode(',', $__ids) . ")")->fetchAll(PDO::FETCH_NUM) as $__r) $__nb[(int)$__r[0]] = (string)$__r[1]; } catch (Throwable $e) { }
            if ($o['made'] < 2 && $o['note'] === '' && (int)$st['n'] < 900) {   // 🔁 second pass: the twins made before the excitement / surprise rule (13:47 UTC, 24 Nov) get a second version, two a round
                foreach ($st['pairs'] as $old => $p0) { if ($o['made'] >= 2 || (int)$st['n'] >= 400) break;
                    if (empty($p0['new']) || !in_array((string)($p0['st'] ?? ''), ['made', 'replaced'], true) || !empty($p0['v2']) || !empty($p0['v2fail'])) continue;
                    $__early = (int)($p0['at'] ?? 0) < 1795528020 && empty($p0['v2done']); $__dull = isset($__nb[(int)$p0['new']]) && wa_rs_dull((string)$__nb[(int)$p0['new']]);   /* a twin whose own wording is basic gets another version too (owner 25 Nov 2026: the customer must want to order) */
                    if (!$__early && !$__dull) continue;
                    if ((int)($st['next'][$old] ?? 0) > time()) continue;
                    $r = wa_rs_make_one($pdo, (int)$old); $st['n']++;
                    if (!empty($r['new'])) { $st['pairs'][$old]['v2'] = (int)$r['new']; $o['made']++; wa_rs_save($pdo, $st); }
                    else { if (!empty($r['busy'])) { $st['next'][$old] = time() + 120; $o['note'] = (string)$r['why']; break; } $f2 = (int)($st['fails2'][$old] ?? 0) + 1; $st['fails2'][$old] = $f2; $st['next'][$old] = time() + (!empty($r['retry']) ? 600 : 3600); if ($f2 >= (!empty($r['retry']) ? 30 : 3)) $st['pairs'][$old]['v2fail'] = 1; $o['note'] = (string)($r['why'] ?? ''); if (!empty($r['retry'])) break; } }
            }
            wa_rs_save($pdo, $st);
            if ($o['note'] !== '') { $st['why_last'] = $o['note']; $st['why_n'][$o['note']] = (int)($st['why_n'][$o['note']] ?? 0) + 1; wa_rs_save($pdo, $st); }
            if (($o['made'] || $o['replaced']) && function_exists('wa_log')) wa_log($pdo, 'template', true, 'New-style messages: ' . $o['made'] . ' made, ' . $o['replaced'] . ' replaced');
            try { if (!function_exists('wa_style_daily_tick') && is_file(__DIR__ . '/wa_style.php')) require_once __DIR__ . '/wa_style.php'; if (function_exists('wa_style_daily_tick')) { $dn = wa_style_daily_tick($pdo); $o['daily'] = $dn['note'] ?? ''; } } catch (Throwable $e2) { }   // 🆕 up to 7 brand-new messages a day (owner 24 Nov)
        } catch (Throwable $e) { if (function_exists('wa_log')) wa_log($pdo, 'template', false, 'New-style messages error: ' . mb_substr($e->getMessage(), 0, 120)); $o['note'] = 'error'; }
        return $o;
    }
    function wa_rs_report(PDO $pdo): array
    {
        $st = wa_rs_state($pdo); $tg = wa_rs_targets($pdo); $c = ['targets' => count($tg), 'made' => 0, 'replaced' => 0, 'fail' => 0, 'waiting' => 0, 'by_src' => []];
        foreach ($st['pairs'] as $old => $p) { $s = (string)($p['st'] ?? ''); if (!empty($p['v2'])) $c['v2_made'] = (int)($c['v2_made'] ?? 0) + 1; if (!empty($p['v2done'])) $c['v2_done'] = (int)($c['v2_done'] ?? 0) + 1; if ($s === 'replaced') { $c['replaced']++; $c['made']++; } elseif ($s === 'made') $c['made']++; elseif ($s === 'fail') $c['fail']++;
            $k = explode(':', (string)($p['src'] ?? '?'))[0]; if (!isset($c['by_src'][$k])) $c['by_src'][$k] = ['made' => 0, 'replaced' => 0]; if ($s === 'replaced') { $c['by_src'][$k]['replaced']++; } if ($s === 'replaced' || $s === 'made') $c['by_src'][$k]['made']++; }
        $c['waiting'] = max(0, $c['targets'] - count(array_intersect_key($st['pairs'], $tg))); $c['made_today'] = (int)($st['n'] ?? 0); return $c;
    }
}
