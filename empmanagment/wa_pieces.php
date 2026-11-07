<?php
/**
 * wa_pieces.php — the "message pieces" library of the campaign engine. Loaded by wa_camp.php.
 *
 * A pre-made (template) message has fixed text with variables {{1}} {{2}} … Meta approves the fixed text once; what goes INTO a variable may change for every customer.
 * A "piece" is one ready-made line the owner wrote for one of these places ("slots"): an offer line, an opening, a closing, an emoji, a date phrase.
 * A campaign variable can be set to "a line from my pieces": for every customer the engine picks one active piece of that slot — at random, or one that fits the customer
 * (tag buyer = has ordered · new = new customer · lapsed = quiet for 30+ days · any = everybody). The exact wording sent is saved with the customer (wa_camp_items.vars_json).
 *
 * Hard rules: only pieces the owner switched ON are ever used. The starter lines that promise a price, a discount or a freebie are added SWITCHED OFF — the owner turns on only
 * the offers the shop really runs. Nothing is invented. A variable can never hold a new line, a tab, more than 300 letters or runs of spaces (Meta rejects those).
 *
 *   wa.piece_list     GET   {slot?}                       → {pieces:[{id,slot,text,tag,active,uses,discount}], slots, tags, counts}
 *   wa.piece_save     POST  {id?, slot, text, tag, active}  add / change one piece (with only {id, active} it just switches it on or off)
 *   wa.piece_del      POST  {id}
 *   wa.piece_starter  POST  {}                            adds the ready-made starter lines that are not there yet (safe to press again)
 * Admin / HR only.
 */
