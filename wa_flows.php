<?php
/**
 * wa_flows.php — the flow builder + its engine. Loaded by wa_growth.php. Admin only.
 *
 * A FLOW = a trigger + a list of steps. "First message → if they reply → second → third…"
 *   Triggers   new_chat · ad_click · keyword · button · tag · no_reply (customer silent for N minutes)
 *              camp_kw  = "a customer REPLIES TO A CAMPAIGN": they got a message of campaign camp_id in the last camp_days days AND
 *                         their reply has one of the words (or they tap one of the campaign's buttons)
 *   Steps      text · media (one saved photo/video/PDF) · folder (up to 3 items of a gallery folder) · buttons (up to 3 reply buttons)
 *              list (interactive list, up to 10 rows) · media_buttons (photo / video with up to 3 reply buttons in ONE message)
 *              ask_location (WhatsApp "Send location" button) · ask_address (WhatsApp address form, India; plain question if refused)
 *              goto_flow (end this run and start another flow for the same customer; max 3 jumps per customer per hour)
 *              tpl (a pre-made message; works outside the 24 h window) · wait (minutes) · wait_reply (until they answer, with a time-out)
 *              branch (tag / ordered / reply contains…) · tag · assign · notify · stop
 *   Every step has an id. "next" = the step after it (default: the one below). "on" = jumps, e.g. {"btn:Price":"s5","reply":"s4","timeout":"s9"}.
 *
 * SAFETY (cannot be switched off)
 *   - Free text, photos and buttons are sent only inside the 24-hour window; outside it the run is "blocked" (shown in the flow's numbers) unless a tpl step comes first.
 *   - One run per customer at a time. A customer who opted out never gets a flow message.
 *   - If a person writes to the customer while a flow waits, the flow stops (the person has taken over).
 *   - A flow starts only once per customer per 30 days (a jump with "go to another flow" is the only exception). A run is capped at 25 steps.
 *
 * FLOW OR CHATBOT: while a customer has a run in state run / wait / ready the chatbot stays quiet for that chat — wag_flow_active($pdo, $phone).
 *   A run leaves those states when it ends, is blocked, is switched off, is cancelled (wag_flow_cancel / wa.g_flow_cancel) or a person writes to the customer.
 */
