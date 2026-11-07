<?php
/**
 * wa_media.php — the media library of the WhatsApp console. Loaded by wa_more.php.
 *
 *   wa.lib_list    GET   every saved photo / video / document                                  [any login that uses WhatsApp chats]
 *   wa.lib_add     POST  multipart {file, title}  save a file once                              [Admin / HR]
 *   wa.lib_file    GET   {id}  the file itself (the browser fetches it with the login token)    [any login]
 *   wa.lib_rename  POST  {id, title}                                                            [Admin / HR]
 *   wa.lib_del     POST  {id}                                                                   [Admin / HR]
 *   wa.lib_send    POST  {phone, id, caption}  send a saved file into a chat (24-hour window)   [chat owner / admin]
 *
 * Files live in  wa_files/  next to api.php (closed to the web by an .htaccess); Meta keeps an uploaded copy for 30 days only,
 * so the library re-uploads to Meta when a copy is older than 25 days. Same WhatsApp limits as a normal attachment.
 */
if (!function_exists('wa_lib_dir')) {

function wa_lib_dir(): string
{
    $d = __DIR__ . '/wa_files';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (is_dir($d) && !is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    return $d;
}
function wa_lib_row(PDO $pdo, int $id): ?array { $q = $pdo->prepare("SELECT * FROM wa_media_lib WHERE id = ?"); $q->execute([$id]); $r = $q->fetch(); return $r ?: null; }
function wa_lib_public(array $r): array
{
    return ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'kind' => (string)$r['kind'], 'mime' => (string)$r['mime'], 'filename' => (string)$r['filename'], 'size' => (int)$r['size'], 'created_at' => (string)$r['created_at']];
}
/** The Meta media id of a saved file for ONE number (uploaded when missing or older than 25 days). Returns [id|'' , error]. */
function wa_lib_media_id(PDO $pdo, array $r, string $phoneId, bool $fresh = false): array
{
    $m = json_decode((string)$r['mids'], true); if (!is_array($m)) $m = [];
    if (!$fresh && !empty($m[$phoneId]['id']) && (int)($m[$phoneId]['at'] ?? 0) > time() - 25 * 86400) return [(string)$m[$phoneId]['id'], ''];
    $path = wa_lib_dir() . '/' . basename((string)$r['path']); if (!is_file($path)) return ['', 'The saved file is missing on the server.'];
    [$c, $d] = wa_media_upload($phoneId, $path, (string)$r['mime'], (string)$r['filename']);
    $id = is_array($d) ? (string)($d['id'] ?? '') : ''; if ($c < 200 || $c >= 300 || $id === '') return ['', wa_err($d, 'Meta did not accept the file.')];
    $m[$phoneId] = ['id' => $id, 'at' => time()]; $pdo->prepare("UPDATE wa_media_lib SET mids = ? WHERE id = ?")->execute([json_encode($m), (int)$r['id']]);
    return [$id, ''];
}
}

