<?php
/**
 * Store → WhatsApp → Campaigns: one approved Store template to a Store segment (Contacts), sent by the SERVER from the Store's own number - the page can be closed.
 * The Store only: never the sales numbers, never the sales campaign tables (wa_campaigns). Logs into st_wa_msg so replies and ticks show in Chats.
 *
 * How sending runs: every HRMS request (the bot heartbeat makes several a second) calls st_camp_kick() (store_lib.php). When a campaign is running and
 * the last round is older than 15 s, one round runs AFTER the answer has left (shutdown): up to 25 messages, inside the send hours, under the limits.
 * Rules at sending time: STOP customers never; nobody who got another Store campaign in the last N days (setting); a daily cap for all Store campaigns.
 * Report: sent, delivered, read, replied (wrote within 3 days), failed with Meta's code; "send the failed again" makes a new campaign of only them (e.g. with a Utility template).
 * Tables: st_wa_camp, st_wa_camp_item. Settings: st_cs_settings 'swcamp'.
 * Actions (st_wa.camp_*): camp_list, camp_get, camp_opts, camp_preview, camp_save, camp_ctl, camp_retry, camp_test, camp_cfg_save.
 */

function swc_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_wa_camp (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', tpl_id INT NOT NULL, tpl_name VARCHAR(120) NOT NULL DEFAULT '', category VARCHAR(12) NOT NULL DEFAULT '',
            vars TEXT NULL, audience TEXT NULL, status VARCHAR(10) NOT NULL DEFAULT 'running', total INT NOT NULL DEFAULT 0, day_limit INT NOT NULL DEFAULT 0, start_at DATETIME NULL, created_by INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
            done_at DATETIME NULL, retry_of INT NOT NULL DEFAULT 0, KEY k_st (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_wa_camp_item (id BIGINT AUTO_INCREMENT PRIMARY KEY, camp_id INT NOT NULL, phone VARCHAR(12) NOT NULL, data TEXT NULL, status VARCHAR(8) NOT NULL DEFAULT 'queued',
            wamid VARCHAR(120) NOT NULL DEFAULT '', err VARCHAR(220) NOT NULL DEFAULT '', sent_at DATETIME NULL, KEY k_cs (camp_id, status), KEY k_ph (phone, sent_at), KEY k_w (wamid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $have = []; foreach ($pdo->query("SHOW COLUMNS FROM st_wa_camp")->fetchAll() as $c) $have[(string)$c['Field']] = 1;
        if (empty($have['no_repeat'])) $pdo->exec("ALTER TABLE st_wa_camp ADD COLUMN no_repeat INT NOT NULL DEFAULT 30, ADD COLUMN tag_after VARCHAR(30) NOT NULL DEFAULT '', ADD COLUMN family VARCHAR(120) NOT NULL DEFAULT ''");
        foreach ($pdo->query("SELECT id, tpl_name FROM st_wa_camp WHERE family = ''")->fetchAll() as $r) $pdo->prepare("UPDATE st_wa_camp SET family = ? WHERE id = ?")->execute([swc_family((string)$r['tpl_name']), (int)$r['id']]);      // older campaigns count as 'this message' too
        return $ok = true;
    } catch (Throwable $e) { error_log('swc_tables: ' . $e->getMessage()); return $ok = false; }
}
/** "The same message": one template in all its versions and languages (followup_1_gu, followup_1_gu_v3 and followup_1_en are one message). */
function swc_family(string $name): string { $b = preg_replace('/(_\d{6,})?(_v\d+)?(_copy\d*)?$/', '', $name); $b = preg_replace('/(_\d{6,})?(_v\d+)?$/', '', (string)$b); return (string)preg_replace('/_(gu|hi|en)$/', '', (string)$b); }
/** Numbers that got this message (any version / language) in the last $days days, from Store campaigns: [phone => date]. */
function swc_got(PDO $pdo, string $family, int $days, int $notCamp = 0): array
{
    if ($days <= 0 || $family === '') return []; $o = [];
    $q = $pdo->prepare("SELECT i.phone, MAX(i.sent_at) d FROM st_wa_camp_item i JOIN st_wa_camp c ON c.id = i.camp_id WHERE c.family = ? AND c.id <> ? AND i.status = 'sent' AND i.sent_at >= ? GROUP BY i.phone");
    $q->execute([$family, $notCamp, date('Y-m-d H:i:s', time() - $days * 86400)]); foreach ($q->fetchAll() as $r) $o[(string)$r['phone']] = substr((string)$r['d'], 0, 10);
    return $o;
}
function swc_cfg_defaults(): array { return ['from' => 10, 'to' => 21, 'day_cap' => 5000, 'any_gap_h' => 20, 'per_round' => 25, 'price_mkt' => 0.86, 'price_utl' => 0.13]; }
function swc_cfg(PDO $pdo): array
{
    $d = swc_cfg_defaults();
    try { $v = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = 'swcamp'")->fetchColumn(); $j = $v ? json_decode((string)$v, true) : null; if (is_array($j)) foreach ($d as $k => $x) if (isset($j[$k]) && is_numeric($j[$k])) $d[$k] = is_float($x) ? (float)$j[$k] : (int)$j[$k]; } catch (Throwable $e) { }
    $d['from'] = max(0, min(23, $d['from'])); $d['to'] = max($d['from'] + 1, min(24, $d['to'])); $d['per_round'] = max(1, min(60, $d['per_round'])); return $d;
}
/** What a {{n}} can be filled with. */
function swc_kinds(): array { return ['name' => 'Customer first name', 'perfume' => 'Perfume they bought most', 'days' => 'Days since last visit', 'visits' => 'Number of visits', 'spent' => 'Total spent (₹)', 'fixed' => 'Same text for everybody']; }
function swc_first(string $n): string { $n = trim($n); if ($n === '') return 'ji'; if (function_exists('wa_first_name')) return wa_first_name($n); return explode(' ', $n)[0]; }
function swc_vals(array $data, array $map): array
{
    $o = [];
    foreach ($map as $m) { $k = (string)($m['kind'] ?? 'name'); $v = '';
        if ($k === 'name') $v = swc_first((string)($data['name'] ?? '')); elseif ($k === 'perfume') $v = (string)($data['perfume'] ?? '') ?: 'perfume'; elseif ($k === 'days') $v = (string)(int)($data['days'] ?? 0);
        elseif ($k === 'visits') $v = (string)(int)($data['visits'] ?? 1); elseif ($k === 'spent') $v = number_format((float)($data['spent'] ?? 0)); else $v = trim((string)($m['text'] ?? ''));
        $o[] = mb_substr($v !== '' ? $v : 'ji', 0, 200); }
    return $o;
}
/** The approved Store templates (on the Store account): [id => row]. */
function swc_tpls(PDO $pdo, string $waba): array
{
    $o = [];
    foreach ($pdo->query("SELECT t.id FROM wa_templates t JOIN wa_tpl_waba w ON w.tpl_id = t.id AND w.waba_id = " . $pdo->quote($waba) . " AND UPPER(w.status) = 'APPROVED' WHERE t.name LIKE 'tmph\\_store\\_%' ORDER BY t.id DESC")->fetchAll() as $r) {
        $t = wa_tpl_row($pdo, (int)$r['id']); if (!$t) continue; $w = $pdo->prepare("SELECT category FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $w->execute([(int)$r['id'], $waba]); $mc = strtoupper((string)$w->fetchColumn());
        $o[(int)$r['id']] = $t + ['meta_category' => $mc ?: strtoupper((string)$t['category'])]; }
    return $o;
}
/** The people of an audience: ['seg' => id, 'tag' => '', 'q' => '', 'phones' => [...]] → [phone => data]; STOP never. */
function swc_people(PDO $pdo, array $aud): array
{
    require_once __DIR__ . '/store_wa_aud.php'; $P = swa_build($pdo, swa_cfg($pdo)); $o = [];
    $phones = array_values(array_filter(array_map(fn($p) => substr(preg_replace('/\D/', '', (string)$p), -10), (array)($aud['phones'] ?? []))));
    $seg = (string)($aud['seg'] ?? 'all'); $tag = (string)($aud['tag'] ?? ''); $ex = (string)($aud['ex_tag'] ?? ''); $q = mb_strtolower(trim((string)($aud['q'] ?? '')));
    foreach ($P as $ph => $x) {
        if ($x['stop'] || ($ex !== '' && in_array($ex, $x['tags'], true))) continue;
        if ($phones) { if (!in_array($ph, $phones, true)) continue; }
        elseif (($seg !== 'all' && !in_array($seg, $x['segs'], true)) || ($tag !== '' && !in_array($tag, $x['tags'], true)) || ($q !== '' && strpos(mb_strtolower($x['name'] . ' ' . $x['phone'] . ' ' . implode(' ', $x['top']) . ' ' . implode(' ', $x['tags'])), $q) === false)) continue;
        $o[$ph] = ['name' => $x['name'], 'perfume' => (string)($x['top'][0] ?? ''), 'days' => $x['days'], 'visits' => $x['visits'], 'spent' => $x['spent']];
    }
    return $o;
}
function swc_aud_label(array $aud): string
{
    if (!empty($aud['phones'])) return count((array)$aud['phones']) . ' chosen customers'; $o = [];
    if (($aud['seg'] ?? 'all') !== 'all') $o[] = 'segment ' . $aud['seg']; if (($aud['tag'] ?? '') !== '') $o[] = 'tag ' . $aud['tag']; if (($aud['ex_tag'] ?? '') !== '') $o[] = 'without tag ' . $aud['ex_tag']; if (($aud['q'] ?? '') !== '') $o[] = 'search "' . $aud['q'] . '"';
    return $o ? implode(' · ', $o) : 'all contacts';
}

/** Sends one item. Returns [ok, wamid or error]. */
function swc_send(PDO $pdo, array $t, array $num, string $phone, array $vals): array
{
    $b = wa_tpl_send_components($pdo, $t, $vals, (string)$num['phone_id'], []); if (empty($b['ok'])) return [false, (string)($b['error'] ?? 'could not build the message')];
    $pl = ['messaging_product' => 'whatsapp', 'to' => '91' . $phone, 'type' => 'template', 'template' => ['name' => $t['name'], 'language' => ['code' => $t['language']]]]; if (!empty($b['components'])) $pl['template']['components'] = $b['components'];
    [$code, $d] = wa_api('POST', '/' . $num['phone_id'] . '/messages', $pl); $wamid = (string)($d['messages'][0]['id'] ?? '');
    if ($code < 200 || $code >= 300 || $wamid === '') return [false, function_exists('wa_err') ? wa_err(is_array($d) ? $d : null) : 'WhatsApp did not accept it'];
    try { $shown = function_exists('wa_tpl_render') ? wa_tpl_render((string)$t['body'], $vals) : (string)$t['name']; st_wa_log_out($pdo, true, $wamid, $phone, (int)$num['id'], $shown); } catch (Throwable $e) { }
    return [true, $wamid];
}

/** One sending round (run by st_camp_kick after a request, or by hand). Returns how many went. */
function swc_tick(PDO $pdo, bool $force = false): int
{
    if (!swc_tables($pdo) || !st_wa_load()) return 0; wa_tpl_tables($pdo); if (!function_exists('sw_tables')) require_once __DIR__ . '/store_wa.php'; if (!sw_tables($pdo)) return 0;
    try { $got = (int)$pdo->query("SELECT GET_LOCK('st_wa_camp', 0)")->fetchColumn(); } catch (Throwable $e) { $got = 1; } if ($got !== 1) return 0;
    $sent = 0;
    try {
        $c = swc_cfg($pdo); $h = (int)date('G'); $now = date('Y-m-d H:i:s');
        $camps = $pdo->query("SELECT * FROM st_wa_camp WHERE status = 'running' ORDER BY id")->fetchAll();
        if (!$camps) { st_camp_next(300); return 0; }
        if (!$force && ($h < $c['from'] || $h >= $c['to'])) { st_camp_next(120); return 0; }      // outside the send hours: nothing, look again in 2 minutes
        $num = sw_number($pdo); if (!$num) { st_camp_next(300); return 0; }
        $waba = wa_waba_of($num); $today = date('Y-m-d');
        $q = $pdo->prepare("SELECT COUNT(*) FROM st_wa_camp_item WHERE status = 'sent' AND sent_at >= ?"); $q->execute([$today . ' 00:00:00']); $dayAll = (int)$q->fetchColumn();
        $left = min($c['per_round'], max(0, $c['day_cap'] - $dayAll)); $t0 = microtime(true);
        $gotMemo = []; $gotOf = function (int $id) use (&$gotMemo, $pdo, $camps) { if (!isset($gotMemo[$id])) { $cp = null; foreach ($camps as $x) if ((int)$x['id'] === $id) $cp = $x; $gotMemo[$id] = $cp ? swc_got($pdo, (string)($cp['family'] ?: swc_family((string)$cp['tpl_name'])), (int)$cp['no_repeat'], $id) : []; } return $gotMemo[$id]; };
        foreach ($camps as $cp) {
            if ($left <= 0 || microtime(true) - $t0 > 35) break; if ($cp['start_at'] && $cp['start_at'] > $now) continue;
            $tpl = wa_tpl_row($pdo, (int)$cp['tpl_id']); $ok = $pdo->prepare("SELECT UPPER(status) FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $ok->execute([(int)$cp['tpl_id'], $waba]);
            if (!$tpl || $ok->fetchColumn() !== 'APPROVED') { $pdo->prepare("UPDATE st_wa_camp SET status = 'paused' WHERE id = ?")->execute([(int)$cp['id']]); continue; }      // the template stopped being approved: pause, the owner decides
            $lim = (int)$cp['day_limit']; if ($lim > 0) { $q = $pdo->prepare("SELECT COUNT(*) FROM st_wa_camp_item WHERE camp_id = ? AND status = 'sent' AND sent_at >= ?"); $q->execute([(int)$cp['id'], $today . ' 00:00:00']); if ((int)$q->fetchColumn() >= $lim) continue; }
            $map = json_decode((string)$cp['vars'], true) ?: [];
            $items = $pdo->prepare("SELECT * FROM st_wa_camp_item WHERE camp_id = ? AND status = 'queued' ORDER BY id LIMIT " . (int)max($left * 4, 100)); $items->execute([(int)$cp['id']]); $rows = $items->fetchAll();
            if (!$rows) { $pdo->prepare("UPDATE st_wa_camp SET status = 'done', done_at = ? WHERE id = ? AND status = 'running'")->execute([$now, (int)$cp['id']]); continue; }
            foreach ($rows as $it) {
                if ($left <= 0 || microtime(true) - $t0 > 35) break; $ph = (string)$it['phone'];
                $st = $pdo->prepare("SELECT (SELECT COUNT(*) FROM st_rm_stop WHERE RIGHT(phone, 10) = ?) + (SELECT COUNT(*) FROM st_wa_chat WHERE phone = ? AND optout = 1)"); $stop = 0; try { $st->execute([$ph, $ph]); $stop = (int)$st->fetchColumn(); } catch (Throwable $e) { }
                if ($stop > 0) { $pdo->prepare("UPDATE st_wa_camp_item SET status = 'skipped', err = 'STOP' WHERE id = ?")->execute([(int)$it['id']]); continue; }
                if (!(int)$cp['retry_of']) {      // a resend of failed ones skips these rules
                    if ((int)$cp['no_repeat'] > 0) { $got = $gotOf((int)$cp['id']); if (isset($got[$ph])) { $pdo->prepare("UPDATE st_wa_camp_item SET status = 'skipped', err = ? WHERE id = ?")->execute(['already got this message on ' . $got[$ph], (int)$it['id']]); continue; } }
                    if ($c['any_gap_h'] > 0) { $g = $pdo->prepare("SELECT COUNT(*) FROM st_wa_camp_item WHERE phone = ? AND status = 'sent' AND camp_id <> ? AND sent_at >= ?"); $g->execute([$ph, (int)$cp['id'], date('Y-m-d H:i:s', time() - $c['any_gap_h'] * 3600)]);
                        if ((int)$g->fetchColumn() > 0) continue; }      // got another campaign in the last hours: it waits (stays queued) and goes in a later round
                }
                $data = json_decode((string)$it['data'], true) ?: []; [$ok2, $w] = swc_send($pdo, $tpl, $num, $ph, swc_vals($data, $map));
                if ($ok2) { $pdo->prepare("UPDATE st_wa_camp_item SET status = 'sent', wamid = ?, sent_at = ? WHERE id = ?")->execute([$w, date('Y-m-d H:i:s'), (int)$it['id']]); $sent++; $left--; if (trim((string)$cp['tag_after']) !== '') { try { $pdo->prepare("INSERT IGNORE INTO st_wa_tag (phone, tag) VALUES (?, ?)")->execute([$ph, (string)$cp['tag_after']]); } catch (Throwable $e) { } } }
                else $pdo->prepare("UPDATE st_wa_camp_item SET status = 'failed', err = ?, sent_at = ? WHERE id = ?")->execute([mb_substr($w, 0, 220), date('Y-m-d H:i:s'), (int)$it['id']]);
            }
        }
        st_camp_next(15);
    } catch (Throwable $e) { error_log('swc_tick: ' . $e->getMessage()); st_camp_next(60); }
    finally { try { $pdo->query("SELECT RELEASE_LOCK('st_wa_camp')"); } catch (Throwable $e) { } }
    return $sent;
}

/** Numbers of one campaign: queued, sent, delivered, read, replied, failed (at sending or by Meta), skipped. */
function swc_stats(PDO $pdo, int $id): array
{
    $o = ['queued' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'replied' => 0, 'failed' => 0, 'skipped' => 0];
    foreach ($pdo->query("SELECT status, COUNT(*) n FROM st_wa_camp_item WHERE camp_id = " . $id . " GROUP BY status")->fetchAll() as $r) { $k = (string)$r['status']; if (isset($o[$k])) $o[$k] = (int)$r['n']; }
    try { $r = $pdo->query("SELECT COALESCE(SUM(m.status IN ('delivered','read')), 0) d, COALESCE(SUM(m.status = 'read'), 0) rd, COALESCE(SUM(m.status = 'failed'), 0) f,
            COALESCE(SUM(EXISTS (SELECT 1 FROM st_wa_msg x WHERE x.phone = i.phone AND x.dir = 'in' AND x.ts >= i.sent_at AND x.ts < DATE_ADD(i.sent_at, INTERVAL 3 DAY))), 0) rp
            FROM st_wa_camp_item i LEFT JOIN st_wa_msg m ON m.wamid = i.wamid AND i.wamid <> '' WHERE i.camp_id = " . $id . " AND i.status = 'sent'")->fetch();
        $o['delivered'] = (int)$r['d']; $o['read'] = (int)$r['rd']; $o['replied'] = (int)$r['rp']; $o['failed'] += (int)$r['f']; $o['sent'] -= 0; } catch (Throwable $e) { }
    return $o;
}

function swc_handle(PDO $pdo, string $action, int $me, bool $post): void
{
    if (!swc_tables($pdo)) json_response(['error' => 'The campaign tables could not be made.'], 500);
    $num = sw_number($pdo); if (!$num) json_response(['error' => 'Choose the Store number in Setup first.'], 409); $waba = wa_waba_of($num); $c = swc_cfg($pdo);
    if ($action === 'st_wa.camp_list') {
        $o = []; foreach ($pdo->query("SELECT * FROM st_wa_camp ORDER BY id DESC LIMIT 100")->fetchAll() as $r) { $a = json_decode((string)$r['audience'], true) ?: [];
            $o[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'tpl' => (string)$r['tpl_name'], 'category' => (string)$r['category'], 'status' => (string)$r['status'], 'total' => (int)$r['total'], 'audience' => swc_aud_label($a), 'start_at' => (string)$r['start_at'], 'created_at' => (string)$r['created_at'], 'retry_of' => (int)$r['retry_of'], 'no_repeat' => (int)($r['no_repeat'] ?? 0), 'tag_after' => (string)($r['tag_after'] ?? ''), 'stats' => swc_stats($pdo, (int)$r['id'])]; }
        $q = $pdo->prepare("SELECT COUNT(*) FROM st_wa_camp_item WHERE status = 'sent' AND sent_at >= ?"); $q->execute([date('Y-m-d') . ' 00:00:00']);
        json_response(['camps' => $o, 'cfg' => $c, 'sent_today' => (int)$q->fetchColumn(), 'now_h' => (int)date('G')]);
    }
    if ($action === 'st_wa.camp_opts') {
        require_once __DIR__ . '/store_wa_aud.php'; $ac = swa_cfg($pdo); $P = swa_build($pdo, $ac); $cnt = ['all' => 0]; foreach ($P as $x) { if ($x['stop']) continue; $cnt['all']++; foreach ($x['segs'] as $s) $cnt[$s] = ($cnt[$s] ?? 0) + 1; }
        $tags = []; foreach ($P as $x) if (!$x['stop']) foreach ($x['tags'] as $t) $tags[$t] = ($tags[$t] ?? 0) + 1;
        $tp = []; foreach (swc_tpls($pdo, $waba) as $t) $tp[] = ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'language' => (string)$t['language'], 'category' => (string)$t['meta_category'], 'body' => (string)$t['body'], 'header' => (string)($t['header_format'] ?? ''), 'vars' => count(wa_tpl_vars((string)$t['body'])), 'buttons' => array_values(array_map(fn($b) => (string)($b['text'] ?? ''), (array)($t['buttons'] ?? [])))];
        json_response(['segs' => array_values(array_filter(array_map(fn($s) => ['id' => $s[0], 'name' => $s[1], 'rule' => $s[3], 'n' => (int)($cnt[$s[0]] ?? 0)], swa_segs($ac)), fn($s) => $s['id'] !== 'stop')), 'tags' => $tags, 'templates' => $tp, 'kinds' => swc_kinds(), 'cfg' => $c]);
    }
    if ($action === 'st_wa.camp_get') {
        $id = (int)($_GET['id'] ?? 0); $r = $pdo->prepare("SELECT * FROM st_wa_camp WHERE id = ?"); $r->execute([$id]); $cp = $r->fetch(); if (!$cp) json_response(['error' => 'Campaign not found.'], 404);
        $f = (string)($_GET['f'] ?? 'all'); $w = $f === 'all' ? '' : ($f === 'failed' ? " AND (i.status = 'failed' OR m.status = 'failed')" : ($f === 'read' ? " AND m.status = 'read'" : " AND i.status = " . $pdo->quote($f)));
        $it = $pdo->query("SELECT i.phone, i.data, i.status, i.err, i.sent_at, COALESCE(m.status, '') ms, COALESCE(m.err, '') merr FROM st_wa_camp_item i LEFT JOIN st_wa_msg m ON m.wamid = i.wamid AND i.wamid <> '' WHERE i.camp_id = " . $id . $w . " ORDER BY i.id LIMIT 500")->fetchAll();
        $rows = array_map(function ($x) { $d = json_decode((string)$x['data'], true) ?: []; $st = $x['status'] === 'sent' && $x['ms'] !== '' ? $x['ms'] : $x['status']; return ['phone' => (string)$x['phone'], 'name' => (string)($d['name'] ?? ''), 'status' => $st, 'err' => (string)($x['err'] ?: $x['merr']), 'at' => (string)$x['sent_at']]; }, $it);
        json_response(['camp' => ['id' => (int)$cp['id'], 'name' => (string)$cp['name'], 'tpl' => (string)$cp['tpl_name'], 'status' => (string)$cp['status'], 'total' => (int)$cp['total'], 'audience' => swc_aud_label(json_decode((string)$cp['audience'], true) ?: [])], 'stats' => swc_stats($pdo, $id), 'rows' => $rows]);
    }
    if (!$post) json_response(['error' => 'Method not allowed.'], 405); $in = json_input();
    $tplOf = function (int $id) use ($pdo, $waba) { $all = swc_tpls($pdo, $waba); if (!isset($all[$id])) json_response(['error' => 'Choose an APPROVED Store template.'], 422); return $all[$id]; };
    $mapIn = function (array $t) use ($in) { $n = count(wa_tpl_vars((string)$t['body'])); $m = []; for ($i = 0; $i < $n; $i++) { $x = (array)(($in['vars'] ?? [])[$i] ?? []); $k = (string)($x['kind'] ?? ($i === 0 ? 'name' : 'perfume')); if (!isset(swc_kinds()[$k])) $k = 'name'; $m[] = ['kind' => $k, 'text' => mb_substr(trim((string)($x['text'] ?? '')), 0, 200)]; if ($k === 'fixed' && $m[$i]['text'] === '') json_response(['error' => 'Write the text for {{' . ($i + 1) . '}}.'], 422); } return $m; };
    $audIn = function () use ($in) { $a = (array)($in['audience'] ?? []); return ['seg' => (string)($a['seg'] ?? 'all'), 'tag' => mb_substr((string)($a['tag'] ?? ''), 0, 30), 'ex_tag' => mb_substr((string)($a['ex_tag'] ?? ''), 0, 30), 'q' => mb_substr((string)($a['q'] ?? ''), 0, 60), 'phones' => array_slice(array_values(array_filter(array_map(fn($p) => substr(preg_replace('/\D/', '', (string)$p), -10), (array)($a['phones'] ?? [])), fn($p) => strlen($p) === 10)), 0, 100000)]; };
    if ($action === 'st_wa.camp_preview') {
        $t = $tplOf((int)($in['tpl_id'] ?? 0)); $map = $mapIn($t); $P = swc_people($pdo, $audIn());
        $nr = max(0, (int)($in['no_repeat'] ?? 30)); $got = swc_got($pdo, swc_family((string)$t['name']), $nr); $already = count(array_intersect_key($P, $got)); if ($nr > 0 && empty($in['retry_of'])) $P = array_diff_key($P, $got); $first = $P ? reset($P) : ['name' => 'Rahul', 'perfume' => 'Oud Royal 50 ml', 'days' => 30, 'visits' => 2, 'spent' => 2500];
        $vals = swc_vals($first, $map); $price = strtoupper((string)$t['meta_category']) === 'UTILITY' ? $c['price_utl'] : $c['price_mkt'];
        json_response(['ok' => true, 'count' => count($P), 'cost' => round(count($P) * $price), 'price' => $price, 'category' => (string)$t['meta_category'], 'sample' => function_exists('wa_tpl_render') ? wa_tpl_render((string)$t['body'], $vals) : implode(' | ', $vals), 'sample_name' => (string)($first['name'] ?? ''), 'already' => $already, 'no_repeat' => $nr, 'days' => (int)ceil(count($P) / max(1, (int)($in['day_limit'] ?? 0) ?: $c['day_cap']))]);
    }
    if ($action === 'st_wa.camp_test') {
        $t = $tplOf((int)($in['tpl_id'] ?? 0)); $map = $mapIn($t); $p = substr(preg_replace('/\D/', '', (string)($in['phone'] ?? '')), -10); if (strlen($p) !== 10) json_response(['error' => 'Write your 10-digit mobile number.'], 422);
        [$ok, $w] = swc_send($pdo, $t, $num, $p, swc_vals(['name' => (string)($in['test_name'] ?? 'Test'), 'perfume' => 'Oud Royal 50 ml', 'days' => 30, 'visits' => 2, 'spent' => 2500], $map)); if (!$ok) json_response(['error' => $w], 422); json_response(['ok' => true]);
    }
    if ($action === 'st_wa.camp_save') {
        $t = $tplOf((int)($in['tpl_id'] ?? 0)); $map = $mapIn($t); $aud = $audIn(); $P = swc_people($pdo, $aud); if (!$P) json_response(['error' => 'Nobody to send to in this audience (STOP customers are left out).'], 422);
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120) ?: ($t['name'] . ' · ' . date('d M')); $start = trim((string)($in['start_at'] ?? '')); $start = $start !== '' && strtotime($start) ? date('Y-m-d H:i:s', strtotime($start)) : null;
        $nr = max(0, min(3650, (int)($in['no_repeat'] ?? 30))); $fam = swc_family((string)$t['name']); if ($nr > 0 && empty($in['retry_of'])) { $P = array_diff_key($P, swc_got($pdo, $fam, $nr)); if (!$P) json_response(['error' => 'Everybody here already got this message in the last ' . $nr . ' days.'], 422); }
        $tagAfter = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['tag_after'] ?? ''))), 0, 30);
        $pdo->prepare("INSERT INTO st_wa_camp (name, tpl_id, tpl_name, category, vars, audience, status, total, day_limit, start_at, created_by, created_at, retry_of, no_repeat, tag_after, family) VALUES (?, ?, ?, ?, ?, ?, 'running', ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$name, (int)$t['id'], (string)$t['name'], (string)$t['meta_category'], json_encode($map, JSON_UNESCAPED_UNICODE), json_encode($aud, JSON_UNESCAPED_UNICODE), count($P), max(0, (int)($in['day_limit'] ?? 0)), $start, $me, date('Y-m-d H:i:s'), max(0, (int)($in['retry_of'] ?? 0)), $nr, $tagAfter, $fam]);
        $cid = (int)$pdo->lastInsertId(); $ins = $pdo->prepare("INSERT INTO st_wa_camp_item (camp_id, phone, data) VALUES (?, ?, ?)");
        $pdo->beginTransaction(); foreach ($P as $ph => $d) $ins->execute([$cid, $ph, json_encode($d, JSON_UNESCAPED_UNICODE)]); $pdo->commit();
        st_camp_next(0); json_response(['ok' => true, 'id' => $cid, 'count' => count($P)]);
    }
    if ($action === 'st_wa.camp_ctl') {
        $id = (int)($in['id'] ?? 0); $do = (string)($in['do'] ?? ''); $to = ['pause' => 'paused', 'resume' => 'running', 'cancel' => 'cancelled'][$do] ?? ''; if ($to === '') json_response(['error' => 'Unknown.'], 422);
        $pdo->prepare("UPDATE st_wa_camp SET status = ? WHERE id = ? AND status IN ('running','paused')")->execute([$to, $id]); if ($to === 'cancelled') $pdo->prepare("UPDATE st_wa_camp_item SET status = 'skipped', err = 'cancelled' WHERE camp_id = ? AND status = 'queued'")->execute([$id]);
        if ($to === 'running') st_camp_next(0); json_response(['ok' => true]);
    }
    if ($action === 'st_wa.camp_retry') {      // the failed ones of a campaign (at sending or by Meta) as a NEW campaign, e.g. with a Utility template
        $id = (int)($in['id'] ?? 0); $ph = $pdo->query("SELECT DISTINCT i.phone FROM st_wa_camp_item i LEFT JOIN st_wa_msg m ON m.wamid = i.wamid AND i.wamid <> '' WHERE i.camp_id = " . $id . " AND (i.status = 'failed' OR m.status = 'failed')")->fetchAll(PDO::FETCH_COLUMN);
        json_response(['ok' => true, 'phones' => array_values(array_map('strval', $ph))]);
    }
    if ($action === 'st_wa.camp_cfg_save') {
        $d = swc_cfg_defaults(); $o = []; foreach ($d as $k => $x) $o[$k] = is_float($x) ? max(0, min(100, (float)($in[$k] ?? $c[$k]))) : max(0, min(1000000, (int)($in[$k] ?? $c[$k])));
        $pdo->prepare("INSERT INTO st_cs_settings (skey, svalue) VALUES ('swcamp', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([json_encode($o)]); json_response(['ok' => true, 'cfg' => swc_cfg($pdo)]);
    }
    json_response(['error' => 'Unknown action.'], 404);
}
