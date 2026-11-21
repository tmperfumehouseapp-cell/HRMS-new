<?php
/**
 * wa_line.php — 🧵 LINEUP: the 30-day follow-up by TOPIC (12 steps on day 1,2,3,4,6,8,10,15,18,22,26,30) (replaces the one-ladder 7-Day Drip).
 *   · A customer who writes to us is read once (his words): TOPIC = pending (address / payment), price, trust (original? COD? reviews), size (ml), reorder (bought before), general.
 *   · Each topic has its own 7 steps (4 topic messages + 3 shared). Every message is a UTILITY template with ONE quick-reply button and NO offer words:
 *     the offer / reviews / video go FREE as the answer to the button (Button replies cards ln_*) inside the customer's 24 hours.
 *   · The whole lineup of a customer is written down at enrolment (wa_line_plan: 7 rows, each with its own due time = his first message + N × 24 h, inside the 10:00-20:00 IST window),
 *     so the owner can SEE it (wa.line_get) and the engine only sends what is due. Order / opt-out / complaint = out. A reply stops the ladder; after 24 quiet hours he is read again:
 *     a NEW topic = a new ladder (messages already sent are never repeated; at most 2 changes, never beyond 14 days).
 *   · Starting it (wa.line_start) switches the old 7-Day Drip off, makes the templates and sends them to Meta (the existing auto-submit keeps retrying), installs the button cards, and enrols everybody since yesterday 10:00 IST.
 *   It is ALWAYS ON (owner, 21 Nov): the first engine round after the upload starts it by itself, the engine sends inside 10:00-20:00 IST once Meta approved ALL the messages; the screen's Send button is the manual second way (works at any hour, batches of 60). PHP 7.4 safe.
 *   Settings: wl_cfg (mode, cap, from, to, since, offer) · wl_tpls (key => template id). Tables: wa_line_cust, wa_line_plan, wa_line_log.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wl_cfg')) {

// ---- pure:begin  (no database here: tests/line_test.php runs this block alone)
function wl_topics(): array
{
    $x = []; foreach (wl_topics_extra_get() as $t) if (!empty($t['key'])) $x[(string)$t['key']] = (string)($t['label'] ?? $t['key']) . ' (new)';
    return $x + ['pending' => 'Address / payment pending', 'price' => 'Price / discount', 'trust' => 'Trust (original, COD, reviews)', 'size' => 'Size / ml', 'reorder' => 'Bought before', 'general' => 'No clear topic', 'campaign' => 'Got a campaign, did not write'];
}
/** Words that say what the customer is worried about, strongest first. Latin (English / Hinglish) and Gujarati / Hindi script. */
function wl_words(): array
{
    $w = [
        'pending' => ['address', 'pincode', 'pin code', 'payment', 'paid', 'upi', 'qr', 'order karna', 'order karo', 'order kar', 'એડ્રેસ', 'પેમેન્ટ', 'ઓર્ડર', 'पता', 'पेमेंट', 'ऑर्डर'],
        'price' => ['price', 'rate', 'kimat', 'kitna', 'kitne', 'kitni', 'kya rate', 'discount', 'costly', 'expensive', 'mehnga', 'mahenga', 'mahnga', 'sasta', 'cheap', 'budget', 'offer', 'ભાવ', 'કિંમત', 'કેટલા', 'સસ્તું', 'મોંઘું', 'कीमत', 'कितना', 'कितने', 'सस्ता', 'महंगा'],
        'trust' => ['original', 'asli', 'real', 'fake', 'genuine', 'review', 'cod', 'cash on delivery', 'trust', 'bharosa', 'vishwas', 'proof', 'guarantee', 'nakli', 'duplicate', 'batch', 'અસલી', 'ઓરિજિનલ', 'નકલી', 'વિશ્વાસ', 'असली', 'ओरिजिनल', 'नकली', 'भरोसा'],
        'size' => ['ml', 'size', 'quantity', 'sample', 'tester', 'bottle', 'ટેસ્ટર', 'સાઇઝ', 'साइज'],
    ];
    $late = []; foreach (wl_topics_extra_get() as $t) { if (empty($t['ready']) || empty($t['key']) || empty($t['words'])) continue; $ws = array_map('strval', (array)$t['words']);
        if (!empty($t['before']) && isset($w[(string)$t['before']])) { $o = []; foreach ($w as $k => $v) { if ($k === (string)$t['before']) $o[(string)$t['key']] = $ws; $o[$k] = $v; } $w = $o; } else $late[(string)$t['key']] = $ws; }   // a theme that beats an older topic ('before'), else new topics come last: only customers with no older topic
    return $w + $late;
}
/** Topic from what the customer wrote. $texts = his messages since he came in; $bought = he has ordered before. */
/** The topic of ONE message ('' = no clear topic). Latin words match as whole words, Gujarati / Hindi script as parts. */
function wl_topic_of(string $text): string
{
    $all = mb_strtolower(trim($text)); if ($all === '') return '';
    $norm = preg_replace('/(?<=\d)(?=\p{L})|(?<=\p{L})(?=\d)/u', ' ', $all); $norm = ' ' . trim((string)preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', (string)$norm)) . ' ';   // "50ml" -> "50 ml"; marks stay (Gujarati / Hindi)
    foreach (wl_words() as $topic => $ws) foreach ($ws as $w) {
        $w = mb_strtolower($w); if (preg_match('/^[a-z ]+$/', $w)) { if (strpos($norm, ' ' . $w . ' ') !== false) return $topic; }   // no "real" inside "really", no "book" inside "facebook"
        elseif (strpos($norm, $w) !== false) return $topic;
    }
    return '';
}
/** The topic of a whole conversation: his messages oldest first. The NEWEST message with a clear topic decides (owner 21 Nov: if his reply changes, the category changes with it); none = bought before -> reorder, else general. */
function wl_classify(array $texts, bool $bought): string
{
    for ($i = count($texts) - 1; $i >= 0; $i--) { $t = wl_topic_of((string)$texts[$i]); if ($t !== '') return $t; }
    return $bought ? 'reorder' : 'general';
}
/** The IST wall clock of a time stamp: [Y-m-d, hour]. */
function wl_ist(int $ts): array { $d = new DateTime('@' . $ts); $d->setTimezone(new DateTimeZone('Asia/Kolkata')); return [$d->format('Y-m-d'), (int)$d->format('G')]; }
function wl_ist_at(string $ymd, int $hour): int { $d = new DateTime($ymd . ' ' . sprintf('%02d', $hour) . ':00:00', new DateTimeZone('Asia/Kolkata')); return $d->getTimestamp(); }
/** When Day N is due: his first message + N × 24 h (his own minute), moved into the sending window (default 10:00-20:00 IST): before it = that morning, after it = next morning. */
function wl_due_ts(int $anchor, int $day, int $from = 10, int $to = 20): int
{
    $t = $anchor + $day * 86400; [$ymd, $h] = wl_ist($t);
    if ($h < $from) return wl_ist_at($ymd, $from);
    if ($h >= $to) return wl_ist_at(date('Y-m-d', strtotime($ymd . ' +1 day')), $from);
    return $t;
}
/** 10:00 IST of the day before today (IST) — the start of "yesterday". */
function wl_yesterday_start(int $now): int { [$ymd] = wl_ist($now); return wl_ist_at(date('Y-m-d', strtotime($ymd . ' -1 day')), 10); }

/** THE MESSAGES. key => [topic, day-in-ladder, body, button, free answer]. Utility only: no offer / discount / sale / free / special in a template (Meta re-labels those to marketing). The offer goes in the FREE answer to the button. */
function wl_pack(): array
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
    // days 15-30 (own messages, utility) and the price / prepaid messages (marketing, 2 blanks: name + the price text worked out from the shop's price chart at send time; used only when Meta approved them, else the plain message of the same step goes)
    $add('ln_all_8', 'all', 8, "{{1}} ji, pehli baar le rahe hain to 20 ml se shuru karna theek rahega. Pasand aaye to bada size baad mein. Kaunsa perfume dekh rahe the?", 'Chhota size dekhna hai', "Zaroor 🙏 Aapko kaunsa perfume pasand hai bata dein, hum 20 ml ke options aur kimat bhej dete hain.");
    $add('ln_all_9', 'all', 9, "{{1}} ji, aapki request abhi bhi hamare paas hai. Perfume ya size mein kuch badalna ho to bata dijiye.", 'Perfume badalna hai', "Ji 🙏 Aapko kaunsa perfume ya size chahiye, yahin likh dein, hum turant dikhate hain.");
    $add('ln_all_10', 'all', 10, "{{1}} ji, order mein address ya payment ki koi dikkat ho to bata dijiye. COD bhi available hai aur hum seedha madad karenge.", 'Madad chahiye', "Zaroor 🙏 Apna naam, poora address aur pincode bhej dein, hum order confirm kar dete hain.");
    $add('ln_all_11', 'all', 11, "{{1}} ji, aapke liye perfume abhi bhi reserve hai. Order karna ho to bata dijiye, hum dispatch ki taiyari kar dete hain.", 'Order karna hai', "Ji 🙏 Perfume ka naam, size aur address bhej dein, hum order confirm karte hain.");
    $add('ln_all_12', 'all', 12, "{{1}} ji, ye hamara aakhri message hai. Jab bhi perfume chahiye ho, yahin likh dijiye, hum madad karenge. Shukriya 🙏", 'Perfume chahiye', "Ji 🙏 Aapko kaunsa perfume chahiye, bata dein, hum turant madad karte hain.");
    $mk = function ($key, $step, $body, $btn, $free) use ($add, &$P) { $add($key, 'all', $step, $body, $btn, $free); $P[$key]['cat'] = 'MARKETING'; $P[$key]['pref'] = true; $P[$key]['vars'] = ($key === 'ln_pp_6') ? 1 : 2; };
    $px_free = "Ji 🙏 Aapko kaunse perfume chahiye aur kitni bottle? Bata dein, hum isi bhav mein total confirm kar dete hain.";
    $mk('ln_px_5', 5, "Hi {{1}} ji 🙏 Aapke liye {{2}} mein ho sakta hai. Kaunse perfume lene hain, bata dein to hum book kar dete hain.", 'Book karna hai', $px_free);
    $mk('ln_px_7', 7, "{{1}} ji, abhi aapke liye ye rate hai: {{2}}. Perfume chun lein to hum aaj hi dispatch ki taiyari karte hain.", 'Perfume chunna hai', $px_free);
    $mk('ln_px_9', 9, "{{1}} ji, aapke liye khaas rate: {{2}}. Aapko kaunse perfume chahiye, bata dijiye.", 'Rate ok hai', $px_free);
    $mk('ln_px_11', 11, "{{1}} ji, aakhri mauka: {{2}}. Order karna ho to abhi bata dein, hum confirm kar dete hain.", 'Aaj hi order karein', $px_free);
    // the second wording of every price step: the same price, a warmer reason to decide now (no made-up scarcity or deadlines) — the picker splits them, the report counts orders per wording, the stop-loss keeps the better one
    $mv = function ($key, $of, $step, $body, $btn) use (&$P, $px_free) { $P[$key] = ['topic' => 'all', 'step' => $step, 'body' => $body, 'button' => $btn, 'free' => $px_free, 'of' => $of, 'cat' => 'MARKETING', 'pref' => true, 'vars' => 2]; };
    $mv('ln_px_5b', 'ln_px_5', 5, "Hi {{1}} ji 🙏 Hamare popular perfumes aajkal bahut pasand kiye ja rahe hain. Aapke liye {{2}} mein ho sakta hai. Kaunsa chahiye, bata dein.", 'Popular dekhna hai');
    $mv('ln_px_7b', 'ln_px_7', 7, "Hi {{1}} ji, aapki pasand ke perfume ke liye ye rate hai: {{2}}. Aaj order karenge to hum dispatch ki taiyari shuru kar dete hain.", 'Abhi book karein');
    $mv('ln_px_9b', 'ln_px_9', 9, "Hi {{1}} ji, aapke liye ek aur rate: {{2}}. Pasand ka perfume chun lein, hum aapki madad karenge.", 'Perfume chun leta hoon');
    $mv('ln_px_11b', 'ln_px_11', 11, "Hi {{1}} ji, ye aapke liye hamara sabse achha rate hai: {{2}}. Order karna ho to abhi bata dein, hum confirm kar dete hain.", 'Mujhe yahi chahiye');
    $mk('ln_pp_6', 6, "{{1}} ji, prepaid karne par cart par 2% extra chhoot milti hai. Perfume aur bottle count bata dein to total bata dete hain.", 'Total bataiye', "Zaroor 🙏 Aapko kaunse perfume aur kitni bottle chahiye, bata dein, hum prepaid ke saath total bhej dete hain.");
    foreach (wl_extra_get() as $e) { if (!empty($e['newtopic']) && !empty($e['key']) && !isset($P[$e['key']])) { $P[$e['key']] = ['topic' => (string)$e['topic'], 'step' => (int)$e['step'], 'body' => (string)$e['body'], 'button' => (string)$e['button'], 'free' => (string)$e['free'], 'auto' => 1, 'newtopic' => 1]; continue; } if (empty($e['key']) || isset($P[$e['key']]) || empty($e['of']) || !isset($P[$e['of']])) continue; $P[$e['key']] = ['topic' => (string)$e['topic'], 'step' => (int)$e['step'], 'body' => (string)$e['body'], 'button' => (string)$e['button'], 'free' => (string)$e['free'], 'of' => (string)$e['of'], 'auto' => 1]; }
    return $P;
}
/** The wordings of one message: the message itself and its alternatives (key => the keys that can be sent instead). */
function wl_variants(string $key): array { $o = [$key]; foreach (wl_pack() as $k => $m) if (($m['of'] ?? '') === $key) $o[] = $k; return $o; }
/** Which wording goes to this customer. $score = [key => [delivered, score]] (what the last 30 days showed), $ok = keys that Meta approved. Below 40 delivered per wording: an even split (he is placed by his number, so the same customer always gets the same). Then 80% the wording with the best score, 20% the others (keeps learning). */
function wl_pick(string $key, string $phone, array $score, array $ok): string
{
    $v = array_values(array_filter(wl_variants($key), fn($k) => in_array($k, $ok, true))); if (count($v) < 2) return $v ? $v[0] : $key;
    // STOP-LOSS (owner, 21 Nov): a wording that got NO order in 150+ delivered while the best wording of the same step got 3+ is not used any more; one that is clearly weaker (less than a third of the best's orders per delivered, 150+ delivered) gets only the small test share. Fewer than 2 wordings are never reduced.
    $best = null; foreach ($v as $k) if ($best === null || ($score[$k][1] ?? 0) > ($score[$best][1] ?? 0)) $best = $k;
    if ($best !== null && count($v) > 1 && ($score[$best][2] ?? 0) >= 3) { $keep = []; foreach ($v as $k) { $d0 = (int)($score[$k][0] ?? 0); $o0 = (int)($score[$k][2] ?? 0); if ($k !== $best && $d0 >= 150 && $o0 === 0) continue; $keep[] = $k; } if ($keep) $v = $keep; if (count($v) < 2) return $v[0]; }
    $h = (int)(abs(crc32($phone . '|' . $key)) % 100); $young = false; foreach ($v as $k) if (($score[$k][0] ?? 0) < 40) $young = true;
    if ($young) return $v[$h % count($v)];
    usort($v, fn($a, $b) => ($score[$b][1] ?? 0) <=> ($score[$a][1] ?? 0)); if ($h < 80) return $v[0];
    return $v[1 + ($h % (count($v) - 1))];
}
/** THE 30-DAY LADDER (owner, 21 Nov): twelve steps, on day 1, 2, 3, 4, 6, 8, 10, 15, 18, 22, 26, 30 after the end of his conversation. Step 1 = 24 h after it; every later step = the gap below after the message before it really went. */
function wl_days(?array $set = null): array { static $o = null; if ($set !== null) $o = $set; return $o ?? [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 6, 6 => 8, 7 => 10, 8 => 15, 9 => 18, 10 => 22, 11 => 26, 12 => 30]; }
/** The day of every step can be changed in the setting wl_days (JSON list of 12 growing numbers, day 1 first, last day at most 60) — the learning report suggests changes; nothing is moved by itself without enough numbers. */
function wl_extra_load(PDO $pdo): void { $j = json_decode((string)setting_get($pdo, 'wl_extra', ''), true); wl_extra_get(is_array($j) ? $j : []); $t = json_decode((string)setting_get($pdo, 'wl_topics_extra', ''), true); wl_topics_extra_get(is_array($t) ? $t : []); }
function wl_days_load(PDO $pdo): void { $j = json_decode((string)setting_get($pdo, 'wl_days', ''), true); if (is_array($j) && count($j) === 12) { $d = []; $prev = 0; foreach (array_values($j) as $i => $v) { $v = (int)$v; if ($v <= $prev || $v > 60) { $d = []; break; } $d[$i + 1] = $v; $prev = $v; } if (count($d) === 12) wl_days($d); } }
function wl_steps(): int { return 12; }
/** The wordings the learning wrote by itself (setting wl_extra; loaded by wl_cfg): [{key, of, topic, step, body, button, free}] — they join wl_pack() as wordings of an existing message and are used by the picker once Meta approved them everywhere. */
function wl_extra_get(?array $set = null): array { static $o = []; if ($set !== null) $o = $set; return $o; }
/** NEW TOPICS found by the learning (setting wl_topics_extra): [{key, label, words[], ready}] — a topic takes part in the classification only when `ready` (its 4 messages are approved on 3+ accounts); until then its customers get the 'general' ladder. */
function wl_topics_extra_get(?array $set = null): array { static $t = []; if ($set !== null) $t = $set; return $t; }
/** Days between step N-1 and step N (step 1 = 1: 24 h after the conversation). */
function wl_gap(int $step): int { $d = wl_days(); return max(1, ($d[$step] ?? 30) - ($d[$step - 1] ?? 0)); }
/** Days from step $from (already sent / the first one planned) up to step $to. */
function wl_gap_sum(int $from, int $to): int { $n = 0; for ($i = $from + 1; $i <= $to; $i++) $n += wl_gap($i); return $n; }
/** The template key of step N (1..12) for a topic. */
function wl_key(string $topic, int $step): string { return $step >= 5 ? 'ln_all_' . $step : 'ln_' . $topic . '_' . $step; }
/** The PRICE / PREPAID message that goes instead of the plain one of this step when Meta approved it everywhere ('' = none): days 6, 10, 18, 26 carry a price, day 8 the 2% prepaid. */
function wln_pref_key(int $step): string { return [5 => 'ln_px_5', 6 => 'ln_pp_6', 7 => 'ln_px_7', 9 => 'ln_px_9', 11 => 'ln_px_11'][$step] ?? ''; }
/** Words the templates must never contain (Meta would re-label them as marketing). */
function wl_banned(): array { return ['offer', 'discount', 'sale', 'free', 'special', 'khaas', 'chhoot', 'cashback', '%', '₹', 'off ']; }
// ---- pure:end

