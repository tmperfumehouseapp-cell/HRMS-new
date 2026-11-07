<?php
/**
 * wa_diag.php — 🩺 Diagnose a campaign: WHY did it fail? Facts only, read from this HRMS database and asked LIVE from Meta.
 *
 *   wa.camp_diagnose GET {id}  →  { verdict: [ {level, title, detail, fix} ], facts: { campaign, template, numbers, outcome, failures, webhook, audience }, report: "plain text to copy" }
 *
 * What it checks
 *   1. the campaign      status, start, send-time mode, retries, note
 *   2. the message       our copy (category, header photo/video, body length, buttons) + Meta's LIVE status, category and quality per WhatsApp account
 *   3. the numbers       Meta's LIVE connection status, quality, messaging limit, display-name approval, webhook subscription; HRMS sending room
 *   4. the outcome       sent / delivered / read / failed / retry / skipped / waiting, and each number used
 *   5. the failures      every error code Meta gave (on the person, on the message, in the raw answers, in the webhook log), grouped
 *   6. the webhook       did Meta's delivery reports reach HRMS around the send time, and what did they say
 *   7. the audience      already messaged by a campaign in the 7 days before, opted out, never wrote to us, odd numbers
 * Then a verdict built from those facts — the biggest cause first. Loaded by wa_more.php (before wa_seg.php). PHP 7.4 safe. Admin only.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wa_diag_codes')) {

/** Meta error code → [what it means, what to do]. */
function wa_diag_codes(): array
{
    return [
        131049 => ['Meta held the message back to protect the customer from too many marketing messages ("healthy ecosystem" limit). This is per customer, across ALL brands, not your tier.', 'Send to customers who wrote to you recently, wait 1–2 days between campaigns to the same people, and prefer a utility message where it is truly utility.'],
        131026 => ['The number cannot receive WhatsApp messages (not on WhatsApp, very old app, or it blocked business messages).', 'Clean the list: these numbers will keep failing.'],
        131050 => ['The customer turned off marketing messages from your business.', 'Do not send marketing to them; they still get replies and utility messages.'],
        131047 => ['More than 24 hours since the customer wrote — only an approved template can go.', 'This happens when a plain text is sent outside the window.'],
        131048 => ['Meta flagged the sends as spam (too many blocks / reports).', 'Pause, change the message and the audience, send only to people who know you.'],
        131056 => ['Too many messages to the same customer in a short time.', 'Space them out.'],
        130429 => ['Sent too fast for Meta.', 'Lower the speed.'],
        131042 => ['A payment problem on the WhatsApp account (no card / failed charge).', 'Fix the payment method in Meta Business Settings → Billing.'],
        131031 => ['The WhatsApp account is locked or restricted.', 'Check Meta Business Support / Account quality.'],
        131053 => ['The photo / video of the message could not be uploaded by Meta.', 'Use a smaller JPG/MP4 (photo under 5 MB, video under 16 MB) that opens by a public link.'],
        131052 => ['The photo / video could not be downloaded.', 'Same as above.'],
        132000 => ['The number of variables does not match the approved message.', 'Fix the variables of the campaign.'],
        132001 => ['The message (template) does not exist in that language on that account.', 'Use the exact approved name and language.'],
        132005 => ['The text is too long after filling the variables.', 'Shorten the variables.'],
        132007 => ['The text broke Meta’s message rules.', 'Change the wording.'],
        132012 => ['A variable has the wrong format (for example a photo link where text is expected).', 'Fix the variables.'],
        132015 => ['Meta PAUSED this message for low quality (people blocked / reported it).', 'Make a new version of the message; do not resend the paused one.'],
        132016 => ['Meta DISABLED this message (paused too many times).', 'Make a new message.'],
        131000 => ['Meta had an internal problem.', 'Retry later.'],
        131016 => ['Meta was busy.', 'Retry later.'],
        135000 => ['Generic Meta error — usually the media link or the message format.', 'Check the photo / video link and the variables.'],
        131008 => ['A required field is missing in the request.', 'A code problem in the sender — report it.'],
        131009 => ['A field has a wrong value.', 'A code problem in the sender — report it.'],
        100 => ['Wrong parameter in the request.', 'A code problem in the sender — report it.'],
        190 => ['The access token is expired or invalid.', 'Make a new permanent token and put it in config.php (WA_TOKEN).'],
        368 => ['Meta blocked the account temporarily for policy reasons.', 'Check Account quality in Meta Business Manager.'],
        130472 => ['The customer is in a Meta test group.', 'Nothing to do.'],
        131021 => ['You sent to your own number.', 'Remove it from the list.'],
        131051 => ['Message type not supported.', 'Check the message.'],
    ];
}
function wa_diag_q(PDO $pdo, string $sql, array $a = []): array { try { $q = $pdo->prepare($sql); $q->execute($a); return $q->fetchAll(); } catch (Throwable $e) { return []; } }
function wa_diag_v(PDO $pdo, string $sql, array $a = [], $d = null) { try { $q = $pdo->prepare($sql); $q->execute($a); $v = $q->fetchColumn(); return $v === false ? $d : $v; } catch (Throwable $e) { return $d; } }

