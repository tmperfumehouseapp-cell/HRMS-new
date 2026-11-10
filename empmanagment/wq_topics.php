<?php
/**
 * 🧭 Follow-up TOPICS and the time LADDER (rungs), with Claude to change any message by talking.
 *
 *   rungs   = the time windows of the 60 days (3–5 h, 5–7 h … 60 days; editable)
 *   topics  = what kind of message goes (free 10 ml, best sellers, gift …); each has a general summary, the rungs it suits and one or more messages
 *   message = an ordinary Journey message (registry key tpNN / tpNNb …): its words are the Meta template ({{1}} name, {{2}} perfume, {{3}} one line Claude fills for each customer)
 *
 * The messages are only DRAFTS until you press Approve: Approve = the words go to Meta as a new template version (the old approved version keeps working meanwhile).
 * "Ask Claude" (for one topic or for everything) writes proposals; you edit, then Apply = the same as Approve for every row (limits checked, Undo kept) — wj_change_apply.
 *   wq.topics        GET   admin      → ladder, topics, messages with their Meta status
 *   wq.topics_save   POST  admin      → {rungs[], topics[]}  (windows, summaries, which rungs a topic suits, on / off)
 *   wq.topics_msg    POST  admin      → {key, body}  save the words (draft)      · {topic, add:1} add a variant
 *   wq.topics_approve POST admin      → {key}  send these words to Meta
 *   wq.topics_ask    POST  admin      → {scope: all | tpNN, ask}  Claude's proposals (nothing is changed)
 *   wq.topics_apply  POST  admin      → {rows[], ask}  apply the proposals you kept (edited or not) and send them to Meta
 * PHP 7.4 safe. Nothing here sends to a customer: the ladder engine that picks a topic for a customer comes later.
 */
