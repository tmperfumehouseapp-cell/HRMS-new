<?php
/**
 * wa_line.php — 🧵 LINEUP: the 7-day follow-up by TOPIC (replaces the one-ladder 7-Day Drip).
 *   · A customer who writes to us is read once (his words): TOPIC = pending (address / payment), price, trust (original? COD? reviews), size (ml), reorder (bought before), general.
 *   · Each topic has its own 7 steps (4 topic messages + 3 shared). Every message is a UTILITY template with ONE quick-reply button and NO offer words:
 *     the offer / reviews / video go FREE as the answer to the button (Button replies cards ln_*) inside the customer's 24 hours.
 *   · The whole lineup of a customer is written down at enrolment (wa_line_plan: 7 rows, each with its own due time = his first message + N × 24 h, inside the 10:00-20:00 IST window),
 *     so the owner can SEE it (wa.line_get) and the engine only sends what is due. Order / opt-out / complaint = out. A reply stops the ladder; after 24 quiet hours he is read again:
 *     a NEW topic = a new ladder (messages already sent are never repeated; at most 2 changes, never beyond 14 days).
 *   · Starting it (wa.line_start) switches the old 7-Day Drip off, makes the templates and sends them to Meta (the existing auto-submit keeps retrying), installs the button cards, and enrols everybody since yesterday 10:00 IST.
 *   It is ALWAYS ON (owner, 21 Nov): the first engine round after the upload starts it by itself, the engine sends inside 10:00-20:00 IST once Meta approved ALL the messages; the screen's Send button is the manual second way (works at any hour, batches of 60). PHP 7.4 safe.
 *   Settings: wln_cfg (mode, cap, from, to, since, offer) · wl_tpls (key => template id). Tables: wa_line_cust, wa_line_plan, wa_line_log.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wln_cfg')) {

// ---- pure:begin  (no database here: tests/line_test.php runs this block alone)
function wln_topics(): array
{
    return ['pending' => 'Address / payment pending', 'price' => 'Price / discount', 'trust' => 'Trust (original, COD, reviews)', 'size' => 'Size / ml', 'reorder' => 'Bought before', 'general' => 'No clear topic'];
}
/** Words that say what the customer is worried about, strongest first. Latin (English / Hinglish) and Gujarati / Hindi script. */
function wln_words(): array
{
    return [
        'pending' => ['address', 'pincode', 'pin code', 'payment', 'paid', 'upi', 'qr', 'order karna', 'order karo', 'order kar', 'એડ્રેસ', 'પેમેન્ટ', 'ઓર્ડર', 'पता', 'पेमेंट', 'ऑर्डर'],
        'price' => ['price', 'rate', 'kimat', 'kitna', 'kitne', 'kitni', 'kya rate', 'discount', 'costly', 'expensive', 'mehnga', 'mahenga', 'mahnga', 'sasta', 'cheap', 'budget', 'offer', 'ભાવ', 'કિંમત', 'કેટલા', 'સસ્તું', 'મોંઘું', 'कीमत', 'कितना', 'कितने', 'सस्ता', 'महंगा'],
        'trust' => ['original', 'asli', 'real', 'fake', 'genuine', 'review', 'cod', 'cash on delivery', 'trust', 'bharosa', 'vishwas', 'proof', 'guarantee', 'nakli', 'duplicate', 'batch', 'અસલી', 'ઓરિજિનલ', 'નકલી', 'વિશ્વાસ', 'असली', 'ओरिजिनल', 'नकली', 'भरोसा'],
        'size' => ['ml', 'size', 'quantity', 'sample', 'tester', 'bottle', 'ટેસ્ટર', 'સાઇઝ', 'साइज'],
    ];
}
/** Topic from what the customer wrote. $texts = his messages since he came in; $bought = he has ordered before. */
function wln_classify(array $texts, bool $bought): string
{
    $all = mb_strtolower(trim(implode(' ', array_map('strval', $texts)))); if ($all !== '') {
        $norm = preg_replace('/(?<=\d)(?=\p{L})|(?<=\p{L})(?=\d)/u', ' ', $all); $norm = ' ' . trim((string)preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', (string)$norm)) . ' ';   // "50ml" -> "50 ml"; marks stay (Gujarati / Hindi)
        foreach (wln_words() as $topic => $ws) foreach ($ws as $w) {
            $w = mb_strtolower($w); if (preg_match('/^[a-z ]+$/', $w)) { if (strpos($norm, ' ' . $w . ' ') !== false) return $topic; }   // Latin: whole words only (no "real" inside "really", no "book" inside "facebook")
            elseif (strpos($norm, $w) !== false) return $topic;   // Gujarati / Hindi script: the word may carry endings
        }
    }
    return $bought ? 'reorder' : 'general';
}
/** The IST wall clock of a time stamp: [Y-m-d, hour]. */
function wln_ist(int $ts): array { $d = new DateTime('@' . $ts); $d->setTimezone(new DateTimeZone('Asia/Kolkata')); return [$d->format('Y-m-d'), (int)$d->format('G')]; }
function wln_ist_at(string $ymd, int $hour): int { $d = new DateTime($ymd . ' ' . sprintf('%02d', $hour) . ':00:00', new DateTimeZone('Asia/Kolkata')); return $d->getTimestamp(); }
/** When Day N is due: his first message + N × 24 h (his own minute), moved into the sending window (default 10:00-20:00 IST): before it = that morning, after it = next morning. */
function wln_due_ts(int $anchor, int $day, int $from = 10, int $to = 20): int
{
    $t = $anchor + $day * 86400; [$ymd, $h] = wln_ist($t);
    if ($h < $from) return wln_ist_at($ymd, $from);
    if ($h >= $to) return wln_ist_at(date('Y-m-d', strtotime($ymd . ' +1 day')), $from);
    return $t;
}
/** 10:00 IST of the day before today (IST) — the start of "yesterday". */
function wln_yesterday_start(int $now): int { [$ymd] = wln_ist($now); return wln_ist_at(date('Y-m-d', strtotime($ymd . ' -1 day')), 10); }

/** THE MESSAGES. key => [topic, day-in-ladder, body, button, free answer]. Utility only: no offer / discount / sale / free / special in a template (Meta re-labels those to marketing). The offer goes in the FREE answer to the button. */
function wln_pack(): array
{
    $P = [];
    $add = function ($key, $topic, $step, $body, $btn, $free) use (&$P) { if (strpos($body, '{{1}}') === 0) $body = 'Hi ' . $body;   // Meta refuses a message that starts with a blank
        $P[$key] = ['topic' => $topic, 'step' => $step, 'body' => $body, 'button' => $btn, 'free' => $free]; };
    $T = [
        'trust' => [
            ["Namaste {{1}} ji 🙏 Aapne perfume ke original hone ke baare mein poocha tha. Hamare saare perfume original hain aur COD bhi available hai. Parcel khol kar check karke hi payment kar sakte hain.", 'Proof dekhna hai', "Bilkul 🙏 Hamare saare perfume 100% original hain. Parcel aane par khol kar check karein, phir payment karein (COD available hai). Chahen to hamare customers ke real reviews aur photos bhej dete hain — bas 'Reviews' likh dein."],
            ["{{1}} ji, aapke sawal se jude hamare customers ke reviews taiyaar hain. Dekhna chahenge?", 'Reviews dekhna hai', "Ye hain hamare customers ke real reviews aur photos 👇 Aapko kaunsa perfume dekhna hai, bata dein to uski details bhi bhej dete hain."],
            ["{{1}} ji, parcel kaise pack hota hai aur aap tak kaise pahunchta hai, iska ek chhota video taiyaar hai.", 'Packing video dekhna hai', "Ye hai packing aur dispatch ka video 🎥 Parcel sealed aata hai aur aap delivery par khol kar check kar sakte hain. Koi sawal ho to yahin likh dein."],
            ["{{1}} ji, pehli baar le rahe hain to 20 ml se shuru kar sakte hain. Pasand aaye to bada size baad mein. 20 ml ke options bhejun?", '20 ml ke options', "20 ml ek achha shuruaati option hai 🙂 Aapko kaunsa perfume pasand hai bata dein, hum 20 ml ki kimat aur options bhej dete hain."],
        ],
        'price' => [
            ["Namaste {{1}} ji 🙏 Aapne perfume ke rate ke baare mein poocha tha. Hamare paas har budget ke options hain. Budget bata dein to sahi perfume suggest kar dete hain.", 'Budget batana hai', "Zaroor 🙏 Aapka budget kitna hai? Bata dein, hum us mein best perfume aur size suggest karte hain."],
            ["{{1}} ji, 20 ml aur 50 ml dono ka rate aur farak bhejun? Aapke budget ke hisaab se best option dikhate hain.", 'Rate list bhejiye', "Ye lijiye 20 ml aur 50 ml ka rate aur farak 👇 Aapko kaunsa perfume dekhna hai, bata dein."],
            ["{{1}} ji, ek perfume kitne din chalta hai aur roz ka kharch kitna aata hai, ye samjhaun?", 'Kharch samjhaiye', "Ek 50 ml perfume roz ke 2-3 spray par kaafi din chalta hai, isliye roz ka kharch bahut kam aata hai 🙂 Aapko kaunsa perfume chahiye, bata dein to hisaab bhej dete hain."],
            ["{{1}} ji, aapke liye koi chhota pack ya combo ka option dekhna hai?", 'Combo dekhna hai', "Combo ke options ye rahe 👇 Aapko kaunse perfume pasand hain, bata dein to sahi combo suggest karte hain."],
        ],
        'pending' => [
            ["Namaste {{1}} ji 🙏 Aapka order abhi tak confirm nahi hua hai. Address ya payment mein koi madad chahiye?", 'Order confirm karna hai', "Theek hai 🙏 Apna poora naam, address aur pincode bhej dein, hum order confirm karte hain. COD ya online payment, jo aapko theek lage."],
            ["{{1}} ji, aapka perfume abhi bhi aapke naam se taiyaar hai. Address bhej dein ya COD select karein?", 'COD se bhejiye', "Ji, COD se bhej dete hain 🙏 Bas apna naam, address aur pincode likh dein."],
            ["{{1}} ji, aaj order confirm hone par dispatch jaldi ho jata hai. Kisi cheez mein madad chahiye?", 'Dispatch ke baare mein', "Order confirm hote hi dispatch ki process shuru ho jati hai 📦 Naam, address aur pincode bhej dein, hum aaj hi aage badhate hain."],
            ["{{1}} ji, order mein koi dikkat aa rahi ho to bata dijiye, hum seedha solve kar denge.", 'Baat karni hai', "Zaroor 🙏 Aap yahin apni dikkat likh dein, hamari team turant madad karegi."],
        ],
        'size' => [
            ["Namaste {{1}} ji 🙏 Aapne size / ML ke baare mein poocha tha. 20 ml, 50 ml aur 100 ml ka farak aur rate bhejun?", 'Size ka farak batao', "Ye raha 20 ml, 50 ml aur 100 ml ka farak aur rate 👇 Aapko kaunsa perfume dekhna hai, bata dein."],
            ["{{1}} ji, perfume kitne ghante tikta hai aur kaunsa size aapke liye theek rahega, batayein?", 'Mere liye kaunsa size', "Rozmarra ke liye 50 ml sabse zyada pasand kiya jata hai, aur try karne ke liye 20 ml 🙂 Aap kaunsa perfume dekh rahe hain bata dein."],
            ["{{1}} ji, gift ke liye ya apne liye? Us hisaab se size suggest kar denge.", 'Gift ke liye dekhna hai', "Gift ke liye hamare paas achhe options hain 🎁 Kisko dena hai (men / women) aur budget bata dein, hum suggest karte hain."],
            ["{{1}} ji, pehle chhota size try karna chahenge?", 'Chhota size dikhao', "Chhote size ye rahe 👇 Aapko kaunsa perfume pasand hai bata dein, hum 20 ml ki details bhej dete hain."],
        ],
        'reorder' => [
            ["Namaste {{1}} ji 🙏 Aapne pehle hamse perfume liya tha. Kaisa laga? Naya kuch try karna ho to bataiye.", 'Naya perfume dekhna hai', "Dhanyavaad 🙏 Aapko pichhla perfume kaisa laga? Kaisi khushboo pasand hai bata dein, hum naya perfume suggest karte hain."],
            ["{{1}} ji, aapke pehle wale perfume jaisa hi naya option dekhna chahenge?", 'Milta julta dikhao', "Aapke pichhle perfume se milte julte options ye rahe 👇 Bata dein kaunsa dekhna hai."],
            ["{{1}} ji, repeat order par aapke liye alag se dekh lete hain. Dekhna hai?", 'Repeat order ki details', "Repeat customers ke liye hum alag se dekhte hain 🙏 Aapko kaunsa perfume chahiye bata dein."],
            ["{{1}} ji, aapka pichhla perfume khatam hone wala ho to wahi dobara bhej dein?", 'Wahi dobara bhejiye', "Ji, wahi perfume dobara bhej dete hain 🙏 Bas size aur address confirm kar dein."],
        ],
        'general' => [
            ["Namaste {{1}} ji 🙏 Aapne hamse sampark kiya tha. Kaunsa perfume dekh rahe the? Bata dein to turant details bhej dete hain.", 'Perfume batana hai', "Zaroor 🙏 Perfume ka naam ya photo bhej dein, hum details aur size bata dete hain."],
            ["{{1}} ji, men's, women's ya unisex — aapko kaunsi type pasand hai?", 'Type batani hai', "Theek hai 🙂 Bata dein kis type ki khushboo pasand hai (fresh, sweet, woody...), hum 2-3 options bhejte hain."],
            ["{{1}} ji, hamare sabse zyada pasand kiye jaane wale perfumes ki list bhejun?", 'Best sellers dikhao', "Ye hain hamare best sellers 👇 Koi pasand aaye to bata dein, details bhej dete hain."],
            ["{{1}} ji, aapki pasand ke hisaab se 2-3 perfume suggest kar sakte hain. Madad chahiye?", 'Suggest kijiye', "Zaroor 🙏 Aap kisi aur perfume ka naam bata dein jo pasand ho, hum usi jaise 2-3 options bhejte hain."],
        ],
    ];
    foreach ($T as $topic => $rows) foreach ($rows as $i => $r) $add('ln_' . $topic . '_' . ($i + 1), $topic, $i + 1, $r[0], $r[1], $r[2]);
    // second wording of step 1 (everybody passes step 1, so it learns fastest): the engine sends the wording that gets more taps / orders (wl_choose)
    $V = ['trust' => ["Namaste {{1}} ji 🙏 Perfume asli hai ya nahi, ye sabse zaroori sawal hai. Aap parcel khol kar check karke hi payment kar sakte hain (COD). Proof ya reviews dekhna chahenge?", 'Reviews aur proof dekhein'],
        'price' => ["Namaste {{1}} ji 🙏 Rate poochne ke liye shukriya. Perfume aur size ke hisaab se rate alag hota hai. Kaunsa perfume dekh rahe the, bata dein?", 'Perfume ka naam batao'],
        'pending' => ["Namaste {{1}} ji 🙏 Aapka order bas ek step door hai: address aur pincode. Bhej dein to hum confirm kar dete hain.", 'Address bhejna hai'],
        'size' => ["Namaste {{1}} ji 🙏 Size chunna aasan hai: 20 ml try karne ke liye, 50 ml roz ke liye, 100 ml lambe time ke liye. Aapke liye kaunsa theek rahega?", 'Kaunsa size theek hai'],
        'reorder' => ["Namaste {{1}} ji 🙏 Pichhli baar aapne hamse perfume liya tha, shukriya. Is baar kaunsi khushboo try karni hai?", 'Nayi khushboo batayein'],
        'general' => ["Namaste {{1}} ji 🙏 Hamse sampark karne ke liye shukriya. Aap kaunsi khushboo dhoondh rahe hain, bata dein to hum sahi perfume dikhate hain.", 'Khushboo batani hai']];
    foreach ($V as $topic => $r) { $k = 'ln_' . $topic . '_1b'; $add($k, $topic, 1, $r[0], $r[1], $P['ln_' . $topic . '_1']['free']); $P[$k]['of'] = 'ln_' . $topic . '_1'; }
    // the same last three steps for every topic
    $add('ln_all_5', 'all', 5, "{{1}} ji, aapke sawal ka jawab aapko mil gaya? Aur jaankari chahiye to yahin likh dijiye.", 'Aur jaankari chahiye', "Zaroor 🙏 Aap yahin likh dein ki aur kya jaanna hai, hum turant batate hain.");
    $add('ln_all_6', 'all', 6, "{{1}} ji, aapki request ke baare mein final details taiyaar hain. Dekhna chahenge?", 'Final details dekhein', "__OFFER__");
    $add('ln_all_7', 'all', 7, "{{1}} ji, aapki request ka ye aakhri reminder hai. Order karna chahte hain to hum abhi madad kar sakte hain.", 'Order karna chahta hoon', "Ji 🙏 Perfume ka naam, size aur address bhej dein, hum order confirm karte hain.");
    return $P;
}
/** The wordings of one message: the message itself and its alternatives (key => the keys that can be sent instead). */
function wln_variants(string $key): array { $o = [$key]; foreach (wln_pack() as $k => $m) if (($m['of'] ?? '') === $key) $o[] = $k; return $o; }
/** Which wording goes to this customer. $score = [key => [delivered, score]] (what the last 30 days showed), $ok = keys that Meta approved. Below 40 delivered per wording: an even split (he is placed by his number, so the same customer always gets the same). Then 80% the wording with the best score, 20% the others (keeps learning). */
function wln_pick(string $key, string $phone, array $score, array $ok): string
{
    $v = array_values(array_filter(wln_variants($key), fn($k) => in_array($k, $ok, true))); if (count($v) < 2) return $v ? $v[0] : $key;
    $h = (int)(abs(crc32($phone . '|' . $key)) % 100); $young = false; foreach ($v as $k) if (($score[$k][0] ?? 0) < 40) $young = true;
    if ($young) return $v[$h % count($v)];
    usort($v, fn($a, $b) => ($score[$b][1] ?? 0) <=> ($score[$a][1] ?? 0)); if ($h < 80) return $v[0];
    return $v[1 + ($h % (count($v) - 1))];
}
/** The template key of step N (1..7) for a topic. */
function wln_key(string $topic, int $step): string { return $step >= 5 ? 'ln_all_' . $step : 'ln_' . $topic . '_' . $step; }
/** Words the templates must never contain (Meta would re-label them as marketing). */
function wln_banned(): array { return ['offer', 'discount', 'sale', 'free', 'special', 'khaas', 'chhoot', 'cashback', '%', '₹', 'off ']; }
// ---- pure:end

function wln_cfg(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'wl_cfg', ''), true); if (!is_array($j)) $j = [];
    $mode = (string)($j['mode'] ?? 'auto'); if (!in_array($mode, ['off', 'manual', 'auto'], true)) $mode = 'auto';   // owner 21 Nov: always on (no switch on the screen); 'off' stays only as an emergency value the API accepts
    return ['mode' => $mode, 'cap' => max(5, min(300, (int)($j['cap'] ?? 60))), 'from' => max(0, min(23, (int)($j['from'] ?? 10))), 'to' => max(1, min(24, (int)($j['to'] ?? 20))), 'since' => (int)($j['since'] ?? 0),
        'offer' => (string)($j['offer'] ?? ''), 'started' => (string)($j['started'] ?? '')];
}
function wln_cfg_put(PDO $pdo, array $c): void { setting_set($pdo, 'wl_cfg', json_encode($c, JSON_UNESCAPED_UNICODE)); }
function wln_offer_text(PDO $pdo): string { $o = trim(wln_cfg($pdo)['offer']); return $o !== '' ? $o : "Aapke liye final details ye hain 🙏 Aapko kaunsa perfume chahiye, bata dein to hum is hafte ki sabse achhi kimat bata dete hain."; }
function wln_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_line_cust (phone VARCHAR(12) NOT NULL PRIMARY KEY, topic VARCHAR(12) NOT NULL DEFAULT 'general', anchor_at DATETIME NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'active', left_why VARCHAR(40) NOT NULL DEFAULT '', switches TINYINT NOT NULL DEFAULT 0, recheck_at DATETIME NULL, created_at DATETIME NOT NULL, KEY k_status (status, recheck_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_line_plan (id BIGINT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, topic VARCHAR(12) NOT NULL, step TINYINT NOT NULL, tpl_key VARCHAR(20) NOT NULL, due_at DATETIME NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'planned', why VARCHAR(200) NOT NULL DEFAULT '', sent_at DATETIME NULL, wamid VARCHAR(80) NOT NULL DEFAULT '', KEY k_due (status, due_at), KEY k_phone (phone, step)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_line_snap (id BIGINT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, j TEXT NOT NULL, KEY k_at (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_line_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, at DATETIME NOT NULL, what VARCHAR(200) NOT NULL DEFAULT '', KEY k_phone (phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true;
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
function wln_tpl_ids(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'wl_tpls', ''), true); return is_array($j) ? array_map('intval', $j) : []; }
function wln_log(PDO $pdo, string $phone, string $what): void { try { $pdo->prepare("INSERT INTO wa_line_log (phone, at, what) VALUES (?, ?, ?)")->execute([$phone, date('Y-m-d H:i:s'), mb_substr($what, 0, 200)]); } catch (Throwable $e) { } }

/** Write the lineup of a customer: one row per step still to go. $skipKeys = messages he already got (never sent again). Returns the number of rows. */
function wln_plan(PDO $pdo, string $phone, string $topic, int $anchor, array $skipKeys = [], int $now = 0): int
{
    $cfg = wln_cfg($pdo); $now = $now ?: time(); $ins = $pdo->prepare("INSERT INTO wa_line_plan (phone, topic, step, tpl_key, due_at) VALUES (?, ?, ?, ?, ?)"); $n = 0; $day = 0;
    for ($s = 1; $s <= 7; $s++) {
        $k = wln_key($topic, $s); if (isset($skipKeys[$k])) continue; $day++;
        $due = wln_due_ts($anchor, $day, $cfg['from'], $cfg['to']); if ($due < $now - 86400 * 2) $due = wln_due_ts($now, 0, $cfg['from'], $cfg['to']);
        $ins->execute([$phone, $topic, $s, $k, date('Y-m-d H:i:s', $due)]); $n++;
    }
    return $n;
}
/** Orders placed since $from, as [last-10-digits => [timestamps]] — ONE query for a whole round (the wa_cust table is only refreshed now and then, so it can miss an order from the last hours). */
function wln_orders_since(PDO $pdo, int $from): array
{
    $o = []; try { $q = $pdo->prepare("SELECT phone, created_at FROM shop_orders WHERE COALESCE(tags, '') NOT LIKE '%wa-rx%' AND created_at >= ? AND phone IS NOT NULL AND phone != '' LIMIT 200000"); $q->execute([date('Y-m-d H:i:s', $from)]);
        foreach ($q->fetchAll() as $r) { $k = function_exists('wa_key') ? wa_key($r['phone']) : substr(preg_replace('/\D+/', '', (string)$r['phone']), -10); if ($k !== '') $o[$k][] = (int)strtotime((string)$r['created_at']); } } catch (Throwable $e) { }
    return $o;
}
function wln_ordered_after(array $orders, string $phone, int $t): bool { foreach ($orders[substr(preg_replace('/\D+/', '', $phone), -10)] ?? [] as $x) if ($x >= $t) return true; return false; }
/** Who comes in: every customer who wrote to us (new query / campaign reply) at or after $since, however old the contact is — his FIRST message in that window starts his Day 0.
 *  One lineup per customer per 30 days (an old finished one is replaced). Already ordered since he wrote = not enrolled. Each is read once (topic) and gets his 7 rows. */
function wln_enroll(PDO $pdo, int $since): int
{
    if ($since <= 0) return 0; $n = 0; $orders = wln_orders_since($pdo, $since);
    $have = []; $h = $pdo->prepare("SELECT phone FROM wa_line_cust WHERE created_at >= ?"); $h->execute([date('Y-m-d H:i:s', time() - 30 * 86400)]); foreach ($h->fetchAll(PDO::FETCH_COLUMN) as $p) $have[(string)$p] = 1;
    $q = $pdo->prepare("SELECT c.phone FROM wa_contacts c WHERE c.source IN ('query','campaign') AND c.last_in_at >= ? AND c.optout = 0 AND c.camp_optout = 0 LIMIT 60000"); $q->execute([date('Y-m-d H:i:s', $since)]);
    $cand = []; foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $p) if (!isset($have[(string)$p])) $cand[] = (string)$p;
    foreach (array_chunk($cand, 400) as $ch) {
        $in = implode(',', array_fill(0, count($ch), '?'));
        $f = $pdo->prepare("SELECT phone, MIN(ts) t FROM wa_messages WHERE dir = 'in' AND ts >= ? AND phone IN ($in) GROUP BY phone"); $f->execute(array_merge([date('Y-m-d H:i:s', $since)], $ch)); $first = [];
        foreach ($f->fetchAll() as $r) { $t = (int)strtotime((string)$r['t']); if ($t >= $since) $first[(string)$r['phone']] = $t; }
        if (!$first) continue; $ph = array_keys($first); $in2 = implode(',', array_fill(0, count($ph), '?'));
        $b = $pdo->prepare("SELECT phone, n FROM wa_cust WHERE phone IN ($in2)"); $b->execute($ph); $buy = []; foreach ($b->fetchAll() as $r) $buy[(string)$r['phone']] = (int)$r['n'] > 0;
        $m = $pdo->prepare("SELECT phone, body FROM wa_messages WHERE dir = 'in' AND mtype IN ('text','interactive','button') AND phone IN ($in2) AND ts >= ? ORDER BY id"); $m->execute(array_merge($ph, [date('Y-m-d H:i:s', $since)])); $txt = [];
        foreach ($m->fetchAll() as $r) { $k = (string)$r['phone']; if (!isset($txt[$k]) || count($txt[$k]) < 8) $txt[$k][] = (string)$r['body']; }
        foreach ($first as $phone => $anchor) {
            if (wln_ordered_after($orders, $phone, $anchor)) continue;   // ordered since he wrote
            $topic = wln_classify($txt[$phone] ?? [], !empty($buy[$phone]));
            try { $pdo->prepare("DELETE FROM wa_line_cust WHERE phone = ?")->execute([$phone]);
                  $pdo->prepare("INSERT INTO wa_line_cust (phone, topic, anchor_at, status, created_at) VALUES (?, ?, ?, 'active', ?)")->execute([$phone, $topic, date('Y-m-d H:i:s', $anchor), date('Y-m-d H:i:s')]); } catch (Throwable $e) { continue; }
            wln_plan($pdo, $phone, $topic, $anchor); $n++;
        }
    }
    return $n;
}
/** Why this customer gets nothing now ('' = fine). 'wait…' = try again later; anything else takes him out of the lineup.
 *  Shop rules that already exist are used as they are: an order that just completed, a ticket, a birthday / review thank-you or a person in Rescue mute him (wa_auto_muted);
 *  a person who wrote in the last 36 h or a campaign in the last 2 days steps the plan aside (wq_plan_paused); a follow-up (Autopilot / Journey) in the last day too. */
function wln_block(PDO $pdo, array $c, string $anchor, array $orders = []): string
{
    $phone = (string)$c['phone']; $now = time();
    if ((int)($c['optout'] ?? 0) === 1 || (int)($c['camp_optout'] ?? 0) === 1) return 'opted out';
    if ((int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint') return 'complaint';
    if (wln_ordered_after($orders, $phone, (int)strtotime($anchor))) return 'ordered';
    try { $q = $pdo->prepare("SELECT 1 FROM wa_cust WHERE phone = ? AND n > 0 AND last_order >= ? LIMIT 1"); $q->execute([$phone, $anchor]); if ($q->fetchColumn()) return 'ordered'; } catch (Throwable $e) { }
    try { $q = $pdo->prepare("SELECT MAX(sent_at) FROM wa_line_plan WHERE phone = ? AND status = 'sent'"); $q->execute([$phone]); $last = (string)$q->fetchColumn();
        if ($last !== '' && !empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) > strtotime($last)) return 'replied'; } catch (Throwable $e) { }
    if (function_exists('wa_auto_muted') && wa_auto_muted($pdo, $phone)) return 'wait: muted (order / ticket / thank-you)';
    if (function_exists('wq_plan_paused')) { $z = wq_plan_paused($pdo, $phone, $now); if ($z !== '') return 'wait: ' . $z; }
    if (!empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) > $now - 6 * 3600) return 'wait: chatting now';   // he wrote in the last 6 hours: the chat answers him, not a template
    foreach (['wa_fu_ev', 'wa_wj_ev'] as $t) { try { $q = $pdo->prepare("SELECT 1 FROM $t WHERE phone = ? AND status = 'sent' AND sent_at >= ? LIMIT 1"); $q->execute([$phone, date('Y-m-d H:i:s', $now - 86400)]); if ($q->fetchColumn()) return 'wait: got a follow-up today'; } catch (Throwable $e) { } }
    try { $q = $pdo->prepare("SELECT 1 FROM wa_messages WHERE phone = ? AND dir = 'out' AND mtype = 'template' AND status <> 'failed' AND ts >= ? LIMIT 1"); $q->execute([$phone, date('Y-m-d H:i:s', $now - 20 * 3600)]); if ($q->fetchColumn()) return 'wait: got a template in the last 20 h'; } catch (Throwable $e) { }   // a campaign, the old drip or our own previous step: one in 24 hours
    return '';
}
/** A customer who answered and then went quiet for 24 h is read again: a new topic = a new ladder (what he already got is never repeated; max 2 changes; never beyond 14 days). */
function wln_recheck(PDO $pdo, int $now): int
{
    $q = $pdo->prepare("SELECT * FROM wa_line_cust WHERE status = 'replied' AND recheck_at IS NOT NULL AND recheck_at <= ? LIMIT 200"); $q->execute([date('Y-m-d H:i:s', $now)]); $n = 0;
    foreach ($q->fetchAll() as $r) {
        $phone = (string)$r['phone']; $anchor = (int)strtotime((string)$r['anchor_at']);
        $done = function (string $why) use ($pdo, $phone) { $pdo->prepare("UPDATE wa_line_cust SET status = 'done', left_why = ?, recheck_at = NULL WHERE phone = ?")->execute([$why, $phone]); };
        if ($now > $anchor + 14 * 86400) { $done('14 days over'); continue; }
        $c = function_exists('wa_contact') ? wa_contact($pdo, $phone) : null; if (!$c) { $done('no contact'); continue; }
        if (wln_block($pdo, $c, date('Y-m-d H:i:s', $anchor)) === 'ordered') { $done('ordered'); continue; }
        $o = $pdo->prepare("SELECT n FROM wa_cust WHERE phone = ?"); $o->execute([$phone]); $bought = (int)$o->fetchColumn() > 0;
        $m = $pdo->prepare("SELECT body FROM wa_messages WHERE dir = 'in' AND mtype IN ('text','interactive','button') AND phone = ? AND ts >= ? ORDER BY id DESC LIMIT 8"); $m->execute([$phone, date('Y-m-d H:i:s', $anchor)]);
        $topic = wln_classify($m->fetchAll(PDO::FETCH_COLUMN), $bought); $sw = (int)$r['switches'];
        if ($topic !== (string)$r['topic']) { if ($sw >= 2) { $done('topic changed too often'); continue; } $sw++; wln_log($pdo, $phone, 'topic ' . $r['topic'] . ' -> ' . $topic); }
        $sent = []; $s = $pdo->prepare("SELECT tpl_key FROM wa_line_plan WHERE phone = ? AND status = 'sent'"); $s->execute([$phone]); foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $k) $sent[(string)$k] = 1;
        $pdo->prepare("DELETE FROM wa_line_plan WHERE phone = ? AND status = 'planned'")->execute([$phone]);
        $rows = wln_plan($pdo, $phone, $topic, $now, $sent, $now);
        if ($rows < 1) { $done('all messages sent'); continue; }
        $pdo->prepare("UPDATE wa_line_cust SET topic = ?, status = 'active', switches = ?, recheck_at = NULL WHERE phone = ?")->execute([$topic, $sw, $phone]); $n++;
    }
    return $n;
}
/** THE START (runs by itself on the first engine round after the upload, and from the button): old 7-Day Drip off, every lineup message made and sent to Meta, button cards, everybody since yesterday 10:00 IST read and planned. Safe to repeat.
 *  Nothing goes to a customer here: sending waits until Meta approved all the messages (wln_ready). */
function wln_start_all(PDO $pdo, int $by = 0): array
{
    $c = wln_cfg($pdo); $dripWas = wln_drip_off($pdo); $tp = wln_make_templates($pdo, $by); $kw = wln_install_kw($pdo);
    if ($c['since'] <= 0) $c['since'] = wln_yesterday_start(time()); if ($c['started'] === '') $c['started'] = date('Y-m-d H:i:s'); if ($c['mode'] === 'off') $c['mode'] = 'auto'; wln_cfg_put($pdo, $c);
    $n = wln_enroll($pdo, $c['since']); wa_log($pdo, 'engine', true, 'Lineup started: ' . $n . ' customers planned, ' . $tp['made'] . ' messages made, ' . $tp['sent'] . ' sent to Meta, old drip ' . ($dripWas ? 'switched off' : 'was already off'));
    return ['drip_was_on' => $dripWas, 'templates' => $tp, 'cards' => $kw, 'enrolled' => $n];
}
/** All templates approved? Until then nothing is sent by itself (a step whose message is still waiting for Meta would be jumped over). */
function wln_ready(PDO $pdo): bool { $P = wln_pack(); foreach (wln_tpl_status($pdo) as $k => $s) if (empty($P[$k]['of']) && $s !== 'approved') return false; return true; }   // the extra wordings are optional: one that is not approved is simply not used
/** The engine step. Sends what is due (cap per round), enrols new customers, re-reads the quiet ones. $force = the Send button (ignores the clock window and the "all approved" gate, never the blocks). */
function wln_tick(PDO $pdo, array $opt = []): array
{
    $o = ['entered' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0]; if (!wln_tables($pdo)) return $o; $cfg = wln_cfg($pdo); $force = !empty($opt['now']);
    if ($cfg['mode'] === 'off') return $o;
    if (!$force) { setting_set($pdo, 'wl_tick_at', date('Y-m-d H:i:s')); try { wln_snap($pdo); } catch (Throwable $e) { } }   // the 20-minute status line
    if ($cfg['started'] === '' || $cfg['since'] <= 0) { if (function_exists('wa_lock') && wa_lock($pdo, 'wlboot', 120)) { try { wln_start_all($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'lineup start: ' . $e->getMessage()); } } return $o; }   // first round after the upload: start by itself
    if (!$force && function_exists('wa_lock') && count(wln_tpl_ids($pdo)) < count(wln_pack()) && wa_lock($pdo, 'wlmake', 300)) { try { wln_make_templates($pdo); } catch (Throwable $e) { } }   // a message that did not fit Meta's limit yet: made / sent in a later minute
    if (!$force && (!function_exists('wa_lock') || !wa_lock($pdo, 'wlrun', 50))) return $o;
    $now = time(); [, $h] = wln_ist($now); if (!$force && ($h < $cfg['from'] || $h >= $cfg['to'])) return $o;   // only inside the sending window
    if (!$force && function_exists('wa_lock') && wa_lock($pdo, 'wlenrol', 300)) { try { $o['entered'] = wln_enroll($pdo, $cfg['since']); wln_recheck($pdo, $now); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'lineup enrol: ' . $e->getMessage()); } }
    $ids = wln_tpl_ids($pdo); if (!$ids) return $o; if (!$force && !wln_ready($pdo)) { $o['why'] = 'waiting for Meta to approve every lineup message'; return $o; }
    $cap = $force ? (int)($opt['cap'] ?? 60) : $cfg['cap']; $dl = microtime(true) + 25;
    $q = $pdo->prepare("SELECT p.id, p.phone, p.step, p.tpl_key, p.due_at, c.anchor_at FROM wa_line_plan p JOIN wa_line_cust c ON c.phone = p.phone WHERE p.status = 'planned' AND p.due_at <= ? AND c.status = 'active' ORDER BY p.phone, p.step LIMIT 3000");
    $q->execute([date('Y-m-d H:i:s', $now)]); $rows = $q->fetchAll(); if (!$rows) return $o; $byPhone = []; foreach ($rows as $r) $byPhone[(string)$r['phone']][] = $r; $sent = 0;
    if (function_exists('wq_plan_paused_prefetch')) { try { wq_plan_paused_prefetch($pdo, array_keys($byPhone), $now); } catch (Throwable $e) { } }
    $orders = wln_orders_since($pdo, $now - 16 * 86400); $scores = json_decode((string)setting_get($pdo, 'wl_scores', ''), true); if (!is_array($scores)) $scores = []; $okKeys = array_keys(array_filter(wln_tpl_status($pdo), fn($x) => $x === 'approved'));
    $upd = $pdo->prepare("UPDATE wa_line_plan SET status = ?, why = ?, sent_at = ?, wamid = ?, tpl_key = ? WHERE id = ?");
    foreach ($byPhone as $phone => $list) {
        if ($sent >= $cap || microtime(true) > $dl) { $o['waiting'] += count($list); continue; }
        $row = end($list);
        $c = function_exists('wa_contact') ? wa_contact($pdo, (string)$phone) : null; if (!$c) { $upd->execute(['skipped', 'no contact', null, '', $row['tpl_key'], $row['id']]); $o['skipped']++; continue; }
        $why = wln_block($pdo, $c, (string)$row['anchor_at'], $orders);
        if ($why !== '') {
            if (strpos($why, 'wait') === 0) {
                if ((int)strtotime((string)$row['due_at']) < $now - 30 * 3600) { $upd->execute(['skipped', 'too late (' . mb_substr($why, 6, 80) . ')', null, '', $row['tpl_key'], $row['id']]); $o['skipped']++; } else $o['waiting']++;   // waited more than 30 h past its time: dropped, not sent stale
                continue; }
            if ($why === 'replied') { $pdo->prepare("UPDATE wa_line_plan SET status = 'cancelled', why = 'replied' WHERE phone = ? AND status = 'planned'")->execute([$phone]);
                $pdo->prepare("UPDATE wa_line_cust SET status = 'replied', recheck_at = ? WHERE phone = ?")->execute([date('Y-m-d H:i:s', strtotime((string)($c['last_in_at'] ?? 'now')) + 86400), $phone]); $o['skipped']++; continue; }
            $pdo->prepare("UPDATE wa_line_plan SET status = 'cancelled', why = ? WHERE phone = ? AND status = 'planned'")->execute([$why, $phone]);
            $pdo->prepare("UPDATE wa_line_cust SET status = 'done', left_why = ? WHERE phone = ?")->execute([$why, $phone]); $o['skipped']++; continue;
        }
        $key = wln_pick((string)$row['tpl_key'], (string)$phone, $scores, $okKeys); $tid = (int)($ids[$key] ?? 0); if ($tid <= 0 || !function_exists('wd_send')) { $o['waiting']++; continue; }
        $res = wd_send($pdo, $c, ['tpl_id' => $tid, 'number_id' => 0]);
        if ($res[0]) { foreach (array_slice($list, 0, -1) as $old) $upd->execute(['skipped', 'late (a later step went first)', null, '', $old['tpl_key'], $old['id']]);   // only the latest due step goes, and only once it really went
            $upd->execute(['sent', '', date('Y-m-d H:i:s'), (string)($res[3] ?? ''), $key, $row['id']]); $o['sent']++; $sent++; }
        elseif (stripos((string)$res[1], 'No number has room') === 0 || stripos((string)$res[1], 'The chosen number has no room') === 0) { $o['why'] = (string)$res[1]; $o['waiting']++; break; }   // no number can send now: try again next round, nothing is written
        elseif (stripos((string)$res[1], 'not approved by Meta') !== false) { $o['why'] = (string)$res[1]; $o['waiting']++; }
        else { $upd->execute(['failed', mb_substr((string)$res[1], 0, 190), null, '', $row['tpl_key'], $row['id']]); $o['failed']++; }
    }
    return $o;
}
/** The button cards: a tap on a button answers at once with the FREE message (Button replies, wa_kw.php). Our cards are ln_*; the owner's own cards are never touched. */
function wln_install_kw(PDO $pdo): int
{
    if (!function_exists('wkw_cfg') || !function_exists('wkw_clean')) return 0;
    $cfg = wkw_cfg($pdo); $keep = []; foreach ($cfg['rules'] as $r) if (strpos((string)($r['id'] ?? ''), 'ln_') !== 0) $keep[] = $r;
    foreach (wln_pack() as $k => $m) { $free = $m['free'] === '__OFFER__' ? wln_offer_text($pdo) : $m['free']; $keep[] = ['id' => $k, 'name' => 'Lineup · ' . $k, 'on' => true, 'words' => [$m['button']], 'text' => $free, 'lib_id' => 0, 'tpl_id' => 0, 'fu' => []]; }
    $clean = wkw_clean(['auto' => $cfg['auto'], 'rules' => $keep]); setting_set($pdo, 'wkw_cfg', json_encode($clean, JSON_UNESCAPED_UNICODE)); return count(wln_pack());
}
/** Make every lineup template and send it to Meta (Utility, one name blank, one quick-reply button). Safe to repeat: a template that exists is not made again.
 *  Uses the same Meta budget as everything else (wa_tpl_try_create); what does not fit now waits in the queue and the existing auto-submit sends it in the next minutes. Returns [made, already, failed[]]. */
function wln_make_templates(PDO $pdo, int $by = 0): array
{
    $res = ['made' => 0, 'already' => 0, 'sent' => 0, 'failed' => []];
    foreach (['wa_tpl_clean', 'wa_tpl_insert', 'wa_tpl_row'] as $f) if (!function_exists($f)) { $res['failed'][] = 'The WhatsApp template module is not available.'; return $res; }
    $ids = wln_tpl_ids($pdo); $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 12, 'made' => 0];
    foreach (wln_pack() as $k => $m) {
        if (!empty($ids[$k])) { $res['already']++; continue; }
        [$t, $err] = wa_tpl_clean(['name' => $k, 'language' => 'en', 'category' => 'UTILITY', 'body' => $m['body'], 'footer' => 'TM Perfume House', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => $m['button']]],
            'examples' => ['body' => ['Rahul'], 'labels' => ['Name']], 'folder' => 'Lineup']);
        if ($err !== '') { $res['failed'][] = $k . ': ' . $err; continue; }
        $id = wa_tpl_insert($pdo, $t, $by, 'Lineup follow-up (' . $m['topic'] . ' step ' . $m['step'] . '): answers what the customer asked; the offer goes free after the button.', 'Utility follow-up to a customer who asked.');
        $r = wa_tpl_row($pdo, $id); if (!$r) { $res['failed'][] = $k . ': could not save'; continue; }
        $ids[$k] = (int)$id; setting_set($pdo, 'wl_tpls', json_encode($ids)); $res['made']++;
        foreach ($wabas as $w) {
            if (function_exists('wa_tpl_try_create')) { [$ok, $why] = wa_tpl_try_create($pdo, $r, $w, $budget); } elseif (function_exists('wa_tpl_create_at')) { [$ok, $why] = wa_tpl_create_at($pdo, $r, $w); } else { $ok = false; $why = 'no submit function'; }
            if ($ok) $res['sent']++; else $res['failed'][] = $k . ' → ' . $why;   // "over the limit / no room" = queued, the auto-submit sends it later
        }
    }
    return $res;
}
/** Switch the old 7-Day Drip off (its customers and history stay). */
function wln_drip_off(PDO $pdo): bool
{
    $j = json_decode((string)setting_get($pdo, 'wd_cfg', ''), true); if (!is_array($j)) $j = []; $was = (string)($j['mode'] ?? 'manual'); $j['mode'] = 'off'; $j['on'] = 0; setting_set($pdo, 'wd_cfg', json_encode($j)); return $was !== 'off';
}
/** Template status: [key => 'approved'|'pending'|'rejected'|'missing']. */
function wln_tpl_status(PDO $pdo): array
{
    $ids = wln_tpl_ids($pdo); $out = []; $st = [];
    try { foreach ($pdo->query("SELECT tpl_id, status FROM wa_tpl_waba")->fetchAll() as $r) $st[(int)$r['tpl_id']][] = strtoupper((string)$r['status']); } catch (Throwable $e) { }
    foreach (wln_pack() as $k => $m) { $id = (int)($ids[$k] ?? 0); if ($id <= 0) { $out[$k] = 'missing'; continue; } $s = $st[$id] ?? []; $out[$k] = in_array('APPROVED', $s, true) ? 'approved' : (($s && count(array_diff($s, ['REJECTED'])) === 0) ? 'rejected' : 'pending'); }
    return $out;
}
/** The status the owner asked for, in one block: messages made / sent to Meta / approved / utility / still to go · customers identified · customers who got a message · messages sent / delivered · orders. */
function wln_status(PDO $pdo, bool $withOrders = true): array
{
    $pack = wln_pack(); $ids = wln_tpl_ids($pdo); $st = wln_tpl_status($pdo); $c = array_count_values($st); $made = count($ids); $inMeta = 0; $util = 0; $rel = 0;
    if ($ids) { $in = implode(',', array_map('intval', $ids));
        try { $inMeta = (int)$pdo->query("SELECT COUNT(DISTINCT tpl_id) FROM wa_tpl_waba WHERE tpl_id IN ($in)")->fetchColumn(); } catch (Throwable $e) { }
        try { $r = $pdo->query("SELECT category, relabelled_at FROM wa_templates WHERE id IN ($in)")->fetchAll(); foreach ($r as $x) { if (strtoupper((string)$x['category']) === 'UTILITY') $util++; if (!empty($x['relabelled_at'])) $rel++; } } catch (Throwable $e) { } }
    $o = ['at' => date('Y-m-d H:i:s'), 'started' => wln_cfg($pdo)['started'], 'messages' => ['total' => count($pack), 'made' => $made, 'in_meta' => $inMeta, 'approved' => (int)($c['approved'] ?? 0), 'pending' => (int)($c['pending'] ?? 0), 'rejected' => (int)($c['rejected'] ?? 0), 'not_made' => count($pack) - $made, 'utility' => $util, 'relabelled' => $rel, 'left' => count($pack) - (int)($c['approved'] ?? 0)]];
    $o['customers'] = ['identified' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_cust")->fetchColumn(), 'active' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_cust WHERE status = 'active'")->fetchColumn(), 'replied' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_cust WHERE status = 'replied'")->fetchColumn(), 'left' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_cust WHERE status = 'done'")->fetchColumn(),
        'got_message' => (int)$pdo->query("SELECT COUNT(DISTINCT phone) FROM wa_line_plan WHERE status = 'sent'")->fetchColumn(), 'planned_rows' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan WHERE status = 'planned'")->fetchColumn()];
    $o['sending'] = ['sent' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan WHERE status = 'sent'")->fetchColumn(), 'failed' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan WHERE status = 'failed'")->fetchColumn(), 'delivered' => 0, 'read' => 0, 'buttons' => 0];
    $o['health'] = json_decode((string)setting_get($pdo, 'wl_issues', ''), true) ?: ['at' => '', 'fixed' => [], 'issues' => []];
    $o['orders'] = ['orders' => 0, 'buyers' => 0, 'revenue' => 0];
    if ($withOrders && $o['sending']['sent'] > 0) { try { $r = wln_report($pdo, 30); foreach ($r['steps'] as $x) { $o['sending']['delivered'] += $x['delivered']; $o['sending']['buttons'] += $x['taps']; $o['orders']['orders'] += $x['orders']; $o['orders']['revenue'] += $x['revenue']; } foreach ($r['topics'] as $t) $o['orders']['buyers'] += $t['buyers']; } catch (Throwable $e) { } }
    return $o;
}
/** The self-check, every 10 minutes. Fixes what can be fixed by itself and writes down what cannot: the list is shown on the screen (wln_status → issues).
 *  fixed: old Drip switched on again by someone · button cards missing / changed · messages not made · customers waiting because a message never reached Meta.
 *  reported: rejected messages (text must be changed), Meta turned a message into marketing, many failed sends, nothing sent for hours although due, the engine not running. */