if (!function_exists('wa_piece_tables')) {

function wa_piece_slots(): array
{
    return ['offer' => 'Offer line', 'opening' => 'Opening', 'closing' => 'Closing', 'emoji' => 'Emoji', 'date' => 'Date or time words'];
}
function wa_piece_tags(): array
{
    return ['any' => 'Everybody', 'buyer' => 'Has ordered', 'new' => 'New customer', 'lapsed' => 'Quiet for 30+ days'];
}
function wa_piece_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_pieces (id INT AUTO_INCREMENT PRIMARY KEY, slot VARCHAR(20) NOT NULL, text VARCHAR(200) NOT NULL, tag VARCHAR(20) NOT NULL DEFAULT 'any',
            active TINYINT NOT NULL DEFAULT 1, uses INT NOT NULL DEFAULT 0, created_at DATETIME NULL, KEY k_slot (slot, active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
/** A value that is safe to put into a Meta template variable: no new lines or tabs, no runs of spaces, at most $max letters. */
function wa_camp_clean_var(string $s, int $max = 300): string
{
    $s = preg_replace('/[\r\n\t\x0B\f]+/u', ' ', $s) ?? $s; $s = preg_replace('/\s{2,}/u', ' ', $s) ?? $s; $s = trim($s);
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max)) : $s;
}
/** Does this line promise money off / a price / a freebie? (only used to warn the owner — what is used is decided by the owner's ON switch) */
function wa_piece_is_offer(string $t): bool
{
    return (bool)preg_match('/(%|₹|\brs\.?\s*\d|\brupees?\b|\bfree\b|\boff\b|\bflat\b|\bdiscount|\bbuy\s*\d|\bcashback\b|\bsale\b|\bcombo\b|\bspecial price\b|\bpercent)/iu', $t);
}
/** The ready-made starter lines: [slot, text, tag, active]. Lines about prices / offers start SWITCHED OFF. */
function wa_piece_starter(): array
{
    $o = [];
    foreach (['Namaste 🙏', 'Hello', 'Hi', 'Hey there', 'Kaise hain aap', 'Aapka din shubh ho', 'Namaste ji'] as $x) $o[] = ['opening', $x, 'any', 1];
    $o[] = ['opening', 'Welcome to TM Perfume House', 'new', 1]; $o[] = ['opening', 'Wapas aapka swagat hai', 'buyer', 1]; $o[] = ['opening', 'Bahut dino baad — kaise hain', 'lapsed', 1];
    $o[] = ['opening', 'Thank you for choosing us', 'buyer', 1]; $o[] = ['opening', 'Hope you are doing well', 'any', 1];
    foreach (['Reply YES for details', 'Bas ek reply door!', 'Aap bas “Hi” likh dijiye', 'Visit tmperfumehouse.com', 'Hum yahin hain, kabhi bhi poochiye', 'Order karna ho to bas reply kijiye', 'Photos chahiye to reply kijiye'] as $x) $o[] = ['closing', $x, 'any', 1];
    $o[] = ['closing', 'Aapka favourite phir se ready hai', 'buyer', 1]; $o[] = ['closing', 'Naye aaye perfumes dekhna na bhoolen', 'buyer', 1]; $o[] = ['closing', 'Pehla order, pura bharosa — COD available', 'new', 1];
    $o[] = ['closing', 'Hum aapko miss kar rahe the', 'lapsed', 1]; $o[] = ['closing', 'Kuch naya try karna ho to batayiye', 'lapsed', 1];
    foreach (['✨', '🪔', '🎁', '💫', '🌸', '🔥', '😊', '🙏', '🧴', '💐'] as $x) $o[] = ['emoji', $x, 'any', 1];
    foreach (['aaj', 'kal tak', 'is weekend', 'is hafte', 'aaj raat tak', 'Sunday tak', 'is festive season', 'abhi', 'sirf 2 din', 'month end tak'] as $x) $o[] = ['date', $x, 'any', 1];
    // offers: switched OFF until the owner confirms that the shop really runs them
    foreach (['Free shipping on every order today', 'Free shipping above ₹499', 'COD available on all orders', 'Free gift with every order', 'Free sample with your first order', 'Gift box free on 2 or more perfumes',
              'Buy 2 get 1 free on attars', 'Flat 20% off on all perfumes', 'Extra 10% off on orders above ₹999', '₹100 off on your next order', 'Special combo price on gift boxes', 'Festival special price on selected perfumes'] as $x) $o[] = ['offer', $x, 'any', 0];
    $o[] = ['offer', 'Special price for our repeat customers', 'buyer', 0]; $o[] = ['offer', 'Welcome offer for new customers', 'new', 0]; $o[] = ['offer', 'Comeback price just for you', 'lapsed', 0]; $o[] = ['offer', 'Loyalty price for our old customers', 'buyer', 0];
    return $o;
}
function wa_piece_public(array $r): array
{
    return ['id' => (int)$r['id'], 'slot' => (string)$r['slot'], 'text' => (string)$r['text'], 'tag' => (string)$r['tag'], 'active' => (int)$r['active'] === 1, 'uses' => (int)$r['uses'], 'discount' => wa_piece_is_offer((string)$r['text'])];
}
/** Every ACTIVE piece, grouped by slot (loaded once per request). */
function wa_piece_all(PDO $pdo, bool $fresh = false): array
{
    static $c = null; if ($c !== null && !$fresh) return $c; $c = [];
    if (!wa_piece_tables($pdo)) return $c;
    foreach ($pdo->query("SELECT id, slot, text, tag FROM wa_pieces WHERE active = 1 ORDER BY id")->fetchAll() as $r) $c[(string)$r['slot']][] = ['id' => (int)$r['id'], 'text' => (string)$r['text'], 'tag' => (string)$r['tag']];
    return $c;
}
/** One piece for one customer. $classes = what the customer is (buyer / new / lapsed). A piece made for the customer's class is preferred (3 times in 4); otherwise a general one; never an inactive one. Returns ['id', 'text'] or null. */
function wa_piece_pick(PDO $pdo, string $slot, array $classes = []): ?array
{
    $all = wa_piece_all($pdo)[$slot] ?? []; if (!$all) return null;
    $fit = array_values(array_filter($all, fn($p) => $p['tag'] !== 'any' && in_array($p['tag'], $classes, true))); $gen = array_values(array_filter($all, fn($p) => $p['tag'] === 'any'));
    if ($fit && (!$gen || random_int(1, 4) <= 3)) $pool = $fit; else $pool = $gen ?: $fit;
    if (!$pool) return null; $p = $pool[random_int(0, count($pool) - 1)];
    return ['id' => $p['id'], 'text' => wa_camp_clean_var($p['text'], 300)];
}
/** Counts how often pieces were used: [pieceId => times]. */
function wa_piece_used(PDO $pdo, array $counts): void
{
    foreach ($counts as $id => $n) { try { $pdo->prepare("UPDATE wa_pieces SET uses = uses + ? WHERE id = ?")->execute([(int)$n, (int)$id]); } catch (Throwable $e) { } }
}
function wa_piece_add_starter(PDO $pdo): int
{
    if (!wa_piece_tables($pdo)) return 0; $have = []; foreach ($pdo->query("SELECT slot, text FROM wa_pieces")->fetchAll() as $r) $have[$r['slot'] . '|' . mb_strtolower((string)$r['text'])] = true;
    $ins = $pdo->prepare("INSERT INTO wa_pieces (slot, text, tag, active, created_at) VALUES (?, ?, ?, ?, ?)"); $n = 0;
    foreach (wa_piece_starter() as [$slot, $text, $tag, $act]) { if (isset($have[$slot . '|' . mb_strtolower($text)])) continue; $ins->execute([$slot, $text, $tag, $act, date('Y-m-d H:i:s')]); $n++; }
    wa_piece_all($pdo, true); return $n;
}
}