function wl_cfg(PDO $pdo): array
{
    wl_days_load($pdo); wl_extra_load($pdo); $j = json_decode((string)setting_get($pdo, 'wl_cfg', ''), true); if (!is_array($j)) $j = [];
    $mode = (string)($j['mode'] ?? 'auto'); if (!in_array($mode, ['off', 'manual', 'auto'], true)) $mode = 'auto';   // owner 21 Nov: always on (no switch on the screen); 'off' stays only as an emergency value the API accepts
    // owner 21 Nov: no clock window of its own (the 10-20 one is gone; Module A / B's times do not apply): a step goes when its customer is ready; 'win' = 1 brings a from / to window back
    return ['shards' => max(1, min(8, (int)($j['shards'] ?? 8))), 'mode' => $mode, 'cap' => max(5, min(300, (int)($j['cap'] ?? 60))), 'from' => !empty($j['win']) ? max(0, min(23, (int)($j['from'] ?? 0))) : 0, 'to' => !empty($j['win']) ? max(1, min(24, (int)($j['to'] ?? 24))) : 24, 'win' => !empty($j['win']) ? 1 : 0, 'since' => (int)($j['since'] ?? 0),
        'offer' => (string)($j['offer'] ?? ''), 'started' => (string)($j['started'] ?? ''),
        'camp' => in_array((string)($j['camp'] ?? 'off'), ['off', 'read', 'delivered'], true) ? (string)($j['camp'] ?? 'off') : 'off', 'camp_cap' => max(0, min(20000, (int)($j['camp_cap'] ?? 2000)))];   // campaign recipients: who joins (read = opened the campaign message) and at most how many new ones a day
}
function wl_cfg_put(PDO $pdo, array $c): void { setting_set($pdo, 'wl_cfg', json_encode($c, JSON_UNESCAPED_UNICODE)); }
function wl_offer_text(PDO $pdo): string { $o = trim(wl_cfg($pdo)['offer']); return $o !== '' ? $o : "Aapke liye final details ye hain 🙏 Aapko kaunsa perfume chahiye, bata dein to hum is hafte ki sabse achhi kimat bata dete hain."; }
function wln_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_line_cust (phone VARCHAR(12) NOT NULL PRIMARY KEY, topic VARCHAR(12) NOT NULL DEFAULT 'general', anchor_at DATETIME NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'active', left_why VARCHAR(40) NOT NULL DEFAULT '', switches TINYINT NOT NULL DEFAULT 0, recheck_at DATETIME NULL, created_at DATETIME NOT NULL, KEY k_status (status, recheck_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_line_plan (id BIGINT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, topic VARCHAR(12) NOT NULL, step TINYINT NOT NULL, tpl_key VARCHAR(20) NOT NULL, tpl_id INT NOT NULL DEFAULT 0, due_at DATETIME NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'planned', why VARCHAR(200) NOT NULL DEFAULT '', sent_at DATETIME NULL, wamid VARCHAR(80) NOT NULL DEFAULT '', KEY k_due (status, due_at), KEY k_phone (phone, step)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_line_snap (id BIGINT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, j TEXT NOT NULL, KEY k_at (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_line_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, at DATETIME NOT NULL, what VARCHAR(200) NOT NULL DEFAULT '', KEY k_phone (phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->query("SELECT tpl_id FROM wa_line_plan LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE wa_line_plan ADD COLUMN tpl_id INT NOT NULL DEFAULT 0"); } catch (Throwable $e2) { } }   // the table made before this column existed
        try { $pdo->query("SELECT px FROM wa_line_plan LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE wa_line_plan ADD COLUMN px VARCHAR(8) NOT NULL DEFAULT ''"); } catch (Throwable $e2) { } }   // the price column (%) a price message was worked out at
        $ok = true;
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
function wl_tpl_ids(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'wl_tpls', ''), true); return is_array($j) ? array_map('intval', $j) : []; }
/** THE SHOP'S OWN APPROVED MESSAGES (owner, 21 Nov: "new utility messages cannot be made — use the many messages we already have"). Meta's weekly limit stopped new utility messages on 4 of 6 accounts and re-labelled the first lineup message as marketing,
 *  so every step is now served by a message that is already approved on (almost) every account. Picked from the server's own list (approved, text only, at most one blank = the name, no date words like Sunday / Diwali / Today). No step repeats a message within one ladder.
 *  #386 is utility (cheap); the others are marketing (the shop's rate). The setting wl_map (JSON key => template id, {} = use only the lineup's own messages) overrides this. */
function wln_existing_map(): array
{
    $m = [];
    $by = ['trust' => [386, 336, 31, 3196], 'price' => [386, 2612, 32, 277], 'pending' => [60, 155, 153, 3196], 'size' => [386, 278, 2612, 32], 'reorder' => [288, 395, 277, 386], 'general' => [386, 336, 2612, 32]];
    foreach ($by as $t => $ids) foreach ($ids as $i => $id) $m['ln_' . $t . '_' . ($i + 1)] = $id;
    foreach ([388, 336, 277, 32] as $i => $id) $m['ln_campaign_' . ($i + 1)] = $id;   // for people who got a campaign and did not write: no pretext of an enquiry
    $m['ln_all_5'] = 42; $m['ln_all_6'] = 23; $m['ln_all_7'] = 109;
    return $m;
}
function wln_map(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'wl_map', ''), true); return is_array($j) ? array_map('intval', $j) : wln_existing_map(); }
/** key => the template id that really goes out: the shop's approved message where one is mapped, else the lineup's own. */
function wln_eff_ids(PDO $pdo): array
{
    $own = wl_tpl_ids($pdo); $ids = $own; $cand = []; foreach (wln_map($pdo) as $k => $id) if ($id > 0) { $ids[$k] = $id; if (!empty($own[$k]) && (int)$own[$k] !== (int)$id) $cand[$k] = [(int)$id, (int)$own[$k]]; }
    // UTILITY FIRST (owner, 21 Nov: "the idea is done"): where the shop's mapped message is counted as MARKETING by Meta (₹1.09 and ~10% fail with "too many marketing messages") and the Lineup's own message of the same step is UTILITY and approved on 3+ accounts, the own one goes. Marketing stays for the price steps only.
    if ($cand) { try { $all = []; foreach ($cand as [$m, $w]) { $all[$m] = 1; $all[$w] = 1; } $in = implode(',', array_map('intval', array_keys($all))); $cat = []; $ok = [];
            foreach ($pdo->query("SELECT id, category, relabelled_at FROM wa_templates WHERE id IN ($in)")->fetchAll() as $r) $cat[(int)$r['id']] = (strtoupper((string)$r['category']) === 'UTILITY' && empty($r['relabelled_at']));
            foreach ($pdo->query("SELECT tpl_id, SUM(UPPER(status) = 'APPROVED') n FROM wa_tpl_waba WHERE tpl_id IN ($in) GROUP BY tpl_id")->fetchAll() as $r) $ok[(int)$r['tpl_id']] = (int)$r['n'];
            foreach ($cand as $k => [$m, $w]) if (empty($cat[$m]) && !empty($cat[$w]) && ($ok[$w] ?? 0) >= 3) $ids[$k] = $w; } catch (Throwable $e) { } }
    return $ids;
}
function wl_log(PDO $pdo, string $phone, string $what): void { try { $pdo->prepare("INSERT INTO wa_line_log (phone, at, what) VALUES (?, ?, ?)")->execute([$phone, date('Y-m-d H:i:s'), mb_substr($what, 0, 200)]); } catch (Throwable $e) { } }

/** Write the lineup of a customer: one row per step still to go. $skipKeys = messages he already got (never sent again). Returns the number of rows. */
function wln_plan(PDO $pdo, string $phone, string $topic, int $anchor, array $skipKeys = [], int $now = 0): int
{
    $cfg = wl_cfg($pdo); $now = $now ?: time(); $ins = $pdo->prepare("INSERT INTO wa_line_plan (phone, topic, step, tpl_key, due_at) VALUES (?, ?, ?, ?, ?)"); $n = 0; $day = 0; $last = 0;
    for ($s = 1; $s <= wl_steps(); $s++) {
        $k = wl_key($topic, $s); if (isset($skipKeys[$k]) || isset($skipKeys[wln_pref_key($s)])) continue; $day = $last === 0 ? 1 : $day + wl_gap_sum($last, $s); $last = $s;   // the first step still to go = 24 h after the end of his conversation, the rest by the 30-day gaps
        $due = wl_due_ts($anchor, $day, $cfg['from'], $cfg['to']); if ($due < $now - 86400 * 2) $due = wl_due_ts($now, 0, $cfg['from'], $cfg['to']);
        $ins->execute([$phone, $topic, $s, $k, date('Y-m-d H:i:s', $due)]); $n++;
    }
    return $n;
}
/** Orders placed since $from, as [last-10-digits => [timestamps]] — ONE query for a whole round (the wa_cust table is only refreshed now and then, so it can miss an order from the last hours). */
function wl_orders_since(PDO $pdo, int $from): array
{
    $o = []; try { $q = $pdo->prepare("SELECT phone, created_at FROM shop_orders WHERE COALESCE(tags, '') NOT LIKE '%wa-rx%' AND created_at >= ? AND phone IS NOT NULL AND phone != '' LIMIT 200000"); $q->execute([date('Y-m-d H:i:s', $from)]);
        foreach ($q->fetchAll() as $r) { $k = function_exists('wa_key') ? wa_key($r['phone']) : substr(preg_replace('/\D+/', '', (string)$r['phone']), -10); if ($k !== '') $o[$k][] = (int)strtotime((string)$r['created_at']); } } catch (Throwable $e) { }
    return $o;
}
function wl_ordered_after(array $orders, string $phone, int $t): bool { foreach ($orders[substr(preg_replace('/\D+/', '', $phone), -10)] ?? [] as $x) if ($x >= $t) return true; return false; }
/** Who comes in: every customer who wrote to us (a new query or a REPLY to a campaign, a button tap too) at or after $since, however old the contact is — his FIRST message in that window starts his Day 0.
 *  One lineup per customer per 30 days (an old finished one is replaced). Already ordered since he wrote = not enrolled. Each is read once (topic) and gets his 7 rows. */
function wl_enroll(PDO $pdo, int $since): int
{
    if ($since <= 0) return 0; $n = 0; $orders = wl_orders_since($pdo, $since);
    $have = []; $h = $pdo->prepare("SELECT phone FROM wa_line_cust WHERE created_at >= ?"); $h->execute([date('Y-m-d H:i:s', time() - 30 * 86400)]); foreach ($h->fetchAll(PDO::FETCH_COLUMN) as $p) $have[(string)$p] = 1;
    $q = $pdo->prepare("SELECT c.phone FROM wa_contacts c WHERE c.last_in_at >= ? AND c.optout = 0 AND c.camp_optout = 0 LIMIT 60000"); $q->execute([date('Y-m-d H:i:s', $since)]);
    $cand = []; foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $p) if (!isset($have[(string)$p])) $cand[] = (string)$p;
    $cand = array_slice($cand, 0, 3000);   // the rest comes in the next rounds
    foreach (array_chunk($cand, 400) as $ch) {
        $in = implode(',', array_fill(0, count($ch), '?'));
        $f = $pdo->prepare("SELECT phone, MIN(ts) t FROM wa_messages WHERE dir = 'in' AND ts >= ? AND phone IN ($in) GROUP BY phone"); $f->execute(array_merge([date('Y-m-d H:i:s', $since)], $ch)); $first = [];
        foreach ($f->fetchAll() as $r) { $t = (int)strtotime((string)$r['t']); if ($t >= $since) $first[(string)$r['phone']] = $t; }
        if (!$first) continue; $ph = array_keys($first); $in2 = implode(',', array_fill(0, count($ph), '?'));
        $b = $pdo->prepare("SELECT phone, n FROM wa_cust WHERE phone IN ($in2)"); $b->execute($ph); $buy = []; foreach ($b->fetchAll() as $r) $buy[(string)$r['phone']] = (int)$r['n'] > 0;
        $m = $pdo->prepare("SELECT phone, body FROM wa_messages WHERE dir = 'in' AND mtype IN ('text','interactive','button') AND phone IN ($in2) AND ts >= ? ORDER BY id"); $m->execute(array_merge($ph, [date('Y-m-d H:i:s', $since)])); $txt = [];
        foreach ($m->fetchAll() as $r) { $k = (string)$r['phone']; $txt[$k][] = (string)$r['body']; if (count($txt[$k]) > 12) array_shift($txt[$k]); }   // the latest 12, oldest first
        foreach ($first as $phone => $anchor) {
            if (wl_ordered_after($orders, $phone, $anchor)) continue;   // ordered since he wrote
            $topic = wl_classify($txt[$phone] ?? [], !empty($buy[$phone]));
            try { $pdo->prepare("DELETE FROM wa_line_cust WHERE phone = ?")->execute([$phone]);
                  $pdo->prepare("INSERT INTO wa_line_cust (phone, topic, anchor_at, status, created_at) VALUES (?, ?, ?, 'active', ?)")->execute([$phone, $topic, date('Y-m-d H:i:s', $anchor), date('Y-m-d H:i:s')]); } catch (Throwable $e) { continue; }
            wln_plan($pdo, $phone, $topic, $anchor); $n++;
        }
    }
    return $n;
}
/** The campaign ladder is switched off (default): whoever was put on it is taken off again, so nobody who only opened a campaign gets a follow-up. Cheap: does nothing when there is nobody. */
function wln_camp_cleanup(PDO $pdo): int
{
    if (wl_cfg($pdo)['camp'] !== 'off') return 0; $n = (int)$pdo->query("SELECT COUNT(*) FROM wa_line_cust WHERE topic = 'campaign' AND status = 'active'")->fetchColumn(); if ($n <= 0) return 0;
    $pdo->exec("UPDATE wa_line_plan SET status = 'cancelled', why = 'campaign list is off' WHERE status = 'planned' AND phone IN (SELECT phone FROM wa_line_cust WHERE topic = 'campaign' AND status = 'active')");
    $pdo->exec("UPDATE wa_line_cust SET status = 'done', left_why = 'campaign list is off' WHERE topic = 'campaign' AND status = 'active'"); return $n;
}
/** Campaign recipients (owner, 21 Nov): customers a campaign message was SENT to since the Lineup's start who opened it (read; or delivered, setting wl_cfg.camp) and have not written, ordered or opted out.
 *  They get the 'campaign' ladder (the shop's own approved messages, no pretext of an enquiry), starting 24 h after the campaign message. At most camp_cap new ones a day (default 2,000), oldest first, so a 60,000-person campaign day never becomes 400,000 follow-ups. */
function wln_enroll_camp(PDO $pdo, int $since): int
{
    $c = wl_cfg($pdo); if ($c['camp'] === 'off' || $c['camp_cap'] <= 0 || $since <= 0) return 0;
    [$td] = wl_ist(time()); $key = (string)setting_get($pdo, 'wl_camp_day', ''); $done = 0; if (strpos($key, $td . '|') === 0) $done = (int)substr($key, strlen($td) + 1);
    $room = $c['camp_cap'] - $done; if ($room <= 0) return 0;
    $st = $c['camp'] === 'delivered' ? "'read','delivered'" : "'read'"; $orders = wl_orders_since($pdo, $since); $n = 0;
    $have = []; $h = $pdo->prepare("SELECT phone FROM wa_line_cust WHERE created_at >= ?"); $h->execute([date('Y-m-d H:i:s', time() - 30 * 86400)]); foreach ($h->fetchAll(PDO::FETCH_COLUMN) as $p) $have[(string)$p] = 1;
    $q = $pdo->prepare("SELECT phone, MIN(sent_at) t FROM wa_camp_items WHERE status = 'sent' AND sent_at >= ? AND last_status IN ($st) GROUP BY phone ORDER BY t LIMIT " . (int)min(20000, $room * 3));
    $q->execute([date('Y-m-d H:i:s', $since)]); $cand = []; foreach ($q->fetchAll() as $r) if (!isset($have[(string)$r['phone']])) $cand[(string)$r['phone']] = (int)strtotime((string)$r['t']);
    foreach (array_chunk(array_keys($cand), 400) as $ch) {
        $in = implode(',', array_fill(0, count($ch), '?')); $bad = [];
        $x = $pdo->prepare("SELECT phone FROM wa_contacts WHERE phone IN ($in) AND (optout = 1 OR camp_optout = 1 OR (last_in_at IS NOT NULL AND last_in_at >= ?))"); $x->execute(array_merge($ch, [date('Y-m-d H:i:s', $since)])); foreach ($x->fetchAll(PDO::FETCH_COLUMN) as $p) $bad[(string)$p] = 1;   // opted out, or he wrote (the normal path enrols him with his own topic)
        foreach ($ch as $phone) {
            if ($n >= $room) break 2; if (isset($bad[$phone]) || wl_ordered_after($orders, $phone, $cand[$phone])) continue;
            try { $pdo->prepare("DELETE FROM wa_line_cust WHERE phone = ?")->execute([$phone]);
                  $pdo->prepare("INSERT INTO wa_line_cust (phone, topic, anchor_at, status, created_at) VALUES (?, 'campaign', ?, 'active', ?)")->execute([$phone, date('Y-m-d H:i:s', $cand[$phone]), date('Y-m-d H:i:s')]); } catch (Throwable $e) { continue; }
            wln_plan($pdo, $phone, 'campaign', $cand[$phone]); $n++;
        }
    }
    if ($n > 0) setting_set($pdo, 'wl_camp_day', $td . '|' . ($done + $n)); return $n;
}
/** Why this customer gets nothing now ('' = fine). 'wait…' = try again later; anything else takes him out of the lineup.
 *  Shop rules that already exist are used as they are: an order that just completed, a ticket, a birthday / review thank-you or a person in Rescue mute him (wa_auto_muted);
 *  a person who wrote in the last 36 h or a campaign in the last 2 days steps the plan aside (wq_plan_paused); a follow-up (Autopilot / Journey) in the last day too. */
function wl_block(PDO $pdo, array $c, string $anchor, array $orders = [], bool $first = false, &$until = null): string
{
    $phone = (string)$c['phone']; $now = time();
    if ((int)($c['optout'] ?? 0) === 1 || (int)($c['camp_optout'] ?? 0) === 1) return 'opted out';
    if ((int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint') return 'complaint';
    if (wl_ordered_after($orders, $phone, (int)strtotime($anchor))) return 'ordered';
    try { $q = $pdo->prepare("SELECT 1 FROM wa_cust WHERE phone = ? AND n > 0 AND last_order >= ? LIMIT 1"); $q->execute([$phone, $anchor]); if ($q->fetchColumn()) return 'ordered'; } catch (Throwable $e) { }
    try { $q = $pdo->prepare("SELECT MAX(sent_at) FROM wa_line_plan WHERE phone = ? AND status = 'sent'"); $q->execute([$phone]); $last = (string)$q->fetchColumn();
        if ($last !== '' && !empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) > strtotime($last)) return 'replied'; } catch (Throwable $e) { }
    if (function_exists('wa_auto_muted') && wa_auto_muted($pdo, $phone)) { $until = $now + 3600; return 'wait: muted (order / ticket / thank-you)'; }
    // the conversation ends with the last message of EITHER side: a PERSON (salesperson) who wrote in the last 24 hours is still working him: wait (24 h like the customer's own last message). Campaigns (Module A / B) and templates do NOT hold the Lineup any more (owner 21 Nov: it runs as its own module)
    try { $q = $pdo->prepare("SELECT MAX(ts) FROM wa_messages WHERE phone = ? AND dir = 'out' AND who IN ('emp','person','phone') AND status <> 'failed' AND ts >= ?"); $q->execute([$phone, date('Y-m-d H:i:s', $now - 24 * 3600)]); $pt = (string)$q->fetchColumn(); if ($pt !== '') { $until = (int)strtotime($pt) + 24 * 3600; return 'wait: a person wrote in the last 24 h'; } } catch (Throwable $e) { }
    if ($first && !empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) > $now - 24 * 3600) { $until = (int)strtotime((string)$c['last_in_at']) + 24 * 3600; return 'wait: conversation not over'; }   // owner 21 Nov: the 24 hours count from the END of the conversation (his last message); only then, with no order, the lineup starts
    if (!$first && !empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) > $now - 6 * 3600) { $until = (int)strtotime((string)$c['last_in_at']) + 6 * 3600; return 'wait: chatting now'; }   // he wrote in the last 6 hours: the chat answers him, not a template
    foreach (['wa_fu_ev', 'wa_wj_ev'] as $t) { try { $q = $pdo->prepare("SELECT 1 FROM $t WHERE phone = ? AND status = 'sent' AND sent_at >= ? LIMIT 1"); $q->execute([$phone, date('Y-m-d H:i:s', $now - 86400)]); if ($q->fetchColumn()) return 'wait: got a follow-up today'; } catch (Throwable $e) { } }
    return '';
}
/** A customer who answered and then went quiet for 24 h is read again: a new topic = a new ladder (what he already got is never repeated; max 2 changes; never beyond 14 days). */
function wl_recheck(PDO $pdo, int $now): int
{
    $q = $pdo->prepare("SELECT * FROM wa_line_cust WHERE status = 'replied' AND recheck_at IS NOT NULL AND recheck_at <= ? LIMIT 200"); $q->execute([date('Y-m-d H:i:s', $now)]); $n = 0;
    foreach ($q->fetchAll() as $r) {
        $phone = (string)$r['phone']; $anchor = (int)strtotime((string)$r['anchor_at']);
        $done = function (string $why) use ($pdo, $phone) { $pdo->prepare("UPDATE wa_line_cust SET status = 'done', left_why = ?, recheck_at = NULL WHERE phone = ?")->execute([$why, $phone]); };
        if ($now > $anchor + 32 * 86400) { $done('30 days over'); continue; }
        $c = function_exists('wa_contact') ? wa_contact($pdo, $phone) : null; if (!$c) { $done('no contact'); continue; }
        if (wl_block($pdo, $c, date('Y-m-d H:i:s', $anchor)) === 'ordered') { $done('ordered'); continue; }
        $li = !empty($c['last_in_at']) ? (int)strtotime((string)$c['last_in_at']) : 0;
        if ($li > $now - 86400) { $pdo->prepare("UPDATE wa_line_cust SET recheck_at = ? WHERE phone = ?")->execute([date('Y-m-d H:i:s', $li + 86400), $phone]); continue; }   // he wrote again: the conversation is not over; the 24 hours count from his NEW last message
        $o = $pdo->prepare("SELECT n FROM wa_cust WHERE phone = ?"); $o->execute([$phone]); $bought = (int)$o->fetchColumn() > 0;
        $m = $pdo->prepare("SELECT body FROM wa_messages WHERE dir = 'in' AND mtype IN ('text','interactive','button') AND phone = ? AND ts >= ? ORDER BY id DESC LIMIT 12"); $m->execute([$phone, date('Y-m-d H:i:s', $anchor)]);
        $topic = wl_classify(array_reverse($m->fetchAll(PDO::FETCH_COLUMN)), $bought); $sw = (int)$r['switches'];
        if ($topic !== (string)$r['topic']) { if ($sw >= 2) { $done('topic changed too often'); continue; } $sw++; wl_log($pdo, $phone, 'topic ' . $r['topic'] . ' -> ' . $topic); }
        $sent = []; $s = $pdo->prepare("SELECT tpl_key FROM wa_line_plan WHERE phone = ? AND status = 'sent'"); $s->execute([$phone]); foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $k) $sent[(string)$k] = 1;
        $pdo->prepare("DELETE FROM wa_line_plan WHERE phone = ? AND status = 'planned'")->execute([$phone]);
        $rows = wln_plan($pdo, $phone, $topic, $now - 86400, $sent, $now);   // his 24 quiet hours are already over: the new ladder's first step is due NOW, the next ones 24 h after each message that goes
        if ($rows < 1) { $done('all messages sent'); continue; }
        $pdo->prepare("UPDATE wa_line_cust SET topic = ?, status = 'active', switches = ?, recheck_at = NULL WHERE phone = ?")->execute([$topic, $sw, $phone]); $n++;
    }
    return $n;
}
/** THE START (runs by itself on the first engine round after the upload, and from the button): old 7-Day Drip off, every lineup message made and sent to Meta, button cards, everybody since yesterday 10:00 IST read and planned. Safe to repeat.
 *  Nothing goes to a customer here: sending waits until Meta approved all the messages (wl_ready). */
