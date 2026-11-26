<?php
/**
 * Store → WhatsApp → Follow-ups: the messages that go to a customer 7, 30 and 60 days after the perfume was bought, sent from the Store's OWN WhatsApp number as approved
 * templates (Gujarati / Hindi / English, each with a photo on top and two reply buttons). Nothing goes out by itself: the Store team looks at the list, takes people off it and presses Send.
 *
 * Actions (st_wa.fu_*): fu_list, fu_send (ONE customer per call; the screen calls it again and again with a pause, so the server never waits), fu_lang, fu_cfg_save, fu_tpls, fu_tpl_seed, fu_tpl_submit, fu_tpl_sync.
 * Own tables: st_wa_fu (who got which follow-up, when), st_wa_lang (a customer's language). The messages go into the Store chats (st_wa_msg), never into the sales tables.
 */
require_once __DIR__ . '/store_remind.php';      // sr_phone, sr_short_title, st_rm_stop (people who asked not to be messaged)

function swf_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_wa_fu (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, name VARCHAR(120) NOT NULL DEFAULT '', stage SMALLINT NOT NULL, bill_id INT NOT NULL DEFAULT 0, lang VARCHAR(5) NOT NULL DEFAULT '', perfume VARCHAR(120) NOT NULL DEFAULT '',
            status VARCHAR(5) NOT NULL DEFAULT 'sent', wamid VARCHAR(120) NOT NULL DEFAULT '', err VARCHAR(220) NOT NULL DEFAULT '', day DATE NOT NULL, at DATETIME NOT NULL, by_emp INT NOT NULL DEFAULT 0, KEY k_ph (phone, stage), KEY k_day (day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_wa_lang (phone VARCHAR(12) NOT NULL PRIMARY KEY, lang VARCHAR(5) NOT NULL DEFAULT 'gu') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $ok = true;
    } catch (Throwable $e) { error_log('swf_tables: ' . $e->getMessage()); return $ok = false; }
}
function swf_langs(): array { return ['gu' => 'Gujarati', 'hi' => 'Hindi', 'en' => 'English']; }
function swf_cfg(PDO $pdo): array
{
    $d = ['limit' => 50, 'gap' => 6, 'lang' => 'gu'];
    try { $v = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = 'swfu'")->fetchColumn(); $j = $v ? json_decode((string)$v, true) : null; if (is_array($j)) foreach ($d as $k => $x) if (isset($j[$k])) $d[$k] = $j[$k]; } catch (Throwable $e) { }
    $d['limit'] = max(5, min(1000, (int)$d['limit'])); $d['gap'] = max(3, min(30, (int)$d['gap'])); if (!isset(swf_langs()[$d['lang']])) $d['lang'] = 'gu';
    return $d;
}
/** The nine messages (3 follow-ups × 3 languages): name, language, body (WhatsApp *bold* is allowed), footer, two reply buttons. {{1}} = first name, {{2}} = the perfume. */
function swf_defs(): array
{
    $b = [
        'gu' => [1 => "🌸 *નમસ્તે {{1}}!*\n\nTM Perfume House પરથી *{{2}}* પસંદ કરવા બદલ દિલથી આભાર 🙏\n\n✨ તમને તેની સુગંધ કેવી લાગી?\nઆ મેસેજનો જવાબ આપીને અમને જણાવો, તમારો અભિપ્રાય અમારા માટે ખૂબ કિંમતી છે 💬",
                 2 => "⏳ *નમસ્તે {{1}}!*\n\nતમે *{{2}}* લીધે એક મહિનો થઈ ગયો છે 😊\nહવે તે ખતમ થવા આવ્યું હશે.\n\n🌿 પૂરું થતા પહેલાં TM Perfume House પર આવીને ફરી ભરાવી લો.\nતમને મળીને અમને આનંદ થશે 🙏",
                 3 => "🌸 *નમસ્તે {{1}}!*\n\nતમારું *{{2}}* હવે પૂરું થઈ ગયું હશે 💭\n\n✨ ફરી ભરાવવા અથવા કોઈ નવી સુગંધ અજમાવવા TM Perfume House પર પધારો.\nપસંદગીમાં મદદ કરવાનો અમને આનંદ થશે 🙏"],
        'hi' => [1 => "🌸 *नमस्ते {{1}}!*\n\nTM Perfume House से *{{2}}* चुनने के लिए दिल से धन्यवाद 🙏\n\n✨ आपको इसकी खुशबू कैसी लगी?\nइस मैसेज का जवाब देकर हमें बताइए, आपकी राय हमारे लिए बहुत मायने रखती है 💬",
                 2 => "⏳ *नमस्ते {{1}}!*\n\nआपको *{{2}}* लिए एक महीना हो गया है 😊\nअब यह खत्म होने वाला होगा।\n\n🌿 खत्म होने से पहले TM Perfume House आकर इसे दोबारा भरवा लीजिए।\nआपसे मिलकर हमें खुशी होगी 🙏",
                 3 => "🌸 *नमस्ते {{1}}!*\n\nआपका *{{2}}* अब तक खत्म हो गया होगा 💭\n\n✨ दोबारा भरवाने या कोई नई खुशबू आज़माने के लिए TM Perfume House पधारिए।\nचुनने में आपकी मदद करके हमें खुशी होगी 🙏"],
        'en' => [1 => "🌸 *Hello {{1}}!*\n\nThank you for choosing *{{2}}* at TM Perfume House 🙏\n\n✨ How are you liking the fragrance?\nReply to this message and tell us, we love hearing from our customers 💬",
                 2 => "⏳ *Hello {{1}}!*\n\nIt has been a month since you took *{{2}}* 😊\nBy now it may be running low.\n\n🌿 Visit TM Perfume House to refill it before it ends.\nWe would love to see you at the store 🙏",
                 3 => "🌸 *Hello {{1}}!*\n\nYour *{{2}}* must be finished by now 💭\n\n✨ Visit TM Perfume House to refill it or to smell something new.\nWe will be happy to help you choose 🙏"]];
    $foot = ['gu' => 'સંદેશા બંધ કરવા STOP લખો', 'hi' => 'बंद करने के लिए STOP लिखें', 'en' => 'Reply STOP to stop messages'];
    $btn = ['gu' => [1 => ['😍 ખૂબ ગમ્યું', '🤔 મદદ જોઈએ છે'], 2 => ['📍 હું આવીશ', '⏰ પછી યાદ અપાવો'], 3 => ['😊 હું આવીશ', '🛍️ નવી સુગંધ બતાવો']],
            'hi' => [1 => ['😍 बहुत पसंद आया', '🤔 मदद चाहिए'], 2 => ['📍 मैं आऊंगा', '⏰ बाद में याद दिलाएं'], 3 => ['😊 मैं आऊंगा', '🛍️ नई खुशबू दिखाएं']],
            'en' => [1 => ['😍 Loved it', '🤔 Need help'], 2 => ['📍 I will visit', '⏰ Remind me later'], 3 => ['😊 I will visit', '🛍️ Show me something new']]];
    $title = [1 => 'Follow-up · how do you like it (7 days)', 2 => 'Follow-up · running low, refill (30 days)', 3 => 'Follow-up · finished, come back (60 days)'];
    $out = [];
    foreach (['gu', 'hi', 'en'] as $lg) foreach ([1, 2, 3] as $n) {
        $out[] = ['name' => 'tmph_store_followup_' . $n . '_' . $lg, 'language' => $lg, 'category' => 'MARKETING', 'header_format' => 'IMAGE', 'body' => $b[$lg][$n], 'footer' => $foot[$lg],
            'buttons' => array_map(fn($t) => ['type' => 'QUICK_REPLY', 'text' => $t], $btn[$lg][$n]), 'examples' => ['body' => ['Rahul', 'Oud Royal 50 ml'], 'labels' => ['Name', 'Perfume']], 'title' => $title[$n] . ' · ' . swf_langs()[$lg], 'stage' => [1 => 7, 2 => 30, 3 => 60][$n]];
    }
    return $out;
}
function swf_stage_n(int $stage): int { return $stage === 7 ? 1 : ($stage === 30 ? 2 : 3); }

/** The approved template for a stage: the customer's language first, then Gujarati, Hindi, English. Returns [template row, language used] or [null, '']. */
function swf_pick(PDO $pdo, int $stage, string $lang): array
{
    foreach (array_values(array_unique([$lang, 'gu', 'hi', 'en'])) as $lg) { $t = st_wa_tpl($pdo, 'tmph_store_followup_' . swf_stage_n($stage) . '_' . $lg, $lg); if ($t) return [$t, $lg]; }
    return [null, ''];
}

/** Everybody who is due for a follow-up today: the buyer of the LAST bill, 7-13 / 30-36 / 60-66 days ago. Each row: ready / stop / recent / sent / failed. */
function swf_queue(PDO $pdo, array $cfg): array
{
    $t0 = strtotime(date('Y-m-d'));
    $stop = []; try { foreach ($pdo->query("SELECT phone FROM st_rm_stop")->fetchAll(PDO::FETCH_COLUMN) as $p) $stop[substr(sr_phone((string)$p), -10)] = 1; } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT phone FROM st_wa_chat WHERE optout = 1")->fetchAll(PDO::FETCH_COLUMN) as $p) $stop[substr(sr_phone((string)$p), -10)] = 1; } catch (Throwable $e) { }
    $last = [];
    $q = $pdo->query("SELECT p.phone, p.name, b.bill_id, b.biz_date FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id AND b.status = 'ok' WHERE p.phone <> '' AND p.declined = 0 AND p.person_no = 1 AND b.biz_date >= DATE_SUB(CURDATE(), INTERVAL 70 DAY) ORDER BY b.biz_date, b.bill_id");
    foreach ($q->fetchAll() as $r) { $ph = sr_phone((string)$r['phone']); $nm = trim((string)$r['name']); $prev = $last[$ph] ?? null; $last[$ph] = ['phone' => $ph, 'name' => $nm !== '' ? $nm : ($prev['name'] ?? ''), 'bill_id' => (int)$r['bill_id'], 'day' => (string)$r['biz_date']]; }
    $items = []; $ids = array_values(array_unique(array_map(fn($x) => $x['bill_id'], $last)));
    foreach (array_chunk($ids, 500) as $ch) { $in = implode(',', array_map('intval', $ch)); if ($in === '') continue;
        foreach ($pdo->query("SELECT i.bill_id, i.ml, COALESCE(pr.title, '') title FROM st_bill_items i LEFT JOIN st_bottles bt ON bt.bottle_id = i.bottle_id LEFT JOIN st_products pr ON pr.product_id = bt.product_id WHERE i.kind = 'sale' AND i.bill_id IN ($in)")->fetchAll() as $r) { $b = (int)$r['bill_id']; $items[$b][(string)$r['title']] = ($items[$b][(string)$r['title']] ?? 0) + (int)$r['ml']; } }
    $fu = []; foreach ($pdo->query("SELECT phone, stage, status, day, wamid, err FROM st_wa_fu WHERE day >= DATE_SUB(CURDATE(), INTERVAL 70 DAY) ORDER BY id")->fetchAll() as $r) $fu[$r['phone']][] = $r;
    $langs = []; foreach ($pdo->query("SELECT phone, lang FROM st_wa_lang")->fetchAll() as $r) $langs[$r['phone']] = $r['lang'];
    $mst = []; foreach ($pdo->query("SELECT m.wamid, m.status FROM st_wa_msg m JOIN st_wa_fu f ON f.wamid = m.wamid WHERE f.day >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)")->fetchAll() as $r) $mst[$r['wamid']] = $r['status'];
    $today = date('Y-m-d'); $rows = [];
    foreach ($last as $ph => $c) {
        if (strlen($ph) < 11) continue; $key = substr($ph, -10); $days = (int)floor(($t0 - strtotime($c['day'])) / 86400);
        $stage = $days >= 7 && $days <= 13 ? 7 : ($days >= 30 && $days <= 36 ? 30 : ($days >= 60 && $days <= 66 ? 60 : 0)); if (!$stage) continue;
        $it = $items[$c['bill_id']] ?? []; $perfume = ''; $ml = 0; if ($it) { arsort($it); $perfume = sr_short_title((string)array_key_first($it)); $ml = (int)reset($it); }
        $label = $perfume !== '' ? ($ml > 0 ? $perfume . ' ' . $ml . ' ml' : $perfume) : ($ml > 0 ? $ml . ' ml perfume' : 'perfume');
        $state = 'ready'; $note = ''; $wamid = ''; $err = '';
        if (isset($stop[$key])) { $state = 'stop'; $note = 'Asked to STOP'; }
        foreach ($fu[$key] ?? [] as $f) {
            if ((int)$f['stage'] === $stage && $f['status'] === 'sent') { $state = 'sent'; $wamid = (string)$f['wamid']; $note = ''; }
            elseif ($f['status'] === 'sent' && $state === 'ready') { $d = (int)floor(($t0 - strtotime((string)$f['day'])) / 86400); if ($d < 20) { $state = 'recent'; $note = 'Messaged ' . $d . ' days ago'; } }
            elseif ($f['status'] === 'fail' && (int)$f['stage'] === $stage && $f['day'] === $today && $state === 'ready') { $state = 'failed'; $err = (string)$f['err']; }
        }
        $run = ''; if ($state === 'sent') { $ms = $mst[$wamid] ?? 'sent'; $run = in_array($ms, ['delivered', 'read', 'failed'], true) ? $ms : 'sent'; }
        $rows[] = ['phone' => $key, 'name' => $c['name'], 'perfume' => $label, 'days' => $days, 'stage' => $stage, 'lang' => $langs[$key] ?? $cfg['lang'], 'state' => $state, 'note' => $note, 'run' => $run, 'err' => $err, 'bill_id' => $c['bill_id']];
    }
    usort($rows, fn($a, $b) => [$a['stage'], $a['state'] === 'ready' ? 0 : 1, $a['days']] <=> [$b['stage'], $b['state'] === 'ready' ? 0 : 1, $b['days']]);
    return $rows;
}
function swf_sent_today(PDO $pdo): int { $q = $pdo->prepare("SELECT COUNT(*) FROM st_wa_fu WHERE day = ? AND status = 'sent'"); $q->execute([date('Y-m-d')]); return (int)$q->fetchColumn(); }
function swf_report(PDO $pdo, string $day): array
{
    $q = $pdo->prepare("SELECT COUNT(*) sent, COALESCE(SUM(m.status IN ('delivered','read')), 0) delivered, COALESCE(SUM(m.status = 'read'), 0) rd, COALESCE(SUM(m.status = 'failed'), 0) failed,
        COALESCE(SUM(EXISTS (SELECT 1 FROM st_wa_msg r WHERE r.phone = f.phone AND r.dir = 'in' AND r.ts > f.at)), 0) replied
        FROM st_wa_fu f LEFT JOIN st_wa_msg m ON m.wamid = f.wamid WHERE f.status = 'sent' AND f.day = ?");
    $q->execute([$day]); $r = $q->fetch() ?: []; return ['day' => $day, 'sent' => (int)($r['sent'] ?? 0), 'delivered' => (int)($r['delivered'] ?? 0), 'read' => (int)($r['rd'] ?? 0), 'replied' => (int)($r['replied'] ?? 0), 'failed' => (int)($r['failed'] ?? 0)];
}
/** Which of the nine messages exist and where they stand at Meta (for the Store's own account). */
function swf_tpl_state(PDO $pdo, string $waba): array
{
    $out = [];
    foreach (swf_defs() as $d) {
        $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = ?"); $q->execute([$d['name'], $d['language']]); $id = (int)$q->fetchColumn(); $st = $id ? 'DRAFT' : 'MISSING'; $why = '';
        if ($id) { $w = $pdo->prepare("SELECT status, reason FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $w->execute([$id, $waba]); $x = $w->fetch(); if ($x) { $st = strtoupper((string)$x['status']) ?: 'PENDING'; $why = (string)$x['reason']; } }
        $out[] = ['name' => $d['name'], 'lang' => $d['language'], 'stage' => $d['stage'], 'id' => $id, 'status' => $st, 'reason' => $why];
    }
    return $out;
}

function swf_handle(PDO $pdo, string $action, int $me, bool $post): void
{
    if (!swf_tables($pdo)) json_response(['error' => 'The follow-up tables could not be made.'], 500);
    $num = sw_number($pdo); $cfg = swf_cfg($pdo);
    if ($action === 'st_wa.fu_list') {
        $rows = swf_queue($pdo, $cfg); $cnt = [7 => 0, 30 => 0, 60 => 0]; foreach ($rows as $r) if ($r['state'] === 'ready') $cnt[$r['stage']]++;
        $tp = []; foreach ([7, 30, 60] as $s) { [$t, $lg] = swf_pick($pdo, $s, $cfg['lang']); $tp[$s] = $t ? $lg : ''; }      // the language that would really go out for each stage ('' = no approved message yet)
        $defs = []; foreach (swf_defs() as $d) $defs[$d['stage']][$d['language']] = ['body' => $d['body'], 'footer' => $d['footer'], 'buttons' => array_column($d['buttons'], 'text')];
        json_response(['defs' => $defs, 'rows' => $rows, 'counts' => $cnt, 'cfg' => $cfg, 'sent_today' => swf_sent_today($pdo), 'ready_langs' => $tp, 'number' => $num ? true : false, 'yesterday' => swf_report($pdo, date('Y-m-d', strtotime('-1 day'))), 'today' => swf_report($pdo, date('Y-m-d'))]);
    }
    if (!$post && $action !== 'st_wa.fu_tpls') json_response(['error' => 'Method not allowed.'], 405);
    $in = $post ? json_input() : [];
    if ($action === 'st_wa.fu_lang') { $ph = wa_key($in['phone'] ?? ''); $lg = (string)($in['lang'] ?? ''); if (strlen($ph) !== 10 || !isset(swf_langs()[$lg])) json_response(['error' => 'Wrong number or language.'], 422); $pdo->prepare("INSERT INTO st_wa_lang (phone, lang) VALUES (?, ?) ON DUPLICATE KEY UPDATE lang = VALUES(lang)")->execute([$ph, $lg]); json_response(['ok' => true]); }
    if ($action === 'st_wa.fu_cfg_save') {
        $c = $cfg; foreach (['limit', 'gap', 'lang'] as $k) if (array_key_exists($k, $in)) $c[$k] = $in[$k];
        $c['limit'] = max(5, min(1000, (int)$c['limit'])); $c['gap'] = max(3, min(30, (int)$c['gap'])); if (!isset(swf_langs()[$c['lang']])) $c['lang'] = 'gu';
        $pdo->prepare("INSERT INTO st_cs_settings (skey, svalue) VALUES ('swfu', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([json_encode($c)]); json_response(['ok' => true, 'cfg' => $c]);
    }
    if ($action === 'st_wa.fu_send') {
        if (!$num) json_response(['error' => 'Choose the Store number in Setup first.'], 409);
        $ph = wa_key($in['phone'] ?? ''); $stage = (int)($in['stage'] ?? 0); if (strlen($ph) !== 10 || !in_array($stage, [7, 30, 60], true)) json_response(['error' => 'Wrong customer.'], 422);
        if (swf_sent_today($pdo) >= $cfg['limit']) json_response(['ok' => false, 'stop' => true, 'error' => 'Today\'s limit (' . $cfg['limit'] . ') is reached. The rest wait for tomorrow.']);
        $row = null; foreach (swf_queue($pdo, $cfg) as $r) if ($r['phone'] === $ph && $r['stage'] === $stage) { $row = $r; break; }
        if (!$row) json_response(['ok' => false, 'error' => 'This customer is not due for this message today.']);
        if ($row['state'] !== 'ready' && $row['state'] !== 'failed') json_response(['ok' => false, 'error' => $row['state'] === 'sent' ? 'Already sent.' : ($row['note'] ?: 'Skipped.')]);
        [$t, $lg] = swf_pick($pdo, $stage, (string)$row['lang']); if (!$t) json_response(['ok' => false, 'stop' => true, 'error' => 'No follow-up message is approved by Meta yet. Open "Messages" and send them to Meta.']);
        [$nm, $own, $why] = st_wa_number_for($pdo, $t); if (!$nm || !$own) json_response(['ok' => false, 'stop' => true, 'error' => $nm ? 'The Store number is not the one chosen for this message.' : (string)$why]);
        $first = trim((string)$row['name']) !== '' ? explode(' ', trim((string)$row['name']))[0] : ($lg === 'en' ? 'there' : 'જી'); if (function_exists('wa_first_name') && trim((string)$row['name']) !== '') $first = wa_first_name((string)$row['name']);
        $vars = [$first, (string)$row['perfume']];
        $b = wa_tpl_send_components($pdo, $t, $vars, (string)$nm['phone_id'], []); if (empty($b['ok'])) json_response(['ok' => false, 'stop' => true, 'error' => (string)($b['error'] ?? 'Could not build the message.')]);
        $pl = ['messaging_product' => 'whatsapp', 'to' => '91' . $ph, 'type' => 'template', 'template' => ['name' => $t['name'], 'language' => ['code' => $t['language']]]]; if (!empty($b['components'])) $pl['template']['components'] = $b['components'];
        [$code, $d] = wa_api('POST', '/' . $nm['phone_id'] . '/messages', $pl); $wamid = (string)($d['messages'][0]['id'] ?? ''); $now = date('Y-m-d H:i:s'); $day = date('Y-m-d');
        $ins = $pdo->prepare("INSERT INTO st_wa_fu (phone, name, stage, bill_id, lang, perfume, status, wamid, err, day, at, by_emp) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        if ($code < 200 || $code >= 300 || $wamid === '') { $e = function_exists('wa_err') ? wa_err(is_array($d) ? $d : null) : 'WhatsApp did not accept the message'; $ins->execute([$ph, mb_substr((string)$row['name'], 0, 120), $stage, (int)$row['bill_id'], $lg, mb_substr((string)$row['perfume'], 0, 120), 'fail', '', mb_substr($e, 0, 215), $day, $now, $me]); json_response(['ok' => false, 'error' => $e]); }
        $ins->execute([$ph, mb_substr((string)$row['name'], 0, 120), $stage, (int)$row['bill_id'], $lg, mb_substr((string)$row['perfume'], 0, 120), 'sent', $wamid, '', $day, $now, $me]);
        try { $shown = function_exists('wa_tpl_render') ? wa_tpl_render((string)$t['body'], $vars) : (string)$t['body']; st_wa_log_out($pdo, true, $wamid, $ph, (int)$nm['id'], $shown); } catch (Throwable $e) { }
        json_response(['ok' => true, 'lang' => $lg, 'sent_today' => swf_sent_today($pdo)]);
    }
    // the messages themselves (Admin / HR): make the nine drafts, send them to Meta, ask Meta how they are doing
    $waba = $num ? wa_waba_of($num) : ''; if ($waba === '') json_response(['error' => 'Choose the Store number (with its WhatsApp Business Account ID) in Setup first.'], 409);
    if ($action === 'st_wa.fu_tpls') json_response(['templates' => swf_tpl_state($pdo, $waba), 'photo_ok' => true]);
    require_once __DIR__ . '/store_wa_tpl.php';
    if (!function_exists('wa_app_id') && is_file(__DIR__ . '/wa_more.php')) { try { require_once __DIR__ . '/wa_more.php'; } catch (Throwable $e) { } }
    if (function_exists('wa_tpl_need_media')) wa_tpl_need_media();
    if ($action === 'st_wa.fu_tpl_seed') {
        [$lib, $perr] = swt_photo_from_store($pdo, 'back', $me); $made = []; $skipped = [];
        foreach (swf_defs() as $d) {
            $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = ?"); $q->execute([$d['name'], $d['language']]); if ($q->fetchColumn()) { $skipped[] = $d['name']; continue; }
            $dr = ['name' => $d['name'], 'language' => $d['language'], 'category' => $d['category'], 'header_format' => 'IMAGE', 'header_lib_id' => max(0, (int)$lib), 'body' => $d['body'], 'footer' => $d['footer'], 'buttons' => $d['buttons'], 'examples' => $d['examples'], 'folder' => 'Store'];
            [$t, $err] = wa_tpl_clean($dr, true); if ($err !== '') { $skipped[] = $d['name'] . ' (' . $err . ')'; continue; }
            $id = wa_tpl_insert($pdo, $t, $me, $d['title'], 'Store follow-up'); if ($id) $made[] = $d['name'];
        }
        json_response(['ok' => true, 'made' => $made, 'skipped' => $skipped]);
    }
    if ($action === 'st_wa.fu_tpl_submit') {
        $sent = []; $fail = [];
        foreach (swf_tpl_state($pdo, $waba) as $s) {
            if ($s['id'] <= 0 || $s['status'] !== 'DRAFT') { if ($s['status'] !== 'REJECTED') continue; }
            $row = wa_tpl_row($pdo, $s['id']); if (!$row) continue;
            [$t, $err] = wa_tpl_clean(['name' => $row['name'], 'language' => $row['language'], 'category' => $row['category'], 'header_format' => $row['header_format'], 'header_text' => $row['header_text'] ?? '', 'header_lib_id' => (int)($row['header_lib_id'] ?? 0), 'body' => $row['body'], 'footer' => $row['footer'], 'buttons' => $row['buttons'], 'examples' => $row['examples']], false);
            if ($err !== '') { $fail[] = $s['name'] . ': ' . $err; continue; } $me2 = wa_tpl_media_check($pdo, $row); if ($me2 !== '') { $fail[] = $s['name'] . ': ' . $me2; continue; }
            [$ok, $why] = wa_tpl_create_at($pdo, $row, $waba); if ($ok) $sent[] = $s['name']; else $fail[] = $s['name'] . ': ' . $why;
        }
        json_response(['ok' => !$fail, 'sent' => $sent, 'failed' => $fail]);
    }
    if ($action === 'st_wa.fu_tpl_sync') { swt_handle($pdo, 'st_wa.tpl_sync', $me, true); }      // asks Meta and updates every Store message (answers and stops)
    json_response(['error' => 'Unknown action.'], 404);
}
