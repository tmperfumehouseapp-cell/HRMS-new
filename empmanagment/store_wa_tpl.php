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
    $lt = ['new' => 'Store Picture 1 (new customer)', 'back' => 'Store Picture 2 (returning customer)', 'bday' => 'Store Picture 3 (birthday)'];
    return array_map(fn($x) => ['kind' => $x[0], 'name' => $x[1], 'title' => $x[2], 'lib_title' => $lt[$x[0]], 'thumb' => strlen($x[3]) < 400000 ? $x[3] : '', 'custom' => $x[4]], $o);
}
/** Saves image bytes into the media library (photos are made smaller first, like everywhere else). Returns [library id, error]. */
function swt_lib_save(PDO $pdo, string $raw, string $mime, string $title, string $name, int $me): array
{
    if (!function_exists('wa_lib_dir') && is_file(__DIR__ . '/wa_media.php')) require_once __DIR__ . '/wa_media.php';
    if (!function_exists('wa_lib_dir')) return [0, 'The media library is not available on this server.'];
    $shr = function_exists('wa_lib_shrink') && strpos($mime, 'image/') === 0 ? wa_lib_shrink($raw, $mime) : null; if ($shr) { $raw = $shr[0]; $mime = $shr[1]; }
    $kind = in_array($mime, ['image/jpeg', 'image/png'], true) ? 'image' : ($mime === 'video/mp4' ? 'video' : ($mime === 'application/pdf' ? 'document' : ''));
    if ($kind === '') return [0, 'On top of a message Meta takes only a JPG / PNG photo, an MP4 video or a PDF.'];
    if ($kind === 'image' && strlen($raw) > 5 * 1048576) return [0, 'This photo is over 5 MB. Use a smaller one.'];
    if ($kind !== 'image' && strlen($raw) > 16 * 1048576) return [0, 'This ' . ($kind === 'video' ? 'video' : 'PDF') . ' is over 16 MB. Use a smaller one.'];
    try { $q = $pdo->prepare("SELECT id FROM wa_media_lib WHERE title = ? AND size = ? AND kind = ? LIMIT 1"); $q->execute([mb_substr($title, 0, 80), strlen($raw), $kind]); $ex = (int)$q->fetchColumn(); if ($ex > 0) return [$ex, '']; } catch (Throwable $e) { }      // the same picture is not saved twice
    $dir = wa_lib_dir(); if (!is_dir($dir) || !is_writable($dir)) return [0, 'The server cannot save files in the wa_files folder.'];
    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ['image/png' => '.png', 'video/mp4' => '.mp4', 'application/pdf' => '.pdf'][$mime] ?? '.jpg'; if (@file_put_contents($dir . '/' . $stored, $raw) === false) return [0, 'Could not save the photo on the server.'];
    $pdo->prepare("INSERT INTO wa_media_lib (title, kind, mime, filename, size, path, mids, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, '{}', ?, ?)")->execute([mb_substr($title, 0, 80), $kind, $mime, mb_substr($name, 0, 120), strlen($raw), $stored, $me, date('Y-m-d H:i:s')]);
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
    $st = 'draft'; $why = ''; $meta = ''; try { $q = $pdo->prepare("SELECT status, reason, meta_id FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $q->execute([(int)$r['id'], $waba]); $x = $q->fetch(); if ($x) { $st = strtoupper((string)$x['status']) ?: 'PENDING'; $why = (string)$x['reason']; if (strtoupper($why) === 'NONE') $why = ''; $meta = (string)$x['meta_id']; } } catch (Throwable $e) { }
    $sentAnywhere = false; try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([(int)$r['id']]); $sentAnywhere = (int)$q->fetchColumn() > 0; } catch (Throwable $e) { }
    $photo = ''; if ((int)($t['header_lib_id'] ?? 0) > 0) { $lr = wa_tpl_lib_row($pdo, (int)$t['header_lib_id']); if ($lr) $photo = (string)$lr['title']; }
    $ex = is_array($t['examples'] ?? null) ? $t['examples'] : [];
    return ['id' => (int)$t['id'], 'language' => (string)($t['language'] ?? 'en'), 'name' => (string)$t['name'], 'category' => (string)$t['category'], 'header_format' => (string)($t['header_format'] ?? ''), 'header_lib_id' => (int)($t['header_lib_id'] ?? 0), 'header_text' => (string)($t['header_text'] ?? ''), 'photo' => $photo, 'body' => (string)$t['body'], 'footer' => (string)($t['footer'] ?? ''),
        'buttons' => array_values((array)($t['buttons'] ?? [])), 'examples' => array_values((array)($ex['body'] ?? [])), 'labels' => array_values((array)($ex['labels'] ?? [])), 'status' => $st, 'reason' => $why, 'meta_id' => $meta, 'locked' => $sentAnywhere, 'version' => (int)($t['version'] ?? 1), 'title' => (string)($t['purpose'] ?? '')];
}
function swt_draft_in(array $in): array
{
    if (is_array($in['buttons'] ?? null)) $in['buttons'] = st_wa_btns_clean($in['buttons']);      // Meta refuses emoji in buttons
    $ex = []; foreach ((array)($in['examples'] ?? []) as $x) $ex[] = (string)$x; $lab = []; foreach ((array)($in['labels'] ?? []) as $x) $lab[] = (string)$x;
    return ['name' => (string)($in['name'] ?? ''), 'language' => in_array((string)($in['language'] ?? 'en'), ['en', 'gu', 'hi'], true) ? (string)$in['language'] : 'en', 'category' => (string)($in['category'] ?? 'MARKETING'), 'header_format' => in_array((string)($in['header_format'] ?? ''), ['', 'TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'], true) ? (string)($in['header_format'] ?? '') : '', 'header_text' => mb_substr(trim((string)($in['header_text'] ?? '')), 0, 60), 'header_lib_id' => (int)($in['header_lib_id'] ?? 0), 'body' => (string)($in['body'] ?? ''), 'footer' => (string)($in['footer'] ?? ''),
        'buttons' => is_array($in['buttons'] ?? null) ? $in['buttons'] : [], 'folder' => 'Store', 'examples' => ['body' => $ex, 'labels' => $lab]];
}

function swt_handle(PDO $pdo, string $action, int $me, bool $post): void
{
    $num = sw_number($pdo); if (!$num) json_response(['error' => 'Choose the Store number in Setup first.'], 409); $waba = wa_waba_of($num); if ($waba === '') json_response(['error' => 'The Store number has no WhatsApp Business Account ID. Add it in WhatsApp → Numbers.'], 422);
    if (!function_exists('wa_app_id') && is_file(__DIR__ . '/wa_more.php')) { try { require_once __DIR__ . '/wa_more.php'; } catch (Throwable $e) { error_log('swt: wa_more ' . $e->getMessage()); } }      // the Meta app id (needed to put a photo on top) is found there
    if (function_exists('wa_tpl_need_media')) wa_tpl_need_media();
    if ($action === 'st_wa.tpl_all') {
        swt_seed_util_bill($pdo, $me);      // the plain Utility bill message (sent when the Marketing one fails) is ready as a draft once
        $rows = $pdo->query("SELECT id, name FROM wa_templates WHERE name LIKE 'tmph\\_store\\_%' ORDER BY id")->fetchAll(); $out = []; $used = swt_used($pdo);
        foreach ($rows as $r) {
            if (in_array((string)$r['name'], ['tmph_store_bill', 'tmph_store_bill_back', 'tmph_store_bday'], true)) { $hq = $pdo->prepare("SELECT 1 FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $hq->execute([(int)$r['id'], $waba]); if (!$hq->fetchColumn()) continue; }      // the older plain-text versions (made for the other accounts) are not the Store's to see
            $o = swt_out($pdo, $r, $waba); if (!$o) continue;
            $o['used'] = $used[$o['id']] ?? []; $o['family'] = swt_family($o['name']); $o['machine'] = (bool)preg_match('/_\d{6,}(_v\d+)?$/', $o['name']);
            $q2 = $pdo->prepare("SELECT category, COUNT(*) n, SUM(meta_id <> '') m FROM wa_tpl_waba WHERE tpl_id = ? GROUP BY category"); $q2->execute([$o['id']]); $o['meta_category'] = ''; $o['at_meta'] = false; foreach ($q2->fetchAll() as $x) { if ((string)$x['category'] !== '') $o['meta_category'] = strtoupper((string)$x['category']); if ((int)$x['m'] > 0) $o['at_meta'] = true; }
            $out[] = $o;
        }
        $photos = []; try { foreach ($pdo->query("SELECT * FROM wa_media_lib WHERE kind = 'image' AND mime IN ('image/jpeg','image/png') ORDER BY id DESC LIMIT 40")->fetchAll() as $r) $photos[] = ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'thumb' => swt_thumb($r)]; } catch (Throwable $e) { }
        $media = []; try { foreach ($pdo->query("SELECT id, title, kind, size FROM wa_media_lib WHERE kind IN ('video','document') AND mime IN ('video/mp4','application/pdf') ORDER BY id DESC LIMIT 40")->fetchAll() as $r) $media[] = ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'kind' => (string)$r['kind'], 'mb' => round((int)$r['size'] / 1048576, 1)]; } catch (Throwable $e) { }
        json_response(['templates' => $out, 'photos' => $photos, 'media' => $media, 'store_pics' => swt_store_pics($pdo), 'waba' => $waba, 'app_id_ok' => (function_exists('wa_app_id') ? wa_app_id($pdo) : wa_cfg_val('WA_APP_ID')) !== '']);
    }
    if ($action === 'st_wa.tpl_diag') json_response(swt_diag($pdo, $num, $waba));
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
    if ($action === 'st_wa.tpl_del') {      // only messages that never reached Meta (drafts, or refused before Meta made them)
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? [$in['id'] ?? 0]))))); $del = 0; $kept = [];
        $atMeta = []; $url = '/' . $waba . '/message_templates?limit=100&fields=name,language'; for ($pg = 0; $pg < 8 && $url; $pg++) { [$cc, $dd] = wa_api('GET', $url); if ($cc < 200 || $cc >= 300 || !is_array($dd)) json_response(['error' => 'Meta did not answer, so nothing was deleted (to be safe). Try again.'], 502); foreach ((array)($dd['data'] ?? []) as $x) $atMeta[(string)$x['name'] . '|' . (string)$x['language']] = 1; $url = ''; $nx = (string)($dd['paging']['next'] ?? ''); if ($nx !== '' && preg_match('#graph\.facebook\.com/[^/]+(/.+)$#', $nx, $mm)) $url = $mm[1]; }      // a message Meta has is never deleted here
        foreach ($ids as $id) {
            $r = wa_tpl_row($pdo, $id); if (!$r || strpos((string)$r['name'], 'tmph_store_') !== 0) continue;
            if (isset($atMeta[(string)$r['name'] . '|' . (string)($r['language'] ?? 'en')])) { $kept[] = (string)$r['name'] . ' (it is at Meta)'; continue; }
            $q = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_waba WHERE tpl_id = ? AND (meta_id <> '' OR UPPER(status) IN ('APPROVED','PENDING','IN_APPEAL','PAUSED'))"); $q->execute([$id]); if ((int)$q->fetchColumn() > 0) { $kept[] = (string)$r['name']; continue; }
            if (!empty(swt_used($pdo)[$id])) { $kept[] = (string)$r['name']; continue; }
            $pdo->prepare("DELETE FROM wa_tpl_waba WHERE tpl_id = ?")->execute([$id]); $pdo->prepare("DELETE FROM wa_templates WHERE id = ?")->execute([$id]); $del++;
        }
        json_response(['ok' => true, 'deleted' => $del, 'kept' => $kept]);
    }
    if ($action === 'st_wa.tpl_photo') {      // the photo / video / PDF on top is sent with each message, so it can change on an approved message without asking Meta again
        $id = (int)($in['id'] ?? 0); $lib = (int)($in['lib_id'] ?? 0); $r = wa_tpl_row($pdo, $id); if (!$r || strpos((string)$r['name'], 'tmph_store_') !== 0) json_response(['error' => 'Message not found.'], 404);
        if (!in_array(strtoupper((string)($r['header_format'] ?? '')), ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) json_response(['error' => 'This message has no photo on top.'], 422);
        $lr = wa_tpl_lib_row($pdo, $lib); if (!$lr) json_response(['error' => 'Photo not found.'], 404); $pdo->prepare("UPDATE wa_templates SET header_lib_id = ? WHERE id = ?")->execute([$lib, $id]); json_response(['ok' => true, 'title' => (string)$lr['title']]);
    }
    if ($action === 'st_wa.tpl_copy') {      // a new draft from an existing message (any status): same text, photo, buttons; a free name
        $r = wa_tpl_row($pdo, (int)($in['id'] ?? 0)); if (!$r || strpos((string)$r['name'], 'tmph_store_') !== 0) json_response(['error' => 'Message not found.'], 404);
        $base = substr(preg_replace('/(_copy\d*)?(_v\d+)?$/', '', (string)$r['name']), 0, 50); $lg = (string)($r['language'] ?? 'en'); $n = 1; $c = $pdo->prepare("SELECT 1 FROM wa_templates WHERE name = ? AND language = ?");
        do { $nm = $base . '_copy' . ($n > 1 ? $n : ''); $c->execute([$nm, $lg]); $n++; } while ($c->fetchColumn());
        $d = ['name' => $nm, 'language' => $lg, 'category' => (string)$r['category'], 'header_format' => (string)($r['header_format'] ?? ''), 'header_text' => (string)($r['header_text'] ?? ''), 'header_lib_id' => (int)($r['header_lib_id'] ?? 0), 'body' => (string)$r['body'], 'footer' => (string)($r['footer'] ?? ''), 'buttons' => st_wa_btns_clean(array_values((array)($r['buttons'] ?? []))), 'examples' => is_array($r['examples'] ?? null) ? $r['examples'] : [], 'folder' => 'Store'];
        [$t, $err] = wa_tpl_clean($d, true); if ($err !== '') json_response(['error' => $err], 422); $id = wa_tpl_insert($pdo, $t, $me, (string)($r['purpose'] ?? 'Store message'), 'Copy of ' . $r['name']); json_response(['ok' => true, 'id' => $id, 'name' => $nm]);
    }
    if ($action === 'st_wa.tpl_fix') json_response(swt_fix_all($pdo, $waba, $me, !empty($in['dry'])));      // 🔧 every rejected Store message: fix what rules can fix and send it to Meta again
    if ($action === 'st_wa.tpl_ai') { [$ok, $d] = swt_ai($in); if (!$ok) json_response(['error' => $d], 502); json_response(['ok' => true] + $d); }
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
        $nb = st_wa_btns_clean(array_values((array)($row['buttons'] ?? []))); if ($nb !== array_values((array)($row['buttons'] ?? []))) { $pdo->prepare("UPDATE wa_templates SET buttons = ? WHERE id = ?")->execute([json_encode($nb, JSON_UNESCAPED_UNICODE), $id]); $row['buttons'] = $nb; }      // Meta refuses emoji in buttons
        $d = ['name' => $row['name'], 'language' => $row['language'], 'category' => $row['category'], 'header_format' => $row['header_format'], 'header_text' => $row['header_text'] ?? '', 'header_lib_id' => (int)($row['header_lib_id'] ?? 0), 'body' => $row['body'], 'footer' => $row['footer'], 'buttons' => $row['buttons'], 'examples' => $row['examples']];
        [$t, $err] = wa_tpl_clean($d, false); if ($err !== '') json_response(['error' => $err], 422); $me2 = wa_tpl_media_check($pdo, $row); if ($me2 !== '') json_response(['error' => $me2], 422);
        $q = $pdo->prepare("SELECT status FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $q->execute([$id, $waba]); $cur = $q->fetchColumn(); if ($cur !== false && strtoupper((string)$cur) !== 'REJECTED') json_response(['error' => 'This message is already at Meta (' . strtolower((string)$cur) . ').'], 409);
        [$ok, $why] = wa_tpl_create_at($pdo, $row, $waba); if (!$ok) json_response(['error' => (string)$why], 422); json_response(['ok' => true]);
    }
    if ($action === 'st_wa.tpl_sync') {      // also saves messages HRMS still thinks are drafts but Meta has (approved / pending / rejected)
        [$n, $err] = st_wa_tpl_sync($pdo, $waba); if ($err !== '' && $n === 0) json_response(['error' => 'Meta did not answer: ' . $err], 502); json_response(['ok' => true, 'updated' => $n]);
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

/** Our own checks of one template against the rules Meta usually rejects for. Returns short English notes (for the copied report). */
function swt_lint(array $t): array
{
    $o = []; $b = (string)($t['body'] ?? ''); $lg = (string)($t['language'] ?? 'en'); $cat = strtoupper((string)($t['category'] ?? '')); $hf = strtoupper((string)($t['header_format'] ?? ''));
    $vars = wa_tpl_vars($b); $tb = trim($b);
    if (preg_match('/^\{\{\s*\d+\s*\}\}/u', $tb)) $o[] = 'Body STARTS with a variable (Meta rejects this).';
    if (preg_match('/\{\{\s*\d+\s*\}\}[\s.!?,]*$/u', $tb)) $o[] = 'Body ENDS with a variable (Meta rejects this).';
    if (preg_match('/\}\}\s*\{\{/u', $b)) $o[] = 'Two variables side by side (Meta rejects this).';
    if ($vars && $vars !== range(1, count($vars))) $o[] = 'Variables are not 1,2,3... in order.';
    $words = count(preg_split('/\s+/u', trim(preg_replace('/\{\{\s*\d+\s*\}\}/u', ' ', $b)), -1, PREG_SPLIT_NO_EMPTY));
    if ($vars && $words < 3 * count($vars) + 2) $o[] = 'Too many variables for so few words (' . count($vars) . ' variables, ' . $words . ' words).';
    $ex = (array)(($t['examples']['body'] ?? null) ?? []); if (count($vars) > count(array_filter($ex, fn($x) => trim((string)$x) !== ''))) $o[] = 'A variable has no example value.';
    if (mb_strlen($b) > 1024) $o[] = 'Body is longer than 1024 letters.';
    if (preg_match("/\n\s*\n\s*\n/u", $b)) $o[] = 'Three or more line breaks in a row.';
    if (mb_strlen((string)($t['footer'] ?? '')) > 60) $o[] = 'Footer is longer than 60 letters.';
    foreach ((array)($t['buttons'] ?? []) as $bt) { if (mb_strlen((string)($bt['text'] ?? '')) > 25) $o[] = 'Button "' . $bt['text'] . '" is longer than 25 letters.'; if (function_exists('st_wa_btn_text') && st_wa_btn_text((string)($bt['text'] ?? '')) !== trim((string)($bt['text'] ?? ''))) $o[] = 'Button "' . $bt['text'] . '" has emoji / formatting (Meta refuses this).'; }
    $gu = preg_match_all('/[\x{0A80}-\x{0AFF}]/u', $b); $hi = preg_match_all('/[\x{0900}-\x{097F}]/u', $b); $la = preg_match_all('/[A-Za-z]/u', preg_replace('/\{\{\s*\d+\s*\}\}|TM Perfume House|STOP/u', '', $b));
    if ($lg === 'gu' && $gu < 10) $o[] = 'Language is Gujarati but the body is not in Gujarati letters.';
    if ($lg === 'hi' && $hi < 10) $o[] = 'Language is Hindi but the body is not in Hindi letters.';
    if (strpos($lg, 'en') === 0 && ($gu + $hi) > $la) $o[] = 'Language is English but the body is in ' . ($gu > $hi ? 'Gujarati' : 'Hindi') . ' letters (choose the right language).';
    if (in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && (int)($t['header_lib_id'] ?? 0) <= 0) $o[] = 'Photo on top is switched on but no photo is chosen.';
    if ($cat === 'UTILITY' && function_exists('wa_tpl_promo_hits') && ($h = wa_tpl_promo_hits($b . ' ' . ($t['footer'] ?? '')))) $o[] = 'Set as Utility but has selling words (' . implode(', ', array_slice($h, 0, 4)) . ') - Meta makes it Marketing or rejects it.';
    if ($cat === 'MARKETING' && stripos((string)($t['footer'] ?? '') . $b, 'STOP') === false) $o[] = 'Marketing without a "STOP" line.';
    return $o;
}
/** 🩺 Diagnose: every Store template with HRMS status, the LIVE answer from Meta (status, category, rejected reason, quality), the last submit errors and our own checks - as one plain text to copy. Reads only, changes nothing. */
function swt_diag(PDO $pdo, array $num, string $waba): array
{
    $live = []; $lerr = ''; $url = '/' . $waba . '/message_templates?limit=100&fields=id,name,language,status,category,rejected_reason,quality_score,previous_category';
    for ($page = 0; $page < 8 && $url; $page++) {
        [$c, $d] = wa_api('GET', $url); if ($c < 200 || $c >= 300 || !is_array($d)) { $lerr = wa_err(is_array($d) ? $d : null); break; }
        foreach ((array)($d['data'] ?? []) as $x) { $nm = (string)($x['name'] ?? ''); if (strpos($nm, 'tmph_store_') === 0) $live[$nm . '|' . (string)($x['language'] ?? '')] = $x; }
        $url = ''; $nx = (string)($d['paging']['next'] ?? ''); if ($nx !== '' && preg_match('#graph\.facebook\.com/[^/]+(/.+)$#', $nx, $m)) $url = $m[1];
    }
    $appOk = (function_exists('wa_app_id') ? wa_app_id($pdo) : (function_exists('wa_cfg_val') ? wa_cfg_val('WA_APP_ID') : '')) !== '';
    $L = ['STORE TEMPLATE DIAGNOSE · ' . date('d-m-Y H:i'), 'Store number: ' . (string)(($num['display'] ?? '') ?: ($num['label'] ?? '')) . ' · WABA ' . $waba . ' · Meta app id for photos: ' . ($appOk ? 'found' : 'NOT FOUND') . ($lerr !== '' ? ' · Meta list error: ' . $lerr : ' · Meta has ' . count($live) . ' Store templates')];
    $items = []; $seen = [];
    foreach ($pdo->query("SELECT id, name FROM wa_templates WHERE name LIKE 'tmph\\_store\\_%' ORDER BY id")->fetchAll() as $r) {
        $t = wa_tpl_row($pdo, (int)$r['id']); if (!$t) continue; $lg = (string)($t['language'] ?? 'en');
        $q = $pdo->prepare("SELECT status, reason, meta_id, category FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $q->execute([(int)$t['id'], $waba]); $h = $q->fetch() ?: [];
        $m = $live[$t['name'] . '|' . $lg] ?? null; $seen[$t['name'] . '|' . $lg] = 1;
        $subs = []; try { $s = $pdo->prepare("SELECT at, ok, note FROM wa_tpl_subs WHERE tpl_id = ? AND waba_id = ? ORDER BY id DESC LIMIT 3"); $s->execute([(int)$t['id'], $waba]); foreach ($s->fetchAll() as $x) $subs[] = substr((string)$x['at'], 0, 16) . ' ' . ((int)$x['ok'] ? 'sent OK' : 'REFUSED: ' . $x['note']); } catch (Throwable $e) { }
        $hs = strtoupper((string)($h['status'] ?? '')) ?: 'DRAFT (never sent to Meta)'; $ms = $m ? strtoupper((string)($m['status'] ?? '')) : ($lerr !== '' ? '?' : 'NOT AT META');
        $mr = $m ? (string)($m['rejected_reason'] ?? '') : ''; if ($mr === 'NONE') $mr = '';
        $lint = swt_lint($t); $btn = implode(' | ', array_map(fn($b) => strtoupper((string)($b['type'] ?? '')) . ': ' . (string)($b['text'] ?? ''), (array)($t['buttons'] ?? [])));
        $bad = $ms === 'REJECTED' || strpos($hs, 'REJECTED') === 0 || strpos($hs, 'ERROR') === 0;
        $items[] = ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'language' => $lg, 'category' => (string)$t['category'], 'meta_category' => $m ? (string)($m['category'] ?? '') : '', 'hrms' => $hs, 'meta' => $ms, 'meta_reason' => $mr, 'hrms_reason' => (string)($h['reason'] ?? ''), 'quality' => $m ? (string)($m['quality_score']['score'] ?? '') : '', 'subs' => $subs, 'checks' => $lint, 'bad' => $bad];
        $L[] = ''; $L[] = '■ ' . $t['name'] . ' [' . $lg . '] · ' . ($bad ? '❌ ' : '') . 'HRMS: ' . $hs . ' · Meta: ' . $ms;
        $L[] = '  Category: ours ' . $t['category'] . ($m ? ' / Meta ' . ($m['category'] ?? '?') . (!empty($m['previous_category']) ? ' (was ' . $m['previous_category'] . ')' : '') : '') . ' · Header: ' . (($t['header_format'] ?? '') ?: 'none') . (in_array(strtoupper((string)($t['header_format'] ?? '')), ['IMAGE', 'VIDEO', 'DOCUMENT'], true) ? ' (photo id ' . (int)($t['header_lib_id'] ?? 0) . ')' : '') . ($m && !empty($m['quality_score']['score']) ? ' · Quality ' . $m['quality_score']['score'] : '');
        if ($mr !== '') $L[] = '  Meta rejected_reason: ' . $mr;
        if (trim((string)($h['reason'] ?? '')) !== '') $L[] = '  Saved reason: ' . $h['reason'];
        foreach ($subs as $x) $L[] = '  Submit: ' . $x;
        foreach ($lint as $x) $L[] = '  ⚠ ' . $x;
        $L[] = '  Body: ' . str_replace("\n", ' ⏎ ', (string)$t['body']);
        if (trim((string)($t['footer'] ?? '')) !== '') $L[] = '  Footer: ' . $t['footer'];
        if ($btn !== '') $L[] = '  Buttons: ' . $btn;
        $ex = (array)($t['examples']['body'] ?? []); if ($ex) $L[] = '  Examples: ' . implode(' | ', array_map('strval', $ex));
    }
    foreach ($live as $k => $m) if (empty($seen[$k])) { $L[] = ''; $L[] = '■ ' . $m['name'] . ' [' . ($m['language'] ?? '') . '] · only at Meta (not in HRMS) · ' . ($m['status'] ?? '') . ' · ' . ($m['category'] ?? '') . (!empty($m['rejected_reason']) && $m['rejected_reason'] !== 'NONE' ? ' · rejected_reason ' . $m['rejected_reason'] : ''); }
    return ['ok' => true, 'text' => implode("\n", $L), 'items' => $items, 'meta_error' => $lerr, 'app_ok' => $appOk];
}

/** ✨ Claude writes a Store template from a short note ("ask how the perfume is, 7 days after the bill"), following Meta's rules for the chosen category and language. Returns [ok, fields or error]. */
function swt_ai(array $in): array
{
    if (!function_exists('wa_tpl_model')) return [false, 'Claude is not available on this server.'];
    $about = trim(mb_substr((string)($in['about'] ?? ''), 0, 600));
    if (!empty($in['rewrite'])) $about = "REWRITE this message so that Meta approves it. Keep the same meaning, the same number of variables ({{1}} = first name) and the same language. Meta's reason for rejecting it: " . mb_substr((string)($in['reason'] ?? 'not given'), 0, 200) . "\nTHE MESSAGE:\n" . mb_substr((string)$in['rewrite'], 0, 1024); if ($about === '') return [false, 'Write in a few words what the message should say.'];
    $cat = strtoupper((string)($in['category'] ?? 'UTILITY')) === 'MARKETING' ? 'MARKETING' : 'UTILITY'; $lg = in_array((string)($in['language'] ?? 'en'), ['gu', 'hi', 'en'], true) ? (string)$in['language'] : 'en';
    $lname = ['gu' => 'Gujarati, written in Gujarati script', 'hi' => 'Hindi, written in Devanagari script', 'en' => 'simple English'][$lg]; $hf = (string)($in['header_format'] ?? '');
    $sys = "You write ONE WhatsApp message template for TM Perfume House, a perfume shop (store in Vesu, Surat, open 11 am to 11 pm). The owner tells you what the message is about. Follow Meta's template rules exactly:\n"
        . "- Language: " . $lname . " only (brand name, perfume names and STOP may stay in English letters).\n"
        . "- Variables are {{1}}, {{2}} ... in order. {{1}} is always the customer's first name. Never start or end the text with a variable, never put two variables side by side, use at most 4 variables and keep plenty of words around them.\n"
        . "- WhatsApp formatting is allowed in the text: *bold*, _italic_, ~strike~. Use it lightly. At most 3 emoji in the text.\n"
        . ($cat === 'UTILITY'
            ? "- Category UTILITY: the message must be about the customer's own purchase or bill (thank-you, bill, how is the perfume they bought, care / usage tip for it). NO offers, discounts, prices, 'visit again', 'new arrivals', review requests, community or anything that sells. Calm, short (2-4 short sentences). Footer: the shop name.\n"
            : "- Category MARKETING: friendly, short (2-5 sentences), may invite the customer back or tell about something new. The footer MUST tell how to stop, e.g. 'Reply STOP to stop messages' in the same language.\n")
        . "- Buttons: up to 2 quick-reply buttons, each at most 20 letters, plain words only - NO emoji, no *, no new line, no variables. Utility buttons must be neutral answers (e.g. 'Liked it', 'Need help').\n"
        . ($hf === 'TEXT' ? "- Also write a short heading (header) of at most 50 letters, no emoji, no variables.\n" : '')
        . "- Give a realistic example value for every variable (Indian first names, perfume names like 'Oud Royal 50 ml', bill numbers like 'TM-1042').\n"
        . "- 'tips': 1-3 short notes in simple English for the owner (why this should pass Meta, or what to watch).";
    $sch = ['type' => 'OBJECT', 'properties' => ['header' => ['type' => 'STRING'], 'body' => ['type' => 'STRING'], 'footer' => ['type' => 'STRING'], 'buttons' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        'examples' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'labels' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'tips' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['body', 'examples']];
    [$ok, $v] = wa_tpl_model($sys, "Category: " . $cat . "\nWhat the message is about (from the owner):\n" . $about, $sch, 1800);
    if (!$ok || !is_array($v) || trim((string)($v['body'] ?? '')) === '') return [false, 'Claude could not write it now. Try again in a minute.'];
    $body = trim((string)$v['body']); $n = count(wa_tpl_vars($body)); $ex = array_values(array_map('strval', (array)($v['examples'] ?? []))); for ($i = count($ex); $i < $n; $i++) $ex[] = $i === 0 ? 'Rahul' : ($i === 1 ? 'Oud Royal 50 ml' : 'TM-1042'); $ex = array_slice($ex, 0, $n);
    $btn = []; foreach (array_slice((array)($v['buttons'] ?? []), 0, 3) as $b) { $t = function_exists('st_wa_btn_text') ? st_wa_btn_text((string)$b) : trim((string)$b); if ($t !== '') $btn[] = ['type' => 'QUICK_REPLY', 'text' => $t]; }
    return [true, ['header_text' => mb_substr(trim((string)($v['header'] ?? '')), 0, 60), 'body' => $body, 'footer' => mb_substr(trim((string)($v['footer'] ?? '')), 0, 60), 'buttons' => $btn, 'examples' => $ex, 'labels' => array_slice(array_values(array_map('strval', (array)($v['labels'] ?? []))), 0, $n), 'tips' => array_values(array_map('strval', (array)($v['tips'] ?? []))), 'lint' => swt_lint(['body' => $body, 'language' => $lg, 'category' => $cat, 'footer' => (string)($v['footer'] ?? ''), 'buttons' => $btn, 'examples' => ['body' => $ex], 'header_format' => $hf, 'header_lib_id' => (int)($in['header_lib_id'] ?? 0)])]];
}

/** The family of a message: its name without version / copy / machine suffixes (birthday_v3 → birthday). */
function swt_family(string $name): string { $b = preg_replace('/(_\d{6,})?(_v\d+)?(_copy\d*)?$/', '', $name); $b = preg_replace('/(_\d{6,})?(_v\d+)?$/', '', (string)$b); return (string)$b; }
/** Where each Store message is used right now: [template id => ['Bill · new customer', ...]]. Only the one that would really go is marked. */
function swt_used(PDO $pdo): array
{
    static $memo = null; if ($memo !== null) return $memo; $u = []; $add = function (?array $t, string $what) use (&$u) { if ($t) $u[(int)$t['id']][] = $what; };
    try {
        $sw = function_exists('st_store_waba') ? st_store_waba($pdo) : ''; $ok = function (?array $t) use ($sw) { return $t && ($sw === '' || in_array($sw, (array)($t['ok_wabas'] ?? []), true)); };
        if (function_exists('st_wa_order_names')) {
            foreach ([false => 'Bill · new customer', true => 'Bill · returning customer'] as $back => $lbl) foreach (st_wa_order_names((bool)$back) as $nm) { $t = st_wa_tpl($pdo, $nm); if ($ok($t)) { $add($t, $lbl); break; } }
        }
        if (defined('ST_WA_UTIL_BILL')) { $ub = st_wa_tpl($pdo, ST_WA_UTIL_BILL); if ($ok($ub)) $add($ub, 'Bill · if the first one fails'); }
        $b = st_wa_tpl($pdo, 'tmph_store_bday_v2') ?: st_wa_tpl($pdo, 'tmph_store_bday'); if ($ok($b)) $add($b, 'Birthday');
        if (!function_exists('swf_cfg') && is_file(__DIR__ . '/store_wa_follow.php')) require_once __DIR__ . '/store_wa_follow.php';
        if (function_exists('swf_cfg')) { $c = swf_cfg($pdo); foreach ([7, 30, 60] as $st) { $id = (int)($c['tpl'][$st] ?? 0); if ($id > 0) $u[$id][] = 'Follow-up · ' . $st . ' days'; else foreach (['gu', 'hi', 'en'] as $lg) { $t = st_wa_tpl($pdo, 'tmph_store_followup_' . ($st === 7 ? 1 : ($st === 30 ? 2 : 3)) . '_' . $lg, $lg); if ($ok($t)) $add($t, 'Follow-up · ' . $st . ' days (' . $lg . ')'); } } }
    } catch (Throwable $e) { }
    return $memo = $u;
}

/**
 * 🔧 Fix rejected: for every Store message whose latest version is rejected (or refused) on the Store account, fixes what the rules can fix and sends it to Meta again:
 * emoji / marks out of buttons; Utility → Marketing when Meta said the category is wrong or it is promotional; the STOP line on Marketing.
 * Never at Meta → sent again under the same name. Already at Meta → a new version (_vN). Messages with problems only a person can fix (language, variables) are listed, not sent.
 * A family that already has an approved or waiting version is left alone. Returns ['sent' => [...], 'skipped' => [[name, why]], 'failed' => [[name, why]]].
 */
function swt_fix_all(PDO $pdo, string $waba, int $me, bool $dry = false): array
{
    $rows = []; foreach ($pdo->query("SELECT t.id, t.name, t.language, w.status, w.reason, w.meta_id FROM wa_templates t LEFT JOIN wa_tpl_waba w ON w.tpl_id = t.id AND w.waba_id = " . $pdo->quote($waba) . " WHERE t.name LIKE 'tmph\\_store\\_%' ORDER BY t.id")->fetchAll() as $r) $rows[] = $r;
    $fam = []; foreach ($rows as $r) { if (preg_match('/_\d{6,}(_v\d+)?$/', (string)$r['name'])) continue; $fam[swt_family((string)$r['name']) . '|' . $r['language']][] = $r; }
    $out = ['sent' => [], 'skipped' => [], 'failed' => []];
    foreach ($fam as $members) {
        $live = array_filter($members, fn($m) => in_array(strtoupper((string)$m['status']), ['APPROVED', 'PENDING', 'IN_APPEAL'], true)); if ($live) continue;
        $bad = array_values(array_filter($members, fn($m) => in_array(strtoupper((string)$m['status']), ['REJECTED', 'ERROR'], true))); if (!$bad) continue;
        $m = end($bad); $t = wa_tpl_row($pdo, (int)$m['id']); if (!$t) continue; $nm = (string)$t['name']; $why = strtoupper((string)$m['reason']);
        $buttons = st_wa_btns_clean(array_values((array)($t['buttons'] ?? []))); $cat = strtoupper((string)$t['category']); $footer = (string)($t['footer'] ?? ''); $changes = [];
        if ($buttons !== array_values((array)($t['buttons'] ?? []))) $changes[] = 'buttons cleaned';
        if ($cat === 'UTILITY' && preg_match('/INCORRECT_CATEGORY|PROMOTIONAL|TAG_CONTENT_MISMATCH|MARKETING/', $why)) { $cat = 'MARKETING'; $changes[] = 'made Marketing'; }
        if ($cat === 'MARKETING' && stripos($footer . ' ' . $t['body'], 'STOP') === false) { $footer = ['gu' => 'સંદેશા બંધ કરવા STOP લખો', 'hi' => 'बंद करने के लिए STOP लिखें'][(string)$t['language']] ?? 'Reply STOP to stop messages'; $changes[] = 'STOP line added'; }
        $hard = array_values(array_filter(swt_lint(['body' => $t['body'], 'language' => $t['language'], 'category' => $cat, 'footer' => $footer, 'buttons' => $buttons, 'examples' => $t['examples'] ?? [], 'header_format' => $t['header_format'] ?? '', 'header_lib_id' => $t['header_lib_id'] ?? 0]),
            fn($x) => preg_match('/STARTS|ENDS|side by side|Language is|no example|no photo is chosen|not 1,2,3/', $x)));
        if ($hard) { $out['skipped'][] = [$nm, 'needs a person: ' . implode(' ', $hard)]; continue; }
        if (!$changes && !preg_match('/INCORRECT_CATEGORY/', $why)) { $out['skipped'][] = [$nm, 'nothing the rules can fix (' . ($m['reason'] ?: 'no reason') . ') - open it and press Fix with Claude']; continue; }
        if ($dry) { $out['sent'][] = $nm . ' (' . implode(', ', $changes) . ')'; continue; }
        $atMeta = trim((string)$m['meta_id']) !== '';
        $d = ['name' => $nm, 'language' => (string)$t['language'], 'category' => $cat, 'header_format' => (string)($t['header_format'] ?? ''), 'header_text' => (string)($t['header_text'] ?? ''), 'header_lib_id' => (int)($t['header_lib_id'] ?? 0), 'body' => (string)$t['body'], 'footer' => $footer, 'buttons' => $buttons, 'examples' => is_array($t['examples'] ?? null) ? $t['examples'] : [], 'folder' => 'Store'];
        if ($atMeta) {      // Meta keeps the rejected one under its name: a new version
            $base = preg_replace('/_v\d+$/', '', $nm); $v = max(2, (int)($t['version'] ?? 1) + 1); $c = $pdo->prepare("SELECT 1 FROM wa_templates WHERE name = ? AND language = ?"); do { $d['name'] = substr($base, 0, 56) . '_v' . $v; $c->execute([$d['name'], $d['language']]); $v++; } while ($c->fetchColumn());
            [$ct, $err] = wa_tpl_clean($d, false); if ($err !== '') { $out['failed'][] = [$nm, $err]; continue; } $ct['family_id'] = (int)($t['family_id'] ?? 0) ?: (int)$t['id']; $ct['version'] = $v - 1;
            $id = wa_tpl_insert($pdo, $ct, $me, (string)($t['purpose'] ?? 'Store message'), 'Fixed version of ' . $nm);
        } else {
            [$ct, $err] = wa_tpl_clean($d, false); if ($err !== '') { $out['failed'][] = [$nm, $err]; continue; } $id = (int)$t['id'];
            $pdo->prepare("UPDATE wa_templates SET category = ?, footer = ?, buttons = ? WHERE id = ?")->execute([$ct['category'], $ct['footer'], json_encode($ct['buttons'], JSON_UNESCAPED_UNICODE), $id]);
        }
        $row = wa_tpl_row($pdo, $id); $me2 = $row ? wa_tpl_media_check($pdo, $row) : 'not saved'; if ($me2 !== '') { $out['failed'][] = [$nm, $me2]; continue; }
        [$ok, $w] = wa_tpl_create_at($pdo, $row, $waba); if ($ok) $out['sent'][] = (string)$row['name'] . ' (' . implode(', ', $changes ?: ['sent again']) . ')'; else $out['failed'][] = [(string)$row['name'], (string)$w];
    }
    return ['ok' => true] + $out;
}

/** Makes the Utility bill message as a DRAFT, once (if the owner deletes it, it is not made again). Nothing goes to Meta until "Save & send to Meta". */
function swt_seed_util_bill(PDO $pdo, int $me): void
{
    if (!defined('ST_WA_UTIL_BILL') || (string)setting_get($pdo, 'st_wa_util_seed', '') !== '') return; setting_set($pdo, 'st_wa_util_seed', date('Y-m-d H:i:s'));
    $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([ST_WA_UTIL_BILL]); if ($q->fetchColumn()) return;
    $d = ['name' => ST_WA_UTIL_BILL, 'language' => 'en', 'category' => 'UTILITY', 'header_format' => '', 'header_text' => '', 'header_lib_id' => 0,
        'body' => "Hi {{1}}, thank you for your purchase at TM Perfume House.\n\nYour bill {{2}} is ready. You can view it here: {{3}}\n\nThank you for visiting us.",
        'footer' => 'TM Perfume House', 'buttons' => [], 'examples' => ['body' => ['Rahul', 'TM-1042', 'https://fourpirates.space/b/1a2b3c4d5e'], 'labels' => ['Name', 'Bill no', 'Bill link']], 'folder' => 'Store'];
    [$t, $err] = wa_tpl_clean($d, true); if ($err === '') wa_tpl_insert($pdo, $t, $me, 'Bill · Utility (sent when the bill message fails)', 'Store bill message, utility');
}
