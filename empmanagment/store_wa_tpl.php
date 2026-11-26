<?php
/**
 * Store → WhatsApp → Templates: the Store's own pre-made messages (WhatsApp templates), made and sent to Meta from the Store screen (Admin / HR only).
 * They live in the same library as every other pre-made message (wa_templates + wa_tpl_waba) but are created ONLY in the Store's own WhatsApp account (never in the sales accounts).
 * Names start with tmph_store_ . A message that is already at Meta is never changed in place: an edit becomes a NEW version (name_v2, _v3 ...) so the one that works keeps working until the new one is approved.
 * Actions: st_wa.tpl_all, tpl_seed, tpl_save, tpl_check, tpl_submit, tpl_sync, tpl_test, photo_store, photo_upload.
 */

function swt_waba(PDO $pdo): string { $n = sw_number($pdo); return $n ? wa_waba_of($n) : ''; }

/** The first Store messages (drafts, nothing is sent to Meta by making them). */
function swt_defs(): array
{
    $d = st_wa_defs(); $bill = $d['tmph_store_bill']; $back = $d['tmph_store_bill_back']; $bday = $d['tmph_store_bday'];
    $stop = 'Reply STOP to stop messages'; $site = [['type' => 'URL', 'text' => 'Visit our website', 'url' => 'https://tmperfumehouse.com']];
    return [
        ['name' => 'tmph_store_bill_v2', 'category' => 'UTILITY', 'header_format' => 'IMAGE', 'photo' => 'new', 'body' => $bill['body'], 'footer' => 'TM Perfume House', 'buttons' => $bill['buttons'], 'examples' => ['body' => $bill['ex'], 'labels' => $bill['lab']], 'title' => 'Bill · new customer'],
        ['name' => 'tmph_store_bill_back_v2', 'category' => 'UTILITY', 'header_format' => 'IMAGE', 'photo' => 'back', 'body' => $back['body'], 'footer' => 'TM Perfume House', 'buttons' => [], 'examples' => ['body' => $back['ex'], 'labels' => $back['lab']], 'title' => 'Bill · returning customer'],
        ['name' => 'tmph_store_bday_v2', 'category' => 'MARKETING', 'header_format' => 'IMAGE', 'photo' => 'bday', 'body' => $bday['body'], 'footer' => $stop, 'buttons' => [], 'examples' => ['body' => $bday['ex'], 'labels' => $bday['lab']], 'title' => 'Birthday gift'],
        ['name' => 'tmph_store_finished', 'category' => 'MARKETING', 'header_format' => '', 'body' => "Hello {{1}}, your {{2}} must be finished by now 😊 Visit TM Perfume House or tap below to see what is new.", 'footer' => $stop, 'buttons' => $site, 'examples' => ['body' => ['Chetan', 'Oud Royal 50 ml'], 'labels' => ['Name', 'Perfume']], 'title' => 'Reminder · perfume finished'],
        ['name' => 'tmph_store_running', 'category' => 'MARKETING', 'header_format' => '', 'body' => "Hello {{1}}, your {{2}} is about to finish. Refill it before it ends. We would love to see you at the store.", 'footer' => $stop, 'buttons' => $site, 'examples' => ['body' => ['Chetan', 'Oud Royal 50 ml'], 'labels' => ['Name', 'Perfume']], 'title' => 'Reminder · perfume running out'],
        ['name' => 'tmph_store_offer', 'category' => 'MARKETING', 'header_format' => 'IMAGE', 'photo' => '', 'body' => "Hello {{1}}, {{2}} at TM Perfume House this week. Come and smell it in the store!", 'footer' => $stop, 'buttons' => [['type' => 'URL', 'text' => 'See what is new', 'url' => 'https://tmperfumehouse.com']], 'examples' => ['body' => ['Chetan', 'new arrivals are here'], 'labels' => ['Name', 'Offer']], 'title' => 'Store offer (with a photo)'],
    ];
}

