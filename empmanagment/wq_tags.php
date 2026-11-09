<?php
/**
 * 🏷 One tag system that keeps itself right.
 *
 *  • A REGISTRY of every tag (what it means, who sets it, when it goes away): fact / status / intent / journey / manual.
 *  • AUTOMATIC tags for what the 60-day plan needs: interested · ready to pay · thinking · not interested · premium / budget buyer · order from a query / a campaign · asking again after an order.
 *    Each carries WHY and UNTIL (it removes itself), is recomputed from the customer's real behaviour, and sits in wa_auto_tags — the tag index (wa_tagidx) includes it, so filters,
 *    audiences and counts see it.
 *  • PATTERN LEARNING: a tag the staff made by hand (wa_ctags src person / list) is studied: Claude proposes a rule over measurable facts, the rule is SCORED against the customers who have the
 *    tag and a sample who do not, and when it reproduces the staff's tagging it is applied to everyone automatically (the manual tags stay exactly as they are).
 *  Actions (admin): wq.tags_list · wq.tags_run · wq.tags_rule.   Background: jt_tick() (called from the Journey tick on the cron).   PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('jt_tables')) {

function jt_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_auto_tags (phone VARCHAR(12) NOT NULL, tag VARCHAR(40) NOT NULL, why VARCHAR(220) NOT NULL DEFAULT '', at DATETIME NULL, until DATETIME NULL, src VARCHAR(8) NOT NULL DEFAULT 'auto', PRIMARY KEY (phone, tag), KEY k_tag (tag), KEY k_until (until)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_tag_rule (tag VARCHAR(40) NOT NULL PRIMARY KEY, label VARCHAR(60) NOT NULL DEFAULT '', kind VARCHAR(10) NOT NULL DEFAULT 'manual', purpose VARCHAR(240) NOT NULL DEFAULT '', pattern TEXT NULL, rule TEXT NULL,
            recall DECIMAL(5,2) NOT NULL DEFAULT 0, falsepos DECIMAL(5,2) NOT NULL DEFAULT 0, n_tagged INT NOT NULL DEFAULT 0, auto TINYINT NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'learning', learned_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** The tags the system manages itself (key => [label, kind, rule in words, when it goes, where it is used]). */
function jt_auto_defs(): array
{
    return [
        'hot' => ['Ready to pay now', 'intent', 'Has not ordered and in the last 3 days wrote about address / payment / booking, or the chat is marked hot.', 'Goes after 3 days, or when they order.', 'Rescue picks these first; a person should answer fast.'],
        'interested' => ['Interested', 'intent', 'Has not ordered, wrote in the last 14 days and asked about a perfume / price, or answered one of our follow-ups.', 'Goes 21 days after their last message, or when they order.', 'Audiences for campaigns; the Journey lane is chosen for them.'],
        'considering' => ['Thinking / asking family', 'intent', 'Has not ordered and said they will think, ask at home or come back later.', 'Goes 14 days after their last message, or when they order.', 'Journey lane "asking family"; gentle, no-pressure messages.'],
        'not_interested' => ['Not interested', 'intent', 'Said so clearly (no thanks / not interested / do not message) in the last 30 days and has not ordered since.', 'Goes when they write again with a different message, or order.', 'The Journey never sells to them while it is on.'],
        'premium_buyer' => ['Premium buyer', 'status', 'Spends per order in the top quarter of all buyers.', 'Recomputed daily.', 'Premium messages and early-access steps in the after-order timeline.'],
        'budget_buyer' => ['Budget buyer', 'status', 'Spends per order in the bottom quarter of all buyers.', 'Recomputed daily.', 'Sample and offer steps in the after-order timeline.'],
        'order_query' => ['Order from a query', 'fact', 'The last order came from a customer who wrote to us (a query order).', 'Stays; follows the latest order.', 'Reports: which orders came from queries.'],
        'order_campaign' => ['Order from a campaign', 'fact', 'The last order came after one of our campaigns (a campaign order).', 'Stays; follows the latest order.', 'Reports: which orders came from campaigns.'],
        'returning_enquiry' => ['Asking again after ordering', 'intent', 'Ordered before and has written a new enquiry since.', 'Goes 30 days after their last message, or when they order again.', 'Treated as a returning customer in the Journey (new plan, welcome-back tone).'],
    ];
}
function jt_auto_keys(): array { return array_keys(jt_auto_defs()); }

/** Every tag the shop has: the system's own (existing catalog + chat + query + journey) and the automatic ones — for the screen. */
function jt_static_registry(): array
{
    $o = [];
    $cat = function_exists('wag_tag_catalog') ? wag_tag_catalog() : [];
    $status = ['bought' => 'Has an order.', 'repeat' => 'Two or more orders.', 'vip' => 'Many orders or a big spend (set in Customers).', 'not_ordered' => 'Wrote to us, never ordered.', 'never_replied' => 'Never wrote; our messages got no answer.', 'cold' => 'Last wrote 30+ days ago.', 'from_ad' => 'Came from a Meta ad.', 'came_back' => 'Came through a campaign.', 'cod_pending' => 'Cash-on-delivery order not paid yet.'];
    $outcome = ['wants_buy' => 'Chat marked "wants to buy" or a person noted "later".', 'price_issue' => 'Chat marked price, or a person noted a price problem.', 'not_interested' => 'A person noted "not interested" (and they have not ordered).', 'wrong_number' => 'A person noted a wrong number.', 'complaint' => 'Chat marked complaint, or a person noted one.'];
    foreach ($cat as $k => $l) $o[$k] = ['label' => $l, 'kind' => isset($outcome[$k]) ? 'intent' : 'status', 'rule' => $outcome[$k] ?? ($status[$k] ?? ''), 'expiry' => 'Recomputed every 10 minutes — goes by itself when it no longer fits.', 'used' => '', 'src' => 'system'];
    foreach (function_exists('wq_tag_labels') ? wq_tag_labels() : [] as $k => $l) if (!isset($o[$k])) $o[$k] = ['label' => $l, 'kind' => 'fact', 'rule' => strpos($k, '_query') !== false ? 'The type of the customer’s query (set by the rules in Queries).' : (strpos($k, 'arrive_') === 0 ? 'The time of day the query came.' : (strpos($k, 'premium_') === 0 ? 'The value of the query.' : '')), 'expiry' => 'Stays (a fact about that query).', 'used' => '', 'src' => 'system'];
    $o['ignored_fu'] = ['label' => '❄ Ignored our follow-ups (cold group)', 'kind' => 'journey', 'rule' => 'Ignored the set number of follow-ups in a row (Journey setting).', 'expiry' => 'Goes when they write again.', 'used' => 'The plan stops for them; an audience for campaigns.', 'src' => 'system'];
    foreach (jt_auto_defs() as $k => $d) $o[$k] = ['label' => $d[0], 'kind' => $d[1], 'rule' => $d[2], 'expiry' => $d[3], 'used' => $d[4], 'src' => 'auto'];
    return $o;
}

