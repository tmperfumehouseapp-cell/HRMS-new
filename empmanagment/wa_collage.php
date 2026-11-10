<?php
/**
 * wa_collage.php — ONE picture for several perfumes (owner, 10 Nov 2026).
 * When the bot (or a list) suggests 2–5 perfumes, the customer gets ONE image: each perfume's photo with its name, notes and how long it lasts.
 * Built with GD from the Shopify product data already in wa_prod; cached by content (same perfumes + same data = same file).
 *   wa_collage_make($pdo, $productIds, $max = 5)  → ['ok'=>bool, 'url'=>public link, 'names'=>[...], 'ids'=>[...], 'error'=>'']
 *   wa.collage_view&t=<hash>  (public: Meta fetches the picture by its link; the hash cannot be guessed)
 *   wa_collage_enabled($pdo)  → the setting wa_collage_on (default on)
 */
if (!function_exists('wa_collage_make')) {

function wa_collage_dir(): string { $d = __DIR__ . '/uploads/wa_collage/'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function wa_collage_enabled(PDO $pdo): bool { try { return (string)setting_get($pdo, 'wa_collage_on', '1') !== '0'; } catch (Throwable $e) { return true; } }
function wa_collage_font(bool $bold): string
{
    $n = $bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf';
    foreach ([__DIR__ . '/assets/fonts/' . $n, '/usr/share/fonts/truetype/dejavu/' . $n, '/usr/share/fonts/dejavu/' . $n] as $f) if (is_file($f)) return $f;
    return '';
}
/** Word-wrap $text into at most $maxLines lines no wider than $w pixels (the last line gets "…" when cut). */
function wa_collage_wrap(string $text, string $font, float $size, int $w, int $maxLines): array
{
    $words = preg_split('/\s+/u', trim($text)) ?: []; $lines = []; $cur = '';
    foreach ($words as $wd) {
        $try = $cur === '' ? $wd : $cur . ' ' . $wd; $b = imagettfbbox($size, 0, $font, $try);
        if (($b[2] - $b[0]) <= $w || $cur === '') { $cur = $try; } else { $lines[] = $cur; $cur = $wd; }
    }
    if ($cur !== '') $lines[] = $cur;
    if (count($lines) > $maxLines) { $lines = array_slice($lines, 0, $maxLines); $l = rtrim($lines[$maxLines - 1], " ,.") . '…'; $lines[$maxLines - 1] = $l; }
    return $lines;
}
function wa_collage_fetch(string $url)
{
    if (!function_exists('curl_init')) { $d = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8]])); return $d !== false ? @imagecreatefromstring($d) : false; }
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
    $d = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($d !== false && $code >= 200 && $code < 300) ? @imagecreatefromstring((string)$d) : false;
}
/** $rows: decoded wa_prod rows (wa_prod_dec). $imgs: [id => GD image or null] — separated so it can be tested without the network. */
function wa_collage_render(array $rows, array $imgs): ?string
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) return null;
    $fr = wa_collage_font(false); $fb = wa_collage_font(true); if ($fr === '' || $fb === '') return null;
    $n = count($rows); if ($n < 1) return null; $cols = $n <= 3 ? $n : 3; $rowsN = (int)ceil($n / $cols);
    $cw = 380; $ch = 600; $head = 64; $W = $cw * $cols; $H = $head + $ch * $rowsN;
    $im = imagecreatetruecolor($W, $H); imageantialias($im, true);
    $bg = imagecolorallocate($im, 250, 247, 242); $ink = imagecolorallocate($im, 33, 33, 38); $mut = imagecolorallocate($im, 100, 100, 110); $gold = imagecolorallocate($im, 150, 108, 40); $line = imagecolorallocate($im, 226, 219, 206); $card = imagecolorallocate($im, 255, 255, 255);
    imagefill($im, 0, 0, $bg);
    $t = 'TM PERFUME HOUSE'; $b = imagettfbbox(20, 0, $fb, $t); imagettftext($im, 20, 0, (int)(($W - ($b[2] - $b[0])) / 2), 40, $gold, $fb, $t);
    foreach ($rows as $i => $p) {
        $c = $i % $cols; $r = intdiv($i, $cols); $x = $c * $cw; $y = $head + $r * $ch;
        imagefilledrectangle($im, $x + 8, $y + 4, $x + $cw - 8, $y + $ch - 8, $card); imagerectangle($im, $x + 8, $y + 4, $x + $cw - 8, $y + $ch - 8, $line);
        $ph = $imgs[$p['id']] ?? null; $S = $cw - 36; $px = $x + 18; $py = $y + 14;
        if ($ph) { $sw = imagesx($ph); $sh = imagesy($ph); $m = min($sw, $sh); imagecopyresampled($im, $ph, $px, $py, (int)(($sw - $m) / 2), (int)(($sh - $m) / 2), $S, $S, $m, $m); }   // centred square crop
        else { imagefilledrectangle($im, $px, $py, $px + $S - 1, $py + $S - 1, $bg); }
        $ty = $py + $S + 30; $tw = $cw - 40;
        foreach (wa_collage_wrap((string)$p['name'], $fb, 17, $tw, 2) as $ln) { imagettftext($im, 17, 0, $px, $ty, $ink, $fb, $ln); $ty += 26; }
        $notes = array_values(array_filter(array_map('trim', (array)$p['notes']))); $ty += 4;
        if ($notes) foreach (wa_collage_wrap('Notes: ' . implode(', ', array_slice($notes, 0, 8)), $fr, 13, $tw, 3) as $ln) { imagettftext($im, 13, 0, $px, $ty, $mut, $fr, $ln); $ty += 20; }
        $lg = trim((string)$p['longevity']); if ($lg !== '') imagettftext($im, 14, 0, $px, $y + $ch - 24, $gold, $fb, 'Lasts: ' . mb_substr($lg, 0, 22));
    }
    ob_start(); imagejpeg($im, null, 84); $d = ob_get_clean(); imagedestroy($im); return $d !== false && $d !== '' ? $d : null;
}
function wa_collage_make(PDO $pdo, array $ids, int $max = 5): array
{
    $o = ['ok' => false, 'url' => '', 'names' => [], 'ids' => [], 'error' => ''];
    if (!function_exists('wa_prod_get') || !function_exists('pc_base_url')) { $o['error'] = 'Collage is not available.'; return $o; }
    $rows = []; foreach (wa_prod_get($pdo, array_slice(array_values(array_unique(array_map('intval', $ids))), 0, $max)) as $p) { $p = wa_prod_dec($p); if ((int)$p['active'] === 1 && $p['photos']) $rows[] = $p; }
    if (!$rows) { $o['error'] = 'None of these perfumes has a photo.'; return $o; }
    $sig = []; foreach ($rows as $p) $sig[] = [$p['id'], $p['name'], $p['notes'], $p['longevity'], $p['photos'][0]]; $key = md5(json_encode($sig, JSON_UNESCAPED_UNICODE) . '|v1');
    $file = wa_collage_dir() . $key . '.jpg';
    if (!is_file($file)) {
        $imgs = []; foreach ($rows as $p) $imgs[$p['id']] = wa_collage_fetch(function_exists('wa_prod_img_url') ? wa_prod_img_url((string)$p['photos'][0]) . '' : (string)$p['photos'][0]) ?: null;
        $jpg = wa_collage_render($rows, $imgs); foreach ($imgs as $g) if ($g) imagedestroy($g);
        if ($jpg === null) { $o['error'] = 'Could not draw the picture (GD / font missing).'; return $o; }
        if (@file_put_contents($file, $jpg) === false) { $o['error'] = 'Could not save the picture.'; return $o; }
    }
    return ['ok' => true, 'url' => pc_base_url() . '/empmanagment/api.php?action=wa.collage_view&t=' . $key, 'names' => array_column($rows, 'name'), 'ids' => array_column($rows, 'id'), 'error' => ''];
}

