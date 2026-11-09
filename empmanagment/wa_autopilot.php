<?php
/**
 * wa_autopilot.php — 🔁 FOLLOW-UP AUTOPILOT (one screen: Learn & Setup → Follow-ups, WhatsApp → Automation → Follow-ups, Replies → Follow-up messages).
 * Built ON the follow-up engine (wa_follow.php): the same situations ("paths"), states, limits, stop rules, Suggest / Auto modes. This file adds:
 *   · the 6 PATHS of a customer who did not order (asked the price · got suggestions · price felt high · thinking / family · agreed but did not pay · never answered),
 *     each with 3–4 free follow-ups inside 24 hours (Claude writes each one from the chat) and the OFFERS on day 2 / 4 / 7 / 10 (mega);
 *   · the OFFER LADDER (Offer 1, 2, 3, Mega — the owner's rule for each) and one approved WhatsApp message per offer whose blanks Claude fills per customer
 *     ({{1}} name · {{2}} the perfume they asked about · {{3}} the offer, from the price chart, never below the lowest price);
 *   · the FESTIVAL / SEASON box (from – to): every message and offer uses it, then it switches back by itself;
 *   · "Teach this step" (per day card), the GENERAL SUMMARY (Claude proposes changes to every path — add a day 12, remove a step… — nothing changes before Approve);
 *   · RESULTS: per path and step — sent, replied, ordered, ₹ sales (an order within 7 days after a follow-up counts for the last one sent).
 * Actions (Admin / HR / control tower): fu.ap_get · fu.ap_install · fu.ap_path_save · fu.ap_step_teach · fu.ap_offers_save · fu.ap_season_save · fu.ap_summary · fu.ap_apply
 *          · fu.ap_results · fu.ap_tpl (send the offer messages to Meta) · fu.ap_mode. Loaded by wa_more.php after wa_fu_coach.php. PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('fa_paths')) {

/** The 6 paths with their default days. [after hours (from our last message), kind, offer key, the idea for Claude]. */
function fa_paths(): array
{
    $n = fn($h, $t) => [$h, 'nudge', '', $t]; $o = fn($h, $k) => [$h, 'offer', $k, ''];
    return [
        ['id' => 'f_quiet', 'key' => 'quiet', 'title' => 'Asked the price, then went quiet', 'desc' => 'Customer got the price or list and stopped replying.', 'priority' => 3,
         'steps' => [$n(0.5, 'Koi doubt ho to bataiye — kaunsi smell pasand aayi? Main best 2-3 suggest kar deta hu 😊'), $n(4, 'Budget ka koi issue ho to bataiye, main price mein bhi dekh lunga 🙏'), $n(20, 'Yeh perfume poore din tikta hai aur roz ke liye perfect hai 😊 Aapke liye ready kar du?'),
                     $o(48, 'o1'), $o(96, 'o2'), $o(168, 'o3'), $o(240, 'mega')]],
        ['id' => 'f_sugg', 'key' => 'sugg', 'title' => 'Got our suggestions, no reply', 'desc' => 'We sent perfume photos / suggestions / a list and the customer did not answer.', 'priority' => 2,
         'steps' => [$n(0.5, 'Sir ji, suggestion mein koi issue ho to bataiye — aur options bhej du? 😊'), $n(4, 'Inmein se koi ek pasand aaya? Price mein bhi dekh lunga aapke liye 🙏'), $n(20, 'Aapke liye sabse best wahi rahega jo maine pehle suggest kiya tha 😊 Kahiye to aaj hi ready kar du?'),
                     $o(48, 'o1'), $o(96, 'o2'), $o(168, 'o3'), $o(240, 'mega')]],
        ['id' => 'f_price', 'key' => 'price', 'title' => 'Price felt high', 'desc' => 'Customer said it is costly / asked for a discount / is comparing with other sites.', 'priority' => 2,
         'steps' => [$n(0.5, 'Samajh sakta hu 🙏 Chahein to chhota size ya budget wala option bata du — quality wahi rahegi.'), $n(4, 'Hamare perfumes long-lasting hain aur bahut saare customers roz use karte hain 😊 Ek baar try karke dekhiye.'), $n(20, 'Ek combo bhi hai jisme aapko zyada value milegi 🙏 Bataun?'),
                     $o(48, 'o1'), $o(96, 'o2'), $o(168, 'o3'), $o(240, 'mega')]],
        ['id' => 'f_ask', 'key' => 'ask', 'title' => 'Thinking / asking family', 'desc' => 'Customer will ask someone at home, think, or come back later.', 'priority' => 2,
         'steps' => [$n(20, 'Pooch liya? Koi sawal ho to bataiye, main help karta hu 😊'), $o(48, 'o1'), $o(120, 'o2'), $o(240, 'mega')]],
        ['id' => 'f_pay', 'key' => 'pay', 'title' => 'Agreed / sent the address, but did not pay', 'desc' => 'Customer accepted the price, asked how to pay or sent the address, then did not pay.', 'priority' => 1,
         'steps' => [$n(0.25, 'Aapka order ready hai — bas payment baki hai. UPI link bhej du?'), $n(2, 'Payment mein koi dikkat ho to bataiye — UPI, card ya COD, jo aasaan lage 😊'), $n(20, 'Aapka order aaj ke liye hold pe rakha hai 🙏 UPI, card ya COD — jo aasaan lage, bata dijiye.'),
                     $o(48, 'o1'), $o(120, 'mega')]],
        ['id' => 'f_first', 'key' => 'first', 'title' => 'Never answered our first reply', 'desc' => 'Customer wrote once and did not answer our first reply.', 'priority' => 4,
         'steps' => [$n(20, 'Namaste! Aapne puchha tha — kisi khaas occasion ya smell ke liye dhundh rahe ho? Main help karta hu 😊'), $o(48, 'o1'), $o(168, 'mega')]],
    ];
}
/** The offer ladder: key => [label, the owner's rule]. Saved in setting fa_offers. */
function fa_offers_default(): array
{
    return ['o1' => ['Offer 1', 'A free 10 ml decant with the perfume they asked about'], 'o2' => ['Offer 2', 'The "Offer" column price of the price chart for the size they asked about'],
        'o3' => ['Offer 3', 'A combo / bundle price (a second perfume or a smaller size together) from the price chart'], 'mega' => ['Mega offer', 'Our lowest allowed price (the "Campaign" column), valid today only']];
}
function fa_offers(PDO $pdo): array
{
    $d = fa_offers_default(); $j = json_decode((string)setting_get($pdo, 'fa_offers', ''), true); if (!is_array($j)) return $d;
    foreach ($j as $k => $x) if (is_array($x) && preg_match('/^[a-z0-9_]{1,12}$/', (string)$k)) $d[$k] = [mb_substr(trim((string)($x[0] ?? $k)), 0, 40) ?: (string)$k, mb_substr(trim((string)($x[1] ?? '')), 0, 300)];
    return $d;
}
function fa_offer_rule(PDO $pdo, string $k): string { $O = fa_offers($pdo); return isset($O[$k]) ? $O[$k][0] . ' — ' . $O[$k][1] : 'a special offer within the owner\'s limits'; }
/** The festival / season line while it is on and today is inside its dates ('' otherwise). */
function fa_season(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'fa_season', ''), true); return is_array($j) ? $j + ['on' => false, 'text' => '', 'from' => '', 'to' => ''] : ['on' => false, 'text' => '', 'from' => '', 'to' => '']; }
function fa_season_text(PDO $pdo): string
{
    $s = fa_season($pdo); $t = date('Y-m-d');
    return (!empty($s['on']) && trim((string)$s['text']) !== '' && ($s['from'] === '' || $t >= $s['from']) && ($s['to'] === '' || $t <= $s['to'])) ? trim((string)$s['text']) : '';
}
/** Did we send a price after the customer's last message? */
function fa_sent_price(PDO $pdo, array $c): bool
{
    try { $q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'out' AND who NOT IN ('campaign','order') AND ts >= ? ORDER BY id DESC LIMIT 6"); $q->execute([(string)$c['phone'], (string)($c['last_in_at'] ?? '')]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $b) if (preg_match('/₹\s?\d|\brs\.?\s?\d|\binr\s?\d|\d{3,5}\s?(rs|rupees|\/-)/iu', (string)$b)) return true; } catch (Throwable $e) { }
    return false;
}
/** Did we send suggestions (photos / documents / a list of perfumes) after the customer's last message? */
function fa_sent_suggestions(PDO $pdo, array $c): bool
{
    try { $q = $pdo->prepare("SELECT mtype, body FROM wa_messages WHERE phone = ? AND dir = 'out' AND who NOT IN ('campaign','order') AND ts >= ? ORDER BY id DESC LIMIT 6"); $q->execute([(string)$c['phone'], (string)($c['last_in_at'] ?? '')]);
        foreach ($q->fetchAll() as $m) { if (in_array((string)$m['mtype'], ['image', 'video', 'document'], true)) return true; if (preg_match('/suggest|option|recommend|try (this|these)|best for you|👉|1\.|①/iu', (string)$m['body']) && substr_count((string)$m['body'], "\n") >= 2) return true; }
    } catch (Throwable $e) { }
    return false;
}

// ---------------------------------------------------------------------------------------------------------------------- the offer messages (one approved template per offer)
function fa_tpl_name(string $k): string { return 'tmph_fu_' . preg_replace('/[^a-z0-9_]/', '', $k); }
function fa_tpl_bodies(): array
{
    return ['o1' => "Hi {{1}} 🙏 Still thinking about {{2}}? Just for you: {{3}}. Reply here and we will keep it ready for you.",
        'o2' => "Hello {{1}}, a special price on {{2}} for you: {{3}}. Shall I book it for you? Just reply here 🙂",
        'o3' => "Hi {{1}}! Something you will love with {{2}}: {{3}}. Reply here and we will send it today.",
        'mega' => "Hi {{1}}, our biggest offer on {{2}}: {{3}}. Valid today only — reply YES to order 🎁"];
}
function fa_tpl_body(PDO $pdo, string $k): string { $own = (string)setting_get($pdo, 'fa_tplbody_' . $k, ''); if ($own !== '') return $own; $B = fa_tpl_bodies(); return $B[$k] ?? "Hi {{1}}, a special offer on {{2}} for you: {{3}}. Reply here to get it 🙏"; }
/** Make + send to Meta the offer message of one offer key (a new version when the words changed). [ok, message]. */
function fa_tpl_make(PDO $pdo, string $k, int $by = 0): array
{
    if (!function_exists('wa_tpl_clean') || !function_exists('wa_tpl_insert') || !function_exists('wa_tpl_create_at')) return [false, 'The WhatsApp message module is not available.'];
    $body = fa_tpl_body($pdo, $k); $names = json_decode((string)setting_get($pdo, 'fa_tplnames_' . $k, '[]'), true); if (!is_array($names)) $names = [];
    $name = fa_tpl_name($k) . ($names ? '_v' . (count($names) + 1) : '');
    $ph = (int)setting_get($pdo, 'fa_tplphoto_' . $k, '0');   // 📷 an optional photo on top of the message (from the media library); a new photo = a new version at Meta
    [$t, $err] = wa_tpl_clean(['name' => $name, 'language' => 'en', 'category' => 'MARKETING', 'body' => $body, 'footer' => 'TM Perfume House'] + ($ph > 0 ? ['header_format' => 'IMAGE', 'header_lib_id' => $ph] : []) + [
        'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Yes, I want it'], ['type' => 'QUICK_REPLY', 'text' => 'Not now']],
        'examples' => ['body' => ['Rahul', 'Dior Sauvage 50 ml', 'a free 10 ml decant with your order at ₹899'], 'labels' => ['Name', 'Perfume', 'Offer']], 'folder' => 'Follow-ups']);
    if ($err !== '') return [false, $err];
    $id = wa_tpl_insert($pdo, $t, $by, 'Follow-up offer (' . (fa_offers($pdo)[$k][0] ?? $k) . ') to a customer who asked about a perfume and did not order.', 'Marketing offer to an interested customer.');
    $r = wa_tpl_row($pdo, $id); if (!$r) return [false, 'Could not save the message.'];
    $names[] = (string)$r['name']; setting_set($pdo, 'fa_tplnames_' . $k, json_encode(array_slice($names, -10)));
    $ok = 0; $bad = []; foreach ((function_exists('wa_wabas') ? wa_wabas($pdo) : []) as $w) { [$s, $why] = wa_tpl_create_at($pdo, $r, $w); if ($s) $ok++; else $bad[] = $why; }
    return $ok ? [true, 'Sent to Meta ✅'] : [false, $bad ? implode(' · ', array_unique($bad)) : 'No WhatsApp account is connected.'];
}
/** The approved offer template (newest approved version) or null. */
function fa_tpl_pick(PDO $pdo, string $k): ?array
{
    if (!function_exists('wa_camp_tpl')) return null; $names = json_decode((string)setting_get($pdo, 'fa_tplnames_' . $k, '[]'), true); if (!is_array($names)) return null;
    foreach (array_reverse($names) as $n) { try { $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$n]); $id = (int)$q->fetchColumn(); } catch (Throwable $e) { $id = 0; } $t = $id ? wa_camp_tpl($pdo, $id) : null; if ($t) return $t; }
    return null;
}
function fa_tpl_status(PDO $pdo, string $k): string
{
    $names = json_decode((string)setting_get($pdo, 'fa_tplnames_' . $k, '[]'), true); if (!is_array($names) || !$names || !function_exists('wa_tpl_status')) return 'none';
    $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $first = '';
    foreach (array_reverse($names) as $i => $n) { $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$n]); $id = (int)$q->fetchColumn(); if (!$id) continue;
        $s = (string)wa_tpl_status($pdo, $id, $wabas)['status']; if ($s === 'approved' || $s === 'partial') return $i === 0 ? 'approved' : 'approved_old'; if ($first === '') $first = $s; }
    return $first ?: 'none';
}
/** Every offer message that is missing at Meta is made (from the background, a few at a time). */
function fa_tpl_setup(PDO $pdo): int
{
    $n = 0; foreach (array_keys(fa_offers($pdo)) as $k) { if (fa_tpl_status($pdo, $k) !== 'none') continue; if ((int)setting_get($pdo, 'fa_tpltry_' . $k, '0') > time() - 6 * 3600) continue;
        setting_set($pdo, 'fa_tpltry_' . $k, (string)time()); if (fa_tpl_make($pdo, $k)[0]) $n++; if ($n >= 3) break; }
    return $n;
}

