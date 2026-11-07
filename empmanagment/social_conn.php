<?php
/**
 * social_conn.php — the connectors of the Inbox hub (loaded by social.php).  PHP 7.4 safe.
 *
 *  Every connector has:  sx_test_<kind>($pdo, &$secret, &$extra) → [ok, message]      (checks the keys, finds ids, nothing is saved here)
 *                        sx_pull_<kind>($pdo, $conn, &$stats, $quiet)                   (reads new things → sx_upsert)
 *                        sx_send_<kind>($pdo, $conn, $item, $mode, $text) → [ok, message, ext_id]
 *  Only official APIs are used (Meta Graph, IMAP/SMTP, Judge.me, Google Business Profile, Google Play Developer).  No scraping.
 */
if (!defined('SX_LOADED')) { return; }

function sx_dt($s) { $t = is_numeric($s) ? (int)$s : strtotime((string)$s); return $t ? date('Y-m-d H:i:s', $t) : bd_now(); }
function sx_b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/* ============================================================ META (Instagram + Facebook) ============================================================ */
function sx_graph_base() { return rtrim((string)tmph_cfg('SX_GRAPH_BASE', 'https://graph.facebook.com/v23.0'), '/'); }
/** one Graph call.  Returns [ok, data, errorText, errorCode] */
function sx_g($method, $path, $token, array $params = [], $payload = null) {
    $url = sx_graph_base() . '/' . ltrim($path, '/') . '?' . http_build_query($params + ['access_token' => $token]);
    list($code, $j, $raw, $err) = $payload === null ? sx_http($method, $url) : sx_http_json($method, $url, [], $payload);
    if ($err === '' && $code >= 200 && $code < 300 && is_array($j)) return [true, $j, '', 0];
    return [false, $j ?: [], sx_err_text($code, $j, $raw, $err), is_array($j) ? (int)($j['error']['code'] ?? 0) : 0];
}
function sx_test_meta(PDO $pdo, array &$sec, array &$extra) {
    $page = preg_replace('/\D+/', '', (string)($sec['page_id'] ?? '')); $tok = trim((string)($sec['token'] ?? ''));
    if ($page === '' || $tok === '') return [false, 'Page ID and token are needed.'];
    $r = sx_g('GET', $page, $tok, ['fields' => 'name,access_token,instagram_business_account{id,username}']);
    if (!$r[0]) return [false, $r[2]];
    $d = $r[1]; $sec['page_id'] = $page; $sec['ptoken'] = !empty($d['access_token']) ? (string)$d['access_token'] : $tok;
    $ig = $d['instagram_business_account'] ?? null; $extra['ig_id'] = $ig['id'] ?? ''; $extra['ig_user'] = $ig['username'] ?? ''; $extra['page_name'] = $d['name'] ?? '';
    $extra['info'] = ['name' => 'Facebook: ' . ($d['name'] ?? $page) . ($extra['ig_user'] !== '' ? ' · Instagram: @' . $extra['ig_user'] : '')];
    return [true, 'Found Facebook page “' . ($d['name'] ?? $page) . '”' . ($extra['ig_user'] !== '' ? ' and Instagram @' . $extra['ig_user'] . '.' : ' — but no Instagram business account is linked to it, so Instagram will be skipped.')];
}
function sx_meta_sender($m, $page, $ig, $igu) {
    $id = (string)($m['from']['id'] ?? ''); $un = (string)($m['from']['username'] ?? '');
    return ($id !== '' && ($id === (string)$page || ($ig !== '' && $id === (string)$ig))) || ($igu !== '' && strcasecmp($un, $igu) === 0);
}
function sx_pull_meta(PDO $pdo, array $c, array &$stats, $quiet) {
    $sec = sx_dec($c['secret']); $x = bd_unjson($c['extra'], []); $page = (string)$sec['page_id']; $pt = (string)($sec['ptoken'] ?? $sec['token']); $ig = (string)($x['ig_id'] ?? ''); $igu = (string)($x['ig_user'] ?? '');
    $first = empty($c['last_sync']); $since = $first ? time() - 14 * 86400 : strtotime($c['last_sync']) - 86400; $pages = $first ? 3 : 1; $fatal = false;
    $note = function ($t) use (&$stats) { $stats['notes'][] = $t; };
    $count = function ($res) use (&$stats) { $stats['items']++; if (!empty($res['new'])) $stats['new']++; };
    // 1+2. DMs
    foreach (['messenger' => 'fb_dm', 'instagram' => 'ig_dm'] as $plat => $chn) {
        if ($plat === 'instagram' && $ig === '') continue;
        $next = null; $path = $page . '/conversations'; $params = ['platform' => $plat, 'fields' => 'id,updated_time,participants,messages.limit(15){id,message,from,created_time,attachments{name}}', 'limit' => 25];
        for ($p = 0; $p < $pages; $p++) {
            $r = $next ? sx_g('GET', $next[0], $pt, $next[1]) : sx_g('GET', $path, $pt, $params);
            if (!$r[0]) { if ($r[3] === 190 || $r[3] === 102) throw new Exception($r[2]); $note(($plat === 'instagram' ? 'Instagram DMs' : 'Messenger') . ': ' . sx_short($r[2], 140)); break; }
            $stop = false;
            foreach ((array)($r[1]['data'] ?? []) as $cv) {
                if (strtotime((string)($cv['updated_time'] ?? '')) < $since) { $stop = true; continue; }
                $cust = null; foreach ((array)($cv['participants']['data'] ?? []) as $pp) { $pid = (string)($pp['id'] ?? ''); if ($pid !== '' && $pid !== $page && $pid !== $ig && strcasecmp((string)($pp['username'] ?? ''), $igu) !== 0) { $cust = $pp; break; } }
                if (!$cust) continue; $msgs = [];
                foreach ((array)($cv['messages']['data'] ?? []) as $m) {
                    $t = trim((string)($m['message'] ?? '')); if ($t === '') $t = !empty($m['attachments']['data']) ? '[photo / attachment]' : ''; if ($t === '') continue;
                    $msgs[] = [(string)$m['id'], sx_meta_sender($m, $page, $ig, $igu) ? 'out' : 'in', $t, sx_dt($m['created_time'] ?? '')];
                }
                if (!$msgs) continue; usort($msgs, function ($a, $b) { return strcmp($a[3], $b[3]); });
                $nm = trim((string)($cust['name'] ?? '')) ?: (!empty($cust['username']) ? '@' . $cust['username'] : 'Customer');
                $count(sx_upsert($pdo, $c, $chn, (string)$cv['id'], ['thread_key' => (string)$cv['id'], 'who_name' => $nm, 'who_id' => (string)$cust['id'], 'who_email' => (string)($cust['email'] ?? ''), 'raw' => ['psid' => (string)$cust['id'], 'conv' => (string)$cv['id']]], $msgs, $quiet));
            }
            $nx = $r[1]['paging']['next'] ?? ''; if ($stop || !$nx) break;
            $q = []; parse_str((string)parse_url($nx, PHP_URL_QUERY), $q); unset($q['access_token']); $next = [$path, $q];
        }
    }
    // 3. Facebook page comments (+ ad posts)
    $cf = 'id,message,permalink_url,created_time,comments.limit(30){id,message,from,created_time,permalink_url,can_reply_privately,is_hidden,comments.limit(10){id,message,from,created_time}}';
    foreach (['feed' => 0, 'ads_posts' => 1] as $edge => $isAd) {
        $r = sx_g('GET', $page . '/' . $edge, $pt, ['fields' => $cf, 'limit' => 10]);
        if (!$r[0]) { if ($r[3] === 190) throw new Exception($r[2]); $note('Facebook ' . ($isAd ? 'ad ' : 'page ') . 'comments: ' . sx_short($r[2], 120)); continue; }
        foreach ((array)($r[1]['data'] ?? []) as $po) {
            $post = sx_short((string)($po['message'] ?? 'Post'), 120);
            foreach ((array)($po['comments']['data'] ?? []) as $cm) {
                if ((string)($cm['from']['id'] ?? '') === $page || empty($cm['id'])) continue;
                $t = trim((string)($cm['message'] ?? '')); if ($t === '') continue; $msgs = [[(string)$cm['id'], 'in', $t, sx_dt($cm['created_time'] ?? '')]];
                foreach ((array)($cm['comments']['data'] ?? []) as $rp) { $rt = trim((string)($rp['message'] ?? '')); if ($rt !== '') $msgs[] = [(string)$rp['id'], sx_meta_sender($rp, $page, $ig, $igu) ? 'out' : 'in', $rt, sx_dt($rp['created_time'] ?? '')]; }
                usort($msgs, function ($a, $b) { return strcmp($a[3], $b[3]); });
                if (!$first && strtotime($msgs[0][3]) < $since - 14 * 86400 && count($msgs) === 1) continue;
                $count(sx_upsert($pdo, $c, 'fb_comment', (string)$cm['id'], ['thread_key' => (string)$cm['id'], 'who_name' => (string)($cm['from']['name'] ?? 'Facebook user'), 'who_id' => (string)($cm['from']['id'] ?? ''), 'post_ref' => $post, 'url' => (string)($cm['permalink_url'] ?? $po['permalink_url'] ?? ''), 'is_ad' => $isAd,
                    'raw' => ['comment_id' => (string)$cm['id'], 'post_id' => (string)$po['id'], 'comment_at' => sx_dt($cm['created_time'] ?? '')]], $msgs, $quiet));
            }
        }
    }
    // 4. Instagram comments
    if ($ig !== '') {
        $r = sx_g('GET', $ig . '/media', $pt, ['fields' => 'id,caption,permalink,timestamp,comments.limit(30){id,text,username,timestamp,replies.limit(10){id,text,username,timestamp}}', 'limit' => 12]);
        if (!$r[0]) { if ($r[3] === 190) throw new Exception($r[2]); $note('Instagram comments: ' . sx_short($r[2], 120)); }
        else foreach ((array)($r[1]['data'] ?? []) as $md) {
            $post = sx_short((string)($md['caption'] ?? 'Post'), 120);
            foreach ((array)($md['comments']['data'] ?? []) as $cm) {
                if (empty($cm['id']) || strcasecmp((string)($cm['username'] ?? ''), $igu) === 0) continue; $t = trim((string)($cm['text'] ?? '')); if ($t === '') continue;
                $msgs = [[(string)$cm['id'], 'in', $t, sx_dt($cm['timestamp'] ?? '')]];
                foreach ((array)($cm['replies']['data'] ?? []) as $rp) { $rt = trim((string)($rp['text'] ?? '')); if ($rt !== '') $msgs[] = [(string)$rp['id'], strcasecmp((string)($rp['username'] ?? ''), $igu) === 0 ? 'out' : 'in', $rt, sx_dt($rp['timestamp'] ?? '')]; }
                usort($msgs, function ($a, $b) { return strcmp($a[3], $b[3]); });
                $count(sx_upsert($pdo, $c, 'ig_comment', (string)$cm['id'], ['thread_key' => (string)$cm['id'], 'who_name' => '@' . (string)($cm['username'] ?? 'user'), 'who_id' => (string)($cm['username'] ?? ''), 'post_ref' => $post, 'url' => (string)($md['permalink'] ?? ''),
                    'raw' => ['comment_id' => (string)$cm['id'], 'media_id' => (string)$md['id'], 'comment_at' => sx_dt($cm['timestamp'] ?? '')]], $msgs, $quiet));
            }
        }
    }
}
/* ---- deleted comments: does the comment still exist on the post?  true = yes, false = Meta says it is gone, null = could not tell (token / network / rate limit) ---- */
function sx_graph_missing($code, $j) {
    $e = is_array($j) && isset($j['error']) && is_array($j['error']) ? $j['error'] : []; $c = (int)($e['code'] ?? $code); $sub = (int)($e['error_subcode'] ?? 0); $msg = strtolower((string)($e['message'] ?? ''));
    if (in_array($c, [190, 102, 10, 200, 4, 17, 32, 341, 368, 613], true)) return false;   // token, permission, rate limit: this says nothing about the comment
    if ($c === 803 || $sub === 33) return true;
    return $c === 100 && (strpos($msg, 'does not exist') !== false || strpos($msg, 'unsupported get request') !== false || strpos($msg, 'has been deleted') !== false || strpos($msg, 'invalid comment') !== false);
}
function sx_meta_exists(PDO $pdo, array $c, array $r) {
    $raw = bd_unjson($r['raw'], []); $cid = (string)($raw['comment_id'] ?? ''); $sec = sx_dec($c['secret']); $pt = (string)($sec['ptoken'] ?? $sec['token'] ?? '');
    if ($cid === '' || $pt === '') return null;
    $res = sx_g('GET', $cid, $pt, ['fields' => 'id']);
    if ($res[0]) return true;
    return sx_graph_missing($res[3], $res[1]) ? false : null;
}
/** many comments in one call (Graph batch, 50 at a time).  Returns [comment_id => true|false|null] */
function sx_meta_exists_batch(PDO $pdo, array $c, array $ids) {
    $sec = sx_dec($c['secret']); $pt = (string)($sec['ptoken'] ?? $sec['token'] ?? ''); $out = [];
    foreach ($ids as $i) $out[(string)$i] = null;
    if ($pt === '') return $out;
    foreach (array_chunk(array_values(array_map('strval', $ids)), 50) as $chunk) {
        $batch = []; foreach ($chunk as $i) $batch[] = ['method' => 'GET', 'relative_url' => rawurlencode($i) . '?fields=id'];
        $res = sx_g('POST', '', $pt, [], ['batch' => $batch]);
        if (!$res[0] || !isset($res[1][0])) continue;   // the whole call failed → nobody is judged
        foreach ($chunk as $k => $i) {
            $it = $res[1][$k] ?? null; if (!is_array($it)) continue;
            if ((int)($it['code'] ?? 0) === 200) { $out[$i] = true; continue; }
            $b = json_decode((string)($it['body'] ?? ''), true); $out[$i] = sx_graph_missing((int)($it['code'] ?? 0), is_array($b) ? $b : null) ? false : null;
        }
    }
    return $out;
}
/** every ~15 min: look at the comments still waiting for a reply and move the deleted ones to "Deleted".  A comment must be missing twice in a row. */
function sx_sweep_gone(PDO $pdo, $now) {
    if ($now - (int)sx_state_get($pdo, 'gone_sweep', '0') < 900) return 0;
    sx_state_put($pdo, 'gone_sweep', (string)$now); $moved = 0; $cut = date('Y-m-d H:i:s', $now - 30 * 86400); $nowS = date('Y-m-d H:i:s', $now);
    foreach ($pdo->query("SELECT * FROM sx_conn WHERE kind = 'meta' AND status IN ('ok','error')")->fetchAll() as $c) {
        $st = $pdo->prepare("SELECT id, raw, gone_miss FROM sx_items WHERE conn_id = ? AND channel IN ('ig_comment','fb_comment') AND status = 'new' AND gone = 0 AND last_in_at >= ? ORDER BY (gone_chk IS NULL) DESC, gone_chk ASC, id DESC LIMIT 50");
        $st->execute([(int)$c['id'], $cut]); $rows = []; $ids = [];
        foreach ($st->fetchAll() as $r) { $raw = bd_unjson($r['raw'], []); if (!empty($raw['comment_id'])) { $rows[(string)$raw['comment_id']] = $r; $ids[] = (string)$raw['comment_id']; } }
        if (!$ids) continue;
        $map = sx_meta_exists_batch($pdo, $c, $ids); $judged = 0; $gone = 0; foreach ($map as $v) { if ($v !== null) $judged++; if ($v === false) $gone++; }
        if ($judged >= 4 && $gone === $judged) { error_log('Inbox deleted-check: every comment looked missing — probably a token problem, nothing was changed.'); continue; }
        foreach ($map as $cid => $v) {
            $r = $rows[$cid] ?? null; if (!$r || $v === null) continue; $id = (int)$r['id'];
            if ($v === true) { $pdo->prepare("UPDATE sx_items SET gone_chk = ?, gone_miss = 0 WHERE id = ?")->execute([$nowS, $id]); continue; }
            if ((int)$r['gone_miss'] >= 1) { if (sx_mark_gone($pdo, $id, 'checked with Meta')) $moved++; }
            else $pdo->prepare("UPDATE sx_items SET gone_chk = ?, gone_miss = 1 WHERE id = ?")->execute([$nowS, $id]);
        }
    }
    return $moved;
}
function sx_send_meta(PDO $pdo, array $c, array $r, $mode, $text) {
    $sec = sx_dec($c['secret']); $pt = (string)($sec['ptoken'] ?? $sec['token']); $page = (string)$sec['page_id']; $raw = bd_unjson($r['raw'], []);
    if ($mode === 'dm') {
        if (empty($raw['psid'])) return [false, 'The customer id is missing — sync again.'];
        $h = $r['last_in_at'] ? (time() - strtotime($r['last_in_at'])) / 3600 : 999; if ($h > 168) return [false, 'More than 7 days since the customer wrote — Meta does not allow a reply.'];
        $body = ['recipient' => ['id' => $raw['psid']], 'message' => ['text' => $text]]; if ($h <= 24) $body['messaging_type'] = 'RESPONSE'; else { $body['messaging_type'] = 'MESSAGE_TAG'; $body['tag'] = 'HUMAN_AGENT'; }
        $res = sx_g('POST', $page . '/messages', $pt, [], $body); if (!$res[0]) return [false, $res[2]];
        return [true, 'Sent.', (string)($res[1]['message_id'] ?? '')];
    }
    if ($mode === 'comment') {
        if (empty($raw['comment_id'])) return [false, 'The comment id is missing — sync again.'];
        $res = $r['channel'] === 'ig_comment' ? sx_g('POST', $raw['comment_id'] . '/replies', $pt, [], ['message' => $text]) : sx_g('POST', $raw['comment_id'] . '/comments', $pt, [], ['message' => $text]);
        if (!$res[0]) return [false, $res[2]]; return [true, 'Reply posted under the comment.', (string)($res[1]['id'] ?? '')];
    }
    if ($mode === 'private') {
        if (empty($raw['comment_id'])) return [false, 'The comment id is missing — sync again.'];
        $res = sx_g('POST', $page . '/messages', $pt, [], ['recipient' => ['comment_id' => $raw['comment_id']], 'message' => ['text' => $text], 'messaging_type' => 'RESPONSE']);
        if (!$res[0]) return [false, $res[2]]; return [true, 'Private message sent to the commenter.', (string)($res[1]['message_id'] ?? '')];
    }
    return [false, 'Unknown reply type.'];
}
function sx_hide_meta(PDO $pdo, array $c, array $r, $hide) {
    $sec = sx_dec($c['secret']); $pt = (string)($sec['ptoken'] ?? $sec['token']); $raw = bd_unjson($r['raw'], []); if (empty($raw['comment_id'])) return [false, 'The comment id is missing.'];
    $res = sx_g('POST', $raw['comment_id'], $pt, [], $r['channel'] === 'ig_comment' ? ['hide' => (bool)$hide] : ['is_hidden' => (bool)$hide]);
    return $res[0] ? [true, 'ok'] : [false, $res[2]];
}

