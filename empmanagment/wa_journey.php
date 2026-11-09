<?php
/**
 * wa_journey.php — 🗓 THE 60-DAY JOURNEY (WhatsApp → Automation → 60-day Journey). Admin / HR.
 *
 *   ONE plan for every customer for 60 days, on two tracks, with a rolling entry and exit:
 *     · "Didn't order"  — day counted from the customer's LAST message to us. Days 1–10 belong to the Follow-up Autopilot (wa_autopilot.php);
 *                         this plan carries on from day 11 to day 60. When the customer writes again the day count starts again (they are back with the Autopilot).
 *     · "Ordered"       — day counted from the customer's LAST ORDER (After-delivery messages and Order updates stay as they are).
 *   Every day new people enter (their day 1 arrives), and the ones past day 60 simply stop — nothing is moved by hand. Nothing is copied per customer:
 *   a step is due when (today − anchor) reaches its day, it is sent once per anchor (table wa_wj_ev), a missed step is never caught up in a burst.
 *
 *   ONE central registry of messages and offers = the Autopilot's offer ladder (fa_offers + one approved Meta message per entry, 3 blanks:
 *   {{1}} name · {{2}} the perfume · {{3}} the offer/line, filled by Claude per customer). Change an entry and every step that uses it changes.
 *
 *   NIGHTLY: Claude reads the day's chats → topics (what customers talk about), then reviews the plan against topics + results + festivals and leaves a
 *   proposal ("what would change") for the owner. The FESTIVAL WATCHER suggests a season switch ~3 weeks before a festival.
 *   THE GENERAL BRAIN (fu.wj_brain): the owner talks; Claude answers with questions + a list of exact changes (steps, messages/offers, festival). Nothing changes
 *   before ▶ Start; ⏹ Stop drops it; Undo restores the plan, registry and festival as they were. The brain reads everything the Teach bot knows, and the Teach bot reads
 *   the current offers + festival back (wj_bot_note, appended to the bot's STORE FACTS).
 *
 *   Modes: off · suggest (shows who would get what today, sends nothing — the default) · auto.
 *   Actions: fu.wj_get · fu.wj_queue · fu.wj_results · fu.wj_mode · fu.wj_cfg · fu.wj_steps_save · fu.wj_brain · fu.wj_apply · fu.wj_undo · fu.wj_night_run · fu.wj_fest · fu.wj_tpl
 *   Loaded by wa_more.php after wa_autopilot.php. PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wj_tables')) {

// ---------------------------------------------------------------------------------------------------------------------- storage + small helpers
function wj_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_wj_ev (id BIGINT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, track VARCHAR(8) NOT NULL DEFAULT '', step_id VARCHAR(12) NOT NULL DEFAULT '', day INT NOT NULL DEFAULT 0,
            anchor VARCHAR(10) NOT NULL DEFAULT '', ref VARCHAR(16) NOT NULL DEFAULT '', status VARCHAR(10) NOT NULL DEFAULT 'sent', body TEXT NULL, why VARCHAR(160) NOT NULL DEFAULT '', sent_at DATETIME NULL,
            UNIQUE KEY uq_step (phone, track, step_id, anchor), KEY k_at (sent_at), KEY k_phone (phone, sent_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['ALTER TABLE wa_contacts ADD COLUMN wj_anchor DATETIME NULL', 'ALTER TABLE wa_contacts ADD COLUMN wj_anchor_in DATETIME NULL'] as $__alt) { try { $pdo->exec($__alt); } catch (Throwable $e) { /* already there */ } }
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_wj_topic (phone VARCHAR(12) NOT NULL PRIMARY KEY, topic VARCHAR(32) NOT NULL DEFAULT '', perfume VARCHAR(60) NOT NULL DEFAULT '', hook VARCHAR(200) NOT NULL DEFAULT '',
            at DATETIME NULL, KEY k_topic (topic)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
function wj_json(PDO $pdo, string $k, $def) { $j = json_decode((string)setting_get($pdo, $k, ''), true); return is_array($j) ? $j : $def; }
function wj_put(PDO $pdo, string $k, $v): void { setting_set($pdo, $k, json_encode($v, JSON_UNESCAPED_UNICODE)); }
function wj_dt(int $ts): string { return date('Y-m-d H:i:s', $ts); }
function wj_mode(PDO $pdo): string { $m = (string)setting_get($pdo, 'wj_mode', 'suggest'); return in_array($m, ['off', 'suggest', 'auto'], true) ? $m : 'suggest'; }
function wj_cfg(PDO $pdo): array
{
    $d = ['gap_days' => 2, 'day_cap' => 300, 'run_cap' => 12, 'grace' => 2, 'catchup' => 1, 'from' => 600, 'to' => 1200, 'lead_days' => 21];
    $j = wj_json($pdo, 'wj_cfg', []); foreach ($d as $k => $v) if (isset($j[$k]) && is_numeric($j[$k])) $d[$k] = (int)$j[$k];
    $d['gap_days'] = max(1, min(14, $d['gap_days'])); $d['day_cap'] = max(10, min(3000, $d['day_cap'])); $d['run_cap'] = max(1, min(40, $d['run_cap'])); $d['grace'] = max(1, min(5, $d['grace']));
    $d['catchup'] = max(0, min(3, $d['catchup'])); $d['from'] = max(0, min(1380, $d['from'])); $d['to'] = max($d['from'] + 60, min(1440, $d['to'])); $d['lead_days'] = max(7, min(45, $d['lead_days']));
    return $d;
}
function wj_hm(int $m): string { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }
/** The central registry = the Autopilot's offer ladder (key => [label, rule]). */
function wj_reg(PDO $pdo): array { return function_exists('fa_offers') ? fa_offers($pdo) : []; }
function wj_icon(string $k): string { return strpos($k, 'w_') === 0 ? '💬' : '🎁'; }

/** The ready-made touch messages (no discount unless the rule says so) — [label, rule for Claude's {{3}}, the approved-message words]. */
function wj_touch_defaults(): array
{
    return [
        'w_miss' => ['We miss you', 'A warm, friendly check-in: ask what they are looking for. No pressure and no discount.', 'Hi {{1}} 🙏 It has been a while since you asked about {{2}}. {{3}} Reply here whenever you like and we will help you pick.'],
        'w_new' => ['New arrivals', 'Tell them about ONE new or trending perfume close to what they liked, in a few words. No discount.', 'Hi {{1}}! Since you liked {{2}}, we thought of you: {{3}} Reply here and we will send you the details 😊'],
        'w_proof' => ['Customers love it', 'Say that many customers love the perfume they asked about (long-lasting, compliments). No discount, no made-up numbers.', 'Hello {{1}}, so many customers love {{2}}. {{3}} Reply here if you would like to try it 🙂'],
        'w_fest' => ['Festival / season wish', 'A warm wish for the festival or season that is on now (if none: the month or weather) and one soft reminder of the perfume. Use the festival offer only if one is written in the festival box.', 'Hi {{1}} ✨ Warm wishes from TM Perfume House! A gentle thought of {{2}} for the season: {{3}} Reply here and we will keep it ready for you.'],
        'w_sample' => ['Try a small size', 'Suggest a small size or 10 ml to try first, with the price from the price chart.', 'Hi {{1}}, not sure about {{2}} yet? {{3}} Reply here and we will send a small size to try 😊'],
        'w_last' => ['Last note', 'The last gentle message of the journey: our best allowed offer from the price chart, valid for 3 days, no pressure.', 'Hi {{1}}, if you still like {{2}}, this is our last note for now 🙏 {{3}} Reply here and we will keep it for you.'],
        'w_tip' => ['How is it? (care tip)', 'Ask how they like the perfume they bought and give ONE tip to wear or store it so it lasts. No offer.', 'Hi {{1}} 😊 How are you enjoying {{2}}? {{3}} Reply here with any question, we are happy to help.'],
        'w_collect' => ['Complete the collection', 'Suggest ONE perfume that goes well with what they bought. No discount unless the rule of an offer says so.', 'Hello {{1}}! Since you love {{2}}, you may also like this: {{3}} Reply here and we will share more 🙂'],
        'w_reorder' => ['Time to reorder', 'Their perfume may be finishing: a friendly reorder reminder with a small thank-you from the owner\'s loyalty rule if one is taught.', 'Hi {{1}}, is your {{2}} getting over? {{3}} Reply here and we will arrange a quick reorder for you 🙏'],
        'w_vip' => ['Thank-you for staying with us', 'A sincere thank-you to a loyal customer with the best loyalty offer the owner allows (price chart / taught rules).', 'Hi {{1}} 💛 Thank you for being our valued customer! A little something for {{2}} or anything you love: {{3}} Reply here to use it.'],
    ];
}
/** The starting plan. Steps: id · day (1–60) · ref (a registry key) · idea (extra words for Claude) · topic ('' = everyone) · on. */
function wj_plan_default(): array
{
    $mk = function ($pfx, $rows) { $o = []; foreach ($rows as $r) $o[] = ['id' => $pfx . $r[0], 'day' => $r[0], 'ref' => $r[1], 'idea' => '', 'topic' => '', 'on' => true]; return $o; };
    return ['tracks' => [
        'lead' => ['title' => "Didn't order", 'steps' => $mk('l', [[12, 'w_miss'], [15, 'w_new'], [18, 'o1'], [22, 'w_proof'], [26, 'w_fest'], [30, 'o2'], [34, 'w_sample'], [38, 'w_new'], [42, 'o3'], [47, 'w_fest'], [52, 'w_proof'], [56, 'mega'], [60, 'w_last']])],
        'buyer' => ['title' => 'Ordered', 'steps' => $mk('b', [[10, 'w_tip'], [21, 'w_collect'], [35, 'w_fest'], [45, 'w_reorder'], [52, 'w_new'], [60, 'w_vip']])]]];
}
function wj_plan_clean(array $p, ?array $reg): array
{
    $out = ['tracks' => []];
    foreach (['lead' => "Didn't order", 'buyer' => 'Ordered'] as $tk => $title) {
        $steps = []; $seen = [];
        foreach ((array)($p['tracks'][$tk]['steps'] ?? []) as $s) {
            if (!is_array($s)) continue; $day = (int)($s['day'] ?? 0); if ($day < 1 || $day > ($tk === 'buyer' ? 365 : 60)) continue;
            $ref = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($s['ref'] ?? ''))); if ($ref === '' || ($reg !== null && !isset($reg[$ref]))) continue;
            $id = preg_replace('/[^a-z0-9]/i', '', (string)($s['id'] ?? '')); if ($id === '' || isset($seen[$id])) $id = 's' . substr(md5(uniqid('', true)), 0, 6); $id = substr($id, 0, 10); $seen[$id] = 1;
            $steps[] = ['id' => $id, 'day' => $day, 'ref' => $ref, 'idea' => mb_substr(trim((string)($s['idea'] ?? '')), 0, 300), 'topic' => substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string)($s['topic'] ?? ''))), 0, 24), 'on' => !array_key_exists('on', $s) || !empty($s['on'])];
        }
        usort($steps, function ($a, $b) { return $a['day'] <=> $b['day'] ?: strcmp($a['id'], $b['id']); });
        $out['tracks'][$tk] = ['title' => $title, 'steps' => array_slice($steps, 0, $tk === 'buyer' ? 150 : 30)];
    }
    return $out;
}
function wj_plan_get(PDO $pdo): array { $j = wj_json($pdo, 'wj_plan', []); return wj_plan_clean(empty($j['tracks']) ? wj_plan_default() : $j, null); }
/** Keep what is about to change (plan + registry + festival) so ↩ Undo can bring it back. */
function wj_hist_push(PDO $pdo, string $why, string $who = ''): void
{
    $h = wj_json($pdo, 'wj_hist', []); $h[] = ['at' => date('Y-m-d H:i:s'), 'why' => mb_substr($why, 0, 200), 'who' => $who, 'plan' => wj_plan_get($pdo), 'offers' => (string)setting_get($pdo, 'fa_offers', ''), 'season' => (string)setting_get($pdo, 'fa_season', '')];
    wj_put($pdo, 'wj_hist', array_slice($h, -12));
}
function wj_undo(PDO $pdo): array
{
    $h = wj_json($pdo, 'wj_hist', []); $e = array_pop($h); if (!$e) return [false, 'Nothing to undo.'];
    wj_put($pdo, 'wj_plan', $e['plan']); setting_set($pdo, 'fa_offers', (string)$e['offers']); setting_set($pdo, 'fa_season', (string)$e['season']); wj_put($pdo, 'wj_hist', $h);
    return [true, 'Undone ✓ — back to how it was before “' . (string)$e['why'] . '”.'];
}
/** First run: the ten ready-made touch messages join the central registry (their words are made at Meta by the Autopilot's background job). */
function wj_install(PDO $pdo): void
{
    if ((string)setting_get($pdo, 'wj_installed', '') !== '' || !function_exists('fa_offers')) return;
    $cur = fa_offers($pdo);
    foreach (wj_touch_defaults() as $k => $d) { if (!isset($cur[$k])) $cur[$k] = [$d[0], $d[1]]; if ((string)setting_get($pdo, 'fa_tplbody_' . $k, '') === '') setting_set($pdo, 'fa_tplbody_' . $k, $d[2]); }
    setting_set($pdo, 'fa_offers', json_encode(array_map(function ($x) { return [$x[0], $x[1]]; }, $cur), JSON_UNESCAPED_UNICODE));
    if ((string)setting_get($pdo, 'wj_plan', '') === '') wj_put($pdo, 'wj_plan', wj_plan_default());
    setting_set($pdo, 'wj_installed', date('Y-m-d H:i:s'));
}
function wj_tpl_state(PDO $pdo, string $k): string { return function_exists('fa_tpl_status') ? fa_tpl_status($pdo, $k) : 'none'; }