// ---------------------------------------------------------------------------------------------------------------------- an offer for ONE customer (Claude fills the blanks)
/** [perfume, offer line] for this chat. Claude reads the chat + price chart + limits + season; the line is checked like every follow-up (no invented numbers). */
function fa_offer_fill(PDO $pdo, array $c, string $k): array
{
    $perf = ''; $line = ''; $msgs = function_exists('fu_conv') ? fu_conv($pdo, (string)$c['phone']) : [];
    $facts = ''; $allowed = []; if (function_exists('wk_knowledge')) { $K = wk_knowledge($pdo, $msgs); $facts = $K['text']; $allowed = $K['allowed']; }   // 🧠 one knowledge for every writer
    $season = fa_season_text($pdo); $rule = fa_offer_rule($pdo, $k);
    if (function_exists('convo_ai') && $msgs) {
        $conv = ''; foreach (array_slice($msgs, -14) as $m) $conv .= ($m['who'] === 'customer' ? 'Customer: ' : 'Shop: ') . $m['text'] . "\n";
        $sch = ['type' => 'OBJECT', 'properties' => ['perfume' => ['type' => 'STRING'], 'offer' => ['type' => 'STRING']], 'required' => ['perfume', 'offer']];
        $sys = "You fill two blanks of an approved WhatsApp offer message of TM Perfume House (Indian perfume brand) for ONE customer who asked about perfume and did not order. "
            . "perfume = the perfume (and size) THIS customer asked about or liked most, as they would recognise it, max 40 letters (if unclear: 'your favourite perfume'). "
            . "offer = the offer in one short phrase, max 90 letters, no new lines, following the OWNER'S OFFER RULE exactly, with the real price from the STORE FACTS / price chart when a price is part of it. "
            . "Never invent a number, never go below the owner's lowest price, never mention percentages." . ($season !== '' ? " Use this season / festival in the wording: " . $season . '.' : '');
        for ($i = 0; $i < 2; $i++) {
            try { [$ok, $v] = convo_ai($sys, [['text' => ($facts !== '' ? $facts . "\n" : '') . "OWNER'S OFFER RULE: $rule\nCONVERSATION:\n$conv\nFill the blanks."]], $sch, false, 400, 'claude'); } catch (Throwable $e) { $ok = false; $v = null; }
            if (!$ok || !is_array($v)) break;
            $p = trim(preg_replace('/\s+/u', ' ', mb_substr((string)($v['perfume'] ?? ''), 0, 40))); $l = trim(preg_replace('/\s+/u', ' ', mb_substr((string)($v['offer'] ?? ''), 0, 90))); $why = [];
            if ($p !== '' && $l !== '' && (!function_exists('fu_text_ok') || fu_text_ok($pdo, $p . ' ' . $l, $allowed, $why))) { $perf = $p; $line = $l; break; }
        }
    }
    if ($perf === '') $perf = 'your favourite perfume';
    if ($line === '') { $O = fa_offers($pdo); $line = mb_strtolower(preg_replace('/\s*\(.*?\)|\s*—.*$/u', '', (string)($O[$k][1] ?? 'a special offer just for you'))); if (preg_match('/\d/', $line)) $line = 'an exclusive price, only for you'; }   // no Claude: the rule in plain words, never a number we did not check
    return [$perf, $line];
}
/** An offer inside the 24-hour window when Claude is not available: one ready sentence (same blanks as the approved message). */
function fa_offer_text(PDO $pdo, array $c, string $k): string
{
    [$perf, $line] = fa_offer_fill($pdo, $c, $k); $name = function_exists('fu_first_name') ? fu_first_name((string)($c['name'] ?? '')) : '';
    $t = str_replace(['{{1}}', '{{2}}', '{{3}}'], [$name !== '' ? $name : 'ji', $perf, $line], fa_tpl_body($pdo, $k)); return trim(preg_replace('/\s+/u', ' ', $t));
}
/** After 24 hours: an offer step goes as the approved offer message with this customer's perfume and offer. [ok, shown text | why]. */
function fa_offer_send(PDO $pdo, array $c, array $st, array $sit, array $step): array
{
    $k = (string)($step['offer'] ?? 'o1'); $t = fa_tpl_pick($pdo, $k);
    if (!$t) return [false, 'The "' . (fa_offers($pdo)[$k][0] ?? $k) . '" message is not approved by Meta yet — it goes by itself once approved.'];
    [$perf, $line] = fa_offer_fill($pdo, $c, $k); $name = function_exists('fu_first_name') ? fu_first_name((string)($c['name'] ?? '')) : ''; if ($name === '') $name = 'there';
    $ctx = wa_camp_ctx($pdo, $t, 2); [$nid, $why] = wa_pick_number($pdo, $c, $ctx); if (!$nid) return [false, 'No number has room now (' . $why . ').'];
    $vars = array_slice([$name, $perf, $line], 0, (int)$t['nvars']);
    [$ok, $res] = wa_send_tpl_one($pdo, $c, $t, $ctx['nums'][$nid], $vars, 'followup'); $shown = function_exists('cx6_render') ? cx6_render((string)$t['body'], $vars) : (string)$t['body'];
    if (!function_exists('cx6_render')) foreach ($vars as $i => $v) $shown = str_replace('{{' . ($i + 1) . '}}', $v, $shown);
    if (function_exists('fu_ev')) fu_ev($pdo, (string)$c['phone'], (string)$st['sid'], (int)$st['step'], 'tpl', $ok ? 'sent' : 'failed', $shown, 0, (int)$st['in_id'], 'bot');
    if (!$ok) { if (function_exists('wa_log')) wa_log($pdo, 'followup', false, $c['phone'] . ' · offer ' . $k . ' · ' . $res); return [false, (string)$res]; }
    return [true, $shown];
}