if (!function_exists('wtp_default')) {

function wtp_default(): array
{
    $R = [['r1', '3–5 hours', 3, 5], ['r2', '5–7 hours', 5, 7], ['r3', '7–9 hours', 7, 9], ['r4', '24 hours', 22, 26], ['r5', '48 hours', 46, 50], ['r6', '3 days', 66, 78], ['r7', '3½ days', 78, 90], ['r8', '4 days', 90, 102],
        ['r9', '12 days', 276, 300], ['r10', '15 days', 348, 372], ['r11', '18 days', 420, 444], ['r12', '24 days', 564, 588], ['r13', '30 days', 708, 732], ['r14', '45 days', 1068, 1092], ['r15', '60 days', 1428, 1452]];
    $T = [
        ['tp01', '🙋', 'Gentle check-in', 'A soft hello, no offer, no pressure. {{3}} = one friendly line like "happy to help if you have any question".', ['r1', 'r2', 'r3'], 'Hi {{1}} 🙏 Just checking in about {{2}}. {{3}} Reply here anytime, I am happy to help.'],
        ['tp02', '❓', 'Clear a doubt', 'Invite the customer\'s question about the perfume (scent, lasting, price). {{3}} = a short line inviting the question, no new facts.', ['r1', 'r2', 'r4'], 'Hi {{1}}, any doubt about {{2}}? {{3}} Ask me anything — I will answer right away 🙂'],
        ['tp03', '🏆', 'Best sellers', 'Our most loved perfumes. {{3}} = one line naming a best-seller close to what the customer asked, only from the store facts.', ['r4', 'r5'], 'Hi {{1}}! Our customers love these best-sellers. For you: {{2}} — {{3}} Reply to know more.'],
        ['tp04', '⏳', 'Long-lasting', 'For customers who care about staying power. {{3}} = a line about lasting power taken from the store facts only.', ['r4', 'r5', 'r6'], 'Hello {{1}}, looking for a long-lasting fragrance like {{2}}? {{3}} Reply here and I will suggest more.'],
        ['tp05', '🎁', 'Free 10 ml', 'The free 10 ml offer, in the owner\'s own words. Soft tone, never a price cut. {{3}} = the offer line from the owner\'s offer rule.', ['r6', 'r7', 'r8', 'r10'], 'Hi {{1}} 🎁 With {{2}}: {{3}} Reply here and we will keep it ready for you.'],
        ['tp06', '💰', 'Combo / bundle', 'A combo idea with a second perfume or a smaller size, using the price chart only. {{3}} = the combo line.', ['r6', 'r8', 'r9'], 'Hi {{1}}, a combo idea with {{2}}: {{3}} Reply here to book it 🙂'],
        ['tp07', '🚚', 'Easy ordering', 'Convenience: cash on delivery, quick delivery, simple ordering — only what is true for the shop. {{3}} = that line.', ['r9', 'r10', 'r11'], 'Hello {{1}}, for {{2}}: {{3}} Easy ordering — just reply here.'],
        ['tp08', '⭐', 'Happy customers', 'Real customer love for the perfume (no invented reviews). {{3}} = a line from real reviews in the store facts, or a gentle line if none.', ['r4', 'r5', 'r12'], 'Hi {{1}} ⭐ Customers loved {{2}}. {{3}} Reply here if you would like to try it.'],
        ['tp09', '🧴', 'Try a small size', 'For customers worried about the price: try a smaller size first. {{3}} = the trial-size line from the store facts.', ['r6', 'r7', 'r8'], 'Hi {{1}}, want to try {{2}} in a smaller size first? {{3}} Reply here and I will arrange it.'],
        ['tp10', '🎯', 'Gift idea', 'Gifting: birthday, wedding, festival. {{3}} = a short gift line, no price unless it is in the store facts.', ['r10', 'r11', 'r12'], 'Hi {{1}} 🎁 Looking for a gift? {{2}} is a lovely choice. {{3}} Reply here for gift options.'],
        ['tp11', '🪔', 'Festival / season', 'The festival or season now on (from the Festivals tab). {{3}} = the festival line. Change it when a new festival comes.', ['r12', 'r13'], 'Hi {{1}} 🪔 The festive season is here! {{2}}: {{3}} Reply here to order in time.'],
        ['tp12', '🆕', 'New arrival', 'Something new, similar to what the customer liked. Only real new products. {{3}} = the new-arrival line.', ['r9', 'r11', 'r12'], 'Hi {{1}}, something new you may like, similar to {{2}}: {{3}} Reply here for details.'],
        ['tp13', '⏰', 'Offer ending', 'Only when there is a REAL end date. {{3}} = the deadline line from the owner\'s offer rule. Never invent urgency.', ['r12', 'r13'], 'Hi {{1}}, a quick reminder about {{2}}: {{3}} Reply here before it ends.'],
        ['tp14', '👪', 'Asking family', 'For customers who said they will think or ask at home. Give time, no pressure. {{3}} = a gentle line.', ['r2', 'r3', 'r4'], 'Hi {{1}}, take your time with {{2}}. {{3}} Happy to answer questions from your family too 🙂'],
        ['tp15', '🧾', 'Order almost done', 'The customer gave an address or was about to pay. A gentle nudge to finish. {{3}} = the next-step line.', ['r1', 'r2', 'r3'], 'Hi {{1}}, your order for {{2}} is almost ready. {{3}} Reply here and we will complete it.'],
        ['tp16', '🤝', 'Talk to our expert', 'Offer a personal call or chat with an advisor. {{3}} = the line offering help.', ['r12', 'r13', 'r14'], 'Hi {{1}}, our perfume expert can guide you on {{2}}. {{3}} Reply here and we will call you.'],
        ['tp17', '👋', 'Soft goodbye', 'A calm last message, no pressure, door open. {{3}} = a polite closing line.', ['r14', 'r15'], 'Hi {{1}}, no hurry about {{2}}. {{3}} Whenever you are ready, just reply here 🙏'],
    ];
    $topics = []; foreach ($T as $t) $topics[] = ['id' => $t[0], 'icon' => $t[1], 'title' => $t[2], 'summary' => $t[3], 'rungs' => $t[4], 'on' => true, 'msgs' => [$t[0]], 'seed' => [$t[0] => $t[5]]];
    $rungs = []; foreach ($R as $r) $rungs[] = ['id' => $r[0], 'label' => $r[1], 'from_h' => $r[2], 'to_h' => $r[3]];
    return ['rungs' => $rungs, 'topics' => $topics];
}
function wtp_cfg(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'wtp_cfg', ''), true);
    if (is_array($j) && !empty($j['topics'])) return $j;
    // first time: the topics become ordinary Journey messages (registry), held back as DRAFTS (nothing goes to Meta until Approve)
    $d = wtp_default(); $reg = function_exists('fa_offers') ? fa_offers($pdo) : [];
    foreach ($d['topics'] as &$t) { foreach ($t['seed'] as $k => $body) { $reg[$k] = [mb_substr($t['icon'] . ' ' . $t['title'], 0, 40), mb_substr($t['summary'], 0, 300)]; setting_set($pdo, 'fa_tplbody_' . $k, $body); setting_set($pdo, 'fa_tpltry_' . $k, (string)(time() + 10 * 365 * 86400)); } unset($t['seed']); } unset($t);
    setting_set($pdo, 'fa_offers', json_encode(array_map(function ($x) { return [$x[0], $x[1]]; }, $reg), JSON_UNESCAPED_UNICODE));
    setting_set($pdo, 'wtp_cfg', json_encode($d, JSON_UNESCAPED_UNICODE)); return $d;
}
function wtp_view(PDO $pdo): array
{
    $c = wtp_cfg($pdo); $reg = function_exists('fa_offers') ? fa_offers($pdo) : []; $names = [];
    foreach ($c['topics'] as &$t) { $ms = []; foreach ((array)$t['msgs'] as $k) { $st = function_exists('wj_tpl_state') ? wj_tpl_state($pdo, $k) : 'none'; $ms[] = ['key' => $k, 'body' => function_exists('fa_tpl_body') ? fa_tpl_body($pdo, $k) : '', 'status' => $st, 'held' => (int)setting_get($pdo, 'fa_tpltry_' . $k, '0') > time() + 86400, 'reason' => ($st === 'rejected' && function_exists('jn_tpl_reason')) ? jn_tpl_reason($pdo, $k) : '']; } $t['messages'] = $ms; } unset($t);
    foreach ($c['topics'] as &$t) { $rv = array_flip((array)($t['by_rung'] ?? [])); foreach ($t['messages'] as &$m) $m['rung'] = isset($rv[$m['key']]) ? (string)$rv[$m['key']] : ''; unset($m); } unset($t);
    $g = wtp_gen_state($pdo);
    return ['ok' => true, 'rungs' => $c['rungs'], 'topics' => $c['topics'], 'gen' => ['left' => count((array)$g['queue']), 'done' => (int)$g['done'], 'total' => (int)$g['total'], 'made' => (int)$g['made'], 'sent' => (int)$g['sent'], 'errors' => array_slice((array)$g['errors'], -6), 'at' => (string)$g['at']]];
}
function wtp_save(PDO $pdo, array $in): array
{
    $c = wtp_cfg($pdo); $reg = function_exists('fa_offers') ? fa_offers($pdo) : [];
    if (isset($in['rungs']) && is_array($in['rungs'])) { $R = []; $ids = []; foreach ($in['rungs'] as $r) { $id = preg_replace('/[^a-z0-9]/', '', (string)($r['id'] ?? '')); if ($id === '' || isset($ids[$id])) continue; $ids[$id] = 1; $f = max(0, (float)($r['from_h'] ?? 0)); $t = max($f, (float)($r['to_h'] ?? $f)); $R[] = ['id' => $id, 'label' => mb_substr(trim((string)($r['label'] ?? $id)), 0, 30), 'from_h' => $f, 'to_h' => min(1500, $t)]; } if ($R) $c['rungs'] = $R; }
    $valid = array_column($c['rungs'], 'id');
    if (isset($in['topics']) && is_array($in['topics'])) foreach ($in['topics'] as $x) { foreach ($c['topics'] as &$t) { if ($t['id'] !== (string)($x['id'] ?? '')) continue;
        if (isset($x['title'])) $t['title'] = mb_substr(trim((string)$x['title']), 0, 40) ?: $t['title']; if (isset($x['summary'])) $t['summary'] = mb_substr(trim((string)$x['summary']), 0, 400); if (isset($x['on'])) $t['on'] = !empty($x['on']);
        if (isset($x['rungs']) && is_array($x['rungs'])) $t['rungs'] = array_values(array_intersect($valid, array_map('strval', $x['rungs'])));
        foreach ((array)$t['msgs'] as $k) if (isset($reg[$k])) $reg[$k] = [mb_substr(($t['icon'] ?? '') . ' ' . $t['title'], 0, 40), mb_substr($t['summary'], 0, 300)];   // the summary is the rule Claude follows for the {{3}} line
    } unset($t); }
    setting_set($pdo, 'fa_offers', json_encode(array_map(function ($x) { return [$x[0], $x[1]]; }, $reg), JSON_UNESCAPED_UNICODE)); setting_set($pdo, 'wtp_cfg', json_encode($c, JSON_UNESCAPED_UNICODE)); return [true, 'Saved ✓'];
}
/** The words of one message must keep the three blanks and stay short. */
function wtp_body_ok(string $b): string
{
    $b = trim($b); if ($b === '' || mb_strlen($b) > 600) return 'The message must be 1–600 letters.';
    foreach (['{{1}}', '{{2}}', '{{3}}'] as $v) if (strpos($b, $v) === false) return 'Keep ' . $v . ' in the message (1 = name, 2 = perfume, 3 = one line for this customer).';
    return '';
}
function wtp_msg_save(PDO $pdo, string $key, string $body): array
{
    $c = wtp_cfg($pdo); $ok = false; foreach ($c['topics'] as $t) if (in_array($key, (array)$t['msgs'], true)) $ok = true; if (!$ok) return [false, 'Message not found.'];
    $e = wtp_body_ok($body); if ($e !== '') return [false, $e];
    setting_set($pdo, 'fa_tplbody_' . $key, trim($body)); if ((int)setting_get($pdo, 'fa_tpltry_' . $key, '0') > time()) { /* still a draft */ } else setting_set($pdo, 'fa_tpltry_' . $key, (string)(time() + 10 * 365 * 86400));   // edited words are a draft again until Approve
    return [true, 'Saved as a draft — press Approve to send it to Meta.'];
}
function wtp_add_variant(PDO $pdo, string $topic): array
{
    $c = wtp_cfg($pdo); $reg = function_exists('fa_offers') ? fa_offers($pdo) : [];
    foreach ($c['topics'] as &$t) { if ($t['id'] !== $topic) continue; $n = count($t['msgs']); if ($n >= 5) return [false, 'A topic can have up to 5 messages.'];
        $k = $t['id'] . chr(97 + $n); $base = function_exists('fa_tpl_body') ? fa_tpl_body($pdo, (string)$t['msgs'][0]) : ''; $t['msgs'][] = $k; $reg[$k] = [mb_substr(($t['icon'] ?? '') . ' ' . $t['title'] . ' ' . ($n + 1), 0, 40), mb_substr($t['summary'], 0, 300)];
        setting_set($pdo, 'fa_tplbody_' . $k, $base); setting_set($pdo, 'fa_tpltry_' . $k, (string)(time() + 10 * 365 * 86400)); setting_set($pdo, 'fa_offers', json_encode(array_map(function ($x) { return [$x[0], $x[1]]; }, $reg), JSON_UNESCAPED_UNICODE)); setting_set($pdo, 'wtp_cfg', json_encode($c, JSON_UNESCAPED_UNICODE)); return [true, 'Variant added (a copy to rewrite).']; } unset($t);
    return [false, 'Topic not found.'];
}
function wtp_approve(PDO $pdo, string $key, int $by): array
{
    $c = wtp_cfg($pdo); $ok = false; foreach ($c['topics'] as $t) if (in_array($key, (array)$t['msgs'], true)) $ok = true; if (!$ok || !function_exists('fa_tpl_make')) return [false, 'Message not found.'];
    $e = wtp_body_ok(fa_tpl_body($pdo, $key)); if ($e !== '') return [false, $e];
    setting_set($pdo, 'fa_tpltry_' . $key, '0'); [$r, $msg] = fa_tpl_make($pdo, $key, $by);
    if (!$r) { setting_set($pdo, 'fa_tpltry_' . $key, (string)(time() + 10 * 365 * 86400)); return [false, (string)$msg]; }
    return [true, 'Sent to Meta ✓ — it shows Approved here when Meta says yes.'];
}
/** Claude proposes new words for the messages of one topic (or all). Nothing is changed. */
function wtp_ask(PDO $pdo, string $scope, string $ask): array
{
    if (!function_exists('convo_ai')) return [false, 'Claude is not available.', []];
    $c = wtp_cfg($pdo); $list = []; $cur = [];
    foreach ($c['topics'] as $t) { if ($scope !== 'all' && $t['id'] !== $scope) continue; foreach ((array)$t['msgs'] as $k) { $list[$k] = $t; $cur[] = "KEY $k | TOPIC " . $t['title'] . " | SUMMARY: " . $t['summary'] . "\nWORDS: " . fa_tpl_body($pdo, $k); } }
    if (!$list) return [false, 'Nothing to change.', []];
    $K = function_exists('wk_knowledge') ? wk_knowledge($pdo, [], $ask) : ['text' => '', 'allowed' => []]; $season = function_exists('fa_season_text') ? fa_season_text($pdo) : '';
    $sch = ['type' => 'OBJECT', 'properties' => ['rows' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['key' => ['type' => 'STRING'], 'body' => ['type' => 'STRING'], 'summary' => ['type' => 'STRING'], 'why' => ['type' => 'STRING']], 'required' => ['key', 'body', 'why']]]], 'required' => ['rows']];
    $sys = "You help the owner of TM Perfume House (Indian perfume brand) rewrite the WhatsApp follow-up messages (Meta templates). Each message has three blanks: {{1}} = customer's first name, {{2}} = the perfume, {{3}} = ONE short line written for each customer (an offer or a friendly line, following the topic's SUMMARY). "
        . "The owner will tell you what to change; change ONLY the messages the request touches (all of them if the request is about everything, e.g. a festival, a new offer wording, the tone). Keep every message short (max 300 letters), friendly, in simple English / Hinglish like the existing ones, keep {{1}} {{2}} {{3}} exactly, one or two emojis at most. "
        . "NEVER invent prices, discounts, dates or products: use only what is in the STORE FACTS or in the owner's request. Never promise something the owner did not allow. If the request changes what the topic is about, also give the new 'summary' (the rule for {{3}}), else leave it empty." . ($season !== '' ? " Season now on: $season." : '');
    try { [$ok, $v] = convo_ai($sys, [['text' => ($K['text'] !== '' ? "STORE FACTS:\n" . $K['text'] . "\n\n" : '') . "MESSAGES NOW:\n" . implode("\n\n", $cur) . "\n\nTHE OWNER'S REQUEST: " . $ask . "\n\nReturn the messages that change."]], $sch, false, 5000, 'claude'); } catch (Throwable $e) { return [false, 'Claude could not answer.', []]; }
    if (!$ok || !is_array($v)) return [false, 'Claude could not answer.', []];
    $rows = []; $allowed = $K['allowed'] + (function_exists('teach_nums') ? teach_nums($ask) : []);
    foreach ((array)($v['rows'] ?? []) as $r) { $k = (string)($r['key'] ?? ''); if (!isset($list[$k])) continue; $b = trim((string)($r['body'] ?? '')); if ($b === '' || wtp_body_ok($b) !== '') continue;
        $warn = function_exists('wj_change_check') ? wj_change_check($pdo, (string)($list[$k]['summary'] ?? ''), $b, $allowed) : '';
        $rows[] = ['key' => $k, 'title' => $list[$k]['icon'] . ' ' . $list[$k]['title'], 'old' => fa_tpl_body($pdo, $k), 'body' => $b, 'summary' => trim((string)($r['summary'] ?? '')), 'why' => mb_substr(trim((string)($r['why'] ?? '')), 0, 200), 'warn' => $warn]; }
    return [true, $rows ? count($rows) . ' message(s) proposed' : 'Claude found nothing to change for this request.', $rows];
}
function wtp_apply(PDO $pdo, array $rows, string $ask, int $by): array
{
    if (!function_exists('wj_change_apply')) return [false, 'The message module is not available.'];
    $c = wtp_cfg($pdo); $reg = function_exists('fa_offers') ? fa_offers($pdo) : []; $out = [];
    foreach ($rows as $r) { $k = preg_replace('/[^a-z0-9_]/', '', (string)($r['key'] ?? '')); if (!isset($reg[$k]) || empty($r['on'])) continue; $body = trim((string)($r['body'] ?? '')); if (wtp_body_ok($body) !== '') continue;
        $sum = trim((string)($r['summary'] ?? '')); if ($sum !== '') { foreach ($c['topics'] as &$t) if (in_array($k, (array)$t['msgs'], true)) $t['summary'] = mb_substr($sum, 0, 400); unset($t); }
        $topic = null; foreach ($c['topics'] as $t) if (in_array($k, (array)$t['msgs'], true)) $topic = $t;
        $out[] = ['on' => 1, 'key' => $k, 'label' => $reg[$k][0], 'rule' => $topic ? $topic['summary'] : $reg[$k][1], 'body' => $body, 'photo' => (int)setting_get($pdo, 'fa_tplphoto_' . $k, '0')]; }
    if (!$out) return [false, 'Nothing selected.'];
    setting_set($pdo, 'wtp_cfg', json_encode($c, JSON_UNESCAPED_UNICODE)); wj_put($pdo, 'wj_change', ['asked' => mb_substr($ask, 0, 300)]);
    foreach ($out as $o) setting_set($pdo, 'fa_tpltry_' . $o['key'], '0');   // approved by the owner: no longer a draft
    return wj_change_apply($pdo, $out, $by);
}