/** Send ONE collage for these perfumes (2–5) — or the single photo when only one is left. Perfumes already sent to this customer in the last 24 h are left out when the bot sends. Returns the number of perfumes shown. */
function wa_collage_send(PDO $pdo, array $c, array $prodIds, string $who, int $empId, array $why = []): int
{
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $prodIds)))), 0, 5); if (!$ids) return 0;
    $phone = (string)$c['phone'];
    if ($who === 'bot') { $recent = wa_prod_recent($pdo, $phone, 24); $ids = array_values(array_filter($ids, fn($i) => empty($recent[$i]))); if (!$ids) return 0; }
    if (count($ids) === 1 || !wa_collage_enabled($pdo)) { $r = wa_prod_send_items($pdo, $c, $ids, 'why', $who, $empId, ['why' => $why]); return count($r['sent'] ?? []); }
    $m = wa_collage_make($pdo, $ids, 5);
    if ($m['ok']) {
        [$ok, $r] = wa_send_image_link($pdo, $c, $m['url'], mb_substr(implode(' · ', $m['names']), 0, 900), $who, $empId, 0);
        if ($ok) { foreach ($m['ids'] as $pid) { try { wa_prod_seen_mark($pdo, $phone, (int)$pid, 0, (string)(is_array($r) ? ($r['wamid'] ?? '') : $r)); } catch (Throwable $e) { } } return count($m['ids']); }
        error_log('wa_collage_send: ' . (is_string($r) ? $r : json_encode($r)));
    }
    $r = wa_prod_send_items($pdo, $c, $ids, 'why', $who, $empId, ['why' => $why]); return count($r['sent'] ?? []);   // the picture could not be made or sent: the separate photos go as before
}
/** 🧠 Learning gate (owner, 10 Nov): the bot keeps sending photos only while they help. Every 12 h we compare, over the last 14 days, how often customers wrote back within 6 hours after a bot answer WITH photos vs WITHOUT.
 *  WhatsApp does not tell us who "opened" a picture, so a reply is the signal we have. 150+ answers on each side and the photo side 15%+ lower → photos pause (the Products tab note says why); the check keeps running and turns them back on when it recovers.
 *  setting wa_photo_mode: auto (default) · on (always) · off (never). Returns true = photos may go. */