// ---------------------------------------------------------------------------------------------------------------------- features of a customer (the facts rules are written over)
function jt_kw(): array
{
    return ['hot' => ['address', 'pincode', 'pin code', 'payment', 'pay kar', 'paid', 'send link', 'payment link', 'cod', 'cash on delivery', 'order kar', 'book kar', 'confirm', 'qr', 'gpay', 'phonepe', 'paytm', 'upi', 'delivery kab', 'kab tak pahunch'],
        'think' => ['ghar pe puch', 'ghar wale', 'family', 'sochke', 'soch ke', 'think', 'will let you know', 'let me ask', 'baad me', 'baad mein', 'later', 'kal batata', 'kal bataunga', 'pachi', 'vichari', 'puchi ne'],
        'price' => ['costly', 'expensive', 'mehang', 'mahang', 'discount', 'price kam', 'kam karo', 'best price', 'last price', 'thoda kam', 'cheaper', 'price?', 'rate'],
        'neg' => ['not interested', 'no thanks', 'no thank', 'nahi chahiye', 'nai chahiye', 'dont message', "don't message", 'do not message', 'stop messaging', 'remove me', 'unsubscribe', 'band karo', 'mat bhejo', 'not required', 'no need', 'nathi joitu', 'nahi joiye']];
}
function jt_has(string $t, array $words): bool { foreach ($words as $w) if (mb_strpos($t, $w) !== false) return true; return false; }

/** phone => features, computed with a few set-based queries for a whole batch. */
function jt_features(PDO $pdo, array $phones): array
{
    $phones = array_values(array_unique(array_map('strval', $phones))); $F = []; if (!$phones) return $F; $now = time(); $K = jt_kw(); $cuts = function_exists('jn_cuts') ? jn_cuts($pdo) : ['hi' => 3000, 'lo' => 800];
    foreach (array_chunk($phones, 300) as $ch) {
        $in = implode(',', array_fill(0, count($ch), '?')); $cm = []; $cu = []; $oc = []; $qt = []; $fu = []; $msgs = []; $want = [];
        try { $q = $pdo->prepare("SELECT phone, last_in_at, last_out_at, source, tag, hot FROM wa_contacts WHERE phone IN ($in)"); $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $cm[(string)$r['phone']] = $r; } catch (Throwable $e) { }
        try { $q = $pdo->prepare("SELECT phone, n, spent, last_order FROM wa_cust WHERE phone IN ($in)"); $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $cu[(string)$r['phone']] = $r; } catch (Throwable $e) { }
        try { $q = $pdo->prepare("SELECT phone, code FROM wa_outcomes WHERE phone IN ($in) ORDER BY id"); $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $oc[(string)$r['phone']] = (string)$r['code']; } catch (Throwable $e) { }
        try { $q = $pdo->prepare("SELECT phone, type FROM wq_qtype WHERE phone IN ($in) ORDER BY opened_at"); $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $qt[(string)$r['phone']] = (string)$r['type']; } catch (Throwable $e) { }
        try { $q = $pdo->prepare("SELECT phone, sent_at FROM wa_fu_ev WHERE status = 'sent' AND sent_at >= ? AND phone IN ($in)"); $q->execute(array_merge([date('Y-m-d H:i:s', $now - 60 * 86400)], $ch)); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $fu[(string)$r['phone']][] = strtotime((string)$r['sent_at']); } catch (Throwable $e) { }
        try { $q = $pdo->prepare("SELECT phone, body, ts FROM wa_messages WHERE dir = 'in' AND mtype = 'text' AND ts >= ? AND phone IN ($in) ORDER BY id DESC"); $q->execute(array_merge([date('Y-m-d H:i:s', $now - 14 * 86400)], $ch));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $p = (string)$r['phone']; if (count($msgs[$p] ?? []) < 10) $msgs[$p][] = ['t' => mb_strtolower((string)$r['body']), 'ts' => strtotime((string)$r['ts'])]; } } catch (Throwable $e) { }
        try { $q = $pdo->prepare("SELECT phone, topic FROM wa_wj_topic WHERE phone IN ($in)"); $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $want[(string)$r['phone']] = (string)$r['topic']; } catch (Throwable $e) { }
        foreach ($ch as $p) {
            $c = $cm[$p] ?? null; $u = $cu[$p] ?? null; $in_ = $c && $c['last_in_at'] ? strtotime((string)$c['last_in_at']) : 0; $out_ = $c && $c['last_out_at'] ? strtotime((string)$c['last_out_at']) : 0; $n = $u ? (int)$u['n'] : 0; $sp = $u ? (float)$u['spent'] : 0.0; $lo = $u && $u['last_order'] ? strtotime((string)$u['last_order']) : 0;
            $sent = $fu[$p] ?? []; $ign = 0; $rep = false; foreach ($sent as $t) { if ($t > $in_) $ign++; elseif ($in_ > $t && $in_ - $t < 3 * 86400) $rep = true; }
            $txt = ''; $recent3 = ''; foreach ($msgs[$p] ?? [] as $i => $m) { $txt .= ' ' . $m['t']; if ($m['ts'] >= $now - 3 * 86400) $recent3 .= ' ' . $m['t']; }
            $last = ($msgs[$p][0]['t'] ?? '');
            $F[$p] = ['phone' => $p, 'n' => $n, 'spent' => $sp, 'avg_order' => $n > 0 ? $sp / $n : 0.0, 'ordered' => $n > 0 && $lo > 0 && ($in_ === 0 || $lo >= $in_ - 2 * 86400) ? 1 : 0, 'bought_before' => $n > 0 ? 1 : 0, 'last_order_ts' => $lo, 'last_in_ts' => $in_, 'last_out_ts' => $out_,
                'days_since_in' => $in_ ? (int)floor(($now - $in_) / 86400) : 999, 'days_since_order' => $lo ? (int)floor(($now - $lo) / 86400) : 999, 'ignored_fu' => $ign, 'replied_fu' => $rep ? 1 : 0,
                'chat_tag' => $c ? (string)$c['tag'] : '', 'source' => $c ? (string)$c['source'] : '', 'qtype' => $qt[$p] ?? '', 'outcome' => $oc[$p] ?? '', 'hot' => $c ? (int)$c['hot'] : 0, 'want' => $want[$p] ?? '',
                'kw_hot' => jt_has($recent3, $K['hot']) ? 1 : 0, 'kw_think' => jt_has($txt, $K['think']) ? 1 : 0, 'kw_price' => jt_has($txt, $K['price']) ? 1 : 0, 'kw_neg' => jt_has($last, $K['neg']) ? 1 : 0, 'msgs_14d' => count($msgs[$p] ?? []),
                'tier' => $n > 0 ? ($sp / $n >= $cuts['hi'] ? 'prem' : ($sp / $n < $cuts['lo'] ? 'bud' : 'std')) : '', 'comes_back' => $n > 0 && $in_ > $lo + 2 * 86400 ? 1 : 0];
        }
    }
    return $F;
}

