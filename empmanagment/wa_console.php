<?php
/**
 * wa_console.php — add-on for the WhatsApp console. Loaded by wa.php (no change to api.php).
 *
 *   wa.overview    GET   Query / Campaign / Community counters (new numbers only), every number with its health (quality rating,
 *                        status, name approval, webhook, daily limit), chats right now, pre-made messages, things that need attention   [Admin / HR / control tower]
 *   wa.send_media  POST  multipart: phone, caption, file — a photo, video, voice note or document from a chat                            [chat owner / admin]
 *
 * Meta's rules (checked here before anything is uploaded): photo JPG / PNG up to 5 MB · video MP4 / 3GP up to 16 MB ·
 * voice / audio up to 16 MB · document (PDF, Word, Excel, PowerPoint, TXT) up to 100 MB. A photo or video can carry a caption.
 */
if (!function_exists('wa_media_rules')) {

function wa_ini_bytes(string $v): int
{
    $v = trim($v); if ($v === '') return 0; $n = (float)$v; $u = strtolower(substr($v, -1));
    return (int)($u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n)));
}
/** What WhatsApp accepts. Returns ['kind','mime','max','error']. */
function wa_media_rules(string $detected, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)); $mime = strtolower(trim($detected));
    $byExt = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'mp4' => 'video/mp4', '3gp' => 'video/3gpp', '3gpp' => 'video/3gpp', 'aac' => 'audio/aac', 'm4a' => 'audio/mp4', 'mp3' => 'audio/mpeg', 'amr' => 'audio/amr', 'ogg' => 'audio/ogg', 'opus' => 'audio/ogg',
        'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'txt' => 'text/plain'];
    if (in_array($mime, ['', 'application/octet-stream', 'application/zip', 'application/x-ole-storage', 'application/cdfv2', 'application/x-empty', 'application/x-zip-compressed'], true) && isset($byExt[$ext])) $mime = $byExt[$ext];
    if ($mime === 'image/jpg' || $mime === 'image/pjpeg') $mime = 'image/jpeg';
    if ($mime === 'video/x-m4v') $mime = 'video/mp4';
    // a voice note: the file name says audio, the content sniffer often says "video/mp4" (same box format) or an x- name — send it as audio, else it fails at delivery
    if (in_array($ext, ['m4a', 'aac', 'mp3', 'ogg', 'opus', 'amr'], true) && (in_array($mime, ['video/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/x-hx-aac-adts', 'audio/x-aac', 'audio/mp3', 'audio/x-mpeg', 'audio/x-opus+ogg', 'application/ogg', 'video/ogg'], true))) $mime = $byExt[$ext];
    if ($mime === 'image/webp') return ['kind' => '', 'mime' => $mime, 'max' => 0, 'error' => 'WhatsApp does not accept WebP photos. Send a JPG or PNG.'];
    if ($mime === 'image/heic' || $mime === 'image/heif') return ['kind' => '', 'mime' => $mime, 'max' => 0, 'error' => 'WhatsApp does not accept HEIC photos. Send a JPG or PNG.'];
    if ($mime === 'video/quicktime') return ['kind' => '', 'mime' => $mime, 'max' => 0, 'error' => 'WhatsApp does not accept .mov videos. Send an MP4.'];
    if (in_array($mime, ['image/jpeg', 'image/png'], true)) return ['kind' => 'image', 'mime' => $mime, 'max' => 5 * 1048576, 'error' => ''];
    if (in_array($mime, ['video/mp4', 'video/3gpp'], true)) return ['kind' => 'video', 'mime' => $mime, 'max' => 16 * 1048576, 'error' => ''];
    if (in_array($mime, ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg', 'audio/opus'], true)) return ['kind' => 'audio', 'mime' => $mime === 'audio/opus' ? 'audio/ogg' : $mime, 'max' => 16 * 1048576, 'error' => ''];
    if (in_array($mime, array_values(array_filter($byExt, fn($m) => strpos($m, 'application/') === 0 || $m === 'text/plain')), true)) return ['kind' => 'document', 'mime' => $mime, 'max' => 100 * 1048576, 'error' => ''];
    return ['kind' => '', 'mime' => $mime, 'max' => 0, 'error' => 'WhatsApp cannot send this kind of file. Use a JPG / PNG photo, an MP4 video, or a PDF / Word / Excel / PowerPoint / TXT document.'];
}
}
if (!function_exists('wa_media_upload')) {
/** Uploads the file to Meta for one number. Replaceable in tests. Returns [httpCode, decodedJson|null]. */
function wa_media_upload(string $pid, string $path, string $mime, string $name): array
{
    $tok = wa_cfg_val('WA_TOKEN'); if ($tok === '') return [0, ['error' => ['message' => 'WA_TOKEN is missing in config.php.']]];
    $ch = curl_init('https://graph.facebook.com/' . wa_graph() . '/' . $pid . '/media');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 240, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok],
        CURLOPT_POSTFIELDS => ['messaging_product' => 'whatsapp', 'type' => $mime, 'file' => new CURLFile($path, $mime, $name)]]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) return [0, ['error' => ['message' => 'Could not reach Meta: ' . $err]]];
    return [$code, json_decode((string)$raw, true)];
}
}
if (!function_exists('wa_is_upload')) { function wa_is_upload(string $tmp): bool { return is_uploaded_file($tmp); } }