function wa_photo_gate(PDO $pdo): bool
{
    $mode = (string)setting_get($pdo, 'wa_photo_mode', 'auto'); if ($mode === 'on') return true; if ($mode === 'off') return false;
    $st = json_decode((string)setting_get($pdo, 'wa_photo_learn', ''), true); $st = is_array($st) ? $st : [];
    if ((int)($st['t'] ?? 0) < time() - 12 * 3600) {
        $st = ['t' => time(), 'pause' => (bool)($st['pause'] ?? false), 'with' => [0, 0], 'without' => [0, 0], 'why' => (string)($st['why'] ?? '')];
        try {
            $since = date('Y-m-d H:i:s', time() - 14 * 86400); $cut = date('Y-m-d H:i:s', time() - 6 * 3600);
            $q = $pdo->prepare("SELECT m.id, m.phone, m.ts, EXISTS(SELECT 1 FROM wa_prod_seen s WHERE s.phone = m.phone AND s.at >= m.ts AND s.at <= DATE_ADD(m.ts, INTERVAL 3 MINUTE)) AS ph,
                EXISTS(SELECT 1 FROM wa_messages i WHERE i.phone = m.phone AND i.dir = 'in' AND i.ts > m.ts AND i.ts <= DATE_ADD(m.ts, INTERVAL 6 HOUR)) AS rep
                FROM wa_messages m WHERE m.dir = 'out' AND m.who = 'bot' AND m.mtype = 'text' AND m.ts >= ? AND m.ts <= ? ORDER BY m.id DESC LIMIT 4000"); $q->execute([$since, $cut]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $k = (int)$r['ph'] ? 'with' : 'without'; $st[$k][0]++; if ((int)$r['rep']) $st[$k][1]++; }
        } catch (Throwable $e) { }
        [$nw, $rw] = $st['with']; [$no, $ro] = $st['without'];
        if ($nw >= 150 && $no >= 150) { $a = $rw / $nw; $b = $ro / $no; $st['pause'] = $a < $b * 0.85; $st['why'] = 'Replies after a bot answer: with photos ' . round($a * 100) . '% (' . $nw . ' answers) · without ' . round($b * 100) . '% (' . $no . ' answers).' . ($st['pause'] ? ' Photos are paused until this recovers.' : ''); }
        try { setting_set($pdo, 'wa_photo_learn', json_encode($st)); } catch (Throwable $e) { }
    }
    return empty($st['pause']);
}
}   // function_exists guard

$__wcg = (string)($action ?? ($_GET['action'] ?? ''));
if ($__wcg === 'wa.collage_view') {   // public: Meta fetches the picture by its link
    $t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? '')); $f = wa_collage_dir() . $t . '.jpg';
    if (strlen($t) < 16 || !is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('Cache-Control: public, max-age=86400'); header('Content-Length: ' . filesize($f)); readfile($f); exit;
}