function wa_camp_diagnose(PDO $pdo, int $id): array
{
    $c = wa_camp_row($pdo, $id); if (!$c) return ['error' => 'Campaign not found.'];
    $V = []; $F = []; $codes = wa_diag_codes();
    $add = function (string $lvl, string $title, string $detail = '', string $fix = '') use (&$V) { $V[] = ['level' => $lvl, 'title' => $title, 'detail' => $detail, 'fix' => $fix]; };
    $start = (string)($c['started_at'] ?: ($c['created_at'] ?? '')); $startTs = $start !== '' ? (int)strtotime($start) : time() - 86400;

    // 1. campaign
    $F['campaign'] = ['id' => $id, 'name' => (string)$c['name'], 'status' => (string)$c['status'], 'started_at' => $start, 'note' => (string)($c['note'] ?? ''),
        'send_time' => (int)($c['own_hour'] ?? 0) === 1 ? 'each person at their best hour' : 'everyone together', 'retry' => (int)($c['retry'] ?? 0) === 1, 'audience_src' => (string)($c['audience']['src'] ?? 'contacts')];

    // 2. the message: our copy + Meta live
    $t = wa_diag_q($pdo, "SELECT * FROM wa_templates WHERE id = ?", [(int)$c['tpl_id']]); $t = $t[0] ?? null;
    $T = ['found' => (bool)$t];
    if ($t) {
        $btn = json_decode((string)($t['buttons'] ?? ''), true);
        $T += ['name' => (string)$t['name'], 'language' => (string)$t['language'], 'category' => (string)$t['category'], 'category_now' => (string)($t['category_now'] ?? ''), 'header' => (string)$t['header_format'] ?: 'none',
            'body_chars' => mb_strlen((string)$t['body']), 'variables' => preg_match_all('/\{\{\d+\}\}/', (string)$t['body']), 'buttons' => is_array($btn) ? count($btn) : 0, 'campaign_media' => (int)($c['media_lib_id'] ?? 0) > 0, 'accounts' => []];
        foreach (wa_diag_q($pdo, "SELECT * FROM wa_tpl_waba WHERE tpl_id = ?", [(int)$t['id']]) as $w) {
            $acc = ['waba' => (string)$w['waba_id'], 'hrms_status' => (string)$w['status'], 'hrms_reason' => (string)($w['reason'] ?? ''), 'meta_id' => (string)$w['meta_id']];
            if ((string)$w['meta_id'] !== '' && function_exists('wa_api')) {
                [$cd, $d] = wa_api('GET', '/' . $w['meta_id'] . '?fields=name,status,category,quality_score,rejected_reason,language');
                if ($cd >= 200 && $cd < 300 && is_array($d)) { $acc['meta_status'] = (string)($d['status'] ?? ''); $acc['meta_category'] = (string)($d['category'] ?? ''); $acc['meta_quality'] = (string)($d['quality_score']['score'] ?? ''); $acc['meta_rejected'] = (string)($d['rejected_reason'] ?? ''); }
                else $acc['meta_error'] = function_exists('wa_err') ? wa_err(is_array($d) ? $d : null, 'Meta did not answer') : 'Meta did not answer';
            }
            $T['accounts'][] = $acc;
            $ms = strtoupper((string)($acc['meta_status'] ?? $acc['hrms_status']));
            if ($ms !== '' && $ms !== 'APPROVED') $add('bad', 'The message "' . $t['name'] . '" is ' . $ms . ' at Meta on account ' . $acc['waba'], $acc['meta_rejected'] ?? $acc['hrms_reason'], 'Only an APPROVED message can be sent. Make a new version and get it approved.');
            if (!empty($acc['meta_category']) && strtoupper($acc['meta_category']) !== strtoupper((string)$t['category'])) $add('warn', 'Meta re-labelled the message from ' . $t['category'] . ' to ' . $acc['meta_category'], 'It is charged and limited as ' . $acc['meta_category'] . '.', '');
            if (in_array(strtoupper((string)($acc['meta_quality'] ?? '')), ['RED', 'LOW'], true)) $add('bad', 'The message has LOW quality at Meta (people block or report it)', '', 'Change the wording / offer and the audience; Meta pauses low-quality messages.');
        }
        if (!$T['accounts']) $add('bad', 'The message was never submitted to Meta for any WhatsApp account', '', 'Submit it in Pre-made messages and wait for approval.');
    } else $add('bad', 'The campaign’s message no longer exists in HRMS', '', 'Pick an approved message.');
    $F['template'] = $T;

    // 3. numbers: Meta live
    $N = []; $health = function_exists('wa_health_all') ? wa_health_all($pdo, true) : [];
    $caps = function_exists('wa_number_caps') ? wa_number_caps($pdo, $health) : [];
    foreach ($health as $h) {
        $cp = $caps[(int)$h['id']] ?? [];
        $N[] = ['id' => (int)$h['id'], 'label' => (string)$h['label'], 'display' => (string)($h['display'] ?? ''), 'status' => (string)$h['status'], 'quality' => (string)$h['quality'], 'limit' => $h['tier'], 'name_status' => (string)($h['name_status'] ?? ''),
            'webhook_subscribed' => $h['subscribed'], 'meta_error' => (string)($h['error'] ?? ''), 'room_left' => $cp['left'] ?? null, 'room_why' => $cp['why'] ?? ''];
        $usedN = array_map('intval', (array)($c['extra']['numbers'] ?? [])); $mine = !$usedN || in_array((int)$h['id'], $usedN, true);   // a number this campaign does not send from is listed below, not a problem of this campaign
        if (($h['status'] ?? '') !== '' && $h['status'] !== 'CONNECTED' && $mine) $add('warn', 'Number ' . $h['label'] . ' is ' . $h['status'] . ' at Meta', 'It cannot send until it is CONNECTED.', 'Finish its setup in WhatsApp Manager → Phone numbers.');
        if (($h['quality'] ?? '') === 'RED') $add('bad', 'Number ' . $h['label'] . ' has LOW quality at Meta', '', 'Stop campaigns from it for a few days.');
        if (($h['subscribed'] ?? null) === false) $add('bad', 'The WhatsApp account of ' . $h['label'] . ' is NOT connected to the webhook', 'Meta cannot send HRMS the delivery reports (delivered / read / failed with reason).', 'Setup & connection → Connect (subscribe the app).');
        if (($h['error'] ?? '') !== '') $add('warn', 'Meta did not answer for number ' . $h['label'], (string)$h['error'], 'If it says the token is invalid, renew WA_TOKEN in config.php.');
    }
    $F['numbers'] = $N;

    // 4. outcome
    $O = ['total' => 0, 'waiting' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0, 'held_by_meta' => 0, 'accepted_then_failed' => 0, 'rejected_at_send' => 0];
    foreach (wa_diag_q($pdo, "SELECT i.status, i.last_status, i.wamid <> '' AS acc, m.status AS ms, COUNT(*) AS n FROM wa_camp_items i LEFT JOIN wa_messages m ON m.wamid = i.wamid AND i.wamid <> '' WHERE i.camp_id = ? GROUP BY i.status, i.last_status, acc, m.status", [$id]) as $r) {
        $n = (int)$r['n']; $O['total'] += $n; $st = (string)$r['status']; $ms = (string)($r['ms'] ?? ''); $ls = (string)$r['last_status'];
        if (in_array($st, ['queued', 'sending'], true)) $O['waiting'] += $n; elseif ($st === 'retry') $O['retry'] += $n; elseif ($st === 'skipped') $O['skipped'] += $n;
        elseif ($st === 'failed' && !(int)$r['acc']) { $O['failed'] += $n; $O['rejected_at_send'] += $n; }
        elseif ($st === 'failed' || $ls === 'failed' || $ms === 'failed') { $O['failed'] += $n; $O['accepted_then_failed'] += $n; }
        elseif ($ms === 'read' || $ls === 'read') $O['read'] += $n; elseif ($ms === 'delivered' || $ls === 'delivered') $O['delivered'] += $n; else $O['sent'] += $n;
    }
    $O['held_by_meta'] = (int)wa_diag_v($pdo, "SELECT COUNT(*) FROM wa_camp_items WHERE camp_id = ? AND status = 'skipped' AND err_code = 131049", [$id], 0); $O['skipped'] = max(0, $O['skipped'] - $O['held_by_meta']);   // Meta's per-customer marketing limit: kept as "skipped" (never retried), shown on its own so it is not hidden inside skipped
    foreach (wa_diag_q($pdo, "SELECT variant, COUNT(*) AS n FROM wa_camp_items WHERE camp_id = ? AND status = 'sent' GROUP BY variant", [$id]) as $r) $O['sent_' . ['U' => 'utility', 'M' => 'marketing', 'F' => 'free_chat'][(string)$r['variant']] ?? 'other'] = (int)$r['n'];
    $F['split'] = ['utility_version' => (int)($c['extra']['u_tpl'] ?? 0), 'utility_pct_wanted' => (int)($c['extra']['u_pct'] ?? 0), 'number_shares' => (array)($c['extra']['num_w'] ?? []), 'numbers_chosen' => (array)($c['extra']['numbers'] ?? [])];
    if (!empty($c['extra']['u_tpl'])) { $ur = wa_diag_q($pdo, "SELECT name, category, category_now, relabelled_at FROM wa_templates WHERE id = ?", [(int)$c['extra']['u_tpl']]); $ur = $ur[0] ?? null;
        if (!$ur) $add('warn', 'The chosen utility version no longer exists', '', 'Pick another one.');
        elseif (strtoupper((string)$ur['category_now']) === 'MARKETING' || !empty($ur['relabelled_at'])) $add('warn', 'Meta re-labelled the utility version "' . $ur['name'] . '" as MARKETING', 'This campaign sends the marketing message to everyone.', 'Make new utility versions (✨) — without offer words.'); }
    $O['by_number'] = wa_diag_q($pdo, "SELECT i.number_id, COUNT(*) AS n, SUM(i.status = 'failed' OR i.last_status = 'failed') AS failed FROM wa_camp_items i WHERE i.camp_id = ? AND i.number_id > 0 GROUP BY i.number_id", [$id]);
    $F['outcome'] = $O;

    // 5. failures: every code we can find
    $fails = []; $put = function (int $code, string $txt, int $n, string $src) use (&$fails) { $k = $code . '|' . mb_strtolower(mb_substr($txt, 0, 60)); if (!isset($fails[$k])) $fails[$k] = ['code' => $code, 'text' => $txt, 'n' => 0, 'source' => $src]; $fails[$k]['n'] += $n; };
    foreach (wa_diag_q($pdo, "SELECT COALESCE(NULLIF(i.err_code, 0), m.err_code, 0) AS code, COALESCE(NULLIF(i.err, ''), m.err, '') AS txt, i.wamid <> '' AS acc, COUNT(*) AS n
        FROM wa_camp_items i LEFT JOIN wa_messages m ON m.wamid = i.wamid AND i.wamid <> '' WHERE i.camp_id = ? AND (i.status IN ('failed', 'retry') OR i.last_status = 'failed' OR m.status = 'failed') GROUP BY code, txt, acc", [$id]) as $r)
        $put((int)$r['code'], (string)$r['txt'], (int)$r['n'], (int)$r['acc'] ? 'webhook (accepted, then failed)' : 'send (Meta refused at once)');
    $raw = wa_diag_q($pdo, "SELECT f.code, f.payload FROM wa_fail_raw f JOIN wa_camp_items i ON i.wamid = f.wamid AND i.wamid <> '' WHERE i.camp_id = ? ORDER BY f.id DESC LIMIT 400", [$id]);
    $rawN = []; foreach ($raw as $r) { $pj = json_decode((string)$r['payload'], true); $e0 = (array)($pj['errors'][0] ?? []); $cd = (int)($r['code'] ?: ($e0['code'] ?? 0)); $rawN[$cd] = ($rawN[$cd] ?? 0) + 1; }
    $F['raw_answers'] = ['count' => (int)wa_diag_v($pdo, "SELECT COUNT(*) FROM wa_fail_raw f JOIN wa_camp_items i ON i.wamid = f.wamid AND i.wamid <> '' WHERE i.camp_id = ?", [$id], count($raw)), 'by_code' => $rawN, 'example' => $raw ? mb_substr((string)$raw[0]['payload'], 0, 900) : ''];
    // the webhook log around the send (the phone of each failed person, from 10 minutes before to 6 hours after the start)
    $from = date('Y-m-d H:i:s', $startTs - 600); $to = date('Y-m-d H:i:s', $startTs + 6 * 3600);
    $logFail = wa_diag_q($pdo, "SELECT info FROM wa_log WHERE kind = 'status' AND ok = 0 AND at BETWEEN ? AND ? ORDER BY id LIMIT 3000", [$from, $to]);
    $phones = []; foreach (wa_diag_q($pdo, "SELECT phone FROM wa_camp_items WHERE camp_id = ?", [$id]) as $r) $phones[(string)$r['phone']] = true;
    $logCodes = []; $logMine = 0; foreach ($logFail as $r) { $info = (string)$r['info']; $ph = substr(preg_replace('/\D/', '', strtok($info, ' ')) ?: '', -10); if (!isset($phones[$ph])) continue; $logMine++;
        $cd = preg_match('/code (\d+)/', $info, $m) ? (int)$m[1] : 0; $tx = trim(preg_replace('/^\S+\s*·\s*/', '', preg_replace('/\s*\(code \d+\)\s*$/', '', $info)) ?? ''); $k = $cd . '|' . $tx; $logCodes[$k] = ['code' => $cd, 'text' => $tx, 'n' => ($logCodes[$k]['n'] ?? 0) + 1]; }
    $hooks = (int)wa_diag_v($pdo, "SELECT COUNT(*) FROM wa_log WHERE kind = 'webhook' AND at BETWEEN ? AND ?", [$from, $to], 0);
    // the log keeps only its last 300 lines: for an older send it no longer covers the window, and "0 failed reports" would be false
    $logStart = (string)wa_diag_v($pdo, "SELECT MIN(at) FROM wa_log", [], ''); $logCovers = $logStart !== '' && $logStart <= $from;
    $repWhy = (int)wa_diag_v($pdo, "SELECT COUNT(*) FROM wa_camp_items i LEFT JOIN wa_messages m ON m.wamid = i.wamid AND i.wamid <> '' WHERE i.camp_id = ? AND i.wamid <> '' AND (i.last_status = 'failed' OR m.status = 'failed') AND COALESCE(NULLIF(i.err_code, 0), m.err_code, 0) > 0", [$id], 0);
    $hookErr = wa_diag_q($pdo, "SELECT info, COUNT(*) AS n FROM wa_log WHERE kind = 'webhook' AND ok = 0 AND at BETWEEN ? AND ? GROUP BY info ORDER BY n DESC LIMIT 5", [$from, $to]);
    $sendErr = wa_diag_q($pdo, "SELECT kind, info, COUNT(*) AS n FROM wa_log WHERE kind IN ('campaign', 'engine', 'send') AND ok = 0 AND at BETWEEN ? AND ? GROUP BY kind, info ORDER BY n DESC LIMIT 6", [$from, $to]);
    $F['webhook'] = ['log_covers_window' => $logCovers, 'log_starts' => $logStart, 'failure_reports_with_reason' => $repWhy, 'reports_in_window' => $hooks, 'failed_reports_for_this_campaign' => $logMine, 'codes_in_log' => array_values($logCodes), 'webhook_errors' => $hookErr, 'sender_errors' => $sendErr, 'window' => $from . ' → ' . $to];
    foreach ($logCodes as $x) if ($x['code'] > 0) { $found = false; foreach ($fails as &$f0) if ($f0['code'] === $x['code']) { $found = true; break; } unset($f0); if (!$found) $put($x['code'], $x['text'], $x['n'], 'webhook log only'); }
    foreach ($rawN as $cd => $n) if ($cd > 0) { $found = false; foreach ($fails as $f0) if ($f0['code'] === $cd) $found = true; if (!$found) $put($cd, '', $n, 'Meta raw answer'); }
    usort($fails, fn($a, $b) => $b['n'] <=> $a['n']); $F['failures'] = array_values($fails);

    // 6. audience facts
    $A = ['people' => $O['total']];
    $A['messaged_by_a_campaign_in_7_days_before'] = (int)wa_diag_v($pdo, "SELECT COUNT(DISTINCT i.phone) FROM wa_camp_items i WHERE i.camp_id = ? AND EXISTS (SELECT 1 FROM wa_messages m WHERE m.phone = i.phone AND m.dir = 'out' AND m.who = 'campaign' AND m.status <> 'failed' AND m.ts >= ? AND m.ts < ?)", [$id, date('Y-m-d H:i:s', $startTs - 7 * 86400), $start ?: date('Y-m-d H:i:s')], 0);
    $A['never_wrote_to_us'] = (int)wa_diag_v($pdo, "SELECT COUNT(*) FROM wa_camp_items i LEFT JOIN wa_contacts c ON c.phone = i.phone WHERE i.camp_id = ? AND (c.last_in_at IS NULL)", [$id], 0);
    $A['wrote_in_last_30_days'] = (int)wa_diag_v($pdo, "SELECT COUNT(*) FROM wa_camp_items i JOIN wa_contacts c ON c.phone = i.phone WHERE i.camp_id = ? AND c.last_in_at >= ?", [$id, date('Y-m-d H:i:s', $startTs - 30 * 86400)], 0);
    $A['opted_out_now'] = (int)wa_diag_v($pdo, "SELECT COUNT(*) FROM wa_camp_items i JOIN wa_contacts c ON c.phone = i.phone WHERE i.camp_id = ? AND c.optout = 1", [$id], 0);
    $A['odd_numbers'] = (int)wa_diag_v($pdo, "SELECT COUNT(*) FROM wa_camp_items WHERE camp_id = ? AND phone NOT REGEXP '^[6-9][0-9]{9}$'", [$id], 0);
    $A['imported_never_chatted'] = (int)wa_diag_v($pdo, "SELECT COUNT(*) FROM wa_camp_items i JOIN wa_contacts c ON c.phone = i.phone WHERE i.camp_id = ? AND c.last_in_at IS NULL AND c.first_seen IS NOT NULL", [$id], 0);
    $F['audience'] = $A;

    // 7. verdict from the facts
    $tot = max(1, $O['total']); $failed = $O['failed'] + $O['retry'];
    if ($O['total'] === 0) $add('warn', 'This campaign has no people at all', 'It was saved but never started, or every person was left out by the filters.', 'Start it again with an audience.');
    if ($failed > 0) {
        $known = array_filter($fails, fn($f) => $f['code'] > 0); $unknown = array_sum(array_map(fn($f) => $f['code'] > 0 ? 0 : $f['n'], $fails));
        foreach (array_slice(array_values($known), 0, 4) as $f) { [$mean, $fix] = $codes[$f['code']] ?? ['Meta error ' . $f['code'] . ($f['text'] !== '' ? ': ' . $f['text'] : ''), 'Look up the code in Meta’s error list.'];
            $add($f['n'] * 100 / $tot >= 20 ? 'bad' : 'warn', $f['n'] . ' of ' . $O['total'] . ($f['code'] === 131049 ? ' were held back by Meta (code ' . $f['code'] . ', counted as "held by meta" — not retried)' : ($f['code'] === 131026 ? ' failed with Meta code 131026 (tried once more, then stopped)' : ' failed with Meta code ' . $f['code'])) . ($f['text'] !== '' ? ' — ' . mb_substr($f['text'], 0, 80) : ''), $mean . ' (found in: ' . $f['source'] . ')', $fix); }
        if ($unknown > 0) {
            if (!$logCovers) $add('warn', $unknown . ' failed and the reason was not kept', 'The webhook log keeps only its last 300 lines and no longer reaches back to this send, so it cannot be checked now.', 'Press Diagnose right after a campaign sends to see the webhook side too.');
            elseif ($O['accepted_then_failed'] > 0 && $logMine === 0 && $hooks === 0) $add('bad', $unknown . ' failed with no code AND no delivery report reached HRMS around the send', 'Meta accepted the messages, but its delivery reports (which always carry the reason) never arrived — the webhook is not reaching this HRMS, or reaches another copy.', 'Check WhatsApp Manager → Configuration → Webhook: the Callback URL must be https://' . ($_SERVER['HTTP_HOST'] ?? 'your-site') . '/empmanagment/api.php?action=wa.webhook, and "messages" must be subscribed.');
            elseif ($O['accepted_then_failed'] > 0 && $logMine === 0) $add('bad', $unknown . ' failed with no code — HRMS received other webhook reports, but none for these people', 'The failed status was set without Meta’s error, which only an older webhook handler does (wa_backup.php keeps no reason).', 'Make sure Meta’s Callback URL is api.php?action=wa.webhook and not an old file. New failures now keep Meta’s raw answer.');
            elseif ($O['rejected_at_send'] > 0) $add('bad', $unknown . ' were refused by Meta at send time without a readable answer', 'Usually the connection to Meta failed (timeout) or the token was rejected.', 'See "Sender errors" below; check WA_TOKEN in config.php.');
            else $add('warn', $unknown . ' failed and the reason was not kept', 'From now on every failure keeps Meta’s exact answer (🔍 on the person).', 'Send a small test campaign (10 people) and press Diagnose again.');
        }
        if ($A['messaged_by_a_campaign_in_7_days_before'] * 100 / $tot >= 30) $add('warn', $A['messaged_by_a_campaign_in_7_days_before'] . ' of these people got another campaign from you in the 7 days before', 'Meta holds back marketing to people who get many marketing messages (code 131049).', 'Leave 3–7 days between campaigns to the same people.');
        if ($A['never_wrote_to_us'] * 100 / $tot >= 50) $add('warn', $A['never_wrote_to_us'] . ' of these people never wrote to you on WhatsApp', 'Cold numbers (imported / Shopify) fail and block more often.', 'Start with people who wrote to you; warm the others up slowly.');
    } elseif ($O['total'] > 0 && $O['waiting'] === $O['total']) $add('warn', 'Nothing was sent yet — all ' . $O['total'] . ' are waiting', function_exists('wa_camp_wait_reason') ? wa_camp_wait_reason($pdo, $c) : '', '');
    elseif ($O['total'] > 0) $add('ok', 'No failures: ' . ($O['delivered'] + $O['read']) . ' delivered or read, ' . $O['sent'] . ' sent (report pending)', '', '');
    if (!$V) $add('ok', 'No problem found', '', '');
    $rank = ['bad' => 0, 'warn' => 1, 'ok' => 2]; usort($V, fn($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);

    // plain text to copy
    $L = []; $L[] = '🩺 DIAGNOSE — campaign #' . $id . ' "' . $c['name'] . '" · ' . date('j M Y, g:i a');
    $L[] = ''; $L[] = 'VERDICT'; foreach ($V as $i => $v) $L[] = ($i + 1) . '. [' . strtoupper($v['level']) . '] ' . $v['title'] . ($v['detail'] !== '' ? "\n   " . $v['detail'] : '') . ($v['fix'] !== '' ? "\n   → " . $v['fix'] : '');
    $L[] = ''; $L[] = 'OUTCOME: ' . implode(' · ', array_map(fn($k) => $k . ' ' . $O[$k], ['total', 'waiting', 'sent', 'delivered', 'read', 'failed', 'retry', 'skipped', 'held_by_meta', 'accepted_then_failed', 'rejected_at_send']));
    $nl = []; foreach ($N as $n0) $nl[(int)$n0['id']] = $n0['label'];
    $tried = $O['total'] - $O['waiting'] - $O['skipped']; $L[] = 'IN SHORT: ' . $tried . ' tried · ' . ($O['delivered'] + $O['read']) . ' delivered (' . ($tried ? round(($O['delivered'] + $O['read']) * 100 / $tried) : 0) . '%) · ' . $O['read'] . ' read · ' . ($O['held_by_meta'] + $O['failed'] + $O['retry']) . ' not delivered by Meta · ' . $O['waiting'] . ' still waiting';
    $L[] = 'SPLIT: utility ' . ($O['sent_utility'] ?? 0) . ' · marketing ' . ($O['sent_marketing'] ?? 0) . ' · free chat ' . ($O['sent_free_chat'] ?? 0) . ' (wanted utility ' . (int)($c['extra']['u_pct'] ?? 0) . '%)' . (!empty($c['extra']['num_w']) ? ' · number shares ' . implode(', ', array_map(fn($k, $v) => ($nl[(int)$k] ?? '#' . $k) . ' ' . $v . '%', array_keys((array)$c['extra']['num_w']), (array)$c['extra']['num_w'])) : '');
    $L[] = 'BY NUMBER: ' . ($O['by_number'] ? implode(' · ', array_map(fn($r) => ($nl[(int)$r['number_id']] ?? '#' . $r['number_id']) . ' ' . (int)$r['n'] . ' (failed ' . (int)$r['failed'] . ')', $O['by_number'])) : 'nobody sent yet');
    $L[] = 'FAILURES: ' . ($fails ? implode(' | ', array_map(fn($f) => ($f['code'] ?: 'no code') . ($f['text'] !== '' ? ' "' . mb_substr($f['text'], 0, 60) . '"' : '') . ' ×' . $f['n'] . ' [' . $f['source'] . ']', array_slice($fails, 0, 8))) : 'none');
    $L[] = 'MESSAGE: ' . ($t ? $T['name'] . ' (' . $T['language'] . ') · ' . $T['category'] . ($T['category_now'] ? ' → now ' . $T['category_now'] : '') . ' · header ' . $T['header'] . ' · ' . $T['body_chars'] . ' chars · ' . $T['variables'] . ' variables · ' . $T['buttons'] . ' buttons' : 'missing');
    foreach ($T['accounts'] ?? [] as $a) $L[] = '   account ' . $a['waba'] . ': HRMS ' . $a['hrms_status'] . ' · Meta ' . ($a['meta_status'] ?? '?') . ' / ' . ($a['meta_category'] ?? '?') . ' / quality ' . ($a['meta_quality'] ?? '?') . (!empty($a['meta_error']) ? ' · ' . $a['meta_error'] : '');
    $usedN = array_map('intval', (array)($c['extra']['numbers'] ?? []));
    foreach ($N as $n) $L[] = 'NUMBER ' . $n['label'] . ($usedN && !in_array((int)$n['id'], $usedN, true) ? ' (not used by this campaign)' : '') . ' ' . $n['display'] . ': ' . ($n['status'] ?: '?') . ' · quality ' . $n['quality'] . ' · limit ' . ($n['limit'] === null ? '?' : number_format((int)$n['limit'])) . ' · name ' . ($n['name_status'] ?: '?') . ' · webhook ' . ($n['webhook_subscribed'] === null ? '?' : ($n['webhook_subscribed'] ? 'subscribed' : 'NOT subscribed')) . ' · room ' . ($n['room_left'] ?? '?') . ($n['meta_error'] ? ' · ERROR ' . $n['meta_error'] : '');
    $L[] = 'WEBHOOK: ' . $repWhy . ' failure reports with Meta’s reason are stored on these people' . ($logCovers ? '' : ' · the webhook log (last 300 lines, from ' . $logStart . ') no longer reaches back to this send, so the window below is not checked');
    if ($logCovers) $L[] = 'WEBHOOK LOG (' . $F['webhook']['window'] . '): ' . $hooks . ' reports arrived · ' . $logMine . ' failed reports for these people' . ($logCodes ? ' · codes: ' . implode(', ', array_map(fn($x) => ($x['code'] ?: '?') . ' ×' . $x['n'], $logCodes)) : '');
    foreach ($hookErr as $e) $L[] = '   webhook error: ' . $e['info'] . ' ×' . $e['n'];
    foreach ($sendErr as $e) $L[] = '   sender error (' . $e['kind'] . '): ' . $e['info'] . ' ×' . $e['n'];
    $L[] = 'AUDIENCE: ' . implode(' · ', array_map(fn($k, $v) => str_replace('_', ' ', $k) . ' ' . $v, array_keys($A), $A));
    $L[] = 'RAW META ANSWERS KEPT: ' . $F['raw_answers']['count'] . ($F['raw_answers']['example'] !== '' ? "\n   example: " . $F['raw_answers']['example'] : '');
    $L[] = 'CAMPAIGN: ' . $F['campaign']['status'] . ' · started ' . $start . ' · ' . $F['campaign']['send_time'] . ' · retry ' . ($F['campaign']['retry'] ? 'on' : 'off') . ($F['campaign']['note'] !== '' ? ' · note: ' . $F['campaign']['note'] : '');
    return ['ok' => true, 'verdict' => $V, 'facts' => $F, 'report' => implode("\n", $L)];
}

}   // function_exists guard

if (!function_exists('wa_tpl_diagnose')) {
/** 🩺 Diagnose a pre-made message (template): our copy, its state on EVERY WhatsApp account (ours vs Meta LIVE), which numbers can send it,
 *  Meta's events in the log, campaigns that use it — and a verdict. A status that Meta changed but never told us is corrected on the spot. */
function wa_tpl_diagnose(PDO $pdo, int $id, string $name = ''): array
{
    if (!function_exists('wa_tpl_row')) return ['error' => 'Templates are not available.'];
    if (!$id && $name !== '') $id = (int)wa_diag_v($pdo, "SELECT id FROM wa_templates WHERE name = ? ORDER BY id DESC LIMIT 1", [$name], 0);
    $t = $id ? wa_tpl_row($pdo, $id) : null; if (!$t) return ['error' => 'Template not found.'];
    $V = []; $L = []; $now = time(); $add = function ($lv, $title, $detail = '', $fix = '') use (&$V) { $V[] = ['level' => $lv, 'title' => $title, 'detail' => $detail, 'fix' => $fix]; };
    $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : [];
    $btn = wa_tpl_arr($t['buttons'] ?? []); $ex = wa_tpl_arr($t['examples'] ?? []); $xt = wa_tpl_arr($t['extra'] ?? []);
    $L[] = '=== TEMPLATE #' . $id . ' "' . $t['name'] . '" (' . $t['language'] . ')';
    $L[] = 'category (ours): ' . $t['category'] . ' · source: ' . $t['source'] . ' · header: ' . ($t['header_format'] ?: 'none') . ' · body ' . mb_strlen((string)$t['body']) . ' chars, ' . count(wa_tpl_vars((string)$t['body'])) . ' variable(s) · buttons: ' . (count($btn) ? implode(', ', array_map(fn($b) => (string)($b['type'] ?? '?') . ':' . (string)($b['text'] ?? ''), $btn)) : 'none');
    $L[] = 'created: ' . ($t['created_at'] ?? '?') . ' · used: ' . (int)($t['used'] ?? 0) . ' · relabelled: ' . ((string)($t['relabelled_at'] ?? '') ?: 'no') . ' · variable examples: ' . json_encode($ex['body'] ?? [], JSON_UNESCAPED_UNICODE);
    if (!empty($xt['utility_of'])) $L[] = 'utility version of template #' . (int)$xt['utility_of']; if (!empty($xt['utility_id'])) $L[] = 'its utility version: template #' . (int)$xt['utility_id'];
    $L[] = 'BODY: ' . str_replace("\n", ' ⏎ ', mb_substr((string)$t['body'], 0, 700));
    // ours, per account
    $db = []; foreach (wa_diag_q($pdo, "SELECT * FROM wa_tpl_waba WHERE tpl_id = ?", [$id]) as $r) $db[(string)$r['waba_id']] = $r;
    $L[] = ''; $L[] = '=== ON EACH WHATSAPP ACCOUNT (ours → Meta live)'; $fixed = 0; $live = [];
    foreach ($wabas as $w) {
        $d = $db[$w] ?? null; $nums = array_values(array_filter(wa_diag_q($pdo, "SELECT id, label, display, waba_id FROM wa_numbers"), fn($n) => (function_exists('wa_waba_of') ? wa_waba_of($n) : trim((string)$n['waba_id'])) === (string)$w));   // a number without its own account id belongs to the main account (config)
        $nl = implode(', ', array_map(fn($n) => trim((string)$n['label'] . ' ' . (string)$n['display']), $nums)) ?: 'no number';
        $m = null; $merr = '';
        if (function_exists('wa_api')) {
            [$c, $j] = wa_api('GET', '/' . rawurlencode($w) . '/message_templates?name=' . rawurlencode((string)$t['name']) . '&fields=id,name,language,status,category,rejected_reason,quality_score&limit=20');
            if ($c >= 200 && $c < 300 && is_array($j)) { foreach ((array)($j['data'] ?? []) as $x) if ((string)($x['language'] ?? '') === (string)$t['language']) { $m = $x; break; } }
            else $merr = function_exists('wa_err') ? wa_err($j) : ('HTTP ' . $c);
        }
        $live[$w] = $m;
        $ours = $d ? strtoupper((string)$d['status']) : 'NOT SENT'; $metaS = $m ? strtoupper((string)($m['status'] ?? '')) : ($merr !== '' ? 'could not ask (' . $merr . ')' : 'NOT ON THIS ACCOUNT');
        $L[] = '- account ' . $w . ' [' . $nl . ']: ours ' . $ours . ($d ? ' (updated ' . $d['updated_at'] . ', meta id ' . ($d['meta_id'] ?: '—') . ($d['reason'] !== '' ? ', reason ' . $d['reason'] : '') . (isset($d['tries']) ? ', tries ' . (int)$d['tries'] : '') . ')' : '')
            . ' → Meta live: ' . $metaS . ($m ? ' · category ' . ($m['category'] ?? '?') . (!empty($m['rejected_reason']) && $m['rejected_reason'] !== 'NONE' ? ' · rejected because ' . $m['rejected_reason'] : '') . (!empty($m['quality_score']['score']) ? ' · quality ' . $m['quality_score']['score'] : '') : '');
        if ($m && strtoupper((string)$m['status']) !== $ours) {   // Meta changed it and never told us (webhook): correct it now
            try { $pdo->prepare("DELETE FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?")->execute([$id, $w]);
                $pdo->prepare("INSERT INTO wa_tpl_waba (tpl_id, waba_id, meta_id, status, reason, updated_at, category) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$id, $w, (string)($m['id'] ?? ''), strtoupper((string)$m['status']), mb_substr((string)(($m['rejected_reason'] ?? '') === 'NONE' ? '' : ($m['rejected_reason'] ?? '')), 0, 300), date('Y-m-d H:i:s'), strtoupper((string)($m['category'] ?? ''))]);
                $fixed++; $L[] = '  ↳ corrected here: ' . $ours . ' → ' . strtoupper((string)$m['status']); } catch (Throwable $e) { }
        }
    }
    $st = wa_tpl_status($pdo, $id, $wabas);
    $L[] = 'overall now: ' . strtoupper($st['status']) . ' (approved on ' . ($st['ok_n'] ?? 0) . ' of ' . count($wabas) . ' account(s))';
    // Meta's events in our log
    $ev = wa_diag_q($pdo, "SELECT at, ok, info FROM wa_log WHERE kind = 'template' AND info LIKE ? ORDER BY id DESC LIMIT 15", ['%' . $t['name'] . '%']);
    $L[] = ''; $L[] = '=== META EVENTS RECEIVED (webhook / log), newest first'; foreach ($ev as $e) $L[] = '- ' . $e['at'] . ' ' . ($e['ok'] ? '' : '[problem] ') . $e['info']; if (!$ev) $L[] = '- none';
    $anyEv = (int)wa_diag_v($pdo, "SELECT COUNT(*) FROM wa_log WHERE kind = 'template' AND info LIKE 'Template % → %' AND at >= ?", [date('Y-m-d H:i:s', $now - 30 * 86400)], 0);
    $L[] = 'template status events from Meta in the last 30 days (all templates): ' . $anyEv;
    // where it is used
    $camps = wa_diag_q($pdo, "SELECT id, name, status, created_at FROM wa_campaigns WHERE tpl_id = ? ORDER BY id DESC LIMIT 10", [$id]);
    $L[] = ''; $L[] = '=== CAMPAIGNS USING IT'; foreach ($camps as $c) $L[] = '- #' . $c['id'] . ' ' . $c['name'] . ' · ' . $c['status'] . ' · ' . $c['created_at']; if (!$camps) $L[] = '- none';
    // verdict
    $okN = (int)($st['ok_n'] ?? 0); $n = count($wabas);
    if ($fixed) $add('warn', 'Meta had changed this message but never told HRMS — corrected now (' . $fixed . ' account' . ($fixed === 1 ? '' : 's') . ').', 'Meta’s status reports for templates are not reaching HRMS.', 'In Meta (App → WhatsApp → Configuration → Webhook fields) subscribe to message_template_status_update. Until then press “Refresh from Meta” on the Pre-made screen after you submit.');
    if ($okN === $n && $n) $add('ok', 'Approved on every account (' . $n . ') — ready to send from every number.');
    elseif ($okN > 0) $add('ok', 'Approved on ' . $okN . ' of ' . $n . ' accounts — ready to send now from those numbers.', 'The other account(s) join by themselves once Meta approves there.');
    foreach ($wabas as $w) { $d = $db[$w] ?? null; $m = $live[$w] ?? null; $s2 = $m ? strtoupper((string)$m['status']) : ($d ? strtoupper((string)$d['status']) : '');
        if (!$d && !$m) $add('warn', 'Not created on account ' . $w . '.', 'The numbers of that account cannot send it.', 'Press “Copy to the other account” on the message.');
        elseif ($s2 === 'PENDING' || $s2 === 'IN_APPEAL') { $since = $d ? (int)round(($now - strtotime((string)$d['updated_at'])) / 3600) : 0; $add($since >= 24 ? 'bad' : 'warn', 'Still waiting for Meta on account ' . $w . ($since ? ' (' . $since . ' h)' : '') . '.', 'Meta usually decides within minutes to 24 hours.', $since >= 24 ? 'Over a day: make a slightly changed copy (new name) with Claude in the Message builder — Meta often approves the new one quickly.' : 'Wait; nothing to change.'); }
        elseif ($s2 === 'REJECTED') $add('bad', 'Rejected on account ' . $w . (($m['rejected_reason'] ?? ($d['reason'] ?? '')) ? ' — ' . ($m['rejected_reason'] ?? $d['reason']) : '') . '.', '', 'Make a corrected copy with Claude in the Message builder (it knows the usual reasons).');
        elseif (in_array($s2, ['PAUSED', 'DISABLED'], true)) $add('bad', 'Meta ' . strtolower($s2) . ' it on account ' . $w . ' (too many blocks / reports).', '', 'Use another message for a while; send only to people who know you.');
        if ($m && !empty($m['category']) && strtoupper((string)$m['category']) !== strtoupper((string)$t['category'])) $add('warn', 'Meta counts it as ' . $m['category'] . ' on account ' . $w . ' (we made it ' . $t['category'] . ').', 'It is billed and limited as ' . $m['category'] . '.', ''); }
    if (!$anyEv && !$fixed) $add('warn', 'No template status report from Meta in 30 days.', 'Approvals may not reach HRMS by themselves.', 'Subscribe the webhook to message_template_status_update, or press “Refresh from Meta” after submitting.');
    $order = ['bad' => 0, 'warn' => 1, 'ok' => 2]; usort($V, fn($a, $b) => $order[$a['level']] <=> $order[$b['level']]);
    $rep = "🩺 TEMPLATE DIAGNOSE — " . date('Y-m-d H:i') . "\n\nVERDICT\n" . implode("\n", array_map(fn($v, $i) => ($i + 1) . '. [' . strtoupper($v['level']) . '] ' . $v['title'] . ($v['detail'] ? ' — ' . $v['detail'] : '') . ($v['fix'] ? ' → ' . $v['fix'] : ''), $V, array_keys($V))) . "\n\n" . implode("\n", $L);
    return ['ok' => true, 'status' => $st['status'], 'ok_n' => $okN, 'accounts' => $n, 'fixed' => $fixed, 'verdict' => $V, 'report' => $rep];
}
}

$__wdg = (string)($action ?? ($_GET['action'] ?? ''));
if ($__wdg === 'wa.tpl_diagnose') {
    $auth = require_auth();
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    if (function_exists('wa_tpl_tables')) wa_tpl_tables($pdo); @set_time_limit(90);
    json_response(wa_tpl_diagnose($pdo, (int)($_GET['id'] ?? 0), (string)($_GET['name'] ?? '')));
}
if ($__wdg === 'wa.camp_diagnose') {
    $auth = require_auth();
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    if (function_exists('wa_more_tables')) { try { wa_more_tables($pdo); } catch (Throwable $e) { } }
    @set_time_limit(90);
    json_response(wa_camp_diagnose($pdo, (int)($_GET['id'] ?? 0)));
}