$__wl = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wl, ['wa.lib_list', 'wa.lib_add', 'wa.lib_file', 'wa.lib_rename', 'wa.lib_del', 'wa.lib_send'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $auth = require_auth();
    if (!wa_tables($pdo) || !wa_more_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    $me = (int)($auth['employee_id'] ?? 0);
    switch ($__wl) {
        case 'wa.lib_list': {
            $rows = $pdo->query("SELECT * FROM wa_media_lib ORDER BY id DESC")->fetchAll();
            json_response(['items' => array_map('wa_lib_public', $rows), 'can_edit' => wa_is_admin($pdo, $auth)]);
        }
        case 'wa.lib_file': {
            $r = wa_lib_row($pdo, (int)($_GET['id'] ?? 0)); if (!$r) json_response(['error' => 'File not found.'], 404);
            $p = wa_lib_dir() . '/' . basename((string)$r['path']); if (!is_file($p)) json_response(['error' => 'The saved file is missing on the server.'], 404);
            $mime = preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', (string)$r['mime']) ? (string)$r['mime'] : 'application/octet-stream';
            header('Content-Type: ' . $mime); header('Content-Length: ' . filesize($p)); header('Cache-Control: private, max-age=3600');
            header('Content-Disposition: inline; filename="' . str_replace('"', '', (string)$r['filename']) . '"'); readfile($p); exit;
        }
        case 'wa.lib_send': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $key = wa_key($in['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            if ((int)$c['optout'] === 1) json_response(['error' => 'This customer opted out — do not message them.'], 422);
            if (empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) json_response(['error' => 'The 24-hour reply window is closed. Only an approved pre-made message can be sent now.'], 422);
            $r = wa_lib_row($pdo, (int)($in['id'] ?? 0)); if (!$r) json_response(['error' => 'File not found in the library.'], 404);
            $q = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q->execute([(int)$c['last_number_id']]); $pid = (string)$q->fetchColumn(); if ($pid === '') $pid = wa_cfg_val('WA_PHONE_ID');
            if ($pid === '') json_response(['error' => 'WA_PHONE_ID is missing in config.php.'], 422);
            $cap = trim(mb_substr((string)($in['caption'] ?? ''), 0, 1000)); ignore_user_abort(true);
            [$mid, $e] = wa_lib_media_id($pdo, $r, $pid); if ($mid === '') json_response(['error' => $e], 502);
            [$ok, $res] = wa_send_media($pdo, $c, (string)$r['kind'], $mid, (string)$r['mime'], $cap, (string)$r['filename'], $me);
            if (!$ok && preg_match('/media|expired|not found|131053|131052/i', (string)$res)) {   // Meta forgot the copy → upload again once
                [$mid, $e] = wa_lib_media_id($pdo, $r, $pid, true); if ($mid !== '') [$ok, $res] = wa_send_media($pdo, $c, (string)$r['kind'], $mid, (string)$r['mime'], $cap, (string)$r['filename'], $me);
            }
            if (!$ok) json_response(['error' => $res], 422);
            json_response(['ok' => true, 'wamid' => $res['wamid']]);
        }
    }
    wa_guard_admin($pdo, $auth);
    switch ($__wl) {
        case 'wa.lib_add': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $cl = (int)($_SERVER['CONTENT_LENGTH'] ?? 0); $pm = wa_ini_bytes((string)ini_get('post_max_size'));
            if (empty($_FILES['file']) && $cl > 0 && $pm > 0 && $cl > $pm) json_response(['error' => 'This file is too big for the server (limit ' . round($pm / 1048576) . ' MB).'], 413);
            $f = $_FILES['file'] ?? null; if (!$f || !isset($f['tmp_name'])) json_response(['error' => 'Pick a file first.'], 422);
            if ((int)($f['error'] ?? 0) !== UPLOAD_ERR_OK) json_response(['error' => 'The file did not upload. Try again.'], 422);
            $tmp = (string)$f['tmp_name']; if (!wa_is_upload($tmp)) json_response(['error' => 'The file did not upload. Try again.'], 422);
            $name = preg_replace('/[^\w.\- ()\[\]]+/u', '_', basename((string)($f['name'] ?? 'file'))) ?: 'file'; $size = (int)($f['size'] ?? filesize($tmp));
            $det = ''; if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); if ($fi) { $det = (string)finfo_file($fi, $tmp); finfo_close($fi); } } if ($det === '') $det = (string)($f['type'] ?? '');
            $r = wa_media_rules($det, $name); if ($r['kind'] === '') json_response(['error' => $r['error']], 422);
            if ($size > $r['max']) json_response(['error' => 'This ' . $r['kind'] . ' is ' . round($size / 1048576, 1) . ' MB. WhatsApp allows up to ' . round($r['max'] / 1048576) . ' MB.'], 422);
            $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 80)); if ($title === '') $title = pathinfo($name, PATHINFO_FILENAME);
            $dir = wa_lib_dir(); if (!is_dir($dir) || !is_writable($dir)) json_response(['error' => 'The server cannot save files in the wa_files folder. Create it next to api.php and allow writing (permission 755).'], 500);
            $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . (strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin');
            $okMove = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dir . '/' . $stored) : @copy($tmp, $dir . '/' . $stored);
            if (!$okMove) json_response(['error' => 'Could not save the file on the server.'], 500);
            $pdo->prepare("INSERT INTO wa_media_lib (title, kind, mime, filename, size, path, mids, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, '{}', ?, ?)")->execute([$title, $r['kind'], $r['mime'], $name, $size, $stored, $me, date('Y-m-d H:i:s')]);
            json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        }
        case 'wa.lib_rename': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $t = trim(mb_substr((string)($in['title'] ?? ''), 0, 80)); if ($t === '') json_response(['error' => 'Write a name.'], 422);
            $pdo->prepare("UPDATE wa_media_lib SET title = ? WHERE id = ?")->execute([$t, (int)($in['id'] ?? 0)]); json_response(['ok' => true]);
        }
        case 'wa.lib_del': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $r = wa_lib_row($pdo, (int)(json_input()['id'] ?? 0)); if (!$r) json_response(['error' => 'File not found.'], 404);
            @unlink(wa_lib_dir() . '/' . basename((string)$r['path'])); $pdo->prepare("DELETE FROM wa_media_lib WHERE id = ?")->execute([(int)$r['id']]); json_response(['ok' => true]);
        }
    }
}
