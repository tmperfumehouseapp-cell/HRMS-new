<?php
/** wa_offer.php — the PRICE OFFER to the customers who answered a 30-day Lineup message (owner 22 Nov).
 *  Who: a customer who wrote after a Lineup message, whose reply is a real one (not another business's auto-reply, not stop / no / thanks), who has not ordered since, whom a person is not talking to right now.
 *  What: ONE free-text message (the 24-hour window is open, so it costs nothing) with a price taken ONLY from our price chart (ml_slab_chart):
 *    - he was already quoted a price in the chat  -> the NEXT LOWER chart column than the deepest one he has seen (never up, never below the last column of the chart);
 *    - he was not quoted / did not ask            -> the 6% column, for what the chat shows he wants (else a combo that reaches Rs 2,700+ at that price).
 *  Mode (setting wo_mode): 'dry' = work out and record, send nothing (the default until the owner's samples were checked) · 'live' = send · 'off'. Never between 21:30 and 09:00 (quiet hours 03-09 are universal).
 *  Functions only (PHP 7.4 safe); called once a minute from the Lineup round (wl_round: the bookkeeping caller) and by diag_ask.php (read-only dry check). */
if (!function_exists('wof_run')) {
    function wof_tables(PDO $pdo): void
    {
        static $done = false; if ($done) return; $done = true;
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_offer (id BIGINT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(20) NOT NULL, reply_at DATETIME NULL, kind VARCHAR(8) NOT NULL DEFAULT '', pct DECIMAL(5,2) NOT NULL DEFAULT 0, qty INT NOT NULL DEFAULT 0, ml INT NOT NULL DEFAULT 0, price INT NOT NULL DEFAULT 0, asked TINYINT NOT NULL DEFAULT 0, mode VARCHAR(6) NOT NULL DEFAULT '', ok TINYINT NOT NULL DEFAULT 0, why VARCHAR(160) NOT NULL DEFAULT '', body TEXT NULL, at DATETIME NOT NULL, KEY k_phone (phone, at), KEY k_at (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) { }
    }
    /** The chart: ['cols' => [floats], 'rows' => [ml => [prices by column]]] or null. */
    function wof_chart(PDO $pdo): ?array
    {
        $j = json_decode((string)setting_get($pdo, 'ml_slab_chart', ''), true); if (!is_array($j) || empty($j['cols']) || empty($j['rows'])) return null;
        $cols = array_values(array_map('floatval', (array)$j['cols'])); $rows = []; foreach ((array)$j['rows'] as $r) { $ml = (int)($r['ml'] ?? 0); $p = array_map('intval', array_values((array)($r['p'] ?? []))); if ($ml > 0 && $p && $p[0] > 0) $rows[$ml] = $p; }
        return $rows ? ['cols' => $cols, 'rows' => $rows] : null;
    }
    /** The deepest chart column (percent) whose price OUR side already wrote in this chat (30 days); null = nothing quoted. */
    function wof_level(PDO $pdo, string $phone, array $ch): ?float
    {
        try { $q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'out' AND mtype = 'text' AND ts >= ? ORDER BY id DESC LIMIT 80"); $q->execute([$phone, date('Y-m-d H:i:s', time() - 30 * 86400)]); $msgs = $q->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { return null; }
        $am = []; foreach ($msgs as $m) if (preg_match_all('/\d{1,3}(?:,\d{2,3})+|\d{3,6}/', (string)$m, $mm)) foreach ($mm[0] as $n) $am[(int)str_replace(',', '', $n)] = 1;
        if (!$am) return null; $deep = null;
        foreach ($ch['rows'] as $ml => $p) foreach ($p as $i => $v) if ($v > 0 && isset($am[$v]) && isset($ch['cols'][$i])) { $c = $ch['cols'][$i]; if ($deep === null || $c < $deep) $deep = $c; }
        return $deep;
    }
    /** What he wants: [qty, size] from the chat (his own "N x S ml", else our last quote, else his size word), [0, 0] when unknown. */
    function wof_want(array $in, array $out): array
    {
        $cust = mb_strtolower(implode(' ', $in)); $our = mb_strtolower(implode(' ', $out));
        if (preg_match_all('/(\d{1,2})\s*[x×*]\s*(20|30|50|100)\s*ml/u', $cust, $m, PREG_SET_ORDER)) { $l = end($m); return [(int)$l[1], (int)$l[2]]; }
        if (preg_match_all('/(?<![\d.])(\d{1,2})\s*(?:pice|piece|pieces|pcs|pc|bottles?|nang|nag)\b/u', $cust, $a) && preg_match_all('/\b(20|30|50|100)\s*ml\b/u', $cust, $b)) return [(int)end($a[1]), (int)end($b[1])];
        if (preg_match_all('/(\d{1,2})\s*[x×*]\s*(20|30|50|100)\s*ml/u', $our, $m, PREG_SET_ORDER)) { $l = end($m); return [(int)$l[1], (int)$l[2]]; }
        if (preg_match_all('/\b(20|30|50|100)\s*ml\b/u', $cust, $b)) return [0, (int)end($b[1])];
        return [0, 0];
    }
    /** Work out the offer of one customer; [] = no offer (with why in $why). $c = wa_contact row. */
    function wof_make(PDO $pdo, array $c, array $ch, array $in, array $out, bool $asked, ?string &$why = null): array
    {
        $why = ''; $cols = $ch['cols']; $lvl = wof_level($pdo, (string)$c['phone'], $ch);
        [$qty, $size] = wof_want($in, $out);
        if ($lvl !== null && $lvl <= 6.0 + 0.001) {   // he has seen 6% or lower: the NEXT LOWER column (only for the one who asked; an offer that goes lower for nobody who asked is not made)
            /* owner 27 Nov 2026: EVERY customer who answers gets an offer. The one who did not ask for less, and the one who is at the last column already, get the price they saw ONCE MORE (kind 'again'); a lower price is still only for the one who asked */
            $same = -1; $sd0 = 1e9; foreach ($cols as $i => $cv) { $d = abs($cv - $lvl); if ($d < $sd0) { $sd0 = $d; $same = $i; } }
            $tc = -1; $tp = -1.0; if ($asked) foreach ($cols as $i => $cv) if ($cv < $lvl - 0.001 && $cv > $tp) { $tp = $cv; $tc = $i; }
            if ($tc >= 0) $kind = 'lower'; else { if ($same < 0) { $why = $asked ? 'already at the last column' : 'seen 6% already, did not ask'; return []; } $tc = $same; $kind = 'again'; }
        } elseif ($lvl !== null && $asked) { $tc = -1; $tp = -1.0; foreach ($cols as $i => $cv) if ($cv < $lvl - 0.001 && $cv > $tp) { $tp = $cv; $tc = $i; } if ($tc < 0) { $why = 'no lower column'; return []; } $kind = 'lower'; }
        else { $tc = -1; $bd = 1e9; foreach ($cols as $i => $cv) { $d = abs($cv - 6.0); if ($d < $bd) { $bd = $d; $tc = $i; } } if ($tc < 0) { $why = 'no 6% column'; return []; } $kind = 'six'; }
        $pct = (float)$cols[$tc];
        if ($qty < 1 || $size < 20) {   // nothing named: a combo of his size (else 50 ml) that reaches Rs 2,700+ at this price
            $sz = $size >= 20 ? $size : 50; $qty = 0;
            for ($q = 3; $q <= 12; $q++) { $t = $q * $sz; if (isset($ch['rows'][$t]) && (int)($ch['rows'][$t][$tc] ?? 0) >= 2700) { $qty = $q; break; } }
            /* owner 27 Nov 2026: the 20 ml customer (25-35 a day tap "20ML") got nothing because no 20 ml combo reaches Rs 2,700: he gets the 160 ml combo (8 x 20 ml, the campaign that sells) or the biggest combo of his size the chart has */
            if ($qty < 1) { foreach ([8, 12, 10, 6, 5] as $q) { $t = $q * $sz; if (isset($ch['rows'][$t]) && (int)($ch['rows'][$t][$tc] ?? 0) > 0) { $qty = $q; break; } } }
            if ($qty < 1) { $why = 'no combo reaches 2700'; return []; } $size = $sz;
        }
        if ($qty * $size < 100) $qty = (int)ceil(100 / $size);   // our combo minimum (5 x 20 ml = 100 ml): never an offer below 100 ml
        $total = $qty * $size; if (!isset($ch['rows'][$total]) || (int)($ch['rows'][$total][$tc] ?? 0) <= 0) { $why = 'no chart row for ' . $total . ' ml'; return []; }
        $price = (int)$ch['rows'][$total][$tc]; $online = [20 => 499, 50 => 1199, 100 => 1810][$size] ?? 0; $onl = $online > 0 ? $online * $qty : 0;
        return ['kind' => $kind, 'pct' => $pct, 'qty' => $qty, 'ml' => $size, 'price' => $price, 'online' => $onl];
    }
    function wof_text(array $o): string
    {
        $n = function (int $v) { return '₹' . number_format($v); }; $total = $o['qty'] * $o['ml']; $pb = (int)round($o['price'] / max(1, $o['qty']));
        $dt = (int)date('md'); $season = ($dt >= 1115 || $dt <= 228) ? 'Shaadi season special 💍' : 'Special offer 🎁'; if ($dt >= 1205 || $dt <= 115) $season = 'New Year special 🎊';
        /* owner 25 Nov 2026: a proper "crazy pricing" message, shaped by his size (20 / 50 / 100 ml): spacing, emojis, the price per bottle, what he saves against the online price, and the lock-in; prices come from the chart only */
        $ml = (int)$o['ml']; $hook = $ml >= 100 ? '100 ml ki shaan 👑' : ($ml >= 50 ? '50 ml ki asli value 🔥' : '20 ml ki pocket-size khushboo 🌸');
        $save = $o['online'] > $o['price'] ? "\n💰 Online price " . $n($o['online']) . " — aapko seedha " . $n($o['online'] - $o['price']) . " ki bachat!" : '';
        $line = "🎁 " . $o['qty'] . ' x ' . $ml . ' ml (' . $total . " ml)\n💥 Sirf " . $n($o['price']) . ' (ek bottle ' . $n($pb) . ')' . $save;
        /* owner 26 Nov: the first line answers what HE wrote (men / women / unisex, a gift, a size, a price question) */
        $sd = (string)($o['said'] ?? ''); $op = '';
        if (preg_match('/(gift|shaadi|shadi|wedding|birthday|anniversary|गिफ्ट|ગિફ્ટ)/iu', $sd)) $op = 'Ji bilkul, gift ke liye ye perfect rahega 🎁';
        elseif (preg_match('/(women|ladies|lady|female|wife|girl|महिला|લેડીઝ)/iu', $sd)) $op = 'Ji, ladies ke liye hamare best perfumes isi offer mein hain 🌸';
        elseif (preg_match('/(unisex)/iu', $sd)) $op = 'Ji, unisex perfumes isi offer mein mil jayenge ✨';
        elseif (preg_match('/(\bmen\b|\bmens\b|gents|male|husband|\bboy|पुरुष|જેન્ટ્સ)/iu', $sd)) $op = 'Ji, gents ke liye hamare best perfumes isi offer mein hain 👔';
        elseif (preg_match('/\b(20|30|50|100)\s*ml\b/iu', $sd)) $op = 'Ji, aapne jo size bataya usi ka best price ye raha 👇';
        elseif (preg_match('/(order|book|kharid|lena h|leni h|\bbuy\b|mangwa|mangana|chahiye|ऑर्डर|ઓર્ડર)/iu', $sd)) $op = 'Ji bilkul, aapka order abhi bana dete hain ✅';
        elseif (!empty($o['asked'])) $op = 'Ji, aapne price pucha tha, ye raha aaj ka best price 👇';
        elseif ($sd !== '') $op = 'Ji, aapke reply ke liye shukriya 🙏 Ye raha aapke liye 👇';
        $op = $op !== '' ? $op . "\n\n" : '';
        if ($o['kind'] === 'again') return $op . "Sir, aapka khaas price abhi bhi aapke liye ready hai 🎉\n\n" . $hook . "\n\n" . $line . "\n\n⏰ Aaj order karoge to yahi price pakka.\n\nKaunse perfumes chahiye? Bata dijiye, order abhi bana deta hoon 😊✅";
        if ($o['kind'] === 'lower') return $op . "Sir, aapke liye aur bhi khaas price 🎉\n\n" . $hook . "\n\n" . $line . "\n\n⏰ Ye price abhi aapke liye lock hai — aaj hi order karoge to hi.\n\nOrder bana dun? ✅";
        return $op . $season . "\n\n" . $hook . "\n\n" . $line . "\n\n⏰ Ye price aaj ke liye hi — abhi order karoge to hi milega.\n\nKaunse perfumes chahiye? Bata dijiye, order abhi bana deta hoon 😊✅";
    }
    /** owner 26 Nov 2026: he TAPS A BUTTON of one of our automatic messages ("Share feedback", "Haan batao" …): the price offer is what must answer him, not the chat bot's thank-you. The tap is noted here (called when his message comes in); the chat bot stays silent for 4 minutes (wag_bot_off reads 'offerhold:<phone>') and the offer run sends the offer 1-3 minutes after the tap. When no offer can be made, the bot answers after the 4 minutes as before. */
    function wof_tap(PDO $pdo, string $key, string $text): void
    {
        try {
            if ((string)setting_get($pdo, 'wo_tap', '1') === '0' || (string)setting_get($pdo, 'wo_mode', 'live') !== 'live') return;
            $t = trim(preg_replace('/\s+/u', ' ', $text)); if ($t === '') return;
            if (preg_match('/(not now|\bno\b|nope|stop|unsubscribe|don.?t|nahi|nahin|abhi nahi|baad me|नहीं|ના)/iu', $t)) return;
            if (preg_match('/(parcel|courier|deliver|tracking|\btrack|return|refund|replace|damage|leak|complain|cancel|order status|पार्सल|પાર્સલ|રિફંડ)/iu', $t) || !preg_match('/[\p{L}\p{N}]/u', $t)) return;   // a parcel / return question or only an emoji: the bot (or a person) answers at once, no offer
            $h = (int)(new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('G'); if (($h < 9 || $h >= 22) && (string)setting_get($pdo, 'wo_night', '1') === '0') return;   /* owner 27 Nov 2026 (night): "at night too, send the offer on the spot" - the offer answers a customer who has just written, at any hour; wo_night = 0 brings back 09:00-22:00 */
            $q = $pdo->prepare("SELECT who, mtype, ts FROM wa_messages WHERE phone = ? AND dir = 'out' ORDER BY id DESC LIMIT 1"); $q->execute([$key]); $m = $q->fetch(PDO::FETCH_ASSOC);
            if (!$m || (string)$m['mtype'] !== 'template' || !in_array((string)$m['who'], ['followup', 'campaign', 'recover'], true) || (string)$m['ts'] < date('Y-m-d H:i:s', time() - 72 * 3600)) return;
            $k = substr(preg_replace('/\D+/', '', $key), -10); if (strlen($k) < 10) return;
            /* owner 26 Nov 2026 (13:00): "when a reply to a utility message comes, the offer must go AT ONCE". One customer, one try in 2 minutes (two taps in a row do not make two offers); the offer is worked out and sent right here. When it went, the chat bot waits 4 minutes; when no offer could be made, the bot answers at once as before, and the minute run tries again later. */
            $lk = $pdo->prepare("INSERT INTO wq_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = IF(CAST(v AS UNSIGNED) < ?, VALUES(v), v)"); $lk->execute(['offernow:' . $k, (string)time(), time() - 120]); if ($lk->rowCount() === 0) return;
            $sent = 0; if ((string)setting_get($pdo, 'wo_now', '1') !== '0') { $GLOBALS['wo_only'] = $key; $GLOBALS['wo_only_text'] = $t; try { $r = wof_run($pdo, 1); $sent = (int)($r[2] ?? 0); } finally { unset($GLOBALS['wo_only'], $GLOBALS['wo_only_text']); } }
            if ($sent > 0 || (string)setting_get($pdo, 'wo_now', '1') === '0') $pdo->prepare("INSERT INTO wq_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute(['offerhold:' . $k, (string)(time() + 240)]);
        } catch (Throwable $e) { try { setting_set($pdo, 'wo_tap_err', date('H:i:s') . ' ' . mb_substr($e->getMessage(), 0, 120)); } catch (Throwable $e2) { } }
    }
    /** owner 27 Nov 2026: "after the offer ONE follow-up, very light, when no answer comes". 2 hours after an offer that he did not answer (no message of his, no person of ours wrote, no order), one short free text goes inside his 24-hour window: the price is still ready, a 'haan' is enough. One per offer, 09:00-21:30, at most 40 a minute. A night offer is followed up in the morning. Off: wo_follow = 0; the wait in minutes: wo_follow_min (120). Kept in wa_offer as kind 'follow', ok = 2 (so the offer counts stay what they were). Returns the number sent. */
    function wof_follow(PDO $pdo, int $now): int
    {
        if ((string)setting_get($pdo, 'wo_follow', '1') === '0') return 0; $hm = (int)date('G', $now) * 60 + (int)date('i', $now); if ($hm < 9 * 60 || $hm >= 21 * 60 + 30) return 0;
        $wait = max(30, (int)setting_get($pdo, 'wo_follow_min', '120')) * 60; $n = 0; $t0 = microtime(true);
        $q = $pdo->prepare("SELECT o.id, o.phone, o.at FROM wa_offer o WHERE o.ok = 1 AND o.mode = 'live' AND o.kind <> 'follow' AND o.at >= ? AND o.at <= ? AND NOT EXISTS (SELECT 1 FROM wa_offer f WHERE f.phone = o.phone AND f.kind = 'follow' AND f.at >= o.at) ORDER BY o.at DESC LIMIT 200");
        $q->execute([date('Y-m-d H:i:s', $now - 13 * 3600), date('Y-m-d H:i:s', $now - $wait)]); $rows = $q->fetchAll(PDO::FETCH_ASSOC); if (!$rows) return 0;   // an offer older than 13 hours is not followed up (13 h covers a late-evening offer followed up at 09:00)
        $emp = $pdo->prepare("SELECT 1 FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'emp' AND ts > ? LIMIT 1"); $ord = $pdo->prepare("SELECT 1 FROM wa_order_attr WHERE phone IN (?, ?) AND order_at >= ? LIMIT 1");
        $ins = $pdo->prepare("INSERT INTO wa_offer (phone, reply_at, kind, pct, qty, ml, price, asked, mode, ok, why, body, at) VALUES (?, ?, 'follow', 0, 0, 0, 0, 0, 'live', ?, ?, ?, ?)"); $seen = [];
        $T = ["Sir 😊 upar jo price bheja he wo aapke liye abhi bhi ready he.\n\nKoi sawal ho to bataiye, ya bas *haan* likh dijiye — order hum bana denge ✅", "Ji 🙏 bas yaad dila rahe hain: upar wala price aaj aapke liye ready he.\n\nPerfume chunne me madad chahiye to bataiye, ya *haan* likh dijiye ✅"];
        foreach ($rows as $r) { if ($n >= 40 || microtime(true) - $t0 > 12) break; $ph = (string)$r['phone']; if (isset($seen[$ph])) continue; $seen[$ph] = 1; $at = (string)$r['at'];
            $c = function_exists('wa_contact') ? wa_contact($pdo, $ph) : null; if (!$c || !empty($c['optout']) || !empty($c['camp_optout'])) { $ins->execute([$ph, $at, 0, 'no contact / opted out', '', date('Y-m-d H:i:s')]); continue; }
            $li = (string)($c['last_in_at'] ?? ''); if ($li === '' || (int)strtotime($li) < $now - 23 * 3600) { $ins->execute([$ph, $at, 0, 'his 24-hour window is over', '', date('Y-m-d H:i:s')]); continue; }
            if ((int)strtotime($li) > (int)strtotime($at) + 60) { $ins->execute([$ph, $at, 0, 'he wrote after the offer', '', date('Y-m-d H:i:s')]); continue; }
            $emp->execute([$ph, $at]); if ($emp->fetchColumn()) { $ins->execute([$ph, $at, 0, 'a person of ours wrote after the offer', '', date('Y-m-d H:i:s')]); continue; }
            $k10 = substr(preg_replace('/\D+/', '', $ph), -10); $ord->execute([$k10, '91' . $k10, $at]); if ($ord->fetchColumn()) { $ins->execute([$ph, $at, 0, 'ordered', '', date('Y-m-d H:i:s')]); continue; }
            if (function_exists('wa_auto_muted') && wa_auto_muted($pdo, $ph)) { $ins->execute([$ph, $at, 0, 'muted (order / ticket)', '', date('Y-m-d H:i:s')]); continue; }
            $body = $T[abs(crc32($ph)) % count($T)]; $s = wa_send_text($pdo, $c, $body, 'offer', 0);
            if (!empty($s[0])) { $n++; $ins->execute([$ph, $at, 2, 'follow-up sent', $body, date('Y-m-d H:i:s')]); } else $ins->execute([$ph, $at, 0, 'not sent: ' . mb_substr((string)($s[1] ?? ''), 0, 100), '', date('Y-m-d H:i:s')]); }
        return $n;
    }
    /** One run (about a minute): up to $cap customers. Returns [candidates looked at, offers made, sent, notes]. */
    function wof_run(PDO $pdo, int $cap = 30, ?array &$samples = null, bool $simulate = false): array
    {
        $tz0 = date_default_timezone_get(); date_default_timezone_set('Asia/Kolkata');   // the shop's clock (the command-line default is Europe/Berlin)
        try { return wof_run0($pdo, $cap, $samples, $simulate); } finally { date_default_timezone_set($tz0); }
    }
    function wof_run0(PDO $pdo, int $cap, ?array &$samples, bool $simulate): array
    {
        $mode = (string)setting_get($pdo, 'wo_mode', 'live'); /* owner 22 Nov, 19:45: switched on (the samples and the 188 were shown to him) */ if ($mode === 'off') return [0, 0, 0, 'off']; if (!$simulate && $mode !== 'live') return [0, 0, 0, 'not live (' . $mode . ')']; if (!$simulate) { wof_tables($pdo); try { setting_set($pdo, 'wo_last', json_encode(['at' => date('Y-m-d H:i:s'), 'mode' => $mode, 'cap' => $cap])); } catch (Throwable $e) { } } $now = time(); $t0 = microtime(true);
        $only = $simulate ? '' : (string)($GLOBALS['wo_only'] ?? '');   /* owner 26 Nov: the offer goes AT ONCE when his reply comes in (wof_tap): this run then looks at that one customer only, no waiting time */
        $res = [0, 0, 0, '']; $h = (int)date('G', $now); if (!$simulate && ($h < 9 || $h >= 22) && (string)setting_get($pdo, 'wo_night', '1') === '0') { $res[3] = 'outside 09-22'; return $res; }   // owner 27 Nov: the offer goes at night too (it answers a customer who just wrote); the light follow-up keeps its 09:00-21:30
        if (!$simulate && $only === '' && function_exists('wa_lock') && !wa_lock($pdo, 'wooffer', 55)) { $res[3] = 'locked'; return $res; }
        $ch = wof_chart($pdo); if (!$ch) { $res[3] = 'no chart'; return $res; }
        $maxAge = 23 * 3600; $minAge = ((string)setting_get($pdo, 'wo_fast', '1') !== '0') ? 30 : 20 * 60;   /* owner 27 Nov 2026 (evening): "make the offer speed 5 minutes" - an offer within 5 minutes brings 34% of the customers back, one after an hour 10%; the minute run now waits 30-90 seconds (was 1-3 minutes) */   /* owner 26 Nov 2026: the offer goes 1-3 minutes after his reply (was 20-60): a button tap or a reply is answered while he is still on his phone; wo_fast = 0 brings back the 20-60 minutes */ $fastMax = max(30, (int)setting_get($pdo, 'wo_max_min2', '60')) * 60;   /* owner 25 Nov: the offer goes 20-60 minutes (setting wo_max_min2); before it was 20-120 after his reply (was 20-90); a reply that came while nothing is sent (before 09:00 or after 21:40) is answered when the morning starts, inside the 24-hour window */ if ($simulate && !empty($GLOBALS['wo_win'])) { $maxAge = (int)$GLOBALS['wo_win'][0]; $minAge = (int)$GLOBALS['wo_win'][1]; }   // (the dry run of the diagnostic may look at another window, e.g. yesterday's customers)   // the first day: everyone who answered in the last 23 h; afterwards the first hour (and a half)
        /* owner 25 Nov 2026: the list was the OLDEST 1,500 of the day's writers (now 5,000+ with Cold / B / C / campaigns), all of them daytime replies older than 2 hours that the rule below skips: nobody was ever looked at. Only the ones that can still get an offer are listed: a reply of the last 2 hours or one that came at night (before 09:00 / after 21:40). */
        $__tw = $simulate ? '' : " AND (last_in_at >= '" . date('Y-m-d H:i:s', $now - $fastMax) . "' OR TIME(last_in_at) < '09:00:00' OR TIME(last_in_at) >= '21:40:00')";
        $st = $pdo->prepare("SELECT phone, last_in_at FROM wa_contacts WHERE last_in_at >= ? AND last_in_at <= ? AND optout = 0 AND camp_optout = 0 AND (cx_id IS NULL OR cx_id = 0) AND (tag IS NULL OR tag <> 'complaint')" . $__tw . ($only !== '' ? ' AND phone = ' . $pdo->quote($only) : '') . " ORDER BY last_in_at DESC LIMIT 1500");   /* owner 26 Nov: NEWEST replies first (it was the oldest first: with 30 a minute the fresh reply waited behind the whole queue, so the 1-3 minutes could not happen) */
        $st->execute([date('Y-m-d H:i:s', $now - $maxAge), date('Y-m-d H:i:s', $now - ($only !== '' ? -5 : $minAge))]); $cont = $st->fetchAll(PDO::FETCH_ASSOC); if (!$cont) return $res;
        $ordK = []; try { foreach ($pdo->query("SELECT phone, created_at FROM shop_orders WHERE created_at >= '" . date('Y-m-d H:i:s', $now - 2 * 86400) . "' AND phone IS NOT NULL AND phone <> ''")->fetchAll(PDO::FETCH_NUM) as $r) $ordK[substr(preg_replace('/\D+/', '', (string)$r[0]), -10)][] = (int)strtotime((string)$r[1]); } catch (Throwable $e) { }
        $pr = '/(price|rate|kitn|kimat|cost|offer|discount|combo|\bml\b|size|chahiye|\d+\s*[x*]\s*\d+|कीमत|कितना|ભાવ|કિંમત)/iu'; $dc = '/(nahi|nahin|\bno\b|not interested|not now|abhi nahi|baad me|stop|band kar|mat bhej|unsubscribe|don.?t|bye|नहीं|मत)/iu';
        $triv = '/^\W*(ok+|okay|okk+|k|thanks?|thank you|thanku|thx|ty|done|got it|noted|fine|nice|good|great|cool|welcome|hmm+|theek( hai)?|thik( hai)?|shukriya|dhanyawad|धन्यवाद|शुक्रिया|ठीक( है)?|ओके)\W*$/iu';   /* owner 26 Nov: a plain yes / haan / ji / sure is an answer to our question, he gets the offer */
        $svc = '/(parcel|courier|deliver|tracking|\btrack|return|refund|replace|damage|leak|complain|kab aa|nahi mila|nahi aaya|not received|wrong (item|product|perfume)|cancel|पार्सल|डिलीवरी|પાર્સલ|ડિલિવરી|રિફંડ)/iu';   /* he writes about a parcel / return / complaint: a person answers, a price offer would be the wrong reply */
        // who got a Lineup message after which he wrote: the LAST Lineup message of each customer must be older than his last message
        $lp = []; foreach (array_chunk(array_column($cont, 'phone'), 400) as $chk) { $x = $pdo->prepare("SELECT l.phone, MAX(l.sent_at) FROM wa_line_plan l JOIN wa_contacts cc ON cc.phone COLLATE utf8mb4_unicode_ci = l.phone COLLATE utf8mb4_unicode_ci WHERE l.status = 'sent' AND l.sent_at >= ? AND l.sent_at < cc.last_in_at AND l.phone IN (" . implode(',', array_fill(0, count($chk), '?')) . ") GROUP BY l.phone"); $x->execute(array_merge([date('Y-m-d H:i:s', $now - $maxAge - 2 * 86400)], $chk)); foreach ($x->fetchAll(PDO::FETCH_NUM) as $r) $lp[(string)$r[0]] = (string)$r[1]; }
        /* owner 22 Nov: a customer who answers a COLD-funnel message gets the same price offer (his reply came after one of our messages: the Cold funnel's counts as well as the Lineup's) */ try { foreach (array_chunk(array_column($cont, 'phone'), 400) as $chk) { $x = $pdo->prepare("SELECT l.phone, MAX(l.at) FROM wa_cold_log l JOIN wa_contacts cc ON cc.phone COLLATE utf8mb4_unicode_ci = l.phone COLLATE utf8mb4_unicode_ci WHERE l.ok = 1 AND l.at >= ? AND l.at < cc.last_in_at AND l.phone IN (" . implode(',', array_fill(0, count($chk), '?')) . ") GROUP BY l.phone"); $x->execute(array_merge([date('Y-m-d H:i:s', $now - $maxAge - 2 * 86400)], $chk)); foreach ($x->fetchAll(PDO::FETCH_NUM) as $r) if (!isset($lp[(string)$r[0]]) || (string)$r[1] > $lp[(string)$r[0]]) $lp[(string)$r[0]] = (string)$r[1]; } } catch (Throwable $e) { /* no cold table yet: nothing to add */ }
        /* owner 25 Nov 2026: a customer who answers a Module B / Module C utility message gets the price offer too (they were never looked at: 4,400 wrote a day, ~80 were looked at) */ try { foreach (array_chunk(array_column($cont, 'phone'), 400) as $chk) { $x = $pdo->prepare("SELECT l.phone, MAX(l.at) FROM wa_ru_log l JOIN wa_contacts cc ON cc.phone COLLATE utf8mb4_unicode_ci = l.phone COLLATE utf8mb4_unicode_ci WHERE l.ok = 1 AND l.at >= ? AND l.at < cc.last_in_at AND l.phone IN (" . implode(',', array_fill(0, count($chk), '?')) . ") GROUP BY l.phone"); $x->execute(array_merge([date('Y-m-d H:i:s', $now - $maxAge - 2 * 86400)], $chk)); foreach ($x->fetchAll(PDO::FETCH_NUM) as $r) if (!isset($lp[(string)$r[0]]) || (string)$r[1] > $lp[(string)$r[0]]) $lp[(string)$r[0]] = (string)$r[1]; } } catch (Throwable $e) { /* no utility log yet */ }
        /* owner 25 Nov 2026: and a customer who answers a Module A campaign message (or a recovery message) gets it too */ try { foreach (array_chunk(array_column($cont, 'phone'), 400) as $chk) { $x = $pdo->prepare("SELECT l.phone, MAX(l.ts) FROM wa_messages l JOIN wa_contacts cc ON cc.phone = l.phone WHERE l.dir = 'out' AND l.who IN ('campaign', 'recover', 'followup') AND l.mtype = 'template' AND l.ts >= ? AND l.ts < cc.last_in_at AND l.phone IN (" . implode(',', array_fill(0, count($chk), '?')) . ") GROUP BY l.phone"); $x->execute(array_merge([date('Y-m-d H:i:s', $now - $maxAge - 2 * 86400)], $chk)); foreach ($x->fetchAll(PDO::FETCH_NUM) as $r) if (!isset($lp[(string)$r[0]]) || (string)$r[1] > $lp[(string)$r[0]]) $lp[(string)$r[0]] = (string)$r[1]; } } catch (Throwable $e) { /* nothing to add */ }
        /* owner 25 Nov 2026: and the After-Order messages too (his chat is read the same way; a customer who ordered in the last 2 days still gets no lower price) */ try { foreach (array_chunk(array_column($cont, 'phone'), 400) as $chk) { $x = $pdo->prepare("SELECT l.phone, MAX(l.sent_at) FROM wa_aft_plan l JOIN wa_contacts cc ON cc.phone COLLATE utf8mb4_unicode_ci = l.phone COLLATE utf8mb4_unicode_ci WHERE l.status = 'sent' AND l.sent_at >= ? AND l.sent_at < cc.last_in_at AND l.phone IN (" . implode(',', array_fill(0, count($chk), '?')) . ") GROUP BY l.phone"); $x->execute(array_merge([date('Y-m-d H:i:s', $now - $maxAge - 2 * 86400)], $chk)); foreach ($x->fetchAll(PDO::FETCH_NUM) as $r) if (!isset($lp[(string)$r[0]]) || (string)$r[1] > $lp[(string)$r[0]]) $lp[(string)$r[0]] = (string)$r[1]; } } catch (Throwable $e) { /* no After-Order table yet */ }
        /* owner 27 Nov 2026: one offer in 24 hours stays; but a customer whose earlier reply got only a note ('thanks', 'no combo' …) is read AGAIN when he writes something new (he was skipped for a whole day: "not looked at by the offer run") */
        $done = []; try { $d = $pdo->prepare("SELECT phone, MAX(ok), MAX(reply_at) FROM wa_offer WHERE at >= ? AND mode = 'live' GROUP BY phone"); $d->execute([date('Y-m-d H:i:s', $now - 86400)]); foreach ($d->fetchAll(PDO::FETCH_NUM) as $p) $done[(string)$p[0]] = (int)$p[1] > 0 ? '9' : (string)$p[2]; } catch (Throwable $e) { }
        foreach ($cont as $r) {
            if ($res[0] >= $cap || microtime(true) - $t0 > 40) break; $ph = (string)$r['phone']; if ((isset($done[$ph]) && ($done[$ph] === '9' || $done[$ph] >= (string)$r['last_in_at'])) || !isset($lp[$ph]) || $lp[$ph] >= (string)$r['last_in_at']) continue;
            if (!$simulate && $only === '' && (string)setting_get($pdo, 'wo_fast', '1') !== '0' && $now - (int)strtotime((string)$r['last_in_at']) < 30 + (int)(abs(crc32($ph)) % 61)) continue;   // each customer has his own wait between 1 and 3 minutes (so they do not all go at the same second)
            if (!$simulate) { $__t1 = (int)strtotime((string)$r['last_in_at']); if ($now - $__t1 > $fastMax) { $__hm = (int)date('G', $__t1) * 60 + (int)date('i', $__t1); if (!($__hm < 9 * 60 || $__hm >= 21 * 60 + 40)) continue; } }   // older than 2 h and it came in the daytime: too late (a lowered price after hours is no longer 'the same conversation'); a night reply is not too late
            $res[0]++;
            $note = function (string $why, int $ok = 0, array $o = [], string $body = '', int $asked = 0) use ($pdo, $ph, $r, $mode, $simulate) { $GLOBALS['wo_stat']['r:' . mb_substr($why, 0, 28)] = ($GLOBALS['wo_stat']['r:' . mb_substr($why, 0, 28)] ?? 0) + 1; if ($simulate) return; try { $pdo->prepare("INSERT INTO wa_offer (phone, reply_at, kind, pct, qty, ml, price, asked, mode, ok, why, body, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$ph, (string)$r['last_in_at'], (string)($o['kind'] ?? ''), (float)($o['pct'] ?? 0), (int)($o['qty'] ?? 0), (int)($o['ml'] ?? 0), (int)($o['price'] ?? 0), $asked, $mode, $ok, mb_substr($why, 0, 150), $body, date('Y-m-d H:i:s')]); } catch (Throwable $e) { } };
            $m = $pdo->prepare("SELECT dir, who, body, ts FROM wa_messages WHERE phone = ? AND ts >= ? AND dir IN ('in', 'out', 'sys') ORDER BY id DESC LIMIT 60"); $m->execute([$ph, date('Y-m-d H:i:s', $now - 3 * 86400)]); $ms = array_reverse($m->fetchAll(PDO::FETCH_ASSOC)); $in = []; $out = []; $first = ''; $emp = 0; $auto = false; $ordered = false;
            foreach ($ms as $x) { if ($x['dir'] === 'sys' && strpos((string)$x['body'], 'Auto-reply from another business') === 0 && (string)$x['ts'] >= $lp[$ph]) $auto = true; if ($x['dir'] === 'in' && (string)$x['ts'] > $lp[$ph]) { if ($first === '') $first = trim(preg_replace('/\s+/u', ' ', (string)$x['body'])); $in[] = (string)$x['body']; } if ($x['dir'] === 'out') { $out[] = (string)$x['body']; if ($x['who'] === 'emp' && (string)$x['ts'] >= date('Y-m-d H:i:s', $now - 30 * 60)) $emp++; } }
            if (!$in && $only !== '' && trim((string)($GLOBALS['wo_only_text'] ?? '')) !== '') $in = [(string)$GLOBALS['wo_only_text']];   // his message is being stored right now: the text that just came in is used
            if ($auto) { $note('other business auto-reply'); continue; } /* owner 26 Nov: the offer is made after reading ALL he wrote since our message (not only his first line) */ $real = []; $decl = false; $serv = false; foreach ($in as $b) { $b1 = trim(preg_replace('/\s+/u', ' ', (string)$b)); if ($b1 === '') continue; if (preg_match($svc, $b1)) $serv = true; if (preg_match($dc, $b1)) $decl = true; if (mb_strlen($b1) > 1 && !preg_match($triv, $b1) && !preg_match('/^[\p{So}\p{Sk}\p{P}\s]+$/u', $b1)) $real[] = $b1; }
            if ($serv) { $note('parcel / return / complaint: a person answers'); continue; } if ($decl || !$real) { $note('decline / thanks / empty'); continue; }
            if ($emp > 0) { continue; } if (!$in) continue;   // a person talked to him in the last 30 minutes: not now (tried again next minute)
            $k10 = substr(preg_replace('/\D+/', '', $ph), -10); $t1 = (int)strtotime((string)$r['last_in_at']); foreach ($ordK[$k10] ?? [] as $ot) if ($ot >= $t1 - 6 * 3600) { $ordered = true; break; } if ($ordered) { $note('ordered'); continue; }
            if (function_exists('wa_auto_muted') && wa_auto_muted($pdo, $ph)) { $note('muted (order / ticket)'); continue; }
            $asked = false; foreach ($in as $b) if (preg_match($pr, $b)) { $asked = true; break; }
            $c = function_exists('wa_contact') ? wa_contact($pdo, $ph) : null; if (!$c) { $note('no contact'); continue; }
            $why = ''; $o = wof_make($pdo, $c, $ch, $in, $out, $asked, $why); if (!$o) { $note($why, 0, [], '', (int)$asked); continue; } $o['said'] = mb_strtolower(implode(' ', $real)); $o['asked'] = $asked; $body = wof_text($o); $res[1]++; $sk = 'made ' . $o['kind'] . ($asked ? ' asked' : ' not-asked'); $GLOBALS['wo_stat'][$sk] = ($GLOBALS['wo_stat'][$sk] ?? 0) + 1; $GLOBALS['wo_stat']['sum price'] = ($GLOBALS['wo_stat']['sum price'] ?? 0) + $o['price']; if ($samples !== null && count($samples) < 6) $samples[] = $o['kind'] . ' ' . $o['pct'] . '% ' . $o['qty'] . 'x' . $o['ml'] . ' ' . ($asked ? 'asked' : 'not-asked') . ' :: ' . str_replace("\n", ' / ', $body);
            if ($simulate || $mode !== 'live') continue;
            $s = wa_send_text($pdo, $c, $body, 'offer', 0); if (!empty($s[0])) { $res[2]++; $note('sent ' . (string)($s[1] ?? ''), 1, $o, $body, (int)$asked); if (function_exists('wa_event')) { try { wa_event($pdo, $ph, '🎯 Price offer sent (' . $o['kind'] . ', ' . $o['pct'] . '% column, ' . $o['qty'] . ' x ' . $o['ml'] . ' ml)'); } catch (Throwable $e) { } } } else $note('not sent: ' . (string)($s[1] ?? ''), 0, $o, $body, (int)$asked);
        }
        if (!$simulate && $only === '' && $mode === 'live') { try { wof_follow($pdo, $now); } catch (Throwable $e) { } }
        if (!$simulate) { try { setting_set($pdo, 'wo_last_res', json_encode(['at' => date('Y-m-d H:i:s'), 'looked' => $res[0], 'made' => $res[1], 'sent' => $res[2]])); } catch (Throwable $e) { } }
        return $res;
    }
}
