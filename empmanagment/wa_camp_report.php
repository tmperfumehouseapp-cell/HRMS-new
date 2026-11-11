<?php
/**
 * wa_camp_report.php — 📊 the campaign REPORT CARD, the learnings of all campaigns, and "person vs bot: which replies win orders".
 *
 *   wa.camp_report        GET  {id}            right after sending: delivered / read / replied / failed, best reply hours, tags that replied.
 *                                              3 h+ after the last send: score /100 (vs your own average), who ordered / replied-no-order / read-and-ignored,
 *                                              what customers asked, reply speed, repeat vs new buyers, and Claude's advice (kept once written).
 *   wa.camp_advice        POST {id, force?}    Claude writes "what went well / what to do better next time" for one campaign (kept); every 5 new ones → learnings merged
 *   wa.camp_report_tag    POST {id, group}     tags the people of one group (ordered / replied_no_order / read_ignored / not_read) → a ready audience for a follow-up
 *   wa.camp_learn         GET                  the merged "What works for our campaigns" summary (shown at the top of New campaign)
 *   wa.camp_learn_build   POST                 merge now
 *   wa.pvb_run            POST                 person vs bot now (it also runs once a day by itself, from the engine tick)
 *
 * Person vs bot: for each kind of customer question (price, size, delivery, COD, availability, offer…) it compares, over the last 14 days, how often a
 * PERSON's reply and the BOT's reply were followed by an order within 3 days. Where people clearly win, Claude writes the reply rule the bot should
 * learn, from the people's own winning replies, and it lands on the Teach screen as a report (Approve → topic / Dismiss). The bot changes only after approval.
 * Loaded by wa_more.php (before wa_seg.php). Admin only. PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wcr_report')) {

/** The kind of question / answer in a customer message. */
function wcr_topic(string $t): string
{
    $t = mb_strtolower($t);
    if (preg_match('/\b(stop|unsubscribe|not interested|nahi chahiye|mat bhejo|band karo)\b/u', $t)) return 'stop';
    if (preg_match('/\b(cod|cash on delivery)\b/u', $t)) return 'cod';
    if (preg_match('/price|rate|kitn[aei]|cost|₹|\brs\b|kimat|keemat|charges?/u', $t)) return 'price';
    if (preg_match('/\b(\d+ ?ml|size|bottle|quantity)\b/u', $t)) return 'size';
    if (preg_match('/deliver|shipping|courier|kab tak|kab milega|days|track/u', $t)) return 'delivery';
    if (preg_match('/offer|discount|coupon|sale|deal|free/u', $t)) return 'offer';
    if (preg_match('/available|stock|hai kya|milega|in stock/u', $t)) return 'availability';
    if (preg_match('/address|location|shop|store|kahan|where/u', $t)) return 'location';
    if (preg_match('/\b(yes|haan|ha|ok|okay|interested|send|details|order|chahiye|book)\b/u', $t)) return 'interested';
    return 'other';
}
function wcr_topic_label(string $k): string { return ['price' => 'Price', 'size' => 'Size / ml', 'cod' => 'Cash on delivery', 'delivery' => 'Delivery time', 'offer' => 'Offer / discount', 'availability' => 'Is it available', 'location' => 'Shop / address', 'interested' => 'Interested / yes', 'stop' => 'Stop / not interested', 'other' => 'Other'][$k] ?? ucfirst($k); }
function wcr_get(PDO $pdo, string $k) { try { $v = json_decode((string)setting_get($pdo, $k, ''), true); return is_array($v) ? $v : null; } catch (Throwable $e) { return null; } }
function wcr_put(PDO $pdo, string $k, array $v): void { try { setting_set($pdo, $k, json_encode($v, JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) { } }

/** Everything about one campaign's recipients, one row per person: sent hour, read, first reply (time + kind), ordered, repeat buyer, tags. */
function wcr_people(PDO $pdo, int $id, string $startedAt, string $endAt): array
{
    $P = []; $q = $pdo->prepare("SELECT phone, sent_at, wamid FROM wa_camp_items WHERE camp_id = ? AND status = 'sent' AND sent_at IS NOT NULL LIMIT 200000"); $q->execute([$id]);
    foreach ($q->fetchAll() as $r) $P[(string)$r['phone']] = ['sent' => strtotime((string)$r['sent_at']), 'read' => false, 'delivered' => false, 'reply' => 0, 'rbody' => '', 'order' => false, 'rev' => 0, 'tags' => []];
    if (!$P) return $P;
    $q = $pdo->prepare("SELECT i.phone, m.status FROM wa_camp_items i JOIN wa_messages m ON m.wamid = i.wamid WHERE i.camp_id = ? AND i.wamid != ''"); $q->execute([$id]);
    foreach ($q->fetchAll() as $r) { $k = (string)$r['phone']; if (!isset($P[$k])) continue; if (in_array($r['status'], ['delivered', 'read'], true)) $P[$k]['delivered'] = true; if ($r['status'] === 'read') $P[$k]['read'] = true; }
    $q = $pdo->prepare("SELECT m.phone, m.ts, m.body FROM wa_camp_items i JOIN wa_messages m ON m.phone = i.phone AND m.dir = 'in' AND m.ts > i.sent_at AND m.ts <= DATE_ADD(i.sent_at, INTERVAL 2 DAY) WHERE i.camp_id = ? AND i.status = 'sent' ORDER BY m.id"); $q->execute([$id]);
    foreach ($q->fetchAll() as $r) { $k = (string)$r['phone']; if (isset($P[$k]) && !$P[$k]['reply']) { $P[$k]['reply'] = strtotime((string)$r['ts']); $P[$k]['rbody'] = mb_substr((string)$r['body'], 0, 200); $P[$k]['read'] = true; } }
    try {
        $q = $pdo->prepare("SELECT phone, created_at, total FROM shop_orders WHERE created_at >= ? AND created_at <= ? AND phone IS NOT NULL AND phone != '' LIMIT 200000"); $q->execute([$startedAt, date('Y-m-d H:i:s', strtotime($endAt) + 7 * 86400)]);
        foreach ($q->fetchAll() as $o) { $k = function_exists('wa_key') ? wa_key((string)$o['phone']) : substr(preg_replace('/\D/', '', (string)$o['phone']), -10); $ts = strtotime((string)$o['created_at']); if (isset($P[$k]) && $ts > $P[$k]['sent'] && $ts <= $P[$k]['sent'] + 7 * 86400) { $P[$k]['order'] = true; $P[$k]['rev'] += (int)round((float)$o['total']); } }
    } catch (Throwable $e) { }
    try { $q = $pdo->prepare("SELECT t.phone, t.tag FROM wa_tagidx t JOIN wa_camp_items i ON i.phone = t.phone AND i.camp_id = ? AND i.status = 'sent' LIMIT 400000"); $q->execute([$id]); foreach ($q->fetchAll() as $r) { $k = (string)$r['phone']; if (isset($P[$k])) $P[$k]['tags'][] = (string)$r['tag']; } } catch (Throwable $e) { }
    return $P;
}
function wcr_pct($a, $b) { return $b > 0 ? round(100 * $a / $b, 1) : null; }
/** The full report (kept 15 minutes, so the screen opens fast). */
function wcr_report(PDO $pdo, int $id, bool $fresh = false): ?array
{
    $c = function_exists('wa_camp_row') ? wa_camp_row($pdo, $id) : null; if (!$c) return null;
    $ck = 'camp_rep2_' . $id; $old = $fresh ? null : wcr_get($pdo, $ck); if ($old && (int)($old['at'] ?? 0) > time() - 900) return $old['d'];
    $cfg = function_exists('wa_team_cfg') ? wa_team_cfg($pdo) : ['rate_marketing' => 0.88, 'rate_utility' => 0.13];
    $res = function_exists('wa_camp_result') ? wa_camp_result($pdo, $c, $cfg) : [];
    $start = (string)($c['started_at'] ?: $c['created_at']); $end = (string)($c['finished_at'] ?: date('Y-m-d H:i:s'));
    $P = wcr_people($pdo, $id, $start, $end); $n = count($P);
    $lastSent = $n ? max(array_column($P, 'sent')) : 0; $ageH = $lastSent ? round((time() - $lastSent) / 3600, 1) : 0;
    $hours = []; $tags = []; $topics = []; $speed = []; $rep = 0; $new = 0; $grp = ['ordered' => 0, 'replied_no_order' => 0, 'read_ignored' => 0, 'not_read' => 0];
    $om = function_exists('wa_order_map') ? wa_order_map($pdo) : null; if ($om && method_exists($om, 'prefetch')) $om->prefetch(array_keys($P));
    foreach ($P as $k => $x) {
        $h = (int)date('G', $x['sent']); $hours[$h] = $hours[$h] ?? ['sent' => 0, 'replied' => 0, 'ordered' => 0]; $hours[$h]['sent']++; if ($x['reply']) $hours[$h]['replied']++; if ($x['order']) $hours[$h]['ordered']++;
        foreach (array_unique($x['tags']) as $t) { if (strpos($t, 'camp_') === 0) continue; $tags[$t] = $tags[$t] ?? ['n' => 0, 'replied' => 0, 'ordered' => 0, 'dl' => 0, 'rd' => 0]; $tags[$t]['n']++; if ($x['reply']) $tags[$t]['replied']++; if ($x['order']) $tags[$t]['ordered']++; if ($x['delivered'] || $x['read'] || $x['reply']) $tags[$t]['dl']++; if ($x['read'] || $x['reply']) $tags[$t]['rd']++; }
        if ($x['reply']) { $tp = in_array('business_autoreply', $x['tags'], true) ? 'business' : wcr_topic($x['rbody']); $topics[$tp] = ($topics[$tp] ?? 0) + 1; $speed[] = ($x['reply'] - $x['sent']) / 60; }
        if ($x['order']) { $grp['ordered']++; $o = $om ? ($om[$k] ?? null) : null; if ($o && (int)$o['n'] >= 2) $rep++; else $new++; }
        elseif ($x['reply']) $grp['replied_no_order']++; elseif ($x['read']) $grp['read_ignored']++; else $grp['not_read']++;
    }
    ksort($hours); $hl = []; foreach ($hours as $h => $v) $hl[] = ['hour' => $h, 'sent' => $v['sent'], 'reply_pct' => wcr_pct($v['replied'], $v['sent']), 'order_pct' => wcr_pct($v['ordered'], $v['sent'])];
    $tl = []; foreach ($tags as $t => $v) if ($v['n'] >= max(10, (int)round($n * 0.02))) $tl[] = ['tag' => $t, 'n' => $v['n'], 'reply_pct' => wcr_pct($v['replied'], $v['n']), 'order_pct' => wcr_pct($v['ordered'], $v['n'])];
    usort($tl, fn($a, $b) => [$b['order_pct'], $b['reply_pct']] <=> [$a['order_pct'], $a['reply_pct']]); $tl = array_slice($tl, 0, 12);
    // 🏷 the full breakdown by tag: who the campaign reached by tag, and how each group delivered, read, replied and ordered (against the whole campaign)
    $repAll = 0; foreach ($P as $x0) if ($x0['reply']) $repAll++; $ovr = wcr_pct($repAll, $n); $tr = [];
    foreach ($tags as $t => $v) if ($v['n'] >= max(10, (int)round($n * 0.01))) { $rp = wcr_pct($v['replied'], $v['n']);
        $tr[] = ['tag' => $t, 'label' => function_exists('wa_tag_label') ? wa_tag_label($pdo, $t) : $t, 'group' => function_exists('wa_tag_group_of') ? wa_tag_group_of($t) : 'Other', 'n' => $v['n'], 'share' => wcr_pct($v['n'], $n), 'dl_pct' => wcr_pct($v['dl'], $v['n']), 'rd_pct' => wcr_pct($v['rd'], $v['n']), 'reply_pct' => $rp, 'order_pct' => wcr_pct($v['ordered'], $v['n']), 'ordered' => $v['ordered'], 'lift' => ($ovr && $rp !== null) ? round($rp / $ovr, 2) : null]; }
    usort($tr, fn($a, $b) => [$b['reply_pct'], $b['n']] <=> [$a['reply_pct'], $a['n']]); $tr = array_slice($tr, 0, 80);
    arsort($topics); $tpl = []; foreach ($topics as $k => $v) $tpl[] = ['kind' => $k, 'label' => $k === 'business' ? '🏢 Business auto-reply' : wcr_topic_label($k), 'n' => $v];
    sort($speed); $med = $speed ? round($speed[(int)floor((count($speed) - 1) / 2)]) : null;
    $sent = (int)($res['sent'] ?? $n); $m = ['read' => wcr_pct($res['read'] ?? 0, $sent), 'reply' => wcr_pct($res['replied'] ?? 0, $sent), 'order' => wcr_pct($res['buyers'] ?? 0, $sent), 'roas' => ($res['cost'] ?? 0) > 0 ? round(($res['revenue'] ?? 0) / $res['cost'], 1) : null];
    $ready = $sent > 0 && $ageH >= 3;
    $score = null; $parts = [];
    if ($ready) {
        $bench = wcr_get($pdo, 'camp_bench') ?: []; $others = array_values(array_filter($bench, fn($b) => (int)$b['id'] !== $id)); $avg = function ($k, $def) use ($others) { $v = array_values(array_filter(array_column($others, $k), fn($x) => $x !== null)); return count($v) >= 3 ? max($def / 3, array_sum($v) / count($v)) : $def; };
        $W = [['read', 'Read', 20, $avg('read', 60.0)], ['reply', 'Replied', 25, $avg('reply', 6.0)], ['order', 'Ordered', 35, $avg('order', 1.5)], ['roas', 'Money back (revenue ÷ cost)', 20, $avg('roas', 4.0)]];
        $score = 0; foreach ($W as [$k, $lab, $w, $ref]) { $v = $m[$k]; $p = $v === null ? 0 : min(1.25, $v / max(0.01, $ref)); $pts = (int)round($w * min(1, $p)); $score += $pts; $parts[] = ['key' => $k, 'label' => $lab, 'value' => $v, 'avg' => round($ref, 1), 'points' => $pts, 'of' => $w, 'good' => $v !== null && $v >= $ref]; }
        $bench = array_values(array_filter($bench, fn($b) => (int)$b['id'] !== $id)); $bench[] = ['id' => $id, 'read' => $m['read'], 'reply' => $m['reply'], 'order' => $m['order'], 'roas' => $m['roas'], 'score' => $score, 'at' => time()];
        usort($bench, fn($a, $b) => $b['at'] <=> $a['at']); wcr_put($pdo, 'camp_bench', array_slice($bench, 0, 20));
    }
    $tb = ''; try { $t = wa_tpl_row($pdo, (int)$c['tpl_id']); $tb = $t ? (string)$t['body'] : ''; } catch (Throwable $e) { }
    $d = ['id' => $id, 'name' => (string)$c['name'], 'status' => (string)$c['status'], 'message' => $tb, 'result' => $res, 'rates' => $m, 'age_h' => $ageH, 'ready' => $ready, 'score' => $score, 'parts' => $parts,
          'hours' => $hl, 'tags' => $tl, 'tag_rows' => $tr, 'overall' => ['people' => $n, 'reply_pct' => $ovr], 'topics' => $tpl, 'reply_min' => $med, 'buyers_repeat' => $rep, 'buyers_new' => $new, 'groups' => $grp, 'advice' => wcr_get($pdo, 'camp_adv_' . $id)];
    wcr_put($pdo, $ck, ['at' => time(), 'd' => $d]);
    return $d;
}
function wcr_ai(string $sys, string $user, int $max = 2500): ?array
{
    $GLOBALS['wcr_ai_why'] = '';
    if (!function_exists('brain_ai_json')) { $GLOBALS['wcr_ai_why'] = 'Claude is not installed on this server.'; return null; }
    try { $j = brain_ai_json('internal', 'whatsapp', $sys, $user, $max, ['ctx' => false, 'timeout' => 100]); } catch (Throwable $e) { $GLOBALS['wcr_ai_why'] = $e->getMessage(); return null; }
    if (!is_array($j)) $GLOBALS['wcr_ai_why'] = (string)($GLOBALS['brain_last_error'] ?? '') ?: 'Claude answered in a form HRMS cannot read.';
    return is_array($j) ? $j : null;
}
/** Claude's read of ALL recent campaigns together (numbers sent by the Summary screen + each message text): what works, what to change. Not stored. */
function wcr_summary_ai(PDO $pdo, array $rows): array
{
    $rows = array_slice(array_values(array_filter($rows, 'is_array')), 0, 14); if (!$rows) return ['error' => 'No campaign with numbers yet.'];
    $out = []; foreach ($rows as $r) {
        $sm = max(0, (int)($r['sent_to_meta'] ?? 0)); $msg = ''; try { $t = function_exists('wa_tpl_row') ? wa_tpl_row($pdo, (int)($r['tpl_id'] ?? 0)) : null; if ($t) $msg = mb_substr(trim((string)($t['header_text'] ?? '') . ' ' . (string)$t['body']), 0, 500); } catch (Throwable $e) { }
        $out[] = ['campaign' => mb_substr((string)($r['name'] ?? ''), 0, 80), 'started' => (string)($r['started'] ?? ''), 'sent_to_meta' => $sm, 'delivered_pct' => wcr_pct((int)($r['delivered'] ?? 0), $sm), 'read_pct' => wcr_pct((int)($r['read'] ?? 0), $sm), 'replied_pct' => wcr_pct((int)($r['replied'] ?? 0), $sm), 'held_by_meta_pct' => wcr_pct((int)($r['held'] ?? 0), $sm), 'failed_pct' => wcr_pct((int)($r['failed'] ?? 0), $sm), 'message' => $msg];
    }
    $sys = "You are the WhatsApp marketing analyst of TM Perfume House (perfumes, India). You get the recent campaigns with their delivered / read / replied / held-by-Meta percentages (of everyone Meta accepted) and the message text of each. "
         . "Meta holds marketing messages to customers who got many from businesses (held_by_meta_pct): that is Meta's decision, so advise on audience (recent chatters, spacing) and timing, not on forcing it. Compare the campaigns with each other: what is different in the winners' message (first line, length, offer, question, photo) and audience. "
         . "Only claim what the numbers and messages show. Plain simple English, concrete changes. Answer {\"headline\":\"one line verdict\",\"did_well\":[\"...\"],\"do_better\":[\"specific change to a named campaign or message, with the number it should lift\"],\"next_time\":{\"send_at\":\"e.g. 7 pm\",\"audience\":\"who to send to / leave out\",\"message\":\"the exact first line or ending to try\",\"avoid\":\"what to stop\"}}. Max 5 items per list.";
    $j = wcr_ai($sys, json_encode($out, JSON_UNESCAPED_UNICODE), 2500); if (!$j || empty($j['headline'])) return ['error' => 'Claude could not review the campaigns right now. Reason: ' . ((string)($GLOBALS['wcr_ai_why'] ?? '') ?: 'no answer') . ' — try again in a minute.'];
    return ['ok' => true, 'advice' => ['headline' => mb_substr((string)$j['headline'], 0, 240), 'did_well' => array_slice(array_map('strval', (array)($j['did_well'] ?? [])), 0, 5), 'do_better' => array_slice(array_map('strval', (array)($j['do_better'] ?? [])), 0, 5), 'next_time' => array_map('strval', (array)($j['next_time'] ?? []))]];
}
/** Claude's advice for one campaign (kept). */
function wcr_advice(PDO $pdo, int $id): array
{
    $r = wcr_report($pdo, $id, true); if (!$r) return ['error' => 'Campaign not found.'];
    if (!$r['ready']) return ['error' => 'The report card is ready 3 hours after the last message went out — orders need a little time.'];
    $sys = "You are the WhatsApp marketing analyst of TM Perfume House (perfumes, India). Read ONE campaign's numbers and write short, concrete, plain-English advice the owner can act on next time. "
         . "Only claim what the numbers show; compare with the averages given. Mention the best send hour, which customer tags replied / ordered more or less, what customers asked, and one change to the message. "
         . "Answer {\"headline\":\"one line verdict\",\"did_well\":[\"...\"],\"do_better\":[\"...\"],\"next_time\":{\"send_at\":\"e.g. 7 pm\",\"audience\":\"who to send to / leave out\",\"message\":\"what to change in the message\",\"avoid\":\"what to stop doing\"}}. Max 4 items per list.";
    $user = json_encode(['campaign' => $r['name'], 'message' => mb_substr($r['message'], 0, 900), 'numbers' => $r['result'], 'rates_pct' => $r['rates'], 'score' => $r['score'], 'parts_vs_average' => $r['parts'], 'by_send_hour' => $r['hours'], 'by_customer_tag' => $r['tags'],
        'what_customers_replied' => $r['topics'], 'median_minutes_to_reply' => $r['reply_min'], 'buyers_repeat' => $r['buyers_repeat'], 'buyers_new' => $r['buyers_new'], 'groups' => $r['groups']], JSON_UNESCAPED_UNICODE);
    $j = wcr_ai($sys, $user); if (!$j || empty($j['headline'])) return ['error' => 'Claude could not write the advice right now — try again in a minute.'];
    $adv = ['headline' => mb_substr((string)$j['headline'], 0, 240), 'did_well' => array_slice(array_map('strval', (array)($j['did_well'] ?? [])), 0, 4), 'do_better' => array_slice(array_map('strval', (array)($j['do_better'] ?? [])), 0, 4),
            'next_time' => array_map('strval', (array)($j['next_time'] ?? [])), 'score' => $r['score'], 'at' => date('Y-m-d H:i:s'), 'name' => $r['name']];
    wcr_put($pdo, 'camp_adv_' . $id, $adv);
    $L = wcr_get($pdo, 'camp_learnings'); $used = $L ? (array)($L['ids'] ?? []) : []; $all = wcr_advice_ids($pdo);
    if (count(array_diff($all, $used)) >= 5 || (!$L && count($all) >= 2)) { try { wcr_learn_build($pdo); } catch (Throwable $e) { } }   // every 5 new report cards → one merged summary
    return ['ok' => true, 'advice' => $adv];
}
function wcr_advice_ids(PDO $pdo): array { $o = []; try { foreach ($pdo->query("SELECT setting_key FROM hrms_settings WHERE setting_key LIKE 'camp\\_adv\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $k) $o[] = (int)substr((string)$k, 9); } catch (Throwable $e) { } return array_values(array_filter($o)); }
/** One summary from all report cards: "What works for our campaigns". */
function wcr_learn_build(PDO $pdo): array
{
    $ids = wcr_advice_ids($pdo); if (!$ids) return ['error' => 'No report card has advice yet. Open a campaign’s report card 3 hours after sending and press “Ask Claude”.'];
    rsort($ids); $ids = array_slice($ids, 0, 25); $cards = []; $bench = wcr_get($pdo, 'camp_bench') ?: [];
    foreach ($ids as $i) { $a = wcr_get($pdo, 'camp_adv_' . $i); if ($a) $cards[] = $a + ['numbers' => current(array_filter($bench, fn($b) => (int)$b['id'] === $i)) ?: null]; }
    $sys = "Merge the report cards of TM Perfume House's WhatsApp campaigns into ONE short guide the owner reads before making the next campaign. Keep only what repeats across campaigns or is clearly proven. "
         . "Answer {\"summary\":\"2-3 lines\",\"points\":[\"max 7 concrete rules\"],\"best_hours\":[\"7 pm\"],\"best_audiences\":[\"...\"],\"avoid\":[\"...\"],\"message_tips\":[\"...\"]}.";
    $j = wcr_ai($sys, json_encode($cards, JSON_UNESCAPED_UNICODE), 2000); if (!$j || empty($j['summary'])) return ['error' => 'Claude could not merge the learnings right now.'];
    $L = ['summary' => (string)$j['summary'], 'points' => array_slice(array_map('strval', (array)($j['points'] ?? [])), 0, 7), 'best_hours' => array_slice(array_map('strval', (array)($j['best_hours'] ?? [])), 0, 3), 'best_audiences' => array_slice(array_map('strval', (array)($j['best_audiences'] ?? [])), 0, 4),
          'avoid' => array_slice(array_map('strval', (array)($j['avoid'] ?? [])), 0, 4), 'message_tips' => array_slice(array_map('strval', (array)($j['message_tips'] ?? [])), 0, 4), 'ids' => $ids, 'n' => count($cards), 'at' => date('Y-m-d H:i:s')];
    wcr_put($pdo, 'camp_learnings', $L); return ['ok' => true, 'learn' => $L];
}
/** Tags one group of a campaign's people (camp_<id>_<group>) → pick it as the audience of a follow-up campaign. */
function wcr_tag_group(PDO $pdo, int $id, string $g): array
{
    if (!in_array($g, ['ordered', 'replied_no_order', 'read_ignored', 'not_read'], true)) return ['error' => 'Unknown group.'];
    $c = wa_camp_row($pdo, $id); if (!$c) return ['error' => 'Campaign not found.'];
    $P = wcr_people($pdo, $id, (string)($c['started_at'] ?: $c['created_at']), (string)($c['finished_at'] ?: date('Y-m-d H:i:s'))); $tag = 'camp_' . $id . '_' . $g; $n = 0; $now = date('Y-m-d H:i:s');
    $a = $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) VALUES (?, ?, 'camp', ?)"); $b = $pdo->prepare("INSERT IGNORE INTO wa_tagidx (phone, tag) VALUES (?, ?)");
    foreach ($P as $k => $x) {
        $in = $g === 'ordered' ? $x['order'] : ($g === 'replied_no_order' ? (!$x['order'] && $x['reply']) : ($g === 'read_ignored' ? (!$x['order'] && !$x['reply'] && $x['read']) : (!$x['order'] && !$x['reply'] && !$x['read'])));
        if (!$in) continue; try { $a->execute([$k, $tag, $now]); $b->execute([$k, $tag]); $n++; } catch (Throwable $e) { }
    }
    return ['ok' => true, 'tag' => $tag, 'n' => $n];
}

// ---------------------------------------------------------------------------------------------------------------- person vs bot
/** First reply after each customer message (last $days days), by a person or the bot, and whether an order followed within 3 days. */
function wcr_pvb_data(PDO $pdo, int $days = 14): array
{
    $from = date('Y-m-d H:i:s', time() - $days * 86400); $rows = [];
    $st = $pdo->prepare("SELECT phone, dir, who, emp_id, mtype, body, ts FROM wa_messages WHERE ts >= ? AND dir IN ('in', 'out') ORDER BY phone, id LIMIT 300000"); $st->execute([$from]);
    $lastIn = []; foreach ($st->fetchAll() as $m) {
        $k = (string)$m['phone'];
        if ($m['dir'] === 'in') { $lastIn[$k] = (string)$m['body']; continue; }
        if (!isset($lastIn[$k])) continue; $who = (string)$m['who'];
        if (($who === 'emp' || $who === 'bot') && (string)$m['mtype'] === 'text' && mb_strlen(trim((string)$m['body'])) >= 8) $rows[] = ['phone' => $k, 'who' => $who, 'emp' => (int)$m['emp_id'], 'topic' => wcr_topic($lastIn[$k]), 'q' => mb_substr($lastIn[$k], 0, 160), 'a' => mb_substr((string)$m['body'], 0, 500), 'ts' => strtotime((string)$m['ts'])];
        unset($lastIn[$k]);   // only the FIRST answer to a customer message
    }
    $ord = []; try { $q = $pdo->prepare("SELECT phone, created_at FROM shop_orders WHERE created_at >= ? AND phone IS NOT NULL AND phone != ''"); $q->execute([$from]); foreach ($q->fetchAll() as $o) { $k = function_exists('wa_key') ? wa_key((string)$o['phone']) : substr(preg_replace('/\D/', '', (string)$o['phone']), -10); $ord[$k][] = strtotime((string)$o['created_at']); } } catch (Throwable $e) { }
    foreach ($rows as &$r) { $r['won'] = false; foreach ($ord[$r['phone']] ?? [] as $t) if ($t > $r['ts'] && $t <= $r['ts'] + 3 * 86400) { $r['won'] = true; break; } } unset($r);
    return $rows;
}
/** Compares per topic; where people clearly win, Claude writes the rule and it goes to the Teach screen. Once a day (or "now"). */
function wcr_pvb_run(PDO $pdo, bool $force = false): array
{
    if (!$force && (string)setting_get($pdo, 'pvb_day', '') === date('Y-m-d')) return ['skipped' => 'already ran today'];
    try { setting_set($pdo, 'pvb_day', date('Y-m-d')); } catch (Throwable $e) { }
    $rows = wcr_pvb_data($pdo); $by = [];
    foreach ($rows as $r) { if (in_array($r['topic'], ['stop', 'other', 'interested'], true)) continue; $by[$r['topic']][$r['who']][] = $r; }
    $found = []; $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS teach_reports (id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL DEFAULT 0, by_name VARCHAR(100) NOT NULL DEFAULT '', phone VARCHAR(16) NOT NULL DEFAULT '', note TEXT NULL, chat TEXT NULL, images MEDIUMTEXT NULL, status VARCHAR(8) NOT NULL DEFAULT 'new', created_at DATETIME NULL, done_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) { }
    foreach ($by as $topic => $w) {
        $e = $w['emp'] ?? []; $b = $w['bot'] ?? []; if (count($e) < 8 || count($b) < 8) continue;
        $ce = count(array_filter($e, fn($x) => $x['won'])) / count($e); $cb = count(array_filter($b, fn($x) => $x['won'])) / count($b);
        if ($ce < $cb + 0.08 || $ce < 0.05) continue;   // people must clearly win (8 points or more)
        if ((string)setting_get($pdo, 'pvb_last_' . $topic, '') > date('Y-m-d', time() - 7 * 86400)) continue;   // one card per kind of question per week
        $wins = array_values(array_filter($e, fn($x) => $x['won'])); $loss = array_slice(array_values(array_filter($b, fn($x) => !$x['won'])), 0, 5);
        $sys = "Write ONE reply rule for TM Perfume House's WhatsApp bot, learned from the replies of salespeople that led to orders. It must be general (no customer names, no one-off prices unless they are the shop's standard), "
             . "in the shop's own style, and say WHEN to use it. Answer {\"rule\":\"When the customer asks about … reply like this: …\",\"example\":\"one example reply\",\"why\":\"what the winning replies do that the bot's replies do not\"}.";
        $user = "KIND OF QUESTION: " . wcr_topic_label($topic) . "\n\nPEOPLE'S REPLIES THAT LED TO AN ORDER:\n" . implode("\n", array_map(fn($x) => 'Customer: ' . $x['q'] . "\nReply: " . $x['a'], array_slice($wins, 0, 8)))
              . "\n\nBOT REPLIES THAT DID NOT:\n" . implode("\n", array_map(fn($x) => 'Customer: ' . $x['q'] . "\nBot: " . $x['a'], $loss));
        $j = wcr_ai($sys, $user, 1200); if (!$j || empty($j['rule'])) continue;
        $note = '📈 ' . wcr_topic_label($topic) . ' questions: people\'s replies led to an order ' . round($ce * 100) . '% of the time (' . count($e) . ' chats), the bot\'s ' . round($cb * 100) . '% (' . count($b) . ' chats), last 14 days.'
              . "\n\nSuggested rule for the bot: " . trim((string)$j['rule']) . (!empty($j['example']) ? "\nExample: " . trim((string)$j['example']) : '') . (!empty($j['why']) ? "\nWhy it works: " . trim((string)$j['why']) : '')
              . "\n\nApprove = make it a Teach topic (the bot learns it after you confirm). Dismiss = ignore.";
        $chat = implode("\n\n", array_map(fn($x) => 'Customer: ' . $x['q'] . "\n" . ($names[$x['emp']] ?? 'Salesperson') . ' (ordered after): ' . $x['a'], array_slice($wins, 0, 4)));
        $pdo->prepare("INSERT INTO teach_reports (emp_id, by_name, phone, note, chat, images, status, created_at) VALUES (0, ?, '', ?, ?, NULL, 'new', NOW())")->execute(['📈 Claude · replies that win orders', mb_substr($note, 0, 4000), mb_substr($chat, 0, 4000)]);
        try { setting_set($pdo, 'pvb_last_' . $topic, date('Y-m-d')); } catch (Throwable $e) { }
        $found[] = ['topic' => $topic, 'people' => round($ce * 100), 'bot' => round($cb * 100)];
    }
    return ['ok' => true, 'compared' => count($rows), 'cards' => $found];
}

}   // function_exists guard

$__wcr = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wcr, ['wa.camp_report', 'wa.camp_advice', 'wa.camp_report_tag', 'wa.camp_learn', 'wa.camp_learn_build', 'wa.pvb_run', 'wa.camp_summary_ai'], true)) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    if (!$isPost && $__wcr !== 'wa.camp_report' && $__wcr !== 'wa.camp_learn') json_response(['error' => 'Method not allowed.'], 405);
    @set_time_limit(150);
    if ($__wcr === 'wa.camp_report') { $r = wcr_report($pdo, (int)($_GET['id'] ?? 0), !empty($_GET['fresh'])); if (!$r) json_response(['error' => 'Campaign not found.'], 404); json_response(['ok' => true, 'report' => $r]); }
    if ($__wcr === 'wa.camp_learn') json_response(['ok' => true, 'learn' => wcr_get($pdo, 'camp_learnings'), 'cards' => count(wcr_advice_ids($pdo))]);
    $in = json_input();
    if ($__wcr === 'wa.camp_advice') { $id = (int)($in['id'] ?? 0); $old = wcr_get($pdo, 'camp_adv_' . $id); if ($old && empty($in['force'])) json_response(['ok' => true, 'advice' => $old]); $r = wcr_advice($pdo, $id); if (isset($r['error'])) json_response($r, 422); json_response($r); }
    if ($__wcr === 'wa.camp_summary_ai') { $r = wcr_summary_ai($pdo, (array)($in['rows'] ?? [])); if (isset($r['error'])) json_response($r, 422); json_response($r); }
    if ($__wcr === 'wa.camp_report_tag') { $r = wcr_tag_group($pdo, (int)($in['id'] ?? 0), (string)($in['group'] ?? '')); if (isset($r['error'])) json_response($r, 422); json_response($r); }
    if ($__wcr === 'wa.camp_learn_build') { $r = wcr_learn_build($pdo); if (isset($r['error'])) json_response($r, 422); json_response($r); }
    if ($__wcr === 'wa.pvb_run') json_response(wcr_pvb_run($pdo, true));
}