/** The campaign messages already sent (pricing, Diwali …): the wording and the offers Claude learns from. [text, allowed numbers] */
function wtp_campaign_text(PDO $pdo): array
{
    $t = ''; $allowed = [];
    try {
        $q = $pdo->query("SELECT c.name, tpl.body, COUNT(i.id) sent FROM wa_campaigns c JOIN wa_templates tpl ON tpl.id = c.tpl_id LEFT JOIN wa_camp_items i ON i.camp_id = c.id AND i.status <> 'queued' AND i.err = '' WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 150 DAY) GROUP BY c.id, c.name, tpl.body ORDER BY sent DESC, c.id DESC LIMIT 25");
        $seen = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $b = trim((string)$r['body']); $k = md5($b); if ($b === '' || isset($seen[$k])) continue; $seen[$k] = 1; $t .= '- "' . mb_substr($r['name'], 0, 50) . '" (sent to ' . (int)$r['sent'] . '): ' . mb_substr(preg_replace('/\s+/u', ' ', $b), 0, 420) . "\n"; }
    } catch (Throwable $e) { }
    if ($t !== '' && function_exists('teach_nums')) $allowed = teach_nums($t);
    return [$t, $allowed];
}
function wtp_gen_state(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'wtp_gen', ''), true); return is_array($j) ? $j : ['queue' => [], 'done' => 0, 'total' => 0, 'errors' => [], 'made' => 0, 'sent' => 0, 'at' => '']; }
/** Queue the topics (all that are ON, or one) — the engine (cron) writes them one by one: messages for every time window the topic suits, offers learned from the campaigns, and sends them to Meta. */
function wtp_gen_queue(PDO $pdo, string $which): array
{
    $c = wtp_cfg($pdo); $q = []; foreach ($c['topics'] as $t) { if (empty($t['on'])) continue; if ($which !== 'all' && $t['id'] !== $which) continue; $q[] = $t['id']; }
    if (!$q) return [false, 'No topic is switched on.'];
    setting_set($pdo, 'wtp_gen', json_encode(['queue' => $q, 'done' => 0, 'total' => count($q), 'errors' => [], 'made' => 0, 'sent' => 0, 'at' => date('Y-m-d H:i')], JSON_UNESCAPED_UNICODE));
    return [true, 'Claude will write ' . count($q) . ' topic(s) in the background — about a minute each. You can leave this screen.'];
}
/** One topic per engine run. */
function wtp_gen_tick(PDO $pdo): void
{
    if ((string)setting_get($pdo, 'wtp_auto_gen', '') !== '1') { setting_set($pdo, 'wtp_auto_gen', '1'); wtp_gen_queue($pdo, 'all'); return; }   // once, as the owner asked: the first time the engine runs after this update, Claude writes every topic's messages
    $S = wtp_gen_state($pdo); if (empty($S['queue'])) return; if ((int)setting_get($pdo, 'wtp_gen_at', '0') > time() - 40) return; setting_set($pdo, 'wtp_gen_at', (string)time());
    $id = array_shift($S['queue']); $c = wtp_cfg($pdo); $ti = null; foreach ($c['topics'] as $i => $t) if ($t['id'] === $id) $ti = $i;
    if ($ti === null || !function_exists('convo_ai')) { $S['done']++; setting_set($pdo, 'wtp_gen', json_encode($S, JSON_UNESCAPED_UNICODE)); return; }
    setting_set($pdo, 'wtp_gen', json_encode($S, JSON_UNESCAPED_UNICODE));   // taken off the queue first: a slow run is never started twice
    @set_time_limit(240); $t = $c['topics'][$ti]; $def = null; foreach (wtp_default()['topics'] as $d) if ($d['id'] === $id) $def = $d;
    $R = []; foreach ($t['rungs'] as $rid) foreach ($c['rungs'] as $r) if ($r['id'] === $rid) $R[] = $r; if (!$R) { $S['done']++; setting_set($pdo, 'wtp_gen', json_encode($S, JSON_UNESCAPED_UNICODE)); return; }
    [$camp, $campNums] = wtp_campaign_text($pdo); $K = function_exists('wk_knowledge') ? wk_knowledge($pdo, [], $t['title'] . ' ' . $t['summary']) : ['text' => '', 'allowed' => []]; $allowed = $K['allowed'] + $campNums;
    $rl = ''; foreach ($R as $r) $rl .= '- ' . $r['id'] . ' = ' . $r['label'] . ' after our last reply (' . $r['from_h'] . '–' . $r['to_h'] . " hours)\n";
    $sch = ['type' => 'OBJECT', 'properties' => ['summary' => ['type' => 'STRING'], 'rows' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['rung' => ['type' => 'STRING'], 'body' => ['type' => 'STRING']], 'required' => ['rung', 'body']]]], 'required' => ['summary', 'rows']];
    $sys = "You write WhatsApp follow-up templates for TM Perfume House (Indian perfume brand) for ONE topic. Each message has three blanks: {{1}} = customer's first name, {{2}} = the perfume they asked about, {{3}} = ONE short line written for each customer later (the offer or a friendly line, following the topic's rule). "
        . "Write ONE message for EACH time window listed, with wording that fits THAT moment (a few hours after our reply: 'just checking in'; days later: 'it has been a few days'; weeks later: 'it has been a while'). Never repeat the same sentence in two windows. "
        . "Short (max 280 letters), warm, simple English / Hinglish like the shop's own campaign messages, one or two emojis at most, keep {{1}} {{2}} {{3}} exactly, end by inviting a reply. "
        . "LEARN from the REAL CAMPAIGN MESSAGES the shop already sent: use their style and their real offers/prices; you may reuse a price or offer that appears there or in the STORE FACTS, but NEVER invent a number, discount, date or product. "
        . "'summary' = 2–3 plain sentences: what this topic says and the rule for the {{3}} line (which real offer or fact it may use). Topic: " . $t['title'] . ". Current summary: " . $t['summary'];
    try { [$ok, $v] = convo_ai($sys, [['text' => ($K['text'] !== '' ? "STORE FACTS:\n" . $K['text'] . "\n\n" : '') . ($camp !== '' ? "REAL CAMPAIGN MESSAGES ALREADY SENT:\n" . $camp . "\n" : '') . "TIME WINDOWS:\n" . $rl . "\nWrite the summary and one message per window."]], $sch, false, 4000, 'claude'); } catch (Throwable $e) { $ok = false; $v = null; }
    $S = wtp_gen_state($pdo); $S['done']++; $reg = function_exists('fa_offers') ? fa_offers($pdo) : []; $c = wtp_cfg($pdo);
    $summary = ($ok && is_array($v) && trim((string)($v['summary'] ?? '')) !== '') ? mb_substr(trim((string)$v['summary']), 0, 400) : $t['summary']; $by = []; foreach ((array)($ok && is_array($v) ? ($v['rows'] ?? []) : []) as $x) $by[(string)($x['rung'] ?? '')] = trim((string)($x['body'] ?? ''));
    $keys = []; $byRung = []; $made = 0; $sent = 0; $oldKeys = (array)$c['topics'][$ti]['msgs'];
    foreach ($R as $r) {
        $b = $by[$r['id']] ?? ''; $why = $b !== '' ? (wtp_body_ok($b) ?: (function_exists('wj_change_check') ? wj_change_check($pdo, $summary, $b, $allowed) : '')) : 'no message'; if ($why !== '') { $b = $def ? (string)$def['seed'][$id] : ''; $S['errors'][] = $t['title'] . ' · ' . $r['label'] . ': ' . mb_substr((string)$why, 0, 80) . ' (a plain message was used)'; }
        if ($b === '') continue; $k = substr($id . preg_replace('/[^a-z0-9]/', '', (string)$r['id']), 0, 12);
        $reg[$k] = [mb_substr($t['icon'] . ' ' . $t['title'] . ' · ' . $r['label'], 0, 40), mb_substr($summary, 0, 300)]; setting_set($pdo, 'fa_tplbody_' . $k, $b); $keys[] = $k; $byRung[$r['id']] = $k; $made++;
        setting_set($pdo, 'fa_tpltry_' . $k, '0');   // the owner said: send everything to Meta
        if (function_exists('fa_tpl_make') && function_exists('fa_tpl_status') && fa_tpl_status($pdo, $k) === 'none') { try { [$o2] = fa_tpl_make($pdo, $k, 0); if ($o2) $sent++; else setting_set($pdo, 'fa_tpltry_' . $k, (string)(time() + 3600)); } catch (Throwable $e) { setting_set($pdo, 'fa_tpltry_' . $k, (string)(time() + 3600)); } }
    }
    foreach ($oldKeys as $ok0) if (!in_array($ok0, $keys, true) && $ok0 === $id && function_exists('fa_tpl_status') && fa_tpl_status($pdo, $ok0) === 'none') unset($reg[$ok0]);   // the first draft that never went anywhere is replaced
    foreach ($oldKeys as $ok0) if (!in_array($ok0, $keys, true) && isset($reg[$ok0]) && $ok0 !== $id) $keys[] = $ok0;   // variants the owner added stay
    $c['topics'][$ti]['summary'] = $summary; $c['topics'][$ti]['msgs'] = array_values(array_unique($keys)); $c['topics'][$ti]['by_rung'] = $byRung;
    $S['made'] += $made; $S['sent'] += $sent; if (!$ok) $S['errors'][] = $t['title'] . ': Claude could not answer — plain messages were used.';
    setting_set($pdo, 'fa_offers', json_encode(array_map(function ($x) { return [$x[0], $x[1]]; }, $reg), JSON_UNESCAPED_UNICODE)); setting_set($pdo, 'wtp_cfg', json_encode($c, JSON_UNESCAPED_UNICODE)); setting_set($pdo, 'wtp_gen', json_encode($S, JSON_UNESCAPED_UNICODE));
}

}