/** One of the Store pictures (Billing / Messages) as a media-library row. kind: new | back | bday. Returns the library id or an error text. */
function swt_photo_from_store(PDO $pdo, string $kind, int $me): array
{
    $get = function (string $k) use ($pdo): string { try { $x = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = '" . $k . "'")->fetchColumn(); $j = $x ? json_decode((string)$x, true) : null; return is_array($j) ? (string)($j['photo'] ?? '') : ''; } catch (Throwable $e) { return ''; } };
    $v = $kind === 'bday' ? (function_exists('st_bday_msg') ? (string)(st_bday_msg($pdo, true)['photo'] ?? '') : '') : ($kind === 'back' ? $get('bill_image_back') : $get('bill_image'));
    if ($v === '' && $kind === 'back') $v = $get('bill_image');
    if ($v === '') { $df = dirname(__DIR__) . '/assets/img/store_bill_default.jpg'; if (is_file($df)) $v = 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($df)); }
    if (!preg_match('#^data:(image/[a-z+]+);base64,(.+)$#s', $v, $m)) return [0, 'No picture is saved for this yet. Upload one in Store → Messages, or upload a photo here.'];
    $raw = base64_decode($m[2], true); if ($raw === false || $raw === '') return [0, 'The saved picture could not be read.'];
    return swt_lib_save($pdo, $raw, $m[1], ['new' => 'Store Picture 1 (new customer)', 'back' => 'Store Picture 2 (returning customer)', 'bday' => 'Store Picture 3 (birthday)'][$kind] ?? 'Store picture', 'store_' . $kind . '.jpg', $me);
}
/** The owner's three Store pictures as they are saved in Store → Messages (small, already compressed): [kind, title, data URL, custom?]. */
function swt_store_pics(PDO $pdo): array
{
    $get = function (string $k) use ($pdo): string { try { $x = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = '" . $k . "'")->fetchColumn(); $j = $x ? json_decode((string)$x, true) : null; return is_array($j) ? (string)($j['photo'] ?? '') : ''; } catch (Throwable $e) { return ''; } };
    $df = ''; $d = dirname(__DIR__) . '/assets/img/store_bill_default.jpg'; if (is_file($d)) $df = 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($d));
    $new = $get('bill_image'); $back = $get('bill_image_back'); $bd = function_exists('st_bday_msg') ? (string)(st_bday_msg($pdo, true)['photo'] ?? '') : '';
    $o = [['new', 'Picture 1', 'New customer (bill thank-you)', $new !== '' ? $new : $df, $new !== ''], ['back', 'Picture 2', 'Returning customer (welcome back)', $back !== '' ? $back : ($new !== '' ? $new : $df), $back !== ''], ['bday', 'Picture 3', 'Birthday gift', $bd !== '' ? $bd : $df, $bd !== '']];
    return array_map(fn($x) => ['kind' => $x[0], 'name' => $x[1], 'title' => $x[2], 'thumb' => strlen($x[3]) < 400000 ? $x[3] : '', 'custom' => $x[4]], $o);
}
/** Saves image bytes into the media library (photos are made smaller first, like everywhere else). Returns [library id, error]. */
function swt_lib_save(PDO $pdo, string $raw, string $mime, string $title, string $name, int $me): array
{
    if (!function_exists('wa_lib_dir') && is_file(__DIR__ . '/wa_media.php')) require_once __DIR__ . '/wa_media.php';
    if (!function_exists('wa_lib_dir')) return [0, 'The media library is not available on this server.'];
    $shr = function_exists('wa_lib_shrink') ? wa_lib_shrink($raw, $mime) : null; if ($shr) { $raw = $shr[0]; $mime = $shr[1]; }
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) return [0, 'Meta takes only JPG or PNG photos on top of a message.'];
    if (strlen($raw) > 5 * 1048576) return [0, 'This photo is over 5 MB. Use a smaller one.'];
    try { $q = $pdo->prepare("SELECT id FROM wa_media_lib WHERE title = ? AND size = ? AND kind = 'image' LIMIT 1"); $q->execute([mb_substr($title, 0, 80), strlen($raw)]); $ex = (int)$q->fetchColumn(); if ($ex > 0) return [$ex, '']; } catch (Throwable $e) { }      // the same picture is not saved twice
    $dir = wa_lib_dir(); if (!is_dir($dir) || !is_writable($dir)) return [0, 'The server cannot save files in the wa_files folder.'];
    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($mime === 'image/png' ? '.png' : '.jpg'); if (@file_put_contents($dir . '/' . $stored, $raw) === false) return [0, 'Could not save the photo on the server.'];
    $pdo->prepare("INSERT INTO wa_media_lib (title, kind, mime, filename, size, path, mids, created_by, created_at) VALUES (?, 'image', ?, ?, ?, ?, '{}', ?, ?)")->execute([mb_substr($title, 0, 80), $mime, mb_substr($name, 0, 120), strlen($raw), $stored, $me, date('Y-m-d H:i:s')]);
    return [(int)$pdo->lastInsertId(), ''];
}
function swt_thumb(array $r): string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('wa_lib_dir')) return '';
    try { $p = wa_lib_dir() . '/' . basename((string)$r['path']); if (!is_file($p) || filesize($p) > 6 * 1048576) return ''; $im = @imagecreatefromstring((string)file_get_contents($p)); if (!$im) return '';
        $w = imagesx($im); $h = imagesy($im); $s = 96 / max(1, min($w, $h)); $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s)); $o = imagecreatetruecolor($nw, $nh); imagecopyresampled($o, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start(); imagejpeg($o, null, 70); $j = (string)ob_get_clean(); imagedestroy($im); imagedestroy($o); return 'data:image/jpeg;base64,' . base64_encode($j); } catch (Throwable $e) { return ''; }
}