/* ============================================================ EMAIL (IMAP in, SMTP out) ============================================================ */
function sx_mail_dec_header($s) {
    $s = (string)$s; if ($s === '' || strpos($s, '=?') === false) return trim($s);
    $d = function_exists('iconv_mime_decode') ? @iconv_mime_decode($s, 2, 'UTF-8') : false; if ($d === false || $d === '') $d = function_exists('mb_decode_mimeheader') ? mb_decode_mimeheader($s) : $s; return trim((string)$d);
}
function sx_mail_headers($t) {
    $t = preg_replace("/\n[ \t]+/", ' ', str_replace("\r\n", "\n", (string)$t)); $h = [];
    foreach (explode("\n", $t) as $ln) { $p = strpos($ln, ':'); if ($p === false) continue; $k = strtolower(trim(substr($ln, 0, $p))); if (!isset($h[$k])) $h[$k] = trim(substr($ln, $p + 1)); }
    return $h;
}
function sx_mail_body_decode($body, $cte, $charset) {
    $cte = strtolower(trim((string)$cte));
    if ($cte === 'base64') $body = base64_decode(preg_replace('/\s+/', '', $body)); elseif ($cte === 'quoted-printable') $body = quoted_printable_decode($body);
    $cs = strtolower(trim((string)$charset, " \"'")); if ($cs !== '' && $cs !== 'utf-8' && $cs !== 'us-ascii') { $c = @mb_convert_encoding($body, 'UTF-8', $cs); if ($c !== false && $c !== '') $body = $c; }
    return (string)$body;
}
function sx_mail_param($hv, $name) { return preg_match('/' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|([^;\s]+))/i', (string)$hv, $m) ? (isset($m[3]) && $m[3] !== '' ? $m[3] : $m[2]) : ''; }
function sx_mail_walk($hdr, $body, array &$o) {
    $ct = $hdr['content-type'] ?? 'text/plain'; $type = strtolower(trim(explode(';', $ct)[0])); $cte = $hdr['content-transfer-encoding'] ?? '';
    if (strpos($type, 'multipart/') === 0) {
        $b = sx_mail_param($ct, 'boundary'); if ($b === '') return;
        foreach (preg_split('/\n?--' . preg_quote($b, '/') . '(?:--)?[ \t]*\n?/', $body) as $i => $part) {
            if ($i === 0 || trim($part) === '') continue; $pp = strpos($part, "\n\n"); if ($pp === false) continue;
            sx_mail_walk(sx_mail_headers(substr($part, 0, $pp)), substr($part, $pp + 2), $o);
        }
        return;
    }
    $isAtt = stripos((string)($hdr['content-disposition'] ?? ''), 'attachment') !== false || sx_mail_param($ct, 'name') !== '' || sx_mail_param((string)($hdr['content-disposition'] ?? ''), 'filename') !== '';
    if ($type === 'text/plain' && !$isAtt) { if ($o['text'] === '') $o['text'] = sx_mail_body_decode($body, $cte, sx_mail_param($ct, 'charset')); }
    elseif ($type === 'text/html' && !$isAtt) { if ($o['html'] === '') $o['html'] = sx_mail_body_decode($body, $cte, sx_mail_param($ct, 'charset')); }
    else $o['att']++;
}
function sx_html_text($h) {
    $h = preg_replace('~<(script|style)[^>]*>.*?</\1>~is', '', (string)$h); $h = preg_replace('~<\s*(br|/p|/div|/tr|/li)[^>]*>~i', "\n", $h);
    return trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($h), ENT_QUOTES, 'UTF-8')));
}
/** the new words of a mail, without the quoted older mails */
function sx_mail_fresh($t) {
    $lines = explode("\n", str_replace("\r", '', (string)$t)); $out = [];
    foreach ($lines as $i => $ln) {
        if (preg_match('/^\s*On .{4,200}(wrote|said):\s*$/iu', $ln) || (preg_match('/^\s*On .{4,120}$/u', $ln) && isset($lines[$i + 1]) && preg_match('/wrote:\s*$/i', $lines[$i + 1]))) break;
        if (preg_match('/^\s*-{2,}\s*(Original Message|Forwarded message)/i', $ln) || preg_match('/^_{10,}\s*$/', $ln)) break;
        if (preg_match('/^From:\s.+/i', $ln) && isset($lines[$i + 1]) && preg_match('/^(Sent|Date):/i', $lines[$i + 1])) break;
        if (preg_match('/^\s*>/', $ln)) continue; $out[] = $ln;
    }
    $r = trim(implode("\n", $out)); return $r !== '' ? $r : trim((string)$t);
}
function sx_mail_parse($raw) {
    $raw = str_replace("\r\n", "\n", (string)$raw); $p = strpos($raw, "\n\n"); $hdr = sx_mail_headers($p === false ? $raw : substr($raw, 0, $p)); $body = $p === false ? '' : substr($raw, $p + 2);
    $o = ['text' => '', 'html' => '', 'att' => 0]; sx_mail_walk($hdr, $body, $o);
    $text = $o['text'] !== '' ? $o['text'] : sx_html_text($o['html']);
    $from = sx_mail_dec_header($hdr['from'] ?? ''); $name = ''; $addr = '';
    if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $from, $m)) { $name = trim($m[1]); $addr = trim($m[2]); } else { $addr = trim($from, " <>"); }
    $rt = sx_mail_dec_header($hdr['reply-to'] ?? ''); $reply = $addr; if ($rt !== '') { if (preg_match('/<([^>]+)>/', $rt, $m)) $reply = trim($m[1]); elseif (strpos($rt, '@') !== false) $reply = trim($rt); }
    $refs = []; foreach (['references', 'in-reply-to'] as $k) if (!empty($hdr[$k])) { preg_match_all('/<[^>]+>/', $hdr[$k], $mm); foreach ($mm[0] as $id) $refs[] = $id; }
    $auto = (isset($hdr['auto-submitted']) && strtolower($hdr['auto-submitted']) !== 'no') || (isset($hdr['precedence']) && preg_match('/bulk|list|junk/i', $hdr['precedence'])) || isset($hdr['list-unsubscribe']) || isset($hdr['x-auto-response-suppress'])
        || preg_match('/(no-?reply|do-?not-?reply|mailer-daemon|postmaster|notifications?@|bounce)/i', $addr);
    $mid = ''; if (!empty($hdr['message-id']) && preg_match('/<[^>]+>/', $hdr['message-id'], $mm)) $mid = $mm[0];
    return ['from' => strtolower($addr), 'name' => $name !== '' ? $name : $addr, 'reply_to' => strtolower($reply), 'subject' => sx_mail_dec_header($hdr['subject'] ?? '(no subject)'), 'mid' => $mid, 'refs' => array_values(array_unique($refs)), 'at' => sx_dt($hdr['date'] ?? ''),
        'text' => sx_mail_fresh(trim($text)), 'full' => trim($text), 'att' => $o['att'], 'auto' => (bool)$auto];
}
/** new messages of the mailbox after $lastUid → [[uid, raw], ...], newest uid.  Real mailbox via the PHP imap extension; a test folder of .eml files can stand in (SX_MAIL_FIXTURES). */
function sx_imap_new(array $sec, $lastUid, $limit = 40) {
    $fx = (string)tmph_cfg('SX_MAIL_FIXTURES', '');
    if ($fx !== '' && is_dir($fx)) { $files = glob(rtrim($fx, '/') . '/*.eml'); sort($files); $out = []; foreach ($files as $i => $f) { $uid = $i + 1; if ($uid > $lastUid) $out[] = [$uid, (string)file_get_contents($f)]; } return [array_slice($out, 0, $limit), $out ? end($out)[0] : $lastUid]; }
    if (!function_exists('imap_open')) throw new Exception('The PHP imap extension is off. hPanel → Advanced → PHP Configuration → Extensions → tick “imap”.');
    $port = (int)($sec['imap_port'] ?? 993); $flags = $port === 143 ? '/imap/notls' : '/imap/ssl'; $box = '{' . $sec['imap_host'] . ':' . $port . $flags . '}INBOX';
    $im = @imap_open($box, (string)$sec['user'], (string)$sec['pass'], OP_READONLY, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']); if (!$im) throw new Exception('Mailbox login failed: ' . imap_last_error());
    try {
        $uids = imap_search($im, $lastUid > 0 ? 'ALL' : 'SINCE "' . date('d-M-Y', time() - 7 * 86400) . '"', SE_UID); $uids = $uids ?: []; sort($uids); $uids = array_values(array_filter($uids, function ($u) use ($lastUid) { return $u > $lastUid; }));
        $uids = array_slice($uids, 0, $limit); $out = []; foreach ($uids as $u) { $raw = imap_fetchbody($im, $u, '', FT_UID | FT_PEEK); if ($raw) $out[] = [$u, $raw]; }
        return [$out, $uids ? end($uids) : $lastUid];
    } finally { @imap_close($im); }
}
function sx_smtp_send(array $sec, $toEmail, $toName, $subject, $text, $inReplyTo = '', array $refs = []) {
    $host = (string)($sec['smtp_host'] ?? ''); $port = (int)($sec['smtp_port'] ?? 465); $user = (string)$sec['user']; if ($host === '') return [false, 'SMTP server is missing.', ''];
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return [false, 'The customer email address is not valid.', ''];
    $ssl = $port === 465; $fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, 20); if (!$fp) return [false, 'Cannot reach the mail server (' . $es . ').', '']; stream_set_timeout($fp, 20);
    $rd = function () use ($fp) { $o = ''; while (($l = fgets($fp, 1024)) !== false) { $o .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $o; };
    $cmd = function ($c, $ok) use ($fp, $rd) { if ($c !== null) fwrite($fp, $c . "\r\n"); $r = $rd(); return [strpos($r, (string)$ok) === 0, trim($r)]; };
    try {
        $r = $cmd(null, '220'); if (!$r[0]) return [false, 'Mail server: ' . $r[1], ''];
        $dom = preg_replace('/^.*@/', '', $user) ?: 'localhost'; $r = $cmd('EHLO ' . $dom, '250'); if (!$r[0]) return [false, 'Mail server: ' . $r[1], '']; $caps = $r[1];
        if (!$ssl && stripos($caps, 'STARTTLS') !== false) { $r = $cmd('STARTTLS', '220'); if (!$r[0] || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return [false, 'Could not start a secure connection.', '']; $r = $cmd('EHLO ' . $dom, '250'); }
        if (stripos($caps, 'AUTH') !== false || $ssl) { $r = $cmd('AUTH LOGIN', '334'); if ($r[0]) { $r = $cmd(base64_encode($user), '334'); if ($r[0]) $r = $cmd(base64_encode((string)$sec['pass']), '235'); } if (!$r[0]) return [false, 'Mail login failed: ' . $r[1], '']; }
        $r = $cmd('MAIL FROM:<' . $user . '>', '250'); if (!$r[0]) return [false, 'Mail server: ' . $r[1], '']; $r = $cmd('RCPT TO:<' . $toEmail . '>', '250'); if (!$r[0]) return [false, 'Mail server: ' . $r[1], '']; $r = $cmd('DATA', '354'); if (!$r[0]) return [false, 'Mail server: ' . $r[1], ''];
        $mid = '<' . bin2hex(random_bytes(10)) . '@' . $dom . '>'; $enc = function ($t) { return '=?UTF-8?B?' . base64_encode($t) . '?='; };
        $h = ['From: ' . $enc((string)($sec['from_name'] ?? 'TM Perfume House')) . ' <' . $user . '>', 'To: ' . ($toName !== '' ? $enc($toName) . ' ' : '') . '<' . $toEmail . '>', 'Subject: ' . $enc($subject), 'Date: ' . date('r'), 'Message-ID: ' . $mid, 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
        if ($inReplyTo !== '') { $h[] = 'In-Reply-To: ' . $inReplyTo; $h[] = 'References: ' . trim(implode(' ', array_slice(array_unique(array_merge($refs, [$inReplyTo])), -8))); }
        fwrite($fp, implode("\r\n", $h) . "\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n.\r\n"); $r = $rd(); if (strpos($r, '250') !== 0) return [false, 'Mail server: ' . trim($r), ''];
        $cmd('QUIT', '221'); return [true, 'Email sent to ' . $toEmail . '.', $mid];
    } finally { @fclose($fp); }
}
function sx_test_email(PDO $pdo, array &$sec, array &$extra) {
    foreach (['user', 'pass', 'imap_host', 'smtp_host'] as $k) if (empty($sec[$k])) return [false, 'Fill in all the fields.'];
    if (!filter_var($sec['user'], FILTER_VALIDATE_EMAIL)) return [false, 'The email address is not valid.'];
    $fx = (string)tmph_cfg('SX_MAIL_FIXTURES', '');
    if ($fx === '' || !is_dir($fx)) {
        if (!function_exists('imap_open')) return [false, 'The PHP imap extension is off on this hosting. hPanel → Advanced → PHP Configuration → Extensions → tick “imap”, save, and try again.'];
        $port = (int)($sec['imap_port'] ?? 993); $im = @imap_open('{' . $sec['imap_host'] . ':' . $port . ($port === 143 ? '/imap/notls' : '/imap/ssl') . '}INBOX', $sec['user'], $sec['pass'], OP_READONLY, 1);
        if (!$im) return [false, 'Cannot read the mailbox: ' . imap_last_error()]; @imap_close($im);
    }
    $extra['info'] = ['name' => $sec['user']]; $extra['last_uid'] = 0;
    return [true, 'Mailbox ' . $sec['user'] . ' can be read.'];
}
function sx_pull_email(PDO $pdo, array $c, array &$stats, $quiet) {
    $sec = sx_dec($c['secret']); $x = bd_unjson($c['extra'], []); $last = (int)($x['last_uid'] ?? 0); $me = strtolower((string)$sec['user']);
    list($list, $max) = sx_imap_new($sec, $last, 40);
    foreach ($list as $pair) {
        $m = sx_mail_parse($pair[1]); if ($m['from'] === '' || $m['from'] === $me) continue;
        $mid = $m['mid'] !== '' ? $m['mid'] : '<h' . md5($m['from'] . $m['subject'] . $m['at']) . '>'; $ext = $mid;
        if ($m['refs']) { $in = implode(',', array_fill(0, count($m['refs']), '?')); $st = $pdo->prepare("SELECT i.ext_id FROM sx_msgs g JOIN sx_items i ON i.id = g.item_id WHERE i.channel = 'email' AND i.conn_id = ? AND g.ext_id IN ($in) LIMIT 1"); $st->execute(array_merge([(int)$c['id']], $m['refs'])); $f = $st->fetchColumn(); if ($f) $ext = (string)$f; }
        $body = $m['text'] !== '' ? $m['text'] : '(empty message)'; if ($m['att'] > 0) $body .= "\n[" . $m['att'] . ' attachment' . ($m['att'] > 1 ? 's' : '') . ' — open the mailbox to see ' . ($m['att'] > 1 ? 'them' : 'it') . ']';
        $res = sx_upsert($pdo, $c, 'email', $ext, ['thread_key' => $ext, 'who_name' => $m['name'], 'who_id' => $m['from'], 'who_email' => $m['reply_to'] ?: $m['from'], 'subject' => $m['subject'], 'raw' => ['last_mid' => $mid, 'refs' => $m['refs']]], [[$mid, 'in', $body, $m['at']]], $quiet);
        if ($m['auto'] && $res['new']) $pdo->prepare("UPDATE sx_items SET status = 'spam' WHERE id = ?")->execute([$res['id']]);
        $stats['items']++; if ($res['new'] && !$m['auto']) $stats['new']++;
    }
    $x['last_uid'] = $max; $pdo->prepare("UPDATE sx_conn SET extra = ? WHERE id = ?")->execute([bd_json($x), (int)$c['id']]);
}
function sx_send_email(PDO $pdo, array $c, array $r, $mode, $text) {
    $sec = sx_dec($c['secret']); $raw = bd_unjson($r['raw'], []); $to = $r['who_email']; $subj = trim((string)$r['subject']);
    if ($r['channel'] === 'review_product') $subj = 'Your review of ' . ($r['post_ref'] !== '' ? $r['post_ref'] : 'our product'); elseif ($subj === '') $subj = 'Your message';
    if (!preg_match('/^re:/i', $subj)) $subj = 'Re: ' . $subj;
    $res = sx_smtp_send($sec, $to, $r['who_name'], $subj, $text, (string)($raw['last_mid'] ?? ''), (array)($raw['refs'] ?? []));
    return $res[0] ? [true, $res[1], $res[2]] : [false, $res[1]];
}

/* ============================================================ JUDGE.ME (product reviews, read-only: Judge.me has no reply API) ============================================================ */
function sx_jm_get(array $sec, array $q) {
    $bases = array_unique([rtrim((string)tmph_cfg('SX_JUDGEME_BASE', 'https://judge.me/api/v1'), '/'), 'https://api.judge.me/api/v1']); $last = [false, [], 'No answer.'];
    foreach ($bases as $b) {
        list($code, $j, $raw, $err) = sx_http('GET', $b . '/reviews?' . http_build_query($q + ['api_token' => $sec['api_token'], 'shop_domain' => $sec['shop_domain']]));
        if ($err === '' && $code === 200 && is_array($j) && isset($j['reviews'])) return [true, $j, ''];
        $last = [false, [], $code === 401 || $code === 403 || $code === 422 ? 'Judge.me refused the token or the shop domain (' . $code . ').' : sx_err_text($code, $j, $raw, $err)];
        if ($code === 401 || $code === 403) break;
    }
    return $last;
}
function sx_test_judgeme(PDO $pdo, array &$sec, array &$extra) {
    $sec['shop_domain'] = strtolower(trim((string)($sec['shop_domain'] ?? ''))); $sec['shop_domain'] = preg_replace('~^https?://|/.*$~i', '', $sec['shop_domain']);
    if ($sec['shop_domain'] === '' || empty($sec['api_token'])) return [false, 'Shop domain and Private API token are needed.'];
    $r = sx_jm_get($sec, ['per_page' => 1, 'page' => 1]); if (!$r[0]) return [false, $r[2]];
    $extra['info'] = ['name' => 'Judge.me · ' . $sec['shop_domain']]; return [true, 'Judge.me answered for ' . $sec['shop_domain'] . '.'];
}
function sx_pull_judgeme(PDO $pdo, array $c, array &$stats, $quiet) {
    $sec = sx_dec($c['secret']); $first = empty($c['last_sync']); $pages = $first ? 3 : 1;
    for ($p = 1; $p <= $pages; $p++) {
        $r = sx_jm_get($sec, ['per_page' => 50, 'page' => $p]); if (!$r[0]) { if ($p === 1) throw new Exception($r[2]); break; }
        $rows = (array)$r[1]['reviews']; if (!$rows) break;
        foreach ($rows as $rv) {
            if (empty($rv['id']) || !empty($rv['hidden']) || (isset($rv['published']) && $rv['published'] === false)) continue;
            $rev = (array)($rv['reviewer'] ?? []); $title = trim((string)($rv['title'] ?? '')); $body = trim((string)($rv['body'] ?? '')); $txt = trim($title . ($title !== '' && $body !== '' ? '. ' : '') . $body); if ($txt === '') $txt = '(rating only)';
            $handle = (string)($rv['product_handle'] ?? ''); $res = sx_upsert($pdo, $c, 'review_product', (string)$rv['id'], ['thread_key' => (string)$rv['id'], 'who_name' => (string)($rev['name'] ?? $rv['reviewer_name'] ?? 'Customer'), 'who_id' => (string)($rev['email'] ?? $rv['reviewer_email'] ?? ''), 'who_email' => (string)($rev['email'] ?? $rv['reviewer_email'] ?? ''),
                'subject' => $title, 'post_ref' => (string)($rv['product_title'] ?? $handle), 'rating' => (int)($rv['rating'] ?? 0) ?: null, 'url' => $handle !== '' ? 'https://' . $sec['shop_domain'] . '/products/' . $handle : '', 'raw' => ['jm_id' => (string)$rv['id']]],
                [['r', 'in', $txt, sx_dt($rv['created_at'] ?? '')]], $quiet);
            $stats['items']++; if ($res['new']) $stats['new']++;
        }
        if (count($rows) < 50) break;
    }
}

/* ============================================================ GOOGLE (Business Profile reviews) ============================================================ */
function sx_gcfg($k, $d) { return rtrim((string)tmph_cfg($k, $d), '/'); }
function sx_google_token(PDO $pdo, array $sec) {
    $ck = 'gtok_' . md5((string)$sec['refresh_token']); $cached = bd_unjson(sx_state_get($pdo, $ck, ''), []); if (!empty($cached['t']) && $cached['e'] > time() + 60) return $cached['t'];
    list($code, $j, $raw, $err) = sx_http('POST', (string)tmph_cfg('SX_G_OAUTH', 'https://oauth2.googleapis.com/token'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['client_id' => $sec['client_id'], 'client_secret' => $sec['client_secret'], 'refresh_token' => $sec['refresh_token'], 'grant_type' => 'refresh_token']));
    if (empty($j['access_token'])) throw new Exception('Google sign-in failed: ' . sx_err_text($code, $j, $raw, $err));
    sx_state_put($pdo, $ck, bd_json(['t' => $j['access_token'], 'e' => time() + (int)($j['expires_in'] ?? 3000)])); return $j['access_token'];
}
function sx_gcall(PDO $pdo, $tok, $method, $url, $payload = null) {
    list($code, $j, $raw, $err) = $payload === null ? sx_http($method, $url, ['Authorization: Bearer ' . $tok]) : sx_http_json($method, $url, ['Authorization: Bearer ' . $tok], $payload);
    return ($err === '' && $code >= 200 && $code < 300) ? [true, is_array($j) ? $j : [], ''] : [false, [], sx_err_text($code, $j, $raw, $err), $code];
}
function sx_test_google(PDO $pdo, array &$sec, array &$extra) {
    foreach (['client_id', 'client_secret', 'refresh_token'] as $k) if (empty($sec[$k])) return [false, 'Fill in all three values.'];
    try { $tok = sx_google_token($pdo, $sec); } catch (Throwable $e) { return [false, $e->getMessage()]; }
    $a = sx_gcall($pdo, $tok, 'GET', sx_gcfg('SX_G_ACCT', 'https://mybusinessaccountmanagement.googleapis.com') . '/v1/accounts'); if (!$a[0]) return [false, 'Google Business accounts: ' . $a[2] . ($a[3] === 403 || $a[3] === 429 ? ' — Google must approve the Business Profile API for your project first.' : '')];
    $locs = [];
    foreach ((array)($a[1]['accounts'] ?? []) as $ac) {
        $l = sx_gcall($pdo, $tok, 'GET', sx_gcfg('SX_G_INFO', 'https://mybusinessbusinessinformation.googleapis.com') . '/v1/' . $ac['name'] . '/locations?readMask=name,title&pageSize=100');
        if ($l[0]) foreach ((array)($l[1]['locations'] ?? []) as $lc) $locs[] = ['name' => $ac['name'] . '/' . $lc['name'], 'title' => (string)($lc['title'] ?? $lc['name'])];
    }
    if (!$locs) return [false, 'Signed in, but no business locations were found for this Google account.'];
    $extra['locations'] = $locs; $extra['info'] = ['name' => count($locs) . ' location' . (count($locs) > 1 ? 's' : '') . ': ' . implode(', ', array_slice(array_column($locs, 'title'), 0, 4))];
    return [true, 'Found ' . count($locs) . ' location(s).'];
}
function sx_pull_google(PDO $pdo, array $c, array &$stats, $quiet) {
    $sec = sx_dec($c['secret']); $x = bd_unjson($c['extra'], []); $tok = sx_google_token($pdo, $sec); $map = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];
    foreach ((array)($x['locations'] ?? []) as $loc) {
        $r = sx_gcall($pdo, $tok, 'GET', sx_gcfg('SX_G_V4', 'https://mybusiness.googleapis.com/v4') . '/' . $loc['name'] . '/reviews?pageSize=50&orderBy=updateTime%20desc');
        if (!$r[0]) { $stats['notes'][] = $loc['title'] . ': ' . sx_short($r[2], 120); continue; }
        foreach ((array)($r[1]['reviews'] ?? []) as $rv) {
            $id = (string)($rv['reviewId'] ?? ''); if ($id === '') continue; $txt = trim((string)($rv['comment'] ?? '')); if ($txt === '') $txt = '(rating only)'; $msgs = [['r', 'in', $txt, sx_dt($rv['createTime'] ?? '')]];
            if (!empty($rv['reviewReply']['comment'])) $msgs[] = ['reply', 'out', (string)$rv['reviewReply']['comment'], sx_dt($rv['reviewReply']['updateTime'] ?? '')];
            $res = sx_upsert($pdo, $c, 'review_google', $id, ['thread_key' => $id, 'who_name' => (string)($rv['reviewer']['displayName'] ?? 'Google user'), 'who_id' => $id, 'post_ref' => (string)$loc['title'], 'rating' => $map[$rv['starRating'] ?? ''] ?? null, 'url' => '', 'raw' => ['review_name' => (string)($rv['name'] ?? ($loc['name'] . '/reviews/' . $id))]], $msgs, $quiet);
            $stats['items']++; if ($res['new']) $stats['new']++;
        }
    }
}
function sx_send_google(PDO $pdo, array $c, array $r, $mode, $text) {
    $sec = sx_dec($c['secret']); $raw = bd_unjson($r['raw'], []); if (empty($raw['review_name'])) return [false, 'The review id is missing — sync again.'];
    $tok = sx_google_token($pdo, $sec); $res = sx_gcall($pdo, $tok, 'PUT', sx_gcfg('SX_G_V4', 'https://mybusiness.googleapis.com/v4') . '/' . $raw['review_name'] . '/reply', ['comment' => $text]);
    return $res[0] ? [true, 'Reply is live under the review.', 'reply'] : [false, $res[2]];
}

/* ============================================================ GOOGLE PLAY (Android Publisher reviews) ============================================================ */
function sx_play_token(PDO $pdo, array $sec) {
    $sa = json_decode((string)$sec['sa_json'], true); if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) throw new Exception('The key file is not a valid service-account JSON.');
    $ck = 'ptok_' . md5($sa['client_email']); $cached = bd_unjson(sx_state_get($pdo, $ck, ''), []); if (!empty($cached['t']) && $cached['e'] > time() + 60) return $cached['t'];
    $aud = (string)($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token'); $now = time();
    $in = sx_b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . sx_b64u(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/androidpublisher', 'aud' => $aud, 'iat' => $now, 'exp' => $now + 3300]));
    if (!openssl_sign($in, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) throw new Exception('The private key in the file could not be read.');
    list($code, $j, $raw, $err) = sx_http('POST', (string)tmph_cfg('SX_G_OAUTH', $aud), ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $in . '.' . sx_b64u($sig)]));
    if (empty($j['access_token'])) throw new Exception('Google sign-in failed: ' . sx_err_text($code, $j, $raw, $err));
    sx_state_put($pdo, $ck, bd_json(['t' => $j['access_token'], 'e' => $now + (int)($j['expires_in'] ?? 3000)])); return $j['access_token'];
}
function sx_play_url($pkg) { return sx_gcfg('SX_G_PLAY', 'https://androidpublisher.googleapis.com') . '/androidpublisher/v3/applications/' . rawurlencode($pkg) . '/reviews'; }
function sx_test_play(PDO $pdo, array &$sec, array &$extra) {
    $sec['package'] = trim((string)($sec['package'] ?? '')); if ($sec['package'] === '' || empty($sec['sa_json'])) return [false, 'Package name and the key file are needed.'];
    try { $tok = sx_play_token($pdo, $sec); } catch (Throwable $e) { return [false, $e->getMessage()]; }
    $r = sx_gcall($pdo, $tok, 'GET', sx_play_url($sec['package']) . '?maxResults=1'); if (!$r[0]) return [false, 'Google Play: ' . $r[2] . ($r[3] === 403 ? ' — give the service account the permission “Reply to reviews” in Play Console → Users and permissions.' : '')];
    $extra['info'] = ['name' => 'Play · ' . $sec['package']]; return [true, 'Google Play answered for ' . $sec['package'] . '.'];
}
function sx_pull_play(PDO $pdo, array $c, array &$stats, $quiet) {
    $sec = sx_dec($c['secret']); $tok = sx_play_token($pdo, $sec); $tokenPg = '';
    for ($p = 0; $p < 2; $p++) {
        $r = sx_gcall($pdo, $tok, 'GET', sx_play_url($sec['package']) . '?maxResults=100' . ($tokenPg !== '' ? '&token=' . rawurlencode($tokenPg) : '')); if (!$r[0]) throw new Exception($r[2]);
        foreach ((array)($r[1]['reviews'] ?? []) as $rv) {
            $id = (string)($rv['reviewId'] ?? ''); $uc = $rv['comments'][0]['userComment'] ?? null; if ($id === '' || !$uc) continue;
            $txt = trim(str_replace("\t", ' — ', (string)($uc['text'] ?? ''))); if ($txt === '') $txt = '(rating only)'; $msgs = [['r', 'in', $txt, sx_dt($uc['lastModified']['seconds'] ?? time())]];
            foreach ((array)$rv['comments'] as $cm) if (!empty($cm['developerComment']['text'])) $msgs[] = ['reply', 'out', (string)$cm['developerComment']['text'], sx_dt($cm['developerComment']['lastModified']['seconds'] ?? time())];
            $res = sx_upsert($pdo, $c, 'review_play', $id, ['thread_key' => $id, 'who_name' => (string)($rv['authorName'] ?? 'Play user'), 'who_id' => $id, 'subject' => !empty($uc['appVersionName']) ? 'App v' . $uc['appVersionName'] : '', 'post_ref' => $sec['package'], 'rating' => isset($uc['starRating']) ? (int)$uc['starRating'] : null, 'raw' => ['review_id' => $id]], $msgs, $quiet);
            $stats['items']++; if ($res['new']) $stats['new']++;
        }
        $tokenPg = (string)($r[1]['tokenPagination']['nextPageToken'] ?? ''); if ($tokenPg === '') break;
    }
}
function sx_send_play(PDO $pdo, array $c, array $r, $mode, $text) {
    $sec = sx_dec($c['secret']); $raw = bd_unjson($r['raw'], []); if (empty($raw['review_id'])) return [false, 'The review id is missing — sync again.']; if (mb_strlen($text) > 350) return [false, 'Google Play allows at most 350 characters (yours: ' . mb_strlen($text) . ').'];
    $tok = sx_play_token($pdo, $sec); $res = sx_gcall($pdo, $tok, 'POST', sx_play_url($sec['package']) . '/' . rawurlencode($raw['review_id']) . ':reply', ['replyText' => $text]);
    return $res[0] ? [true, 'Reply is live on Google Play.', 'reply'] : [false, $res[2]];
}