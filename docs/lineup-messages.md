# Lineup — all pre-made messages (33)

Every message is a **Utility** template: one blank (the first name), one quick-reply button, no offer words. The button answer goes **free** inside the customer's 24 hours (cards "Lineup · …" in ⚡ Button replies, editable). Steps 5–7 are the same for every topic. Step 1 of every topic has a second wording (`…_1b`): the engine splits evenly until each has 40 delivered, then sends the better one (most button taps + orders) to 80% and keeps testing the other on 20%. Generated from `wl_pack()` in `empmanagment/wa_line.php`.

## Address / payment pending (`pending`)

| Step | Template | Message (sent) | Button | Free answer after the tap |
|---|---|---|---|---|
| 1 | `ln_pending_1` | Namaste {{1}} ji 🙏 Aapka order abhi tak confirm nahi hua hai. Address ya payment mein koi madad chahiye? | Order confirm karna hai | Theek hai 🙏 Apna poora naam, address aur pincode bhej dein, hum order confirm karte hain. COD ya online payment, jo aapko theek lage. |
| 1 | `ln_pending_1b` | Namaste {{1}} ji 🙏 Aapka order bas ek step door hai: address aur pincode. Bhej dein to hum confirm kar dete hain. | Address bhejna hai | Theek hai 🙏 Apna poora naam, address aur pincode bhej dein, hum order confirm karte hain. COD ya online payment, jo aapko theek lage. |
| 2 | `ln_pending_2` | Hi {{1}} ji, aapka perfume abhi bhi aapke naam se taiyaar hai. Address bhej dein ya COD select karein? | COD se bhejiye | Ji, COD se bhej dete hain 🙏 Bas apna naam, address aur pincode likh dein. |
| 3 | `ln_pending_3` | Hi {{1}} ji, aaj order confirm hone par dispatch jaldi ho jata hai. Kisi cheez mein madad chahiye? | Dispatch ke baare mein | Order confirm hote hi dispatch ki process shuru ho jati hai 📦 Naam, address aur pincode bhej dein, hum aaj hi aage badhate hain. |
| 4 | `ln_pending_4` | Hi {{1}} ji, order mein koi dikkat aa rahi ho to bata dijiye, hum seedha solve kar denge. | Baat karni hai | Zaroor 🙏 Aap yahin apni dikkat likh dein, hamari team turant madad karegi. |

## Price / discount (`price`)

| Step | Template | Message (sent) | Button | Free answer after the tap |
|---|---|---|---|---|
| 1 | `ln_price_1` | Namaste {{1}} ji 🙏 Aapne perfume ke rate ke baare mein poocha tha. Hamare paas har budget ke options hain. Budget bata dein to sahi perfume suggest kar dete hain. | Budget batana hai | Zaroor 🙏 Aapka budget kitna hai? Bata dein, hum us mein best perfume aur size suggest karte hain. |
| 1 | `ln_price_1b` | Namaste {{1}} ji 🙏 Rate poochne ke liye shukriya. Perfume aur size ke hisaab se rate alag hota hai. Kaunsa perfume dekh rahe the, bata dein? | Perfume ka naam batao | Zaroor 🙏 Aapka budget kitna hai? Bata dein, hum us mein best perfume aur size suggest karte hain. |
| 2 | `ln_price_2` | Hi {{1}} ji, 20 ml aur 50 ml dono ka rate aur farak bhejun? Aapke budget ke hisaab se best option dikhate hain. | Rate list bhejiye | Ye lijiye 20 ml aur 50 ml ka rate aur farak 👇 Aapko kaunsa perfume dekhna hai, bata dein. |
| 3 | `ln_price_3` | Hi {{1}} ji, ek perfume kitne din chalta hai aur roz ka kharch kitna aata hai, ye samjhaun? | Kharch samjhaiye | Ek 50 ml perfume roz ke 2-3 spray par kaafi din chalta hai, isliye roz ka kharch bahut kam aata hai 🙂 Aapko kaunsa perfume chahiye, bata dein to hisaab bhej dete hain. |
| 4 | `ln_price_4` | Hi {{1}} ji, aapke liye koi chhota pack ya combo ka option dekhna hai? | Combo dekhna hai | Combo ke options ye rahe 👇 Aapko kaunse perfume pasand hain, bata dein to sahi combo suggest karte hain. |

## Trust (original, COD, reviews) (`trust`)