// ---------------------------------------------------------------------------------------------------------------------- festivals
/** [name, date, theme]. Dates are the usual ones — check the year's exact date for moving festivals; add or remove your own in the Festivals tab. */
function wj_fest_builtin(): array
{
    return [['Bhai Dooj', '2026-11-11', 'Bhai Dooj — a gift for a brother or sister'], ['Wedding season', '2026-11-20', 'Wedding season — perfumes for the bride, groom and guests'], ['Christmas', '2026-12-25', 'Christmas — gifts and party fragrances'],
        ['New Year', '2027-01-01', 'New Year — start the year with a new signature scent'], ['Makar Sankranti', '2027-01-14', 'Makar Sankranti / Uttarayan'], ['Republic Day', '2027-01-26', 'Republic Day'],
        ['Valentine\'s Day', '2027-02-14', 'Valentine\'s Day — a fragrance gift for someone you love'], ['Eid al-Fitr', '2027-03-10', 'Eid — attar and perfume gifting'], ['Holi', '2027-03-22', 'Holi'], ['Gudi Padwa / Ugadi', '2027-04-07', 'New Year festivals'],
        ['Mother\'s Day', '2027-05-09', 'Mother\'s Day — a gift for mom'], ['Eid al-Adha', '2027-05-17', 'Eid al-Adha'], ['Father\'s Day', '2027-06-20', 'Father\'s Day — a gift for dad'], ['Independence Day', '2027-08-15', 'Independence Day'],
        ['Raksha Bandhan', '2027-08-17', 'Raksha Bandhan — a gift for your sibling'], ['Ganesh Chaturthi', '2027-09-05', 'Ganesh Chaturthi'], ['Navratri', '2027-10-08', 'Navratri'], ['Dussehra', '2027-10-17', 'Dussehra'],
        ['Karwa Chauth', '2027-10-20', 'Karwa Chauth — a gift for your partner'], ['Diwali', '2027-10-29', 'Diwali — the gifting season'], ['New Year', '2028-01-01', 'New Year — start the year with a new signature scent']];
}
function wj_fest_all(PDO $pdo): array
{
    $o = []; foreach (wj_fest_builtin() as $f) $o[] = ['name' => $f[0], 'date' => $f[1], 'theme' => $f[2], 'custom' => false];
    foreach (wj_json($pdo, 'wj_fest_custom', []) as $f) if (is_array($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['date'] ?? '')) && trim((string)($f['name'] ?? '')) !== '') $o[] = ['name' => mb_substr(trim((string)$f['name']), 0, 40), 'date' => (string)$f['date'], 'theme' => mb_substr(trim((string)($f['theme'] ?? '')), 0, 160), 'custom' => true];
    usort($o, function ($a, $b) { return strcmp($a['date'], $b['date']); }); return $o;
}
function wj_fest_upcoming(PDO $pdo, int $n = 8): array
{
    $t = strtotime(date('Y-m-d')); $o = [];
    foreach (wj_fest_all($pdo) as $f) { $d = (int)round((strtotime($f['date']) - $t) / 86400); if ($d >= -1) { $f['days_left'] = $d; $o[] = $f; } if (count($o) >= $n) break; }
    return $o;
}
/** At night: a festival within the lead time that the owner has not answered yet becomes ONE suggestion card. */
function wj_fest_check(PDO $pdo): void
{
    $S = wj_json($pdo, 'wj_fest_sug', []); $done = wj_json($pdo, 'wj_fest_done', []); $cfg = wj_cfg($pdo);
    if (!empty($S['name'])) { if (strtotime((string)$S['date']) < strtotime(date('Y-m-d')) - 86400) { wj_put($pdo, 'wj_fest_sug', []); $S = []; } else return; }
    $snooze = (string)setting_get($pdo, 'wj_fest_snooze', ''); if ($snooze !== '' && $snooze > date('Y-m-d')) return;
    $cur = function_exists('fa_season_text') ? mb_strtolower(fa_season_text($pdo)) : '';
    foreach (wj_fest_upcoming($pdo, 6) as $f) {
        $key = $f['name'] . '|' . $f['date']; if (in_array($key, $done, true) || $f['days_left'] > $cfg['lead_days']) continue;
        if ($cur !== '' && mb_strpos($cur, mb_strtolower($f['name'])) !== false) continue;
        $from = date('Y-m-d', max(time(), strtotime($f['date']) - 14 * 86400)); $to = date('Y-m-d', strtotime($f['date']) + 2 * 86400);
        wj_put($pdo, 'wj_fest_sug', ['name' => $f['name'], 'date' => $f['date'], 'days_left' => $f['days_left'], 'text' => $f['theme'], 'from' => $from, 'to' => $to, 'at' => date('Y-m-d H:i:s')]); return;
    }
}