if (!function_exists('wa_health_one')) {

/** 'TIER_250' → 250 · 'TIER_2K' → 2000 · 'TIER_UNLIMITED' → -1 · anything else → null. */
function wa_tier_num(string $t): ?int
{
    if (!preg_match('/(\d+)\s*([KM]?)|UNLIMITED/i', $t, $m)) return null;
    if (stripos($t, 'UNLIMITED') !== false) return -1;
    $n = (int)$m[1]; $u = strtoupper($m[2] ?? ''); return $u === 'K' ? $n * 1000 : ($u === 'M' ? $n * 1000000 : $n);
}
/** One number, asked live from Meta: quality rating, status, name approval, webhook, daily limit tier. */
function wa_health_one(array $n): array
{
    $ck = wa_number_check($n); $i = is_array($ck['info'] ?? null) ? $ck['info'] : [];
    $q = strtoupper((string)($i['quality_rating'] ?? '')); if (!in_array($q, ['GREEN', 'YELLOW', 'RED'], true)) $q = 'UNKNOWN';   // a new number has no rating yet
    $h = ['id' => (int)$n['id'], 'phone_id' => (string)$n['phone_id'], 'label' => (string)$n['label'], 'display' => (string)($i['display_phone_number'] ?? $n['display']), 'verified_name' => (string)($i['verified_name'] ?? ''),
        'name_status' => (string)($i['name_status'] ?? ''), 'quality' => $q, 'status' => (string)($i['status'] ?? ''), 'subscribed' => $ck['subscribed'], 'error' => (string)($ck['error'] ?: ''), 'tier' => null, 'at' => date('Y-m-d H:i:s')];
    if ($i) foreach (['messaging_limit_tier', 'whatsapp_business_manager_messaging_limit'] as $f) {   // asked one by one: a field Meta does not know must not spoil the rest
        [$c, $d] = wa_api('GET', '/' . $n['phone_id'] . '?fields=' . $f);
        if ($c >= 200 && $c < 300 && is_array($d) && !empty($d[$f])) { $h['tier'] = wa_tier_num((string)$d[$f]); break; }
    }
    $bad = $h['error'] !== '' || ($h['status'] !== '' && $h['status'] !== 'CONNECTED') || $q === 'RED' || $h['subscribed'] === false;
    $warn = $q === 'YELLOW' || ($h['name_status'] !== '' && !in_array($h['name_status'], ['APPROVED', 'AVAILABLE_WITHOUT_REVIEW'], true));
    $h['level'] = $bad ? 'bad' : ($warn ? 'warn' : 'ok');
    return $h;
}
/** Health of every number. Kept for 10 minutes so the Overview opens fast; $force asks Meta again. A failed ask keeps the last good answer. */
function wa_health_all(PDO $pdo, bool $force): array
{
    try { $cache = json_decode((string)setting_get($pdo, 'wa_health', ''), true); } catch (Throwable $e) { $cache = null; }   // the cache is a convenience: never let it stop the Overview
    if (!is_array($cache)) $cache = [];
    $nums = $pdo->query("SELECT * FROM wa_numbers ORDER BY id")->fetchAll(); $out = []; $dirty = false;
    foreach ($nums as $n) {
        $id = (int)$n['id']; $old = $cache[$id] ?? null; $age = $old ? time() - strtotime((string)$old['at']) : 999999;
        if ($force || $age > 600) {
            $h = wa_health_one($n);
            if ($h['error'] !== '' && $old && $h['status'] === '') { $old['stale'] = true; $h = $old; }   // Meta did not answer: show the last answer, marked old
            else { $cache[$id] = $h; $dirty = true; }
        } else $h = $old;
        $h['label'] = (string)$n['label'] !== '' ? (string)$n['label'] : ($h['verified_name'] ?: ($h['display'] ?: 'Number ' . $id)); $h['id'] = $id;
        $out[] = $h;
    }
    if ($dirty) { try { setting_set($pdo, 'wa_health', json_encode($cache, JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) { } }
    return $out;
}
/** A photo / video / voice note / document to one customer, from the number they last used. */
function wa_send_media(PDO $pdo, array $c, string $kind, string $mediaId, string $mime, string $caption, string $filename, int $empId): array
{
    $q = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q->execute([(int)$c['last_number_id']]); $pid = (string)$q->fetchColumn();
    if ($pid === '') $pid = wa_cfg_val('WA_PHONE_ID'); if ($pid === '') return [false, 'WA_PHONE_ID is missing in config.php.'];
    $obj = ['id' => $mediaId]; if ($caption !== '' && in_array($kind, ['image', 'video', 'document'], true)) $obj['caption'] = $caption; if ($kind === 'document') $obj['filename'] = $filename;
    [$code, $d] = wa_api('POST', '/' . $pid . '/messages', ['messaging_product' => 'whatsapp', 'to' => (string)($c['wa_id'] ?: '91' . $c['phone']), 'type' => $kind, $kind => $obj]);
    $wamid = (string)($d['messages'][0]['id'] ?? '');
    if ($code < 200 || $code >= 300 || $wamid === '') { $e = wa_err($d); wa_log($pdo, 'send', false, $c['phone'] . ' · ' . $e); return [false, $e]; }
    $label = ['image' => '📷 Photo', 'video' => '🎬 Video', 'audio' => '🎤 Voice note', 'document' => '📄 Document'][$kind]; $tail = $kind === 'document' ? $filename : $caption;
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, media_id, media_mime, status, who, emp_id, ts) VALUES (?, ?, ?, 'out', ?, ?, ?, ?, 'sent', 'emp', ?, ?)")
        ->execute([$wamid, $c['phone'], (int)$c['last_number_id'], $kind, $label . ($tail !== '' ? ': ' . mb_substr($tail, 0, 300) : ''), $mediaId, mb_substr($mime, 0, 60), $empId, $now]);
    $pdo->prepare("UPDATE wa_contacts SET last_out_at = ?, state = 'human', status = 'open', closed_at = NULL WHERE phone = ?")->execute([$now, $c['phone']]);   // a person replied → the bot stays quiet, a closed chat re-opens
    return [true, ['wamid' => $wamid, 'media_id' => $mediaId]];
}
}

// ------------------------------------------------------------------------------------------------------------------------ router
$__wc = (string)($action ?? ($_GET['action'] ?? ''));
if ($__wc === 'wa.overview' || $__wc === 'wa.send_media' || $__wc === 'wa.client_err') {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $auth = require_auth();
    if (!wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    switch ($__wc) {
        case 'wa.client_err': {   // the phone / browser tells us why a voice note could not be recorded, sent or played (shown in the WhatsApp log): so the cause is known, not guessed
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in0 = json_input(); $emp0 = (int)($auth['employee_id'] ?? 0);
            $kind0 = preg_replace('/[^a-z_]/', '', strtolower((string)($in0['kind'] ?? 'client'))); $msg0 = preg_replace('/\s+/', ' ', (string)($in0['msg'] ?? '')); $ua0 = preg_replace('/\s+/', ' ', (string)($in0['ua'] ?? ''));
            wa_log($pdo, 'client', false, 'emp ' . $emp0 . ' · ' . mb_substr($kind0, 0, 20) . ' · ' . mb_substr($msg0, 0, 120) . ' · ' . mb_substr((string)($in0['info'] ?? ''), 0, 60) . ' · ' . mb_substr($ua0, 0, 60)); json_response(['ok' => true]);
        }
        case 'wa.send_media': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $cl = (int)($_SERVER['CONTENT_LENGTH'] ?? 0); $pm = wa_ini_bytes((string)ini_get('post_max_size'));
            if (empty($_FILES['file']) && $cl > 0 && $pm > 0 && $cl > $pm) json_response(['error' => 'This file is too big for the server (limit ' . round($pm / 1048576) . ' MB). Send a smaller one.'], 413);
            $key = wa_key($_POST['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            if (function_exists('wa_cx_lock') && ($__cx = wa_cx_lock($pdo, $auth, $c)) !== '') json_response(['error' => $__cx, 'complaint' => (int)$c['cx_id']], 423);
            if ((int)$c['optout'] === 1) json_response(['error' => 'This customer opted out — do not message them.'], 422);
            if (empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) json_response(['error' => 'The 24-hour reply window is closed. Only an approved template can be sent now.'], 422);
            $f = $_FILES['file'] ?? null;
            if (!$f || !isset($f['tmp_name'])) json_response(['error' => 'Pick a photo, video or document first.'], 422);
            if ((int)($f['error'] ?? 0) !== UPLOAD_ERR_OK) json_response(['error' => ((int)$f['error'] === UPLOAD_ERR_INI_SIZE || (int)$f['error'] === UPLOAD_ERR_FORM_SIZE) ? 'This file is too big for the server. Send a smaller one.' : 'The file did not upload. Try again.'], 422);
            $tmp = (string)$f['tmp_name']; if (!wa_is_upload($tmp)) json_response(['error' => 'The file did not upload. Try again.'], 422);
            $name = preg_replace('/[^\w.\- ()\[\]]+/u', '_', basename((string)($f['name'] ?? 'file'))) ?: 'file'; $size = (int)($f['size'] ?? filesize($tmp));
            $det = ''; if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); if ($fi) { $det = (string)finfo_file($fi, $tmp); finfo_close($fi); } } if ($det === '') $det = (string)($f['type'] ?? '');
            $r = wa_media_rules($det, $name); if ($r['kind'] === '') json_response(['error' => $r['error']], 422);
            if ($size > $r['max']) json_response(['error' => 'This ' . $r['kind'] . ' is ' . round($size / 1048576, 1) . ' MB. WhatsApp allows up to ' . round($r['max'] / 1048576) . ' MB for ' . ($r['kind'] === 'image' ? 'an ' : 'a ') . $r['kind'] . '.'], 422);
            $cap = trim(mb_substr((string)($_POST['caption'] ?? ''), 0, 1000));
            $q = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q->execute([(int)$c['last_number_id']]); $pid = (string)$q->fetchColumn(); if ($pid === '') $pid = wa_cfg_val('WA_PHONE_ID');
            if ($pid === '') json_response(['error' => 'WA_PHONE_ID is missing in config.php.'], 422);
            ignore_user_abort(true);
            [$uc, $ud] = wa_media_upload($pid, $tmp, $r['mime'], $name);
            $mid = (string)($ud['id'] ?? ''); if ($uc < 200 || $uc >= 300 || $mid === '') { $e = wa_err($ud, 'Meta did not accept the file.'); wa_log($pdo, 'send', false, $key . ' · upload · ' . $e); json_response(['error' => $e], 502); }
            [$ok, $res] = wa_send_media($pdo, $c, $r['kind'], $mid, $r['mime'], $cap, $name, (int)($auth['employee_id'] ?? 0));
            if (!$ok) json_response(['error' => $res], 422);
            json_response(['ok' => true, 'wamid' => $res['wamid'], 'media_id' => $mid, 'kind' => $r['kind']]);
        }
        case 'wa.overview': {
            wa_guard_admin($pdo, $auth);
            $GLOBALS['__wa_lim_cache_ok'] = empty($_GET['live']);   // ⚡ screen only: the heavy 24-hour count is remembered for 30 s (the campaign sender never sets this)
            $d0 = date('Y-m-d 00:00:00'); $d7 = date('Y-m-d 00:00:00', strtotime('-6 days'));
            $wait = "last_in_at IS NOT NULL AND (last_out_at IS NULL OR last_out_at < last_in_at) AND optout = 0 AND status = 'open'";
            $cnt = function (string $w, array $p = []) use ($pdo) { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE $w"); $q->execute($p); return (int)$q->fetchColumn(); };
            $src = []; foreach (['query', 'campaign', 'community'] as $s) $src[$s] = ['today' => $cnt("source = ? AND first_seen >= ?", [$s, $d0]), 'week' => $cnt("source = ? AND first_seen >= ?", [$s, $d7]), 'total' => $cnt("source = ?", [$s]), 'waiting' => $cnt("source = ? AND $wait", [$s])];
            $emp = (int)($auth['employee_id'] ?? 0); $chats = wa_counts($pdo, true, $emp); if (function_exists('wa_src_counts')) $chats['src'] = wa_src_counts($pdo, true, $emp);
            $ago3 = date('Y-m-d H:i:s', time() - 180); $ago10 = date('Y-m-d H:i:s', time() - 600);
            $unpicked = $cnt("assigned_emp = 0 AND $wait AND last_in_at < ?", [$ago3]); $slow = $cnt("assigned_emp > 0 AND $wait AND last_in_at < ?", [$ago10]);
            $numbers = wa_health_all($pdo, !empty($_GET['live']));
            $tpl = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
            if (function_exists('wa_tpl_tables') && wa_tpl_tables($pdo)) { try { $wb = function_exists('wa_wabas') ? wa_wabas($pdo) : [];
                foreach ($pdo->query("SELECT * FROM wa_templates")->fetchAll() as $r) { $r['buttons'] = json_decode((string)$r['buttons'], true) ?: []; $r['examples'] = json_decode((string)$r['examples'], true) ?: []; $p = wa_tpl_public($pdo, $r, $wb); if (isset($tpl[$p['status']])) $tpl[$p['status']]++; } } catch (Throwable $e) { } }
            $tiers = array_filter(array_column($numbers, 'tier'), fn($t) => $t !== null); $limit = $tiers ? (in_array(-1, $tiers, true) ? -1 : max($tiers)) : null;   // the limit is shared by every number of the portfolio
            require_once __DIR__ . '/wa_lim.php'; $used = wa_lim_used($pdo, date('Y-m-d H:i:s', time() - 86400))['all'];
            $att = [];
            if (!$numbers) $att[] = ['level' => 'bad', 'text' => 'No WhatsApp number is connected yet.', 'go' => 'setup'];
            if ($unpicked > 0) $att[] = ['level' => 'bad', 'text' => $unpicked . ' chat' . ($unpicked === 1 ? '' : 's') . ' waiting over 3 minutes — nobody has taken ' . ($unpicked === 1 ? 'it' : 'them') . '.', 'go' => 'chats'];
            $late = null; if (!function_exists('wa_late_scan') && is_file(__DIR__ . '/wa_late.php')) require_once __DIR__ . '/wa_late.php';   // this file runs before wa_more.php loads it
            if (function_exists('wa_late_scan')) { try { wa_late_scan($pdo); $late = wa_late_summary($pdo); } catch (Throwable $e) { $late = null; } }
            if ($late && $late['now_total'] > 0) {   // ⏰ late replies come FIRST, red, with the names: these customers are waiting right now
                $who = implode(' · ', array_map(fn($p) => $p['name'] . ' ' . $p['n'] . ' (longest ' . ($p['longest'] >= 60 ? floor($p['longest'] / 60) . 'h ' . ($p['longest'] % 60) . 'm' : $p['longest'] . ' min') . ')', array_slice($late['now'], 0, 4)));
                array_unshift($att, ['level' => 'bad', 'text' => '⏰ ' . $late['now_total'] . ' assigned chat' . ($late['now_total'] === 1 ? '' : 's') . ' waiting over ' . $late['min'] . ' minutes for a reply — ' . $who, 'go' => 'grab', 'kind' => 'late']);
            } elseif ($slow > 0) $att[] = ['level' => 'bad', 'text' => $slow . ' assigned chat' . ($slow === 1 ? '' : 's') . ' waiting over 10 minutes for a reply.', 'go' => 'grab'];
            foreach ($numbers as $h) {
                $nm = $h['label'];
                if ($h['error'] !== '' && $h['status'] === '') $att[] = ['level' => 'bad', 'text' => $nm . ': Meta did not answer — ' . $h['error'], 'go' => 'setup'];
                elseif ($h['status'] !== '' && $h['status'] !== 'CONNECTED') $att[] = ['level' => 'bad', 'text' => $nm . ' is ' . strtolower(str_replace('_', ' ', $h['status'])) . ' at Meta.', 'go' => 'setup'];
                if ($h['quality'] === 'RED') $att[] = ['level' => 'bad', 'text' => $nm . ': quality rating is RED — slow down and stop marketing sends from it.', 'go' => 'numbers'];
                elseif ($h['quality'] === 'YELLOW') $att[] = ['level' => 'warn', 'text' => $nm . ': quality rating is YELLOW — customers are blocking or reporting. Send less.', 'go' => 'numbers'];
                if ($h['subscribed'] === false) $att[] = ['level' => 'bad', 'text' => $nm . ' is not connected to the webhook — messages will not arrive.', 'go' => 'setup'];
                if ($h['name_status'] !== '' && !in_array($h['name_status'], ['APPROVED', 'AVAILABLE_WITHOUT_REVIEW'], true)) $att[] = ['level' => 'warn', 'text' => $nm . ': display name is ' . strtolower(str_replace('_', ' ', $h['name_status'])) . '.', 'go' => 'numbers'];
            }
            if ($tpl['rejected'] > 0) $att[] = ['level' => 'warn', 'text' => $tpl['rejected'] . ' pre-made message' . ($tpl['rejected'] === 1 ? ' was' : 's were') . ' rejected by Meta.', 'go' => 'premade'];
            usort($att, fn($a, $b) => ($a['level'] === 'bad' ? 0 : 1) <=> ($b['level'] === 'bad' ? 0 : 1));
            json_response(['sources' => $src, 'chats' => $chats, 'numbers' => $numbers, 'limit' => ['tier' => $limit, 'used' => $used], 'templates' => $tpl, 'attention' => $att, 'late' => $late, 'at' => date('Y-m-d H:i:s')]);
        }
    }
}
