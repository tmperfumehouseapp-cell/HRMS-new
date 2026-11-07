<?php
/**
 * complaints_photo.php — 📷 every automatic complaint message is an APPROVED WhatsApp message (never free text), most of them with a photo on top.
 *
 *   Which message gets which photo (owner's rule; the team can change it in Complaints → Settings → Photos on complaint messages):
 *     Ticket created (every problem)  → "Customer Care" banner      · except "Complaint about our staff" → no photo (the customer is upset with us)
 *     Pickup booked / Picked up / Received & checked / Tracking number → "Customer Care" banner
 *     New goods shipped / Refund-credit done / Ticket resolved ("Are you happy?") → "Thank you" image
 *     Courier will try again / Ticket open again / We need information / Complaint update → no photo (annoying moments: plain and calm)
 *   The two default photos are made from the logo (assets/img/cx_care.jpg, cx_thanks.jpg) and put in the WhatsApp media library once.
 *   A message with a photo needs its own Meta-approved version (made by cx8_setup, by itself). Until Meta approves it the approved text version
 *   goes; if nothing is approved yet the message waits and goes by itself later. Changing the photo needs no new approval (Meta takes the photo per send).
 *   Action: bd.cx_photos  GET → the list · POST {k, choice: care | thanks | none | <media library id>}.  Loaded at the bottom of complaints_more.php.
 */