// ---------------------------------------------------------------------------------------------------------------------- the automatic rules
/** tag => [why, until timestamp|null] for one customer. */
function jt_auto_for(array $f): array
{
    $now = time(); $t = []; $ord = (int)$f['ordered'] === 1; $in_ = (int)$f['last_in_ts'];
    if (!$ord && $in_ > 0) {
        if ((int)$f['kw_neg'] === 1 && $f['days_since_in'] <= 30) $t['not_interested'] = ['said they are not interested', $in_ + 60 * 86400];
        else {
            if ((int)$f['hot'] === 1 && $f['days_since_in'] <= 3 || (int)$f['kw_hot'] === 1) $t['hot'] = ['wrote about address / payment / booking in the last 3 days', $in_ + 3 * 86400];
            if ($f['days_since_in'] <= 14 && ($f['chat_tag'] === 'buy' || $f['chat_tag'] === 'price' || (int)$f['kw_price'] === 1 || (int)$f['replied_fu'] === 1 || (int)$f['kw_hot'] === 1)) $t['interested'] = ['asked about a perfume / price or answered our follow-up', $in_ + 21 * 86400];
            if ($f['days_since_in'] <= 14 && (int)$f['kw_think'] === 1) $t['considering'] = ['said they will think / ask at home / come back', $in_ + 14 * 86400];
        }
    }
    if ((int)$f['bought_before'] === 1) {
        if ($f['tier'] === 'prem') $t['premium_buyer'] = ['spends in the top quarter per order (₹' . round($f['avg_order']) . ' avg)', null];
        if ($f['tier'] === 'bud') $t['budget_buyer'] = ['spends in the bottom quarter per order (₹' . round($f['avg_order']) . ' avg)', null];
        if ((int)$f['comes_back'] === 1 && $f['days_since_in'] <= 30) $t['returning_enquiry'] = ['ordered before and wrote a new enquiry', $in_ + 30 * 86400];
    }
    return $t;
}
/** Replace one customer's automatic tags (only the managed ones) with the computed set. */
function jt_store(PDO $pdo, string $phone, array $tags, array $managed, string $src = 'auto'): void
{
    $now = date('Y-m-d H:i:s'); $keep = array_keys($tags);
    try {
        $q = $pdo->prepare("SELECT tag FROM wa_auto_tags WHERE phone = ? AND src = ?"); $q->execute([$phone, $src]); $have = $q->fetchAll(PDO::FETCH_COLUMN);
        foreach ($have as $h) if (in_array($h, $managed, true) && !in_array($h, $keep, true)) $pdo->prepare("DELETE FROM wa_auto_tags WHERE phone = ? AND tag = ? AND src = ?")->execute([$phone, $h, $src]);
        $up = $pdo->prepare("INSERT INTO wa_auto_tags (phone, tag, why, at, until, src) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE why = VALUES(why), until = VALUES(until), at = IF(src = VALUES(src), at, VALUES(at)), src = VALUES(src)");
        foreach ($tags as $k => $v) $up->execute([$phone, $k, mb_substr((string)$v[0], 0, 220), $now, $v[1] ? date('Y-m-d H:i:s', (int)$v[1]) : null, $src]);
    } catch (Throwable $e) { }
}
/** Order source of the customer's last order (query / campaign), from the same rule the order allocation uses. */
function jt_order_source(PDO $pdo, string $phone): string
{
    if (!function_exists('order_chat_type')) return '';
    try { $q = $pdo->prepare("SELECT * FROM wa_contacts WHERE phone = ?"); $q->execute([$phone]); $c = $q->fetch(PDO::FETCH_ASSOC); if (!$c) return '';
        $u = $pdo->prepare("SELECT last_order FROM wa_cust WHERE phone = ?"); $u->execute([$phone]); $lo = (string)$u->fetchColumn(); if ($lo === '') return '';
        return order_chat_type($pdo, $c, (int)strtotime($lo)) === 'campaign' ? 'campaign' : 'query'; } catch (Throwable $e) { return ''; }
}