// ---------------------------------------------------------------------------------------------------------------- actions
$__wpc = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wpc, ['wa.piece_list', 'wa.piece_save', 'wa.piece_del', 'wa.piece_starter'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $auth = require_auth(); wa_guard_admin($pdo, $auth);
    if (!wa_tables($pdo) || !wa_piece_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    switch ($__wpc) {
        case 'wa.piece_list': {
            $slot = (string)($_GET['slot'] ?? '');
            if ($slot !== '' && isset(wa_piece_slots()[$slot])) { $q = $pdo->prepare("SELECT * FROM wa_pieces WHERE slot = ? ORDER BY active DESC, id"); $q->execute([$slot]); }
            else $q = $pdo->query("SELECT * FROM wa_pieces ORDER BY slot, active DESC, id");
            $counts = []; foreach ($pdo->query("SELECT slot, COUNT(*) n, SUM(active) a FROM wa_pieces GROUP BY slot")->fetchAll() as $r) $counts[(string)$r['slot']] = ['n' => (int)$r['n'], 'on' => (int)$r['a']];
            json_response(['pieces' => array_map('wa_piece_public', $q->fetchAll()), 'slots' => wa_piece_slots(), 'tags' => wa_piece_tags(), 'counts' => $counts]);
        }
        case 'wa.piece_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $id = (int)($in['id'] ?? 0);
            if ($id > 0 && !array_key_exists('text', $in) && !array_key_exists('slot', $in) && array_key_exists('active', $in)) {   // just the ON / OFF switch
                $pdo->prepare("UPDATE wa_pieces SET active = ? WHERE id = ?")->execute([!empty($in['active']) ? 1 : 0, $id]); wa_piece_all($pdo, true); json_response(['ok' => true, 'id' => $id]);
            }
            $slot = (string)($in['slot'] ?? ''); if (!isset(wa_piece_slots()[$slot])) json_response(['error' => 'Choose where this line is used (offer, opening, closing, emoji or date).'], 422);
            $text = wa_camp_clean_var((string)($in['text'] ?? ''), 200); if ($text === '') json_response(['error' => 'Write the line first.'], 422);
            $tag = isset(wa_piece_tags()[(string)($in['tag'] ?? 'any')]) ? (string)($in['tag'] ?? 'any') : 'any';
            $off = wa_piece_is_offer($text); $act = array_key_exists('active', $in) ? (!empty($in['active']) ? 1 : 0) : ($off ? 0 : 1);   // an offer line starts OFF unless the owner switches it on
            if ($id > 0) { $pdo->prepare("UPDATE wa_pieces SET slot = ?, text = ?, tag = ?, active = ? WHERE id = ?")->execute([$slot, $text, $tag, $act, $id]); }
            else { $pdo->prepare("INSERT INTO wa_pieces (slot, text, tag, active, created_at) VALUES (?, ?, ?, ?, ?)")->execute([$slot, $text, $tag, $act, date('Y-m-d H:i:s')]); $id = (int)$pdo->lastInsertId(); }
            wa_piece_all($pdo, true); json_response(['ok' => true, 'id' => $id, 'active' => $act === 1, 'discount' => $off]);
        }
        case 'wa.piece_del': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $pdo->prepare("DELETE FROM wa_pieces WHERE id = ?")->execute([(int)(json_input()['id'] ?? 0)]); wa_piece_all($pdo, true); json_response(['ok' => true]);
        }
        case 'wa.piece_starter': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            json_response(['ok' => true, 'added' => wa_piece_add_starter($pdo)]);
        }
    }
}