if (!function_exists('wag_flow_tables')) {

function wag_flow_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_flows (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, trig VARCHAR(16) NOT NULL DEFAULT 'new_chat', trig_val VARCHAR(120) NOT NULL DEFAULT '', active TINYINT(1) NOT NULL DEFAULT 0,
            steps MEDIUMTEXT NULL, created_by INT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL, KEY k_act (active, trig)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_flow_runs (id INT AUTO_INCREMENT PRIMARY KEY, flow_id INT NOT NULL, phone VARCHAR(12) NOT NULL, step_id VARCHAR(12) NOT NULL DEFAULT '', state VARCHAR(10) NOT NULL DEFAULT 'run', wait_kind VARCHAR(6) NOT NULL DEFAULT '',
            wait_until DATETIME NULL, last_reply VARCHAR(300) NOT NULL DEFAULT '', steps_done INT NOT NULL DEFAULT 0, note VARCHAR(200) NOT NULL DEFAULT '', started_at DATETIME NULL, updated_at DATETIME NULL, last_flow_at DATETIME NULL,
            KEY k_phone (phone, state), KEY k_flow (flow_id, started_at), KEY k_state (state, wait_until)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_flow_ev (id INT AUTO_INCREMENT PRIMARY KEY, run_id INT NOT NULL, flow_id INT NOT NULL, step_id VARCHAR(12) NOT NULL DEFAULT '', phone VARCHAR(12) NOT NULL, kind VARCHAR(8) NOT NULL, at DATETIME NULL, KEY k_flow (flow_id, step_id, kind), KEY k_run (run_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (PDOException $e) { $ok = false; }
    if ($ok) {   // newer columns / index: added once, never fails the page
        try {
            $have = []; foreach ($pdo->query("SHOW COLUMNS FROM wa_flows")->fetchAll() as $c) $have[(string)$c['Field']] = true;
            if (empty($have['camp_id'])) { try { $pdo->exec("ALTER TABLE wa_flows ADD COLUMN camp_id INT NOT NULL DEFAULT 0"); } catch (PDOException $e) { } }
            if (empty($have['camp_days'])) { try { $pdo->exec("ALTER TABLE wa_flows ADD COLUMN camp_days TINYINT NOT NULL DEFAULT 3"); } catch (PDOException $e) { } }
            $ix = false; foreach ($pdo->query("SHOW INDEX FROM wa_flow_ev")->fetchAll() as $c) if ((string)$c['Key_name'] === 'k_ph') { $ix = true; break; }
            if (!$ix) { try { $pdo->exec("ALTER TABLE wa_flow_ev ADD KEY k_ph (phone, at)"); } catch (PDOException $e) { } }
        } catch (Throwable $e) { }
    }
    return $ok;
}
function wag_flow_types(): array
{
    return ['text' => 'Send text', 'media' => 'Send a photo / video / PDF', 'folder' => 'Send photos of a gallery folder', 'buttons' => 'Ask with buttons', 'list' => 'Ask with a list', 'media_buttons' => 'Photo + buttons',
            'ask_location' => 'Ask for location', 'ask_address' => 'Ask for address', 'tpl' => 'Send a pre-made message', 'wait' => 'Wait', 'wait_reply' => 'Wait for their reply',
            'branch' => 'If … then', 'tag' => 'Add a tag', 'assign' => 'Give to a person', 'notify' => 'Alert a person', 'goto_flow' => 'Go to another flow', 'stop' => 'End'];
}
/** Steps that send a free message (no template): they need the 24-hour window. */
function wag_flow_free_types(): array { return ['text', 'media', 'folder', 'buttons', 'list', 'media_buttons', 'ask_location', 'ask_address']; }
/** Steps that send a question and then wait for the customer's answer. */
function wag_flow_ask_types(): array { return ['buttons', 'list', 'media_buttons', 'ask_location', 'ask_address']; }
/** Cleans and validates what the builder sends. Returns [steps, problems[]]. */
function wag_flow_clean(array $raw, PDO $pdo, int $selfId = 0): array
{
    $types = wag_flow_types(); $steps = []; $bad = []; $ids = [];
    foreach (array_slice($raw, 0, 30) as $i => $s) {
        if (!is_array($s) || !isset($types[$s['type'] ?? ''])) { $bad[] = 'Step ' . ($i + 1) . ': unknown type.'; continue; }
        $id = preg_replace('/[^a-z0-9]/i', '', (string)($s['id'] ?? '')) ?: 's' . ($i + 1); $id = substr($id, 0, 10); while (isset($ids[$id])) $id .= 'x'; $ids[$id] = 1;
        $o = ['id' => $id, 'type' => $s['type']]; $n = 'Step ' . ($i + 1) . ' (' . $types[$s['type']] . ')';
        switch ($s['type']) {
            case 'text': $o['text'] = trim(mb_substr((string)($s['text'] ?? ''), 0, 1000)); if ($o['text'] === '') $bad[] = $n . ': write the message.'; break;
            case 'media': $o['lib_id'] = (int)($s['lib_id'] ?? 0); $o['caption'] = trim(mb_substr((string)($s['caption'] ?? ''), 0, 900)); if ($o['lib_id'] <= 0) $bad[] = $n . ': choose the file.'; break;
            case 'folder': $o['folder'] = (string)($s['folder'] ?? ''); $o['count'] = max(1, min(3, (int)($s['count'] ?? 3))); $o['caption'] = trim(mb_substr((string)($s['caption'] ?? ''), 0, 900)); if ($o['folder'] === '' || !isset(wag_folders()[$o['folder']])) $bad[] = $n . ': choose the folder.'; break;
            case 'buttons': $o['text'] = trim(mb_substr((string)($s['text'] ?? ''), 0, 900)); $b = []; foreach ((array)($s['buttons'] ?? []) as $t) { $t = trim(mb_substr((string)(is_array($t) ? ($t['title'] ?? '') : $t), 0, 20)); if ($t !== '') $b[] = $t; } $o['buttons'] = array_slice(array_values(array_unique($b)), 0, 3); $o['timeout'] = max(0, min(10080, (int)($s['timeout'] ?? 0)));
                if ($o['text'] === '' || count($o['buttons']) < 1) $bad[] = $n . ': write the question and 1–3 buttons (max 20 letters each).'; break;
            case 'list': {
                $o['header'] = trim(mb_substr((string)($s['header'] ?? ''), 0, 60)); $o['text'] = trim(mb_substr((string)($s['text'] ?? ''), 0, 1024)); $o['button'] = trim(mb_substr((string)($s['button'] ?? ''), 0, 20)); if ($o['button'] === '') $o['button'] = 'Choose';
                $rows = []; $seen = []; foreach ((array)($s['rows'] ?? []) as $r) { $t = trim(mb_substr((string)(is_array($r) ? ($r['title'] ?? '') : $r), 0, 24)); $d = is_array($r) ? trim(mb_substr((string)($r['description'] ?? ''), 0, 72)) : ''; if ($t === '' || isset($seen[mb_strtolower($t)])) continue; $seen[mb_strtolower($t)] = 1; $rows[] = ['title' => $t, 'description' => $d]; }
                $o['rows'] = array_slice($rows, 0, 10); $o['timeout'] = max(0, min(10080, (int)($s['timeout'] ?? 0)));
                if ($o['text'] === '' || count($o['rows']) < 1) $bad[] = $n . ': write the question and 1–10 list items (each max 24 letters; the same title only once).'; break;
            }
            case 'media_buttons': {
                $o['lib_id'] = (int)($s['lib_id'] ?? 0); $o['text'] = trim(mb_substr((string)($s['text'] ?? ''), 0, 1024)); $b = []; foreach ((array)($s['buttons'] ?? []) as $t) { $t = trim(mb_substr((string)(is_array($t) ? ($t['title'] ?? '') : $t), 0, 20)); if ($t !== '') $b[] = $t; }
                $o['buttons'] = array_slice(array_values(array_unique($b)), 0, 3); $o['timeout'] = max(0, min(10080, (int)($s['timeout'] ?? 0)));
                if ($o['lib_id'] <= 0) $bad[] = $n . ': choose the photo or video.';
                elseif (function_exists('wa_lib_row') && ($lr = wa_lib_row($pdo, $o['lib_id'])) && !in_array((string)$lr['kind'], ['image', 'video'], true)) $bad[] = $n . ': a photo or a video is needed here (a PDF cannot go with buttons).';
                if ($o['text'] === '' || count($o['buttons']) < 1) $bad[] = $n . ': write the question and 1–3 buttons (max 20 letters each).'; break;
            }
            case 'ask_location': case 'ask_address': $o['text'] = trim(mb_substr((string)($s['text'] ?? ''), 0, 1024)); $o['timeout'] = max(0, min(10080, (int)($s['timeout'] ?? 0))); if ($o['text'] === '') $bad[] = $n . ': write what you ask the customer.'; break;
            case 'goto_flow': $o['flow_id'] = (int)($s['flow_id'] ?? 0);
                if ($o['flow_id'] <= 0 || !wag_flow_row($pdo, $o['flow_id'])) $bad[] = $n . ': choose the flow to go to.'; elseif ($selfId > 0 && $o['flow_id'] === $selfId) $bad[] = $n . ': a flow cannot go to itself.'; break;
            case 'tpl': $o['tpl_id'] = (int)($s['tpl_id'] ?? 0); if ($o['tpl_id'] <= 0) $bad[] = $n . ': choose the pre-made message.'; break;
            case 'wait': $o['minutes'] = max(1, min(43200, (int)($s['minutes'] ?? 0))); break;
            case 'wait_reply': $o['timeout'] = max(1, min(43200, (int)($s['timeout'] ?? 60))); break;
            case 'branch': $o['cond'] = in_array($s['cond'] ?? '', ['tag', 'ordered', 'kw'], true) ? $s['cond'] : 'kw'; $o['val'] = trim(mb_substr((string)($s['val'] ?? ''), 0, 60)); $o['yes'] = (string)($s['yes'] ?? ''); $o['no'] = (string)($s['no'] ?? ''); if ($o['cond'] !== 'ordered' && $o['val'] === '') $bad[] = $n . ': write the tag / word.'; break;
            case 'tag': $o['tag'] = mb_strtolower(trim(mb_substr(preg_replace('/[^\p{L}\p{N} \-]/u', '', (string)($s['tag'] ?? '')) ?? '', 0, 28))); if (mb_strlen($o['tag']) < 2) $bad[] = $n . ': write the tag.'; break;
            case 'assign': case 'notify': $o['emp_id'] = (int)($s['emp_id'] ?? 0); if ($s['type'] === 'notify') $o['text'] = trim(mb_substr((string)($s['text'] ?? ''), 0, 120)); break;
        }
        if (!empty($s['next'])) $o['next'] = substr(preg_replace('/[^a-z0-9]/i', '', (string)$s['next']), 0, 10);
        if (!empty($s['on']) && is_array($s['on'])) { $on = []; foreach ($s['on'] as $k => $v) { $k = mb_substr((string)$k, 0, 40); $v = substr(preg_replace('/[^a-z0-9]/i', '', (string)$v), 0, 10); if ($v !== '') $on[$k] = $v; } if ($on) $o['on'] = $on; }
        $steps[] = $o;
    }
    foreach ($steps as $s) { foreach (array_merge([$s['next'] ?? ''], array_values($s['on'] ?? []), [$s['yes'] ?? '', $s['no'] ?? '']) as $t) if ($t !== '' && !isset($ids[$t])) $bad[] = 'A step points to “' . $t . '”, which does not exist.'; }
    if (!$steps) $bad[] = 'Add at least one step.';
    return [$steps, array_values(array_unique($bad))];
}
function wag_flow_row(PDO $pdo, int $id): ?array { $q = $pdo->prepare("SELECT * FROM wa_flows WHERE id = ?"); $q->execute([$id]); $r = $q->fetch(); return $r ?: null; }
function wag_flow_steps(array $f): array { $s = json_decode((string)$f['steps'], true); return is_array($s) ? $s : []; }
function wag_flow_ev(PDO $pdo, array $run, string $step, string $kind): void { $pdo->prepare("INSERT INTO wa_flow_ev (run_id, flow_id, step_id, phone, kind, at) VALUES (?, ?, ?, ?, ?, ?)")->execute([$run['id'], $run['flow_id'], $step, $run['phone'], $kind, date('Y-m-d H:i:s')]); }
function wag_flow_idx(array $steps, string $id): int { foreach ($steps as $i => $s) if ($s['id'] === $id) return $i; return -1; }
function wag_flow_fill(string $t, array $c): string { return str_replace(['{name}', '{{1}}'], wa_first_name((string)$c['name']), $t); }

/** Starts a run unless the customer is busy in a flow, opted out, or already had this flow in the last 30 days ($skip30 = a jump from another flow). */
function wag_flow_start(PDO $pdo, array $f, string $key, string $why = '', bool $now_run = false, bool $skip30 = false): bool
{
    $c = wa_contact($pdo, $key); if (!$c || (int)$c['optout'] === 1) return false; $steps = wag_flow_steps($f); if (!$steps) return false;
    if (!wag_flow_window($c) && in_array($steps[0]['type'], wag_flow_free_types(), true)) return false;   // window closed and the flow starts with a free message: nothing could be sent, do not start
    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_flow_runs WHERE phone = ? AND state IN ('run', 'wait', 'ready')"); $q->execute([$key]); if ((int)$q->fetchColumn() > 0) return false;
    if (!$skip30) { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_flow_runs WHERE phone = ? AND flow_id = ? AND started_at >= ?"); $q->execute([$key, $f['id'], date('Y-m-d H:i:s', time() - 30 * 86400)]); if ((int)$q->fetchColumn() > 0) return false; }
    $now = date('Y-m-d H:i:s'); $pdo->prepare("INSERT INTO wa_flow_runs (flow_id, phone, step_id, state, started_at, updated_at, note) VALUES (?, ?, ?, 'ready', ?, ?, ?)")->execute([$f['id'], $key, $steps[0]['id'], $now, $now, mb_substr($why, 0, 200)]);
    $rid = (int)$pdo->lastInsertId(); wag_flow_ev($pdo, ['id' => $rid, 'flow_id' => $f['id'], 'phone' => $key], '', 'start');
    if ($now_run) { $q = $pdo->prepare("SELECT * FROM wa_flow_runs WHERE id = ?"); $q->execute([$rid]); $r = $q->fetch(); if ($r) { try { wag_flow_advance($pdo, $r); } catch (Throwable $e) { wag_flow_stop($pdo, $r, 'stopped', 'Error: ' . mb_substr($e->getMessage(), 0, 120)); } } }   // the customer just wrote: answer at once, not at the next tick
    return true;
}
/** True while the customer is inside a flow (a run that is going, waiting or about to go) — the chatbot must stay quiet for that chat. Self-correcting: a run whose person already wrote is stopped here. */
function wag_flow_active(PDO $pdo, string $phone): bool
{
    try {
        if ($phone === '' || !wag_flow_tables($pdo)) return false;
        $q = $pdo->prepare("SELECT * FROM wa_flow_runs WHERE phone = ? AND state IN ('run', 'wait', 'ready') ORDER BY id DESC LIMIT 5"); $q->execute([$phone]); $now = time();
        foreach ($q->fetchAll() as $r) {
            if ($r['state'] === 'run' && strtotime((string)$r['updated_at']) < $now - 600) continue;                                                        // stuck run: the tick will stop it
            if ($r['state'] === 'wait' && !empty($r['wait_until']) && strtotime((string)$r['wait_until']) < $now - 3600) continue;                      // the wait is long over and nobody picked it up (cron not running): do not silence the bot for ever
            if ($r['state'] === 'wait' && wag_flow_person_wrote($pdo, $r)) { wag_flow_stop($pdo, $r, 'stopped', 'A person wrote to the customer, so the flow stopped.'); continue; }
            return true;
        }
    } catch (Throwable $e) { }
    return false;
}
/** Stops every running / waiting flow of one customer (a person takes over). Returns how many runs were stopped. */
function wag_flow_cancel(PDO $pdo, string $phone, string $note = 'A person stopped the flow.'): int
{
    if (!wag_flow_tables($pdo)) return 0; $q = $pdo->prepare("SELECT * FROM wa_flow_runs WHERE phone = ? AND state IN ('run', 'wait', 'ready')"); $q->execute([$phone]); $n = 0;
    foreach ($q->fetchAll() as $r) { wag_flow_stop($pdo, $r, 'stopped', $note); $n++; } return $n;
}
/** Did a person (not the flow / bot / campaign) write to the customer since the flow last sent something? */
function wag_flow_person_wrote(PDO $pdo, array $r): bool
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'out' AND who NOT IN ('flow', 'bot', 'system', 'follow', 'campaign') AND emp_id > 0 AND ts > ?"); $q->execute([$r['phone'], $r['last_flow_at'] ?: $r['started_at']]); return (int)$q->fetchColumn() > 0;
}
/** The quick-reply button titles of the pre-made message a campaign sends. */
function wag_flow_camp_buttons(PDO $pdo, int $campId): array
{
    $out = []; try {
        $q = $pdo->prepare("SELECT t.buttons FROM wa_campaigns c JOIN wa_templates t ON t.id = c.tpl_id WHERE c.id = ?"); $q->execute([$campId]); $j = json_decode((string)$q->fetchColumn(), true);
        foreach ((array)$j as $b) if (is_array($b) && in_array(strtoupper((string)($b['type'] ?? 'QUICK_REPLY')), ['QUICK_REPLY', 'REPLY'], true) && trim((string)($b['text'] ?? '')) !== '') $out[] = trim((string)$b['text']);
    } catch (Throwable $e) { }
    return $out;
}
/** When did this customer last get a message of the flow's campaign inside the allowed days? '' = never / too long ago. */
function wag_flow_camp_sent(PDO $pdo, array $f, string $key): string
{
    $cid = (int)($f['camp_id'] ?? 0); if ($cid <= 0) return '';
    try { $q = $pdo->prepare("SELECT MAX(sent_at) FROM wa_camp_items WHERE camp_id = ? AND phone = ? AND status = 'sent' AND sent_at >= ?"); $q->execute([$cid, $key, date('Y-m-d H:i:s', time() - max(1, (int)($f['camp_days'] ?? 3)) * 86400)]); return (string)$q->fetchColumn(); } catch (Throwable $e) { return ''; }
}
/** Words of a "keyword" trigger: any word inside the message (comma list). Button titles of the campaign inside the list match only a tap on that button. */
function wag_flow_kw_hit(string $trigVal, string $text, string $btn, array $exact = []): bool
{
    $low = mb_strtolower($text); $tv = mb_strtolower($trigVal); $ex = [];
    foreach ($exact as $t) { $t = mb_strtolower(trim((string)$t)); if ($t !== '' && mb_strpos($t, ',') !== false && mb_strpos($tv, $t) !== false) { $ex[] = $t; $tv = str_replace($t, ',', $tv); } }   // a button title with a comma ("Yes, send me the list") must not fall apart into words: it matches only a tap on that button
    if ($btn !== '' && in_array(mb_strtolower($btn), $ex, true)) return true;
    foreach (preg_split('/\s*,\s*/u', $tv) as $k) { if ($k !== '' && mb_strpos($low, $k) !== false) return true; }
    return false;
}
function wag_flow_addr_text(array $m): string
{
    $nf = $m['interactive']['nfm_reply'] ?? null; if (!is_array($nf)) return ''; $j = json_decode((string)($nf['response_json'] ?? ''), true); if (!is_array($j)) return '';
    $v = isset($j['values']) && is_array($j['values']) ? $j['values'] : $j;
    if ((string)($nf['name'] ?? '') !== 'address_message' && !isset($v['address']) && !isset($v['in_pin_code'])) return '';
    $g = fn(string $k) => trim((string)($v[$k] ?? '')); $who = trim($g('name') . ($g('phone_number') !== '' ? ' · ' . $g('phone_number') : ''));
    $adr = array_values(array_filter([$g('house_number'), $g('floor_number'), $g('tower_number'), $g('building_name'), $g('address'), $g('landmark_area'), $g('city'), $g('state'), $g('in_pin_code') ?: $g('sg_post_code')], fn($x) => $x !== ''));
    if (!$adr && $who === '') { foreach ($v as $k => $x) if (is_string($x) && $x !== '' && $k !== 'flow_token') $adr[] = $x; }
    return trim($who . ($who !== '' && $adr ? "\n" : '') . implode(', ', $adr));
}
/**
 * What the customer's message means for a flow. Also writes the location / address into the stored chat message
 * (wa_store_in keeps only "📍 Location" and nothing for an address form).
 * Returns ['body' => text for trigger matching, 'btn' => button / list title, 'reply' => what a waiting run receives, 'kind' => btn|loc|addr|reply]
 */
function wag_flow_in_info(PDO $pdo, string $key, array $m): array
{
    $btn = trim((string)($m['interactive']['button_reply']['title'] ?? $m['button']['text'] ?? $m['interactive']['list_reply']['title'] ?? ''));
    $body = trim((string)($m['text']['body'] ?? $m['button']['text'] ?? $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''));
    $reply = $btn !== '' ? 'btn:' . $btn : $body; $kind = $btn !== '' ? 'btn' : 'reply'; $wamid = (string)($m['id'] ?? '');
    $save = function (string $txt) use ($pdo, $key, $wamid) { if ($wamid === '') return; try { $pdo->prepare("UPDATE wa_messages SET body = ? WHERE wamid = ? AND phone = ? AND dir = 'in'")->execute([$txt, $wamid, $key]); } catch (Throwable $e) { } };
    if ((string)($m['type'] ?? '') === 'location' && is_array($m['location'] ?? null)) {
        $l = $m['location']; $lat = $l['latitude'] ?? ''; $lng = $l['longitude'] ?? '';
        if (is_numeric($lat) && is_numeric($lng)) {
            $nm = trim((string)($l['name'] ?? '')); $ad = trim((string)($l['address'] ?? ''));
            $save('📍 Location' . ($nm !== '' ? ': ' . $nm : '') . ($ad !== '' ? ' — ' . $ad : '') . ' · ' . $lat . ',' . $lng . ' · https://maps.google.com/?q=' . $lat . ',' . $lng);
            $body = ''; $reply = 'loc:' . $lat . ',' . $lng; $kind = 'loc';
        }
    } elseif ((string)($m['type'] ?? '') === 'interactive' && isset($m['interactive']['nfm_reply'])) {
        $a = wag_flow_addr_text($m);
        if ($a !== '') { $save('🏠 Address: ' . $a); $body = ''; $reply = 'addr:' . preg_replace('/\s+/u', ' ', $a); $kind = 'addr'; }
    }
    return ['body' => $body, 'btn' => $btn, 'reply' => mb_substr($reply, 0, 300), 'kind' => $kind];
}
function wag_flow_on_in(PDO $pdo, string $key, array $m, bool $isNew, array $ref): void
{
    if (!wag_flow_tables($pdo)) return;
    $in = wag_flow_in_info($pdo, $key, $m); $body = $in['body']; $btn = $in['btn'];
    // 1) a run that waits for an answer: the answer wakes it
    $q = $pdo->prepare("SELECT * FROM wa_flow_runs WHERE phone = ? AND state = 'wait' AND wait_kind = 'reply' ORDER BY id DESC LIMIT 1"); $q->execute([$key]); $run = $q->fetch();
    if ($run) {
        $pdo->prepare("UPDATE wa_flow_runs SET state = 'ready', last_reply = ?, updated_at = ? WHERE id = ?")->execute([$in['reply'], date('Y-m-d H:i:s'), $run['id']]);
        wag_flow_ev($pdo, $run, (string)$run['step_id'], 'reply'); if ($in['kind'] !== 'reply') wag_flow_ev($pdo, $run, (string)$run['step_id'], $in['kind']);   // btn / loc / addr: "answered a button" in the numbers
        $run['state'] = 'ready'; $run['last_reply'] = $in['reply']; try { wag_flow_advance($pdo, $run); } catch (Throwable $e) { wag_flow_stop($pdo, $run, 'stopped', 'Error: ' . mb_substr($e->getMessage(), 0, 120)); }   // answer at once: the customer just tapped
        return;
    }
    // 2) a new run (never while the customer is inside another flow)
    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_flow_runs WHERE phone = ? AND state IN ('run', 'wait', 'ready')"); $q->execute([$key]); if ((int)$q->fetchColumn() > 0) return;
    $camp = []; $rest = [];
    foreach ($pdo->query("SELECT * FROM wa_flows WHERE active = 1 AND trig IN ('new_chat', 'ad_click', 'keyword', 'button', 'camp_kw') ORDER BY id")->fetchAll() as $f) {
        if ($f['trig'] !== 'camp_kw') { $rest[] = $f; continue; }
        if ($body === '' && $btn === '') continue;   // a location / photo is not a reply with words
        $at = wag_flow_camp_sent($pdo, $f, $key); if ($at === '') continue;   // did not get this campaign (or too long ago)
        if (wag_flow_kw_hit((string)$f['trig_val'], $body, $btn, wag_flow_camp_buttons($pdo, (int)$f['camp_id']))) $camp[] = [$at, $f];
    }
    usort($camp, fn($a, $b) => strcmp($b[0], $a[0]) ?: ((int)$a[1]['id'] <=> (int)$b[1]['id']));   // the campaign they got most recently wins
    foreach ($camp as [$at, $f]) if (wag_flow_start($pdo, $f, $key, 'campaign #' . (int)$f['camp_id'] . ' reply', true)) return;
    foreach ($rest as $f) {
        $hit = false;
        if ($f['trig'] === 'new_chat') $hit = $isNew;
        elseif ($f['trig'] === 'ad_click') $hit = $isNew && !empty($ref);
        elseif ($f['trig'] === 'keyword') $hit = wag_flow_kw_hit((string)$f['trig_val'], $body, '');
        elseif ($f['trig'] === 'button') $hit = $btn !== '' && mb_strtolower($btn) === mb_strtolower(trim((string)$f['trig_val']));
        if ($hit && wag_flow_start($pdo, $f, $key, $f['trig'], true)) return;
    }
}
function wag_flow_on_tag(PDO $pdo, string $key, string $tag): void
{
    if (!wag_flow_tables($pdo)) return;
    foreach ($pdo->query("SELECT * FROM wa_flows WHERE active = 1 AND trig = 'tag' ORDER BY id")->fetchAll() as $f) if (mb_strtolower(trim((string)$f['trig_val'])) === mb_strtolower($tag) && wag_flow_start($pdo, $f, $key, 'tag ' . $tag, true)) return;
}
function wag_flow_stop(PDO $pdo, array $run, string $state, string $note): void
{
    $pdo->prepare("UPDATE wa_flow_runs SET state = ?, note = ?, updated_at = ? WHERE id = ?")->execute([$state, mb_substr($note, 0, 200), date('Y-m-d H:i:s'), $run['id']]); wag_flow_ev($pdo, $run, (string)$run['step_id'], $state === 'done' ? 'done' : $state);
}
function wag_flow_window(array $c): bool { return !empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) > time() - 86400; }
/** The WhatsApp number id (Meta phone_id) this customer last wrote to. */
function wag_flow_pid(PDO $pdo, array $c): string
{
    $q = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q->execute([(int)$c['last_number_id']]); $pid = (string)$q->fetchColumn(); return $pid !== '' ? $pid : wa_cfg_val('WA_PHONE_ID');
}
/**
 * Sends ONE interactive message (buttons / list / location request / address form) and writes it into the chat.
 * $shown = the words the chat window shows. Returns [ok, wamid|error, Meta error code].
 */
function wag_flow_send_interactive(PDO $pdo, array $c, array $interactive, string $shown, string $mediaId = '', string $mime = ''): array
{
    if (!wag_flow_window($c)) return [false, 'The 24-hour window is closed.', 0];
    $pid = wag_flow_pid($pdo, $c); if ($pid === '') return [false, 'WA_PHONE_ID is missing.', 0];
    [$code, $d] = wa_api('POST', '/' . $pid . '/messages', ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => (string)($c['wa_id'] ?: '91' . $c['phone']), 'type' => 'interactive', 'interactive' => $interactive]);
    $wamid = (string)($d['messages'][0]['id'] ?? ''); if ($code < 200 || $code >= 300 || $wamid === '') return [false, wa_err($d), (int)($d['error']['code'] ?? 0)];
    $now = date('Y-m-d H:i:s'); $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, media_id, media_mime, status, who, emp_id, ts) VALUES (?, ?, ?, 'out', 'interactive', ?, ?, ?, 'sent', 'flow', 0, ?)")->execute([$wamid, $c['phone'], (int)$c['last_number_id'], mb_substr($shown, 0, 3000), $mediaId, mb_substr($mime, 0, 60), $now]);
    $pdo->prepare("UPDATE wa_contacts SET last_out_at = ? WHERE phone = ?")->execute([$now, $c['phone']]); return [true, $wamid, 0];
}
/** Sends reply buttons. Returns [ok, wamid|error]. */
function wag_flow_send_buttons(PDO $pdo, array $c, string $text, array $buttons, int $runId): array
{
    $bt = []; foreach ($buttons as $i => $t) $bt[] = ['type' => 'reply', 'reply' => ['id' => 'f' . $runId . '_' . $i, 'title' => mb_substr($t, 0, 20)]];
    [$ok, $r] = wag_flow_send_interactive($pdo, $c, ['type' => 'button', 'body' => ['text' => $text], 'action' => ['buttons' => $bt]], $text . "\n[" . implode('] [', $buttons) . ']'); return [$ok, $r];
}
/** The payload of a list message (header optional, body, button text, up to 10 rows in one section). */
function wag_flow_list_payload(string $header, string $text, string $button, array $rows, int $runId): array
{
    $r = []; foreach (array_slice($rows, 0, 10) as $i => $x) { $o = ['id' => 'f' . $runId . '_r' . $i, 'title' => mb_substr((string)$x['title'], 0, 24)]; if (trim((string)($x['description'] ?? '')) !== '') $o['description'] = mb_substr((string)$x['description'], 0, 72); $r[] = $o; }
    $i = ['type' => 'list']; if ($header !== '') $i['header'] = ['type' => 'text', 'text' => mb_substr($header, 0, 60)];
    $i['body'] = ['text' => mb_substr($text, 0, 1024)]; $i['action'] = ['button' => mb_substr($button !== '' ? $button : 'Choose', 0, 20), 'sections' => [['title' => 'Options', 'rows' => $r]]]; return $i;
}
/** The payload of reply buttons with a photo / video on top, in ONE message. */
function wag_flow_media_buttons_payload(string $kind, string $mediaId, string $text, array $buttons, int $runId): array
{
    $bt = []; foreach (array_slice($buttons, 0, 3) as $i => $t) $bt[] = ['type' => 'reply', 'reply' => ['id' => 'f' . $runId . '_' . $i, 'title' => mb_substr((string)$t, 0, 20)]];
    return ['type' => 'button', 'header' => ['type' => $kind, $kind => ['id' => $mediaId]], 'body' => ['text' => mb_substr($text, 0, 1024)], 'action' => ['buttons' => $bt]];
}
/** "Send location" button. */
function wag_flow_location_payload(string $text): array { return ['type' => 'location_request_message', 'body' => ['text' => mb_substr($text, 0, 1024)], 'action' => ['name' => 'send_location']]; }
/** WhatsApp address form (India). */
function wag_flow_address_payload(string $text): array { return ['type' => 'address_message', 'body' => ['text' => mb_substr($text, 0, 1024)], 'action' => ['name' => 'address_message', 'parameters' => ['country' => 'IN']]]; }
/** How many times did this customer jump from one flow to another in the last hour? */
function wag_flow_hops(PDO $pdo, string $phone): int { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_flow_ev WHERE phone = ? AND kind = 'goto' AND at >= ?"); $q->execute([$phone, date('Y-m-d H:i:s', time() - 3600)]); return (int)$q->fetchColumn(); }
/** Runs one customer's flow forward until it has to wait, ends or is blocked. */
function wag_flow_advance(PDO $pdo, array $run): void
{
    $cl = $pdo->prepare("UPDATE wa_flow_runs SET state = 'run', updated_at = ? WHERE id = ? AND state = ?"); $cl->execute([date('Y-m-d H:i:s'), $run['id'], $run['state']]); if ($cl->rowCount() < 1) return;   // someone else (the tick or the webhook) already took this run
    $f = wag_flow_row($pdo, (int)$run['flow_id']); if (!$f || !(int)$f['active']) { wag_flow_stop($pdo, $run, 'stopped', 'The flow was switched off.'); return; }
    $steps = wag_flow_steps($f); $c = wa_contact($pdo, (string)$run['phone']); if (!$c || (int)$c['optout'] === 1) { wag_flow_stop($pdo, $run, 'stopped', 'The customer opted out.'); return; }
    $reply = (string)$run['last_reply']; $cur = (string)$run['step_id']; $fromWait = $run['state'] === 'ready' && $run['wait_kind'] !== ''; $timedOut = $run['state'] === 'wait'; $n = 0; $key = (string)$run['phone'];
    // we are waking from a wait: pick the way out of the step we waited at
    if (($fromWait || $timedOut) && (string)$run['wait_kind'] === 'time') { if ($cur === '') { wag_flow_stop($pdo, $run, 'done', 'Finished.'); return; } }   // a time wait: step_id already holds the step after it
    elseif ($fromWait || $timedOut) {
        $i = wag_flow_idx($steps, $cur); $st = $i >= 0 ? $steps[$i] : null; $on = $st['on'] ?? []; $to = '';
        if ($timedOut) $to = $on['timeout'] ?? '';
        else {
            foreach ($on as $k => $v) if ($k === $reply) { $to = $v; break; }
            if ($to === '' && strpos($reply, 'loc:') === 0) $to = $on['loc'] ?? '';        // they shared a location
            if ($to === '' && strpos($reply, 'addr:') === 0) $to = $on['addr'] ?? '';      // they sent the address form
            $to = $to ?: ($on['reply'] ?? '');
        }
        if ($timedOut && $to === '') { wag_flow_stop($pdo, $run, 'done', 'No reply — flow ended.'); return; }
        $cur = $to !== '' ? $to : (string)($st['next'] ?? ($steps[$i + 1]['id'] ?? ''));
        if ($cur === '') { wag_flow_stop($pdo, $run, 'done', 'Finished.'); return; }
    }
    while ($n++ < 25) {
        $i = wag_flow_idx($steps, $cur); if ($i < 0) { wag_flow_stop($pdo, $run, 'done', 'Finished.'); return; }
        $s = $steps[$i]; $nextId = (string)($s['next'] ?? ($steps[$i + 1]['id'] ?? '')); $now = date('Y-m-d H:i:s'); $c = wa_contact($pdo, $key) ?: $c;
        $run['step_id'] = $s['id']; $upd = fn(string $state, string $wk, ?string $until) => $pdo->prepare("UPDATE wa_flow_runs SET step_id = ?, state = ?, wait_kind = ?, wait_until = ?, updated_at = ?, steps_done = steps_done + 1 WHERE id = ?")->execute([$s['id'], $state, $wk, $until, $now, $run['id']]);
        $block = function (string $why) use ($pdo, $run, $s) { $pdo->prepare("UPDATE wa_flow_runs SET step_id = ?, state = 'blocked', note = ?, updated_at = ? WHERE id = ?")->execute([$s['id'], mb_substr($why, 0, 200), date('Y-m-d H:i:s'), $run['id']]); wag_flow_ev($pdo, $run, $s['id'], 'blocked'); };
        switch ($s['type']) {
            case 'list': case 'media_buttons': case 'ask_location': case 'ask_address': {
                if (!wag_flow_window($c)) { $block('The 24-hour window is closed — put a pre-made message step before this one.'); return; }
                $txt = wag_flow_fill((string)$s['text'], $c); $mid = ''; $mime = ''; $ok = false; $r = ''; $note = '';
                if ($s['type'] === 'list') {
                    $rows = (array)($s['rows'] ?? []); $titles = array_map(fn($x) => (string)$x['title'], $rows);
                    [$ok, $r] = wag_flow_send_interactive($pdo, $c, wag_flow_list_payload(wag_flow_fill((string)($s['header'] ?? ''), $c), $txt, (string)($s['button'] ?? ''), $rows, (int)$run['id']), '📋 ' . $txt . "\n[" . implode('] [', $titles) . ']');
                } elseif ($s['type'] === 'media_buttons') {
                    $lr = wa_lib_row($pdo, (int)$s['lib_id']); $pid = wag_flow_pid($pdo, $c);
                    if (!$lr || !in_array((string)$lr['kind'], ['image', 'video'], true)) { $r = 'The photo / video was not found in the library.'; }
                    else {
                        $kind = (string)$lr['kind']; [$mid, $e] = wa_lib_media_id($pdo, $lr, $pid);
                        if ($mid === '') $r = $e;
                        else {
                            $shown = ($kind === 'video' ? '🎬 ' : '📷 ') . $txt . "\n[" . implode('] [', (array)$s['buttons']) . ']'; $mime = (string)$lr['mime'];
                            [$ok, $r, $ec] = wag_flow_send_interactive($pdo, $c, wag_flow_media_buttons_payload($kind, $mid, $txt, (array)$s['buttons'], (int)$run['id']), $shown, $mid, $mime);
                            if (!$ok && (in_array($ec, [100, 131052, 131053, 131009], true) || stripos((string)$r, 'media') !== false)) {   // the saved Meta media id may have expired: upload again once
                                [$mid2, $e2] = wa_lib_media_id($pdo, $lr, $pid, true);
                                if ($mid2 !== '') { $mid = $mid2; [$ok, $r, $ec] = wag_flow_send_interactive($pdo, $c, wag_flow_media_buttons_payload($kind, $mid, $txt, (array)$s['buttons'], (int)$run['id']), $shown, $mid, $mime); }
                            }
                            if ($ok) { wag_use_tables($pdo); $pdo->prepare("INSERT INTO wa_media_use (lib_id, phone, emp_id, at) VALUES (?, ?, 0, ?)")->execute([(int)$s['lib_id'], $key, $now]); }
                        }
                    }
                } elseif ($s['type'] === 'ask_location') {
                    [$ok, $r] = wag_flow_send_interactive($pdo, $c, wag_flow_location_payload($txt), '📍 ' . $txt . "\n[Send location]");
                } else {   // ask_address: the WhatsApp address form; if Meta refuses it, a normal question
                    [$ok, $r] = wag_flow_send_interactive($pdo, $c, wag_flow_address_payload($txt), '🏠 ' . $txt . "\n[Address form]");
                    if (!$ok) {
                        wag_log($pdo, 'engine', false, 'flow ' . $f['id'] . ' · ' . $key . ' · address form refused (' . (is_string($r) ? $r : '') . ') — asked in words instead');
                        [$ok, $r] = wa_send_text($pdo, $c, $txt . "\n\nPlease type your full address: name, house / flat no., area, city and pincode.", 'flow', 0); $note = 'address form not available — asked in words';
                    }
                }
                if (!$ok) { $block('Could not send: ' . (is_string($r) ? $r : 'error')); wag_log($pdo, 'engine', false, 'flow ' . $f['id'] . ' · ' . $key . ' · ' . (is_string($r) ? $r : '')); return; }
                $pdo->prepare("UPDATE wa_flow_runs SET last_flow_at = ?" . ($note !== '' ? ", note = " . $pdo->quote($note) : '') . " WHERE id = ?")->execute([$now, $run['id']]); wag_flow_ev($pdo, $run, $s['id'], 'sent');
                $upd('wait', 'reply', date('Y-m-d H:i:s', time() + ((int)($s['timeout'] ?? 0) > 0 ? (int)$s['timeout'] : 10080) * 60)); return;
            }
            case 'goto_flow': {
                $tf = wag_flow_row($pdo, (int)$s['flow_id']); $upd('run', '', null);
                if (!$tf || !(int)$tf['active']) { wag_flow_stop($pdo, $run, 'done', 'The next flow is missing or switched off — this flow ended here.'); return; }
                if (wag_flow_hops($pdo, $key) >= 3) { wag_flow_stop($pdo, $run, 'done', 'Not sent on to “' . mb_substr((string)$tf['name'], 0, 60) . '”: this customer already jumped between flows 3 times in the last hour.'); return; }
                wag_flow_ev($pdo, $run, $s['id'], 'goto'); wag_flow_stop($pdo, $run, 'done', 'Went on to the flow “' . mb_substr((string)$tf['name'], 0, 60) . '”.');
                if (!wag_flow_start($pdo, $tf, $key, 'From flow “' . mb_substr((string)$f['name'], 0, 60) . '”', true, true)) $pdo->prepare("UPDATE wa_flow_runs SET note = ? WHERE id = ?")->execute([mb_substr('Tried to go on to “' . $tf['name'] . '” but it could not start (window closed, opted out or empty).', 0, 200), $run['id']]);
                return;
            }
            case 'text': case 'media': case 'folder': case 'buttons': {
                if (!wag_flow_window($c)) { $block('The 24-hour window is closed — put a pre-made message step before this one.'); return; }
                if ($s['type'] === 'text') { [$ok, $r] = wa_send_text($pdo, $c, wag_flow_fill((string)$s['text'], $c), 'flow', 0); }
                elseif ($s['type'] === 'buttons') { [$ok, $r] = wag_flow_send_buttons($pdo, $c, wag_flow_fill((string)$s['text'], $c), (array)$s['buttons'], (int)$run['id']); }
                else {
                    $ids = $s['type'] === 'media' ? [(int)$s['lib_id']] : array_map(fn($x) => (int)$x['id'], array_slice(array_values(array_filter(wag_gallery($pdo)['items'], fn($x) => $x['folder'] === $s['folder'])), 0, (int)($s['count'] ?? 3)));
                    $q = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q->execute([(int)$c['last_number_id']]); $pid = (string)$q->fetchColumn() ?: wa_cfg_val('WA_PHONE_ID'); $ok = false; $r = 'Nothing to send.'; $cnt = 0;
                    foreach ($ids as $lid) { $lr = wa_lib_row($pdo, $lid); if (!$lr) continue; [$mid, $e] = wa_lib_media_id($pdo, $lr, $pid); if ($mid === '') { $r = $e; continue; }
                        [$ok1, $r1] = wa_send_media($pdo, $c, (string)$lr['kind'], $mid, (string)$lr['mime'], $cnt === 0 ? wag_flow_fill((string)($s['caption'] ?? ''), $c) : '', (string)$lr['filename'], 0); if ($ok1) { $cnt++; $ok = true; wag_use_tables($pdo); $pdo->prepare("INSERT INTO wa_media_use (lib_id, phone, emp_id, at) VALUES (?, ?, 0, ?)")->execute([$lid, $key, $now]); } else $r = $r1; }
                }
                if (!$ok) { $block('Could not send: ' . (is_string($r) ? $r : 'error')); wag_log($pdo, 'engine', false, 'flow ' . $f['id'] . ' · ' . $key . ' · ' . (is_string($r) ? $r : '')); return; }
                $pdo->prepare("UPDATE wa_flow_runs SET last_flow_at = ? WHERE id = ?")->execute([$now, $run['id']]); wag_flow_ev($pdo, $run, $s['id'], 'sent');
                if ($s['type'] === 'buttons') { $upd('wait', 'reply', date('Y-m-d H:i:s', time() + ((int)($s['timeout'] ?? 0) > 0 ? (int)$s['timeout'] : 10080) * 60)); return; }
                $upd('run', '', null); $cur = $nextId; break;
            }
            case 'tpl': {
                if (!function_exists('wa_camp_tpl')) { $block('Pre-made messages are not available.'); return; }
                $t = wa_camp_tpl($pdo, (int)$s['tpl_id']); if (!$t) { $block('The pre-made message was not found or is not approved.'); return; }
                $ctx = wa_camp_ctx($pdo, $t, 2); [$nid, $why] = wa_pick_number($pdo, $c, $ctx); if ($nid === 0) { $block('No number can send now: ' . $why); return; }
                [$ok, $r] = wa_send_tpl_one($pdo, $c, $t, $ctx['nums'][$nid], wa_tpl_vars_for($t, (string)$c['name'], []), 'flow'); if (!$ok) { $block('Meta refused: ' . (is_string($r) ? $r : '')); return; }
                $pdo->prepare("UPDATE wa_flow_runs SET last_flow_at = ? WHERE id = ?")->execute([$now, $run['id']]); wag_flow_ev($pdo, $run, $s['id'], 'sent'); $upd('run', '', null); $cur = $nextId; break;
            }
            case 'wait': $upd('wait', 'time', date('Y-m-d H:i:s', time() + (int)$s['minutes'] * 60)); $pdo->prepare("UPDATE wa_flow_runs SET step_id = ? WHERE id = ?")->execute([$nextId !== '' ? $nextId : '', $run['id']]); if ($nextId === '') { wag_flow_stop($pdo, $run, 'done', 'Finished.'); } return;
            case 'wait_reply': $upd('wait', 'reply', date('Y-m-d H:i:s', time() + (int)$s['timeout'] * 60)); return;
            case 'branch': {
                $yes = false; $low = mb_strtolower($reply);
                if ($s['cond'] === 'tag') $yes = in_array(mb_strtolower((string)$s['val']), array_map('mb_strtolower', array_keys(wag_ctag_map($pdo)[$key] ?? [])), true);
                elseif ($s['cond'] === 'ordered') { $om = wa_order_map($pdo)[$key] ?? null; $yes = $om && $om['last'] && strtotime((string)$om['last']) > strtotime((string)$run['started_at']); }
                else $yes = $s['val'] !== '' && mb_strpos($low, mb_strtolower((string)$s['val'])) !== false;
                $to = $yes ? ($s['yes'] ?: $nextId) : ($s['no'] ?: $nextId); $upd('run', '', null); if ($to === '') { wag_flow_stop($pdo, $run, 'done', 'Finished.'); return; } $cur = $to; break;
            }
            case 'tag': $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) VALUES (?, ?, 'flow', ?)")->execute([$key, $s['tag'], $now]); $upd('run', '', null); $cur = $nextId; break;
            case 'assign': { $e = (int)$s['emp_id']; if ($e > 0 && (int)$c['assigned_emp'] !== $e) wa_assign_to($pdo, $key, $e, 0, 'auto'); $upd('run', '', null); $cur = $nextId; break; }
            case 'notify': { $e = (int)($s['emp_id'] ?? 0) ?: (int)$c['assigned_emp']; if ($e > 0 && function_exists('ct_push')) { try { ct_push($pdo, [$e], 'Flow alert', ($s['text'] ?? '') !== '' ? $s['text'] : ($c['name'] !== '' ? $c['name'] : '+91 ' . $key) . ' needs you.', '/my_chats.html?phone=' . $key, 'wa-fl-' . $key); } catch (Throwable $x) { } } $upd('run', '', null); $cur = $nextId; break; }
            case 'stop': wag_flow_stop($pdo, $run, 'done', 'Ended by the flow.'); return;
        }
        if ($cur === '') { wag_flow_stop($pdo, $run, 'done', 'Finished.'); return; }
    }
    wag_flow_stop($pdo, $run, 'stopped', 'Too many steps in a row (limit 25).');
}
/** Called by wa_tick: runs that are ready or whose wait is over; "no reply" triggers; a person who wrote stops the flow. */
function wag_flow_tick(PDO $pdo): int
{
    if (!wag_flow_tables($pdo)) return 0;   // no lock: each run is claimed atomically in wag_flow_advance
    $now = date('Y-m-d H:i:s'); $n = 0;
    $pdo->prepare("UPDATE wa_flow_runs SET state = 'stopped', note = 'Interrupted — please check the log.' WHERE state = 'run' AND updated_at < ?")->execute([date('Y-m-d H:i:s', time() - 600)]);
    // a person wrote while the flow waits → the person took over
    $st = $pdo->query("SELECT r.* FROM wa_flow_runs r WHERE r.state = 'wait' LIMIT 200");
    foreach ($st->fetchAll() as $r) {
        if (wag_flow_person_wrote($pdo, $r)) wag_flow_stop($pdo, $r, 'stopped', 'A person wrote to the customer, so the flow stopped.');
    }
    // no_reply triggers
    // a quiet customer belongs to ONE engine: when the Follow-ups engine sends by itself (auto modes), "no reply" flows do not start — they would message
    // the same people twice. In "off" / "suggest" the flows work as before. A person handling the chat (state human) is never nudged by a flow either.
    $fuAuto = in_array((string)setting_get($pdo, 'wa_fu_mode', 'off'), ['auto24', 'autotpl'], true);
    if (!$fuAuto) foreach ($pdo->query("SELECT * FROM wa_flows WHERE active = 1 AND trig = 'no_reply'")->fetchAll() as $f) {
        $mins = max(5, (int)$f['trig_val']); $q = $pdo->prepare("SELECT phone FROM wa_contacts WHERE status = 'open' AND optout = 0 AND state != 'human' AND last_in_at IS NOT NULL AND last_out_at IS NOT NULL AND last_out_at >= last_in_at AND last_out_at <= ? AND last_in_at >= ? ORDER BY last_out_at DESC LIMIT 15");
        $q->execute([date('Y-m-d H:i:s', time() - $mins * 60), date('Y-m-d H:i:s', time() - 30 * 86400)]); foreach ($q->fetchAll() as $c) wag_flow_start($pdo, $f, (string)$c['phone'], 'silent ' . $mins . ' min');
    }
    $st = $pdo->prepare("SELECT * FROM wa_flow_runs WHERE state = 'ready' OR (state = 'wait' AND wait_until IS NOT NULL AND wait_until <= ?) ORDER BY id LIMIT 12"); $st->execute([$now]);
    foreach ($st->fetchAll() as $r) { try { wag_flow_advance($pdo, $r); $n++; } catch (Throwable $e) { wag_flow_stop($pdo, $r, 'stopped', 'Error: ' . mb_substr($e->getMessage(), 0, 120)); wag_log($pdo, 'engine', false, 'flow run ' . $r['id'] . ': ' . $e->getMessage()); } }
    return $n;
}
/** Per flow and step: how many were reached, how many answered, how many ordered afterwards. */
function wag_flow_stats(PDO $pdo, int $flowId = 0): array
{
    $w = $flowId > 0 ? 'WHERE flow_id = ' . (int)$flowId : ''; $out = [];
    foreach ($pdo->query("SELECT flow_id, step_id, kind, COUNT(DISTINCT run_id) n FROM wa_flow_ev $w GROUP BY flow_id, step_id, kind")->fetchAll() as $r) $out[(int)$r['flow_id']]['steps'][(string)$r['step_id']][(string)$r['kind']] = (int)$r['n'];
    foreach ($pdo->query("SELECT flow_id, state, COUNT(*) n FROM wa_flow_runs " . ($flowId > 0 ? 'WHERE flow_id = ' . (int)$flowId : '') . " GROUP BY flow_id, state")->fetchAll() as $r) $out[(int)$r['flow_id']]['runs'][(string)$r['state']] = (int)$r['n'];
    $orders = wa_order_map($pdo); $q = $pdo->query("SELECT flow_id, phone, started_at FROM wa_flow_runs " . ($flowId > 0 ? 'WHERE flow_id = ' . (int)$flowId : '') . " ORDER BY id DESC LIMIT 5000"); $rr = $q->fetchAll();
    if (is_object($orders) && method_exists($orders, 'prefetch')) $orders->prefetch(array_column($rr, 'phone'));   // one query per 500 customers, not one per run
    foreach ($rr as $r) { $o = $orders[(string)$r['phone']] ?? null; if ($o && $o['last'] && strtotime((string)$o['last']) > strtotime((string)$r['started_at'])) $out[(int)$r['flow_id']]['ordered'] = ($out[(int)$r['flow_id']]['ordered'] ?? 0) + 1; }
    return $out;
}
/** The last 7 days of each flow: customers it started with, who answered a button / list / location / address, who ordered afterwards. [flow_id => [started, answered, ordered]] */
function wag_flow_last7(PDO $pdo): array
{
    $out = []; $since = date('Y-m-d H:i:s', time() - 7 * 86400);
    $q = $pdo->prepare("SELECT flow_id, COUNT(*) n FROM wa_flow_runs WHERE started_at >= ? GROUP BY flow_id"); $q->execute([$since]); foreach ($q->fetchAll() as $r) $out[(int)$r['flow_id']]['started'] = (int)$r['n'];
    $q = $pdo->prepare("SELECT flow_id, COUNT(DISTINCT run_id) n FROM wa_flow_ev WHERE kind IN ('btn', 'loc', 'addr') AND at >= ? GROUP BY flow_id"); $q->execute([$since]); foreach ($q->fetchAll() as $r) $out[(int)$r['flow_id']]['answered'] = (int)$r['n'];
    $q = $pdo->prepare("SELECT flow_id, phone, started_at FROM wa_flow_runs WHERE started_at >= ? ORDER BY id DESC LIMIT 5000"); $q->execute([$since]); $rr = $q->fetchAll();
    if ($rr) { $orders = wa_order_map($pdo); if (is_object($orders) && method_exists($orders, 'prefetch')) $orders->prefetch(array_column($rr, 'phone'));
        foreach ($rr as $r) { $o = $orders[(string)$r['phone']] ?? null; if ($o && $o['last'] && strtotime((string)$o['last']) > strtotime((string)$r['started_at'])) $out[(int)$r['flow_id']]['ordered'] = ($out[(int)$r['flow_id']]['ordered'] ?? 0) + 1; } }
    foreach ($out as $k => $v) $out[$k] = ['started' => (int)($v['started'] ?? 0), 'answered' => (int)($v['answered'] ?? 0), 'ordered' => (int)($v['ordered'] ?? 0)];
    return $out;
}
/** The last 30 campaigns (for the "reply to a campaign" picker) with the buttons of their pre-made message. $extraId = a campaign that must be in the list (the one a flow already uses). */
function wag_flow_camps(PDO $pdo, int $extraId = 0): array
{
    $out = []; $ids = [];
    try {
        foreach ($pdo->query("SELECT id, name, status FROM wa_campaigns ORDER BY id DESC LIMIT 30")->fetchAll() as $r) { $ids[(int)$r['id']] = 1; $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'status' => (string)$r['status'], 'buttons' => wag_flow_camp_buttons($pdo, (int)$r['id'])]; }
        if ($extraId > 0 && !isset($ids[$extraId])) { $q = $pdo->prepare("SELECT id, name, status FROM wa_campaigns WHERE id = ?"); $q->execute([$extraId]); if ($r = $q->fetch()) $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'status' => (string)$r['status'], 'buttons' => wag_flow_camp_buttons($pdo, (int)$r['id'])]; }
    } catch (Throwable $e) { }
    return $out;
}
/** What the Claude button says about flows: which message gets no answers, where runs are stuck, a flow that never converts. */
function wag_flow_items(PDO $pdo): array
{
    $it = []; if (!wag_flow_tables($pdo)) return $it;
    try {
        $stats = wag_flow_stats($pdo);
        foreach ($pdo->query("SELECT * FROM wa_flows WHERE active = 1")->fetchAll() as $f) {
            $st = $stats[(int)$f['id']] ?? []; $steps = wag_flow_steps($f); $total = array_sum($st['runs'] ?? []); $names = [];
            foreach ($steps as $i => $s) $names[$s['id']] = '#' . ($i + 1) . ' ' . (wag_flow_types()[$s['type']] ?? $s['type']);
            foreach ($steps as $s) {
                $e = $st['steps'][$s['id']] ?? []; $sent = (int)($e['sent'] ?? 0); $rep = (int)($e['reply'] ?? 0);
                if (in_array($s['type'], ['ask_location', 'ask_address'], true) && $sent >= 10 && $rep / $sent < 0.15) $it[] = ['level' => 'warn', 'text' => 'Flow “' . $f['name'] . '”, step ' . $names[$s['id']] . ': only ' . $rep . ' of ' . $sent . ' answered. Many people do not like to share this — ask it later, or ask in words.', 'go' => 'flows', 'needs' => [], 'on' => ['home', 'flows']];
                if (in_array($s['type'], ['buttons', 'list', 'media_buttons'], true) && $sent >= 10 && $rep / $sent < 0.15) $it[] = ['level' => 'warn', 'text' => 'Flow “' . $f['name'] . '”, step ' . $names[$s['id']] . ': only ' . $rep . ' of ' . $sent . ' answered. Shorten the question or change the buttons.', 'go' => 'flows', 'needs' => [], 'on' => ['home', 'flows']];
            }
            $blocked = (int)($st['runs']['blocked'] ?? 0); if ($blocked >= 3) $it[] = ['level' => 'bad', 'text' => 'Flow “' . $f['name'] . '” is blocked for ' . $blocked . ' customers — the 24-hour window closed before a step could send. Put a pre-made message step first, or shorten the waits.', 'go' => 'flows', 'needs' => [], 'on' => ['home', 'flows']];
            if ($total >= 30 && (int)($st['ordered'] ?? 0) === 0) $it[] = ['level' => 'warn', 'text' => 'Flow “' . $f['name'] . '” ran for ' . $total . ' customers and none ordered afterwards. Change the second message, or send the price earlier.', 'go' => 'flows', 'needs' => [], 'on' => ['home', 'flows']];
            elseif ($total >= 30) $it[] = ['level' => 'idea', 'text' => 'Flow “' . $f['name'] . '”: ' . (int)$st['ordered'] . ' of ' . $total . ' customers ordered afterwards (' . round(100 * (int)$st['ordered'] / $total) . '%). Compare with the rate without a flow before copying it.', 'go' => 'flows', 'needs' => [], 'on' => ['flows']];
        }
    } catch (Throwable $e) { }
    return $it;
}
/** Dry run: the path a customer would take, with the words filled in. Sends nothing. $reply = what the pretend customer taps / writes ("" = writes something). */
function wag_flow_dry(array $steps, string $name, string $reply, ?PDO $pdo = null, int $depth = 0): array
{
    $o = []; $cur = $steps[0]['id'] ?? ''; $n = 0; $c = ['name' => $name]; $types = wag_flow_types();
    $pick = function (array $s, string $nx) use ($reply) { return $reply !== '' && isset($s['on']['btn:' . $reply]) ? $s['on']['btn:' . $reply] : ($s['on']['reply'] ?? $nx); };
    $who = fn(string $reply) => ['t' => 'Customer', 'v' => $reply !== '' ? 'taps / writes “' . $reply . '”' : 'answers'];
    while ($cur !== '' && $n++ < 30) {
        $i = wag_flow_idx($steps, $cur); if ($i < 0) break; $s = $steps[$i]; $nx = (string)($s['next'] ?? ($steps[$i + 1]['id'] ?? '')); $t = $types[$s['type']] ?? $s['type'];
        switch ($s['type']) {
            case 'text': $o[] = ['t' => $t, 'v' => wag_flow_fill((string)$s['text'], $c)]; $cur = $nx; break;
            case 'buttons': $o[] = ['t' => $t, 'v' => wag_flow_fill((string)$s['text'], $c) . '  [' . implode('] [', $s['buttons']) . ']']; $o[] = $who($reply); $cur = $pick($s, $nx); break;
            case 'list': $o[] = ['t' => $t, 'v' => (($s['header'] ?? '') !== '' ? wag_flow_fill((string)$s['header'], $c) . ' · ' : '') . wag_flow_fill((string)$s['text'], $c) . '  ▤ ' . ($s['button'] ?? 'Choose') . ' → ' . implode(' | ', array_map(fn($r) => $r['title'] . (($r['description'] ?? '') !== '' ? ' (' . $r['description'] . ')' : ''), (array)($s['rows'] ?? [])))]; $o[] = $who($reply); $cur = $pick($s, $nx); break;
            case 'media_buttons': { $lt = ''; if ($pdo && function_exists('wa_lib_row') && ($lr = wa_lib_row($pdo, (int)($s['lib_id'] ?? 0)))) $lt = (string)$lr['title'] . ' (' . $lr['kind'] . ') · ';
                $o[] = ['t' => $t, 'v' => $lt . wag_flow_fill((string)$s['text'], $c) . '  [' . implode('] [', $s['buttons']) . ']']; $o[] = $who($reply); $cur = $pick($s, $nx); break; }
            case 'ask_location': $o[] = ['t' => $t, 'v' => wag_flow_fill((string)$s['text'], $c) . '  [Send location]']; $o[] = ['t' => 'Customer', 'v' => 'shares a location (21.1702, 72.8311)']; $cur = $s['on']['loc'] ?? ($s['on']['reply'] ?? $nx); break;
            case 'ask_address': $o[] = ['t' => $t, 'v' => wag_flow_fill((string)$s['text'], $c) . '  [Address form — or a normal question if WhatsApp refuses it]']; $o[] = ['t' => 'Customer', 'v' => 'sends the address (Rahul Shah, 12 Rose Apartment, Vesu, Surat 395007)']; $cur = $s['on']['addr'] ?? ($s['on']['reply'] ?? $nx); break;
            case 'goto_flow': {
                $tf = $pdo ? wag_flow_row($pdo, (int)($s['flow_id'] ?? 0)) : null; $o[] = ['t' => $t, 'v' => $tf ? '“' . $tf['name'] . '”' . ((int)$tf['active'] ? '' : ' — this flow is switched OFF, nothing would start') : '(flow not found)'];
                if ($tf && (int)$tf['active'] && $pdo && $depth < 2) foreach (wag_flow_dry(wag_flow_steps($tf), $name, $reply, $pdo, $depth + 1) as $x) $o[] = ['t' => '↳ ' . $x['t'], 'v' => $x['v']];
                $cur = ''; break;
            }
            case 'wait': $o[] = ['t' => $t, 'v' => $s['minutes'] . ' minutes']; $cur = $nx; break;
            case 'wait_reply': $o[] = ['t' => $t, 'v' => 'up to ' . $s['timeout'] . ' minutes']; $cur = $s['on']['reply'] ?? $nx; break;
            case 'branch': $o[] = ['t' => $t, 'v' => $s['cond'] . ' ' . $s['val']]; $cur = $s['yes'] ?: $nx; break;
            case 'stop': $o[] = ['t' => $t, 'v' => '']; $cur = ''; break;
            default: $o[] = ['t' => $t, 'v' => (string)($s['caption'] ?? $s['tag'] ?? $s['folder'] ?? '')]; $cur = $nx;
        }
    }
    return $o;
}

}   // function_exists guard