// ---------------------------------------------------------------------------------------------------------------------- rules written by Claude for staff-made tags (a small, safe language)
function jt_fields(): array
{
    return ['n' => 'number of orders', 'spent' => 'total spent (₹)', 'avg_order' => 'spent per order (₹)', 'ordered' => '1 if they ordered after their last enquiry', 'bought_before' => '1 if they ever ordered', 'days_since_in' => 'days since they last wrote', 'days_since_order' => 'days since the last order',
        'ignored_fu' => 'follow-ups of ours they ignored since their last message', 'replied_fu' => '1 if they answered one of our follow-ups', 'chat_tag' => 'chat label: price / buy / order_status / complaint / other', 'source' => 'where the chat started: query / campaign / community',
        'qtype' => 'latest query type', 'outcome' => 'last outcome a person noted: later / price / no_interest / wrong / complaint …', 'hot' => '1 if the chat is marked ready to pay', 'want' => 'topic found by Claude', 'kw_hot' => '1 if they wrote about address / payment lately',
        'kw_think' => '1 if they said they will think / ask family', 'kw_price' => '1 if they talked price / discount', 'kw_neg' => '1 if their last message says no thanks', 'msgs_14d' => 'messages they wrote in the last 14 days', 'tier' => 'prem / std / bud (spend per order)', 'comes_back' => '1 if they wrote again after an order'];
}
function jt_eval_cond(array $c, array $f): bool
{
    $fld = (string)($c['f'] ?? ''); if (!isset(jt_fields()[$fld]) || !array_key_exists($fld, $f)) return false; $v = $c['v'] ?? null; $x = $f[$fld]; $op = (string)($c['op'] ?? '==');
    if ($op === 'in') return is_array($v) && in_array((string)$x, array_map('strval', $v), true);
    if (is_numeric($x) && is_numeric($v)) { $x = (float)$x; $v = (float)$v; } else { $x = (string)$x; $v = (string)$v; }
    switch ($op) { case '>=': return $x >= $v; case '<=': return $x <= $v; case '>': return $x > $v; case '<': return $x < $v; case '!=': return $x != $v; default: return $x == $v; }
}
function jt_eval(array $rule, array $f): bool
{
    $all = (array)($rule['all'] ?? []); $any = (array)($rule['any'] ?? []); if (!$all && !$any) return false;
    foreach ($all as $c) if (!is_array($c) || !jt_eval_cond($c, $f)) return false;
    if ($any) { foreach ($any as $c) if (is_array($c) && jt_eval_cond($c, $f)) return true; return false; }
    return true;
}
function jt_rule_clean($r): array
{
    $o = ['all' => [], 'any' => []]; if (!is_array($r)) return $o;
    foreach (['all', 'any'] as $g) foreach (array_slice((array)($r[$g] ?? []), 0, 6) as $c) { if (!is_array($c) || !isset(jt_fields()[(string)($c['f'] ?? '')])) continue; $op = in_array(($c['op'] ?? '=='), ['>=', '<=', '>', '<', '==', '!=', 'in'], true) ? $c['op'] : '=='; $v = $c['v'] ?? '';
        $o[$g][] = ['f' => (string)$c['f'], 'op' => $op, 'v' => is_array($v) ? array_slice(array_map('strval', $v), 0, 8) : (is_numeric($v) ? $v + 0 : mb_substr((string)$v, 0, 30))]; }
    return $o;
}
function jt_rule_text(array $r): string
{
    $s = function ($c) { return $c['f'] . ' ' . $c['op'] . ' ' . (is_array($c['v']) ? '[' . implode(', ', $c['v']) . ']' : $c['v']); }; $p = [];
    if (!empty($r['all'])) $p[] = implode(' AND ', array_map($s, $r['all'])); if (!empty($r['any'])) $p[] = '(' . implode(' OR ', array_map($s, $r['any'])) . ')';
    return implode(' AND ', $p);
}

