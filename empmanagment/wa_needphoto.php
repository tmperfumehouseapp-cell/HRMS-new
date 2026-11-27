<?php
/**
 * wa_needphoto.php — 📷 NEEDED PHOTOS (owner, 27 Nov 2026: "put a section on top of the Campaigns page: the photos you need; we upload each one
 * with a button and the message then goes to Meta for approval").
 *
 *  · A "need" is ONE photo with a short brief (what must be in the picture) and the messages that will carry it on top.
 *  · The owner uploads the photo (wa.need_photo_add, multipart {key, file}); it is saved in the media library and the messages of that need are made
 *    with the photo as header and sent to Meta on every WhatsApp account, by the normal template maker. Nothing is sent to a customer from here.
 *  · The messages are MARKETING, named tmph_cp_pNN: once Meta approved them the Cold funnel picks them up like the other tmph_cp_ messages, and they are
 *    in the message library for the owner's own campaigns (Module A). The prices are the shop's own proven combo prices (the same as tmph_cp_01-18).
 *  · A second kind of need is found by itself: an approved message that should have a photo on top but has none in the library (key 'tpl:<id>');
 *    the uploaded photo is put on that message, no new approval is needed.
 *  wa.need_photos (GET) the list with its state · wa.need_photo_add (POST multipart) upload.   Admin only. PHP 7.4 safe. Loaded by wa_more.php.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wnp_defs')) {
    /** The photos asked for: key => [title, brief, where it is used, messages [name => [body, buttons]]]. */
    function wnp_defs(): array
    {
        return [
            'combo160' => ['title' => '160 ML combo (8 × 20ml)', 'brief' => '8 bottles of 20 ml together on a clean background, labels to the front. Wide photo (landscape), bright light, no text needed.', 'use' => 'Cold funnel + your campaigns',
                'tpls' => ['tmph_cp_p01' => ["Hello {{1}} 🤩\n\n*8 perfumes sirf ₹1,999 me ?*\n\nKya sach me ? 👀\n\nHaan ji ! 8 × 20ml (160 ML), aapki pasand ki 8 khushbu 🌸\n\nOrder ke liye tap kijiye 👇", ['BOOK MY COMBO', 'Show more combos']],
                    'tmph_cp_p02' => ["Hi {{1}} 👋\n\nPhoto me jo 8 bottles hain, wo sab aapki ho sakti hain 😍\n\n160 ML sirf ₹1,999 🔥\n\nKaunsi khushbu chahiye ? 👇", ['Men', 'Women', 'Unisex']]]],
            'combo5x50' => ['title' => '5 × 50ml combo', 'brief' => '5 bottles of 50 ml in one row, labels to the front, clean background. Wide photo (landscape).', 'use' => 'Cold funnel + your campaigns',
                'tpls' => ['tmph_cp_p03' => ["Hello {{1}} 😳\n\n*Ek 50ml perfume sirf ₹550 ka ?*\n\nKya ? 👀\n\nHaan ji ! 5 × 50ml ka combo sirf ₹2,750 🔥\n\nApni pasand ke 5 perfumes chuniye 👇", ['Order Now', 'Send Details']]]],
            'combo3x50' => ['title' => '3 × 50ml + 1 × 20ml FREE', 'brief' => '3 bottles of 50 ml and ONE 20 ml bottle in front of them (the free one). Wide photo (landscape), clean background.', 'use' => 'Cold funnel + your campaigns',
                'tpls' => ['tmph_cp_p04' => ["Hi {{1}} 🎁\n\n*Aage wali chhoti bottle FREE ?* 😳\n\nHaan ji ! 3 × 50ml sirf ₹2,140 aur saath me 1 × 20ml FREE 🔥\n\nApne liye chahiye ya gift ke liye ? 👇", ['For me', 'As a gift']]]],
            'flat2099' => ['title' => 'Flat ₹2,099 combo (4 × 50ml and 2 × 100ml)', 'brief' => '4 bottles of 50 ml on the left and 2 bottles of 100 ml on the right, in one picture. Wide photo (landscape).', 'use' => 'Cold funnel + your campaigns',
                'tpls' => ['tmph_cp_p05' => ["Hello {{1}} 💥\n\n*Left wala combo ya right wala — dono ek hi price me ?*\n\nHaan ji, flat ₹2,099 😍\n\n4 × 50ml ya 2 × 100ml, aapki marzi ✨\n\nKaunsa chahiye ? 👇", ['Order Now', 'Send Details']]]],
            'sizes' => ['title' => 'The three sizes side by side (20 / 50 / 100 ml)', 'brief' => 'One 20 ml, one 50 ml and one 100 ml bottle side by side, small to big, so the size difference is clear. Wide photo (landscape).', 'use' => 'Cold funnel + your campaigns',
                'tpls' => ['tmph_cp_p06' => ["Hi {{1}} 🌸\n\nEk chhota sa sawal 🤔 Photo me se aap kaunsa size use karte ho ?\n\nHar size par ek khaas combo ready he, 160 ML sirf ₹1,999 se 🔥\n\nApna size dabaiye 👇", ['20ML', '50ML', '100ML']]]],
            'gift' => ['title' => 'Gift pack', 'brief' => 'Perfumes in a gift box or gift wrap (ribbon), wedding / winter feeling. Wide photo (landscape).', 'use' => 'Cold funnel + your campaigns',
                'tpls' => ['tmph_cp_p07' => ["Hello {{1}} 🎁\n\nShaadi ka season he 💍 Kisi ko aisa gift dena he ?\n\n3 × 50ml sirf ₹2,140 aur saath me 1 × 20ml FREE 🔥\n\nKiske liye chahiye ? 👇", ['Men', 'Women', 'Gift set']]]],
            'reviews' => ['title' => 'Customer reviews', 'brief' => 'A screenshot collage of 3-4 real Google reviews with 5 stars (names can stay). Wide photo (landscape), text readable on a phone.', 'use' => 'Cold funnel + your campaigns',
                'tpls' => ['tmph_cp_p08' => ["Hi {{1}} ⭐\n\n7,000+ customers ne TM Perfume House ko Google par review diya he 🙏\n\nAap bhi try karoge ? 160 ML combo sirf ₹1,999 🔥\n\nKaunsi khushbu chahiye ? 👇", ['Men', 'Women', 'Unisex']]]],
            'parcel' => ['title' => 'A packed parcel', 'brief' => 'A sealed TM Perfume House parcel as the customer gets it (box + tape + sticker), one opened next to it with the bottles inside. Wide photo (landscape).', 'use' => 'Cold funnel + your campaigns',
                'tpls' => ['tmph_cp_p09' => ["Hello {{1}} 📦\n\nAapka parcel aise aata he — sealed aur safe 😊\n\nCOD bhi available he: parcel khol kar check karke hi payment 🙏\n\nPehla order 160 ML combo ₹1,999 se shuru karein ? 👇", ['Order Now', 'Send Details']]]],
        ];
    }
    function wnp_state(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'wa_needphoto', ''), true); return is_array($j) ? $j : []; }
    /** Meta's answer for a message: approved / waiting / rejected / not sent yet. */
    function wnp_tpl_state(PDO $pdo, int $id): string
    {
        if ($id <= 0) return 'not made';
        try { $q = $pdo->prepare("SELECT UPPER(status) FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([$id]); $s = $q->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { return 'not known'; }
        if (!$s) return 'in the line for Meta'; if (in_array('APPROVED', $s, true)) return 'approved'; if (in_array('PENDING', $s, true) || in_array('IN_APPEAL', $s, true)) return 'waiting at Meta'; if (in_array('REJECTED', $s, true)) return 'rejected by Meta'; return 'in the line for Meta';
    }
    /** Approved MARKETING messages that should carry a photo but have none in the library (at most 12). Owner 27 Nov: the utility ones (complaint, raise a ticket, sizes of a ticket ...) are not asked for. */
    function wnp_missing(PDO $pdo): array
    {
        $o = []; try { $rows = $pdo->query("SELECT t.id, t.name, t.category, LEFT(t.body, 160) b FROM wa_templates t LEFT JOIN wa_media_lib l ON l.id = t.header_lib_id WHERE t.header_format = 'IMAGE' AND (t.header_lib_id = 0 OR l.id IS NULL) AND UPPER(t.category) = 'MARKETING' AND t.name NOT REGEXP 'compl|ticket|raise|cx_|support|care' AND EXISTS (SELECT 1 FROM wa_tpl_waba w WHERE w.tpl_id = t.id AND UPPER(w.status) = 'APPROVED') ORDER BY t.id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) $o['tpl:' . (int)$r['id']] = ['title' => 'Photo of message ' . (string)$r['name'], 'brief' => 'This approved message should have a photo on top, but its photo is not in the library, so it cannot be sent. Upload the photo that belongs to it: "' . trim(preg_replace('/\s+/u', ' ', (string)$r['b'])) . '…"', 'use' => (string)$r['category'] . ' message, already approved', 'fix' => (int)$r['id']]; } catch (Throwable $e) { }
        return $o;
    }
    /** The list for the Campaigns page. */
    function wnp_list(PDO $pdo): array
    {
        $st = wnp_state($pdo); $items = []; $open = 0;
        foreach (wnp_defs() + wnp_missing($pdo) as $k => $d) {
            $s = $st[$k] ?? []; $lib = (int)($s['lib'] ?? 0); $msgs = [];
            foreach ((array)($d['tpls'] ?? []) as $name => $m) { $id = (int)($s['tpls'][$name] ?? 0); $msgs[] = ['name' => $name, 'body' => (string)$m[0], 'buttons' => (array)$m[1], 'state' => $lib > 0 ? wnp_tpl_state($pdo, $id) : 'waits for the photo']; }
            $done = $lib > 0; if (!$done) $open++;
            $items[] = ['key' => $k, 'title' => (string)$d['title'], 'brief' => (string)$d['brief'], 'use' => (string)$d['use'], 'done' => $done, 'at' => (string)($s['at'] ?? ''), 'lib' => $lib, 'fix' => (int)($d['fix'] ?? 0), 'msgs' => $msgs, 'note' => (string)($s['note'] ?? '')];
        }
        return ['items' => $items, 'open' => $open];
    }
    /** Saves the uploaded picture in the media library. [library id, error]. */
    function wnp_lib_save(PDO $pdo, array $f, string $title, int $me): array
    {
        if (!function_exists('wa_lib_dir') && is_file(__DIR__ . '/wa_media.php')) { try { require_once __DIR__ . '/wa_media.php'; } catch (Throwable $e) { } }
        if (!function_exists('wa_lib_dir')) return [0, 'The media library is not available on this server.'];
        $tmp = (string)($f['tmp_name'] ?? ''); if ((int)($f['error'] ?? 1) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) return [0, 'The upload did not arrive. Please try again.'];
        $mime = ''; try { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = (string)finfo_file($fi, $tmp); finfo_close($fi); } catch (Throwable $e) { }
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) return [0, 'Meta takes only JPG or PNG photos on top of a message.'];
        $raw = (string)@file_get_contents($tmp); if ($raw === '') return [0, 'The photo could not be read.'];
        $shr = function_exists('wa_lib_shrink') ? wa_lib_shrink($raw, $mime) : null; if ($shr) { $raw = (string)$shr[0]; $mime = (string)$shr[1]; }
        if (strlen($raw) > 5 * 1048576) return [0, 'This photo is over 5 MB. Use a smaller one.'];
        $dir = wa_lib_dir(); if (!is_dir($dir) || !is_writable($dir)) return [0, 'The photo folder on the server is not writable.'];
        $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($mime === 'image/png' ? '.png' : '.jpg'); if (@file_put_contents($dir . '/' . $stored, $raw) === false) return [0, 'Could not save the photo on the server.'];
        $name = mb_substr(preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)($f['name'] ?? 'photo.jpg')), 0, 110);
        try { $pdo->prepare("INSERT INTO wa_media_lib (title, kind, mime, filename, size, path, mids, created_by, created_at) VALUES (?, 'image', ?, ?, ?, ?, '{}', ?, ?)")->execute([mb_substr($title, 0, 80), $mime, $name, strlen($raw), $stored, $me, date('Y-m-d H:i:s')]); return [(int)$pdo->lastInsertId(), '']; } catch (Throwable $e) { @unlink($dir . '/' . $stored); return [0, 'Could not save the photo in the library.']; }
    }
    /** The photo of one need arrived: make its messages with the photo on top and send them to Meta. ['made' => n, 'failed' => [..]]. */
    function wnp_make(PDO $pdo, string $key, int $lib, int $by): array
    {
        $res = ['made' => 0, 'failed' => []]; $st = wnp_state($pdo); $s = $st[$key] ?? []; $s['lib'] = $lib; $s['at'] = date('Y-m-d H:i:s'); $s['by'] = $by; if (!isset($s['tpls']) || !is_array($s['tpls'])) $s['tpls'] = [];
        if (strpos($key, 'tpl:') === 0) {   // an approved message without its photo: the photo is put on it
            $id = (int)substr($key, 4); try { $pdo->prepare("UPDATE wa_templates SET header_lib_id = ? WHERE id = ? AND header_format = 'IMAGE'")->execute([$lib, $id]); $res['made'] = 1; } catch (Throwable $e) { $res['failed'][] = 'could not put the photo on the message'; }
            $st[$key] = $s; setting_set($pdo, 'wa_needphoto', json_encode($st)); return $res;
        }
        $d = wnp_defs()[$key] ?? null; if (!$d) return ['made' => 0, 'failed' => ['unknown photo']];
        foreach (['wa_tpl_clean', 'wa_tpl_insert', 'wa_tpl_row'] as $f) if (!function_exists($f)) return ['made' => 0, 'failed' => ['The message maker is not loaded.']];
        $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 12, 'made' => 0];
        foreach ($d['tpls'] as $name => $m) {
            $qr = array_map(function ($t) { return ['type' => 'QUICK_REPLY', 'text' => $t]; }, (array)$m[1]);
            [$t, $err] = wa_tpl_clean(['name' => $name, 'language' => 'en', 'category' => 'MARKETING', 'header_format' => 'IMAGE', 'header_lib_id' => $lib, 'body' => (string)$m[0], 'footer' => 'TM Perfume House', 'buttons' => $qr, 'examples' => ['body' => ['Rahul'], 'labels' => ['Name']], 'folder' => 'Offers']);
            if ($err !== '') { $res['failed'][] = $name . ': ' . $err; continue; }
            $id = wa_tpl_insert($pdo, $t, $by, 'Photo message (owner, 27 Nov 2026): the photo "' . (string)$d['title'] . '" on top, the price as a question, one tap to order.', 'Marketing message with a photo.'); $r = wa_tpl_row($pdo, $id); if (!$r) { $res['failed'][] = $name . ': could not save'; continue; }
            $s['tpls'][$name] = (int)$id; $res['made']++;
            foreach ($wabas as $w) { if (function_exists('wa_tpl_try_create')) { [$ok, $why] = wa_tpl_try_create($pdo, $r, (string)$w, $budget); } elseif (function_exists('wa_tpl_create_at')) { [$ok, $why] = wa_tpl_create_at($pdo, $r, (string)$w); } else { $ok = false; $why = 'no submit function'; } if (!$ok) $res['failed'][] = $name . ' → ' . mb_substr((string)$why, 0, 90); }
        }
        $s['note'] = $res['failed'] ? mb_substr((string)$res['failed'][0], 0, 140) : ''; $st[$key] = $s; setting_set($pdo, 'wa_needphoto', json_encode($st));
        if (function_exists('wa_log')) wa_log($pdo, 'template', !$res['failed'], 'Needed photo "' . (string)$d['title'] . '" uploaded: ' . $res['made'] . ' photo messages made and sent to Meta' . ($res['failed'] ? ' — ' . $res['failed'][0] : ''));
        return $res;
    }
}   // function_exists guard