| Step | Template | Message (sent) | Button | Free answer after the tap |
|---|---|---|---|---|
| 1 | `ln_trust_1` | Namaste {{1}} ji 🙏 Aapne perfume ke original hone ke baare mein poocha tha. Hamare saare perfume original hain aur COD bhi available hai. Parcel khol kar check karke hi payment kar sakte hain. | Proof dekhna hai | Bilkul 🙏 Hamare saare perfume 100% original hain. Parcel aane par khol kar check karein, phir payment karein (COD available hai). Chahen to hamare customers ke real reviews aur photos bhej dete hain — bas 'Reviews' likh dein. |
| 1 | `ln_trust_1b` | Namaste {{1}} ji 🙏 Perfume asli hai ya nahi, ye sabse zaroori sawal hai. Aap parcel khol kar check karke hi payment kar sakte hain (COD). Proof ya reviews dekhna chahenge? | Reviews aur proof dekhein | Bilkul 🙏 Hamare saare perfume 100% original hain. Parcel aane par khol kar check karein, phir payment karein (COD available hai). Chahen to hamare customers ke real reviews aur photos bhej dete hain — bas 'Reviews' likh dein. |
| 2 | `ln_trust_2` | Hi {{1}} ji, aapke sawal se jude hamare customers ke reviews taiyaar hain. Dekhna chahenge? | Reviews dekhna hai | Ye hain hamare customers ke real reviews aur photos 👇 Aapko kaunsa perfume dekhna hai, bata dein to uski details bhi bhej dete hain. |
| 3 | `ln_trust_3` | Hi {{1}} ji, parcel kaise pack hota hai aur aap tak kaise pahunchta hai, iska ek chhota video taiyaar hai. | Packing video dekhna hai | Ye hai packing aur dispatch ka video 🎥 Parcel sealed aata hai aur aap delivery par khol kar check kar sakte hain. Koi sawal ho to yahin likh dein. |
| 4 | `ln_trust_4` | Hi {{1}} ji, pehli baar le rahe hain to 20 ml se shuru kar sakte hain. Pasand aaye to bada size baad mein. 20 ml ke options bhejun? | 20 ml ke options | 20 ml ek achha shuruaati option hai 🙂 Aapko kaunsa perfume pasand hai bata dein, hum 20 ml ki kimat aur options bhej dete hain. |

## Size / ml (`size`)

| Step | Template | Message (sent) | Button | Free answer after the tap |
|---|---|---|---|---|
| 1 | `ln_size_1` | Namaste {{1}} ji 🙏 Aapne size / ML ke baare mein poocha tha. 20 ml, 50 ml aur 100 ml ka farak aur rate bhejun? | Size ka farak batao | Ye raha 20 ml, 50 ml aur 100 ml ka farak aur rate 👇 Aapko kaunsa perfume dekhna hai, bata dein. |
| 1 | `ln_size_1b` | Namaste {{1}} ji 🙏 Size chunna aasan hai: 20 ml try karne ke liye, 50 ml roz ke liye, 100 ml lambe time ke liye. Aapke liye kaunsa theek rahega? | Kaunsa size theek hai | Ye raha 20 ml, 50 ml aur 100 ml ka farak aur rate 👇 Aapko kaunsa perfume dekhna hai, bata dein. |
| 2 | `ln_size_2` | Hi {{1}} ji, perfume kitne ghante tikta hai aur kaunsa size aapke liye theek rahega, batayein? | Mere liye kaunsa size | Rozmarra ke liye 50 ml sabse zyada pasand kiya jata hai, aur try karne ke liye 20 ml 🙂 Aap kaunsa perfume dekh rahe hain bata dein. |
| 3 | `ln_size_3` | Hi {{1}} ji, gift ke liye ya apne liye? Us hisaab se size suggest kar denge. | Gift ke liye dekhna hai | Gift ke liye hamare paas achhe options hain 🎁 Kisko dena hai (men / women) aur budget bata dein, hum suggest karte hain. |
| 4 | `ln_size_4` | Hi {{1}} ji, pehle chhota size try karna chahenge? | Chhota size dikhao | Chhote size ye rahe 👇 Aapko kaunsa perfume pasand hai bata dein, hum 20 ml ki details bhej dete hain. |

## Bought before (`reorder`)