/** Staff-made tags (wa_ctags, src person / list) that have no rule yet or whose rule is old. Returns tag names, biggest first. */
function jt_manual_candidates(PDO $pdo): array
{
    $skip = array_keys(jt_static_registry()); $o = [];
    try { $q = $pdo->query("SELECT tag, COUNT(*) n FROM wa_ctags WHERE src IN ('person', 'list') GROUP BY tag HAVING n >= 8 ORDER BY n DESC LIMIT 40");
        $have = []; foreach ($pdo->query("SELECT tag, learned_at FROM wq_tag_rule")->fetchAll(PDO::FETCH_ASSOC) as $r) $have[(string)$r['tag']] = (string)$r['learned_at'];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $t = (string)$r['tag']; if (in_array($t, $skip, true) || strpos($t, 'import_') === 0) continue; if (!isset($have[$t]) || ($have[$t] !== '' && strtotime($have[$t]) < time() - 14 * 86400)) $o[] = $t; } } catch (Throwable $e) { }
    return $o;
}
/** Study one staff-made tag: what do the customers who have it look like, compared with those who do not? Claude proposes a rule; the rule is scored; a good one is switched on. */
function jt_learn_tag(PDO $pdo, string $tag): array
{
    if (!function_exists('convo_ai')) return [false, 'Claude is not available.'];
    $T = []; $U = [];
    try { $q = $pdo->prepare("SELECT phone FROM wa_ctags WHERE tag = ? AND src IN ('person', 'list') ORDER BY at DESC LIMIT 80"); $q->execute([$tag]); $T = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
        $q = $pdo->prepare("SELECT phone FROM wa_contacts WHERE last_in_at >= ? ORDER BY MD5(CONCAT(phone, ?)) LIMIT 160"); $q->execute([date('Y-m-d H:i:s', time() - 90 * 86400), date('Ymd')]); $U = array_diff(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)), $T); $U = array_slice(array_values($U), 0, 80); } catch (Throwable $e) { return [false, 'Could not read the customers.']; }
    if (count($T) < 8 || count($U) < 20) return [false, 'Not enough customers to learn from.'];
    $FT = jt_features($pdo, $T); $FU = jt_features($pdo, $U); $stat = [];
    foreach (jt_fields() as $k => $desc) { $a = []; $b = []; foreach ($FT as $f) $a[] = $f[$k]; foreach ($FU as $f) $b[] = $f[$k];
        if (is_numeric($a[0] ?? null) || (count($a) && is_numeric($a[0]))) { $stat[$k] = ['tagged_avg' => round(array_sum($a) / max(1, count($a)), 2), 'others_avg' => round(array_sum($b) / max(1, count($b)), 2)]; }
        else { $ca = array_count_values(array_map('strval', $a)); $cb = array_count_values(array_map('strval', $b)); arsort($ca); arsort($cb); $stat[$k] = ['tagged_top' => array_slice($ca, 0, 4, true), 'others_top' => array_slice($cb, 0, 4, true)]; } }
    $ex = ''; try { $q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' AND mtype = 'text' ORDER BY id DESC LIMIT 3"); foreach (array_slice($T, 0, 8) as $p) { $q->execute([$p]); $ex .= '- ' . implode(' | ', array_map(function ($b) { return mb_substr(function_exists('convo_mask') ? convo_mask(trim((string)$b)) : trim((string)$b), 0, 90); }, $q->fetchAll(PDO::FETCH_COLUMN))) . "\n"; } } catch (Throwable $e) { }
    $fields = ''; foreach (jt_fields() as $k => $d) $fields .= "  $k = $d\n";
    $sch = ['type' => 'OBJECT', 'properties' => ['label' => ['type' => 'STRING'], 'purpose' => ['type' => 'STRING'], 'pattern' => ['type' => 'STRING'], 'rule' => ['type' => 'OBJECT', 'properties' => ['all' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['f' => ['type' => 'STRING'], 'op' => ['type' => 'STRING'], 'v' => ['type' => 'STRING']], 'required' => ['f', 'op', 'v']]],
        'any' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['f' => ['type' => 'STRING'], 'op' => ['type' => 'STRING'], 'v' => ['type' => 'STRING']], 'required' => ['f', 'op', 'v']]]]]], 'required' => ['label', 'purpose', 'pattern', 'rule']];
    $sys = "You study how the staff of a perfume shop (TM Perfume House, WhatsApp) use one customer tag, so the system can apply it by itself. You get the tag name, how the customers WITH the tag differ from the others (averages / most common values of measurable facts) and a few of their recent messages. "
        . "Answer: label = a clear short name (max 4 words, you may improve the tag's name), purpose = one sentence: where this tag is useful (a campaign audience, a Journey lane, an exclusion, a priority for the sales team), pattern = one plain sentence: WHY the staff probably give this tag, "
        . "rule = a measurable rule that reproduces it, made only of conditions {f, op, v} over the listed fields (op: >= <= > < == != or in with comma-separated values in v), grouped in all (every one must hold) and any (at least one must hold). Use the facts where the tagged customers clearly differ from the others; keep it simple (at most 4 conditions). "
        . "If the tag cannot be explained by these facts (for example it depends on something only a person knows) say so in pattern and give an empty rule.";
    try { [$ok, $v] = convo_ai($sys, [['text' => "TAG: $tag (" . count($T) . " customers have it; compared with " . count($U) . " others)\nFIELDS:\n$fields\nSTATISTICS (tagged vs others):\n" . json_encode($stat, JSON_UNESCAPED_UNICODE) . "\nRECENT MESSAGES OF TAGGED CUSTOMERS:\n$ex"]], $sch, false, 3000, 'claude'); } catch (Throwable $e) { return [false, 'Claude could not answer.']; }
    if (!$ok || !is_array($v)) return [false, 'Claude could not read the pattern.'];
    $rule = jt_rule_clean($v['rule'] ?? []); foreach (['all', 'any'] as $g) foreach ($rule[$g] as $i => $c) if ($c['op'] === 'in' && !is_array($c['v'])) $rule[$g][$i]['v'] = array_map('trim', explode(',', (string)$c['v']));
    $hit = 0; $fp = 0; if ($rule['all'] || $rule['any']) { foreach ($FT as $f) if (jt_eval($rule, $f)) $hit++; foreach ($FU as $f) if (jt_eval($rule, $f)) $fp++; }
    $recall = $FT ? $hit / count($FT) : 0; $fpr = $FU ? $fp / count($FU) : 0; $good = ($rule['all'] || $rule['any']) && $recall >= 0.6 && $fpr <= 0.3 && $recall > $fpr * 2;
    $label = mb_substr(trim((string)($v['label'] ?? $tag)), 0, 60) ?: $tag; $now = date('Y-m-d H:i:s');
    $old = null; try { $q = $pdo->prepare("SELECT label, auto FROM wq_tag_rule WHERE tag = ?"); $q->execute([$tag]); $old = $q->fetch(PDO::FETCH_ASSOC); } catch (Throwable $e) { }
    $pdo->prepare("INSERT INTO wq_tag_rule (tag, label, kind, purpose, pattern, rule, recall, falsepos, n_tagged, auto, status, learned_at, updated_at) VALUES (?, ?, 'manual', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE label = IF(label <> '' AND label <> tag, label, VALUES(label)), purpose = VALUES(purpose), pattern = VALUES(pattern), rule = VALUES(rule), recall = VALUES(recall), falsepos = VALUES(falsepos), n_tagged = VALUES(n_tagged), status = VALUES(status), learned_at = VALUES(learned_at), updated_at = VALUES(updated_at)")
        ->execute([$tag, $label, mb_substr(trim((string)($v['purpose'] ?? '')), 0, 240), mb_substr(trim((string)($v['pattern'] ?? '')), 0, 600), json_encode($rule, JSON_UNESCAPED_UNICODE), round($recall, 2), round($fpr, 2), count($T), $good ? 1 : 0, $good ? 'active' : 'weak', $now, $now]);
    if ($old && (int)$old['auto'] === 0 && $good === false) { /* an owner who switched it off stays off */ }
    return [true, ['tag' => $tag, 'label' => $label, 'recall' => round($recall, 2), 'falsepos' => round($fpr, 2), 'auto' => $good]];
}
function jt_learned_rules(PDO $pdo): array
{
    static $c = null; if ($c !== null) return $c; $c = [];
    try { foreach ($pdo->query("SELECT tag, label, rule FROM wq_tag_rule WHERE auto = 1 AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $r) { $j = json_decode((string)$r['rule'], true); if (is_array($j)) $c[(string)$r['tag']] = ['label' => (string)$r['label'], 'rule' => $j]; } } catch (Throwable $e) { }
    return $c;
}
/** The display name of a learned / renamed tag ('' when none). */
function jt_label(PDO $pdo, string $tag): string
{
    static $m = null; if ($m === null) { $m = []; try { foreach ($pdo->query("SELECT tag, label FROM wq_tag_rule WHERE label <> ''")->fetchAll(PDO::FETCH_ASSOC) as $r) $m[(string)$r['tag']] = (string)$r['label']; } catch (Throwable $e) { } }
    return $m[$tag] ?? '';
}

// ---------------------------------------------------------------------------------------------------------------------- the background (called from the Journey tick on the cron)
/** Tag a batch of customers now. Returns how many. */
function jt_run_batch(PDO $pdo, array $phones): int
{
    $F = jt_features($pdo, $phones); if (!$F) return 0; $managed = array_values(array_diff(jt_auto_keys(), ['order_query', 'order_campaign'])); $L = jt_learned_rules($pdo); $n = 0; $os = [];
    try { $in = implode(',', array_fill(0, count($F), '?')); $q = $pdo->prepare("SELECT phone, MAX(at) a FROM wa_auto_tags WHERE tag IN ('order_query', 'order_campaign') AND phone IN ($in) GROUP BY phone"); $q->execute(array_keys($F)); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $os[(string)$r['phone']] = strtotime((string)$r['a']); } catch (Throwable $e) { }
    foreach ($F as $p => $f) {
        $tags = jt_auto_for($f);
        if ((int)$f['bought_before'] === 1 && ($f['days_since_order'] ?? 999) <= 200 && (!isset($os[$p]) || $os[$p] < (int)$f['last_order_ts'])) {   // once per order, not on every round
            $so = jt_order_source($pdo, $p); if ($so !== '') jt_store($pdo, $p, ['order_' . $so => ['the last order (' . date('j M', (int)$f['last_order_ts']) . ') came from a ' . ($so === 'campaign' ? 'campaign' : 'query'), null]], ['order_query', 'order_campaign'], 'auto'); }
        jt_store($pdo, $p, $tags, $managed, 'auto');
        if ($L) { $lt = []; foreach ($L as $tg => $d) if (jt_eval($d['rule'], $f)) $lt[$tg] = ['matches the pattern of your tag “' . $d['label'] . '”', time() + 30 * 86400]; jt_store($pdo, $p, $lt, array_keys($L), 'learn'); }
        $n++;
    }
    return $n;
}
function jt_tick(PDO $pdo, bool $force = false): array
{
    $o = []; if ((string)setting_get($pdo, 'jt_bg', '1') === '0' || !jt_tables($pdo)) return $o;
    if (!$force && (int)setting_get($pdo, 'jt_at', '0') > time() - 55) return $o; setting_set($pdo, 'jt_at', (string)time());
    if (function_exists('wa_lock') && !wa_lock($pdo, 'jttick', 200)) return ['locked' => 1];
    try { $pdo->exec("DELETE FROM wa_auto_tags WHERE until IS NOT NULL AND until < NOW()"); } catch (Throwable $e) { }
    $lim = $force ? 600 : 250; $ph = [];
    try { $q = $pdo->prepare("SELECT phone FROM wa_contacts WHERE last_in_at >= ? ORDER BY last_in_at DESC LIMIT 120"); $q->execute([date('Y-m-d H:i:s', time() - 26 * 3600)]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $x) $ph[(string)$x] = 1;   // changed in the last day
        $cur = (string)setting_get($pdo, 'jt_cursor', ''); $q = $pdo->prepare("SELECT phone FROM wa_contacts WHERE phone > ? AND (last_in_at >= ? OR phone IN (SELECT phone FROM wa_cust WHERE n > 0 AND last_order >= ?)) ORDER BY phone LIMIT " . (int)$lim);
        $q->execute([$cur, date('Y-m-d H:i:s', time() - 62 * 86400), date('Y-m-d H:i:s', time() - 400 * 86400)]); $rows = $q->fetchAll(PDO::FETCH_COLUMN); foreach ($rows as $x) $ph[(string)$x] = 1; setting_set($pdo, 'jt_cursor', count($rows) < $lim ? '' : (string)end($rows));
    } catch (Throwable $e) { error_log('jt_tick select: ' . $e->getMessage()); }
    $o['tagged'] = jt_run_batch($pdo, array_keys($ph));
    // one staff-made tag at a time: learn its pattern
    if ((int)setting_get($pdo, 'jt_learn_at', '0') < time() - 1800 || $force) { setting_set($pdo, 'jt_learn_at', (string)time());
        $c = jt_manual_candidates($pdo); if ($c) { [$ok, $r] = jt_learn_tag($pdo, $c[0]); $o['learned'] = $ok ? $r : (string)$r; } }
    // once a day: Claude looks for new useful tags on its own, and tags nobody has any more retire
    if ((int)setting_get($pdo, 'jt_disc_at', '0') < time() - 20 * 3600 || ($force && !empty($GLOBALS['__jt_discover']))) { setting_set($pdo, 'jt_disc_at', (string)time()); [$ok, $r] = jt_discover($pdo); $o['discover'] = $ok ? $r : (string)$r; $o['retired'] = jt_retire_unused($pdo); }
    return $o;
}

/** 🧠 Claude's own tags: it looks at how customers really behave and makes any new tag that would help the 60-day plan (a lane, a tone, an exclusion, a campaign audience) — on any condition it can measure.
 *  It knows every existing tag and must not repeat one or fight one: a new tag whose customers are (almost) the same as an existing tag's is refused as a duplicate, a tag that fits almost nobody or almost everybody is refused,
 *  and the tags it contradicts / overlaps are written on it. Up to 3 a day. A tag nobody has for a month retires by itself. */
function jt_discover(PDO $pdo): array
{
    if (!function_exists('convo_ai')) return [false, 'Claude is not available.'];
    $reg = jt_static_registry(); $rules = []; try { foreach ($pdo->query("SELECT tag, label, rule, purpose, status FROM wq_tag_rule")->fetchAll(PDO::FETCH_ASSOC) as $r) $rules[(string)$r['tag']] = $r; } catch (Throwable $e) { }
    $ph = []; try { $q = $pdo->prepare("SELECT phone FROM wa_contacts WHERE last_in_at >= ? ORDER BY MD5(CONCAT(phone, ?)) LIMIT 220"); $q->execute([date('Y-m-d H:i:s', time() - 60 * 86400), date('YmdH')]); $ph = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { }
    if (count($ph) < 40) return [false, 'Not enough active customers yet.'];
    $F = jt_features($pdo, $ph); $stat = [];
    foreach (jt_fields() as $k => $d) { $v = []; foreach ($F as $f) $v[] = $f[$k]; if ($v && is_numeric($v[0])) { sort($v); $stat[$k] = ['avg' => round(array_sum($v) / count($v), 2), 'p50' => $v[(int)floor(count($v) / 2)], 'p90' => $v[(int)floor(count($v) * 0.9)]]; } else { $c = array_count_values(array_map('strval', $v)); arsort($c); $stat[$k] = array_slice($c, 0, 5, true); } }
    $have = ''; foreach ($reg as $k => $d) $have .= "- $k = " . $d['label'] . ': ' . mb_substr($d['rule'], 0, 110) . "\n"; foreach ($rules as $k => $r) if (!isset($reg[$k])) $have .= "- $k = " . $r['label'] . ' (' . $r['status'] . ')' . "\n";
    $ex = ''; try { $q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' AND mtype = 'text' ORDER BY id DESC LIMIT 3"); foreach (array_slice($ph, 0, 14) as $p) { $q->execute([$p]); $ex .= '- ' . implode(' | ', array_map(function ($b) { return mb_substr(function_exists('convo_mask') ? convo_mask(trim((string)$b)) : trim((string)$b), 0, 80); }, $q->fetchAll(PDO::FETCH_COLUMN))) . "\n"; } } catch (Throwable $e) { }
    $fields = ''; foreach (jt_fields() as $k => $d) $fields .= "  $k = $d\n";
    $cond = ['type' => 'OBJECT', 'properties' => ['f' => ['type' => 'STRING'], 'op' => ['type' => 'STRING'], 'v' => ['type' => 'STRING']], 'required' => ['f', 'op', 'v']];
    $sch = ['type' => 'OBJECT', 'properties' => ['tags' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['key' => ['type' => 'STRING'], 'label' => ['type' => 'STRING'], 'purpose' => ['type' => 'STRING'], 'why' => ['type' => 'STRING'],
        'rule' => ['type' => 'OBJECT', 'properties' => ['all' => ['type' => 'ARRAY', 'items' => $cond], 'any' => ['type' => 'ARRAY', 'items' => $cond]]], 'conflicts' => ['type' => 'STRING']], 'required' => ['key', 'label', 'purpose', 'why', 'rule', 'conflicts']]]], 'required' => ['tags']];
    $sys = "You are the tagging brain of the WhatsApp system of TM Perfume House (Indian perfume brand). The shop follows up customers who did not order for 60 days and keeps in touch after an order. You may create ANY new customer tag that would make that work better — "
        . "a tag that chooses a lane or a tone in the follow-up plan, excludes people from selling, or makes a useful campaign audience — on any condition you can measure with the listed FIELDS. You see every EXISTING tag: do not repeat one, and do not make a tag that fights one without saying so. "
        . "Propose at most 3 tags, only when the facts show a real, useful group (not everyone, not almost nobody). For each: key (lowercase snake_case, max 24 letters, not an existing key), label (short, clear, max 4 words), purpose (where it helps — one sentence), why (what behaviour it recognises — one sentence), "
        . "rule (conditions {f, op, v}; op: >= <= > < == != or in with comma-separated values; all = every one must hold, any = at least one), conflicts (the existing tag keys it overlaps with or contradicts and how you keep them apart; empty if none). If nothing useful is missing return an empty list.";
    try { [$ok, $v] = convo_ai($sys, [['text' => "EXISTING TAGS:\n$have\nFIELDS:\n$fields\nSTATISTICS of " . count($F) . " customers active in the last 60 days:\n" . json_encode($stat, JSON_UNESCAPED_UNICODE) . "\nRECENT MESSAGES:\n$ex"]], $sch, false, 4000, 'claude'); } catch (Throwable $e) { return [false, 'Claude could not answer.']; }
    if (!$ok || !is_array($v)) return [false, 'Claude could not answer.'];
    $made = []; $skipped = [];
    foreach (array_slice((array)($v['tags'] ?? []), 0, 3) as $t) {
        $k = strtolower(preg_replace('/[^a-z0-9_]/', '', str_replace(' ', '_', (string)($t['key'] ?? '')))); $k = substr($k, 0, 24); if ($k === '' || isset($reg[$k]) || isset($rules[$k])) { $skipped[] = ($k ?: '?') . ': name already used'; continue; }
        $rule = jt_rule_clean($t['rule'] ?? []); foreach (['all', 'any'] as $g) foreach ($rule[$g] as $i => $c) if ($c['op'] === 'in' && !is_array($c['v'])) $rule[$g][$i]['v'] = array_map('trim', explode(',', (string)$c['v']));
        if (!$rule['all'] && !$rule['any']) { $skipped[] = "$k: no measurable rule"; continue; }
        $A = []; foreach ($F as $p => $f) if (jt_eval($rule, $f)) $A[$p] = 1; $cov = count($A) / count($F); if ($cov < 0.02 || $cov > 0.5) { $skipped[] = "$k: fits " . round($cov * 100) . '% of customers'; continue; }
        $dup = ''; $overlap = [];
        foreach ($F as $p => $f) { $set = []; foreach (jt_auto_for($f) as $tg => $_) $set[$tg] = 1; foreach (jt_learned_rules($pdo) as $tg => $d) if (jt_eval($d['rule'], $f)) $set[$tg] = 1; foreach ($set as $tg => $_) { $overlap[$tg]['b'] = ($overlap[$tg]['b'] ?? 0) + 1; if (isset($A[$p])) $overlap[$tg]['i'] = ($overlap[$tg]['i'] ?? 0) + 1; } }
        foreach ($overlap as $tg => $o) { $i = (int)($o['i'] ?? 0); $u = $o['b'] + count($A) - $i; if ($u > 0 && $i / $u >= 0.85 && $o['b'] >= 3) $dup = $tg; }
        if ($dup !== '') { $skipped[] = "$k: same customers as $dup"; continue; }
        $note = trim((string)($t['conflicts'] ?? '')); $lab = mb_substr(trim((string)($t['label'] ?? $k)), 0, 60) ?: $k; $now = date('Y-m-d H:i:s');
        try { $pdo->prepare("INSERT INTO wq_tag_rule (tag, label, kind, purpose, pattern, rule, recall, falsepos, n_tagged, auto, status, learned_at, updated_at) VALUES (?, ?, 'claude', ?, ?, ?, ?, 0, ?, 1, 'active', ?, ?)")
            ->execute([$k, $lab, mb_substr(trim((string)($t['purpose'] ?? '')), 0, 240), mb_substr(trim((string)($t['why'] ?? '')) . ($note !== '' ? ' — Fits with other tags: ' . $note : ''), 0, 600), json_encode($rule, JSON_UNESCAPED_UNICODE), round($cov, 2), count($A), $now, $now]); $made[] = $k; } catch (Throwable $e) { }
    }
    return [true, ['made' => $made, 'skipped' => $skipped]];
}
/** A tag of Claude's (or a learned one) that nobody has had for a month retires by itself. */
function jt_retire_unused(PDO $pdo): int
{
    $n = 0; try { foreach ($pdo->query("SELECT tag FROM wq_tag_rule WHERE auto = 1 AND learned_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_auto_tags WHERE tag = ? AND src = 'learn'"); $q->execute([(string)$t]); if ((int)$q->fetchColumn() === 0) { $pdo->prepare("UPDATE wq_tag_rule SET auto = 0, status = 'retired', updated_at = ? WHERE tag = ?")->execute([date('Y-m-d H:i:s'), (string)$t]); $n++; } } } catch (Throwable $e) { }
    return $n;
}

// ---------------------------------------------------------------------------------------------------------------------- one customer: their tags with the reason
function jt_customer_tags(PDO $pdo, string $phone): array
{
    $o = []; if (!jt_tables($pdo)) return $o; $reg = jt_static_registry();
    try { $q = $pdo->prepare("SELECT tag, why, until, src FROM wa_auto_tags WHERE phone = ? AND (until IS NULL OR until > NOW()) ORDER BY at DESC"); $q->execute([$phone]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $t = (string)$r['tag']; $o[] = ['tag' => $t, 'label' => $reg[$t]['label'] ?? (jt_label($pdo, $t) ?: $t), 'why' => (string)$r['why'], 'until' => $r['until'] ? substr((string)$r['until'], 0, 10) : '', 'auto' => true]; } } catch (Throwable $e) { }
    try { $q = $pdo->prepare("SELECT tag, src, at FROM wa_ctags WHERE phone = ? ORDER BY at DESC LIMIT 20"); $q->execute([$phone]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $t = (string)$r['tag']; $o[] = ['tag' => $t, 'label' => $reg[$t]['label'] ?? (jt_label($pdo, $t) ?: $t), 'why' => in_array((string)$r['src'], ['person', 'list'], true) ? 'put by a person' : ($r['src'] === 'camp' ? 'from a campaign' : ($r['src'] === 'import' ? 'imported' : 'set by the system (' . $r['src'] . ')')), 'until' => '', 'auto' => false]; } } catch (Throwable $e) { }
    return $o;
}

}   // function_exists guard

// ------------------------------------------------------------------------------------------------------------------ router (admin)
$__jtm = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__jtm, ['wq.tags_list', 'wq.tags_run', 'wq.tags_rule'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth();
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    if (function_exists('wq_deps')) wq_deps($pdo); if (!jt_tables($pdo)) json_response(['error' => 'Tag storage is not available.'], 500);
    if (!wa_is_admin($pdo, $auth)) json_response(['error' => 'Only Admin, HR or the control tower can do this.'], 403);
    $in = $isPost ? json_input() : $_GET;
    try {
        if ($__jtm === 'wq.tags_list') {
            if ($isPost) json_response(['error' => 'Method not allowed.'], 405);
            $cnt = []; try { foreach ($pdo->query("SELECT tag, COUNT(*) n FROM wa_tagidx GROUP BY tag")->fetchAll(PDO::FETCH_ASSOC) as $r) $cnt[(string)$r['tag']] = (int)$r['n']; } catch (Throwable $e) { }
            $rules = []; foreach ($pdo->query("SELECT * FROM wq_tag_rule")->fetchAll(PDO::FETCH_ASSOC) as $r) $rules[(string)$r['tag']] = $r;
            $rows = []; foreach (jt_static_registry() as $k => $d) $rows[] = ['tag' => $k, 'label' => $d['label'], 'kind' => $d['kind'], 'rule' => $d['rule'], 'expiry' => $d['expiry'], 'used' => $d['used'], 'src' => $d['src'], 'n' => $cnt[$k] ?? 0];
            $man = []; foreach (array_merge(array_keys($rules), jt_manual_candidates($pdo)) as $t) { if (isset($man[$t])) continue; $r = $rules[$t] ?? null; $mn = 0; try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_ctags WHERE tag = ? AND src IN ('person', 'list')"); $q->execute([$t]); $mn = (int)$q->fetchColumn(); } catch (Throwable $e) { }
                $man[$t] = ['tag' => $t, 'label' => $r ? $r['label'] : $t, 'learned' => (bool)$r, 'pattern' => $r ? (string)$r['pattern'] : '', 'purpose' => $r ? (string)$r['purpose'] : '', 'rule_text' => $r ? jt_rule_text(jt_rule_clean(json_decode((string)$r['rule'], true))) : '', 'recall' => $r ? (float)$r['recall'] : 0, 'falsepos' => $r ? (float)$r['falsepos'] : 0, 'auto' => $r ? (int)$r['auto'] : 0, 'status' => $r ? (string)$r['status'] : 'waiting', 'by_hand' => $mn, 'n' => $cnt[$t] ?? $mn]; }
            json_response(['ok' => true, 'rows' => $rows, 'manual' => array_values($man), 'last_run' => (int)setting_get($pdo, 'jt_at', '0') ? date('Y-m-d H:i', (int)setting_get($pdo, 'jt_at', '0')) : '']);
        }
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        if ($__jtm === 'wq.tags_run') { if (!empty($in['discover'])) $GLOBALS['__jt_discover'] = 1; $r = jt_tick($pdo, true); if (!empty($in['tag'])) { [$ok, $x] = jt_learn_tag($pdo, preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$in['tag'])); $r['learned'] = $ok ? $x : (string)$x; } try { setting_set($pdo, 'wa_tag_cache', ''); } catch (Throwable $e) { } json_response(['ok' => true, 'message' => 'Done: ' . (int)($r['tagged'] ?? 0) . ' customers tagged now' . (isset($r['learned']) ? (is_array($r['learned']) ? ' · studied “' . $r['learned']['tag'] . '” (' . round($r['learned']['recall'] * 100) . '% matched)' : ' · ' . $r['learned']) : '') . (isset($r['discover']) && is_array($r['discover']) ? ' · Claude made ' . count($r['discover']['made']) . ' new tag(s)' . ($r['discover']['skipped'] ? ' (' . count($r['discover']['skipped']) . ' refused: ' . implode('; ', array_slice($r['discover']['skipped'], 0, 3)) . ')' : '') : '') . '.'] + $r); }
        if ($__jtm === 'wq.tags_rule') {
            $t = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['tag'] ?? '')); if ($t === '') json_response(['error' => 'Which tag?'], 422);
            if (array_key_exists('auto', $in)) $pdo->prepare("UPDATE wq_tag_rule SET auto = ?, status = IF(? = 1, 'active', 'off'), updated_at = ? WHERE tag = ?")->execute([!empty($in['auto']) ? 1 : 0, !empty($in['auto']) ? 1 : 0, date('Y-m-d H:i:s'), $t]);
            if (isset($in['label']) && trim((string)$in['label']) !== '') $pdo->prepare("UPDATE wq_tag_rule SET label = ?, updated_at = ? WHERE tag = ?")->execute([mb_substr(trim((string)$in['label']), 0, 60), date('Y-m-d H:i:s'), $t]);
            if (!empty($in['retire'])) { $pdo->prepare("UPDATE wq_tag_rule SET auto = 0, status = 'retired', updated_at = ? WHERE tag = ?")->execute([date('Y-m-d H:i:s'), $t]); $pdo->prepare("DELETE FROM wa_auto_tags WHERE tag = ? AND src = 'learn'")->execute([$t]); }
            if (!empty($in['auto'])) $pdo->prepare("UPDATE wq_tag_rule SET updated_at = updated_at WHERE tag = ?")->execute([$t]);
            try { setting_set($pdo, 'wa_tag_cache', ''); } catch (Throwable $e) { }
            json_response(['ok' => true, 'message' => 'Saved ✓']);
        }
    } catch (Throwable $e) { error_log('wq_tags: ' . $e->getMessage()); json_response(['error' => 'Tags: ' . mb_substr($e->getMessage(), 0, 200)], 500); }
}
