<?php
/**
 * wa_ordersrc.php — 🧭 WHERE THE ORDERS CAME FROM (owner, 28 Nov 2026: "for every order of the day: on which trigger point, which campaign, which message
 * and which module did the customer come - also when the campaign went 5, 10, 20 days or a month ago").
 *
 *  wa.order_source (GET, admin; ?day=YYYY-MM-DD or ?days=7): every order of the sales day (the order ledger: website + panel) with its TRIGGER:
 *    1. the customer tapped a button of / replied to one of OUR messages (WhatsApp tells which one: wa_messages.reply_to) - however old that message is,
 *       its module, campaign and message are the trigger;
 *    2. else his message is the ready-made sentence of a link ("I came from community", "Hey TMPerfumehouse ...") - Community / Website-ad link;
 *    3. else the last message of ours in the 72 hours before the order (the ledger's own rule);
 *    4. else Direct (nothing found).
 *  Read-only. A day is kept in a file of the temp folder (today 5 minutes, a past day 6 hours). No full phone number leaves the server.
 */
if (!function_exists('wos_day')) {
    function wos_k10($p): string { return substr(preg_replace('/\D+/', '', (string)$p), -10); }
    function wos_link(string $t): string
    {
        $t = mb_strtolower(trim($t));
        if (strpos($t, 'community') !== false) return 'Community link';
        if (preg_match('/^(hey|hi|hello)\s+tm\s*perfume\s*house/u', $t) || strpos($t, "i'm placing my first order") === 0 || strpos($t, 'please show me your best-sel') === 0 || strpos($t, 'i saw this on') === 0) return 'Website / ad link';
        return '';
    }
    /** The module, message and campaign of ONE message of ours (by the customer and the time it went, ±3 minutes). [module, tpl_id, campaign] */
    function wos_module(PDO $pdo, string $k, string $ts): array
    {
        static $ps = null;
        if ($ps === null) { $ps = [];
            $Q = ['A' => "SELECT c.tpl_id, c.name FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE i.phone IN (?, ?) AND i.sent_at BETWEEN ? AND ? ORDER BY i.sent_at DESC LIMIT 1",
                'A-rescue' => "SELECT a.tpl_id, '' FROM wa_arsc a WHERE a.phone IN (?, ?) AND a.ok = 1 AND a.at BETWEEN ? AND ? LIMIT 1",
                'B / C' => "SELECT l.tpl_id, '' FROM wa_ru_log l WHERE l.phone IN (?, ?) AND l.ok = 1 AND l.at BETWEEN ? AND ? LIMIT 1",
                'Cold' => "SELECT l.tpl_id, '' FROM wa_cold_log l WHERE l.phone IN (?, ?) AND l.ok = 1 AND l.at BETWEEN ? AND ? LIMIT 1",
                'Lineup' => "SELECT l.tpl_id, CASE WHEN l.why LIKE 'utility rescue%' THEN 'rescue' WHEN l.why LIKE 'free text%' THEN 'free offer' ELSE '' END FROM wa_line_plan l WHERE l.phone IN (?, ?) AND l.status = 'sent' AND l.sent_at BETWEEN ? AND ? LIMIT 1",
                'After-Order' => "SELECT l.tpl_id, '' FROM wa_aft_plan l WHERE l.phone IN (?, ?) AND l.status = 'sent' AND l.sent_at BETWEEN ? AND ? LIMIT 1"];
            foreach ($Q as $m => $sql) { try { $ps[$m] = $pdo->prepare($sql); } catch (Throwable $e) { } } }
        $a = date('Y-m-d H:i:s', strtotime($ts) - 180); $b = date('Y-m-d H:i:s', strtotime($ts) + 180);
        foreach ($ps as $m => $st) { try { $st->execute([$k, '91' . $k, $a, $b]); $r = $st->fetch(PDO::FETCH_NUM); if ($r) return [$m, (int)$r[0], (string)$r[1]]; } catch (Throwable $e) { } }
        return ['', 0, ''];
    }
    function wos_tname(PDO $pdo, int $id): string { static $c = []; if ($id <= 0) return ''; if (!isset($c[$id])) { try { $q = $pdo->prepare("SELECT name FROM wa_templates WHERE id = ?"); $q->execute([$id]); $c[$id] = (string)$q->fetchColumn(); } catch (Throwable $e) { $c[$id] = ''; } } return $c[$id] !== '' ? $c[$id] : 'message #' . $id; }
    /** One sales day: ['day', 'from', 'to', 'orders', 'rev', 'groups' => {label => [n, rs]}, 'modules' => {...}, 'camps' => [...], 'msgs' => [...], 'list' => [...]] */
    function wos_day(PDO $pdo, string $day, bool $fresh = false): array
    {
        $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d'); $isToday = $day >= $today; $file = rtrim(sys_get_temp_dir(), '/') . '/wa_osrc_' . preg_replace('/[^0-9-]/', '', $day) . '.json';
        if (!$fresh && is_file($file) && filemtime($file) > time() - ($isToday ? 300 : 6 * 3600)) { $j = json_decode((string)@file_get_contents($file), true); if (is_array($j) && isset($j['orders'])) return $j + ['cached' => 1]; }
        [$from, $to] = function_exists('biz_window') ? biz_window($pdo, $day, $day) : [$day . ' 00:00:00', $day . ' 23:59:59'];
        $R = ['day' => $day, 'from' => $from, 'to' => $to, 'at' => date('Y-m-d H:i:s'), 'orders' => 0, 'rev' => 0, 'wrote' => 0, 'groups' => [], 'modules' => [], 'camps' => [], 'msgs' => [], 'list' => [], 'partial' => 0];
        try { $q = $pdo->prepare("SELECT okey, src, phone, order_at, total, module, tpl_id, msg_at FROM wa_order_attr WHERE order_at >= ? AND order_at <= ? ORDER BY order_at"); $q->execute([$from, $to]); $rows = $q->fetchAll(PDO::FETCH_NUM); } catch (Throwable $e) { return $R + ['error' => 'The order ledger is not there yet.']; }
        $first = []; foreach (["SELECT RIGHT(REPLACE(REPLACE(phone, '+', ''), ' ', ''), 10) p, MIN(created_at) FROM shop_orders WHERE phone <> '' GROUP BY p", "SELECT RIGHT(REPLACE(REPLACE(phone, '+', ''), ' ', ''), 10) p, MIN(order_date) FROM manual_orders WHERE phone <> '' GROUP BY p"] as $sql) { try { foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_NUM) as $r) { $k = (string)$r[0]; if (!isset($first[$k]) || (string)$r[1] < $first[$k]) $first[$k] = (string)$r[1]; } } catch (Throwable $e) { } }
        $qIn = $pdo->prepare("SELECT body, reply_to, ts FROM wa_messages WHERE phone IN (?, ?) AND dir = 'in' AND ts >= ? AND ts <= ? ORDER BY id DESC LIMIT 40");
        $qOut = $pdo->prepare("SELECT ts, who, mtype, body FROM wa_messages WHERE wamid = ? LIMIT 1");
        $qOf = $pdo->prepare("SELECT COUNT(*) FROM wa_offer WHERE phone IN (?, ?) AND ok > 0 AND at BETWEEN ? AND ?");
        $qFr = $pdo->prepare("SELECT COUNT(*) FROM wa_line_plan WHERE phone IN (?, ?) AND status = 'sent' AND why LIKE 'free text%' AND sent_at BETWEEN ? AND ?");
        $qEmp = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone IN (?, ?) AND dir = 'out' AND who IN ('emp','person','phone') AND ts BETWEEN ? AND ?");
        $t0 = microtime(true); $age = function (int $d) { return $d <= 0 ? 'today' : ($d <= 3 ? 'd1_3' : ($d <= 10 ? 'd4_10' : ($d <= 30 ? 'd11_30' : 'd30p'))); };
        $LM = ['A' => 'Module A campaign', 'A-rescue' => 'Rescue utility', 'B' => 'Module B / C', 'C' => 'Module B / C', 'B / C' => 'Module B / C', 'Cold' => 'Cold funnel', 'Lineup' => '30-Day Lineup', 'After' => 'After-Order', 'After-Order' => 'After-Order'];
        foreach ($rows as $r) {
            if (microtime(true) - $t0 > 45) { $R['partial'] = 1; break; }
            $k = wos_k10($r[2]); $oa = (string)$r[3]; $tot = (int)round((float)$r[4]); $R['orders']++; $R['rev'] += $tot;
            $o = ['t' => substr($oa, 11, 5), 'rs' => $tot, 'src' => (string)$r[1], 'cust' => (isset($first[$k]) && $first[$k] < $oa) ? 'Repeat' : 'New', 'how' => 'direct', 'group' => 'Direct, no trigger found', 'trigger' => '', 'msg' => '', 'camp' => '', 'sent' => '', 'days' => null, 'fu' => [], 'gap' => null];
            if (strlen($k) === 10) {
                $ins = []; try { $qIn->execute([$k, '91' . $k, date('Y-m-d H:i:s', strtotime($oa) - 5 * 86400), $oa]); $ins = $qIn->fetchAll(PDO::FETCH_NUM); } catch (Throwable $e) { }
                if ($ins) $R['wrote']++;
                $trig = null; $tried = 0;
                foreach ($ins as $m) { if ((string)$m[1] === '' || $tried >= 6) continue; $tried++; try { $qOut->execute([(string)$m[1]]); $om = $qOut->fetch(PDO::FETCH_NUM); } catch (Throwable $e) { $om = null; } if (!$om) continue; [$mod, $tid, $extra] = wos_module($pdo, $k, (string)$om[0]); if ($mod === '' && (string)$om[2] !== 'template') continue;
                    $trig = ['body' => (string)$m[0], 'ts' => (string)$m[2], 'msg_ts' => (string)$om[0], 'mod' => $mod, 'tid' => $tid, 'extra' => $extra, 'who' => (string)$om[1], 'obody' => (string)($om[3] ?? '')]; break; }
                if ($trig) {
                    $o['how'] = 'tap'; $o['trigger'] = mb_substr(trim(preg_replace('/\s+/u', ' ', $trig['body'])), 0, 40); $o['sent'] = $trig['msg_ts']; $o['days'] = (int)floor((strtotime($oa) - strtotime($trig['msg_ts'])) / 86400); $o['gap'] = (int)round((strtotime($oa) - strtotime($trig['ts'])) / 60);
                    $o['msg'] = wos_tname($pdo, (int)$trig['tid']); $lab = $LM[$trig['mod']] ?? ($trig['mod'] !== '' ? $trig['mod'] : (in_array($trig['who'], ['emp', 'person', 'phone'], true) ? 'Salesperson follow-up' : (in_array($trig['who'], ['bot', 'auto', 'flow'], true) ? 'Chat bot message' : 'Other message of ours')));   /* 28 Nov: all 23 "other" orders of the day were answers to a message a SALESPERSON wrote (a follow-up, an offer text) */ if ($trig['mod'] === 'Lineup' && $trig['extra'] === 'rescue') $lab = 'Rescue utility';
                    if ($trig['mod'] === '') { $o['msg'] = '[' . $trig['who'] . '] ' . mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$trig['obody'])), 0, 60); }   /* a message of ours that no module's log knows (a chat template, a keyword card, a flow): shown by who sent it and its first words */
                    if ($trig['mod'] === 'A') $o['camp'] = $trig['extra']; elseif ($trig['extra'] !== '') $o['msg'] .= ' (' . $trig['extra'] . ')'; $o['group'] = $lab;
                } else {
                    $link = ''; $lb = ''; $lt = ''; foreach ($ins as $m) { $x = wos_link((string)$m[0]); if ($x !== '') { $link = $x; $lb = (string)$m[0]; $lt = (string)$m[2]; } }   // the oldest such sentence of the 5 days = how he came in
                    if ($link !== '') { $o['how'] = 'link'; $o['group'] = $link; $o['trigger'] = mb_substr(trim(preg_replace('/\s+/u', ' ', $lb)), 0, 40); $o['gap'] = (int)round((strtotime($oa) - strtotime($lt)) / 60); }
                    elseif (!in_array((string)$r[5], ['none', 'no phone', ''], true)) { $o['how'] = 'last'; $o['group'] = $LM[(string)$r[5]] ?? (string)$r[5]; $o['msg'] = wos_tname($pdo, (int)$r[6]); $o['sent'] = (string)($r[7] ?? ''); if ($o['sent'] !== '') { $o['days'] = (int)floor((strtotime($oa) - strtotime($o['sent'])) / 86400); $o['gap'] = (int)round((strtotime($oa) - strtotime($o['sent'])) / 60); } $o['trigger'] = $ins ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string)end($ins)[0])), 0, 40) : '(did not write)';
                        if ((string)$r[5] === 'A' && $o['sent'] !== '') { [$mm, $tt, $cc] = wos_module($pdo, $k, $o['sent']); if ($mm === 'A') $o['camp'] = $cc; } }
                    elseif ($ins) { $o['trigger'] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)end($ins)[0])), 0, 40); $o['group'] = 'Wrote to us himself'; $o['how'] = 'self'; }
                }
                $f0 = $o['sent'] !== '' ? $o['sent'] : date('Y-m-d H:i:s', strtotime($oa) - 3 * 86400); if (strtotime($f0) < strtotime($oa) - 5 * 86400) $f0 = date('Y-m-d H:i:s', strtotime($oa) - 5 * 86400);
                try { $qOf->execute([$k, '91' . $k, $f0, $oa]); if ((int)$qOf->fetchColumn() > 0) $o['fu'][] = 'Offer'; } catch (Throwable $e) { }
                try { $qFr->execute([$k, '91' . $k, $f0, $oa]); if ((int)$qFr->fetchColumn() > 0) $o['fu'][] = '24 h offer'; } catch (Throwable $e) { }
                try { $qEmp->execute([$k, '91' . $k, date('Y-m-d H:i:s', strtotime($oa) - 86400), $oa]); if ((int)$qEmp->fetchColumn() > 0) $o['fu'][] = 'Sales'; } catch (Throwable $e) { }
            } else { $o['group'] = 'No phone on the order'; }
            $g = $o['group']; if (!isset($R['groups'][$g])) $R['groups'][$g] = [0, 0]; $R['groups'][$g][0]++; $R['groups'][$g][1] += $tot;
            if ($o['days'] !== null) { if (!isset($R['modules'][$g])) $R['modules'][$g] = ['n' => 0, 'rs' => 0, 'today' => 0, 'd1_3' => 0, 'd4_10' => 0, 'd11_30' => 0, 'd30p' => 0, 'fu' => 0, 'tap' => 0]; $M = &$R['modules'][$g]; $M['n']++; $M['rs'] += $tot; $M[$age((int)$o['days'])]++; if ($o['fu']) $M['fu']++; if ($o['how'] === 'tap') $M['tap']++; unset($M); }
            if ($o['camp'] !== '') { $ck = $o['camp'] . '|' . $o['msg']; if (!isset($R['camps'][$ck])) $R['camps'][$ck] = ['camp' => $o['camp'], 'msg' => $o['msg'], 'sent' => substr($o['sent'], 0, 10), 'days' => $o['days'], 'n' => 0, 'rs' => 0, 'taps' => []]; $R['camps'][$ck]['n']++; $R['camps'][$ck]['rs'] += $tot; if ($o['how'] === 'tap') $R['camps'][$ck]['taps'][$o['trigger']] = ($R['camps'][$ck]['taps'][$o['trigger']] ?? 0) + 1; }
            if ($o['msg'] !== '') { $mk = $g . '|' . $o['msg']; if (!isset($R['msgs'][$mk])) $R['msgs'][$mk] = ['group' => $g, 'msg' => $o['msg'], 'n' => 0, 'rs' => 0]; $R['msgs'][$mk]['n']++; $R['msgs'][$mk]['rs'] += $tot; }
            $R['list'][] = $o;
        }
        uasort($R['groups'], function ($a, $b) { return $b[0] <=> $a[0]; }); uasort($R['modules'], function ($a, $b) { return $b['n'] <=> $a['n']; });
        $R['camps'] = array_values($R['camps']); usort($R['camps'], function ($a, $b) { return [$b['n'], $b['rs']] <=> [$a['n'], $a['rs']]; }); foreach ($R['camps'] as &$c) { arsort($c['taps']); $c['top'] = $c['taps'] ? (string)array_key_first($c['taps']) : ''; unset($c['taps']); } unset($c);
        $R['msgs'] = array_values($R['msgs']); usort($R['msgs'], function ($a, $b) { return [$b['n'], $b['rs']] <=> [$a['n'], $a['rs']]; }); $R['msgs'] = array_slice($R['msgs'], 0, 40);
        if (!$R['partial']) @file_put_contents($file, json_encode($R, JSON_UNESCAPED_UNICODE));
        return $R;
    }
}
if ((string)($action ?? ($_GET['action'] ?? '')) === 'wa.order_source') {
    $auth = require_auth(); if (!function_exists('wa_is_admin') || !wa_is_admin($pdo, $auth)) json_response(['error' => 'Only the owner / admin can see this.'], 403);
    @set_time_limit(180); $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d'); $fresh = !empty($_GET['fresh']);
    $days = max(0, min(31, (int)($_GET['days'] ?? 0)));
    if ($days <= 1) { $day = (string)($_GET['day'] ?? $today); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = $today; json_response(['ok' => true, 'today' => $today] + wos_day($pdo, $day, $fresh)); }
    $w7 = function_exists('biz_window') ? biz_window($pdo, date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' day')), $today) : ['', ''];
    $S = ['ok' => true, 'today' => $today, 'from' => $w7[0], 'to' => $w7[1], 'day' => date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' day')) . ' … ' . $today, 'orders' => 0, 'rev' => 0, 'wrote' => 0, 'groups' => [], 'modules' => [], 'camps' => [], 'msgs' => [], 'list' => [], 'partial' => 0, 'by_day' => []]; $cm = []; $mm = [];
    for ($i = $days - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime($today . ' -' . $i . ' day')); $D = wos_day($pdo, $d, $fresh && $i === 0); $S['orders'] += (int)$D['orders']; $S['rev'] += (int)$D['rev']; $S['wrote'] += (int)$D['wrote']; if (!empty($D['partial'])) $S['partial'] = 1; $S['by_day'][] = ['day' => $d, 'orders' => (int)$D['orders'], 'rev' => (int)$D['rev']];
        foreach ((array)$D['groups'] as $g => $v) { if (!isset($S['groups'][$g])) $S['groups'][$g] = [0, 0]; $S['groups'][$g][0] += $v[0]; $S['groups'][$g][1] += $v[1]; }
        foreach ((array)$D['modules'] as $g => $v) { if (!isset($S['modules'][$g])) $S['modules'][$g] = array_fill_keys(array_keys($v), 0); foreach ($v as $kk => $vv) $S['modules'][$g][$kk] = ($S['modules'][$g][$kk] ?? 0) + $vv; }
        foreach ((array)$D['camps'] as $c) { $ck = $c['camp'] . '|' . $c['msg']; if (!isset($cm[$ck])) $cm[$ck] = $c; else { $cm[$ck]['n'] += $c['n']; $cm[$ck]['rs'] += $c['rs']; } }
        foreach ((array)$D['msgs'] as $c) { $ck = $c['group'] . '|' . $c['msg']; if (!isset($mm[$ck])) $mm[$ck] = $c; else { $mm[$ck]['n'] += $c['n']; $mm[$ck]['rs'] += $c['rs']; } } }
    uasort($S['groups'], function ($a, $b) { return $b[0] <=> $a[0]; }); uasort($S['modules'], function ($a, $b) { return $b['n'] <=> $a['n']; });
    $S['camps'] = array_values($cm); usort($S['camps'], function ($a, $b) { return [$b['n'], $b['rs']] <=> [$a['n'], $a['rs']]; }); $S['camps'] = array_slice($S['camps'], 0, 60);
    $S['msgs'] = array_values($mm); usort($S['msgs'], function ($a, $b) { return [$b['n'], $b['rs']] <=> [$a['n'], $a['rs']]; }); $S['msgs'] = array_slice($S['msgs'], 0, 40);
    json_response($S);
}