if (!function_exists('cx8_keys')) {

/** key => [label, icon, group, default photo]. First messages = the problem keys, progress = 'p_<step>', general = 'g_<kind>'. */
function cx8_keys(): array
{
    $out = [];
    foreach (cx_problems() as $k => $p) $out[$k] = [(string)$p[0], (string)$p[1], 'first', $k === 'staff' ? 'none' : 'care'];
    $pd = ['pickup' => 'care', 'pickedup' => 'care', 'transit' => 'care', 'arrived' => 'care', 'received' => 'care', 'tracking' => 'care', 'shipped' => 'thanks', 'refund' => 'thanks', 'reattempt' => 'none'];
    if (function_exists('cx7_defs')) foreach (cx7_defs() as $k => $x) $out['p_' . $k] = [(string)$x[1], (string)$x[0], 'progress', $pd[$k] ?? 'care'];
    $out['g_welcome'] = ['Ticket created (general — when a problem has no own message)', '🎫', 'general', 'care'];
    $out['g_resolved'] = ['Ticket resolved — "Are you happy?"', '🙏', 'general', 'thanks'];
    $out['g_reopened'] = ['Ticket open again', '🔁', 'general', 'none'];
    $out['g_need_info'] = ['We need information', '❓', 'general', 'none'];
    $out['g_update'] = ['Complaint update (typed by the team after 24 h)', '✍️', 'general', 'none'];
    return $out;
}
/** The general messages' templates: text names (newest choice first) and the name of the photo version. */
function cx8_general(): array
{
    return ['g_welcome' => [['tmph_ticket_welcome', 'tmph_ticket_created'], 'tmph_ticket_welcome_img'], 'g_resolved' => [['tmph_ticket_resolved'], 'tmph_ticket_resolved_img'],
        'g_reopened' => [['tmph_ticket_reopened'], 'tmph_ticket_reopened_img'], 'g_need_info' => [['tmph_complaint_need_info'], 'tmph_complaint_need_info_img'], 'g_update' => [['tmph_complaint_update'], 'tmph_complaint_update_img']];
}
/** The media-library id of a default photo ('care' | 'thanks'): put in the library once from assets/img. 0 when it cannot be. */
function cx8_lib_default(PDO $pdo, string $which): int
{
    if (!in_array($which, ['care', 'thanks'], true) || !function_exists('wa_lib_dir')) return 0;
    $id = (int)cx_state_get($pdo, 'cx8_lib_' . $which, '0');
    if ($id > 0) { try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_media_lib WHERE id = ?"); $q->execute([$id]); if ((int)$q->fetchColumn() > 0) return $id; } catch (Throwable $e) { return 0; } }
    $src = dirname(__DIR__) . '/assets/img/cx_' . $which . '.jpg'; if (!is_file($src)) return 0;
    $dir = wa_lib_dir(); if (!is_dir($dir) || !is_writable($dir)) return 0;
    $stored = 'cx_' . $which . '_' . date('Ymd_His') . '.jpg'; if (!@copy($src, $dir . '/' . $stored)) return 0;
    try { $pdo->prepare("INSERT INTO wa_media_lib (title, kind, mime, filename, size, path, mids, created_by, created_at) VALUES (?, 'image', 'image/jpeg', ?, ?, ?, '{}', 0, ?)")
        ->execute([$which === 'care' ? 'Complaints · Customer Care banner' : 'Complaints · Thank-you image', 'cx_' . $which . '.jpg', (int)filesize($dir . '/' . $stored), $stored, date('Y-m-d H:i:s')]);
        $id = (int)$pdo->lastInsertId(); cx_state_put($pdo, 'cx8_lib_' . $which, (string)$id); return $id; } catch (Throwable $e) { return 0; }
}
/** The choice for one message: 'care' | 'thanks' | 'none' | '<library id>'. */
function cx8_choice(PDO $pdo, string $key): string { $K = cx8_keys(); $v = (string)cx_state_get($pdo, 'cx8ph_' . $key, ''); return $v !== '' ? $v : (string)($K[$key][3] ?? 'none'); }
/** The media-library id of the photo on top of this message (0 = no photo). */
function cx8_photo(PDO $pdo, string $key): int
{
    $c = cx8_choice($pdo, $key); if ($c === 'none') return 0;
    if (ctype_digit($c)) { try { $q = $pdo->prepare("SELECT kind FROM wa_media_lib WHERE id = ?"); $q->execute([(int)$c]); if ((string)$q->fetchColumn() === 'image') return (int)$c; } catch (Throwable $e) { } return cx8_lib_default($pdo, 'care'); }
    return cx8_lib_default($pdo, $c);
}
function cx8_tpl_id(PDO $pdo, string $name): int { try { $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$name]); return (int)$q->fetchColumn(); } catch (Throwable $e) { return 0; } }
function cx8_is_photo_tpl(PDO $pdo, int $id): bool { $r = $id ? wa_tpl_row($pdo, $id) : null; return $r && strtoupper((string)$r['header_format']) === 'IMAGE'; }
/** The template names of a message: [photo versions (newest first), text versions (newest first)]. */
function cx8_names(PDO $pdo, string $key): array
{
    $img = []; $txt = [];
    if (isset(cx8_general()[$key])) { [$t, $i] = cx8_general()[$key]; return [[$i], $t]; }
    foreach (array_reverse(cx6_names($pdo, $key)) as $n) { $id = cx8_tpl_id($pdo, $n); if (!$id) continue; if (cx8_is_photo_tpl($pdo, $id)) $img[] = $n; else $txt[] = $n; }
    return [$img, $txt];
}
/** The approved template to send for a message: the photo version (with the chosen photo) when the message has a photo and Meta approved it, else the approved text version. null = nothing approved yet. */
function cx8_pick(PDO $pdo, string $key, string $photoKey = '', bool $noPhoto = false): ?array
{
    if (!function_exists('wa_camp_tpl')) return null;
    [$img, $txt] = cx8_names($pdo, $key); $lib = $noPhoto ? 0 : cx8_photo($pdo, $photoKey !== '' ? $photoKey : $key);   // a problem that borrows "Other"'s words keeps ITS OWN photo rule
    if ($lib > 0) foreach ($img as $n) { $id = cx8_tpl_id($pdo, $n); $t = $id ? wa_camp_tpl($pdo, $id, true, $lib) : null; if ($t) return $t; }
    foreach ($txt as $n) { $id = cx8_tpl_id($pdo, $n); $t = $id ? wa_camp_tpl($pdo, $id) : null; if ($t) return $t; }
    return null;
}
/** Send one complaint message as an approved template. [ok, shown text | why, template name]. */
function cx8_send(PDO $pdo, array $c, string $key, array $vars, string $photoKey = ''): array
{
    $t = cx8_pick($pdo, $key, $photoKey); if (!$t) return [false, 'waits for Meta', ''];
    $ctx = wa_camp_ctx($pdo, $t, 99); $ctx['port_left'] = max(1, (int)$ctx['port_left']);   // a complaint message never waits for the campaign limits
    [$nid, $why] = wa_pick_number($pdo, $c, $ctx); if (!$nid) return [false, 'no number free: ' . $why, (string)$t['name']];
    $v = array_slice(array_values($vars), 0, (int)$t['nvars']);
    [$ok, $res] = wa_send_tpl_one($pdo, $c, $t, $ctx['nums'][$nid], $v, 'order');
    if (!$ok && strtoupper((string)$t['header_format']) === 'IMAGE' && ($t2 = cx8_pick($pdo, $key, $photoKey, true))) {   // the photo could not go (file missing / Meta refused it): the approved text version, never nothing
        error_log('cx8: photo version ' . $t['name'] . ' failed (' . $res . '), sending the text version');
        $t = $t2; $v = array_slice(array_values($vars), 0, (int)$t['nvars']); [$ok, $res] = wa_send_tpl_one($pdo, $c, $t, $ctx['nums'][$nid], $v, 'order');
    }
    if (!$ok) return [false, (string)$res, (string)$t['name']];
    return [true, cx6_render((string)$t['body'], $v), (string)$t['name'] . (strtoupper((string)$t['header_format']) === 'IMAGE' ? ' + photo' : '')];
}

