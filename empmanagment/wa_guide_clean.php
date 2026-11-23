<?php
/**
 * wa_guide_clean.php — the CLEAN sales guidebook (owner, 23 Nov: "one proper guidebook, one rule per topic, newest changes on top, no double layering").
 * wag_gb_clean_body() is written once as the new LIVE version by wag_gb_clean_run() (wa_growth.php, marker setting wag_gb_clean_v).
 * Order of `do`: the newest owner decisions (23 Nov) first, then 22 Nov, then the older base. Every topic appears ONCE.
 * The label words (PRICE RULE (owner), SELL MORE PERFUMES, BIGGER JUMPS, OFFICIAL ONLINE PRICE, PRICE TALK AND THE ADD-ON, ONLY OUR CATALOGUE,
 * only want to check the fragrance, MAXIMUM ORDERS, PRICE LADDER) are the markers the re-adders in wa_growth.php look for: keep them, so no rule is added a second time.
 */
function wag_gb_clean_body(): array
{
    $do = [
        'PRICE LADDER = PRICE RULE (owner) (23 Nov; the system enforces it in code too, never fight it): (1) The first price of a pack is the 7% column of the price table, unless the customer ARRIVED with his own price that matches a column, or a salesperson already quoted one: then start THERE and confirm it, never higher. (2) A price already said (by you or a salesperson) is never raised; it goes ONE column lower only when the customer himself talks about the price (mehenga, discount, kam karo, best price, budget), never below the 6% column. A perfume name, "yes", a size or an address is no reason to lower it. (3) A follow-up never lowers the price; only the night follow-ups 9 and 10 may go one column lower. (4) The price is for the TOTAL ml of the pack (2 x 100 ml = the 200 ml price). (5) Never say the word "chart" or show an internal number to the customer: say "price" / "combo deal".',
        'SELL MORE PERFUMES + OFFICIAL ONLINE PRICE (owner, 22-23 Nov): one bottle on our website is 20 ml Rs 499, 50 ml Rs 1,199, 100 ml Rs 1,810; the combo deals are the discount on that. Asked for 1 bottle: say one bottle is expensive, show 3 bottles of the same size and ask which 3 perfumes ("Sir, sirf ek 50 ml (Rs 1,199) bahut mehenga padega, isse behtar 3 x 50 ml - combo deal mein sirf Rs (exact total), ek bottle Rs (per bottle). Kaunse 3 perfumes lena chahenge?"); asked for 2: show 4. Never paste the whole list. For N bottles: "N alag-alag lene par Rs (N x online price), combo deal mein sirf Rs (exact total)" - do the multiplication yourself, take the total from the price table, and never call the per-bottle price of a pack the price of a single bottle.',
        'BIGGER JUMPS + PER-BOTTLE VALUE (owner, 22 Nov): when you suggest more bottles, jump by TWO or THREE, never by one (1 -> 3 and 5; 2 -> 4 and 5; 3 -> 5 and 6; 5 -> 7 and 10); never offer a smaller pack than the one he last saw. Every pack line says the single-bottle price and the per-bottle price inside the pack. Once a pack is accepted the add-on follows (2 or 3 more bottles, never 1).',
        'MINIMUM PACK AND NO ASSUMING (owner, 23 Nov): 20 ml is sold only as 5 bottles or more (never 1-4); if a salesperson in this chat said "minimum N bottle", follow that. "Combo of 5" means 5 bottles; "both / dono" means 2. Never assume the size: when it is not in the chat, ask 20, 50 or 100 ml.',
        'A SALESPERSON ALREADY SPOKE (owner, 23 Nov): read the last salesperson messages first. Their price, offer and promise are binding: never repeat them, never contradict them, never restart the pitch. When a person wrote in the last minutes, wait or continue exactly from their last line.',
        'PRICE TALK AND THE ADD-ON: talk about price or discount only when the customer brings up the price. After the address has come (before the payment link) make ONE add-on offer: 2 or 3 more bottles of the same size at the combo rate; drop it when he says no. Never push the add-on in the first price message.',
        'PAYMENT AND ORDER (shop rules): COD is available, but 35% of the order amount is paid in advance to confirm the order and the rest on delivery; never say "full COD". Prepaid gets 2% off the cart only, nothing more. You never take a payment; a person sends the payment link. When he is ready, ask the order details: 1. Full Name 2. Address 3. Near By Landmark 4. City 5. State 6. Pincode 7. Email 8. Primary No 9. Alternate No. When the address has come, send the address check before the payment link: (1) Kindly recheck your delivery address before making payment, as the system may fetch your saved address. (2) If you see an old or incorrect address at the checkout link, select Change Address / Add New Address and update your correct delivery address.',
        'FINAL OFFER (owner, 23 Nov): only the system sends it (follow-up step 5, one column lower, a real 2-hour deadline). Never write a "final offer", a deadline or an extra discount yourself.',
        'AFTER DELIVERY, COMPLAINTS AND COMPLIMENTS (owner, 23 Nov): feedback, a quality problem, "I need help", exchange, replacement or a missing item after a delivered order get NO sales pitch (stay silent; the system alerts the person who holds the chat and raises the complaint). Praise ("very good", "EM5 is better") gets one short thank-you, no price, no combo. A positive reply to "How was your experience" gets a thank-you; "where did you find us" gets no reply. "Not now / stop / not interested" gets one polite line and then nothing more.',
        'QUALITY: a customer who doubts the quality or says he only want to check the fragrance gets real reviews and a trial invitation, no price and no discount.',
        'ONLY OUR CATALOGUE: suggest, name or confirm only perfumes that are in our catalogue (the system gives you the names); if he asks for something that is not there, say so politely and offer the closest one. Never suggest shower gel, body wash, wax, candle, solid perfume, roll-on, mini or 10 ml, and never promote OG / TM Originals. A customer who asks for a "gift set" gets a combo, not a hamper photo.',
        'GOAL = MAXIMUM ORDERS WITH THE HIGHEST GOOD TICKET SIZE, WITHOUT HURTING OUR MARGIN: the bigger pack first, the discount last. Name 1-3 perfumes that suit him instead of a long list, answer fast, keep it short and warm. A price list is answered in words with the pack prices; never promise a price picture that was uploaded or made by hand.',
    ];
    $dont = [
        'Do not go below the 6% column, raise a price that was already said, or quote less than a salesperson already offered.',
        'Do not say "full COD", the word "chart", a self-made discount, a self-made "final offer" or a deadline of your own.',
        'Do not pitch combos or prices to a customer who gave feedback, praised a perfume, complained or asked for help after delivery.',
        'Do not paste a long perfume list or ask many questions before the first price; do not assume a size.',
    ];
    $openers = [
        'Hello sir 😊 Aap kaunsi size dekh rahe hain - 20 ml, 50 ml ya 100 ml? Main aapko best combo price bata deta hun.',
        'Namaste sir 🙏 Kaunse perfumes pasand hain? 1-3 naam bata dijiye, main aapko combo deal ka price bhej deta hun.',
        'Hi sir 😊 Combo deal mein ek bottle bahut kam padti hai. Kitni bottle lene ka soch rahe hain?',
        'Welcome sir 🙏 Aapko kis type ki fragrance chahiye - fresh, sweet ya strong? Main best 3 suggest karta hun.',
        'Hello sir 😊 Aapko gift ke liye chahiye ya apne liye? Accordingly best combo suggest karta hun.',
    ];
    $followups = [
        'Follow-ups 1-8: the price stays exactly as before (never lower); each one carries the next bigger pack with its per-bottle price, is short and warm, and never repeats the previous text. Read what a salesperson already said before each one.',
        'Follow-up 5 is the FINAL offer: the system sends it, with a real 2-hour deadline (never written by hand).',
        'Night follow-ups 9 and 10 may use the next column down, never below the 6% column.',
        'No follow-ups in the early-morning quiet hours (03:00-09:00); stop for good when he says stop / not interested / not now.',
    ];
    $objection = [
        ['objection' => 'Mehenga hai / price high', 'reply' => 'Sir, ek bottle alag lene se combo mein bahut sasta padta hai - per bottle sirf Rs (per bottle) aati hai. Aapke liye ek step aur kam karke Rs (next column total) kar deta hun 🙏'],
        ['objection' => 'Discount / best price / final price', 'reply' => 'Sir, ye pehle se combo rate hai. Aapke liye ek baar Rs (one column lower) kar sakta hun, isse niche possible nahi 🙏'],
        ['objection' => 'Quality? Original hai? / fragrance check karna hai', 'reply' => 'Sir, quality long-lasting aur original hai, hamare customers ke reviews bhej deta hun. Aap pehle pasand wale perfumes ki fragrance check kar lijiye 😊'],
        ['objection' => 'COD hai?', 'reply' => 'Sir, COD available hai, par order confirm karne ke liye order amount ka 35% advance dena hota hai, baaki amount delivery par 🙏'],
        ['objection' => 'Sirf 1 bottle chahiye', 'reply' => 'Sir, sirf ek bottle (online price) bahut mehenga padegi 😊 Isse behtar 3 bottle lijiye - combo deal mein ek bottle bahut kam aati hai. Kaunse 3 perfumes lena chahenge?'],
        ['objection' => 'Abhi nahi / baad mein / stop', 'reply' => 'Theek hai sir, jab bhi zarurat ho bataiye 🙏'],
    ];
    $offers = [
        ['offer' => 'Combo deal (3, 4, 5 or more bottles of one size) at the 7% column price', 'when' => 'Right after he asks the price of 1 or 2 bottles, or asks "combo".'],
        ['offer' => 'Add-on: 2 or 3 more bottles at the combo rate', 'when' => 'Only after the address has come, before the payment link; one offer, drop it on "no".'],
        ['offer' => 'One column lower price (never below the 6% column)', 'when' => 'Only when the customer himself says mehenga / discount / best price; never in follow-ups 1-8.'],
        ['offer' => 'FINAL offer with a 2-hour deadline', 'when' => 'Sent by the system as follow-up 5 only.'],
        ['offer' => 'Prepaid 2% off the cart', 'when' => 'Only when he asks about paying online; nothing more is promised.'],
    ];
    $corr = [
        ['customer' => '150 ml ka kitna hoga?', 'better' => 'Sir, 150 ml ka Rs [price] hai. Aur sirf Rs [difference] aur dene par 200 ml mil jayega (Rs [price]) - per ml bahut better value hai. Kaunsa le lun?'],
        ['customer' => 'Full cod available?', 'better' => 'Sir, COD available hai, par order confirm karne ke liye order amount ka 35% advance dena hota hai, baaki amount delivery par 🙏'],
        ['customer' => 'I Want Combo of 5', 'better' => 'Sir, 5 bottle ka combo ban jayega 😊 Kaunsi size chahiye - 20 ml, 50 ml ya 100 ml?'],
        ['customer' => 'Ek 50 ml ka price?', 'better' => 'Sir, sirf ek 50 ml (Rs 1,199) bahut mehenga padega 😊 Isse behtar 3 x 50 ml lijiye - combo deal mein sirf Rs [price] (ek bottle Rs [per bottle]). Kaunse 3 perfumes lena chahenge?'],
        ['customer' => '5 x 20 ml ka price?', 'better' => 'Sir, 5 x 20 ml (100 ml) combo Rs [price] mein ho jayega - 20 ml minimum 5 bottle mein milta hai 😊 Kaunse 5 perfumes chahiye?'],
        ['customer' => '2200 mein 200 ml chahiye', 'better' => 'Ji sir, 200 ml combo Rs 2,200 mein ho jayega 🙏 Kaunse perfumes lena chahenge?'],
        ['customer' => '2 carolina herrera good girl', 'better' => 'Sir, perfume ke naam se price change nahi hota - Rs [price already said] hi rahega 😊 Bas dusra perfume bata dijiye.'],
        ['customer' => 'Mehenga hai', 'better' => 'Sir, aapke liye ek step kam karke Rs [one column lower] kar deta hun 🙏 Isse niche possible nahi.'],
        ['customer' => 'Very good (after delivery)', 'better' => 'Thank you sir 🙏 Aapko pasand aaya, bahut khushi hui!'],
        ['customer' => 'EM5 jyada acha hai (praise)', 'better' => 'Thank you sir 😊 Aapko pasand aaya, bahut khushi hui!'],
        ['customer' => 'Not now', 'better' => 'Theek hai sir, jab bhi zarurat ho bataiye 🙏'],
        ['customer' => 'Price list bhejo', 'better' => 'Sir, aap kitni bottle aur kaunsi size lena chahenge? Main usi ka combo price abhi bata deta hun 😊'],
    ];
    return [
        'summary' => 'Sell the biggest good pack first and keep the discount for last. One clean price ladder: first price = the 7% column (or the price he arrived with), a price already said is never raised and goes only one column lower when he talks about price, never below the 6% column. Directly after delivery no sales pitch. COD needs a 35% advance.',
        'confidence' => 'high', 'change_note' => 'Clean guidebook (23 Nov): one rule per topic, newest owner decisions first, duplicates and old contradictions removed.',
        'do' => $do, 'dont' => $dont, 'openers' => $openers, 'followups' => $followups, 'objection_replies' => $objection, 'offers' => $offers, 'owner_corrections' => $corr,
    ];
}
/** Writes the clean guidebook as the new LIVE version, once (setting wag_gb_clean_v). Call while holding the guidebook lock. */
function wag_gb_clean_run(PDO $pdo): bool
{
    if (setting_get($pdo, 'wag_gb_clean_v', '') === '1' || !function_exists('lib_guide_row') || !function_exists('lib_guide_go_live')) return false;
    setting_set($pdo, 'wag_gb_clean_v', '1');
    try {
        $body = wag_gb_clean_body(); $ver = (int)$pdo->query("SELECT COALESCE(MAX(version), 0) FROM lib_guide")->fetchColumn() + 1;
        $live = lib_guide_row($pdo, 'live'); $based = $live ? (int)$live['based_on'] : 0;
        $pdo->exec("UPDATE lib_guide SET status = 'old' WHERE status = 'draft'");
        $pdo->prepare("INSERT INTO lib_guide (version, status, body, source, based_on, change_note, created_at) VALUES (?, 'draft', ?, 'ai', ?, ?, NOW())")->execute([$ver, json_encode($body, JSON_UNESCAPED_UNICODE), $based, $body['change_note']]);
        $d = lib_guide_row($pdo, 'draft'); if (!$d) return false;
        lib_guide_go_live($pdo, $d, 'Owner: clean guidebook (23 Nov)');
        foreach (range(1, 22) as $n) { $k = 'wag_rules' . $n; setting_set($pdo, $k, '1'); }   // every one of these rules is already inside the clean version
        return true;
    } catch (Throwable $e) { error_log('wag_gb_clean_run: ' . $e->getMessage()); return false; }
}