$__wtp = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wtp, ['wq.topics', 'wq.topics_save', 'wq.topics_msg', 'wq.topics_approve', 'wq.topics_ask', 'wq.topics_apply', 'wq.topics_generate'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth();
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    if (!wa_is_admin($pdo, $auth)) json_response(['error' => 'Only Admin, HR or the control tower can do this.'], 403);
    $by = (int)($auth['employee_id'] ?? 0); $in = $isPost ? json_input() : $_GET;
    if ($__wtp === 'wq.topics') { if ($isPost) json_response(['error' => 'Method not allowed.'], 405); json_response(wtp_view($pdo)); }
    if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
    if ($__wtp === 'wq.topics_save') { [$ok, $m] = wtp_save($pdo, $in); json_response(['ok' => $ok, 'message' => $m], $ok ? 200 : 422); }
    if ($__wtp === 'wq.topics_msg') { if (!empty($in['add'])) [$ok, $m] = wtp_add_variant($pdo, (string)($in['topic'] ?? '')); else [$ok, $m] = wtp_msg_save($pdo, preg_replace('/[^a-z0-9_]/', '', (string)($in['key'] ?? '')), (string)($in['body'] ?? '')); json_response(['ok' => $ok, 'message' => $m], $ok ? 200 : 422); }
    if ($__wtp === 'wq.topics_approve') { [$ok, $m] = wtp_approve($pdo, preg_replace('/[^a-z0-9_]/', '', (string)($in['key'] ?? '')), $by); json_response(['ok' => $ok, 'message' => $m], $ok ? 200 : 422); }
    if ($__wtp === 'wq.topics_ask') { $ask = trim((string)($in['ask'] ?? '')); if ($ask === '') json_response(['error' => 'Tell Claude what to change.'], 422); [$ok, $m, $rows] = wtp_ask($pdo, preg_replace('/[^a-z0-9_]/', '', (string)($in['scope'] ?? 'all')) ?: 'all', mb_substr($ask, 0, 600)); json_response(['ok' => $ok, 'message' => $m, 'rows' => $rows], $ok ? 200 : 422); }
    if ($__wtp === 'wq.topics_generate') { [$ok, $m] = wtp_gen_queue($pdo, preg_replace('/[^a-z0-9_]/', '', (string)($in['topic'] ?? 'all')) ?: 'all'); json_response(['ok' => $ok, 'message' => $m], $ok ? 200 : 422); }
    if ($__wtp === 'wq.topics_apply') { [$ok, $m] = wtp_apply($pdo, (array)($in['rows'] ?? []), (string)($in['ask'] ?? ''), $by); json_response(['ok' => $ok, 'message' => $m], $ok ? 200 : 422); }
}