// ---------------------------------------------------------------------------------------------------------------------- topics (what customers talk about) — learned every night
function wj_kw_topic(string $text): string
{
    $t = mb_strtolower($text);
    $map = ['price' => '/price|costly|expensive|mehang|kitne ka|kitna|discount|rate\b|budget|₹/u', 'longlasting' => '/long ?last|tikta|tikt|stay|projection|kitni der|lasting/u', 'gifting' => '/gift|birthday|anniversary|wedding|shaadi|surprise|bhai|wife|husband/u',
        'sample' => '/sample|trial|small size|10 ?ml|decant|try first/u', 'delivery' => '/deliver|courier|kab tak|shipping|\bcod\b|cash on|tracking/u', 'authentic' => '/original|authentic|fake|genuine|real\b|duplicate/u', 'thinking' => '/ghar (pe|par|mein)|family|pooch|puch(ta|ti|unga|ungi)|soch|sochna|baad mein|later|think|ask my|wife se|husband se|mummy|papa se|decide/u',
        'recommend' => '/suggest|recommend|which|konsa|kaunsa|options|best/u', 'buying' => '/order|buy|book|payment|\bpay\b|upi|address/u'];
    foreach ($map as $k => $re) if (preg_match($re, $t)) return $k;
    return 'general';
}
function wj_topic_titles(): array { return ['price' => 'Asks the price / wants a discount', 'longlasting' => 'Asks how long it lasts', 'gifting' => 'Looking for a gift', 'sample' => 'Wants a sample or small size', 'delivery' => 'Delivery / COD questions', 'authentic' => 'Asks if it is original', 'thinking' => 'Needs time / asking family', 'recommend' => 'Wants a suggestion', 'buying' => 'Ready to buy', 'general' => 'General enquiry']; }
/** Read one batch of chats → each chat gets a topic, the perfume it liked and the one angle that would win it. Claude when available, plain words otherwise. */
function wj_classify_batch(PDO $pdo, array $phones): int
{
    if (!$phones) return 0; $items = []; $T = wj_json($pdo, 'wj_topics', ['topics' => []]); $known = array_keys((array)$T['topics']);
    foreach ($phones as $i => $ph) { $m = function_exists('wa_learn_msgs') ? wa_learn_msgs($pdo, (string)$ph, 10) : []; $s = ''; $cust = ''; foreach ($m as $x) { $s .= ($x['who'] === 'customer' ? 'C: ' : 'S: ') . mb_substr((string)$x['text'], 0, 160) . "\n"; if ($x['who'] === 'customer') $cust .= ' ' . $x['text']; } $items[] = ['i' => $i, 'ph' => (string)$ph, 'chat' => $s, 'cust' => $cust]; }
    $res = [];
    if (function_exists('convo_ai')) {
        $txt = ''; foreach ($items as $it) $txt .= "### CHAT " . $it['i'] . "\n" . mb_substr($it['chat'], 0, 1100) . "\n";
        $sch = ['type' => 'OBJECT', 'properties' => ['chats' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['i' => ['type' => 'INTEGER'], 'topic' => ['type' => 'STRING'], 'title' => ['type' => 'STRING'], 'perfume' => ['type' => 'STRING'], 'hook' => ['type' => 'STRING']], 'required' => ['i', 'topic', 'title', 'perfume', 'hook']]]], 'required' => ['chats']];
        $sys = "You read WhatsApp chats of TM Perfume House (Indian perfume brand; Hindi / Gujarati / English). For EACH chat give: topic = a short stable key (lowercase letters/underscore, max 20) for what this customer is mainly about "
            . "(reuse one of these keys when it fits: " . implode(', ', array_merge($known, array_keys(wj_topic_titles()))) . "; make a new key only when none fits); title = the topic in 3–6 plain words; perfume = the perfume (and size) they liked or asked about, max 40 letters, '' if none; "
            . "hook = ONE short angle (max 120 letters, no price, no discount number) that would win this customer back later, in English.";
        try { [$ok, $v] = convo_ai($sys, [['text' => $txt]], $sch, false, 3500, 'claude'); } catch (Throwable $e) { $ok = false; $v = null; }
        if ($ok && is_array($v)) foreach ((array)($v['chats'] ?? []) as $r) if (is_array($r) && isset($items[(int)($r['i'] ?? -1)])) $res[(int)$r['i']] = $r;
    }
    $day = date('Y-m-d'); $n = 0; $titles = wj_topic_titles();
    $up = $pdo->prepare("INSERT INTO wa_wj_topic (phone, topic, perfume, hook, at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE topic = VALUES(topic), perfume = VALUES(perfume), hook = VALUES(hook), at = VALUES(at)");
    foreach ($items as $it) {
        $r = $res[$it['i']] ?? null; $k = $r ? substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string)$r['topic'])), 0, 24) : ''; if ($k === '') $k = wj_kw_topic($it['cust']);
        $title = $r && trim((string)$r['title']) !== '' ? mb_substr(trim((string)$r['title']), 0, 60) : ($titles[$k] ?? ucfirst(str_replace('_', ' ', $k)));
        $perf = $r ? mb_substr(trim((string)$r['perfume']), 0, 60) : ''; $hook = $r ? mb_substr(trim((string)$r['hook']), 0, 200) : '';
        try { $up->execute([$it['ph'], $k, $perf, $hook, date('Y-m-d H:i:s')]); } catch (Throwable $e) { continue; }
        if (!isset($T['topics'][$k])) $T['topics'][$k] = ['title' => $title, 'days' => [], 'ex' => []];
        if (!empty($r) && trim((string)$r['title']) !== '') $T['topics'][$k]['title'] = $title;
        $T['topics'][$k]['days'][$day] = (int)($T['topics'][$k]['days'][$day] ?? 0) + 1;
        $q = trim(preg_replace('/\s+/u', ' ', mb_substr($it['cust'], 0, 140))); if ($q !== '' && count($T['topics'][$k]['ex']) < 4 && !in_array($q, array_column($T['topics'][$k]['ex'], 'q'), true)) $T['topics'][$k]['ex'][] = ['q' => $q, 'p' => $perf, 'h' => $hook];
        $n++;
    }
    $cut = date('Y-m-d', time() - 90 * 86400); foreach ($T['topics'] as $k => $x) { foreach ($x['days'] as $d => $c) if ($d < $cut) unset($T['topics'][$k]['days'][$d]); }
    $T['updated'] = date('Y-m-d H:i:s'); wj_put($pdo, 'wj_topics', $T); return $n;
}
function wj_topics_view(PDO $pdo): array
{
    $T = wj_json($pdo, 'wj_topics', ['topics' => []]); $d7 = date('Y-m-d', time() - 7 * 86400); $d30 = date('Y-m-d', time() - 30 * 86400); $rows = []; $tot = 0;
    foreach ((array)$T['topics'] as $k => $x) { $n7 = 0; $n30 = 0; foreach ((array)$x['days'] as $d => $c) { if ($d >= $d7) $n7 += (int)$c; if ($d >= $d30) $n30 += (int)$c; } $tot += $n30; $rows[] = ['key' => $k, 'title' => (string)$x['title'], 'n7' => $n7, 'n30' => $n30, 'ex' => array_slice((array)$x['ex'], 0, 3)]; }
    usort($rows, function ($a, $b) { return $b['n30'] <=> $a['n30']; });
    foreach ($rows as &$r) $r['share'] = $tot ? (int)round($r['n30'] * 100 / $tot) : 0; unset($r);
    return ['rows' => array_slice($rows, 0, 14), 'total30' => $tot, 'updated' => (string)($T['updated'] ?? '')];
}
/** The night's work, one small step per background tick: 1) read the day's chats in batches → topics, 2) festival watch, 3) Claude reviews the plan and leaves a proposal. */
function wj_night_start(PDO $pdo, bool $manual = false): array
{
    $cur = (string)setting_get($pdo, 'wj_topic_cursor', ''); $since = $manual ? wj_dt(time() - 2 * 86400) : ($cur !== '' ? $cur : wj_dt(time() - 7 * 86400));
    $ph = []; try { $q = $pdo->prepare("SELECT phone FROM wa_contacts WHERE last_in_at >= ? AND optout = 0 ORDER BY last_in_at DESC LIMIT 90"); $q->execute([$since]); $ph = $q->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { }
    $S = ['day' => date('Y-m-d'), 'stage' => $ph ? 'topics' : 'fest', 'queue' => array_values($ph), 'total' => count($ph), 'started' => date('Y-m-d H:i:s'), 'note' => ''];
    if (!$manual) setting_set($pdo, 'wj_topic_cursor', date('Y-m-d H:i:s')); wj_put($pdo, 'wj_night', $S); return $S;
}
/** 🌙 What Claude suggests at night is put in place by itself — but ONLY additions (a new message / offer, a new day). Nothing existing is changed, turned off or deleted.
 *  It is one history entry (↩ Undo in the Journey) and one line in the daily update. Returns the lines that were added. */
function wj_night_autoapply(PDO $pdo, array $prop): array
{
    $reg = wj_reg($pdo); $add = []; $left = [];
    foreach ((array)($prop['ops'] ?? []) as $o) {
        if (!is_array($o)) continue; $op = (string)($o['op'] ?? '');
        if (($op === 'offer_set' && !isset($reg[(string)($o['key'] ?? '')])) || ($op === 'step_set' && (string)($o['id'] ?? '') === '')) $add[] = $o; else $left[] = $o;
    }
    $lines = [];
    if ($add) {
        [$lines, $cl] = wj_ops_clean($pdo, $add);
        if ($cl) { [$ok] = wj_ops_apply($pdo, $cl, '🌙 Claude added these overnight'); if (!$ok) $lines = []; }
    }
    $prop['ops'] = $left; $prop['changes'] = []; wj_put($pdo, 'wj_proposal', $left ? $prop : []);
    return [$lines, count($left)];
}
function wj_night_step(PDO $pdo, bool $force = false): array
{
    $S = wj_json($pdo, 'wj_night', []);
    if (!$S || ($S['day'] ?? '') !== date('Y-m-d')) { if (!$force && (int)date('G') < 2) return ['idle' => 1]; $S = wj_night_start($pdo); }
    $stage = (string)($S['stage'] ?? 'done'); if ($stage === 'done') return ['done' => 1];
    if ($stage === 'topics') {
        $batch = array_splice($S['queue'], 0, 6); try { wj_classify_batch($pdo, $batch); } catch (Throwable $e) { error_log('wj_classify: ' . $e->getMessage()); }
        if (!$S['queue']) $S['stage'] = 'fest'; wj_put($pdo, 'wj_night', $S); return ['topics' => count($batch), 'left' => count($S['queue'])];
    }
    if ($stage === 'fest') { try { wj_fest_check($pdo); } catch (Throwable $e) { } $S['stage'] = 'suggest'; wj_put($pdo, 'wj_night', $S); return ['fest' => 1]; }
    if ($stage === 'suggest') {
        $S['stage'] = 'done'; wj_put($pdo, 'wj_night', $S);   // marked first: a slow / failing Claude call never repeats all night
        $P = wj_json($pdo, 'wj_proposal', []);
        if (function_exists('convo_ai') && (empty($P['ops']) || ($P['source'] ?? '') === 'night')) {
            [$ok, $r] = wj_brain($pdo, "NIGHTLY REVIEW. Read the TOPICS (what customers talked about), the RESULTS of each step, the festival calendar and the plan. Suggest at most 6 careful improvements: a new or changed message for a topic that is growing, moving or turning off a step "
                . "that many customers got and nobody answered, a festival touch coming up. Keep it small. If nothing is worth changing, return no ops and say so in 'understood'.", false, 'night');
            $lines = []; $kept = 0; if ($ok && !empty($r['ops'])) { try { [$lines, $kept] = wj_night_autoapply($pdo, $r); } catch (Throwable $e) { error_log('wj_autoapply: ' . $e->getMessage()); } }
            $S['note'] = $ok ? (empty($r['ops']) ? 'Reviewed — no change needed.' : ($lines ? count($lines) . ' addition(s) put in place.' : 'New suggestion ready.')) : 'Review skipped: ' . mb_substr((string)$r, 0, 80); wj_put($pdo, 'wj_night', $S);
            wj_put($pdo, 'wj_daily', ['at' => date('Y-m-d H:i:s'), 'understood' => $ok ? mb_substr((string)($r['understood'] ?? ''), 0, 300) : '', 'lines' => array_slice($lines, 0, 12), 'kept' => $kept, 'note' => $S['note']]);
        }
        return ['suggest' => 1];
    }
    return [];
}

// ---------------------------------------------------------------------------------------------------------------------- results
function wj_results(PDO $pdo, int $days): array
{
    $ck = wj_json($pdo, 'wj_res_cache', []); if (($ck['days'] ?? 0) === $days && ($ck['t'] ?? 0) > time() - 600) return $ck['data'];
    $since = wj_dt(time() - $days * 86400); $S = []; $tot = ['sent' => 0, 'replied' => 0, 'ordered' => 0, 'sales' => 0.0]; $rows = [];
    try {
        $q = $pdo->prepare("SELECT e.id, e.phone, e.track, e.step_id, e.sent_at, EXISTS(SELECT 1 FROM wa_messages m WHERE m.phone = e.phone AND m.dir = 'in' AND m.ts > e.sent_at AND m.ts <= DATE_ADD(e.sent_at, INTERVAL 2 DAY)) AS rep FROM wa_wj_ev e WHERE e.status = 'sent' AND e.sent_at >= ? ORDER BY e.sent_at LIMIT 20000");
        $q->execute([$since]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { }
    $byPhone = []; foreach ($rows as $r) { $k = $r['track'] . '|' . $r['step_id']; if (!isset($S[$k])) $S[$k] = ['track' => $r['track'], 'step' => $r['step_id'], 'sent' => 0, 'replied' => 0, 'ordered' => 0, 'sales' => 0.0]; $S[$k]['sent']++; $tot['sent']++; if ((int)$r['rep']) { $S[$k]['replied']++; $tot['replied']++; } $byPhone[(string)$r['phone']][] = $r; }
    foreach (array_chunk(array_keys($byPhone), 400) as $chunk) {
        try { $in = implode(',', array_fill(0, count($chunk), '?')); $q = $pdo->prepare("SELECT RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) p, created_at, total FROM shop_orders WHERE created_at >= ? AND RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) IN ($in)"); $q->execute(array_merge([$since], $chunk));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) { $ot = strtotime((string)$o['created_at']); $last = null; foreach ($byPhone[(string)$o['p']] ?? [] as $r) { $st = strtotime((string)$r['sent_at']); if ($st <= $ot && $st >= $ot - 7 * 86400) $last = $r; }
                if ($last) { $k = $last['track'] . '|' . $last['step_id']; $S[$k]['ordered']++; $S[$k]['sales'] += (float)$o['total']; $tot['ordered']++; $tot['sales'] += (float)$o['total']; } } } catch (Throwable $e) { }
    }
    $d = ['days' => $days, 'total' => $tot, 'steps' => array_values($S)]; wj_put($pdo, 'wj_res_cache', ['days' => $days, 't' => time(), 'data' => $d]); return $d;
}
function wj_stats(PDO $pdo): array
{
    $c = wj_json($pdo, 'wj_stats', []); if (($c['t'] ?? 0) > time() - 600) return $c;
    $now = time(); $r = ['t' => $now, 'lead' => 0, 'buyer' => 0, 'joined' => 0, 'leaving' => 0, 'sent_today' => 0, 'sent_7' => 0];
    try {
        $r['lead'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts c WHERE c.optout = 0 AND c.last_in_at >= '" . wj_dt($now - 60 * 86400) . "' AND NOT EXISTS (SELECT 1 FROM wa_cust u WHERE u.phone = c.phone AND u.n > 0 AND u.last_order >= DATE_SUB(c.last_in_at, INTERVAL 2 DAY))")->fetchColumn();
        $r['buyer'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_cust u JOIN wa_contacts c ON c.phone = u.phone WHERE u.n > 0 AND c.optout = 0 AND u.last_order >= '" . wj_dt($now - 60 * 86400) . "'")->fetchColumn();
        $r['joined'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts c WHERE c.optout = 0 AND c.last_in_at >= '" . wj_dt($now - 86400) . "'")->fetchColumn();
        $r['leaving'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts c WHERE c.optout = 0 AND c.last_in_at < '" . wj_dt($now - 60 * 86400) . "' AND c.last_in_at >= '" . wj_dt($now - 61 * 86400) . "'")->fetchColumn();
        $r['sent_today'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_wj_ev WHERE status = 'sent' AND sent_at >= '" . date('Y-m-d 00:00:00') . "'")->fetchColumn();
        $r['sent_7'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_wj_ev WHERE status = 'sent' AND sent_at >= '" . wj_dt($now - 7 * 86400) . "'")->fetchColumn();
    } catch (Throwable $e) { }
    wj_put($pdo, 'wj_stats', $r); return $r;
}

// ---------------------------------------------------------------------------------------------------------------------- who gets what today
/** Why this customer must not get a journey message now ('' = fine). Cheap checks only. */
function wj_blocked(PDO $pdo, array $c, int $now): string
{
    $k = (string)$c['phone'];
    if (function_exists('wa_auto_muted') && wa_auto_muted($pdo, $k)) return 'order completed today';
    if (function_exists('wq_plan_paused')) { $pz = wq_plan_paused($pdo, $k, $now); if ($pz !== '') return $pz; }
    if ((string)($c['tag'] ?? '') === 'complaint') return 'complaint chat';   // a complaint is never sold to
    try { $q = $pdo->prepare("SELECT 1 FROM wq_rescue WHERE phone = ? AND status = 'skipped' AND (ai_state = 'auto' OR (ai_state = 'agreed' AND skip_reason <> 'price') OR (skip_reason = 'other' AND ai_state = 'final')) LIMIT 1"); $q->execute([$k]); if ($q->fetchColumn()) return 'not a prospect (wrong number / spam / complaint — marked in Rescue)'; } catch (Throwable $e) { }
    try { if (function_exists('wr_blocked_open_case') && wr_blocked_open_case($pdo, $k, '')) return 'open complaint'; } catch (Throwable $e) { }
    try { $q = $pdo->prepare("SELECT 1 FROM wa_flow_runs WHERE phone = ? AND state IN ('run', 'wait', 'ready') LIMIT 1"); $q->execute([$k]); if ($q->fetchColumn()) return 'a flow is running'; } catch (Throwable $e) { }
    return '';
}
/** ⏱ The Journey's clock for a customer who did not order = OUR reply to their last message (bot or person), not their message itself.
 *  Kept in wa_contacts.wj_anchor (for the last_in_at it was worked out for, wj_anchor_in). A campaign, an order message or the Journey's own messages never move it. */
function wj_anchor_ready(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->query("SELECT wj_anchor, wj_anchor_in FROM wa_contacts LIMIT 1")->fetchAll(); return $ok = true; } catch (Throwable $e) { return $ok = false; }
}
function wj_anchor_refresh(PDO $pdo, int $max = 600): int
{
    if (!wj_anchor_ready($pdo)) return 0; $n = 0;
    try {
        $rows = $pdo->query("SELECT phone, last_in_at FROM wa_contacts WHERE optout = 0 AND last_in_at >= DATE_SUB(NOW(), INTERVAL 62 DAY) AND last_out_at IS NOT NULL AND last_out_at >= last_in_at AND (wj_anchor_in IS NULL OR wj_anchor_in <> last_in_at) ORDER BY last_in_at DESC LIMIT " . (int)$max)->fetchAll(PDO::FETCH_ASSOC);
        $up = $pdo->prepare("UPDATE wa_contacts SET wj_anchor = ?, wj_anchor_in = ? WHERE phone = ?");
        foreach (array_chunk($rows, 200) as $ch) {
            $ph = array_map(function ($r) { return (string)$r['phone']; }, $ch); $min = min(array_map(function ($r) { return (string)$r['last_in_at']; }, $ch)); $in = implode(',', array_fill(0, count($ph), '?')); $first = [];
            $q = $pdo->prepare("SELECT phone, ts FROM wa_messages WHERE dir = 'out' AND status <> 'failed' AND who NOT IN ('campaign','order','followup','flow') AND ts >= ? AND phone IN ($in) ORDER BY ts"); $q->execute(array_merge([$min], $ph));
            $li = []; foreach ($ch as $r) $li[(string)$r['phone']] = (string)$r['last_in_at'];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) { $p = (string)$m['phone']; if (!isset($first[$p]) && (string)$m['ts'] >= $li[$p]) $first[$p] = (string)$m['ts']; }
            foreach ($ch as $r) { $p = (string)$r['phone']; if (isset($first[$p])) { $up->execute([$first[$p], (string)$r['last_in_at'], $p]); $n++; } }
        }
    } catch (Throwable $e) { error_log('wj_anchor_refresh: ' . $e->getMessage()); }
    return $n;
}
/** Everyone whose day has come: [{c, track, anchor, age, step, others[], topic}] — the same list feeds the preview and the sender. */
function wj_collect(PDO $pdo, array $plan, array $cfg, int $now, int $limit = 400): array
{
    $out = []; $autoSince = (int)setting_get($pdo, 'wj_auto_since', '0'); $reg = wj_reg($pdo); if (function_exists('wa_cust_ready')) { try { wa_cust_ready($pdo); } catch (Throwable $e) { } }
    foreach (['lead', 'buyer'] as $tk) {
        if ($tk === 'lead' && jn_unified($pdo)) continue;   // one engine: the follow-up engine runs the didn't-order flow
        $steps = array_values(array_filter($plan['tracks'][$tk]['steps'], function ($s) use ($reg) { return !empty($s['on']) && isset($reg[$s['ref']]); })); if (!$steps) continue;
        $aex = wj_anchor_ready($pdo) ? 'IF(c.wj_anchor_in = c.last_in_at AND c.wj_anchor IS NOT NULL, c.wj_anchor, c.last_in_at)' : 'c.last_in_at';   // our reply to their last message
        $col = $tk === 'lead' ? $aex : 'u.last_order'; $conds = []; $args = [];
        foreach (array_unique(array_column($steps, 'day')) as $d) { $conds[] = "($col <= ? AND $col > ?)"; $args[] = wj_dt($now - $d * 86400); $args[] = wj_dt($now - ($d + $cfg['grace'] + 1) * 86400); }
        $sql = $tk === 'lead'
            ? "SELECT c.*, $aex AS anchor_at FROM wa_contacts c WHERE c.optout = 0 AND c.last_in_at IS NOT NULL AND (" . implode(' OR ', $conds) . ") AND NOT EXISTS (SELECT 1 FROM wa_cust u WHERE u.phone = c.phone AND u.n > 0 AND (u.last_order >= DATE_SUB(c.last_in_at, INTERVAL 2 DAY) OR u.last_order >= DATE_SUB(NOW(), INTERVAL 7 DAY))) ORDER BY c.last_in_at LIMIT " . (int)$limit
            : "SELECT c.*, u.last_order AS anchor_at FROM wa_cust u JOIN wa_contacts c ON c.phone = u.phone WHERE u.n > 0 AND c.optout = 0 AND (" . implode(' OR ', $conds) . ") AND (c.last_in_at IS NULL OR u.last_order >= DATE_SUB(c.last_in_at, INTERVAL 2 DAY)) ORDER BY u.last_order LIMIT " . (int)$limit;
        try { $q = $pdo->prepare($sql); $q->execute($args); $rows = $q->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { error_log('wj_collect ' . $tk . ': ' . $e->getMessage()); continue; }
        foreach (array_chunk($rows, 300) as $chunk) {
            $ph = array_map(function ($r) { return (string)$r['phone']; }, $chunk); $in = implode(',', array_fill(0, count($ph), '?')); $done = []; $last = []; $top = []; $refSent = []; $offerAt = []; if (function_exists('wq_plan_paused_prefetch')) wq_plan_paused_prefetch($pdo, $ph, $now);
            try { $q = $pdo->prepare("SELECT phone, track, step_id, anchor FROM wa_wj_ev WHERE phone IN ($in)"); $q->execute($ph); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $e) $done[$e['phone'] . '|' . $e['track'] . '|' . $e['step_id'] . '|' . $e['anchor']] = 1;
                $q = $pdo->prepare("SELECT phone, MAX(sent_at) m FROM wa_wj_ev WHERE status = 'sent' AND phone IN ($in) GROUP BY phone"); $q->execute($ph); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $e) $last[$e['phone']] = strtotime((string)$e['m']);
                $q = $pdo->prepare("SELECT phone, ref, MAX(sent_at) m FROM wa_wj_ev WHERE status = 'sent' AND sent_at >= ? AND phone IN ($in) GROUP BY phone, ref"); $q->execute(array_merge([wj_dt($now - 60 * 86400)], $ph));
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $e) { $t0 = strtotime((string)$e['m']); $refSent[$e['phone'] . '|' . $e['ref']] = $t0; if (strpos((string)$e['ref'], 'w_') !== 0 && $t0 > ($offerAt[$e['phone']] ?? 0)) $offerAt[$e['phone']] = $t0; }
                $q = $pdo->prepare("SELECT phone, topic, perfume, hook FROM wa_wj_topic WHERE phone IN ($in)"); $q->execute($ph); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $e) $top[$e['phone']] = $e; } catch (Throwable $e) { }
            $segOf = []; if ($tk === 'buyer') { try { $cuts = jn_cuts($pdo); $sq = $pdo->prepare("SELECT phone, n, spent FROM wa_cust WHERE phone IN ($in)"); $sq->execute($ph); foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $e) $segOf[(string)$e['phone']] = jn_seg_of((int)$e['n'], (float)$e['spent'], $cuts); } catch (Throwable $e) { } }
            foreach ($chunk as $c) {
                $ph0 = (string)$c['phone']; $a = strtotime((string)$c['anchor_at']); if (!$a) continue; $age = (int)floor(($now - $a) / 86400); $ak = date('Ymd', $a); $due = []; $topic = $top[$ph0] ?? null;
                if ($tk === 'buyer') $topic = array_merge($topic ?: ['perfume' => '', 'hook' => ''], ['topic' => $segOf[$ph0] ?? '']);   // the customer's type
                foreach ($steps as $s) {
                    if ($age < $s['day'] || $age - $s['day'] > $cfg['grace'] || isset($done[$ph0 . '|' . $tk . '|' . $s['id'] . '|' . $ak])) continue;
                    if ($autoSince && $a + $s['day'] * 86400 < $autoSince - $cfg['catchup'] * 86400) continue;   // a day that came before Auto was switched on is not caught up
                    if ($tk === 'buyer') { if (!jn_seg_match((string)$s['topic'], (string)($topic['topic'] ?? ''))) continue; }
                    elseif ($s['topic'] !== '' && (!$topic || $topic['topic'] !== $s['topic'])) continue;
                    $due[] = $s;
                }
                if (!$due) continue;
                usort($due, function ($x, $y) { return ($y['topic'] !== '') <=> ($x['topic'] !== '') ?: $y['day'] <=> $x['day']; });
                $out[] = ['c' => $c, 'track' => $tk, 'anchor' => date('Y-m-d H:i:s', $a), 'ak' => $ak, 'age' => $age, 'step' => $due[0], 'others' => array_slice($due, 1), 'topic' => $topic];
            }
        }
    }
    usort($out, function ($x, $y) { return strcmp($x['anchor'], $y['anchor']); }); return array_slice($out, 0, $limit);
}
/** Preview: who would get which message now (nothing is sent). */
function wj_queue(PDO $pdo): array
{
    $plan = wj_plan_get($pdo); $cfg = wj_cfg($pdo); $now = time(); $reg = wj_reg($pdo); $list = wj_collect($pdo, $plan, $cfg, $now, 400); $rows = []; $by = []; $blocked = 0; $wait = 0;
    foreach ($list as $x) {
        $why = wj_blocked($pdo, $x['c'], $now); if ($why !== '') { $blocked++; continue; }
        $ref = $x['step']['ref']; $ts = wj_tpl_state($pdo, $ref); if (!in_array($ts, ['approved', 'approved_old'], true)) $wait++;
        $k = $x['track'] . '|' . $x['step']['id']; $by[$k] = ($by[$k] ?? 0) + 1;
        if (count($rows) < 60) $rows[] = ['name' => function_exists('fu_first_name') ? (fu_first_name((string)$x['c']['name']) ?: 'Customer') : 'Customer', 'tail' => substr((string)$x['c']['phone'], -4), 'track' => $x['track'], 'day' => $x['step']['day'], 'age' => $x['age'], 'ref' => $ref, 'label' => $reg[$ref][0] ?? $ref, 'topic' => (string)($x['topic']['topic'] ?? ''), 'tpl' => $ts];
    }
    return ['total' => count($list) - $blocked, 'blocked' => $blocked, 'waiting_meta' => $wait, 'rows' => $rows, 'by' => $by, 'mode' => wj_mode($pdo)];
}

// ---------------------------------------------------------------------------------------------------------------------- sending
/** The two blanks of the message ({{2}} perfume, {{3}} the line) for THIS customer — Claude reads the chat + the registry rule + the step's idea + the festival; numbers are checked against the owner's price chart. */
function wj_fill(PDO $pdo, array $c, array $x): array
{
    $step = $x['step']; $ref = $step['ref']; $reg = wj_reg($pdo); $topic = $x['topic']; $perf = trim((string)($topic['perfume'] ?? '')); $line = '';
    $rule = isset($reg[$ref]) ? $reg[$ref][0] . ' — ' . $reg[$ref][1] : 'a gentle message'; $season = function_exists('fa_season_text') ? fa_season_text($pdo) : '';
    $msgs = function_exists('fu_conv') ? fu_conv($pdo, (string)$c['phone']) : []; $facts = ''; $allowed = [];
    if (function_exists('wk_knowledge')) { $K = wk_knowledge($pdo, $msgs, $rule . ' ' . (string)($step['idea'] ?? '')); $facts = $K['text']; $allowed = $K['allowed']; }   // 🧠 the same knowledge as every other writer
    if (function_exists('convo_ai') && $msgs) {
        $conv = ''; foreach (array_slice($msgs, -14) as $m) $conv .= ($m['who'] === 'customer' ? 'Customer: ' : 'Shop: ') . $m['text'] . "\n";
        $sch = ['type' => 'OBJECT', 'properties' => ['perfume' => ['type' => 'STRING'], 'line' => ['type' => 'STRING']], 'required' => ['perfume', 'line']];
        $sys = "You fill two blanks of an approved WhatsApp message of TM Perfume House (Indian perfume brand) for ONE customer. perfume = the perfume (and size) THIS customer asked about, liked most" . ($x['track'] === 'buyer' ? ' or bought' : '')
            . ", as they would recognise it, max 40 letters (if unclear: 'your favourite perfume'). line = ONE short phrase for {{3}}, max 90 letters, no new lines, following the MESSAGE RULE exactly, in the customer's language style (simple Hinglish / English), with a real price ONLY from the STORE FACTS when a price is part of the rule. "
            . "Never invent a number, never go below the owner's lowest price, never mention percentages, never write a greeting or the customer's name (those are already in the message)." . ($season !== '' ? " The festival / season now: " . $season . ' — use it when the rule allows.' : '');
        $rep = ''; try { $rq = $pdo->prepare("SELECT n FROM wa_cust WHERE phone = ?"); $rq->execute([(string)$c['phone']]); if ((int)$rq->fetchColumn() > 0 && $x['track'] === 'lead') $rep = "THIS IS A REPEAT CUSTOMER (they ordered from us before and now asked again): welcome them back warmly, no first-time introductions.\n"; } catch (Throwable $e) { }
        $ask = $rep . ($facts !== '' ? $facts . "\n" : '') . "MESSAGE RULE: $rule\n" . ($step['idea'] !== '' ? "OWNER'S IDEA FOR THIS DAY: " . $step['idea'] . "\n" : '') . ($topic && $topic['hook'] !== '' ? "WHAT WOULD WIN THIS CUSTOMER (read from their chat): " . $topic['hook'] . "\n" : '')
            . "THIS IS DAY " . $step['day'] . " of the " . ($x['track'] === 'buyer' ? 'customer who ordered (counted from the order)' : 'customer who did not order (counted from their last message)') . ".\nCONVERSATION:\n$conv\nFill the blanks.";
        for ($i = 0; $i < 2; $i++) {
            try { [$ok, $v] = convo_ai($sys, [['text' => $ask]], $sch, false, 400, 'claude'); } catch (Throwable $e) { $ok = false; $v = null; } if (!$ok || !is_array($v)) break;
            $p = trim(preg_replace('/\s+/u', ' ', mb_substr((string)($v['perfume'] ?? ''), 0, 40))); $l = trim(preg_replace('/\s+/u', ' ', mb_substr((string)($v['line'] ?? ''), 0, 90))); $why = [];
            if ($p !== '' && $l !== '' && (!function_exists('fu_text_ok') || fu_text_ok($pdo, $p . ' ' . $l, $allowed, $why))) { $perf = $p; $line = $l; break; }
        }
    }
    if ($perf === '') $perf = 'your favourite perfume'; if ($line === '') $line = 'we have something nice picked for you';
    return [$perf, $line];
}
/** Send one journey message (the approved Meta message of the step's registry entry). [ok, shown text | reason, retry-later flag]. */
function wj_send(PDO $pdo, array $c, array $x): array
{
    $ref = $x['step']['ref']; $t = function_exists('fa_tpl_pick') ? fa_tpl_pick($pdo, $ref) : null; if (!$t) return [false, 'The message is not approved by Meta yet.', 'wait'];
    [$perf, $line] = wj_fill($pdo, $c, $x); $name = function_exists('fu_first_name') ? fu_first_name((string)($c['name'] ?? '')) : ''; if ($name === '') $name = 'there';
    $ctx = wa_camp_ctx($pdo, $t, 2); [$nid, $why] = wa_pick_number($pdo, $c, $ctx); if (!$nid) return [false, 'No number has room now (' . $why . ').', 'nonumber'];
    $vars = array_slice([$name, $perf, $line], 0, (int)$t['nvars']); [$ok, $res] = wa_send_tpl_one($pdo, $c, $t, $ctx['nums'][$nid], $vars, 'followup');
    $shown = function_exists('cx6_render') ? cx6_render((string)$t['body'], $vars) : (string)$t['body']; if (!function_exists('cx6_render')) foreach ($vars as $i => $v) $shown = str_replace('{{' . ($i + 1) . '}}', $v, $shown);
    if (!$ok) { if (function_exists('wa_log')) wa_log($pdo, 'followup', false, $c['phone'] . ' · journey ' . $ref . ' · ' . $res); return [false, (string)$res, '']; }
    return [true, $shown, ''];
}
function wj_run(PDO $pdo): array
{
    $cfg = wj_cfg($pdo); $now = time(); $o = ['sent' => 0, 'failed' => 0, 'waiting' => 0, 'blocked' => 0, 'deferred' => 0];
    $L = function_exists('fu_limits') ? fu_limits($pdo) : null; if ($L && function_exists('fu_in_quiet') && fu_in_quiet($L, $now)) return $o + ['quiet' => 1];
    $m = (int)date('G', $now) * 60 + (int)date('i', $now); if ($m < $cfg['from'] || $m >= $cfg['to']) return $o + ['off_hours' => 1];
    $guard = function_exists('fu_guard') ? fu_guard($pdo)['state'] : 'ok'; if ($guard === 'stop') return $o + ['guard' => 'stop']; $cap = $guard === 'slow' ? min(3, $cfg['run_cap']) : $cfg['run_cap'];
    $today = 0; try { $today = (int)$pdo->query("SELECT COUNT(*) FROM wa_wj_ev WHERE status = 'sent' AND sent_at >= '" . date('Y-m-d 00:00:00', $now) . "'")->fetchColumn(); } catch (Throwable $e) { } if ($today >= $cfg['day_cap']) return $o + ['day_cap' => 1];
    $list = wj_collect($pdo, wj_plan_get($pdo), $cfg, $now, 300); if (!$list) return $o;
    $ins = $pdo->prepare("INSERT IGNORE INTO wa_wj_ev (phone, track, step_id, day, anchor, ref, status, why, sent_at) VALUES (?, ?, ?, ?, ?, ?, 'sending', '', ?)");
    foreach ($list as $x) {
        if ($o['sent'] >= $cap || $today + $o['sent'] >= $cfg['day_cap']) break; $c = $x['c']; $ph = (string)$c['phone'];
        if (wj_blocked($pdo, $c, $now) !== '') { $o['blocked']++; continue; }
        if (!function_exists('fa_tpl_pick') || !fa_tpl_pick($pdo, $x['step']['ref'])) { $o['waiting']++; continue; }
        $ins->execute([$ph, $x['track'], $x['step']['id'], $x['step']['day'], $x['ak'], $x['step']['ref'], wj_dt($now)]); if ($ins->rowCount() < 1) continue; $id = (int)$pdo->lastInsertId();
        [$ok, $res, $retry] = wj_send($pdo, $c, $x);
        if (!$ok && $retry !== '') { $pdo->prepare("DELETE FROM wa_wj_ev WHERE id = ?")->execute([$id]); $o['deferred']++; if ($retry === 'nonumber') break; continue; }
        $pdo->prepare("UPDATE wa_wj_ev SET status = ?, body = ?, why = ?, sent_at = ? WHERE id = ?")->execute([$ok ? 'sent' : 'failed', $ok ? mb_substr($res, 0, 1500) : '', $ok ? '' : mb_substr($res, 0, 160), wj_dt(time()), $id]);
        if ($ok) { $o['sent']++; foreach ($x['others'] as $s) { try { $ins2 = $pdo->prepare("INSERT IGNORE INTO wa_wj_ev (phone, track, step_id, day, anchor, ref, status, why, sent_at) VALUES (?, ?, ?, ?, ?, ?, 'skipped', 'a later day went first', ?)"); $ins2->execute([$ph, $x['track'], $s['id'], $s['day'], $x['ak'], $s['ref'], wj_dt($now)]); } catch (Throwable $e) { } } }
        else $o['failed']++;
    }
    return $o;
}

// ---------------------------------------------------------------------------------------------------------------------- the general brain
// ---------------------------------------------------------------------------------------------------------------------- AFTER AN ORDER: 12 customer types
/** After an order a customer is one of 12 types: how many times they ordered (1st, 2nd, 3rd, 4th or more) × what they spend per order (premium / standard / budget).
 *  Each type has its own timeline (days counted from the LAST order, up to day 365). Ordering again moves the customer to the next type and the clock starts again from that order. */
function jn_seg_keys(): array { $o = []; foreach ([1, 2, 3, 4] as $c) foreach (['prem', 'std', 'bud'] as $t) $o[] = 'c' . $c . '_' . $t; return $o; }
function jn_seg_title(string $k): string
{
    if (!preg_match('/^c([1-4])_(prem|std|bud)$/', $k, $m)) return $k;
    return ['1' => '1st order', '2' => '2nd order', '3' => '3rd order', '4' => '4th+ order (loyal)'][$m[1]] . ' · ' . ['prem' => 'Premium', 'std' => 'Standard', 'bud' => 'Budget'][$m[2]];
}
/** Percentile cuts of "spent per order" (top quarter = premium, bottom quarter = budget), refreshed once a day. */
function jn_cuts(PDO $pdo): array
{
    $c = wj_json($pdo, 'jn_cuts', []); if (!empty($c['at']) && $c['at'] > time() - 86400) return $c;
    $hi = 3000.0; $lo = 800.0;
    try { $v = array_map('floatval', $pdo->query("SELECT spent / n FROM wa_cust WHERE n > 0 AND spent > 0 ORDER BY spent / n LIMIT 200000")->fetchAll(PDO::FETCH_COLUMN)); $n = count($v);
        if ($n >= 40) { $lo = round($v[(int)floor($n * 0.25)]); $hi = round($v[(int)floor($n * 0.75)]); if ($hi <= $lo) $hi = $lo + 1; } } catch (Throwable $e) { }
    $c = ['hi' => $hi, 'lo' => $lo, 'at' => time()]; wj_put($pdo, 'jn_cuts', $c); return $c;
}
function jn_seg_of(int $n, float $spent, array $cuts): string
{
    $c = min(4, max(1, $n)); $avg = $n > 0 ? $spent / $n : 0.0; $t = $avg >= $cuts['hi'] ? 'prem' : ($avg < $cuts['lo'] ? 'bud' : 'std'); return 'c' . $c . '_' . $t;
}
/** A step's "topic" for the after-order track: '' = every type, a type key, a value group (prem / std / bud) or an order-count group (c1 … c4). */
function jn_seg_match(string $stepTopic, string $seg): bool
{
    if ($stepTopic === '') return true; if ($seg === '') return false; if ($stepTopic === $seg) return true;
    if (in_array($stepTopic, ['prem', 'std', 'bud'], true)) return substr($seg, 3) === $stepTopic;
    if (preg_match('/^c[1-4]$/', $stepTopic)) return substr($seg, 0, 2) === $stepTopic; return false;
}
/** Starting timelines, days counted from the last order, to day 200. Claude and the owner change them afterwards. */
function jn_buyer_defaults(): array
{
    return [
        'c1_std' => [[15, 'w_tip'], [30, 'w_collect'], [60, 'w_reorder'], [90, 'w_new'], [120, 'w_fest'], [150, 'o1'], [200, 'w_miss']],
        'c1_prem' => [[15, 'w_tip'], [30, 'w_vip'], [60, 'w_collect'], [90, 'w_reorder'], [120, 'w_vip'], [150, 'w_new'], [200, 'w_miss']],
        'c1_bud' => [[15, 'w_tip'], [30, 'w_sample'], [60, 'w_reorder'], [90, 'o1'], [120, 'w_new'], [150, 'o2'], [200, 'w_miss']],
        'c2_std' => [[30, 'w_collect'], [60, 'w_new'], [90, 'w_reorder'], [120, 'w_fest'], [150, 'w_vip'], [200, 'w_miss']],
        'c2_prem' => [[30, 'w_vip'], [60, 'w_collect'], [90, 'w_reorder'], [120, 'w_vip'], [150, 'w_new'], [200, 'w_fest']],
        'c2_bud' => [[30, 'w_sample'], [60, 'w_reorder'], [90, 'o1'], [120, 'w_new'], [150, 'o2'], [200, 'w_miss']],
        'c3_std' => [[30, 'w_collect'], [60, 'w_vip'], [90, 'w_reorder'], [120, 'w_new'], [150, 'w_fest'], [200, 'w_miss']],
        'c3_prem' => [[30, 'w_vip'], [60, 'w_new'], [90, 'w_reorder'], [120, 'w_vip'], [150, 'w_collect'], [200, 'w_fest']],
        'c3_bud' => [[30, 'w_new'], [60, 'w_reorder'], [90, 'o2'], [120, 'w_sample'], [150, 'o3'], [200, 'w_miss']],
        'c4_std' => [[30, 'w_vip'], [60, 'w_new'], [90, 'w_reorder'], [120, 'w_fest'], [150, 'w_vip'], [200, 'w_miss']],
        'c4_prem' => [[30, 'w_vip'], [60, 'w_collect'], [90, 'w_vip'], [120, 'w_new'], [150, 'w_reorder'], [200, 'w_fest']],
        'c4_bud' => [[30, 'w_vip'], [60, 'w_reorder'], [90, 'o2'], [120, 'w_new'], [150, 'o3'], [200, 'w_miss']],
    ];
}
/** Once: give each of the 12 types its starting timeline (additions only; what is there stays). One history entry, ↩ Undo in the Claude tab. */
function jn_buyer_seed(PDO $pdo): void
{
    if ((string)setting_get($pdo, 'jn_buyer_seeded', '') !== '' || (string)setting_get($pdo, 'wj_installed', '') === '') return;
    $reg = wj_reg($pdo); $plan = wj_plan_get($pdo); wj_hist_push($pdo, '🛍 After-order timelines set up (12 customer types)'); $have = [];
    foreach ($plan['tracks']['buyer']['steps'] as $s) $have[$s['topic'] . '|' . $s['day'] . '|' . $s['ref']] = 1;
    foreach (jn_buyer_defaults() as $seg => $rows) foreach ($rows as $r) { if (!isset($reg[$r[1]]) || isset($have[$seg . '|' . $r[0] . '|' . $r[1]])) continue;
        $plan['tracks']['buyer']['steps'][] = ['id' => 'g' . substr(md5($seg . $r[0] . $r[1]), 0, 8), 'day' => $r[0], 'ref' => $r[1], 'idea' => '', 'topic' => $seg, 'on' => true]; }
    wj_put($pdo, 'wj_plan', wj_plan_clean($plan, $reg)); setting_set($pdo, 'jn_buyer_seeded', date('Y-m-d H:i:s'));
}
/** How many customers sit in each type now (cached 10 minutes). */
function jn_segments_view(PDO $pdo): array
{
    $ck = wj_json($pdo, 'jn_segcount', []); $cuts = jn_cuts($pdo); $cnt = [];
    if (!empty($ck['t']) && $ck['t'] > time() - 600) $cnt = (array)$ck['n'];
    else { try { $q = $pdo->prepare("SELECT LEAST(n, 4) c, CASE WHEN spent / n >= ? THEN 'prem' WHEN spent / n < ? THEN 'bud' ELSE 'std' END t, COUNT(*) k FROM wa_cust WHERE n > 0 GROUP BY c, t"); $q->execute([$cuts['hi'], $cuts['lo']]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $cnt['c' . (int)$r['c'] . '_' . $r['t']] = (int)$r['k']; } catch (Throwable $e) { } wj_put($pdo, 'jn_segcount', ['t' => time(), 'n' => $cnt]); }
    $o = []; foreach (jn_seg_keys() as $k) $o[] = ['k' => $k, 'title' => jn_seg_title($k), 'n' => (int)($cnt[$k] ?? 0)];
    return ['rows' => $o, 'hi' => $cuts['hi'], 'lo' => $cuts['lo']];
}

// ---------------------------------------------------------------------------------------------------------------------- ONE ENGINE (before an order)
/** From now ONE engine (the follow-up engine, wa_follow.php) runs every customer who did not order, from the first minutes to day 60: one plan per situation, the situation is read again at every new
 *  customer message, one clock (our last message), one state, one set of rules. The 60-day Journey's "didn't order" days were moved INTO those plans once (jn_unify) and its own sender is switched off for them.
 *  The Journey's "ordered" track (counted from the order) keeps its own sender — that is the after-order flow. The old plan is kept in settings jn_backup_* . */
function jn_unified(PDO $pdo, ?bool $set = null): bool { static $u = null; if ($set !== null) $u = $set; if ($u === null) $u = (string)setting_get($pdo, 'jn_unified', '') !== ''; return $u; }
/** PURE: put the Journey's lead steps into the situations' plans. A step for a topic goes to that topic's situation (price → price felt high, thinking → ask family, buying → agreed / address) or to a lane of its own;
 *  a step for everyone goes into every situation. Returns the new list of situations (not yet cleaned). */
function jn_merge_plan(array $sits, array $leadSteps, callable $label): array
{
    $map = ['price' => 'price', 'thinking' => 'ask', 'buying' => 'pay']; $byKey = [];
    foreach ($sits as $i => $s) if (($s['kind'] ?? '') !== 'stop') $byKey[(string)$s['key']] = $i;
    $early = []; if (isset($byKey['quiet'])) foreach ((array)$sits[$byKey['quiet']]['steps'] as $x) if ((float)$x['after_h'] < 240) $early[] = $x;
    $mk = function (array $s) { return ['after_h' => (float)$s['day'] * 24, 'text' => '', 'remark' => (string)($s['idea'] ?? ''), 'ch' => 'tpl', 'kind' => 'offer', 'offer' => (string)$s['ref']]; };
    $add = function (array &$sit, array $st) { foreach ((array)$sit['steps'] as $x) if (abs((float)$x['after_h'] - (float)$st['after_h']) < 12 && (string)($x['offer'] ?? '') === (string)$st['offer']) return; $sit['steps'][] = $st; };
    $on = array_values(array_filter($leadSteps, function ($s) { return !empty($s['on']) && (int)($s['day'] ?? 0) >= 1 && (string)($s['ref'] ?? '') !== ''; }));
    foreach ($on as $s) {
        $t = (string)($s['topic'] ?? ''); if ($t === '') continue;
        if (isset($map[$t]) && isset($byKey[$map[$t]])) $idx = $byKey[$map[$t]];
        else {
            $cid = 't_' . substr(preg_replace('/[^a-z0-9]/', '', strtolower($t)), 0, 14); $idx = null; foreach ($sits as $i => $x) if ($x['id'] === $cid) $idx = $i;
            if ($idx === null) { $lb = trim((string)$label($t)); $sits[] = ['id' => $cid, 'key' => $t, 'title' => $lb !== '' ? $lb : $t, 'desc' => 'Customers whose chats are about: ' . ($lb !== '' ? $lb : $t), 'kind' => 'nudge', 'stage' => 'saved', 'on' => true, 'priority' => 4, 'remark_all' => '', 'steps' => $early]; $idx = count($sits) - 1; }
        }
        $add($sits[$idx], $mk($s));
    }
    foreach ($on as $s) { if ((string)($s['topic'] ?? '') !== '') continue; foreach ($sits as $i => $x) if (($x['kind'] ?? '') !== 'stop') $add($sits[$i], $mk($s)); }
    return $sits;
}
/** Once: move the Journey's "didn't order" days into the plans and switch the Journey's lead sender off. Safe to call again (does nothing). */
function jn_unify(PDO $pdo): bool
{
    if (jn_unified($pdo)) return true;
    if (!function_exists('fu_plans_get') || !function_exists('fa_install') || !function_exists('wa_lock') || !wa_lock($pdo, 'jnunify', 120)) return false;
    try {
        if ((string)setting_get($pdo, 'fa_installed', '') === '') fa_install($pdo, false);
        $P = fu_plans_get($pdo); if (!array_filter($P['situations'], function ($s) { return ($s['kind'] ?? '') !== 'stop'; })) return false;
        setting_set($pdo, 'jn_backup_plan', json_encode($P, JSON_UNESCAPED_UNICODE)); $plan = wj_plan_get($pdo); setting_set($pdo, 'jn_backup_wj', json_encode($plan, JSON_UNESCAPED_UNICODE));
        $sits = jn_merge_plan($P['situations'], (array)$plan['tracks']['lead']['steps'], function ($t) { return function_exists('wq_want_label') ? wq_want_label($t) : ucfirst(str_replace('_', ' ', $t)); });
        $clean = []; foreach ($sits as $s) $clean[] = fu_sit_clean($s); fu_plans_put($pdo, ['situations' => $clean]);
        setting_set($pdo, 'jn_unified', date('Y-m-d H:i:s')); jn_unified($pdo, true); return true;
    } catch (Throwable $e) { error_log('jn_unify: ' . $e->getMessage()); return false; }
}
/** The situations a Journey "topic" belongs to: [] = every situation. */
function jn_sits_for_topic(array $sits, string $t): array
{
    if ($t === '') { $o = []; foreach ($sits as $i => $s) if (($s['kind'] ?? '') !== 'stop') $o[] = $i; return $o; }
    $map = ['price' => 'price', 'thinking' => 'ask', 'buying' => 'pay']; $key = $map[$t] ?? null;
    foreach ($sits as $i => $s) if (($key !== null && $s['key'] === $key) || $s['id'] === 't_' . substr(preg_replace('/[^a-z0-9]/', '', strtolower($t)), 0, 14)) return [$i];
    return [];
}
/** Claude's "add / change a day of the didn't-order track" now changes the situation plans. Returns how many plans were touched. */
function jn_lead_op(PDO $pdo, array $o): int
{
    if (($o['op'] ?? '') !== 'step_set') return 0; $P = fu_plans_get($pdo); $sits = $P['situations']; $n = 0; $h = (float)$o['day'] * 24;
    foreach (jn_sits_for_topic($sits, (string)($o['topic'] ?? '')) as $i) {
        $hit = false; foreach ($sits[$i]['steps'] as $k => $x) if (abs((float)$x['after_h'] - $h) < 12 && ($x['kind'] ?? '') === 'offer') { $sits[$i]['steps'][$k]['offer'] = (string)$o['ref']; $sits[$i]['steps'][$k]['remark'] = (string)$o['idea']; $hit = true; }
        if (!$hit) $sits[$i]['steps'][] = ['after_h' => $h, 'text' => '', 'remark' => (string)$o['idea'], 'ch' => 'tpl', 'kind' => 'offer', 'offer' => (string)$o['ref']]; $n++;
    }
    if ($n) { $c = []; foreach ($sits as $s) $c[] = fu_sit_clean($s); fu_plans_put($pdo, ['situations' => $c]); }
    return $n;
}
function wj_autopilot_lines(PDO $pdo): string
{
    $s = ''; try { if (function_exists('fu_plans_get')) foreach (fu_plans_get($pdo)['situations'] as $p) { if (($p['kind'] ?? '') === 'stop') continue; $d = []; foreach ((array)$p['steps'] as $x) $d[] = 'day ' . round($x['after_h'] / 24, 1) . ($x['kind'] === 'offer' ? ' offer ' . $x['offer'] : ' message'); $s .= '  ' . $p['title'] . ': ' . implode(', ', $d) . "\n"; } } catch (Throwable $e) { }
    return $s;
}
function wj_plan_text(PDO $pdo, array $plan, array $reg): string
{
    $s = ''; foreach ($plan['tracks'] as $tk => $T) { if ($tk === 'lead' && jn_unified($pdo)) { $s .= "LEAD TRACK (before an order) is now ONE plan per situation, listed below (FOLLOW-UP AUTOPILOT = all steps from minutes to day 60). To add or change a day use step_set with track 'lead', day, ref and topic = the situation (price / thinking / buying / a topic key; '' = every situation). The ids of old lead steps no longer exist.\n"; continue; } $s .= strtoupper($tk) . ' TRACK (' . ($tk === 'lead' ? 'day counted from the customer\'s last message; days 1–10 are the Follow-up Autopilot' : 'day counted from the last order') . "):\n"; foreach ($T['steps'] as $x) $s .= '  [' . $x['id'] . '] day ' . $x['day'] . ' · ' . $x['ref'] . ' (' . ($reg[$x['ref']][0] ?? '?') . ')' . ($x['topic'] !== '' ? ' · only topic ' . $x['topic'] : '') . ($x['on'] ? '' : ' · OFF') . ($x['idea'] !== '' ? ' · idea: ' . $x['idea'] : '') . "\n"; }
    return $s;
}
function wj_context(PDO $pdo): string
{
    $reg = wj_reg($pdo); $plan = wj_plan_get($pdo); $c = '';
    try { if (function_exists('teach_facts_prompt')) { [$kf] = teach_facts_prompt($pdo, []); $c .= "WHAT THE OWNER HAS ALREADY TAUGHT (store facts, selling rules, price chart, limits):\n" . mb_substr((string)$kf, 0, 5000) . "\n"; } if (function_exists('teach_limits_text')) $c .= (string)teach_limits_text($pdo) . "\n"; } catch (Throwable $e) { }
    $c .= "\nAFTER-ORDER CUSTOMER TYPES (the 'buyer' track, days counted from the LAST order, up to day 365): c1_prem c1_std c1_bud c2_* c3_* c4_* = 1st / 2nd / 3rd / 4th+ order × premium / standard / budget (spend per order). A buyer step's topic = one type, or a group: prem / std / bud / c1 … c4, '' = every type. A customer who orders again moves to the next type and the clock restarts from that order.\n" . "\nTODAY: " . date('Y-m-d (l)') . "\nMESSAGE & OFFER REGISTRY (key — label: rule; each has ONE approved Meta message with {{1}} name {{2}} perfume {{3}} line):\n";
    foreach ($reg as $k => $x) $c .= "  $k — " . $x[0] . ': ' . $x[1] . ' [Meta: ' . wj_tpl_state($pdo, $k) . ']' . "\n";
    $c .= "\nTHE 60-DAY JOURNEY:\n" . wj_plan_text($pdo, $plan, $reg) . "\nFOLLOW-UP AUTOPILOT (days 1–10, owned by the Follow-ups tab — do not duplicate):\n" . wj_autopilot_lines($pdo);
    $S = function_exists('fa_season') ? fa_season($pdo) : []; $c .= "\nFESTIVAL BOX NOW: " . (!empty($S['on']) && trim((string)$S['text']) !== '' ? $S['text'] . ' (' . $S['from'] . ' → ' . $S['to'] . ')' : 'off') . "\nUPCOMING FESTIVALS: ";
    foreach (wj_fest_upcoming($pdo, 5) as $f) $c .= $f['name'] . ' ' . $f['date'] . ' (in ' . $f['days_left'] . ' d) · '; $c .= "\n";
    $T = wj_topics_view($pdo); if ($T['rows']) { $c .= "\nTOPICS customers talk about (last 30 days):\n"; foreach (array_slice($T['rows'], 0, 8) as $r) $c .= '  ' . $r['key'] . ' — ' . $r['title'] . ': ' . $r['n30'] . ' chats (' . $r['share'] . '%), last 7 days ' . $r['n7'] . ($r['ex'] ? ' · e.g. "' . mb_substr($r['ex'][0]['q'], 0, 90) . '"' : '') . "\n"; }
    try { $R = wj_results($pdo, 30); if ($R['steps']) { $c .= "\nRESULTS last 30 days per step:\n"; foreach ($R['steps'] as $r) $c .= '  ' . $r['track'] . ' [' . $r['step'] . '] sent ' . $r['sent'] . ', replied ' . $r['replied'] . ', ordered ' . $r['ordered'] . "\n"; } } catch (Throwable $e) { }
    return $c;
}
function wj_body_ok(string $b): string
{
    preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $b, $mm); if (array_map('intval', $mm[1]) !== [1, 2, 3]) return 'Keep {{1}} (name), {{2}} (perfume) and {{3}} (the line) once each, in this order.';
    if (preg_match('/^\s*\{\{|\}\}\s*$/', $b)) return 'Meta does not allow {{…}} at the very start or end — add a word before / after it.'; return '';
}
/** Describe what the ops would change, in plain lines (also the check that every op is sound). Returns [lines, cleanOps]. */
function wj_ops_clean(PDO $pdo, array $ops): array
{
    $reg = wj_reg($pdo); $plan = wj_plan_get($pdo); $S = function_exists('fa_season') ? fa_season($pdo) : []; $lines = []; $clean = []; $newKeys = [];
    $find = function ($tk, $id) use ($plan) { foreach ($plan['tracks'][$tk]['steps'] as $s) if ($s['id'] === $id) return $s; return null; };
    $tn = ['lead' => "Didn't order", 'buyer' => 'Ordered'];
    foreach ($ops as $o) {   // 1) messages / offers first (steps may use a new one)
        if (!is_array($o) || ($o['op'] ?? '') !== 'offer_set') continue; $k = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($o['key'] ?? ''))); $k = substr($k, 0, 12); if ($k === '') continue;
        $label = mb_substr(trim((string)($o['label'] ?? '')), 0, 40); $rule = mb_substr(trim((string)($o['rule'] ?? '')), 0, 300); $body = trim((string)($o['body'] ?? '')); if ($body !== '' && wj_body_ok($body) !== '') $body = '';
        if (isset($reg[$k])) { $ch = []; if ($label !== '' && $label !== $reg[$k][0]) $ch[] = 'name “' . $reg[$k][0] . '” → “' . $label . '”'; if ($rule !== '' && $rule !== $reg[$k][1]) $ch[] = 'rule “' . mb_substr($reg[$k][1], 0, 70) . '” → “' . mb_substr($rule, 0, 90) . '”'; if ($body !== '' && $body !== fa_tpl_body($pdo, $k)) $ch[] = 'message words changed (goes to Meta again)';
            if (!$ch) continue; $lines[] = '✏️ ' . wj_icon($k) . ' ' . $reg[$k][0] . ': ' . implode('; ', $ch); }
        else { if ($label === '' || $rule === '') continue; $lines[] = '➕ New ' . (strpos($k, 'w_') === 0 ? 'message' : 'offer') . ' “' . $label . '” — ' . mb_substr($rule, 0, 110) . ($body !== '' ? '' : ' (Claude writes the words; sent to Meta after you start)'); $newKeys[$k] = 1; $reg[$k] = [$label, $rule]; }
        $clean[] = ['op' => 'offer_set', 'key' => $k, 'label' => $label, 'rule' => $rule, 'body' => $body];
    }
    foreach ($ops as $o) {
        if (!is_array($o)) continue; $op = (string)($o['op'] ?? ''); $tk = (string)($o['track'] ?? '');
        if ($op === 'step_set' && isset($tn[$tk])) {
            $day = (int)($o['day'] ?? 0); $ref = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($o['ref'] ?? ''))); if ($day < 1 || $day > ($tk === 'buyer' ? 365 : 60) || !isset($reg[$ref])) continue; $id = preg_replace('/[^a-z0-9]/i', '', (string)($o['id'] ?? '')); $old = $id !== '' ? $find($tk, $id) : null;
            $new = ['op' => 'step_set', 'track' => $tk, 'id' => $old ? $id : '', 'day' => $day, 'ref' => $ref, 'idea' => mb_substr(trim((string)($o['idea'] ?? '')), 0, 300), 'topic' => substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string)($o['topic'] ?? ''))), 0, 24), 'on' => !array_key_exists('on', $o) || !empty($o['on'])];
            if ($old) { $ch = []; if ($old['day'] !== $day) $ch[] = 'day ' . $old['day'] . ' → ' . $day; if ($old['ref'] !== $ref) $ch[] = ($reg[$old['ref']][0] ?? $old['ref']) . ' → ' . $reg[$ref][0]; if ($old['idea'] !== $new['idea']) $ch[] = 'idea “' . mb_substr($new['idea'], 0, 80) . '”'; if ($old['topic'] !== $new['topic']) $ch[] = 'topic ' . ($new['topic'] ?: 'everyone'); if ($old['on'] !== $new['on']) $ch[] = $new['on'] ? 'turned on' : 'turned off';
                if (!$ch) continue; $lines[] = '✏️ ' . $tn[$tk] . ' · day ' . $old['day'] . ': ' . implode('; ', $ch); }
            else $lines[] = '➕ ' . $tn[$tk] . ' · day ' . $day . ': ' . wj_icon($ref) . ' ' . $reg[$ref][0] . ($new['topic'] !== '' ? ' (only topic ' . $new['topic'] . ')' : '') . ($new['idea'] !== '' ? ' — ' . mb_substr($new['idea'], 0, 80) : '');
            $clean[] = $new;
        } elseif ($op === 'step_del' && isset($tn[$tk])) {
            $id = preg_replace('/[^a-z0-9]/i', '', (string)($o['id'] ?? '')); $old = $find($tk, $id); if (!$old) continue; $lines[] = '➖ ' . $tn[$tk] . ' · day ' . $old['day'] . ': ' . ($reg[$old['ref']][0] ?? $old['ref']) . ' removed'; $clean[] = ['op' => 'step_del', 'track' => $tk, 'id' => $id];
        } elseif ($op === 'offer_del') {
            $k = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($o['key'] ?? ''))); if (!isset($reg[$k]) || in_array($k, ['o1', 'o2', 'o3', 'mega'], true)) continue; $lines[] = '➖ ' . wj_icon($k) . ' “' . $reg[$k][0] . '” removed from the registry (its steps go too)'; $clean[] = ['op' => 'offer_del', 'key' => $k];
        } elseif ($op === 'season') {
            $f = (string)($o['from'] ?? ''); $t = (string)($o['to'] ?? ''); foreach ([$f, $t] as $d) if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue 2; $on = !empty($o['on']); $txt = mb_substr(trim((string)($o['text'] ?? '')), 0, 300);
            $lines[] = '🪔 Festival box: ' . (!empty($S['on']) && trim((string)($S['text'] ?? '')) !== '' ? '“' . mb_substr($S['text'], 0, 60) . '” → ' : 'off → ') . ($on ? '“' . mb_substr($txt, 0, 80) . '” (' . ($f ?: 'now') . ' → ' . ($t ?: 'no end') . ')' : 'off'); $clean[] = ['op' => 'season', 'on' => $on, 'text' => $txt, 'from' => $f, 'to' => $t];
        }
    }
    return [$lines, $clean];
}
function wj_ops_apply(PDO $pdo, array $ops, string $why): array
{
    if (!$ops) return [false, 'Nothing to change.']; wj_hist_push($pdo, $why); $reg = wj_reg($pdo); $plan = wj_plan_get($pdo); $n = 0; $meta = 0;
    foreach ($ops as $o) if ($o['op'] === 'offer_set') {
        $k = $o['key']; $isNew = !isset($reg[$k]); $label = $o['label'] !== '' ? $o['label'] : ($reg[$k][0] ?? $k); $rule = $o['rule'] !== '' ? $o['rule'] : ($reg[$k][1] ?? ''); $reg[$k] = [$label, $rule]; $n++;
        setting_set($pdo, 'fa_offers', json_encode(array_map(function ($x) { return [$x[0], $x[1]]; }, $reg), JSON_UNESCAPED_UNICODE));
        if ($o['body'] !== '' && $o['body'] !== fa_tpl_body($pdo, $k)) { setting_set($pdo, 'fa_tplbody_' . $k, mb_substr($o['body'], 0, 900)); setting_set($pdo, 'fa_tpltry_' . $k, '0'); if (!$isNew && fa_tpl_make($pdo, $k)[0]) $meta++; }
        elseif ($isNew && (string)setting_get($pdo, 'fa_tplbody_' . $k, '') === '') setting_set($pdo, 'fa_tplbody_' . $k, "Hi {{1}} 🙏 Something for you about {{2}}: {{3}} Reply here and we will help you.");
    }
    foreach ($ops as $o) {
        if (jn_unified($pdo) && ($o['track'] ?? '') === 'lead' && in_array($o['op'], ['step_set', 'step_del'], true)) { $n += jn_lead_op($pdo, $o); continue; }   // one engine: a day of the didn't-order flow lives in the situation plans
        if ($o['op'] === 'step_set') { $steps = $plan['tracks'][$o['track']]['steps']; $hit = false; foreach ($steps as $i => $s) if ($o['id'] !== '' && $s['id'] === $o['id']) { $steps[$i] = array_merge($s, ['day' => $o['day'], 'ref' => $o['ref'], 'idea' => $o['idea'], 'topic' => $o['topic'], 'on' => $o['on']]); $hit = true; }
            if (!$hit) $steps[] = ['id' => '', 'day' => $o['day'], 'ref' => $o['ref'], 'idea' => $o['idea'], 'topic' => $o['topic'], 'on' => $o['on']]; $plan['tracks'][$o['track']]['steps'] = $steps; $n++; }
        elseif ($o['op'] === 'step_del') { $plan['tracks'][$o['track']]['steps'] = array_values(array_filter($plan['tracks'][$o['track']]['steps'], function ($s) use ($o) { return $s['id'] !== $o['id']; })); $n++; }
        elseif ($o['op'] === 'offer_del') { unset($reg[$o['key']]); setting_set($pdo, 'fa_offers', json_encode(array_map(function ($x) { return [$x[0], $x[1]]; }, $reg), JSON_UNESCAPED_UNICODE));
            foreach (['lead', 'buyer'] as $tk) $plan['tracks'][$tk]['steps'] = array_values(array_filter($plan['tracks'][$tk]['steps'], function ($s) use ($o) { return $s['ref'] !== $o['key']; })); $n++; }
        elseif ($o['op'] === 'season') { setting_set($pdo, 'fa_season', json_encode(['on' => $o['on'], 'text' => $o['text'], 'from' => $o['from'], 'to' => $o['to']], JSON_UNESCAPED_UNICODE)); $n++; }
    }
    wj_put($pdo, 'wj_plan', wj_plan_clean($plan, wj_reg($pdo)));
    return [true, 'Started ✓ — ' . $n . ' change(s) are live' . ($meta ? '; ' . $meta . ' changed message(s) sent to Meta' : '') . '. ↩ Undo is at the top if you need it.'];
}
/** The owner talks (or the night / a festival does); Claude answers with what it understood, questions, and the exact changes. Nothing changes before ▶ Start. */
function wj_brain(PDO $pdo, string $text, bool $more = false, string $source = 'owner'): array
{
    if (!function_exists('convo_ai')) return [false, 'Claude is not available.'];
    $prev = $more ? wj_json($pdo, 'wj_proposal', []) : []; $turns = is_array($prev['turns'] ?? null) ? $prev['turns'] : [];
    $op = ['type' => 'OBJECT', 'properties' => ['op' => ['type' => 'STRING'], 'track' => ['type' => 'STRING'], 'id' => ['type' => 'STRING'], 'day' => ['type' => 'INTEGER'], 'ref' => ['type' => 'STRING'], 'idea' => ['type' => 'STRING'], 'topic' => ['type' => 'STRING'], 'on' => ['type' => 'BOOLEAN'],
        'key' => ['type' => 'STRING'], 'label' => ['type' => 'STRING'], 'rule' => ['type' => 'STRING'], 'body' => ['type' => 'STRING'], 'text' => ['type' => 'STRING'], 'from' => ['type' => 'STRING'], 'to' => ['type' => 'STRING']], 'required' => ['op']];
    $sch = ['type' => 'OBJECT', 'properties' => ['understood' => ['type' => 'STRING'], 'questions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'changes' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'ops' => ['type' => 'ARRAY', 'items' => $op]], 'required' => ['understood', 'questions', 'changes', 'ops']];
    $sys = "You are the general brain of the 60-day WhatsApp journey of TM Perfume House (Indian perfume brand). The owner talks in Hindi / Gujarati / English. You see everything: what the owner taught the Teach bot, the central registry of messages & offers, the two tracks of the journey, the festival box, "
        . "topics from the chats, results, and the festival calendar. Answer with: understood (1–3 plain sentences), questions (up to 4 short questions for anything unclear that the owner must decide — [] when clear), changes (each change in one plain line), and ops (the exact changes). "
        . "OPS: {op:'step_set', track:'lead'|'buyer', id:(the id in [brackets] for an existing step, '' for a new one), day:1–60, ref:(a registry key), idea:(extra words for the day, '' if none), topic:(a topic key to send ONLY to customers of that topic, '' = everyone), on:true|false} — ALWAYS send all fields of a step; "
        . "{op:'step_del', track, id}; {op:'offer_set', key, label, rule, body} to add or change a registry entry (key: lowercase letters/digits/underscore, max 12; use the prefix w_ for a plain message with no discount and o_ for a new offer; label = its name; rule = what Claude must put in {{3}} for each customer, with the owner's limits; "
        . "body = the approved message words with {{1}} name, {{2}} perfume, {{3}} line once each IN THIS ORDER, never at the very start or end, warm and short, English with a little Hinglish — or '' to keep the current words); {op:'offer_del', key} (never o1/o2/o3/mega); {op:'season', on, text, from:'YYYY-MM-DD', to:'YYYY-MM-DD'} for the festival box. "
        . "RULES: every journey message after the first 24 hours goes as an approved Meta message built from a registry entry, so a step always points to a registry key. Lead track = days counted from the customer's last message (days 1–10 belong to the Follow-up Autopilot — do not put steps there unless the owner insists); buyer track = days counted from the last order (After-delivery messages already cover days 2, 5, 15, 30 after delivery). "
        . "Keep at least 2 days between two steps of a track, at most 20 steps per track. When a festival changes, update the registry rules and step ideas that mention the old festival and the festival box; do not change days. Never invent a price, offer amount or store fact: use only what the owner taught, or ask. "
        . "Change only what the owner asked; keep everything else exactly as it is. If the owner asks why something is as it is, explain in 'understood'. If nothing needs to change, return empty ops.";
    $hist = ''; foreach (array_slice($turns, -8) as $t) $hist .= ($t['who'] === 'you' ? 'OWNER: ' : 'YOU UNDERSTOOD: ') . $t['text'] . "\n";
    $ask = ($hist !== '' ? "EARLIER IN THIS CONVERSATION (build on it):\n" . $hist : '') . ($source === 'owner' ? 'THE OWNER SAYS NOW:' : 'TASK (' . $source . '):') . "\n" . mb_substr($text, 0, 2500);
    try { [$ok, $v] = convo_ai($sys, [['text' => wj_context($pdo) . "\n" . $ask]], $sch, false, 5000, 'claude'); } catch (Throwable $e) { return [false, 'Claude could not answer — try again.']; }
    if (!$ok || !is_array($v)) return [false, 'Claude could not make a plan from that — ' . (is_string($v) ? mb_substr($v, 0, 160) : 'say it a little more clearly.')];
    [$lines, $clean] = wj_ops_clean($pdo, (array)($v['ops'] ?? [])); $und = trim((string)($v['understood'] ?? '')); if ($und === '') $und = $clean ? 'Here is what I would change.' : 'I need a little more detail before I can change anything.';
    $turns[] = ['who' => 'you', 'text' => mb_substr($text, 0, 1500)]; $turns[] = ['who' => 'claude', 'text' => $und];
    $prop = ['asked' => mb_substr($text, 0, 2500), 'understood' => $und, 'questions' => array_slice(array_map('strval', (array)($v['questions'] ?? [])), 0, 4), 'changes' => array_slice(array_map('strval', (array)($v['changes'] ?? [])), 0, 20), 'lines' => $lines, 'ops' => $clean, 'source' => $source, 'at' => date('Y-m-d H:i:s'), 'turns' => array_slice($turns, -12)];
    wj_put($pdo, 'wj_proposal', $prop); return [true, $prop];
}

// ---------------------------------------------------------------------------------------------------------------------- ✏️ change many messages at once
/** The owner says anything ("Diwali theme", "20% extra on every offer", "new perfume launched"): Claude lists EVERY related message of the library with a suggested new rule / words.
 *  Nothing is changed here. Each suggestion is checked against the Teach facts + limits (a discount over the limit is blocked). [ok, rows|error] */
function wj_change_scan(PDO $pdo, string $text): array
{
    if (!function_exists('convo_ai')) return [false, 'Claude is not available.'];
    $reg = wj_reg($pdo); $plan = wj_plan_get($pdo); $uses = [];
    foreach ($plan['tracks'] as $tk => $T) foreach ($T['steps'] as $s) $uses[$s['ref']][] = ($tk === 'lead' ? 'day ' : 'buyer day ') . $s['day'];
    $lib = ''; foreach ($reg as $k => $x) $lib .= "[$k] " . $x[0] . ' | RULE: ' . $x[1] . ' | WORDS: ' . (function_exists('fa_tpl_body') ? fa_tpl_body($pdo, $k) : '') . ' | USED: ' . (isset($uses[$k]) ? implode(', ', array_slice($uses[$k], 0, 8)) : 'not used yet') . "\n";
    $K = function_exists('wk_knowledge') ? wk_knowledge($pdo, [], $text) : ['text' => '', 'allowed' => []];
    $sch = ['type' => 'OBJECT', 'properties' => ['understood' => ['type' => 'STRING'], 'rows' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['key' => ['type' => 'STRING'], 'why' => ['type' => 'STRING'], 'new_rule' => ['type' => 'STRING'], 'new_body' => ['type' => 'STRING']], 'required' => ['key', 'why', 'new_rule', 'new_body']]]], 'required' => ['understood', 'rows']];
    $sys = "You help the owner of TM Perfume House (Indian perfume brand) change many WhatsApp follow-up messages at once. The owner says something in Hindi / Gujarati / English: a festival, a season, a new offer, an extra discount, a new perfume, a change of tone — anything. "
        . "You get the whole message library (key, rule = what Claude writes in {{3}} for each customer, words = the approved message). Pick EVERY message that this change really touches (no others) and, for each, suggest the new RULE (max 300 letters) and the new WORDS. "
        . "If only the rule must change keep new_body = '' (the words stay). WORDS must keep {{1}} (customer name), {{2}} (perfume) and {{3}} (the line) once each IN THIS ORDER, never at the very start or end, warm, short, English with a little Hinglish. "
        . "A message that does not fit the change is left out. Obey the STORE FACTS and LIMITS: never go below the lowest price or above the maximum discount; if the owner asks for something over a limit, still suggest it (the system will block it and tell the owner). "
        . "why = one plain line. understood = 1-2 plain sentences of what you understood.";
    try { [$ok, $v] = convo_ai($sys, [['text' => ($K['text'] !== '' ? $K['text'] . "\n\n" : '') . "MESSAGE LIBRARY:\n" . $lib . "\nTODAY: " . date('Y-m-d') . "\nTHE OWNER SAYS:\n" . mb_substr($text, 0, 2000)]], $sch, false, 6000, 'claude'); } catch (Throwable $e) { return [false, 'Claude could not answer — try again.']; }
    if (!$ok || !is_array($v)) return [false, 'Claude could not read that — say it a little more clearly.'];
    $allowed = $K['allowed'] + (function_exists('teach_nums') ? teach_nums($text) : []); $rows = [];
    foreach ((array)($v['rows'] ?? []) as $r) {
        $k = (string)($r['key'] ?? ''); if (!isset($reg[$k]) || count($rows) >= 40) continue;
        $nr = mb_substr(trim((string)($r['new_rule'] ?? '')), 0, 300); $nb = trim((string)($r['new_body'] ?? '')); $cur = function_exists('fa_tpl_body') ? fa_tpl_body($pdo, $k) : '';
        $row = ['key' => $k, 'label' => $reg[$k][0], 'old_rule' => $reg[$k][1], 'old_body' => $cur, 'rule' => $nr !== '' ? $nr : $reg[$k][1], 'body' => $nb !== '' ? $nb : $cur, 'why' => mb_substr(trim((string)($r['why'] ?? '')), 0, 200), 'photo' => (int)setting_get($pdo, 'fa_tplphoto_' . $k, '0'), 'used' => isset($uses[$k]) ? array_slice($uses[$k], 0, 6) : [], 'blocked' => '', 'on' => true];
        $row['blocked'] = wj_change_check($pdo, $row['rule'], $row['body'], $allowed);
        if ($row['blocked'] !== '') $row['on'] = false;
        $rows[] = $row;
    }
    $out = ['asked' => mb_substr($text, 0, 2000), 'understood' => mb_substr(trim((string)($v['understood'] ?? '')), 0, 400), 'rows' => $rows, 'at' => date('Y-m-d H:i:s')];
    wj_put($pdo, 'wj_change', $out); return [true, $out];
}
/** '' when a new rule / words are fine, else the plain reason. Numbers must come from the Teach facts / price chart / the owner's own request, and no hard limit may be broken. */
function wj_change_check(PDO $pdo, string $rule, string $body, array $allowed): string
{
    if ($body !== '') {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $mm); if (array_map('intval', $mm[1]) !== [1, 2, 3]) return 'The words must keep {{1}} name, {{2}} perfume, {{3}} line once each, in this order.';
        if (preg_match('/^\s*\{\{|\}\}\s*$/', $body)) return 'Meta does not allow {{…}} at the very start or end — add a word before / after it.';
    }
    $why = []; if (function_exists('fu_text_ok') && !fu_text_ok($pdo, $rule . ' ' . $body, $allowed, $why)) return 'Not allowed by your Teach facts / limits: ' . implode(', ', array_slice(array_map('strval', $why), 0, 4)) . '. Change the limit in Teach first, or change this suggestion.';
    return '';
}
/** Apply the ticked rows: the new rule / words / photo are saved in the library; changed words or photo go to Meta as a NEW version (the old approved one keeps working until the new one is approved). The old values are kept for Undo. */
function wj_change_apply(PDO $pdo, array $rows, int $by): array
{
    $reg = wj_reg($pdo); $K = function_exists('wk_knowledge') ? wk_knowledge($pdo, [], '') : ['allowed' => []]; $asked = (string)(wj_json($pdo, 'wj_change', [])['asked'] ?? '');
    $allowed = $K['allowed'] + (function_exists('teach_nums') ? teach_nums($asked) : []); $undo = []; $n = 0; $sent = 0; $bad = [];
    foreach ($rows as $r) {
        if (empty($r['on'])) continue; $k = preg_replace('/[^a-z0-9_]/', '', (string)($r['key'] ?? '')); if (!isset($reg[$k])) continue;
        $label = mb_substr(trim((string)($r['label'] ?? $reg[$k][0])), 0, 40) ?: $reg[$k][0]; $rule = mb_substr(trim((string)($r['rule'] ?? '')), 0, 300); $body = trim((string)($r['body'] ?? '')); $photo = max(0, (int)($r['photo'] ?? 0));
        if ($rule === '') $rule = $reg[$k][1]; $chk = wj_change_check($pdo, $rule, $body, $allowed); if ($chk !== '') { $bad[] = $label . ': ' . $chk; continue; }
        $curBody = fa_tpl_body($pdo, $k); $curPhoto = (int)setting_get($pdo, 'fa_tplphoto_' . $k, '0');
        $undo[$k] = ['label' => $reg[$k][0], 'rule' => $reg[$k][1], 'body' => $curBody, 'photo' => $curPhoto];
        $reg[$k] = [$label, $rule]; $n++;
        if (($body !== '' && $body !== $curBody) || $photo !== $curPhoto) {
            if ($body !== '' && $body !== $curBody) setting_set($pdo, 'fa_tplbody_' . $k, mb_substr($body, 0, 900)); setting_set($pdo, 'fa_tplphoto_' . $k, (string)$photo); setting_set($pdo, 'fa_tpltry_' . $k, '0');
            [$ok] = fa_tpl_make($pdo, $k, $by); if ($ok) $sent++;
        }
    }
    if ($n) { setting_set($pdo, 'fa_offers', json_encode(array_map(function ($x) { return [$x[0], $x[1]]; }, $reg), JSON_UNESCAPED_UNICODE)); wj_put($pdo, 'wj_change_undo', ['at' => date('Y-m-d H:i:s'), 'asked' => mb_substr($asked, 0, 200), 'items' => $undo]); wj_put($pdo, 'wj_change', []); wj_put($pdo, 'wj_res_cache', []); }
    return [$n > 0 || !$bad, ($n ? $n . ' message(s) updated ✓' . ($sent ? ' — ' . $sent . ' new version(s) sent to Meta; the old approved one keeps working until the new one is approved' : '') : 'Nothing was changed.') . ($bad ? ' Skipped: ' . implode(' | ', $bad) : '')];
}
function wj_change_undo(PDO $pdo, int $by): array
{
    $u = wj_json($pdo, 'wj_change_undo', []); if (empty($u['items'])) return [false, 'There is nothing to undo.'];
    $reg = wj_reg($pdo); $sent = 0;
    foreach ($u['items'] as $k => $o) { if (!isset($reg[$k])) continue; $reg[$k] = [(string)$o['label'], (string)$o['rule']]; $curB = fa_tpl_body($pdo, $k); $curP = (int)setting_get($pdo, 'fa_tplphoto_' . $k, '0');
        if ((string)$o['body'] !== $curB || (int)$o['photo'] !== $curP) { setting_set($pdo, 'fa_tplbody_' . $k, (string)$o['body']); setting_set($pdo, 'fa_tplphoto_' . $k, (string)(int)$o['photo']); setting_set($pdo, 'fa_tpltry_' . $k, '0'); if (fa_tpl_make($pdo, $k, $by)[0]) $sent++; } }
    setting_set($pdo, 'fa_offers', json_encode(array_map(function ($x) { return [$x[0], $x[1]]; }, $reg), JSON_UNESCAPED_UNICODE)); wj_put($pdo, 'wj_change_undo', []); wj_put($pdo, 'wj_res_cache', []);
    return [true, 'Changes undone' . ($sent ? ' — ' . $sent . ' old wording(s) sent to Meta again' : '') . '.'];
}