| Step | Template | Message (sent) | Button | Free answer after the tap |
|---|---|---|---|---|
| 1 | `ln_reorder_1` | Namaste {{1}} ji 🙏 Aapne pehle hamse perfume liya tha. Kaisa laga? Naya kuch try karna ho to bataiye. | Naya perfume dekhna hai | Dhanyavaad 🙏 Aapko pichhla perfume kaisa laga? Kaisi khushboo pasand hai bata dein, hum naya perfume suggest karte hain. |
| 1 | `ln_reorder_1b` | Namaste {{1}} ji 🙏 Pichhli baar aapne hamse perfume liya tha, shukriya. Is baar kaunsi khushboo try karni hai? | Nayi khushboo batayein | Dhanyavaad 🙏 Aapko pichhla perfume kaisa laga? Kaisi khushboo pasand hai bata dein, hum naya perfume suggest karte hain. |
| 2 | `ln_reorder_2` | Hi {{1}} ji, aapke pehle wale perfume jaisa hi naya option dekhna chahenge? | Milta julta dikhao | Aapke pichhle perfume se milte julte options ye rahe 👇 Bata dein kaunsa dekhna hai. |
| 3 | `ln_reorder_3` | Hi {{1}} ji, repeat order par aapke liye alag se dekh lete hain. Dekhna hai? | Repeat order ki details | Repeat customers ke liye hum alag se dekhte hain 🙏 Aapko kaunsa perfume chahiye bata dein. |
| 4 | `ln_reorder_4` | Hi {{1}} ji, aapka pichhla perfume khatam hone wala ho to wahi dobara bhej dein? | Wahi dobara bhejiye | Ji, wahi perfume dobara bhej dete hain 🙏 Bas size aur address confirm kar dein. |

## No clear topic (`general`)

| Step | Template | Message (sent) | Button | Free answer after the tap |
|---|---|---|---|---|
| 1 | `ln_general_1` | Namaste {{1}} ji 🙏 Aapne hamse sampark kiya tha. Kaunsa perfume dekh rahe the? Bata dein to turant details bhej dete hain. | Perfume batana hai | Zaroor 🙏 Perfume ka naam ya photo bhej dein, hum details aur size bata dete hain. |
| 1 | `ln_general_1b` | Namaste {{1}} ji 🙏 Hamse sampark karne ke liye shukriya. Aap kaunsi khushboo dhoondh rahe hain, bata dein to hum sahi perfume dikhate hain. | Khushboo batani hai | Zaroor 🙏 Perfume ka naam ya photo bhej dein, hum details aur size bata dete hain. |
| 2 | `ln_general_2` | Hi {{1}} ji, men's, women's ya unisex — aapko kaunsi type pasand hai? | Type batani hai | Theek hai 🙂 Bata dein kis type ki khushboo pasand hai (fresh, sweet, woody...), hum 2-3 options bhejte hain. |
| 3 | `ln_general_3` | Hi {{1}} ji, hamare sabse zyada pasand kiye jaane wale perfumes ki list bhejun? | Best sellers dikhao | Ye hain hamare best sellers 👇 Koi pasand aaye to bata dein, details bhej dete hain. |
| 4 | `ln_general_4` | Hi {{1}} ji, aapki pasand ke hisaab se 2-3 perfume suggest kar sakte hain. Madad chahiye? | Suggest kijiye | Zaroor 🙏 Aap kisi aur perfume ka naam bata dein jo pasand ho, hum usi jaise 2-3 options bhejte hain. |

## Shared steps 5–7 (every topic)

| Step | Template | Message (sent) | Button | Free answer after the tap |
|---|---|---|---|---|
| 5 | `ln_all_5` | Hi {{1}} ji, aapke sawal ka jawab aapko mil gaya? Aur jaankari chahiye to yahin likh dijiye. | Aur jaankari chahiye | Zaroor 🙏 Aap yahin likh dein ki aur kya jaanna hai, hum turant batate hain. |
| 6 | `ln_all_6` | Hi {{1}} ji, aapki request ke baare mein final details taiyaar hain. Dekhna chahenge? | Final details dekhein | **The offer** — set in the Lineup settings (`wl_cfg.offer`); default asks which perfume and quotes this week's best price |
| 7 | `ln_all_7` | Hi {{1}} ji, aapki request ka ye aakhri reminder hai. Order karna chahte hain to hum abhi madad kar sakte hain. | Order karna chahta hoon | Ji 🙏 Perfume ka naam, size aur address bhej dein, hum order confirm karte hain. |

---

# What can go wrong, and what the code does about it