function wln_heal(PDO $pdo): array
{
    $fix = []; $bad = []; $cfg = wln_cfg($pdo); if ($cfg['started'] === '') return ['fixed' => $fix, 'issues' => [['level' => 'info', 'text' => 'The Lineup has not started yet (it starts by itself on the next engine round).']]];
    $dj = json_decode((string)setting_get($pdo, 'wd_cfg', ''), true); if (is_array($dj) && (string)($dj['mode'] ?? '') !== 'off') { wln_drip_off($pdo); $fix[] = 'The old 7-Day Drip was switched on again: switched off.'; }
    if (count(wln_tpl_ids($pdo)) < count(wln_pack())) { $r = wln_make_templates($pdo); if ($r['made']) $fix[] = $r['made'] . ' missing messages made and sent to Meta.'; }
    if (function_exists('wkw_cfg')) { $have = []; foreach (wkw_cfg($pdo)['rules'] as $x) $have[(string)($x['id'] ?? '')] = !empty($x['on']); $miss = 0; foreach (wln_pack() as $k => $m) if (empty($have[$k])) $miss++; if ($miss) { wln_install_kw($pdo); $fix[] = $miss . ' button answers were missing or off: put back.'; } }
    $PK = wln_pack(); foreach (wln_tpl_status($pdo) as $k => $st) if ($st === 'rejected') { $extra = !empty($PK[$k]['of']);
        $bad[] = ['level' => 'warn', 'text' => $k . ' was REJECTED by Meta — change its words in Pre-made messages' . ($extra ? ' (only an extra wording: nothing is blocked)' : ' — until then no message is sent')]; }
    $rel = (int)wln_status($pdo, false)['messages']['relabelled']; if ($rel) $bad[] = ['level' => 'warn', 'text' => $rel . ' lineup message(s) were turned into MARKETING by Meta — they cost more; soften the words'];
    $f = $pdo->prepare("SELECT why, COUNT(*) n FROM wa_line_plan WHERE status = 'failed' AND sent_at IS NULL GROUP BY why ORDER BY n DESC LIMIT 3"); $f->execute(); foreach ($f->fetchAll() as $r) if ((int)$r['n'] >= 5) $bad[] = ['level' => 'warn', 'text' => $r['n'] . ' sends failed: ' . $r['why']];
    $d = $pdo->prepare("SELECT COUNT(*) FROM wa_line_plan p JOIN wa_line_cust c ON c.phone = p.phone WHERE p.status = 'planned' AND c.status = 'active' AND p.due_at <= ?"); $d->execute([date('Y-m-d H:i:s', time() - 3 * 3600)]); $due = (int)$d->fetchColumn();
    if ($due > 0) { $why = wln_ready($pdo) ? 'nobody can send (no number has room, or every customer is waiting for a rule: person chatting / campaign today)' : 'waiting for Meta to approve all the messages'; $bad[] = ['level' => $due > 50 ? 'warn' : 'info', 'text' => $due . ' messages are 3+ hours late: ' . $why]; }
    $last = (int)strtotime((string)setting_get($pdo, 'wl_snap_at', '')); [, $h] = wln_ist(time()); $tk = (int)strtotime((string)setting_get($pdo, 'wl_tick_at', '')); if ($tk > 0 && $tk < time() - 1800 && $h >= $cfg['from'] && $h < $cfg['to']) $bad[] = ['level' => 'warn', 'text' => 'The engine has not run the Lineup for ' . round((time() - $tk) / 60) . ' minutes'];
    setting_set($pdo, 'wl_issues', json_encode(['at' => date('Y-m-d H:i:s'), 'fixed' => $fix, 'issues' => $bad], JSON_UNESCAPED_UNICODE)); if ($fix) wa_log($pdo, 'engine', true, 'Lineup self-check fixed: ' . implode(' | ', $fix));
    return ['fixed' => $fix, 'issues' => $bad];
}
/** The status as one short phone notification (Admin / HR; the same tag replaces the last one, so they do not pile up). */
function wln_push(PDO $pdo, array $st): int
{
    if (!function_exists('ct_push') || !function_exists('wq_admin_ids')) return 0; $c = wln_cfg($pdo); $age = time() - (int)strtotime($c['started']); $bad = 0; foreach (($st['health']['issues'] ?? []) as $x) if (($x['level'] ?? '') === 'warn') $bad++;
    // owner 21 Nov: every 10 minutes at first (the first 6 hours), then every half hour (until 48 hours), then only when the self-check warns
    $gap = $age <= 6 * 3600 ? 9 * 60 : ($age <= 48 * 3600 ? 29 * 60 : ($bad ? 29 * 60 : 0)); if ($gap === 0) return 0;
    if ((int)strtotime((string)setting_get($pdo, 'wl_push_at', '')) > time() - $gap) return 0; setting_set($pdo, 'wl_push_at', date('Y-m-d H:i:s'));
    $M = $st['messages']; $K = $st['customers']; $G = $st['sending']; $O = $st['orders'];
    $body = 'Msgs made ' . $M['made'] . '/' . $M['total'] . ' · in Meta ' . $M['in_meta'] . ' · approved ' . $M['approved'] . ' · utility ' . $M['utility'] . ' · left ' . $M['left'] . "\n" . 'Customers ' . $K['identified'] . ' · got msg ' . $K['got_message'] . ' · sent ' . $G['sent'] . ' · orders ' . $O['orders'] . ' · cost today ₹' . (isset($st['cost_today']) ? $st['cost_today'] : '0') . ($bad ? "\n" . '⚠ ' . $bad . ' issue(s): open Lineup' : '');
    try { return (int)ct_push($pdo, wq_admin_ids($pdo), '🧵 Lineup status', $body, 'campaigns.html', 'hrms-lineup'); } catch (Throwable $e) { return 0; }
}
/** Every 10 minutes the engine writes the status down, so the owner sees the whole day as a table (kept 14 days). */
function wln_snap(PDO $pdo, bool $force = false): bool
{
    $last = (int)strtotime((string)setting_get($pdo, 'wl_snap_at', '')); if (!$force && $last > time() - 10 * 60) return false;
    if (!$force && function_exists('wa_lock') && !wa_lock($pdo, 'wlsnap', 60)) return false; setting_set($pdo, 'wl_snap_at', date('Y-m-d H:i:s'));
    try { $r = wln_report($pdo, 30); $sc = []; foreach ($r['keys'] as $k => $c) $sc[$k] = [(int)$c['delivered'], (float)$c['score']]; setting_set($pdo, 'wl_scores', json_encode($sc)); } catch (Throwable $e) { }   // what the picker (wln_pick) reads: delivered + score of every wording
    try { wln_heal($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'lineup heal: ' . $e->getMessage()); }
    $st = wln_status($pdo); try { [$td0] = wln_ist(time()); $st['cost_today'] = wln_summary($pdo, wln_ist_at($td0, 0))['cost']; } catch (Throwable $e) { }
    $pdo->prepare("INSERT INTO wa_line_snap (at, j) VALUES (?, ?)")->execute([date('Y-m-d H:i:s'), json_encode($st, JSON_UNESCAPED_UNICODE)]);
    try { wln_push($pdo, $st); } catch (Throwable $e) { }
    if (mt_rand(1, 30) === 1) $pdo->prepare("DELETE FROM wa_line_snap WHERE at < ?")->execute([date('Y-m-d H:i:s', time() - 14 * 86400)]); return true;
}
function wln_snaps(PDO $pdo, int $n = 36): array { $o = []; foreach ($pdo->query("SELECT at, j FROM wa_line_snap ORDER BY id DESC LIMIT " . (int)$n)->fetchAll() as $r) { $j = json_decode((string)$r['j'], true); if (is_array($j)) { $j['at'] = (string)$r['at']; $o[] = $j; } } return $o; }
/** The outside look (the card on the Campaigns page): what happened since $from — messages sent / delivered / read / failed, how many people answered (a button tap or any message), orders and revenue credited to the Lineup,
 *  how many went as Utility and how many as plain (marketing) messages, and what they cost (the shop's own rates, wa_team_cfg). */