/** Make (and send to Meta) the photo version of every message that has a photo but no photo version yet. A few per run. Returns how many were made. */
function cx8_setup(PDO $pdo, int $max = 6, string $only = ''): int
{
    if (!function_exists('wa_tpl_clean') || !function_exists('wa_tpl_insert') || !function_exists('wa_tpl_create_at')) return 0;
    $made = 0;
    foreach (cx8_keys() as $key => $k) {
        if ($only !== '' && $key !== $only) continue; if ($made >= $max) break;
        $lib = cx8_photo($pdo, $key); [$img, $txt] = cx8_names($pdo, $key);
        if ($lib <= 0) { if (isset(cx8_general()[$key]) || $txt || $img) continue; }   // no photo: only a message with NO approved-able version at all gets its plain version made
        elseif (isset(cx8_general()[$key])) { if (cx8_tpl_id($pdo, $img[0])) continue; }
        elseif ($img) continue;
        if ((int)cx_state_get($pdo, 'cx8_try_' . $key, '0') > time() - 6 * 3600) continue;   // a failed try waits 6 hours
        cx_state_put($pdo, 'cx8_try_' . $key, (string)time());
        if (isset(cx8_general()[$key])) [$ok] = cx8_make_general($pdo, $key, $lib); else [$ok] = cx6_save($pdo, $key, cx6_body($pdo, $key), true, 0, max(0, $lib));
        if ($ok) { $made++; cx_state_put($pdo, 'cx8_try_' . $key, '0'); }
    }
    return $made;
}
/** The photo version of a general ticket message (same words and buttons as the text one). */
function cx8_make_general(PDO $pdo, string $key, int $lib): array
{
    [$txt, $name] = cx8_general()[$key];
    $defs = (function_exists('cx3_tpl_defs') ? cx3_tpl_defs() : []) + (function_exists('cx2_tpl_defs') ? cx2_tpl_defs() : []); $d = $defs[$txt[0]] ?? null; if (!$d) return [false, 'unknown message'];
    [$t, $err] = wa_tpl_clean(['name' => $name, 'language' => 'en', 'category' => 'UTILITY', 'header_format' => 'IMAGE', 'header_lib_id' => $lib, 'body' => $d['body'], 'footer' => 'TM Perfume House Customer Care',
        'buttons' => $d['buttons'] ?? [], 'examples' => ['body' => $d['ex'], 'labels' => $d['lab']], 'folder' => 'Complaints']);
    if ($err !== '') return [false, $err];
    $id = wa_tpl_insert($pdo, $t, 0, 'Customer complaint ticket message (' . $d['label'] . ') with the Customer Care photo on top.', 'Utility message about the customer\'s own complaint.');
    $r = wa_tpl_row($pdo, $id); if (!$r) return [false, 'could not save'];
    $ok = 0; $bad = []; foreach ((function_exists('wa_wabas') ? wa_wabas($pdo) : []) as $w) { [$s, $why] = wa_tpl_create_at($pdo, $r, $w); if ($s) $ok++; else $bad[] = $why; }
    return $ok ? [true, 'sent to Meta'] : [false, $bad ? implode(' · ', array_unique($bad)) : 'no WhatsApp account'];
}
/** The Settings list. */
function cx8_list(PDO $pdo): array
{
    $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $out = [];
    $st = function (string $n) use ($pdo, $wabas) { $id = cx8_tpl_id($pdo, $n); if (!$id || !function_exists('wa_tpl_status')) return 'none'; return (string)wa_tpl_status($pdo, $id, $wabas)['status']; };
    foreach (cx8_keys() as $key => $k) {
        [$img, $txt] = cx8_names($pdo, $key); $is = 'none'; foreach ($img as $n) { $s = $st($n); if ($s === 'approved' || $s === 'partial') { $is = 'approved'; break; } if ($is === 'none' && $s !== 'none') $is = $s; }
        $ts = 'none'; foreach ($txt as $n) { $s = $st($n); if ($s === 'approved' || $s === 'partial') { $ts = 'approved'; break; } if ($ts === 'none' && $s !== 'none') $ts = $s; }
        $ch = cx8_choice($pdo, $key);
        $out[] = ['k' => $key, 'label' => $k[0], 'icon' => $k[1], 'group' => $k[2], 'choice' => $ch, 'def' => $k[3], 'photo_status' => $is, 'text_status' => $ts,
            'goes' => $k[2] === 'first' && $is === 'none' && $ts === 'none' ? 'general' : ($ch === 'none' ? ($ts === 'approved' ? 'text' : 'wait') : ($is === 'approved' ? 'photo' : ($ts === 'approved' ? 'text_until_photo' : 'wait')))];   // a problem without its own message uses the general "ticket created"
    }
    return $out;
}
/** A message that could not go yet (resolved / open again): tried again every few minutes for 2 days. */
function cx8_retry_add(PDO $pdo, int $id, string $kind): void
{
    $q = json_decode((string)cx_state_get($pdo, 'cx8_retry', '[]'), true); if (!is_array($q)) $q = [];
    $q[$id . '_' . $kind] = ['id' => $id, 'kind' => $kind, 'at' => date('Y-m-d H:i:s')]; cx_state_put($pdo, 'cx8_retry', json_encode(array_slice($q, -200, null, true)));
}
function cx8_retry_run(PDO $pdo): int
{
    $q = json_decode((string)cx_state_get($pdo, 'cx8_retry', '[]'), true); if (!is_array($q) || !$q) return 0; $n = 0; $keep = [];
    cx_state_put($pdo, 'cx8_retry', '[]');
    foreach ($q as $k => $x) {
        if (strtotime((string)$x['at']) < time() - 2 * 86400) continue;                                         // 2 days: give up
        $r = cx_row($pdo, (int)$x['id']); if (!$r) continue;
        if (($x['kind'] === 'resolved' && !in_array((string)$r['status'], ['resolved', 'closed'], true)) || ($x['kind'] === 'reopened' && $r['status'] !== 'reopened')) continue;   // the ticket moved on
        if (cx3_send($pdo, $r, (string)$x['kind'], true) !== '') { $n++; continue; }
        $keep[$k] = $x;
    }
    $now = json_decode((string)cx_state_get($pdo, 'cx8_retry', '[]'), true); cx_state_put($pdo, 'cx8_retry', json_encode($keep + (is_array($now) ? $now : [])));
    return $n;
}

}