| Risk | What happens | Guard in `wa_line.php` |
|---|---|---|
| Meta re-labels a "utility" message as marketing (offer words) | cost goes up, number quality drops | templates contain no offer / discount / % / ₹ words (checked by `tests/line_test.php`); the offer goes free after the button; Meta's re-label is already detected by the template module |
| Meta rejects a body that starts or ends with a blank | template never approved | `Hi {{1}}…` prefix added automatically; test checks start and end |
| A step whose template is still pending gets jumped over | customer gets step 6 without step 1–5 | automatic sending waits until all **27 base** messages are approved (the 6 extra wordings are optional) (`wl_ready`); only the Send button ignores this |
| Customer ordered but the order is not yet in `wa_cust` (it refreshes now and then) | a reminder after the order | orders are read straight from `shop_orders` once per round, plus `wa_cust` |
| Two systems message the same person (campaign, old Drip, Autopilot, Journey, a salesperson) | 2 messages in one day, complaints | waits if: a template in the last 20 h, a follow-up in the last day (`wa_fu_ev`, `wa_wj_ev`), a person wrote in 36 h or a campaign in 2 days (`wq_plan_paused`), order / ticket / thank-you mute (`wa_auto_muted`), he wrote in the last 6 h |
| Customer taps a button | counts as a reply: the ladder stops, the free answer goes at once | after 24 quiet hours he is read again; a new topic = a new ladder, messages already sent are never repeated; max 2 changes, never beyond 14 days |
| Topic read wrongly ("really" = real, "facebook" = book) | wrong ladder | Latin words match as whole words only; Gujarati / Hindi script match as parts; tested |
| Many customers due at the same minute | numbers run out | per-round cap (60), 25-second budget, "no room" = try next round, nothing is lost |
| The engine was off for a day | old steps arrive late | only the latest due step goes; a step waiting more than 30 h is dropped, never sent stale |
| Night messages | customer woken | window 10:00–20:00 IST; a step due outside moves to 10:00 |
| Same customer enrolled twice | double ladders | one lineup per customer per 30 days |
| Everything starts at once | 1,000+ messages in the first hour | always on (no switch); nothing is sent until Meta approved all 27; the engine sends 60 per round inside 10:00–20:00 IST, the screen's Send button is the manual second way |
| Old Drip keeps running next to the new one | double messages | `wa.line_start` sets the old Drip to **off** (its history stays) |

## What the data will tell (the pattern → orders loop)

`wa.line_report` credits every order to the last Lineup message the customer got in the 7 days before it, and shows per topic × step: sent, delivered, button taps, orders, revenue, orders per 100 delivered, and per topic the share of customers who ordered. Nothing changes by itself: the screen names the best steps and the steps with many deliveries and **no** orders, and you change the words (or the order of steps) on those. A rate is shown only after 30 delivered / 30 customers.

## Not checked from this environment

The code was written and linted without access to the live database, Meta or the guidebook: the SQL, the Meta submission and the real sending have not been run. The Lineup starts by itself on the first engine round after the upload and sends the 27 messages to Meta. Sending to customers begins only after Meta approved all 27 — until then, open Campaigns → 🧵 Lineup and check the board and "Tomorrow".

## Self-check and the 10-minute status

Every 10 minutes the engine (`wl_snap`) writes one status line (messages made / sent to Meta / approved / utility / still to approve · customers identified · customers who got a message · sent / delivered / button taps · orders and revenue) and runs `wl_heal`. `wl_heal` **fixes by itself**: old Drip switched on again, button cards missing or off, messages not made. It **reports** (screen → 🩺 Self-check): rejected messages, messages Meta turned into marketing, 5+ sends failing for the same reason, messages 3+ hours late (and why), the engine not running. Tests: `tests/line_test.php` (rules, wording picker) and `tests/line_db_test.php` (enrolment, topics, sending, order / reply stops, report, status, recheck on a small SQLite copy).

## Guidebook and learning

The approved guidebook (`wk_guide_text`) is shown on the Lineup screen next to the messages; the 27 + 6 texts were written to its rules (short, polite, no price or number invented). The learning loop is the report: per wording, taps and orders; the picker sends the better wording more. New wordings are added the same way: write a second text for a step (`$V` in `wl_pack`), Meta approves it, it enters the 50/50 test and the better one takes 80%.

The same status goes to Admin / HR phones as a push notification (tag `hrms-lineup`, replaces the previous one) for the first 24 hours after the start, then only when the self-check finds a warning.
