<?php
/**
 * wa_restyle.php — REPLACE THE OLD MESSAGES WITH THE NEW STYLE (owner 24 Nov 2026): "make a similar message in this style for every old message, and replace as they get approved".
 * The style (from the owner's "surprise deal" / "ml wise" messages that bring replies): warm Hinglish, the customer's name, emoji, a curiosity hook, ONE clear choice by button, short.
 * For every message the After-Order plan and the 30-day Lineup use (the shop's approved templates), Claude writes a TWIN with the same variables, the same category (utility stays utility: no deal words),
 * the same header / photo and the same number of buttons; the twin goes to Meta; when it is approved on every account where the old one is approved, the plan uses the twin instead of the old message.
 *  · State: setting wa_rs_state {pairs: {oldId: {new, src, at, st: made|replaced|fail, why}}}. wa_rs_report() gives the counts. Switch off: wa_rs_on = 0.
 *  · Two twins per minute at most, 150 a day. Meta cannot edit an approved template, so the old one stays in the library (unused, its numbers kept for the comparison).
 * Called from the minute job after Module C (wa_rec.php). PHP 7.4 safe.
 */
if (!function_exists('wa_rs_tick')) {
    function wa_rs_state(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'wa_rs_state', ''), true); return is_array($j) && isset($j['pairs']) && is_array($j['pairs']) ? $j : ['pairs' => [], 'day' => '', 'n' => 0]; }
    function wa_rs_save(PDO $pdo, array $s): void { setting_set($pdo, 'wa_rs_state', json_encode($s)); }
    /** old template id => where it is used ('aft:group' / 'line:key'). */
    function wa_rs_targets(PDO $pdo): array
    {
        $o = [];
        if (!function_exists('wa_aft_groups') && is_file(__DIR__ . '/wa_after.php')) { try { require_once __DIR__ . '/wa_after.php'; } catch (Throwable $e) { } }
        if (function_exists('wa_aft_groups')) { foreach (wa_aft_groups() as $g => $ids) foreach ($ids as $id) $o[(int)$id] = 'aft:' . $g; }
        try { $lt = json_decode((string)setting_get($pdo, 'wl_tpls', ''), true); if (is_array($lt)) foreach ($lt as $k => $id) if ((int)$id > 0 && !isset($o[(int)$id])) $o[(int)$id] = 'line:' . $k; } catch (Throwable $e) { }
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
        return "STYLE (the owner's customers love it and reply to it): warm, personal Hinglish (Hindi in Roman letters, the way a friendly shop person writes on WhatsApp), the customer's name, 2-4 emoji, a small curiosity hook or a friendly question, ONE clear choice by button, short (2-5 short lines), line breaks between thoughts. "
            . "Examples of the style (marketing): \"Hello {{1}} 🙋‍♂️ TM Perfume House se bol rahe hain.\\n\\nAaj perfumes par hamne ek bahot achhi si *surprise deal* rakhi he... 🎁\\n\\n*Wo kya he ?* 🤔\\nJan ne ke liye abhi button dabaiye !!\" and \"Hello {{1}}! 👋\\n\\nAapke liye kuch special unlock hua hai 👀🔥\\n\\n👇 Apni ML size par click kijiye aur dekhiye aapke liye kya unlock hua hai! 🔓😍\" with buttons 20ML / 50ML / 100ML. "
            . "Examples (utility, no deal words): \"Hi {{1}} 😊 aapne {{3}} ko {{2}} ke bare me poocha tha... abhi bhi chahiye ? Neeche button dabaiye 👇\" and \"Hello {{1}} 🌸 TM Perfume House team yahan he. Koi sawal ho ya perfume chunne me help chahiye to bas ek button dabaiye 🙏\". ";
    }
    /** Makes the twin of one old message; returns ['new' => id] or ['why' => text]. */
    function wa_rs_make_one(PDO $pdo, int $oldId): array
    {
        foreach (['wa_tpl_row', 'wa_tpl_clean', 'wa_tpl_insert', 'brain_ai_json'] as $f) if (!function_exists($f)) return ['why' => 'module not loaded: ' . $f];
        $o = wa_tpl_row($pdo, $oldId); if (!$o) return ['why' => 'old message not found'];
        $cat = strtoupper((string)($o['category'] ?? 'MARKETING')); $body = (string)$o['body']; preg_match_all('/\{\{(\d+)\}\}/', $body, $vm); $vars = array_values(array_unique(array_map('intval', $vm[1]))); sort($vars);
        $btn = (array)($o['buttons'] ?? []); $qrOnly = true; foreach ($btn as $b) if (strtoupper((string)($b['type'] ?? '')) !== 'QUICK_REPLY') $qrOnly = false;
        $sys = "You rewrite a WhatsApp message of TM Perfume House (perfume shop, Surat, India) in the owner's favourite style, keeping its MEANING and PURPOSE. " . wa_rs_style_text()
            . "Rules: use exactly these variables, each at least once: " . ($vars ? '{{' . implode('}}, {{', $vars) . '}}' : '(none)') . "; a variable never starts or ends the message and is never next to another variable; keep what each variable means (as in the old message). "
            . "Category " . $cat . ($cat === 'UTILITY' ? ": NO offer, discount, sale, free, deal, limited, hurry, coupon, price-as-a-deal words (Meta would turn it into marketing); only follow up on what the customer did, ask a simple question." : ": offers and curiosity hooks are allowed, but keep every price / number the old message has (they are the shop's chart prices).") . " "
            . "Maximum 600 characters. " . ($btn && $qrOnly ? "Give exactly " . count($btn) . " button texts (quick replies, max 20 characters each, same meaning as the old buttons) in the same order as the old ones: " . json_encode(array_map(function ($b) { return (string)($b['text'] ?? ''); }, $btn), JSON_UNESCAPED_UNICODE) . ". " : "No buttons. ")
            . "Do not copy the old wording; do not invent facts, prices or promises that the old message does not have. Answer only JSON: {\"body\":\"...\",\"buttons\":[\"...\"]}.";
        $j = brain_ai_json('internal', 'whatsapp', $sys, "The old message:\n" . $body, 1500, ['ctx' => false]);
        if (!is_array($j) || trim((string)($j['body'] ?? '')) === '') return ['why' => 'Claude gave no answer'];
        $nb = trim((string)$j['body']); preg_match_all('/\{\{(\d+)\}\}/', $nb, $nm); $nv = array_values(array_unique(array_map('intval', $nm[1]))); sort($nv);
        if ($nv !== $vars) return ['why' => 'variables differ'];
        if (mb_strlen($nb) > 900 || mb_strlen($nb) < 15) return ['why' => 'length'];
        if (preg_match('/^\s*\{\{\d+\}\}/u', $nb) || preg_match('/\{\{\d+\}\}\s*$/u', $nb) || preg_match('/\{\{\d+\}\}\s*\{\{\d+\}\}/u', $nb)) return ['why' => 'variable at the start / end / next to another'];
        if ($cat === 'UTILITY') { $low = mb_strtolower($nb); foreach (wa_util_bad_words() as $w) if ($w !== '' && mb_strpos($low, $w) !== false) return ['why' => 'deal word: ' . trim($w)]; }
        $buttons = $btn; if ($btn && $qrOnly) { $tx = array_values(array_filter(array_map(function ($x) { return mb_substr(trim((string)$x), 0, 20); }, (array)($j['buttons'] ?? [])))); if (count($tx) !== count($btn)) return ['why' => 'buttons differ']; $buttons = array_map(function ($t) { return ['type' => 'QUICK_REPLY', 'text' => $t]; }, $tx); }
        $d = ['name' => 'rs' . $oldId . '_' . preg_replace('/[^a-z0-9_]/', '', strtolower(substr((string)$o['name'], 0, 22))), 'language' => (string)$o['language'], 'category' => $cat, 'body' => $nb, 'footer' => (string)($o['footer'] ?? ''), 'buttons' => $buttons,
            'examples' => is_array($o['examples'] ?? null) ? $o['examples'] : [], 'folder' => (string)($o['folder'] ?? '')];
        if (!empty($o['header_format'])) { $d['header_format'] = (string)$o['header_format']; $d['header_text'] = (string)($o['header_text'] ?? ''); $d['header_lib_id'] = (int)($o['header_lib_id'] ?? 0); }
        [$t, $err] = wa_tpl_clean($d); if ($err !== '') return ['why' => 'template check: ' . mb_substr($err, 0, 80)];
        $t['extra'] = ['restyle_of' => $oldId]; if (!empty($o['family_id'])) $t['family_id'] = (int)$o['family_id'];
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
                    if (strpos($src, 'line:') === 0) { $key = substr($src, 5); $ids = json_decode((string)setting_get($pdo, 'wl_tpls', ''), true); if (is_array($ids) && isset($ids[$key]) && (int)$ids[$key] === (int)$old) { $ids[$key] = $new; setting_set($pdo, 'wl_tpls', json_encode($ids)); } }
                    $p['st'] = 'replaced'; $p['rep_at'] = time(); $o['replaced']++;
                } unset($p);
            }
            // 2) make: two old messages without a twin
            foreach ($tg as $old => $src) {
                if ($o['made'] >= 2 || (int)$st['n'] >= 150) break; if (isset($st['pairs'][$old])) continue;
                try { $to = wa_camp_tpl($pdo, (int)$old, true, 0); } catch (Throwable $e) { $to = null; } if (!$to || empty($to['ok_wabas'])) { $st['pairs'][$old] = ['src' => $src, 'st' => 'fail', 'why' => 'old message not approved', 'at' => time()]; continue; }
                $st['pairs'][$old] = ['src' => $src, 'st' => 'making', 'at' => time()]; wa_rs_save($pdo, $st);   // saved BEFORE the work: two overlapping rounds made two twins of the same message (the second one was refused by Meta: the name existed)
                $r = wa_rs_make_one($pdo, (int)$old); $st['n']++;
                if (!empty($r['new'])) { $st['pairs'][$old] = ['new' => (int)$r['new'], 'src' => $src, 'st' => 'made', 'at' => time()]; $o['made']++; wa_rs_save($pdo, $st); }
                else { unset($st['pairs'][$old]); $f = (int)($st['fails'][$old] ?? 0) + 1; $st['fails'][$old] = $f; if ($f >= 3) $st['pairs'][$old] = ['src' => $src, 'st' => 'fail', 'why' => (string)($r['why'] ?? '?'), 'at' => time()]; $o['note'] = (string)($r['why'] ?? ''); }
            }
            wa_rs_save($pdo, $st);
            if (($o['made'] || $o['replaced']) && function_exists('wa_log')) wa_log($pdo, 'template', true, 'New-style messages: ' . $o['made'] . ' made, ' . $o['replaced'] . ' replaced');
        } catch (Throwable $e) { if (function_exists('wa_log')) wa_log($pdo, 'template', false, 'New-style messages error: ' . mb_substr($e->getMessage(), 0, 120)); $o['note'] = 'error'; }
        return $o;
    }
    function wa_rs_report(PDO $pdo): array
    {
        $st = wa_rs_state($pdo); $tg = wa_rs_targets($pdo); $c = ['targets' => count($tg), 'made' => 0, 'replaced' => 0, 'fail' => 0, 'waiting' => 0, 'by_src' => []];
        foreach ($st['pairs'] as $old => $p) { $s = (string)($p['st'] ?? ''); if ($s === 'replaced') { $c['replaced']++; $c['made']++; } elseif ($s === 'made') $c['made']++; elseif ($s === 'fail') $c['fail']++;
            $k = explode(':', (string)($p['src'] ?? '?'))[0]; if (!isset($c['by_src'][$k])) $c['by_src'][$k] = ['made' => 0, 'replaced' => 0]; if ($s === 'replaced') { $c['by_src'][$k]['replaced']++; } if ($s === 'replaced' || $s === 'made') $c['by_src'][$k]['made']++; }
        $c['waiting'] = max(0, $c['targets'] - count(array_intersect_key($st['pairs'], $tg))); $c['made_today'] = (int)($st['n'] ?? 0); return $c;
    }
}