// ---------------------------------------------------------------------------------------------------------------------- the Teach bot reads the offers + festival back
function wj_bot_note(PDO $pdo): string
{
    if (!function_exists('fa_offers') || (string)setting_get($pdo, 'wj_installed', '') === '') return '';
    try {
        $t = ''; $s = function_exists('fa_season_text') ? fa_season_text($pdo) : ''; if ($s !== '') $t .= "- Festival / season on now: " . $s . "\n";
        foreach (fa_offers($pdo) as $k => $x) { if (strpos($k, 'w_') === 0 || trim((string)$x[1]) === '') continue; $t .= '- ' . $x[0] . ': ' . mb_substr(trim((string)$x[1]), 0, 200) . "\n"; }
        return $t === '' ? '' : "OFFERS THE SHOP IS RUNNING NOW (kept in WhatsApp → 60-day Journey; mention one only when it fits the chat, and only the way it is written here):\n" . $t;
    } catch (Throwable $e) { return ''; }
}

// ---------------------------------------------------------------------------------------------------------------------- the screen's data
function wj_view(PDO $pdo): array
{
    try { jn_unify($pdo); jn_buyer_seed($pdo); } catch (Throwable $e) { }
    $reg = wj_reg($pdo); $plan = wj_plan_get($pdo); $steps = ['lead' => [], 'buyer' => []];
    foreach ($plan['tracks'] as $tk => $T) foreach ($T['steps'] as $s) $steps[$tk][] = $s + ['label' => $reg[$s['ref']][0] ?? $s['ref'], 'icon' => wj_icon($s['ref']), 'tpl' => wj_tpl_state($pdo, $s['ref']), 'missing' => !isset($reg[$s['ref']])];
    $messages = []; foreach ($reg as $k => $x) $messages[] = ['k' => $k, 'label' => $x[0], 'rule' => $x[1], 'body' => function_exists('fa_tpl_body') ? fa_tpl_body($pdo, $k) : '', 'status' => wj_tpl_state($pdo, $k), 'icon' => wj_icon($k), 'photo' => (int)setting_get($pdo, 'fa_tplphoto_' . $k, '0'), 'uses' => count(array_filter(array_merge($plan['tracks']['lead']['steps'], $plan['tracks']['buyer']['steps']), function ($s) use ($k) { return $s['ref'] === $k; }))];
    $auto = []; try { if (function_exists('fu_plans_get')) foreach (fu_plans_get($pdo)['situations'] as $p) { if (($p['kind'] ?? '') === 'stop') continue; foreach ((array)$p['steps'] as $x) { $d = max(1, (int)ceil($x['after_h'] / 24)); if ($d > 10) continue; $auto[$d] = $auto[$d] ?? ['day' => $d, 'msg' => 0, 'offer' => 0]; $auto[$d][$x['kind'] === 'offer' ? 'offer' : 'msg']++; } } } catch (Throwable $e) { }
    ksort($auto); $hist = []; foreach (array_reverse(wj_json($pdo, 'wj_hist', [])) as $h) $hist[] = ['at' => $h['at'], 'why' => $h['why']];
    return ['ok' => true, 'segments' => jn_segments_view($pdo), 'unified' => jn_unified($pdo), 'daily' => wj_json($pdo, 'wj_daily', []), 'change' => wj_json($pdo, 'wj_change', []), 'can_undo_change' => !empty(wj_json($pdo, 'wj_change_undo', [])['items']), 'mode' => wj_mode($pdo), 'cfg' => wj_cfg($pdo), 'ai' => function_exists('convo_ai'), 'ready' => function_exists('fa_offers'), 'steps' => $steps, 'messages' => $messages, 'auto10' => array_values($auto), 'season' => function_exists('fa_season') ? fa_season($pdo) : [], 'season_now' => function_exists('fa_season_text') ? fa_season_text($pdo) : '',
        'proposal' => wj_json($pdo, 'wj_proposal', []), 'fest_sug' => wj_json($pdo, 'wj_fest_sug', []), 'festivals' => wj_fest_upcoming($pdo, 10), 'topics' => wj_topics_view($pdo), 'night' => wj_json($pdo, 'wj_night', []), 'stats' => wj_stats($pdo), 'hist' => array_slice($hist, 0, 6),
        'auto_since' => (int)setting_get($pdo, 'wj_auto_since', '0'), 'autopilot_mode' => function_exists('fu_mode') ? fu_mode($pdo) : 'off'];
}
/** Save one whole track as the screen sends it. */
function wj_steps_save(PDO $pdo, string $tk, array $rows): array
{
    if (!in_array($tk, ['lead', 'buyer'], true)) return [false, 'Unknown track.']; $reg = wj_reg($pdo); $plan = wj_plan_get($pdo); $days = [];
    foreach ($rows as $r) { $d = (int)($r['day'] ?? 0); if ($d < 1 || $d > ($tk === 'buyer' ? 365 : 60)) return [false, $tk === 'buyer' ? 'Every day must be between 1 and 365.' : 'Every day must be between 1 and 60.']; if (!isset($reg[(string)($r['ref'] ?? '')])) return [false, 'Choose a message for day ' . $d . '.']; $days[] = $d; }
    if (count($rows) > ($tk === 'buyer' ? 150 : 30)) return [false, 'At most ' . ($tk === 'buyer' ? 150 : 30) . ' steps in a track.'];
    wj_hist_push($pdo, 'You edited the ' . ($tk === 'lead' ? "“Didn't order”" : '“Ordered”') . ' track'); $plan['tracks'][$tk]['steps'] = $rows; wj_put($pdo, 'wj_plan', wj_plan_clean($plan, $reg)); return [true, 'Saved ✓'];
}