// ---------------------------------------------------------------------------------------------------------------------- the plan
function fa_mk(array $p): array
{
    $steps = []; foreach ($p['steps'] as $i => $x) $steps[] = ['n' => $i + 1, 'after_h' => (float)$x[0], 'kind' => $x[1], 'offer' => $x[2], 'text' => $x[3], 'remark' => ''];
    return fu_sit_clean(['id' => $p['id'], 'key' => $p['key'], 'title' => $p['title'], 'desc' => $p['desc'], 'kind' => 'nudge', 'stage' => 'saved', 'on' => true, 'priority' => $p['priority'], 'steps' => $steps, 'created_at' => fu_now(), 'updated_at' => fu_now()]);
}
/** Put the Autopilot plan live: the 6 paths (+ "angry = stop"); the old plan is kept as a backup. Mode Off → Suggest only (3 days to check the wording). */
function fa_install(PDO $pdo, bool $force = false): array
{
    if (!$force && (string)setting_get($pdo, 'fa_installed', '') !== '') return ['ok' => true, 'already' => true];
    $old = fu_plans_get($pdo); if ($old['situations']) setting_set($pdo, 'fa_backup_' . date('Ymd_His'), json_encode($old, JSON_UNESCAPED_UNICODE));
    $sits = []; foreach (fa_paths() as $p) $sits[] = fa_mk($p);
    foreach ($old['situations'] as $s) if ($s['id'] === 'f_angry') $sits[] = $s; if (!array_filter($sits, fn($s) => $s['id'] === 'f_angry')) foreach (fu_defaults() as $d) if ($d['id'] === 'f_angry') $sits[] = fu_sit_clean(fu_mk_sit($d, 'saved'));
    fu_plans_put($pdo, ['situations' => $sits]);
    setting_set($pdo, 'fu_plan_limits', json_encode(['max' => 10, 'day_max' => 3, 'gap_min' => 15, 'quiet' => [21 * 60, 9 * 60]]));   // 3–4 nudges + 4 offers; nothing 9 PM – 9 AM
    if (fu_mode($pdo) === 'off') { setting_set($pdo, 'wa_fu_mode', 'suggest'); setting_set($pdo, 'fa_suggest_since', date('Y-m-d H:i:s')); }
    setting_set($pdo, 'fa_installed', date('Y-m-d H:i:s'));
    return ['ok' => true, 'paths' => count($sits)];
}
/** The screen's data. */
function fa_view(PDO $pdo): array
{
    $P = function_exists('fuc_plan_view') ? fuc_plan_view($pdo) : fu_plans_public($pdo); $O = fa_offers($pdo); $offers = [];
    foreach ($O as $k => $x) $offers[] = ['k' => $k, 'label' => $x[0], 'rule' => $x[1], 'body' => fa_tpl_body($pdo, $k), 'status' => fa_tpl_status($pdo, $k)];
    $L = fu_limits($pdo);
    return ['ok' => true, 'installed' => (string)setting_get($pdo, 'fa_installed', ''), 'mode' => fu_mode($pdo), 'paths' => array_values(array_filter($P['situations'], fn($s) => $s['kind'] !== 'stop')),
        'offers' => $offers, 'season' => fa_season($pdo), 'season_now' => fa_season_text($pdo), 'proposal' => json_decode((string)setting_get($pdo, 'fa_proposal', ''), true),
        'limits' => ['max' => $L['max'], 'day_max' => $L['day_max'], 'gap_min' => $L['gap_min'], 'quiet' => sprintf('%02d:%02d–%02d:%02d', intdiv($L['quiet'][0], 60), $L['quiet'][0] % 60, intdiv($L['quiet'][1], 60), $L['quiet'][1] % 60)],
        'ai' => function_exists('convo_ai')];
}
/** Save the days of one path (the screen sends the whole list). */
function fa_path_save(PDO $pdo, string $sid, array $steps, ?bool $on = null): array
{
    $P = fu_plans_get($pdo); $i = fu_sit_find($P, $sid); if ($i === null) return [false, 'Path not found.'];
    $O = fa_offers($pdo); $clean = [];
    foreach (array_slice($steps, 0, 24) as $x) {
        $h = (float)($x['after_h'] ?? 0); if ($h <= 0 || $h > 24 * 60) return [false, 'Every step needs a time between a few minutes and 60 days.'];
        $kind = ($x['kind'] ?? '') === 'offer' ? 'offer' : 'nudge'; $ok = (string)($x['offer'] ?? ''); if ($kind === 'offer' && !isset($O[$ok])) return [false, 'Choose which offer goes on that day.'];
        $clean[] = ['after_h' => $h, 'kind' => $kind, 'offer' => $kind === 'offer' ? $ok : '', 'text' => mb_substr(trim((string)($x['text'] ?? '')), 0, 700), 'remark' => mb_substr(trim((string)($x['remark'] ?? '')), 0, 200), 'ch' => $h >= 23.5 ? 'tpl' : 'free'];
    }
    if (!$clean) return [false, 'Keep at least one day.'];
    $s = $P['situations'][$i]; $s['steps'] = $clean; if ($on !== null) $s['on'] = $on; $s['stage'] = 'saved'; $s['updated_at'] = fu_now(); $P['situations'][$i] = fu_sit_clean($s); fu_plans_put($pdo, $P);
    return [true, 'Saved ✓'];
}
/** "Teach this step": the owner's words for one day ("instead of a free 10 ml, give a combo"). Kept as the step's rule; Claude follows it for that day only. */
function fa_step_teach(PDO $pdo, string $sid, int $n, string $text): array
{
    $P = fu_plans_get($pdo); $i = fu_sit_find($P, $sid); if ($i === null) return [false, 'Path not found.']; $s = $P['situations'][$i]; $j = $n - 1; if (!isset($s['steps'][$j])) return [false, 'Day not found.'];
    $s['steps'][$j]['remark'] = mb_substr(trim($text), 0, 200);
    if (($s['steps'][$j]['kind'] ?? '') !== 'offer' && trim($text) !== '' && function_exists('fu_ai_rewrite')) { try { $r = fu_ai_rewrite($pdo, $s, $s['steps'], $text, $n); if (is_array($r) && isset($r[$j]['text'])) $s['steps'] = $r; } catch (Throwable $e) { } }   // Claude rewrites this day's idea (the rule stays even when Claude is not available)
    $s['updated_at'] = fu_now(); $P['situations'][$i] = fu_sit_clean($s); fu_plans_put($pdo, $P);
    return [true, 'Taught ✓ — this day follows your words now.'];
}
/** GENERAL SUMMARY: Claude reads the owner's words + the whole plan and PROPOSES a new plan (days added / removed / moved, ideas, offers). Nothing changes before Approve. */
function fa_summary(PDO $pdo, string $text, bool $more = false, string $scope = ''): array
{
    if (!function_exists('convo_ai')) return [false, 'Claude is not available.'];
    $prev = $more ? json_decode((string)setting_get($pdo, 'fa_proposal', ''), true) : null; $turns = is_array($prev['turns'] ?? null) ? $prev['turns'] : [];
    if (!$more && $scope !== '') $turns = []; $scope = $more && is_array($prev) ? (string)($prev['scope'] ?? '') : $scope;
    $V = fa_view($pdo); $O = fa_offers($pdo); $plan = '';
    foreach ($V['paths'] as $p) { $plan .= "PATH " . $p['id'] . " — " . $p['title'] . ":\n"; foreach ($p['steps'] as $x) $plan .= "  after " . $x['after_h'] . " h · " . ($x['kind'] === 'offer' ? 'OFFER ' . $x['offer'] : 'message: ' . $x['text']) . ($x['remark'] !== '' ? ' (owner taught: ' . $x['remark'] . ')' : '') . "\n"; }
    $ol = ''; foreach ($O as $k => $x) $ol .= "  $k = " . $x[0] . ': ' . $x[1] . "\n";
    $sch = ['type' => 'OBJECT', 'properties' => ['understood' => ['type' => 'STRING'], 'questions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'changes' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'paths' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
        'id' => ['type' => 'STRING'], 'steps' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['after_h' => ['type' => 'NUMBER'], 'kind' => ['type' => 'STRING'], 'offer' => ['type' => 'STRING'], 'text' => ['type' => 'STRING'], 'remark' => ['type' => 'STRING']], 'required' => ['after_h', 'kind', 'offer', 'text', 'remark']]]], 'required' => ['id', 'steps']]]],
        'required' => ['understood', 'questions', 'changes', 'paths']];
    $sys = "You manage the WhatsApp follow-up plan of TM Perfume House for customers who did not order. The owner tells you, in Hindi / Gujarati / English, how to change it. "
        . "Return the WHOLE new plan: for every path its days in time order (after_h = hours after our last message; inside 24 h = free messages, after 24 h = offers). kind = nudge (a message, text = the idea) or offer (offer = one key of the OFFER LADDER, text = ''). "
        . "You may add days (up to 10 per path, up to 30 days), remove or move them, change ideas and which offer goes when — only what the owner asks. remark = the owner's rule for that day ('' if none). "
        . "understood = 1–3 sentences of what you understood; changes = each change in one short line; questions = up to 4 short questions for anything unclear that you need the owner to decide (e.g. which paths, exact days, which offer) — [] when everything is clear. "
        . "IMPORTANT: after 24 h a free message cannot reach the customer, so any day after 24 h MUST be an offer (kind=offer); if the owner wants different words there (e.g. 'last discount'), make it an offer and put their words in remark. "
        . "If the owner asks why a day says what it says, explain it in 'understood' in plain simple words. "
        . "When something is unclear, still return your best plan for the clear parts and ask about the rest (empty paths is fine if nothing is clear yet). Keep paths you were not asked to change exactly as they are.";
    $hist = ''; foreach (array_slice($turns, -8) as $t) $hist .= ($t['who'] === 'you' ? 'OWNER: ' : 'YOU UNDERSTOOD: ') . $t['text'] . "\n";
    $ask = ($scope !== '' ? "THIS IS ABOUT: " . $scope . " (change only this path unless the owner clearly asks for more; you may move this day's time, change it, add or remove days of this path)\n" : '')
         . ($hist !== '' ? "EARLIER IN THIS CONVERSATION (the owner is correcting / adding to it — build on it):\n" . $hist : '') . "THE OWNER SAYS NOW:\n" . mb_substr($text, 0, 2000);
    // 🧠 everything the owner has already taught (store facts, selling rules, price chart, limits, the perfume lists): Claude reads it before it changes a day or writes a message
    $know = '';
    try {
        if (function_exists('teach_facts_prompt')) { [$kf] = teach_facts_prompt($pdo, []); $know .= (string)$kf . "\n"; }
        if (function_exists('teach_limits_text')) $know .= (string)teach_limits_text($pdo) . "\n";
        $KL = $pdo->query("SELECT name FROM wa_lists ORDER BY grp, name LIMIT 30")->fetchAll(PDO::FETCH_COLUMN); if ($KL) $know .= "READY PERFUME LISTS (a day may say: send the “…” list): " . implode(' · ', $KL) . "\n";
    } catch (Throwable $e) { /* the teach pieces are not on this install: Claude works with the plan only */ }
    $sys .= " Follow the facts, prices and rules the owner has ALREADY TAUGHT (shown below); never invent a price, an offer or a store fact that is not there — ask instead.";
    try { [$ok, $v] = convo_ai($sys, [['text' => ($know !== '' ? "WHAT THE OWNER HAS ALREADY TAUGHT (store facts, selling rules, price chart, limits):\n" . mb_substr($know, 0, 6000) . "\n" : '') . "OFFER LADDER:\n$ol\nCURRENT PLAN:\n$plan\n" . $ask]], $sch, false, 4000, 'claude'); } catch (Throwable $e) { return [false, 'Claude could not answer — try again.']; }
    if (!$ok || !is_array($v) || (empty($v['paths']) && trim((string)($v['understood'] ?? '')) === '' && empty($v['questions']))) return [false, 'Claude could not make a plan from that — ' . (is_string($v) ? $v : 'say it a little more clearly.')];
    // Claude may only have QUESTIONS (nothing clear enough to change yet) — a valid answer: the pop-up shows them and the owner replies
    if (trim((string)($v['understood'] ?? '')) === '') $v['understood'] = 'I need a little more detail before I can change anything.';
    $v['paths'] = is_array($v['paths'] ?? null) ? $v['paths'] : [];
    $turns[] = ['who' => 'you', 'text' => mb_substr($text, 0, 2000)]; $turns[] = ['who' => 'claude', 'text' => (string)$v['understood']];
    $prop = ['asked' => mb_substr($text, 0, 2000), 'understood' => (string)$v['understood'], 'changes' => array_slice(array_map('strval', (array)$v['changes']), 0, 30), 'paths' => (array)$v['paths'], 'questions' => array_slice(array_map('strval', (array)($v['questions'] ?? [])), 0, 4), 'at' => date('Y-m-d H:i:s'), 'turns' => array_slice($turns, -12), 'scope' => $scope];
    setting_set($pdo, 'fa_proposal', json_encode($prop, JSON_UNESCAPED_UNICODE)); return [true, $prop];
}
function fa_apply(PDO $pdo): array
{
    $prop = json_decode((string)setting_get($pdo, 'fa_proposal', ''), true); if (!is_array($prop) || empty($prop['paths'])) return [false, 'There is nothing to approve.'];
    $n = 0; $bad = []; foreach ($prop['paths'] as $p) { if (!is_array($p) || empty($p['id'])) continue; [$ok, $m] = fa_path_save($pdo, (string)$p['id'], (array)($p['steps'] ?? [])); if ($ok) $n++; else $bad[] = $p['id'] . ': ' . $m; }
    setting_set($pdo, 'fa_proposal', ''); return $n ? [true, 'Approved ✓ — ' . $n . ' path(s) updated' . ($bad ? ' (not changed: ' . implode('; ', $bad) . ')' : '')] : [false, $bad ? implode('; ', $bad) : 'Nothing changed.'];
}
/** RESULTS per path and day: sent, replied (within 2 days), ordered (within 7 days, credited to the last follow-up before the order), ₹ sales. */
function fa_results(PDO $pdo, int $days): array
{
    $since = date('Y-m-d H:i:s', time() - $days * 86400); $rows = [];
    try { $q = $pdo->prepare("SELECT phone, sid, step, channel, sent_at FROM wa_fu_ev WHERE status = 'sent' AND sent_at >= ? ORDER BY sent_at"); $q->execute([$since]); $rows = $q->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { }
    $byPhone = []; foreach ($rows as $r) $byPhone[(string)$r['phone']][] = $r;
    $orders = [];
    if ($byPhone) { try { $ph = array_keys($byPhone); $in = implode(',', array_fill(0, count($ph), '?'));
        $q = $pdo->prepare("SELECT RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) p, created_at, total FROM shop_orders WHERE created_at >= ? AND RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) IN ($in)"); $q->execute(array_merge([$since], $ph));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) $orders[(string)$o['p']][] = $o; } catch (Throwable $e) { } }
    $S = []; $tot = ['sent' => 0, 'replied' => 0, 'ordered' => 0, 'sales' => 0.0, 'tpl' => 0];
    foreach ($rows as $r) { $k = $r['sid'] . '|' . $r['step']; $S[$k] = $S[$k] ?? ['sid' => $r['sid'], 'step' => (int)$r['step'], 'sent' => 0, 'replied' => 0, 'ordered' => 0, 'sales' => 0.0]; $S[$k]['sent']++; $tot['sent']++; if ($r['channel'] === 'tpl') $tot['tpl']++;
        if (function_exists('fu_replied') && fu_replied($pdo, (string)$r['phone'], (string)$r['sent_at'])) { $S[$k]['replied']++; $tot['replied']++; } }
    foreach ($orders as $p => $L) foreach ($L as $o) { $ot = strtotime((string)$o['created_at']); $last = null;
        foreach ($byPhone[$p] ?? [] as $r) { $st = strtotime((string)$r['sent_at']); if ($st <= $ot && $st >= $ot - 7 * 86400) $last = $r; }
        if ($last) { $k = $last['sid'] . '|' . $last['step']; $S[$k]['ordered']++; $S[$k]['sales'] += (float)$o['total']; $tot['ordered']++; $tot['sales'] += (float)$o['total']; } }
    return ['days' => $days, 'total' => $tot, 'steps' => array_values($S)];
}

}