function wln_summary(PDO $pdo, int $from): array
{
    $o = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'replied' => 0, 'taps' => 0, 'orders' => 0, 'buyers' => 0, 'revenue' => 0, 'utility' => 0, 'plain' => 0, 'cost_utility' => 0.0, 'cost_plain' => 0.0, 'cost' => 0.0, 'rate_u' => 0.0, 'rate_m' => 0.0];
    $tc = function_exists('wa_team_cfg') ? wa_team_cfg($pdo) : ['rate_marketing' => 1.09, 'rate_utility' => 0.15]; $o['rate_u'] = (float)$tc['rate_utility']; $o['rate_m'] = (float)$tc['rate_marketing'];
    $f = date('Y-m-d H:i:s', $from); $ids = wln_tpl_ids($pdo); $util = []; $cat = [];
    if ($ids) { try { foreach ($pdo->query("SELECT id, category, relabelled_at FROM wa_templates WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")")->fetchAll() as $r) $cat[(int)$r['id']] = (strtoupper((string)$r['category']) === 'UTILITY' && empty($r['relabelled_at'])); } catch (Throwable $e) { } }
    foreach ($ids as $k => $id) $util[$k] = !empty($cat[(int)$id]);
    $q = $pdo->prepare("SELECT phone, tpl_key, sent_at, wamid, status FROM wa_line_plan WHERE status IN ('sent','failed') AND COALESCE(sent_at, ?) >= ?"); $q->execute([$f, $f]); $rows = $q->fetchAll(); $sent = [];
    foreach ($rows as $r) { if ($r['status'] === 'failed') { $o['failed']++; continue; } $o['sent']++; if (!empty($util[(string)$r['tpl_key']])) $o['utility']++; else $o['plain']++; $sent[] = $r; }
    $o['cost_utility'] = round($o['utility'] * $o['rate_u'], 2); $o['cost_plain'] = round($o['plain'] * $o['rate_m'], 2); $o['cost'] = round($o['cost_utility'] + $o['cost_plain'], 2);
    $wm = array_filter(array_column($sent, 'wamid')); $st = [];
    foreach (array_chunk($wm, 400) as $ch) { $x = $pdo->prepare("SELECT wamid, status FROM wa_messages WHERE wamid IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $x->execute($ch); foreach ($x->fetchAll() as $r) $st[(string)$r['wamid']] = (string)$r['status']; }
    foreach ($sent as $r) { $x = $st[(string)$r['wamid']] ?? ''; if (in_array($x, ['delivered', 'read'], true)) $o['delivered']++; if ($x === 'read') $o['read']++; }
    $byP = []; foreach ($sent as $r) $byP[(string)$r['phone']][] = (int)strtotime((string)$r['sent_at']);
    if ($byP) { $btn = []; foreach (wln_pack() as $k => $m) $btn[mb_strtolower(trim($m['button']))] = 1; $rep = []; $tap = [];
        foreach (array_chunk(array_keys($byP), 400) as $ch) { $x = $pdo->prepare("SELECT phone, body, mtype, ts FROM wa_messages WHERE dir = 'in' AND ts >= ? AND phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $x->execute(array_merge([$f], $ch));
            foreach ($x->fetchAll() as $r) { $t = (int)strtotime((string)$r['ts']); foreach ($byP[(string)$r['phone']] ?? [] as $st0) if ($t > $st0 && $t <= $st0 + 2 * 86400) { $rep[(string)$r['phone']] = 1; if (isset($btn[mb_strtolower(trim((string)$r['body']))])) $tap[(string)$r['phone'] . '|' . $st0] = 1; break; } } }
        $o['replied'] = count($rep); $o['taps'] = count($tap); }
    $ord = []; try { $x = $pdo->prepare("SELECT phone, created_at, total FROM shop_orders WHERE COALESCE(tags, '') NOT LIKE '%wa-rx%' AND created_at >= ? AND phone IS NOT NULL AND phone != '' LIMIT 200000"); $x->execute([$f]);
        foreach ($x->fetchAll() as $r) { $k = function_exists('wa_key') ? wa_key($r['phone']) : substr(preg_replace('/\D+/', '', (string)$r['phone']), -10); if ($k !== '' && isset($byP[$k])) $ord[$k][] = [(int)strtotime((string)$r['created_at']), (float)$r['total']]; } } catch (Throwable $e) { }
    $buy = []; foreach ($ord as $k => $list) foreach ($list as [$ot, $tot]) foreach ($byP[$k] as $st0) if ($st0 < $ot && $ot <= $st0 + 7 * 86400) { $o['orders']++; $o['revenue'] += (int)round($tot); $buy[$k] = 1; break; }
    $o['buyers'] = count($buy); return $o;
}
/** The picture the owner looks at: customers by topic and status, how many go today / tomorrow (by hour), the templates, the old drip. */
function wln_overview(PDO $pdo): array
{
    $cfg = wln_cfg($pdo); $o = ['cfg' => $cfg, 'topics' => wln_topics(), 'by_topic' => [], 'board' => [], 'tomorrow' => ['total' => 0, 'by_topic' => [], 'by_hour' => []], 'today' => ['total' => 0, 'sent' => 0, 'by_topic' => []], 'left' => [], 'tpl' => [], 'now' => date('Y-m-d H:i')];
    foreach ($pdo->query("SELECT topic, status, COUNT(*) n FROM wa_line_cust GROUP BY topic, status")->fetchAll() as $r) $o['by_topic'][$r['topic']][$r['status']] = (int)$r['n'];
    foreach ($pdo->query("SELECT left_why, COUNT(*) n FROM wa_line_cust WHERE left_why <> '' GROUP BY left_why")->fetchAll() as $r) $o['left'][$r['left_why']] = (int)$r['n'];
    foreach ($pdo->query("SELECT p.topic, p.step, COUNT(*) n FROM wa_line_plan p JOIN wa_line_cust c ON c.phone = p.phone WHERE p.status = 'planned' AND c.status = 'active' GROUP BY p.topic, p.step")->fetchAll() as $r) $o['board'][$r['topic']][(int)$r['step']] = (int)$r['n'];
    [$td] = wln_ist(time()); $tm = date('Y-m-d', strtotime($td . ' +1 day'));
    $q = $pdo->prepare("SELECT p.topic, p.due_at, p.status FROM wa_line_plan p JOIN wa_line_cust c ON c.phone = p.phone WHERE p.due_at >= ? AND p.due_at < ? AND p.status IN ('planned','sent') AND (c.status = 'active' OR p.status = 'sent')");
    $q->execute([date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s', time() + 3 * 86400)]);
    foreach ($q->fetchAll() as $r) { [$d, $hh] = wln_ist((int)strtotime((string)$r['due_at']));
        if ($d === $td) { $o['today']['total']++; if ($r['status'] === 'sent') $o['today']['sent']++; $o['today']['by_topic'][$r['topic']] = ($o['today']['by_topic'][$r['topic']] ?? 0) + 1; }
        elseif ($d === $tm) { $o['tomorrow']['total']++; $o['tomorrow']['by_topic'][$r['topic']] = ($o['tomorrow']['by_topic'][$r['topic']] ?? 0) + 1; $o['tomorrow']['by_hour'][$hh] = ($o['tomorrow']['by_hour'][$hh] ?? 0) + 1; } }
    ksort($o['tomorrow']['by_hour']); $o['status'] = wln_status($pdo); [$td0] = wln_ist(time()); $o['summary'] = ['today' => wln_summary($pdo, wln_ist_at($td0, 0)), 'total' => wln_summary($pdo, (int)strtotime($cfg['started'] ?: 'now') - 60)]; $o['snaps'] = wln_snaps($pdo, 36); $o['tpl'] = wln_tpl_status($pdo); $o['tpl_counts'] = array_count_values($o['tpl']);
    $o['guide'] = ['live' => false, 'excerpt' => '']; try { if (function_exists('wk_guide_text')) { $g = wk_guide_text($pdo, 1500); $o['guide'] = ['live' => $g !== '', 'excerpt' => $g]; } } catch (Throwable $e) { }   // the approved guidebook: the owner reads the lineup messages against it
    $dj = json_decode((string)setting_get($pdo, 'wd_cfg', ''), true); $o['old_drip'] = is_array($dj) ? (string)($dj['mode'] ?? 'manual') : 'manual';
    return $o;
}
/** THE LEARNING: which topic and which step bring ORDERS. An order is credited to the last lineup message the customer got in the 7 days before it (same rule as the campaigns' report).
 *  Per topic × step: sent, delivered, button taps, orders, revenue, orders per 100 delivered. Per topic: customers, buyers (ordered after he came in), conversion %. Rates are shown only from $min delivered / customers (small numbers lie). */
function wln_report(PDO $pdo, int $days = 30, int $min = 30): array
{
    $from = date('Y-m-d H:i:s', time() - $days * 86400); $rows = []; $topics = []; $steps = [];
    $q = $pdo->prepare("SELECT phone, topic, step, tpl_key, sent_at, wamid FROM wa_line_plan WHERE status = 'sent' AND sent_at >= ?"); $q->execute([$from]); $sent = $q->fetchAll();
    $st = []; foreach (array_chunk(array_filter(array_column($sent, 'wamid')), 400) as $ch) { $x = $pdo->prepare("SELECT wamid, status FROM wa_messages WHERE wamid IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $x->execute($ch); foreach ($x->fetchAll() as $r) $st[(string)$r['wamid']] = (string)$r['status']; }
    $pack = wln_pack(); $btn = []; foreach ($pack as $k => $m) $btn[$k] = mb_strtolower(trim($m['button']));
    $tap = []; try { $t = $pdo->prepare("SELECT phone, body, ts FROM wa_messages WHERE dir = 'in' AND mtype IN ('button','interactive') AND ts >= ?"); $t->execute([$from]); foreach ($t->fetchAll() as $r) $tap[(string)$r['phone']][] = [mb_strtolower(trim((string)$r['body'])), (int)strtotime((string)$r['ts'])]; } catch (Throwable $e) { }
    $kc = []; $kcell = function (string $k) use (&$kc) { if (!isset($kc[$k])) $kc[$k] = ['sent' => 0, 'delivered' => 0, 'taps' => 0, 'orders' => 0, 'revenue' => 0]; };
    $ord = []; try { $o = $pdo->prepare("SELECT phone, created_at, total FROM shop_orders WHERE COALESCE(tags, '') NOT LIKE '%wa-rx%' AND created_at >= ? AND phone IS NOT NULL AND phone != '' LIMIT 200000"); $o->execute([$from]);
        foreach ($o->fetchAll() as $r) { $k = function_exists('wa_key') ? wa_key($r['phone']) : substr(preg_replace('/\D+/', '', (string)$r['phone']), -10); if ($k !== '') $ord[$k][] = [(int)strtotime((string)$r['created_at']), (float)$r['total']]; } } catch (Throwable $e) { }
    $byPhone = []; foreach ($sent as $r) { $r['ts'] = (int)strtotime((string)$r['sent_at']); $byPhone[substr(preg_replace('/\D+/', '', (string)$r['phone']), -10)][] = $r; }
    $cell = function (string $t, int $s) use (&$rows) { if (!isset($rows[$t][$s])) $rows[$t][$s] = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'taps' => 0, 'orders' => 0, 'revenue' => 0]; };
    foreach ($sent as $r) { $t = (string)$r['topic']; $s = (int)$r['step']; $cell($t, $s); $rows[$t][$s]['sent']++; $kcell((string)$r['tpl_key']); $kc[(string)$r['tpl_key']]['sent']++; $x = $st[(string)$r['wamid']] ?? '';
        if (in_array($x, ['delivered', 'read'], true)) { $rows[$t][$s]['delivered']++; $kc[(string)$r['tpl_key']]['delivered']++; } if ($x === 'read') $rows[$t][$s]['read']++;
        foreach ($tap[(string)$r['phone']] ?? [] as [$b, $ts]) if ($ts >= (int)strtotime((string)$r['sent_at']) && $ts <= (int)strtotime((string)$r['sent_at']) + 2 * 86400 && $b === ($btn[(string)$r['tpl_key']] ?? '#')) { $rows[$t][$s]['taps']++; $kc[(string)$r['tpl_key']]['taps']++; break; } }
    $credited = [];
    foreach ($byPhone as $k => $list) foreach ($ord[$k] ?? [] as [$ots, $tot]) { $best = null; foreach ($list as $r) if ($r['ts'] < $ots && $ots <= $r['ts'] + 7 * 86400 && ($best === null || $r['ts'] > $best['ts'])) $best = $r;
        if ($best) { $t = (string)$best['topic']; $s = (int)$best['step']; $cell($t, $s); $rows[$t][$s]['orders']++; $rows[$t][$s]['revenue'] += (int)round($tot); $credited[$t][$k] = 1; $kcell((string)$best['tpl_key']); $kc[(string)$best['tpl_key']]['orders']++; $kc[(string)$best['tpl_key']]['revenue'] += (int)round($tot); } }
    foreach ($pdo->query("SELECT topic, COUNT(*) n FROM wa_line_cust GROUP BY topic")->fetchAll() as $r) $topics[$r['topic']] = ['customers' => (int)$r['n'], 'buyers' => count($credited[$r['topic']] ?? []), 'orders' => 0, 'revenue' => 0, 'sent' => 0, 'delivered' => 0];
    foreach ($rows as $t => $ss) foreach ($ss as $s => $c) { $topics[$t] = ($topics[$t] ?? ['customers' => 0, 'buyers' => count($credited[$t] ?? []), 'orders' => 0, 'revenue' => 0, 'sent' => 0, 'delivered' => 0]);
        foreach (['orders', 'revenue', 'sent', 'delivered'] as $f) $topics[$t][$f] += $c[$f]; $c['per100'] = $c['delivered'] >= $min ? round($c['orders'] * 100 / $c['delivered'], 1) : null; $c['tap_pct'] = $c['delivered'] >= $min ? round($c['taps'] * 100 / $c['delivered'], 1) : null; $steps[] = ['topic' => $t, 'step' => $s] + $c; }
    foreach ($topics as $t => &$c) { $c['conv'] = $c['customers'] >= $min ? round($c['buyers'] * 100 / $c['customers'], 1) : null; } unset($c);
    $ranked = array_filter($steps, fn($x) => $x['per100'] !== null); usort($ranked, fn($a, $b) => $b['per100'] <=> $a['per100']);
    $tr = array_filter($topics, fn($x) => $x['conv'] !== null); uasort($tr, fn($a, $b) => $b['conv'] <=> $a['conv']);
    $weak = array_values(array_filter($ranked, fn($x) => $x['orders'] === 0 && $x['delivered'] >= $min * 3));
    foreach ($kc as $k => &$c) { $c['score'] = round(($c['taps'] + 5 * $c['orders'] + 1) / ($c['delivered'] + 20), 4); $c['per100'] = $c['delivered'] >= $min ? round($c['orders'] * 100 / $c['delivered'], 1) : null; $c['tap_pct'] = $c['delivered'] >= $min ? round($c['taps'] * 100 / $c['delivered'], 1) : null; } unset($c);
    return ['days' => $days, 'min' => $min, 'keys' => $kc, 'steps' => $steps, 'topics' => $topics, 'best_topic' => $tr ? array_key_first($tr) : null, 'best_steps' => array_slice(array_values($ranked), 0, 3), 'weak_steps' => array_slice($weak, 0, 5),
        'note' => 'Orders are credited to the last lineup message in the 7 days before the order. A rate shows only after ' . $min . ' delivered / customers — before that it is only a guess.'];
}
/** One customer's whole lineup (for the chat / customer card). */
function wln_customer(PDO $pdo, string $phone): array
{
    $c = $pdo->prepare("SELECT * FROM wa_line_cust WHERE phone = ?"); $c->execute([$phone]); $cu = $c->fetch(); if (!$cu) return ['in' => false];
    $p = $pdo->prepare("SELECT step, tpl_key, due_at, status, why, sent_at FROM wa_line_plan WHERE phone = ? ORDER BY id"); $p->execute([$phone]);
    $l = $pdo->prepare("SELECT at, what FROM wa_line_log WHERE phone = ? ORDER BY id"); $l->execute([$phone]);
    return ['in' => true, 'customer' => $cu, 'plan' => $p->fetchAll(), 'log' => $l->fetchAll()];
}

}

// ---------------------------------------------------------------------------------------------------------------------- the screen's actions
$__wl = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wl, 'wa.line_') === 0) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!wa_is_admin($pdo, $auth)) json_response(['error' => 'Only the owner / admin can set up the Lineup.'], 403);
    if (!wln_tables($pdo)) json_response(['error' => 'Lineup storage is not available.'], 500);
    switch ($__wl) {
        case 'wa.line_get': json_response(['ok' => true] + wln_overview($pdo));
        case 'wa.line_card': { $c = wln_cfg($pdo); [$td0] = wln_ist(time()); $st = wln_status($pdo, false); json_response(['ok' => true, 'started' => $c['started'], 'ready' => wln_ready($pdo), 'status' => $st, 'today' => wln_summary($pdo, wln_ist_at($td0, 0)), 'total' => wln_summary($pdo, (int)strtotime($c['started'] ?: 'now') - 60), 'tomorrow' => wln_overview($pdo)['tomorrow']]); }
        case 'wa.line_report': json_response(['ok' => true] + wln_report($pdo, max(7, min(90, (int)($_GET['days'] ?? 30)))));
        case 'wa.line_customer': json_response(['ok' => true] + wln_customer($pdo, preg_replace('/\D/', '', (string)($_GET['phone'] ?? ''))));
        case 'wa.line_save': {   // POST {mode, cap, offer}
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $c = wln_cfg($pdo);
            if (isset($in['mode']) && in_array((string)$in['mode'], ['off', 'manual', 'auto'], true)) $c['mode'] = (string)$in['mode'];
            if (isset($in['cap'])) $c['cap'] = max(5, min(300, (int)$in['cap'])); if (isset($in['offer'])) $c['offer'] = mb_substr(trim((string)$in['offer']), 0, 900);
            wln_cfg_put($pdo, $c); if (isset($in['offer'])) wln_install_kw($pdo); json_response(['ok' => true, 'cfg' => wln_cfg($pdo)]);
        }
        case 'wa.line_start': {   // POST — the same start the engine does by itself (kept as a button in case the engine has not run yet). Nothing is SENT to a customer here.
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); @set_time_limit(120);
            json_response(['ok' => true] + wln_start_all($pdo, (int)($auth['employee_id'] ?? 0)) + wln_overview($pdo));
        }
        case 'wa.line_run': {   // POST — send what is due now (manual mode), in batches; call again while "waiting" > 0
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if (wln_cfg($pdo)['started'] === '') json_response(['error' => 'The Lineup has not started yet — press Start first.'], 422);
            @set_time_limit(120); json_response(['ok' => true] + wln_tick($pdo, ['now' => 1, 'cap' => 60]));
        }
        default: json_response(['error' => 'Unknown Lineup action.'], 404);
    }
}