// ---------------------------------------------------------------------------------------------------------------------- the background part
function wj_tick(PDO $pdo): array
{
    $o = []; if ((string)setting_get($pdo, 'wj_bg', '1') === '0') return $o;   // kill switch: setting wj_bg = 0 stops all background work of the Journey
    if (!function_exists('fa_offers') || !wj_tables($pdo)) return $o;
    try { wj_install($pdo); } catch (Throwable $e) { error_log('wj_install: ' . $e->getMessage()); }
    if ((int)setting_get($pdo, 'wj_tpl_at', '0') < time() - 600 && function_exists('fa_tpl_setup')) { setting_set($pdo, 'wj_tpl_at', (string)time()); try { $o['tpl'] = fa_tpl_setup($pdo); } catch (Throwable $e) { error_log('wj tpl: ' . $e->getMessage()); } }
    try { jn_unify($pdo); jn_buyer_seed($pdo); } catch (Throwable $e) { }
    try { $o['anchor'] = wj_anchor_refresh($pdo); } catch (Throwable $e) { error_log('wj anchor: ' . $e->getMessage()); }
    if (wj_mode($pdo) === 'auto' && wa_lock($pdo, 'wjrun', 240)) { try { $o['run'] = wj_run($pdo); } catch (Throwable $e) { wa_log($pdo, 'followup', false, 'journey: ' . $e->getMessage()); } }
    /* a slow Claude call must never let a second copy start (wa_lock is only a time window) */
    if (wa_lock($pdo, 'wjnight', 300)) { try { $o['night'] = wj_night_step($pdo); } catch (Throwable $e) { error_log('wj_night: ' . $e->getMessage()); } }
    return $o;
}

}