$__wnp = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wnp, ['wa.need_photos', 'wa.need_photo_add'], true)) {
    $auth = require_auth();
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    if (function_exists('wa_more_tables')) wa_more_tables($pdo);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    if (function_exists('wa_tpl_tables')) wa_tpl_tables($pdo);
    if ($__wnp === 'wa.need_photos') json_response(['ok' => true] + wnp_list($pdo));
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    @set_time_limit(180);
    $key = trim((string)($_POST['key'] ?? '')); $all = wnp_defs() + wnp_missing($pdo); if ($key === '' || !isset($all[$key])) json_response(['error' => 'This photo is not on the list any more. Refresh the page.'], 422);
    $st0 = wnp_state($pdo); if (!empty($st0[$key]['lib']) && empty($_POST['again'])) json_response(['error' => 'A photo was already uploaded for this one.'], 422);
    $f = $_FILES['file'] ?? null; if (!$f || !isset($f['tmp_name'])) json_response(['error' => 'Pick a photo first.'], 422);
    $me = (int)($auth['employee_id'] ?? 0);
    [$lib, $err] = wnp_lib_save($pdo, $f, 'Needed photo: ' . (string)$all[$key]['title'], $me); if ($err !== '' || $lib <= 0) json_response(['error' => $err !== '' ? $err : 'Could not save the photo.'], 422);
    $r = wnp_make($pdo, $key, $lib, $me);
    json_response(['ok' => true, 'lib' => $lib, 'made' => (int)$r['made'], 'failed' => $r['failed']] + wnp_list($pdo));
}