function wl_start_all(PDO $pdo, int $by = 0): array
{
    $c = wl_cfg($pdo); $dripWas = wl_drip_off($pdo); $tp = wl_make_templates($pdo, $by); $kw = wl_install_kw($pdo);
    if ($c['since'] <= 0) $c['since'] = wl_yesterday_start(time()); if ($c['started'] === '') $c['started'] = date('Y-m-d H:i:s'); if ($c['mode'] === 'off') $c['mode'] = 'auto'; wl_cfg_put($pdo, $c);
    $n = wl_enroll($pdo, $c['since']); wa_log($pdo, 'engine', true, 'Lineup started: ' . $n . ' customers planned, ' . $tp['made'] . ' messages made, ' . $tp['sent'] . ' sent to Meta, old drip ' . ($dripWas ? 'switched off' : 'was already off'));
    return ['drip_was_on' => $dripWas, 'templates' => $tp, 'cards' => $kw, 'enrolled' => $n];
}
/** All templates approved? Until then nothing is sent by itself (a step whose message is still waiting for Meta would be jumped over). */
function wl_ready(PDO $pdo): bool { $P = wl_pack(); foreach (wl_tpl_status($pdo) as $k => $s) if (empty($P[$k]['of']) && empty($P[$k]['pref']) && empty($P[$k]['newtopic']) && (int)($P[$k]['step'] ?? 1) < 8 && $s !== 'approved') return false; return true; }   // the extra wordings are optional: one that is not approved is simply not used
/** Send one Lineup message. [ok, reason, number id, wamid]. Owner, 21 Nov: the Lineup goes slowly, one message at a time, and Module A / Module B's allowances (hourly speed, the 90% margin, the daily room) do NOT apply to it:
 *  the number is chosen with the "burst" switch of wa_camp_ctx, so only what Meta itself decides (its tier, a Low-quality / disconnected / locked number, a new number's slow start) can stop a message. */
function wln_send(PDO $pdo, array $c, array $step): array
{
    // the message and the numbers' health are looked up ONCE per message and 45 seconds, not for every customer (that took 5-10 s each: 6 messages a minute); burst mode ignores the used / left counters anyway
    static $cache = []; $tid = (int)$step['tpl_id']; $ck = $tid . '|' . intdiv(time(), 45); if (count($cache) > 60) $cache = [];
    if (!isset($cache[$ck])) { $t0 = function_exists('wa_camp_tpl') ? wa_camp_tpl($pdo, $tid, true, 0) : null; $cache[$ck] = $t0 ? [$t0, wa_camp_ctx($pdo, $t0, 2, [], 1000000)] : [null, null]; }
    [$t, $ctx] = $cache[$ck]; if (!$t) return [false, 'The message is not approved by Meta (or its photo is missing).'];
    $nv = (int)($t['nvars'] ?? 0); $v2 = (string)($step['v2'] ?? ''); if ($nv > 2 || ($nv === 2 && $v2 === '')) return [false, 'This message has ' . $nv . ' blanks.'];
    $nid = 0;
    if (empty($ctx['nums'])) return [false, 'No number can send this message now (' . (function_exists('wd_why_none') ? wd_why_none($pdo, $t) : 'none available') . ')'];
    $pref = (int)($c['last_number_id'] ?? 0);   // owner 21 Nov: the message goes back from the number he wrote to (where his reply came in); another number only if that one cannot send this message
    if ($pref > 0 && isset($ctx['nums'][$pref])) { $nid = $pref; $why = ''; } else [$nid, $why] = wa_pick_number($pdo, $c, $ctx);
    if (!$nid && $why === 'ignored every number') { $el = $ctx['nums']; uasort($el, function ($a, $b) { return ($a['used'] / max(1, $a['cap'])) <=> ($b['used'] / max(1, $b['cap'])); }); $nid = (int)(reset($el)['id'] ?? 0); }   // about THIS customer, not about the numbers: the freest number takes him
    if (!$nid || !isset($ctx['nums'][$nid])) return [false, 'No number can send this message now (' . $why . ').'];
    [$ok, $res] = wa_send_tpl_one($pdo, $c, $t, $ctx['nums'][$nid], $nv ? array_slice([function_exists('wd_name') ? wd_name($c) : 'ji', $v2], 0, $nv) : [], 'followup');
    return $ok ? [true, '', $nid, (string)$res] : [false, (string)$res, $nid];
}
/** One time, after the 10:00-20:00 window was removed: the steps still waiting for their first message go back to their natural time (his first message + N x 24 h). Only customers who have not been sent anything yet; harmless to repeat. */
function wln_unwindow(PDO $pdo): int
{
    if ((string)setting_get($pdo, 'wl_unwin', '') === '1') return 0; $n = 0;
    $q = $pdo->query("SELECT p.id, p.step, c.anchor_at FROM wa_line_plan p JOIN wa_line_cust c ON c.phone = p.phone WHERE p.status = 'planned' AND c.status = 'active' AND NOT EXISTS (SELECT 1 FROM wa_line_plan s WHERE s.phone = p.phone AND s.status = 'sent')");
    $u = $pdo->prepare("UPDATE wa_line_plan SET due_at = ? WHERE id = ?"); foreach ($q->fetchAll() as $r) { $u->execute([date('Y-m-d H:i:s', (int)strtotime((string)$r['anchor_at']) + (int)$r['step'] * 86400), $r['id']]); $n++; }
    setting_set($pdo, 'wl_unwin', '1'); return $n;
}
/** One time (owner, 21 Nov): the Lineup also catches everybody who wrote since the day BEFORE yesterday 10:00 IST (it started with yesterday 10:00). The new people join through the normal enrolment; their timing is the same rule as everybody's: step 1 only when his last message is more than 24 h old. */
/** Owner, 21 Nov: "take everybody from the 15th" — one time: the Lineup catches every customer who wrote since 15 Nov 10:00 IST (before: 19 Nov 10:00). Those who already are in the Lineup stay as they are; the older ones start at step 1 now (their first step is due at once, the next ones follow by the 30-day gaps). Enrolment takes 3,000 people a round. */
function wln_since_15(PDO $pdo): bool
{
    if ((string)setting_get($pdo, 'wl_since_v3', '') === '1') return false; $c = wl_cfg($pdo); if ($c['started'] === '' || $c['since'] <= 0) return false;
    $to = wl_ist_at('2026-11-15', 10); setting_set($pdo, 'wl_since_v3', '1'); if ($c['since'] <= $to) return false;
    $c['since'] = $to; wl_cfg_put($pdo, $c); wa_log($pdo, 'engine', true, 'Lineup: catching everybody who wrote since ' . date('Y-m-d H:i', $to) . ' (15 Nov 10:00 IST)'); return true;
}
/** Owner, 21 Nov: "today take the 1st to the 14th as well" - one time: the Lineup also catches everybody who wrote since 1 Nov 10:00 IST (the enrolment takes 3,000 a round; the older ones start at step 1 now, like the 15th ones did). */
function wln_since_1(PDO $pdo): bool
{
    if ((string)setting_get($pdo, 'wl_since_v4', '') === '1') return false; $c = wl_cfg($pdo); if ($c['started'] === '' || $c['since'] <= 0) return false;
    $to = wl_ist_at('2026-11-01', 10); setting_set($pdo, 'wl_since_v4', '1'); if ($c['since'] <= $to) return false;
    $c['since'] = $to; wl_cfg_put($pdo, $c); wa_log($pdo, 'engine', true, 'Lineup: catching everybody who wrote since ' . date('Y-m-d H:i', $to) . ' (1 Nov 10:00 IST)'); return true;
}
function wln_since_back(PDO $pdo): bool
{
    if ((string)setting_get($pdo, 'wl_since_v2', '') === '1') return false; $c = wl_cfg($pdo); if ($c['started'] === '' || $c['since'] <= 0) return false;
    $c['since'] = wl_yesterday_start(time()) - 86400; wl_cfg_put($pdo, $c); setting_set($pdo, 'wl_since_v2', '1'); wa_log($pdo, 'engine', true, 'Lineup: catching everybody who wrote since ' . date('Y-m-d H:i', $c['since'])); return true;
}
/** One time: steps that were marked failed only because no number could send the message at that moment ("No number can send…") go back to planned, if the customer has not been sent anything since (so his step 1 is still his first). */
function wln_requeue_failed(PDO $pdo): int
{
    if ((string)setting_get($pdo, 'wl_requeue_v1', '') === '1') return 0;
    $n = $pdo->exec("UPDATE wa_line_plan SET status = 'planned', why = '' WHERE status = 'failed' AND why LIKE 'No number can send%' AND phone NOT IN (SELECT phone FROM (SELECT DISTINCT phone FROM wa_line_plan WHERE status = 'sent') s)");
    setting_set($pdo, 'wl_requeue_v1', '1'); return (int)$n;
}
/** The keys whose message is approved on at least 3 WhatsApp accounts (the shop's own messages are on 5-6; a lineup template that only one account took, one without any number, must never be picked: 40% of the step-1 sends waited for ever on those). */
function wln_wide_ok(PDO $pdo): array
{
    $ids = wln_eff_ids($pdo); $n = []; try { foreach ($pdo->query("SELECT tpl_id, SUM(UPPER(status) = 'APPROVED') ok FROM wa_tpl_waba GROUP BY tpl_id")->fetchAll() as $r) $n[(int)$r['tpl_id']] = (int)$r['ok']; } catch (Throwable $e) { }
    $o = []; foreach ($ids as $k => $id) if (($n[(int)$id] ?? 0) >= 3) $o[] = $k; return $o;
}
/** THE PRICE OF THIS CUSTOMER'S NEXT PRICE STEP, worked out from the shop's own price chart (setting ml_slab_chart: rows = total ml, columns = 7 / 6.5 / 6 / 5 / 4 / 3.5 / 3 %; a higher % is a HIGHER price).
 *  The quote is the 4 x 50 ml = 200 ml combo row. Start = the deepest column already quoted in his chat (never above 6.5%); every price step goes ONE column deeper than the one before (never higher than what he was told), the last column of the chart is the floor.
 *  Returns '' when there is no chart or no row (the plain message of the step goes then). */
function wln_price_text(PDO $pdo, string $phone, int $ml = 200): string { $i = wln_price_info($pdo, $phone, $ml); return $i ? $i['text'] : ''; }
/** [text, pct] of the next price step, or null (see wln_price_text). */
function wln_price_info(PDO $pdo, string $phone, int $ml = 200): ?array
{
    $j = json_decode((string)setting_get($pdo, 'ml_slab_chart', ''), true); if (!is_array($j) || empty($j['cols']) || empty($j['rows'])) return null;
    $cols = array_values(array_map('floatval', (array)$j['cols'])); $row = null; foreach ((array)$j['rows'] as $r) if ((int)($r['ml'] ?? 0) === $ml) { $row = $r; break; } if (!$row || empty($row['p'])) return null;
    $base = 0; $bb = 1e9; foreach ($cols as $i => $cv) { $d = abs($cv - 7.0); if ($d < $bb) { $bb = $d; $base = $i; } }   // owner, 21 Nov: the first price is the 7% column; the Lineup's price steps then go 6.5% (day 1 evening / night), 6% (next day), 5% (day 5-6), then the deeper columns - one column per price step
    if (function_exists('pc_chat_level')) { try { $lv = pc_chat_level($pdo, $phone); if (empty($lv['default'])) foreach ($cols as $i => $cv) if (abs($cv - (float)$lv['pct']) < 0.01) $base = max($base, $i); } catch (Throwable $e) { } }
    $k = 0; try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_line_plan WHERE phone = ? AND status = 'sent' AND SUBSTR(tpl_key, 1, 6) = 'ln_px_'"); $q->execute([$phone]); $k = (int)$q->fetchColumn(); } catch (Throwable $e) { }
    $idx = min(count($cols) - 1, $base + 1 + $k); $v = (int)($row['p'][$idx] ?? 0); if ($v <= 0) return null;
    return ['text' => '4 x 50 ml (' . $ml . ' ml) combo sirf Rs ' . number_format($v), 'pct' => (float)$cols[$idx]];
}
/** One time per customer (idempotent): the old seven-step ladder becomes the 30-day one. The planned steps keep their next time, every later step is re-timed by the new gaps and the missing steps 8-12 are added. 300 customers a round. */
function wln_ladder30(PDO $pdo): int
{
    $q = $pdo->query("SELECT c.phone, c.topic FROM wa_line_cust c WHERE c.status = 'active' AND EXISTS (SELECT 1 FROM wa_line_plan p WHERE p.phone = c.phone AND p.status IN ('planned','sent')) AND NOT EXISTS (SELECT 1 FROM wa_line_plan p2 WHERE p2.phone = c.phone AND p2.step = 12) LIMIT 300"); $n = 0;
    $ins = $pdo->prepare("INSERT INTO wa_line_plan (phone, topic, step, tpl_key, due_at) VALUES (?, ?, ?, ?, ?)"); $upd = $pdo->prepare("UPDATE wa_line_plan SET due_at = ? WHERE id = ?");
    foreach ($q->fetchAll() as $c) {
        $ph = (string)$c['phone']; $rows = $pdo->prepare("SELECT id, step, status, due_at, sent_at FROM wa_line_plan WHERE phone = ? AND status IN ('planned','sent') ORDER BY step"); $rows->execute([$ph]); $all = $rows->fetchAll();
        $have = []; foreach ($all as $r) $have[(int)$r['step']] = $r; $ref = null; foreach ($all as $r) if ($r['status'] === 'planned') { $ref = $r; break; }
        if ($ref) { $t0 = (int)strtotime((string)$ref['due_at']); $s0 = (int)$ref['step']; } else { $ls = null; foreach ($all as $r) if ($r['status'] === 'sent') $ls = $r; if (!$ls) continue; $t0 = (int)strtotime((string)$ls['sent_at']); $s0 = (int)$ls['step']; }
        for ($s = $s0 + ($ref ? 1 : 1); $s <= wl_steps(); $s++) { $due = date('Y-m-d H:i:s', $t0 + wl_gap_sum($s0, $s) * 86400);
            if (isset($have[$s])) { if ($have[$s]['status'] === 'planned') $upd->execute([$due, $have[$s]['id']]); } else $ins->execute([$ph, (string)$c['topic'], $s, wl_key((string)$c['topic'], $s), $due]); }
        $n++;
    }
    return $n;
}
/** 🧵 For the campaign cards: how many people each campaign SENT TO (last 30 days only) are in the 30-Day Lineup now. Worked out in PHP from the Lineup's own phone list (no slow join), kept 10 minutes in a setting, 8 campaigns per call; {campaign id => n}. */
function wl_camp_lineup_counts(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids))); if (!$ids) return [];
    $cache = json_decode((string)setting_get($pdo, 'wl_camp_lineup', ''), true); if (!is_array($cache)) $cache = [];
    $out = []; $todo = []; foreach ($ids as $id) { $c = $cache[(string)$id] ?? null; if (is_array($c) && (int)($c['at'] ?? 0) > time() - 600) $out[$id] = (int)$c['n']; else $todo[] = $id; }
    if ($todo) {
        $set = []; try { foreach ($pdo->query("SELECT phone FROM wa_line_cust WHERE status IN ('active', 'replied')")->fetchAll(PDO::FETCH_COLUMN) as $ph) $set[(string)$ph] = 1; } catch (Throwable $e) { return $out; }
        $from = date('Y-m-d H:i:s', time() - 30 * 86400); $done = 0;
        foreach ($todo as $id) { if ($done >= 8) { $out[$id] = (int)($cache[(string)$id]['n'] ?? 0); continue; } $done++;
            try { $q = $pdo->prepare("SELECT phone FROM wa_camp_items WHERE camp_id = ? AND status = 'sent' AND sent_at >= ? LIMIT 60000"); $q->execute([$id, $from]); $n = 0; foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $ph) if (isset($set[(string)$ph])) $n++; } catch (Throwable $e) { $n = 0; }
            $out[$id] = $n; $cache[(string)$id] = ['n' => $n, 'at' => time()]; }
        if (count($cache) > 300) $cache = array_slice($cache, -150, null, true); setting_set($pdo, 'wl_camp_lineup', json_encode($cache));
    }
    return $out;
}
/** The plan as the owner reads it: how many customers have each step still to go, on which day of the ladder it goes, and how many go on each calendar day (IST) for the next 31 days. */
function wl_calendar(PDO $pdo): array
{
    $o = ['steps' => [], 'days' => [], 'total' => 0]; $d = wl_days();
    try { foreach ($pdo->query("SELECT p.step, COUNT(*) n FROM wa_line_plan p JOIN wa_line_cust c ON c.phone = p.phone WHERE p.status = 'planned' AND c.status = 'active' GROUP BY p.step ORDER BY p.step")->fetchAll() as $r) { $o['steps'][] = ['step' => (int)$r['step'], 'day' => $d[(int)$r['step']] ?? 0, 'n' => (int)$r['n']]; $o['total'] += (int)$r['n']; } } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT DATE(due_at) d, COUNT(*) n FROM wa_line_plan p JOIN wa_line_cust c ON c.phone = p.phone WHERE p.status = 'planned' AND c.status = 'active' GROUP BY DATE(due_at) ORDER BY d LIMIT 40")->fetchAll() as $r) $o['days'][] = ['date' => (string)$r['d'], 'n' => (int)$r['n']]; } catch (Throwable $e) { }
    return $o;
}
/** Send one Lineup message through whatever sender exists (the campaign engine, or the stub of the tests). [ok, reason, number id, wamid]. */
function wln_send_any(PDO $pdo, array $c, int $tid, string $v2 = ''): array
{
    return function_exists('wa_camp_ctx') ? wln_send($pdo, $c, ['tpl_id' => $tid, 'number_id' => 0, 'v2' => $v2]) : wd_send($pdo, $c, ['tpl_id' => $tid, 'number_id' => 0]);
}
/** THE UTILITY TWIN (owner, 21 Nov: "not one message may fail — if it fails as marketing, send it again as utility on the spot, within one hour"): the utility messages that can say the same thing as this step —
 *  the lineup's own wordings of the step (made as utility) and the shop's utility message #386 — that Meta still counts as UTILITY, are not the message that failed and were not sent to him yet. [[template id, key]]. */
