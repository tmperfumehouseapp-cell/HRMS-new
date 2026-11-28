<?php
if (!function_exists('wa_strip_t0')) {
    /** THE START OF THE SALES DAY (owner 25 Nov 2026: "all numbers by the sales day, not from midnight"): the business day starts at the day-start time of the day (10:00 normally, or the date-specific time set on Day timing, e.g. 12:25), the same as attendance and orders. Falls back to midnight where that function is not loaded. */
    function wa_strip_t0(PDO $pdo): string { try { if (function_exists('wa_bizday_start')) return wa_bizday_start($pdo); } catch (Throwable $e) { } return date('Y-m-d 00:00:00'); }
}
/**
 * Message patterns (owner 24 Nov 2026): every message sent today (or the last 3 days) in Cold, Lineup, After-Order, Module A, B, C and the bot follow-ups,
 * sorted into TOPICS (price, launch, size, reorder ...) and STYLES (short / long, question, emoji), with sample messages and how many people REPLIED
 * within 48 hours of each. Read-only: counts and the template texts, nothing is changed and no customer phone is shown. Cached 10 minutes.
 */
if (!function_exists('wa_pat_topic')) {
    /** [key, label] — the first rule that matches wins; the order goes from the most special to the most general. */
    function wa_pat_topic(string $name, string $body): array
    {
        $t = mb_strtolower($body . ' ' . $name);
        $rules = [
            ['addon', 'Add-on / extra discount', '/add[- ]?on|extra discount|bonus|combo|એડ ઓન|એક્સ્ટ્રા/u'],
            ['launch', 'New perfume / launch', '/launch|new perfume|new arrival|just arrived|naya|નવું|નવી|લોન્ચ|નયા/u'],
            ['surprise', 'Surprise / mystery / curiosity', '/surprise|mystery|secret|guess|curious|સરપ્રાઈઝ|રહસ્ય/u'],
            ['gift', 'Gift / wedding / festival / season', '/gift|wedding|shaadi|diwali|new year|festival|winter|christmas|valentine|black friday|cyber monday|ગિફ્ટ|લગ્ન|દિવાળી|શિયાળ/u'],
            ['reorder', 'Refill / reorder / finished', '/refill|re-?order|reorder|finish|khatam|repeat|again|ફરી|પૂરું|ખતમ/u'],
            ['size', 'Size / ML choice', '/\b\d+ ?ml\b|\bml\b|size|bottle|સાઇઝ|બોટલ/u'],
            ['price', 'Order push (offer · price · combo · deadline)', '/₹|\brs\.?\b|offer|discount|price|% ?off|sale|deal|combo|flat|deadline|sirf|jaldi|aaj hi|abhi order|order (now|kar)|ઓફર|કિંમત|ડિસ્કાઉન્ટ/u'],
            ['order', 'Order / parcel / address', '/order|parcel|delivery|dispatch|shipped|address|tracking|ઓર્ડર|પાર્સલ|ડિલિવરી|સરનામ/u'],
            ['feedback', 'Feedback / check-in', '/feedback|review|how was|how is|liked|hope you|check[- ]in|કેવી|કેવું|ગમ/u'],
        ];
        foreach ($rules as $r) if (preg_match($r[2], $t)) return [$r[0], $r[1]];
        if (preg_match('/\?\s*$/u', trim($body))) return ['question', 'Question (other topic)'];
        return ['general', 'General / other'];
    }
    /** Style tags of a text: length, question or statement, emoji. */
    function wa_pat_style(string $body): array
    {
        $b = trim($body); $n = mb_strlen($b);
        $len = $n < 110 ? 'Short (under 110 letters)' : ($n < 240 ? 'Medium (110-240)' : 'Long (over 240)');
        $q = preg_match('/\?/u', $b) ? 'Asks a question' : 'Statement only';
        $e = preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $b) ? 'With emoji' : 'No emoji';
        return [$len, $q, $e];
    }
    /** The data of one module: rows [tpl_id, sent, replied] */
    function wa_pat_rows(PDO $pdo, string $mod, string $from, int $mult): array
    {
        $rep = function ($ph, $at) { return "EXISTS (SELECT 1 FROM wa_messages rm WHERE rm.phone = $ph COLLATE utf8mb4_unicode_ci AND rm.dir = 'in' AND rm.ts > $at AND rm.ts <= DATE_ADD($at, INTERVAL 48 HOUR))"; };   /* inner alias rm: with the outer alias i (Module A) the old inner i hid it and every row counted as replied */
        $m = max(1, $mult); $smp = function ($c) use ($m) { return $m > 1 ? " AND $c % $m = 0" : ''; };
        switch ($mod) {
            case 'cold': $sql = "SELECT l.tpl_id, COUNT(*), SUM(" . $rep('l.phone', 'l.at') . ") FROM wa_cold_log l WHERE l.ok = 1 AND l.tpl_id > 0 AND l.at >= ?" . $smp('l.id') . " GROUP BY l.tpl_id"; break;
            case 'modb': $sql = "SELECT l.tpl_id, COUNT(*), SUM(" . $rep('l.phone', 'l.at') . ") FROM wa_ru_log l WHERE l.ok = 1 AND l.kind <> 'cnev' AND l.tpl_id > 0 AND l.at >= ?" . $smp('l.id') . " GROUP BY l.tpl_id"; break;
            case 'modc': $sql = "SELECT l.tpl_id, COUNT(*), SUM(" . $rep('l.phone', 'l.at') . ") FROM wa_ru_log l WHERE l.ok = 1 AND l.kind = 'cnev' AND l.tpl_id > 0 AND l.at >= ?" . $smp('l.id') . " GROUP BY l.tpl_id"; break;
            case 'after': $sql = "SELECT l.tpl_id, COUNT(*), SUM(" . $rep('l.phone', 'l.sent_at') . ") FROM wa_aft_plan l WHERE l.status = 'sent' AND l.tpl_id > 0 AND l.sent_at >= ? GROUP BY l.tpl_id"; $m = 1; break;
            case 'line': $sql = "SELECT l.tpl_id, COUNT(*), SUM(" . $rep('l.phone', 'l.sent_at') . ") FROM wa_line_plan l WHERE l.status = 'sent' AND l.tpl_id > 0 AND l.sent_at >= ? GROUP BY l.tpl_id"; $m = 1; break;
            case 'moda': $m = max($m, 24); $sql = "SELECT c.tpl_id, COUNT(*), SUM(" . $rep('i.phone', 'i.sent_at') . ") FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE i.id > (SELECT MAX(id) - 200000 FROM wa_camp_items) AND i.sent_at >= ? AND c.tpl_id > 0 AND (i.status IN ('sent','delivered','read') OR i.last_status IN ('delivered','read'))" . " AND i.id % 24 = 0 GROUP BY c.tpl_id"; break;
            default: return [];
        }
        $q = $pdo->prepare($sql); $q->execute([$from]); $o = [];
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) $o[] = [(int)$r[0], (int)$r[1] * $m, (int)$r[2] * $m];
        return $o;
    }
    /** Bot follow-ups are free text (no template): grouped by the text itself. Returns pseudo rows [text, sent, replied] */
    function wa_pat_followups(PDO $pdo, string $from): array
    {
        $q = $pdo->prepare("SELECT SUBSTRING(m.body, 1, 500), EXISTS (SELECT 1 FROM wa_messages i WHERE i.phone = m.phone AND i.dir = 'in' AND i.ts > m.ts AND i.ts <= DATE_ADD(m.ts, INTERVAL 48 HOUR)) FROM wa_messages m WHERE m.dir = 'out' AND m.who = 'followup' AND m.ts >= ? ORDER BY m.id DESC LIMIT 6000");
        $q->execute([$from]); return $q->fetchAll(PDO::FETCH_NUM);
    }
    function wa_pat_report(PDO $pdo, string $scope = 'today', bool $fresh = false, string $only = ''): array
    {
        $scope = $scope === '3d' ? '3d' : 'today'; $only = preg_replace('/\W/', '', $only); $key = 'wa_pat_cache_' . $scope . ($only !== '' ? '_' . $only : '');
        if (!$fresh) { $c = json_decode((string)setting_get($pdo, $key, ''), true); if (is_array($c) && !empty($c['t']) && time() - (int)$c['t'] < 600) return $c; }
        @set_time_limit(120); try { $pdo->exec("SET SESSION MAX_EXECUTION_TIME = 25000"); } catch (Throwable $e) { }
        $from = $scope === '3d' ? date('Y-m-d 00:00:00', time() - 2 * 86400) : wa_strip_t0($pdo); $mult0 = $scope === '3d' ? 3 : 1;
        $names = ['cold' => 'Cold', 'line' => '30-day Lineup', 'after' => 'After-Order', 'moda' => 'Module A (campaigns)', 'modb' => 'Module B', 'modc' => 'Module C', 'follow' => 'Bot follow-ups'];
        $tpl = []; $out = ['t' => time(), 'scope' => $scope, 'from' => $from, 'sampled' => $mult0 > 1, 'modules' => []];
        foreach ($names as $mod => $label) {
            if ($only !== '' && $mod !== $only) continue;
            $mult = in_array($mod, ['cold', 'modb', 'modc'], true) ? max($mult0, 3) : $mult0;   /* the three big ones are read as a 1-in-3 sample and scaled, so each module answers in a few seconds */
            $M = ['key' => $mod, 'label' => $label, 'sent' => 0, 'replied' => 0, 'topics' => [], 'styles' => [], 'err' => '', 'new' => ['sent' => 0, 'replied' => 0, 'types' => []]];
            try {
                $items = [];   // [text, name, sent, replied]
                if ($mod === 'follow') { $g = []; foreach (wa_pat_followups($pdo, $from) as $r) { $k = md5(mb_substr(preg_replace('/\d+/', '#', (string)$r[0]), 0, 60)); if (!isset($g[$k])) $g[$k] = [(string)$r[0], '', 0, 0]; $g[$k][2]++; $g[$k][3] += (int)$r[1]; } $items = array_values($g); }
                else {
                    $rows = wa_pat_rows($pdo, $mod, $from, $mult); $need = [];
                    foreach ($rows as $r) if (!isset($tpl[$r[0]])) $need[] = $r[0];
                    if ($need) { $q = $pdo->query("SELECT id, name, body, extra FROM wa_templates WHERE id IN (" . implode(',', array_map('intval', $need)) . ")"); foreach ($q->fetchAll(PDO::FETCH_NUM) as $t) $tpl[(int)$t[0]] = [(string)$t[1], (string)$t[2], (bool)(preg_match('/^(tmph_style_|tmph_new_|tmph_fu_(open|ask|order)_(hi|ord|new)_)/', (string)$t[1]) || strpos((string)$t[3], 'restyle_of') !== false)]; }
                    foreach ($rows as $r) { $t = $tpl[$r[0]] ?? ['#' . $r[0], '', false]; $items[] = [$t[1], $t[0], $r[1], $r[2], !empty($t[2])]; }
                }
                foreach ($items as $it) {
                    [$tk, $tl] = wa_pat_topic((string)$it[1], (string)$it[0]); $st = wa_pat_style((string)$it[0]);
                    $M['sent'] += $it[2]; $M['replied'] += $it[3]; if (!empty($it[4])) { $M['new']['sent'] += $it[2]; $M['new']['replied'] += $it[3]; $M['new']['types'][] = ['text' => mb_substr((string)$it[0], 0, 420), 'name' => (string)$it[1], 'topic' => $tl, 'sent' => (int)$it[2], 'replied' => (int)$it[3]]; }
                    if (!isset($M['topics'][$tk])) $M['topics'][$tk] = ['key' => $tk, 'label' => $tl, 'sent' => 0, 'replied' => 0, 'msgs' => 0, 'samples' => []];
                    $T = &$M['topics'][$tk]; $T['sent'] += $it[2]; $T['replied'] += $it[3]; $T['msgs']++; $T['samples'][] = ['text' => mb_substr((string)$it[0], 0, 420), 'name' => (string)$it[1], 'sent' => (int)$it[2], 'replied' => (int)$it[3]]; unset($T);
                    foreach ($st as $s) { if (!isset($M['styles'][$s])) $M['styles'][$s] = ['label' => $s, 'sent' => 0, 'replied' => 0]; $M['styles'][$s]['sent'] += $it[2]; $M['styles'][$s]['replied'] += $it[3]; }
                }
                foreach ($M['topics'] as &$T) { usort($T['samples'], function ($a, $b) { return $b['sent'] <=> $a['sent']; }); $T['samples'] = array_slice($T['samples'], 0, 5); $T['standard'] = $T['samples'] ? preg_replace(['/₹ ?\d[\d,]*/u', '/\b\d+ ?ml\b/iu'], ['₹X', 'N ml'], (string)$T['samples'][0]['text']) : ''; foreach ($T['samples'] as &$S) $S['best'] = false; unset($S); $bi = -1; $br = -1; foreach ($T['samples'] as $i => $S) if ($S['sent'] >= 30 && $S['replied'] / $S['sent'] > $br) { $br = $S['replied'] / $S['sent']; $bi = $i; } if ($bi >= 0) $T['samples'][$bi]['best'] = true; } unset($T);
                usort($M['new']['types'], function ($a, $b) { return $b['sent'] <=> $a['sent']; }); $M['new']['types'] = array_slice($M['new']['types'], 0, 8);
                $M['topics'] = array_values($M['topics']); usort($M['topics'], function ($a, $b) { return $b['sent'] <=> $a['sent']; });
                $M['styles'] = array_values($M['styles']); usort($M['styles'], function ($a, $b) { return strcmp($a['label'], $b['label']); });
            } catch (Throwable $e) { $M['err'] = substr($e->getMessage(), 0, 120); }
            $out['modules'][] = $M;
        }
        try { setting_set($pdo, $key, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); } catch (Throwable $e) { }
        return $out;
    }
}
if (!function_exists('wa_pat_repliers')) {
    /** 💬 The customers behind a module's Replies number (owner 25 Nov 2026): everyone who wrote today AFTER a message of this module went to them today.
     *  $topic (the key or the label of a message type, '' = all) keeps only the customers whose message was of that type. Each row says which type they got and when.
     *  Read from the customer side (today's incoming phones, then the module's log by phone) so it stays quick; the base list is kept 60 seconds. Read-only. */
    function wa_pat_repliers(PDO $pdo, string $mod, string $topic = '', int $limit = 600): array
    {
        $sqls = [
            'cold' => "SELECT l.phone, l.tpl_id, l.at FROM wa_cold_log l WHERE l.ok = 1 AND l.tpl_id > 0 AND l.at >= ? AND l.phone IN (%s)",
            'modb' => "SELECT l.phone, l.tpl_id, l.at FROM wa_ru_log l WHERE l.ok = 1 AND l.kind <> 'cnev' AND l.tpl_id > 0 AND l.at >= ? AND l.phone IN (%s)",
            'modc' => "SELECT l.phone, l.tpl_id, l.at FROM wa_ru_log l WHERE l.ok = 1 AND l.kind = 'cnev' AND l.tpl_id > 0 AND l.at >= ? AND l.phone IN (%s)",
            'after' => "SELECT l.phone, l.tpl_id, l.sent_at FROM wa_aft_plan l WHERE l.status = 'sent' AND l.tpl_id > 0 AND l.sent_at >= ? AND l.phone IN (%s)",
            'line' => "SELECT l.phone, l.tpl_id, l.sent_at FROM wa_line_plan l WHERE l.status = 'sent' AND l.tpl_id > 0 AND l.sent_at >= ? AND l.phone IN (%s)",
            'moda' => "SELECT i.phone, c.tpl_id, i.sent_at FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE i.sent_at >= ? AND c.tpl_id > 0 AND (i.status IN ('sent','delivered','read') OR i.last_status IN ('delivered','read')) AND i.phone IN (%s)",
        ];
        if (!isset($sqls[$mod])) return [[], false, 0];
        $from = wa_strip_t0($pdo); $sql = $sqls[$mod];
        $base = function () use ($pdo, $from, $sql) {
            try { $pdo->exec("SET SESSION MAX_EXECUTION_TIME = 20000"); } catch (Throwable $e) { }
            $q = $pdo->prepare("SELECT phone, MAX(ts) FROM wa_messages WHERE dir = 'in' AND who = 'customer' AND ts >= ? GROUP BY phone LIMIT 30000"); $q->execute([$from]); $in = [];
            foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) $in[(string)$r[0]] = (string)$r[1];
            $hit = [];   // phone => [tpl_id, sent at]: the newest message of this module that the customer wrote after
            foreach (array_chunk(array_keys($in), 400) as $ch) {
                $s = $pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($ch), '?')))); $s->execute(array_merge([$from], array_map('strval', $ch)));
                foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) { $ph = (string)$r[0]; $at = (string)$r[2]; if (!isset($in[$ph]) || $at === '' || $at >= $in[$ph]) continue; if (!isset($hit[$ph]) || $at > $hit[$ph][1]) $hit[$ph] = [(int)$r[1], $at]; }
            }
            $o = []; foreach ($hit as $ph => $v) $o[] = [(string)$ph, $v[0], $v[1]]; return $o;
        };
        $hits = function_exists('hrms_memo') ? hrms_memo('wa_pat_rep1:' . $mod . ':' . $from, 60, $base) : $base();
        if (!is_array($hits) || !$hits) return [[], false, 0];
        $ids = []; foreach ($hits as $h) $ids[(int)$h[1]] = 1; $tp = [];
        if ($ids) { $q = $pdo->query("SELECT id, name, body FROM wa_templates WHERE id IN (" . implode(',', array_map('intval', array_keys($ids))) . ")"); foreach ($q->fetchAll(PDO::FETCH_NUM) as $t) $tp[(int)$t[0]] = wa_pat_topic((string)$t[1], (string)$t[2]); }
        $want = trim($topic); $pick = [];   // phone => [type label, sent at]
        foreach ($hits as $h) { $t = $tp[(int)$h[1]] ?? wa_pat_topic('#' . (int)$h[1], ''); if ($want !== '' && $want !== $t[0] && $want !== $t[1]) continue; $pick[(string)$h[0]] = [(string)$t[1], (string)$h[2]]; }
        if (!$pick) return [[], false, 0];
        $n = count($pick); $info = []; $last = [];
        if ($n > 3000) $pick = array_slice($pick, 0, 3000, true);
        foreach (array_chunk(array_map('strval', array_keys($pick)), 400) as $ch) { $in = implode(',', array_fill(0, count($ch), '?'));
            $c = $pdo->prepare("SELECT phone, name, assigned_emp, last_in_at, last_out_at, status FROM wa_contacts WHERE phone IN ($in)"); $c->execute($ch); foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) $info[(string)$r['phone']] = $r;
            $m = $pdo->prepare("SELECT m.phone, m.body, m.mtype, m.ts FROM wa_messages m JOIN (SELECT phone, MAX(id) id FROM wa_messages WHERE dir = 'in' AND phone IN ($in) GROUP BY phone) x ON x.id = m.id"); $m->execute($ch); foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) $last[(string)$r['phone']] = $r; }
        $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $rows = [];
        foreach ($pick as $ph => $v) { $ph = (string)$ph; $c = $info[$ph] ?? []; $l = $last[$ph] ?? null; $answered = !empty($c['last_out_at']) && (string)$c['last_out_at'] >= (string)($c['last_in_at'] ?? ''); $emp = (int)($c['assigned_emp'] ?? 0);
            $rows[] = ['phone' => $ph, 'name' => (string)($c['name'] ?? ''), 'text' => $l ? mb_substr(((string)$l['body'] !== '' ? (string)$l['body'] : '[' . (string)$l['mtype'] . ']'), 0, 90) : '', 'at' => $l ? (string)$l['ts'] : $v[1], 'wait' => !$answered, 'emp' => $emp, 'holder' => $emp > 0 ? (string)($nm[$emp] ?? '') : '', 'closed' => ((string)($c['status'] ?? '') === 'closed'), 'tag' => $v[0] . ' · sent ' . substr($v[1], 11, 5)]; }
        usort($rows, function ($a, $b) { return [(int)$b['wait'], $b['at']] <=> [(int)$a['wait'], $a['at']]; });
        return [array_slice($rows, 0, $limit), $n > $limit, $n];
    }
}