/** A template row for the screen: its fields plus its state in the Store's own account. */
function swt_out(PDO $pdo, array $r, string $waba): array
{
    $t = wa_tpl_row($pdo, (int)$r['id']); if (!$t) return [];
    $st = 'draft'; $why = ''; $meta = ''; try { $q = $pdo->prepare("SELECT status, reason, meta_id FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $q->execute([(int)$r['id'], $waba]); $x = $q->fetch(); if ($x) { $st = strtoupper((string)$x['status']) ?: 'PENDING'; $why = (string)$x['reason']; $meta = (string)$x['meta_id']; } } catch (Throwable $e) { }
    $sentAnywhere = false; try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([(int)$r['id']]); $sentAnywhere = (int)$q->fetchColumn() > 0; } catch (Throwable $e) { }
    $photo = ''; if ((int)($t['header_lib_id'] ?? 0) > 0) { $lr = wa_tpl_lib_row($pdo, (int)$t['header_lib_id']); if ($lr) $photo = (string)$lr['title']; }
    $ex = is_array($t['examples'] ?? null) ? $t['examples'] : [];
    return ['id' => (int)$t['id'], 'language' => (string)($t['language'] ?? 'en'), 'name' => (string)$t['name'], 'category' => (string)$t['category'], 'header_format' => (string)($t['header_format'] ?? ''), 'header_lib_id' => (int)($t['header_lib_id'] ?? 0), 'photo' => $photo, 'body' => (string)$t['body'], 'footer' => (string)($t['footer'] ?? ''),
        'buttons' => array_values((array)($t['buttons'] ?? [])), 'examples' => array_values((array)($ex['body'] ?? [])), 'labels' => array_values((array)($ex['labels'] ?? [])), 'status' => $st, 'reason' => $why, 'meta_id' => $meta, 'locked' => $sentAnywhere, 'version' => (int)($t['version'] ?? 1), 'title' => (string)($t['purpose'] ?? '')];
}
function swt_draft_in(array $in): array
{
    $ex = []; foreach ((array)($in['examples'] ?? []) as $x) $ex[] = (string)$x; $lab = []; foreach ((array)($in['labels'] ?? []) as $x) $lab[] = (string)$x;
    return ['name' => (string)($in['name'] ?? ''), 'language' => in_array((string)($in['language'] ?? 'en'), ['en', 'gu', 'hi'], true) ? (string)$in['language'] : 'en', 'category' => (string)($in['category'] ?? 'MARKETING'), 'header_format' => (string)($in['header_format'] ?? ''), 'header_lib_id' => (int)($in['header_lib_id'] ?? 0), 'body' => (string)($in['body'] ?? ''), 'footer' => (string)($in['footer'] ?? ''),
        'buttons' => is_array($in['buttons'] ?? null) ? $in['buttons'] : [], 'folder' => 'Store', 'examples' => ['body' => $ex, 'labels' => $lab]];
}

function swt_handle(PDO $pdo, string $action, int $me, bool $post): void
{
    $num = sw_number($pdo); if (!$num) json_response(['error' => 'Choose the Store number in Setup first.'], 409); $waba = wa_waba_of($num); if ($waba === '') json_response(['error' => 'The Store number has no WhatsApp Business Account ID. Add it in WhatsApp → Numbers.'], 422);
    if (!function_exists('wa_app_id') && is_file(__DIR__ . '/wa_more.php')) { try { require_once __DIR__ . '/wa_more.php'; } catch (Throwable $e) { error_log('swt: wa_more ' . $e->getMessage()); } }      // the Meta app id (needed to put a photo on top) is found there
    if (function_exists('wa_tpl_need_media')) wa_tpl_need_media();
    if ($action === 'st_wa.tpl_all') {
        $rows = $pdo->query("SELECT id, name FROM wa_templates WHERE name LIKE 'tmph\\_store\\_%' ORDER BY id")->fetchAll(); $out = [];
        foreach ($rows as $r) {
            if (strpos((string)$r['name'], 'tmph_store_followup_') === 0) continue;      // the follow-ups are looked after on their own screen (Follow-ups → Messages)
            if (in_array((string)$r['name'], ['tmph_store_bill', 'tmph_store_bill_back', 'tmph_store_bday'], true)) { $hq = $pdo->prepare("SELECT 1 FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $hq->execute([(int)$r['id'], $waba]); if (!$hq->fetchColumn()) continue; }      // the older plain-text versions (made for the other accounts) are not the Store's to see
            $o = swt_out($pdo, $r, $waba); if ($o) $out[] = $o;
        }
        $photos = []; try { foreach ($pdo->query("SELECT * FROM wa_media_lib WHERE kind = 'image' AND mime IN ('image/jpeg','image/png') ORDER BY id DESC LIMIT 40")->fetchAll() as $r) $photos[] = ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'thumb' => swt_thumb($r)]; } catch (Throwable $e) { }
        json_response(['templates' => $out, 'photos' => $photos, 'store_pics' => swt_store_pics($pdo), 'waba' => $waba, 'app_id_ok' => (function_exists('wa_app_id') ? wa_app_id($pdo) : wa_cfg_val('WA_APP_ID')) !== '']);
    }
    if (!$post) json_response(['error' => 'Method not allowed.'], 405); $in = json_input();
    if ($action === 'st_wa.tpl_seed') {
        $made = []; $skipped = [];
        foreach (swt_defs() as $d) {
            $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$d['name']]); if ($q->fetchColumn()) { $skipped[] = $d['name']; continue; }
            $lib = 0; if (!empty($d['photo'])) { [$lib, $e] = swt_photo_from_store($pdo, (string)$d['photo'], $me); if ($lib <= 0) $lib = 0; }      // the photo is attached when the Store picture exists; else the owner picks one
            $dr = ['name' => $d['name'], 'category' => $d['category'], 'header_format' => $d['header_format'], 'header_lib_id' => $lib, 'body' => $d['body'], 'footer' => $d['footer'], 'buttons' => $d['buttons'], 'examples' => $d['examples'], 'folder' => 'Store'];
            [$t, $err] = wa_tpl_clean($dr, true); if ($err !== '') { $skipped[] = $d['name'] . ' (' . $err . ')'; continue; }
            $id = wa_tpl_insert($pdo, $t, $me, $d['title'], 'Store message'); if ($id) $made[] = $d['name'];
        }
        json_response(['ok' => true, 'made' => $made, 'skipped' => $skipped]);
    }
    if ($action === 'st_wa.photo_store') { [$id, $err] = swt_photo_from_store($pdo, (string)($in['kind'] ?? 'new'), $me); if ($id <= 0) json_response(['error' => $err], 422); json_response(['ok' => true, 'id' => $id]); }
    if ($action === 'st_wa.photo_upload') {
        $f = $_FILES['file'] ?? null; if (!$f || (int)($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_file((string)$f['tmp_name'])) json_response(['error' => 'The photo did not arrive. Try a smaller one.'], 422);
        $raw = (string)file_get_contents((string)$f['tmp_name']); $mime = ''; if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); if ($fi) { $mime = (string)finfo_file($fi, (string)$f['tmp_name']); finfo_close($fi); } }
        [$id, $err] = swt_lib_save($pdo, $raw, $mime, trim((string)($_POST['title'] ?? '')) ?: pathinfo((string)$f['name'], PATHINFO_FILENAME), (string)$f['name'], $me); if ($id <= 0) json_response(['error' => $err], 422); json_response(['ok' => true, 'id' => $id]);
    }
    if ($action === 'st_wa.tpl_check') {
        $d = swt_draft_in($in); $full = !empty($in['for_send']); [$t, $err] = wa_tpl_clean($d, !$full); $body = (string)$d['body'];
        $warn = $t['warnings'] ?? []; $promo = wa_tpl_promo_hits($body . ' ' . $d['footer']); $hint = '';
        if ($d['category'] === 'UTILITY' && $promo) $hint = 'This is set to Utility but sounds promotional. Meta will probably make it Marketing.'; elseif ($d['category'] === 'MARKETING') $hint = 'Marketing: Meta charges for each message sent outside a customer\'s 24-hour window. Keep the "Reply STOP" line.';
        json_response(['ok' => $err === '', 'problem' => $err, 'warnings' => $warn, 'promo' => $promo, 'hint' => $hint]);
    }
    if ($action === 'st_wa.tpl_save') {
        $id = (int)($in['id'] ?? 0); $d = swt_draft_in($in); [$t, $err] = wa_tpl_clean($d, true); if ($err !== '') json_response(['error' => $err], 422);
        if ($id > 0) {
            $row = wa_tpl_row($pdo, $id); if (!$row || strpos((string)$row['name'], 'tmph_store_') !== 0) json_response(['error' => 'Message not found.'], 404);
            $q = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([$id]);
            if ((int)$q->fetchColumn() > 0) {      // already at Meta: a NEW version, the old one keeps working
                $base = preg_replace('/_v\d+$/', '', (string)$row['name']); $v = max(2, (int)($row['version'] ?? 1) + 1); $nm = $base . '_v' . $v; $c = $pdo->prepare("SELECT 1 FROM wa_templates WHERE name = ? AND language = ?"); $lgc = (string)($row['language'] ?? 'en'); $c->execute([$nm, $lgc]); while ($c->fetchColumn()) { $v++; $nm = $base . '_v' . $v; $c->execute([$nm, $lgc]); }
                $t['name'] = $nm; $t['family_id'] = (int)($row['family_id'] ?? 0); $t['version'] = $v; $nid = wa_tpl_insert($pdo, $t, $me, (string)($row['purpose'] ?? 'Store message'), 'Store message (new version)'); json_response(['ok' => true, 'id' => $nid, 'new_version' => true, 'name' => $nm]);
            }
            $pdo->prepare("UPDATE wa_templates SET category = ?, header_format = ?, header_text = ?, header_lib_id = ?, body = ?, footer = ?, buttons = ?, examples = ? WHERE id = ?")->execute([$t['category'], $t['header_format'], $t['header_text'], (int)$t['header_lib_id'], $t['body'], $t['footer'], json_encode($t['buttons'], JSON_UNESCAPED_UNICODE), json_encode($t['examples'], JSON_UNESCAPED_UNICODE), $id]);
            json_response(['ok' => true, 'id' => $id]);
        }
        $nm = wa_tpl_name((string)$d['name']); if (strpos($nm, 'tmph_store_') !== 0) $nm = 'tmph_store_' . ltrim(preg_replace('/^tmph_/', '', $nm), '_'); $t['name'] = $nm; $nid = wa_tpl_insert($pdo, $t, $me, 'Store message', 'Store message'); json_response(['ok' => true, 'id' => $nid]);
    }
    if ($action === 'st_wa.tpl_submit') {
        $id = (int)($in['id'] ?? 0); $row = wa_tpl_row($pdo, $id); if (!$row || strpos((string)$row['name'], 'tmph_store_') !== 0) json_response(['error' => 'Message not found.'], 404);
        $d = ['name' => $row['name'], 'language' => $row['language'], 'category' => $row['category'], 'header_format' => $row['header_format'], 'header_text' => $row['header_text'] ?? '', 'header_lib_id' => (int)($row['header_lib_id'] ?? 0), 'body' => $row['body'], 'footer' => $row['footer'], 'buttons' => $row['buttons'], 'examples' => $row['examples']];
        [$t, $err] = wa_tpl_clean($d, false); if ($err !== '') json_response(['error' => $err], 422); $me2 = wa_tpl_media_check($pdo, $row); if ($me2 !== '') json_response(['error' => $me2], 422);
        $q = $pdo->prepare("SELECT status FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $q->execute([$id, $waba]); $cur = $q->fetchColumn(); if ($cur !== false && strtoupper((string)$cur) !== 'REJECTED') json_response(['error' => 'This message is already at Meta (' . strtolower((string)$cur) . ').'], 409);
        [$ok, $why] = wa_tpl_create_at($pdo, $row, $waba); if (!$ok) json_response(['error' => (string)$why], 422); json_response(['ok' => true]);
    }
    if ($action === 'st_wa.tpl_sync') {
        $n = 0; $url = '/' . $waba . '/message_templates?limit=100&fields=id,name,language,status,category,rejected_reason'; $err = '';
        for ($page = 0; $page < 5 && $url; $page++) {
            [$c, $d] = wa_api('GET', $url); if ($c < 200 || $c >= 300 || !is_array($d)) { $err = wa_err(is_array($d) ? $d : null); break; }
            foreach ((array)($d['data'] ?? []) as $x) { $nm = (string)($x['name'] ?? ''); if (strpos($nm, 'tmph_store_') !== 0) continue; $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = ?"); $q->execute([$nm, (string)($x['language'] ?? 'en')]); $tid = (int)$q->fetchColumn(); if (!$tid) continue;
                $pdo->prepare("UPDATE wa_tpl_waba SET status = ?, reason = ?, meta_id = ?, updated_at = ?, category = ? WHERE tpl_id = ? AND waba_id = ?")->execute([strtoupper((string)($x['status'] ?? 'PENDING')), mb_substr((string)($x['rejected_reason'] ?? ''), 0, 300), (string)($x['id'] ?? ''), date('Y-m-d H:i:s'), strtoupper((string)($x['category'] ?? '')), $tid, $waba]); $n += 1; }
            $url = ''; $nx = (string)($d['paging']['next'] ?? ''); if ($nx !== '' && preg_match('#graph\.facebook\.com/[^/]+(/.+)$#', $nx, $m)) $url = $m[1];
        }
        if ($err !== '') json_response(['error' => 'Meta did not answer: ' . $err], 502); json_response(['ok' => true, 'updated' => $n]);
    }
    if ($action === 'st_wa.tpl_test') {
        $t = wa_tpl_row($pdo, (int)($in['id'] ?? 0)); $p = wa_key($in['phone'] ?? ''); if (!$t || strlen($p) !== 10) json_response(['error' => 'Write your 10-digit mobile number.'], 422); if (!sw_tpl_ok($pdo, $num, (int)$t['id'])) json_response(['error' => 'Meta has not approved this message yet.'], 422);
        $vars = array_map(fn($x) => mb_substr(trim((string)$x), 0, 300), (array)($in['vars'] ?? [])); $need = count(wa_tpl_vars((string)$t['body'])); for ($i = 0; $i < $need; $i++) if (($vars[$i] ?? '') === '') json_response(['error' => 'Fill the variable {{' . ($i + 1) . '}}.'], 422);
        $b = wa_tpl_send_components($pdo, $t, array_slice($vars, 0, $need), (string)$num['phone_id'], []); if (empty($b['ok'])) json_response(['error' => (string)($b['error'] ?? 'Could not build the message.')], 422);
        $pl = ['messaging_product' => 'whatsapp', 'to' => sw_to($p), 'type' => 'template', 'template' => ['name' => $t['name'], 'language' => ['code' => $t['language']]]]; if (!empty($b['components'])) $pl['template']['components'] = $b['components'];
        [$code, $d] = wa_api('POST', '/' . $num['phone_id'] . '/messages', $pl); if ($code < 200 || $code >= 300 || empty($d['messages'][0]['id'])) json_response(['error' => wa_err(is_array($d) ? $d : null)], 422); json_response(['ok' => true]);
    }
    json_response(['error' => 'Unknown action.'], 404);
}
