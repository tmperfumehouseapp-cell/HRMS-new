<?php
/**
 * wa_learn.php — the shared brain for the WhatsApp console. Loaded by wa_more.php (no change to api.php).
 *
 *  • Suggested replies come from the SAME approved guidebook, owner corrections, teacher lessons and store facts that the Practice bot uses
 *    (api.php: lib_guide_row('live') + lib_bot_reply). Without an approved guidebook the older generic suggestion is used.
 *  • Every reply a person sends is compared with the suggestion that was shown for that customer message:
 *        used  = sent as suggested          edited = changed a little          own = wrote something else (or no suggestion existed)
 *  • "edited" and "own" replies wait in a queue (Learning). An Admin taps "Teach the bot" → the reply is saved as a correction in lib_feedback,
 *    and the NEXT guidebook draft learns from it after the Admin approves that draft in Learn & Setup. Nothing changes by itself.
 *
 *   wa.learn_list  GET   {st: new|taught|skipped}   the queue + counts                                [Admin / HR]
 *   wa.learn_act   POST  {id, act: teach|skip, text?}   save a correction / skip it                   [Admin / HR]
 */
if (!function_exists('wa_learn_tables')) {

function wa_learn_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_sugg (phone VARCHAR(12) NOT NULL PRIMARY KEY, in_id BIGINT NOT NULL DEFAULT 0, body TEXT NULL, source VARCHAR(8) NOT NULL DEFAULT 'basic',
            ver INT NOT NULL DEFAULT 0, created_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_learn (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, in_id BIGINT NOT NULL DEFAULT 0, customer TEXT NULL, sugg TEXT NULL, sent TEXT NULL,
            kind VARCHAR(6) NOT NULL DEFAULT 'own', emp_id INT NOT NULL DEFAULT 0, ver INT NOT NULL DEFAULT 0, status VARCHAR(6) NOT NULL DEFAULT 'new', created_at DATETIME NULL, KEY k_st (status, id),
            KEY k_pi (phone, in_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
/** Auto-reply (the suggested reply counts down in the inbox and sends itself): the admin's master switch. Off until an admin turns it on. */
function wa_auto_state(PDO $pdo): array
{
    return ['on' => (string)setting_get($pdo, 'wa_autosend', '0') === '1', 'secs' => max(5, min(60, (int)setting_get($pdo, 'wa_autosend_secs', '20') ?: 20))];
}
function wa_learn_clip(string $s, int $n): string { $s = trim($s); return function_exists('lib_clip') ? lib_clip($s, $n) : mb_substr($s, 0, $n); }
function wa_learn_norm(string $s): string { $s = mb_strtolower($s); $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s; return trim(preg_replace('/\s+/', ' ', $s) ?? $s); }

/** The chat as lib_bot_reply wants it: [{who: customer|bot, text}] oldest first. */
function wa_learn_msgs(PDO $pdo, string $key, int $n = 25): array
{
    $st = $pdo->prepare("SELECT dir, body, mtype FROM wa_messages WHERE phone = ? AND dir != 'sys' ORDER BY id DESC LIMIT " . (int)$n); $st->execute([$key]); $out = [];
    foreach (array_reverse($st->fetchAll()) as $r) {
        $t = trim((string)$r['body']); if ($t === '') $t = '[' . ((string)$r['mtype'] !== '' ? $r['mtype'] : 'attachment') . ']';
        $out[] = ['who' => $r['dir'] === 'in' ? 'customer' : 'bot', 'text' => mb_substr($t, 0, 600)];
    }
    return $out;
}
function wa_last_in(PDO $pdo, string $key): array
{
    $q = $pdo->prepare("SELECT id, body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $q->execute([$key]); $r = $q->fetch();
    return $r ? [(int)$r['id'], (string)$r['body']] : [0, ''];
}
function wa_sugg_save(PDO $pdo, string $key, string $text, string $source, int $ver): void
{
    if (!wa_learn_tables($pdo)) return; [$inId] = wa_last_in($pdo, $key); if ($inId === 0) return;
    try { $pdo->prepare("DELETE FROM wa_sugg WHERE phone = ?")->execute([$key]);
        $pdo->prepare("INSERT INTO wa_sugg (phone, in_id, body, source, ver, created_at) VALUES (?, ?, ?, ?, ?, ?)")->execute([$key, $inId, mb_substr($text, 0, 1500), $source, $ver, date('Y-m-d H:i:s')]); } catch (PDOException $e) { }
}

/** Suggestion from the approved guidebook. Sends the JSON answer itself and exits when it works; returns false when it cannot (the caller then uses the generic suggestion). */
function wa_suggest_guided(PDO $pdo, array $c, string $key, array $in): bool
{
    if (!function_exists('lib_guide_row') || !function_exists('lib_bot_reply') || !function_exists('ensure_lib_tables')) return false;
    try { if (!ensure_lib_tables($pdo)) return false; $g = lib_guide_row($pdo, 'live'); } catch (Throwable $e) { return false; }
    if (!$g) {   // no approved guidebook yet: if the owner has taught anything (rules, limits, lessons, facts), the SAME brain answers from that alone
        require_once __DIR__ . '/wa_hist.php'; $taught = false;
        try { $taught = (function_exists('teach_facts_prompt') && teach_facts_prompt($pdo, [])[2]) || (function_exists('teach_limits_text') && teach_limits_text($pdo) !== '')
            || (function_exists('ensure_teach_table') && ensure_teach_table($pdo) && (int)$pdo->query("SELECT COUNT(*) FROM lib_teach WHERE status = 'taught'")->fetchColumn() > 0); } catch (Throwable $e) { }
        if (!$taught) return false;
        $g = ['body' => json_encode(lib_stub_guide(), JSON_UNESCAPED_UNICODE), 'version' => 0];
    }
    $body = json_decode((string)$g['body'], true); if (!is_array($body) || !$body) return false;
    $msgs = wa_learn_msgs($pdo, $key); if (!$msgs) return false;
    $avoid = trim(mb_substr((string)($in['avoid'] ?? ''), 0, 500)); if ($avoid !== '') $msgs[] = ['who' => 'bot', 'text' => $avoid];   // "Another": the bot sees it already said that
    ignore_user_abort(true);
    try { [$ok, $v] = lib_bot_reply($pdo, $body, $msgs); } catch (Throwable $e) { return false; }
    if (!$ok || !is_array($v) || trim((string)($v['reply'] ?? '')) === '') return false;
    require_once __DIR__ . '/wa_hist.php'; $v = learn_enforce_v($pdo, $v);                                    // the owner's hard limits are checked in code, whatever the AI wrote
    if (!empty($v['handoff'])) { $lc = ''; foreach (array_reverse($msgs) as $m) if ($m['who'] === 'customer') { $lc = $m['text']; break; } learn_gap_log($pdo, 'unsure', $lc, (string)($v['handoff_reason'] ?? '')); }   // what the bot did not know → Learning & Improvement
    $text = mb_substr(trim((string)$v['reply']), 0, 1500); $ver = (int)$g['version'];
    wa_sugg_save($pdo, $key, $text, 'guide', $ver);
    json_response(['ok' => true, 'suggestion' => $text, 'why' => mb_substr(trim((string)($v['why'] ?? '')), 0, 300), 'call_now' => !empty($v['handoff']), 'handoff' => !empty($v['handoff']),
        'handoff_reason' => mb_substr(trim((string)($v['handoff_reason'] ?? '')), 0, 120), 'source' => 'guide', 'ver' => $ver,
        'learned' => ['lessons' => (int)($v['lessons'] ?? 0), 'limits' => function_exists('teach_limits_text') && teach_limits_text($pdo) !== '', 'rules' => function_exists('teach_facts_get') && trim((string)(teach_facts_get($pdo)['style'] ?? '')) !== '', 'blocked' => array_values((array)($v['blocked'] ?? []))]]);
    return true;
}

/** Called after a person's text reply went out (wa.send). Records used / edited / own against the suggestion shown for that customer message. Never blocks a send. */
function wa_learn_log(PDO $pdo, array $c, string $text, int $empId): void
{
    try {
        if (!wa_learn_tables($pdo)) return; $key = (string)$c['phone']; $text = trim($text); if (mb_strlen($text) < 4) return;
        [$inId] = wa_last_in($pdo, $key); if ($inId === 0) return;
        $ex = $pdo->prepare("SELECT id, sent FROM wa_learn WHERE phone = ? AND in_id = ? LIMIT 1"); $ex->execute([$key, $inId]); $row = $ex->fetch();
        if ($row) {   // a second / third message answering the same customer message: keep them together
            $all = trim((string)$row['sent'] . "\n" . $text); if (mb_strlen($all) <= 900 && substr_count($all, "\n") < 3) $pdo->prepare("UPDATE wa_learn SET sent = ? WHERE id = ?")->execute([wa_learn_clip($all, 900), (int)$row['id']]);
            return;
        }
        $sg = $pdo->prepare("SELECT body, ver FROM wa_sugg WHERE phone = ? AND in_id = ?"); $sg->execute([$key, $inId]); $s = $sg->fetch();
        $sugg = $s ? (string)$s['body'] : ''; $ver = $s ? (int)$s['ver'] : 0; $kind = 'own';
        if ($sugg !== '') {
            $a = wa_learn_norm($sugg); $b = wa_learn_norm($text);
            if ($a === $b) $kind = 'used'; else { similar_text($a, $b, $pct); if ($pct >= 80) $kind = 'edited'; }
        }
        $prev = $pdo->prepare("SELECT MAX(id) FROM wa_messages WHERE phone = ? AND dir = 'out' AND id < ?"); $prev->execute([$key, $inId]); $from = (int)$prev->fetchColumn();
        $cm = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' AND id > ? AND id <= ? ORDER BY id"); $cm->execute([$key, $from, $inId]);
        $cust = trim(implode("\n", array_map(fn($r) => trim((string)$r['body']), $cm->fetchAll())));
        $pdo->prepare("INSERT INTO wa_learn (phone, in_id, customer, sugg, sent, kind, emp_id, ver, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$key, $inId, wa_learn_clip($cust, 600), wa_learn_clip($sugg, 900), wa_learn_clip($text, 900), $kind, $empId, $ver, $kind === 'used' ? 'done' : 'new', date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { /* learning must never break sending */ }
}
}

$__wl2 = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wl2, ['wa.learn_list', 'wa.learn_act', 'wa.learn_groups', 'wa.learn_group_act'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $auth = require_auth(); wa_guard_admin($pdo, $auth);
    if (!wa_tables($pdo) || !wa_learn_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    $names = employee_names_by_id($pdo);
    switch ($__wl2) {
        case 'wa.learn_list': {
            $st = (string)($_GET['st'] ?? 'new'); if (!in_array($st, ['new', 'taught', 'skipped'], true)) $st = 'new'; $since = date('Y-m-d H:i:s', time() - 30 * 86400);
            $cnt = ['used' => 0, 'edited' => 0, 'own' => 0];
            $q = $pdo->prepare("SELECT kind, COUNT(*) AS n FROM wa_learn WHERE created_at >= ? GROUP BY kind"); $q->execute([$since]); foreach ($q->fetchAll() as $r) if (isset($cnt[$r['kind']])) $cnt[$r['kind']] = (int)$r['n'];
            $waiting = (int)$pdo->query("SELECT COUNT(*) FROM wa_learn WHERE status = 'new'")->fetchColumn();
            $q = $pdo->prepare("SELECT l.*, c.name AS cname FROM wa_learn l LEFT JOIN wa_contacts c ON c.phone = l.phone WHERE l.status = ? ORDER BY l.id DESC LIMIT 60"); $q->execute([$st]); $items = [];
            foreach ($q->fetchAll() as $r) $items[] = ['id' => (int)$r['id'], 'phone' => (string)$r['phone'], 'name' => (string)($r['cname'] ?? ''), 'customer' => (string)$r['customer'], 'sugg' => (string)$r['sugg'], 'sent' => (string)$r['sent'],
                'kind' => (string)$r['kind'], 'by' => (string)($names[(int)$r['emp_id']] ?? ''), 'at' => (string)$r['created_at'], 'ver' => (int)$r['ver']];
            $live = null; if (function_exists('lib_guide_row') && function_exists('ensure_lib_tables')) { try { if (ensure_lib_tables($pdo)) { $g = lib_guide_row($pdo, 'live'); if ($g) $live = (int)$g['version']; } } catch (Throwable $e) { } }
            $proofs = 0; try { $pq = $pdo->prepare("SELECT COUNT(*) FROM convo_uploads WHERE note = 'WhatsApp (new number)' AND created_at >= ?"); $pq->execute([$since]); $proofs = (int)$pq->fetchColumn(); } catch (Throwable $e) { }
            json_response(['counts' => $cnt, 'waiting' => $waiting, 'items' => $items, 'guide' => $live, 'proofs' => $proofs]);
        }
        // 🧩 Group the waiting replies: instead of 500 cards one by one, Claude puts them into a few situations ("these 12 are about price"), with what the customers wrote
        // and what the team did. The owner answers ONE general reply per group → it goes to the Teach board (a topic; unclear parts become questions) and every reply in the group leaves the queue.
        case 'wa.learn_groups': {
            if (!$isPost) { $c = json_decode((string)setting_get($pdo, 'wa_learn_groups', ''), true); $c = is_array($c) ? $c : ['groups' => [], 'at' => ''];
                if ($c['groups']) { $ids = []; foreach ($c['groups'] as $g) foreach ($g['ids'] as $x) $ids[] = (int)$x; $left = [];
                    if ($ids) { $q = $pdo->query("SELECT id FROM wa_learn WHERE status = 'new' AND id IN (" . implode(',', array_map('intval', $ids)) . ")"); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $x) $left[(int)$x] = true; }
                    $c['groups'] = array_values(array_filter(array_map(function ($g) use ($left) { $g['ids'] = array_values(array_filter($g['ids'], fn($x) => isset($left[(int)$x]))); $g['n'] = count($g['ids']); return $g; }, $c['groups']), fn($g) => $g['n'] > 0)); }
                json_response($c + ['waiting' => (int)$pdo->query("SELECT COUNT(*) FROM wa_learn WHERE status = 'new'")->fetchColumn()]); }
            $rows = $pdo->query("SELECT id, customer, sugg, sent FROM wa_learn WHERE status = 'new' ORDER BY id DESC LIMIT 160")->fetchAll();
            if (!$rows) json_response(['groups' => [], 'at' => date('Y-m-d H:i:s'), 'waiting' => 0]);
            $tops = []; try { $bq = $pdo->query("SELECT body FROM lib_facts WHERE k = 'ivboard'"); $bj = json_decode((string)($bq ? $bq->fetchColumn() : ''), true); foreach ((array)($bj['topics'] ?? []) as $t0) if (!empty($t0['id'])) $tops[(string)$t0['id']] = (string)($t0['title'] ?? ''); } catch (Throwable $e) { }
            $topT = ''; foreach ($tops as $i0 => $t0) $topT .= $i0 . ' | ' . $t0 . "\n";
            $lines = []; foreach ($rows as $r) $lines[] = '#' . (int)$r['id'] . ' | customer: ' . wa_learn_clip(str_replace("\n", ' / ', (string)$r['customer']), 160) . ' | bot suggested: ' . wa_learn_clip(str_replace("\n", ' / ', (string)$r['sugg']), 140) . ' | team sent: ' . wa_learn_clip(str_replace("\n", ' / ', (string)$r['sent']), 160);
            $sys = "You help the owner of TM Perfume House teach the WhatsApp bot. Below are replies where the sales team did NOT use the bot's suggestion (customer message, bot suggestion, what the team actually sent). "
                 . "Put them into 4–12 GROUPS by situation (for example 'Asks price of 2 × 50 ml', 'Asks where the order was bought', 'Wants options / photos', 'Greeting only'). Every id goes into exactly one group; replies that teach nothing (ok, thanks, a name, a number only) go into one group titled 'Nothing to learn'. "
                 . "For each group: title (max 6 words); ids; customers = 1–2 sentences: what customers wrote, with 1–2 real examples in quotes; team = 1–2 sentences: what the team decided to do / say instead, with 1 real example; bot = 1 sentence: what the bot did wrong or differently; "
                 . "question = ONE general question to the owner so his single answer covers the whole group; suggest = your suggested general answer from what the team did (1–2 sentences); "
                 . "tid = the id of the OPEN TEACH TOPIC below this situation belongs to (same subject), or '' when none fits — then it becomes a new topic. Answer {\"groups\":[{\"title\":\"\",\"ids\":[1],\"customers\":\"\",\"team\":\"\",\"bot\":\"\",\"question\":\"\",\"suggest\":\"\",\"tid\":\"\"}]}.";
            $j = function_exists('brain_ai_json') ? brain_ai_json('internal', 'whatsapp', $sys, "OPEN TEACH TOPICS (id | title):\n" . ($topT !== '' ? $topT : "(none)\n") . "\nREPLIES:\n" . implode("\n", $lines), 6000, ['ctx' => false]) : null;
            if (!is_array($j)) json_response(['error' => 'Claude could not group the replies right now — try again in a minute.'], 502);
            $valid = []; foreach ($rows as $r) $valid[(int)$r['id']] = true; $used = []; $G = [];
            foreach (array_slice((array)($j['groups'] ?? []), 0, 14) as $g) { if (!is_array($g)) continue; $ids = [];
                foreach ((array)($g['ids'] ?? []) as $x) { $x = (int)$x; if (isset($valid[$x]) && !isset($used[$x])) { $used[$x] = true; $ids[] = $x; } } if (!$ids) continue;
                $G[] = ['gid' => 'g' . substr(md5(implode(',', $ids)), 0, 8), 'title' => wa_learn_clip((string)($g['title'] ?? 'Group'), 60), 'ids' => $ids, 'n' => count($ids), 'customers' => wa_learn_clip((string)($g['customers'] ?? ''), 400), 'team' => wa_learn_clip((string)($g['team'] ?? ''), 400),
                    'bot' => wa_learn_clip((string)($g['bot'] ?? ''), 300), 'question' => wa_learn_clip((string)($g['question'] ?? ''), 300), 'suggest' => wa_learn_clip((string)($g['suggest'] ?? ''), 400), 'tid' => isset($tops[(string)($g['tid'] ?? '')]) ? (string)$g['tid'] : '', 'topic' => $tops[(string)($g['tid'] ?? '')] ?? '', 'nothing' => (bool)preg_match('/nothing to learn/i', (string)($g['title'] ?? ''))]; }
            usort($G, fn($a, $b) => [$a['nothing'], -$a['n']] <=> [$b['nothing'], -$b['n']]);
            $c = ['groups' => $G, 'at' => date('Y-m-d H:i:s'), 'read' => count($rows)]; setting_set($pdo, 'wa_learn_groups', json_encode($c, JSON_UNESCAPED_UNICODE));
            json_response($c + ['waiting' => (int)$pdo->query("SELECT COUNT(*) FROM wa_learn WHERE status = 'new'")->fetchColumn()]);
        }
        case 'wa.learn_group_act': {   // a whole group: 'board' (its general answer went to the Teach board) or 'skip' (not a rule) — every reply in it leaves the queue
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? [])))); $act = (string)($in['act'] ?? '');
            if (!$ids || !in_array($act, ['board', 'skip'], true)) json_response(['error' => 'Nothing to do.'], 422);
            $st = $act === 'skip' ? 'skipped' : 'taught';
            $u = $pdo->prepare("UPDATE wa_learn SET status = ? WHERE status = 'new' AND id IN (" . implode(',', $ids) . ")"); $u->execute([$st]);
            require_once __DIR__ . '/wa_hist.php'; learn_hist_add($pdo, ($act === 'skip' ? 'Skipped ' : 'Sent to the Teach board ') . $u->rowCount() . ' WhatsApp replies as one group: ' . wa_learn_clip((string)($in['title'] ?? ''), 60));
            json_response(['ok' => true, 'done' => $u->rowCount()]);
        }
        case 'wa.learn_act': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $id = (int)($in['id'] ?? 0); $act = (string)($in['act'] ?? ''); $q = $pdo->prepare("SELECT * FROM wa_learn WHERE id = ?"); $q->execute([$id]); $r = $q->fetch();
            if (!$r) json_response(['error' => 'Not found.'], 404); if (!in_array($act, ['teach', 'skip', 'board'], true)) json_response(['error' => 'Choose Teach or Skip.'], 422);
            if ($act === 'skip') { $pdo->prepare("UPDATE wa_learn SET status = 'skipped' WHERE id = ?")->execute([$id]); json_response(['ok' => true]); }
            if ($act === 'board') { $pdo->prepare("UPDATE wa_learn SET status = 'taught' WHERE id = ?")->execute([$id]); require_once __DIR__ . '/wa_hist.php'; learn_hist_add($pdo, 'Sent a WhatsApp reply to the Teach board (' . (string)($names[(int)($auth['employee_id'] ?? 0)] ?? 'Admin') . ')'); json_response(['ok' => true]); }   // the one Teach board decides (its topic saves the rule)
            $better = trim((string)($in['text'] ?? '')); if ($better === '') $better = (string)$r['sent'];
            if (!function_exists('ensure_lib_tables') || !ensure_lib_tables($pdo)) json_response(['error' => 'The Learn & Setup storage is not available.'], 500);
            $g = function_exists('lib_guide_row') ? lib_guide_row($pdo, 'live') : null; $who = (string)($names[(int)($auth['employee_id'] ?? 0)] ?? 'Admin');
            $pdo->prepare("INSERT INTO lib_feedback (guide_version, rating, tag, customer, reply, better, stage, src, by_name, created_at) VALUES (?, -1, 'other', ?, ?, ?, '', 'wa', ?, ?)")
                ->execute([$g ? (int)$g['version'] : 0, wa_learn_clip((string)$r['customer'], 600), wa_learn_clip((string)$r['sugg'], 900), wa_learn_clip($better, 900), $who, date('Y-m-d H:i:s')]);
            // also an "own" lesson in Learn & Setup > Teach: lessons reach the bot at once (the correction above only goes live with the next approved guidebook)
            $lesson = false; $cust = wa_learn_clip((string)$r['customer'], 600);
            if ($cust !== '' && function_exists('ensure_teach_table') && function_exists('teach_norm') && teach_norm($cust) !== '') {
                try { if (ensure_teach_table($pdo)) { $k = md5('own|' . teach_norm($cust)); $ex = $pdo->prepare("SELECT id FROM lib_teach WHERE grp = 'own' AND k = ?"); $ex->execute([$k]); $lid = (int)$ex->fetchColumn(); $rm = 'From a WhatsApp reply';
                    if ($lid) $pdo->prepare("UPDATE lib_teach SET customer = ?, sales_reply = ?, bot_reply = ?, better = ?, remark = ?, status = 'taught', by_name = ?, updated_at = NOW() WHERE id = ?")->execute([$cust, wa_learn_clip((string)$r['sent'], 700), wa_learn_clip((string)$r['sugg'], 900), wa_learn_clip($better, 1600), $rm, $who, $lid]);
                    else $pdo->prepare("INSERT INTO lib_teach (grp, k, customer, sales_reply, bot_reply, better, remark, status, by_name, ctx, scen, created_at, updated_at) VALUES ('own', ?, ?, ?, ?, ?, ?, 'taught', ?, '', '', NOW(), NOW())")->execute([$k, $cust, wa_learn_clip((string)$r['sent'], 700), wa_learn_clip((string)$r['sugg'], 900), wa_learn_clip($better, 1600), $rm, $who]);
                    $lesson = true; } } catch (Throwable $e) { }
            }
            $pdo->prepare("UPDATE wa_learn SET status = 'taught' WHERE id = ?")->execute([$id]); require_once __DIR__ . '/wa_hist.php'; learn_hist_add($pdo, 'Taught the bot from a WhatsApp reply (' . $who . ')' . ($lesson ? ' - live now as a lesson' : '')); json_response(['ok' => true, 'live' => $lesson]);
        }
    }
}

$__wa = (string)($action ?? ($_GET['action'] ?? ''));
if ($__wa === 'wa.autosend') {   // POST {on, secs?}  → the master switch for auto-reply  [Admin / HR]
    $auth = require_auth(); wa_guard_admin($pdo, $auth);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); setting_set($pdo, 'wa_autosend', !empty($in['on']) ? '1' : '0');
    if (isset($in['secs'])) setting_set($pdo, 'wa_autosend_secs', (string)max(5, min(60, (int)$in['secs'])));
    json_response(['ok' => true] + wa_auto_state($pdo));
}