// ---------------------------------------------------------------------------------------------------------------------- the screen's actions
$__wj = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wj, 'fu.wj_') === 0) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!function_exists('fu_tables') || !fu_tables($pdo) || !wj_tables($pdo) || !function_exists('fa_offers')) json_response(['error' => 'Journey storage is not available.'], 500);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    $me = (int)($auth['employee_id'] ?? 0); $in = $isPost ? json_input() : [];
    try { wj_install($pdo); } catch (Throwable $e) { }
    if ($__wj === 'fu.wj_get') json_response(wj_view($pdo));
    if ($__wj === 'fu.wj_msg_new') {   // ➕ a new message or offer made straight from a journey day: it lives in the one registry (Messages → My messages) and is chosen in every dropdown
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $label = mb_substr(trim((string)($in['label'] ?? '')), 0, 40); $rule = mb_substr(trim((string)($in['rule'] ?? '')), 0, 300); $offer = !empty($in['offer']); $body = trim((string)($in['body'] ?? ''));
        if ($label === '' || $rule === '') json_response(['error' => 'Give it a name (title) and a rule.'], 422);
        $reg = fa_offers($pdo); foreach ($reg as $x) if (mb_strtolower($x[0]) === mb_strtolower($label)) json_response(['error' => 'A message called "' . $label . '" already exists — pick it from the list, or use another name.'], 422);
        $k = ($offer ? 'o_' : 'w_') . substr(md5($label . microtime(true)), 0, 6); $reg[$k] = [$label, $rule];
        if ($body !== '') {
            preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $mm); if (array_map('intval', $mm[1]) !== [1, 2, 3]) json_response(['error' => 'Keep {{1}} (name), {{2}} (perfume) and {{3}} (the line) once each, in this order.'], 422);
            if (preg_match('/^\s*\{\{|\}\}\s*$/', $body)) json_response(['error' => 'Meta does not allow {{…}} at the very start or end — add a word before / after it.'], 422);
        } else $body = 'Hi {{1}} 🙏 About {{2}}: {{3}} Reply here and we will help you right away.';
        setting_set($pdo, 'fa_offers', json_encode(array_map(function ($x) { return [$x[0], $x[1]]; }, $reg), JSON_UNESCAPED_UNICODE)); setting_set($pdo, 'fa_tplbody_' . $k, mb_substr($body, 0, 900)); setting_set($pdo, 'fa_tpltry_' . $k, '0');
        json_response(['message' => 'Saved in the message library ✓ — it goes to Meta by itself in a few minutes.', 'new_key' => $k] + wj_view($pdo));
    }
    if ($__wj === 'fu.wj_change') { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $t = trim((string)($in['text'] ?? '')); if ($t === '') json_response(['error' => 'Write what you want to change.'], 422); [$ok, $r] = wj_change_scan($pdo, $t); if (!$ok) json_response(['error' => $r], 422); json_response(['message' => count($r['rows']) ? count($r['rows']) . ' related message(s) found — check, edit, then press Apply.' : 'No message in the library is touched by this.'] + wj_view($pdo)); }
    if ($__wj === 'fu.wj_change_apply') { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if (!empty($in['discard'])) { wj_put($pdo, 'wj_change', []); json_response(['message' => 'Dropped — nothing was changed.'] + wj_view($pdo)); } [$ok, $m] = wj_change_apply($pdo, (array)($in['rows'] ?? []), $me); json_response(['message' => $m] + wj_view($pdo)); }
    if ($__wj === 'fu.wj_change_undo') { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); [$ok, $m] = wj_change_undo($pdo, $me); if (!$ok) json_response(['error' => $m], 422); json_response(['message' => $m] + wj_view($pdo)); }
    if ($__wj === 'fu.jn_results') {   // real results: every lane step of the one engine + every after-order step
        $days = max(1, min(90, (int)($_GET['days'] ?? 30))); $a = function_exists('fa_results') ? fa_results($pdo, $days) : ['total' => [], 'steps' => []]; $b = wj_results($pdo, $days);
        $bs = array_values(array_filter($b['steps'], function ($s) { return $s['track'] === 'buyer'; })); $bt = ['sent' => 0, 'replied' => 0, 'ordered' => 0, 'sales' => 0.0]; foreach ($bs as $s) foreach ($bt as $k => $_) $bt[$k] += $s[$k];
        $at = $a['total'] + ['sent' => 0, 'replied' => 0, 'ordered' => 0, 'sales' => 0.0]; json_response(['ok' => true, 'days' => $days, 'lanes' => $a['steps'], 'buyer' => $bs, 'lane_total' => $at, 'buyer_total' => $bt,
            'total' => ['sent' => $at['sent'] + $bt['sent'], 'replied' => $at['replied'] + $bt['replied'], 'ordered' => $at['ordered'] + $bt['ordered'], 'sales' => $at['sales'] + $bt['sales']]]);
    }
    if ($__wj === 'fu.wj_queue') json_response(['ok' => true] + wj_queue($pdo));
    if ($__wj === 'fu.wj_results') json_response(['ok' => true] + wj_results($pdo, max(1, min(90, (int)($_GET['days'] ?? 30)))));
    if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
    switch ($__wj) {
        case 'fu.wj_mode': {
            $m = (string)($in['mode'] ?? ''); if (!in_array($m, ['off', 'suggest', 'auto'], true)) json_response(['error' => 'Choose Off, Preview or Auto.'], 422);
            if ($m === 'auto' && wj_mode($pdo) !== 'auto') setting_set($pdo, 'wj_auto_since', (string)time()); setting_set($pdo, 'wj_mode', $m); json_response(['message' => $m === 'auto' ? 'Auto is ON — messages go from today (no catching up of earlier days).' : 'Saved ✓'] + wj_view($pdo));
        }
        case 'fu.wj_cfg': { $c = wj_cfg($pdo); foreach (['gap_days', 'day_cap', 'run_cap', 'grace', 'catchup', 'lead_days'] as $k) if (isset($in[$k]) && is_numeric($in[$k])) $c[$k] = (int)$in[$k];
            foreach (['from', 'to'] as $k) if (isset($in[$k]) && preg_match('/^(\d{1,2}):(\d{2})$/', (string)$in[$k], $mm)) $c[$k] = (int)$mm[1] * 60 + (int)$mm[2]; wj_put($pdo, 'wj_cfg', $c); json_response(['message' => 'Saved ✓'] + wj_view($pdo)); }
        case 'fu.wj_steps_save': { [$ok, $m] = wj_steps_save($pdo, (string)($in['track'] ?? ''), (array)($in['steps'] ?? [])); if (!$ok) json_response(['error' => $m], 422); json_response(['message' => $m] + wj_view($pdo)); }
        case 'fu.wj_brain': { $t = trim((string)($in['text'] ?? '')); if ($t === '') json_response(['error' => 'Write what you want.'], 422); [$ok, $r] = wj_brain($pdo, $t, !empty($in['more'])); if (!$ok) json_response(['error' => $r], 422); json_response(['popup' => 1] + wj_view($pdo)); }
        case 'fu.wj_apply': {
            if (!empty($in['drop'])) { wj_put($pdo, 'wj_proposal', []); json_response(['message' => 'Stopped — nothing was changed.'] + wj_view($pdo)); }
            $P = wj_json($pdo, 'wj_proposal', []); if (empty($P['ops'])) json_response(['error' => 'There is nothing to start.'], 422);
            $ops = wj_ops_clean($pdo, (array)$P['ops'])[1]; [$ok, $m] = wj_ops_apply($pdo, $ops, mb_substr((string)($P['understood'] ?? 'Claude\'s change'), 0, 120)); if (!$ok) json_response(['error' => $m], 422);
            wj_put($pdo, 'wj_proposal', []); wj_put($pdo, 'wj_res_cache', []); json_response(['message' => $m] + wj_view($pdo));
        }
        case 'fu.wj_undo': { [$ok, $m] = wj_undo($pdo); if (!$ok) json_response(['error' => $m], 422); json_response(['message' => $m] + wj_view($pdo)); }
        case 'fu.wj_night_run': { wj_night_start($pdo, true); try { wj_night_step($pdo, true); } catch (Throwable $e) { } json_response(['message' => 'Claude is reading the chats now — the topics fill in over the next minutes.'] + wj_view($pdo)); }
        case 'fu.wj_fest': {
            $act = (string)($in['act'] ?? ''); $S = wj_json($pdo, 'wj_fest_sug', []); $done = wj_json($pdo, 'wj_fest_done', []);
            if ($act === 'add') { $n = trim((string)($in['name'] ?? '')); $d = (string)($in['date'] ?? ''); if ($n === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) json_response(['error' => 'Give a name and a date.'], 422); $C = wj_json($pdo, 'wj_fest_custom', []); $C[] = ['name' => mb_substr($n, 0, 40), 'date' => $d, 'theme' => mb_substr(trim((string)($in['theme'] ?? '')), 0, 160)]; wj_put($pdo, 'wj_fest_custom', array_slice($C, -40)); json_response(['message' => 'Added ✓'] + wj_view($pdo)); }
            if ($act === 'del') { $C = wj_json($pdo, 'wj_fest_custom', []); $C = array_values(array_filter($C, function ($f) use ($in) { return !(($f['name'] ?? '') === (string)($in['name'] ?? '') && ($f['date'] ?? '') === (string)($in['date'] ?? '')); })); wj_put($pdo, 'wj_fest_custom', $C); json_response(['message' => 'Removed ✓'] + wj_view($pdo)); }
            if (empty($S['name'])) json_response(['error' => 'There is no festival suggestion now.'], 422);
            if ($act === 'later') { setting_set($pdo, 'wj_fest_snooze', date('Y-m-d', time() + 3 * 86400)); wj_put($pdo, 'wj_fest_sug', []); json_response(['message' => 'I will ask again in 3 days.'] + wj_view($pdo)); }
            if ($act === 'skip') { $done[] = $S['name'] . '|' . $S['date']; wj_put($pdo, 'wj_fest_done', array_slice($done, -60)); wj_put($pdo, 'wj_fest_sug', []); json_response(['message' => 'Skipped ✓'] + wj_view($pdo)); }
            if ($act === 'yes') {
                $text = mb_substr(trim((string)($in['text'] ?? '')) ?: (string)$S['text'], 0, 300); $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['from'] ?? '')) ? (string)$in['from'] : (string)$S['from']; $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['to'] ?? '')) ? (string)$in['to'] : (string)$S['to'];
                wj_hist_push($pdo, 'Festival box: ' . $S['name']); setting_set($pdo, 'fa_season', json_encode(['on' => true, 'text' => $text, 'from' => $f, 'to' => $t], JSON_UNESCAPED_UNICODE));
                $done[] = $S['name'] . '|' . $S['date']; wj_put($pdo, 'wj_fest_done', array_slice($done, -60)); wj_put($pdo, 'wj_fest_sug', []); $msg = 'Festival box is ON for ' . $S['name'] . ' ✓';
                if (function_exists('convo_ai')) { [$ok] = wj_brain($pdo, "The festival box is now ON for " . $S['name'] . " (" . $S['date'] . "): “" . $text . "”, " . $f . " → " . $t . ". Update the registry rules and step ideas that mention an older festival so every message fits " . $S['name'] . " — change only the words, not the days — and say in 'questions' if a festival offer is needed.", false, 'festival'); if ($ok) $msg .= ' — Claude prepared the message changes for you to check.'; }
                json_response(['message' => $msg, 'popup' => 1] + wj_view($pdo));
            }
            json_response(['error' => 'Unknown choice.'], 422);
        }
        case 'fu.wj_tpl': { $n = 0; $bad = ''; foreach (array_keys(wj_reg($pdo)) as $k) { if (wj_tpl_state($pdo, $k) !== 'none') continue; [$ok, $m] = fa_tpl_make($pdo, $k, $me); if ($ok) $n++; else $bad = $m; } json_response(['message' => $n ? $n . ' message(s) sent to Meta ✓' : ($bad !== '' ? $bad : 'Every message is already at Meta.')] + wj_view($pdo)); }
    }
    json_response(['error' => 'Unknown action.'], 404);
}