// ---------------------------------------------------------------------------------------------------------------- actions (Admin only)
$__wf = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wf, 'wa.g_flow') === 0) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth(); wa_guard_admin($pdo, $auth);
    if (!wa_tables($pdo) || !wa_more_tables($pdo) || !wag_tables($pdo) || !wag_flow_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    $me = (int)($auth['employee_id'] ?? 0); $in = $isPost ? json_input() : [];
    switch ($__wf) {
        case 'wa.g_flows': {
            $st = wag_flow_stats($pdo); $l7 = []; try { $l7 = wag_flow_last7($pdo); } catch (Throwable $e) { } $rows = []; $camps = wag_flow_camps($pdo); $cn = []; foreach ($camps as $cp) $cn[$cp['id']] = $cp;
            foreach ($pdo->query("SELECT * FROM wa_flows ORDER BY id DESC")->fetchAll() as $f) {
                $s = $st[(int)$f['id']] ?? []; $steps = wag_flow_steps($f); $cid = (int)($f['camp_id'] ?? 0);
                if ($cid > 0 && !isset($cn[$cid])) { foreach (wag_flow_camps($pdo, $cid) as $cp) $cn[$cp['id']] = $cp; if (isset($cn[$cid])) $camps[] = $cn[$cid]; }
                $rows[] = ['id' => (int)$f['id'], 'name' => $f['name'], 'trig' => $f['trig'], 'trig_val' => $f['trig_val'], 'camp_id' => $cid, 'camp_days' => (int)($f['camp_days'] ?? 3) ?: 3, 'camp_name' => $cid > 0 ? ($cn[$cid]['name'] ?? 'A deleted campaign') : '', 'camp_status' => $cn[$cid]['status'] ?? '', 'active' => (int)$f['active'] === 1, 'steps' => $steps, 'runs' => $s['runs'] ?? [], 'ordered' => (int)($s['ordered'] ?? 0), 'by_step' => $s['steps'] ?? [], 'last7' => $l7[(int)$f['id']] ?? ['started' => 0, 'answered' => 0, 'ordered' => 0], 'updated_at' => $f['updated_at']];
            }
            $tp = []; try { foreach ($pdo->query("SELECT t.id, t.name, t.category FROM wa_templates t WHERE EXISTS (SELECT 1 FROM wa_tpl_waba w WHERE w.tpl_id = t.id AND w.status = 'APPROVED') ORDER BY t.name LIMIT 100")->fetchAll() as $t) $tp[] = ['id' => (int)$t['id'], 'name' => $t['name'], 'category' => $t['category']]; } catch (Throwable $e) { }
            $lib = array_map(fn($x) => ['id' => $x['id'], 'title' => $x['title'], 'kind' => $x['kind'], 'folder' => $x['folder']], array_slice(wag_gallery($pdo)['items'], 0, 200));
            json_response(['flows' => $rows, 'types' => wag_flow_types(), 'triggers' => ['camp_kw' => 'A customer replies to a campaign', 'new_chat' => 'A new customer writes', 'ad_click' => 'A customer writes after a Meta ad', 'keyword' => 'The message contains a word', 'button' => 'A button is tapped', 'tag' => 'A tag is added', 'no_reply' => 'The customer goes silent'],
                'camps' => $camps, 'templates' => $tp, 'library' => $lib, 'folders' => wag_folders(), 'people' => array_map(fn($p) => ['emp_id' => $p['emp_id'], 'name' => $p['name']], wa_people($pdo))]);
        }
        case 'wa.g_flow_camps': { json_response(['camps' => wag_flow_camps($pdo, (int)($_GET['id'] ?? 0))]); }   // the last 30 campaigns for the "reply to a campaign" picker
        case 'wa.g_flow_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $id = (int)($in['id'] ?? 0); $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 80)); $trig = (string)($in['trig'] ?? 'new_chat'); $tv = trim(mb_substr((string)($in['trig_val'] ?? ''), 0, 120));
            $campId = (int)($in['camp_id'] ?? 0); $campDays = max(1, min(30, (int)($in['camp_days'] ?? 3)));
            if ($name === '') json_response(['error' => 'Give the flow a name.'], 422); if (!in_array($trig, ['new_chat', 'ad_click', 'keyword', 'button', 'tag', 'no_reply', 'camp_kw'], true)) json_response(['error' => 'Unknown trigger.'], 422);
            if (in_array($trig, ['keyword', 'button', 'tag', 'no_reply'], true) && $tv === '') json_response(['error' => 'Fill the trigger detail (word / button / tag / minutes).'], 422);
            if ($trig === 'camp_kw') {
                if ($campId <= 0) json_response(['error' => 'Choose the campaign this flow answers.'], 422);
                $q = $pdo->prepare("SELECT COUNT(*) FROM wa_campaigns WHERE id = ?"); $q->execute([$campId]); if ((int)$q->fetchColumn() < 1) json_response(['error' => 'That campaign was not found. Choose another one.'], 422);
                if ($tv === '') json_response(['error' => 'Write the words (or the button) that start this flow, e.g. price, rate, offer.'], 422);
            } else { $campId = 0; $campDays = 3; }
            [$steps, $bad] = wag_flow_clean((array)($in['steps'] ?? []), $pdo, $id); if ($bad) json_response(['error' => implode(' ', array_slice($bad, 0, 3))], 422); $now = date('Y-m-d H:i:s'); $js = json_encode($steps, JSON_UNESCAPED_UNICODE);
            if ($id > 0 && wag_flow_row($pdo, $id)) $pdo->prepare("UPDATE wa_flows SET name = ?, trig = ?, trig_val = ?, camp_id = ?, camp_days = ?, steps = ?, updated_at = ? WHERE id = ?")->execute([$name, $trig, $tv, $campId, $campDays, $js, $now, $id]);
            else { $pdo->prepare("INSERT INTO wa_flows (name, trig, trig_val, camp_id, camp_days, active, steps, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)")->execute([$name, $trig, $tv, $campId, $campDays, $js, $me, $now, $now]); $id = (int)$pdo->lastInsertId(); }
            json_response(['ok' => true, 'id' => $id]);
        }
        case 'wa.g_flow_toggle': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $f = wag_flow_row($pdo, (int)($in['id'] ?? 0)); if (!$f) json_response(['error' => 'Flow not found.'], 404); $on = !empty($in['on']);
            $pdo->prepare("UPDATE wa_flows SET active = ? WHERE id = ?")->execute([$on ? 1 : 0, $f['id']]);
            if (!$on) { $pdo->prepare("UPDATE wa_flow_runs SET state = 'stopped', note = 'The flow was switched off.' WHERE flow_id = ? AND state IN ('run', 'wait', 'ready')")->execute([$f['id']]); }
            wag_log($pdo, 'engine', true, 'Flow “' . $f['name'] . '” switched ' . ($on ? 'ON' : 'off')); json_response(['ok' => true]);
        }
        case 'wa.g_flow_del': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $id = (int)($in['id'] ?? 0);
            $pdo->prepare("DELETE FROM wa_flow_runs WHERE flow_id = ? AND state IN ('run', 'wait', 'ready', 'blocked')")->execute([$id]); $pdo->prepare("DELETE FROM wa_flows WHERE id = ?")->execute([$id]); json_response(['ok' => true]);
        }
        case 'wa.g_flow_test': {   // dry run: shows the path; sends nothing
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); [$steps, $bad] = wag_flow_clean((array)($in['steps'] ?? []), $pdo, (int)($in['id'] ?? 0));
            json_response(['ok' => true, 'path' => wag_flow_dry($steps, (string)($in['name'] ?? 'Rahul Shah'), trim((string)($in['reply'] ?? '')), $pdo), 'problems' => $bad]);
        }
        case 'wa.g_flow_cancel': {   // a person takes over: stop every running / waiting flow of this customer
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $ph = wa_key((string)($in['phone'] ?? '')); if ($ph === '') json_response(['error' => 'Which customer?'], 422);
            json_response(['ok' => true, 'stopped' => wag_flow_cancel($pdo, $ph)]);
        }
        case 'wa.g_flow_stats': { json_response(['stats' => wag_flow_stats($pdo, (int)($_GET['id'] ?? 0))]); }
        case 'wa.g_flow_runs': {
            $q = $pdo->prepare("SELECT r.id, r.phone, r.state, r.step_id, r.note, r.started_at, r.updated_at, c.name FROM wa_flow_runs r LEFT JOIN wa_contacts c ON c.phone = r.phone WHERE r.flow_id = ? ORDER BY r.id DESC LIMIT 40"); $q->execute([(int)($_GET['id'] ?? 0)]); json_response(['runs' => $q->fetchAll()]);
        }
    }
    json_response(['error' => 'Unknown flow action.'], 404);
}