// ---------------------------------------------------------------------------------------------------------------------- the background part (from the WhatsApp engine)
if (!function_exists('fa_tick')) {
function fa_tick(PDO $pdo): void
{
    if ((string)setting_get($pdo, 'fa_installed', '') === '') { try { if (!fu_plans_get($pdo)['situations'] || (string)setting_get($pdo, 'fa_auto_install', '1') === '1') fa_install($pdo); } catch (Throwable $e) { } }   // the Autopilot replaces the old plan once
    if ((int)setting_get($pdo, 'fa_tpl_at', '0') < time() - 600) { setting_set($pdo, 'fa_tpl_at', (string)time()); try { fa_tpl_setup($pdo); } catch (Throwable $e) { error_log('fa_tpl_setup: ' . $e->getMessage()); } }
}
}

// ---------------------------------------------------------------------------------------------------------------------- the screen's actions
$__fa = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__fa, 'fu.ap_') === 0) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!function_exists('fu_tables') || !fu_tables($pdo)) json_response(['error' => 'Follow-up storage is not available.'], 500);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    $me = (int)($auth['employee_id'] ?? 0); $in = $isPost ? json_input() : [];
    if (function_exists('fa_tick')) { try { fa_tick($pdo); } catch (Throwable $e) { } }
    if ($__fa === 'fu.ap_get') json_response(fa_view($pdo));
    if ($__fa === 'fu.ap_results') json_response(['ok' => true] + fa_results($pdo, max(1, min(90, (int)($_GET['days'] ?? 30)))));
    if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
    switch ($__fa) {
        case 'fu.ap_install': json_response(fa_install($pdo, true) + fa_view($pdo));
        case 'fu.ap_mode': { $m = (string)($in['mode'] ?? ''); if (!in_array($m, ['off', 'suggest', 'autotpl'], true)) json_response(['error' => 'Choose Off, Suggest only or Auto.'], 422); setting_set($pdo, 'wa_fu_mode', $m); json_response(fa_view($pdo)); }
        case 'fu.ap_path_save': { [$ok, $m] = fa_path_save($pdo, (string)($in['sid'] ?? ''), (array)($in['steps'] ?? []), array_key_exists('on', $in) ? !empty($in['on']) : null); if (!$ok) json_response(['error' => $m], 422); json_response(['message' => $m] + fa_view($pdo)); }
        case 'fu.ap_step_teach': {
            $sid = (string)($in['sid'] ?? ''); $n = (int)($in['n'] ?? 0); $t = trim((string)($in['text'] ?? '')); if ($t === '') json_response(['error' => 'Write what this day should do.'], 422);
            $P = fu_plans_get($pdo); $i = fu_sit_find($P, $sid); $x = $i !== null ? ($P['situations'][$i]['steps'][$n - 1] ?? null) : null; if (!$x) json_response(['error' => 'Day not found.'], 404);
            $scope = 'path "' . $P['situations'][$i]['title'] . '" (id ' . $sid . '), day #' . $n . ' — now after ' . round($x['after_h'], 2) . ' hours, ' . (($x['kind'] ?? '') === 'offer' ? 'OFFER ' . $x['offer'] : 'message: ' . $x['text']);
            [$ok, $r] = function_exists('convo_ai') ? fa_summary($pdo, $t, false, $scope) : [false, 'no Claude'];
            if ($ok) json_response(['popup' => 1] + fa_view($pdo));   // 🧠 the pop-up shows what Claude understood; Approve applies it
            [$ok2, $m] = fa_step_teach($pdo, $sid, $n, $t); if (!$ok2) json_response(['error' => $m], 422);
            json_response(['message' => 'Claude could not answer right now (' . (is_string($r) ? mb_substr($r, 0, 120) : '') . ') — your words are saved as the rule for this day.'] + fa_view($pdo));
        }
        case 'fu.ap_path_teach': {   // 🧠 teach a WHOLE path in one go (times, words, offers of all its days) — same pop-up, nothing changes before Approve
            $sid = (string)($in['sid'] ?? ''); $t = trim((string)($in['text'] ?? '')); if ($t === '') json_response(['error' => 'Write what this path should do.'], 422);
            $P = fu_plans_get($pdo); $i = fu_sit_find($P, $sid); if ($i === null) json_response(['error' => 'Path not found.'], 404);
            $scope = 'the WHOLE path "' . $P['situations'][$i]['title'] . '" (id ' . $sid . ') — all its days';
            [$ok, $r] = function_exists('convo_ai') ? fa_summary($pdo, $t, false, $scope) : [false, 'Claude is not available.'];
            if (!$ok) json_response(['error' => is_string($r) ? $r : 'Claude could not answer — try again.'], 422);
            json_response(['popup' => 1] + fa_view($pdo));
        }
        case 'fu.ap_offers_save': {
            $cur = fa_offers($pdo); $new = [];
            foreach ((array)($in['offers'] ?? []) as $x) { $k = preg_replace('/[^a-z0-9_]/', '', (string)($x['k'] ?? '')); if ($k === '') continue; $new[$k] = [mb_substr(trim((string)($x['label'] ?? $k)), 0, 40), mb_substr(trim((string)($x['rule'] ?? '')), 0, 300)];
                $b = trim((string)($x['body'] ?? '')); if ($b !== '' && $b !== fa_tpl_body($pdo, $k)) {
                    preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $b, $mm); if (array_map('intval', $mm[1]) !== [1, 2, 3]) json_response(['error' => 'Keep {{1}} (name), {{2}} (perfume) and {{3}} (offer) once each, in this order.'], 422);
                    if (preg_match('/^\s*\{\{|\}\}\s*$/', $b)) json_response(['error' => 'Meta does not allow {{…}} at the very start or end — add a word before / after it.'], 422);
                    setting_set($pdo, 'fa_tplbody_' . $k, mb_substr($b, 0, 900)); setting_set($pdo, 'fa_tpltry_' . $k, '0'); $new[$k][2] = 'changed'; } }
            if (!$new) json_response(['error' => 'Keep at least one offer.'], 422);
            setting_set($pdo, 'fa_offers', json_encode(array_map(fn($x) => [$x[0], $x[1]], $new), JSON_UNESCAPED_UNICODE)); $sent = 0;
            foreach ($new as $k => $x) if (!empty($x[2]) || !isset($cur[$k])) { if (fa_tpl_make($pdo, $k, $me)[0]) $sent++; }
            json_response(['message' => 'Saved ✓' . ($sent ? ' — ' . $sent . ' changed offer message(s) sent to Meta' : '')] + fa_view($pdo));
        }
        case 'fu.ap_season_save': { $f = (string)($in['from'] ?? ''); $t = (string)($in['to'] ?? ''); foreach ([$f, $t] as $d) if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) json_response(['error' => 'Dates as YYYY-MM-DD.'], 422);
            setting_set($pdo, 'fa_season', json_encode(['on' => !empty($in['on']), 'text' => mb_substr(trim((string)($in['text'] ?? '')), 0, 300), 'from' => $f, 'to' => $t], JSON_UNESCAPED_UNICODE)); json_response(['message' => 'Saved ✓'] + fa_view($pdo)); }
        case 'fu.ap_summary': { $t = trim((string)($in['text'] ?? '')); if ($t === '') json_response(['error' => 'Write what you want to change.'], 422); [$ok, $r] = fa_summary($pdo, $t, !empty($in['more'])); if (!$ok) json_response(['error' => $r], 422); json_response(['popup' => 1] + fa_view($pdo)); }
        case 'fu.ap_apply': { if (!empty($in['drop'])) { setting_set($pdo, 'fa_proposal', ''); json_response(['message' => 'Dropped.'] + fa_view($pdo)); } [$ok, $m] = fa_apply($pdo); if (!$ok) json_response(['error' => $m], 422); json_response(['message' => $m] + fa_view($pdo)); }
        case 'fu.ap_tpl': { $k = (string)($in['k'] ?? ''); if (!isset(fa_offers($pdo)[$k])) json_response(['error' => 'Unknown offer.'], 422); [$ok, $m] = fa_tpl_make($pdo, $k, $me); if (!$ok) json_response(['error' => $m], 422); json_response(['message' => $m] + fa_view($pdo)); }
    }
    json_response(['error' => 'Unknown action.'], 404);
}