function wln_twins(PDO $pdo, string $phone, string $topic, int $step, int $failedTid): array
{
    $own = wl_tpl_ids($pdo); $cand = []; foreach (wl_variants(wl_key($topic, $step)) as $k) if (!empty($own[$k]) && empty(wl_pack()[$k]['cat'])) $cand[(int)$own[$k]] = $k;
    foreach (wln_existing_map() as $k => $id) if ((int)$id === 386) { $cand[386] = $k; break; } if (!isset($cand[386])) $cand[386] = 'ln_util_386';
    if (function_exists('wa_util_pool_usable')) { try { foreach (wa_util_pool_usable($pdo) as $t0) { $pid = (int)($t0['id'] ?? 0); if ($pid > 0 && (int)($t0['nvars'] ?? 0) <= 1 && (string)($t0['pool_kind'] ?? 'open') === 'open' && !isset($cand[$pid])) $cand[$pid] = 'ln_util_pool'; } } catch (Throwable $e) { } }   // the shop's own older utility follow-ups (name only, approved, not made marketing) when the lineup's own ones are not enough
    unset($cand[$failedTid]); if (!$cand) return [];
    $util = []; try { foreach ($pdo->query("SELECT id, category FROM wa_templates WHERE id IN (" . implode(',', array_map('intval', array_keys($cand))) . ")")->fetchAll() as $r) if (strtoupper((string)$r['category']) === 'UTILITY') $util[(int)$r['id']] = 1; } catch (Throwable $e) { return []; }
    $got = []; try { $q = $pdo->prepare("SELECT tpl_id FROM wa_line_plan WHERE phone = ? AND status = 'sent'"); $q->execute([$phone]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) $got[(int)$t] = 1; } catch (Throwable $e) { }
    $o = []; foreach ($cand as $id => $k) if (isset($util[$id]) && !isset($got[$id])) $o[] = [(int)$id, (string)$k]; return $o;
}
/** How much of what we sent a customer he actually OPENED: [messages sent, messages read]. A customer who never opened 3+ messages does not get the paid (marketing) price steps — the plain utility message goes instead (cheaper, and it protects the numbers' quality); one who read but did not answer gets the price step. */
function wln_engagement(PDO $pdo, string $phone): array
{
    try { $q = $pdo->prepare("SELECT wamid FROM wa_line_plan WHERE phone = ? AND status = 'sent' AND wamid <> '' ORDER BY id DESC LIMIT 20"); $q->execute([$phone]); $w = $q->fetchAll(PDO::FETCH_COLUMN); if (!$w) return [0, 0];
        $in = implode(',', array_fill(0, count($w), '?')); $r = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE wamid IN ($in) AND status = 'read'"); $r->execute($w); return [count($w), (int)$r->fetchColumn()]; } catch (Throwable $e) { return [0, 0]; }
}
/** Marketing-only blocks (Meta: too many marketing messages to this customer / he stopped marketing / marketing holdback). Utility is not held by them. */
function wln_mkt_codes(): array { return [131049, 131050, 130472]; }
/** THE RESCUE: a Lineup message whose delivery receipt says FAILED for a marketing-only reason is sent again at once as its utility twin (one rescue per message; not if he wrote since, ordered or opted out).
 *  The rescue is a row of its own (status sent, why 'utility rescue'), so it is counted — and its receipt counts as delivered — like any other message. 40 a round, receipts of the last 3 hours. Returns the number of rescues sent. */
function wln_rescue(PDO $pdo, int $now = 0, int $cap = 40): int
{
    $now = $now ?: time(); $in = implode(',', wln_mkt_codes()); $n = 0;
    // (no JOIN on the wamids: the two tables have different collations — that threw an error and nothing was rescued; the receipts are read with a bound list instead)
    // (the FAILED receipts are read first, newest first, then their plan rows by a bound wamid list — before, the 4,000 oldest sent rows of the last 3 hours were read and the newer failures were never seen once more than 4,000 had gone)
    $rows = []; try { $f = $pdo->prepare("SELECT wamid, err_code FROM wa_messages WHERE dir = 'out' AND mtype = 'template' AND ts >= ? AND status = 'failed' AND err_code IN ($in) ORDER BY id DESC LIMIT 1500"); $f->execute([date('Y-m-d H:i:s', $now - 3 * 3600)]); $bad = [];
        foreach ($f->fetchAll() as $m) $bad[(string)$m['wamid']] = (int)$m['err_code'];
        foreach (array_chunk(array_keys($bad), 400) as $ch) { $x = $pdo->prepare("SELECT id, phone, topic, step, tpl_id, sent_at, wamid FROM wa_line_plan WHERE status = 'sent' AND why = '' AND wamid IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $x->execute($ch);
            foreach ($x->fetchAll() as $r) { $r['err_code'] = $bad[(string)$r['wamid']]; $rows[] = $r; if (count($rows) >= $cap) break 2; } } } catch (Throwable $e) { return 0; }
    $mark = $pdo->prepare("UPDATE wa_line_plan SET why = ? WHERE id = ?"); $ins = $pdo->prepare("INSERT INTO wa_line_plan (phone, topic, step, tpl_key, tpl_id, due_at, status, why, sent_at, wamid) VALUES (?, ?, ?, ?, ?, ?, 'sent', ?, ?, ?)");
    foreach ($rows as $r) {
        $ph = (string)$r['phone']; $c = function_exists('wa_contact') ? wa_contact($pdo, $ph) : null; if (!$c) { $mark->execute(['rescue: no contact', $r['id']]); continue; }
        if (!empty($c['optout']) || !empty($c['camp_optout'])) { $mark->execute(['rescue: opted out', $r['id']]); continue; }
        if (!empty($c['last_in_at']) && (int)strtotime((string)$c['last_in_at']) > (int)strtotime((string)$r['sent_at'])) { $mark->execute(['rescue not needed: he wrote', $r['id']]); continue; }
        $tw = wln_twins($pdo, $ph, (string)$r['topic'], (int)$r['step'], (int)$r['tpl_id']); if (!$tw) { $mark->execute(['rescue: no utility message left', $r['id']]); wl_log($pdo, $ph, 'failed (' . $r['err_code'] . ') and no utility message to rescue it'); continue; }
        foreach ($tw as [$tid, $key]) {
            $res = wln_send_any($pdo, $c, $tid);
            if ($res[0]) { $ts = date('Y-m-d H:i:s'); $ins->execute([$ph, (string)$r['topic'], (int)$r['step'], $key, $tid, $ts, 'utility rescue of #' . $r['id'] . ' (' . $r['err_code'] . ')', $ts, (string)($res[3] ?? '')]); $mark->execute(['rescued (' . $r['err_code'] . ') as utility #' . $tid, $r['id']]); $n++; wl_log($pdo, $ph, 'marketing failed (' . $r['err_code'] . ') -> utility #' . $tid . ' sent'); continue 2; }
            $why1 = mb_substr((string)$res[1], 0, 60);   // this twin could not go (no number of his account has it approved, no room...): the next twin is tried
        }
        $mark->execute(['rescue: no utility message could go (' . ($why1 ?? '') . ')', $r['id']]);   // marked, so the next rounds go on with OTHER failed messages (before, the oldest unsendable 40 were tried again every minute and blocked all the others)
        unset($why1);
    }
    return $n;
}
/** A customer who has to wait: his planned steps move to the time the wait ends (the same gap between them), so the engine does not look at him again every minute (2,800 waiting customers used the whole 40 seconds of a round and only 21 messages went). */
function wln_push(PDO $pdo, string $phone, int $until): void
{
    $r = $pdo->prepare("SELECT id, step FROM wa_line_plan WHERE phone = ? AND status = 'planned' ORDER BY step"); $r->execute([$phone]); $rows = $r->fetchAll(); if (!$rows) return;
    $u = $pdo->prepare("UPDATE wa_line_plan SET due_at = ? WHERE id = ?"); foreach ($rows as $i => $x) $u->execute([date('Y-m-d H:i:s', $until + $i * 86400), $x['id']]);
}
/** The engine step. Sends what is due (cap per round), enrols new customers, re-reads the quiet ones. $force = the Send button (ignores the clock window and the "all approved" gate, never the blocks). */
/** One group of customers (by phone) a caller works on: MySQL GET_LOCK per group (atomic, freed by itself if the process dies). -1 = every group is taken. */
function wln_claim_shard(PDO $pdo, int $n): int
{
    $my = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $st0 = mt_rand(0, max(0, $n - 1));   // random start: several callers at once do not all knock on shard 0 first
    for ($j = 0; $j < $n; $j++) { $k = ($st0 + $j) % $n;
        if ($my) { try { $g = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $g->execute(['hrms_wl_shard_' . $k]); if ((int)$g->fetchColumn() === 1) return $k; } catch (Throwable $e) { } }
        elseif (!function_exists('wa_lock') || wa_lock($pdo, 'wlshard' . $k, 50)) return $k;
    }
    return -1;
}
/** FAN-OUT: when hundreds are due, the engine calls the sending job (wa.cron_send) a few more times at the same moment (a request that is not waited for; the job ignores a hung-up caller), so several shards send at once even when the extra cron lines do not fire. At most N-1 calls a minute. */
function wln_fanout(PDO $pdo, int $n, int $now): int
{
    if ((string)setting_get($pdo, 'wl_cli', '') !== '0') return 0;   // the command-line copies (cron_line.php) send: no extra web-server requests (they starved the bot follow-up worker)
    if ($n < 2 || !function_exists('wa_lock') || !wa_lock($pdo, 'wlfan', 40)) return 0;
    try { $q = $pdo->prepare("SELECT COUNT(*) FROM (SELECT 1 FROM wa_line_plan WHERE status = 'planned' AND due_at <= ? LIMIT 300) t"); $q->execute([date('Y-m-d H:i:s', $now)]); if ((int)$q->fetchColumn() < 300) return 0; } catch (Throwable $e) { return 0; }
    $key = (string)setting_get($pdo, 'wa_cron_key', ''); if ($key === '' || !function_exists('stream_socket_client')) return 0; $host = (string)($_SERVER['HTTP_HOST'] ?? 'staging.fourpirates.space'); $sent = 0;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
    for ($i = 1; $i < $n; $i++) { $fp = @stream_socket_client('ssl://127.0.0.1:443', $en, $es, 2, STREAM_CLIENT_CONNECT, $ctx); if (!$fp) continue;
        @fwrite($fp, "GET /empmanagment/api.php?action=wa.cron_send&key=" . urlencode($key) . " HTTP/1.1\r\nHost: $host\r\nUser-Agent: wa-line-fanout\r\nConnection: close\r\n\r\n"); @fclose($fp); $sent++; }
    return $sent;
}
function wln_release_shard(PDO $pdo, int $k): void { if ($k < 0 || (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return; try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['hrms_wl_shard_' . $k]); } catch (Throwable $e) { } }
function wl_tick(PDO $pdo, array $opt = []): array
{
    $o = ['entered' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0]; if (!wln_tables($pdo)) return $o; $cfg = wl_cfg($pdo); $force = !empty($opt['now']); $sendonly = !empty($opt['sendonly']);   // sendonly = the sending job (wa.cron_send): only the sending round, no bookkeeping
    if ($cfg['mode'] === 'off') return $o;
    if (!$force && function_exists('wa_quiet_hours') && wa_quiet_hours()) { $o['why'] = 'quiet hours 03:00-09:00'; return $o; }   // universal rule (owner, 21 Nov)
    if (!$force && !$sendonly) { setting_set($pdo, 'wl_tick_at', date('Y-m-d H:i:s')); try { wl_snap($pdo); } catch (Throwable $e) { } }   // the 20-minute status line
    if ($sendonly && ($cfg['started'] === '' || $cfg['since'] <= 0)) return $o;
    if ($cfg['started'] === '' || $cfg['since'] <= 0) { if (function_exists('wa_lock') && wa_lock($pdo, 'wlboot', 120)) { try { wl_start_all($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'lineup start: ' . $e->getMessage()); } } return $o; }   // first round after the upload: start by itself
    if (!$force && !$sendonly && function_exists('wa_lock') && count(wl_tpl_ids($pdo)) < count(wl_pack()) && wa_lock($pdo, 'wlmake', 300)) { try { wl_make_templates($pdo); } catch (Throwable $e) { } }   // a message that did not fit Meta's limit yet: made / sent in a later minute
    $book = !$force && !$sendonly && function_exists('wa_lock') && wa_lock($pdo, 'wlbook', 50);   // the bookkeeping (enrolment, re-reads, rescue) is done by ONE caller a minute; the sending is shared by shards below
    $now = time(); [, $h] = wl_ist($now); if (!$force && ($h < $cfg['from'] || $h >= $cfg['to'])) return $o;   // only inside the sending window (none unless wl_cfg.win = 1)
    if ($book) { try { wln_unwindow($pdo); wln_since_back($pdo); wln_requeue_failed($pdo); wln_ladder30($pdo); wln_since_15($pdo); wln_since_1($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'lineup unwindow: ' . $e->getMessage()); } }
    $cfg = wl_cfg($pdo);   // (since may just have moved)
    if ($book && function_exists('wa_lock') && wa_lock($pdo, 'wlenrol', 300)) { try { $o['entered'] = wl_enroll($pdo, $cfg['since']); $o['entered_camp'] = wln_enroll_camp($pdo, $cfg['since']); wln_camp_cleanup($pdo); wl_recheck($pdo, $now); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'lineup enrol: ' . $e->getMessage()); } }
    if ($book) { try { $o['rescued'] = wln_rescue($pdo, $now); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'lineup rescue: ' . $e->getMessage()); } }   // failed as marketing -> sent again as utility on the spot
    $N = 8; $shard = -1;   // 8 groups always (owner 21 Nov: finish the day's plan before the next day's steps are added): cron_line.php runs up to 8 sending copies on the command line
      // SHARDS (owner, 21 Nov: speed): the due customers are split into N groups by their phone; every caller (the engine and the sending job) takes the first group nobody works on, so several run at once
    if (!$force) { $shard = wln_claim_shard($pdo, $N); if ($shard < 0) return $o; }
    if (!$force && !$sendonly) { try { $o['fanout'] = wln_fanout($pdo, $N, $now); } catch (Throwable $e) { } }   // more sending jobs at once while a lot is due
    try { return wln_send_round($pdo, $opt, $cfg, $force, $now, $shard, $N, $o); } finally { wln_release_shard($pdo, $shard); }
}
/** The sending round of one shard (all of the old wl_tick below the bookkeeping). */
function wln_send_round(PDO $pdo, array $opt, array $cfg, bool $force, int $now, int $shard, int $N, array $o): array
{
    $ids = wln_eff_ids($pdo); if (!$ids) return $o; if (!$force && !wl_ready($pdo)) { $o['why'] = 'waiting for Meta to approve every lineup message'; return $o; }
    $cap = $force ? (int)($opt['cap'] ?? 60) : max((int)$cfg['cap'], 400); $dl = microtime(true) + ($force ? 40 : 52);   // a shard's round may work 52 s
    $my = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; $shsql = ($shard >= 0 && $N > 1 && $my) ? ' AND MOD(CAST(RIGHT(p.phone, 6) AS UNSIGNED), ' . (int)$N . ') = ' . (int)$shard : '';
    $q = $pdo->prepare("SELECT p.id, p.phone, p.step, p.tpl_key, p.due_at, c.anchor_at, c.topic FROM wa_line_plan p JOIN wa_line_cust c ON c.phone = p.phone WHERE p.status = 'planned' AND p.due_at <= ? AND c.status = 'active'" . $shsql . " ORDER BY p.phone, p.step LIMIT 3000");
    $q->execute([date('Y-m-d H:i:s', $now)]); $rows = $q->fetchAll(); if ($shard >= 0 && $N > 1 && !$my) $rows = array_values(array_filter($rows, fn($r) => ((int)substr(preg_replace('/\\D/', '', (string)$r['phone']), -6)) % $N === $shard)); if (!$rows) return $o; $byPhone = []; foreach ($rows as $r) $byPhone[(string)$r['phone']][] = $r; $sent = 0;
    $orders = wl_orders_since($pdo, $now - 16 * 86400); $scores = json_decode((string)setting_get($pdo, 'wl_scores', ''), true); if (!is_array($scores)) $scores = []; $okKeys = wln_wide_ok($pdo);
    $upd = $pdo->prepare("UPDATE wa_line_plan SET status = ?, why = ?, sent_at = ?, wamid = ?, tpl_key = ? WHERE id = ?");
    $wc = []; $wcn = function (string $k) use (&$wc) { $wc[$k] = ($wc[$k] ?? 0) + 1; };
    foreach ($byPhone as $phone => $list) {
        if ($sent >= $cap || microtime(true) > $dl) { $o['waiting'] += count($list); $wcn('round full or out of time'); continue; }
        $row = $list[0];   // the lowest due step goes (the list is ordered by step); the steps after it are moved to 24 h after the message that really went
        $c = function_exists('wa_contact') ? wa_contact($pdo, (string)$phone) : null; if (!$c) { $upd->execute(['skipped', 'no contact', null, '', $row['tpl_key'], $row['id']]); $o['skipped']++; continue; }
        $fq = $pdo->prepare("SELECT 1 FROM wa_line_plan WHERE phone = ? AND status = 'sent' LIMIT 1"); $fq->execute([$phone]); $isFirst = !$fq->fetchColumn();
        $until = 0; $why = wl_block($pdo, $c, (string)$row['anchor_at'], $orders, $isFirst, $until);
        if ($why !== '') {
            if ($why === 'wait: conversation not over') {   // still talking: wait as long as it takes (up to 14 days from his first message), then drop
                $wcn('conversation not over (last message < 24 h)'); if ($until > $now) wln_push($pdo, (string)$phone, $until + 60);
                if ($now > (int)strtotime((string)$row['anchor_at']) + 14 * 86400) { $pdo->prepare("UPDATE wa_line_plan SET status = 'cancelled', why = 'the conversation never ended' WHERE phone = ? AND status = 'planned'")->execute([$phone]); $pdo->prepare("UPDATE wa_line_cust SET status = 'done', left_why = 'conversation never ended' WHERE phone = ?")->execute([$phone]); $o['skipped']++; } else $o['waiting']++;
                continue; }
            if (strpos($why, 'wait') === 0) {
                $wcn(mb_substr($why, 6, 60)); if ($until > $now) wln_push($pdo, (string)$phone, $until + 60);
                if ((int)strtotime((string)$row['due_at']) < $now - 30 * 3600) { $upd->execute(['skipped', 'too late (' . mb_substr($why, 6, 80) . ')', null, '', $row['tpl_key'], $row['id']]); $o['skipped']++; } else $o['waiting']++;   // waited more than 30 h past its time: dropped, not sent stale
                continue; }
            if ($why === 'replied') { $pdo->prepare("UPDATE wa_line_plan SET status = 'cancelled', why = 'replied' WHERE phone = ? AND status = 'planned'")->execute([$phone]);
                $pdo->prepare("UPDATE wa_line_cust SET status = 'replied', recheck_at = ? WHERE phone = ?")->execute([date('Y-m-d H:i:s', strtotime((string)($c['last_in_at'] ?? 'now')) + 86400), $phone]); $o['skipped']++; continue; }
            $pdo->prepare("UPDATE wa_line_plan SET status = 'cancelled', why = ? WHERE phone = ? AND status = 'planned'")->execute([$why, $phone]);
            $pdo->prepare("UPDATE wa_line_cust SET status = 'done', left_why = ? WHERE phone = ?")->execute([$why, $phone]); $o['skipped']++; continue;
        }
        if ($isFirst && (string)$row['topic'] !== 'campaign') {   // owner 21 Nov: the conversation is over: his WHOLE chat is read now (newest message decides) and the ladder is the one of THAT topic
            try { $mq = $pdo->prepare("SELECT body FROM wa_messages WHERE dir = 'in' AND mtype IN ('text','interactive','button') AND phone = ? AND ts >= ? ORDER BY id DESC LIMIT 12"); $mq->execute([$phone, (string)$row['anchor_at']]); $texts = array_reverse($mq->fetchAll(PDO::FETCH_COLUMN));
                $bq = $pdo->prepare("SELECT n FROM wa_cust WHERE phone = ?"); $bq->execute([$phone]); $nt = wl_classify($texts, (int)$bq->fetchColumn() > 0);
                if ($nt !== (string)$row['topic']) { wl_log($pdo, (string)$phone, 'topic ' . $row['topic'] . ' -> ' . $nt . ' (his whole chat, before the first message)'); $pdo->prepare("UPDATE wa_line_cust SET topic = ? WHERE phone = ?")->execute([$nt, $phone]);
                    $pdo->prepare("DELETE FROM wa_line_plan WHERE phone = ? AND status = 'planned'")->execute([$phone]); wln_plan($pdo, (string)$phone, $nt, $now - 86400, [], $now);   // the new ladder starts now (its step 1 is due now)
                    $rq0 = $pdo->prepare("SELECT id, phone, step, tpl_key, due_at FROM wa_line_plan WHERE phone = ? AND status = 'planned' ORDER BY step LIMIT 1"); $rq0->execute([$phone]); $nr = $rq0->fetch(); if (!$nr) continue; $row = $nr + ['anchor_at' => $row['anchor_at'], 'topic' => $nt]; }
            } catch (Throwable $e) { }
        }
        $key = wl_pick((string)$row['tpl_key'], (string)$phone, $scores, $okKeys); $v2 = ''; $pxPct = ''; $pk = wln_pref_key((int)$row['step']); if ($pk !== '') { [$es, $er] = wln_engagement($pdo, (string)$phone); if ($es >= 3 && $er === 0) $pk = ''; }   // never opened 3+ messages: no paid price step for him
        if ($pk !== '' && isset($ids[$pk]) && in_array($pk, $okKeys, true)) { if ($pk === 'ln_pp_6') $key = $pk; else { $pi = wln_price_info($pdo, (string)$phone); $v2 = $pi ? $pi['text'] : ''; if ($v2 !== '') { $key = wl_pick($pk, (string)$phone, $scores, $okKeys); $pxPct = (string)$pi['pct']; } } }   // the price / prepaid message of this step when Meta approved it everywhere; no chart or no price = the plain message of the step
        $tid = (int)($ids[$key] ?? 0); if ($tid <= 0 || (!function_exists('wd_send') && !function_exists('wln_send'))) { $o['waiting']++; continue; }
        $res = function_exists('wa_camp_ctx') ? wln_send($pdo, $c, ['tpl_id' => $tid, 'number_id' => 0, 'v2' => $v2]) : wd_send($pdo, $c, ['tpl_id' => $tid, 'number_id' => 0]);   // no Module A / B allowance (wln_send); the stub of the tests has no campaign module
        if ($res[0]) { $tn = time(); $rq = $pdo->prepare("SELECT id FROM wa_line_plan WHERE phone = ? AND status = 'planned' AND step > ? ORDER BY step"); $rq->execute([$phone, (int)$row['step']]); $rq = $pdo->prepare("SELECT id, step FROM wa_line_plan WHERE phone = ? AND status = 'planned' AND step > ? ORDER BY step"); $rq->execute([$phone, (int)$row['step']]); foreach ($rq->fetchAll() as $rr) $pdo->prepare("UPDATE wa_line_plan SET due_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', wl_due_ts($tn, wl_gap_sum((int)$row['step'], (int)$rr['step']), $cfg['from'], $cfg['to'])), $rr['id']]);   // the next steps: 24 h after THIS message, 24 h after that…
            $upd->execute(['sent', '', date('Y-m-d H:i:s'), (string)($res[3] ?? ''), $key, $row['id']]); try { $pdo->prepare("UPDATE wa_line_plan SET tpl_id = ?, px = ? WHERE id = ?")->execute([$tid, $pxPct, $row['id']]); } catch (Throwable $e) { } $o['sent']++; $sent++; }
        elseif (stripos((string)$res[1], 'No number has room') === 0 || stripos((string)$res[1], 'The chosen number has no room') === 0) { $o['why'] = (string)$res[1]; $o['waiting']++; break; }   // no number can send now: try again next round, nothing is written
        elseif (stripos((string)$res[1], 'not approved by Meta') !== false) { $o['why'] = (string)$res[1]; $o['waiting']++; }
        elseif (stripos((string)$res[1], 'No number can send') === 0) { $o['why'] = (string)$res[1]; $o['waiting']++; $wcn('no number can send message #' . $tid . ' (not approved on the numbers\' accounts)'); wln_push($pdo, (string)$phone, $now + 600); }   // a passing state, not a failure: the customer keeps his place and is tried again (before, he was marked failed for good and his next step went first)
        else { $twin = null; foreach (wln_twins($pdo, (string)$phone, (string)$row['topic'], (int)$row['step'], $tid) as [$ttid, $tkey]) { if (!preg_match('/\b(131049|131050|130472|132015|132001|132007|132012|132016)\b|marketing|paused|low quality/i', (string)$res[1])) break; $r2 = wln_send_any($pdo, $c, $ttid); if ($r2[0]) { $twin = [$ttid, $tkey, $r2]; break; } }   // the send itself was refused as marketing: the utility twin goes at once
            if ($twin) { $upd->execute(['sent', 'utility rescue (' . mb_substr((string)$res[1], 0, 60) . ')', date('Y-m-d H:i:s'), (string)($twin[2][3] ?? ''), $twin[1], $row['id']]); try { $pdo->prepare("UPDATE wa_line_plan SET tpl_id = ? WHERE id = ?")->execute([$twin[0], $row['id']]); } catch (Throwable $e) { } $o['sent']++; $sent++; continue; }
            $upd->execute(['failed', mb_substr((string)$res[1], 0, 190), null, '', $key, $row['id']]); try { $pdo->prepare("UPDATE wa_line_plan SET tpl_id = ? WHERE id = ?")->execute([$tid, $row['id']]); } catch (Throwable $e) { } $o['failed']++; $wcn('failed: ' . mb_substr((string)$res[1], 0, 50)); }
    }
    try { setting_set($pdo, 'wl_last_round', json_encode(['at' => date('Y-m-d H:i:s'), 'shard' => $shard, 'of' => $N, 'due' => count($byPhone), 'sent' => $o['sent'], 'skipped' => $o['skipped'], 'failed' => $o['failed'], 'waits' => $wc], JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) { }   // why the due customers did not go this round
    return $o;
}
/** The button cards: a tap on a button answers at once with the FREE message (Button replies, wa_kw.php). Our cards are ln_*; the owner's own cards are never touched. */
function wl_install_kw(PDO $pdo): int
{
    if (!function_exists('wkw_cfg') || !function_exists('wkw_clean')) return 0;
    $cfg = wkw_cfg($pdo); $keep = []; foreach ($cfg['rules'] as $r) if (strpos((string)($r['id'] ?? ''), 'ln_') !== 0) $keep[] = $r;
    foreach (wl_pack() as $k => $m) { $free = $m['free'] === '__OFFER__' ? wl_offer_text($pdo) : $m['free']; $keep[] = ['id' => $k, 'name' => 'Lineup · ' . $k, 'on' => true, 'words' => [$m['button']], 'text' => $free, 'lib_id' => 0, 'tpl_id' => 0, 'fu' => []]; }
    $clean = wkw_clean(['auto' => $cfg['auto'], 'rules' => $keep]); setting_set($pdo, 'wkw_cfg', json_encode($clean, JSON_UNESCAPED_UNICODE)); return count(wl_pack());
}
/** Make every lineup template and send it to Meta (Utility, one name blank, one quick-reply button). Safe to repeat: a template that exists is not made again.
 *  Uses the same Meta budget as everything else (wa_tpl_try_create); what does not fit now waits in the queue and the existing auto-submit sends it in the next minutes. Returns [made, already, failed[]]. */
function wl_make_templates(PDO $pdo, int $by = 0): array
{
    $res = ['made' => 0, 'already' => 0, 'sent' => 0, 'failed' => []];
    foreach (['wa_tpl_clean', 'wa_tpl_insert', 'wa_tpl_row'] as $f) if (!function_exists($f)) { $res['failed'][] = 'The WhatsApp template module is not available.'; return $res; }
    $ids = wl_tpl_ids($pdo); $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 12, 'made' => 0];
    foreach (wl_pack() as $k => $m) {
        if (!empty($ids[$k])) { $res['already']++; continue; }
        $two = (int)($m['vars'] ?? 1) > 1;
        [$t, $err] = wa_tpl_clean(['name' => $k, 'language' => 'en', 'category' => $m['cat'] ?? 'UTILITY', 'body' => $m['body'], 'footer' => 'TM Perfume House', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => $m['button']]],
            'examples' => ['body' => $two ? ['Rahul', '4 x 50 ml (200 ml) combo sirf Rs 2280'] : ['Rahul'], 'labels' => $two ? ['Name', 'Rate'] : ['Name']], 'folder' => 'Lineup']);
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
function wl_drip_off(PDO $pdo): bool
{
    $j = json_decode((string)setting_get($pdo, 'wd_cfg', ''), true); if (!is_array($j)) $j = []; $was = (string)($j['mode'] ?? 'manual'); $j['mode'] = 'off'; $j['on'] = 0; setting_set($pdo, 'wd_cfg', json_encode($j)); return $was !== 'off';
}
/** Template status: [key => 'approved'|'pending'|'rejected'|'missing']. */
function wl_tpl_status(PDO $pdo): array
{
    $ids = wln_eff_ids($pdo); $out = []; $st = [];
    try { foreach ($pdo->query("SELECT tpl_id, status FROM wa_tpl_waba")->fetchAll() as $r) $st[(int)$r['tpl_id']][] = strtoupper((string)$r['status']); } catch (Throwable $e) { }
    foreach (wl_pack() as $k => $m) { $id = (int)($ids[$k] ?? 0); if ($id <= 0) { $out[$k] = 'missing'; continue; } $s = $st[$id] ?? []; $out[$k] = in_array('APPROVED', $s, true) ? 'approved' : (($s && count(array_diff($s, ['REJECTED'])) === 0) ? 'rejected' : 'pending'); }
    return $out;
}
/** The status the owner asked for, in one block: messages made / sent to Meta / approved / utility / still to go · customers identified · customers who got a message · messages sent / delivered · orders. */
function wl_status(PDO $pdo, bool $withOrders = true): array
{
    $pack = wl_pack(); $ids = wl_tpl_ids($pdo); $st = wl_tpl_status($pdo); $c = array_count_values($st); $made = count($ids); $inMeta = 0; $util = 0; $rel = 0;
    if ($ids) { $in = implode(',', array_map('intval', $ids));
        try { $inMeta = (int)$pdo->query("SELECT COUNT(DISTINCT tpl_id) FROM wa_tpl_waba WHERE tpl_id IN ($in)")->fetchColumn(); } catch (Throwable $e) { }
        try { $r = $pdo->query("SELECT category, relabelled_at FROM wa_templates WHERE id IN ($in)")->fetchAll(); foreach ($r as $x) { if (strtoupper((string)$x['category']) === 'UTILITY') $util++; } } catch (Throwable $e) { } }
    $eff = array_values(array_unique(array_filter(array_map('intval', array_values(wln_eff_ids($pdo)))))); if ($eff) { try { $rel = (int)$pdo->query("SELECT COUNT(*) FROM wa_templates WHERE relabelled_at IS NOT NULL AND id IN (" . implode(',', $eff) . ")")->fetchColumn(); } catch (Throwable $e) { } }   // only messages that really go out count (an unused lineup template that Meta made marketing is no problem)
    $o = ['at' => date('Y-m-d H:i:s'), 'started' => wl_cfg($pdo)['started'], 'messages' => ['total' => count($pack), 'made' => $made, 'in_meta' => $inMeta, 'approved' => (int)($c['approved'] ?? 0), 'pending' => (int)($c['pending'] ?? 0), 'existing' => count(array_filter(wln_map($pdo), fn($x) => $x > 0)), 'rejected' => (int)($c['rejected'] ?? 0), 'not_made' => count($pack) - $made, 'utility' => $util, 'relabelled' => $rel, 'left' => count($pack) - (int)($c['approved'] ?? 0)]];
    $o['customers'] = ['identified' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_cust")->fetchColumn(), 'active' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_cust WHERE status = 'active'")->fetchColumn(), 'replied' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_cust WHERE status = 'replied'")->fetchColumn(), 'left' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_cust WHERE status = 'done'")->fetchColumn(),
        'got_message' => (int)$pdo->query("SELECT COUNT(DISTINCT phone) FROM wa_line_plan WHERE status = 'sent'")->fetchColumn(), 'planned_rows' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan WHERE status = 'planned'")->fetchColumn()];
    $o['sending'] = ['sent' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan WHERE status = 'sent'")->fetchColumn(), 'failed' => (int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan WHERE status = 'failed'")->fetchColumn(), 'delivered' => 0, 'read' => 0, 'buttons' => 0];
    try { $o['sending']['rescued'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan WHERE status = 'sent' AND why LIKE 'utility rescue%'")->fetchColumn(); } catch (Throwable $e) { }
    $o['round'] = json_decode((string)setting_get($pdo, 'wl_last_round', ''), true) ?: null;
    $o['health'] = json_decode((string)setting_get($pdo, 'wl_issues', ''), true) ?: ['at' => '', 'fixed' => [], 'issues' => []];
    $o['orders'] = ['orders' => 0, 'buyers' => 0, 'revenue' => 0];
    if ($withOrders && $o['sending']['sent'] > 0) { try { $r = wl_report($pdo, 30); foreach ($r['steps'] as $x) { $o['sending']['delivered'] += $x['delivered']; $o['sending']['buttons'] += $x['taps']; $o['orders']['orders'] += $x['orders']; $o['orders']['revenue'] += $x['revenue']; } foreach ($r['topics'] as $t) $o['orders']['buyers'] += $t['buyers']; } catch (Throwable $e) { } }
    return $o;
}
/** The self-check, every 10 minutes. Fixes what can be fixed by itself and writes down what cannot: the list is shown on the screen (wl_status → issues).
 *  fixed: old Drip switched on again by someone · button cards missing / changed · messages not made · customers waiting because a message never reached Meta.
 *  reported: rejected messages (text must be changed), Meta turned a message into marketing, many failed sends, nothing sent for hours although due, the engine not running. */
function wl_heal(PDO $pdo): array
{
    $fix = []; $bad = []; $cfg = wl_cfg($pdo); if ($cfg['started'] === '') return ['fixed' => $fix, 'issues' => [['level' => 'info', 'text' => 'The Lineup has not started yet (it starts by itself on the next engine round).']]];
    $dj = json_decode((string)setting_get($pdo, 'wd_cfg', ''), true); if (is_array($dj) && (string)($dj['mode'] ?? '') !== 'off') { wl_drip_off($pdo); $fix[] = 'The old 7-Day Drip was switched on again: switched off.'; }
    if (count(wl_tpl_ids($pdo)) < count(wl_pack())) { $r = wl_make_templates($pdo); if ($r['made']) $fix[] = $r['made'] . ' missing messages made and sent to Meta.'; }
    if (function_exists('wkw_cfg')) { $have = []; foreach (wkw_cfg($pdo)['rules'] as $x) $have[(string)($x['id'] ?? '')] = !empty($x['on']); $miss = 0; foreach (wl_pack() as $k => $m) if (empty($have[$k])) $miss++; if ($miss) { wl_install_kw($pdo); $fix[] = $miss . ' button answers were missing or off: put back.'; } }
    $PK = wl_pack(); foreach (wl_tpl_status($pdo) as $k => $st) if ($st === 'rejected') { $extra = !empty($PK[$k]['of']);
        $bad[] = ['level' => 'warn', 'text' => $k . ' was REJECTED by Meta — change its words in Pre-made messages' . ($extra ? ' (only an extra wording: nothing is blocked)' : ' — until then no message is sent')]; }
    $rel = (int)wl_status($pdo, false)['messages']['relabelled']; if ($rel) $bad[] = ['level' => 'info', 'text' => $rel . ' of the shop\'s approved messages the Lineup uses are counted as MARKETING by Meta (₹1.09 instead of ₹0.15 each). An approved message\'s words cannot be changed; a utility one can replace it as soon as Meta lets new utility messages be made.'];
    try { $sc = json_decode((string)setting_get($pdo, 'wl_scores', ''), true); if (is_array($sc)) foreach (wl_pack() as $k => $m) { if (empty($m['of'])) continue; $base = (string)$m['of']; if ((int)($sc[$k][0] ?? 0) >= 150 && (int)($sc[$k][2] ?? 0) === 0 && max((int)($sc[$base][2] ?? 0), 0) >= 3) $bad[] = ['level' => 'info', 'text' => 'Wording ' . $k . ' is not used any more: no order in ' . (int)$sc[$k][0] . ' delivered while ' . $base . ' got ' . (int)$sc[$base][2] . ' (stop-loss).']; } } catch (Throwable $e) { }
    $f = $pdo->prepare("SELECT why, COUNT(*) n FROM wa_line_plan WHERE status = 'failed' AND sent_at IS NULL GROUP BY why ORDER BY n DESC LIMIT 3"); $f->execute(); foreach ($f->fetchAll() as $r) if ((int)$r['n'] >= 5) $bad[] = ['level' => 'warn', 'text' => $r['n'] . ' sends failed: ' . $r['why']];
    $d = $pdo->prepare("SELECT COUNT(*) FROM wa_line_plan p JOIN wa_line_cust c ON c.phone = p.phone WHERE p.status = 'planned' AND c.status = 'active' AND p.due_at <= ?"); $d->execute([date('Y-m-d H:i:s', time() - 3 * 3600)]); $due = (int)$d->fetchColumn();
    if ($due > 0) { $why = wl_ready($pdo) ? 'nobody can send (no number has room, or every customer is waiting for a rule: person chatting / campaign today)' : 'waiting for Meta to approve all the messages'; $bad[] = ['level' => $due > 50 ? 'warn' : 'info', 'text' => $due . ' messages are 3+ hours late: ' . $why]; }
    $last = (int)strtotime((string)setting_get($pdo, 'wl_snap_at', '')); [, $h] = wl_ist(time()); $tk = (int)strtotime((string)setting_get($pdo, 'wl_tick_at', '')); if ($tk > 0 && $tk < time() - 1800 && $h >= $cfg['from'] && $h < $cfg['to']) $bad[] = ['level' => 'warn', 'text' => 'The engine has not run the Lineup for ' . round((time() - $tk) / 60) . ' minutes'];
    setting_set($pdo, 'wl_issues', json_encode(['at' => date('Y-m-d H:i:s'), 'fixed' => $fix, 'issues' => $bad], JSON_UNESCAPED_UNICODE)); if ($fix) wa_log($pdo, 'engine', true, 'Lineup self-check fixed: ' . implode(' | ', $fix));
    return ['fixed' => $fix, 'issues' => $bad];
}
/** The status as one short phone notification (Admin / HR; the same tag replaces the last one, so they do not pile up). */
function wl_push(PDO $pdo, array $st): int
{
    if (!function_exists('ct_push') || !function_exists('wq_admin_ids')) return 0; $c = wl_cfg($pdo); $age = time() - (int)strtotime($c['started']); $bad = 0; foreach (($st['health']['issues'] ?? []) as $x) if (($x['level'] ?? '') === 'warn') $bad++;
    // owner 21 Nov: every 10 minutes at first (the first 6 hours), then every half hour (until 48 hours), then only when the self-check warns
    $gap = $age <= 6 * 3600 ? 9 * 60 : ($age <= 48 * 3600 ? 29 * 60 : ($bad ? 29 * 60 : 0)); if ($gap === 0) return 0;
    if ((int)strtotime((string)setting_get($pdo, 'wl_push_at', '')) > time() - $gap) return 0; setting_set($pdo, 'wl_push_at', date('Y-m-d H:i:s'));
    $M = $st['messages']; $K = $st['customers']; $G = $st['sending']; $O = $st['orders'];
    $body = 'Msgs made ' . $M['made'] . '/' . $M['total'] . ' · in Meta ' . $M['in_meta'] . ' · approved ' . $M['approved'] . ' · utility ' . $M['utility'] . ' · left ' . $M['left'] . "\n" . 'Customers ' . $K['identified'] . ' · got msg ' . $K['got_message'] . ' · sent ' . $G['sent'] . ' · orders ' . $O['orders'] . ' · cost today ₹' . (isset($st['cost_today']) ? $st['cost_today'] : '0') . ($bad ? "\n" . '⚠ ' . $bad . ' issue(s): open Lineup' : '');
    try { return (int)ct_push($pdo, wq_admin_ids($pdo), '🧵 Lineup status', $body, 'campaigns.html', 'hrms-lineup'); } catch (Throwable $e) { return 0; }
}
/** Every 10 minutes the engine writes the status down, so the owner sees the whole day as a table (kept 14 days). */
function wl_snap(PDO $pdo, bool $force = false): bool
{
    $last = (int)strtotime((string)setting_get($pdo, 'wl_snap_at', '')); if (!$force && $last > time() - 10 * 60) return false;
    if (!$force && function_exists('wa_lock') && !wa_lock($pdo, 'wlsnap', 60)) return false; setting_set($pdo, 'wl_snap_at', date('Y-m-d H:i:s'));
    try { $r = wl_report($pdo, 30); $sc = []; foreach ($r['keys'] as $k => $c) $sc[$k] = [(int)$c['delivered'], (float)$c['score'], (int)$c['orders']]; setting_set($pdo, 'wl_scores', json_encode($sc)); } catch (Throwable $e) { }   // what the picker (wl_pick) reads: delivered + score of every wording
    try { wl_suggest($pdo); } catch (Throwable $e) { }   // once a day: the winning sentences of the guidebook
    try { wl_topics_refresh($pdo); } catch (Throwable $e) { }   // a new topic goes live when its 4 messages are approved
    try { wl_learn_night($pdo); } catch (Throwable $e) { }   // once a night: days, new wordings (to Meta), sentences
    try { wl_heal($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'lineup heal: ' . $e->getMessage()); }
    $st = wl_status($pdo); try { [$td0] = wl_ist(time()); $st['cost_today'] = wl_summary($pdo, wl_ist_at($td0, 0))['cost']; } catch (Throwable $e) { }
    $pdo->prepare("INSERT INTO wa_line_snap (at, j) VALUES (?, ?)")->execute([date('Y-m-d H:i:s'), json_encode($st, JSON_UNESCAPED_UNICODE)]);
    try { wl_push($pdo, $st); } catch (Throwable $e) { }
    if (mt_rand(1, 30) === 1) $pdo->prepare("DELETE FROM wa_line_snap WHERE at < ?")->execute([date('Y-m-d H:i:s', time() - 14 * 86400)]); return true;
}
function wl_snaps(PDO $pdo, int $n = 36): array { $o = []; foreach ($pdo->query("SELECT at, j FROM wa_line_snap ORDER BY id DESC LIMIT " . (int)$n)->fetchAll() as $r) { $j = json_decode((string)$r['j'], true); if (is_array($j)) { $j['at'] = (string)$r['at']; $o[] = $j; } } return $o; }
/** The outside look (the card on the Campaigns page): what happened since $from — messages sent / delivered / read / failed, how many people answered (a button tap or any message), orders and revenue credited to the Lineup,
 *  how many went as Utility and how many as plain (marketing) messages, and what they cost (the shop's own rates, wa_team_cfg). */
function wl_summary(PDO $pdo, int $from): array
{
    $o = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'replied' => 0, 'taps' => 0, 'orders' => 0, 'buyers' => 0, 'revenue' => 0, 'utility' => 0, 'plain' => 0, 'cost_utility' => 0.0, 'cost_plain' => 0.0, 'cost' => 0.0, 'rate_u' => 0.0, 'rate_m' => 0.0];
    $tc = function_exists('wa_team_cfg') ? wa_team_cfg($pdo) : ['rate_marketing' => 1.09, 'rate_utility' => 0.15]; $o['rate_u'] = (float)$tc['rate_utility']; $o['rate_m'] = (float)$tc['rate_marketing'];
    $f = date('Y-m-d H:i:s', $from); $ids = wln_eff_ids($pdo); $cat = [];
    $q = $pdo->prepare("SELECT phone, tpl_key, tpl_id, sent_at, wamid, status FROM wa_line_plan WHERE status IN ('sent','failed') AND COALESCE(sent_at, ?) >= ?"); $q->execute([$f, $f]); $rows = $q->fetchAll(); $sent = [];
    $want = array_map('intval', array_values($ids)); foreach ($rows as $r) if ((int)$r['tpl_id'] > 0) $want[] = (int)$r['tpl_id']; $want = array_values(array_unique(array_filter($want)));
    if ($want) { try { foreach ($pdo->query("SELECT id, category, relabelled_at FROM wa_templates WHERE id IN (" . implode(',', $want) . ")")->fetchAll() as $r) $cat[(int)$r['id']] = (strtoupper((string)$r['category']) === 'UTILITY' && empty($r['relabelled_at'])); } catch (Throwable $e) { } }
    foreach ($rows as $r) { if ($r['status'] === 'failed') { $o['failed']++; continue; } $o['sent']++; $tid = (int)$r['tpl_id'] > 0 ? (int)$r['tpl_id'] : (int)($ids[(string)$r['tpl_key']] ?? 0); if (!empty($cat[$tid])) $o['utility']++; else $o['plain']++; $sent[] = $r; }
    $o['cost_utility'] = round($o['utility'] * $o['rate_u'], 2); $o['cost_plain'] = round($o['plain'] * $o['rate_m'], 2); $o['cost'] = round($o['cost_utility'] + $o['cost_plain'], 2);
    $wm = array_filter(array_column($sent, 'wamid')); $st = [];
    foreach (array_chunk($wm, 400) as $ch) { $x = $pdo->prepare("SELECT wamid, status FROM wa_messages WHERE wamid IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $x->execute($ch); foreach ($x->fetchAll() as $r) $st[(string)$r['wamid']] = (string)$r['status']; }
    foreach ($sent as $r) { $x = $st[(string)$r['wamid']] ?? ''; if (in_array($x, ['delivered', 'read'], true)) $o['delivered']++; if ($x === 'read') $o['read']++; }
    $byP = []; foreach ($sent as $r) $byP[(string)$r['phone']][] = (int)strtotime((string)$r['sent_at']);
    if ($byP) { $btn = []; foreach (wl_pack() as $k => $m) $btn[mb_strtolower(trim($m['button']))] = 1; $rep = []; $tap = [];
        foreach (array_chunk(array_keys($byP), 400) as $ch) { $x = $pdo->prepare("SELECT phone, body, mtype, ts FROM wa_messages WHERE dir = 'in' AND ts >= ? AND phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $x->execute(array_merge([$f], $ch));
            foreach ($x->fetchAll() as $r) { $t = (int)strtotime((string)$r['ts']); foreach ($byP[(string)$r['phone']] ?? [] as $st0) if ($t > $st0 && $t <= $st0 + 2 * 86400) { $rep[(string)$r['phone']] = 1; if (isset($btn[mb_strtolower(trim((string)$r['body']))])) $tap[(string)$r['phone'] . '|' . $st0] = 1; break; } } }
        $o['replied'] = count($rep); $o['taps'] = count($tap); }
    $ord = []; try { $x = $pdo->prepare("SELECT phone, created_at, total FROM shop_orders WHERE COALESCE(tags, '') NOT LIKE '%wa-rx%' AND created_at >= ? AND phone IS NOT NULL AND phone != '' LIMIT 200000"); $x->execute([$f]);
        foreach ($x->fetchAll() as $r) { $k = function_exists('wa_key') ? wa_key($r['phone']) : substr(preg_replace('/\D+/', '', (string)$r['phone']), -10); if ($k !== '' && isset($byP[$k])) $ord[$k][] = [(int)strtotime((string)$r['created_at']), (float)$r['total']]; } } catch (Throwable $e) { }
    $buy = []; foreach ($ord as $k => $list) foreach ($list as [$ot, $tot]) foreach ($byP[$k] as $st0) if ($st0 < $ot && $ot <= $st0 + 7 * 86400) { $o['orders']++; $o['revenue'] += (int)round($tot); $buy[$k] = 1; break; }
    $o['buyers'] = count($buy); return $o;
}
/** The picture the owner looks at: customers by topic and status, how many go today / tomorrow (by hour), the templates, the old drip. */
function wl_overview(PDO $pdo, bool $fresh = false): array
{
    // the Lineup page and the Lineup card read it often (wa.line_get / wa.line_card: 3 heavy counts over the whole plan each time): one shared copy per 45 s
    if (!$fresh && function_exists('hrms_memo')) return hrms_memo('wl_overview45', 45, function () use ($pdo) { return wl_overview_calc($pdo); });
    return wl_overview_calc($pdo);
}
function wl_overview_calc(PDO $pdo): array
{
    $cfg = wl_cfg($pdo); $o = ['cfg' => $cfg, 'topics' => wl_topics(), 'by_topic' => [], 'board' => [], 'tomorrow' => ['total' => 0, 'by_topic' => [], 'by_hour' => []], 'today' => ['total' => 0, 'sent' => 0, 'by_topic' => [], 'left_by_hour' => []], 'left' => [], 'tpl' => [], 'now' => date('Y-m-d H:i')];
    foreach ($pdo->query("SELECT topic, status, COUNT(*) n FROM wa_line_cust GROUP BY topic, status")->fetchAll() as $r) $o['by_topic'][$r['topic']][$r['status']] = (int)$r['n'];
    foreach ($pdo->query("SELECT left_why, COUNT(*) n FROM wa_line_cust WHERE left_why <> '' GROUP BY left_why")->fetchAll() as $r) $o['left'][$r['left_why']] = (int)$r['n'];
    foreach ($pdo->query("SELECT p.topic, p.step, COUNT(*) n FROM wa_line_plan p JOIN wa_line_cust c ON c.phone = p.phone WHERE p.status = 'planned' AND c.status = 'active' GROUP BY p.topic, p.step")->fetchAll() as $r) $o['board'][$r['topic']][(int)$r['step']] = (int)$r['n'];
    [$td] = wl_ist(time()); $tm = date('Y-m-d', strtotime($td . ' +1 day'));
    $q = $pdo->prepare("SELECT p.topic, p.due_at, p.status FROM wa_line_plan p JOIN wa_line_cust c ON c.phone = p.phone WHERE p.due_at >= ? AND p.due_at < ? AND p.status IN ('planned','sent') AND (c.status = 'active' OR p.status = 'sent')");
    $q->execute([date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s', time() + 3 * 86400)]);
    foreach ($q->fetchAll() as $r) { [$d, $hh] = wl_ist((int)strtotime((string)$r['due_at']));
        if ($d === $td) { if ($r['status'] === 'planned') $o['today']['left_by_hour'][$hh] = ($o['today']['left_by_hour'][$hh] ?? 0) + 1; $o['today']['total']++; if ($r['status'] === 'sent') $o['today']['sent']++; $o['today']['by_topic'][$r['topic']] = ($o['today']['by_topic'][$r['topic']] ?? 0) + 1; }
        elseif ($d === $tm) { $o['tomorrow']['total']++; $o['tomorrow']['by_topic'][$r['topic']] = ($o['tomorrow']['by_topic'][$r['topic']] ?? 0) + 1; $o['tomorrow']['by_hour'][$hh] = ($o['tomorrow']['by_hour'][$hh] ?? 0) + 1; } }
    $o['by_day'] = []; try { foreach ($pdo->query("SELECT DATE(anchor_at) d, COUNT(*) n, SUM(status = 'active') a, SUM(status = 'replied') r, SUM(status = 'done') x FROM wa_line_cust GROUP BY DATE(anchor_at) ORDER BY d")->fetchAll() as $r) $o['by_day'][] = ['day' => (string)$r['d'], 'n' => (int)$r['n'], 'active' => (int)$r['a'], 'replied' => (int)$r['r'], 'done' => (int)$r['x']]; } catch (Throwable $e) { }   // customers by the day they came in
    ksort($o['tomorrow']['by_hour']); ksort($o['today']['left_by_hour']); $o['calendar'] = wl_calendar($pdo); $o['suggest'] = json_decode((string)setting_get($pdo, 'wl_suggest', ''), true) ?: ['at' => '', 'items' => []]; $o['learn_log'] = json_decode((string)setting_get($pdo, 'wl_learn_log', ''), true) ?: []; $o['extras'] = array_map(fn($e) => ['key' => $e['key'], 'of' => $e['of'], 'body' => $e['body'], 'at' => $e['at'] ?? ''], (array)(json_decode((string)setting_get($pdo, 'wl_extra', ''), true) ?: [])); $o['days_now'] = wl_days(); $o['ready'] = wl_ready($pdo); $o['status'] = wl_status($pdo); [$td0] = wl_ist(time()); $o['summary'] = ['today' => wl_summary($pdo, wl_ist_at($td0, 0)), 'total' => wl_summary($pdo, (int)strtotime($cfg['started'] ?: 'now') - 60)]; $o['snaps'] = wl_snaps($pdo, 36); $o['tpl'] = wl_tpl_status($pdo); $o['tpl_counts'] = array_count_values($o['tpl']);
    $o['guide'] = ['live' => false, 'excerpt' => '']; try { if (function_exists('wk_guide_text')) { $g = wk_guide_text($pdo, 1500); $o['guide'] = ['live' => $g !== '', 'excerpt' => $g]; } } catch (Throwable $e) { }   // the approved guidebook: the owner reads the lineup messages against it
    $dj = json_decode((string)setting_get($pdo, 'wd_cfg', ''), true); $o['old_drip'] = is_array($dj) ? (string)($dj['mode'] ?? 'manual') : 'manual';
    return $o;
}
/** THE DAILY LEARNING (owner, 21 Nov: "the learning changes the days, the messages and the templates by itself and they go to Meta for approval on their own").
 *  Once a night (02:00-05:00 IST, one round) — nothing here touches a customer directly:
 *   1. DAYS: with 300+ delivered on the steps, a step whose orders per delivered are less than 35% of step 1's moves one day later, one that is more than 1.6x the average of its two neighbours moves one day earlier
 *      (the gap to the next step must stay at least 1 day; one move per step per week; its planned rows move with it). Written to wl_days, logged.
 *   2. WORDS: for the step with the most delivered messages and the fewest wordings (3 at most) Claude writes ONE new wording from the live guidebook's winning lines — a utility message with one name blank, one button, no offer / price words, nothing that repeats an existing wording.
 *      It must pass the same checks as the hand-made ones (wl_check_wording); it is added to wl_extra, made as a template and sent to Meta by the normal template maker. Used only when approved on 3+ accounts; the stop-loss removes it if it brings no orders.
 *   3. The suggestions list is refreshed. Every action is written to wl_learn_log (last 40) and shown on the screen. Setting wl_auto_words = 0 switches the writing of new words off (days and stop-loss stay). */
function wl_learn_log(PDO $pdo, string $what): void { $j = json_decode((string)setting_get($pdo, 'wl_learn_log', ''), true); if (!is_array($j)) $j = []; array_unshift($j, ['at' => date('Y-m-d H:i'), 'what' => mb_substr($what, 0, 300)]); setting_set($pdo, 'wl_learn_log', json_encode(array_slice($j, 0, 40), JSON_UNESCAPED_UNICODE)); }
/** The rules a wording must meet (the same as the tests): [ok, why]. $key = the key it would get, $existing = [key => message] to compare the button with. */
function wl_check_wording(array $w, array $existing): array
{
    $body = trim((string)($w['body'] ?? '')); $btn = trim((string)($w['button'] ?? '')); $free = trim((string)($w['free'] ?? ''));
    if (preg_match_all('/\{\{\d+\}\}/', $body) !== 1 || strpos($body, '{{1}}') === false) return [false, 'it must have exactly one blank {{1}}'];
    if (strpos($body, '{{1}}') === 0 || preg_match('/\{\{1\}\}\s*$/', $body)) return [false, 'the blank must not be first or last'];
    if (mb_strlen($body) < 40 || mb_strlen($body) > 320) return [false, 'body length']; if ($btn === '' || mb_strlen($btn) > 25) return [false, 'button length']; if ($free === '' || mb_strlen($free) > 400) return [false, 'free answer length'];
    foreach (wl_banned() as $b) if (stripos($body . ' ' . $btn, $b) !== false) return [false, "contains '$b'"];
    if (preg_match('/(?:rs\.?|₹|inr)\s*\d|\d\s*%|\d{3,}/iu', $body . ' ' . $btn)) return [false, 'no prices, percentages or big numbers in a utility wording'];
    $n = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($btn))); foreach ($existing as $m) { $o = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string)$m['button']))); if ($o === '' ) continue; if ($o === $n || strpos($o, $n) !== false || strpos($n, $o) !== false) return [false, 'the button text is too like another one']; }
    foreach ($existing as $m) { similar_text(mb_strtolower((string)$m['body']), mb_strtolower($body), $pc); if ($pc > 82) return [false, 'too like an existing wording']; }
    return [true, ''];
}
function wl_days_auto(PDO $pdo, array $report): string
{
    $at = (int)strtotime((string)setting_get($pdo, 'wl_days_at', '2000-01-01')); if ($at > time() - 7 * 86400) return '';
    $by = []; foreach ((array)($report['steps'] ?? []) as $x) { $k = (int)$x['step']; $by[$k] = $by[$k] ?? ['d' => 0, 'o' => 0]; $by[$k]['d'] += (int)$x['delivered']; $by[$k]['o'] += (int)$x['orders']; }
    foreach ([1, 2, 3, 4] as $k) if (($by[$k]['d'] ?? 0) < 300) return ''; if (($by[1]['o'] ?? 0) < 1) return '';
    $r = []; foreach ($by as $k => $c) $r[$k] = $c['d'] >= 300 ? $c['o'] / $c['d'] : null; $d = wl_days(); $move = null;
    foreach ([2, 3, 4, 5, 6, 7, 8, 9, 10, 11] as $k) { if ($r[$k] === null || !isset($d[$k + 1]) || !isset($d[$k - 1])) continue;
        $nb = array_filter([$r[$k - 1] ?? null, $r[$k + 1] ?? null], fn($x) => $x !== null); $avg = $nb ? array_sum($nb) / count($nb) : null;
        if ($k <= 4 && $r[$k] < $r[1] * 0.35 && $d[$k] + 1 < $d[$k + 1]) { $move = [$k, 1, 'brings ' . round(100 * $r[$k], 2) . ' orders per 100 delivered against ' . round(100 * $r[1], 2) . ' for step 1']; break; }
        if ($avg !== null && $avg > 0 && $r[$k] > $avg * 1.6 && $d[$k] - 1 > $d[$k - 1]) { $move = [$k, -1, 'brings ' . round(100 * $r[$k], 2) . ' orders per 100 delivered, 1.6x its neighbours']; break; } }
    setting_set($pdo, 'wl_days_at', date('Y-m-d')); if (!$move) return 'days: the steps look alike, no day moved';
    [$k, $dl, $why] = $move; $d[$k] += $dl; setting_set($pdo, 'wl_days', json_encode(array_values($d))); wl_days($d);
    try { $pdo->prepare("UPDATE wa_line_plan SET due_at = " . (strpos((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'sqlite') !== false ? "datetime(due_at, ?)" : "DATE_ADD(due_at, INTERVAL ? DAY)") . " WHERE step = ? AND status = 'planned'")->execute([strpos((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'sqlite') !== false ? ($dl . ' day') : $dl, $k]); } catch (Throwable $e) { }
    return 'days: step ' . $k . ' moved to day ' . $d[$k] . ' (' . $why . ')';
}
function wl_new_word(PDO $pdo, array $report): string
{
    if ((string)setting_get($pdo, 'wl_auto_words', '1') === '0') return '';
    if (!function_exists('wcr_ai')) return 'words: Claude is not available on this server';
    $extra = json_decode((string)setting_get($pdo, 'wl_extra', ''), true); if (!is_array($extra)) $extra = []; if (count($extra) >= 30) return 'words: 30 written wordings already, no more';
    $made = 0; foreach ($extra as $e) if (substr((string)($e['at'] ?? ''), 0, 10) === date('Y-m-d')) $made++; if ($made >= 3) return '';
    $P = wl_pack(); $cnt = []; foreach ($P as $k => $m) { $b = $m['of'] ?? $k; $cnt[$b] = ($cnt[$b] ?? 0) + 1; }
    // OWNER 21 Nov: the WEAKEST message gets the new wording (the group whose best wording scores lowest, 150+ delivered, at most 4 wordings), written in the style of the WINNING messages
    $grp = []; foreach ((array)($report['keys'] ?? []) as $k => $c) { $b = $P[$k]['of'] ?? $k; if (!isset($P[$b]) || !empty($P[$b]['pref'])) continue; $grp[$b]['d'] = ($grp[$b]['d'] ?? 0) + (int)$c['delivered']; $grp[$b]['s'] = max($grp[$b]['s'] ?? 0, (float)$c['score']); }
    $best = null; foreach ($grp as $b => $g) { if ($g['d'] < 150 || ($cnt[$b] ?? 1) >= 4) continue; if ($best === null || $g['s'] < $best[1]) $best = [$b, $g['s']]; }
    $win = []; $ks = (array)($report['keys'] ?? []); uasort($ks, fn($x, $y) => $y['score'] <=> $x['score']); foreach ($ks as $k => $c) { if (count($win) >= 3) break; if ((int)$c['delivered'] >= 150 && isset($P[$k]) && empty($P[$k]['pref'])) $win[] = '- ' . $P[$k]['body'] . ' [button: ' . $P[$k]['button'] . ']'; }
    if (!$best) return 'words: no step has 100 delivered yet, nothing new is written';
    $base = $best[0]; $bm = $P[$base]; $ex = []; foreach ($P as $k => $m) if (($m['of'] ?? $k) === $base) $ex[$k] = $m;
    $gb = ''; try { $g = $pdo->query("SELECT body FROM lib_guide WHERE status = 'live' ORDER BY version DESC LIMIT 1")->fetchColumn(); if ($g) { $j = json_decode((string)$g, true); if (is_array($j)) $gb = json_encode(['do' => array_slice((array)($j['do'] ?? []), 0, 8), 'openers' => array_slice((array)($j['openers'] ?? []), 0, 5), 'followups' => array_slice((array)($j['followups'] ?? []), 0, 4)], JSON_UNESCAPED_UNICODE); } } catch (Throwable $e) { }
    $role = [1 => 'the first message, 24 hours after the customer stopped chatting', 2 => 'day 2: a gentle second message', 3 => 'day 3: a question to move the chat on', 4 => 'day 4: a light nudge', 5 => 'day 6', 6 => 'day 8', 7 => 'day 10', 8 => 'day 15: a small first size suggestion', 9 => 'day 18', 10 => 'day 22: help with address / payment', 11 => 'day 26', 12 => 'day 30: the polite last message'][(int)$bm['step']] ?? '';
    $sys = 'You write ONE new WhatsApp UTILITY message wording for a perfume shop (TM Perfume House), in the same Hinglish (Hindi in Roman letters) as the examples. Rules: exactly one blank {{1}} (the customer first name) in the middle of the text, never first or last; 40-300 characters; NO prices, no percentages, no discount / offer / free / sale / special words; it answers or follows up a customer who asked; one quick-reply button text of 2-4 words (max 25 characters) that is different from every existing button; plus the free answer that goes out when the button is tapped (max 350 characters, warm, asks for the perfume name / size, no price). It must NOT repeat any existing wording. Use what wins in real chats (given). Return only JSON {"body": "...", "button": "...", "free": "..."}.';
    $usr = "Step: " . $bm['topic'] . ' step ' . $bm['step'] . ' (' . $role . ")\nExisting wordings of this step (do not repeat):\n" . implode("\n", array_map(fn($k, $m) => '- ' . $m['body'] . ' [button: ' . $m['button'] . ']', array_keys($ex), $ex)) . "\nAll buttons in use: " . implode(' | ', array_unique(array_map(fn($m) => (string)$m['button'], $P))) . "\nWhat wins in real chats (live guidebook): " . $gb . "\nOur best performing messages (write in this warm, short style, but different words): \n" . implode("\n", $win);
    $r = wcr_ai($sys, $usr, 900); if (!is_array($r)) return 'words: Claude did not answer (' . (string)($GLOBALS['wcr_ai_why'] ?? '') . ')';
    $w = ['body' => (string)($r['body'] ?? ''), 'button' => (string)($r['button'] ?? ''), 'free' => (string)($r['free'] ?? '')]; [$ok, $why] = wl_check_wording($w, $P); if (!$ok) return 'words: the written wording was refused (' . $why . ')';
    $n = 1; foreach ($extra as $e) if (($e['of'] ?? '') === $base) $n++; $key = substr($base, 0, 14) . '_a' . $n; if (isset($P[$key])) $key = substr($base, 0, 13) . '_a' . $n . mt_rand(1, 9);
    $extra[] = ['key' => $key, 'of' => $base, 'topic' => (string)$bm['topic'], 'step' => (int)$bm['step'], 'body' => $w['body'], 'button' => $w['button'], 'free' => $w['free'], 'at' => date('Y-m-d H:i:s')];
    setting_set($pdo, 'wl_extra', json_encode($extra, JSON_UNESCAPED_UNICODE)); wl_extra_get($extra);
    $mk = ['made' => 0]; try { $mk = wl_make_templates($pdo); } catch (Throwable $e) { }   // made and sent to Meta by the normal template maker (what does not fit Meta's limit now waits in the queue)
    return 'words: new wording ' . $key . ' written for ' . $base . ' and sent to Meta for approval (' . (int)$mk['made'] . ' made): "' . mb_substr($w['body'], 0, 90) . '…"';
}
/** The themes the old data showed to convert well (150 days of chats, 21 Nov: everybody who wrote 5.8% ordered; gift 22%, women 20%, men 13.7%, best-selling 12.2%). The learning adds them one at a time. */
function wl_topic_candidates(): array
{
    $c = function (string $label, string $brief, array $words, string $before = '') { return ['label' => $label, 'brief' => $brief, 'words' => $words, 'before' => $before]; };
    return [
        'gift' => $c('Gift', 'a customer who is looking for a perfume as a GIFT (birthday, wedding, anniversary, for someone special)', ['gift', 'gifting', 'gifts', 'present', 'birthday', 'wedding', 'anniversary', 'bhet', 'ગિફ્ટ', 'भेंट', 'उपहार']),
        'women' => $c("Women's", 'a customer who is looking for a perfume for a WOMAN (herself, wife, sister, mother)', ['women', 'womens', 'woman', 'ladies', 'lady', 'female', 'girls', 'wife', 'મહિલા', 'महिला']),
        'sample' => $c('Sample / tester', 'a customer who wants to TRY first: a sample, a tester or a small bottle before buying', ['sample', 'samples', 'tester', 'testers', 'trial', 'try', 'mini', 'small bottle'], 'size'),
        'men' => $c("Men's", 'a customer who is looking for a perfume for a MAN (himself, husband, brother, father)', ['men', 'mens', 'gents', 'male', 'gentleman', 'boys', 'પુરુષ', 'पुरुष']),
        'bestsell' => $c('Best-sellers', 'a customer who asks for the BEST / TOP-SELLING / most recommended perfumes and wants a safe choice', ['best selling', 'bestseller', 'bestsellers', 'top selling', 'popular', 'trending', 'recommend', 'recommendation', 'recommendations']),
        'attar' => $c('Attar / oil', 'a customer who is looking for an ATTAR / perfume oil (alcohol-free, traditional)', ['attar', 'attars', 'itra', 'ittar', 'oil', 'oils', 'alcohol free', 'અત્તર', 'इत्र']),
        'lasting' => $c('Long-lasting', 'a customer who wants a perfume that LASTS LONG / has strong projection', ['lasting', 'longest', 'long lasting', 'long-lasting', 'projection', 'strong', 'beast mode', 'stays']),
        'brand' => $c('Brand / inspired by', 'a customer who asks for a particular famous perfume or brand (inspired-by versions) and wants that scent', ['rasasi', 'elixir', 'hawas', 'creed', 'dior', 'tom ford', 'armaf', 'lattafa', 'afnan', 'sauvage', 'aventus', 'bleu', 'oud wood']),
        'party' => $c('Party / occasion', 'a customer who wants a perfume for a PARTY, night out, date or special occasion', ['party', 'night', 'date', 'club', 'occasion', 'function', 'celebration']),
        'office' => $c('Daily / office', 'a customer who wants a light perfume for DAILY use, office or college', ['office', 'daily', 'everyday', 'college', 'work', 'light', 'fresh', 'summer']),
        'travel' => $c('Travel / mini', 'a customer who wants a small TRAVEL / pocket size perfume', ['travel', 'pocket', 'mini', 'compact', 'small size']),
        'collect' => $c('Collection', 'a customer who wants to see the whole COLLECTION / catalogue / list of perfumes', ['collection', 'catalogue', 'catalog', 'exclusive', 'list', 'all perfumes']),
        'wholesal' => $c('Wholesale / resell', 'a customer who wants to buy in BULK / wholesale / to resell', ['wholesale', 'bulk', 'resell', 'reseller', 'dealer', 'business']),
        'combo' => $c('Combo', 'a customer who asks for COMBO / multi-bottle packs', ['combo', 'combos', 'pack of', 'set of', 'bundle']),
    ];
}
/** A new topic becomes live (its words join the classification) when its 4 messages are approved on 3+ accounts. */
function wl_topics_refresh(PDO $pdo): int
{
    $t = json_decode((string)setting_get($pdo, 'wl_topics_extra', ''), true); if (!is_array($t) || !$t) return 0; $ok = wln_wide_ok($pdo); $n = 0;
    foreach ($t as &$x) { if (!empty($x['ready'])) continue; $all = true; for ($i = 1; $i <= 4; $i++) if (!in_array('ln_' . $x['key'] . '_' . $i, $ok, true)) $all = false;
        if ($all) { $x['ready'] = true; $n++; wl_learn_log($pdo, 'topic "' . $x['label'] . '" is live: its 4 messages are approved, customers who write about it now get its ladder'); } } unset($x);
    if ($n) { setting_set($pdo, 'wl_topics_extra', json_encode($t, JSON_UNESCAPED_UNICODE)); wl_topics_extra_get($t); }
    return $n;
}
/** THE DATA SCAN (owner, 21 Nov: "make topics for all small things, orders are what matters"): once a night the last 120 days of inbound chats are read and, for every theme of wl_topic_candidates(), the customers who wrote its words and the share that ORDERED within 14 days after their last message are written to wl_theme_stats. wl_new_topic takes the themes in the order of that order rate. */
function wl_theme_scan(PDO $pdo): string
{
    $from = date('Y-m-d H:i:s', time() - 120 * 86400); $msgs = []; $last = [];
    try { $q = $pdo->prepare("SELECT phone, body, ts FROM wa_messages WHERE dir = 'in' AND mtype IN ('text','interactive','button') AND ts >= ? AND body <> '' ORDER BY id DESC LIMIT 400000"); $q->execute([$from]);
        foreach ($q as $r) { $p = (string)$r['phone']; if (!isset($msgs[$p])) $msgs[$p] = []; if (count($msgs[$p]) < 25) $msgs[$p][] = (string)$r['body']; if (!isset($last[$p])) $last[$p] = (int)strtotime((string)$r['ts']); } } catch (Throwable $e) { return 'themes: the scan failed (' . mb_substr($e->getMessage(), 0, 80) . ')'; }
    if (count($msgs) < 500) return 'themes: not enough chats yet (' . count($msgs) . ')';
    $ord = []; try { foreach ($pdo->query("SELECT phone, created_at FROM shop_orders WHERE created_at >= '" . date('Y-m-d H:i:s', time() - 140 * 86400) . "' AND phone IS NOT NULL AND phone <> ''") as $o) { $k = function_exists('wa_key') ? wa_key($o['phone']) : substr(preg_replace('/\D+/', '', (string)$o['phone']), -10); $ord[$k][] = (int)strtotime((string)$o['created_at']); } } catch (Throwable $e) { }
    $ordered = []; $nOrd = 0; foreach ($msgs as $p => $t) { $k = function_exists('wa_key') ? wa_key($p) : substr($p, -10); $ok = false; foreach (($ord[$k] ?? []) as $ot) if ($ot >= $last[$p] - 86400 && $ot <= $last[$p] + 14 * 86400) { $ok = true; break; } $ordered[$p] = $ok; if ($ok) $nOrd++; }
    $out = ['at' => date('Y-m-d H:i:s'), 'overall' => ['n' => count($msgs), 'o' => $nOrd, 'rate' => round(100 * $nOrd / max(1, count($msgs)), 2)], 'themes' => []];
    foreach (wl_topic_candidates() as $key => $c) { $n = 0; $o = 0;
        foreach ($msgs as $p => $t) { $hit = false; foreach ($t as $b) { $nb = ' ' . trim((string)preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', mb_strtolower($b))) . ' '; foreach ($c['words'] as $w) { $w = mb_strtolower($w); if (strpos($nb, ' ' . $w . ' ') !== false || (!preg_match('/^[a-z ]+$/', $w) && strpos($nb, $w) !== false)) { $hit = true; break 2; } } } if ($hit) { $n++; if ($ordered[$p]) $o++; } }
        $out['themes'][$key] = ['n' => $n, 'o' => $o, 'rate' => round(100 * $o / max(1, $n), 2)]; }
    setting_set($pdo, 'wl_theme_stats', json_encode($out, JSON_UNESCAPED_UNICODE));
    return 'themes: scanned ' . count($msgs) . ' customers (' . $out['overall']['rate'] . '% ordered): ' . implode(', ', array_map(fn($k, $v) => $k . ' ' . $v['rate'] . '% of ' . $v['n'], array_keys($out['themes']), $out['themes']));
}
/** One new topic a night (setting wl_auto_topics = 0 stops it): the next theme of wl_topic_candidates() that is not there yet; Claude writes its 4 messages (utility, the rules of wl_check_wording), they go to Meta for approval with the normal template maker. Used only when approved. */
function wl_new_topic(PDO $pdo): string
{
    if ((string)setting_get($pdo, 'wl_auto_topics', '1') === '0') return '';
    if (!function_exists('wcr_ai')) return 'topics: Claude is not available on this server';
    $t = json_decode((string)setting_get($pdo, 'wl_topics_extra', ''), true); if (!is_array($t)) $t = [];
    $waiting = []; foreach ($t as $x) if (empty($x['ready']) && time() - (int)strtotime((string)($x['at'] ?? 'now')) < 4 * 86400) $waiting[] = (string)$x['label']; if (count($waiting) >= 2) return 'topics: waiting for Meta to approve the messages of ' . implode(', ', $waiting);
    $have = array_column($t, 'key'); $st = json_decode((string)setting_get($pdo, 'wl_theme_stats', ''), true); $pick = null; $best = -1.0;
    foreach (wl_topic_candidates() as $k => $c) { if (in_array($k, $have, true)) continue; $v = is_array($st) ? ($st['themes'][$k] ?? null) : null;
        if ($v !== null) { if ((int)$v['n'] < 250 || (float)$v['rate'] < 1.3 * (float)($st['overall']['rate'] ?? 0)) continue; if ((float)$v['rate'] > $best) { $best = (float)$v['rate']; $pick = [$k, $c]; } }
        elseif ($pick === null && !is_array($st)) { $pick = [$k, $c]; break; } }   // no scan yet: the list's own order
    if (!$pick) return 'topics: every known theme is added already';
    [$key, $c] = $pick; $P = wl_pack();
    $gb = ''; try { $g = $pdo->query("SELECT body FROM lib_guide WHERE status = 'live' ORDER BY version DESC LIMIT 1")->fetchColumn(); if ($g) { $j = json_decode((string)$g, true); if (is_array($j)) $gb = json_encode(['do' => array_slice((array)($j['do'] ?? []), 0, 8), 'openers' => array_slice((array)($j['openers'] ?? []), 0, 5)], JSON_UNESCAPED_UNICODE); } } catch (Throwable $e) { }
    $sys = 'You write the 4-message follow-up ladder of ONE new topic for a perfume shop (TM Perfume House) on WhatsApp, in Hinglish (Hindi in Roman letters) like the examples. Customer type: ' . $c['brief'] . '. The 4 messages go on day 1, 2, 3, 4 after his chat ended: (1) a warm reply to his interest and one easy question, (2) what makes buying easy (original perfume, packing, COD / delivery), (3) a question that moves the chat on (occasion / who it is for / which fragrance family he likes), (4) a gentle nudge to choose a size (20 ml to start). Every message is a UTILITY message: exactly one blank {{1}} (the first name) in the middle of the text, never first or last; 40-300 characters; NO prices, no percentages, no digits of 3 or more, no discount / offer / free / sale / special words; one quick-reply button of 2-4 words (max 25 characters, different from every existing button and from each other); plus the free answer sent when the button is tapped (max 350 characters, warm, asks for the perfume name / size / address, no price). Use what wins in real chats (given). Return only JSON {"messages": [{"body": "...", "button": "...", "free": "..."}, ... 4 items]}.';
    $usr = "Existing messages (do not repeat their words):\n" . implode("\n", array_map(fn($m) => '- ' . $m['body'] . ' [button: ' . $m['button'] . ']', array_slice($P, 0, 30))) . "\nAll buttons in use: " . implode(' | ', array_unique(array_map(fn($m) => (string)$m['button'], $P))) . "\nWhat wins in real chats (live guidebook): " . $gb;
    $r = wcr_ai($sys, $usr, 1800); $ms = is_array($r) ? (array)($r['messages'] ?? []) : []; if (count($ms) < 4) return 'topics: Claude did not write 4 messages (' . (string)($GLOBALS['wcr_ai_why'] ?? '') . ')';
    $extra = json_decode((string)setting_get($pdo, 'wl_extra', ''), true); if (!is_array($extra)) $extra = []; $add = []; $pool = $P;
    for ($i = 0; $i < 4; $i++) { $w = ['body' => (string)($ms[$i]['body'] ?? ''), 'button' => (string)($ms[$i]['button'] ?? ''), 'free' => (string)($ms[$i]['free'] ?? '')]; [$ok, $why] = wl_check_wording($w, $pool); if (!$ok) return 'topics: message ' . ($i + 1) . ' of "' . $c['label'] . '" was refused (' . $why . ')';
        $k = 'ln_' . $key . '_' . ($i + 1); $pool[$k] = ['body' => $w['body'], 'button' => $w['button']]; $add[] = ['key' => $k, 'topic' => $key, 'step' => $i + 1, 'body' => $w['body'], 'button' => $w['button'], 'free' => $w['free'], 'newtopic' => 1, 'at' => date('Y-m-d H:i:s')]; }
    foreach ($add as $e) $extra[] = $e; setting_set($pdo, 'wl_extra', json_encode($extra, JSON_UNESCAPED_UNICODE)); wl_extra_get($extra);
    $t[] = ['key' => $key, 'label' => $c['label'], 'words' => $c['words'], 'before' => $c['before'], 'ready' => false, 'at' => date('Y-m-d H:i:s')]; setting_set($pdo, 'wl_topics_extra', json_encode($t, JSON_UNESCAPED_UNICODE)); wl_topics_extra_get($t);
    $mk = ['made' => 0]; try { $mk = wl_make_templates($pdo); } catch (Throwable $e) { }
    return 'topics: new topic "' . $c['label'] . '" (the old data: its customers order more than average): 4 messages written and sent to Meta for approval (' . (int)$mk['made'] . ' made); it goes live when approved on 3+ accounts';
}
function wl_learn_night(PDO $pdo): void
{
    // TWICE A DAY (owner, 21 Nov): the night run (02-05 IST) and an afternoon run (14-17 IST); the marker is "date|am" / "date|pm" (the old plain date counts as the night run)
    [$td, $h] = wl_ist(time()); $slot = ($h >= 14 && $h < 17) ? 'pm' : (($h >= 2 && $h < 5) ? 'am' : ''); $__ld = (string)setting_get($pdo, 'wl_learn_day', ''); if ($slot === '' || $__ld === $td . '|' . $slot || ($slot === 'am' && $__ld === $td)) return;
    if (function_exists('wa_lock') && !wa_lock($pdo, 'wllearn', 280)) return; setting_set($pdo, 'wl_learn_day', $td . '|' . $slot);
    try { $rep = wl_report($pdo, 30, 30); } catch (Throwable $e) { return; }
    foreach ([fn() => wl_days_auto($pdo, $rep), fn() => wl_new_word($pdo, $rep), fn() => wl_theme_scan($pdo), fn() => wl_new_topic($pdo), function () use ($pdo) { $x = wl_suggest($pdo, true); return 'sentences: ' . count($x['items']) . ' winning sentences listed'; }] as $step) { try { $m = $step(); if ($m !== '') wl_learn_log($pdo, $m); } catch (Throwable $e) { wl_learn_log($pdo, 'learning step failed: ' . $e->getMessage()); } }
}
/** NEW WINNING SENTENCES (owner, 21 Nov): once a day the live guidebook (and the words of the team's own offers that worked) is read and the sentences that win in real chats are listed as ideas for NEW wordings of the Lineup messages.
 *  They are only listed (screen: "New winning sentences"): a new message goes to Meta for approval and into the Lineup only after it is approved. Returns the stored list. */
function wl_suggest(PDO $pdo, bool $force = false): array
{
    $j = json_decode((string)setting_get($pdo, 'wl_suggest', ''), true); if (!is_array($j)) $j = ['at' => '', 'items' => []];
    if (!$force && substr((string)$j['at'], 0, 10) === date('Y-m-d')) return $j;
    $items = []; $ver = 0; $add = function (string $text, string $kind, string $from) use (&$items) { $text = trim(preg_replace('/\s+/', ' ', $text)); $l = mb_strlen($text); if ($l < 18 || $l > 240) return; foreach ($items as $x) if (mb_strtolower($x['text']) === mb_strtolower($text)) return; $items[] = ['text' => $text, 'kind' => $kind, 'from' => $from]; };
    try { $g = $pdo->query("SELECT version, body FROM lib_guide WHERE status = 'live' ORDER BY version DESC LIMIT 1")->fetch(); if ($g) { $ver = (int)$g['version']; $b = json_decode((string)$g['body'], true); $from = 'guidebook v' . $ver;
        if (is_array($b)) { foreach ((array)($b['openers'] ?? []) as $x) if (is_string($x)) $add($x, 'opener', $from);
            foreach ((array)($b['offers'] ?? []) as $x) if (is_array($x) && !empty($x['offer'])) $add((string)$x['offer'] . (!empty($x['when']) ? ' — ' . $x['when'] : ''), 'offer', $from);
            foreach ((array)($b['objection_replies'] ?? []) as $x) if (is_array($x) && !empty($x['reply'])) $add((string)$x['reply'], 'answer to "' . mb_substr((string)($x['objection'] ?? ''), 0, 40) . '"', $from);
            foreach ((array)($b['do'] ?? []) as $x) if (is_string($x)) $add($x, 'what wins', $from); } } } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT tip, COUNT(*) n FROM order_rate_auto WHERE worked = 1 AND tip <> '' AND at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY tip ORDER BY n DESC LIMIT 8")->fetchAll() as $r) $add((string)$r['tip'], 'team offer that worked', 'the team (30 days)'); } catch (Throwable $e) { }
    $j = ['at' => date('Y-m-d H:i:s'), 'ver' => $ver, 'items' => array_slice($items, 0, 24)]; setting_set($pdo, 'wl_suggest', json_encode($j, JSON_UNESCAPED_UNICODE)); return $j;
}
/** DAYS FROM THE RESPONSES: the daily steps (1-4) compared by orders per 100 delivered — a step that brings clearly fewer orders than step 1 is suggested to move one day later; nothing is moved by itself (the days are in the setting wl_days) and nothing is suggested before 300 delivered per step. */
function wl_day_hints(array $report): array
{
    $by = []; foreach ((array)($report['steps'] ?? []) as $x) { $s = (int)$x['step']; $by[$s] = $by[$s] ?? ['delivered' => 0, 'orders' => 0, 'taps' => 0]; foreach (['delivered', 'orders', 'taps'] as $f) $by[$s][$f] += (int)$x[$f]; }
    $o = ['steps' => [], 'hints' => [], 'need' => 300]; ksort($by); $d = wl_days();
    foreach ($by as $s => $c) { $c['per100'] = $c['delivered'] >= 300 ? round($c['orders'] * 100 / $c['delivered'], 2) : null; $c['step'] = $s; $c['day'] = $d[$s] ?? 0; $o['steps'][] = $c; }
    $b1 = $by[1] ?? null; if ($b1 && $b1['delivered'] >= 300 && $b1['orders'] > 0) { $r1 = $b1['orders'] / $b1['delivered'];
        foreach ($by as $s => $c) if ($s >= 2 && $s <= 4 && $c['delivered'] >= 300 && ($c['orders'] / $c['delivered']) < $r1 * 0.35) $o['hints'][] = 'Step ' . $s . ' (day ' . ($d[$s] ?? '?') . ') brings ' . round(100 * $c['orders'] / $c['delivered'], 2) . ' orders per 100 delivered against ' . round(100 * $r1, 2) . ' for step 1: consider moving it one day later (setting wl_days).'; }
    if (!$o['hints']) $o['note'] = $b1 && $b1['delivered'] >= 300 ? 'The daily steps look alike: no day is moved.' : 'Not enough numbers yet (300 delivered per step are needed): the days stay as they are.';
    return $o;
}
/** THE LEARNING: which topic and which step bring ORDERS. An order is credited to the last lineup message the customer got in the 7 days before it (same rule as the campaigns' report).
 *  Per topic × step: sent, delivered, button taps, orders, revenue, orders per 100 delivered. Per topic: customers, buyers (ordered after he came in), conversion %. Rates are shown only from $min delivered / customers (small numbers lie). */
function wl_report(PDO $pdo, int $days = 30, int $min = 30): array
{
    $from = date('Y-m-d H:i:s', time() - $days * 86400); $rows = []; $topics = []; $steps = [];
    $q = $pdo->prepare("SELECT phone, topic, step, tpl_key, sent_at, wamid, px FROM wa_line_plan WHERE status = 'sent' AND sent_at >= ?"); $q->execute([$from]); $sent = $q->fetchAll();
    $st = []; foreach (array_chunk(array_filter(array_column($sent, 'wamid')), 400) as $ch) { $x = $pdo->prepare("SELECT wamid, status FROM wa_messages WHERE wamid IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $x->execute($ch); foreach ($x->fetchAll() as $r) $st[(string)$r['wamid']] = (string)$r['status']; }
    $pack = wl_pack(); $btn = []; foreach ($pack as $k => $m) $btn[$k] = mb_strtolower(trim($m['button']));
    $tap = []; try { $t = $pdo->prepare("SELECT phone, body, ts FROM wa_messages WHERE dir = 'in' AND mtype IN ('button','interactive') AND ts >= ?"); $t->execute([$from]); foreach ($t->fetchAll() as $r) $tap[(string)$r['phone']][] = [mb_strtolower(trim((string)$r['body'])), (int)strtotime((string)$r['ts'])]; } catch (Throwable $e) { }
    $colc = []; $kc = []; $kcell = function (string $k) use (&$kc) { if (!isset($kc[$k])) $kc[$k] = ['sent' => 0, 'delivered' => 0, 'taps' => 0, 'orders' => 0, 'revenue' => 0]; };
    $ord = []; try { $o = $pdo->prepare("SELECT phone, created_at, total FROM shop_orders WHERE COALESCE(tags, '') NOT LIKE '%wa-rx%' AND created_at >= ? AND phone IS NOT NULL AND phone != '' LIMIT 200000"); $o->execute([$from]);
        foreach ($o->fetchAll() as $r) { $k = function_exists('wa_key') ? wa_key($r['phone']) : substr(preg_replace('/\D+/', '', (string)$r['phone']), -10); if ($k !== '') $ord[$k][] = [(int)strtotime((string)$r['created_at']), (float)$r['total']]; } } catch (Throwable $e) { }
    $byPhone = []; foreach ($sent as $r) { $r['ts'] = (int)strtotime((string)$r['sent_at']); $byPhone[substr(preg_replace('/\D+/', '', (string)$r['phone']), -10)][] = $r; }
    $cell = function (string $t, int $s) use (&$rows) { if (!isset($rows[$t][$s])) $rows[$t][$s] = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'taps' => 0, 'orders' => 0, 'revenue' => 0]; };
    foreach ($sent as $r) { $t = (string)$r['topic']; $s = (int)$r['step']; $cell($t, $s); $rows[$t][$s]['sent']++; $kcell((string)$r['tpl_key']); $kc[(string)$r['tpl_key']]['sent']++; $x = $st[(string)$r['wamid']] ?? ''; $pxk = (string)($r['px'] ?? ''); if ($pxk !== '') { $colc[$pxk] = $colc[$pxk] ?? ['sent' => 0, 'delivered' => 0, 'replied' => 0, 'orders' => 0, 'revenue' => 0]; $colc[$pxk]['sent']++; if (in_array($x, ['delivered', 'read'], true)) $colc[$pxk]['delivered']++; }
        if (in_array($x, ['delivered', 'read'], true)) { $rows[$t][$s]['delivered']++; $kc[(string)$r['tpl_key']]['delivered']++; } if ($x === 'read') $rows[$t][$s]['read']++;
        foreach ($tap[(string)$r['phone']] ?? [] as [$b, $ts]) if ($ts >= (int)strtotime((string)$r['sent_at']) && $ts <= (int)strtotime((string)$r['sent_at']) + 2 * 86400 && $b === ($btn[(string)$r['tpl_key']] ?? '#')) { $rows[$t][$s]['taps']++; $kc[(string)$r['tpl_key']]['taps']++; break; } }
    $credited = [];
    foreach ($byPhone as $k => $list) foreach ($ord[$k] ?? [] as [$ots, $tot]) { $best = null; foreach ($list as $r) if ($r['ts'] < $ots && $ots <= $r['ts'] + 7 * 86400 && ($best === null || $r['ts'] > $best['ts'])) $best = $r;
        if ($best) { $t = (string)$best['topic']; $s = (int)$best['step']; $cell($t, $s); $rows[$t][$s]['orders']++; $rows[$t][$s]['revenue'] += (int)round($tot); $credited[$t][$k] = 1; $kcell((string)$best['tpl_key']); $kc[(string)$best['tpl_key']]['orders']++; $kc[(string)$best['tpl_key']]['revenue'] += (int)round($tot); $bpx = (string)($best['px'] ?? ''); if ($bpx !== '' && isset($colc[$bpx])) { $colc[$bpx]['orders']++; $colc[$bpx]['revenue'] += (int)round($tot); } } }
    foreach ($pdo->query("SELECT topic, COUNT(*) n FROM wa_line_cust GROUP BY topic")->fetchAll() as $r) $topics[$r['topic']] = ['customers' => (int)$r['n'], 'buyers' => count($credited[$r['topic']] ?? []), 'orders' => 0, 'revenue' => 0, 'sent' => 0, 'delivered' => 0];
    foreach ($rows as $t => $ss) foreach ($ss as $s => $c) { $topics[$t] = ($topics[$t] ?? ['customers' => 0, 'buyers' => count($credited[$t] ?? []), 'orders' => 0, 'revenue' => 0, 'sent' => 0, 'delivered' => 0]);
        foreach (['orders', 'revenue', 'sent', 'delivered'] as $f) $topics[$t][$f] += $c[$f]; $c['per100'] = $c['delivered'] >= $min ? round($c['orders'] * 100 / $c['delivered'], 1) : null; $c['tap_pct'] = $c['delivered'] >= $min ? round($c['taps'] * 100 / $c['delivered'], 1) : null; $steps[] = ['topic' => $t, 'step' => $s] + $c; }
    foreach ($topics as $t => &$c) { $c['conv'] = $c['customers'] >= $min ? round($c['buyers'] * 100 / $c['customers'], 1) : null; } unset($c);
    $ranked = array_filter($steps, fn($x) => $x['per100'] !== null); usort($ranked, fn($a, $b) => $b['per100'] <=> $a['per100']);
    $tr = array_filter($topics, fn($x) => $x['conv'] !== null); uasort($tr, fn($a, $b) => $b['conv'] <=> $a['conv']);
    $weak = array_values(array_filter($ranked, fn($x) => $x['orders'] === 0 && $x['delivered'] >= $min * 3));
    foreach ($kc as $k => &$c) { $c['score'] = round(($c['taps'] + 5 * $c['orders'] + 1) / ($c['delivered'] + 20), 4); $c['per100'] = $c['delivered'] >= $min ? round($c['orders'] * 100 / $c['delivered'], 1) : null; $c['tap_pct'] = $c['delivered'] >= $min ? round($c['taps'] * 100 / $c['delivered'], 1) : null; } unset($c);
    // price column (%): which step down the price chart brings the orders — orders and revenue per 100 delivered, and per message sent (a marketing message costs the shop's marketing rate)
    $rm = 1.09; try { $tc = function_exists('wa_team_cfg') ? wa_team_cfg($pdo) : null; if ($tc) $rm = (float)$tc['rate_marketing']; } catch (Throwable $e) { }
    $columns = []; krsort($colc, SORT_NUMERIC); foreach ($colc as $pct => $c) { $c['pct'] = (float)$pct; $c['cost'] = round($c['sent'] * $rm, 2); $c['per100'] = $c['delivered'] >= $min ? round($c['orders'] * 100 / $c['delivered'], 1) : null; $c['rev_per_100'] = $c['delivered'] >= $min ? (int)round($c['revenue'] * 100 / $c['delivered']) : null; $c['net'] = (int)round($c['revenue'] - $c['cost']); $columns[] = $c; }
    $res = ['columns' => $columns, 'days' => $days, 'min' => $min, 'keys' => $kc, 'steps' => $steps, 'topics' => $topics, 'best_topic' => $tr ? array_key_first($tr) : null, 'best_steps' => array_slice(array_values($ranked), 0, 3), 'weak_steps' => array_slice($weak, 0, 5),
        'note' => 'Orders are credited to the last lineup message in the 7 days before the order. A rate shows only after ' . $min . ' delivered / customers — before that it is only a guess.'];
    $res['day_hints'] = wl_day_hints($res); return $res;
}
/** One customer's whole lineup (for the chat / customer card). */
function wl_customer(PDO $pdo, string $phone): array
{
    $c = $pdo->prepare("SELECT * FROM wa_line_cust WHERE phone = ?"); $c->execute([$phone]); $cu = $c->fetch(); if (!$cu) return ['in' => false];
    $p = $pdo->prepare("SELECT id, step, tpl_key, tpl_id, due_at, status, why, sent_at, wamid, px FROM wa_line_plan WHERE phone = ? ORDER BY id"); $p->execute([$phone]); $plan = $p->fetchAll();
    $st = []; foreach ($plan as $r) if (!empty($r['wamid'])) { try { $q = $pdo->prepare("SELECT status, err_code FROM wa_messages WHERE wamid = ?"); $q->execute([$r['wamid']]); $m = $q->fetch(); if ($m) $st[(string)$r['wamid']] = $m; } catch (Throwable $e) { } }
    foreach ($plan as &$r) { $m = $st[(string)$r['wamid']] ?? null; $r['receipt'] = $m ? (string)$m['status'] : ''; $r['err_code'] = $m ? (int)($m['err_code'] ?? 0) : 0; unset($r['wamid']); } unset($r);
    $l = $pdo->prepare("SELECT at, what FROM wa_line_log WHERE phone = ? ORDER BY id"); $l->execute([$phone]);
    $in = []; try { $q = $pdo->prepare("SELECT ts, SUBSTR(body, 1, 90) b FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts >= ? ORDER BY id LIMIT 40"); $q->execute([$phone, (string)$cu['anchor_at']]); $in = $q->fetchAll(); } catch (Throwable $e) { }
    $ord = []; try { $q = $pdo->prepare("SELECT created_at, total FROM shop_orders WHERE phone LIKE ? AND created_at >= ? ORDER BY created_at LIMIT 10"); $q->execute(['%' . substr(preg_replace('/\D+/', '', $phone), -10), (string)$cu['anchor_at']]); $ord = $q->fetchAll(); } catch (Throwable $e) { }
    return ['in' => true, 'customer' => $cu, 'plan' => $plan, 'log' => $l->fetchAll(), 'replies' => $in, 'orders' => $ord, 'days' => wl_days()];
}

}

// ---------------------------------------------------------------------------------------------------------------------- the screen's actions
$__wl = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wl, 'wa.line_') === 0) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!wa_is_admin($pdo, $auth)) json_response(['error' => 'Only the owner / admin can set up the Lineup.'], 403);
    if (!wln_tables($pdo)) json_response(['error' => 'Lineup storage is not available.'], 500);
    switch ($__wl) {
        case 'wa.line_get': json_response(['ok' => true] + wl_overview($pdo));
        case 'wa.line_aft': { if (!function_exists('wa_aft_overview')) require_once __DIR__ . '/wa_after.php'; json_response(['ok' => true] + wa_aft_overview($pdo)); }   // the After-Order plan card under the 30-day plan
        case 'wa.line_card': { $c = wl_cfg($pdo); [$td0] = wl_ist(time()); $st = wl_status($pdo, false); json_response(['ok' => true, 'started' => $c['started'], 'ready' => wl_ready($pdo), 'status' => $st, 'today' => wl_summary($pdo, wl_ist_at($td0, 0)), 'total' => wl_summary($pdo, (int)strtotime($c['started'] ?: 'now') - 60), 'tomorrow' => wl_overview($pdo)['tomorrow']]); }
        case 'wa.line_report': json_response(['ok' => true] + wl_report($pdo, max(7, min(90, (int)($_GET['days'] ?? 30)))));
        case 'wa.line_customer': json_response(['ok' => true] + wl_customer($pdo, preg_replace('/\D/', '', (string)($_GET['phone'] ?? ''))));
        case 'wa.line_save': {   // POST {mode, cap, offer}
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $c = wl_cfg($pdo);
            if (isset($in['mode']) && in_array((string)$in['mode'], ['off', 'manual', 'auto'], true)) $c['mode'] = (string)$in['mode'];
            if (isset($in['cap'])) $c['cap'] = max(5, min(300, (int)$in['cap'])); if (isset($in['camp']) && in_array((string)$in['camp'], ['off', 'read', 'delivered'], true)) $c['camp'] = (string)$in['camp']; if (isset($in['camp_cap'])) $c['camp_cap'] = max(0, min(20000, (int)$in['camp_cap'])); if (isset($in['offer'])) $c['offer'] = mb_substr(trim((string)$in['offer']), 0, 900);
            wl_cfg_put($pdo, $c); if (isset($in['offer'])) wl_install_kw($pdo); json_response(['ok' => true, 'cfg' => wl_cfg($pdo)]);
        }
        case 'wa.line_start': {   // POST — the same start the engine does by itself (kept as a button in case the engine has not run yet). Nothing is SENT to a customer here.
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); @set_time_limit(120);
            json_response(['ok' => true] + wl_start_all($pdo, (int)($auth['employee_id'] ?? 0)) + wl_overview($pdo, true));
        }
        case 'wa.line_run': {   // POST — send what is due now (manual mode), in batches; call again while "waiting" > 0
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if (wl_cfg($pdo)['started'] === '') json_response(['error' => 'The Lineup has not started yet — press Start first.'], 422);
            @set_time_limit(120); json_response(['ok' => true] + wl_tick($pdo, ['now' => 1, 'cap' => 60]));
        }
        default: json_response(['error' => 'Unknown Lineup action.'], 404);
    }
}
